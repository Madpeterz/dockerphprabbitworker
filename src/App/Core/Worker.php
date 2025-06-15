<?php

namespace App\Core;

use GuzzleHttp\Client;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use YAPF\InputFilter\InputFilter;

class Worker
{
    protected string $rabbitMQHost = 'localhost';
    protected int $rabbitMQPort = 5672;
    protected string $rabbitMQUser = 'guest';
    protected string $rabbitMQPassword = 'guest';
    protected string $rabbitMQQueue = 'default_queue';
    protected string $rabbitMQvHost = '/';
    protected bool $enableEchoOutput = true;
    protected bool $useSecondlifeBatching = false;
    protected int $recoveryWaitTime = 30; // seconds
    protected Client $guzzle;
    protected AMQPStreamConnection $connection;
    protected AMQPChannel $channel;

    protected array $secondlifeUrlLastSeen = []; // url => unixtime
    public function __construct()
    {
        $this->logMessage("Initializing Worker...");
        // get settings from environment variables
        $input = new InputFilter();
        $this->rabbitMQHost = $input->envInput("RABBITMQ_HOST")->asString('localhost');
        $this->rabbitMQPort = $input->envInput("RABBITMQ_PORT")->asInt(5672);
        $this->rabbitMQUser = $input->envInput("RABBITMQ_USER")->asString('guest');
        $this->rabbitMQPassword = $input->envInput("RABBITMQ_PASSWORD")->asString('guest');
        $this->rabbitMQQueue = $input->envInput("RABBITMQ_QUEUE")->asString('default_queue');
        $this->rabbitMQvHost = $input->envInput("RABBITMQ_VHOST")->asString('/');
        $this->enableEchoOutput = $input->envInput("ENABLE_ECHO_OUTPUT")->asBool(true);
        $this->useSecondlifeBatching = $input->envInput("USE_SECOND_LIFE_BATCHING")->asBool(false);
        $this->recoveryWaitTime = $input->envInput("RECOVERY_WAIT_TIME")->asInt(30);
        $this->logMessage("Worker initialized with settings: " .
            json_encode([
                'rabbitMQHost' => $this->rabbitMQHost,
                'rabbitMQPort' => $this->rabbitMQPort,
                'rabbitMQUser' => $this->rabbitMQUser,
                'rabbitMQQueue' => $this->rabbitMQQueue,
                'enableEchoOutput' => $this->enableEchoOutput,
                'useSecondlifeBatching' => $this->useSecondlifeBatching,
                'recoveryWaitTime' => $this->recoveryWaitTime,
            ]));
        $this->guzzle = new Client();
        $this->start();
    }
    protected function start(): void
    {
        while (true) {
            $this->logMessage("Connecting to RabbitMQ at " .
            "{" . $this->rabbitMQHost . "}:{" . $this->rabbitMQPort . "}...");
            $this->makeConnection();
            $this->logMessage("Connection lost, attempting to reconnect" .
            "in {" . $this->recoveryWaitTime . "} seconds...");
            sleep($this->recoveryWaitTime);
        }
    }

    protected function doMessageTask(AMQPMessage $message): bool
    {
        try {
            // send message out
            $body = json_decode($message->getBody(), true);
            $this->logMessage("Processing message: " . json_encode($body));
            $data = json_decode($body['data'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logMessage("JSON decoding error: " . json_last_error_msg());
                return false;
            }
            if (is_array($data) == false) {
                $this->logMessage("Data is not an array skipping message");
                return false;
            }
            if ($body["method"] == "GET") {
                $this->guzzle->get($body['url'], ['query' => $data]);
                return true;
            } elseif ($body["method"] == "POST") {
                $this->guzzle->post($body['url'], ['form_params' => $data]);
                return true;
            }
            $this->logMessage("Unsupported method: {$body['method']}");
            return false;
        } catch (\Exception $e) {
            $this->logMessage("Error processing message: " . $e->getMessage());
            return false;
        }
    }

    public function processMessage(AMQPMessage $message): void
    {
        if ($this->messageChecks($message) === false) {
            return;
        }
        if ($this->secondlifeBatching($message) === false) {
            return;
        }
        // delete message from queue
        $message->ack();
        if ($this->checkMessageAge($message) === false) {
            $this->logMessage("Message is too old or malformed, skipping");
            return;
        }
        if ($this->doMessageTask($message) === false) {
            return;
        }
        $this->cleanupSeenUrls();
        $this->logMessage("Message processed successfully");
    }

    protected function checkMessageAge(AMQPMessage $message): bool
    {
        // check if message is too old
        try {
            $body = json_decode($message->getBody(), true);
            if (isset($body['unixtime']) && is_numeric($body['unixtime'])) {
                $messageAge = time() - $body['unixtime'];
                if ($messageAge < 60) {
                    return true;
                }
                return false;
            }
            return true;
        } catch (\Exception $e) {
            $this->logMessage("Error checking message age: " . $e->getMessage());
            return false;
        }
    }

    protected function cleanupSeenUrls(): void
    {
        // cleanup seen urls to prevent memory leaks
        if ($this->useSecondlifeBatching == false) {
            return;
        }
        $toclean = [];
        foreach ($this->secondlifeUrlLastSeen as $url => $lastSeen) {
            if ($lastSeen < (time() - 5)) { // remove entries older than 5 seconds
                $toclean[] = $url;
            }
        }
        foreach ($toclean as $url) {
            unset($this->secondlifeUrlLastSeen[$url]);
        }
    }

    protected function secondlifeBatching(AMQPMessage $message): bool
    {
        // secondlife batching
        try {
            if ($this->useSecondlifeBatching == false) {
                return true;
            }
            $body = json_decode($message->getBody(), true);
            if (array_key_exists($body['url'], $this->secondlifeUrlLastSeen) == false) {
                $secondlifeUrlLastSeen[$body['url']] = time();
                return true;
            }
            if ($this->secondlifeUrlLastSeen[$body['url']] >= (time() + 1)) {
                return true;
            }
            $this->logMessage("waiting for 1 sec to allow SL to not be shit");
            sleep(1);
            return true;
        } catch (\Exception $e) {
            $this->logMessage("Error in secondlifeBatching: " . $e->getMessage());
            return false;
        }
    }

    protected function messageChecks(AMQPMessage $message): bool
    {
        // formating checks
        try {
            $body = json_decode($message->getBody(), true);
            $requiredKeys = ['url', 'unixtime', 'method', 'data'];
            if (is_array($body) && array_diff($requiredKeys, array_keys($body))) {
                $this->logMessage("Invalid message format: missing required keys");
                return false;
            }
            return true;
        } catch (\Exception $e) {
            $this->logMessage("Error processing message: " . $e->getMessage());
            return false;
        }
    }

    protected function makeConnection(): void
    {
        try {
            $this->connection = new AMQPStreamConnection(
                $this->rabbitMQHost,
                $this->rabbitMQPort,
                $this->rabbitMQUser,
                $this->rabbitMQPassword,
                $this->rabbitMQvHost,
            );
            $this->logMessage("Connected");
            $this->channel = $this->connection->channel();
            $this->logMessage("Declaring queue: " . $this->rabbitMQQueue);
            $this->channel->queue_declare(
                queue: $this->rabbitMQQueue,
                passive: false,
                durable: true,
                exclusive: false,
                auto_delete: false,
                nowait: false,
                arguments: null,
            );
            $this->logMessage("Queue declared successfully");
            $this->channel->basic_consume(
                queue: $this->rabbitMQQueue,
                consumer_tag: '',
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: [$this, 'processMessage']
            );
            $this->logMessage("Consumer registered successfully");
            while ($this->channel->is_consuming()) {
                $this->channel->wait();
            }
             $this->logMessage("dropping from waiting");
        } catch (\Exception $e) {
            $this->logMessage("Error connecting to RabbitMQ: " . $e->getMessage());
        }
    }

    protected function logMessage($message): void
    {
        if ($this->enableEchoOutput == true) {
            echo $message . "\n";
        }
    }
}
