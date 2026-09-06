<?php
include '../includes/session.php';
include '../includes/conn.php';
include '../includes/programs.php';

$tc = $conn->real_escape_string($user['user_code']);

// 2. Fetch selected class (if any)
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$active_class = null;
if ($class_id > 0) {
    $class_res = $conn->query("SELECT * FROM classes WHERE id=$class_id AND teacher_code='$tc' LIMIT 1");
    if ($class_res && $class_res->num_rows > 0) {
        $active_class = $class_res->fetch_assoc();
    } else {
        $class_id = 0; // fallback if unauthorized
    }
}

// 3. Fetch active classes for teacher
$classes_query = $conn->query("
    SELECT c.*, COUNT(cm.id)-1 AS student_count
    FROM classes c 
    LEFT JOIN class_members cm ON c.id=cm.class_id
    WHERE c.teacher_code='$tc' AND (c.is_archived=0 OR c.is_archived IS NULL) AND c.is_subject_only = 1
    GROUP BY c.id ORDER BY c.class_name ASC
");
$classes = [];
while ($row = $classes_query->fetch_assoc()) {
    $classes[] = $row;
}

// 4. Statistics calculation
if ($class_id > 0) {
    // Stats for active class
    $total_logs = $conn->query("SELECT COUNT(*) as c FROM subject_logbook WHERE class_id=$class_id AND teacher_code='$tc'")->fetch_assoc()['c'];
    $duration_res = $conn->query("SELECT start_time, end_time FROM subject_logbook WHERE class_id=$class_id AND teacher_code='$tc'");
    $total_minutes = 0;
    while ($d = $duration_res->fetch_assoc()) {
        $start = strtotime($d['start_time']);
        $end = strtotime($d['end_time']);
        if ($end > $start) {
            $total_minutes += ($end - $start) / 60;
        }
    }
    $total_hours = round($total_minutes / 60, 1);
} else {
    // Overall stats
    $total_logs = $conn->query("SELECT COUNT(*) as c FROM subject_logbook WHERE teacher_code='$tc'")->fetch_assoc()['c'];
    
    $duration_res = $conn->query("SELECT start_time, end_time FROM subject_logbook WHERE teacher_code='$tc'");
    $total_minutes = 0;
    while ($d = $duration_res->fetch_assoc()) {
        $start = strtotime($d['start_time']);
        $end = strtotime($d['end_time']);
        if ($end > $start) {
            $total_minutes += ($end - $start) / 60;
        }
    }
    $total_hours = round($total_minutes / 60, 1);
}

// Fetch logbook entries for table/grid
$entries = [];
if ($class_id > 0) {
    $entries_res = $conn->query("SELECT * FROM subject_logbook WHERE class_id=$class_id AND teacher_code='$tc' ORDER BY log_date DESC, start_time DESC");
    while ($row = $entries_res->fetch_assoc()) {
        $entries[] = $row;
    }
}

$initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — <?php echo $class_id > 0 ? htmlspecialchars(($active_class['subject'] ?: $active_class['class_name']) . ' - ' . $active_class['class_code']) : 'Subject Log Book'; ?></title>
  <link rel="stylesheet" href="/cenlearn/system/bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/cenlearn/system/bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/cenlearn/system/dist/css/cenlearn.css">
  <style>
    *{box-sizing:border-box;}
    html,body{margin:0;padding:0;overflow-x:hidden;}
    body{font-family:'Inter',sans-serif;background:#f0f4f8;color:#1e293b;}
    
    /* Navigation Sidebar */
    /* Navigation Sidebar */
    .td-sidebar{position:fixed;top:0;left:0;width:240px;height:100vh;background:linear-gradient(180deg,#0a1f0f 0%,#0d3320 55%,#065f46 100%);display:flex;flex-direction:column;z-index:200;transition:transform .25s cubic-bezier(.4,0,.2,1);transform:translateX(-240px);}
    .td-sidebar.open{transform:translateX(0);}
    @media(min-width:901px){.td-sidebar{transform:translateX(0);}}
    .sb-brand{padding:18px 18px 14px;border-bottom:1px solid rgba(255,255,255,.08);}
    .sb-logo{width:34px;height:34px;border-radius:8px;background:linear-gradient(135deg,#10b981,#059669);display:inline-flex;align-items:center;justify-content:center;margin-bottom:6px;box-shadow:0 3px 10px rgba(16,185,129,.35);}
    .sb-logo i{color:#fff;font-size:15px;}
    .sb-brand h2{color:#fff;font-size:16px;font-weight:800;margin:0;}
    .sb-brand h2 span{color:#34d399;}
    .sb-brand p{color:rgba(255,255,255,.35);font-size:9.5px;margin:2px 0 0;}
    .sb-nav{flex:1;padding:10px 0;overflow-y:auto;}
    .sb-section{padding:8px 18px 4px;font-size:9px;font-weight:700;color:rgba(255,255,255,.25);letter-spacing:1.4px;text-transform:uppercase;}
    .sb-nav ul{list-style:none;margin:0;padding:0;}
    .sb-nav li a{display:flex;align-items:center;gap:10px;padding:9px 18px;color:rgba(255,255,255,.6);text-decoration:none;font-size:12.5px;font-weight:500;transition:all .18s;border-left:3px solid transparent;}
    .sb-nav li a:hover{background:rgba(255,255,255,.07);color:#fff;}
    .sb-nav li.active a{background:rgba(52,211,153,.12);color:#fff;border-left-color:#34d399;}
    .sb-nav li a i{width:17px;text-align:center;font-size:14px;}
    .sb-footer{padding:12px 18px;border-top:1px solid rgba(255,255,255,.07);}
    .sb-user{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
    .sb-av{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;flex-shrink:0;}
    .sb-meta strong{display:block;color:#fff;font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;}
    .sb-meta span{color:rgba(255,255,255,.4);font-size:9.5px;}
    .sb-out{display:flex;align-items:center;justify-content:center;gap:6px;padding:7px;width:100%;background:rgba(255,255,255,.07);color:rgba(255,255,255,.6);border:1px solid rgba(255,255,255,.1);border-radius:6px;font-size:11.5px;font-weight:500;text-decoration:none;transition:background .2s;}
    .sb-out:hover{background:rgba(255,255,255,.13);color:#fff;}
    
    /* Layout Main */
    .td-main{margin-left:0;min-height:100vh;display:flex;flex-direction:column;transition:margin 0s;}
    @media(min-width:901px){.td-main{margin-left:240px;}}
    .td-topbar{background:#fff;padding:8px 18px;height:auto;min-height:52px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:50;box-shadow:0 1px 4px rgba(0,0,0,.04);flex-wrap:wrap;gap:8px;}
    .td-topbar-title h3{font-size:15px;font-weight:700;color:#0f172a;margin:0;}
    .td-topbar-title p{font-size:11px;color:#64748b;margin:0;}
    .td-content{padding:18px 20px 40px;flex:1;}
    footer.td-footer{text-align:center;padding:14px;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;background:#fff;}
    
    /* Header components */
    .back-btn{display:inline-flex;align-items:center;gap:6px;color:#64748b;font-size:12.5px;font-weight:600;text-decoration:none;margin-bottom:14px;transition:color .15s;}
    .back-btn:hover{color:#059669;text-decoration:none;}
    
    /* Stat grid */
    .stats-strip{display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap;}
    .stat-pill{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 16px;flex:1;min-width:160px;box-shadow:0 1px 3px rgba(0,0,0,.02);}
    .sp-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .sp-icon i{font-size:16px;}
    .stat-pill strong{display:block;font-size:20px;font-weight:800;color:#0f172a;line-height:1;margin-bottom:2px;}
    .stat-pill span{font-size:9.5px;color:#64748b;font-weight:500;text-transform:uppercase;letter-spacing:.4px;}
    
    /* Subject Row Cards matching requested mockup style */
    .subject-row-card {
      background: #ffffff;
      border: 1.5px solid #e2e8f0;
      border-radius: 10px;
      padding: 10px 16px;
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02), 0 1px 2px rgba(0, 0, 0, 0.02);
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .subject-row-card:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 18px rgba(0, 0, 0, 0.05);
      border-color: #cbd5e1;
    }
    .src-left {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }
    .src-icon {
      width: 36px;
      height: 36px;
      border-radius: 8px;
      background: linear-gradient(135deg, #10b981, #059669);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ffffff;
      font-size: 15px;
      flex-shrink: 0;
      box-shadow: 0 2px 6px rgba(16, 185, 129, 0.2);
    }
    .src-info {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .src-title {
      font-size: 14px;
      font-weight: 700;
      color: #0f172a;
      margin: 0;
      letter-spacing: -0.2px;
    }
    .src-sub {
      font-size: 12px;
      color: #64748b;
      font-weight: 500;
    }
    .badge-code-green {
      background: #dcfce7;
      color: #15803d;
      font-size: 10px;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 5px;
      font-family: monospace;
      letter-spacing: 0.5px;
      border: 1px solid #bbf7d0;
    }
    .badge-prog-blue {
      background: #e0f2fe;
      color: #0369a1;
      font-size: 10px;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 5px;
      border: 1px solid #bae6fd;
    }
    .btn-delete-subject {
      background: #fef2f2;
      color: #dc2626;
      border: 1.5px solid #f87171;
      padding: 5px 12px;
      border-radius: 7px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      transition: all 0.15s ease-in-out;
    }
    .btn-delete-subject:hover {
      background: #fee2e2;
      transform: translateY(-1px);
    }
    .btn-delete-subject.icon-only {
      width: 28px;
      height: 28px;
      padding: 0;
      justify-content: center;
      font-size: 13px;
    }
    
    /* Tables & Timeline */
    .log-card{background:#fff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.03);margin-bottom:20px;}
    .log-card-hdr{padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:between;flex-wrap:wrap;gap:12px;}
    .log-card-hdr h4{font-size:14px;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:8px;}
    
    .table-responsive{margin:0;}
    .log-table{width:100%;border-collapse:collapse;font-size:13px;}
    .log-table th{background:#f8fafc;color:#475569;font-weight:700;text-transform:uppercase;font-size:11px;letter-spacing:.6px;padding:12px 20px;text-align:left;border-bottom:1px solid #e2e8f0;}
    .log-table td{padding:14px 20px;border-bottom:1px solid #f1f5f9;color:#334155;vertical-align:top;}
    .log-table tr:last-child td{border-bottom:none;}
    .log-table tr:hover td{background:#f8fafc;}
    
    .btn-green-sm{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;text-decoration:none;transition:opacity .2s;}
    .btn-green-sm:hover{opacity:.88;color:#fff;}
    .btn-outline-sm{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;background:#fff;color:#475569;border:1.5px solid #cbd5e1;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;}
    .btn-outline-sm:hover{background:#f8fafc;border-color:#94a3b8;color:#1e293b;text-decoration:none;}
    
    .badge-time{background:#eff6ff;color:#1d4ed8;padding:3px 8px;border-radius:6px;font-weight:600;font-size:11px;}
    .badge-hours{background:#f0fdf4;color:#166534;padding:3px 8px;border-radius:6px;font-weight:600;font-size:11px;}
    
    .empty-state{text-align:center;padding:52px 24px;background:#fff;border-radius:16px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.02);}
    .empty-icon{width:64px;height:64px;border-radius:50%;background:#f0fdf4;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;}
    .empty-icon i{font-size:24px;color:#10b981;}
    .empty-state h5{font-size:15px;font-weight:700;color:#334155;margin:0 0 6px;}
    .empty-state p{font-size:13px;color:#64748b;margin:0 0 16px;}
    
    /* Form elements */
    .fc{width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;background:#f9fafb;color:#1e293b;transition:all .2s;}
    .fc:focus{outline:none;border-color:#10b981;background:#fff;box-shadow:0 0 0 3px rgba(16,185,129,.1);}
    
    /* Print Layout adjustments */
    .print-report{display:none;}
    
    @media print{
      body{background:#fff;color:#000;font-family:sans-serif;}
      .td-sidebar, .td-topbar, .td-content > :not(.print-report), footer, .modal-backdrop, .modal{display:none !important;}
      .td-main{margin-left:0 !important;min-height:0 !important;}
      .td-content{padding:0 !important;}
      .print-report{display:block !important;padding:20px;}
      .print-header{text-align:center;margin-bottom:30px;border-bottom:2px solid #000;padding-bottom:15px;}
      .print-header h1{font-size:24px;margin:0 0 5px;font-weight:bold;text-transform:uppercase;}
      .print-header h2{font-size:16px;margin:0 0 5px;font-weight:normal;}
      .print-info{display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:25px;font-size:13px;}
      .print-info div span{font-weight:bold;margin-right:5px;}
      .print-table{width:100%;border-collapse:collapse;margin-bottom:40px;font-size:12px;}
      .print-table th, .print-table td{border:1px solid #000;padding:10px;text-align:left;}
      .print-table th{background:#f0f0f0 !important;font-weight:bold;-webkit-print-color-adjust:exact;}
      .print-signatures{display:grid;grid-template-columns:1fr 1fr;gap:50px;margin-top:50px;}
      .sig-block{text-align:center;}
      .sig-line{border-top:1px solid #000;margin-top:40px;padding-top:5px;font-weight:bold;}
    }
  </style>
</head>
<body>
<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- Navigation Sidebar -->
<aside class="td-sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo"><i class="fa fa-graduation-cap"></i></div>
    <h2>Cen<span>Learn</span></h2>
    <p>Learning Management System</p>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Teacher Menu</div>
    <ul>
      <li><a href="dashboard"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li><a href="classes"><i class="fa fa-book"></i> Classes</a></li>
      <li><a href="quizzes"><i class="fa fa-question-circle"></i> Quizzes</a></li>
      <li><a href="assignments"><i class="fa fa-tasks"></i> Assignments</a></li>
      <li><a href="attendance"><i class="fa fa-calendar-check-o"></i> Attendance</a></li>
      <li class="active"><a href="logbook"><i class="fa fa-pencil-square-o"></i> Manage Subject</a></li>
      <li><a href="class_record"><i class="fa fa-table"></i> Class Record</a></li>
      <li><a href="subject_repository"><i class="fa fa-archive"></i> Past Subject Repository</a></li>
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
    <a href="/cenlearn/logout" class="sb-out"><i class="fa fa-sign-out"></i> Sign Out</a>
  </div>
</aside>

<div class="td-main">
  <header class="td-topbar" style="display:flex; align-items:center; justify-content:space-between; padding:0 28px; height:62px; background:#fff; border-bottom:1px solid #e2e8f0; position:sticky; top:0; z-index:50;">
    <div style="display:flex; align-items:center; gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
    </div>
  </header>

  <div class="td-content">
      <!-- Subjects Dashboard View -->
      <div style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
          <h4 style="font-size:16px; font-weight:700; color:#0f172a; margin:0;"><i class="fa fa-university" style="color:#10b981; margin-right:8px;"></i>Manage Subject</h4>
        </div>
        <button class="btn-green-sm" data-toggle="modal" data-target="#createSubjectModal" style="padding:9px 18px; font-size:13px;"><i class="fa fa-plus-circle"></i> Create Subject</button>
      </div>

      <?php if (empty($classes)): ?>
        <div class="empty-state">
          <div class="empty-icon"><i class="fa fa-folder-open-o"></i></div>
          <h5>No Subjects Found</h5>
          <p>Create your first subject to start managing teaching logs.</p>
          <button class="btn-green-sm" data-toggle="modal" data-target="#createSubjectModal"><i class="fa fa-plus"></i> Create Subject</button>
        </div>
      <?php else: ?>
        <div id="subjectsList">
          <?php foreach ($classes as $idx => $class): ?>
            <div class="subject-row-card">
              <div class="src-left">
                <div class="src-icon"><i class="fa fa-book"></i></div>
                <div class="src-info">
                  <h5 class="src-title"><?php echo htmlspecialchars($class['class_name']); ?></h5>
                  <?php if (!empty($class['subject']) && $class['subject'] !== $class['class_name']): ?>
                    <span class="src-sub"><?php echo htmlspecialchars($class['subject']); ?></span>
                  <?php endif; ?>
                  <span class="badge-code-green"><?php echo htmlspecialchars($class['class_code']); ?></span>
                  <?php if (!empty($class['program_code'])): ?>
                    <span class="badge-prog-blue"><?php echo htmlspecialchars($class['program_code']); ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="src-actions">
                <button class="btn-delete-subject icon-only" onclick="deleteSubject(<?php echo $class['id']; ?>, '<?php echo htmlspecialchars(addslashes($class['class_name'])); ?>')" title="Delete Subject">
                  <i class="fa fa-trash"></i>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
  </div>

  <footer class="td-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<!-- Create Subject Modal -->
<div class="modal fade" id="createSubjectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.15);">
      <form id="createSubjectForm">
        <input type="hidden" name="action" value="create_subject">
        
        <div style="padding:18px 22px;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#10b981,#059669);">
          <h4 style="color:#fff;font-size:15px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px;"><i class="fa fa-plus-circle"></i> Create New Subject</h4>
          <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;font-size:20px;background:none;border:none;">&times;</button>
        </div>
        
        <div class="modal-body" style="padding:22px 24px;">
          <div id="subjectAlert" style="display:none;margin-bottom:14px;padding:10px 14px;border-radius:9px;font-size:13px;display:flex;align-items:flex-start;gap:8px;"></div>
          
          <div style="margin-bottom:14px;">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Course / Program</label>
            <select name="program_code" id="subj_program" class="fc" style="width:100%;">
              <option value="">— Select Course / Program (Optional) —</option>
              <option value="IS">IS — Information Systems</option>
              <option value="CRIM">CRIM — Criminology</option>
              <option value="ARTS">ARTS — Arts (BSOA, AB)</option>
              <option value="EDUCATION">EDUCATION — Education (BEED, BSED, BPED)</option>
            </select>
          </div>

          <div style="margin-bottom:14px;">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Subject Code <span style="color:#ef4444;">*</span></label>
            <input type="text" name="class_code" id="subj_code" class="fc" placeholder="e.g. IT-302, COMSCI1" required maxlength="10" style="text-transform:uppercase;">
            <small style="display:block;color:#94a3b8;margin-top:4px;font-size:10px;">3-10 characters, alphanumeric or hyphens. Used for student enrollment.</small>
          </div>
          
          <div style="margin-bottom:0;">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Subject Name <span style="color:#ef4444;">*</span></label>
            <input type="text" name="subject_name" id="subj_name" class="fc" placeholder="e.g. Web Development, Database Systems" required maxlength="100" style="text-transform:uppercase;">
          </div>
        </div>
        
        <div style="padding:16px 24px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px;background:#f8fafc;">
          <button type="button" style="padding:10px 18px;background:#fff;color:#475569;border:1.5px solid #cbd5e1;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;" data-dismiss="modal">Cancel</button>
          <button type="submit" id="btnCreateSubject" style="padding:10px 22px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;"><i class="fa fa-save"></i> Create Subject</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include '../includes/scripts.php'; ?>
<script>
function openSidebar(){ 
  document.getElementById('sidebar').classList.add('open'); 
  document.getElementById('sidebarOverlay').classList.add('active'); 
}
function closeSidebar(){ 
  document.getElementById('sidebar').classList.remove('open'); 
  document.getElementById('sidebarOverlay').classList.remove('active'); 
}

function deleteSubject(id, name) {
  if (!confirm('Are you sure you want to delete the subject "' + name + '"?')) return;
  $.post('/cenlearn/shared/class_delete', {class_id: id}, function(res) {
    if (res.success) {
      location.reload();
    } else {
      alert(res.msg || 'Failed to delete subject.');
    }
  }, 'json');
}



// Automatically capitalize subject name and strip spaces from subject code
$('#subj_name').on('input', function() {
  this.value = this.value.toUpperCase();
});
$('#subj_code').on('input', function() {
  this.value = this.value.toUpperCase().replace(/\s/g, '');
});

$('#createSubjectForm').on('submit', function(e) {
  e.preventDefault();
  
  var code = $('#subj_code').val().trim();
  var name = $('#subj_name').val().trim();
  
  if (!code || !name) {
    showSubjectAlert('All fields are required.', 'danger');
    return;
  }
  
  if (!/^[A-Za-z0-9\-]{3,10}$/.test(code)) {
    showSubjectAlert('Subject code must be 3-10 alphanumeric characters or hyphens.', 'danger');
    return;
  }
  
  $('#btnCreateSubject').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Creating...');
  
  $.ajax({
    url: '/cenlearn/shared/logbook_handler',
    type: 'POST',
    data: $(this).serialize(),
    dataType: 'json',
    success: function(res) {
      $('#btnCreateSubject').prop('disabled', false).html('<i class="fa fa-save"></i> Create Subject');
      if (res.success) {
        showSubjectAlert(res.msg, 'success');
        setTimeout(function() {
          $('#createSubjectModal').modal('hide');
          location.reload();
        }, 1200);
      } else {
        showSubjectAlert(res.msg, 'danger');
      }
    },
    error: function() {
      $('#btnCreateSubject').prop('disabled', false).html('<i class="fa fa-save"></i> Create Subject');
      showSubjectAlert('An unexpected error occurred. Please try again.', 'danger');
    }
  });
});

function showSubjectAlert(msg, type) {
  var alertEl = $('#subjectAlert');
  alertEl.removeClass('success danger')
         .addClass(type)
         .html('<i class="fa fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> <span>' + msg + '</span>')
         .css('display', 'flex');
}
</script>
</body>
</html>
