<?php

namespace App;

use GuzzleHttp\Client;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

require_once 'vendor/autoload.php';

set_time_limit(0);
ini_set('max_execution_time ', 0);


$rabbitMQHost = $_ENV['RABBITMQ_HOST'] ?? 'localhost';
$rabbitMQPort = $_ENV['RABBITMQ_PORT'] ?? '5672';
$rabbitMQUser = $_ENV['RABBITMQ_USER'] ?? 'guest';
$rabbitMQPassword = $_ENV['RABBITMQ_PASSWORD'] ?? 'guest';
$rabbitMQQueue = $_ENV['RABBITMQ_QUEUE'] ?? 'default_queue';
$rabbitMQvHost = $_ENV['RABBITMQ_VHOST'] ?? '/';
$enableEchoOutput = $_ENV['ENABLE_ECHO_OUTPUT'] ?? true;
$useSecondlifeBatching = $_ENV['USE_SECOND_LIFE_BATCHING'] ?? false;
$recoveryWaitTime = $_ENV['RECOVERY_WAIT_TIME'] ?? 30; // seconds

$secondlifeUrlLastSeen = []; // url => unixtime
$guzzle = new Client();

$processMessageFunction = function (AMQPMessage $message): void {
    global $secondlifeUrlLastSeen, $useSecondlifeBatching, $guzzle;
    $body = json_decode($message->getBody(), true);
    // formating checks
    $requiredKeys = ['url', 'unixtime', 'method', 'data'];
    if (is_array($body) && array_diff($requiredKeys, array_keys($body))) {
        logMessage("Invalid message format: missing required keys");
        return;
    }
    // secondlife batching
    if ($useSecondlifeBatching == true) {
        if (array_key_exists($body['url'], $secondlifeUrlLastSeen)) {
            if ($secondlifeUrlLastSeen[$body['url']] < (time() + 1)) {
                logMessage("Skipping message for URL: {$body['url']} due to recent processing");
                return;
            }
        }
        $secondlifeUrlLastSeen[$body['url']] = time();
    }
    // delete message from queue
    $message->ack();
    // process message
    logMessage("Processing message: " . json_encode($body));
    if ($body["method"] == "GET") {
        $guzzle->get($body['url'], ['query' => $body['data']]);
    } elseif ($body["method"] == "POST") {
        $guzzle->post($body['url'], ['form_params' => $body['data']]);
    } else {
        logMessage("Unsupported method: {$body['method']}");
        return;
    }
    // cleanup seen urls to prevent memory leaks
    if ($useSecondlifeBatching == true) {
        $toclean = [];
        foreach ($secondlifeUrlLastSeen as $url => $lastSeen) {
            if ($lastSeen < (time() - 5)) { // remove entries older than 60 seconds
                $toclean[] = $url;
            }
        }
        foreach ($toclean as $url) {
            unset($secondlifeUrlLastSeen[$url]);
        }
    }
};

function logMessage($message): void
{
    global $enableEchoOutput;
    if ($enableEchoOutput) {
        echo $message . "\n";
    }
}
logMessage("Starting worker...");
while (true) {
    try {
        logMessage("Connecting to RabbitMQ at {$GLOBALS['rabbitMQHost']}:{$GLOBALS['rabbitMQPort']}...");
        $connection = new AMQPStreamConnection(
            $rabbitMQHost,
            $rabbitMQPort,
            $rabbitMQUser,
            $rabbitMQPassword,
            $rabbitMQvHost,
        );
        $channel = $connection->channel();
        logMessage("Connected to RabbitMQ successfully");
        $channel->queue_declare(
            queue: $rabbitMQQueue,
            passive: false,
            durable: true,
            exclusive: false,
            auto_delete: false,
            nowait: false,
            arguments: null,
            ticket: null,
        );
        logMessage("Queue '{$rabbitMQQueue}' declared successfully");
        $channel->basic_consume(
            queue: $rabbitMQQueue,
            consumer_tag: '',
            no_local: false,
            no_ack: false,
            exclusive: false,
            nowait: false,
            callback: $processMessageFunction,
        );
        logMessage("Consumer registered successfully");
        try {
            $channel->consume();
        } catch (\Exception $exception) {
            logMessage($exception->getMessage());
        }
        logMessage("Connection closed successfully");
        break; // Exit the loop if the connection is successful
    } catch (\Exception $e) {
        logMessage("Connection to rabbit has failed - Auto recovery in " . $recoveryWaitTime . " secs");
        sleep($recoveryWaitTime);
        continue;
    }
}
