<?php
include '../includes/session.php';
include '../includes/conn.php';

$uc = $conn->real_escape_string($user['user_code']);

$pg_res = $conn->query("SELECT * FROM published_grades WHERE student_code='$uc'");
$publishedGradesMap = [];
while($r = $pg_res->fetch_assoc()) {
    $publishedGradesMap[$r['class_id']][$r['term']] = $r;
}

$user['program_code'] = $user['program_code'] ?? '';
$user['year_level']   = $user['year_level']   ?? '';
$user['section']      = $user['section']       ?? '';
$isGraduated = !empty($user['graduated_at']) || strtoupper($user['user_group'] ?? '') === 'ALUMNI';

// Active classes — show all enrolled classes where student is a member
$my_classes = $conn->query("
    SELECT c.*, u.first_name AS teacher_first, u.last_name AS teacher_last,
           COALESCE(cc.status,'accepted') AS confirm_status,
           c.class_code AS display_code
    FROM class_members cm JOIN classes c ON cm.class_id=c.id
    LEFT JOIN users u ON c.teacher_code=u.user_code
    LEFT JOIN class_confirmations cc ON cc.class_id=c.id AND cc.student_code='$uc'
    WHERE cm.user_code='$uc' AND c.teacher_code!='$uc'
      AND (c.is_archived=0 OR c.is_archived IS NULL)
      AND (cc.status IS NULL OR cc.status = 'accepted')
    GROUP BY c.id
    ORDER BY cm.joined_at DESC
");
$classCount = $my_classes->num_rows;

// Count pending confirmations for the inbox badge
$pendingConfirmCount = 0;

// Archived enrolled classes (history)
$archived_classes = $conn->query("
    SELECT c.*, u.first_name AS teacher_first, u.last_name AS teacher_last
    FROM class_members cm JOIN classes c ON cm.class_id=c.id
    LEFT JOIN users u ON c.teacher_code=u.user_code
    WHERE cm.user_code='$uc' AND c.teacher_code!='$uc'
      AND c.is_archived=1
    GROUP BY UPPER(TRIM(c.class_name))
    ORDER BY c.archived_at DESC
");
$archivedCount = $archived_classes->num_rows;

$available_classes = null;
if(!$isGraduated){
    $pc  = $conn->real_escape_string(strtoupper($user['program_code']));
    $yl  = intval($user['year_level']);
    $sec = $conn->real_escape_string(strtoupper($user['section']));
    $available_classes = $conn->query("
        SELECT c.*, u.first_name AS teacher_first, u.last_name AS teacher_last
        FROM classes c LEFT JOIN users u ON c.teacher_code=u.user_code
        WHERE c.teacher_code!='$uc'
          AND (c.is_archived=0 OR c.is_archived IS NULL)
          AND NOT EXISTS (SELECT 1 FROM class_members cm WHERE cm.class_id=c.id AND cm.user_code='$uc')
          AND (c.program_code='' OR c.program_code IS NULL OR UPPER(c.program_code)='$pc')
          AND (c.year_level=0 OR c.year_level IS NULL OR c.year_level=$yl)
          AND (c.section='' OR c.section IS NULL OR UPPER(c.section)='$sec')
        ORDER BY c.created_at DESC
    ");
}

$initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
$availCount = $available_classes ? $available_classes->num_rows : 0;

$palette = ['#1792bb','#10b981','#8b5cf6','#f59e0b','#ef4444','#06b6d4','#ec4899','#0ea5e9'];
$icons   = ['fa-calculator','fa-flask','fa-book','fa-globe','fa-code','fa-pencil','fa-music','fa-leaf'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CenLearn — My Classes</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html,body{margin:0;padding:0;overflow-x:hidden;}
    body { font-family: 'Inter', sans-serif; background: #f0f4f8; color: #1e293b; }

    /* ── Sidebar ── */
    .s-sidebar {
      position: fixed; top: 0; left: 0; width: 260px; height: 100vh;
      background: linear-gradient(180deg, #0c1a2e 0%, #0f2d4a 55%, #0f5f80 100%);
      display: flex; flex-direction: column; z-index: 200; transition: transform .3s cubic-bezier(.4,0,.2,1);
      transform: translateX(-260px);
    }
    .s-sidebar.open{transform: translateX(0);}
    @media(min-width: 901px){.s-sidebar{transform: translateX(0);}}
    .s-brand { padding: 22px 20px 16px; border-bottom: 1px solid rgba(255,255,255,.07); }
    .s-logo {
      width: 40px; height: 40px; border-radius: 10px;
      background: linear-gradient(135deg, #1792bb, #0f5f80);
      display: inline-flex; align-items: center; justify-content: center;
      margin-bottom: 8px; box-shadow: 0 4px 14px rgba(23,146,187,.4);
    }
    .s-logo i { color: #fff; font-size: 17px; }
    .s-brand h2 { color: #fff; font-size: 18px; font-weight: 800; margin: 0; }
    .s-brand h2 span { color: #38bdf8; }
    .s-brand p { color: rgba(255,255,255,.3); font-size: 10px; margin: 2px 0 0; }
    .s-nav { flex: 1; padding: 10px 0; overflow-y: auto; }
    .s-nav-sec { padding: 10px 20px 4px; font-size: 9px; font-weight: 700; color: rgba(255,255,255,.22); letter-spacing: 1.5px; text-transform: uppercase; }
    .s-nav ul { list-style: none; margin: 0; padding: 0; }
    .s-nav li a {
      display: flex; align-items: center; gap: 10px; padding: 10px 20px;
      color: rgba(255,255,255,.55); text-decoration: none; font-size: 13px; font-weight: 500;
      transition: all .18s; border-left: 3px solid transparent;
    }
    .s-nav li a:hover { background: rgba(255,255,255,.06); color: #fff; }
    .s-nav li.active a { background: rgba(56,189,248,.12); color: #fff; border-left-color: #38bdf8; }
    .s-nav li a i { width: 16px; text-align: center; font-size: 13px; }
    .s-footer { padding: 14px 20px; border-top: 1px solid rgba(255,255,255,.07); }
    .s-user { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .s-av {
      width: 36px; height: 36px; border-radius: 9px;
      background: linear-gradient(135deg, #1792bb, #0f5f80);
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; font-weight: 800; color: #fff; flex-shrink: 0;
    }
    .s-meta strong { display: block; color: #fff; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px; }
    .s-meta span { color: rgba(255,255,255,.38); font-size: 10px; }
    .s-out {
      display: flex; align-items: center; justify-content: center; gap: 6px;
      padding: 8px; width: 100%; background: rgba(255,255,255,.06);
      color: rgba(255,255,255,.55); border: 1px solid rgba(255,255,255,.1);
      border-radius: 8px; font-size: 12px; font-weight: 500; text-decoration: none; transition: all .18s;
    }
    .s-out:hover { background: rgba(255,255,255,.12); color: #fff; }

    /* ── Main layout ── */
    .mc-main { margin-left:0; min-height: 100vh; display: flex; flex-direction: column; transition: margin 0s;}
    @media(min-width:901px){.mc-main{margin-left: 260px;}}
    .mc-topbar {
      background: #fff; padding: 0 28px; height: 62px;
      display: flex; align-items: center; justify-content: space-between;
      border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 50;
      box-shadow: 0 1px 4px rgba(0,0,0,.04);
    }
    .mc-topbar h3 { font-size: 16px; font-weight: 700; color: #0f172a; margin: 0; }
    .mc-topbar p { font-size: 12px; color: #64748b; margin: 0; }
    .mc-content { padding: 26px 28px 52px; flex: 1; }

    /* Modern top stats card matching teacher/classes.php */
    .top-stats-card {
      background: #ffffff;
      border: 1.5px solid #e2e8f0;
      border-radius: 16px;
      padding: 16px 24px;
      margin-bottom: 20px;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 16px;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.02);
    }
    .stat-item {
      display: flex;
      align-items: center;
      gap: 16px;
    }
    .stat-item:not(:last-child) {
      border-right: 1px solid #f1f5f9;
      padding-right: 16px;
    }
    @media (max-width: 768px) {
      .stat-item:not(:last-child) { border-right: none; padding-right: 0; }
    }
    .stat-circle {
      width: 46px;
      height: 46px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      flex-shrink: 0;
    }
    .stat-info span {
      display: block;
      font-size: 12px;
      font-weight: 500;
      color: #64748b;
      margin-bottom: 2px;
    }
    .stat-info strong {
      display: block;
      font-size: 20px;
      font-weight: 800;
      line-height: 1;
    }

    /* Filter & Tab bar row */
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
      min-width: 260px;
    }
    .search-box-modern input {
      width: 100%;
      padding: 11px 16px 11px 42px;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      font-size: 13px;
      font-family: 'Inter', sans-serif;
      background: #ffffff;
      color: #0f172a;
      transition: all 0.2s ease;
    }
    .search-box-modern input:focus {
      outline: none;
      border-color: #10b981;
      box-shadow: 0 0 0 3.5px rgba(16, 185, 129, 0.12);
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
      align-items: center;
      background: #f1f5f9;
      padding: 4px;
      border-radius: 12px;
      gap: 4px;
    }
    .tab-btn-modern {
      display: flex;
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
    .tab-btn-modern.active {
      background: #ffffff;
      color: #0f172a;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    }
    .badge-num {
      font-size: 11px;
      padding: 2px 7px;
      border-radius: 12px;
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
      border-radius: 12px;
      padding: 12px 18px;
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03), 0 1px 2px rgba(15, 23, 42, 0.02);
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .class-row-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(23, 146, 187, 0.08), 0 2px 6px rgba(15, 23, 42, 0.04);
      border-color: #7dd3fc;
    }
    .crc-left {
      display: flex;
      align-items: center;
      gap: 14px;
      flex: 1;
      min-width: 0;
    }
    .crc-icon {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      background: linear-gradient(135deg, #10b981, #059669);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ffffff;
      font-size: 16px;
      flex-shrink: 0;
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
    }
    .crc-info h5 {
      font-size: 14.5px;
      font-weight: 700;
      color: #0f172a;
      margin: 0 0 4px 0;
      letter-spacing: -0.1px;
    }
    .crc-meta {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      color: #475569;
      font-weight: 500;
      flex-wrap: wrap;
    }
    .crc-meta .dot {
      color: #cbd5e1;
      font-size: 8px;
    }
    .code-badge-green {
      background: #ecfdf5;
      color: #047857;
      font-size: 11px;
      font-weight: 700;
      padding: 3px 9px;
      border-radius: 6px;
      font-family: 'Inter', sans-serif;
      border: 1px solid #a7f3d0;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .student-badge-blue {
      background: #eff6ff;
      color: #1d4ed8;
      font-size: 11px;
      font-weight: 700;
      padding: 3px 9px;
      border-radius: 6px;
      border: 1px solid #bfdbfe;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .crc-actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .crc-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 6px 14px;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 600;
      font-family: 'Inter', sans-serif;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.15s ease-in-out;
      border: none;
    }
    .crc-btn:hover {
      text-decoration: none;
      transform: translateY(-1px);
    }
    .crc-btn:hover {
      text-decoration: none;
      transform: translateY(-1px);
    }
    .crc-btn.open-btn {
      border: 1.5px solid #10b981;
      background: #f0fdf4;
      color: #059669;
    }
    .crc-btn.open-btn:hover {
      background: #dcfce7;
    }
    .crc-btn.record-btn {
      border: 1.5px solid #3b82f6;
      background: #eff6ff;
      color: #1d4ed8;
    }
    .crc-btn.record-btn:hover {
      background: #dbeafe;
    }

    @media(max-width: 768px) {
      .top-stats-card { grid-template-columns: 1fr; gap: 16px; }
      .class-row-card { flex-direction: column; align-items: flex-start; gap: 16px; }
      .crc-actions { width: 100%; justify-content: flex-end; }
    }

    /* ── Empty state ── */
    .empty-state {
      text-align: center; padding: 64px 24px;
      background: #fff; border-radius: 18px; border: 1px solid #e2e8f0;
    }
    .empty-icon {
      width: 76px; height: 76px; border-radius: 50%;
      background: linear-gradient(135deg, rgba(23,146,187,.08), rgba(23,146,187,.03));
      border: 2px dashed rgba(23,146,187,.22);
      display: flex; align-items: center; justify-content: center; margin: 0 auto 18px;
    }
    .empty-icon i { font-size: 30px; color: rgba(23,146,187,.45); }
    .empty-state h5 { font-size: 16px; font-weight: 700; color: #374151; margin: 0 0 6px; }
    .empty-state p { font-size: 13px; color: #94a3b8; margin: 0; }

    footer.mc-footer {
      text-align: center; padding: 14px; font-size: 11px;
      color: #94a3b8; border-top: 1px solid #e2e8f0; background: #fff;
    }

    /* ── Responsive ── */
    @media (max-width: 768px) {
      .mc-content { padding: 16px 14px 40px; }
    }

    /* ── Tab switcher ── */
    .tab-bar{display:flex;gap:4px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:5px;margin-bottom:20px;}
    .tab-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border:none;background:transparent;border-radius:8px;font-size:13px;font-weight:600;color:#64748b;cursor:pointer;font-family:'Inter',sans-serif;transition:all .15s;white-space:nowrap;}
    .tab-btn:hover{background:#f1f5f9;color:#0f172a;}
    .tab-btn.active{background:linear-gradient(135deg,#1792bb,#0f5f80);color:#fff;box-shadow:0 2px 8px rgba(23,146,187,.3);}
    .tab-btn .tab-badge{background:rgba(255,255,255,.25);color:#fff;padding:1px 7px;border-radius:99px;font-size:10px;font-weight:700;}
    .tab-btn:not(.active) .tab-badge{background:#f1f5f9;color:#64748b;}
    .tab-panel{display:none;}
    .tab-panel.active{display:block;}
    /* Archived card style */
    .cc.archived{opacity:.85;}
    .cc.archived .cc-banner{filter:grayscale(25%);}
    .chip-archive{background:#fef3c7;color:#92400e;}
    @media(max-width:600px){.hide-mobile{display:none !important;}}
  </style>
</head>
<body>
<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<aside class="s-sidebar" id="sidebar">
  <div class="s-brand">
    <div class="s-logo"><i class="fa fa-graduation-cap"></i></div>
    <h2>Cen<span>Learn</span></h2>
    <p>Learning Management System</p>
  </div>
  <nav class="s-nav">
    <div class="s-nav-sec">Student Menu</div>
    <ul>
      <li><a href="dashboard.php"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li class="active"><a href="classes.php"><i class="fa fa-book"></i> My Classes</a></li>
      <li><a href="quizzes.php"><i class="fa fa-question-circle"></i> My Quizzes</a></li>
    </ul>
  </nav>
  <div class="s-footer">
    <div class="s-user">
      <div class="s-av"><?php echo $initials; ?></div>
      <div class="s-meta">
        <strong><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></strong>
        <span>Student &bull; <?php echo htmlspecialchars($user['program_code'] ?: 'No program'); ?></span>
      </div>
    </div>
    <a href="../logout.php" class="s-out"><i class="fa fa-sign-out"></i> Sign Out</a>
  </div>
</aside>

<div class="mc-main">
  <header class="mc-topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
      <div>
        <h3>My Classes</h3>
        <p><?php echo $classCount; ?> class<?php echo $classCount!==1?'es':''; ?> enrolled<?php echo $pendingConfirmCount>0?' &bull; '.$pendingConfirmCount.' pending':''; ?><?php echo $availCount>0?' &bull; '.$availCount.' available':''; ?></p>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <a href="program.php" class="btn-primary-sm" style="background:#fff;color:#1792bb;border:1.5px solid #1792bb;position:relative;width:34px;height:34px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;" title="My Program">
        <i class="fa fa-university" style="font-size:14px;color:#1792bb;"></i>
      </a>
    </div>
  </header>

  <div class="mc-content">

    <div class="top-stats-card">
      <div class="stat-item">
        <div class="stat-circle" style="background:#dcfce7; color:#10b981;"><i class="fa fa-book"></i></div>
        <div class="stat-info">
          <span>Enrolled Classes</span>
          <strong style="color:#10b981;"><?php echo $classCount; ?></strong>
        </div>
      </div>

      <div class="stat-item">
        <div class="stat-circle" style="background:#ffedd5; color:#f59e0b;"><i class="fa fa-history"></i></div>
        <div class="stat-info">
          <span>Class History</span>
          <strong style="color:#f59e0b;"><?php echo $archivedCount; ?></strong>
        </div>
      </div>
      <?php if($user['program_code']): ?>
      <div class="stat-item">
        <div class="stat-circle" style="background:#f3e8ff; color:#9333ea;"><i class="fa fa-graduation-cap"></i></div>
        <div class="stat-info">
          <span>Program / Level</span>
          <strong style="color:#9333ea; font-size:16px;"><?php echo htmlspecialchars($user['program_code']); ?><?php echo $user['year_level'] ? ' - Y'.$user['year_level'] : ''; ?></strong>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Filter & Tab bar row -->
    <div class="filter-tabs-row">
      <div class="search-box-modern">
        <i class="fa fa-search"></i>
        <input type="text" id="classSearch" placeholder="Search by class name, subject, or teacher…" oninput="filterCards()">
      </div>

      <div class="tabs-wrapper">
        <button class="tab-btn-modern active" onclick="switchTab('tab-active',this)">
          My Classes
          <span class="badge-num"><?php echo $classCount; ?></span>
        </button>
        <button class="tab-btn-modern" onclick="switchTab('tab-history',this)">
          History
          <span class="badge-num"><?php echo $archivedCount; ?></span>
        </button>
      </div>
    </div>

    <!-- ── ACTIVE CLASSES TAB ── -->
    <div class="tab-panel active" id="tab-active">

      <?php if($classCount === 0 && $availCount === 0): ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fa fa-book"></i></div>
        <h5>No classes available</h5>
        <p>You will see classes here when created for your program and section.</p>
      </div>
      <?php else: ?>

      <?php if($classCount > 0): ?>
      <div class="sec-hdr" style="margin-bottom:16px;">
        <h4 style="font-size:15px; font-weight:700; color:#0f172a; margin:0; display:flex; align-items:center; gap:8px;">
          <i class="fa fa-book" style="color:#10b981;"></i> Enrolled Classes
        </h4>
        <span style="font-size:13px; color:#64748b; font-weight:500;" id="visibleCount"><?php echo $classCount; ?> class<?php echo $classCount!==1?'es':''; ?></span>
      </div>

      <div id="classesGrid">
        <?php
        while($c = $my_classes->fetch_assoc()):
          $teacherName = trim($c['teacher_first'].' '.$c['teacher_last']);
          $isPending   = ($c['confirm_status'] === 'pending');
        ?>
        <div class="class-row-card" data-name="<?php echo strtolower(htmlspecialchars($c['class_name'].' '.$c['subject'].' '.$teacherName)); ?>">
          <div class="crc-left">
            <div class="crc-info">
              <h5 class="crc-title"><?php echo htmlspecialchars($c['class_name']); ?></h5>
              <div class="crc-meta">
                <span class="code-badge-green"><i class="fa fa-book" style="margin-right:3px;font-size:9px;"></i> Subject Code: <?php echo htmlspecialchars($c['display_code'] ?: $c['class_code']); ?></span>
                <span class="dot">&bull;</span>
                <span><i class="fa fa-user" style="color:#3b82f6; margin-right:4px;"></i><?php echo htmlspecialchars($teacherName); ?></span>
                <span class="dot">&bull;</span>
                <span><?php echo htmlspecialchars($c['program_code'] ?: 'N/A'); ?></span>
                <span class="dot">&bull;</span>
                <span>Year <?php echo $c['year_level'] ?: '1'; ?></span>
                <span class="dot">&bull;</span>
                <span>Sec <?php echo htmlspecialchars($c['section'] ?: 'A'); ?></span>
              </div>
              <?php
              $hasGrades = false;
              $gradesHtml = '';
              if (isset($publishedGradesMap[$c['id']])) {
                  foreach (['midterm', 'final'] as $t) {
                      if (isset($publishedGradesMap[$c['id']][$t])) {
                          $pg = $publishedGradesMap[$c['id']][$t];
                          $termLabel = ucfirst($t);
                          $statusColor = $pg['remarks'] === 'Passed' ? '#166534' : '#991b1b';
                          $statusBg = $pg['remarks'] === 'Passed' ? '#dcfce7' : '#fee2e2';
                          $gradesHtml .= "<span style='display:inline-flex; align-items:center; gap:5px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:3px 8px; font-size:11px;'>
                              <strong style='color:#64748b;'>$termLabel:</strong>
                              <span style='font-weight:800; color:#0f172a;'>{$pg['grade']}%</span>
                              <span style='font-weight:700; color:#5b21b6;'>({$pg['transmuted']})</span>
                              <span style='font-weight:800; background:$statusBg; color:$statusColor; padding:1px 5px; border-radius:4px; font-size:10px;'>{$pg['remarks']}</span>
                          </span>";
                          $hasGrades = true;
                      }
                  }
              }
              if ($hasGrades):
              ?>
              <div style="margin-top: 8px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">
                  <span style="font-size:11px; color:#64748b; font-weight:700;"><i class="fa fa-graduation-cap" style="color:#8b5cf6;"></i> Released Grades:</span>
                  <?php echo $gradesHtml; ?>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="crc-actions">
            <?php if($isPending): ?>
            <a href="inbox.php" class="crc-btn" style="border:1.5px solid #f59e0b; background:#fffbe6; color:#d97706;">
              <i class="fa fa-clock-o"></i> Confirm Schedule
            </a>
            <a href="../shared/class_view.php?id=<?php echo $c['id']; ?>" class="crc-btn open-btn" title="Preview Class">
              <i class="fa fa-eye"></i> View
            </a>
            <?php else: ?>
            <a href="../shared/class_view.php?id=<?php echo $c['id']; ?>" class="crc-btn open-btn">
              <i class="fa fa-folder-open"></i> Open Class
            </a>
            <button type="button" class="crc-btn" onclick="leaveClass(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['class_name'])); ?>')" style="border:1.5px solid #fca5a5; background:#fff; color:#ef4444; margin-left:4px;" title="Leave this class">
              <i class="fa fa-sign-out"></i> Leave
            </button>
            <?php endif; ?>
          </div>
        </div>
        <?php endwhile; ?>
      </div>
      <?php endif; ?>


      <?php endif; ?>
    </div><!-- /tab-active -->
    <div class="tab-panel" id="tab-history">

      <?php if($archivedCount > 0): ?>
      <div class="sec-hdr" style="margin-bottom:16px;">
        <h4 style="font-size:15px; font-weight:700; color:#0f172a; margin:0; display:flex; align-items:center; gap:8px;">
          <i class="fa fa-history" style="color:#92400e;"></i> Class History
        </h4>
        <span style="font-size:13px; color:#64748b; font-weight:500;"><?php echo $archivedCount; ?> archived</span>
      </div>

      <div id="historyGrid">
        <?php
        while($c = $archived_classes->fetch_assoc()):
          $teacherName3 = trim($c['teacher_first'].' '.$c['teacher_last']);
        ?>
        <div class="class-row-card archived" data-name="<?php echo strtolower(htmlspecialchars($c['class_name'].' '.$c['subject'].' '.$teacherName3.' '.($c['school_year']??''))); ?>" style="opacity:0.88;">
          <div class="crc-left">
            <div class="crc-info">
              <h5 class="crc-title"><?php echo htmlspecialchars($c['class_name']); ?></h5>
              <div class="crc-meta">
                <span><i class="fa fa-user" style="color:#64748b; margin-right:4px;"></i><?php echo htmlspecialchars($teacherName3); ?></span>
                <span class="dot">&bull;</span>
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
              <i class="fa fa-eye"></i> View Records
            </a>
          </div>
        </div>
        <?php endwhile; ?>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon" style="border-color:rgba(245,158,11,.22);background:linear-gradient(135deg,rgba(245,158,11,.08),rgba(245,158,11,.03));">
          <i class="fa fa-history" style="color:rgba(245,158,11,.5);"></i>
        </div>
        <h5>No class history yet</h5>
        <p>Classes from previous school years will appear here once your teacher archives them.</p>
      </div>
      <?php endif; ?>

    </div><!-- /tab-history -->

  </div>
  <footer class="mc-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script src="../bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
// ── Tab switching ──────────────────────────────────────────────────────────
function switchTab(tabId, btn){
  document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
  document.querySelectorAll('.tab-btn-modern').forEach(function(b){ b.classList.remove('active'); });
  document.getElementById(tabId).classList.add('active');
  btn.classList.add('active');
}

function filterCards(){
  var q = document.getElementById('classSearch').value.toLowerCase();
  var cards = document.querySelectorAll('#classesGrid .class-row-card, #availableGrid .class-row-card');
  var visible = 0;
  cards.forEach(function(c){
    var match = c.dataset.name.includes(q);
    c.style.display = match ? 'flex' : 'none';
    if(match) visible++;
  });
  var el = document.getElementById('visibleCount');
  if(el) el.textContent = visible + ' class' + (visible !== 1 ? 'es' : '');
}

function filterHistory(){
  var q = document.getElementById('classSearch').value.toLowerCase();
  document.querySelectorAll('#historyGrid .class-row-card').forEach(function(c){
    c.style.display = c.dataset.name.includes(q) ? 'flex' : 'none';
  });
}

function quickJoin(classId, btn){
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Joining…';
  $.post('../shared/class_save.php', { action: 'join_by_id', class_id: classId }, function(res){
    if(res.success){
      btn.innerHTML = '<i class="fa fa-check"></i> Joined!';
      setTimeout(function(){ location.reload(); }, 900);
    } else {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa fa-sign-in"></i> Join Class';
      alert(res.msg || 'Could not join.');
    }
  }, 'json');
}
function leaveClass(classId, className){
  if(confirm("Are you sure you want to leave '" + className + "'? You will no longer have access to this class.")){
    $.post('../shared/class_save.php', { action: 'leave_class', class_id: classId }, function(res){
      if(res.success){
        alert(res.msg || 'You have left the class.');
        location.reload();
      } else {
        alert(res.msg || 'Could not leave class.');
      }
    }, 'json');
  }
}
function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
</script>
</body>
</html>
