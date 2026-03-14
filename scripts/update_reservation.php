<?php

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/api.php';
require_once __DIR__ . '/../helpers/helpers.php';

$options = getopt("", ["reservation_id:"]);

if (php_sapi_name() !== 'cli') {

    $options = [
        "reservation_id" => $GLOBALS['reservation_id'] ?? null,
    ];
}

if (!isset($options["reservation_id"])) {
    writeLog("update_reservations bad option parameter reservation_id", $options);
    echo "please provide the reservation ID: php update_reservation.php --reservation_id=123";
    exit();
}

$loginArray = api_request('/user/auth/login', 'POST', [
    'token' => $TOKEN,
    'username' => $USERNAME,
    'password' => $PASSWORD
]);

if (!is_array($loginArray) || !isset($loginArray['pkey'])) {
    writeLog("update_reservations login failed", $loginArray);
    echo "Login failed\n";
    exit();
}

$KEY = $loginArray['pkey'];

$reservationId = $options["reservation_id"];

$reservation = api_request(
    "/reservation/data/reservation",
    'POST',
    [
        "token" => $TOKEN,
        "key" => $KEY,
        "id_properties" => $PROPERTY_ID,
        "id_reservations" => $reservationId
    ]
);

if (!is_array($reservation) || !isset($reservation)) {
    writeLog("update_reservations bad response for reservation", $reservation);
    echo "there are no reservations for the provided ID";
    exit();
}

// 1. pronadji rezervaciju ako postoji po exId
$existingReservation = findByID('reservations', 'external_id', (int) $reservationId);

// 2. ukoliko rezervacija ne postoji, nema akcije
if (!$existingReservation) {
    writeLog("update_reservations - reservation does not exist locally");     
    echo 'this reservation does not exist locally';
    exit();
}

global $mysqli;

// mysqli transakcija zbog azuriranja bocnih reservations tabela
// ako se azurira jedna od tabela, a na sledecoj nesto nije u redu, update je delimican

mysqli_begin_transaction($mysqli);

try {

    // 3. uporedjuje se hash, ako je isti nema akcije
    $newHash = payload_hash($reservation);

    if ($existingReservation['payload_hash'] === $newHash) {
        writeLog("update_reservations - same hash no change", $reservationId);  
        mysqli_commit($mysqli);

        echo "no changes";
        exit();
    }

    // 4. ukoliko je rezervacija otkazana, azuriraj zapis
    // cancel i delete su obradjeni odvojeno od ostalih zapisa
    // zato sto nema potrebe proveravati bocne tabele i zapise
    // ako je rezervacija otkazana ili obrisana
    if (isReservationCancelled($reservation)) {

        cancelReservationLocally(
            $reservationId,
            $reservation,
            $existingReservation
        );

        mysqli_commit($mysqli);

        writeLog("update_reservations - reservation cancelled locally", $reservationId);  
        echo "reservation canceled locally";
        exit();
    }

    // 5. ukoliko je rezervacija obrisana (soft delete) azuriraj zapis
    if (isReservationDeleted($reservation)) {

        deleteReservationLocally(
            $reservationId,
            $reservation,
            $existingReservation
        );

        mysqli_commit($mysqli);

        writeLog("update_reservations - reservation soft deleted locally", $reservationId);  
        echo "reservation soft deleted locally";
        exit();
    }

    // 6. mapiranje podataka slicno kao za task 2
    // izdvojeno u lokalnu odvojenu funkciju radi citljivosti

    $mapped = mapReservationPayload($reservation);

    updateReservationIfChanged(
        $reservationId,
        $existingReservation,
        $mapped['reservation']
    );

    syncMappedData(
        'reservation_rooms',
        $reservationId,
        $mapped['rooms']
    );

    syncMappedData(
        'reservation_guests',
        $reservationId,
        $mapped['guests']
    );

    syncMappedData(
        'reservation_extras',
        $reservationId,
        $mapped['extras']
    );

    mysqli_commit($mysqli);

    writeLog("update_reservations - reservation sync finished", $reservationId);  
    echo "Reservation synced successfully\n";

} catch (Exception $e) {

    mysqli_rollback($mysqli);

    writeLog("update_reservations - reservation did not sync successfully. rollback", $reservationId);  
    echo "Error: " . $e->getMessage();
}

function isReservationCancelled($reservation)
{
    if (isset($reservation['date_canceled'])) {
        return true;
    }

    if (isset($reservation['status']) && strtolower($reservation['status']) === 'canceled') {
        return true;
    }

    return false;
}

function isReservationDeleted($reservation)
{

    if (isset($reservation['date_deleted'])) {
        return true;
    }

    if (isset($reservation['is_deleted']) && (int) $reservation['is_deleted'] == 1) {
        return true;
    }

    return false;
}


function mapReservationPayload($reservation)
{
    $data = [
        'reservation' => [],
        'rooms' => [],
        'guests' => [],
        'extras' => []
    ];

    $guestName = trim(
        ($reservation['first_name'] ?? '') . ' ' .
        ($reservation['last_name'] ?? '')
    );

    $data['reservation'] = [

        'external_id' => $reservation['id_reservations'],

        'status' => $reservation['status'],
        'canceled_at' => $reservation['date_canceled'],
        'is_deleted' => (int) $reservation['is_deleted'],
        'deleted_at' => $reservation['date_deleted'],

        'guest_name' => $guestName,
        'guest_email' => $reservation['email'] ?? null,

        'arrival_date' => $reservation['date_arrival'],
        'departure_date' => $reservation['date_departure'],

        'nights' => $reservation['nights'],

        'total_amount' => $reservation['total_price'],
        'currency' => $reservation['currency'] ?? 'EUR',

        'channel' => $reservation['channel_name'] ?? null,

        'payload_hash' => payload_hash($reservation),

        'created_at' => $reservation['date_created'],
        'updated_at' => $reservation['date_modified']
    ];

    if (!empty($reservation['rooms'])) {

        foreach ($reservation['rooms'] as $room) {

            $data['rooms'][] = [

                'external_id' => $room['id_reservations_rooms'],
                'reservation_external_id' => $reservation['id_reservations'],

                'room_external_id' => $room['id_rooms'],
                'room_type_external_id' => $room['id_room_types'],

                'room_name' => $room['name'] ?? null,

                'arrival_date' => $room['date_arrival'],
                'departure_date' => $room['date_departure'],

                'nights' => $room['nights_count'],
                'total_guests' => $room['total_guests'],

                'total_price' => $room['total_price']
            ];
        }
    }

    if (!empty($reservation['guests'])) {

        foreach ($reservation['guests'] as $guest) {

            $data['guests'][] = [

                'external_id' => $guest['id_guests'],
                'reservation_external_id' => $reservation['id_reservations'],

                'first_name' => $guest['first_name'],
                'last_name' => $guest['last_name'],

                'email' => $guest['email'] ?? null,
                'phone' => $guest['phone'] ?? null
            ];
        }
    }

    if (!empty($reservation['extras'])) {

        foreach ($reservation['extras'] as $extra) {

            $data['extras'][] = [

                'external_id' => $extra['id_reservation_extras'],
                'reservation_external_id' => $reservation['id_reservations'],

                'name' => $extra['name'],
                'quantity' => $extra['quantity'],

                'price_per_unit' => $extra['price_per_unit'],
                'total_price' => $extra['total_price']
            ];
        }
    }

    return $data;
}

function updateReservationIfChanged($reservationId, $existing, $mapped)
{

    $changes = compareRows($existing, $mapped);

    if (empty($changes)) {
        return;
    }

    compareAndLog($reservationId, $existing, $mapped);

    upsert('reservations', [$mapped]);
}

function compareRows($old, $new)
{

    $changes = [];

    foreach ($new as $field => $value) {

        if (!array_key_exists($field, $old)) {
            continue;
        }

        if ((string) $old[$field] !== (string) $value) {

            $changes[$field] = [
                'old' => $old[$field],
                'new' => $value
            ];
        }
    }

    return $changes;
}

function syncMappedData($table, $reservationId, $newRows)
{

    $existingRows = findByReservation($table, $reservationId);

    $existingMap = [];

    foreach ($existingRows as $row) {
        $existingMap[$row['external_id']] = $row; // external_id za rooms, extras, guests
    }

    foreach ($newRows as $row) {

        // lokalno mapirani zapisi o svim dodatnim podacima
        // za svaki od dodatnih zapisa provera da li se njihv external_id nalazi u postojecim zapisima 
        // ako ne postoji, dodajemo ga

        if (!isset($existingMap[$row['external_id']])) {

            upsert($table, [$row]);
            continue;
        }

        compareAndLog(
            $reservationId,
            $existingMap[$row['external_id']],
            $row
        );

        upsert($table, [$row]);
    }
}