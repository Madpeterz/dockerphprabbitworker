<?php

namespace App;

require_once '../../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

if (isset($_POST['host'])) {
    $host = $_POST['host'];
    $rabbitMQHost = $_ENV['RABBITMQ_HOST'] ?? 'localhost';
    $rabbitMQPort = $_ENV['RABBITMQ_PORT'] ?? '5672';
    $rabbitMQUser = $_ENV['RABBITMQ_USER'] ?? 'guest';
    $rabbitMQPassword = $_ENV['RABBITMQ_PASSWORD'] ?? 'guest';
    $rabbitMQQueue = $_ENV['RABBITMQ_QUEUE'] ?? 'default_queue';

    // Create a new AMQP connection
    $connection = new AMQPStreamConnection(
        $rabbitMQHost,
        $rabbitMQPort,
        $rabbitMQUser,
        $rabbitMQPassword
    );
    $channel = $connection->channel();
    $data = json_encode(["dog" => "bark", "cat" => "meow"]);
    $blob = [
        'url' => $host,
        'unixtime' => time(),
        'method' => 'GET',
        'data' => $data,
    ];
    // Declare a queue
    $channel->queue_declare($rabbitMQQueue, false, true, false, false);

    // Create a new message
    $msg = new AMQPMessage(json_encode($blob));

    // Publish the message to the queue
    $channel->basic_publish($msg, '', $rabbitMQQueue);

    echo " [x] Sent '$data'\n";

    // Close the channel and connection
    $channel->close();
    $connection->close();
}
?>

<form method="post" action="http://localhost/test.php">
    <input type="text" name="host" placeholder="host url">  
    <button type="submit">Send Message</button>
</form>
