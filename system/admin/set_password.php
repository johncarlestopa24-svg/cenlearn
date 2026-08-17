<?php
session_start();
include '../includes/conn.php';

// Only ADMIN and SUPERADMIN can set local passwords
if(empty($_SESSION['user']) || !in_array($_SESSION['user']['user_group'], ['ADMIN','SUPERADMIN'])){
    echo json_encode(['success'=>false,'msg'=>'Unauthorized']);
    exit;
}

$user_code = trim($_POST['user_code'] ?? '');
$new_pw    = trim($_POST['new_password'] ?? '');

if(empty($user_code) || empty($new_pw)){
    echo json_encode(['success'=>false,'msg'=>'User code and new password are required.']);
    exit;
}
if(strlen($new_pw) < 4){
    echo json_encode(['success'=>false,'msg'=>'Password must be at least 4 characters.']);
    exit;
}

$uc   = $conn->real_escape_string($user_code);
$hash = $conn->real_escape_string(password_hash($new_pw, PASSWORD_DEFAULT));

// Check user exists
$chk = $conn->query("SELECT id, first_name, last_name FROM users WHERE user_code='$uc'");
if(!$chk || $chk->num_rows === 0){
    echo json_encode(['success'=>false,'msg'=>'Account not found: '.$user_code]);
    exit;
}
$u = $chk->fetch_assoc();

$conn->query("UPDATE users SET password_hash='$hash' WHERE user_code='$uc'");

echo json_encode([
    'success' => true,
    'msg'     => 'Local password set for '.htmlspecialchars($u['first_name'].' '.$u['last_name']).' ('.$user_code.').'
]);
