<?php
if(session_status() === PHP_SESSION_NONE) session_start();
include '../includes/conn.php';
include '../includes/session.php';

if(empty($user) || $user['user_group'] !== 'SUPERADMIN'){
    header('Location: ../login'); exit;
}

$qStudents = $conn->query("SELECT u.* FROM users u WHERE u.user_group='STUDENT' GROUP BY u.user_code ORDER BY u.last_name, u.first_name");
$total     = (int)($conn->query("SELECT COUNT(DISTINCT user_code) c FROM users WHERE user_group='STUDENT'")->fetch_assoc()['c'] ?? 0);

$qActive   = $conn->query("SELECT COUNT(DISTINCT user_code) c FROM users WHERE user_group='STUDENT' AND is_active=1");
$active    = $qActive->fetch_assoc()['c'];

$qPrograms = $conn->query("SELECT COUNT(DISTINCT program_code) c FROM users WHERE user_group='STUDENT' AND program_code!=''");
$programs  = $qPrograms->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Student Management</title>
  <link rel="stylesheet" href="/cenlearn/system/bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/cenlearn/system/bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/cenlearn/system/dist/css/cenlearn.css">
  <link rel="stylesheet" href="/cenlearn/system/bower_components/datatables.net-bs/css/dataTables.bootstrap.min.css">
  <style>
    *{box-sizing:border-box;}
    body{font-family:'Inter',sans-serif;background:#f0f4f8;}
    .dataTables_wrapper .dataTables_filter input{border:1.5px solid #e2e8f0;border-radius:8px;padding:6px 12px;font-family:'Inter',sans-serif;font-size:13px;}
    .dataTables_wrapper .dataTables_filter input:focus{outline:none;border-color:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.12);}
    .dataTables_wrapper .dataTables_length select{border:1.5px solid #e2e8f0;border-radius:8px;padding:4px 8px;}
    .dataTables_wrapper .dataTables_info,.dataTables_wrapper .dataTables_paginate{font-size:13px;color:#64748b;}
    .dataTables_wrapper{padding:0 24px 16px;}
    /* Stats */
    .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px;}
    .stat-card{background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:20px;display:flex;align-items:center;gap:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
    .stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .stat-icon i{font-size:20px;color:#fff;}
    .stat-info strong{display:block;font-size:24px;font-weight:800;color:#0f172a;line-height:1;}
    .stat-info span{font-size:12px;color:#64748b;font-weight:500;}
    /* Refresh status */
    .refresh-status{margin:12px 0 0;padding:10px 16px;border-radius:10px;font-size:13px;font-weight:600;display:none;align-items:center;gap:8px;}
    .refresh-status.success{background:#dcfce7;color:#166534;}
    .refresh-status.error{background:#fef2f2;color:#991b1b;}
    /* Table badges */
    .badge-active{background:#dcfce7;color:#166534;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:800;}
    .badge-inactive{background:#fef2f2;color:#991b1b;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:800;}
    .yl-pill{padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;}
    .yl-1{background:#dbeafe;color:#1e40af;}
    .yl-2{background:#dcfce7;color:#166534;}
    .yl-3{background:#fef9c3;color:#92400e;}
    .yl-4{background:#fce7f3;color:#9d174d;}
    .yl-5{background:#f3e8ff;color:#6b21a8;}
    .btn-refresh-row{background:#fef3c7;color:#d97706;border:1px solid #fde68a;padding:5px 12px;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;}
    .btn-refresh-row:hover{background:#fde68a;}
    @media(max-width:600px){.dataTables_wrapper{padding:0 12px 12px;}}
  </style>
</head>
<body>

<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

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
      <li class="nav-item">
        <a href="dashboard"><i class="fa fa-th-large"></i> Dashboard</a>
      </li>
      <li class="nav-item active">
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

<div class="cl-main">
  <header class="cl-topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()"><i class="fa fa-bars"></i></button>
      <div class="topbar-title">
        <h1>Student Management</h1>
        <p>View and sync all student records</p>
      </div>
    </div>
    <div class="topbar-right">
      <button class="btn-cl btn-amber sm" id="btnRefreshAll" onclick="refreshAllStudents()">
        <i class="fa fa-refresh"></i> Refresh All from TechnoPal
      </button>
    </div>
  </header>

  <div class="cl-content">

    <!-- Stats -->
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#1792bb,#0f5f80);">
          <i class="fa fa-graduation-cap"></i>
        </div>
        <div class="stat-info">
          <strong><?php echo $total; ?></strong>
          <span>Total Students</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#22c55e,#16a34a);">
          <i class="fa fa-check-circle"></i>
        </div>
        <div class="stat-info">
          <strong><?php echo $active; ?></strong>
          <span>Active</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
          <i class="fa fa-ban"></i>
        </div>
        <div class="stat-info">
          <strong><?php echo $total - $active; ?></strong>
          <span>Inactive</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed);">
          <i class="fa fa-university"></i>
        </div>
        <div class="stat-info">
          <strong><?php echo $programs; ?></strong>
          <span>Programs</span>
        </div>
      </div>
    </div>

    <!-- Refresh status -->
    <div class="refresh-status" id="refreshStatus"></div>

    <!-- Students Table -->
    <div class="cl-card">
      <div class="cl-card-header">
        <h3><i class="fa fa-users"></i> All Students</h3>
        <span class="badge-cl badge-blue"><?php echo $total; ?> students</span>
      </div>
      <div class="cl-card-body no-pad" style="padding-top:16px;">
        <div style="overflow-x:auto;">
          <table class="cl-table" id="studentsTable" style="width:100%;">
            <thead>
              <tr>
                <th>#</th>
                <th>Student ID</th>
                <th>Name</th>
                <th>Program</th>
                <th>Year</th>
                <th>Section</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $i = 1;
              $qStudents->data_seek(0);
              $ylClass = [1=>'yl-1',2=>'yl-2',3=>'yl-3',4=>'yl-4',5=>'yl-5'];
              $ylLabel = [1=>'1st',2=>'2nd',3=>'3rd',4=>'4th',5=>'5th'];
              while($s = $qStudents->fetch_assoc()):
                $yl = (int)($s['year_level'] ?? 0);
              ?>
              <tr data-user-code="<?php echo htmlspecialchars($s['user_code']); ?>">
                <td style="color:#94a3b8;"><?php echo $i++; ?></td>
                <td style="font-family:monospace;font-weight:600;"><?php echo htmlspecialchars($s['user_code']); ?></td>
                <td class="full-name">
                  <a href="javascript:void(0)" onclick="openStudentProfileModal('<?php echo htmlspecialchars($s['user_code']); ?>')" style="color:#2563eb;font-weight:700;text-decoration:none;">
                    <?php echo htmlspecialchars($s['last_name'].', '.$s['first_name']); ?>
                  </a>
                </td>
                <td class="program-code"><?php echo htmlspecialchars($s['program_code'] ?: '—'); ?></td>
                <td class="year-level">
                  <?php if($yl && isset($ylLabel[$yl])): ?>
                    <span class="yl-pill <?php echo $ylClass[$yl]; ?>"><?php echo $ylLabel[$yl]; ?> Year</span>
                  <?php else: ?>
                    <span style="color:#94a3b8;">—</span>
                  <?php endif; ?>
                </td>
                <td class="section"><?php echo htmlspecialchars($s['section'] ?: '—'); ?></td>
                <td>
                  <span class="<?php echo $s['is_active']==1 ? 'badge-active' : 'badge-inactive'; ?>">
                    <?php echo $s['is_active']==1 ? 'Active' : 'Inactive'; ?>
                  </span>
                </td>
                <td>
                  <div style="display:flex;gap:6px;">
                    <button type="button" class="btn-refresh-row" style="background:#eff6ff;color:#2563eb;border-color:#bfdbfe;" onclick="openStudentProfileModal('<?php echo htmlspecialchars($s['user_code']); ?>')">
                      <i class="fa fa-user"></i> Profile
                    </button>
                    <button type="button" class="btn-refresh-row" onclick="refreshStudent('<?php echo htmlspecialchars($s['user_code']); ?>')">
                      <i class="fa fa-refresh"></i> Sync
                    </button>
                  </div>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
  <footer class="cl-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<script src="/cenlearn/system/bower_components/jquery/dist/jquery.min.js"></script>
<script src="/cenlearn/system/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script src="/cenlearn/system/bower_components/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="/cenlearn/system/bower_components/datatables.net-bs/js/dataTables.bootstrap.min.js"></script>
<script>
$(document).ready(function(){
  $('#studentsTable').DataTable({ autoWidth:false, scrollX:true, pageLength:25 });
});

function showStatus(msg, ok){
  var el = $('#refreshStatus');
  el.removeClass('success error').addClass(ok ? 'success' : 'error')
    .html('<i class="fa fa-'+(ok?'check-circle':'times-circle')+'"></i> '+msg)
    .css('display','flex');
  if(ok) setTimeout(function(){ el.fadeOut(400, function(){ el.hide(); }); }, 4000);
}

function refreshStudent(userCode){
  var btn = $('tr[data-user-code="'+userCode+'"] .btn-refresh-row');
  var orig = btn.html();
  btn.html('<i class="fa fa-spinner fa-spin"></i>').prop('disabled', true);
  $.ajax({
    url: 'refresh_student.php', method: 'POST', dataType: 'json',
    data: { user_code: userCode },
    success: function(res){
      if(res.success){
        var row = $('tr[data-user-code="'+userCode+'"]');
        row.find('.full-name').text(res.data.last_name + ', ' + res.data.first_name);
        row.find('.program-code').text(res.data.program_code || '—');
        row.find('.section').text(res.data.section || '—');
        // Update year pill
        var yl = parseInt(res.data.year_level) || 0;
        var ylClass = {1:'yl-1',2:'yl-2',3:'yl-3',4:'yl-4',5:'yl-5'};
        var ylLabel = {1:'1st',2:'2nd',3:'3rd',4:'4th',5:'5th'};
        row.find('.year-level').html(yl && ylLabel[yl]
          ? '<span class="yl-pill '+ylClass[yl]+'">'+ylLabel[yl]+' Year</span>'
          : '<span style="color:#94a3b8;">—</span>');
        showStatus(res.source==='api' ? userCode+' refreshed from TechnoPal.' : res.msg, true);
      } else {
        showStatus(res.msg, false);
      }
    },
    error: function(){ showStatus('Failed to refresh '+userCode, false); },
    complete: function(){ btn.html(orig).prop('disabled', false); }
  });
}

function refreshAllStudents(){
  if(!confirm('Refresh ALL student records from TechnoPal? This may take a while.')) return;
  var btn = $('#btnRefreshAll');
  btn.html('<i class="fa fa-spinner fa-spin"></i> Refreshing...').prop('disabled', true);
  showStatus('<i class="fa fa-spinner fa-spin"></i> Syncing with TechnoPal...', true);
  $.ajax({
    url: 'refresh_all_students.php', method: 'POST', dataType: 'json', timeout: 120000,
    success: function(res){ showStatus(res.msg, res.success); if(res.success && res.updated > 0) setTimeout(()=>location.reload(), 2000); },
    error: function(){ showStatus('Request failed or timed out.', false); },
    complete: function(){ btn.html('<i class="fa fa-refresh"></i> Refresh All from TechnoPal').prop('disabled', false); }
  });
}

function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
</script>
<?php include '../includes/student_profile_modal.php'; ?>
</body>
</html>
