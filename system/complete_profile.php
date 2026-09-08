<?php
// Session is managed by session.php — do not call session_start() here
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Must be logged in — use session.php for proper single-device enforcement
include 'includes/session.php';

$user = $_SESSION['user'];

// Only students need to complete profile
if($user['user_group'] !== 'STUDENT'){
    header('Location: student/dashboard.php');
    exit;
}

// If profile is already complete, skip this page
$needsProfile = empty($user['first_name']) || empty($user['program_code']) || empty($user['year_level']) || empty($user['section']);
if(!$needsProfile){
    header('Location: student/dashboard.php');
    exit;
}

include 'includes/programs.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Complete Your Profile</title>
  <link rel="stylesheet" href="bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="bower_components/jquery/dist/jquery.min.js"></script>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      height: 100%; font-family: 'Inter', sans-serif;
      background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #0f5f80 100%);
      min-height: 100vh; display: flex; align-items: center; justify-content: center;
      padding: 20px;
    }

    .card {
      background: #fff; border-radius: 20px;
      box-shadow: 0 32px 80px rgba(0,0,0,.35);
      width: 100%; max-width: 520px;
      overflow: hidden;
      animation: slideUp .4s cubic-bezier(.4,0,.2,1) both;
    }
    @keyframes slideUp {
      from { opacity:0; transform:translateY(28px) scale(.97); }
      to   { opacity:1; transform:translateY(0)   scale(1);    }
    }

    /* Header */
    .card-header {
      background: linear-gradient(135deg, #1792bb, #0f5f80);
      padding: 28px 32px 24px;
      text-align: center;
    }
    .card-header .logo {
      width: 56px; height: 56px; border-radius: 50%;
      border: 3px solid rgba(255,255,255,.4);
      object-fit: cover; margin-bottom: 12px;
    }
    .card-header h1 {
      font-size: 20px; font-weight: 800; color: #fff; margin-bottom: 4px;
    }
    .card-header p {
      font-size: 12px; color: rgba(255,255,255,.7); font-weight: 500;
    }
    .step-badge {
      display: inline-flex; align-items: center; gap: 6px;
      background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.25);
      border-radius: 99px; padding: 5px 14px;
      font-size: 11px; font-weight: 700; color: rgba(255,255,255,.9);
      margin-top: 12px; letter-spacing: .5px; text-transform: uppercase;
    }
    .step-badge i { font-size: 10px; }

    /* Body */
    .card-body { padding: 32px; }

    .info-notice {
      background: #f0f9ff; border: 1px solid #bae6fd;
      border-radius: 10px; padding: 12px 16px;
      display: flex; align-items: flex-start; gap: 10px;
      margin-bottom: 24px;
    }
    .info-notice i { color: #0284c7; margin-top: 2px; font-size: 14px; }
    .info-notice p { font-size: 12px; color: #0369a1; line-height: 1.5; }
    .info-notice strong { color: #075985; }

    .user-code-badge {
      display: inline-flex; align-items: center; gap: 6px;
      background: #f1f5f9; border: 1px solid #e2e8f0;
      border-radius: 8px; padding: 6px 12px;
      font-size: 12px; font-weight: 700; color: #475569;
      margin-bottom: 24px;
    }
    .user-code-badge i { color: #94a3b8; }

    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media(max-width: 480px){ .form-row { grid-template-columns: 1fr; } }

    .form-group { margin-bottom: 18px; }
    .form-group label {
      display: block; font-size: 12px; font-weight: 700;
      color: #374151; margin-bottom: 7px; letter-spacing: .3px;
    }
    .form-group label .req { color: #ef4444; margin-left: 2px; }
    .form-control {
      width: 100%; padding: 11px 14px;
      border: 1.5px solid #e2e8f0; border-radius: 10px;
      font-size: 14px; font-family: 'Inter', sans-serif;
      color: #1e293b; background: #fff;
      transition: border-color .2s, box-shadow .2s; outline: none;
      appearance: none;
    }
    .form-control:focus {
      border-color: #1792bb;
      box-shadow: 0 0 0 3px rgba(23,146,187,.12);
    }
    .form-control::placeholder { color: #94a3b8; }

    .err-box {
      background: #fef2f2; border: 1px solid #fecaca; color: #dc2626;
      border-radius: 8px; padding: 10px 14px; font-size: 13px;
      margin-bottom: 16px; display: none;
    }

    .btn-save {
      width: 100%; padding: 14px;
      background: linear-gradient(135deg, #1792bb, #0f5f80);
      color: #fff; border: none; border-radius: 10px;
      font-size: 15px; font-weight: 800; font-family: 'Inter', sans-serif;
      cursor: pointer; letter-spacing: .3px;
      transition: opacity .2s, transform .1s, box-shadow .2s;
      box-shadow: 0 4px 18px rgba(23,146,187,.4);
    }
    .btn-save:hover { opacity: .92; box-shadow: 0 6px 24px rgba(23,146,187,.5); }
    .btn-save:active { transform: scale(.98); }
    .btn-save:disabled { opacity: .6; cursor: not-allowed; }

    .skip-note {
      text-align: center; margin-top: 14px;
      font-size: 11px; color: #94a3b8; line-height: 1.5;
    }
  </style>
</head>
<body>

<div class="card">
  <!-- Header -->
  <div class="card-header">
    <img src="dist/img/bcc_logo.jpg" alt="BCC" class="logo"
         onerror="this.style.display='none'">
    <h1>Complete Your Profile</h1>
    <p>Bago City College &mdash; CenLearn</p>
    <div class="step-badge">
      <i class="fa fa-user-circle"></i>
      One-time setup required
    </div>
  </div>

  <!-- Body -->
  <div class="card-body">

    <div class="user-code-badge">
      <i class="fa fa-id-card"></i>
      Student ID: <?php echo htmlspecialchars($user['user_code']); ?>
    </div>

    <div class="info-notice">
      <i class="fa fa-info-circle"></i>
      <p>Your account needs a few more details before you can access CenLearn.
         Please fill in your <strong>full name</strong>, <strong>program</strong>,
         <strong>year level</strong>, and <strong>section</strong> to continue.</p>
    </div>

    <div id="errBox" class="err-box"></div>

    <form id="profileForm" autocomplete="off">

      <!-- Name row -->
      <div class="form-row">
        <div class="form-group">
          <label>First Name <span class="req">*</span></label>
          <input type="text" class="form-control" id="first_name"
                 placeholder="e.g., Juan"
                 value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>"
                 maxlength="80" required>
        </div>
        <div class="form-group">
          <label>Last Name <span class="req">*</span></label>
          <input type="text" class="form-control" id="last_name"
                 placeholder="e.g., Dela Cruz"
                 value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>"
                 maxlength="80" required>
        </div>
      </div>

      <!-- Middle name (optional) -->
      <div class="form-group">
        <label>Middle Name <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
        <input type="text" class="form-control" id="middle_name"
               placeholder="e.g., Santos"
               value="<?php echo htmlspecialchars($user['middle_name'] ?? ''); ?>"
               maxlength="80">
      </div>

      <!-- Program -->
      <div class="form-group">
        <label>Program <span class="req">*</span></label>
        <select class="form-control" id="program_code" required>
          <option value="">-- Select Program --</option>
          <?php foreach($BCC_PROGRAMS as $p): ?>
            <option value="<?php echo htmlspecialchars($p['code']); ?>"
              <?php if(($user['program_code'] ?? '') === $p['code']) echo 'selected'; ?>>
              <?php echo htmlspecialchars($p['code']); ?> &mdash; <?php echo htmlspecialchars($p['desc']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Year + Section row -->
      <div class="form-row">
        <div class="form-group">
          <label>Year Level <span class="req">*</span></label>
          <select class="form-control" id="year_level" required>
            <option value="">-- Year --</option>
            <option value="1" <?php if(($user['year_level'] ?? 0) == 1) echo 'selected'; ?>>1st Year</option>
            <option value="2" <?php if(($user['year_level'] ?? 0) == 2) echo 'selected'; ?>>2nd Year</option>
            <option value="3" <?php if(($user['year_level'] ?? 0) == 3) echo 'selected'; ?>>3rd Year</option>
            <option value="4" <?php if(($user['year_level'] ?? 0) == 4) echo 'selected'; ?>>4th Year</option>
            <option value="5" <?php if(($user['year_level'] ?? 0) == 5) echo 'selected'; ?>>5th Year</option>
          </select>
        </div>
        <div class="form-group">
          <label>Section <span class="req">*</span></label>
          <input type="text" class="form-control" id="section"
                 placeholder="e.g., A, B, 1"
                 value="<?php echo htmlspecialchars($user['section'] ?? ''); ?>"
                 maxlength="20" required>
        </div>
      </div>

      <div id="errBox2" class="err-box"></div>

      <button type="submit" class="btn-save" id="btnSave">
        <i class="fa fa-check-circle"></i>&nbsp; Save &amp; Continue to Dashboard
      </button>
    </form>

    <p class="skip-note">
      <i class="fa fa-lock" style="color:#cbd5e1;"></i>
      This information is required to access CenLearn. It will not be shown publicly.
    </p>

  </div>
</div>

<script>
$('#profileForm').on('submit', function(e){
  e.preventDefault();

  var fn  = $('#first_name').val().trim();
  var ln  = $('#last_name').val().trim();
  var mn  = $('#middle_name').val().trim();
  var pc  = $('#program_code').val();
  var yl  = $('#year_level').val();
  var sec = $('#section').val().trim();

  if(!fn || !ln){
    showErr('Please enter your first and last name.');
    return;
  }
  if(!pc){
    showErr('Please select your program.');
    return;
  }
  if(!yl){
    showErr('Please select your year level.');
    return;
  }
  if(!sec){
    showErr('Please enter your section.');
    return;
  }

  $('#errBox, #errBox2').hide();
  $('#btnSave').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>&nbsp; Saving...');

  $.ajax({
    url: 'save_profile.php',
    method: 'POST',
    dataType: 'json',
    data: {
      first_name:  fn,
      last_name:   ln,
      middle_name: mn,
      program_code: pc,
      year_level:  yl,
      section:     sec
    },
    success: function(res){
      if(res.success){
        window.location.href = 'student/dashboard.php';
      } else {
        showErr(res.msg || 'Failed to save profile. Please try again.');
        $('#btnSave').prop('disabled', false).html('<i class="fa fa-check-circle"></i>&nbsp; Save &amp; Continue to Dashboard');
      }
    },
    error: function(){
      showErr('Server error. Please try again.');
      $('#btnSave').prop('disabled', false).html('<i class="fa fa-check-circle"></i>&nbsp; Save &amp; Continue to Dashboard');
    }
  });
});

function showErr(msg){
  $('#errBox, #errBox2').html(msg).show();
}
</script>
</body>
</html>
