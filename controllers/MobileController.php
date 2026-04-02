<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/mobile_auth.php';

function iotzyMobileJsonResponseStatus(array $result): int
{
    return (int)($result['status'] ?? (!empty($result['success']) ? 200 : 400));
}

function iotzyMobileToggleDevice(PDO $db, int $userId, array $body): array
{
    $deviceId = (int)($body['id'] ?? $body['device_id'] ?? 0);
    $newState = isset($body['state']) ? (int)(bool)$body['state'] : null;
    if ($deviceId <= 0 || $newState === null) {
        return [
            'success' => false,
            'status' => 422,
            'error' => 'device_id dan state wajib diisi',
        ];
    }

    $trigger = trim((string)($body['trigger'] ?? 'Mobile'));
    if ($trigger === '') {
        $trigger = 'Mobile';
    }

    try {
        $db->beginTransaction();
        $stmt = $db->prepare(
            "SELECT id, name, last_state
             FROM devices
             WHERE id = ? AND user_id = ?
             FOR UPDATE"
        );
        $stmt->execute([$deviceId, $userId]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$device) {
            $db->rollBack();
            return ['success' => false, 'status' => 404, 'error' => 'Perangkat tidak ditemukan'];
        }

        $prevState = (int)$device['last_state'];
        $db->prepare(
            "UPDATE devices
             SET last_state = ?, latest_state = ?, last_seen = NOW(), last_state_changed = NOW()
             WHERE id = ?"
        )->execute([$newState, $newState, $deviceId]);

        if ($newState === 1 && $prevState === 0) {
            $db->prepare(
                "INSERT INTO device_sessions (user_id, device_id, turned_on_at, trigger_type)
                 VALUES (?, ?, NOW(), ?)"
            )->execute([$userId, $deviceId, $trigger]);
        } elseif ($newState === 0 && $prevState === 1) {
            $sessionStmt = $db->prepare(
                "SELECT id, turned_on_at
                 FROM device_sessions
                 WHERE device_id = ? AND turned_off_at IS NULL
                 ORDER BY turned_on_at DESC
                 LIMIT 1"
            );
            $sessionStmt->execute([$deviceId]);
            $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
            if ($session) {
                $duration = max(0, time() - strtotime((string)$session['turned_on_at']));
                $db->prepare(
                    "UPDATE device_sessions
                     SET turned_off_at = NOW(), duration_seconds = ?
                     WHERE id = ?"
                )->execute([$duration, (int)$session['id']]);
            }
        }

        $db->commit();
        addActivityLog(
            $userId,
            (string)$device['name'],
            $newState === 1 ? 'Dinyalakan (ON)' : 'Dimatikan (OFF)',
            $trigger,
            'info',
            $deviceId
        );

        return [
            'success' => true,
            'status' => 200,
            'device_id' => $deviceId,
            'state' => $newState,
            'name' => (string)$device['name'],
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[IoTzy Mobile API] toggle device failed: ' . $e->getMessage());
        return ['success' => false, 'status' => 500, 'error' => 'Gagal mengubah status perangkat'];
    }
}

function iotzyMobileGetLogs(PDO $db, int $userId, array $body): array
{
    $limit = max(1, min((int)($body['limit'] ?? $_GET['limit'] ?? 50), 200));
    $date = iotzyNormalizeAnalyticsDate((string)($body['date'] ?? $_GET['date'] ?? date('Y-m-d')));
    $start = $date . ' 00:00:00';
    $end = date('Y-m-d H:i:s', strtotime($start . ' +1 day'));

    $stmt = $db->prepare(
        "SELECT l.id, l.created_at, l.device_name, l.activity, l.trigger_type, l.log_type,
                l.device_id, l.sensor_id
         FROM activity_logs l
         WHERE l.user_id = ? AND l.created_at >= ? AND l.created_at < ?
         ORDER BY l.created_at DESC
         LIMIT ?"
    );
    $stmt->execute([$userId, $start, $end, $limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'success' => true,
        'logs' => array_map(
            static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'created_at' => $row['created_at'],
                    'device_name' => $row['device_name'],
                    'activity' => $row['activity'],
                    'trigger_type' => $row['trigger_type'],
                    'log_type' => $row['log_type'],
                    'device_id' => $row['device_id'] !== null ? (int)$row['device_id'] : null,
                    'sensor_id' => $row['sensor_id'] !== null ? (int)$row['sensor_id'] : null,
                ];
            },
            $rows
        ),
    ];
}

function iotzyMobileSanitizeSettingsPayload(array $body): array
{
    $lampOn = array_key_exists('lamp_on_threshold', $body) ? max(0.0, min(1.0, (float)$body['lamp_on_threshold'])) : null;
    $lampOff = array_key_exists('lamp_off_threshold', $body) ? max(0.0, min(1.0, (float)$body['lamp_off_threshold'])) : null;
    if ($lampOn !== null && $lampOff !== null && $lampOn >= $lampOff) {
        throw new RuntimeException('Threshold lampu ON harus lebih kecil dari OFF');
    }

    $fanHigh = array_key_exists('fan_temp_high', $body) ? max(-50.0, min(100.0, (float)$body['fan_temp_high'])) : null;
    $fanNormal = array_key_exists('fan_temp_normal', $body) ? max(-50.0, min(100.0, (float)$body['fan_temp_normal'])) : null;
    if ($fanHigh !== null && $fanNormal !== null && $fanNormal >= $fanHigh) {
        throw new RuntimeException('Suhu kipas normal harus lebih kecil dari suhu tinggi');
    }

    $fieldCasters = [
        'mqtt_broker' => fn($v) => substr(trim((string)$v), 0, 200),
        'mqtt_port' => fn($v) => max(1, min(65535, (int)$v)),
        'mqtt_client_id' => fn($v) => substr(trim((string)$v), 0, 100),
        'mqtt_path' => fn($v) => substr('/' . ltrim(trim((string)$v), '/'), 0, 100),
        'mqtt_use_ssl' => fn($v) => (int)(bool)$v,
        'mqtt_username' => fn($v) => substr(trim((string)$v), 0, 100),
        'telegram_chat_id' => fn($v) => substr(trim((string)$v), 0, 100),
        'automation_lamp' => fn($v) => (int)(bool)$v,
        'automation_fan' => fn($v) => (int)(bool)$v,
        'automation_lock' => fn($v) => (int)(bool)$v,
        'lamp_on_threshold' => fn($v) => $lampOn ?? max(0.0, min(1.0, (float)$v)),
        'lamp_off_threshold' => fn($v) => $lampOff ?? max(0.0, min(1.0, (float)$v)),
        'fan_temp_high' => fn($v) => $fanHigh ?? max(-50.0, min(100.0, (float)$v)),
        'fan_temp_normal' => fn($v) => $fanNormal ?? max(-50.0, min(100.0, (float)$v)),
        'lock_delay' => fn($v) => max(0, min(60000, (int)$v)),
        'theme' => fn($v) => in_array((string)$v, ['light', 'dark'], true) ? (string)$v : 'light',
        'quick_control_devices' => fn($v) => json_encode(array_values(array_unique(array_map('intval', array_filter((array)$v, fn($id) => is_numeric($id)))))),
    ];

    $normalized = [];
    foreach ($fieldCasters as $field => $caster) {
        if (array_key_exists($field, $body)) {
            $normalized[$field] = $caster($body[$field]);
        }
    }
    if (array_key_exists('mqtt_password', $body)) {
        $normalized['mqtt_password_enc'] = trim((string)$body['mqtt_password']) !== ''
            ? encodeStoredSecret((string)$body['mqtt_password'])
            : null;
    }
    if (array_key_exists('telegram_bot_token', $body)) {
        $telegramToken = trim((string)$body['telegram_bot_token']);
        $normalized['telegram_bot_token'] = $telegramToken !== '' ? encodeStoredSecret($telegramToken) : null;
    }

    return $normalized;
}

function iotzyMobileSaveSettings(PDO $db, int $userId, array $body): array
{
    iotzyEnsureUserSettingsRow($userId, $db);
    try {
        $normalized = iotzyMobileSanitizeSettingsPayload($body);
    } catch (RuntimeException $e) {
        return ['success' => false, 'status' => 422, 'error' => $e->getMessage()];
    }

    if ($normalized) {
        $sets = [];
        $values = [];
        foreach ($normalized as $field => $value) {
            $sets[] = "{$field} = ?";
            $values[] = $value;
        }
        $values[] = $userId;
        $db->prepare("UPDATE user_settings SET " . implode(', ', $sets) . " WHERE user_id = ?")->execute($values);
    }

    return ['success' => true, 'status' => 200, 'settings' => getUserSettings($userId)];
}

function iotzyMobileUpdateProfile(PDO $db, int $userId, array $body): array
{
    $fullName = trim((string)($body['full_name'] ?? ''));
    $email = trim((string)($body['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'status' => 422, 'error' => 'Format email tidak valid'];
    }

    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $userId]);
    if ($stmt->fetch()) {
        return ['success' => false, 'status' => 409, 'error' => 'Email sudah digunakan akun lain'];
    }

    $db->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?")->execute([$fullName ?: null, $email, $userId]);
    return ['success' => true, 'status' => 200, 'user' => iotzyMobileFetchUserProfile($db, $userId)];
}

function iotzyMobileChangePassword(PDO $db, int $userId, array $body): array
{
    $currentPassword = (string)($body['current_password'] ?? '');
    $newPassword = (string)($body['new_password'] ?? '');
    if ($currentPassword === '' || strlen($newPassword) < 8) {
        return ['success' => false, 'status' => 422, 'error' => 'Password baru minimal 8 karakter'];
    }

    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $hash = (string)$stmt->fetchColumn();
    if ($hash === '' || !password_verify($currentPassword, $hash)) {
        return ['success' => false, 'status' => 401, 'error' => 'Password lama tidak sesuai'];
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $userId]);
    return ['success' => true, 'status' => 200, 'message' => 'Password berhasil diubah'];
}

function iotzyMobileGetAutomationRules(PDO $db, int $userId): array
{
    $stmt = $db->prepare(
        "SELECT ar.*, s.name AS sensor_name, d.name AS device_name
         FROM automation_rules ar
         LEFT JOIN sensors s ON s.id = ar.sensor_id
         JOIN devices d ON d.id = ar.device_id
         WHERE ar.user_id = ?
         ORDER BY ar.created_at ASC"
    );
    $stmt->execute([$userId]);

    return [
        'success' => true,
        'rules' => array_map(static function (array $r): array {
            return [
                'id' => (int)$r['id'],
                'sensor_id' => $r['sensor_id'] !== null ? (int)$r['sensor_id'] : null,
                'sensor_name' => $r['sensor_name'],
                'device_id' => (int)$r['device_id'],
                'device_name' => $r['device_name'],
                'condition_type' => $r['condition_type'],
                'threshold' => $r['threshold'] !== null ? (float)$r['threshold'] : null,
                'threshold_min' => $r['threshold_min'] !== null ? (float)$r['threshold_min'] : null,
                'threshold_max' => $r['threshold_max'] !== null ? (float)$r['threshold_max'] : null,
                'action' => $r['action'],
                'delay_ms' => (int)$r['delay_ms'],
                'start_time' => $r['start_time'],
                'end_time' => $r['end_time'],
                'days' => json_decode((string)($r['days'] ?? '[]'), true) ?? [],
                'is_enabled' => (int)$r['is_enabled'],
                'from_template' => $r['from_template'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC)),
    ];
}

function iotzyMobileGetSchedules(PDO $db, int $userId): array
{
    $stmt = $db->prepare("SELECT * FROM schedules WHERE user_id = ? ORDER BY time_hhmm ASC");
    $stmt->execute([$userId]);

    return [
        'success' => true,
        'schedules' => array_map(static function (array $r): array {
            return [
                'id' => (int)$r['id'],
                'label' => $r['label'],
                'time_hhmm' => $r['time_hhmm'],
                'days' => json_decode((string)($r['days'] ?? '[]'), true) ?? [],
                'action' => $r['action'],
                'devices' => json_decode((string)($r['devices'] ?? '[]'), true) ?? [],
                'is_enabled' => (int)$r['is_enabled'],
                'created_at' => $r['created_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC)),
    ];
}

function iotzyMobileToggleAutomationRule(PDO $db, int $userId, array $body): array
{
    $ruleId = (int)($body['id'] ?? 0);
    $enabled = isset($body['is_enabled']) ? (int)(bool)$body['is_enabled'] : null;
    if ($ruleId <= 0 || $enabled === null) {
        return ['success' => false, 'status' => 422, 'error' => 'Data aturan tidak valid'];
    }
    $stmt = $db->prepare("SELECT id FROM automation_rules WHERE id = ? AND user_id = ?");
    $stmt->execute([$ruleId, $userId]);
    if (!$stmt->fetch()) {
        return ['success' => false, 'status' => 404, 'error' => 'Aturan tidak ditemukan'];
    }
    $db->prepare("UPDATE automation_rules SET is_enabled = ? WHERE id = ?")->execute([$enabled, $ruleId]);
    return ['success' => true, 'status' => 200];
}

function iotzyMobileToggleSchedule(PDO $db, int $userId, array $body): array
{
    $scheduleId = (int)($body['id'] ?? $body['schedule_id'] ?? 0);
    $enabled = isset($body['is_enabled']) ? (int)(bool)$body['is_enabled'] : (isset($body['enabled']) ? (int)(bool)$body['enabled'] : null);
    if ($scheduleId <= 0 || $enabled === null) {
        return ['success' => false, 'status' => 422, 'error' => 'Data jadwal tidak valid'];
    }
    $stmt = $db->prepare("SELECT id FROM schedules WHERE id = ? AND user_id = ?");
    $stmt->execute([$scheduleId, $userId]);
    if (!$stmt->fetch()) {
        return ['success' => false, 'status' => 404, 'error' => 'Jadwal tidak ditemukan'];
    }
    $db->prepare("UPDATE schedules SET is_enabled = ? WHERE id = ?")->execute([$enabled, $scheduleId]);
    return ['success' => true, 'status' => 200];
}

function handleMobileAction(string $action, array $body, PDO $db): void
{
    if ($action === 'mobile_login') {
        $result = iotzyMobileHandleLogin($db, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    $auth = iotzyMobileRequireAuthContext($db);
    $userId = (int)$auth['user_id'];

    if ($action === 'mobile_logout') {
        $result = iotzyMobileHandleLogout($db, $auth);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_me') {
        $profile = iotzyMobileFetchUserProfile($db, $userId);
        if (!$profile) {
            jsonOut(['success' => false, 'error' => 'User tidak ditemukan'], 404);
        }
        jsonOut(['success' => true, 'user' => $profile]);
    }

    if ($action === 'mobile_dashboard') {
        $cameraBundle = getUserCameraBundle($userId, $db, $body);
        $devices = getUserDevicesClientPayload($userId, $db);
        $sensors = getUserSensorsClientPayload($userId, $db);
        $analytics = getDailyAnalyticsHeadlineSummary($userId, date('Y-m-d'), $db, $devices, $sensors);
        $logs = iotzyMobileGetLogs($db, $userId, ['limit' => 20]);
        jsonOut([
            'success' => true,
            'devices' => $devices,
            'sensors' => $sensors,
            'cv_state' => $cameraBundle['cv_state'] ?? iotzyDefaultCvState(),
            'camera' => $cameraBundle['camera'] ?? null,
            'camera_settings' => $cameraBundle['camera_settings'] ?? null,
            'analytics_summary' => $analytics['summary'] ?? null,
            'logs' => $logs['logs'] ?? [],
        ]);
    }

    if ($action === 'mobile_devices') {
        jsonOut(['success' => true, 'devices' => getUserDevicesClientPayload($userId, $db)]);
    }

    if ($action === 'mobile_sensors') {
        jsonOut(['success' => true, 'sensors' => getUserSensorsClientPayload($userId, $db)]);
    }

    if ($action === 'mobile_logs') {
        jsonOut(iotzyMobileGetLogs($db, $userId, $body));
    }

    if ($action === 'mobile_analytics') {
        $date = trim((string)($body['date'] ?? $_GET['date'] ?? date('Y-m-d')));
        jsonOut(['success' => true, 'analytics' => getDailyAnalyticsSummary($userId, $date, $db)]);
    }

    if ($action === 'mobile_settings') {
        jsonOut(['success' => true, 'settings' => getUserSettings($userId)]);
    }

    if ($action === 'mobile_save_settings') {
        $result = iotzyMobileSaveSettings($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_update_profile') {
        $result = iotzyMobileUpdateProfile($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_change_password') {
        $result = iotzyMobileChangePassword($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_automation_rules') {
        jsonOut(iotzyMobileGetAutomationRules($db, $userId));
    }

    if ($action === 'mobile_toggle_automation_rule') {
        $result = iotzyMobileToggleAutomationRule($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_schedules') {
        jsonOut(iotzyMobileGetSchedules($db, $userId));
    }

    if ($action === 'mobile_toggle_schedule') {
        $result = iotzyMobileToggleSchedule($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_cv_rules') {
        $bundle = getUserCameraBundle($userId, $db, $body);
        jsonOut(['success' => true, 'rules' => $bundle['camera_settings']['cv_rules'] ?? iotzyDefaultCvRules()]);
    }

    if ($action === 'mobile_cv_config') {
        $bundle = getUserCameraBundle($userId, $db, $body);
        jsonOut(['success' => true, 'config' => iotzyNormalizeCvConfigFlat($bundle['camera_settings'] ?? [])]);
    }

    if ($action === 'mobile_save_cv_config') {
        $bundle = getUserCameraBundle($userId, $db, $body);
        $cameraId = (int)($bundle['camera']['id'] ?? 0);
        $config = $body['config'] ?? null;
        if (!is_array($config) || $cameraId <= 0) {
            jsonOut(['success' => false, 'error' => 'Data config CV tidak valid'], 422);
        }
        $saved = iotzyPersistCvConfig(
            $db,
            $userId,
            $cameraId,
            $config,
            getUserSettings($userId) ?? [],
            $bundle['camera_settings'] ?? []
        );
        jsonOut(['success' => true, 'config' => $saved]);
    }

    if ($action === 'mobile_camera_stream_sessions') {
        jsonOut(['success' => true, 'sessions' => getUserCameraStreamSessions($userId, $body, $db)]);
    }

    if ($action === 'mobile_toggle_device') {
        $result = iotzyMobileToggleDevice($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    jsonOut(['success' => false, 'error' => "Action '$action' unknown"], 400);
}
