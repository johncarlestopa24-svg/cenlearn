<?php
session_start();

// Clear session token from DB so the account can log in again cleanly
if(!empty($_SESSION['user']['user_code'])){
    include __DIR__ . '/includes/conn.php';
    $uc = $conn->real_escape_string($_SESSION['user']['user_code']);
    $conn->query("UPDATE users SET session_token=NULL WHERE user_code='$uc'");
}

$_SESSION = [];
if(ini_get('session.use_cookies')){
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('location: index.php');
exit;
?>
