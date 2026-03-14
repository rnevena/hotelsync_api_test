<?php

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../helpers/helpers.php';
require_once __DIR__ . '/../helpers/logger.php';

$raw = file_get_contents("php://input");

$payload = json_decode($raw, true);

if (!$payload) {

    http_response_code(400);
    echo "invalid payload";
    exit();
}


$hash = payload_hash($payload);

global $mysqli;

$stmt = $mysqli->prepare(
    "SELECT id FROM webhook_events WHERE payload_hash=?"
);

$stmt->bind_param("s", $hash);
$stmt->execute();

$result = $stmt->get_result()->fetch_assoc();

if ($result) {

    writeLog("Webhook duplicate", $payload);
    echo "already processed";
    exit();
}

$stmt = $mysqli->prepare(
    "INSERT INTO webhook_events(payload_hash,payload,processed) VALUES(?,?,0)"
);

$stmt->bind_param(
    "ss",
    $hash,
    $raw
);

$stmt->execute();

writeLog("Webhook received", $payload);

$reservationId = $payload['reservation_id'] ?? null;
$task = $payload['task'] ?? null;

if (!isset($task)) {
    echo 'undefined task parameter. please define task parameter as insert or update';
    exit();
}

// $phpBin = PHP_BIN; 

switch ($task) {
    case 'insert':

        $dateFrom = $payload['dfrom'] ?? null;
        $dateTo = $payload['dto'] ?? null;
        if (!isset($dateFrom) && isset($dateTo)) {
            echo 'undefined dfrom and dto parameters for insert';
            exit();
        }

        $script = __DIR__ . '/../scripts/sync_reservations.php';
        $GLOBALS['from'] = $dateFrom;
        $GLOBALS['to'] = $dateTo;

        require $script;

        break;
    case "update":
        if (!isset($reservationId)) {
            echo 'undefined reservationId parameter for insert';
            exit();
        }

        $script = __DIR__ . '/../scripts/update_reservation.php';
        $GLOBALS['reservation_id'] = $reservationId;
        require $script;

        // $cmd = escapeshellcmd("$phpBin $script --reservation_id=$reservationId");
        // exec($cmd, $output, $returnCode);
        // echo $output; 

        break;

}

$stmt = $mysqli->prepare(
    "UPDATE webhook_events SET processed=1 WHERE payload_hash=?"
);

$stmt->bind_param("s", $hash);
$stmt->execute();

echo "successfully completed webhook event";


// test pozivi webhooka u terminalu

// curl -X POST http://127.0.0.1/hotelsync_api/webhook/otasync.php \
// -H "Content-Type: application/json" \
// -d '{
//  "id_reservations":2700207,
//  "task":"update"
// }'

// curl -X POST http://127.0.0.1/hotelsync_api/webhook/otasync.php \
// -H "Content-Type: application/json" \
// -d '{
//  "dfrom":"2026-03-01",
//  "dto":"2026-03-31",
//  "task":"insert"
// }'