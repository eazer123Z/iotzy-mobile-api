<?php

define('IOTZY_API_ONLY', true);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/controllers/MobileController.php';

if (function_exists('registerApiErrorHandler')) {
    registerApiErrorHandler();
}

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
if ($action === '') {
    jsonOut([
        'success' => false,
        'error' => 'Action tidak ditentukan',
        'available_actions' => [
            'mobile_login',
            'mobile_logout',
            'mobile_me',
            'mobile_dashboard',
            'mobile_devices',
            'mobile_sensors',
            'mobile_logs',
            'mobile_analytics',
            'mobile_settings',
            'mobile_save_settings',
            'mobile_update_profile',
            'mobile_change_password',
            'mobile_automation_rules',
            'mobile_toggle_automation_rule',
            'mobile_schedules',
            'mobile_toggle_schedule',
            'mobile_cv_rules',
            'mobile_cv_config',
            'mobile_save_cv_config',
            'mobile_camera_stream_sessions',
            'mobile_toggle_device',
        ],
    ], 400);
}

$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody ?: '', true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
    $body = $_POST;
}

$db = getLocalDB();
if (!$db) {
    jsonOut([
        'success' => false,
        'error' => $GLOBALS['DB_LAST_ERROR'] ?? 'Database unavailable',
    ], 500);
}

handleMobileAction($action, $body, $db);
