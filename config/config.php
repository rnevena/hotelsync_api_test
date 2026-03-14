<?php

// podaci koje najcesce koristim su izcuveni kroz globalne promenljive
// radi centralizacije podataka koji se ponavljaju
// u produkcionim uslovima bi bilo odvojeni na razlicita mesta po nameni (baza, parametri za api)
// stavljeno u jedan config fajl radi preglednosti zadatka

global $BASE_URL, $TOKEN, $USERNAME, $PASSWORD, $KEY, $PROPERTY_ID, $DBHOST, $DBNAME, $DBUSER, $DBPASS;

$BASE_URL = "https://app.otasync.me/api/";
$TOKEN = "775580f2b13be0215b5aee08a17c7aa892ece321";
$USERNAME = 'nevena.radovanovic.999@gmail.com';
$PASSWORD = 'Rrr357mj.';
$KEY = "0f7780d37285a8b8ad8cfc98bd8b4314c7adf826";
$PROPERTY_ID = "10614";
$DBHOST = '127.0.0.1';
$DBNAME = 'otasync_sync';
$DBUSER = 'root';
$DBPASS = '';

define('PROJECT_ROOT', __DIR__);



// define('PHP_BIN', '/usr/local/bin/php'); // mac/linux
// define('PHP_BIN', 'C:\\xampp\\php\\php.exe'); // windows


?>