<?php
include '../includes/session.php';
include '../includes/conn.php';

$role = strtoupper($user['user_group']);
if($role !== 'SUPERADMIN'){ header('location: /cenlearn/login'); exit; }

$users = $conn->query("SELECT * FROM users ORDER BY user_group, user_code");
$userRows = [];
$counts = ['SUPERADMIN'=>0,'ADMIN'=>0,'TEACHER'=>0,'STUDENT'=>0];
while($u = $users->fetch_assoc()){
  $userRows[] = $u;
  if(isset($counts[$u['user_group']])) $counts[$u['user_group']]++;
}

// Departments and their allowed courses
$DEPARTMENTS = [
  'IS'   => ['label' => 'Information Systems',  'courses' => ['IS']],
  'CRIM' => ['label' => 'Criminology',           'courses' => ['CRIM']],
  'EDUC' => ['label' => 'Education',             'courses' => ['BSED-FILIPINO','BSED-MATHEMATICS','BSED-SOCIAL STUDIES','BPED','BEED']],
  'ART'  => ['label' => 'Arts',                  'courses' => ['BSOA','AB HISTORY','AB ENGLISH']],
];

// Find which departments already have an admin
$takenDepts = [];
foreach($userRows as $u){
  if($u['user_group'] === 'ADMIN' && !empty($u['department'])){
    $takenDepts[] = strtoupper(trim($u['department']));
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Super Admin</title>
  <link rel="stylesheet" href="/cenlearn/system/bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/cenlearn/system/bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/cenlearn/system/dist/css/cenlearn.css">
  <link rel="stylesheet" href="/cenlearn/system/bower_components/datatables.net-bs/css/dataTables.bootstrap.min.css">
  <style>
    .dataTables_wrapper .dataTables_filter input { border:1.5px solid #e2e8f0;border-radius:8px;padding:6px 12px;font-family:'Inter',sans-serif;font-size:13px; }
    .dataTables_wrapper .dataTables_filter input:focus { outline:none;border-color:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.12); }
    .dataTables_wrapper .dataTables_length select { border:1.5px solid #e2e8f0;border-radius:8px;padding:4px 8px;font-family:'Inter',sans-serif; }
    .dataTables_wrapper .dataTables_info, .dataTables_wrapper .dataTables_paginate { font-size:13px;color:#64748b; }
    .dataTables_wrapper { padding:0 24px 16px; }
    /* Responsive table scroll */
    .table-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
    /* Card header flex wrap on small screens */
    @media(max-width:540px){
      .cl-card-header { flex-wrap:wrap; gap:10px; }
      .cl-topbar .topbar-right .topbar-badge { display:none; }
      .dataTables_wrapper { padding:0 12px 12px; }
    }
  </style>
</head>
<body>

<!-- Sidebar overlay -->
<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- Sidebar -->
<aside class="cl-sidebar theme-red" id="sidebar">
  <div class="sidebar-brand">
    <div class="logo-icon" style="background:linear-gradient(135deg,#ef4444,#dc2626);box-shadow:0 4px 12px rgba(239,68,68,.4);">
      <i class="fa fa-shield"></i>
    </div>
    <h2>Cen<span style="color:#fca5a5">Learn</span></h2>
    <p>Super Admin Panel</p>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Admin Menu</div>
    <ul style="list-style:none;margin:0;padding:0;">
      <li class="nav-item active">
        <a href="dashboard"><i class="fa fa-th-large"></i> Dashboard</a>
      </li>
      <li class="<?php echo $page==='students'?'active':''; ?>">
        <a href="students"><i class="fa fa-graduation-cap"></i> Student Management</a>
      </li>
    </ul>
  </nav>
  <div class="sidebar-footer">
    <div class="user-info">
      <div class="user-avatar" style="background:linear-gradient(135deg,#ef4444,#dc2626);">
        <i class="fa fa-user"></i>
      </div>
      <div class="user-meta">
        <strong><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></strong>
        <span>Super Admin</span>
      </div>
    </div>
    <a href="/cenlearn/logout" class="btn-signout"><i class="fa fa-sign-out"></i> Sign Out</a>
  </div>
</aside>

<!-- Main -->
<div class="cl-main">
  <header class="cl-topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
      <div class="topbar-title">
        <h1>Super Admin Dashboard</h1>
        <p>User management &amp; role control</p>
      </div>
    </div>
    <div class="topbar-right">
      <span class="topbar-badge" style="background:#fee2e2;color:#991b1b;">
        <i class="fa fa-shield"></i> Super Admin
      </span>
      <button class="btn-cl btn-red sm" onclick="document.getElementById('createAdminModal').classList.add('open')">
        <i class="fa fa-plus"></i> Create Admin
      </button>
    </div>
  </header>

  <div class="cl-content">

    <!-- Recommendation Banner -->
    <?php if($counts['ADMIN'] === 0): ?>
    <div class="cl-alert alert-warning" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; border-radius:12px; padding:16px 24px; box-shadow: 0 4px 12px rgba(245,158,11,.08);">
      <div style="display:flex; align-items:center; gap:12px;">
        <div style="background:#fef3c7; width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
          <i class="fa fa-lightbulb-o" style="font-size:22px; color:#d97706; margin:0;"></i>
        </div>
        <div>
          <strong style="font-weight:700; color:#92400e; display:block; font-size:14px; margin-bottom:2px;">Recommendation: Insert Admin Account</strong>
          <span style="font-size:13px; color:#b45309;">There are currently <strong>0 Admin accounts</strong> in the database. Please insert/create an Admin account to manage department courses (IS, CRIM, EDUC, ART), teachers, and classes.</span>
        </div>
      </div>
      <button class="btn-cl btn-amber sm" onclick="document.getElementById('createAdminModal').classList.add('open')" style="min-height:36px; padding:6px 16px; font-size:12px; margin:0; border-radius:8px;">
        <i class="fa fa-plus"></i> Create Admin Now
      </button>
    </div>
    <?php else: ?>
    <div class="cl-alert alert-info" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; border-radius:12px; padding:16px 24px; box-shadow: 0 4px 12px rgba(37,99,235,.08);">
      <div style="display:flex; align-items:center; gap:12px;">
        <div style="background:#eff6ff; width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
          <i class="fa fa-info-circle" style="font-size:20px; color:#1d4ed8; margin:0;"></i>
        </div>
        <div>
          <strong style="font-weight:700; color:#1e40af; display:block; font-size:14px; margin-bottom:2px;">Department Admins Active</strong>
          <span style="font-size:13px; color:#1e40af;">You have configured <strong><?php echo $counts['ADMIN']; ?></strong> of 4 maximum department admin accounts. You can insert more admins for remaining departments.</span>
        </div>
      </div>
      <?php if($counts['ADMIN'] < 4): ?>
      <button class="btn-cl btn-blue sm" onclick="document.getElementById('createAdminModal').classList.add('open')" style="min-height:36px; padding:6px 16px; font-size:12px; margin:0; border-radius:8px;">
        <i class="fa fa-plus"></i> Insert Admin Account
      </button>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#1792bb,#0f5f80);">
          <i class="fa fa-graduation-cap"></i>
        </div>
        <div class="stat-info">
          <strong><?php echo $counts['STUDENT']; ?></strong>
          <span>Students</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#10b981,#059669);">
          <i class="fa fa-chalkboard-teacher"></i>
        </div>
        <div class="stat-info">
          <strong><?php echo $counts['TEACHER']; ?></strong>
          <span>Teachers</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
          <i class="fa fa-user-secret"></i>
        </div>
        <div class="stat-info">
          <strong><?php echo $counts['ADMIN']; ?></strong>
          <span>Admins</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#ef4444,#dc2626);">
          <i class="fa fa-users"></i>
        </div>
        <div class="stat-info">
          <strong><?php echo count($userRows); ?></strong>
          <span>Total Users</span>
        </div>
      </div>
    </div>

    <!-- Student Management Card -->
    <div class="cl-card" style="margin-bottom:20px;">
      <div class="cl-card-header">
        <h3><i class="fa fa-graduation-cap"></i> Student Management</h3>
        <div style="display:flex;gap:8px;">
          <button class="btn-cl btn-amber sm" id="btnRefreshAll" onclick="refreshAllStudents()">
            <i class="fa fa-refresh"></i> Refresh All from TechnoPal
          </button>
          <a href="students" class="btn-cl btn-red sm">
            <i class="fa fa-users"></i> Manage Students
          </a>
        </div>
      </div>
      <div class="cl-card-body" style="padding:16px 24px;">
        <p style="font-size:13px;color:#64748b;margin:0;">
          <i class="fa fa-info-circle"></i>
          Use <strong>Refresh All</strong> to sync all student year levels, sections, and program data from TechnoPal.
          Use <strong>Manage Students</strong> to view and refresh individual student records.
        </p>
        <div id="refreshStatus" style="display:none;margin-top:12px;"></div>
      </div>
    </div>

    <!-- Users Table -->
    <div class="cl-card">
      <div class="cl-card-header">
        <h3><i class="fa fa-users"></i> All Users</h3>
        <span class="badge-cl badge-red"><?php echo count($userRows); ?> total</span>
      </div>
      <div class="cl-card-body no-pad" style="padding-top:16px;">
        <div class="table-scroll">
        <table class="cl-table" id="usersTable" style="width:100%;">
          <thead>
            <tr>
              <th>#</th>
              <th>Username</th>
              <th>Name</th>
              <th>Email</th>
              <th>Role</th>
              <th>Dept / Programs</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($userRows as $i => $u): ?>
            <?php
            $badgeMap = ['SUPERADMIN'=>'badge-red','ADMIN'=>'badge-amber','TEACHER'=>'badge-green','STUDENT'=>'badge-blue'];
            $badgeCls = $badgeMap[$u['user_group']] ?? 'badge-slate';
            ?>
            <tr>
              <td style="color:#94a3b8;"><?php echo $i+1; ?></td>
              <td style="font-weight:600;"><?php echo htmlspecialchars($u['user_code']); ?></td>
              <td><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?></td>
              <td style="color:#64748b;"><?php echo htmlspecialchars($u['email_address'] ?: '—'); ?></td>
              <td><span class="badge-cl <?php echo $badgeCls; ?>"><?php echo htmlspecialchars($u['user_group']); ?></span></td>
              <td style="font-size:12px;color:#64748b;">
                <?php if($u['user_group']==='ADMIN'): ?>
                  <?php if(!empty($u['department'])): ?>
                    <span class="badge-cl badge-red" style="margin:1px;"><?php echo htmlspecialchars($u['department']); ?></span>
                  <?php endif; ?>
                  <?php if(!empty($u['program_description'])): ?>
                    <?php foreach(array_map('trim', explode(',', $u['program_description'])) as $prog): ?>
                    <span class="badge-cl badge-amber" style="margin:1px;"><?php echo htmlspecialchars($prog); ?></span>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  <?php if(empty($u['department']) && empty($u['program_description'])): ?>—<?php endif; ?>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td>
                <span class="badge-cl <?php echo $u['is_active'] ? 'badge-green':'badge-slate'; ?>">
                  <?php echo $u['is_active'] ? 'Active':'Inactive'; ?>
                </span>
              </td>
              <td>
                <?php if($u['user_group'] === 'TEACHER'): ?>
                  <button class="btn-cl btn-amber xs"
                    onclick="manageUser('promote','<?php echo $u['user_code']; ?>','<?php echo htmlspecialchars(addslashes($u['first_name'].' '.$u['last_name'])); ?>')">
                    <i class="fa fa-arrow-up"></i> Promote to Admin
                  </button>
                <?php elseif($u['user_group'] === 'ADMIN'): ?>
                  <button class="btn-cl btn-green xs"
                    onclick="manageUser('demote','<?php echo $u['user_code']; ?>','<?php echo htmlspecialchars(addslashes($u['first_name'].' '.$u['last_name'])); ?>')">
                    <i class="fa fa-arrow-down"></i> Demote to Teacher
                  </button>
                <?php else: ?>
                  <span style="color:#cbd5e1;font-size:12px;">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div><!-- /.table-scroll -->
      </div>
    </div>

  </div>
  <footer class="cl-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<!-- Create Admin Modal -->
<div class="cl-modal-overlay" id="createAdminModal">
  <div class="cl-modal" style="max-width:520px;">
    <div class="cl-modal-header" style="background:linear-gradient(135deg,#ef4444,#dc2626);">
      <h4 style="color:#fff;"><i class="fa fa-user-plus"></i> Create Admin Account</h4>
      <button class="cl-modal-close" style="color:#fff;background:rgba(255,255,255,.2);" onclick="document.getElementById('createAdminModal').classList.remove('open')">&times;</button>
    </div>
    <div class="cl-modal-body">

      <!-- Department selector -->
      <div class="fg">
        <label>Department <span class="req">*</span></label>
        <select id="adm_dept" class="fc" onchange="onDeptChange()">
          <option value="">— Select Department —</option>
          <?php foreach($DEPARTMENTS as $deptCode => $dept): ?>
            <?php $taken = in_array($deptCode, $takenDepts); ?>
            <option value="<?php echo $deptCode; ?>"
              <?php echo $taken ? 'disabled style="color:#94a3b8;"' : ''; ?>>
              <?php echo htmlspecialchars($deptCode.' — '.$dept['label']); ?>
              <?php echo $taken ? ' (already has admin)' : ''; ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span style="font-size:11px;color:#94a3b8;display:block;margin-top:4px;">
          Each department can only have <strong>1 admin</strong>. Max 4 admin accounts total.
        </span>
      </div>

      <!-- Courses (auto-populated, checkboxes) -->
      <div class="fg" id="coursesWrap" style="display:none;">
        <label>Courses Handled <span class="req">*</span></label>
        <div id="courseCheckboxes" style="display:flex;flex-wrap:wrap;gap:8px;padding:10px;border:1.5px solid #e2e8f0;border-radius:10px;background:#f8fafc;"></div>
        <span style="font-size:11px;color:#94a3b8;display:block;margin-top:4px;">All courses are pre-selected. Uncheck to exclude.</span>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="fg">
          <label>First Name <span class="req">*</span></label>
          <input type="text" id="adm_first" class="fc" placeholder="First name">
        </div>
        <div class="fg">
          <label>Last Name <span class="req">*</span></label>
          <input type="text" id="adm_last" class="fc" placeholder="Last name">
        </div>
      </div>
      <div class="fg">
        <label>Username / Employee ID <span class="req">*</span></label>
        <input type="text" id="adm_code" class="fc" placeholder="e.g. ADMIN-IS-001">
      </div>
      <div class="fg">
        <label>Password <span class="req">*</span></label>
        <input type="password" id="adm_pass" class="fc" placeholder="Minimum 6 characters">
      </div>
      <div class="fg" style="margin-bottom:0;">
        <label>Email Address</label>
        <input type="email" id="adm_email" class="fc" placeholder="admin@bcc.edu.ph">
      </div>
      <div id="createAdminAlert" style="display:none;margin-top:12px;"></div>
    </div>
    <div class="cl-modal-footer">
      <button class="btn-cl btn-ghost" onclick="document.getElementById('createAdminModal').classList.remove('open')">Cancel</button>
      <button class="btn-cl btn-red" id="btnCreateAdmin"><i class="fa fa-save"></i> Create Admin</button>
    </div>
  </div>
</div>

<!-- Confirm Modal -->
<div class="cl-modal-overlay" id="confirmModal">
  <div class="cl-modal" style="max-width:400px;">
    <div class="cl-modal-header">
      <h4 id="confirmTitle">Confirm Action</h4>
      <button class="cl-modal-close" onclick="document.getElementById('confirmModal').classList.remove('open')">&times;</button>
    </div>
    <div class="cl-modal-body">
      <p id="confirmMsg" style="color:#475569;font-size:14px;margin:0;"></p>
      <div id="confirmAlert" style="display:none;margin-top:12px;"></div>
    </div>
    <div class="cl-modal-footer">
      <button class="btn-cl btn-ghost" onclick="document.getElementById('confirmModal').classList.remove('open')">Cancel</button>
      <button class="btn-cl btn-red" id="btnConfirm"><i class="fa fa-check"></i> Confirm</button>
    </div>
  </div>
</div>

<script src="/cenlearn/system/bower_components/jquery/dist/jquery.min.js"></script>
<script src="/cenlearn/system/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script src="/cenlearn/system/bower_components/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="/cenlearn/system/bower_components/datatables.net-bs/js/dataTables.bootstrap.min.js"></script>
<script>
$(document).ready(function(){
  $('#usersTable').DataTable({ autoWidth:false, scrollX:true });
});

var _pendingAction = null, _pendingCode = null;

function manageUser(action, userCode, userName){
  _pendingAction = action; _pendingCode = userCode;
  var label = action === 'promote' ? 'promote <strong>'+userName+'</strong> to ADMIN' : 'demote <strong>'+userName+'</strong> back to TEACHER';
  $('#confirmTitle').text(action === 'promote' ? 'Promote to Admin' : 'Demote to Teacher');
  $('#confirmMsg').html('Are you sure you want to '+label+'?');
  $('#confirmAlert').hide();
  document.getElementById('confirmModal').classList.add('open');
}

$('#btnConfirm').on('click', function(){
  if(!_pendingAction || !_pendingCode) return;
  $(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i>');
  $.ajax({
    url:'manage_user.php', method:'POST',
    data:{ action:_pendingAction, user_code:_pendingCode },
    dataType:'json',
    success:function(res){
      $('#btnConfirm').prop('disabled',false).html('<i class="fa fa-check"></i> Confirm');
      if(res.success){
        $('#confirmAlert').attr('class','cl-alert alert-success').html('<i class="fa fa-check"></i> '+res.msg).show();
        setTimeout(function(){ location.reload(); }, 1200);
      } else {
        $('#confirmAlert').attr('class','cl-alert alert-danger').html('<i class="fa fa-times-circle"></i> '+res.msg).show();
      }
    },
    error:function(){
      $('#btnConfirm').prop('disabled',false).html('<i class="fa fa-check"></i> Confirm');
      $('#confirmAlert').attr('class','cl-alert alert-danger').html('<i class="fa fa-times-circle"></i> Request failed.').show();
    }
  });
});

document.getElementById('confirmModal').addEventListener('click',function(e){ if(e.target===this) this.classList.remove('open'); });

// ── Department → Courses map (mirrors PHP) ────────────────────────────────
var DEPT_COURSES = {
  'IS':   ['IS'],
  'CRIM': ['CRIM'],
  'EDUC': ['BSED-FILIPINO','BSED-MATHEMATICS','BSED-SOCIAL STUDIES','BPED','BEED'],
  'ART':  ['BSOA','AB HISTORY','AB ENGLISH']
};

function onDeptChange(){
  var dept = $('#adm_dept').val();
  var wrap = document.getElementById('coursesWrap');
  var box  = document.getElementById('courseCheckboxes');
  box.innerHTML = '';
  if(!dept){ wrap.style.display='none'; return; }
  var courses = DEPT_COURSES[dept] || [];
  courses.forEach(function(c){
    var id = 'chk_'+c.replace(/[^a-z0-9]/gi,'_');
    box.innerHTML += '<label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:4px 8px;background:#fff;border:1.5px solid #e2e8f0;border-radius:8px;">'
      +'<input type="checkbox" id="'+id+'" value="'+c+'" checked style="accent-color:#ef4444;width:15px;height:15px;"> '+c+'</label>';
  });
  wrap.style.display = 'block';
}

// ── Create Admin ───────────────────────────────────────────────────────────
$('#btnCreateAdmin').on('click', function(){
  var dept     = $('#adm_dept').val().trim();
  var first    = $('#adm_first').val().trim();
  var last     = $('#adm_last').val().trim();
  var code     = $('#adm_code').val().trim();
  var pass     = $('#adm_pass').val().trim();
  var email    = $('#adm_email').val().trim();

  if(!dept){
    showAdminAlert('danger','Please select a department.');
    return;
  }
  if(!first || !last || !code || !pass){
    showAdminAlert('danger','First name, last name, username and password are required.');
    return;
  }
  if(pass.length < 6){
    showAdminAlert('danger','Password must be at least 6 characters.');
    return;
  }

  // Collect checked courses
  var checked = [];
  $('#courseCheckboxes input[type=checkbox]:checked').each(function(){ checked.push($(this).val()); });
  if(checked.length === 0){
    showAdminAlert('danger','Please select at least one course for this admin.');
    return;
  }

  $(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Creating...');
  var btn = this;
  $.ajax({
    url: 'manage_user.php', method: 'POST', dataType: 'json',
    data: { action:'create_admin', first_name:first, last_name:last,
            user_code:code, password:pass, email:email,
            department:dept, programs:checked.join(',') },
    success: function(res){
      $(btn).prop('disabled',false).html('<i class="fa fa-save"></i> Create Admin');
      if(res.success){
        showAdminAlert('success', res.msg);
        setTimeout(function(){ location.reload(); }, 1200);
      } else {
        showAdminAlert('danger', res.msg);
      }
    },
    error: function(){
      $(btn).prop('disabled',false).html('<i class="fa fa-save"></i> Create Admin');
      showAdminAlert('danger','Request failed. Please try again.');
    }
  });
});

function showAdminAlert(type, msg){
  var el = document.getElementById('createAdminAlert');
  var cls = type==='success' ? 'cl-alert alert-success' : 'cl-alert alert-danger';
  var ico = type==='success' ? 'check-circle' : 'times-circle';
  el.className = cls;
  el.innerHTML = '<i class="fa fa-'+ico+'"></i> '+msg;
  el.style.display = 'flex';
}

document.getElementById('createAdminModal').addEventListener('click',function(e){ if(e.target===this) this.classList.remove('open'); });

function refreshAllStudents(){
  if(!confirm('Refresh ALL student year levels, sections, and programs from TechnoPal? This may take a while.')) return;
  var btn = $('#btnRefreshAll');
  btn.html('<i class="fa fa-spinner fa-spin"></i> Refreshing...').prop('disabled', true);
  var status = $('#refreshStatus');
  status.attr('class','cl-alert alert-success').html('<i class="fa fa-spinner fa-spin"></i> Syncing with TechnoPal...').show();
  $.ajax({
    url: 'refresh_all_students.php',
    method: 'POST',
    dataType: 'json',
    timeout: 120000,
    success: function(res){
      if(res.success){
        status.attr('class','cl-alert alert-success').html('<i class="fa fa-check-circle"></i> ' + res.msg);
      } else {
        status.attr('class','cl-alert alert-danger').html('<i class="fa fa-times-circle"></i> ' + res.msg);
      }
    },
    error: function(){
      status.attr('class','cl-alert alert-danger').html('<i class="fa fa-times-circle"></i> Request failed or timed out.');
    },
    complete: function(){
      btn.html('<i class="fa fa-refresh"></i> Refresh All from TechnoPal').prop('disabled', false);
    }
  });
}

function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
</script>
</body>
</html>
