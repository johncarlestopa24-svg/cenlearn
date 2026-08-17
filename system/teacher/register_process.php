<?php
header('Content-Type: application/json');
include '../includes/conn.php';

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

if(!$username || !$password){
    echo json_encode(['success' => false, 'msg' => 'Username and password are required.']);
    exit;
}
if(strlen($password) < 6){
    echo json_encode(['success' => false, 'msg' => 'Password must be at least 6 characters.']);
    exit;
}

// Check for duplicate username
$uc = $conn->real_escape_string($username);
$chk = $conn->query("SELECT id FROM users WHERE user_code='$uc'");
if($chk && $chk->num_rows > 0){
    echo json_encode(['success' => false, 'msg' => 'Username already exists. Please choose another.']);
    exit;
}

$hash = $conn->real_escape_string(password_hash($password, PASSWORD_DEFAULT));
$now  = date('Y-m-d H:i:s');

$sql = "INSERT INTO users (user_code, password_hash, user_group, is_active, api_cached_at)
        VALUES ('$uc', '$hash', 'TEACHER', 1, '$now')";

if($conn->query($sql)){
    echo json_encode(['success' => true, 'msg' => 'Teacher account created! You can now log in.']);
} else {
    echo json_encode(['success' => false, 'msg' => 'Database error: ' . $conn->error]);
}

$conn->close();
?>
