<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Teacher Registration (Test)</title>
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Inter', sans-serif;
      min-height: 100vh;
      display: flex; align-items: center; justify-content: center;
      background: linear-gradient(135deg, #0f172a 0%, #1e293b 40%, #0f766e 100%);
      position: relative; overflow-x: hidden;
    }
    .bg-blob-1 {
      position: absolute; width: 480px; height: 480px; border-radius: 50%;
      background: radial-gradient(circle, rgba(45,212,191,.18) 0%, transparent 70%);
      top: -180px; left: -120px; filter: blur(60px); pointer-events: none;
    }
    .bg-blob-2 {
      position: absolute; width: 380px; height: 380px; border-radius: 50%;
      background: radial-gradient(circle, rgba(13,148,136,.22) 0%, transparent 70%);
      bottom: -120px; right: -80px; filter: blur(60px); pointer-events: none;
    }
    .card {
      background: #fff; border-radius: 20px;
      box-shadow: 0 28px 72px rgba(0,0,0,.45);
      width: 100%; max-width: 420px;
      padding: 44px 40px 36px;
      position: relative; z-index: 1;
      animation: fadeUp .55s cubic-bezier(.16,1,.3,1) both;
    }
    @keyframes fadeUp {
      from { opacity:0; transform:translateY(22px); }
      to   { opacity:1; transform:translateY(0); }
    }

    /* Brand */
    .brand { text-align:center; margin-bottom:28px; }
    .brand-icon {
      width:62px; height:62px; border-radius:16px;
      background: linear-gradient(135deg,#0d9488,#14b8a6);
      display:inline-flex; align-items:center; justify-content:center;
      margin-bottom:12px; box-shadow:0 10px 24px rgba(13,148,136,.38);
    }
    .brand-icon i { color:#fff; font-size:26px; }
    .brand h1 { font-size:22px; font-weight:800; color:#0f172a; letter-spacing:-.3px; }
    .brand h1 span { color:#0d9488; }
    .brand p { font-size:13px; color:#64748b; margin-top:5px; }

    /* Test badge */
    .test-badge {
      display:inline-flex; align-items:center; gap:6px;
      background:#fef3c7; color:#92400e; border:1.5px solid #fcd34d;
      border-radius:99px; padding:5px 14px; font-size:11px; font-weight:700;
      letter-spacing:.3px; margin-bottom:26px;
    }

    /* Form */
    .form-group { margin-bottom:18px; }
    .form-group label { display:block; font-size:12px; font-weight:700; color:#374151; margin-bottom:7px; }
    .input-wrap { position:relative; }
    .input-wrap i.pfx { position:absolute; left:15px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:14px; }
    .field {
      width:100%; padding:13px 16px 13px 44px;
      border:1.5px solid #e2e8f0; border-radius:11px;
      font-size:14px; color:#1e293b; background:#f8fafc;
      font-family:'Inter',sans-serif; transition:all .2s;
    }
    .field:focus { outline:none; border-color:#0d9488; box-shadow:0 0 0 4px rgba(13,148,136,.12); background:#fff; }
    .field::placeholder { color:#94a3b8; }
    .eye-btn {
      position:absolute; right:12px; top:50%; transform:translateY(-50%);
      background:none; border:none; cursor:pointer; color:#94a3b8;
      padding:4px 7px; border-radius:8px; transition:all .15s;
    }
    .eye-btn:hover { color:#0d9488; background:#ccfbf1; }
    .eye-btn:focus { outline:none; }

    /* Alert */
    .alert-box {
      padding:11px 14px; border-radius:11px;
      font-size:13px; display:flex; align-items:flex-start; gap:9px;
      margin-bottom:16px; font-weight:500;
    }
    .alert-success { background:#f0fdf4; color:#166534; border:1.5px solid #bbf7d0; }
    .alert-danger  { background:#fef2f2; color:#991b1b; border:1.5px solid #fecaca; }

    /* Submit */
    .btn-submit {
      width:100%; padding:13px;
      background:linear-gradient(135deg,#0d9488,#14b8a6);
      color:#fff; border:none; border-radius:11px;
      font-size:15px; font-weight:700; cursor:pointer;
      display:flex; align-items:center; justify-content:center; gap:9px;
      font-family:'Inter',sans-serif; margin-top:6px;
      box-shadow:0 4px 14px rgba(13,148,136,.35);
      transition:all .2s;
    }
    .btn-submit:hover { transform:translateY(-2px); box-shadow:0 8px 22px rgba(13,148,136,.45); }
    .btn-submit:active { transform:translateY(0); }
    .btn-submit:disabled { opacity:.6; cursor:not-allowed; transform:none; box-shadow:none; }

    .footer-note { text-align:center; margin-top:22px; font-size:13px; color:#64748b; }
    .footer-note a { color:#0d9488; font-weight:700; text-decoration:none; }
    .footer-note a:hover { text-decoration:underline; }
  </style>
</head>
<body>

<div class="bg-blob-1"></div>
<div class="bg-blob-2"></div>

<div class="card">
  <div class="brand">
    <div class="brand-icon"><i class="fa fa-graduation-cap"></i></div>
    <h1>Cen<span>Learn</span></h1>
    <p>Bago City College — LMS</p>
  </div>

  <div style="text-align:center;">
    <span class="test-badge"><i class="fa fa-flask"></i> Teacher Account — For Testing Only</span>
  </div>

  <form id="regForm">
    <div class="form-group">
      <label>Username <span style="color:#ef4444;">*</span></label>
      <div class="input-wrap">
        <i class="fa fa-id-badge pfx"></i>
        <input type="text" id="username" class="field" placeholder="Choose a username" required autofocus>
      </div>
    </div>

    <div class="form-group">
      <label>Password <span style="color:#ef4444;">*</span></label>
      <div class="input-wrap">
        <i class="fa fa-lock pfx"></i>
        <input type="password" id="password" class="field" placeholder="Minimum 6 characters" required style="padding-right:44px;">
        <button type="button" class="eye-btn" id="eyeBtn1">
          <i class="fa fa-eye" id="eyeIcon1"></i>
        </button>
      </div>
    </div>

    <div class="form-group">
      <label>Confirm Password <span style="color:#ef4444;">*</span></label>
      <div class="input-wrap">
        <i class="fa fa-lock pfx"></i>
        <input type="password" id="confirm_password" class="field" placeholder="Re-enter your password" required style="padding-right:44px;">
        <button type="button" class="eye-btn" id="eyeBtn2">
          <i class="fa fa-eye" id="eyeIcon2"></i>
        </button>
      </div>
    </div>

    <div id="alertBox" style="display:none;"></div>

    <button type="submit" class="btn-submit" id="btnRegister">
      <i class="fa fa-user-plus"></i> Create Teacher Account
    </button>
  </form>

  <div class="footer-note">
    Already registered? <a href="../index.php">Sign in here</a>
  </div>
</div>

<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script>
// Toggle password visibility
$('#eyeBtn1').on('click', function(){
  var pw = $('#password'), ic = $('#eyeIcon1');
  var show = pw.attr('type') === 'password';
  pw.attr('type', show ? 'text' : 'password');
  ic.toggleClass('fa-eye', !show).toggleClass('fa-eye-slash', show);
});
$('#eyeBtn2').on('click', function(){
  var pw = $('#confirm_password'), ic = $('#eyeIcon2');
  var show = pw.attr('type') === 'password';
  pw.attr('type', show ? 'text' : 'password');
  ic.toggleClass('fa-eye', !show).toggleClass('fa-eye-slash', show);
});

$('#regForm').on('submit', function(e){
  e.preventDefault();
  var username = $('#username').val().trim();
  var password = $('#password').val();
  var confirm  = $('#confirm_password').val();

  if(!username || !password || !confirm){
    showAlert('danger', 'Please fill in all fields.');
    return;
  }
  if(password.length < 6){
    showAlert('danger', 'Password must be at least 6 characters.');
    return;
  }
  if(password !== confirm){
    showAlert('danger', 'Passwords do not match.');
    return;
  }

  $('#btnRegister').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Creating account...');

  $.ajax({
    url: 'register_process.php',
    method: 'POST',
    data: { username: username, password: password },
    dataType: 'json',
    success: function(res){
      $('#btnRegister').prop('disabled', false).html('<i class="fa fa-user-plus"></i> Create Teacher Account');
      if(res.success){
        showAlert('success', res.msg);
        setTimeout(function(){ window.location.href = '../index.php'; }, 2000);
      } else {
        showAlert('danger', res.msg);
      }
    },
    error: function(){
      $('#btnRegister').prop('disabled', false).html('<i class="fa fa-user-plus"></i> Create Teacher Account');
      showAlert('danger', 'Server error. Please try again.');
    }
  });
});

function showAlert(type, msg){
  var cls  = type === 'success' ? 'alert-success' : 'alert-danger';
  var icon = type === 'success' ? 'check-circle' : 'times-circle';
  $('#alertBox').attr('class', 'alert-box ' + cls).html('<i class="fa fa-'+icon+'"></i> ' + msg).show();
}
</script>
</body>
</html>
