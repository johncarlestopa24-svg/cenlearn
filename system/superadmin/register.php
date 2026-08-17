<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Super Admin Registration</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Inter', sans-serif;
      min-height: 100vh;
      display: flex; align-items: center; justify-content: center;
      background: linear-gradient(135deg, #020617 0%, #0f172a 40%, #312e81 100%);
      position: relative; overflow-x: hidden; padding: 30px 15px;
    }
    .bg-blur {
      position: absolute; width: 500px; height: 500px; border-radius: 50%;
      background: radial-gradient(circle, rgba(139,92,246,.18) 0%, transparent 70%);
      top: -200px; left: -150px; filter: blur(60px); pointer-events: none;
    }
    .bg-blur-2 {
      position: absolute; width: 400px; height: 400px; border-radius: 50%;
      background: radial-gradient(circle, rgba(239,68,68,.12) 0%, transparent 70%);
      bottom: -150px; right: -100px; filter: blur(60px); pointer-events: none;
    }
    .reg-card {
      background: #fff; border-radius: 24px;
      box-shadow: 0 30px 80px rgba(0,0,0,.45);
      width: 100%; max-width: 520px;
      padding: 44px 40px 36px;
      position: relative; z-index: 1;
      animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
    }
    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(24px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .brand { text-align: center; margin-bottom: 24px; }
    .brand .logo-icon {
      width: 64px; height: 64px; border-radius: 18px;
      background: linear-gradient(135deg,#8b5cf6,#7c3aed,#6d28d9);
      display: inline-flex; align-items: center; justify-content: center;
      margin-bottom: 12px; box-shadow: 0 10px 30px rgba(139,92,246,.45);
    }
    .brand .logo-icon i { color: #fff; font-size: 26px; }
    .brand h1 { font-size: 24px; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -.3px; }
    .brand h1 span { color: #8b5cf6; }
    .brand p { font-size: 13px; color: #64748b; margin: 6px 0 0; }
    .role-badge {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 7px 18px; border-radius: 99px;
      background: #f3e8ff; color: #6b21a8;
      font-size: 12px; font-weight: 700; margin-bottom: 24px;
      letter-spacing: .3px;
    }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media(max-width:480px){ .form-row { grid-template-columns: 1fr; } .reg-card { padding: 32px 20px; } }

    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 12px; font-weight: 700; color: #374151; margin-bottom: 6px; letter-spacing: .2px; }
    .input-wrap { position: relative; }
    .input-wrap i.icon-prefix { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; }
    .form-control-cl {
      width: 100%; padding: 12px 16px 12px 44px;
      border: 1.5px solid #e2e8f0; border-radius: 12px;
      font-size: 14px; color: #1e293b; background: #f8fafc;
      transition: all .2s; font-family: 'Inter', sans-serif;
    }
    .form-control-cl:focus { outline: none; border-color: #8b5cf6; box-shadow: 0 0 0 4px rgba(139,92,246,.12); background: #fff; }
    .form-control-cl::placeholder { color: #94a3b8; }

    .toggle-pw {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; color: #94a3b8;
      padding: 4px 8px; border-radius: 8px; transition: all .15s;
    }
    .toggle-pw:hover { color: #8b5cf6; background: #f3e8ff; }
    .toggle-pw:focus { outline: none; }

    .btn-submit {
      width: 100%; padding: 14px;
      background: linear-gradient(135deg,#8b5cf6,#7c3aed);
      color: #fff; border: none; border-radius: 12px;
      font-size: 15px; font-weight: 700; cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 10px;
      transition: all .2s; font-family: 'Inter', sans-serif; margin-top: 10px;
      box-shadow: 0 4px 14px rgba(139,92,246,.35);
    }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(139,92,246,.45); }
    .btn-submit:active { transform: translateY(0); }
    .btn-submit:disabled { opacity: .6; cursor: not-allowed; transform: none; box-shadow: none; }

    .cl-alert {
      padding: 12px 16px; border-radius: 12px;
      font-size: 13px; display: flex; align-items: flex-start; gap: 10px;
      margin-bottom: 18px; font-weight: 500;
    }
    .alert-success { background: #f0fdf4; color: #166534; border: 1.5px solid #bbf7d0; }
    .alert-danger  { background: #fef2f2; color: #991b1b; border: 1.5px solid #fecaca; }

    .footer-links { text-align: center; margin-top: 24px; font-size: 13px; color: #64748b; line-height: 1.6; }
    .footer-links a { color: #8b5cf6; font-weight: 700; text-decoration: none; transition: color .15s; }
    .footer-links a:hover { text-decoration: underline; color: #6d28d9; }
  </style>
</head>
<body>
<div class="bg-blur"></div>
<div class="bg-blur-2"></div>

<div class="reg-card">
  <div class="brand">
    <div class="logo-icon"><i class="fa fa-shield"></i></div>
    <h1>Cen<span>Learn</span></h1>
    <p>Bago City College — Learning Management System</p>
  </div>
  <div style="text-align:center;">
    <span class="role-badge"><i class="fa fa-crown"></i> Super Admin Registration</span>
  </div>

  <form id="superadminRegisterForm">
    <div class="form-group">
      <label>Username / Super Admin ID <span style="color:#ef4444;">*</span></label>
      <div class="input-wrap">
        <i class="fa fa-id-badge icon-prefix"></i>
        <input type="text" id="username" class="form-control-cl" placeholder="Choose a superadmin username" required autofocus>
      </div>
      <small style="color:#94a3b8;font-size:11px;margin-top:4px;display:block;">This will be used to sign in with full system access</small>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>First Name <span style="color:#ef4444;">*</span></label>
        <div class="input-wrap">
          <i class="fa fa-user icon-prefix"></i>
          <input type="text" id="first_name" class="form-control-cl" placeholder="First name" required>
        </div>
      </div>
      <div class="form-group">
        <label>Middle Name</label>
        <div class="input-wrap">
          <i class="fa fa-user icon-prefix"></i>
          <input type="text" id="middle_name" class="form-control-cl" placeholder="Middle name (optional)">
        </div>
      </div>
    </div>

    <div class="form-group">
      <label>Last Name <span style="color:#ef4444;">*</span></label>
      <div class="input-wrap">
        <i class="fa fa-user icon-prefix"></i>
        <input type="text" id="last_name" class="form-control-cl" placeholder="Last name" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Email Address <span style="color:#ef4444;">*</span></label>
        <div class="input-wrap">
          <i class="fa fa-envelope icon-prefix"></i>
          <input type="email" id="email" class="form-control-cl" placeholder="admin@email.com" required>
        </div>
      </div>
      <div class="form-group">
        <label>Contact Number</label>
        <div class="input-wrap">
          <i class="fa fa-phone icon-prefix"></i>
          <input type="text" id="cp_number" class="form-control-cl" placeholder="09123456789">
        </div>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Password <span style="color:#ef4444;">*</span></label>
        <div class="input-wrap">
          <i class="fa fa-lock icon-prefix"></i>
          <input type="password" id="password" class="form-control-cl" placeholder="Min. 6 characters" required style="padding-right: 44px;">
          <button type="button" class="toggle-pw" id="togglePw1" title="Show password">
            <i class="fa fa-eye" id="eyeIcon1"></i>
          </button>
        </div>
      </div>
      <div class="form-group">
        <label>Confirm Password <span style="color:#ef4444;">*</span></label>
        <div class="input-wrap">
          <i class="fa fa-lock icon-prefix"></i>
          <input type="password" id="confirm_password" class="form-control-cl" placeholder="Re-enter password" required style="padding-right: 44px;">
          <button type="button" class="toggle-pw" id="togglePw2" title="Show password">
            <i class="fa fa-eye" id="eyeIcon2"></i>
          </button>
        </div>
      </div>
    </div>

    <div id="alertBox" style="display:none;"></div>

    <button type="submit" class="btn-submit" id="btnRegister">
      <i class="fa fa-user-plus"></i> Create Super Admin Account
    </button>
  </form>

  <div class="footer-links">
    <div>Already have an account? <a href="../index.php">Sign in here</a></div>
    <div style="margin-top:6px;">Registering as Teacher? <a href="../teacher/register.php">Click here</a></div>
  </div>
</div>

<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script>
$('#togglePw1').on('click', function(){
  var pw = $('#password');
  var icon = $('#eyeIcon1');
  if(pw.attr('type') === 'password'){
    pw.attr('type', 'text');
    icon.removeClass('fa-eye').addClass('fa-eye-slash');
  } else {
    pw.attr('type', 'password');
    icon.removeClass('fa-eye-slash').addClass('fa-eye');
  }
});

$('#togglePw2').on('click', function(){
  var pw = $('#confirm_password');
  var icon = $('#eyeIcon2');
  if(pw.attr('type') === 'password'){
    pw.attr('type', 'text');
    icon.removeClass('fa-eye').addClass('fa-eye-slash');
  } else {
    pw.attr('type', 'password');
    icon.removeClass('fa-eye-slash').addClass('fa-eye');
  }
});

$('#superadminRegisterForm').on('submit', function(e){
  e.preventDefault();
  var username = $('#username').val().trim();
  var first_name = $('#first_name').val().trim();
  var middle_name = $('#middle_name').val().trim();
  var last_name = $('#last_name').val().trim();
  var email = $('#email').val().trim();
  var cp_number = $('#cp_number').val().trim();
  var password = $('#password').val();
  var confirm  = $('#confirm_password').val();
  
  if(!username || !first_name || !last_name || !email || !password){ 
    showAlert('danger','Please fill in all required fields.'); 
    return; 
  }
  if(password.length < 6){ 
    showAlert('danger','Password must be at least 6 characters long.'); 
    return; 
  }
  if(password !== confirm){ 
    showAlert('danger','Passwords do not match.'); 
    return; 
  }
  
  $('#btnRegister').prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Creating Super Admin...');
  
  $.ajax({
    url:'register_process.php', method:'POST',
    data:{ 
      username: username, 
      password: password, 
      first_name: first_name, 
      middle_name: middle_name,
      last_name: last_name, 
      email: email,
      cp_number: cp_number
    },
    dataType:'json',
    success:function(res){
      $('#btnRegister').prop('disabled',false).html('<i class="fa fa-user-plus"></i> Create Super Admin Account');
      if(res.success){
        showAlert('success', res.msg);
        setTimeout(function(){ window.location.href='../index.php'; }, 2000);
      } else {
        showAlert('danger', res.msg);
      }
    },
    error:function(){
      $('#btnRegister').prop('disabled',false).html('<i class="fa fa-user-plus"></i> Create Super Admin Account');
      showAlert('danger','Registration failed due to a server error. Please try again.');
    }
  });
});

function showAlert(type, msg){
  var cls = type==='success'?'alert-success':'alert-danger';
  var icon = type==='success'?'check-circle':'times-circle';
  $('#alertBox').attr('class','cl-alert '+cls).html('<i class="fa fa-'+icon+'"></i> '+msg).show();
}
</script>
</body>
</html>