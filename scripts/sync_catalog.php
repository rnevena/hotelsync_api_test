<?php

require '../config/config.php';
require '../lib/api.php';
require '../lib/db.php';
require '../helpers/helpers.php';

$loginArray = api_request('/user/auth/login', 'POST', [
    'token' => $TOKEN,
    'username' => $USERNAME,
    'password' => $PASSWORD
]);

// provera responsa i vracenih podataka je nakon svakog poslatog requesta
// u slucajevima kada je los request, servis nije dostupan, i sl.

if (is_array($loginArray) && !empty($loginArray)) {

    if (isset($loginArray['pkey'])) {
        $KEY = $loginArray['pkey'];
    }
    writeLog("sync_catalog API login successful");
} else {
    echo "couldn't log in";
    exit();
}

// preuzimanje podataka

// 1. rooms - preuzimanje

$rooms = api_request('/room/data/rooms', 'POST', [
    'id_properties' => $PROPERTY_ID,
    'key' => $KEY,
    'token' => $TOKEN,
    'type' => 1,
    'details' => 0
]);


// var_dump($rooms); exit();
if (!is_array($rooms) || empty($rooms)) {
    writeLog("sync_catalog bad response for rooms", $rooms);
    return "bad response for rooms";
}

writeLog("sync_catalog fetched rooms", $rooms);

// rooms - mapiranje 

$mappedRooms = [];

foreach ($rooms as $room) {

    $roomId = $room['id_room_types'];
    $name = $room['name'];

    $slug = slugify($name);

    $codeRoom = "HS-" . $roomId . "-" . $slug;

    // priprema za update/insert
    $mappedRooms[] = [
        'external_id' => (int) $roomId, // u bazi je podesen int
        'name' => $name,
        'slug' => $slug,
        'code' => $codeRoom
    ];

    // echo $code . PHP_EOL;
}

// 2. boards - preuzimanje

$boards = api_request('/boards/data/boards', 'POST', [
    'key' => $KEY,
    'token' => $TOKEN,
    'id_properties' => $PROPERTY_ID
]);

if (!is_array($boards) || empty($boards)) {
    writeLog("sync_catalog bad response for boards", $boards);
    return "bad response for boards";
}

writeLog("sync_catalog fetched boards", $boards);

// boards - mapiranje

$boardMap = []; // za mapiranje naziva po id-ju
$mappedBoards = []; // priprema za bazu

foreach ($boards as $board) {
    $boardMap[$board['id_boards']] = $board['name'];

    $mappedBoards[] = [
        'external_id' => $board['id_boards'],
        'name' => $board['name']
    ];
}

// 3. plans - preuzimanje

$plans = api_request('/pricingPlan/data/pricing_plans', 'POST', [
    'key' => $KEY,
    'token' => $TOKEN,
    'id_properties' => $PROPERTY_ID
]);

if (!is_array($plans) || empty($plans)) {
    writeLog("sync_catalog bad response for pricing plans", $plans);
    return "bad response for plans";
}

writeLog("sync_catalog fetched plans", $plans);

// plans - mapiranje

$mappedPlans = [];

foreach ($plans as $plan) {

    $planId = $plan['id_pricing_plans'];
    $boardId = $plan['id_boards'];

    $meal = slugify($boardMap[$boardId]);

    $codeRatePlan = "RP-" . $planId . "-" . $meal;

    $mappedPlans[] = [
        'external_id' => (int) $planId,
        'name' => $plan['name'],
        'board_external_id' => (int) $boardId,
        'meal_plan' => $meal,
        'code' => $codeRatePlan
    ];

    // echo $code . PHP_EOL;
}

// dodavanje i azuriranje podataka
// external id je podatak po kome se gleda da li je podatak upisan

upsert('rooms', $mappedRooms);
writeLog("sync_catalog inserted/updated rooms");

upsert('rate_plans', $mappedPlans);
writeLog("sync_catalog inserted/updated rate plans");

upsert('boards', $mappedBoards);
writeLog("sync_catalog inserted/updated boards");


