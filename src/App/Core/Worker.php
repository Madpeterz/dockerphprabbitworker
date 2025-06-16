<?php

namespace App\Core;

use GuzzleHttp\Client;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use YAPF\InputFilter\InputFilter;

class Worker
{
    protected string $rabbitMQHost;
    protected int $rabbitMQPort;
    protected string $rabbitMQUser;
    protected string $rabbitMQPassword;
    protected string $rabbitMQQueue;
    protected string $rabbitMQvHost;
    protected bool $enableEchoOutput = true;
    protected bool $useSecondlifeBatching;
    protected int $recoveryWaitTime;
    protected Client $guzzle;
    protected AMQPStreamConnection $connection;
    protected AMQPChannel $channel;
    protected array $secondlifeUrlLastSeen = [];

    public function __construct()
    {
        $this->logMessage("Initializing Worker...");
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
            $this->logMessage("Connecting to RabbitMQ at {$this->rabbitMQHost}:{$this->rabbitMQPort}...");
            $this->makeConnection();
            $this->logMessage("Connection lost, attempting to reconnect in {$this->recoveryWaitTime} seconds...");
            sleep($this->recoveryWaitTime);
        }
    }

    protected function handlePost(string $url, bool $useBody, string|array $data): bool
    {
        if ($useBody == false) {
            if (!is_array($data)) {
                $this->logMessage("Data is not an array, skipping message");
                return false;
            }
            $this->guzzle->post($url, ['form_params' => $data]);
            return true;
        }
        $options = [
            "body" => is_string($data) ? $data : json_encode($data),
            'headers' => ['Content-type' => 'application/json'],
            "verify" => false,
            "timeout" => 10,
            "read_timeout" => 10,
            "connect_timeout" => 5,
        ];
        $this->guzzle->post($url, $options);
        return true;
    }

    protected function handleGet(string $url, string|array $data): bool
    {
        if (!is_array($data)) {
            $this->logMessage("Data is not an array, skipping message");
            return false;
        }
        $this->guzzle->get($url, ['query' => $data]);
        return true;
    }

    protected function doMessageTask(AMQPMessage $message): bool
    {
        try {
            $body = json_decode($message->getBody(), true);
            $this->logMessage("Processing message: " . json_encode($body));
            $data = $body['data'];
            if (is_string($data)) {
                $data = json_decode($data, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logMessage("JSON decoding error: " . json_last_error_msg());
                    return false;
                }
            }
            return match ($body["method"]) {
                "GET" => $this->handleGet($body['url'], $data),
                "POST" => $this->handlePost($body['url'], $body['useBody'], $data),
                default => (function () use ($body) {
                    $this->logMessage("Unsupported method: {$body['method']}");
                    return false;
                })(),
            };
        } catch (\Exception $e) {
            $this->logMessage("Error processing message: " . $e->getMessage());
            return false;
        }
    }

    public function processMessage(AMQPMessage $message): void
    {
        if (!$this->messageChecks($message)) {
            return;
        }
        if (!$this->secondlifeBatching($message)) {
            return;
        }
        $message->ack();
        if (!$this->checkMessageAge($message)) {
            $this->logMessage("Message is too old or malformed, skipping");
            return;
        }
        if (!$this->doMessageTask($message)) {
            return;
        }
        $this->cleanupSeenUrls();
        $this->logMessage("Message processed successfully");
    }

    protected function checkMessageAge(AMQPMessage $message): bool
    {
        try {
            $body = json_decode($message->getBody(), true);
            if (isset($body['unixtime']) && is_numeric($body['unixtime'])) {
                return (time() - $body['unixtime']) < 60;
            }
            return true;
        } catch (\Exception $e) {
            $this->logMessage("Error checking message age: " . $e->getMessage());
            return false;
        }
    }

    protected function cleanupSeenUrls(): void
    {
        if (!$this->useSecondlifeBatching) {
            return;
        }
        $oldAge = time() - 5;
        foreach ($this->secondlifeUrlLastSeen as $url => $lastSeen) {
            if ($lastSeen < $oldAge) {
                unset($this->secondlifeUrlLastSeen[$url]);
            }
        }
    }

    protected function secondlifeBatching(AMQPMessage $message): bool
    {
        try {
            if (!$this->useSecondlifeBatching) {
                return true;
            }
            $body = json_decode($message->getBody(), true);
            $url = $body['url'] ?? null;
            if (!$url) {
                $this->logMessage("No URL in message for batching");
                return false;
            }
            $now = time();
            if (!isset($this->secondlifeUrlLastSeen[$url])) {
                $this->secondlifeUrlLastSeen[$url] = $now;
                return true;
            }
            if ($this->secondlifeUrlLastSeen[$url] >= ($now + 1)) {
                return true;
            }
            $this->logMessage("Waiting for 1 sec to allow SL to not be overloaded");
            sleep(1);
            return true;
        } catch (\Exception $e) {
            $this->logMessage("Error in secondlifeBatching: " . $e->getMessage());
            return false;
        }
    }

    protected function messageChecks(AMQPMessage $message): bool
    {
        try {
            $body = json_decode($message->getBody(), true);
            $requiredKeys = ['url', 'unixtime', 'method', 'data', 'useBody'];
            if (!is_array($body) || array_diff($requiredKeys, array_keys($body))) {
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
            $this->logMessage("Dropping from waiting");
        } catch (\Exception $e) {
            $this->logMessage("Error connecting to RabbitMQ: " . $e->getMessage());
        }
    }

    protected function logMessage(string $message): void
    {
        if ($this->enableEchoOutput) {
            echo $message . "\n";
        }
    }
}
