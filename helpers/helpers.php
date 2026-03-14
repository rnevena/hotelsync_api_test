<?php

function slugify($text)
{
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/\s+/', '_', $text);

    return trim($text, '_');
}

function generateLockId($reservation_id, $arrival)
{
    return "LOCK-" . $reservation_id . "-" . $arrival;
}

function payload_hash($payload)
{
    return md5(json_encode($payload));
}


function writeLog($message, $context = [])
{
    $logFile = __DIR__ . '/../logs/app.log';

    $date = date("Y-m-d H:i:s");

    $entry = [
        'date' => $date,
        'message' => $message,
        'context' => $context
    ];

    file_put_contents(
        $logFile,
        json_encode($entry) . PHP_EOL,
        FILE_APPEND
    );
}
