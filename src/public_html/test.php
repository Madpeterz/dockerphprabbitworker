<?php

namespace App;

require_once '../../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function sendMessageToQ(string $qname,string $message)
{
    $rabbitMQHost = 'localhost';
    $rabbitMQPort = '5672';
    $rabbitMQUser = 'guest';
    $rabbitMQPassword =  'guest';
    $rabbitMQQueue = $qname ?? 'default_queue';

    // Create a new AMQP connection
    $connection = new AMQPStreamConnection(
        $rabbitMQHost,
        $rabbitMQPort,
        $rabbitMQUser,
        $rabbitMQPassword
    );
    $channel = $connection->channel();
    // Declare a queue
    $channel->queue_declare($rabbitMQQueue, false, true, false, false);

    // Create a new message
    $msg = new AMQPMessage($message);

    // Publish the message to the queue
    $channel->basic_publish($msg, '', $rabbitMQQueue);

    echo " [x] Sent '$message'\n";

    // Close the channel and connection
    $channel->close();
    $connection->close();
}

if (isset($_POST['qname'])) {
    sendMessageToQ($_POST['qname'], $_POST['message']);
}
?>

<form method="post" action="http://localhost/test.php">
    <select name="qname" placeholder="queue name">
        <option value="notecards">notecards</option>
        <option value="commands">commands</option>
        <option value="ims">ims</option>
        <option value="groupims">groupims</option>
    </select>
    <input type="text" name="message" placeholder="message">
    <button type="submit">Send Message</button>
</form>
