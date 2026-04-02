<?php

$detectedUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$detectedUrl = preg_replace('/\/(api|controllers|core|pages|components|assets)$/', '', $detectedUrl);
if ($detectedUrl === '/')
    $detectedUrl = '';

define('APP_NAME', getenv('APP_NAME') ?: 'IoTzy');

$isVercel = isset($_SERVER['VERCEL']) || getenv('VERCEL') === "1";
$configuredAppUrl = trim((string)(getenv('APP_URL') ?: ''));
$appUrl = $isVercel
    ? ($configuredAppUrl !== '' ? rtrim($configuredAppUrl, '/') : $detectedUrl)
    : $detectedUrl;

define('APP_URL', $appUrl);
define('ASSET_URL', APP_URL . '/assets');

define('APP_RELEASE', '2.0.1');

$vercelBuildRef = trim((string)(
    getenv('VERCEL_GIT_COMMIT_SHA')
    ?: getenv('VERCEL_DEPLOYMENT_ID')
    ?: ''
));
$vercelBuildRef = preg_replace('/[^a-zA-Z0-9._-]/', '', $vercelBuildRef ?? '');
$buildId = $vercelBuildRef !== '' ? substr($vercelBuildRef, 0, 12) : 'dev';

define('APP_BUILD', $buildId);
define('APP_VERSION', APP_RELEASE . '-' . APP_BUILD);

if (!function_exists('iotzyAssetVersion')) {
    function iotzyAssetVersion(string $relativeAssetPath = ''): string
    {
        $relativeAssetPath = ltrim(str_replace('\\', '/', $relativeAssetPath), '/');

        if (APP_BUILD !== 'dev') {
            return APP_BUILD;
        }

        if ($relativeAssetPath !== '') {
            $absolutePath = dirname(__DIR__) . '/' . $relativeAssetPath;
            if (is_file($absolutePath)) {
                return (string)filemtime($absolutePath);
            }
        }

        return APP_VERSION;
    }
}

if (!function_exists('iotzyAssetUrl')) {
    function iotzyAssetUrl(string $relativeAssetPath): string
    {
        $normalizedPath = ltrim(str_replace('\\', '/', $relativeAssetPath), '/');
        return ASSET_URL . '/' . $normalizedPath . '?v=' . rawurlencode(iotzyAssetVersion('assets/' . $normalizedPath));
    }
}

$appSecret = getenv('APP_SECRET');
if (!$appSecret) {
    error_log('[IoTzy FATAL] APP_SECRET tidak diset di environment');
    http_response_code(500);
    exit('Server configuration error.');
}
define('APP_SECRET', $appSecret);

define('SESSION_LIFETIME', 86400);
define('APP_TIMEZONE', getenv('TIMEZONE') ?: 'Asia/Jakarta');

date_default_timezone_set(APP_TIMEZONE);
