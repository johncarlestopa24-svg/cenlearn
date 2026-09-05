<?php
include '../includes/session.php';
include '../includes/conn.php';

$tc = $conn->real_escape_string($user['user_code']);

// Active classes (High-Speed Single Pass JOIN)
$classes = $conn->query("
    SELECT c.*, COALESCE(cm.student_count, 0) AS student_count,
           c.class_code AS display_code
    FROM classes c 
    LEFT JOIN (
        SELECT cm.class_id, COUNT(DISTINCT cm.user_code) AS student_count 
        FROM class_members cm
        JOIN users u ON cm.user_code = u.user_code
        WHERE u.user_group = 'STUDENT'
        GROUP BY cm.class_id
    ) cm ON cm.class_id = c.id
    WHERE c.teacher_code='$tc' AND (c.is_archived=0 OR c.is_archived IS NULL) AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)
    ORDER BY c.id DESC
");
$totalClasses = $classes ? $classes->num_rows : 0;
$classRows = [];
if($classes) while($r = $classes->fetch_assoc()) $classRows[] = $r;
$totalStudents = (int)($conn->query("SELECT COUNT(DISTINCT cm.user_code) AS c FROM class_members cm JOIN classes c ON cm.class_id=c.id JOIN users u ON cm.user_code=u.user_code WHERE c.teacher_code='$tc' AND u.user_group='STUDENT' AND (c.is_archived=0 OR c.is_archived IS NULL) AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)")->fetch_assoc()['c'] ?? 0);

// Archived classes
$archived = $conn->query("
    SELECT c.*, COALESCE(cm.student_count, 0) AS student_count,
           c.class_code AS display_code
    FROM classes c 
    LEFT JOIN (
        SELECT cm.class_id, COUNT(DISTINCT cm.user_code) AS student_count 
        FROM class_members cm
        JOIN users u ON cm.user_code = u.user_code
        WHERE u.user_group = 'STUDENT'
        GROUP BY cm.class_id
    ) cm ON cm.class_id = c.id
    WHERE c.teacher_code='$tc' AND c.is_archived=1 AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)
    ORDER BY c.id DESC
");
$archivedRows = [];
if($archived) while($r = $archived->fetch_assoc()) $archivedRows[] = $r;

$subjects_query = $conn->query("
    SELECT id, class_code, subject, class_name, program_code FROM classes 
    WHERE teacher_code='$tc' AND is_subject_only=1 
    ORDER BY subject ASC
");
$managed_subjects = [];
if ($subjects_query) {
    while($row = $subjects_query->fetch_assoc()) {
        $managed_subjects[] = $row;
    }
}

include '../includes/programs.php';

$sectionsQ = $conn->query("SELECT DISTINCT section FROM users WHERE section!='' AND section IS NOT NULL AND user_group='STUDENT' ORDER BY section");
$sections = [];
while($s = $sectionsQ->fetch_assoc()) $sections[] = $s['section'];

$initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
$palette  = [
  'linear-gradient(135deg,#10b981,#059669)',
  'linear-gradient(135deg,#1792bb,#0f5f80)',
  'linear-gradient(135deg,#8b5cf6,#6d28d9)',
  'linear-gradient(135deg,#f59e0b,#d97706)',
  'linear-gradient(135deg,#ef4444,#dc2626)',
  'linear-gradient(135deg,#06b6d4,#0891b2)',
  'linear-gradient(135deg,#ec4899,#db2777)',
];
$icons = ['fa-calculator','fa-flask','fa-book','fa-globe','fa-code','fa-pencil','fa-music'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CenLearn - Classes</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    *,*::before,*::after{box-sizing:border-box;}
    html,body{margin:0;padding:0;overflow-x:hidden;}
    body{font-family:'Inter',sans-serif;background:#f0f4f8;color:#1e293b;}
    .t-sidebar{position:fixed;top:0;left:0;width:240px;height:100vh;background:linear-gradient(180deg,#0f2027 0%,#203a43 55%,#2c5364 100%);display:flex;flex-direction:column;z-index:200;transition:transform .25s cubic-bezier(.4,0,.2,1);transform:translateX(-240px);}
    .t-sidebar.open{transform:translateX(0);}
    @media(min-width: 901px) { .t-sidebar{transform:translateX(0);} }
    .sb-brand{padding:18px 18px 14px;border-bottom:1px solid rgba(255,255,255,.07);}
    .sb-logo{width:34px;height:34px;border-radius:8px;background:linear-gradient(135deg,#10b981,#059669);display:inline-flex;align-items:center;justify-content:center;margin-bottom:6px;box-shadow:0 3px 10px rgba(16,185,129,.35);}
    .sb-logo i{color:#fff;font-size:15px;}
    .sb-brand h2{color:#fff;font-size:16px;font-weight:800;margin:0;}
    .sb-brand h2 span{color:#10b981;}
    .sb-brand p{color:rgba(255,255,255,.3);font-size:9.5px;margin:2px 0 0;}
    .sb-nav{flex:1;padding:10px 0;overflow-y:auto;}
    .sb-nav-sec{padding:8px 18px 4px;font-size:9px;font-weight:700;color:rgba(255,255,255,.22);letter-spacing:1.5px;text-transform:uppercase;}
    .sb-nav ul{list-style:none;margin:0;padding:0;}
    .sb-nav li a{display:flex;align-items:center;gap:10px;padding:9px 18px;color:rgba(255,255,255,.55);text-decoration:none;font-size:12.5px;font-weight:500;transition:all .18s;border-left:3px solid transparent;}
    .sb-nav li a:hover{background:rgba(255,255,255,.06);color:#fff;}
    .sb-nav li.active a{background:rgba(16,185,129,.15);color:#fff;border-left-color:#10b981;}
    .sb-nav li a i{width:16px;text-align:center;font-size:13px;}
    .sb-submenu{list-style:none;padding:0;margin:0;background:rgba(0,0,0,0.15);border-left:3px solid rgba(16,185,129,0.3);}
    .sb-submenu li a{padding:7px 16px 7px 34px !important;font-size:11.5px !important;color:rgba(255,255,255,0.6) !important;border-left:none !important;}
    .sb-submenu li a:hover{color:#fff !important;background:rgba(255,255,255,0.05) !important;}
    .sb-submenu li.active a{color:#fff !important;background:rgba(16,185,129,0.15) !important;font-weight:700;}
    .sb-footer{padding:12px 18px;border-top:1px solid rgba(255,255,255,.07);}
    .sb-user{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
    .sb-av{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;flex-shrink:0;}
    .sb-meta strong{display:block;color:#fff;font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;}
    .sb-meta span{color:rgba(255,255,255,.38);font-size:9.5px;}
    .sb-out{display:flex;align-items:center;justify-content:center;gap:6px;padding:7px;width:100%;background:rgba(255,255,255,.06);color:rgba(255,255,255,.55);border:1px solid rgba(255,255,255,.1);border-radius:6px;font-size:11.5px;font-weight:500;text-decoration:none;transition:all .18s;}
    .sb-out:hover{background:rgba(255,255,255,.12);color:#fff;}
    .t-main{margin-left:0;min-height:100vh;display:flex;flex-direction:column;transition:margin 0s;}
    @media(min-width: 901px) { .t-main{margin-left:240px;} }
    .t-topbar{background:#fff;padding:0 28px;height:62px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:50;box-shadow:0 1px 4px rgba(0,0,0,.04);}
    .t-topbar h3{font-size:16px;font-weight:700;color:#0f172a;margin:0;}
    .t-topbar p{font-size:12px;color:#64748b;margin:0;}
    .t-content{padding:26px 28px 52px;flex:1;}
    .top-stats-card {
      background: #ffffff;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      padding: 12px 18px;
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      margin-bottom: 18px;
      box-shadow: 0 3px 14px rgba(0, 0, 0, 0.02);
    }
    .stat-item {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .stat-item:not(:last-child) {
      border-right: 1px solid #f1f5f9;
      padding-right: 14px;
    }
    .stat-item:not(:first-child) {
      padding-left: 14px;
    }
    .stat-circle {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 15px;
      flex-shrink: 0;
    }
    .stat-info span {
      display: block;
      font-size: 11.5px;
      font-weight: 600;
      color: #64748b;
    }
    .stat-info strong {
      font-size: 18px;
      font-weight: 800;
      line-height: 1.2;
    }

    /* Filter & Tab bar container */
    .filter-tabs-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 24px;
      flex-wrap: wrap;
    }
    .search-box-modern {
      position: relative;
      flex: 1;
      min-width: 280px;
    }
    .search-box-modern input {
      width: 100%;
      padding: 11px 16px 11px 42px;
      border: 1.5px solid #e2e8f0;
      border-radius: 11px;
      font-size: 13px;
      font-family: 'Inter', sans-serif;
      background: #ffffff;
      color: #1e293b;
      box-shadow: 0 2px 6px rgba(0,0,0,0.02);
      transition: all 0.2s;
    }
    .search-box-modern input:focus {
      outline: none;
      border-color: #10b981;
      box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.12);
    }
    .search-box-modern i {
      position: absolute;
      left: 15px;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
      font-size: 14px;
    }

    .tabs-wrapper {
      display: flex;
      background: #ffffff;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      padding: 4px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.02);
    }
    .tab-btn-modern {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 18px;
      border: none;
      background: transparent;
      border-radius: 9px;
      font-size: 13px;
      font-weight: 600;
      color: #64748b;
      cursor: pointer;
      font-family: 'Inter', sans-serif;
      transition: all 0.15s;
    }
    .tab-btn-modern:hover {
      color: #0f172a;
    }
    .tab-btn-modern.active {
      color: #10b981;
      background: #ffffff;
      box-shadow: 0 2px 6px rgba(0,0,0,0.04);
      border-bottom: 2px solid #10b981;
    }
    .tab-btn-modern .badge-num {
      padding: 2px 8px;
      border-radius: 99px;
      font-size: 11px;
      font-weight: 700;
    }
    .tab-btn-modern.active .badge-num {
      background: #10b981;
      color: #ffffff;
    }
    .tab-btn-modern:not(.active) .badge-num {
      background: #e2e8f0;
      color: #64748b;
    }

    /* Horizontal Class Row Cards with modern comfortable styling */
    .class-row-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 10px 14px;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 2px 6px rgba(15, 23, 42, 0.02);
      transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .class-row-card:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 18px rgba(16, 185, 129, 0.08), 0 2px 4px rgba(15, 23, 42, 0.04);
      border-color: #a7f3d0;
    }
    .crc-left {
      display: flex;
      align-items: center;
      gap: 10px;
      min-width: 0;
      flex: 1;
    }
    .crc-icon {
      width: 36px;
      height: 36px;
      border-radius: 8px;
      background: linear-gradient(135deg, #10b981, #059669);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ffffff;
      font-size: 14px;
      flex-shrink: 0;
      box-shadow: 0 3px 10px rgba(16, 185, 129, 0.22);
    }
    .crc-info {
      min-width: 0;
      flex: 1;
    }
    .crc-info h5 {
      font-size: 13.5px;
      font-weight: 700;
      color: #0f172a;
      margin: 0 0 2px 0;
      letter-spacing: -0.1px;
      line-height: 1.3;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .crc-meta {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 11.5px;
      color: #475569;
      font-weight: 500;
      flex-wrap: wrap;
    }
    .crc-meta .dot {
      color: #cbd5e1;
      font-size: 7px;
    }
    .code-badge-green {
      background: #ecfdf5;
      color: #047857;
      font-size: 10.5px;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 5px;
      font-family: 'Inter', sans-serif;
      cursor: pointer;
      border: 1px solid #a7f3d0;
      transition: all 0.15s ease;
      display: inline-flex;
      align-items: center;
      gap: 3px;
    }
    .code-badge-green:hover {
      background: #d1fae5;
      border-color: #6ee7b7;
      color: #065f46;
      transform: translateY(-1px);
    }
    .student-badge-blue {
      background: #eff6ff;
      color: #1d4ed8;
      font-size: 10.5px;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 5px;
      border: 1px solid #bfdbfe;
      display: inline-flex;
      align-items: center;
      gap: 3px;
      transition: all 0.15s ease;
    }
    .crc-actions {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-shrink: 0;
    }
    .crc-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 5px;
      padding: 5px 12px;
      border-radius: 6px;
      font-size: 11.5px;
      font-weight: 600;
      font-family: 'Inter', sans-serif;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.15s ease-in-out;
      border: none;
      min-height: 32px;
    }
    .crc-btn:hover {
      text-decoration: none;
      transform: translateY(-1px);
    }
    .crc-btn.icon-only {
      width: 30px;
      height: 30px;
      padding: 0;
      font-size: 11.5px;
      border-radius: 6px;
      min-height: 30px;
    }
    .crc-btn.open-btn {
      background: linear-gradient(135deg, #10b981, #059669);
      color: #ffffff;
      box-shadow: 0 2px 6px rgba(16, 185, 129, 0.2);
      font-weight: 700;
    }
    .crc-btn.open-btn:hover {
      opacity: .92;
      box-shadow: 0 3px 10px rgba(16, 185, 129, 0.3);
      color: #ffffff;
    }
    .crc-btn.open-btn.close-state {
      background: linear-gradient(135deg, #64748b, #475569);
      box-shadow: 0 2px 8px rgba(100, 116, 139, 0.22);
    }
    .crc-btn.open-btn.close-state:hover {
      box-shadow: 0 4px 12px rgba(100, 116, 139, 0.32);
    }
    .crc-btn.record-btn {
      border: 1px solid #ddd6fe;
      background: #f5f3ff;
      color: #7c3aed;
    }
    .crc-btn.record-btn:hover {
      background: #ede9fe;
      border-color: #c4b5fd;
    }
    .crc-btn.leave-btn {
      border: 1px solid #fed7aa;
      background: #fff7ed;
      color: #ea580c;
    }
    .crc-btn.leave-btn:hover {
      background: #ffedd5;
      border-color: #fdba74;
    }
    .crc-btn.archive-btn {
      border: 1px solid #fde68a;
      background: #fffbeb;
      color: #d97706;
    }
    .crc-btn.archive-btn:hover {
      background: #fef3c7;
      border-color: #fcd34d;
    }
    .crc-btn.delete-btn {
      border: 1px solid #fecaca;
      background: #fef2f2;
      color: #dc2626;
    }
    .crc-btn.delete-btn:hover {
      background: #fee2e2;
      border-color: #fca5a5;
    }
    .tab-panel{display:none;}
    .tab-panel.active{display:block;}

    .btn-primary-t{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;transition:opacity .18s;box-shadow:0 4px 14px rgba(16,185,129,.25);}
    .btn-primary-t:hover{opacity:.88;color:#fff;}
    .empty-state{text-align:center;padding:72px 24px;background:#fff;border-radius:18px;border:1px solid #e2e8f0;box-shadow:0 4px 16px rgba(0,0,0,.03);}
    .empty-icon{width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,rgba(16,185,129,.08),rgba(16,185,129,.03));border:2px dashed rgba(16,185,129,.22);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;}
    .empty-icon i{font-size:32px;color:rgba(16,185,129,.45);}
    .empty-state h5{font-size:17px;font-weight:700;color:#374151;margin:0 0 8px;}
    .empty-state p{font-size:13px;color:#94a3b8;margin:0 0 20px;}
    .m-alert{padding:10px 14px;border-radius:9px;font-size:13px;display:flex;align-items:flex-start;gap:8px;margin-top:12px;}
    .m-alert.success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
    .m-alert.danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
    footer.t-footer{text-align:center;padding:14px;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;background:#fff;}

    @media(max-width: 768px) {
      .t-topbar {
        padding: 8px 12px !important;
        height: auto !important;
        min-height: 52px !important;
        flex-wrap: wrap !important;
        gap: 8px !important;
      }
      .t-topbar p {
        display: none !important;
      }
      .t-topbar .btn-primary-t {
        padding: 6px 12px !important;
        font-size: 12px !important;
        min-height: 32px !important;
        gap: 4px !important;
      }
      .top-stats-card {
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 6px !important;
        padding: 10px 8px !important;
        margin-bottom: 14px !important;
      }
      .stat-item {
        flex-direction: column !important;
        text-align: center !important;
        gap: 4px !important;
      }
      .stat-item:not(:last-child) {
        border-right: none !important;
        border-bottom: none !important;
        padding-right: 0 !important;
        padding-bottom: 0 !important;
      }
      .stat-item:not(:first-child) {
        padding-left: 0 !important;
      }
      .stat-circle {
        width: 32px !important;
        height: 32px !important;
        font-size: 13px !important;
        margin: 0 auto !important;
      }
      .stat-info span {
        font-size: 10px !important;
      }
      .stat-info strong {
        font-size: 15px !important;
      }
      .search-box-modern {
        min-width: 100% !important;
      }
      .tabs-wrapper {
        width: 100% !important;
      }
      .tab-btn-modern {
        flex: 1 !important;
        justify-content: center !important;
        padding: 7px 8px !important;
        font-size: 12px !important;
      }
      .class-row-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
        padding: 10px 12px;
      }
      .crc-left {
        width: 100%;
      }
      .crc-actions {
        width: 100%;
        justify-content: flex-start;
        flex-wrap: wrap;
        gap: 6px;
        border-top: 1px dashed #f1f5f9;
        padding-top: 8px;
        margin-top: 2px;
      }
      .crc-btn.open-btn {
        flex: 1;
        justify-content: center;
      }
    }
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
      <li class="active">
        <a href="classes.php"><i class="fa fa-book"></i> Classes</a>
        <ul class="sb-submenu" id="classSubmenu" style="display: none;">
          <li><a href="#" id="subMaterials"><i class="fa fa-folder-open"></i> Materials</a></li>
          <li><a href="#" id="subClasswork"><i class="fa fa-tasks"></i> Classwork</a></li>
          <li><a href="#" id="subLiveClass"><i class="fa fa-video-camera"></i> Live Class</a></li>
          <li><a href="#" id="subPerformance"><i class="fa fa-line-chart"></i> Performance &amp; Analytics</a></li>
          <li><a href="#" id="subRecord"><i class="fa fa-book"></i> Subject Class Record</a></li>
        </ul>
      </li>
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
        <h3>Classes</h3>
        <p>Manage and organize your classes</p>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <a href="subject_repository.php" class="btn-primary-t" style="background:#f8fafc;border:1px solid #cbd5e1;color:#334155;box-shadow:none;text-decoration:none;"><i class="fa fa-archive" style="color:#0ea5e9;"></i> Subject Repository</a>
      <button class="btn-primary-t" data-toggle="modal" data-target="#createClassModal"><i class="fa fa-plus"></i> Create Class</button>
    </div>
  </header>
  <div class="t-content">
    <div class="top-stats-card">
      <div class="stat-item">
        <div class="stat-circle" style="background:#dcfce7; color:#10b981;"><i class="fa fa-book"></i></div>
        <div class="stat-info">
          <span>Active Classes</span>
          <strong style="color:#10b981;"><?php echo $totalClasses; ?></strong>
        </div>
      </div>
      <div class="stat-item">
        <div class="stat-circle" style="background:#dbeafe; color:#3b82f6;"><i class="fa fa-users"></i></div>
        <div class="stat-info">
          <span>Total Students</span>
          <strong style="color:#3b82f6;"><?php echo $totalStudents; ?></strong>
        </div>
      </div>
      <div class="stat-item">
        <div class="stat-circle" style="background:#ffedd5; color:#f59e0b;"><i class="fa fa-archive"></i></div>
        <div class="stat-info">
          <span>Archived Classes</span>
          <strong style="color:#f59e0b;"><?php echo count($archivedRows); ?></strong>
        </div>
      </div>
    </div>

    <!-- Filter & Tab bar row -->
    <div class="filter-tabs-row">
      <div class="search-box-modern">
        <i class="fa fa-search"></i>
        <input type="text" id="classSearch" placeholder="Search classes by name or subject..." oninput="filterCards()">
      </div>

      <div class="tabs-wrapper">
        <button class="tab-btn-modern active" onclick="switchTab('tab-active',this)">
          Active Classes
          <span class="badge-num"><?php echo $totalClasses; ?></span>
        </button>
        <button class="tab-btn-modern" onclick="switchTab('tab-history',this)">
          Archived
          <span class="badge-num"><?php echo count($archivedRows); ?></span>
        </button>
      </div>
    </div>

    <!-- ── ACTIVE CLASSES TAB ── -->
    <div class="tab-panel active" id="tab-active">
      <?php if(!empty($classRows)): ?>
      <div class="sec-hdr" style="margin-bottom:16px;">
        <h4 style="font-size:15px; font-weight:700; color:#0f172a; margin:0; display:flex; align-items:center; gap:8px;">
          <i class="fa fa-book" style="color:#10b981;"></i> Your Classes
        </h4>
        <span style="font-size:13px; color:#64748b; font-weight:500;" id="visibleCount"><?php echo $totalClasses; ?> class<?php echo $totalClasses!==1?'es':''; ?></span>
      </div>
      <div id="classesGrid">
        <?php foreach($classRows as $i => $c):
          $sc = max(0,(int)$c['student_count']);
        ?>
        <div class="class-row-card" data-name="<?php echo strtolower(htmlspecialchars($c['class_name'].' '.$c['subject'])); ?>">
          <div class="crc-left">
            <div class="crc-icon">
              <i class="fa fa-book"></i>
            </div>
            <div class="crc-info">
              <h5 class="crc-title"><?php echo htmlspecialchars($c['class_name']); ?></h5>
              <div class="crc-meta">
                <span><?php echo htmlspecialchars($c['program_code'] ?: 'N/A'); ?></span>
                <span class="dot">&bull;</span>
                <span>Year <?php echo $c['year_level'] ?: '1'; ?></span>
                <span class="dot">&bull;</span>
                <span>Sec <?php echo htmlspecialchars($c['section'] ?: 'A'); ?></span>
                <?php if(!empty($c['display_code'])): ?>
                <span class="code-badge-green" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($c['display_code']); ?>'); alert('Subject code copied: <?php echo htmlspecialchars($c['display_code']); ?>');" title="Click to copy subject code">
                  <i class="fa fa-book" style="margin-right:3px;font-size:9px;"></i> Subject Code: <?php echo htmlspecialchars($c['display_code']); ?>
                </span>
                <?php endif; ?>
                <?php if($sc > 0): ?>
                <span class="student-badge-blue">
                  <i class="fa fa-users"></i> <?php echo $sc; ?> student<?php echo $sc!==1?'s':''; ?>
                </span>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="crc-actions">
            <button onclick="toggleSidebarClass(<?php echo $c['id']; ?>, this)" class="crc-btn open-btn toggle-class-btn" data-class-id="<?php echo $c['id']; ?>">
              <i class="fa fa-folder-open"></i> <span class="lbl-text">Open</span>
            </button>
            <button onclick="openArchiveModal(<?php echo $c['id']; ?>,'<?php echo htmlspecialchars(addslashes($c['class_name'])); ?>')" class="crc-btn archive-btn icon-only" title="Archive">
              <i class="fa fa-archive"></i>
            </button>
            <button onclick="confirmDelete(<?php echo $c['id']; ?>,'<?php echo htmlspecialchars(addslashes($c['class_name'])); ?>')" class="crc-btn delete-btn icon-only" title="Delete Class">
              <i class="fa fa-trash"></i>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fa fa-folder-open-o"></i></div>
        <h5>No active classes</h5>
        <p>Create your first class to start managing students and materials.</p>
        <button class="btn-primary-t" data-toggle="modal" data-target="#createClassModal"><i class="fa fa-plus"></i> Create First Class</button>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── HISTORY / ARCHIVE TAB ── -->
    <div class="tab-panel" id="tab-history">
      <?php if(!empty($archivedRows)): ?>
      <div class="sec-hdr" style="margin-bottom:16px;">
        <h4 style="font-size:15px; font-weight:700; color:#0f172a; margin:0; display:flex; align-items:center; gap:8px;">
          <i class="fa fa-history" style="color:#92400e;"></i> Archived Classes
        </h4>
        <span style="font-size:13px; color:#64748b; font-weight:500;"><?php echo count($archivedRows); ?> archived</span>
      </div>
      <div id="archiveGrid">
        <?php foreach($archivedRows as $i => $c):
          $sc = max(0,(int)$c['student_count']);
        ?>
        <div class="class-row-card archived" data-name="<?php echo strtolower(htmlspecialchars($c['class_name'].' '.$c['subject'].' '.($c['school_year']??''))); ?>" style="opacity:0.88;">
          <div class="crc-left">
            <div class="crc-icon" style="background:linear-gradient(135deg,#94a3b8,#64748b);">
              <i class="fa fa-archive"></i>
            </div>
            <div class="crc-info">
              <h5 class="crc-title"><?php echo htmlspecialchars($c['class_name']); ?></h5>
              <div class="crc-meta">
                <span><?php echo htmlspecialchars($c['program_code'] ?: 'N/A'); ?></span>
                <span class="dot">&bull;</span>
                <span>Year <?php echo $c['year_level'] ?: '1'; ?></span>
                <span class="dot">&bull;</span>
                <span>Sec <?php echo htmlspecialchars($c['section'] ?: 'A'); ?></span>
                <?php if(!empty($c['school_year'])): ?>
                <span style="background:#fef3c7; color:#92400e; font-size:11px; font-weight:700; padding:2px 7px; border-radius:6px; margin-left:4px;">
                  S.Y. <?php echo htmlspecialchars($c['school_year']); ?>
                </span>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="crc-actions">
            <a href="../shared/class_view.php?id=<?php echo $c['id']; ?>" class="crc-btn open-btn">
              <i class="fa fa-eye"></i> View
            </a>
            <button onclick="unarchiveClass(<?php echo $c['id']; ?>,'<?php echo htmlspecialchars(addslashes($c['class_name'])); ?>')" class="crc-btn archive-btn icon-only" style="border-color:#10b981; background:#f0fdf4; color:#059669;" title="Restore to Active">
              <i class="fa fa-undo"></i>
            </button>
            <button onclick="confirmDelete(<?php echo $c['id']; ?>,'<?php echo htmlspecialchars(addslashes($c['class_name'])); ?>')" class="crc-btn delete-btn icon-only" title="Delete Class">
              <i class="fa fa-trash"></i>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon" style="border-color:rgba(245,158,11,.22);background:linear-gradient(135deg,rgba(245,158,11,.08),rgba(245,158,11,.03));"><i class="fa fa-history" style="color:rgba(245,158,11,.5);"></i></div>
        <h5>No archived classes</h5>
        <p>Classes you archive will appear here. Use the archive button on any active class to move it to history.</p>
      </div>
      <?php endif; ?>
    </div>

  </div>
  <footer class="t-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>
<!-- Create Class Modal -->
<div class="modal fade" id="createClassModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.18);">
      <div style="padding:18px 22px;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#10b981,#059669);">
        <h4 style="color:#fff;font-size:15px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px;"><i class="fa fa-plus-circle"></i> Create New Class</h4>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;font-size:20px;background:none;border:none;">&times;</button>
      </div>
      <div class="modal-body" style="padding:22px 24px;">

        <!-- Creation Mode Switcher -->
        <div style="display:flex;gap:8px;margin-bottom:16px;background:#f1f5f9;padding:4px;border-radius:10px;">
          <button type="button" class="mode-tab-btn active" id="btnModeStandard" onclick="switchCreateMode('standard')" style="flex:1;padding:8px 12px;border:none;border-radius:8px;font-size:12px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;background:#fff;color:#0f172a;box-shadow:0 2px 6px rgba(0,0,0,0.06);transition:all .15s;">
            <i class="fa fa-sliders"></i> Standard (Section Auto-Enroll)
          </button>
          <button type="button" class="mode-tab-btn" id="btnModeMasterList" onclick="switchCreateMode('masterlist')" style="flex:1;padding:8px 12px;border:none;border-radius:8px;font-size:12px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;background:transparent;color:#64748b;transition:all .15s;">
            <i class="fa fa-file-text-o"></i> Create via Master List
          </button>
        </div>

        <!-- Program / Course -->
        <div style="margin-bottom:14px;">
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Course / Program <span style="color:#ef4444;">*</span></label>
          <select id="create_program" style="width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;background:#fff;">
            <option value="">— Select course / program —</option>
            <option value="IS">IS — Information Systems</option>
            <option value="CRIM">CRIM — Criminology</option>
            <option value="ARTS">ARTS — Arts (BSOA, AB)</option>
            <option value="EDUCATION">EDUCATION — Education (BEED, BSED, BPED)</option>
          </select>
        </div>

        <!-- Subject -->
        <div style="margin-bottom:16px;">
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Subject Name <span style="color:#ef4444;">*</span></label>
          <select id="create_subject" style="width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;background:#fff;">
            <option value="">— Select subject —</option>
            <?php foreach($managed_subjects as $subj): ?>
            <option value="<?php echo htmlspecialchars($subj['subject']); ?>" data-code="<?php echo htmlspecialchars($subj['class_code'] ?? ''); ?>" data-program="<?php echo htmlspecialchars($subj['program_code'] ?? ''); ?>">
              <?php echo htmlspecialchars($subj['subject']); ?><?php if(!empty($subj['class_code'])): ?> (<?php echo htmlspecialchars($subj['class_code']); ?>)<?php endif; ?><?php if(!empty($subj['program_code'])): ?> [<?php echo htmlspecialchars($subj['program_code']); ?>]<?php endif; ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($managed_subjects)): ?>
            <small style="display:block;color:#ef4444;margin-top:5px;font-size:11px;">
              <i class="fa fa-exclamation-circle"></i> No subjects found. Please create one in <b><a href="logbook.php" style="color:#059669;font-weight:600;text-decoration:underline;">Manage Subject</a></b> first.
            </small>
          <?php endif; ?>
        </div>

        <!-- Year Level (Applies to both Standard & Master List modes) -->
        <div style="margin-bottom:16px;">
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Year Level <span style="color:#ef4444;">*</span></label>
          <select id="create_year" style="width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;background:#fff;">
            <option value="">— Select year level —</option>
            <option value="1">1st Year</option>
            <option value="2">2nd Year</option>
            <option value="3">3rd Year</option>
            <option value="4">4th Year</option>
          </select>
        </div>

        <!-- Enrollment Restrictions (Standard Mode) -->
        <div id="standardRestrictionContainer" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;">
          <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-bottom:14px;display:flex;align-items:center;gap:6px;">
            <i class="fa fa-filter" style="color:#1792bb;font-size:12px;"></i> Auto-Enrollment Restrictions
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:8px;">Select Sections <span style="color:#ef4444;">*</span></label>
            <div style="display:flex;flex-wrap:wrap;gap:8px;" id="sectionCheckboxes">
              <?php foreach(range('A', 'J') as $sec): ?>
              <label style="display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border:1.5px solid #e2e8f0;border-radius:8px;cursor:pointer;font-size:12px;font-weight:600;color:#475569;background:#fff;transition:all .15s;user-select:none;" class="sec-lbl" data-sec="<?php echo $sec; ?>">
                <input type="checkbox" class="sec-cb" value="<?php echo $sec; ?>" style="display:none;">
                <i class="fa fa-check" style="display:none;color:#059669;font-size:10px;"></i>
                <span><?php echo $sec; ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div style="margin-top:10px;padding:8px 11px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:11px;color:#1d4ed8;display:flex;align-items:center;gap:6px;">
            <i class="fa fa-info-circle"></i> Only students matching course, year level, and selected sections will be auto-enrolled.
          </div>
        </div>

        <!-- Master List Container (Master List Mode) -->
        <div id="masterListContainer" style="display:none;background:#f8fafc;border:1.5px solid #cbd5e1;border-radius:12px;padding:16px;margin-top:14px;">
          <div style="font-size:12px;font-weight:700;color:#059669;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
            <i class="fa fa-users" style="font-size:14px;"></i> Student Master List Roster
          </div>

          <div style="margin-bottom:12px;padding:10px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:11.5px;color:#1e40af;line-height:1.5;">
            <i class="fa fa-info-circle"></i> <b>Required Format:</b> <code>Lastname, Firstname, Middle Name</code> (e.g. <i>Ablaza, John Paul, Esto</i>). CenLearn tracks and auto-enrolls all students without limits.
          </div>

          <!-- File Upload -->
          <div style="margin-bottom:12px;">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Upload Master List File (Excel / CSV / TXT)</label>
            <input type="file" id="master_list_file" accept=".csv,.txt,.xlsx,.xls" style="width:100%;padding:8px;border:1.5px dashed #cbd5e1;border-radius:8px;font-size:12px;background:#fff;">
          </div>

          <!-- Direct Paste Area -->
          <div style="margin-bottom:8px;">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Or Paste Student Names (One student per line)</label>
            <textarea id="master_list_text" rows="4" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;font-family:monospace;background:#fff;resize:vertical;" placeholder="Ablaza, John Paul, Esto&#10;Alameda, Cris Rainier, Villares&#10;Alojado, Andrew, Villanueva" oninput="updateMasterListPreview()"></textarea>
            <small style="color:#64748b;font-size:11px;margin-top:2px;display:block;">You can copy-paste columns directly from Excel or Google Sheets.</small>
          </div>

          <!-- Live Preview & Counter -->
          <div id="mlPreviewCard" style="display:none;margin-top:10px;padding:10px 14px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
              <span id="mlCounterBadge" style="font-size:12px;font-weight:700;color:#065f46;"><i class="fa fa-check-circle"></i> <span id="mlCountNumber">0</span> students detected</span>
              <button type="button" id="mlToggleListBtn" onclick="$('#mlPreviewList').slideToggle(120);" style="font-size:11px;background:none;border:none;color:#059669;font-weight:600;text-decoration:underline;cursor:pointer;">View Detected Names</button>
            </div>
            <div id="mlPreviewList" style="display:none;max-height:140px;overflow-y:auto;margin-top:8px;padding-top:8px;border-top:1px dashed #a7f3d0;font-size:11.5px;color:#047857;"></div>
          </div>
        </div>

        <div id="createAlert" style="display:none;margin-top:12px;"></div>
      </div>
      <div style="padding:14px 22px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" style="padding:9px 18px;background:#f1f5f9;color:#475569;border:none;border-radius:9px;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;" data-dismiss="modal">Cancel</button>
        <button type="button" id="btnCreate" style="padding:9px 20px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:7px;"><i class="fa fa-save"></i> Create Class</button>
      </div>
    </div>
  </div>
</div>


<!-- Archive Modal -->
<div class="modal fade" id="archiveModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.18);">
      <div style="padding:18px 22px;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#f59e0b,#d97706);">
        <h4 style="color:#fff;font-size:15px;font-weight:700;margin:0;"><i class="fa fa-archive"></i> Move to History</h4>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;font-size:20px;background:none;border:none;">&times;</button>
      </div>
      <div class="modal-body" style="padding:22px;">
        <p style="font-size:13px;color:#374151;margin:0 0 5px;">Archive this class:</p>
        <p style="font-size:15px;font-weight:700;color:#0f172a;margin:0 0 16px;" id="archiveClassName"></p>
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;">School Year <span style="color:#ef4444;">*</span></label>
        <input type="text" id="archiveYear" style="width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;background:#f9fafb;" placeholder="e.g. 2024-2025">
        <p style="font-size:11px;color:#94a3b8;margin:8px 0 0;"><i class="fa fa-info-circle"></i> The class will be moved to History. Students can still view their records.</p>
      </div>
      <div style="padding:14px 22px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" style="padding:9px 18px;background:#f1f5f9;color:#475569;border:none;border-radius:9px;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;" data-dismiss="modal">Cancel</button>
        <button type="button" id="btnArchiveConfirm" style="padding:9px 20px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:7px;"><i class="fa fa-archive"></i> Archive</button>
      </div>
    </div>
  </div>
</div>

<!-- Delete Class Modal -->
<div class="modal fade" id="deleteClassModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.18);">
      <div style="padding:18px 22px;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#ef4444,#dc2626);">
        <h4 style="color:#fff;font-size:15px;font-weight:700;margin:0;"><i class="fa fa-trash"></i> Delete Class</h4>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;font-size:20px;background:none;border:none;">&times;</button>
      </div>
      <div class="modal-body" style="padding:22px;">
        <p style="font-size:13px;color:#374151;margin:0 0 6px;">Are you sure you want to permanently delete this class?</p>
        <p style="font-size:15px;font-weight:700;color:#dc2626;margin:0 0 12px;" id="deleteClassName"></p>
        <p style="font-size:11.5px;color:#64748b;margin:0 0 10px;line-height:1.45;">
          <i class="fa fa-exclamation-triangle" style="color:#ef4444;"></i> This will automatically delete the class and all its records (grades, attendance, quizzes, submissions, learning materials) from the database.
        </p>
        <p style="font-size:11.5px;color:#059669;margin:0;line-height:1.45;">
          <i class="fa fa-check-circle"></i> You can immediately create a new class using the same subject name.
        </p>
        <div id="deleteAlert" style="display:none;margin-top:12px;"></div>
      </div>
      <div style="padding:14px 22px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" style="padding:9px 18px;background:#f1f5f9;color:#475569;border:none;border-radius:9px;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;" data-dismiss="modal">Cancel</button>
        <button type="button" id="btnDeleteConfirm" style="padding:9px 20px;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:7px;"><i class="fa fa-trash"></i> Delete Class</button>
      </div>
    </div>
  </div>
</div>
<?php include '../includes/scripts.php'; ?>
<script src="../plugins/doc_viewer/xlsx.full.min.js"></script>
<script>
// ── Tab switching ──────────────────────────────────────────────────────────
function switchTab(tabId, btn){
  document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
  document.querySelectorAll('.tab-btn-modern').forEach(function(b){ b.classList.remove('active'); });
  document.getElementById(tabId).classList.add('active');
  btn.classList.add('active');
}

function filterCards(){
  var q=document.getElementById('classSearch').value.toLowerCase();
  var cards=document.querySelectorAll('#classesGrid .class-row-card');
  var visible=0;
  cards.forEach(function(c){var match=c.dataset.name.includes(q);c.style.display=match?'flex':'none';if(match)visible++;});
  var el=document.getElementById('visibleCount');
  if(el) el.textContent=visible+' class'+(visible!==1?'es':'');
}

function filterArchive(){
  var q=document.getElementById('classSearch').value.toLowerCase();
  document.querySelectorAll('#archiveGrid .class-row-card').forEach(function(c){
    c.style.display=c.dataset.name.includes(q)?'flex':'none';
  });
}

// ── Archive modal ──────────────────────────────────────────────────────────
var _archiveId = null;
function openArchiveModal(id, name){
  _archiveId = id;
  document.getElementById('archiveClassName').textContent = name;
  // Auto-fill school year
  var y = new Date().getFullYear();
  var m = new Date().getMonth()+1;
  document.getElementById('archiveYear').value = m >= 6 ? y+'-'+(y+1) : (y-1)+'-'+y;
  $('#archiveModal').modal('show');
}
$('#btnArchiveConfirm').on('click', function(){
  if(!_archiveId) return;
  var sy = $('#archiveYear').val().trim();
  $(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i>');
  $.post('../shared/class_archive.php',{action:'archive',class_id:_archiveId,school_year:sy},function(res){
    $('#btnArchiveConfirm').prop('disabled',false).html('<i class="fa fa-archive"></i> Archive');
    if(res.success){ $('#archiveModal').modal('hide'); location.reload(); }
    else alert(res.msg||'Failed to archive.');
  },'json');
});

// ── Unarchive ──────────────────────────────────────────────────────────────
function unarchiveClass(id, name){
  if(!confirm('Restore "'+name+'" to active classes?')) return;
  $.post('../shared/class_archive.php',{action:'unarchive',class_id:id},function(res){
    if(res.success) location.reload();
    else alert(res.msg||'Failed to restore.');
  },'json');
}
var currentCreateMode = 'standard';

function switchCreateMode(mode) {
  currentCreateMode = mode;
  if (mode === 'masterlist') {
    $('#btnModeStandard').removeClass('active').css({ background: 'transparent', color: '#64748b', boxShadow: 'none' });
    $('#btnModeMasterList').addClass('active').css({ background: '#fff', color: '#0f172a', boxShadow: '0 2px 6px rgba(0,0,0,0.06)' });
    $('#standardRestrictionContainer').slideUp(150);
    $('#masterListContainer').slideDown(150);
  } else {
    $('#btnModeMasterList').removeClass('active').css({ background: 'transparent', color: '#64748b', boxShadow: 'none' });
    $('#btnModeStandard').addClass('active').css({ background: '#fff', color: '#0f172a', boxShadow: '0 2px 6px rgba(0,0,0,0.06)' });
    $('#masterListContainer').slideUp(150);
    $('#standardRestrictionContainer').slideDown(150);
  }
}

function escapeHtml(str) {
  return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function parseClientMasterLine(raw) {
  var line = (raw || '').trim();
  if (!line) return null;
  if (/^(no\.?|#|student\s*id|id\s*number|user\s*code|last\s*name|student\s*name|name)/i.test(line) && /(name|first|middle|course|program|section|year)/i.test(line)) return null;

  // strip email
  line = line.replace(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/gi, ' ');
  line = line.replace(/\b\S+@\S*\b/gi, ' ');

  // extract student id
  var codeMatch = line.match(/\b\d{6,15}\b/);
  var code = codeMatch ? codeMatch[0] : '';
  if (code) line = line.replace(code, ' ');

  // strip section / course
  line = line.replace(/\b(?:BSIS|BSIT|BEED|BSED|BSCRIM|ARTS|BSOA|ACT|BLIS|BTVTED|BSNE|CS|IT)[-\s]?\d?[-\s]?[A-Z0-9]*\b/gi, ' ');
  line = line.replace(/\bIS[-\s]?\d*[-A-Z0-9]*\b/gi, ' ');
  line = line.replace(/\bIs-?\d*[-A-Z0-9]*\b/gi, ' ');
  line = line.replace(/\bSec(?:tion)?[-\s]?[A-Z0-9]+\b/gi, ' ');
  line = line.replace(/\bYr(?:ear)?[-\s]?\d+\b/gi, ' ');

  // strip leading row numbers (e.g. "1.", "2)", "3 -")
  line = line.replace(/^\s*#?\d{1,4}(?:[\.\)\:\-]\s*|\s+)/, '').trim();

  var cols = [];
  if (line.indexOf('\t') !== -1) cols = line.split('\t');
  else if (line.indexOf(';') !== -1) cols = line.split(';');
  else if (line.indexOf(',') !== -1) cols = line.split(',');
  else cols = [line];

  cols = cols.map(function(c){ return c.trim(); }).filter(function(c){ return c !== ''; });
  if (cols.length > 0 && /^\d{1,4}[\.\)]?$/.test(cols[0])) cols.shift();

  var ln = '', fn = '', mn = '';
  if (cols.length >= 3) {
    ln = cols[0];
    fn = cols[1];
    mn = cols[2];
  } else if (cols.length === 2) {
    ln = cols[0];
    var rest = cols[1].split(/\s+/);
    if (rest.length > 1) {
      var lastP = rest[rest.length - 1];
      if (/^[A-Z]\.?$/i.test(lastP) || rest.length >= 2) {
        mn = rest.pop().replace(/\.$/, '');
        fn = rest.join(' ');
      } else {
        fn = cols[1];
      }
    } else {
      fn = cols[1];
    }
  } else if (cols.length === 1) {
    var parts = cols[0].split(/\s+/);
    if (parts.length >= 3) {
      ln = parts.shift();
      mn = parts.pop().replace(/\.$/, '');
      fn = parts.join(' ');
    } else if (parts.length === 2) {
      ln = parts[0];
      fn = parts[1];
    } else {
      ln = cols[0];
    }
  }

  if (!ln && !fn) return null;
  return { last: ln, first: fn, middle: mn, code: code };
}

function updateMasterListPreview() {
  var text = $('#master_list_text').val() || '';
  var lines = text.split(/\r\n|\r|\n/);
  var valid = [];
  lines.forEach(function(l){
    var p = parseClientMasterLine(l);
    if (p) valid.push(p);
  });

  if (valid.length > 0) {
    $('#mlCountNumber').text(valid.length);
    var html = '<div style="display:flex;flex-direction:column;gap:3px;">';
    valid.forEach(function(s, idx){
      var nameStr = '<b>' + (idx+1) + '. ' + escapeHtml(s.last) + '</b>, ' + escapeHtml(s.first) + (s.middle ? ' ' + escapeHtml(s.middle) : '');
      var codeStr = s.code ? ' <span style="background:#e0f2fe;color:#0369a1;padding:1px 5px;border-radius:4px;font-size:10px;">ID: ' + escapeHtml(s.code) + '</span>' : '';
      html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:2px 0;"><span>' + nameStr + '</span>' + codeStr + '</div>';
    });
    html += '</div>';
    $('#mlPreviewList').html(html);
    $('#mlPreviewCard').slideDown(150);
  } else {
    $('#mlPreviewCard').slideUp(150);
  }
}

// File change event for instant Excel / CSV reading
$('#master_list_file').on('change', function(e){
  var file = e.target.files[0];
  if (!file) return;

  var ext = file.name.split('.').pop().toLowerCase();
  if ((ext === 'xlsx' || ext === 'xls') && typeof XLSX !== 'undefined') {
    var reader = new FileReader();
    reader.onload = function(evt) {
      try {
        var data = new Uint8Array(evt.target.result);
        var workbook = XLSX.read(data, {type: 'array'});
        var firstSheet = workbook.Sheets[workbook.SheetNames[0]];
        var csv = XLSX.utils.sheet_to_csv(firstSheet);
        if (csv && csv.trim()) {
          $('#master_list_text').val(csv.trim());
          updateMasterListPreview();
        }
      } catch(err) {
        console.log('Client sheet parsing fallback to server:', err);
      }
    };
    reader.readAsArrayBuffer(file);
  } else {
    var reader = new FileReader();
    reader.onload = function(evt) {
      var content = evt.target.result;
      if (content && content.trim()) {
        $('#master_list_text').val(content.trim());
        updateMasterListPreview();
      }
    };
    reader.readAsText(file);
  }
});

$('#btnCreate').on('click',function(){
  var subject=$('#create_subject').val().trim();
  if(!subject){showAlert('#createAlert','danger','Subject name is required.');return;}
  var program=$('#create_program').val();
  var year=$('#create_year').val();
  var checkedSecs = [];
  document.querySelectorAll('#sectionCheckboxes .sec-cb:checked').forEach(function(cb){
    checkedSecs.push(cb.value);
  });
  var section = checkedSecs.join(',');
  if(!program){showAlert('#createAlert','danger','Program is required.');return;}

  if(currentCreateMode === 'standard') {
    if(!year){showAlert('#createAlert','danger','Year level is required.');return;}
    if(!section){showAlert('#createAlert','danger','Section is required.');return;}
  }

  var subjectCode = $('#create_subject option:selected').data('code') || '';

  var fd = new FormData();
  fd.append('action', 'create');
  fd.append('creation_type', currentCreateMode);
  fd.append('subject', subject);
  fd.append('subject_code', subjectCode);
  fd.append('program_code', program);
  fd.append('year_level', year || '1');
  fd.append('section', section || 'A');
  fd.append('schedule_json', '[]');
  fd.append('schedule_room', '');

  if (currentCreateMode === 'masterlist') {
    var mlFileInput = document.getElementById('master_list_file');
    var mlText = ($('#master_list_text').val() || '').trim();
    if (mlText) {
      fd.append('master_list_text', mlText);
    }
    if (mlFileInput && mlFileInput.files.length > 0) {
      fd.append('master_list_file', mlFileInput.files[0]);
    }
    if (!mlText && (!mlFileInput || mlFileInput.files.length === 0)) {
      showAlert('#createAlert', 'danger', 'Please upload a master list file or paste student names (Lastname, Firstname, Middle Name).');
      return;
    }
  }

  $('#btnCreate').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Creating...');

  $.ajax({
    url: '../shared/class_save.php',
    type: 'POST',
    data: fd,
    processData: false,
    contentType: false,
    dataType: 'json',
    success: function(res) {
      $('#btnCreate').prop('disabled', false).html('<i class="fa fa-save"></i> Create Class');
      if(res.success){
        var enrollMsg = res.auto_enrolled > 0 ? ' &mdash; <b>' + res.auto_enrolled + '</b> student' + (res.auto_enrolled !== 1 ? 's' : '') + ' enrolled' : '';
        showAlert('#createAlert', 'success', 'Class created!' + enrollMsg);
        setTimeout(function(){ location.reload(); }, 600);
      } else {
        showAlert('#createAlert', 'danger', res.msg);
      }
    },
    error: function() {
      $('#btnCreate').prop('disabled', false).html('<i class="fa fa-save"></i> Create Class');
      showAlert('#createAlert', 'danger', 'Error processing request.');
    }
  });
});
function showAlert(el,type,msg){$(el).attr('class','m-alert '+type).html('<i class="fa fa-'+(type==='success'?'check-circle':'exclamation-circle')+'"></i> <span>'+msg+'</span>').show();}



// Section checkbox toggle
document.querySelectorAll('.sec-lbl').forEach(function(lbl){
  lbl.addEventListener('click',function(){
    var cb=this.querySelector('.sec-cb');
    cb.checked=!cb.checked;
    var icon=this.querySelector('i');
    if(cb.checked){
      this.style.background='#f0fdf4';this.style.borderColor='#10b981';this.style.color='#059669';
      if(icon) icon.style.display='inline-block';
    } else {
      this.style.background='#fff';this.style.borderColor='#e2e8f0';this.style.color='#475569';
      if(icon) icon.style.display='none';
    }
  });
});



// ── Dynamic Subject Filter by Selected Program/Course ──────────────────────
var rawManagedSubjects = <?php echo json_encode($managed_subjects); ?>;

function filterSubjectsByProgram() {
  var selectedProg = ($('#create_program').val() || '').trim().toUpperCase();
  var subjectSelect = $('#create_subject');
  var currentVal = subjectSelect.val();

  subjectSelect.empty();
  subjectSelect.append('<option value="">— Select subject —</option>');

  var count = 0;
  rawManagedSubjects.forEach(function(s) {
    var sProg = (s.program_code || '').trim().toUpperCase();
    var isMatch = false;

    if (!selectedProg) {
      isMatch = true;
    } else if (sProg === selectedProg) {
      isMatch = true;
    } else if (selectedProg === 'ARTS' && ['ARTS', 'BSOA', 'AB ENGLISH', 'AB HISTORY', 'AB'].indexOf(sProg) !== -1) {
      isMatch = true;
    } else if (selectedProg === 'EDUCATION' && ['EDUCATION', 'BEED', 'BPED', 'BSED', 'BSED-FILIPINO', 'BSED-MATHEMATICS', 'BSED-SOCIAL STUDIES'].indexOf(sProg) !== -1) {
      isMatch = true;
    } else if ((sProg === 'ARTS' && ['BSOA', 'AB ENGLISH', 'AB HISTORY', 'AB'].indexOf(selectedProg) !== -1) ||
               (sProg === 'EDUCATION' && ['BEED', 'BPED', 'BSED', 'BSED-FILIPINO', 'BSED-MATHEMATICS', 'BSED-SOCIAL STUDIES'].indexOf(selectedProg) !== -1)) {
      isMatch = true;
    }

    if (isMatch) {
      var codeTag = s.class_code ? ' (' + s.class_code + ')' : '';
      var progTag = s.program_code ? ' [' + s.program_code + ']' : '';
      subjectSelect.append($('<option>', {
        value: s.subject,
        text: s.subject + codeTag + progTag,
        'data-program': s.program_code || ''
      }));
      count++;
    }
  });

  if (count === 0 && selectedProg) {
    subjectSelect.append($('<option>', {
      value: '',
      text: '⚠️ No subjects created for ' + selectedProg + ' yet',
      disabled: true
    }));
  } else if (currentVal) {
    subjectSelect.val(currentVal);
  }
}

$('#create_program').on('change', filterSubjectsByProgram);

$('#create_subject').on('change', function(){
  var selectedOpt = $(this).find('option:selected');
  var prog = selectedOpt.attr('data-program');
  if (prog && !$('#create_program').val()) {
    $('#create_program').val(prog);
    filterSubjectsByProgram();
    $(this).val(selectedOpt.val());
  }
});

$('#createClassModal').on('show.bs.modal', function(){
  filterSubjectsByProgram();
});

$('#createClassModal').on('hidden.bs.modal',function(){
  $('#create_subject').val('');
  $('#create_program').val('');
  filterSubjectsByProgram();
  $('#create_year').val('');
  $('#master_list_file').val('');
  $('#master_list_text').val('');
  $('#mlPreviewCard').hide();
  $('#mlPreviewList').empty().hide();
  document.querySelectorAll('.sec-cb').forEach(function(cb){
    cb.checked=false;
    var lbl=cb.closest('.sec-lbl');
    lbl.style.background='#fff';lbl.style.borderColor='#e2e8f0';lbl.style.color='#475569';
    var icon=lbl.querySelector('i');
    if(icon) icon.style.display='none';
  });
  $('#createAlert').hide();
});
var _deleteId=null;
function confirmDelete(id,name){_deleteId=id;$('#deleteClassName').text(name);$('#deleteAlert').hide();$('#btnDeleteConfirm').prop('disabled',false).html('<i class="fa fa-trash"></i> Delete');$('#deleteClassModal').modal('show');}
$('#btnDeleteConfirm').on('click',function(){
  if(!_deleteId)return;
  $(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Deleting...');
  $.post('../shared/class_delete.php',{class_id:_deleteId},function(res){
    if(res.success){$('#deleteClassModal').modal('hide');setTimeout(function(){location.reload();},400);}
    else{$('#btnDeleteConfirm').prop('disabled',false).html('<i class="fa fa-trash"></i> Delete');showAlert('#deleteAlert','danger',res.msg);}
  },'json');
});
function leaveClass(id, name){
  confirmDelete(id, name);
}
var activeClassId = null;

function toggleSidebarClass(classId, btn) {
  var $btn = $(btn);
  var submenu = $('#classSubmenu');

  // If the same class is clicked, close it
  if (activeClassId === classId) {
    submenu.slideUp(200);
    $btn.removeClass('close-state');
    $btn.html('<i class="fa fa-folder-open"></i> <span class="lbl-text">Open</span>');
    activeClassId = null;
    return;
  }

  // Restore any previously opened class button
  if (activeClassId !== null) {
    var prevBtn = $('.toggle-class-btn[data-class-id="' + activeClassId + '"]');
    if (prevBtn.length) {
      prevBtn.removeClass('close-state');
      prevBtn.html('<i class="fa fa-folder-open"></i> <span class="lbl-text">Open</span>');
    }
  }

  // Update submenu URLs dynamically
  $('#subMaterials').attr('href', '../shared/class_view.php?id=' + classId + '&tab=materials');
  $('#subClasswork').attr('href', '../shared/class_view.php?id=' + classId + '&tab=classwork');
  $('#subLiveClass').attr('href', '../shared/live_class.php?id=' + classId);
  $('#subPerformance').attr('href', '../shared/class_view.php?id=' + classId + '&tab=performance');
  $('#subRecord').attr('href', '../shared/class_record_detail.php?id=' + classId);

  // Expand submenu
  submenu.slideDown(200);
  $btn.addClass('close-state');
  $btn.html('<i class="fa fa-times-circle"></i> <span class="lbl-text">Close</span>');
  activeClassId = classId;

  // If responsive viewport (mobile), trigger sidebar expansion
  if (window.innerWidth <= 900) {
    openSidebar();
  }
}

function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('sidebarOverlay').classList.add('active');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebarOverlay').classList.remove('active');}
</script>
</body>
</html>
