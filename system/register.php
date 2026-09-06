<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Registration Portal</title>
  <link rel="stylesheet" href="bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Inter', sans-serif;
      min-height: 100vh;
      display: flex; align-items: center; justify-content: center;
      background: linear-gradient(135deg, #020617 0%, #0f172a 50%, #0f766e 100%);
      position: relative; overflow-x: hidden; padding: 30px 15px;
    }
    .bg-blur-1 {
      position: absolute; width: 600px; height: 600px; border-radius: 50%;
      background: radial-gradient(circle, rgba(13,148,136,.18) 0%, transparent 70%);
      top: -200px; left: -150px; filter: blur(70px); pointer-events: none;
    }
    .bg-blur-2 {
      position: absolute; width: 500px; height: 500px; border-radius: 50%;
      background: radial-gradient(circle, rgba(139,92,246,.15) 0%, transparent 70%);
      bottom: -150px; right: -100px; filter: blur(70px); pointer-events: none;
    }
    .reg-card {
      background: #ffffff; border-radius: 24px;
      box-shadow: 0 30px 80px rgba(0,0,0,.45);
      width: 100%; max-width: 540px;
      padding: 44px 40px 36px;
      position: relative; z-index: 1;
      animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
    }
    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(24px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .brand { text-align: center; margin-bottom: 20px; }
    .brand .logo-img {
      width: 64px; height: 64px; border-radius: 50%;
      object-fit: cover; border: 3px solid rgba(13,148,136,.3);
      margin-bottom: 12px; box-shadow: 0 10px 25px rgba(0,0,0,.2);
    }
    .brand h1 { font-size: 24px; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -.3px; }
    .brand h1 span { color: #0d9488; }
    .brand p { font-size: 13px; color: #64748b; margin: 6px 0 0; }

    /* Role Tab Switcher */
    .role-tabs {
      display: flex; background: #f1f5f9; border-radius: 14px;
      padding: 5px; margin-bottom: 24px; gap: 5px;
    }
    .role-tab {
      flex: 1; padding: 11px 14px; border-radius: 10px; border: none;
      background: transparent; color: #64748b; font-size: 13px; font-weight: 700;
      cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
      transition: all .25s ease; font-family: 'Inter', sans-serif;
    }
    .role-tab i { font-size: 15px; }
    .role-tab.active-teacher {
      background: #0d9488; color: #fff; box-shadow: 0 4px 14px rgba(13,148,136,.35);
    }
    .role-tab.active-superadmin {
      background: #8b5cf6; color: #fff; box-shadow: 0 4px 14px rgba(139,92,246,.35);
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
    .form-control-cl:focus { outline: none; border-color: #0d9488; box-shadow: 0 0 0 4px rgba(13,148,136,.12); background: #fff; }
    .form-control-cl::placeholder { color: #94a3b8; }
    
    select.form-control-cl {
      appearance: none; -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2094a3b8' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 16px center;
      padding-right: 40px; cursor: pointer;
    }

    .toggle-pw {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; color: #94a3b8;
      padding: 4px 8px; border-radius: 8px; transition: all .15s;
    }
    .toggle-pw:hover { color: #0d9488; background: #ccfbf1; }
    .toggle-pw:focus { outline: none; }

    .btn-submit-teacher {
      width: 100%; padding: 14px;
      background: linear-gradient(135deg, #0d9488, #14b8a6);
      color: #fff; border: none; border-radius: 12px;
      font-size: 15px; font-weight: 700; cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 10px;
      transition: all .2s; font-family: 'Inter', sans-serif; margin-top: 10px;
      box-shadow: 0 4px 16px rgba(13,148,136,.35);
    }
    .btn-submit-teacher:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(13,148,136,.45); }
    
    .btn-submit-superadmin {
      width: 100%; padding: 14px;
      background: linear-gradient(135deg, #8b5cf6, #7c3aed);
      color: #fff; border: none; border-radius: 12px;
      font-size: 15px; font-weight: 700; cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 10px;
      transition: all .2s; font-family: 'Inter', sans-serif; margin-top: 10px;
      box-shadow: 0 4px 16px rgba(139,92,246,.35);
    }
    .btn-submit-superadmin:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(139,92,246,.45); }
    
    .btn-submit-teacher:disabled, .btn-submit-superadmin:disabled { opacity: .6; cursor: not-allowed; transform: none; box-shadow: none; }

    .cl-alert {
      padding: 12px 16px; border-radius: 12px;
      font-size: 13px; display: flex; align-items: flex-start; gap: 10px;
      margin-bottom: 18px; font-weight: 500;
    }
    .alert-success { background: #f0fdf4; color: #166534; border: 1.5px solid #bbf7d0; }
    .alert-danger  { background: #fef2f2; color: #991b1b; border: 1.5px solid #fecaca; }

    .footer-links { text-align: center; margin-top: 24px; font-size: 13px; color: #64748b; }
    .footer-links a { color: #0d9488; font-weight: 700; text-decoration: none; transition: color .15s; }
    .footer-links a:hover { text-decoration: underline; color: #0f766e; }
  </style>
</head>
<body>

<div class="bg-blur-1"></div>
<div class="bg-blur-2"></div>

<div class="reg-card">
  <div class="brand">
    <img src="dist/img/bcc_logo.jpg" alt="BCC" class="brand-logo logo-img">
    <h1>Cen<span>Learn</span> Portal</h1>
    <p>Bago City College — Registration Portal</p>
  </div>

  <div class="role-tabs">
    <button class="role-tab active-teacher" id="tabTeacher" onclick="switchRole('teacher')">
      <i class="fa fa-graduation-cap"></i> Teacher Register
    </button>
    <button class="role-tab" id="tabSuperadmin" onclick="switchRole('superadmin')">
      <i class="fa fa-shield"></i> Super Admin Register
    </button>
  </div>

  <!-- Form Container -->
  <form id="portalRegisterForm">
    <input type="hidden" id="selectedRole" value="teacher">

    <!-- Username / Code field -->
    <div class="form-group">
      <label id="lblCode">Teacher Code / Employee ID / Username <span style="color:#ef4444;">*</span></label>
      <div class="input-wrap">
        <i class="fa fa-id-card icon-prefix" id="iconCode"></i>
        <input type="text" id="user_code" class="form-control-cl" placeholder="e.g. T-2026-001 or username" required autofocus>
      </div>
      <small id="subCode" style="color:#94a3b8;font-size:11px;margin-top:4px;display:block;">This will be used as your official login username</small>
    </div>

    <!-- Name Fields -->
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

    <!-- Contact & Email -->
    <div class="form-row">
      <div class="form-group">
        <label>Email Address <span style="color:#ef4444;">*</span></label>
        <div class="input-wrap">
          <i class="fa fa-envelope icon-prefix"></i>
          <input type="email" id="email" class="form-control-cl" placeholder="your@email.com" required>
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

    <!-- Department (Teacher Only) -->
    <div class="form-group" id="deptGroup">
      <label>Department / Academic Specialization <span style="color:#ef4444;">*</span></label>
      <div class="input-wrap">
        <i class="fa fa-building icon-prefix"></i>
        <select id="department" class="form-control-cl">
          <option value="" disabled selected>Select Department</option>
          <option value="IS">Information Systems (IS)</option>
          <option value="EDUC">Teacher Education (EDUC)</option>
          <option value="CRIM">Criminology (CRIM)</option>
          <option value="ART">Arts & General Education (ART)</option>
          <option value="BSOA">Office Administration (BSOA)</option>
        </select>
      </div>
    </div>

    <!-- Password Fields -->
    <div class="form-row">
      <div class="form-group">
        <label>Password <span style="color:#ef4444;">*</span></label>
        <div class="input-wrap">
          <i class="fa fa-lock icon-prefix"></i>
          <input type="password" id="password" class="form-control-cl" placeholder="Min. 6 characters" required style="padding-right:44px;">
          <button type="button" class="toggle-pw" id="togglePw1" title="Show password">
            <i class="fa fa-eye" id="eyeIcon1"></i>
          </button>
        </div>
      </div>
      <div class="form-group">
        <label>Confirm Password <span style="color:#ef4444;">*</span></label>
        <div class="input-wrap">
          <i class="fa fa-lock icon-prefix"></i>
          <input type="password" id="confirm_password" class="form-control-cl" placeholder="Re-enter password" required style="padding-right:44px;">
          <button type="button" class="toggle-pw" id="togglePw2" title="Show password">
            <i class="fa fa-eye" id="eyeIcon2"></i>
          </button>
        </div>
      </div>
    </div>

    <div id="alertBox" style="display:none;"></div>

    <button type="submit" class="btn-submit-teacher" id="btnRegister">
      <i class="fa fa-user-plus"></i> Register as Teacher
    </button>
  </form>

  <div class="footer-links">
    Already registered? <a href="./">Sign in to your account</a>
  </div>
</div>

<script src="bower_components/jquery/dist/jquery.min.js"></script>
<script>
function switchRole(role){
  $('#selectedRole').val(role);
  $('#alertBox').hide();
  
  if(role === 'teacher'){
    $('#tabTeacher').addClass('active-teacher').removeClass('active-superadmin');
    $('#tabSuperadmin').removeClass('active-teacher active-superadmin');
    
    $('#lblCode').html('Teacher Code / Employee ID / Username <span style="color:#ef4444;">*</span>');
    $('#user_code').attr('placeholder', 'e.g. T-2026-001 or username');
    $('#subCode').text('This will be used as your official login username');
    $('#deptGroup').slideDown(200);
    $('#department').prop('required', true);
    
    $('#btnRegister').attr('class', 'btn-submit-teacher').html('<i class="fa fa-user-plus"></i> Register as Teacher');
  } else {
    $('#tabSuperadmin').addClass('active-superadmin').removeClass('active-teacher');
    $('#tabTeacher').removeClass('active-teacher active-superadmin');
    
    $('#lblCode').html('Super Admin Username <span style="color:#ef4444;">*</span>');
    $('#user_code').attr('placeholder', 'Choose a superadmin username');
    $('#subCode').text('This username will have full system access');
    $('#deptGroup').slideUp(200);
    $('#department').prop('required', false);
    
    $('#btnRegister').attr('class', 'btn-submit-superadmin').html('<i class="fa fa-shield"></i> Create Super Admin Account');
  }
}

// Auto select role from URL parameter if present (?role=superadmin)
$(document).ready(function(){
  const urlParams = new URLSearchParams(window.location.search);
  const roleParam = urlParams.get('role');
  if(roleParam === 'superadmin'){
    switchRole('superadmin');
  }
});

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

$('#portalRegisterForm').on('submit', function(e){
  e.preventDefault();
  var role = $('#selectedRole').val();
  var user_code = $('#user_code').val().trim();
  var first_name = $('#first_name').val().trim();
  var middle_name = $('#middle_name').val().trim();
  var last_name = $('#last_name').val().trim();
  var email = $('#email').val().trim();
  var cp_number = $('#cp_number').val().trim();
  var department = $('#department').val();
  var password = $('#password').val();
  var confirm  = $('#confirm_password').val();

  if(!user_code || !first_name || !last_name || !email || !password || (role === 'teacher' && !department)){
    showAlert('danger', 'Please fill in all required fields.');
    return;
  }
  if(password.length < 6){
    showAlert('danger', 'Password must be at least 6 characters long.');
    return;
  }
  if(password !== confirm){
    showAlert('danger', 'Passwords do not match.');
    return;
  }

  var targetUrl = (role === 'teacher') ? 'teacher/register_process.php' : 'superadmin/register_process.php';
  var postData  = (role === 'teacher') ? {
    user_code: user_code,
    first_name: first_name,
    middle_name: middle_name,
    last_name: last_name,
    email: email,
    cp_number: cp_number,
    department: department,
    password: password
  } : {
    username: user_code,
    first_name: first_name,
    middle_name: middle_name,
    last_name: last_name,
    email: email,
    cp_number: cp_number,
    password: password
  };

  $('#btnRegister').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Processing registration...');

  $.ajax({
    url: targetUrl,
    method: 'POST',
    data: postData,
    dataType: 'json',
    success: function(res){
      var btnClass = (role === 'teacher') ? 'btn-submit-teacher' : 'btn-submit-superadmin';
      var btnText  = (role === 'teacher') ? '<i class="fa fa-user-plus"></i> Register as Teacher' : '<i class="fa fa-shield"></i> Create Super Admin Account';
      $('#btnRegister').prop('disabled', false).html(btnText);
      
      if(res.success){
        showAlert('success', res.msg);
        setTimeout(function(){ window.location.href = './'; }, 2000);
      } else {
        showAlert('danger', res.msg);
      }
    },
    error: function(){
      var btnText = (role === 'teacher') ? '<i class="fa fa-user-plus"></i> Register as Teacher' : '<i class="fa fa-shield"></i> Create Super Admin Account';
      $('#btnRegister').prop('disabled', false).html(btnText);
      showAlert('danger', 'Registration failed due to a server error. Please try again.');
    }
  });
});

function showAlert(type, msg){
  var cls = type === 'success' ? 'alert-success' : 'alert-danger';
  var icon = type === 'success' ? 'check-circle' : 'times-circle';
  $('#alertBox').attr('class', 'cl-alert ' + cls).html('<i class="fa fa-' + icon + '"></i> ' + msg).show();
}
</script>
</body>
</html>
