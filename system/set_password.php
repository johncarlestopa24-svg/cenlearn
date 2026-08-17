<?php
// Self-service first-login password change
// Shown when API is down and student is using the default placeholder password
session_start();
include 'includes/conn.php';

$error   = '';
$success = false;

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $user_code   = trim($_POST['user_code']   ?? '');
    $current_pw  = trim($_POST['current_pw']  ?? '');
    $new_pw      = trim($_POST['new_pw']      ?? '');
    $confirm_pw  = trim($_POST['confirm_pw']  ?? '');

    if(!$user_code || !$current_pw || !$new_pw || !$confirm_pw){
        $error = 'All fields are required.';
    } elseif($new_pw !== $confirm_pw){
        $error = 'New passwords do not match.';
    } elseif(strlen($new_pw) < 6){
        $error = 'New password must be at least 6 characters.';
    } else {
        $uc = $conn->real_escape_string($user_code);
        $q  = $conn->query("SELECT * FROM users WHERE user_code='$uc' AND is_active=1 LIMIT 1");
        if(!$q || $q->num_rows === 0){
            $error = 'Account not found.';
        } else {
            $r = $q->fetch_assoc();
            if(empty($r['password_hash']) || !password_verify($current_pw, $r['password_hash'])){
                $error = 'Current password is incorrect.';
            } else {
                $newHash = $conn->real_escape_string(password_hash($new_pw, PASSWORD_DEFAULT));
                $now     = date('Y-m-d H:i:s');
                $conn->query("UPDATE users SET password_hash='$newHash', api_cached_at='$now' WHERE user_code='$uc'");
                $success = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Set Your Password</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#0c1a2e,#1792bb);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
    .card{background:#fff;border-radius:16px;padding:40px 36px;width:100%;max-width:420px;box-shadow:0 24px 64px rgba(0,0,0,.3);}
    .logo{display:flex;align-items:center;gap:10px;margin-bottom:28px;}
    .logo-dot{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#1792bb,#0f5f80);display:flex;align-items:center;justify-content:center;}
    .logo-dot svg{width:20px;height:20px;fill:#fff;}
    .logo-text{font-size:20px;font-weight:800;color:#0f172a;}
    .logo-text span{color:#1792bb;}
    h2{font-size:22px;font-weight:800;color:#0f172a;margin-bottom:6px;}
    p.sub{font-size:13px;color:#64748b;margin-bottom:24px;line-height:1.6;}
    label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;}
    input{width:100%;padding:11px 14px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:'Inter',sans-serif;color:#0f172a;outline:none;transition:border-color .2s;}
    input:focus{border-color:#1792bb;box-shadow:0 0 0 3px rgba(23,146,187,.12);}
    .field{margin-bottom:16px;}
    .btn{width:100%;padding:13px;background:linear-gradient(135deg,#1792bb,#0f5f80);color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;margin-top:8px;transition:opacity .2s;}
    .btn:hover{opacity:.88;}
    .err{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px;}
    .ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;border-radius:8px;padding:14px;font-size:13px;margin-bottom:16px;text-align:center;}
    .ok strong{display:block;font-size:15px;margin-bottom:4px;}
    .back{display:block;text-align:center;margin-top:18px;font-size:13px;color:#1792bb;text-decoration:none;font-weight:600;}
    .back:hover{text-decoration:underline;}
    .note{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 14px;font-size:12px;color:#1d4ed8;margin-bottom:20px;line-height:1.6;}
  </style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-dot">
      <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
    </div>
    <span class="logo-text">Cen<span>Learn</span></span>
  </div>

  <?php if($success): ?>
    <div class="ok">
      <strong>✓ Password Updated!</strong>
      You can now log in with your new password.
    </div>
    <a href="index.php" class="btn" style="display:block;text-align:center;text-decoration:none;">Go to Login</a>

  <?php else: ?>
    <h2>Set Your Password</h2>
    <p class="sub">The authentication server is temporarily offline. Set a local password to access your account.</p>

    <div class="note">
      ℹ Your current (default) password is your <strong>TechnoPal password</strong>. Enter it below along with a new password you want to use.
    </div>

    <?php if($error): ?>
      <div class="err"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="field">
        <label>User ID (Student/Employee Number)</label>
        <input type="text" name="user_code" placeholder="e.g. 2023119735" value="<?php echo htmlspecialchars($_POST['user_code'] ?? ''); ?>" required>
      </div>
      <div class="field">
        <label>Current Password (TechnoPal Password)</label>
        <input type="password" name="current_pw" placeholder="Your current password" required>
      </div>
      <div class="field">
        <label>New Password</label>
        <input type="password" name="new_pw" placeholder="Min. 6 characters" required>
      </div>
      <div class="field">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_pw" placeholder="Repeat new password" required>
      </div>
      <button type="submit" class="btn">Set Password</button>
    </form>
    <a href="index.php" class="back">← Back to Login</a>
  <?php endif; ?>
</div>
</body>
</html>
