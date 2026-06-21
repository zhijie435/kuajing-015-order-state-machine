<?php

require_once __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$config = require __DIR__ . '/config/config.php';

use Order\Controllers\OrderController;
use Order\Controllers\ApiResponse;

try {
    $controller = new OrderController($config);

    $requestData = array_merge($_GET, $_POST);

    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $jsonData = json_decode($rawInput, true);
        if (is_array($jsonData)) {
            $requestData = array_merge($requestData, $jsonData);
        }
    }

    $action = $requestData['action'] ?? '';

    if (empty($action)) {
        echo json_encode(ApiResponse::error('缺少 action 参数', 40000), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = $controller->handleRequest($action, $requestData);

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    echo json_encode(ApiResponse::error($e->getMessage(), 50000), JSON_UNESCAPED_UNICODE);
}
