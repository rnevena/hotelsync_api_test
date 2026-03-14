<?php


function api_request($url, $method = 'GET', $data = null, $custom_headers = [])
{

    require '../config/config.php';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $BASE_URL . $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

    // priprema body samo ako ima podatke
    $body = null;
    if ($data !== null) {
        $body = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        // ako nije proslednjen Content-Type, dodaj JSON
        $has_content_type = false;
        foreach ($custom_headers as $h) {
            if (stripos($h, 'Content-Type:') === 0)
                $has_content_type = true;
        }
        if (!$has_content_type) {
            $custom_headers[] = 'Content-Type: application/json';
        }
    }

    // dodaj header-e ako ih ima
    if (!empty($custom_headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $custom_headers);
    }

    // izvrsavanje
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo "cURL error: " . curl_error($ch);
        return null;
    }

    $decoded = json_decode($response, true);
    return $decoded ?? $response;
}


