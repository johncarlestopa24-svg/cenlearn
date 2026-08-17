<?php
include '../includes/session.php';
include '../includes/conn.php';

$role = strtoupper($user['user_group']);
if($role !== 'ADMIN'){ header('location: ../index.php?role_mismatch=admin'); exit; }

// ── Department color theme ────────────────────────────────────────────────
$dept = strtoupper(trim($user['department'] ?? ''));

// Fallback: detect dept from assigned courses if department field is empty
if(empty($dept) && !empty($user['program_description'])){
    $COURSE_TO_DEPT = [
        'IS'                 => 'IS',
        'CRIM'               => 'CRIM',
        'BSED-FILIPINO'      => 'EDUC', 'BSED-MATHEMATICS'   => 'EDUC',
        'BSED-SOCIAL STUDIES'=> 'EDUC', 'BPED'               => 'EDUC', 'BEED' => 'EDUC',
        'BSOA'               => 'ART',  'AB HISTORY'         => 'ART',  'AB ENGLISH' => 'ART',
    ];
    foreach(explode(',', $user['program_description']) as $c){
        $c = strtoupper(trim($c));
        if(isset($COURSE_TO_DEPT[$c])){ $dept = $COURSE_TO_DEPT[$c]; break; }
    }
}
$DEPT_THEMES = [
    'IS'   => [
        'name'    => 'Information Systems',
        'icon'    => 'fa-desktop',
        'dark'    => '#052e16', 'mid'  => '#14532d', 'base' => '#16a34a',
        'light'   => '#4ade80', 'bg'   => '#f0fdf4', 'border' => '#dcfce7',
        'text'    => '#15803d', 'shadow' => 'rgba(22,163,74,.28)',
        'stat1'   => ['g1'=>'#16a34a','g2'=>'#15803d','sh'=>'rgba(22,163,74,.3)'],
        'stat2'   => ['g1'=>'#0d9488','g2'=>'#0f766e','sh'=>'rgba(13,148,136,.3)'],
        'stat3'   => ['g1'=>'#6366f1','g2'=>'#4338ca','sh'=>'rgba(99,102,241,.3)'],
    ],
    'CRIM' => [
        'name'    => 'Criminology',
        'icon'    => 'fa-balance-scale',
        'dark'    => '#450a0a', 'mid'  => '#7f1d1d', 'base' => '#dc2626',
        'light'   => '#f87171', 'bg'   => '#fff1f2', 'border' => '#fecdd3',
        'text'    => '#b91c1c', 'shadow' => 'rgba(220,38,38,.28)',
        'stat1'   => ['g1'=>'#dc2626','g2'=>'#b91c1c','sh'=>'rgba(220,38,38,.3)'],
        'stat2'   => ['g1'=>'#e11d48','g2'=>'#be123c','sh'=>'rgba(225,29,72,.3)'],
        'stat3'   => ['g1'=>'#9333ea','g2'=>'#7e22ce','sh'=>'rgba(147,51,234,.3)'],
    ],
    'EDUC' => [
        'name'    => 'Education',
        'icon'    => 'fa-graduation-cap',
        'dark'    => '#172554', 'mid'  => '#1e3a8a', 'base' => '#2563eb',
        'light'   => '#60a5fa', 'bg'   => '#eff6ff', 'border' => '#bfdbfe',
        'text'    => '#1d4ed8', 'shadow' => 'rgba(37,99,235,.28)',
        'stat1'   => ['g1'=>'#2563eb','g2'=>'#1d4ed8','sh'=>'rgba(37,99,235,.3)'],
        'stat2'   => ['g1'=>'#0891b2','g2'=>'#0e7490','sh'=>'rgba(8,145,178,.3)'],
        'stat3'   => ['g1'=>'#7c3aed','g2'=>'#6d28d9','sh'=>'rgba(124,58,237,.3)'],
    ],
    'ART'  => [
        'name'    => 'Arts',
        'icon'    => 'fa-paint-brush',
        'dark'    => '#451a03', 'mid'  => '#78350f', 'base' => '#d97706',
        'light'   => '#fbbf24', 'bg'   => '#fffbeb', 'border' => '#fde68a',
        'text'    => '#b45309', 'shadow' => 'rgba(217,119,6,.28)',
        'stat1'   => ['g1'=>'#d97706','g2'=>'#b45309','sh'=>'rgba(217,119,6,.3)'],
        'stat2'   => ['g1'=>'#ea580c','g2'=>'#c2410c','sh'=>'rgba(234,88,12,.3)'],
        'stat3'   => ['g1'=>'#0891b2','g2'=>'#0e7490','sh'=>'rgba(8,145,178,.3)'],
    ],
];
// Fallback to IS/green if dept not matched
$T = $DEPT_THEMES[$dept] ?? $DEPT_THEMES['IS'];

// ── Course list ───────────────────────────────────────────────────────────
$adminCourses = [];
if(!empty($user['program_description'])){
    foreach(explode(',', $user['program_description']) as $c){
        $c = strtoupper(trim($c));
        if($c !== '') $adminCourses[] = $c;
    }
}
$courseInList = '';
if(!empty($adminCourses)){
    $escaped      = array_map(function($c) use($conn){ return "'".$conn->real_escape_string($c)."'"; }, $adminCourses);
    $courseInList = implode(',', $escaped);
}

// ── Teachers ──────────────────────────────────────────────────────────────
$teacherRows = [];
if(!empty($courseInList)){
    $tq = $conn->query("
        SELECT DISTINCT u.*
        FROM users u
        INNER JOIN classes c ON c.teacher_code = u.user_code
            AND UPPER(c.program_code) IN ($courseInList)
        WHERE u.user_group = 'TEACHER'
        ORDER BY u.last_name, u.first_name
    ");
    while($t = $tq->fetch_assoc()){
        $tc = $conn->real_escape_string($t['user_code']);
        $t['class_count'] = $conn->query("SELECT COUNT(*) as cnt FROM classes WHERE teacher_code='$tc' AND UPPER(program_code) IN ($courseInList)")->fetch_assoc()['cnt'];
        $tcq = $conn->query("SELECT DISTINCT program_code FROM classes WHERE teacher_code='$tc' AND UPPER(program_code) IN ($courseInList) AND program_code != ''");
        $t['courses'] = [];
        while($row = $tcq->fetch_assoc()) $t['courses'][] = $row['program_code'];
        $teacherRows[] = $t;
    }
}

// ── Classes ───────────────────────────────────────────────────────────────
$classRows = [];
if(!empty($courseInList)){
    $cq = $conn->query("
        SELECT c.*, u.first_name, u.last_name,
               COUNT(DISTINCT cm.id) as student_count
        FROM classes c
        LEFT JOIN users u ON c.teacher_code = u.user_code
        LEFT JOIN class_members cm ON c.id = cm.class_id AND cm.user_code != c.teacher_code
        WHERE UPPER(c.program_code) IN ($courseInList)
        GROUP BY c.id ORDER BY c.created_at DESC
    ");
    while($c = $cq->fetch_assoc()) $classRows[] = $c;
}

// ── Violations ─────────────────────────────────────────────────────────────
$violationRows = [];
$totalViolations = 0;
$unresolvedViolations = 0;
if(!empty($courseInList)){
    $vq = $conn->query("
        SELECT tv.*, u.first_name, u.last_name, c.class_name, c.class_code
        FROM teacher_violations tv
        INNER JOIN users u ON tv.teacher_code = u.user_code
        INNER JOIN classes c ON tv.class_id = c.id
        WHERE UPPER(c.program_code) IN ($courseInList)
        ORDER BY tv.created_at DESC
    ");
    while($v = $vq->fetch_assoc()){
        $violationRows[] = $v;
        $totalViolations++;
        if(!$v['resolved_at']) $unresolvedViolations++;
    }
}

// ── Students ──────────────────────────────────────────────────────────────
$totalStudents = 0;
if(!empty($courseInList)){
    $totalStudents = $conn->query("SELECT COUNT(DISTINCT user_code) as cnt FROM users WHERE user_group='STUDENT' AND UPPER(program_code) IN ($courseInList)")->fetch_assoc()['cnt'];
}
$totalTeachers = count($teacherRows);
$totalClasses  = count($classRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Admin Dashboard</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    :root {
      --c-dark:   <?php echo $T['dark']; ?>;
      --c-mid:    <?php echo $T['mid']; ?>;
      --c-base:   <?php echo $T['base']; ?>;
      --c-light:  <?php echo $T['light']; ?>;
      --c-bg:     <?php echo $T['bg']; ?>;
      --c-border: <?php echo $T['border']; ?>;
      --c-text:   <?php echo $T['text']; ?>;
      --c-shadow: <?php echo $T['shadow']; ?>;
      --sl50:  #f8fafc; --sl100: #f1f5f9; --sl200: #e2e8f0;
      --sl400: #94a3b8; --sl600: #475569; --sl800: #1e293b;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background: var(--sl100); color: var(--sl800); }

    /* ── Sidebar ── */
    .cl-sidebar { background: linear-gradient(175deg, var(--c-dark) 0%, var(--c-mid) 100%) !important; }
    .cl-sidebar .sidebar-brand h2 { color: #fff !important; }
    .cl-sidebar .sidebar-brand h2 span { color: var(--c-light) !important; }
    .cl-sidebar .sidebar-brand p { color: rgba(255,255,255,.45) !important; }
    .cl-sidebar .nav-section { color: rgba(255,255,255,.3) !important; font-size: 10px !important; letter-spacing: 1.2px !important; }
    .cl-sidebar .nav-item a { color: rgba(255,255,255,.7) !important; border-radius: 10px !important; margin: 2px 8px !important; }
    .cl-sidebar .nav-item a:hover { background: rgba(255,255,255,.1) !important; color: #fff !important; }
    .cl-sidebar .nav-item.active a { background: rgba(255,255,255,.15) !important; color: #fff !important; border-left: 3px solid var(--c-light) !important; font-weight: 600 !important; }
    .cl-sidebar .sidebar-footer { border-top: 1px solid rgba(255,255,255,.08) !important; }
    .cl-sidebar .user-meta strong { color: #fff !important; }
    .cl-sidebar .user-meta span { color: rgba(255,255,255,.45) !important; }
    .cl-sidebar .btn-signout { background: rgba(255,255,255,.07) !important; color: rgba(255,255,255,.65) !important; border-radius: 10px !important; }
    .cl-sidebar .btn-signout:hover { background: rgba(255,255,255,.14) !important; color: #fff !important; }

    /* ── Topbar ── */
    .cl-topbar { border-bottom: 1px solid var(--sl200) !important; background: #fff !important; }
    .topbar-title h1 { font-size: 18px !important; font-weight: 700 !important; color: var(--sl800) !important; }
    .topbar-title p  { font-size: 12px !important; color: var(--sl400) !important; }

    /* ── Layout ── */
    .adm-content { padding: 28px 32px; }
    @media(max-width:768px){ .adm-content { padding: 16px; } }

    /* ── Hero banner ── */
    .hero-banner {
      border-radius: 20px; padding: 28px 32px; margin-bottom: 28px;
      background: linear-gradient(135deg, var(--c-dark) 0%, var(--c-mid) 55%, var(--c-base) 100%);
      box-shadow: 0 8px 32px var(--c-shadow);
      display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
      position: relative; overflow: hidden;
    }
    .hero-banner::before {
      content: ''; position: absolute; right: -60px; top: -60px;
      width: 220px; height: 220px; border-radius: 50%;
      background: rgba(255,255,255,.06); pointer-events: none;
    }
    .hero-banner::after {
      content: ''; position: absolute; right: 80px; bottom: -70px;
      width: 160px; height: 160px; border-radius: 50%;
      background: rgba(255,255,255,.04); pointer-events: none;
    }
    .hero-dept-icon {
      width: 60px; height: 60px; border-radius: 16px; flex-shrink: 0;
      background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.2);
      display: flex; align-items: center; justify-content: center;
      backdrop-filter: blur(4px);
    }
    .hero-dept-icon i { font-size: 26px; color: #fff; }
    .hero-info { flex: 1; min-width: 0; }
    .hero-info .hi-greeting { font-size: 12px; font-weight: 600; color: rgba(255,255,255,.55); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
    .hero-info .hi-name { font-size: 20px; font-weight: 800; color: #fff; margin-bottom: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hero-info .hi-dept { font-size: 13px; color: rgba(255,255,255,.7); display: flex; align-items: center; gap: 6px; }
    .hero-info .hi-dept strong { color: var(--c-light); font-weight: 700; }
    .hero-courses { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-left: auto; }
    .hero-courses .hc-label { font-size: 11px; font-weight: 600; color: rgba(255,255,255,.5); text-transform: uppercase; letter-spacing: .8px; }
    .hero-courses .hc-pill {
      background: rgba(255,255,255,.15); color: #fff;
      border: 1px solid rgba(255,255,255,.25);
      padding: 5px 14px; border-radius: 20px;
      font-size: 12px; font-weight: 700; letter-spacing: .3px;
      backdrop-filter: blur(4px);
    }

    /* ── Stats ── */
    .stats-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 20px; margin-bottom: 28px; }
    @media(max-width:640px){ .stats-grid { grid-template-columns: 1fr 1fr; } }
    .stat-card {
      background: #fff; border-radius: 16px; padding: 22px 24px;
      border: 1px solid var(--sl200);
      box-shadow: 0 1px 3px rgba(0,0,0,.05), 0 4px 14px rgba(0,0,0,.04);
      display: flex; align-items: center; gap: 16px;
      transition: transform .18s, box-shadow .18s;
    }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 28px rgba(0,0,0,.1); }
    .stat-icon { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .stat-icon i { font-size: 22px; color: #fff; }
    .stat-body strong { display: block; font-size: 30px; font-weight: 800; color: var(--sl800); line-height: 1; }
    .stat-body span   { font-size: 12px; color: var(--sl400); margin-top: 5px; display: block; font-weight: 500; }
    .stat-card .stat-trend { font-size: 11px; font-weight: 600; color: var(--c-text); margin-top: 2px; display: flex; align-items: center; gap: 3px; }

    /* ── Section header ── */
    .sec-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
    .sec-header h4 { font-size: 15px; font-weight: 700; color: var(--sl800); display: flex; align-items: center; gap: 9px; margin: 0; }
    .sec-header h4 .sec-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--c-base); display: inline-block; box-shadow: 0 0 0 3px var(--c-border); }
    .sec-count { background: var(--c-bg); color: var(--c-text); padding: 4px 13px; border-radius: 20px; font-size: 12px; font-weight: 700; border: 1px solid var(--c-border); }

    /* ── Data card ── */
    .data-card { background: #fff; border-radius: 16px; border: 1px solid var(--sl200); box-shadow: 0 1px 3px rgba(0,0,0,.05), 0 4px 14px rgba(0,0,0,.04); overflow: hidden; margin-bottom: 28px; }
    .data-card-header { padding: 16px 24px; border-bottom: 1px solid var(--sl100); display: flex; align-items: center; gap: 12px; }
    .dch-icon { width: 34px; height: 34px; border-radius: 9px; background: var(--c-bg); border: 1px solid var(--c-border); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .dch-icon i { color: var(--c-base); font-size: 14px; }
    .dch-title { font-size: 14px; font-weight: 700; color: var(--sl800); }
    .dch-sub   { font-size: 12px; color: var(--sl400); margin-left: auto; }

    /* ── Table ── */
    .data-table { width: 100%; border-collapse: collapse; }
    .data-table thead th { padding: 11px 20px; font-size: 10.5px; font-weight: 700; color: var(--sl400); text-transform: uppercase; letter-spacing: .7px; background: var(--sl50); border-bottom: 1px solid var(--sl200); white-space: nowrap; }
    .data-table tbody tr { border-bottom: 1px solid var(--sl100); transition: background .1s; }
    .data-table tbody tr:last-child { border-bottom: none; }
    .data-table tbody tr:hover { background: var(--c-bg); }
    .data-table tbody td { padding: 14px 20px; font-size: 13px; color: #374151; vertical-align: middle; }

    /* ── User cell ── */
    .user-cell { display: flex; align-items: center; gap: 11px; }
    .u-avatar {
      width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
      background: linear-gradient(135deg, var(--c-base), var(--c-mid));
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 2px 8px var(--c-shadow);
    }
    .u-avatar i { color: #fff; font-size: 14px; }
    .u-name  { font-size: 13px; font-weight: 600; color: var(--sl800); display: block; }
    .u-code  { font-size: 11px; color: var(--sl400); display: block; }

    /* ── Badges ── */
    .badge-on  { background: #dcfce7; color: #15803d; padding: 3px 11px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    .badge-off { background: var(--sl100); color: var(--sl400); padding: 3px 11px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    .course-pill { display: inline-block; background: var(--c-bg); color: var(--c-text); border: 1px solid var(--c-border); padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; margin: 1px; }
    .count-chip { display: inline-flex; align-items: center; gap: 5px; background: var(--c-bg); color: var(--c-text); border: 1px solid var(--c-border); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; }

    /* ── Empty state ── */
    .empty-row td { text-align: center; padding: 56px 20px !important; color: var(--sl400); }
    .empty-icon { width: 54px; height: 54px; border-radius: 14px; background: var(--c-bg); border: 1px solid var(--c-border); display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; }
    .empty-icon i { font-size: 22px; color: var(--c-base); }
    .empty-row p { font-size: 13px; margin: 0; }

    /* ── No-courses card ── */
    .no-courses-card { background: #fff; border-radius: 16px; padding: 64px 24px; text-align: center; border: 1px solid var(--sl200); box-shadow: 0 1px 3px rgba(0,0,0,.05); }
    .nc-icon { width: 68px; height: 68px; border-radius: 20px; background: linear-gradient(135deg, var(--c-base), var(--c-mid)); display: flex; align-items: center; justify-content: center; margin: 0 auto 22px; box-shadow: 0 8px 24px var(--c-shadow); }
    .nc-icon i { font-size: 30px; color: #fff; }
    .no-courses-card h4 { font-size: 18px; font-weight: 700; color: var(--sl800); margin-bottom: 10px; }
    .no-courses-card p  { font-size: 14px; color: var(--sl400); max-width: 360px; margin: 0 auto; line-height: 1.7; }
  </style>
</head>
<body>

<!-- Sidebar overlay -->
<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- Sidebar -->
<aside class="cl-sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="logo-icon" style="background:linear-gradient(135deg,<?php echo $T['base']; ?>,<?php echo $T['mid']; ?>);box-shadow:0 4px 14px <?php echo $T['shadow']; ?>;">
      <i class="fa <?php echo $T['icon']; ?>"></i>
    </div>
    <h2>Cen<span>Learn</span></h2>
    <p><?php echo htmlspecialchars($T['name']); ?> Department</p>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Management</div>
    <ul style="list-style:none;padding:0;margin:0;">
      <li class="nav-item active">
        <a href="dashboard.php"><i class="fa fa-th-large"></i> Dashboard</a>
      </li>
      <li class="nav-item">
        <a href="students.php"><i class="fa fa-users"></i> Students</a>
      </li>
      <li class="nav-item">
        <a href="teacher_ratings.php"><i class="fa fa-star"></i> Teacher Ratings</a>
      </li>
    </ul>
  </nav>
  <div class="sidebar-footer">
    <div class="user-info">
      <div class="user-avatar" style="background:linear-gradient(135deg,<?php echo $T['base']; ?>,<?php echo $T['mid']; ?>);">
        <i class="fa fa-user"></i>
      </div>
      <div class="user-meta">
        <strong><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></strong>
        <span><?php echo htmlspecialchars($dept ?: 'Admin'); ?> Department</span>
      </div>
    </div>
    <a href="../logout.php" class="btn-signout"><i class="fa fa-sign-out"></i> Sign Out</a>
  </div>
</aside>

<!-- Main -->
<div class="cl-main">
  <header class="cl-topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
      <div class="topbar-title">
        <h1><?php echo htmlspecialchars($T['name']); ?> Department</h1>
        <p>Teacher &amp; Class Management</p>
      </div>
    </div>
    <div class="topbar-right">
      <span class="topbar-badge" style="background:var(--c-bg);color:var(--c-text);border:1px solid var(--c-border);">
        <i class="fa <?php echo $T['icon']; ?>"></i> <?php echo htmlspecialchars($dept ?: 'Admin'); ?>
      </span>
    </div>
  </header>

  <div class="adm-content">

    <?php if(empty($adminCourses)): ?>
    <!-- No courses assigned -->
    <div class="no-courses-card">
      <div class="nc-icon"><i class="fa fa-exclamation-triangle"></i></div>
      <h4>No Courses Assigned</h4>
      <p>Your admin account has no courses assigned yet. Please contact the Super Admin to assign courses to your account.</p>
    </div>

    <?php else: ?>

    <!-- Hero banner -->
    <div class="hero-banner">
      <div class="hero-dept-icon"><i class="fa <?php echo $T['icon']; ?>"></i></div>
      <div class="hero-info">
        <div class="hi-greeting"><?php echo htmlspecialchars($T['name']); ?> Department</div>
        <div class="hi-name"><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></div>
        <div class="hi-dept">
          <i class="fa fa-building" style="font-size:11px;"></i>
          <strong><?php echo htmlspecialchars($T['name']); ?></strong>
          Department
        </div>
      </div>
      <div class="hero-courses">
        <span class="hc-label">Courses:</span>
        <?php foreach($adminCourses as $course): ?>
          <span class="hc-pill"><?php echo htmlspecialchars($course); ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
      <?php
        $stats = [
          ['icon'=>'fa-chalkboard-teacher','val'=>$totalTeachers,'label'=>'Teachers',  's'=>$T['stat1']],
          ['icon'=>'fa-graduation-cap',    'val'=>$totalStudents,'label'=>'Students',  's'=>$T['stat2']],
          ['icon'=>'fa-book-open',         'val'=>$totalClasses, 'label'=>'Classes',   's'=>$T['stat3']],
          ['icon'=>'fa-exclamation-triangle','val'=>$unresolvedViolations,'label'=>'Unresolved Violations','s'=>$T['stat2']],
        ];
        foreach($stats as $s):
      ?>
      <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,<?php echo $s['s']['g1']; ?>,<?php echo $s['s']['g2']; ?>);box-shadow:0 4px 14px <?php echo $s['s']['sh']; ?>;">
          <i class="fa <?php echo $s['icon']; ?>"></i>
        </div>
        <div class="stat-body">
          <strong><?php echo $s['val']; ?></strong>
          <span><?php echo $s['label']; ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Department Insights & Recommendations -->
    <?php if(!empty($adminCourses)): ?>
    <div class="cl-card" style="margin-bottom: 28px;">
      <div class="cl-card-header" style="background: linear-gradient(135deg, var(--sl800), var(--c-dark)); border-bottom: none; padding: 16px 24px;">
        <h3 style="color: #fff;"><i class="fa fa-lightbulb-o" style="color: #fbbf24; font-size: 16px;"></i> Department Insights &amp; Recommendations</h3>
      </div>
      <div class="cl-card-body" style="padding: 20px 24px; background: #fff;">
        <?php
          $recommendations = [];
          
          // 1. Check unresolved violations
          $unresolvedList = [];
          foreach($violationRows as $v){
              if(!$v['resolved_at']){
                  $unresolvedList[] = htmlspecialchars($v['first_name'].' '.$v['last_name']);
              }
          }
          if(!empty($unresolvedList)){
              $uniqueNames = array_unique($unresolvedList);
              $recommendations[] = [
                  'type' => 'danger',
                  'icon' => 'fa-exclamation-circle',
                  'title' => 'Unresolved Teacher Violations',
                  'text' => 'There are <strong>' . count($unresolvedList) . ' unresolved violations</strong> for teacher(s): ' . implode(', ', $uniqueNames) . '. We recommend reviewing and resolving these violations in the log below.'
              ];
          }

          // 2. Check inactive teachers
          $inactiveTeachers = [];
          foreach($teacherRows as $t){
              if(!$t['is_active']){
                  $inactiveTeachers[] = htmlspecialchars($t['first_name'].' '.$t['last_name']);
              }
          }
          if(!empty($inactiveTeachers)){
              $recommendations[] = [
                  'type' => 'warning',
                  'icon' => 'fa-user-times',
                  'title' => 'Inactive Teacher Accounts',
                  'text' => 'The following teacher(s) are currently inactive: <strong>' . implode(', ', $inactiveTeachers) . '</strong>. If they are teaching this term, we recommend coordinating with the Super Admin to activate their accounts.'
              ];
          }

          // 3. Check empty classes
          $emptyClasses = [];
          foreach($classRows as $c){
              if((int)$c['student_count'] === 0){
                  $emptyClasses[] = '<strong>' . htmlspecialchars($c['class_name']) . '</strong> (' . htmlspecialchars($c['class_code']) . ')';
              }
          }
          if(!empty($emptyClasses)){
              $recommendations[] = [
                  'type' => 'info',
                  'icon' => 'fa-info-circle',
                  'title' => 'Classes with No Students Enrolled',
                  'text' => 'The following class(es) have 0 students enrolled: ' . implode(', ', $emptyClasses) . '. We recommend requesting the assigned teachers to sync or enroll students.'
              ];
          }

          // 4. Default check if everything is clean
          if(empty($recommendations)){
              $recommendations[] = [
                  'type' => 'success',
                  'icon' => 'fa-check-circle',
                  'title' => 'Department Status: Excellent',
                  'text' => 'All teachers are active, classes have student enrollments, and there are no unresolved teacher violations. Keep up the great work!'
              ];
          }
          
          foreach($recommendations as $rec):
              $badgeClass = '';
              $borderStyle = '';
              if($rec['type'] === 'danger') {
                  $badgeClass = 'badge-red';
                  $borderStyle = 'border-left: 4px solid #ef4444; background: #fef2f2;';
              } elseif($rec['type'] === 'warning') {
                  $badgeClass = 'badge-amber';
                  $borderStyle = 'border-left: 4px solid #f59e0b; background: #fffbeb;';
              } elseif($rec['type'] === 'success') {
                  $badgeClass = 'badge-green';
                  $borderStyle = 'border-left: 4px solid #10b981; background: #f0fdf4;';
              } else {
                  $badgeClass = 'badge-blue';
                  $borderStyle = 'border-left: 4px solid #1792bb; background: #eff6ff;';
              }
        ?>
        <div style="<?php echo $borderStyle; ?> padding: 14px 16px; border-radius: 8px; margin-bottom: 12px; display: flex; align-items: flex-start; gap: 12px;">
          <div class="badge-cl <?php echo $badgeClass; ?>" style="padding: 6px; border-radius: 8px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; width: 32px; height: 32px;">
            <i class="fa <?php echo $rec['icon']; ?>" style="font-size: 16px; margin: 0;"></i>
          </div>
          <div style="flex: 1;">
            <strong style="display: block; font-size: 13.5px; color: #1e293b; margin-bottom: 3px;"><?php echo $rec['title']; ?></strong>
            <span style="font-size: 12.5px; color: #475569; line-height: 1.5;"><?php echo $rec['text']; ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Teachers Table -->
    <div class="sec-header">
      <h4><span class="sec-dot"></span> Teachers</h4>
      <span class="sec-count"><?php echo $totalTeachers; ?> found</span>
    </div>
    <div class="data-card">
      <div class="data-card-header">
        <div class="dch-icon"><i class="fa fa-users"></i></div>
        <span class="dch-title">Teacher List</span>
        <span class="dch-sub">Filtered by your assigned courses</span>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th><th>Teacher</th><th>Username</th>
              <th>Email</th><th>Courses Teaching</th><th>Status</th><th>Classes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($teacherRows as $i => $t): ?>
            <tr>
              <td style="color:var(--sl400);font-size:12px;font-weight:600;"><?php echo $i+1; ?></td>
              <td>
                <div class="user-cell">
                  <div class="u-avatar"><i class="fa fa-user"></i></div>
                  <div>
                    <span class="u-name"><?php echo htmlspecialchars($t['first_name'].' '.$t['last_name']); ?></span>
                    <span class="u-code"><?php echo htmlspecialchars($t['user_code']); ?></span>
                  </div>
                </div>
              </td>
              <td style="font-weight:500;color:var(--sl600);"><?php echo htmlspecialchars($t['user_code']); ?></td>
              <td style="color:var(--sl400);"><?php echo htmlspecialchars($t['email_address'] ?: '—'); ?></td>
              <td>
                <?php if(!empty($t['courses'])): foreach($t['courses'] as $cp): ?>
                  <span class="course-pill"><?php echo htmlspecialchars($cp); ?></span>
                <?php endforeach; else: ?>
                  <span style="color:var(--sl400);">—</span>
                <?php endif; ?>
              </td>
              <td><span class="<?php echo $t['is_active'] ? 'badge-on':'badge-off'; ?>"><?php echo $t['is_active'] ? 'Active':'Inactive'; ?></span></td>
              <td><span class="count-chip"><i class="fa fa-book"></i><?php echo $t['class_count']; ?></span></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($teacherRows)): ?>
            <tr class="empty-row"><td colspan="7">
              <div class="empty-icon"><i class="fa fa-users"></i></div>
              <p>No teachers found for your assigned courses.</p>
            </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Classes Table -->
    <div class="sec-header">
      <h4><span class="sec-dot"></span> Classes</h4>
      <span class="sec-count"><?php echo $totalClasses; ?> found</span>
    </div>
    <div class="data-card">
      <div class="data-card-header">
        <div class="dch-icon"><i class="fa fa-book"></i></div>
        <span class="dch-title">Class List</span>
        <span class="dch-sub">Filtered by your assigned courses</span>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th><th>Class</th><th>Subject</th><th>Course</th>
              <th>Section</th><th>Teacher</th><th>Students</th><th>Created</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($classRows as $j => $c): ?>
            <tr>
              <td style="color:var(--sl400);font-size:12px;font-weight:600;"><?php echo $j+1; ?></td>
              <td>
                <strong style="color:var(--sl800);font-size:13px;"><?php echo htmlspecialchars($c['class_name']); ?></strong><br>
                <span style="font-size:11px;color:var(--sl400);letter-spacing:.8px;font-weight:600;"><?php echo htmlspecialchars($c['class_code']); ?></span>
              </td>
              <td style="color:var(--sl600);"><?php echo htmlspecialchars($c['subject'] ?: '—'); ?></td>
              <td><?php if(!empty($c['program_code'])): ?><span class="course-pill"><?php echo htmlspecialchars($c['program_code']); ?></span><?php else: ?>—<?php endif; ?></td>
              <td style="color:var(--sl600);"><?php echo htmlspecialchars($c['section'] ?: '—'); ?></td>
              <td>
                <div class="user-cell">
                  <div class="u-avatar" style="width:28px;height:28px;border-radius:7px;"><i class="fa fa-user" style="font-size:11px;"></i></div>
                  <span style="font-size:13px;color:var(--sl600);"><?php echo htmlspecialchars(trim($c['first_name'].' '.$c['last_name']) ?: '—'); ?></span>
                </div>
              </td>
              <td><span class="count-chip"><i class="fa fa-users"></i><?php echo (int)$c['student_count']; ?></span></td>
              <td style="color:var(--sl400);font-size:12px;"><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($classRows)): ?>
            <tr class="empty-row"><td colspan="8">
              <div class="empty-icon"><i class="fa fa-book"></i></div>
              <p>No classes found for your assigned courses.</p>
            </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Violations Table -->
    <div class="sec-header">
      <h4><span class="sec-dot"></span> Teacher Violations</h4>
      <span class="sec-count"><?php echo $totalViolations; ?> total (<?php echo $unresolvedViolations; ?> unresolved)</span>
    </div>
    <div class="data-card">
      <div class="data-card-header">
        <div class="dch-icon"><i class="fa fa-exclamation-triangle"></i></div>
        <span class="dch-title">Violation Log</span>
        <span class="dch-sub">Missing materials, etc.</span>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th><th>Teacher</th><th>Class</th><th>Violation</th><th>Topic</th><th>Created</th><th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($violationRows as $k => $v): ?>
            <tr>
              <td style="color:var(--sl400);font-size:12px;font-weight:600;"><?php echo $k+1; ?></td>
              <td>
                <div class="user-cell">
                  <div class="u-avatar" style="width:28px;height:28px;border-radius:7px;"><i class="fa fa-user" style="font-size:11px;"></i></div>
                  <span style="font-size:13px;color:var(--sl600);"><?php echo htmlspecialchars(trim($v['first_name'].' '.$v['last_name']) ?: '—'); ?></span>
                </div>
              </td>
              <td>
                <strong style="color:var(--sl800);font-size:13px;"><?php echo htmlspecialchars($v['class_name']); ?></strong><br>
                <span style="font-size:11px;color:var(--sl400);letter-spacing:.8px;font-weight:600;"><?php echo htmlspecialchars($v['class_code']); ?></span>
              </td>
              <td style="color:var(--sl800);max-width:300px;"><?php echo htmlspecialchars($v['description']); ?></td>
              <td style="color:var(--sl600);"><?php echo htmlspecialchars($v['related_topic'] ?: '—'); ?></td>
              <td style="color:var(--sl400);font-size:12px;"><?php echo date('M d, Y', strtotime($v['created_at'])); ?></td>
              <td>
                <?php if($v['resolved_at']): ?>
                <span class="badge-on"><i class="fa fa-check"></i> Resolved</span>
                <?php else: ?>
                <span class="badge-off" style="background:#fff1f2;color:#b91c1c;border:1px solid #fecdd3;"><i class="fa fa-clock-o"></i> Unresolved</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($violationRows)): ?>
            <tr class="empty-row"><td colspan="7">
              <div class="empty-icon"><i class="fa fa-check-circle"></i></div>
              <p>No violations found for your assigned courses.</p>
            </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php endif; ?>

  </div><!-- /.adm-content -->
  <footer class="cl-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<?php include '../includes/scripts.php'; ?>
<script>
function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
</script>
</body>
</html>
