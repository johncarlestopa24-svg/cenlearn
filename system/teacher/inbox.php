<?php
include '../includes/session.php';
include '../includes/conn.php';

$tc = $conn->real_escape_string($user['user_code']);

// Auto-create confirmations table
$conn->query("CREATE TABLE IF NOT EXISTS `class_confirmations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `student_code` varchar(50) NOT NULL,
  `status` enum('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  `responded_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_student` (`class_id`,`student_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Get all classes with accurate confirmation summary.
// We count enrolled students (excluding teacher), then count accepted/declined
// from class_confirmations. Pending = total_students - accepted - declined.
$classesQ = $conn->query("
    SELECT c.id, c.class_name, c.subject, c.section, c.year_level, c.schedule_json, c.schedule_room,
           COUNT(DISTINCT CASE WHEN u.user_group='STUDENT' THEN cm.user_code END) AS total_students,
           SUM(CASE WHEN cc.status='accepted' THEN 1 ELSE 0 END) AS accepted,
           SUM(CASE WHEN cc.status='declined' THEN 1 ELSE 0 END) AS declined
    FROM classes c
    LEFT JOIN class_members cm
           ON cm.class_id = c.id AND cm.user_code != '$tc'
    LEFT JOIN users u
           ON cm.user_code = u.user_code
    LEFT JOIN class_confirmations cc
           ON cc.class_id = c.id
          AND cc.student_code IN (SELECT user_code FROM class_members WHERE class_id=c.id AND user_code!='$tc')
    WHERE c.teacher_code='$tc' AND (c.is_archived=0 OR c.is_archived IS NULL) AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)
    GROUP BY c.id
    ORDER BY c.created_at DESC
");
$classRows = [];
while($r = $classesQ->fetch_assoc()) $classRows[] = $r;

// Recent responses (last 50)
$recentQ = $conn->query("
    SELECT cc.*, c.class_name, c.subject,
           u.first_name, u.last_name, u.section, u.year_level
    FROM class_confirmations cc
    JOIN classes c ON cc.class_id=c.id
    JOIN users u ON cc.student_code=u.user_code
    WHERE c.teacher_code='$tc' AND cc.status != 'pending' AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)
    ORDER BY cc.responded_at DESC
    LIMIT 50
");
$recentRows = [];
while($r = $recentQ->fetch_assoc()) $recentRows[] = $r;

$totalPending  = 0;
$totalAccepted = 0;
$totalDeclined = 0;
foreach($classRows as &$row){
    $row['total_students'] = (int)$row['total_students'];
    $row['accepted']       = (int)$row['accepted'];
    $row['declined']       = (int)$row['declined'];
    // Pending = enrolled students who have NOT yet responded
    $row['pending']        = max(0, $row['total_students'] - $row['accepted'] - $row['declined']);
    $totalPending  += $row['pending'];
    $totalAccepted += $row['accepted'];
    $totalDeclined += $row['declined'];
}
unset($row);

$initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CenLearn — Teacher Notifications</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    *,*::before,*::after{box-sizing:border-box;}
    html,body{margin:0;padding:0;overflow-x:hidden;}
    body{font-family:'Inter',sans-serif;background:#f0f4f8;color:#1e293b;}
    .t-sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:linear-gradient(180deg,#0f2027 0%,#203a43 55%,#2c5364 100%);display:flex;flex-direction:column;z-index:200;transition:transform .3s cubic-bezier(.4,0,.2,1);transform:translateX(-260px);}
    .t-sidebar.open{transform:translateX(0);}
    @media(min-width: 901px) { .t-sidebar{transform:translateX(0);} }
    .sb-brand{padding:22px 20px 16px;border-bottom:1px solid rgba(255,255,255,.07);}
    .sb-logo{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#10b981,#059669);display:inline-flex;align-items:center;justify-content:center;margin-bottom:8px;box-shadow:0 4px 14px rgba(16,185,129,.4);}
    .sb-logo i{color:#fff;font-size:17px;}
    .sb-brand h2{color:#fff;font-size:18px;font-weight:800;margin:0;}
    .sb-brand h2 span{color:#10b981;}
    .sb-brand p{color:rgba(255,255,255,.3);font-size:10px;margin:2px 0 0;}
    .sb-nav{flex:1;padding:10px 0;overflow-y:auto;}
    .sb-nav-sec{padding:10px 20px 4px;font-size:9px;font-weight:700;color:rgba(255,255,255,.22);letter-spacing:1.5px;text-transform:uppercase;}
    .sb-nav ul{list-style:none;margin:0;padding:0;}
    .sb-nav li a{display:flex;align-items:center;gap:10px;padding:10px 20px;color:rgba(255,255,255,.55);text-decoration:none;font-size:13px;font-weight:500;transition:all .18s;border-left:3px solid transparent;}
    .sb-nav li a:hover{background:rgba(255,255,255,.06);color:#fff;}
    .sb-nav li.active a{background:rgba(16,185,129,.15);color:#fff;border-left-color:#10b981;}
    .sb-nav li a i{width:16px;text-align:center;font-size:13px;}
    .sb-footer{padding:14px 20px;border-top:1px solid rgba(255,255,255,.07);}
    .sb-user{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
    .sb-av{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;flex-shrink:0;}
    .sb-meta strong{display:block;color:#fff;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;}
    .sb-meta span{color:rgba(255,255,255,.38);font-size:10px;}
    .sb-out{display:flex;align-items:center;justify-content:center;gap:6px;padding:8px;width:100%;background:rgba(255,255,255,.06);color:rgba(255,255,255,.55);border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:12px;font-weight:500;text-decoration:none;transition:all .18s;}
    .sb-out:hover{background:rgba(255,255,255,.12);color:#fff;}
    .t-main{margin-left:0;min-height:100vh;display:flex;flex-direction:column;transition:margin 0s;}
    @media(min-width: 901px) { .t-main{margin-left:260px;} }
    .t-topbar{background:#fff;padding:0 28px;height:62px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:50;box-shadow:0 1px 4px rgba(0,0,0,.04);}
    .t-topbar h3{font-size:16px;font-weight:700;color:#0f172a;margin:0;}
    .t-topbar p{font-size:12px;color:#64748b;margin:0;}
    .t-content{padding:26px 28px 52px;flex:1;}
    .stats-strip{display:flex;gap:14px;margin-bottom:24px;flex-wrap:wrap;}
    .stat-pill{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 18px;flex:1;min-width:120px;}
    .sp-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .sp-icon i{font-size:16px;}
    .stat-pill strong{display:block;font-size:22px;font-weight:800;color:#0f172a;line-height:1;}
    .stat-pill span{font-size:11px;color:#64748b;font-weight:500;}
    /* Tab bar */
    .tab-bar{display:flex;gap:4px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:5px;margin-bottom:20px;}
    .tab-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border:none;background:transparent;border-radius:8px;font-size:13px;font-weight:600;color:#64748b;cursor:pointer;font-family:'Inter',sans-serif;transition:all .15s;white-space:nowrap;}
    .tab-btn:hover{background:#f1f5f9;color:#0f172a;}
    .tab-btn.active{background:linear-gradient(135deg,#10b981,#059669);color:#fff;box-shadow:0 2px 8px rgba(16,185,129,.3);}
    .tab-panel{display:none;}
    .tab-panel.active{display:block;}
    /* Class summary card */
    .class-conf-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px;margin-bottom:14px;cursor:pointer;transition:box-shadow .2s;}
    .class-conf-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);}
    .class-conf-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;}
    .class-conf-title{font-size:15px;font-weight:700;color:#0f172a;}
    .class-conf-sub{font-size:12px;color:#64748b;}
    .progress-bar-wrap{background:#f1f5f9;border-radius:99px;height:8px;overflow:hidden;margin-bottom:8px;}
    .progress-bar-fill{height:100%;border-radius:99px;background:linear-gradient(135deg,#10b981,#059669);transition:width .4s;}
    .conf-stats{display:flex;gap:16px;font-size:12px;font-weight:600;}
    .conf-stat-accepted{color:#166534;}
    .conf-stat-declined{color:#991b1b;}
    .conf-stat-pending{color:#92400e;}
    /* Recent responses */
    .resp-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #f1f5f9;}
    .resp-row:last-child{border-bottom:none;}
    .resp-av{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;flex-shrink:0;}
    .resp-info{flex:1;min-width:0;}
    .resp-name{font-size:13px;font-weight:600;color:#0f172a;}
    .resp-class{font-size:11px;color:#64748b;}
    .resp-badge{padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;flex-shrink:0;}
    .resp-badge.accepted{background:#dcfce7;color:#166534;}
    .resp-badge.declined{background:#fee2e2;color:#991b1b;}
    .resp-time{font-size:10px;color:#94a3b8;flex-shrink:0;}
    /* Detail modal */
    .detail-table{width:100%;border-collapse:collapse;}
    .detail-table th{padding:8px 12px;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #f1f5f9;text-align:left;}
    .detail-table td{padding:10px 12px;font-size:13px;color:#334155;border-bottom:1px solid #f8fafc;}
    .detail-table tbody tr:last-child td{border-bottom:none;}
    .empty-state{text-align:center;padding:48px 24px;color:#94a3b8;}
    .empty-state i{font-size:36px;margin-bottom:12px;display:block;opacity:.4;}
    footer.t-footer{text-align:center;padding:14px;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;background:#fff;}
    @media(max-width:768px){.t-main{margin-left:0;}.t-content{padding:16px 14px 40px;}}
  </style>
</head>
<body>
<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<aside class="t-sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo"><i class="fa fa-graduation-cap"></i></div>
    <h2>Cen<span>Learn</span></h2>
    <p>Learning Management System</p>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-sec">Main</div>
    <ul>
      <li><a href="dashboard.php"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li><a href="classes.php"><i class="fa fa-book"></i> Classes</a></li>
      <li><a href="quizzes.php"><i class="fa fa-question-circle"></i> Quizzes</a></li>
      <li><a href="assignments.php"><i class="fa fa-tasks"></i> Assignments</a></li>
      <li><a href="attendance.php"><i class="fa fa-calendar-check-o"></i> Attendance</a></li>
      <li><a href="logbook.php"><i class="fa fa-pencil-square-o"></i> Manage Subject</a></li>
      <li><a href="class_record.php"><i class="fa fa-table"></i> Class Record</a></li>
      <li><a href="subject_repository.php"><i class="fa fa-archive"></i> Past Subject Repository</a></li>
    </ul>
  </nav>
  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-av"><?php echo $initials; ?></div>
      <div class="sb-meta">
        <strong><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></strong>
        <span>Teacher</span>
      </div>
    </div>
    <a href="../logout.php" class="sb-out"><i class="fa fa-sign-out"></i> Sign Out</a>
  </div>
</aside>

<div class="t-main">
  <header class="t-topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
      <div>
        <h3>Notifications</h3>
        <p>Student schedule confirmations</p>
      </div>
    </div>
    <?php if($totalPending > 0): ?>
    <span style="background:#fef3c7;color:#92400e;padding:5px 14px;border-radius:99px;font-size:12px;font-weight:700;border:1px solid #fde68a;">
      <i class="fa fa-clock-o"></i> <?php echo $totalPending; ?> awaiting response
    </span>
    <?php endif; ?>
  </header>

  <div class="t-content">

    <!-- Stats -->
    <div class="stats-strip">
      <div class="stat-pill" onclick="filterRecent('pending')" style="cursor:pointer;">
        <div class="sp-icon" style="background:#fef3c7;"><i class="fa fa-clock-o" style="color:#92400e;"></i></div>
        <div><strong><?php echo $totalPending; ?></strong><span>Pending</span></div>
      </div>
      <div class="stat-pill" onclick="filterRecent('accepted')" style="cursor:pointer;">
        <div class="sp-icon" style="background:#dcfce7;"><i class="fa fa-check" style="color:#166534;"></i></div>
        <div><strong><?php echo $totalAccepted; ?></strong><span>Accepted</span></div>
      </div>
      <div class="stat-pill" onclick="filterRecent('declined')" style="cursor:pointer;">
        <div class="sp-icon" style="background:#fee2e2;"><i class="fa fa-times" style="color:#991b1b;"></i></div>
        <div><strong><?php echo $totalDeclined; ?></strong><span>Declined</span></div>
      </div>
      <div class="stat-pill" onclick="filterRecent('all')" style="cursor:pointer;">
        <div class="sp-icon" style="background:#eff6ff;"><i class="fa fa-book" style="color:#1792bb;"></i></div>
        <div><strong><?php echo count($classRows); ?></strong><span>Classes</span></div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tab-bar">
      <button class="tab-btn active" onclick="switchTab('tab-classes',this)"><i class="fa fa-book"></i> By Class</button>
      <button class="tab-btn" onclick="switchTab('tab-recent',this)"><i class="fa fa-history"></i> Recent Responses</button>
    </div>

    <!-- ── BY CLASS TAB ── -->
    <div class="tab-panel active" id="tab-classes">
      <?php if(empty($classRows)): ?>
      <div class="empty-state"><i class="fa fa-inbox"></i><p>No classes yet.</p></div>
      <?php else: ?>
      <?php foreach($classRows as $c):
        $total = max(1, $c['total_students']);
        $acc   = $c['accepted'];
        $dec   = $c['declined'];
        $pend  = $c['pending'];
        $pct   = $total > 0 ? round($acc / $total * 100) : 0;
        $schedArr = !empty($c['schedule_json']) ? json_decode($c['schedule_json'], true) : [];
      ?>
      <div class="class-conf-card">
        <div class="class-conf-hdr">
          <div>
            <div class="class-conf-title"><?php echo htmlspecialchars($c['class_name']); ?></div>
            <div class="class-conf-sub">
              <?php if($c['subject']): ?><?php echo htmlspecialchars($c['subject']); ?> &bull; <?php endif; ?>
              <?php if($c['year_level']): ?>Year <?php echo $c['year_level']; ?><?php endif; ?>
              <?php if($c['section']): ?> &bull; Sec <?php echo htmlspecialchars($c['section']); ?><?php endif; ?>
              <?php foreach($schedArr as $s): ?>
              &bull; <?php echo htmlspecialchars($s['day']); ?> <?php echo date('g:i A',strtotime($s['start'])); ?>–<?php echo date('g:i A',strtotime($s['end'])); ?>
              <?php endforeach; ?>
              <?php if($c['schedule_room']): ?> &bull; <?php echo htmlspecialchars($c['schedule_room']); ?><?php endif; ?>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:12px;font-weight:700;color:#64748b;"><?php echo $total; ?> students</span>
            <button onclick="viewDetail(<?php echo $c['id']; ?>,'<?php echo htmlspecialchars(addslashes($c['class_name'])); ?>')"
                    style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;">
              <i class="fa fa-eye"></i> View
            </button>
          </div>
        </div>
        <div class="progress-bar-wrap">
          <div class="progress-bar-fill" style="width:<?php echo $pct; ?>%;"></div>
        </div>
        <div class="conf-stats">
          <span class="conf-stat-accepted"><i class="fa fa-check-circle"></i> <?php echo $acc; ?> accepted</span>
          <span class="conf-stat-declined"><i class="fa fa-times-circle"></i> <?php echo $dec; ?> declined</span>
          <span class="conf-stat-pending"><i class="fa fa-clock-o"></i> <?php echo $pend; ?> pending</span>
          <span style="color:#64748b;margin-left:auto;"><?php echo $pct; ?>% confirmed</span>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- ── RECENT RESPONSES TAB ── -->
    <div class="tab-panel" id="tab-recent">
      <?php if(empty($recentRows)): ?>
      <div class="empty-state"><i class="fa fa-history"></i><p>No responses yet.</p></div>
      <?php else: ?>
      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:8px 20px;">
        <?php foreach($recentRows as $r):
          $init = strtoupper(substr($r['first_name'],0,1).substr($r['last_name'],0,1));
        ?>
        <div class="resp-row">
          <div class="resp-av"><?php echo $init; ?></div>
          <div class="resp-info">
            <div class="resp-name"><?php echo htmlspecialchars($r['first_name'].' '.$r['last_name']); ?></div>
            <div class="resp-class"><?php echo htmlspecialchars($r['class_name']); ?><?php if($r['subject']): ?> — <?php echo htmlspecialchars($r['subject']); ?><?php endif; ?></div>
          </div>
          <span class="resp-badge <?php echo $r['status']; ?>"><?php echo ucfirst($r['status']); ?></span>
          <span class="resp-time"><?php echo $r['responded_at'] ? date('M d, g:i A', strtotime($r['responded_at'])) : ''; ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
  <footer class="t-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<!-- Detail Modal -->
<div class="modal fade" id="detailModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;">
      <div style="padding:18px 22px;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#10b981,#059669);">
        <h4 id="detailTitle" style="color:#fff;font-size:15px;font-weight:700;margin:0;"></h4>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;font-size:20px;background:none;border:none;">&times;</button>
      </div>
      <div class="modal-body" style="padding:0;max-height:70vh;overflow-y:auto;">
        <div id="detailBody" style="padding:20px;">
          <div style="text-align:center;padding:32px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script src="../bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
function switchTab(tabId, btn){
  document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
  document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
  document.getElementById(tabId).classList.add('active');
  btn.classList.add('active');
  if(tabId === 'tab-recent'){
    document.querySelectorAll('.resp-row').forEach(function(row){
      row.style.display = '';
    });
  }
}

function filterRecent(status){
  if(status === 'all') {
    switchTab('tab-classes', document.querySelector('.tab-btn:nth-child(1)'));
    return;
  }
  
  switchTab('tab-recent', document.querySelector('.tab-btn:nth-child(2)'));
  
  document.querySelectorAll('.resp-row').forEach(function(row){
    var badge = row.querySelector('.resp-badge');
    if(badge){
      var isMatch = badge.classList.contains(status);
      row.style.display = isMatch ? '' : 'none';
    }
  });
}

function viewDetail(classId, className){
  document.getElementById('detailTitle').textContent = className + ' — Confirmations';
  document.getElementById('detailBody').innerHTML = '<div style="text-align:center;padding:32px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>';
  $('#detailModal').modal('show');
  $.get('../shared/confirmation_handler.php', {action:'summary', class_id:classId}, function(r){
    if(!r.success){ document.getElementById('detailBody').innerHTML = '<p style="color:#ef4444;padding:20px;">'+r.msg+'</p>'; return; }
    var html = '<div style="display:flex;gap:12px;padding:16px 20px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;">'
      + '<span style="background:#dcfce7;color:#166534;padding:5px 14px;border-radius:99px;font-size:12px;font-weight:700;"><i class="fa fa-check"></i> '+r.accepted+' Accepted</span>'
      + '<span style="background:#fee2e2;color:#991b1b;padding:5px 14px;border-radius:99px;font-size:12px;font-weight:700;"><i class="fa fa-times"></i> '+r.declined+' Declined</span>'
      + '<span style="background:#fef3c7;color:#92400e;padding:5px 14px;border-radius:99px;font-size:12px;font-weight:700;"><i class="fa fa-clock-o"></i> '+r.pending+' Pending</span>'
      + '</div>';
    html += '<table class="detail-table"><thead><tr><th>Student</th><th>ID</th><th>Section</th><th>Status</th><th>Responded</th></tr></thead><tbody>';
    r.students.forEach(function(s){
      var statusHtml = s.status === 'accepted'
        ? '<span style="background:#dcfce7;color:#166534;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;">Accepted</span>'
        : s.status === 'declined'
        ? '<span style="background:#fee2e2;color:#991b1b;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;">Declined</span>'
        : '<span style="background:#fef3c7;color:#92400e;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;">Pending</span>';
      html += '<tr>'
        + '<td style="font-weight:600;">'+s.first_name+' '+s.last_name+'</td>'
        + '<td style="font-size:11px;color:#64748b;">'+s.user_code+'</td>'
        + '<td>'+(s.section||'—')+'</td>'
        + '<td>'+statusHtml+'</td>'
        + '<td style="font-size:11px;color:#64748b;">'+(s.responded_at ? new Date(s.responded_at).toLocaleDateString() : '—')+'</td>'
        + '</tr>';
    });
    html += '</tbody></table>';
    document.getElementById('detailBody').innerHTML = html;
  }, 'json');
}

function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
</script>
</body>
</html>
