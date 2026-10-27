
<?php
// test-api.php - Simple test to check API accessibility
header('Content-Type: application/json');

$response = [
    'success' => true,
    'message' => 'API is accessible',
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'request_uri' => $_SERVER['REQUEST_URI'],
    'php_version' => phpversion(),
    'post_data' => $_POST,
    'get_data' => $_GET,
    'files_exist' => [
        'config' => file_exists('../config/config.php'),
        'functions' => file_exists('../includes/functions.php')
    ]
];

echo json_encode($response, JSON_PRETTY_PRINT);
?>