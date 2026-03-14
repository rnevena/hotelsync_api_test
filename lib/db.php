<?php

require '../config/config.php';

$mysqli = mysqli_connect(
    $DBHOST,
    $DBUSER,
    $DBPASS,
    $DBNAME
);

if (!$mysqli) {
    die("DB connection failed: " . mysqli_connect_error());
}

// objedinjen update i insert - insert ako ne postoji zapis, update ukoliko postoji

function safe_datetime($value)
{
    if (empty($value) || strtotime($value) === false) {
        return "NULL"; // SQL NULL za prazne ili nevalidne datume
    }
    return "'" . addslashes($value) . "'"; // validan DATETIME
}

function upsert($table, $rows)
{
    global $mysqli;

    foreach ($rows as $row) {
        $columns = [];
        $values = [];

        foreach ($row as $col => $val) {

            $columns[] = "`$col`";

            // Ako je kolona DATETIME, koristi safe_datetime
            if (in_array($col, ['canceled_at', 'deleted_at', 'arrival_date', 'departure_date', 'created_at', 'updated_at'])) {
                $values[] = safe_datetime($val);
            } else {
                // ostali tipovi
                $values[] = isset($val) ? "'" . mysqli_real_escape_string($mysqli, $val) . "'" : "NULL";
            }
        }

        $columns_sql = implode(", ", $columns);
        $values_sql = implode(", ", $values);

        $update_sql = [];
        foreach ($row as $col => $val) {
            if (in_array($col, ['canceled_at', 'deleted_at', 'arrival_date', 'departure_date', 'created_at', 'updated_at'])) {
                $update_sql[] = "`$col` = " . safe_datetime($val);
            } else {
                $update_sql[] = "`$col` = " . (isset($val) ? "'" . mysqli_real_escape_string($mysqli, $val) . "'" : "NULL");
            }
        }

        $update_sql_str = implode(", ", $update_sql);

        $sql = "
            INSERT INTO `$table` ($columns_sql)
            VALUES ($values_sql)
            ON DUPLICATE KEY UPDATE $update_sql_str
        ";

        if (!mysqli_query($mysqli, $sql)) {
            throw new Exception("Upsert failed: " . mysqli_error($mysqli));
        }
    }
}

function findByReservation($tableName, $reservationId)
{

    global $mysqli;

    $stmt = $mysqli->prepare(
        "SELECT * FROM " . $tableName . " WHERE reservation_external_id = ?" // svaka bocna tabela ima res. ex. id
    );

    $stmt->bind_param("i", $reservationId);
    $stmt->execute();

    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function findByID($tableName, $searchBy, $id)
{

    global $mysqli;

    $stmt = $mysqli->prepare(
        "SELECT * FROM " . $tableName . " WHERE " . $searchBy . " = ? LIMIT 1"
    );

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $result = $stmt->get_result();
    return $result->fetch_assoc();

}

// provera/ formatiranje pred logovanje promena u audit tabeli
function compareAndLog($reservationId, $old, $new)
{

    foreach ($new as $field => $value) {

        if (!isset($old[$field])) {
            continue;
        }

        if ((string) $old[$field] !== (string) $value) {

            logChange(
                $reservationId,
                $field,
                $old[$field],
                $value
            );
        }
    }
}

// logovanje promena u audit tabeli
function logChange($reservationId, $field, $old, $new)
{
    global $mysqli;

    $stmt = $mysqli->prepare("
    INSERT INTO reservation_audit_log
    (reservation_external_id, field_name, old_value, new_value)
    VALUES (?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "isss",
        $reservationId,
        $field,
        $old,
        $new
    );

    $stmt->execute();
}

// otkazivanje rezervacije nakon sto proverom utvrdimo da je otkazana
function cancelReservationLocally($reservationId, $reservation, $existing)
{
    global $mysqli;

    $sql = "
        UPDATE reservations
        SET
        status='canceled',
        canceled_at='" . $reservation['date_canceled'] . "'
        WHERE external_id=" . $reservationId;

    mysqli_query($mysqli, $sql);

    compareAndLog(
        $reservationId,
        $existing,
        [
            'status' => 'canceled'
        ]
    );
}

// soft delete rezervacije nakon sto proverom utvrdimo da je obrisana
function deleteReservationLocally($reservationId, $reservation, $existing)
{
    global $mysqli;

    $sql = "
        UPDATE reservations
        SET
        is_deleted=1,
        date_deleted='" . $reservation['date_deleted'] . "'
        WHERE external_id=" . $reservationId;

    mysqli_query($mysqli, $sql);

    compareAndLog(
        $reservationId,
        $existing,
        [
            'status' => 'canceled'
        ]
    );
}


