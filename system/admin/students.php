<?php
if(session_status() === PHP_SESSION_NONE) session_start();
include '../includes/conn.php';
include '../includes/session.php';

if(empty($user) || $user['user_group'] !== 'ADMIN'){
    header('Location: ../index.php?role_mismatch=admin'); exit;
}

// ── Department theme (mirrors dashboard.php) ──────────────────────────────
$dept = strtoupper(trim($user['department'] ?? ''));
if(empty($dept) && !empty($user['program_description'])){
    $COURSE_TO_DEPT = [
        'IS'=>'IS','CRIM'=>'CRIM',
        'BSED-FILIPINO'=>'EDUC','BSED-MATHEMATICS'=>'EDUC','BSED-SOCIAL STUDIES'=>'EDUC','BPED'=>'EDUC','BEED'=>'EDUC',
        'BSOA'=>'ART','AB HISTORY'=>'ART','AB ENGLISH'=>'ART',
    ];
    foreach(explode(',', $user['program_description']) as $c){
        $c = strtoupper(trim($c));
        if(isset($COURSE_TO_DEPT[$c])){ $dept = $COURSE_TO_DEPT[$c]; break; }
    }
}
$DEPT_THEMES = [
    'IS'  =>['base'=>'#16a34a','dark'=>'#052e16','grad1'=>'#16a34a','grad2'=>'#15803d','icon'=>'fa-desktop'],
    'CRIM'=>['base'=>'#dc2626','dark'=>'#450a0a','grad1'=>'#dc2626','grad2'=>'#b91c1c','icon'=>'fa-balance-scale'],
    'EDUC'=>['base'=>'#2563eb','dark'=>'#172554','grad1'=>'#2563eb','grad2'=>'#1d4ed8','icon'=>'fa-graduation-cap'],
    'ART' =>['base'=>'#d97706','dark'=>'#451a03','grad1'=>'#d97706','grad2'=>'#b45309','icon'=>'fa-paint-brush'],
];
$T = $DEPT_THEMES[$dept] ?? $DEPT_THEMES['IS'];

// ── Admin's courses ───────────────────────────────────────────────────────
$adminCourses = [];
if(!empty($user['program_description'])){
    foreach(explode(',', $user['program_description']) as $c){
        $c = strtoupper(trim($c));
        if($c !== '') $adminCourses[] = $c;
    }
}

// ── Fetch students for this admin's courses ───────────────────────────────
if(!empty($adminCourses)){
    $escaped = array_map(fn($c) => "'".$conn->real_escape_string($c)."'", $adminCourses);
    $inList  = implode(',', $escaped);
    $where   = "user_group='STUDENT' AND UPPER(program_code) IN ($inList)";
} else {
    $where = "user_group='STUDENT'";
}

$qStudents = $conn->query("SELECT u.* FROM users u WHERE $where GROUP BY u.user_code ORDER BY u.last_name, u.first_name");
$total     = (int)($conn->query("SELECT COUNT(DISTINCT user_code) c FROM users WHERE $where")->fetch_assoc()['c'] ?? 0);
$qActive   = $conn->query("SELECT COUNT(DISTINCT user_code) c FROM users WHERE $where AND is_active=1");
$active    = $qActive->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Students</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <link rel="stylesheet" href="../bower_components/datatables.net-bs/css/dataTables.bootstrap.min.css">
  <style>
    *{box-sizing:border-box;}
    body{font-family:'Inter',sans-serif;background:#f0f4f8;}
    .dataTables_wrapper .dataTables_filter input{border:1.5px solid #e2e8f0;border-radius:8px;padding:6px 12px;font-size:13px;font-family:'Inter',sans-serif;}
    .dataTables_wrapper .dataTables_filter input:focus{outline:none;border-color:<?php echo $T['base']; ?>;box-shadow:0 0 0 3px <?php echo $T['base']; ?>22;}
    .dataTables_wrapper .dataTables_length select{border:1.5px solid #e2e8f0;border-radius:8px;padding:4px 8px;}
    .dataTables_wrapper .dataTables_info,.dataTables_wrapper .dataTables_paginate{font-size:13px;color:#64748b;}
    .dataTables_wrapper{padding:0 24px 16px;}
    .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:24px;}
    .stat-card{background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:20px;display:flex;align-items:center;gap:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
    .stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .stat-icon i{font-size:20px;color:#fff;}
    .stat-info strong{display:block;font-size:26px;font-weight:800;color:#0f172a;line-height:1;}
    .stat-info span{font-size:12px;color:#64748b;}
    .badge-active{background:#dcfce7;color:#166534;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:800;}
    .badge-inactive{background:#fef2f2;color:#991b1b;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:800;}
    .yl-pill{padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;}
    .yl-1{background:#dbeafe;color:#1e40af;}.yl-2{background:#dcfce7;color:#166534;}
    .yl-3{background:#fef9c3;color:#92400e;}.yl-4{background:#fce7f3;color:#9d174d;}
    .yl-5{background:#f3e8ff;color:#6b21a8;}
    @media(max-width:600px){.dataTables_wrapper{padding:0 12px 12px;}}
  </style>
</head>
<body>

<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<aside class="cl-sidebar" id="sidebar"
  style="background:linear-gradient(180deg,<?php echo $T['dark']; ?> 0%,<?php echo $T['grad2']; ?> 60%,<?php echo $T['grad1']; ?> 100%);">
  <div class="sidebar-brand">
    <div class="logo-icon" style="background:linear-gradient(135deg,<?php echo $T['grad1']; ?>,<?php echo $T['grad2']; ?>);">
      <i class="fa <?php echo $T['icon']; ?>"></i>
    </div>
    <h2>CenLearn</h2>
    <p><?php echo htmlspecialchars($dept ?: 'Admin'); ?> Department</p>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Admin Menu</div>
    <ul style="list-style:none;margin:0;padding:0;">
      <li class="nav-item"><a href="dashboard.php"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li class="nav-item active"><a href="students.php"><i class="fa fa-graduation-cap"></i> Students</a></li>
    </ul>
  </nav>
  <div class="sidebar-footer">
    <div class="user-info">
      <div class="user-avatar" style="background:linear-gradient(135deg,<?php echo $T['grad1']; ?>,<?php echo $T['grad2']; ?>);">
        <i class="fa fa-user"></i>
      </div>
      <div class="user-meta">
        <strong><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></strong>
        <span>Admin</span>
      </div>
    </div>
    <a href="../logout.php" class="btn-signout"><i class="fa fa-sign-out"></i> Sign Out</a>
  </div>
</aside>

<div class="cl-main">
  <header class="cl-topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()"><i class="fa fa-bars"></i></button>
      <div class="topbar-title">
        <h1>Students</h1>
        <p><?php echo htmlspecialchars(implode(', ', $adminCourses) ?: 'All Programs'); ?></p>
      </div>
    </div>
  </header>

  <div class="cl-content">

    <!-- Stats -->
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,<?php echo $T['grad1']; ?>,<?php echo $T['grad2']; ?>);">
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
        <div class="stat-icon" style="background:linear-gradient(135deg,#94a3b8,#64748b);">
          <i class="fa fa-ban"></i>
        </div>
        <div class="stat-info">
          <strong><?php echo $total - $active; ?></strong>
          <span>Inactive</span>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="cl-card">
      <div class="cl-card-header">
        <h3><i class="fa fa-users"></i> Student List</h3>
        <span class="badge-cl" style="background:<?php echo $T['base']; ?>22;color:<?php echo $T['base']; ?>;"><?php echo $total; ?> students</span>
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
              </tr>
            </thead>
            <tbody>
              <?php
              $i = 1;
              $ylClass = [1=>'yl-1',2=>'yl-2',3=>'yl-3',4=>'yl-4',5=>'yl-5'];
              $ylLabel = [1=>'1st',2=>'2nd',3=>'3rd',4=>'4th',5=>'5th'];
              $qStudents->data_seek(0);
              while($s = $qStudents->fetch_assoc()):
                $yl = (int)($s['year_level'] ?? 0);
              ?>
              <tr>
                <td style="color:#94a3b8;"><?php echo $i++; ?></td>
                <td style="font-family:monospace;font-weight:600;"><?php echo htmlspecialchars($s['user_code']); ?></td>
                <td><?php echo htmlspecialchars($s['last_name'].', '.$s['first_name']); ?></td>
                <td><?php echo htmlspecialchars($s['program_code'] ?: '—'); ?></td>
                <td>
                  <?php if($yl && isset($ylLabel[$yl])): ?>
                    <span class="yl-pill <?php echo $ylClass[$yl]; ?>"><?php echo $ylLabel[$yl]; ?> Year</span>
                  <?php else: ?>
                    <span style="color:#94a3b8;">—</span>
                  <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($s['section'] ?: '—'); ?></td>
                <td>
                  <span class="<?php echo $s['is_active']==1 ? 'badge-active' : 'badge-inactive'; ?>">
                    <?php echo $s['is_active']==1 ? 'Active' : 'Inactive'; ?>
                  </span>
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

<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script src="../bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script src="../bower_components/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="../bower_components/datatables.net-bs/js/dataTables.bootstrap.min.js"></script>
<script>
$(document).ready(function(){
  $('#studentsTable').DataTable({ autoWidth:false, scrollX:true, pageLength:25 });
});
function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
</script>
</body>
</html>
