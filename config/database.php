<?php

define('DB_CHARSET', 'utf8mb4');

function getLocalDB(): ?PDO {
    static $pdo = null;
    if ($pdo !== null) return ($pdo instanceof PDO) ? $pdo : null;

    try {
        $h = trim((string)(getenv('MYSQL_HOST') ?: ''));
        $port = trim((string)(getenv('MYSQL_PORT') ?: '3306'));
        $d = trim((string)(getenv('MYSQL_DATABASE') ?: ''));
        $u = trim((string)(getenv('MYSQL_USER') ?: ''));
        $p = trim((string)(getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_PASS') ?: ''));

        if (!$h || !$d || !$u) {
            throw new Exception("Konfigurasi database (ENV) tidak lengkap!");
        }

        $dsn = "mysql:host=$h;port=$port;dbname=$d;charset=" . DB_CHARSET;
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // SSL Handling (Aiven Cloud / Managed DB)
        $sslCa = trim((string)(getenv('MYSQL_SSL_CA') ?: ''));
        $sslVerify = !in_array(strtolower(trim((string)(getenv('MYSQL_SSL_VERIFY') ?: 'true'))), ['0', 'false', 'off', 'no'], true);

        // Check if SSL CA file exists if provided
        $caExists = ($sslCa !== '' && file_exists($sslCa));

        // Auto-enable SSL for Aiven Cloud (Port 25145) if no valid CA provided
        if (!$caExists && $port === '25145') {
            if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = ''; // Trigger SSL
                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                }
            }
        } elseif ($caExists) {
            if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $sslVerify;
                }
            } else {
                // Fallback for older PHP or specific setups
                $options[1007] = $sslCa; // PDO::MYSQL_ATTR_SSL_CA
                $options[1014] = $sslVerify; // PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT
            }
        }

        $pdo = new PDO($dsn, $u, $p, $options);
        $pdo->exec("SET NAMES " . DB_CHARSET);

    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
        $GLOBALS['DB_LAST_ERROR'] = $errorMsg;
        
        // Detailed log for Vercel/Local logs
        error_log("[IoTzy DB Error] " . $errorMsg);
        
        // If we are on Vercel, maybe the SSL trigger needs to be more explicit
        if (strpos($errorMsg, 'SSL') !== false || strpos($errorMsg, 'access denied') !== false) {
             error_log("[IoTzy DB Hint] Check if MYSQL_SSL_CA or port 25145 is correctly handled.");
        }
        
        $pdo = false;
    }

    return ($pdo instanceof PDO) ? $pdo : null;
}

function dbWrite(string $sql, array $params = []): bool {
    $db = getLocalDB();
    return $db ? $db->prepare($sql)->execute($params) : false;
}

function dbInsert(string $sql, array $params = []): ?int {
    $db = getLocalDB();
    if (!$db) return null;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$db->lastInsertId();
}

function dbStatus(): array {
    return [ 'mysql' => getLocalDB() !== null ];
}
