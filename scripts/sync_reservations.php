<?php

require_once __DIR__ . '/../lib/api.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../helpers/helpers.php';


// login (da bi se dobio azurni KEY)

$loginArray = api_request('/user/auth/login', 'POST', [
    'token' => $TOKEN,
    'username' => $USERNAME,
    'password' => $PASSWORD
]);

if (!is_array($loginArray) || !isset($loginArray['pkey'])) {
    writeLog("sync_reservations login failed", $loginArray);
    echo "Login failed\n";
    exit();
}

$KEY = $loginArray['pkey'];
writeLog("sync_reservations login successful");


// from i to dobijeni iz terminala/CLI

$options = getopt("", ["from:", "to:"]);

// fallback ako je skripta pozvana iz webhooka (POST umesto terminala)
// ne prima na isti nacin parametre, tako da mora ovako
if (php_sapi_name() !== 'cli') {

    $options = [
        "from" => $GLOBALS['from'] ?? null,
        "to" => $GLOBALS['to'] ?? null
    ];
}

$from = '';
$to = '';

foreach ($options as $option) {
    $from = $options['from'];
    $to = $options['to'];
}

if ($from == '' || $to == '') {
    writeLog("sync_reservations invalid date span", $options);
    echo "please use the correct date span format: php sync_reservations.php --from=YYYY-MM-DD --to=YYYY-MM-DD\n";
    exit();
}


// dohvatanje rezervacija
// 1. dodati neophodni parametri u request-u kako bi se dobili neophodni podaci

$reservations = api_request(
    "/reservation/data/reservations",
    'POST',
    [
        "dfrom" => $from,
        "dto" => $to,
        "id_properties" => $PROPERTY_ID,
        "key" => $KEY,
        "token" => $TOKEN,
        "show_rooms" => 1,
        "show_extras" => 1,
        "show_nights" => 0,
        "rooms" => [],
        "channels" => [],
        "countries" => [],
        "order_by" => "date_created",
        "view_type" => "reservations"
    ]
);

if (!is_array($reservations) || empty($reservations)) {
    writeLog("sync_reservations bad API response", $reservations);
    echo "Bad reservations response\n";
    exit();
}

writeLog("sync_reservations received reservations", [
    'count' => count($reservations)
]);

//2. definisani nizovi za kasnije ubacivanje podataka u bazu

$mappedReservations = [];
$mappedRooms = [];
$mappedGuests = [];
$mappedExtras = [];


// mapping
// var_dump(array_map('gettype', $reservations));
// exit();


foreach ($reservations as $reservation) {

    if (!is_array($reservation)) {
        continue;
    }

    // var_dump(gettype($reservation)); exit();

    $reservationId = $reservation['id_reservations'];

    // 3. generisanje lockIDja za svaku rezervaciju. odvojeno u helper
    $lockId = generateLockId(
        $reservationId,
        $reservation['date_arrival']
    );

    // 4. generisanje payload hash-a neophodnog za zadatak 3
    $hash = payload_hash($reservation);

    $guestName = trim(
        ($reservation['first_name'] ?? '') . ' ' .
        ($reservation['last_name'] ?? '')
    );


    // 5. mapiranje osnovnih podataka iz rezeravacije

    $mappedReservations[] = [

        'external_id' => $reservationId,
        'status' => $reservation['status'],
        'guest_name' => $guestName,
        'guest_email' => $reservation['email'] ?? null,

        'arrival_date' => $reservation['date_arrival'],
        'departure_date' => $reservation['date_departure'],
        'nights' => $reservation['nights'],

        'total_amount' => $reservation['total_price'],
        'currency' => $reservation['currency'] ?? 'EUR',

        'channel' => $reservation['channel_name'] ?? null,

        'lock_id' => $lockId,
        'payload_hash' => $hash,

        'created_at' => $reservation['date_created'],
        'updated_at' => $reservation['date_modified']
    ];


    // 6. mapiranje podataka koji se odnose na sobe

    if (!empty($reservation['rooms'])) {

        foreach ($reservation['rooms'] as $room) {

            $roomId = $room['id_reservations_rooms'];

            $mappedRooms[] = [

                'external_id' => $roomId,
                'reservation_external_id' => $reservationId,

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


    // 7. mapiranje podataka koji se odnose na goste.
    // u response-u sa demo podacima ih ne dobijam (guests niz), tako da su trenutno prazni

    if (!empty($reservation['guests'])) {

        foreach ($reservation['guests'] as $guest) {

            $mappedGuests[] = [

                'external_id' => $guest['id_guests'],
                'reservation_external_id' => $reservationId,

                'first_name' => $guest['first_name'],
                'last_name' => $guest['last_name'],

                'email' => $guest['email'] ?? null,
                'phone' => $guest['phone'] ?? null
            ];
        }
    }


    // 8. mapiranje podataka o naplativim dodacima

    if (!empty($reservation['extras'])) {

        foreach ($reservation['extras'] as $extra) {

            $mappedExtras[] = [

                'external_id' => $extra['id_reservation_extras'],
                'reservation_external_id' => $reservationId,

                'name' => $extra['name'],
                'quantity' => $extra['quantity'],

                'price_per_unit' => $extra['price_per_unit'],
                'total_price' => $extra['total_price']
            ];
        }
    }

}

//9. unos u bazu

// napomena: iskoriscena je ista funkcija za insert/update kao u tasku 1
// razlog je da se ne bi duplirali podaci u bazi prilikom internog testiranja
// odnosno prilikom okidanja skripte vise puta
// * update/cancel iz taska 3 je odvojeno obradjen, po uputstvu iz taska *
// * u namenskoj skripti update_reservation.php *

if (!empty($mappedReservations)) {
    upsert('reservations', $mappedReservations);
}

if (!empty($mappedRooms)) {
    upsert('reservation_rooms', $mappedRooms);
}

if (!empty($mappedGuests)) {
    upsert('reservation_guests', $mappedGuests);
}

if (!empty($mappedExtras)) {
    upsert('reservation_extras', $mappedExtras);
}

writeLog("sync_reservations finished", [
    'reservations' => count($mappedReservations),
    'rooms' => count($mappedRooms),
    'extras' => count($mappedExtras)
]);


echo "reservations synced successfully!";
exit();