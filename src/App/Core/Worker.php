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
    protected AMQPChannel $taskChannel = null;
    protected AMQPChannel $heartBeatChannel = null;
    protected AMQPChannel $pingPongChannel = null;
    protected array $secondlifeUrlLastSeen = [];
    protected string $heartBeatQueueName;
    protected float $heartBeatQueueInterval;
    protected string $pingPongQueueName;

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
        $this->heartBeatQueueName = $input->envInput("HEARTBEAT_QUEUE")->asString('heartbeat');
        $this->heartBeatQueueInterval = $input->envInput("HEARTBEAT_INTERVAL")->asFloat(0.2);
        $this->pingPongQueueName = $input->envInput("PING_PONG_QUEUE")->asString('ping_pong');
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

    protected function makeChannel(string $queueName, bool $autoDelete = false): AMQPChannel
    {
        $workChannel = $this->connection->channel();
        $this->logMessage("Declaring queue: " . $queueName);
        $workChannel->queue_declare(
            queue: $queueName,
            passive: false,
            durable: true,
            exclusive: false,
            auto_delete: $autoDelete,
            nowait: false,
            arguments: null,
        );
        return $workChannel;
    }

    public function processMessage(AMQPMessage $message): void
    {
        $this->heartBeat();
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

    protected float $lastHeartBeatTime = 0;
    protected function heartBeat(): void
    {
        if ($this->heartBeatChannel === null) {
            $this->heartBeatChannel = $this->makeChannel($this->heartBeatQueueName, true);
        }
        $this->logMessage("Sending heartbeat");
        if ((microtime(true) - $this->lastHeartBeatTime) < $this->heartBeatQueueInterval) {
            return; // Avoid sending heartbeats too frequently
        }
        $this->lastHeartBeatTime = microtime(true);
        $this->heartBeatChannel->queue_purge(
            queue: $this->heartBeatQueueName,
            nowait: false,
        );
        $msg = new AMQPMessage(json_encode(["time" => microtime(true),"random" => rand(0, 10000)]));
        $this->heartBeatChannel->basic_publish($msg, '', $this->heartBeatQueueName);
    }

    protected function pingPong(): void
    {
        if ($this->pingPongChannel === null) {
            $this->pingPongChannel = $this->makeChannel($this->pingPongQueueName, true);
        }
        $this->pingPongChannel->basic_consume(
            queue: $this->pingPongQueueName,
            consumer_tag: '',
            no_local: false,
            no_ack: true,
            exclusive: false,
            nowait: false,
            callback: [$this, 'heartBeat']
        );
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
            $this->taskChannel = $this->makeChannel($this->rabbitMQQueue);
            $this->logMessage("Queue declared successfully");
            $this->taskChannel->basic_consume(
                queue: $this->rabbitMQQueue,
                consumer_tag: '',
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: [$this, 'processMessage']
            );
            $this->heartBeat();
            $this->logMessage("Consumer registered successfully");
            while ($this->taskChannel->is_consuming()) {
                $this->taskChannel->wait();
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
