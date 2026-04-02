<?php

require_once __DIR__ . '/../bootstrap.php';

function iotzyMobileGetBearerToken(): ?string
{
    $rawHeader = trim((string)(
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['Authorization']
        ?? ''
    ));

    if ($rawHeader === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                $rawHeader = trim((string)$value);
                break;
            }
        }
    }

    if ($rawHeader !== '' && preg_match('/^\s*Bearer\s+(.+)$/i', $rawHeader, $matches)) {
        $token = trim((string)$matches[1]);
        return $token !== '' ? $token : null;
    }

    return null;
}

function iotzyMobileFetchUserProfile(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare(
        "SELECT u.id, u.username, u.email, u.full_name, u.role, u.is_active,
                COALESCE(s.theme, 'light') AS theme
         FROM users u
         LEFT JOIN user_settings s ON s.user_id = u.id
         WHERE u.id = ? AND u.is_active = 1
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'id' => (int)$row['id'],
        'username' => (string)$row['username'],
        'email' => (string)$row['email'],
        'full_name' => (string)($row['full_name'] ?? ''),
        'role' => (string)$row['role'],
        'theme' => (string)($row['theme'] ?? 'light'),
    ];
}

function iotzyMobileIssueSessionToken(PDO $db, int $userId): array
{
    $token = bin2hex(random_bytes(32));
    $ttl = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 86400;
    $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

    $db->prepare("DELETE FROM sessions WHERE user_id = ? AND expires_at < NOW()")->execute([$userId]);
    $db->prepare(
        "INSERT INTO sessions (user_id, session_token, ip_address, user_agent, expires_at)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([
        $userId,
        $token,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        $expiresAt,
    ]);

    return [
        'access_token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => $ttl,
        'refresh_token' => null,
        'refresh_expires_in' => null,
        'mode' => 'single_api_session',
    ];
}

function iotzyMobileLookupSessionToken(PDO $db, string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT id, user_id, expires_at
         FROM sessions
         WHERE session_token = ?
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    if (strtotime((string)$row['expires_at']) <= time()) {
        return null;
    }

    return [
        'mode' => 'single_api_session',
        'token' => $token,
        'session_id' => (int)$row['id'],
        'user_id' => (int)$row['user_id'],
    ];
}

function iotzyMobileRequireAuthContext(PDO $db): array
{
    $token = iotzyMobileGetBearerToken();
    if (!$token) {
        jsonOut(['success' => false, 'error' => 'Authorization bearer token tidak ditemukan'], 401);
    }

    $auth = iotzyMobileLookupSessionToken($db, $token);
    if (!$auth) {
        jsonOut(['success' => false, 'error' => 'Unauthorized'], 401);
    }

    return $auth;
}

function iotzyMobileHandleLogin(PDO $db, array $body): array
{
    $login = trim((string)($body['username'] ?? $body['login'] ?? $body['email'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($login === '' || $password === '') {
        return [
            'success' => false,
            'status' => 422,
            'error' => 'Username/email dan password wajib diisi',
        ];
    }

    $stmt = $db->prepare(
        "SELECT id, password_hash, is_active
         FROM users
         WHERE (username = ? OR email = ?)
         LIMIT 1"
    );
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        return [
            'success' => false,
            'status' => 401,
            'error' => 'Username/email atau password salah',
        ];
    }
    if (!(bool)$user['is_active']) {
        return [
            'success' => false,
            'status' => 403,
            'error' => 'Akun dinonaktifkan',
        ];
    }

    $userId = (int)$user['id'];
    $tokenBundle = iotzyMobileIssueSessionToken($db, $userId);
    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$userId]);
    iotzyEnsureUserSettingsRow($userId, $db);
    $profile = iotzyMobileFetchUserProfile($db, $userId);

    return [
        'success' => true,
        'status' => 200,
        'mode' => $tokenBundle['mode'],
        'token_type' => $tokenBundle['token_type'],
        'access_token' => $tokenBundle['access_token'],
        'expires_in' => $tokenBundle['expires_in'],
        'refresh_token' => null,
        'refresh_expires_in' => null,
        'user' => $profile,
    ];
}

function iotzyMobileHandleLogout(PDO $db, array $auth): array
{
    $token = (string)($auth['token'] ?? '');
    if ($token !== '') {
        $db->prepare("DELETE FROM sessions WHERE session_token = ?")->execute([$token]);
    }

    return [
        'success' => true,
        'status' => 200,
        'message' => 'Logout berhasil',
    ];
}
