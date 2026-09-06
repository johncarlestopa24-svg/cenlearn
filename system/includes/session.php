<?php
if(session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

// Helper: redirect to login
function redirectToLogin(){
    $callerDir = str_replace('\\', '/', realpath(dirname($_SERVER['SCRIPT_FILENAME'])));
    $query     = (strpos($callerDir, '/admin') !== false) ? '?from=admin' : '';
    header('location: /cenlearn/login' . $query);
    exit;
}

// ── 1. Basic session check ────────────────────────────────────────────────
if(empty($_SESSION['user']) || !$_SESSION['user']['is_valid']){
    redirectToLogin();
}

// ── 2. Single-device enforcement — validate session token against DB ──────
include_once __DIR__ . '/conn.php';

$uc    = $conn->real_escape_string($_SESSION['user']['user_code'] ?? '');
$token = $_SESSION['user']['session_token'] ?? '';

if($uc && $token){
    $tq = $conn->query("SELECT session_token FROM users WHERE user_code='$uc' LIMIT 1");
    if($tq && $tq->num_rows > 0){
        $dbToken = $tq->fetch_assoc()['session_token'];
        if($dbToken !== $token){
            // Token mismatch — another device logged in, kill this session
            $_SESSION = [];
            if(ini_get('session.use_cookies')){
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            // Redirect with a message flag
            header('location: /cenlearn/login?kicked=1');
            exit;
        }
    }
}

$user = $_SESSION['user'];
?>
