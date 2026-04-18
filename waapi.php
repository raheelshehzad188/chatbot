<?php
/**
 * Create WaAPI instance (waapi.app).
 * Token: set env WAAPI_BEARER_TOKEN or copy waapi.local.example.php → waapi.local.php
 */
$apiToken = getenv('WAAPI_BEARER_TOKEN') ?: '';
if ($apiToken === '' && is_readable(__DIR__ . '/waapi.local.php')) {
    $loaded = include __DIR__ . '/waapi.local.php';
    if (is_string($loaded) && $loaded !== '') {
        $apiToken = $loaded;
    }
}
if (!is_string($apiToken) || trim($apiToken) === '') {
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    echo 'Configure WAAPI_BEARER_TOKEN or create waapi.local.php (see waapi.local.example.php).';
    exit;
}

$data = [
    'name' => 'Instance #88741',
    'webhook_url' => 'https://yourdomain.com/webhook',
];

$curl = curl_init();

curl_setopt_array($curl, [
    CURLOPT_URL => 'https://waapi.app/api/v1/instances',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiToken,
        'Accept: application/json',
        'Content-Type: application/json',
    ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);

curl_close($curl);

if ($err) {
    echo 'cURL Error #: ' . $err;
} else {
    $result = json_decode($response, true);
    print_r($result);
}
