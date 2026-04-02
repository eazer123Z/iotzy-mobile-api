<?php

require_once __DIR__ . '/bootstrap.php';

function iotzyJsonDecode(mixed $value, mixed $default = null): mixed
{
    if ($value === null || $value === '') {
        return $default;
    }
    if (is_array($value) || is_object($value)) {
        return $value;
    }
    $decoded = json_decode((string)$value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
}

function iotzyHumanDuration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;

    if ($hours > 0) {
        return $hours . 'j ' . $minutes . 'm';
    }
    if ($minutes > 0) {
        return $minutes . 'm ' . $secs . 'd';
    }
    return $secs . 'd';
}

function iotzyNormalizeAnalyticsDate(?string $date): string
{
    $date = trim((string)$date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return date('Y-m-d');
    }
    $ts = strtotime($date . ' 00:00:00');
    return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
}

function iotzyDefaultCvRules(): array
{
    return [
        'human' => ['enabled' => true, 'rules' => [], 'delay' => 5000],
        'light' => ['enabled' => true, 'onDark' => [], 'onBright' => [], 'delay' => 2000],
    ];
}

function iotzyDefaultCvConfigFlat(): array
{
    return [
        'showBoundingBox' => true,
        'showDebugInfo' => true,
        'minConfidence' => 0.5,
        'darkThreshold' => 0.3,
        'brightThreshold' => 0.7,
        'humanEnabled' => true,
        'lightEnabled' => true,
    ];
}

function iotzyNormalizeCvRuleAction(mixed $action): string
{
    $normalized = strtolower(trim((string)$action));
    return in_array($normalized, ['', 'on', 'off', 'speed_low', 'speed_mid', 'speed_high'], true)
        ? $normalized
        : '';
}

function iotzyNormalizeHumanRuleCondition(mixed $condition, mixed $count): array
{
    $normalizedCount = max(0, min(20, (int)(is_numeric($count) ? $count : 0)));
    $normalizedCondition = strtolower(trim((string)$condition));

    return match ($normalizedCondition) {
        'gte'  => ['condition' => 'gt',  'count' => max(0, $normalizedCount - 1)],
        'lte'  => ['condition' => 'lt',  'count' => min(20, $normalizedCount + 1)],
        'any'  => ['condition' => 'gt',  'count' => 0],
        'none' => ['condition' => 'eq',  'count' => 0],
        'eq', 'neq', 'gt', 'lt' => ['condition' => $normalizedCondition, 'count' => $normalizedCount],
        default => ['condition' => 'eq', 'count' => $normalizedCount],
    };
}

function iotzyNormalizeCvRules(array $source = [], ?array $flatConfig = null): array
{
    $defaults = iotzyDefaultCvRules();
    $raw = array_replace_recursive($defaults, is_array($source) ? $source : []);
    $boolCaster = static fn($value, bool $fallback = false): bool
        => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? ($value === null ? $fallback : (bool)$value);
    $delayCaster = static fn($value, int $fallback): int
        => max(0, min(600000, (int)(is_numeric($value) ? $value : $fallback)));
    $normalizeDeviceIds = static function (mixed $list): array {
        $items = is_array($list) ? $list : ($list === null || $list === '' ? [] : [$list]);
        $normalized = [];
        foreach ($items as $item) {
            $value = trim((string)$item);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }
        return array_values(array_unique($normalized));
    };

    $humanDelay = $delayCaster($raw['human']['delay'] ?? null, (int)$defaults['human']['delay']);
    $lightDelay = $delayCaster($raw['light']['delay'] ?? null, (int)$defaults['light']['delay']);
    $humanEnabled = is_array($flatConfig) && array_key_exists('humanEnabled', $flatConfig)
        ? (bool)$flatConfig['humanEnabled']
        : $boolCaster($raw['human']['enabled'] ?? $defaults['human']['enabled'], (bool)$defaults['human']['enabled']);
    $lightEnabled = is_array($flatConfig) && array_key_exists('lightEnabled', $flatConfig)
        ? (bool)$flatConfig['lightEnabled']
        : $boolCaster($raw['light']['enabled'] ?? $defaults['light']['enabled'], (bool)$defaults['light']['enabled']);

    $humanRules = [];
    $rawHumanRules = is_array($raw['human']['rules'] ?? null) ? $raw['human']['rules'] : [];
    foreach ($rawHumanRules as $index => $rule) {
        if (!is_array($rule)) {
            continue;
        }

        $normalizedCondition = iotzyNormalizeHumanRuleCondition($rule['condition'] ?? 'eq', $rule['count'] ?? 0);
        $ruleId = trim((string)($rule['id'] ?? ''));
        if ($ruleId === '') {
            $ruleId = 'hr_' . substr(md5(json_encode([$normalizedCondition, $rule, $index])), 0, 9);
        }

        $humanRules[] = [
            'id' => $ruleId,
            'condition' => $normalizedCondition['condition'],
            'count' => $normalizedCondition['count'],
            'devices' => $normalizeDeviceIds($rule['devices'] ?? []),
            'onTrue' => iotzyNormalizeCvRuleAction($rule['onTrue'] ?? ''),
            'onFalse' => iotzyNormalizeCvRuleAction($rule['onFalse'] ?? ''),
            'delay' => $delayCaster($rule['delay'] ?? null, $humanDelay),
        ];
    }

    return [
        'human' => [
            'enabled' => $humanEnabled,
            'rules' => $humanRules,
            'delay' => $humanDelay,
        ],
        'light' => [
            'enabled' => $lightEnabled,
            'onDark' => $normalizeDeviceIds($raw['light']['onDark'] ?? []),
            'onBright' => $normalizeDeviceIds($raw['light']['onBright'] ?? []),
            'delay' => $lightDelay,
        ],
    ];
}

function iotzyResolveNestedValue(array $source, array $paths, mixed $fallback = null): mixed
{
    foreach ($paths as $path) {
        $value = $source;
        $found = true;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $found = false;
                break;
            }
            $value = $value[$segment];
        }
        if ($found && $value !== null && $value !== '') {
            return $value;
        }
    }

    return $fallback;
}

function iotzyNormalizeCvConfigFlat(array $source = [], ?array $defaults = null): array
{
    $defaults = array_merge(iotzyDefaultCvConfigFlat(), (array)$defaults);
    $boolCaster = static fn($value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool)$value;
    $floatCaster = static fn($value): float => is_numeric($value) ? (float)$value : 0.0;

    $showBoundingBox = $boolCaster(iotzyResolveNestedValue(
        $source,
        [
            ['showBoundingBox'],
            ['showBoundingBoxes'],
            ['show_bounding_box'],
            ['ui', 'showBoundingBoxes'],
            ['cv_config', 'ui', 'showBoundingBoxes'],
        ],
        $defaults['showBoundingBox']
    ));
    $showDebugInfo = $boolCaster(iotzyResolveNestedValue(
        $source,
        [
            ['showDebugInfo'],
            ['show_debug_info'],
            ['ui', 'showDebugInfo'],
            ['cv_config', 'ui', 'showDebugInfo'],
        ],
        $defaults['showDebugInfo']
    ));
    $minConfidence = max(0.1, min(0.99, $floatCaster(iotzyResolveNestedValue(
        $source,
        [
            ['minConfidence'],
            ['min_confidence'],
            ['cv_min_confidence'],
            ['detection', 'minConfidence'],
            ['cv_config', 'detection', 'minConfidence'],
        ],
        $defaults['minConfidence']
    ))));
    $darkThreshold = max(0.01, min(0.99, $floatCaster(iotzyResolveNestedValue(
        $source,
        [
            ['darkThreshold'],
            ['dark_threshold'],
            ['cv_dark_threshold'],
            ['light', 'darkThreshold'],
            ['cv_config', 'light', 'darkThreshold'],
        ],
        $defaults['darkThreshold']
    ))));
    $brightThreshold = max(0.01, min(0.99, $floatCaster(iotzyResolveNestedValue(
        $source,
        [
            ['brightThreshold'],
            ['bright_threshold'],
            ['cv_bright_threshold'],
            ['light', 'brightThreshold'],
            ['cv_config', 'light', 'brightThreshold'],
        ],
        $defaults['brightThreshold']
    ))));
    $humanEnabled = $boolCaster(iotzyResolveNestedValue(
        $source,
        [
            ['humanEnabled'],
            ['human_rules_enabled'],
            ['cv_human_rules_enabled'],
            ['automation', 'humanEnabled'],
            ['cv_config', 'automation', 'humanEnabled'],
            ['cv_rules', 'human', 'enabled'],
        ],
        $defaults['humanEnabled']
    ));
    $lightEnabled = $boolCaster(iotzyResolveNestedValue(
        $source,
        [
            ['lightEnabled'],
            ['light_rules_enabled'],
            ['cv_light_rules_enabled'],
            ['automation', 'lightEnabled'],
            ['cv_config', 'automation', 'lightEnabled'],
            ['cv_rules', 'light', 'enabled'],
        ],
        $defaults['lightEnabled']
    ));

    return [
        'showBoundingBox' => $showBoundingBox,
        'showDebugInfo' => $showDebugInfo,
        'minConfidence' => round($minConfidence, 4),
        'darkThreshold' => round($darkThreshold, 4),
        'brightThreshold' => round($brightThreshold, 4),
        'humanEnabled' => $humanEnabled,
        'lightEnabled' => $lightEnabled,
    ];
}

function iotzyBuildCvConfigDocument(array $flat): array
{
    $flat = iotzyNormalizeCvConfigFlat($flat);

    return [
        'detection' => [
            'minConfidence' => $flat['minConfidence'],
        ],
        'light' => [
            'darkThreshold' => $flat['darkThreshold'],
            'brightThreshold' => $flat['brightThreshold'],
        ],
        'ui' => [
            'showBoundingBoxes' => $flat['showBoundingBox'],
            'showDebugInfo' => $flat['showDebugInfo'],
        ],
        'automation' => [
            'humanEnabled' => $flat['humanEnabled'],
            'lightEnabled' => $flat['lightEnabled'],
        ],
    ];
}

function iotzyEnsureUserSettingsRow(int $userId, ?PDO $db = null): void
{
    $db = $db ?: getLocalDB();
    if (!$db) {
        return;
    }

    $mqttDefaults = [
        'mqtt_broker' => getenv('MQTT_HOST') ?: 'broker.hivemq.com',
        'mqtt_port' => (int)(getenv('MQTT_PORT') ?: 8884),
        'mqtt_use_ssl' => (getenv('MQTT_USE_SSL') === 'true' || getenv('MQTT_USE_SSL') === '1') ? 1 : 0,
    ];

    $db->prepare(
        "INSERT IGNORE INTO user_settings (user_id, mqtt_broker, mqtt_port, mqtt_use_ssl)
         VALUES (?, ?, ?, ?)"
    )->execute([$userId, $mqttDefaults['mqtt_broker'], $mqttDefaults['mqtt_port'], $mqttDefaults['mqtt_use_ssl']]);
}

function iotzyPersistCvConfig(
    PDO $db,
    int $userId,
    int $cameraId,
    array $incomingConfig,
    ?array $currentUserSettings = null,
    ?array $currentCameraSettings = null
): array {
    $base = iotzyDefaultCvConfigFlat();
    if (is_array($currentUserSettings) && $currentUserSettings) {
        $base = iotzyNormalizeCvConfigFlat($currentUserSettings, $base);
    }
    if (is_array($currentCameraSettings) && $currentCameraSettings) {
        $base = iotzyNormalizeCvConfigFlat($currentCameraSettings, $base);
    }

    $flat = iotzyNormalizeCvConfigFlat($incomingConfig, $base);
    $configJson = json_encode(iotzyBuildCvConfigDocument($flat), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $existingRules = iotzyDefaultCvRules();
    if (is_array($currentUserSettings) && $currentUserSettings) {
        $existingRules = iotzyNormalizeCvRules(iotzyJsonDecode($currentUserSettings['cv_rules'] ?? null, []));
    }
    if (is_array($currentCameraSettings) && $currentCameraSettings) {
        $existingRules = iotzyNormalizeCvRules(
            array_replace_recursive($existingRules, iotzyJsonDecode($currentCameraSettings['cv_rules'] ?? null, []))
        );
    }
    $syncedRules = iotzyNormalizeCvRules($existingRules, $flat);
    $rulesJson = json_encode($syncedRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    iotzyEnsureUserSettingsRow($userId, $db);

    if ($cameraId > 0) {
        $db->prepare("INSERT IGNORE INTO camera_settings (camera_id) VALUES (?)")->execute([$cameraId]);
        $db->prepare(
            "UPDATE camera_settings
             SET show_bounding_box = ?,
                 show_debug_info = ?,
                 min_confidence = ?,
                 dark_threshold = ?,
                 bright_threshold = ?,
                 human_rules_enabled = ?,
                 light_rules_enabled = ?,
                 cv_config = ?,
                 cv_rules = ?
             WHERE camera_id = ?"
        )->execute([
            (int)$flat['showBoundingBox'],
            (int)$flat['showDebugInfo'],
            $flat['minConfidence'],
            $flat['darkThreshold'],
            $flat['brightThreshold'],
            (int)$flat['humanEnabled'],
            (int)$flat['lightEnabled'],
            $configJson,
            $rulesJson,
            $cameraId,
        ]);
    }

    $db->prepare(
        "UPDATE user_settings
         SET cv_min_confidence = ?,
             cv_dark_threshold = ?,
             cv_bright_threshold = ?,
             cv_human_rules_enabled = ?,
             cv_light_rules_enabled = ?,
             cv_config = ?,
             cv_rules = ?
         WHERE user_id = ?"
    )->execute([
        $flat['minConfidence'],
        $flat['darkThreshold'],
        $flat['brightThreshold'],
        (int)$flat['humanEnabled'],
        (int)$flat['lightEnabled'],
        $configJson,
        $rulesJson,
        $userId,
    ]);

    return $flat;
}

function iotzyPersistCvRules(
    PDO $db,
    int $userId,
    int $cameraId,
    array $rules,
    ?array $currentUserSettings = null,
    ?array $currentCameraSettings = null
): array
{
    $baseConfig = iotzyDefaultCvConfigFlat();
    if (is_array($currentUserSettings) && $currentUserSettings) {
        $baseConfig = iotzyNormalizeCvConfigFlat($currentUserSettings, $baseConfig);
    }
    if (is_array($currentCameraSettings) && $currentCameraSettings) {
        $baseConfig = iotzyNormalizeCvConfigFlat($currentCameraSettings, $baseConfig);
    }

    $normalizedRules = iotzyNormalizeCvRules(iotzyJsonDecode($rules, []));
    $syncedFlatConfig = $baseConfig;
    $syncedFlatConfig['humanEnabled'] = (bool)($normalizedRules['human']['enabled'] ?? $baseConfig['humanEnabled']);
    $syncedFlatConfig['lightEnabled'] = (bool)($normalizedRules['light']['enabled'] ?? $baseConfig['lightEnabled']);
    $normalizedRules = iotzyNormalizeCvRules($normalizedRules, $syncedFlatConfig);
    $rulesJson = json_encode($normalizedRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $configJson = json_encode(iotzyBuildCvConfigDocument($syncedFlatConfig), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    iotzyEnsureUserSettingsRow($userId, $db);

    if ($cameraId > 0) {
        $db->prepare("INSERT IGNORE INTO camera_settings (camera_id) VALUES (?)")->execute([$cameraId]);
        $db->prepare(
            "UPDATE camera_settings
             SET cv_rules = ?,
                 human_rules_enabled = ?,
                 light_rules_enabled = ?,
                 cv_config = ?
             WHERE camera_id = ?"
        )->execute([
            $rulesJson,
            (int)$syncedFlatConfig['humanEnabled'],
            (int)$syncedFlatConfig['lightEnabled'],
            $configJson,
            $cameraId,
        ]);
    }

    $db->prepare(
        "UPDATE user_settings
         SET cv_rules = ?,
             cv_human_rules_enabled = ?,
             cv_light_rules_enabled = ?,
             cv_config = ?
         WHERE user_id = ?"
    )->execute([
        $rulesJson,
        (int)$syncedFlatConfig['humanEnabled'],
        (int)$syncedFlatConfig['lightEnabled'],
        $configJson,
        $userId,
    ]);

    return $normalizedRules;
}

function iotzyInferDeviceTemplateSlug(?string $type = null, ?string $icon = null): ?string
{
    $type = strtolower(trim((string)$type));
    $icon = strtolower(trim((string)$icon));

    $typeMap = [
        'light' => 'led_lamp',
        'lamp' => 'led_lamp',
        'switch' => 'led_lamp',
        'fan' => 'fan_5v',
        'servo' => 'servo',
        'lock' => 'door_lock',
        'door' => 'door_lock',
    ];
    if (isset($typeMap[$type])) {
        return $typeMap[$type];
    }

    if (str_contains($icon, 'light') || str_contains($icon, 'bulb')) {
        return 'led_lamp';
    }
    if (str_contains($icon, 'wind') || str_contains($icon, 'fan')) {
        return 'fan_5v';
    }
    if (str_contains($icon, 'lock') || str_contains($icon, 'door')) {
        return 'door_lock';
    }
    return null;
}

function iotzyInferSensorTemplateSlug(?string $type = null): ?string
{
    $type = strtolower(trim((string)$type));
    return match ($type) {
        'temperature' => 'dht22_temperature',
        'humidity' => 'dht22_humidity',
        'voltage' => 'ina219_voltage',
        'current' => 'ina219_current',
        'power' => 'ina219_power',
        'motion' => 'pir_motion',
        'gas' => 'mq2_gas',
        'distance' => 'ultrasonic_distance',
        default => null,
    };
}

function getCurrentUser(): ?array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (session_status() !== PHP_SESSION_ACTIVE && function_exists('startSecureSession')) {
        startSecureSession();
    }
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $db = getLocalDB();
    if (!$db) {
        return null;
    }

    try {
        $st = $db->prepare(
            "SELECT u.id, u.username, u.email, u.full_name, u.role, u.is_active,
                    COALESCE(s.theme, 'light') AS theme
             FROM users u
             LEFT JOIN user_settings s ON s.user_id = u.id
             WHERE u.id = ? AND u.is_active = 1
             LIMIT 1"
        );
        $st->execute([$_SESSION['user_id']]);
        $cached = $st->fetch() ?: null;
        return $cached;
    } catch (PDOException $e) {
        return null;
    }
}

function getUserSettings(int $userId): ?array
{
    $db = getLocalDB();
    if (!$db) {
        return null;
    }

    try {
        $st = $db->prepare("SELECT * FROM user_settings WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $row = $st->fetch();

        if (!$row) {
            $flatCvConfig = iotzyDefaultCvConfigFlat();
            return [
                'user_id' => $userId,
                'mqtt_broker' => getenv('MQTT_HOST') ?: 'broker.hivemq.com',
                'mqtt_port' => (int)(getenv('MQTT_PORT') ?: 8884),
                'mqtt_use_ssl' => (getenv('MQTT_USE_SSL') === 'true' || getenv('MQTT_USE_SSL') === '1') ? 1 : 0,
                'mqtt_path' => getenv('MQTT_PATH') ?: '/mqtt',
                'theme' => 'light',
                'quick_control_devices' => [],
                'cv_config' => iotzyBuildCvConfigDocument($flatCvConfig),
                'cv_rules' => iotzyDefaultCvRules(),
                'cv_min_confidence' => $flatCvConfig['minConfidence'],
                'cv_dark_threshold' => $flatCvConfig['darkThreshold'],
                'cv_bright_threshold' => $flatCvConfig['brightThreshold'],
                'cv_human_rules_enabled' => (int)$flatCvConfig['humanEnabled'],
                'cv_light_rules_enabled' => (int)$flatCvConfig['lightEnabled'],
            ];
        }

        $row['quick_control_devices'] = iotzyJsonDecode($row['quick_control_devices'], []);
        $row['cv_config'] = iotzyJsonDecode($row['cv_config'], []);
        $flatCvConfig = iotzyNormalizeCvConfigFlat($row);
        $row['cv_rules'] = iotzyNormalizeCvRules(iotzyJsonDecode($row['cv_rules'], []), $flatCvConfig);
        $row['cv_min_confidence'] = $flatCvConfig['minConfidence'];
        $row['cv_dark_threshold'] = $flatCvConfig['darkThreshold'];
        $row['cv_bright_threshold'] = $flatCvConfig['brightThreshold'];
        $row['cv_human_rules_enabled'] = (int)$flatCvConfig['humanEnabled'];
        $row['cv_light_rules_enabled'] = (int)$flatCvConfig['lightEnabled'];
        $row['cv_config'] = iotzyBuildCvConfigDocument($flatCvConfig);
        $row['telegram_configured'] = !empty(readStoredSecret($row['telegram_bot_token'] ?? ''))
            || !empty(defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '');
        unset($row['mqtt_password_enc'], $row['telegram_bot_token']);

        return $row;
    } catch (PDOException $e) {
        return null;
    }
}

function getUserDeviceTemplates(?PDO $db = null): array
{
    $db = $db ?: getLocalDB();
    if (!$db) {
        return [];
    }

    $stmt = $db->query(
        "SELECT id, slug, name, device_type, control_mode, default_icon, state_on_label, state_off_label,
                default_min_value, default_max_value, default_step_value,
                default_topic_sub_pattern, default_topic_pub_pattern, meta, is_active, sort_order
         FROM device_templates
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['is_active'] = (int)$row['is_active'];
        $row['meta'] = iotzyJsonDecode($row['meta'], []);
    }
    unset($row);
    return $rows;
}

function getUserSensorTemplates(?PDO $db = null): array
{
    $db = $db ?: getLocalDB();
    if (!$db) {
        return [];
    }

    $stmt = $db->query(
        "SELECT id, slug, family, metric, name, sensor_type, default_icon, default_unit, output_kind,
                default_topic_pattern, supports_device_link, is_power_metric, sort_order, is_active
         FROM sensor_templates
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['supports_device_link'] = (int)$row['supports_device_link'];
        $row['is_power_metric'] = (int)$row['is_power_metric'];
        $row['is_active'] = (int)$row['is_active'];
    }
    unset($row);
    return $rows;
}

function iotzyFetchTemplateById(PDO $db, string $table, int $id): ?array
{
    $stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function iotzyFetchTemplateBySlug(PDO $db, string $table, string $slug): ?array
{
    $stmt = $db->prepare("SELECT * FROM {$table} WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function resolveDeviceTemplate(PDO $db, mixed $templateId = null, ?string $templateSlug = null, ?string $type = null, ?string $icon = null): ?array
{
    if (is_numeric($templateId) && (int)$templateId > 0) {
        $tpl = iotzyFetchTemplateById($db, 'device_templates', (int)$templateId);
        if ($tpl) {
            return $tpl;
        }
    }

    $templateSlug = trim((string)$templateSlug);
    if ($templateSlug !== '') {
        $tpl = iotzyFetchTemplateBySlug($db, 'device_templates', $templateSlug);
        if ($tpl) {
            return $tpl;
        }
    }

    $inferred = iotzyInferDeviceTemplateSlug($type, $icon);
    return $inferred ? iotzyFetchTemplateBySlug($db, 'device_templates', $inferred) : null;
}

function resolveSensorTemplate(PDO $db, mixed $templateId = null, ?string $templateSlug = null, ?string $type = null): ?array
{
    if (is_numeric($templateId) && (int)$templateId > 0) {
        $tpl = iotzyFetchTemplateById($db, 'sensor_templates', (int)$templateId);
        if ($tpl) {
            return $tpl;
        }
    }

    $templateSlug = trim((string)$templateSlug);
    if ($templateSlug !== '') {
        $tpl = iotzyFetchTemplateBySlug($db, 'sensor_templates', $templateSlug);
        if ($tpl) {
            return $tpl;
        }
    }

    $inferred = iotzyInferSensorTemplateSlug($type);
    return $inferred ? iotzyFetchTemplateBySlug($db, 'sensor_templates', $inferred) : null;
}

function getUserDevices(int $userId, ?PDO $db = null): array
{
    $db = $db ?: getLocalDB();
    if (!$db) {
        return [];
    }

    $sql = "SELECT
                d.id, d.user_id, d.device_template_id, d.device_key, d.name,
                COALESCE(NULLIF(d.icon, ''), dt.default_icon, 'fa-plug') AS icon,
                COALESCE(NULLIF(d.type, ''), dt.device_type, 'switch') AS type,
                d.topic_sub, d.topic_pub, d.control_value, d.control_text,
                d.state_on_label, d.state_off_label,
                COALESCE(NULLIF(d.state_on_label, ''), dt.state_on_label, 'ON') AS resolved_state_on_label,
                COALESCE(NULLIF(d.state_off_label, ''), dt.state_off_label, 'OFF') AS resolved_state_off_label,
                d.is_active, d.last_state, d.latest_state, d.last_seen, d.last_state_changed, d.created_at,
                dt.slug AS template_slug, dt.name AS template_name, dt.device_type AS template_device_type,
                dt.control_mode, dt.default_icon AS template_default_icon
            FROM devices d
            LEFT JOIN device_templates dt ON dt.id = d.device_template_id
            WHERE d.user_id = ?
            ORDER BY d.created_at ASC";

    try {
        $st = $db->prepare($sql);
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['user_id'] = (int)$row['user_id'];
            $row['device_template_id'] = $row['device_template_id'] !== null ? (int)$row['device_template_id'] : null;
            $row['is_active'] = (int)$row['is_active'];
            $row['last_state'] = (int)$row['last_state'];
            $row['latest_state'] = (int)$row['latest_state'];
            $row['model_label'] = $row['template_name'] ?: ucwords(str_replace('_', ' ', (string)$row['type']));
        }
        unset($row);

        return $rows;
    } catch (PDOException $e) {
        return [];
    }
}

function getUserSensors(int $userId, ?PDO $db = null): array
{
    $db = $db ?: getLocalDB();
    if (!$db) {
        return [];
    }

    $sql = "SELECT
                s.id, s.user_id, s.device_id, s.sensor_template_id, s.sensor_key, s.name,
                COALESCE(NULLIF(s.type, ''), st.sensor_type, 'sensor') AS type,
                COALESCE(NULLIF(s.icon, ''), st.default_icon, 'fa-microchip') AS icon,
                COALESCE(NULLIF(s.unit, ''), st.default_unit, '') AS unit,
                s.topic, s.latest_value, s.last_seen, s.created_at,
                d.name AS device_name, d.device_key, d.type AS device_type,
                st.slug AS template_slug, st.metric AS template_metric,
                st.name AS template_name, st.sensor_type AS template_sensor_type,
                st.is_power_metric
            FROM sensors s
            LEFT JOIN devices d ON d.id = s.device_id
            LEFT JOIN sensor_templates st ON st.id = s.sensor_template_id
            WHERE s.user_id = ?
            ORDER BY s.created_at ASC";

    try {
        $st = $db->prepare($sql);
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['user_id'] = (int)$row['user_id'];
            $row['device_id'] = $row['device_id'] !== null ? (int)$row['device_id'] : null;
            $row['sensor_template_id'] = $row['sensor_template_id'] !== null ? (int)$row['sensor_template_id'] : null;
            $row['is_power_metric'] = (int)($row['is_power_metric'] ?? 0);
            $row['latest_value'] = $row['latest_value'] !== null ? (float)$row['latest_value'] : null;
            $row['model_label'] = $row['template_name'] ?: ucwords(str_replace('_', ' ', (string)$row['type']));
        }
        unset($row);

        return $rows;
    } catch (PDOException $e) {
        return [];
    }
}

function iotzyBuildDeviceClientPayload(array $device): array
{
    return [
        'id' => isset($device['id']) ? (int)$device['id'] : 0,
        'device_template_id' => isset($device['device_template_id']) && $device['device_template_id'] !== null ? (int)$device['device_template_id'] : null,
        'device_key' => $device['device_key'] ?? null,
        'name' => $device['name'] ?? '',
        'icon' => $device['icon'] ?? 'fa-plug',
        'type' => $device['type'] ?? 'switch',
        'topic_sub' => $device['topic_sub'] ?? '',
        'topic_pub' => $device['topic_pub'] ?? '',
        'control_value' => $device['control_value'] ?? null,
        'control_text' => $device['control_text'] ?? null,
        'state_on_label' => $device['state_on_label'] ?? null,
        'state_off_label' => $device['state_off_label'] ?? null,
        'resolved_state_on_label' => $device['resolved_state_on_label'] ?? 'ON',
        'resolved_state_off_label' => $device['resolved_state_off_label'] ?? 'OFF',
        'is_active' => isset($device['is_active']) ? (int)$device['is_active'] : 0,
        'last_state' => isset($device['last_state']) ? (int)$device['last_state'] : 0,
        'latest_state' => isset($device['latest_state']) ? (int)$device['latest_state'] : 0,
        'last_seen' => $device['last_seen'] ?? null,
        'last_state_changed' => $device['last_state_changed'] ?? null,
        'created_at' => $device['created_at'] ?? null,
        'template_slug' => $device['template_slug'] ?? null,
        'template_name' => $device['template_name'] ?? null,
        'template_device_type' => $device['template_device_type'] ?? null,
        'template_default_icon' => $device['template_default_icon'] ?? null,
        'control_mode' => $device['control_mode'] ?? 'binary',
        'model_label' => $device['model_label'] ?? ($device['name'] ?? ''),
    ];
}

function getUserDevicesClientPayload(int $userId, ?PDO $db = null): array
{
    return array_map('iotzyBuildDeviceClientPayload', getUserDevices($userId, $db));
}

function iotzyBuildSensorClientPayload(array $sensor): array
{
    return [
        'id' => isset($sensor['id']) ? (int)$sensor['id'] : 0,
        'device_id' => isset($sensor['device_id']) && $sensor['device_id'] !== null ? (int)$sensor['device_id'] : null,
        'sensor_template_id' => isset($sensor['sensor_template_id']) && $sensor['sensor_template_id'] !== null ? (int)$sensor['sensor_template_id'] : null,
        'sensor_key' => $sensor['sensor_key'] ?? null,
        'name' => $sensor['name'] ?? '',
        'type' => $sensor['type'] ?? 'sensor',
        'icon' => $sensor['icon'] ?? 'fa-microchip',
        'unit' => $sensor['unit'] ?? '',
        'topic' => $sensor['topic'] ?? '',
        'latest_value' => array_key_exists('latest_value', $sensor) && $sensor['latest_value'] !== null ? (float)$sensor['latest_value'] : null,
        'last_seen' => $sensor['last_seen'] ?? null,
        'created_at' => $sensor['created_at'] ?? null,
        'device_name' => $sensor['device_name'] ?? null,
        'device_key' => $sensor['device_key'] ?? null,
        'device_type' => $sensor['device_type'] ?? null,
        'template_slug' => $sensor['template_slug'] ?? null,
        'template_metric' => $sensor['template_metric'] ?? null,
        'template_name' => $sensor['template_name'] ?? null,
        'template_sensor_type' => $sensor['template_sensor_type'] ?? null,
        'is_power_metric' => isset($sensor['is_power_metric']) ? (int)$sensor['is_power_metric'] : 0,
        'model_label' => $sensor['model_label'] ?? ($sensor['name'] ?? ''),
    ];
}

function getUserSensorsClientPayload(int $userId, ?PDO $db = null): array
{
    return array_map('iotzyBuildSensorClientPayload', getUserSensors($userId, $db));
}

function iotzyDefaultCvState(): array
{
    return [
        'is_active' => 0,
        'model_loaded' => 0,
        'person_count' => 0,
        'brightness' => 0,
        'light_condition' => 'unknown',
        'last_updated' => null,
    ];
}

function iotzySanitizeCameraKey(mixed $value, int $userId, bool $allowEmpty = false): string
{
    $normalized = strtolower(trim((string)$value));
    $normalized = preg_replace('/[^a-z0-9:_-]+/', '-', $normalized);
    $normalized = trim((string)$normalized, '-_:');
    if ($normalized === '') {
        return $allowEmpty ? '' : ('u' . max(0, $userId) . '-default-browser');
    }

    $prefix = 'u' . max(0, $userId) . '-';
    if (!str_starts_with($normalized, $prefix)) {
        $normalized = $prefix . ltrim($normalized, '-');
    }

    return substr($normalized, 0, 100);
}

function iotzySanitizeCameraName(mixed $value, string $fallback = 'Browser Camera'): string
{
    $normalized = preg_replace('/\s+/', ' ', trim((string)$value));
    $normalized = trim((string)$normalized);
    if ($normalized === '') {
        return $fallback;
    }

    if (function_exists('mb_substr')) {
        return mb_substr($normalized, 0, 100);
    }

    return substr($normalized, 0, 100);
}

function iotzyResolveCameraContext(mixed $context, int $userId): array
{
    $source = is_array($context) ? $context : [];
    $rawKey = $source['camera_key'] ?? $source['cameraKey'] ?? $source['cv_camera_key'] ?? null;
    $hasExplicitKey = trim((string)$rawKey) !== '';

    $sessionLabel = iotzySanitizeCameraName(
        $source['camera_session_label'] ?? $source['cameraSessionLabel'] ?? '',
        ''
    );
    $deviceLabel = iotzySanitizeCameraName(
        $source['camera_device_label'] ?? $source['cameraDeviceLabel'] ?? '',
        ''
    );
    $providedName = iotzySanitizeCameraName(
        $source['camera_name'] ?? $source['cameraName'] ?? '',
        ''
    );
    $fallbackName = 'Browser Camera';
    $nameParts = array_values(array_filter([$sessionLabel, $deviceLabel], static fn($part) => $part !== ''));
    if ($nameParts) {
        $fallbackName = implode(' • ', array_slice($nameParts, 0, 2));
    }

    return [
        'has_explicit_key' => $hasExplicitKey,
        'camera_key' => iotzySanitizeCameraKey($rawKey, $userId, !$hasExplicitKey),
        'camera_name' => iotzySanitizeCameraName($providedName !== '' ? $providedName : $fallbackName, 'Browser Camera'),
        'camera_session_label' => $sessionLabel,
        'camera_device_label' => $deviceLabel,
    ];
}

function iotzyHydrateCameraBundle(PDO $db, array $camera): array
{
    $cameraId = (int)($camera['id'] ?? 0);
    if ($cameraId <= 0) {
        return [
            'camera' => null,
            'camera_settings' => [],
            'cv_state' => iotzyDefaultCvState(),
        ];
    }

    $db->prepare("INSERT IGNORE INTO camera_settings (camera_id) VALUES (?)")->execute([$cameraId]);
    $db->prepare("INSERT IGNORE INTO cv_state (camera_id) VALUES (?)")->execute([$cameraId]);

    $settingsStmt = $db->prepare("SELECT * FROM camera_settings WHERE camera_id = ? LIMIT 1");
    $settingsStmt->execute([$cameraId]);
    $cameraSettings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $cameraSettings['camera_id'] = $cameraId;
    $cameraSettings['cv_config'] = iotzyJsonDecode($cameraSettings['cv_config'] ?? null, []);
    $cameraFlatConfig = iotzyNormalizeCvConfigFlat($cameraSettings);
    $cameraSettings['cv_rules'] = iotzyNormalizeCvRules(
        iotzyJsonDecode($cameraSettings['cv_rules'] ?? null, []),
        $cameraFlatConfig
    );
    $cameraSettings['show_bounding_box'] = (int)$cameraFlatConfig['showBoundingBox'];
    $cameraSettings['show_debug_info'] = (int)$cameraFlatConfig['showDebugInfo'];
    $cameraSettings['min_confidence'] = $cameraFlatConfig['minConfidence'];
    $cameraSettings['dark_threshold'] = $cameraFlatConfig['darkThreshold'];
    $cameraSettings['bright_threshold'] = $cameraFlatConfig['brightThreshold'];
    $cameraSettings['human_rules_enabled'] = (int)$cameraFlatConfig['humanEnabled'];
    $cameraSettings['light_rules_enabled'] = (int)$cameraFlatConfig['lightEnabled'];
    $cameraSettings['cv_config'] = iotzyBuildCvConfigDocument($cameraFlatConfig);

    $cvStmt = $db->prepare("SELECT * FROM cv_state WHERE camera_id = ? LIMIT 1");
    $cvStmt->execute([$cameraId]);
    $cvState = array_merge(iotzyDefaultCvState(), $cvStmt->fetch(PDO::FETCH_ASSOC) ?: []);
    $cvState['camera_id'] = $cameraId;

    return [
        'camera' => $camera,
        'camera_settings' => $cameraSettings,
        'cv_state' => $cvState,
    ];
}

function getUserCameraBundle(int $userId, ?PDO $db = null, mixed $cameraContext = null): array
{
    $db = $db ?: getLocalDB();
    if (!$db) {
        return [
            'camera' => null,
            'camera_settings' => [],
            'cv_state' => iotzyDefaultCvState(),
        ];
    }

    $resolvedContext = iotzyResolveCameraContext($cameraContext, $userId);
    $camera = null;
    $createdCamera = false;

    if ($resolvedContext['has_explicit_key']) {
        $stmt = $db->prepare(
            "SELECT *
             FROM cameras
             WHERE user_id = ? AND camera_key = ?
             LIMIT 1"
        );
        $stmt->execute([$userId, $resolvedContext['camera_key']]);
        $camera = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $stmt = $db->prepare(
            "SELECT *
             FROM cameras
             WHERE user_id = ?
             ORDER BY CASE WHEN source_type = 'webrtc' THEN 0 ELSE 1 END, id ASC
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        $camera = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$camera) {
        $cameraKey = $resolvedContext['has_explicit_key']
            ? $resolvedContext['camera_key']
            : iotzySanitizeCameraKey(null, $userId);
        $cameraName = $resolvedContext['camera_name'] ?: 'Browser Camera';
        $db->prepare(
            "INSERT INTO cameras (user_id, camera_key, name, source_type, is_active, last_seen)
             VALUES (?, ?, ?, 'webrtc', 1, NOW())"
        )->execute([$userId, $cameraKey, $cameraName]);

        $createdCamera = true;
        $lookup = $db->prepare("SELECT * FROM cameras WHERE user_id = ? AND camera_key = ? LIMIT 1");
        $lookup->execute([$userId, $cameraKey]);
        $camera = $lookup->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$camera) {
        return [
            'camera' => null,
            'camera_settings' => [],
            'cv_state' => iotzyDefaultCvState(),
        ];
    }

    $cameraId = (int)$camera['id'];
    $desiredName = $resolvedContext['camera_name'] ?? '';
    if ($resolvedContext['has_explicit_key'] && $desiredName !== '' && trim((string)($camera['name'] ?? '')) !== $desiredName) {
        $db->prepare("UPDATE cameras SET name = ?, is_active = 1, last_seen = NOW() WHERE id = ?")->execute([$desiredName, $cameraId]);
        $camera['name'] = $desiredName;
    }

    if ($createdCamera) {
        $userSettings = getUserSettings($userId, $db) ?? [];
        if (is_array($userSettings) && $userSettings) {
            iotzyPersistCvConfig($db, $userId, $cameraId, $userSettings, $userSettings, []);
        }
    }

    return iotzyHydrateCameraBundle($db, $camera);
}

function getUserCVState(int $userId, ?PDO $db = null, mixed $cameraContext = null): array
{
    $bundle = getUserCameraBundle($userId, $db, $cameraContext);
    return $bundle['cv_state'] ?? iotzyDefaultCvState();
}

function updateUserCVState(int $userId, array $data, ?PDO $db = null): array
{
    $db = $db ?: getLocalDB();
    if (!$db) {
        return iotzyDefaultCvState();
    }

    $bundle = getUserCameraBundle($userId, $db, $data);
    if (empty($bundle['camera']['id'])) {
        return iotzyDefaultCvState();
    }

    $cameraId = (int)$bundle['camera']['id'];
    $allowedLight = ['dark', 'normal', 'bright', 'unknown'];
    $casts = [
        'is_active' => fn($v) => (int)(bool)$v,
        'model_loaded' => fn($v) => (int)(bool)$v,
        'person_count' => fn($v) => max(0, min(20, (int)$v)),
        'brightness' => fn($v) => max(0, min(100, (int)$v)),
        'light_condition' => fn($v) => in_array(strtolower(trim((string)$v)), $allowedLight, true) ? strtolower(trim((string)$v)) : 'unknown',
    ];

    $sets = [];
    $vals = [];
    foreach ($casts as $field => $cast) {
        if (array_key_exists($field, $data)) {
            $sets[] = $field . ' = ?';
            $vals[] = $cast($data[$field]);
        }
    }

    if ($sets) {
        $vals[] = $cameraId;
        $db->prepare(
            "UPDATE cv_state SET " . implode(', ', $sets) . ", last_updated = NOW() WHERE camera_id = ?"
        )->execute($vals);
        $db->prepare("UPDATE cameras SET last_seen = NOW() WHERE id = ?")->execute([$cameraId]);
    }

    return getUserCVState($userId, $db, $data);
}

function iotzyTableExists(PDO $db, string $table): bool
{
    static $cache = [];
    $cacheKey = spl_object_id($db) . ':' . $table;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $db->prepare(
            "SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?
             LIMIT 1"
        );
        $stmt->execute([$table]);
        return $cache[$cacheKey] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$cacheKey] = false;
    }
}

function iotzyCameraStreamFeatureReady(?PDO $db = null): bool
{
    $db = $db ?: getLocalDB();
    if (!$db) {
        return false;
    }

    return iotzyTableExists($db, 'cameras')
        && iotzyTableExists($db, 'camera_settings');
}

function iotzyCameraStreamUsesDedicatedTables(?PDO $db = null): bool
{
    $db = $db ?: getLocalDB();
    if (!$db) {
        return false;
    }

    return iotzyTableExists($db, 'camera_stream_sessions')
        && iotzyTableExists($db, 'camera_stream_candidates');
}

function iotzyNormalizeCameraStreamKey(mixed $value, int $userId, string $fallbackCameraKey = ''): string
{
    $seed = trim((string)$value);
    if ($seed === '') {
        $seed = trim($fallbackCameraKey) !== '' ? ($fallbackCameraKey . '-live') : 'camera-live';
    }

    $normalized = strtolower($seed);
    $normalized = preg_replace('/[^a-z0-9:_-]+/', '-', $normalized);
    $normalized = trim((string)$normalized, '-_:');
    if ($normalized === '') {
        $normalized = 'camera-live';
    }

    $prefix = 'u' . max(0, $userId) . '-';
    if (!str_starts_with($normalized, $prefix)) {
        $normalized = $prefix . ltrim($normalized, '-');
    }

    return substr($normalized, 0, 120);
}

function iotzyNormalizeWebRtcSdp(mixed $value): string
{
    $normalized = trim((string)$value);
    if ($normalized === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($normalized, 0, 200000);
    }

    return substr($normalized, 0, 200000);
}

function iotzyNormalizeWebRtcCandidatePayload(mixed $value): string
{
    if (is_array($value) || is_object($value)) {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '';
    }

    $normalized = trim((string)$value);
    if ($normalized === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($normalized, 0, 120000);
    }

    return substr($normalized, 0, 120000);
}

function iotzyCameraStreamFeatureStatus(?PDO $db = null): array
{
    $ready = iotzyCameraStreamFeatureReady($db);
    return [
        'feature_ready' => $ready,
        'error' => $ready ? null : 'Sinkron source kamera belum aktif di server ini.',
    ];
}

function iotzyCleanupCameraStreamSessions(PDO $db, int $userId = 0): void
{
    if (!iotzyCameraStreamFeatureReady($db)) {
        return;
    }

    $userClause = $userId > 0 ? ' AND user_id = ?' : '';
    $params = $userId > 0 ? [$userId] : [];

    $db->prepare(
        "UPDATE camera_stream_sessions
         SET status = 'ended',
             ended_at = COALESCE(ended_at, NOW())
         WHERE status IN ('awaiting_viewer', 'connecting', 'live')
           AND last_publisher_heartbeat < DATE_SUB(NOW(), INTERVAL 45 SECOND)" . $userClause
    )->execute($params);

    $db->prepare(
        "UPDATE camera_stream_sessions
         SET viewer_camera_key = NULL,
             viewer_name = NULL,
             answer_sdp = NULL,
             status = 'awaiting_viewer',
             last_viewer_heartbeat = NULL
         WHERE status IN ('connecting', 'live')
           AND viewer_camera_key IS NOT NULL
           AND last_viewer_heartbeat IS NOT NULL
           AND last_viewer_heartbeat < DATE_SUB(NOW(), INTERVAL 45 SECOND)" . $userClause
    )->execute($params);

    $db->exec(
        "DELETE c
         FROM camera_stream_candidates c
         JOIN camera_stream_sessions s ON s.id = c.stream_session_id
         WHERE c.created_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)
            OR (s.status = 'ended' AND s.updated_at < DATE_SUB(NOW(), INTERVAL 1 DAY))"
    );
}

function iotzyFetchCameraStreamSession(PDO $db, int $userId, string $streamKey): ?array
{
    if (!iotzyCameraStreamFeatureReady($db)) {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT *
         FROM camera_stream_sessions
         WHERE user_id = ? AND stream_key = ?
         LIMIT 1"
    );
    $stmt->execute([$userId, $streamKey]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function iotzyBuildCameraStreamSummary(array $row, string $requesterCameraKey = ''): array
{
    $publisherKey = trim((string)($row['publisher_camera_key'] ?? ''));
    $viewerKey = trim((string)($row['viewer_camera_key'] ?? ''));
    $requesterCameraKey = trim($requesterCameraKey);

    return [
        'id' => (int)($row['id'] ?? 0),
        'stream_key' => (string)($row['stream_key'] ?? ''),
        'camera_id' => (int)($row['camera_id'] ?? 0),
        'publisher_camera_key' => $publisherKey,
        'publisher_name' => (string)($row['publisher_name'] ?? 'Browser Camera'),
        'source_label' => (string)($row['source_label'] ?? ''),
        'viewer_camera_key' => $viewerKey,
        'viewer_name' => (string)($row['viewer_name'] ?? ''),
        'status' => (string)($row['status'] ?? 'idle'),
        'started_at' => $row['started_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'last_publisher_heartbeat' => $row['last_publisher_heartbeat'] ?? null,
        'last_viewer_heartbeat' => $row['last_viewer_heartbeat'] ?? null,
        'is_owner' => $requesterCameraKey !== '' && hash_equals($publisherKey, $requesterCameraKey),
        'is_viewer' => $requesterCameraKey !== '' && $viewerKey !== '' && hash_equals($viewerKey, $requesterCameraKey),
        'watch_available' => (string)($row['status'] ?? '') !== 'ended'
            && $publisherKey !== ''
            && ($viewerKey === '' || ($requesterCameraKey !== '' && hash_equals($viewerKey, $requesterCameraKey))),
        'is_busy' => $viewerKey !== '' && $requesterCameraKey !== '' && !hash_equals($viewerKey, $requesterCameraKey) && !hash_equals($publisherKey, $requesterCameraKey),
        'offer_ready' => trim((string)($row['offer_sdp'] ?? '')) !== '',
        'answer_ready' => trim((string)($row['answer_sdp'] ?? '')) !== '',
    ];
}

function iotzyCameraStreamNow(): string
{
    return date('Y-m-d H:i:s');
}

function iotzyDecodeCameraLiveBridge(mixed $cvConfig): ?array
{
    $config = iotzyJsonDecode($cvConfig, []);
    if (!is_array($config)) {
        return null;
    }

    $bridge = $config['liveBridge'] ?? null;
    return is_array($bridge) ? $bridge : null;
}

function iotzyPersistCameraLiveBridge(PDO $db, int $cameraId, ?array $bridge): void
{
    $db->prepare("INSERT IGNORE INTO camera_settings (camera_id) VALUES (?)")->execute([$cameraId]);
    $stmt = $db->prepare("SELECT cv_config FROM camera_settings WHERE camera_id = ? LIMIT 1");
    $stmt->execute([$cameraId]);
    $existingConfig = iotzyJsonDecode($stmt->fetchColumn(), []);
    if (!is_array($existingConfig)) {
        $existingConfig = [];
    }

    if ($bridge === null) {
        unset($existingConfig['liveBridge']);
    } else {
        $existingConfig['liveBridge'] = $bridge;
    }

    $json = json_encode($existingConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $db->prepare("UPDATE camera_settings SET cv_config = ? WHERE camera_id = ?")->execute([$json, $cameraId]);
}

function iotzyBuildLegacyCameraStreamRow(array $camera, array $bridge): array
{
    return [
        'id' => (int)($camera['id'] ?? 0),
        'camera_id' => (int)($camera['id'] ?? 0),
        'stream_key' => (string)($bridge['stream_key'] ?? ''),
        'publisher_camera_key' => (string)($bridge['publisher_camera_key'] ?? ''),
        'publisher_name' => (string)($bridge['publisher_name'] ?? ($camera['name'] ?? 'Browser Camera')),
        'source_label' => (string)($bridge['source_label'] ?? ''),
        'viewer_camera_key' => (string)($bridge['viewer_camera_key'] ?? ''),
        'viewer_name' => (string)($bridge['viewer_name'] ?? ''),
        'status' => (string)($bridge['status'] ?? 'idle'),
        'started_at' => $bridge['started_at'] ?? null,
        'updated_at' => $bridge['updated_at'] ?? null,
        'last_publisher_heartbeat' => $bridge['last_publisher_heartbeat'] ?? null,
        'last_viewer_heartbeat' => $bridge['last_viewer_heartbeat'] ?? null,
        'offer_sdp' => (string)($bridge['offer_sdp'] ?? ''),
        'answer_sdp' => (string)($bridge['answer_sdp'] ?? ''),
        'legacy_candidates' => is_array($bridge['candidates'] ?? null) ? array_values($bridge['candidates']) : [],
        'candidate_seq' => max(0, (int)($bridge['candidate_seq'] ?? 0)),
    ];
}

function iotzyLoadLegacyCameraStreamRows(PDO $db, int $userId): array
{
    $stmt = $db->prepare(
        "SELECT c.*, cs.cv_config
         FROM cameras c
         LEFT JOIN camera_settings cs ON cs.camera_id = c.id
         WHERE c.user_id = ?
         ORDER BY c.id ASC"
    );
    $stmt->execute([$userId]);
    $rows = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $camera) {
        $bridge = iotzyDecodeCameraLiveBridge($camera['cv_config'] ?? null);
        if (!is_array($bridge) || trim((string)($bridge['stream_key'] ?? '')) === '') {
            continue;
        }
        $rows[] = iotzyBuildLegacyCameraStreamRow($camera, $bridge);
    }

    return $rows;
}

function iotzyFindLegacyCameraStreamSession(PDO $db, int $userId, string $streamKey): ?array
{
    $normalizedKey = trim($streamKey);
    if ($normalizedKey === '') {
        return null;
    }

    foreach (iotzyLoadLegacyCameraStreamRows($db, $userId) as $row) {
        if (hash_equals((string)($row['stream_key'] ?? ''), $normalizedKey)) {
            return $row;
        }
    }

    return null;
}

function iotzyCleanupLegacyCameraStreamSessions(PDO $db, int $userId = 0): void
{
    if ($userId <= 0) {
        return;
    }

    $now = time();
    foreach (iotzyLoadLegacyCameraStreamRows($db, $userId) as $row) {
        $cameraId = (int)($row['camera_id'] ?? 0);
        $bridge = [
            'stream_key' => $row['stream_key'] ?? '',
            'publisher_camera_key' => $row['publisher_camera_key'] ?? '',
            'publisher_name' => $row['publisher_name'] ?? '',
            'source_label' => $row['source_label'] ?? '',
            'viewer_camera_key' => $row['viewer_camera_key'] ?? '',
            'viewer_name' => $row['viewer_name'] ?? '',
            'status' => $row['status'] ?? 'idle',
            'started_at' => $row['started_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'last_publisher_heartbeat' => $row['last_publisher_heartbeat'] ?? null,
            'last_viewer_heartbeat' => $row['last_viewer_heartbeat'] ?? null,
            'offer_sdp' => $row['offer_sdp'] ?? '',
            'answer_sdp' => $row['answer_sdp'] ?? '',
            'candidate_seq' => $row['candidate_seq'] ?? 0,
            'candidates' => $row['legacy_candidates'] ?? [],
        ];

        $publisherHeartbeat = strtotime((string)($bridge['last_publisher_heartbeat'] ?? '')) ?: 0;
        $viewerHeartbeat = strtotime((string)($bridge['last_viewer_heartbeat'] ?? '')) ?: 0;
        $updated = false;

        if ($publisherHeartbeat > 0 && ($now - $publisherHeartbeat) > 45) {
            iotzyPersistCameraLiveBridge($db, $cameraId, null);
            continue;
        }

        if (!empty($bridge['viewer_camera_key']) && $viewerHeartbeat > 0 && ($now - $viewerHeartbeat) > 45) {
            $bridge['viewer_camera_key'] = null;
            $bridge['viewer_name'] = null;
            $bridge['answer_sdp'] = null;
            $bridge['status'] = 'awaiting_viewer';
            $bridge['last_viewer_heartbeat'] = null;
            $bridge['updated_at'] = iotzyCameraStreamNow();
            $bridge['candidates'] = [];
            $updated = true;
        }

        if ($updated) {
            iotzyPersistCameraLiveBridge($db, $cameraId, $bridge);
        }
    }
}

function getUserCameraStreamSessions(int $userId, array $cameraContext = [], ?PDO $db = null): array
{
    $db = $db ?: getLocalDB();
    if (!$db || !iotzyCameraStreamFeatureReady($db)) {
        return [];
    }

    if (!iotzyCameraStreamUsesDedicatedTables($db)) {
        iotzyCleanupLegacyCameraStreamSessions($db, $userId);
        $resolvedContext = iotzyResolveCameraContext($cameraContext, $userId);
        $rows = array_filter(
            iotzyLoadLegacyCameraStreamRows($db, $userId),
            static fn(array $row): bool => (string)($row['status'] ?? '') !== 'ended'
        );
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
        });
        return array_map(
            static fn(array $row): array => iotzyBuildCameraStreamSummary($row, $resolvedContext['camera_key'] ?? ''),
            array_slice(array_values($rows), 0, 12)
        );
    }

    iotzyCleanupCameraStreamSessions($db, $userId);
    $resolvedContext = iotzyResolveCameraContext($cameraContext, $userId);

    $stmt = $db->prepare(
        "SELECT *
         FROM camera_stream_sessions
         WHERE user_id = ?
           AND status <> 'ended'
           AND last_publisher_heartbeat >= DATE_SUB(NOW(), INTERVAL 45 SECOND)
         ORDER BY updated_at DESC
         LIMIT 12"
    );
    $stmt->execute([$userId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(
        static fn(array $row): array => iotzyBuildCameraStreamSummary($row, $resolvedContext['camera_key'] ?? ''),
        $rows
    );
}

function startUserCameraStreamSession(int $userId, array $cameraContext, array $payload = [], ?PDO $db = null): array
{
    $db = $db ?: getLocalDB();
    if (!$db) {
        return ['success' => false, 'feature_ready' => false, 'error' => 'Database unavailable'];
    }

    if (!iotzyCameraStreamFeatureReady($db)) {
        return ['success' => false] + iotzyCameraStreamFeatureStatus($db);
    }

    $resolvedContext = iotzyResolveCameraContext($cameraContext, $userId);
    $bundle = getUserCameraBundle($userId, $db, $cameraContext);
    $cameraId = (int)($bundle['camera']['id'] ?? 0);
    if ($cameraId <= 0) {
        return ['success' => false, 'feature_ready' => true, 'error' => 'Kamera publisher tidak ditemukan'];
    }

    $offerSdp = iotzyNormalizeWebRtcSdp($payload['offer_sdp'] ?? '');
    if ($offerSdp === '') {
        return ['success' => false, 'feature_ready' => true, 'error' => 'Offer WebRTC belum tersedia'];
    }

    if (!iotzyCameraStreamUsesDedicatedTables($db)) {
        iotzyCleanupLegacyCameraStreamSessions($db, $userId);
        $streamKey = iotzyNormalizeCameraStreamKey($payload['stream_key'] ?? '', $userId, $resolvedContext['camera_key'] ?? '');
        $publisherName = iotzySanitizeCameraName($payload['publisher_name'] ?? ($resolvedContext['camera_name'] ?? 'Browser Camera'), 'Browser Camera');
        $sourceLabel = iotzySanitizeCameraName($payload['source_label'] ?? ($resolvedContext['camera_device_label'] ?? ''), '');
        $now = iotzyCameraStreamNow();

        foreach (iotzyLoadLegacyCameraStreamRows($db, $userId) as $row) {
            if (hash_equals((string)($row['publisher_camera_key'] ?? ''), (string)($resolvedContext['camera_key'] ?? ''))) {
                iotzyPersistCameraLiveBridge($db, (int)$row['camera_id'], null);
            }
        }

        $bridge = [
            'stream_key' => $streamKey,
            'publisher_camera_key' => $resolvedContext['camera_key'],
            'publisher_name' => $publisherName,
            'source_label' => $sourceLabel !== '' ? $sourceLabel : null,
            'viewer_camera_key' => null,
            'viewer_name' => null,
            'offer_sdp' => $offerSdp,
            'answer_sdp' => null,
            'status' => 'awaiting_viewer',
            'started_at' => $now,
            'last_publisher_heartbeat' => $now,
            'last_viewer_heartbeat' => null,
            'updated_at' => $now,
            'candidate_seq' => 0,
            'candidates' => [],
        ];
        iotzyPersistCameraLiveBridge($db, $cameraId, $bridge);
        $session = iotzyBuildLegacyCameraStreamRow($bundle['camera'] ?? ['id' => $cameraId, 'name' => $publisherName], $bridge);
        return [
            'success' => true,
            'feature_ready' => true,
            'session' => iotzyBuildCameraStreamSummary($session, $resolvedContext['camera_key']),
            'stream_key' => $streamKey,
        ];
    }

    iotzyCleanupCameraStreamSessions($db, $userId);

    $streamKey = iotzyNormalizeCameraStreamKey($payload['stream_key'] ?? '', $userId, $resolvedContext['camera_key'] ?? '');
    $publisherName = iotzySanitizeCameraName($payload['publisher_name'] ?? ($resolvedContext['camera_name'] ?? 'Browser Camera'), 'Browser Camera');
    $sourceLabel = iotzySanitizeCameraName($payload['source_label'] ?? ($resolvedContext['camera_device_label'] ?? ''), '');

    $db->prepare(
        "UPDATE camera_stream_sessions
         SET status = 'ended',
             ended_at = COALESCE(ended_at, NOW())
         WHERE user_id = ?
           AND publisher_camera_key = ?
           AND status <> 'ended'"
    )->execute([$userId, $resolvedContext['camera_key']]);

    $db->prepare(
        "INSERT INTO camera_stream_sessions (
            user_id, camera_id, stream_key, publisher_camera_key, publisher_name, source_label,
            offer_sdp, answer_sdp, status, started_at, last_publisher_heartbeat, last_viewer_heartbeat, ended_at
         ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, NULL, 'awaiting_viewer', NOW(), NOW(), NULL, NULL
         )
         ON DUPLICATE KEY UPDATE
            camera_id = VALUES(camera_id),
            publisher_camera_key = VALUES(publisher_camera_key),
            publisher_name = VALUES(publisher_name),
            source_label = VALUES(source_label),
            offer_sdp = VALUES(offer_sdp),
            answer_sdp = NULL,
            viewer_camera_key = NULL,
            viewer_name = NULL,
            status = 'awaiting_viewer',
            started_at = NOW(),
            last_publisher_heartbeat = NOW(),
            last_viewer_heartbeat = NULL,
            ended_at = NULL"
    )->execute([
        $userId,
        $cameraId,
        $streamKey,
        $resolvedContext['camera_key'],
        $publisherName,
        $sourceLabel !== '' ? $sourceLabel : null,
        $offerSdp,
    ]);

    $session = iotzyFetchCameraStreamSession($db, $userId, $streamKey);
    if (!$session) {
        return ['success' => false, 'feature_ready' => true, 'error' => 'Gagal membuat sesi live'];
    }

    $db->prepare("DELETE FROM camera_stream_candidates WHERE stream_session_id = ?")->execute([(int)$session['id']]);

    return [
        'success' => true,
        'feature_ready' => true,
        'session' => iotzyBuildCameraStreamSummary($session, $resolvedContext['camera_key']),
        'stream_key' => $streamKey,
    ];
}

function joinUserCameraStreamSession(int $userId, array $cameraContext, string $streamKey, ?PDO $db = null): array
{
    $db = $db ?: getLocalDB();
    if (!$db) {
        return ['success' => false, 'feature_ready' => false, 'error' => 'Database unavailable'];
    }

    if (!iotzyCameraStreamFeatureReady($db)) {
        return ['success' => false] + iotzyCameraStreamFeatureStatus($db);
    }

    if (!iotzyCameraStreamUsesDedicatedTables($db)) {
        iotzyCleanupLegacyCameraStreamSessions($db, $userId);
        $resolvedContext = iotzyResolveCameraContext($cameraContext, $userId);
        $session = iotzyFindLegacyCameraStreamSession($db, $userId, $streamKey);
        if (!$session || ($session['status'] ?? '') === 'ended') {
            return ['success' => false, 'feature_ready' => true, 'error' => 'Siaran live tidak tersedia'];
        }
        if (hash_equals((string)$session['publisher_camera_key'], (string)$resolvedContext['camera_key'])) {
            return ['success' => false, 'feature_ready' => true, 'error' => 'Device publisher tidak perlu masuk sebagai viewer'];
        }

        $claimedViewer = trim((string)($session['viewer_camera_key'] ?? ''));
        if ($claimedViewer !== '' && !hash_equals($claimedViewer, (string)$resolvedContext['camera_key'])) {
            return ['success' => false, 'feature_ready' => true, 'error' => 'Siaran ini sedang dipantau device lain'];
        }

        $bridge = [
            'stream_key' => $session['stream_key'],
            'publisher_camera_key' => $session['publisher_camera_key'],
            'publisher_name' => $session['publisher_name'],
            'source_label' => $session['source_label'],
            'viewer_camera_key' => $resolvedContext['camera_key'],
            'viewer_name' => iotzySanitizeCameraName($resolvedContext['camera_name'] ?? ($resolvedContext['camera_session_label'] ?? 'Viewer'), 'Viewer'),
            'offer_sdp' => $session['offer_sdp'],
            'answer_sdp' => $session['answer_sdp'],
            'status' => trim((string)($session['offer_sdp'] ?? '')) !== '' ? 'connecting' : 'awaiting_viewer',
            'started_at' => $session['started_at'],
            'last_publisher_heartbeat' => $session['last_publisher_heartbeat'],
            'last_viewer_heartbeat' => iotzyCameraStreamNow(),
            'updated_at' => iotzyCameraStreamNow(),
            'candidate_seq' => $session['candidate_seq'] ?? 0,
            'candidates' => $session['legacy_candidates'] ?? [],
        ];
        iotzyPersistCameraLiveBridge($db, (int)$session['camera_id'], $bridge);
        $fresh = iotzyBuildLegacyCameraStreamRow(['id' => $session['camera_id'], 'name' => $session['publisher_name']], $bridge);
        return [
            'success' => true,
            'feature_ready' => true,
            'session' => iotzyBuildCameraStreamSummary($fresh, $resolvedContext['camera_key']),
            'offer_sdp' => iotzyNormalizeWebRtcSdp($fresh['offer_sdp'] ?? ''),
        ];
    }

    iotzyCleanupCameraStreamSessions($db, $userId);
    $resolvedContext = iotzyResolveCameraContext($cameraContext, $userId);
    $session = iotzyFetchCameraStreamSession($db, $userId, $streamKey);
    if (!$session || ($session['status'] ?? '') === 'ended') {
        return ['success' => false, 'feature_ready' => true, 'error' => 'Siaran live tidak tersedia'];
    }

    if (hash_equals((string)$session['publisher_camera_key'], (string)$resolvedContext['camera_key'])) {
        return ['success' => false, 'feature_ready' => true, 'error' => 'Device publisher tidak perlu masuk sebagai viewer'];
    }

    $claimedViewer = trim((string)($session['viewer_camera_key'] ?? ''));
    if ($claimedViewer !== '' && !hash_equals($claimedViewer, (string)$resolvedContext['camera_key'])) {
        return ['success' => false, 'feature_ready' => true, 'error' => 'Siaran ini sedang dipantau device lain'];
    }

    $viewerName = iotzySanitizeCameraName($resolvedContext['camera_name'] ?? ($resolvedContext['camera_session_label'] ?? 'Viewer'), 'Viewer');
    $status = trim((string)($session['offer_sdp'] ?? '')) !== '' ? 'connecting' : 'awaiting_viewer';

    $db->prepare(
        "UPDATE camera_stream_sessions
         SET viewer_camera_key = ?,
             viewer_name = ?,
             status = ?,
             last_viewer_heartbeat = NOW(),
             ended_at = NULL
         WHERE id = ?"
    )->execute([
        $resolvedContext['camera_key'],
        $viewerName,
        $status,
        (int)$session['id'],
    ]);

    $fresh = iotzyFetchCameraStreamSession($db, $userId, $streamKey) ?: $session;
    return [
        'success' => true,
        'feature_ready' => true,
        'session' => iotzyBuildCameraStreamSummary($fresh, $resolvedContext['camera_key']),
        'offer_sdp' => iotzyNormalizeWebRtcSdp($fresh['offer_sdp'] ?? ''),
    ];
}

function submitUserCameraStreamAnswer(int $userId, array $cameraContext, string $streamKey, mixed $answerSdp, ?PDO $db = null): array
{
    $db = $db ?: getLocalDB();
    if (!$db) {
        return ['success' => false, 'feature_ready' => false, 'error' => 'Database unavailable'];
    }

    if (!iotzyCameraStreamFeatureReady($db)) {
        return ['success' => false] + iotzyCameraStreamFeatureStatus($db);
    }

    if (!iotzyCameraStreamUsesDedicatedTables($db)) {
        $resolvedContext = iotzyResolveCameraContext($cameraContext, $userId);
        $session = iotzyFindLegacyCameraStreamSession($db, $userId, $streamKey);
        if (!$session) {
            return ['success' => false, 'feature_ready' => true, 'error' => 'Siaran live tidak ditemukan'];
        }
        if (!hash_equals((string)($session['viewer_camera_key'] ?? ''), (string)$resolvedContext['camera_key'])) {
            return ['success' => false, 'feature_ready' => true, 'error' => 'Viewer aktif tidak cocok dengan sesi ini'];
        }

        $normalizedAnswer = iotzyNormalizeWebRtcSdp($answerSdp);
        if ($normalizedAnswer === '') {
            return ['success' => false, 'feature_ready' => true, 'error' => 'Answer WebRTC belum tersedia'];
        }

        $bridge = [
            'stream_key' => $session['stream_key'],
            'publisher_camera_key' => $session['publisher_camera_key'],
            'publisher_name' => $session['publisher_name'],
            'source_label' => $session['source_label'],
            'viewer_camera_key' => $session['viewer_camera_key'],
            'viewer_name' => $session['viewer_name'],
            'offer_sdp' => $session['offer_sdp'],
            'answer_sdp' => $normalizedAnswer,
            'status' => 'live',
            'started_at' => $session['started_at'],
            'last_publisher_heartbeat' => $session['last_publisher_heartbeat'],
            'last_viewer_heartbeat' => iotzyCameraStreamNow(),
            'updated_at' => iotzyCameraStreamNow(),
            'candidate_seq' => $session['candidate_seq'] ?? 0,
            'candidates' => $session['legacy_candidates'] ?? [],
        ];
        iotzyPersistCameraLiveBridge($db, (int)$session['camera_id'], $bridge);
        $fresh = iotzyBuildLegacyCameraStreamRow(['id' => $session['camera_id'], 'name' => $session['publisher_name']], $bridge);
        return [
            'success' => true,
            'feature_ready' => true,
            'session' => iotzyBuildCameraStreamSummary($fresh, $resolvedContext['camera_key']),
        ];
    }

    $resolvedContext = iotzyResolveCameraContext($cameraContext, $userId);
    $session = iotzyFetchCameraStreamSession($db, $userId, $streamKey);
    if (!$session) {
        return ['success' => false, 'feature_ready' => true, 'error' => 'Siaran live tidak ditemukan'];
    }

    if (!hash_equals((string)($session['viewer_camera_key'] ?? ''), (string)$resolvedContext['camera_key'])) {
        return ['success' => false, 'feature_ready' => true, 'error' => 'Viewer aktif tidak cocok dengan sesi ini'];
    }

    $normalizedAnswer = iotzyNormalizeWebRtcSdp($answerSdp);
    if ($normalizedAnswer === '') {
        return ['success' => false, 'feature_ready' => true, 'error' => 'Answer WebRTC belum tersedia'];
    }

    $db->prepare(
        "UPDATE camera_stream_sessions
         SET answer_sdp = ?,
             status = 'live',
             last_viewer_heartbeat = NOW()
         WHERE id = ?"
    )->execute([
        $normalizedAnswer,
        (int)$session['id'],
    ]);

    $fresh = iotzyFetchCameraStreamSession($db, $userId, $streamKey) ?: $session;
    return [
        'success' => true,
        'feature_ready' => true,
        'session' => iotzyBuildCameraStreamSummary($fresh, $resolvedContext['camera_key']),
    ];
}

function pushUserCameraStreamCandidate(int $userId, array $cameraContext, string $streamKey, mixed $candidate, ?PDO $db = null): array
{
    $db = $db ?: getLocalDB();
    if (!$db) {
        return ['success' => false, 'feature_ready' => false, 'error' => 'Database unavailable'];
    }

    if (!iotzyCameraStreamFeatureReady($db)) {
        return ['success' => false] + iotzyCameraStreamFeatureStatus($db);
    }

    if (!iotzyCameraStreamUsesDedicatedTables($db)) {
        $resolvedContext = iotzyResolveCameraContext($cameraContext, $userId);
        $session = iotzyFindLegacyCameraStreamSession($db, $userId, $streamKey);
        if (!$session) {
            return ['success' => false, 'feature_ready' => true, 'error' => 'Siaran live tidak ditemukan'];
        }

        $senderKey = (string)$resolvedContext['camera_key'];
        $publisherKey = (string)($session['publisher_camera_key'] ?? '');
        $viewerKey = (string)($session['viewer_camera_key'] ?? '');
        $recipientKey = '';
        $bridge = [
            'stream_key' => $session['stream_key'],
            'publisher_camera_key' => $session['publisher_camera_key'],
            'publisher_name' => $session['publisher_name'],
            'source_label' => $session['source_label'],
            'viewer_camera_key' => $session['viewer_camera_key'],
            'viewer_name' => $session['viewer_name'],
            'offer_sdp' => $session['offer_sdp'],
            'answer_sdp' => $session['answer_sdp'],
            'status' => $session['status'],
            'started_at' => $session['started_at'],
            'last_publisher_heartbeat' => $session['last_publisher_heartbeat'],
            'last_viewer_heartbeat' => $session['last_viewer_heartbeat'],
            'updated_at' => iotzyCameraStreamNow(),
            'candidate_seq' => $session['candidate_seq'] ?? 0,
            'candidates' => $session['legacy_candidates'] ?? [],
        ];

        if ($senderKey !== '' && hash_equals($publisherKey, $senderKey)) {
            $recipientKey = $viewerKey;
            $bridge['last_publisher_heartbeat'] = iotzyCameraStreamNow();
        } elseif ($senderKey !== '' && $viewerKey !== '' && hash_equals($viewerKey, $senderKey)) {
            $recipientKey = $publisherKey;
            $bridge['last_viewer_heartbeat'] = iotzyCameraStreamNow();
        } else {
            return ['success' => false, 'feature_ready' => true, 'error' => 'Konteks device live tidak valid'];
        }

        $candidateJson = iotzyNormalizeWebRtcCandidatePayload($candidate);
        if ($candidateJson === '' || $recipientKey === '') {
            iotzyPersistCameraLiveBridge($db, (int)$session['camera_id'], $bridge);
            return ['success' => true, 'feature_ready' => true, 'queued' => false];
        }

        $bridge['candidate_seq'] = max(0, (int)$bridge['candidate_seq']) + 1;
        $bridge['candidates'][] = [
            'id' => (int)$bridge['candidate_seq'],
            'recipient_camera_key' => $recipientKey,
            'candidate_json' => $candidateJson,
        ];
        if (count($bridge['candidates']) > 120) {
            $bridge['candidates'] = array_slice($bridge['candidates'], -120);
        }

        iotzyPersistCameraLiveBridge($db, (int)$session['camera_id'], $bridge);
        return ['success' => true, 'feature_ready' => true, 'queued' => true];
    }

    $resolvedContext = iotzyResolveCameraContext($cameraContext, $userId);
    $session = iotzyFetchCameraStreamSession($db, $userId, $streamKey);
    if (!$session) {
        return ['success' => false, 'feature_ready' => true, 'error' => 'Siaran live tidak ditemukan'];
    }

    $senderKey = (string)$resolvedContext['camera_key'];
    $publisherKey = (string)($session['publisher_camera_key'] ?? '');
    $viewerKey = (string)($session['viewer_camera_key'] ?? '');
    $recipientKey = '';

    if ($senderKey !== '' && hash_equals($publisherKey, $senderKey)) {
        $recipientKey = $viewerKey;
        $db->prepare("UPDATE camera_stream_sessions SET last_publisher_heartbeat = NOW() WHERE id = ?")->execute([(int)$session['id']]);
    } elseif ($senderKey !== '' && $viewerKey !== '' && hash_equals($viewerKey, $senderKey)) {
        $recipientKey = $publisherKey;
        $db->prepare("UPDATE camera_stream_sessions SET last_viewer_heartbeat = NOW() WHERE id = ?")->execute([(int)$session['id']]);
    } else {
        return ['success' => false, 'feature_ready' => true, 'error' => 'Konteks device live tidak valid'];
    }

    $candidateJson = iotzyNormalizeWebRtcCandidatePayload($candidate);
    if ($candidateJson === '' || $recipientKey === '') {
        return ['success' => true, 'feature_ready' => true, 'queued' => false];
    }

    $db->prepare(
        "INSERT INTO camera_stream_candidates (
            stream_session_id, sender_camera_key, recipient_camera_key, candidate_json
         ) VALUES (?, ?, ?, ?)"
    )->execute([
        (int)$session['id'],
        $senderKey,
        $recipientKey,
        $candidateJson,
    ]);

    return ['success' => true, 'feature_ready' => true, 'queued' => true];
}

function pollUserCameraStreamUpdates(int $userId, array $cameraContext, string $streamKey, int $lastCandidateId = 0, ?PDO $db = null): array
{
    $db = $db ?: getLocalDB();
    if (!$db) {
        return ['success' => false, 'feature_ready' => false, 'error' => 'Database unavailable'];
    }

    if (!iotzyCameraStreamFeatureReady($db)) {
        return ['success' => false] + iotzyCameraStreamFeatureStatus($db);
    }

    if (!iotzyCameraStreamUsesDedicatedTables($db)) {
        iotzyCleanupLegacyCameraStreamSessions($db, $userId);
        $resolvedContext = iotzyResolveCameraContext($cameraContext, $userId);
        $session = iotzyFindLegacyCameraStreamSession($db, $userId, $streamKey);
        if (!$session) {
            return ['success' => false, 'feature_ready' => true, 'error' => 'Siaran live tidak ditemukan'];
        }

        $requesterKey = (string)$resolvedContext['camera_key'];
        $publisherKey = (string)($session['publisher_camera_key'] ?? '');
        $viewerKey = (string)($session['viewer_camera_key'] ?? '');
        $bridge = [
            'stream_key' => $session['stream_key'],
            'publisher_camera_key' => $session['publisher_camera_key'],
            'publisher_name' => $session['publisher_name'],
            'source_label' => $session['source_label'],
            'viewer_camera_key' => $session['viewer_camera_key'],
            'viewer_name' => $session['viewer_name'],
            'offer_sdp' => $session['offer_sdp'],
            'answer_sdp' => $session['answer_sdp'],
            'status' => $session['status'],
            'started_at' => $session['started_at'],
            'last_publisher_heartbeat' => $session['last_publisher_heartbeat'],
            'last_viewer_heartbeat' => $session['last_viewer_heartbeat'],
            'updated_at' => iotzyCameraStreamNow(),
            'candidate_seq' => $session['candidate_seq'] ?? 0,
            'candidates' => $session['legacy_candidates'] ?? [],
        ];

        if ($requesterKey !== '' && hash_equals($publisherKey, $requesterKey)) {
            $bridge['last_publisher_heartbeat'] = iotzyCameraStreamNow();
        } elseif ($requesterKey !== '' && $viewerKey !== '' && hash_equals($viewerKey, $requesterKey)) {
            $bridge['last_viewer_heartbeat'] = iotzyCameraStreamNow();
        }

        iotzyPersistCameraLiveBridge($db, (int)$session['camera_id'], $bridge);
        $fresh = iotzyBuildLegacyCameraStreamRow(['id' => $session['camera_id'], 'name' => $session['publisher_name']], $bridge);
        $candidateRows = array_values(array_filter(
            $bridge['candidates'],
            static fn(array $row): bool => (int)($row['id'] ?? 0) > max(0, $lastCandidateId)
                && hash_equals((string)($row['recipient_camera_key'] ?? ''), $requesterKey)
        ));

        return [
            'success' => true,
            'feature_ready' => true,
            'session' => iotzyBuildCameraStreamSummary($fresh, $requesterKey),
            'offer_sdp' => hash_equals((string)($fresh['viewer_camera_key'] ?? ''), $requesterKey)
                ? iotzyNormalizeWebRtcSdp($fresh['offer_sdp'] ?? '')
                : null,
            'answer_sdp' => hash_equals((string)($fresh['publisher_camera_key'] ?? ''), $requesterKey)
                ? iotzyNormalizeWebRtcSdp($fresh['answer_sdp'] ?? '')
                : null,
            'candidates' => array_map(
                static fn(array $row): array => [
                    'id' => (int)($row['id'] ?? 0),
                    'candidate' => iotzyJsonDecode($row['candidate_json'] ?? '', $row['candidate_json'] ?? ''),
                ],
                array_slice($candidateRows, 0, 50)
            ),
        ];
    }

    iotzyCleanupCameraStreamSessions($db, $userId);
    $resolvedContext = iotzyResolveCameraContext($cameraContext, $userId);
    $session = iotzyFetchCameraStreamSession($db, $userId, $streamKey);
    if (!$session) {
        return ['success' => false, 'feature_ready' => true, 'error' => 'Siaran live tidak ditemukan'];
    }

    $requesterKey = (string)$resolvedContext['camera_key'];
    $sessionId = (int)$session['id'];
    $publisherKey = (string)($session['publisher_camera_key'] ?? '');
    $viewerKey = (string)($session['viewer_camera_key'] ?? '');

    if ($requesterKey !== '' && hash_equals($publisherKey, $requesterKey)) {
        $db->prepare("UPDATE camera_stream_sessions SET last_publisher_heartbeat = NOW() WHERE id = ?")->execute([$sessionId]);
    } elseif ($requesterKey !== '' && $viewerKey !== '' && hash_equals($viewerKey, $requesterKey)) {
        $db->prepare("UPDATE camera_stream_sessions SET last_viewer_heartbeat = NOW() WHERE id = ?")->execute([$sessionId]);
    }

    $candidateStmt = $db->prepare(
        "SELECT id, candidate_json
         FROM camera_stream_candidates
         WHERE stream_session_id = ?
           AND recipient_camera_key = ?
           AND id > ?
         ORDER BY id ASC
         LIMIT 50"
    );
    $candidateStmt->execute([$sessionId, $requesterKey, max(0, $lastCandidateId)]);
    $candidateRows = $candidateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($candidateRows) {
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $candidateRows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE camera_stream_candidates SET delivered_at = NOW() WHERE id IN ($placeholders)")->execute($ids);
    }

    $fresh = iotzyFetchCameraStreamSession($db, $userId, $streamKey) ?: $session;
    return [
        'success' => true,
        'feature_ready' => true,
        'session' => iotzyBuildCameraStreamSummary($fresh, $requesterKey),
        'offer_sdp' => hash_equals((string)($fresh['viewer_camera_key'] ?? ''), $requesterKey)
            ? iotzyNormalizeWebRtcSdp($fresh['offer_sdp'] ?? '')
            : null,
        'answer_sdp' => hash_equals((string)($fresh['publisher_camera_key'] ?? ''), $requesterKey)
            ? iotzyNormalizeWebRtcSdp($fresh['answer_sdp'] ?? '')
            : null,
        'candidates' => array_map(
            static fn(array $row): array => [
                'id' => (int)$row['id'],
                'candidate' => iotzyJsonDecode($row['candidate_json'] ?? '', $row['candidate_json'] ?? ''),
            ],
            $candidateRows
        ),
    ];
}

function stopUserCameraStreamSession(int $userId, array $cameraContext, string $streamKey, ?PDO $db = null): array
{
    $db = $db ?: getLocalDB();
    if (!$db) {
        return ['success' => false, 'feature_ready' => false, 'error' => 'Database unavailable'];
    }

    if (!iotzyCameraStreamFeatureReady($db)) {
        return ['success' => false] + iotzyCameraStreamFeatureStatus($db);
    }

    if (!iotzyCameraStreamUsesDedicatedTables($db)) {
        $resolvedContext = iotzyResolveCameraContext($cameraContext, $userId);
        $session = iotzyFindLegacyCameraStreamSession($db, $userId, $streamKey);
        if (!$session) {
            return ['success' => true, 'feature_ready' => true, 'session' => null];
        }

        $requesterKey = (string)$resolvedContext['camera_key'];
        if ($requesterKey !== '' && hash_equals((string)($session['publisher_camera_key'] ?? ''), $requesterKey)) {
            iotzyPersistCameraLiveBridge($db, (int)$session['camera_id'], null);
            return ['success' => true, 'feature_ready' => true, 'session' => null];
        }

        if ($requesterKey !== '' && hash_equals((string)($session['viewer_camera_key'] ?? ''), $requesterKey)) {
            $bridge = [
                'stream_key' => $session['stream_key'],
                'publisher_camera_key' => $session['publisher_camera_key'],
                'publisher_name' => $session['publisher_name'],
                'source_label' => $session['source_label'],
                'viewer_camera_key' => null,
                'viewer_name' => null,
                'offer_sdp' => $session['offer_sdp'],
                'answer_sdp' => null,
                'status' => 'awaiting_viewer',
                'started_at' => $session['started_at'],
                'last_publisher_heartbeat' => $session['last_publisher_heartbeat'],
                'last_viewer_heartbeat' => null,
                'updated_at' => iotzyCameraStreamNow(),
                'candidate_seq' => 0,
                'candidates' => [],
            ];
            iotzyPersistCameraLiveBridge($db, (int)$session['camera_id'], $bridge);
            $fresh = iotzyBuildLegacyCameraStreamRow(['id' => $session['camera_id'], 'name' => $session['publisher_name']], $bridge);
            return [
                'success' => true,
                'feature_ready' => true,
                'session' => iotzyBuildCameraStreamSummary($fresh, $requesterKey),
            ];
        }

        return ['success' => false, 'feature_ready' => true, 'error' => 'Konteks device live tidak valid'];
    }

    $resolvedContext = iotzyResolveCameraContext($cameraContext, $userId);
    $session = iotzyFetchCameraStreamSession($db, $userId, $streamKey);
    if (!$session) {
        return ['success' => true, 'feature_ready' => true, 'session' => null];
    }

    $requesterKey = (string)$resolvedContext['camera_key'];
    $sessionId = (int)$session['id'];
    if ($requesterKey !== '' && hash_equals((string)($session['publisher_camera_key'] ?? ''), $requesterKey)) {
        $db->prepare(
            "UPDATE camera_stream_sessions
             SET status = 'ended',
                 answer_sdp = NULL,
                 viewer_camera_key = NULL,
                 viewer_name = NULL,
                 last_viewer_heartbeat = NULL,
                 ended_at = NOW()
             WHERE id = ?"
        )->execute([$sessionId]);
    } elseif ($requesterKey !== '' && hash_equals((string)($session['viewer_camera_key'] ?? ''), $requesterKey)) {
        $db->prepare(
            "UPDATE camera_stream_sessions
             SET viewer_camera_key = NULL,
                 viewer_name = NULL,
                 answer_sdp = NULL,
                 status = 'awaiting_viewer',
                 last_viewer_heartbeat = NULL
             WHERE id = ?"
        )->execute([$sessionId]);
    } else {
        return ['success' => false, 'feature_ready' => true, 'error' => 'Konteks device live tidak valid'];
    }

    $db->prepare("DELETE FROM camera_stream_candidates WHERE stream_session_id = ?")->execute([$sessionId]);
    $fresh = iotzyFetchCameraStreamSession($db, $userId, $streamKey);

    return [
        'success' => true,
        'feature_ready' => true,
        'session' => $fresh ? iotzyBuildCameraStreamSummary($fresh, $requesterKey) : null,
    ];
}

function addActivityLog(
    int $userId,
    string $deviceName,
    string $activity,
    string $triggerType = 'System',
    string $logType = 'info',
    ?int $deviceId = null,
    ?int $sensorId = null,
    mixed $metadata = null
): void {
    $db = getLocalDB();
    if (!$db) {
        return;
    }

    $allowed = ['info', 'success', 'warning', 'error'];
    if (!in_array($logType, $allowed, true)) {
        $logType = 'info';
    }

    $metadataJson = null;
    if ($metadata !== null) {
        $metadataJson = is_string($metadata) ? $metadata : json_encode($metadata, JSON_UNESCAPED_UNICODE);
    }

    try {
        $db->prepare(
            "INSERT INTO activity_logs (user_id, device_id, sensor_id, device_name, activity, trigger_type, log_type, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $userId,
            $deviceId,
            $sensorId,
            substr($deviceName, 0, 100),
            substr($activity, 0, 200),
            substr($triggerType, 0, 50),
            $logType,
            $metadataJson,
        ]);
    } catch (PDOException $e) {
    }
}

function iotzyIsUserFacingLog(array $log): bool
{
    $deviceId = isset($log['device_id']) && $log['device_id'] !== null ? (int)$log['device_id'] : 0;
    $sensorId = isset($log['sensor_id']) && $log['sensor_id'] !== null ? (int)$log['sensor_id'] : 0;
    if ($deviceId > 0 || $sensorId > 0) {
        return true;
    }

    $deviceName = strtolower(trim((string)($log['linked_device_name'] ?? $log['device_name'] ?? $log['device'] ?? '')));
    $trigger = strtolower(trim((string)($log['trigger_type'] ?? $log['trigger'] ?? '')));

    if ($deviceName === 'system' || $deviceName === 'mqtt') {
        return false;
    }

    if ($trigger === 'system') {
        return false;
    }

    return trim((string)($log['activity'] ?? '')) !== '';
}

function iotzyBuildAnalyticsDefaults(string $date): array
{
    return [
        'date' => $date,
        'summary' => [
            'total_logs' => 0,
            'devices_total' => 0,
            'devices_active_today' => 0,
            'devices_idle_today' => 0,
            'device_on_events' => 0,
            'device_off_events' => 0,
            'total_duration_seconds' => 0,
            'total_duration_human' => '0d',
            'current_power_watts' => 0,
            'total_energy_wh' => 0,
            'total_energy_kwh' => 0,
            'power_devices' => 0,
        ],
        'timeline' => array_fill(0, 24, 0),
        'devices' => [],
        'recent_logs' => [],
    ];
}

function getDailyAnalyticsHeadlineSummary(int $userId, ?string $date = null, ?PDO $db = null, ?array $devices = null, ?array $sensors = null): array
{
    $db = $db ?: getLocalDB();
    $date = iotzyNormalizeAnalyticsDate($date);
    $defaults = iotzyBuildAnalyticsDefaults($date);

    if (!$db) {
        return $defaults;
    }

    $start = $date . ' 00:00:00';
    $end = date('Y-m-d H:i:s', strtotime($start . ' +1 day'));
    $dayStartTs = strtotime($start);
    $dayEndTs = strtotime($end);

    $devices = $devices ?? getUserDevices($userId, $db);
    $sensors = $sensors ?? getUserSensors($userId, $db);
    $summary = $defaults['summary'];
    $summary['devices_total'] = count($devices);

    $deviceMap = [];
    foreach ($devices as $device) {
        $deviceMap[(int)$device['id']] = [
            'active_duration_seconds' => 0,
            'on_events' => 0,
            'off_events' => 0,
            'latest_power_watts' => 0.0,
            'energy_wh' => 0.0,
            'has_power_data' => false,
        ];
    }

    $logsStmt = $db->prepare(
        "SELECT device_id, activity
         FROM activity_logs
         WHERE user_id = ? AND created_at >= ? AND created_at < ?
           AND (
             device_id IS NOT NULL
             OR sensor_id IS NOT NULL
             OR (
               LOWER(device_name) NOT IN ('system', 'mqtt')
               AND LOWER(trigger_type) <> 'system'
               AND activity <> ''
             )
           )
         ORDER BY created_at DESC"
    );
    $logsStmt->execute([$userId, $start, $end]);
    foreach ($logsStmt->fetchAll(PDO::FETCH_ASSOC) as $log) {
        $summary['total_logs']++;
        $deviceId = $log['device_id'] !== null ? (int)$log['device_id'] : null;
        if (!$deviceId || !isset($deviceMap[$deviceId])) {
            continue;
        }
        if (preg_match('/\b(on|dinyalakan|nyala|aktif|open)\b/i', (string)$log['activity'])) {
            $deviceMap[$deviceId]['on_events']++;
        }
        if (preg_match('/\b(off|dimatikan|mati|lock|terkunci)\b/i', (string)$log['activity'])) {
            $deviceMap[$deviceId]['off_events']++;
        }
    }

    $sessionsStmt = $db->prepare(
        "SELECT device_id, turned_on_at, turned_off_at, energy_wh, latest_power_watts
         FROM device_sessions
         WHERE user_id = ?
           AND turned_on_at < ?
           AND COALESCE(turned_off_at, ?) >= ?
         ORDER BY turned_on_at ASC"
    );
    $sessionsStmt->execute([$userId, $end, $end, $start]);
    foreach ($sessionsStmt->fetchAll(PDO::FETCH_ASSOC) as $session) {
        $deviceId = (int)$session['device_id'];
        if (!isset($deviceMap[$deviceId])) {
            continue;
        }

        $onTs = strtotime($session['turned_on_at']);
        $offTs = $session['turned_off_at'] ? strtotime($session['turned_off_at']) : $dayEndTs;
        $overlapStart = max($onTs, $dayStartTs);
        $overlapEnd = min($offTs, $dayEndTs);
        $duration = max(0, $overlapEnd - $overlapStart);

        $deviceMap[$deviceId]['active_duration_seconds'] += $duration;
        if ($session['energy_wh'] !== null) {
            $deviceMap[$deviceId]['energy_wh'] += (float)$session['energy_wh'];
            $deviceMap[$deviceId]['has_power_data'] = true;
        }
        if ($session['latest_power_watts'] !== null) {
            $deviceMap[$deviceId]['latest_power_watts'] = max(
                (float)$deviceMap[$deviceId]['latest_power_watts'],
                (float)$session['latest_power_watts']
            );
            $deviceMap[$deviceId]['has_power_data'] = true;
        }
    }

    $powerSensors = array_values(array_filter($sensors, static function (array $sensor): bool {
        return !empty($sensor['device_id'])
            && (((string)($sensor['template_metric'] ?? '')) === 'power'
            || ((string)($sensor['template_slug'] ?? '')) === 'ina219_power'
            || ((string)$sensor['type']) === 'power');
    }));

    if ($powerSensors) {
        $sensorIds = array_map(static fn(array $sensor): int => (int)$sensor['id'], $powerSensors);
        $placeholders = implode(',', array_fill(0, count($sensorIds), '?'));
        $readingsStmt = $db->prepare(
            "SELECT sensor_id, value, recorded_at
             FROM sensor_readings
             WHERE sensor_id IN ($placeholders) AND recorded_at >= ? AND recorded_at < ?
             ORDER BY sensor_id ASC, recorded_at ASC"
        );
        $readingsStmt->execute(array_merge($sensorIds, [$start, $end]));
        $grouped = [];
        $sensorPowerByDevice = [];
        foreach ($readingsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grouped[(int)$row['sensor_id']][] = [
                'value' => (float)$row['value'],
                'ts' => strtotime($row['recorded_at']),
            ];
        }

        foreach ($powerSensors as $sensor) {
            $deviceId = (int)$sensor['device_id'];
            if (!isset($deviceMap[$deviceId])) {
                continue;
            }

            $series = $grouped[(int)$sensor['id']] ?? [];
            if (!$series && $sensor['latest_value'] !== null) {
                $series[] = [
                    'value' => (float)$sensor['latest_value'],
                    'ts' => $dayEndTs,
                ];
            }
            if (!$series) {
                continue;
            }

            $latestValue = (float)$series[count($series) - 1]['value'];
            $energyWh = 0.0;
            if (count($series) > 1) {
                for ($i = 1, $len = count($series); $i < $len; $i++) {
                    $prev = $series[$i - 1];
                    $curr = $series[$i];
                    $hours = max(0, $curr['ts'] - $prev['ts']) / 3600;
                    $energyWh += (($prev['value'] + $curr['value']) / 2) * $hours;
                }
            }

            if (!isset($sensorPowerByDevice[$deviceId])) {
                $sensorPowerByDevice[$deviceId] = [
                    'latest_power_watts' => 0.0,
                    'energy_wh' => 0.0,
                ];
            }
            $sensorPowerByDevice[$deviceId]['latest_power_watts'] += $latestValue;
            if ($energyWh > 0) {
                $sensorPowerByDevice[$deviceId]['energy_wh'] += $energyWh;
            }
        }

        foreach ($sensorPowerByDevice as $deviceId => $powerData) {
            if (!isset($deviceMap[$deviceId])) {
                continue;
            }
            $deviceMap[$deviceId]['latest_power_watts'] = $powerData['latest_power_watts'];
            if ($powerData['energy_wh'] > 0) {
                $deviceMap[$deviceId]['energy_wh'] = $powerData['energy_wh'];
            }
            $deviceMap[$deviceId]['has_power_data'] = true;
        }
    }

    foreach ($deviceMap as $device) {
        $summary['device_on_events'] += (int)$device['on_events'];
        $summary['device_off_events'] += (int)$device['off_events'];
        $summary['total_duration_seconds'] += (int)$device['active_duration_seconds'];
        $summary['total_energy_wh'] += (float)$device['energy_wh'];

        if ((int)$device['active_duration_seconds'] > 0) {
            $summary['devices_active_today']++;
        }
        if ($device['has_power_data']) {
            $summary['power_devices']++;
            $summary['current_power_watts'] += (float)$device['latest_power_watts'];
        }
    }

    $summary['devices_idle_today'] = max(0, $summary['devices_total'] - $summary['devices_active_today']);
    $summary['total_duration_human'] = iotzyHumanDuration((int)$summary['total_duration_seconds']);
    $summary['total_energy_wh'] = round((float)$summary['total_energy_wh'], 3);
    $summary['total_energy_kwh'] = round(((float)$summary['total_energy_wh']) / 1000, 4);
    $summary['current_power_watts'] = round((float)$summary['current_power_watts'], 3);

    return [
        'date' => $date,
        'summary' => $summary,
        'timeline' => $defaults['timeline'],
        'devices' => [],
        'recent_logs' => [],
    ];
}

function getDailyAnalyticsSummary(int $userId, ?string $date = null, ?PDO $db = null, ?array $devices = null, ?array $sensors = null): array
{
    $db = $db ?: getLocalDB();
    $date = iotzyNormalizeAnalyticsDate($date);
    $defaults = iotzyBuildAnalyticsDefaults($date);

    if (!$db) {
        return $defaults;
    }

    $start = $date . ' 00:00:00';
    $end = date('Y-m-d H:i:s', strtotime($start . ' +1 day'));
    $dayStartTs = strtotime($start);
    $dayEndTs = strtotime($end);

    $devices = $devices ?? getUserDevices($userId, $db);
    $sensors = $sensors ?? getUserSensors($userId, $db);
    $deviceMap = [];

    foreach ($devices as $device) {
        $deviceMap[(int)$device['id']] = [
            'id' => (int)$device['id'],
            'name' => $device['name'],
            'type' => $device['type'],
            'icon' => $device['icon'],
            'is_active' => (int)$device['is_active'],
            'last_state' => (int)$device['last_state'],
            'latest_state' => (int)$device['latest_state'],
            'last_seen' => $device['last_seen'],
            'template_name' => $device['template_name'] ?? null,
            'template_slug' => $device['template_slug'] ?? null,
            'model_label' => $device['model_label'] ?? $device['name'],
            'control_mode' => $device['control_mode'] ?? 'binary',
            'state_on_label' => $device['resolved_state_on_label'] ?? 'ON',
            'state_off_label' => $device['resolved_state_off_label'] ?? 'OFF',
            'active_duration_seconds' => 0,
            'active_duration_human' => '0d',
            'session_count' => 0,
            'on_events' => 0,
            'off_events' => 0,
            'logs_count' => 0,
            'linked_sensors' => [],
            'latest_power_watts' => null,
            'avg_power_watts' => null,
            'peak_power_watts' => null,
            'energy_wh' => 0.0,
            'energy_kwh' => 0.0,
        ];
    }

    foreach ($sensors as $sensor) {
        if (empty($sensor['device_id']) || !isset($deviceMap[(int)$sensor['device_id']])) {
            continue;
        }
        $deviceMap[(int)$sensor['device_id']]['linked_sensors'][] = [
            'id' => (int)$sensor['id'],
            'name' => $sensor['name'],
            'type' => $sensor['type'],
            'icon' => $sensor['icon'],
            'unit' => $sensor['unit'],
            'latest_value' => $sensor['latest_value'],
            'last_seen' => $sensor['last_seen'],
            'template_name' => $sensor['template_name'] ?? null,
            'template_metric' => $sensor['template_metric'] ?? null,
            'is_power_metric' => (int)($sensor['is_power_metric'] ?? 0),
        ];
    }

    $logsStmt = $db->prepare(
        "SELECT l.*, d.name AS linked_device_name, s.name AS linked_sensor_name
         FROM activity_logs l
         LEFT JOIN devices d ON d.id = l.device_id
         LEFT JOIN sensors s ON s.id = l.sensor_id
         WHERE l.user_id = ? AND l.created_at >= ? AND l.created_at < ?
           AND (
             l.device_id IS NOT NULL
             OR l.sensor_id IS NOT NULL
             OR (
               LOWER(l.device_name) NOT IN ('system', 'mqtt')
               AND LOWER(l.trigger_type) <> 'system'
               AND l.activity <> ''
             )
           )
         ORDER BY l.created_at DESC"
    );
    $logsStmt->execute([$userId, $start, $end]);
    $logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

    $timeline = array_fill(0, 24, 0);
    $recentLogs = [];
    foreach ($logs as $log) {
        $ts = strtotime($log['created_at']);
        $timeline[(int)date('G', $ts)]++;
        $deviceId = $log['device_id'] !== null ? (int)$log['device_id'] : null;
        if ($deviceId && isset($deviceMap[$deviceId])) {
            $deviceMap[$deviceId]['logs_count']++;
            if (preg_match('/\b(on|dinyalakan|nyala|aktif|open)\b/i', (string)$log['activity'])) {
                $deviceMap[$deviceId]['on_events']++;
            }
            if (preg_match('/\b(off|dimatikan|mati|lock|terkunci)\b/i', (string)$log['activity'])) {
                $deviceMap[$deviceId]['off_events']++;
            }
        }
        $recentLogs[] = [
            'id' => (int)$log['id'],
            'created_at' => $log['created_at'],
            'tanggal' => date('d M Y', $ts),
            'waktu' => date('H:i', $ts),
            'device' => $log['linked_device_name'] ?: $log['device_name'],
            'activity' => $log['activity'],
            'trigger' => $log['trigger_type'],
            'type' => $log['log_type'],
            'device_id' => $deviceId,
            'sensor_id' => $log['sensor_id'] !== null ? (int)$log['sensor_id'] : null,
            'sensor_name' => $log['linked_sensor_name'],
            'metadata' => iotzyJsonDecode($log['metadata'], null),
        ];
    }

    $sessionsStmt = $db->prepare(
        "SELECT ds.*, d.name AS device_name
         FROM device_sessions ds
         INNER JOIN devices d ON d.id = ds.device_id
         WHERE ds.user_id = ?
           AND ds.turned_on_at < ?
           AND COALESCE(ds.turned_off_at, ?) >= ?
         ORDER BY ds.turned_on_at ASC"
    );
    $sessionsStmt->execute([$userId, $end, $end, $start]);
    $sessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sessions as $session) {
        $deviceId = (int)$session['device_id'];
        if (!isset($deviceMap[$deviceId])) {
            continue;
        }
        $onTs = strtotime($session['turned_on_at']);
        $offTs = $session['turned_off_at'] ? strtotime($session['turned_off_at']) : $dayEndTs;
        $overlapStart = max($onTs, $dayStartTs);
        $overlapEnd = min($offTs, $dayEndTs);
        $duration = max(0, $overlapEnd - $overlapStart);

        $deviceMap[$deviceId]['active_duration_seconds'] += $duration;
        $deviceMap[$deviceId]['session_count']++;

        if ($session['energy_wh'] !== null) {
            $deviceMap[$deviceId]['energy_wh'] += (float)$session['energy_wh'];
        }
        if ($session['latest_power_watts'] !== null) {
            $deviceMap[$deviceId]['latest_power_watts'] = max(
                (float)($deviceMap[$deviceId]['latest_power_watts'] ?? 0),
                (float)$session['latest_power_watts']
            );
        }
        if ($session['peak_power_watts'] !== null) {
            $deviceMap[$deviceId]['peak_power_watts'] = max(
                (float)($deviceMap[$deviceId]['peak_power_watts'] ?? 0),
                (float)$session['peak_power_watts']
            );
        }
        if ($session['avg_power_watts'] !== null) {
            $deviceMap[$deviceId]['avg_power_watts'] = max(
                (float)($deviceMap[$deviceId]['avg_power_watts'] ?? 0),
                (float)$session['avg_power_watts']
            );
        }
    }

    $powerSensors = array_values(array_filter($sensors, static function (array $sensor): bool {
        return !empty($sensor['device_id'])
            && (((string)($sensor['template_metric'] ?? '')) === 'power'
            || ((string)($sensor['template_slug'] ?? '')) === 'ina219_power'
            || ((string)$sensor['type']) === 'power');
    }));

    if ($powerSensors) {
        $sensorIds = array_map(static fn(array $sensor): int => (int)$sensor['id'], $powerSensors);
        $placeholders = implode(',', array_fill(0, count($sensorIds), '?'));
        $readingsStmt = $db->prepare(
            "SELECT sensor_id, value, recorded_at
             FROM sensor_readings
             WHERE sensor_id IN ($placeholders) AND recorded_at >= ? AND recorded_at < ?
             ORDER BY sensor_id ASC, recorded_at ASC"
        );
        $readingsStmt->execute(array_merge($sensorIds, [$start, $end]));
        $rows = $readingsStmt->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        $sensorPowerByDevice = [];
        foreach ($rows as $row) {
            $grouped[(int)$row['sensor_id']][] = [
                'value' => (float)$row['value'],
                'ts' => strtotime($row['recorded_at']),
            ];
        }

        foreach ($powerSensors as $sensor) {
            $sensorId = (int)$sensor['id'];
            $deviceId = (int)$sensor['device_id'];
            if (!isset($deviceMap[$deviceId])) {
                continue;
            }

            $series = $grouped[$sensorId] ?? [];
            $values = array_map(static fn(array $point): float => $point['value'], $series);
            if (!$values && $sensor['latest_value'] !== null) {
                $values = [(float)$sensor['latest_value']];
            }

            $energyWh = 0.0;
            if (count($series) > 1) {
                for ($i = 1, $len = count($series); $i < $len; $i++) {
                    $prev = $series[$i - 1];
                    $curr = $series[$i];
                    $hours = max(0, $curr['ts'] - $prev['ts']) / 3600;
                    $energyWh += (($prev['value'] + $curr['value']) / 2) * $hours;
                }
            }

            if ($values) {
                if (!isset($sensorPowerByDevice[$deviceId])) {
                    $sensorPowerByDevice[$deviceId] = [
                        'latest_power_watts' => 0.0,
                        'avg_power_watts' => 0.0,
                        'peak_power_watts' => 0.0,
                        'energy_wh' => 0.0,
                    ];
                }
                $sensorPowerByDevice[$deviceId]['latest_power_watts'] += (float)end($values);
                $sensorPowerByDevice[$deviceId]['avg_power_watts'] += (array_sum($values) / count($values));
                $sensorPowerByDevice[$deviceId]['peak_power_watts'] = max(
                    $sensorPowerByDevice[$deviceId]['peak_power_watts'],
                    (float)max($values)
                );
            }
            if ($energyWh > 0) {
                if (!isset($sensorPowerByDevice[$deviceId])) {
                    $sensorPowerByDevice[$deviceId] = [
                        'latest_power_watts' => 0.0,
                        'avg_power_watts' => 0.0,
                        'peak_power_watts' => 0.0,
                        'energy_wh' => 0.0,
                    ];
                }
                $sensorPowerByDevice[$deviceId]['energy_wh'] += $energyWh;
            }
        }

        foreach ($sensorPowerByDevice as $deviceId => $powerData) {
            if (!isset($deviceMap[$deviceId])) {
                continue;
            }
            $deviceMap[$deviceId]['latest_power_watts'] = $powerData['latest_power_watts'];
            $deviceMap[$deviceId]['avg_power_watts'] = $powerData['avg_power_watts'];
            $deviceMap[$deviceId]['peak_power_watts'] = max(
                (float)($deviceMap[$deviceId]['peak_power_watts'] ?? 0),
                $powerData['peak_power_watts']
            );
            if ($powerData['energy_wh'] > 0) {
                $deviceMap[$deviceId]['energy_wh'] = $powerData['energy_wh'];
            }
        }
    }

    $devicesOut = [];
    $summary = $defaults['summary'];
    $summary['total_logs'] = count($recentLogs);
    $summary['devices_total'] = count($deviceMap);

    foreach ($deviceMap as $device) {
        $device['active_duration_human'] = iotzyHumanDuration((int)$device['active_duration_seconds']);
        $device['energy_kwh'] = round(((float)$device['energy_wh']) / 1000, 4);
        if ($device['avg_power_watts'] !== null) {
            $device['avg_power_watts'] = round((float)$device['avg_power_watts'], 3);
        }
        if ($device['peak_power_watts'] !== null) {
            $device['peak_power_watts'] = round((float)$device['peak_power_watts'], 3);
        }
        if ($device['latest_power_watts'] !== null) {
            $device['latest_power_watts'] = round((float)$device['latest_power_watts'], 3);
        }

        $summary['device_on_events'] += (int)$device['on_events'];
        $summary['device_off_events'] += (int)$device['off_events'];
        $summary['total_duration_seconds'] += (int)$device['active_duration_seconds'];
        $summary['total_energy_wh'] += (float)$device['energy_wh'];

        if ((int)$device['active_duration_seconds'] > 0) {
            $summary['devices_active_today']++;
        }
        if ((float)$device['energy_wh'] > 0 || $device['latest_power_watts'] !== null) {
            $summary['power_devices']++;
            $summary['current_power_watts'] += (float)($device['latest_power_watts'] ?? 0);
        }

        $devicesOut[] = $device;
    }

    $summary['devices_idle_today'] = max(0, $summary['devices_total'] - $summary['devices_active_today']);
    $summary['total_duration_human'] = iotzyHumanDuration((int)$summary['total_duration_seconds']);
    $summary['total_energy_wh'] = round((float)$summary['total_energy_wh'], 3);
    $summary['total_energy_kwh'] = round(((float)$summary['total_energy_wh']) / 1000, 4);
    $summary['current_power_watts'] = round((float)$summary['current_power_watts'], 3);

    usort($devicesOut, static function (array $a, array $b): int {
        $durationCompare = ($b['active_duration_seconds'] <=> $a['active_duration_seconds']);
        return $durationCompare !== 0 ? $durationCompare : strcmp($a['name'], $b['name']);
    });

    return [
        'date' => $date,
        'summary' => $summary,
        'timeline' => $timeline,
        'devices' => $devicesOut,
        'recent_logs' => $recentLogs,
    ];
}
