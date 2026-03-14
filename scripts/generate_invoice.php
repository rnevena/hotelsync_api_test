<?php

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../helpers/helpers.php';


$options = getopt("", ["reservation_id:"]);

if (!isset($options['reservation_id'])) {

    writeLog("generate_invoice missing reservation_id parameter");
    echo "please provide the reservation ID: php generate_invoice.php --reservation_id=xxxx";
    exit();
}

$reservationId = (int) $options['reservation_id'];

$reservation = findByID('reservations', 'external_id', $reservationId);

if (!$reservation) {

    writeLog("generate_invoice reservation not found locally", $reservationId);
    echo "there are no reservations locally for the provided ID";
    exit();
}

global $mysqli;

// kreiranje invoice number
// koristi se transakcija u slucaju da se vise faktura koristi u isto vreme

mysqli_begin_transaction($mysqli);

writeLog("invoice sequence transaction started");

try {

    $year = date("Y");

    // preuzima poslednji broj godine i zakljucava red
    // dok je red zakljucan, drugi proces ceka, sloj zastite za duplikate
    $stmt = $mysqli->prepare(
        "SELECT last_number FROM invoice_sequence WHERE year=? FOR UPDATE"
    );

    $stmt->bind_param("i", $year);
    $stmt->execute();

    $result = $stmt->get_result()->fetch_assoc();

    if (!$result) {

    // ako red ne postoji, to znaci da je prvi invoice u godini, kreiramo novi zapis
        writeLog("no invoice sequence found for year, creating new");

        $number = 1;

        $stmt = $mysqli->prepare(
            "INSERT INTO invoice_sequence(year,last_number) VALUES(?,?)"
        );

        $stmt->bind_param("ii", $year, $number);
        $stmt->execute();

    } else {

        $number = $result['last_number'] + 1;

        $stmt = $mysqli->prepare(
            "UPDATE invoice_sequence SET last_number=? WHERE year=?"
        );

        $stmt->bind_param("ii", $number, $year);
        $stmt->execute();
    }

    mysqli_commit($mysqli);

    writeLog("invoice sequence transaction committed", [
        'year' => $year,
        'number' => $number
    ]);

} catch (Exception $e) {

    mysqli_rollback($mysqli);

    writeLog("invoice sequence transaction rolled back", [
        'error' => $e->getMessage()
    ]);

    throw $e;
}

$invoiceNumber = "HS-INV-" . $year . "-" . str_pad($number, 6, "0", STR_PAD_LEFT);

// invoice payload - formatiramo payload
// i podatke upisujemo u invoice queue tabelu informativno

$rooms = findByReservation('reservation_rooms', $reservationId);
$extras = findByReservation('reservation_extras', $reservationId);

$lineItems = [];

foreach ($rooms as $room) {

    $lineItems[] = [
        'name' => $room['room_name'],
        'price' => $room['total_price']
    ];
}

foreach ($extras as $extra) {

    $lineItems[] = [
        'name' => $extra['name'],
        'price' => $extra['total_price']
    ];
}

// samo payload

$payload = [

    'invoice_number' => $invoiceNumber,
    'reservation_external_id' => $reservationId,

    'guest_name' => $reservation['guest_name'],

    'arrival_date' => $reservation['arrival_date'],
    'departure_date' => $reservation['departure_date'],

    'line_items' => $lineItems,

    'total_amount' => $reservation['total_amount'],
    'currency' => $reservation['currency']
];


// payload i ostali podaci koje upisujemo u tabelu invoice queue

$queueRow = [

    'invoice_number' => $invoiceNumber,
    'reservation_external_id' => $reservationId,

    'guest_name' => $reservation['guest_name'],

    'arrival_date' => $reservation['arrival_date'],
    'departure_date' => $reservation['departure_date'],

    'payload' => json_encode($payload),

    'total_amount' => $reservation['total_amount'],
    'currency' => $reservation['currency'],

    'status' => 'pending',
    'retry_count' => 0
];


upsert('invoice_queue', [$queueRow]);

writeLog("invoice queued", $payload);

// kontrola upisa invoice-a
// gore smo pripremali podatke i upisivali sve osim broja fakture

$maxRetries = 5;

$success = false;

for ($i = 1; $i <= $maxRetries; $i++) {

    writeLog("sending invoice attempt " . $i, $payload);

    $success = sendInvoice($payload); // success sve dok nije 5

    if ($success) {

    // ako je uspesno, upisujemo status sent / simuliramo slanje ka eksternom servisu
        mysqli_query($mysqli, "
        UPDATE invoice_queue
        SET status='sent',retry_count=" . $i . ",updated_at=NOW()
        WHERE invoice_number='" . $invoiceNumber . "'
        ");

        writeLog("invoice sent successfully", [
            'invoice_number' => $invoiceNumber,
            'attempt' => $i
        ]);

        echo "Invoice sent\n";

        break;
    }
}

// ako simulirano slanje nije uspesno, stavljamo status failed, i ne mozemo da probamo opet
if (!$success) {

    mysqli_query($mysqli, "
    UPDATE invoice_queue
    SET status='failed',retry_count=" . $maxRetries . "
    WHERE invoice_number='" . $invoiceNumber . "'
    ");

    writeLog("invoice failed after retries", [
        'invoice_number' => $invoiceNumber,
        'attempts' => $maxRetries
    ]);

    echo "Invoice failed\n";
}

function sendInvoice($payload)
{
    // simulacija gde payload koji smo upisali u bazu saljemo nekom eksternom servisu
    return rand(0, 1);
}