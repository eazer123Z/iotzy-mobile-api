<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/mobile_auth.php';

function iotzyMobileJsonResponseStatus(array $result): int
{
    return (int)($result['status'] ?? (!empty($result['success']) ? 200 : 400));
}

function iotzyNormalizeScheduleDays(mixed $days): array
{
    $normalized = [];
    foreach ((array)$days as $day) {
        if (!is_numeric($day)) {
            continue;
        }
        $day = (int)$day;
        if ($day < 0 || $day > 6) {
            continue;
        }
        $normalized[$day] = $day;
    }
    return array_values($normalized);
}

function iotzyResolveOwnedDeviceIds(PDO $db, int $userId, mixed $deviceIds): array
{
    $deviceIds = array_values(array_unique(array_map(
        'intval',
        array_filter((array)$deviceIds, static fn($id) => is_numeric($id) && (int)$id > 0)
    )));
    if (!$deviceIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
    $stmt = $db->prepare("SELECT id FROM devices WHERE user_id = ? AND id IN ($placeholders)");
    $stmt->execute(array_merge([$userId], $deviceIds));
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function iotzyMobileGetDeviceTemplates(PDO $db): array
{
    return [
        'success' => true,
        'templates' => getUserDeviceTemplates($db),
    ];
}

function iotzyMobileGetSensorTemplates(PDO $db): array
{
    return [
        'success' => true,
        'templates' => getUserSensorTemplates($db),
    ];
}

function iotzyMobileAddDevice(PDO $db, int $userId, array $body): array
{
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        return ['success' => false, 'status' => 422, 'error' => 'Nama perangkat tidak boleh kosong'];
    }

    $template = resolveDeviceTemplate(
        $db,
        $body['device_template_id'] ?? null,
        $body['template_slug'] ?? null,
        $body['type'] ?? null,
        $body['icon'] ?? null
    );

    $type = trim((string)($body['type'] ?? ''));
    if ($type === '') {
        $type = $template['device_type'] ?? 'switch';
    }
    $icon = trim((string)($body['icon'] ?? ''));
    if ($icon === '') {
        $icon = $template['default_icon'] ?? 'fa-plug';
    }

    $stateOnLabel = trim((string)($body['state_on_label'] ?? ($template['state_on_label'] ?? '')));
    $stateOffLabel = trim((string)($body['state_off_label'] ?? ($template['state_off_label'] ?? '')));
    $topicSub = trim((string)($body['topic_sub'] ?? ''));
    $topicPub = trim((string)($body['topic_pub'] ?? ''));
    $controlValue = array_key_exists('control_value', $body) && $body['control_value'] !== ''
        ? (float)$body['control_value']
        : null;
    $controlText = trim((string)($body['control_text'] ?? ''));

    $deviceKeyBase = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($name)) ?: 'device';
    $deviceKey = $deviceKeyBase . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

    $newId = dbInsert(
        "INSERT INTO devices (
            user_id, device_template_id, device_key, name, icon, type, topic_sub, topic_pub,
            control_value, control_text, state_on_label, state_off_label
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $userId,
            $template['id'] ?? null,
            $deviceKey,
            $name,
            $icon,
            $type,
            $topicSub !== '' ? $topicSub : null,
            $topicPub !== '' ? $topicPub : null,
            $controlValue,
            $controlText !== '' ? $controlText : null,
            $stateOnLabel !== '' ? $stateOnLabel : null,
            $stateOffLabel !== '' ? $stateOffLabel : null,
        ]
    );

    addActivityLog(
        $userId,
        $name,
        'Perangkat baru ditambahkan',
        'Mobile',
        'success',
        $newId,
        null,
        ['template_slug' => $template['slug'] ?? null, 'type' => $type]
    );

    return [
        'success' => true,
        'status' => 200,
        'id' => $newId,
        'device_key' => $deviceKey,
        'message' => 'Perangkat berhasil ditambahkan',
    ];
}

function iotzyMobileUpdateDevice(PDO $db, int $userId, array $body): array
{
    $deviceId = (int)($body['id'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    if ($deviceId <= 0 || $name === '') {
        return ['success' => false, 'status' => 422, 'error' => 'ID atau nama perangkat tidak valid'];
    }

    $stmt = $db->prepare("SELECT * FROM devices WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$deviceId, $userId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        return ['success' => false, 'status' => 404, 'error' => 'Perangkat tidak ditemukan'];
    }

    $template = resolveDeviceTemplate(
        $db,
        $body['device_template_id'] ?? $existing['device_template_id'] ?? null,
        $body['template_slug'] ?? null,
        $body['type'] ?? $existing['type'] ?? null,
        $body['icon'] ?? $existing['icon'] ?? null
    );

    $type = trim((string)($body['type'] ?? $existing['type'] ?? ''));
    if ($type === '') {
        $type = $template['device_type'] ?? 'switch';
    }
    $icon = trim((string)($body['icon'] ?? $existing['icon'] ?? ''));
    if ($icon === '') {
        $icon = $template['default_icon'] ?? 'fa-plug';
    }

    $topicSub = trim((string)($body['topic_sub'] ?? $existing['topic_sub'] ?? ''));
    $topicPub = trim((string)($body['topic_pub'] ?? $existing['topic_pub'] ?? ''));
    $controlValue = array_key_exists('control_value', $body) && $body['control_value'] !== ''
        ? (float)$body['control_value']
        : ($existing['control_value'] !== null ? (float)$existing['control_value'] : null);
    $controlText = trim((string)($body['control_text'] ?? $existing['control_text'] ?? ''));
    $stateOnLabel = trim((string)($body['state_on_label'] ?? $existing['state_on_label'] ?? ($template['state_on_label'] ?? '')));
    $stateOffLabel = trim((string)($body['state_off_label'] ?? $existing['state_off_label'] ?? ($template['state_off_label'] ?? '')));

    dbWrite(
        "UPDATE devices
         SET device_template_id = ?, name = ?, icon = ?, type = ?, topic_sub = ?, topic_pub = ?,
             control_value = ?, control_text = ?, state_on_label = ?, state_off_label = ?
         WHERE id = ? AND user_id = ?",
        [
            $template['id'] ?? null,
            $name,
            $icon,
            $type,
            $topicSub !== '' ? $topicSub : null,
            $topicPub !== '' ? $topicPub : null,
            $controlValue,
            $controlText !== '' ? $controlText : null,
            $stateOnLabel !== '' ? $stateOnLabel : null,
            $stateOffLabel !== '' ? $stateOffLabel : null,
            $deviceId,
            $userId,
        ]
    );

    addActivityLog(
        $userId,
        $name,
        'Konfigurasi perangkat diperbarui',
        'Mobile',
        'info',
        $deviceId,
        null,
        ['template_slug' => $template['slug'] ?? null, 'type' => $type]
    );

    return ['success' => true, 'status' => 200, 'message' => 'Perangkat berhasil diperbarui'];
}

function iotzyMobileDeleteDevice(PDO $db, int $userId, array $body): array
{
    $deviceId = (int)($body['id'] ?? 0);
    if ($deviceId <= 0) {
        return ['success' => false, 'status' => 422, 'error' => 'ID perangkat tidak valid'];
    }
    $stmt = $db->prepare("SELECT name FROM devices WHERE id = ? AND user_id = ?");
    $stmt->execute([$deviceId, $userId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$device) {
        return ['success' => false, 'status' => 404, 'error' => 'Perangkat tidak ditemukan'];
    }

    dbWrite("DELETE FROM devices WHERE id = ? AND user_id = ?", [$deviceId, $userId]);
    addActivityLog($userId, $device['name'], 'Perangkat telah dihapus dari sistem', 'Mobile', 'warning', $deviceId);

    return ['success' => true, 'status' => 200, 'message' => 'Perangkat berhasil dihapus'];
}

function iotzyMobileAddSensor(PDO $db, int $userId, array $body): array
{
    $name = trim((string)($body['name'] ?? ''));
    $topic = trim((string)($body['topic'] ?? ''));
    if ($name === '' || $topic === '') {
        return ['success' => false, 'status' => 422, 'error' => 'Nama sensor dan topic MQTT harus diisi'];
    }

    $template = resolveSensorTemplate(
        $db,
        $body['sensor_template_id'] ?? null,
        $body['template_slug'] ?? null,
        $body['type'] ?? $body['sensor_type'] ?? null
    );

    $type = trim((string)($body['type'] ?? $body['sensor_type'] ?? ''));
    if ($type === '') {
        $type = $template['sensor_type'] ?? 'temperature';
    }

    $unit = trim((string)($body['unit'] ?? ''));
    if ($unit === '') {
        $unit = (string)($template['default_unit'] ?? '');
    }

    $icon = trim((string)($body['icon'] ?? ''));
    if ($icon === '') {
        $icon = (string)($template['default_icon'] ?? 'fa-microchip');
    }

    $deviceId = isset($body['device_id']) && $body['device_id'] !== '' ? (int)$body['device_id'] : null;
    if ($deviceId) {
        $stmt = $db->prepare("SELECT id FROM devices WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$deviceId, $userId]);
        if (!$stmt->fetch()) {
            $deviceId = null;
        }
    }

    $sensorKeyBase = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($name)) ?: 'sensor';
    $sensorKey = $sensorKeyBase . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

    $newId = dbInsert(
        "INSERT INTO sensors (
            user_id, device_id, sensor_template_id, sensor_key, name, type, icon, unit, topic
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $userId,
            $deviceId,
            $template['id'] ?? null,
            $sensorKey,
            $name,
            $type,
            $icon,
            $unit !== '' ? $unit : null,
            $topic,
        ]
    );

    addActivityLog(
        $userId,
        $name,
        'Sensor baru ditambahkan',
        'Mobile',
        'success',
        $deviceId,
        $newId,
        ['template_slug' => $template['slug'] ?? null, 'type' => $type]
    );

    return [
        'success' => true,
        'status' => 200,
        'id' => $newId,
        'sensor_key' => $sensorKey,
        'message' => 'Sensor berhasil disimpan',
    ];
}

function iotzyMobileUpdateSensor(PDO $db, int $userId, array $body): array
{
    $sensorId = (int)($body['id'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    $topic = trim((string)($body['topic'] ?? ''));
    if ($sensorId <= 0 || $name === '' || $topic === '') {
        return ['success' => false, 'status' => 422, 'error' => 'Data input sensor tidak lengkap'];
    }

    $stmt = $db->prepare("SELECT * FROM sensors WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$sensorId, $userId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        return ['success' => false, 'status' => 404, 'error' => 'Sensor tidak ditemukan'];
    }

    $template = resolveSensorTemplate(
        $db,
        $body['sensor_template_id'] ?? $existing['sensor_template_id'] ?? null,
        $body['template_slug'] ?? null,
        $body['type'] ?? $body['sensor_type'] ?? $existing['type'] ?? null
    );

    $type = trim((string)($body['type'] ?? $body['sensor_type'] ?? $existing['type'] ?? ''));
    if ($type === '') {
        $type = $template['sensor_type'] ?? 'temperature';
    }

    $unit = trim((string)($body['unit'] ?? $existing['unit'] ?? ''));
    if ($unit === '') {
        $unit = (string)($template['default_unit'] ?? '');
    }

    $icon = trim((string)($body['icon'] ?? $existing['icon'] ?? ''));
    if ($icon === '') {
        $icon = (string)($template['default_icon'] ?? 'fa-microchip');
    }

    $deviceId = array_key_exists('device_id', $body)
        ? (($body['device_id'] !== '' && $body['device_id'] !== null) ? (int)$body['device_id'] : null)
        : ($existing['device_id'] !== null ? (int)$existing['device_id'] : null);
    if ($deviceId) {
        $devStmt = $db->prepare("SELECT id FROM devices WHERE id = ? AND user_id = ? LIMIT 1");
        $devStmt->execute([$deviceId, $userId]);
        if (!$devStmt->fetch()) {
            $deviceId = null;
        }
    }

    dbWrite(
        "UPDATE sensors
         SET device_id = ?, sensor_template_id = ?, name = ?, type = ?, icon = ?, unit = ?, topic = ?
         WHERE id = ? AND user_id = ?",
        [
            $deviceId,
            $template['id'] ?? null,
            $name,
            $type,
            $icon,
            $unit !== '' ? $unit : null,
            $topic,
            $sensorId,
            $userId,
        ]
    );

    addActivityLog(
        $userId,
        $name,
        'Konfigurasi sensor diperbarui',
        'Mobile',
        'info',
        $deviceId,
        $sensorId,
        ['template_slug' => $template['slug'] ?? null, 'type' => $type]
    );

    return ['success' => true, 'status' => 200, 'message' => 'Sensor berhasil diperbarui'];
}

function iotzyMobileDeleteSensor(PDO $db, int $userId, array $body): array
{
    $sensorId = (int)($body['id'] ?? 0);
    if ($sensorId <= 0) {
        return ['success' => false, 'status' => 422, 'error' => 'ID sensor tidak valid'];
    }
    $stmt = $db->prepare("SELECT name, device_id FROM sensors WHERE id = ? AND user_id = ?");
    $stmt->execute([$sensorId, $userId]);
    $sensor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sensor) {
        return ['success' => false, 'status' => 404, 'error' => 'Sensor tidak ditemukan'];
    }

    dbWrite("DELETE FROM sensors WHERE id = ? AND user_id = ?", [$sensorId, $userId]);
    addActivityLog(
        $userId,
        $sensor['name'],
        'Sensor telah dihapus',
        'Mobile',
        'warning',
        $sensor['device_id'] ? (int)$sensor['device_id'] : null,
        $sensorId
    );

    return ['success' => true, 'status' => 200, 'message' => 'Sensor berhasil dihapus'];
}

function iotzyMobileAddAutomationRule(PDO $db, int $userId, array $body): array
{
    $sensorId = (int)($body['sensor_id'] ?? 0);
    $deviceId = (int)($body['device_id'] ?? 0);
    $condition = trim((string)($body['condition'] ?? $body['condition_type'] ?? ''));
    $action = trim((string)($body['action'] ?? 'on'));
    $delay = max(0, (int)($body['delay'] ?? $body['delay_ms'] ?? 0));
    $allowedConditions = ['gt', 'lt', 'range', 'between', 'detected', 'absent', 'time_only'];
    $allowedActions = ['on', 'off', 'speed_high', 'speed_mid', 'speed_low', 'toggle'];

    if ($deviceId <= 0 || !in_array($condition, $allowedConditions, true)) {
        return ['success' => false, 'status' => 422, 'error' => 'Konfigurasi aturan tidak valid'];
    }
    if ($condition !== 'time_only' && $sensorId <= 0) {
        return ['success' => false, 'status' => 422, 'error' => 'Tentukan sensor sebagai pemicu'];
    }
    if (!in_array($action, $allowedActions, true)) {
        $action = 'on';
    }

    if ($sensorId > 0) {
        $sensorStmt = $db->prepare("SELECT id FROM sensors WHERE id = ? AND user_id = ?");
        $sensorStmt->execute([$sensorId, $userId]);
        if (!$sensorStmt->fetch()) {
            return ['success' => false, 'status' => 404, 'error' => 'Sensor tidak terdaftar'];
        }
    }

    $deviceStmt = $db->prepare("SELECT id FROM devices WHERE id = ? AND user_id = ?");
    $deviceStmt->execute([$deviceId, $userId]);
    if (!$deviceStmt->fetch()) {
        return ['success' => false, 'status' => 404, 'error' => 'Perangkat tidak terdaftar'];
    }

    $threshold = isset($body['threshold']) && $body['threshold'] !== '' ? (float)$body['threshold'] : null;
    $thresholdMin = isset($body['threshold_min']) && $body['threshold_min'] !== '' ? (float)$body['threshold_min'] : null;
    $thresholdMax = isset($body['threshold_max']) && $body['threshold_max'] !== '' ? (float)$body['threshold_max'] : null;
    $days = array_key_exists('days', $body) ? iotzyNormalizeScheduleDays($body['days']) : [];
    $startTime = !empty($body['start_time']) ? trim((string)$body['start_time']) : null;
    $endTime = !empty($body['end_time']) ? trim((string)$body['end_time']) : null;
    $fromTemplate = trim((string)($body['from_template'] ?? ''));

    if (in_array($condition, ['range', 'between'], true) &&
        $thresholdMin !== null && $thresholdMax !== null && $thresholdMin >= $thresholdMax) {
        return ['success' => false, 'status' => 422, 'error' => 'Batas minimal harus lebih kecil dari maksimal'];
    }

    $newId = dbInsert(
        "INSERT INTO automation_rules (
            user_id, sensor_id, device_id, condition_type, threshold, threshold_min, threshold_max,
            action, delay_ms, start_time, end_time, days, from_template
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $userId,
            $sensorId ?: null,
            $deviceId,
            $condition,
            $threshold,
            $thresholdMin,
            $thresholdMax,
            $action,
            $delay,
            $startTime,
            $endTime,
            $days ? json_encode($days) : null,
            $fromTemplate !== '' ? $fromTemplate : null,
        ]
    );

    addActivityLog($userId, 'Otomasi', 'Aturan baru berhasil dibuat', 'Mobile', 'success');

    return [
        'success' => true,
        'status' => 200,
        'id' => $newId,
        'message' => 'Aturan berhasil ditambahkan',
    ];
}

function iotzyMobileDeleteAutomationRule(PDO $db, int $userId, array $body): array
{
    $ruleId = (int)($body['id'] ?? 0);
    if ($ruleId <= 0) {
        return ['success' => false, 'status' => 422, 'error' => 'ID aturan tidak valid'];
    }
    $stmt = $db->prepare("SELECT id FROM automation_rules WHERE id = ? AND user_id = ?");
    $stmt->execute([$ruleId, $userId]);
    if (!$stmt->fetch()) {
        return ['success' => false, 'status' => 404, 'error' => 'Aturan tidak ditemukan'];
    }
    dbWrite("DELETE FROM automation_rules WHERE id = ? AND user_id = ?", [$ruleId, $userId]);
    addActivityLog($userId, 'Otomasi', 'Aturan telah dihapus', 'Mobile', 'warning');
    return ['success' => true, 'status' => 200, 'message' => 'Aturan berhasil dihapus'];
}

function iotzyMobileAddSchedule(PDO $db, int $userId, array $body): array
{
    $time = trim((string)($body['time_hhmm'] ?? $body['time'] ?? ''));
    $days = iotzyNormalizeScheduleDays($body['days'] ?? []);
    $devices = iotzyResolveOwnedDeviceIds($db, $userId, $body['devices'] ?? []);
    $action = trim((string)($body['action'] ?? 'on'));
    $label = trim((string)($body['label'] ?? ''));

    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        return ['success' => false, 'status' => 422, 'error' => 'Format waktu harus HH:MM'];
    }
    if (!$devices) {
        return ['success' => false, 'status' => 422, 'error' => 'Pilih minimal satu perangkat'];
    }
    if (!in_array($action, ['on', 'off', 'toggle'], true)) {
        $action = 'on';
    }

    $newId = dbInsert(
        "INSERT INTO schedules (user_id, label, time_hhmm, days, action, devices)
         VALUES (?, ?, ?, ?, ?, ?)",
        [$userId, $label !== '' ? $label : null, $time, json_encode($days), $action, json_encode($devices)]
    );
    addActivityLog($userId, 'Jadwal', 'Penjadwalan baru: ' . $time, 'Mobile', 'success');
    return ['success' => true, 'status' => 200, 'id' => $newId, 'message' => 'Jadwal berhasil disimpan'];
}

function iotzyMobileDeleteSchedule(PDO $db, int $userId, array $body): array
{
    $scheduleId = (int)($body['id'] ?? $body['schedule_id'] ?? 0);
    if ($scheduleId <= 0) {
        return ['success' => false, 'status' => 422, 'error' => 'ID jadwal tidak valid'];
    }
    $stmt = $db->prepare("SELECT id FROM schedules WHERE id = ? AND user_id = ?");
    $stmt->execute([$scheduleId, $userId]);
    if (!$stmt->fetch()) {
        return ['success' => false, 'status' => 404, 'error' => 'Jadwal tidak ditemukan'];
    }
    dbWrite("DELETE FROM schedules WHERE id = ? AND user_id = ?", [$scheduleId, $userId]);
    return ['success' => true, 'status' => 200, 'message' => 'Jadwal berhasil dihapus'];
}

function iotzyMobileSaveCvRules(PDO $db, int $userId, array $body): array
{
    $bundle = getUserCameraBundle($userId, $db, $body);
    $cameraId = (int)($bundle['camera']['id'] ?? 0);
    $rules = $body['rules'] ?? null;
    if (!is_array($rules) || $cameraId <= 0) {
        return ['success' => false, 'status' => 422, 'error' => 'Data rules CV tidak valid'];
    }
    $saved = iotzyPersistCvRules(
        $db,
        $userId,
        $cameraId,
        $rules,
        getUserSettings($userId) ?? [],
        $bundle['camera_settings'] ?? []
    );
    return ['success' => true, 'status' => 200, 'rules' => $saved];
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

    if ($action === 'mobile_device_templates') {
        jsonOut(iotzyMobileGetDeviceTemplates($db));
    }

    if ($action === 'mobile_add_device') {
        $result = iotzyMobileAddDevice($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_update_device') {
        $result = iotzyMobileUpdateDevice($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_delete_device') {
        $result = iotzyMobileDeleteDevice($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_sensors') {
        jsonOut(['success' => true, 'sensors' => getUserSensorsClientPayload($userId, $db)]);
    }

    if ($action === 'mobile_sensor_templates') {
        jsonOut(iotzyMobileGetSensorTemplates($db));
    }

    if ($action === 'mobile_add_sensor') {
        $result = iotzyMobileAddSensor($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_update_sensor') {
        $result = iotzyMobileUpdateSensor($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_delete_sensor') {
        $result = iotzyMobileDeleteSensor($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
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

    if ($action === 'mobile_add_automation_rule') {
        $result = iotzyMobileAddAutomationRule($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_toggle_automation_rule') {
        $result = iotzyMobileToggleAutomationRule($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_delete_automation_rule') {
        $result = iotzyMobileDeleteAutomationRule($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_schedules') {
        jsonOut(iotzyMobileGetSchedules($db, $userId));
    }

    if ($action === 'mobile_add_schedule') {
        $result = iotzyMobileAddSchedule($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_toggle_schedule') {
        $result = iotzyMobileToggleSchedule($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_delete_schedule') {
        $result = iotzyMobileDeleteSchedule($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    if ($action === 'mobile_cv_rules') {
        $bundle = getUserCameraBundle($userId, $db, $body);
        jsonOut(['success' => true, 'rules' => $bundle['camera_settings']['cv_rules'] ?? iotzyDefaultCvRules()]);
    }

    if ($action === 'mobile_save_cv_rules') {
        $result = iotzyMobileSaveCvRules($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
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

    if ($action === 'mobile_start_camera_stream') {
        $result = startUserCameraStreamSession($userId, $body, $body, $db);
        jsonOut($result, !empty($result['success']) ? 200 : 400);
    }

    if ($action === 'mobile_join_camera_stream') {
        $streamKey = trim((string)($body['stream_key'] ?? ''));
        $result = joinUserCameraStreamSession($userId, $body, $streamKey, $db);
        jsonOut($result, !empty($result['success']) ? 200 : 400);
    }

    if ($action === 'mobile_submit_camera_stream_answer') {
        $streamKey = trim((string)($body['stream_key'] ?? ''));
        $result = submitUserCameraStreamAnswer($userId, $body, $streamKey, $body['answer_sdp'] ?? '', $db);
        jsonOut($result, !empty($result['success']) ? 200 : 400);
    }

    if ($action === 'mobile_push_camera_stream_candidate') {
        $streamKey = trim((string)($body['stream_key'] ?? ''));
        $result = pushUserCameraStreamCandidate($userId, $body, $streamKey, $body['candidate'] ?? null, $db);
        jsonOut($result, !empty($result['success']) ? 200 : 400);
    }

    if ($action === 'mobile_poll_camera_stream_updates') {
        $streamKey = trim((string)($body['stream_key'] ?? ''));
        $lastCandidateId = max(0, (int)($body['last_candidate_id'] ?? 0));
        $result = pollUserCameraStreamUpdates($userId, $body, $streamKey, $lastCandidateId, $db);
        jsonOut($result, !empty($result['success']) ? 200 : 400);
    }

    if ($action === 'mobile_stop_camera_stream') {
        $streamKey = trim((string)($body['stream_key'] ?? ''));
        $result = stopUserCameraStreamSession($userId, $body, $streamKey, $db);
        jsonOut($result, !empty($result['success']) ? 200 : 400);
    }

    if ($action === 'mobile_toggle_device') {
        $result = iotzyMobileToggleDevice($db, $userId, $body);
        $status = iotzyMobileJsonResponseStatus($result);
        unset($result['status']);
        jsonOut($result, $status);
    }

    jsonOut(['success' => false, 'error' => "Action '$action' unknown"], 400);
}
