<?php
/**
 * core/AIParser.php
 * ───
 * Otak Kecerdasan Buatan (AI) IoTzy.
 * Bertanggung jawab mengonversi perintah bahasa alami menjadi aksi terukur (JSON),
 * mengumpulkan seluruh konteks dashboard, dan mengeksekusi perintah ke database.
 *
 * Changelog v2:
 *  - CREATE TABLE dihapus dari hot-path (sudah di schema migration)
 *  - iotzy_validate_cv_state() untuk sanitasi input frontend
 *  - Rate limiting via tabel ai_rate_limits
 *  - Retry loop konsisten AI_MAX_RETRIES (tanpa +1)
 *  - Transaction rollback menyeluruh di execute_ai_actions
 *  - iotzy_validate_builtin_automation_column() hardened
 *  - iotzy_trim_context_for_prompt() relevance-aware
 *  - Token metrics logging tanpa DDL di runtime
 *  - sensor_readings cleanup helper
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/UserDataService.php';

// ============================================================================
// CONSTANTS
// ============================================================================
if (!defined('AI_TIMEOUT_SECONDS'))         define('AI_TIMEOUT_SECONDS',          120);
if (!defined('AI_CONNECT_TIMEOUT'))         define('AI_CONNECT_TIMEOUT',           15);
if (!defined('AI_MAX_RETRIES'))             define('AI_MAX_RETRIES',                3);
if (!defined('AI_RETRY_DELAY_MS'))          define('AI_RETRY_DELAY_MS',          1000);
if (!defined('AI_MAX_TOKENS'))              define('AI_MAX_TOKENS',              8000);
if (!defined('AI_HISTORY_KEEP'))            define('AI_HISTORY_KEEP',              60);
if (!defined('AI_HISTORY_SEND'))            define('AI_HISTORY_SEND',              10);
if (!defined('AI_HISTORY_MIN_SLIDING'))     define('AI_HISTORY_MIN_SLIDING',        4);
if (!defined('AI_HISTORY_ARCHIVE_KEEP_DAYS')) define('AI_HISTORY_ARCHIVE_KEEP_DAYS', 30);
if (!defined('AI_CHAT_MAX_MESSAGE_LEN'))    define('AI_CHAT_MAX_MESSAGE_LEN',    2000);
if (!defined('AI_CONTEXT_MAX_CHARS'))       define('AI_CONTEXT_MAX_CHARS',       9000);
if (!defined('AI_RATE_LIMIT_MAX'))          define('AI_RATE_LIMIT_MAX',            20); // max per window
if (!defined('AI_RATE_LIMIT_WINDOW_SEC'))   define('AI_RATE_LIMIT_WINDOW_SEC',     60); // window detik
if (!defined('AI_MODEL'))
    define('AI_MODEL', getenv('OPENROUTER_MODEL') ?: 'deepseek/deepseek-chat');

function iotzy_log_ai_nonfatal(string $scope, \Throwable $e): void
{
    error_log(sprintf(
        '[IoTzy AI:%s] %s in %s:%d',
        $scope,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
}

function iotzy_parse_http_status(array $headers): int
{
    foreach ($headers as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$headerLine, $m)) {
            return (int)$m[1];
        }
    }
    return 0;
}

function iotzy_call_api_via_stream(string $apiKey, array $payload): array
{
    $allowUrlFopen = ini_get('allow_url_fopen');
    if ($allowUrlFopen !== '1' && strtolower((string)$allowUrlFopen) !== 'on') {
        return ['code' => 0, 'body' => '', 'error' => 'HTTP stream transport unavailable'];
    }

    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'HTTP-Referer: ' . (defined('APP_URL') ? APP_URL : ''),
        'X-Title: ' . (defined('APP_NAME') ? APP_NAME : 'IOTZY'),
    ];
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => AI_TIMEOUT_SECONDS,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents('https://openrouter.ai/api/v1/chat/completions', false, $context);
    $responseHeaders = $http_response_header ?? [];
    $statusCode = iotzy_parse_http_status((array)$responseHeaders);
    if ($raw === false) {
        $err = error_get_last();
        return [
            'code' => $statusCode,
            'body' => '',
            'error' => $err['message'] ?? 'HTTP stream request failed',
        ];
    }
    return ['code' => $statusCode, 'body' => $raw, 'error' => ''];
}

// ============================================================================
// RATE LIMITING
// ============================================================================

/**
 * Periksa apakah user melampaui batas request dalam window waktu tertentu.
 * Menggunakan tabel ai_rate_limits yang sudah ada di schema.
 *
 * @return bool true = masih boleh, false = rate limit tercapai
 */
function iotzy_check_rate_limit(int $userId, PDO $db, string $action = 'ai_chat'): bool
{
    try {
        if (random_int(1, 50) === 1) {
            $db->prepare(
                "DELETE FROM ai_rate_limits
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)"
            )->execute();
        }

        // Hitung request dalam window aktif
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM ai_rate_limits
             WHERE user_id = ? AND action_name = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL " . (int)AI_RATE_LIMIT_WINDOW_SEC . " SECOND)"
        );
        $stmt->execute([$userId, $action]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= AI_RATE_LIMIT_MAX) {
            return false;
        }

        // Catat request baru
        $db->prepare(
            "INSERT INTO ai_rate_limits (user_id, action_name) VALUES (?, ?)"
        )->execute([$userId, $action]);

        return true;
    } catch (\Throwable $e) {
        iotzy_log_ai_nonfatal('rate_limit', $e);
        return true;
    }
}

// ============================================================================
// CV STATE VALIDATION
// ============================================================================

/**
 * Sanitasi dan validasi cv_state yang dikirim dari frontend.
 * Mencegah nilai manipulatif masuk ke context AI.
 */
function iotzy_validate_cv_state(?array $raw): array
{
    if (!is_array($raw)) {
        return iotzyDefaultCvState();
    }

    $lightConditions = ['unknown', 'dark', 'dim', 'normal', 'bright'];

    return [
        'is_active'      => (bool)($raw['is_active']      ?? false),
        'model_loaded'   => (bool)($raw['model_loaded']   ?? false),
        'person_count'   => max(0, min(100, (int)($raw['person_count'] ?? 0))),
        'brightness'     => max(0, min(100, (int)($raw['brightness']   ?? 0))),
        'light_condition' => in_array(
            strtolower((string)($raw['light_condition'] ?? 'unknown')),
            $lightConditions, true
        )
            ? strtolower((string)$raw['light_condition'])
            : 'unknown',
    ];
}

// ============================================================================
// DATA COLLECTION
// ============================================================================

function iotzy_collect_full_context(int $userId, PDO $db): array
{
    $ctx = [];

    // User + settings — fallback jika kolom CV belum ada (upgrade aman)
    try {
        $stmt = $db->prepare(
            "SELECT u.id, u.username, u.email, u.full_name, u.role, u.is_active, u.last_login, u.created_at,
                    us.mqtt_broker, us.mqtt_port, us.mqtt_use_ssl, us.mqtt_username, us.mqtt_client_id,
                    us.mqtt_path, us.telegram_chat_id,
                    us.automation_lamp, us.automation_fan, us.automation_lock,
                    us.lamp_on_threshold, us.lamp_off_threshold,
                    us.fan_temp_high, us.fan_temp_normal, us.lock_delay,
                    us.theme, us.quick_control_devices,
                    us.cv_rules, us.cv_config,
                    us.cv_min_confidence, us.cv_dark_threshold, us.cv_bright_threshold,
                    us.cv_human_rules_enabled, us.cv_light_rules_enabled
             FROM users u
             LEFT JOIN user_settings us ON us.user_id = u.id
             WHERE u.id = ?"
        );
        $stmt->execute([$userId]);
        $ctx['user'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (\PDOException $e) {
        // Fallback tanpa kolom CV (migrasi bertahap)
        $stmt = $db->prepare(
            "SELECT u.id, u.username, u.email, u.full_name, u.role, u.is_active, u.last_login, u.created_at,
                    us.mqtt_broker, us.mqtt_port, us.mqtt_use_ssl, us.mqtt_username, us.mqtt_client_id,
                    us.mqtt_path, us.telegram_chat_id,
                    us.automation_lamp, us.automation_fan, us.automation_lock,
                    us.lamp_on_threshold, us.lamp_off_threshold,
                    us.fan_temp_high, us.fan_temp_normal, us.lock_delay,
                    us.theme, us.quick_control_devices
             FROM users u
             LEFT JOIN user_settings us ON us.user_id = u.id
             WHERE u.id = ?"
        );
        $stmt->execute([$userId]);
        $ctx['user'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['cv_rules', 'cv_config', 'cv_min_confidence', 'cv_dark_threshold',
                  'cv_bright_threshold', 'cv_human_rules_enabled', 'cv_light_rules_enabled'] as $col) {
            $ctx['user'][$col] = null;
        }
    }

    $ctx['devices'] = getUserDevices($userId);
    $ctx['sensors'] = getUserSensors($userId);

    // Sensor summary 1 jam terakhir
    $stmt = $db->prepare(
        "SELECT sr.sensor_id, s.name sensor_name, s.unit,
                ROUND(AVG(sr.value), 2) avg_val,
                ROUND(MIN(sr.value), 2) min_val,
                ROUND(MAX(sr.value), 2) max_val,
                COUNT(*) total_readings
         FROM sensor_readings sr
         JOIN sensors s ON s.id = sr.sensor_id
         WHERE s.user_id = ?
           AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
         GROUP BY sr.sensor_id, s.name, s.unit"
    );
    $stmt->execute([$userId]);
    $ctx['sensor_summary_1h'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Automation rules
    $stmt = $db->prepare(
        "SELECT ar.id, ar.condition_type, ar.threshold, ar.threshold_min, ar.threshold_max,
                ar.action, ar.delay_ms, ar.is_enabled, ar.start_time, ar.end_time, ar.days,
                ar.from_template,
                s.name sensor_name, s.type sensor_type, s.unit,
                d.name device_name, d.id device_id, d.type device_type, d.is_active device_is_active
         FROM automation_rules ar
         LEFT JOIN sensors s ON s.id = ar.sensor_id
         JOIN devices d ON d.id = ar.device_id
         WHERE ar.user_id = ?
         ORDER BY ar.is_enabled DESC, ar.id ASC"
    );
    $stmt->execute([$userId]);
    $ctx['rules'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Schedules
    $stmt = $db->prepare(
        "SELECT id, label, time_hhmm, days, action, devices, is_enabled, created_at
         FROM schedules WHERE user_id = ? ORDER BY time_hhmm"
    );
    $stmt->execute([$userId]);
    $rawSched  = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $deviceMap = array_column($ctx['devices'], 'name', 'id');
    foreach ($rawSched as &$sc) {
        $ids = json_decode($sc['devices'] ?? '[]', true);
        $sc['device_names']   = array_values(array_map(fn($id) => $deviceMap[$id] ?? "Device#$id", (array)$ids));
        $sc['days_decoded']   = json_decode($sc['days'] ?? '[]', true);
        $sc['device_ids_raw'] = (array)$ids;
    }
    unset($sc);
    $ctx['schedules'] = $rawSched;

    // Camera + CV
    $cameraBundle           = getUserCameraBundle($userId, $db);
    $ctx['camera']          = $cameraBundle['camera']          ?? null;
    $ctx['camera_settings'] = $cameraBundle['camera_settings'] ?? [];
    $ctx['cv_state']        = $cameraBundle['cv_state']        ?? iotzyDefaultCvState();

    // Device sessions hari ini
    $stmt = $db->prepare(
        "SELECT d.name device_name, ds.turned_on_at, ds.turned_off_at,
                ds.duration_seconds, ds.trigger_type
         FROM device_sessions ds
         JOIN devices d ON d.id = ds.device_id
         WHERE ds.user_id = ? AND DATE(ds.turned_on_at) = CURRENT_DATE
         ORDER BY ds.turned_on_at DESC
         LIMIT 30"
    );
    $stmt->execute([$userId]);
    $ctx['device_sessions_today'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Activity logs
    $stmt = $db->prepare(
        "SELECT device_name, activity, trigger_type, log_type, created_at
         FROM activity_logs
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 25"
    );
    $stmt->execute([$userId]);
    $ctx['activity_logs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare(
        "SELECT
            COUNT(*) AS total_logs,
            COUNT(DISTINCT CASE WHEN device_id IS NOT NULL THEN device_id END) AS devices_active_today
         FROM activity_logs
         WHERE user_id = ? AND DATE(created_at) = CURRENT_DATE"
    );
    $stmt->execute([$userId]);
    $analyticsRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_logs' => 0, 'devices_active_today' => 0];

    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(duration_seconds), 0)
         FROM device_sessions
         WHERE user_id = ? AND DATE(turned_on_at) = CURRENT_DATE"
    );
    $stmt->execute([$userId]);
    $totalDurationSeconds = (int)$stmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(energy_wh), 0), COUNT(DISTINCT device_id)
         FROM device_sessions
         WHERE user_id = ? AND DATE(turned_on_at) = CURRENT_DATE"
    );
    $stmt->execute([$userId]);
    $energyRow = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];

    $devicesTotal = count($ctx['devices'] ?? []);
    $devicesActiveToday = (int)($analyticsRow['devices_active_today'] ?? 0);
    $ctx['daily_analytics'] = [
        'summary' => [
            'total_logs' => (int)($analyticsRow['total_logs'] ?? 0),
            'devices_active_today' => $devicesActiveToday,
            'devices_idle_today' => max(0, $devicesTotal - $devicesActiveToday),
            'total_duration_human' => iotzyHumanDuration($totalDurationSeconds),
            'total_energy_kwh' => round(((float)($energyRow[0] ?? 0)) / 1000, 4),
            'power_devices' => (int)($energyRow[1] ?? 0),
        ],
    ];

    // Stats (single query lebih efisien daripada N query individual)
    $stmt = $db->prepare(
        "SELECT
            COUNT(*) AS total_devices,
            SUM(CASE WHEN last_state = 1 AND is_active = 1 THEN 1 ELSE 0 END) AS devices_on,
            SUM(CASE WHEN last_state = 0 AND is_active = 1 THEN 1 ELSE 0 END) AS devices_off,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS devices_inactive
         FROM devices WHERE user_id = ?"
    );
    $stmt->execute([$userId]);
    $devStats = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare(
        "SELECT
            COUNT(*) AS total_sensors,
            SUM(CASE WHEN last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) AS sensors_online
         FROM sensors WHERE user_id = ?"
    );
    $stmt->execute([$userId]);
    $senStats = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare(
        "SELECT
            COUNT(*) AS total_rules,
            SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) AS rules_enabled
         FROM automation_rules WHERE user_id = ?"
    );
    $stmt->execute([$userId]);
    $ruleStats = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare(
        "SELECT
            COUNT(*) AS total_schedules,
            SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) AS schedules_enabled
         FROM schedules WHERE user_id = ?"
    );
    $stmt->execute([$userId]);
    $schedStats = $stmt->fetch(PDO::FETCH_ASSOC);

    $ctx['stats'] = array_merge(
        $devStats  ?: [],
        $senStats  ?: [],
        $ruleStats ?: [],
        $schedStats ?: [],
        [
            'logs_today'             => (int)($analyticsRow['total_logs'] ?? 0),
            'total_on_minutes_today' => round($totalDurationSeconds / 60, 1),
        ]
    );

    // Active sessions
    $stmt = $db->prepare(
        "SELECT ip_address, user_agent, created_at, expires_at
         FROM sessions
         WHERE user_id = ? AND expires_at > NOW()
         ORDER BY created_at DESC
         LIMIT 5"
    );
    $stmt->execute([$userId]);
    $ctx['active_sessions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $ctx;
}

// ============================================================================
// FORMAT CONTEXT
// ============================================================================

function iotzy_format_context(int $userId, PDO $db, ?float $sessionStart = null, ?array $cvState = null): string
{
    $ctx = iotzy_collect_full_context($userId, $db);
    if (!$ctx) return "Sistem error: Gagal mengambil konteks.";

    // cv_state: gabung DB + input frontend (sudah divalidasi sebelumnya)
    if ($cvState) {
        $ctx['cv_state'] = array_merge($ctx['cv_state'] ?: [], $cvState);
    }

    $sec = [];
    $u   = $ctx['user'];
    $st  = $ctx['stats'];
    $DAY = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

    $mqtt_status = $u['mqtt_broker'] ? 'Connected' : 'Disconnected';
    $tg          = $u['telegram_chat_id'] ? "chat_id:{$u['telegram_chat_id']}" : 'belum-diset';

    $auto = [];
    if ($u['automation_lamp']) $auto[] = "lamp(on≤{$u['lamp_on_threshold']} off≥{$u['lamp_off_threshold']})";
    if ($u['automation_fan'])  $auto[] = "fan(high:{$u['fan_temp_high']}°C normal:{$u['fan_temp_normal']}°C)";
    if ($u['automation_lock']) $auto[] = "lock(delay:{$u['lock_delay']}ms)";

    $start_ms       = $sessionStart ?: (!empty($ctx['active_sessions']) ? strtotime($ctx['active_sessions'][0]['created_at']) * 1000 : time() * 1000);
    $session_minutes = round(((time() * 1000) - $start_ms) / 60000);
    $sess_str        = floor($session_minutes / 60) . 'j ' . ($session_minutes % 60) . 'm';

    $sec[] = "## AKUN & DASHBOARD OVERVIEW\n"
        . "Nama:{$u['full_name']} | User:{$u['username']} | Tema:{$u['theme']}\n"
        . "MQTT Status:$mqtt_status ({$u['mqtt_broker']}) | Telegram:$tg\n"
        . "Perangkat Aktif:{$st['devices_on']} dari {$st['total_devices']}\n"
        . "Sensor Online:{$st['sensors_online']} dari {$st['total_sensors']}\n"
        . "Sesi Aktif:$sess_str sejak login\n"
        . "Auto-bawaan: " . (empty($auto) ? 'semua-nonaktif' : implode(' | ', $auto));

    $sec[] = "## STATISTIK\nDevice:{$st['total_devices']} (ON:{$st['devices_on']} OFF:{$st['devices_off']}) "
        . "Sensor:{$st['total_sensors']} Rules:{$st['total_rules']}({$st['rules_enabled']} aktif) "
        . "Jadwal:{$st['total_schedules']}({$st['schedules_enabled']} aktif) "
        . "Log:{$st['logs_today']} Nyala:{$st['total_on_minutes_today']}mnt";

    if (!empty($ctx['devices'])) {
        $rows = [];
        foreach ($ctx['devices'] as $d) {
            $lastChanged   = $d['last_state_changed'] ? ' last_changed:' . date('H:i', strtotime($d['last_state_changed'])) : '';
            $templateLabel = !empty($d['template_name'])  ? " template:{$d['template_name']}" : '';
            $controlLabel  = !empty($d['control_mode'])   ? " control:{$d['control_mode']}"  : '';
            $rows[] = "  ID:{$d['id']} \"{$d['name']}\" type:{$d['type']}{$templateLabel}{$controlLabel} "
                . "(Status:" . ($d['is_active'] ? 'Enabled' : 'Disabled') . ") "
                . "(Power:" . ($d['last_state'] ? 'ON' : 'OFF') . "){$lastChanged} "
                . "[Sub:{$d['topic_sub']} Pub:{$d['topic_pub']}]";
        }
        $sec[] = "## PERANGKAT\n" . implode("\n", $rows);
    } else {
        $sec[] = "## PERANGKAT\n  (kosong - belum ada perangkat terdaftar)";
    }

    if (!empty($ctx['sensors'])) {
        $sumMap = array_column($ctx['sensor_summary_1h'], null, 'sensor_id');
        $rows   = [];
        foreach ($ctx['sensors'] as $s) {
            $val           = $s['latest_value'] !== null ? $s['latest_value'] . $s['unit'] : 'N/A';
            $summary       = '';
            if (isset($sumMap[$s['id']])) {
                $sm      = $sumMap[$s['id']];
                $summary = " [1h: avg:{$sm['avg_val']} min:{$sm['min_val']} max:{$sm['max_val']}]";
            }
            $deviceLink    = !empty($s['device_name'])  ? " device:{$s['device_name']}" : '';
            $templateLabel = !empty($s['template_name']) ? " template:{$s['template_name']}" : '';
            $rows[] = "  ID:{$s['id']} \"{$s['name']}\" type:{$s['type']}{$templateLabel}{$deviceLink} val:{$val}{$summary} [Topic:{$s['topic']}]";
        }
        $sec[] = "## SENSOR\n" . implode("\n", $rows);
    } else {
        $sec[] = "## SENSOR\n  (kosong - belum ada sensor terdaftar)";
    }

    if (!empty($ctx['rules'])) {
        $rows = [];
        foreach ($ctx['rules'] as $r) {
            $thresh     = $r['threshold'] ?? ($r['threshold_min'] . '-' . $r['threshold_max']);
            $time_range = ($r['start_time'] && $r['end_time']) ? " [{$r['start_time']}-{$r['end_time']}]" : '';
            $rows[]     = "  ID:{$r['id']} \"{$r['sensor_name']}\" {$r['condition_type']} {$thresh}{$r['unit']} "
                . "→ \"{$r['device_name']}\" {$r['action']}{$time_range} "
                . "[" . ($r['is_enabled'] ? 'ON' : 'OFF') . "]";
        }
        $sec[] = "## RULES\n" . implode("\n", $rows);
    }

    if (!empty($ctx['schedules'])) {
        $rows = [];
        foreach ($ctx['schedules'] as $sc) {
            $dayStr = !empty($sc['days_decoded'])
                ? implode(',', array_map(fn($d) => $DAY[$d] ?? $d, $sc['days_decoded']))
                : 'setiap hari';
            $rows[] = "  ID:{$sc['id']} \"{$sc['label']}\" {$sc['time_hhmm']} ({$dayStr}) "
                . "→ " . implode(',', $sc['device_names']) . " {$sc['action']} "
                . "[" . ($sc['is_enabled'] ? 'ON' : 'OFF') . "]";
        }
        $sec[] = "## JADWAL\n" . implode("\n", $rows);
    }

    if (!empty($ctx['daily_analytics']['summary'])) {
        $da     = $ctx['daily_analytics']['summary'];
        $sec[]  = "## ANALYTICS HARI INI\n"
            . "  Aktivitas: {$da['total_logs']} | Device aktif harian: {$da['devices_active_today']} | Idle: {$da['devices_idle_today']}\n"
            . "  Durasi aktif total: {$da['total_duration_human']} | Energi: {$da['total_energy_kwh']} kWh | Power device: {$da['power_devices']}";
    }

    if (!empty($ctx['activity_logs'])) {
        $rows = [];
        foreach (array_slice($ctx['activity_logs'], 0, 10) as $l) {
            $rows[] = "  [{$l['log_type']}] " . date('H:i', strtotime($l['created_at'])) . " {$l['device_name']} {$l['activity']} (via:{$l['trigger_type']})";
        }
        $sec[] = "## LOG AKTIVITAS\n" . implode("\n", $rows);
    }

    if (!empty($ctx['cv_state'])) {
        $c     = $ctx['cv_state'];
        $sec[] = "## CAMERA & CV (DB Live)\n"
            . "  Camera: " . (($ctx['camera']['name'] ?? null) ?: 'Browser Camera') . "\n"
            . "  Model Loaded: " . (($c['model_loaded'] ?? 0) ? 'YES' : 'NO') . "\n"
            . "  Status Kamera: " . ($c['is_active'] ? 'ON' : 'OFF') . "\n"
            . "  Orang Terdeteksi: " . ($c['person_count'] ?? 0) . "\n"
            . "  Kecerahan Live: " . ($c['brightness'] ?? 0) . "% (" . ($c['light_condition'] ?? 'unknown') . ")\n"
            . "  -- CONFIG PEGAS --\n"
            . "  Min Confidence: "  . ($u['cv_min_confidence']  ?? 0.6) . "\n"
            . "  Ambang Gelap: "    . ($u['cv_dark_threshold']   ?? 0.3) . "\n"
            . "  Ambang Terang: "   . ($u['cv_bright_threshold'] ?? 0.7) . "\n"
            . "  Otomasi Orang: "   . (($u['cv_human_rules_enabled'] ?? 0) ? 'ON' : 'OFF') . "\n"
            . "  Otomasi Cahaya: "  . (($u['cv_light_rules_enabled'] ?? 0) ? 'ON' : 'OFF');
    }

    if (!empty($ctx['user']['cv_rules'])) {
        $sec[] = "## CV RULES (Current DB)\n  " . json_encode($ctx['user']['cv_rules']);
    }

    return implode("\n\n", $sec);
}

// ============================================================================
// HISTORY HELPERS
// ============================================================================

function iotzy_extract_keywords(string $text): array
{
    $text  = mb_strtolower($text);
    $text  = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
    $parts = preg_split('/\s+/', trim($text)) ?: [];
    $stop  = ['dan', 'atau', 'yang', 'di', 'ke', 'dari', 'untuk', 'dengan', 'pada', 'ini', 'itu',
               'the', 'a', 'an', 'to', 'of', 'is', 'are'];
    $keys  = [];
    foreach ($parts as $p) {
        if (mb_strlen($p) < 3 || in_array($p, $stop, true)) continue;
        $keys[$p] = true;
    }
    return array_keys($keys);
}

function iotzy_relevance_score(string $text, array $keywords): int
{
    if (!$keywords) return 0;
    $normalized = mb_strtolower($text);
    $score      = 0;
    foreach ($keywords as $kw) {
        if (str_contains($normalized, $kw)) $score += 3;
    }
    return $score;
}

function iotzy_build_history_text(array $rows): string
{
    if (empty($rows)) return '';
    return implode("\n", array_map(
        fn($h) => (($h['platform'] === 'telegram' ? '[T]' : '[W]'))
            . ' ' . ($h['sender'] === 'user' ? 'U' : 'A')
            . ": {$h['message']}",
        $rows
    ));
}

function iotzy_get_history(int $userId, PDO $db, string $command = ''): string
{
    try {
        $stmt = $db->prepare(
            "SELECT sender, message, platform, created_at
             FROM ai_chat_history
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT " . (int)AI_HISTORY_KEEP
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return '';

        // Ambil N pesan terbaru sebagai sliding window wajib
        $sliding = array_slice($rows, 0, min(AI_HISTORY_MIN_SLIDING, AI_HISTORY_SEND));
        $picked  = $sliding;

        // Relevance scoring untuk sisa history
        $keywords = iotzy_extract_keywords($command);
        $left     = array_slice($rows, count($sliding));
        $scored   = [];
        foreach ($left as $r) {
            $scored[] = [
                'score' => iotzy_relevance_score((string)($r['message'] ?? ''), $keywords),
                'row'   => $r,
            ];
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Tambah berdasarkan relevansi
        foreach ($scored as $s) {
            if (count($picked) >= AI_HISTORY_SEND) break;
            if ($s['score'] <= 0) break;
            $picked[] = $s['row'];
        }

        // Isi sisa kuota dengan pesan terlama jika masih ada slot
        if (count($picked) < AI_HISTORY_SEND) {
            $pickedKeys = array_map(fn($p) => $p['created_at'] . '|' . $p['message'], $picked);
            foreach ($rows as $r) {
                if (count($picked) >= AI_HISTORY_SEND) break;
                $key = $r['created_at'] . '|' . $r['message'];
                if (!in_array($key, $pickedKeys, true)) {
                    $picked[]      = $r;
                    $pickedKeys[]  = $key;
                }
            }
        }

        // Sort kronologis sebelum dikirim ke AI
        $picked = array_slice($picked, 0, AI_HISTORY_SEND);
        usort($picked, fn($a, $b) => strcmp((string)$a['created_at'], (string)$b['created_at']));

        return iotzy_build_history_text($picked);
    } catch (\Throwable $e) {
        iotzy_log_ai_nonfatal('history', $e);
        return '';
    }
}

// ============================================================================
// SAVE MESSAGE
// ============================================================================

function iotzy_save_message(int $userId, PDO $db, string $sender, string $msg, string $platform = 'web'): void
{
    $msg = trim($msg);
    if ($msg === '') return;
    if (mb_strlen($msg) > AI_CHAT_MAX_MESSAGE_LEN) {
        $msg = mb_substr($msg, 0, AI_CHAT_MAX_MESSAGE_LEN);
    }

    try {
        // Simpan pesan baru
        $db->prepare(
            "INSERT INTO ai_chat_history (user_id, sender, message, platform) VALUES (?, ?, ?, ?)"
        )->execute([$userId, $sender, $msg, $platform]);
    } catch (\Throwable $e) {
        iotzy_log_ai_nonfatal('history_write', $e);
        return;
    }

    try {
        // Arsip pesan yang akan dihapus (overflow)
        $db->prepare(
            "INSERT INTO ai_chat_history_archive (user_id, sender, message, platform, created_at)
             SELECT user_id, sender, message, platform, created_at
             FROM ai_chat_history
             WHERE user_id = ?
               AND id NOT IN (
                   SELECT id FROM (
                       SELECT id FROM ai_chat_history
                       WHERE user_id = ?
                       ORDER BY created_at DESC
                       LIMIT " . (int)AI_HISTORY_KEEP . "
                   ) AS tmp
               )"
        )->execute([$userId, $userId]);

        // Hapus overflow dari tabel aktif
        $db->prepare(
            "DELETE FROM ai_chat_history
             WHERE user_id = ?
               AND id NOT IN (
                   SELECT id FROM (
                       SELECT id FROM ai_chat_history
                       WHERE user_id = ?
                       ORDER BY created_at DESC
                       LIMIT " . (int)AI_HISTORY_KEEP . "
                   ) AS tmp
               )"
        )->execute([$userId, $userId]);

        // Bersihkan arsip lama (> N hari)
        $db->prepare(
            "DELETE FROM ai_chat_history_archive
             WHERE user_id = ?
               AND created_at < DATE_SUB(NOW(), INTERVAL " . (int)AI_HISTORY_ARCHIVE_KEEP_DAYS . " DAY)"
        )->execute([$userId]);
    } catch (\Throwable $e) {
        iotzy_log_ai_nonfatal('history_cleanup', $e);
    }
}

// ============================================================================
// BUILTIN AUTOMATION VALIDATION
// ============================================================================

function iotzy_validate_builtin_automation_column(?string $target): ?string
{
    static $map = [
        'lamp' => 'automation_lamp',
        'fan'  => 'automation_fan',
        'lock' => 'automation_lock',
    ];
    $target = strtolower(trim((string)$target));
    return $map[$target] ?? null;
}

// ============================================================================
// API CALL
// ============================================================================

function iotzy_call_api(string $apiKey, array $payload): array
{
    $lastErr = '';
    $useCurl = function_exists('curl_init');
    // Loop AI_MAX_RETRIES kali (bukan +1)
    for ($i = 1; $i <= AI_MAX_RETRIES; $i++) {
        error_log("[IoTzy AI] API attempt {$i}/" . AI_MAX_RETRIES);
        if ($useCurl) {
            $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_CONNECTTIMEOUT => AI_CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => AI_TIMEOUT_SECONDS,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                    'HTTP-Referer: ' . (defined('APP_URL')  ? APP_URL  : ''),
                    'X-Title: '      . (defined('APP_NAME') ? APP_NAME : 'IOTZY'),
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
            $raw  = curl_exec($ch);
            $err  = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $streamResponse = iotzy_call_api_via_stream($apiKey, $payload);
            $raw = $streamResponse['body'];
            $err = $streamResponse['error'];
            $code = (int)$streamResponse['code'];
        }

        if (!$err && $code === 200) {
            $res = json_decode($raw, true);
            $c   = $res['choices'][0]['message']['content'] ?? null;
            if ($c !== null) return ['ok' => true, 'content' => $c];
            $lastErr = $res['error']['message'] ?? 'Empty response.';
        } else {
            $lastErr = $err ?: "HTTP $code";
        }

        if ($i < AI_MAX_RETRIES) usleep(AI_RETRY_DELAY_MS * 1000);
    }
    return ['ok' => false, 'error' => $lastErr];
}

// ============================================================================
// CONTEXT TRIMMER
// ============================================================================

function iotzy_trim_context_for_prompt(string $ctxText, string $command): string
{
    if (strlen($ctxText) <= AI_CONTEXT_MAX_CHARS) return $ctxText;

    $chunks   = preg_split('/\n(?=## )/', $ctxText) ?: [$ctxText];
    $keywords = iotzy_extract_keywords($command);
    $scored   = [];
    foreach ($chunks as $idx => $chunk) {
        $base     = ($idx < 2) ? 5 : 0; // AKUN & STATISTIK selalu prioritas
        $scored[] = ['chunk' => $chunk, 'score' => $base + iotzy_relevance_score($chunk, $keywords), 'idx' => $idx];
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

    $picked = [];
    $len    = 0;
    foreach ($scored as $s) {
        $cl = strlen($s['chunk']);
        if ($len + $cl > AI_CONTEXT_MAX_CHARS) continue;
        $picked[] = $s;
        $len      += $cl + 2;
        if ($len >= AI_CONTEXT_MAX_CHARS) break;
    }

    if (!$picked) return substr($ctxText, 0, AI_CONTEXT_MAX_CHARS);

    usort($picked, fn($a, $b) => $a['idx'] <=> $b['idx']);
    return implode("\n\n", array_map(fn($s) => $s['chunk'], $picked));
}

// ============================================================================
// TOKEN METRICS
// ============================================================================

function iotzy_estimate_tokens(string $text): int
{
    return (int)ceil(strlen($text) / 4);
}

function iotzy_log_token_metrics(PDO $db, int $userId, int $promptTokens, int $historyTokens, int $contextTokens, int $responseTokens): void
{
    // Tabel sudah ada di schema migration — tidak perlu CREATE TABLE di sini
    try {
        $db->prepare(
            "INSERT INTO ai_token_metrics (user_id, prompt_tokens, history_tokens, context_tokens, response_tokens)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$userId, $promptTokens, $historyTokens, $contextTokens, $responseTokens]);
    } catch (\Throwable $e) {
        iotzy_log_ai_nonfatal('token_metrics', $e);
    }
}

// ============================================================================
// SYSTEM PROMPT BUILDER
// ============================================================================

function iotzy_build_system_prompt(string $model, string $ctxText, string $history, string $time, string $day): string
{
    $isSmall = preg_match('/(1b|2b|3b|7b|8b|mini|small|lite)/i', $model) === 1;

    $base = <<<PROMPT
OUTPUT WAJIB JSON MURNI:
{"response_text":"...","intent":"...","ui_action":"...","actions":[]}
Tanpa markdown dan tanpa teks di luar JSON.

Peran: IoTzy Assistant, bahasa Indonesia natural, ringkas, akurat, langsung eksekusi.
Gunakan ID perangkat/sensor dari data live. Jawab pertanyaan non-IoT singkat lalu kembali ke konteks smart room.
Hanya gunakan action yang valid: immediate, automation, schedule, add_device, add_sensor,
delete_*, toggle_*, update_*, cv_action, navigate, reset_system.
Konfirmasi hanya untuk operasi destruktif besar (hapus semua/reset) kecuali user sudah eksplisit.

WAKTU: {$time} ({$day})

DATA SISTEM LIVE:
{$ctxText}

RIWAYAT:
{$history}
([W]=Web [T]=Telegram | U=User A=Assistant)

REFERENSI ACTION:
immediate: {"type":"immediate","action":"on|off|toggle","device_ids":[ID]}
automation: {"type":"automation","sensor_id":ID,"condition_type":"gt|lt|between","threshold":X,"action":"on|off","device_ids":[ID]}
schedule: {"type":"schedule","label":"...","time":"HH:MM","days":[0-6],"action":"on|off","device_ids":[ID]}
add_device: {"type":"add_device","name":"...","device_type":"...","icon":"fa-...","topic_sub":"iotzy/...","topic_pub":"iotzy/..."}
add_sensor: {"type":"add_sensor","name":"...","sensor_type":"...","unit":"...","topic":"iotzy/..."}
delete: {"type":"delete_device","device_id":ID} | delete_sensor | delete_rule | delete_schedule
toggle: {"type":"toggle_device_active","device_id":ID,"is_active":true} | toggle_rule | toggle_schedule
update_thresholds: {"type":"update_thresholds","lamp_on_threshold":0.4,...}
toggle_builtin_automation: {"type":"toggle_builtin_automation","target":"lamp|fan|lock","enabled":true}
update_mqtt: {"type":"update_mqtt","mqtt_broker":"...","mqtt_port":8884,"mqtt_use_ssl":true}
update_telegram: {"type":"update_telegram","telegram_chat_id":"..."}
update_theme: {"type":"update_theme","theme":"dark|light"}
update_cv_config: {"type":"update_cv_config","min_confidence":0.8,"human_enabled":true}
update_cv_rules: {"type":"update_cv_rules","rules":{...}}
cv_action: {"type":"cv_action","action":"load_model|start_detection|stop_detection"}
navigate: {"type":"navigate","page":"dashboard|devices|sensors|automation|settings|camera"}
reset_system: {"type":"reset_system"}

Intent: kontrol_device|cek_sensor|buat_rule|jadwal|hapus|reset|navigasi|info|sapaan|analisis|konfirmasi
UI_Action: navigate_dashboard|navigate_devices|navigate_sensors|navigate_automation|navigate_settings|refresh|none
PROMPT;

    return $base . ($isSmall
        ? "\n\nModel kecil: prioritas intent utama, maksimal 2 aksi, minim narasi."
        : "\n\nModel besar: penjelasan singkat boleh di response_text, tetap fokus."
    );
}

// ============================================================================
// SENSOR READINGS CLEANUP (panggil dari cron/scheduler)
// ============================================================================

/**
 * Hapus sensor_readings yang lebih tua dari $keepDays hari.
 * Sebaiknya dipanggil dari cron harian, bukan dari request user.
 */
function iotzy_cleanup_sensor_readings(PDO $db, int $keepDays = 90): int
{
    $stmt = $db->prepare(
        "DELETE FROM sensor_readings
         WHERE recorded_at < DATE_SUB(NOW(), INTERVAL ? DAY)
         LIMIT 5000"
    );
    $stmt->execute([$keepDays]);
    return $stmt->rowCount();
}

// ============================================================================
// MAIN PARSE
// ============================================================================

function parse_nl_to_action(
    int     $userId,
    string  $command,
    array   $devices      = [],
    array   $sensors      = [],
    string  $platform     = 'web',
    ?array  $cvState      = null,
    ?float  $sessionStart = null
): array {
    $apiKey = defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '';
    if (!$apiKey) return ['success' => false, 'error' => 'API key belum dikonfigurasi.'];

    $db = getLocalDB();
    if (!$db) return ['success' => false, 'error' => 'Database tidak tersedia.'];

    // Validasi panjang pesan
    $command = trim($command);
    if ($command === '') return ['success' => false, 'error' => 'Pesan tidak boleh kosong.'];
    if (mb_strlen($command) > AI_CHAT_MAX_MESSAGE_LEN) {
        return ['success' => false, 'error' => "Pesan terlalu panjang. Maksimal " . AI_CHAT_MAX_MESSAGE_LEN . " karakter."];
    }

    // Rate limiting
    if (!iotzy_check_rate_limit($userId, $db, 'ai_chat')) {
        $msg = "⏳ Terlalu banyak permintaan. Tunggu sebentar ya!";
        return ['success' => false, 'error' => $msg];
    }

    // Sanitasi cv_state dari frontend
    $cvState = iotzy_validate_cv_state($cvState);

    iotzy_save_message($userId, $db, 'user', $command, $platform);

    try {
        $ctxText = iotzy_trim_context_for_prompt(
            iotzy_format_context($userId, $db, $sessionStart, $cvState),
            $command
        );
    } catch (\Throwable $e) {
        iotzy_log_ai_nonfatal('context', $e);
        $ctxText = "Konteks dashboard lengkap sementara tidak tersedia. Fokus pada intent pengguna, data perangkat yang disebut jelas, dan balas dengan tindakan aman.";
    }
    $history = iotzy_get_history($userId, $db, $command);
    $time    = date('Y-m-d H:i:s');
    $day     = date('l');

    // Normalisasi typo umum
    $commandClean = strtolower($command);
    if (preg_match('/\b(hapu|hapuz|hps|del|remov)\b/', $commandClean)) $commandClean .= ' (maksud user: hapus)';
    if (preg_match('/\batu[rb]?\b/',                   $commandClean)) $commandClean .= ' (maksud user: atur)';

    $sysPrompt    = iotzy_build_system_prompt(AI_MODEL, $ctxText, $history, $time, $day);
    $isSmallModel = preg_match('/(1b|2b|3b|7b|8b|mini|small|lite)/i', AI_MODEL) === 1;

    $promptTokens  = iotzy_estimate_tokens($sysPrompt . "\n" . $commandClean);
    $historyTokens = iotzy_estimate_tokens($history);
    $contextTokens = iotzy_estimate_tokens($ctxText);

    $res = iotzy_call_api($apiKey, [
        'model'       => AI_MODEL,
        'messages'    => [
            ['role' => 'system', 'content' => $sysPrompt],
            ['role' => 'user',   'content' => $commandClean],
        ],
        'temperature' => 0.1,
        'max_tokens'  => $isSmallModel ? min(2200, AI_MAX_TOKENS) : AI_MAX_TOKENS,
    ]);

    if (!$res['ok']) {
        $msg = "Koneksi AI sibuk, coba lagi ya! 🔄";
        iotzy_save_message($userId, $db, 'bot', $msg, $platform);
        return ['success' => false, 'error' => $msg];
    }

    $raw = trim($res['content']);
    error_log("[IoTzy AI] COMMAND: $command | RAW_LEN: " . strlen($raw));
    iotzy_log_token_metrics($db, $userId, $promptTokens, $historyTokens, $contextTokens, iotzy_estimate_tokens($raw));

    // Parse JSON — beberapa model kadang membungkus dengan markdown
    $raw  = preg_replace(['/^```json\s*/i', '/^```\s*/i', '/```\s*$/s'], '', $raw);
    $raw  = trim($raw);
    $json = json_decode($raw, true);

    if (!$json || json_last_error() !== JSON_ERROR_NONE) {
        if (preg_match('/({\s*[\s\S]*})/u', $raw, $m)) $json = json_decode($m[1], true);
    }
    if (!$json || json_last_error() !== JSON_ERROR_NONE) {
        $json = json_decode(preg_replace(['/,\s*}/', '/,\s*]/'], ['}', ']'], $raw), true);
    }
    if (!$json || json_last_error() !== JSON_ERROR_NONE) {
        $msg = "Gagal memproses jawaban AI. Coba kirim ulang ya 😊";
        iotzy_save_message($userId, $db, 'bot', $msg);
        return ['success' => false, 'error' => $msg, 'raw_debug' => $raw];
    }

    // Backward compat: AI kadang kirim action tunggal tanpa wrapper actions[]
    if (isset($json['type']) && !isset($json['actions'])) {
        $cloned = $json;
        unset($cloned['response_text'], $cloned['intent'], $cloned['ui_action']);
        $json['actions'] = [$cloned];
    }

    $json['response_text'] = $json['response_text'] ?? 'Siap! Perintah diproses.';
    $json['intent']        = $json['intent']        ?? 'info';
    $json['ui_action']     = $json['ui_action']     ?? 'none';
    $json['actions']       = $json['actions']       ?? [];

    iotzy_save_message($userId, $db, 'bot', $json['response_text'], $platform);
    return ['success' => true, 'data' => $json];
}

// ============================================================================
// EXECUTE ACTIONS
// ============================================================================

function execute_ai_actions(int $userId, array $parsed): array
{
    $result = ['success' => true, 'executed' => [], 'errors' => []];
    if (empty($parsed['actions'])) return $result;

    $db = getLocalDB();
    if (!$db) return ['success' => false, 'errors' => ['DB error.']];

    try {
        $db->beginTransaction();
    } catch (\Throwable $e) {
        return ['success' => false, 'errors' => ['DB transaction start failed: ' . $e->getMessage()]];
    }

    try {
        foreach ($parsed['actions'] as $a) {
            $type = $a['type'] ?? '';
            switch ($type) {

                case 'immediate':
                    if (!isset($result['device_states'])) $result['device_states'] = [];
                    foreach ((array)($a['device_ids'] ?? []) as $id) {
                        $id = (int)$id;
                        if ($id <= 0) continue;
                        if ($a['action'] === 'toggle') {
                            $db->prepare(
                                "UPDATE devices SET last_state = 1 - last_state,
                                 latest_state = 1 - last_state, last_seen = NOW()
                                 WHERE id = ? AND user_id = ?"
                            )->execute([$id, $userId]);
                            $st = $db->prepare("SELECT last_state FROM devices WHERE id = ? AND user_id = ?");
                            $st->execute([$id, $userId]);
                            $v = (int)$st->fetchColumn();
                        } else {
                            $v = $a['action'] === 'on' ? 1 : 0;
                            $db->prepare(
                                "UPDATE devices SET last_state = ?, latest_state = ?, last_seen = NOW()
                                 WHERE id = ? AND user_id = ?"
                            )->execute([$v, $v, $id, $userId]);
                        }
                        $result['device_states'][$id] = $v;
                    }
                    $result['executed'][] = 'immediate';
                    break;

                case 'schedule':
                    $startTime = $a['time'] ?? ($a['time_hhmm'] ?? '00:00');
                    // Validasi format HH:MM
                    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $startTime)) $startTime = '00:00';
                    $days = array_values(array_unique(array_map(
                        'intval',
                        array_filter((array)($a['days'] ?? [0,1,2,3,4,5,6]),
                            fn($d) => is_numeric($d) && (int)$d >= 0 && (int)$d <= 6)
                    )));
                    $deviceIds = array_values(array_unique(array_map(
                        'intval',
                        array_filter((array)($a['device_ids'] ?? []),
                            fn($id) => is_numeric($id) && (int)$id > 0)
                    )));
                    $action = in_array(($a['action'] ?? 'on'), ['on', 'off', 'toggle'], true) ? $a['action'] : 'on';
                    if (!$deviceIds) break;
                    $db->prepare(
                        "INSERT INTO schedules (user_id, label, time_hhmm, days, action, devices)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    )->execute([
                        $userId,
                        mb_substr((string)($a['label'] ?? ''), 0, 100) ?: null,
                        $startTime,
                        json_encode($days ?: [0,1,2,3,4,5,6]),
                        $action,
                        json_encode($deviceIds),
                    ]);
                    $result['executed'][] = 'schedule';
                    break;

                case 'automation':
                    $conditionType = in_array(($a['condition_type'] ?? 'gt'), ['gt','lt','between','range'], true)
                        ? $a['condition_type'] : 'gt';
                    $stmt = $db->prepare(
                        "INSERT INTO automation_rules
                         (user_id, sensor_id, device_id, condition_type, threshold, threshold_min, threshold_max,
                          action, delay_ms, start_time, end_time, days)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    foreach ((array)($a['device_ids'] ?? []) as $dId) {
                        $dId = (int)$dId;
                        if ($dId <= 0) continue;
                        $stmt->execute([
                            $userId,
                            isset($a['sensor_id']) ? (int)$a['sensor_id'] : null,
                            $dId,
                            $conditionType,
                            $a['threshold']     ?? null,
                            $a['threshold_min'] ?? null,
                            $a['threshold_max'] ?? null,
                            in_array(($a['action'] ?? 'on'), ['on','off'], true) ? $a['action'] : 'on',
                            max(0, (int)($a['delay_ms'] ?? 0)),
                            $a['start_time'] ?? null,
                            $a['end_time']   ?? null,
                            isset($a['days']) ? json_encode($a['days']) : null,
                        ]);
                    }
                    $result['executed'][] = 'automation';
                    break;

                case 'add_device':
                    $template   = resolveDeviceTemplate($db, $a['device_template_id'] ?? null, $a['template_slug'] ?? null, $a['device_type'] ?? null, $a['icon'] ?? null);
                    $deviceType = mb_substr((string)($a['device_type'] ?? ($template['device_type'] ?? 'switch')), 0, 50);
                    $deviceIcon = mb_substr((string)($a['icon']        ?? ($template['default_icon']  ?? 'fa-plug')),  0, 50);
                    $deviceKey  = mb_substr((string)($a['device_key']  ?? strtolower(str_replace(' ', '_', $a['name'])) . '_' . substr(bin2hex(random_bytes(4)), 0, 8)), 0, 100);
                    $db->prepare(
                        "INSERT INTO devices
                         (user_id, device_template_id, name, type, icon, device_key, topic_sub, topic_pub,
                          state_on_label, state_off_label)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    )->execute([
                        $userId,
                        $template['id'] ?? null,
                        mb_substr((string)($a['name'] ?? 'Device'), 0, 100),
                        $deviceType,
                        $deviceIcon,
                        $deviceKey,
                        mb_substr((string)($a['topic_sub'] ?? ''), 0, 200),
                        mb_substr((string)($a['topic_pub'] ?? ''), 0, 200),
                        $a['state_on_label']  ?? ($template['state_on_label']  ?? null),
                        $a['state_off_label'] ?? ($template['state_off_label'] ?? null),
                    ]);
                    $result['executed'][] = 'add_device';
                    break;

                case 'add_sensor':
                    $template   = resolveSensorTemplate($db, $a['sensor_template_id'] ?? null, $a['template_slug'] ?? null, $a['sensor_type'] ?? null);
                    $sensorType = mb_substr((string)($a['sensor_type'] ?? ($template['sensor_type']  ?? 'temperature')), 0, 50);
                    $sensorIcon = mb_substr((string)($a['icon']        ?? ($template['default_icon'] ?? 'fa-microchip')), 0, 50);
                    $sensorUnit = mb_substr((string)($a['unit']        ?? ($template['default_unit'] ?? '')), 0, 20);
                    $sensorKey  = mb_substr((string)($a['sensor_key']  ?? strtolower(str_replace(' ', '_', $a['name'])) . '_' . substr(bin2hex(random_bytes(4)), 0, 8)), 0, 100);
                    $db->prepare(
                        "INSERT INTO sensors
                         (user_id, device_id, sensor_template_id, name, type, icon, sensor_key, unit, topic)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    )->execute([
                        $userId,
                        isset($a['device_id']) ? (int)$a['device_id'] : null,
                        $template['id'] ?? null,
                        mb_substr((string)($a['name'] ?? 'Sensor'), 0, 100),
                        $sensorType,
                        $sensorIcon,
                        $sensorKey,
                        $sensorUnit,
                        mb_substr((string)($a['topic'] ?? ''), 0, 200),
                    ]);
                    $result['executed'][] = 'add_sensor';
                    break;

                case 'delete_device':
                    $db->prepare("DELETE FROM devices WHERE id = ? AND user_id = ?")->execute([(int)$a['device_id'], $userId]);
                    $result['executed'][] = 'del_device';
                    break;

                case 'delete_sensor':
                    $db->prepare("DELETE FROM sensors WHERE id = ? AND user_id = ?")->execute([(int)$a['sensor_id'], $userId]);
                    $result['executed'][] = 'del_sensor';
                    break;

                case 'delete_rule':
                    $db->prepare("DELETE FROM automation_rules WHERE id = ? AND user_id = ?")->execute([(int)$a['rule_id'], $userId]);
                    $result['executed'][] = 'del_rule';
                    break;

                case 'delete_schedule':
                    $db->prepare("DELETE FROM schedules WHERE id = ? AND user_id = ?")->execute([(int)$a['schedule_id'], $userId]);
                    $result['executed'][] = 'del_sched';
                    break;

                case 'toggle_device_active':
                    $db->prepare("UPDATE devices SET is_active = ? WHERE id = ? AND user_id = ?")->execute([(int)(bool)($a['is_active'] ?? true), (int)$a['device_id'], $userId]);
                    $result['executed'][] = 'toggle_dev';
                    break;

                case 'toggle_rule':
                    $db->prepare("UPDATE automation_rules SET is_enabled = ? WHERE id = ? AND user_id = ?")->execute([(int)(bool)($a['is_enabled'] ?? true), (int)$a['rule_id'], $userId]);
                    $result['executed'][] = 'toggle_rule';
                    break;

                case 'toggle_schedule':
                    $db->prepare("UPDATE schedules SET is_enabled = ? WHERE id = ? AND user_id = ?")->execute([(int)(bool)($a['is_enabled'] ?? true), (int)$a['schedule_id'], $userId]);
                    $result['executed'][] = 'toggle_sched';
                    break;

                case 'reset_system':
                    $db->prepare("DELETE FROM automation_rules WHERE user_id = ?")->execute([$userId]);
                    $db->prepare("DELETE FROM schedules        WHERE user_id = ?")->execute([$userId]);
                    $db->prepare("DELETE FROM devices          WHERE user_id = ?")->execute([$userId]);
                    $db->prepare("DELETE FROM sensors          WHERE user_id = ?")->execute([$userId]);
                    $result['executed'][] = 'reset_all';
                    break;

                case 'update_mqtt':
                    $db->prepare(
                        "UPDATE user_settings SET mqtt_broker = ?, mqtt_port = ?, mqtt_use_ssl = ?,
                         mqtt_username = ?, mqtt_path = ? WHERE user_id = ?"
                    )->execute([
                        mb_substr((string)($a['mqtt_broker'] ?? ''), 0, 200) ?: null,
                        max(1, min(65535, (int)($a['mqtt_port'] ?? 8884))),
                        (int)(bool)($a['mqtt_use_ssl'] ?? true),
                        mb_substr((string)($a['mqtt_username'] ?? ''), 0, 100) ?: null,
                        mb_substr((string)($a['mqtt_path']     ?? '/mqtt'), 0, 100),
                        $userId,
                    ]);
                    $result['executed'][] = 'mqtt';
                    break;

                case 'update_telegram':
                    $telegramToken = trim((string)($a['telegram_bot_token'] ?? ''));
                    $db->prepare(
                        "UPDATE user_settings SET telegram_chat_id = ?, telegram_bot_token = ? WHERE user_id = ?"
                    )->execute([
                        mb_substr((string)($a['telegram_chat_id'] ?? ''), 0, 100) ?: null,
                        $telegramToken !== '' ? encodeStoredSecret($telegramToken) : null,
                        $userId,
                    ]);
                    $result['executed'][] = 'telegram';
                    break;

                case 'update_thresholds':
                    $allowed = ['lamp_on_threshold', 'lamp_off_threshold', 'fan_temp_high', 'fan_temp_normal', 'lock_delay'];
                    $sets    = [];
                    $vals    = [];
                    foreach ($allowed as $f) {
                        if (array_key_exists($f, $a)) {
                            $sets[] = "$f = ?";
                            $vals[] = is_numeric($a[$f]) ? $a[$f] : null;
                        }
                    }
                    if ($sets) {
                        $vals[] = $userId;
                        $db->prepare("UPDATE user_settings SET " . implode(', ', $sets) . " WHERE user_id = ?")->execute($vals);
                    }
                    $result['executed'][] = 'thresholds';
                    break;

                case 'toggle_builtin_automation':
                    $col = iotzy_validate_builtin_automation_column($a['target'] ?? null);
                    if ($col === null) throw new \InvalidArgumentException("Invalid builtin automation target: " . ($a['target'] ?? 'null'));
                    $db->prepare("UPDATE user_settings SET {$col} = ? WHERE user_id = ?")->execute([(int)(bool)($a['enabled'] ?? true), $userId]);
                    $result['executed'][] = 'builtin';
                    break;

                case 'update_profile':
                    $allowed = ['full_name', 'email'];
                    $fields  = array_intersect_key($a['fields'] ?? [], array_flip($allowed));
                    if ($fields) {
                        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
                        $db->prepare("UPDATE users SET $sets WHERE id = ?")->execute(array_merge(array_values($fields), [$userId]));
                    }
                    $result['executed'][] = 'profile';
                    break;

                case 'update_theme':
                    $t = in_array($a['theme'] ?? '', ['light', 'dark'], true) ? $a['theme'] : 'light';
                    $db->prepare("UPDATE user_settings SET theme = ? WHERE user_id = ?")->execute([$t, $userId]);
                    $result['executed'][] = "theme:$t";
                    break;

                case 'update_cv_config':
                    $cameraBundle = getUserCameraBundle($userId, $db);
                    $cameraId = (int)($cameraBundle['camera']['id'] ?? 0);
                    iotzyPersistCvConfig(
                        $db,
                        $userId,
                        $cameraId,
                        [
                            'minConfidence' => $a['min_confidence'] ?? null,
                            'darkThreshold' => $a['dark_threshold'] ?? null,
                            'brightThreshold' => $a['bright_threshold'] ?? null,
                            'humanEnabled' => $a['human_enabled'] ?? null,
                            'lightEnabled' => $a['light_enabled'] ?? null,
                        ],
                        getUserSettings($userId) ?? null,
                        $cameraBundle['camera_settings'] ?? null
                    );
                    $result['executed'][] = 'cv_config_updated';
                    break;

                case 'update_cv_rules':
                    $cameraBundle = getUserCameraBundle($userId, $db);
                    $cameraId = (int)($cameraBundle['camera']['id'] ?? 0);
                    $oldR = array_replace_recursive(
                        iotzyDefaultCvRules(),
                        iotzyJsonDecode($cameraBundle['camera_settings']['cv_rules'] ?? null, []),
                        iotzyJsonDecode((getUserSettings($userId) ?? [])['cv_rules'] ?? null, [])
                    );
                    $newR = array_replace_recursive($oldR, (array)($a['rules'] ?? []));
                    iotzyPersistCvRules($db, $userId, $cameraId, $newR);
                    $result['executed'][] = 'cv_rules';
                    break;

                case 'cv_action':
                    $act = $a['action'] ?? '';
                    $cvUpdate = match ($act) {
                        'start_detection' => ['is_active'    => 1],
                        'stop_detection'  => ['is_active'    => 0],
                        'load_model'      => ['model_loaded' => 1],
                        default           => null,
                    };
                    if ($cvUpdate) updateUserCVState($userId, $cvUpdate, $db);
                    $result['executed'][] = "cv_action:$act";
                    break;

                case 'navigate':
                    $allowed = ['dashboard', 'devices', 'sensors', 'automation', 'settings', 'camera'];
                    $page    = in_array($a['page'] ?? '', $allowed, true) ? $a['page'] : 'dashboard';
                    $result['executed'][] = "nav:$page";
                    break;

                default:
                    $result['errors'][] = "Unknown action type: $type";
                    error_log("[IoTzy AI] Unknown action: $type");
            }
        }

        $db->commit();

    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $result['success']        = false;
        $result['errors'][]       = 'transaction: ' . $e->getMessage();
        $result['executed']       = [];
        $result['device_states']  = [];
        error_log('[IoTzy AI TX] rollback: ' . $e->getMessage());
    }

    return $result;
}
