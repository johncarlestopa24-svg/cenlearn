<?php
// Set system timezone to ensure strict date & time synchronization across servers
date_default_timezone_set('Asia/Manila');

// Ensure upload directories exist with writable permissions
$baseUploadDir = dirname(__DIR__) . '/uploads';
foreach (['modules', 'submissions'] as $subDir) {
    $targetDir = $baseUploadDir . '/' . $subDir;
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
    }
}

if (!defined('DB_HOST')) {
    if (file_exists(__DIR__ . '/db_config.php')) {
        include_once __DIR__ . '/db_config.php';
    }
}

if (!defined('DB_HOST')) {
    $rawHost = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $host = strtolower(preg_replace('/:\d+$/', '', trim($rawHost))); // remove port if present

    // ── Universal Local & Multi-WiFi Network Detection ──────────────────────────
    // Detects localhost, 127.0.0.1, IPv6 loopback, and all private LAN/WiFi subnets:
    // - 192.168.0.0/16 (Home / Mobile Hotspot / Office WiFis)
    // - 10.0.0.0/8 (Campus / Corporate Enterprise WiFis)
    // - 172.16.0.0/12 (172.16.x.x - 172.31.x.x Subnets)
    // - Local mDNS / dev domains (.local, .test, .lan, .internal, or single hostname)
    $isLocal = ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1')
            || (strpos($host, '192.168.') === 0)
            || (strpos($host, '10.') === 0)
            || (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host) === 1)
            || (substr($host, -6) === '.local' || substr($host, -5) === '.test' || substr($host, -4) === '.lan' || substr($host, -9) === '.internal')
            || (strpos($host, '.') === false)
            || (getenv('APP_ENV') === 'local');

    if ($isLocal) {
        // ── LOCAL (XAMPP / Multi-WiFi LAN) ──────────────────────────────────
        define('DB_HOST', 'localhost');
        define('DB_USER', 'root');
        define('DB_PASS', '');
        define('DB_NAME', 'cenlearn_db');
    } else {
        // ── LIVE SERVER — load from .env file outside webroot ─────────────
        $envFile = dirname(__DIR__, 3) . '/.cenlearn.env';
        if (!file_exists($envFile)) {
            $envFile = dirname(__DIR__, 2) . '/.cenlearn.env';
        }
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos(trim($line), '#') === 0) continue; // skip comments
                [$k, $v] = explode('=', $line, 2) + ['', ''];
                putenv(trim($k) . '=' . trim($v));
            }
        }
        define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
        define('DB_USER', getenv('DB_USER') ?: 'u520834156_usrCenLrn');
        define('DB_PASS', getenv('DB_PASS') ?: 'h+JWyp9X9D1');
        define('DB_NAME', getenv('DB_NAME') ?: 'u520834156_dbCenLearn26');
    }
}

if (!isset($conn)) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $errMsg = null;
    try {
        $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    } catch (\Throwable $e) {
        $conn = null;
        $errMsg = $e->getMessage();
    }

    if (!$conn || $conn->connect_error) {
        $errDetail = ($conn && $conn->connect_error) ? $conn->connect_error : ($errMsg ?? 'Connection refused');

        $isJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
               || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

        if ($isJson) {
            header('Content-Type: application/json');
            http_response_code(503);
            die(json_encode(['is_valid' => false, 'err_msg' => 'DB_CONNECTION_ERROR: ' . $errDetail]));
        } else {
            http_response_code(503);
            die('
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Database Connection Error — CenLearn</title>
                <style>
                    body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;box-sizing:border-box;}
                    .card{background:#1e293b;border:1px solid #334155;border-radius:16px;padding:32px;max-width:500px;width:100%;text-align:center;box-shadow:0 20px 40px rgba(0,0,0,0.4);}
                    h2{color:#ef4444;margin-top:0;font-size:22px;}p{color:#94a3b8;font-size:14px;line-height:1.6;}
                    .code{background:#090d16;color:#38bdf8;padding:12px;border-radius:8px;font-family:monospace;font-size:12px;margin:16px 0;word-break:break-all;text-align:left;}
                    .btn{display:inline-block;padding:11px 22px;background:#10b981;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;margin-top:10px;cursor:pointer;transition:background .2s;}
                    .btn:hover{background:#059669;}
                </style>
            </head>
            <body>
            <div class="card">
                <h2>⚠️ MySQL Database Offline</h2>
                <p>Could not connect to MySQL database <strong>' . htmlspecialchars(DB_NAME) . '</strong> on host <strong>' . htmlspecialchars(DB_HOST) . '</strong>.</p>
                <p>If you are running locally or on WiFi LAN, please ensure <strong>MySQL</strong> is running in <strong>XAMPP Control Panel</strong>.</p>
                <div class="code">Error: ' . htmlspecialchars($errDetail) . '</div>
                <a href="javascript:location.reload()" class="btn">🔄 Retry Connection</a>
            </div>
            </body>
            </html>
            ');
        }
    }
    $conn->set_charset('utf8mb4');
}

// ── Global Helper for safe dynamic column addition (cached & protected) ──────
if (!function_exists('safeAddColumns')) {
    function safeAddColumns($conn, $table, $columns) {
        try {
            $tblCheck = $conn->query("SHOW TABLES LIKE '$table'");
            if (!$tblCheck || $tblCheck->num_rows === 0) {
                return;
            }
            foreach ($columns as $column => $definition) {
                $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
                if ($check && $check->num_rows === 0) {
                    $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                }
            }
        } catch (\Throwable $e) {
            error_log("safeAddColumns error on table $table: " . $e->getMessage());
        }
    }
}

// ── Lazy / Once-Only Schema Sync ─────────────────────────────────────────────
// Replaces 20+ per-request DDL checks with a smart version-cached manager.
// This makes page reloads and API responses execute with 0ms schema overhead.
if (isset($conn) && $conn) {
    include_once __DIR__ . '/schema_sync.php';
    $forceSync = isset($_GET['migrate']) && ($_GET['migrate'] === '1' || $_GET['migrate'] === 'force');
    cenlearn_sync_schema($conn, $forceSync);
}
