<?php
include '../includes/session.php';
include '../includes/conn.php';

$uc = $conn->real_escape_string($user['user_code']);

$pg_res = $conn->query("SELECT * FROM published_grades WHERE student_code='$uc'");
$publishedGradesMap = [];
$totalGradeSum = 0;
$gradeCount = 0;
while($r = $pg_res->fetch_assoc()) {
    $publishedGradesMap[$r['class_id']][$r['term']] = $r;
    if(!empty($r['grade'])) {
        $totalGradeSum += floatval($r['grade']);
        $gradeCount++;
    }
}

$user['program_code'] = $user['program_code'] ?? '';
$user['year_level']   = $user['year_level']   ?? '';
$user['section']      = $user['section']       ?? '';
$isGraduated = !empty($user['graduated_at']) || strtoupper($user['user_group'] ?? '') === 'ALUMNI';

// Active classes — show all enrolled classes where student is a member
$my_classes = $conn->query("
    SELECT c.*, u.first_name AS teacher_first, u.last_name AS teacher_last,
           'accepted' AS confirm_status,
           c.class_code AS display_code
    FROM class_members cm JOIN classes c ON cm.class_id=c.id
    LEFT JOIN users u ON c.teacher_code=u.user_code
    WHERE cm.user_code='$uc' AND c.teacher_code!='$uc'
      AND (c.is_archived=0 OR c.is_archived IS NULL)
      AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)
    GROUP BY c.id
    ORDER BY cm.joined_at DESC
");
$classCount = $my_classes ? $my_classes->num_rows : 0;

// Archived enrolled classes (history)
$archived_classes = $conn->query("
    SELECT c.*, u.first_name AS teacher_first, u.last_name AS teacher_last
    FROM class_members cm JOIN classes c ON cm.class_id=c.id
    LEFT JOIN users u ON c.teacher_code=u.user_code
    WHERE cm.user_code='$uc' AND c.teacher_code!='$uc'
      AND c.is_archived=1
      AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)
    GROUP BY c.id
    ORDER BY c.archived_at DESC
");
$archivedCount = $archived_classes ? $archived_classes->num_rows : 0;

$available_classes = null;
if(!$isGraduated){
    $pc  = $conn->real_escape_string(strtoupper(trim($user['program_code'] ?? '')));
    $sec = $conn->real_escape_string(strtoupper(trim($user['section'] ?? '')));
    
    $whereConds = [];
    if($pc !== '') {
        $whereConds[] = "(c.program_code='' OR c.program_code IS NULL OR UPPER(c.program_code)='$pc')";
    }
    $extraProg = !empty($whereConds) ? (" AND " . implode(" AND ", $whereConds)) : "";

    $available_classes = $conn->query("
        SELECT c.*, u.first_name AS teacher_first, u.last_name AS teacher_last
        FROM classes c LEFT JOIN users u ON c.teacher_code=u.user_code
        WHERE c.teacher_code!='$uc'
          AND (c.is_archived=0 OR c.is_archived IS NULL)
          AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)
          AND NOT EXISTS (SELECT 1 FROM class_members cm WHERE cm.class_id=c.id AND cm.user_code='$uc')
          $extraProg
        ORDER BY c.created_at DESC
    ");
}

$initials = strtoupper(substr($user['first_name'] ?? 'S',0,1).substr($user['last_name'] ?? 'T',0,1));
$availCount = $available_classes ? $available_classes->num_rows : 0;
$pendingAssignCount = 3;
$avgScoreDisplay = $gradeCount > 0 ? round($totalGradeSum / $gradeCount).'%' : '87%';

$fullName = trim(($user['first_name'] ?? 'Juan').' '.($user['last_name'] ?? 'Dela Cruz'));
?>
<!DOCTYPE html>
<html lang="en">
<head>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CenLearn — My Classes</title>
  <link rel="stylesheet" href="/cenlearn/system/bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/cenlearn/system/bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/cenlearn/system/dist/css/cenlearn.css?v=3.0">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #f4f7fa; font-family: 'Inter', sans-serif; color: #1e293b; overflow-x: hidden; }

    /* ── LEFT SIDEBAR ── */
    .app-sidebar {
      position: fixed; top: 0; left: 0; width: 250px; height: 100vh;
      background: #0b1727; display: flex; flex-direction: column; z-index: 300;
      transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      transform: translateX(-250px);
    }
    .app-sidebar.open { transform: translateX(0); }
    @media (min-width: 901px) { .app-sidebar { transform: translateX(0); } }

    .sb-brand {
      padding: 24px 22px 18px; display: flex; align-items: center; gap: 12px;
    }
    .sb-brand-icon {
      width: 38px; height: 38px; border-radius: 10px;
      background: linear-gradient(135deg, #0284c7, #2563eb);
      display: flex; align-items: center; justify-content: center;
      color: #fff; font-size: 18px; box-shadow: 0 4px 12px rgba(37,99,235,0.4);
    }
    .sb-brand-text h2 { margin: 0; font-size: 19px; font-weight: 800; color: #ffffff; letter-spacing: -0.3px; }
    .sb-brand-text p { margin: 2px 0 0; font-size: 10px; color: #64748b; font-weight: 500; }

    .sb-nav { flex: 1; padding: 12px 14px; overflow-y: auto; }
    .sb-nav ul { list-style: none; margin: 0; padding: 0; }
    .sb-nav li { margin-bottom: 4px; }
    .sb-nav li a {
      display: flex; align-items: center; gap: 12px; padding: 11px 16px;
      color: #94a3b8; text-decoration: none; font-size: 13.5px; font-weight: 500;
      border-radius: 12px; transition: all 0.18s ease;
    }
    .sb-nav li a:hover { background: rgba(255,255,255,0.06); color: #ffffff; }
    .sb-nav li.active a {
      background: #2563eb; color: #ffffff; font-weight: 600;
      box-shadow: 0 4px 14px rgba(37,99,235,0.35);
    }
    .sb-nav li a i { width: 18px; text-align: center; font-size: 15px; }
    .sb-nav-badge {
      margin-left: auto; background: #ef4444; color: #fff;
      font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 99px;
    }

    .sb-promo-card {
      margin: 14px; padding: 16px; border-radius: 16px;
      background: linear-gradient(180deg, rgba(30,58,138,0.4) 0%, rgba(15,23,42,0.6) 100%);
      border: 1px solid rgba(255,255,255,0.08); position: relative; overflow: hidden;
    }
    .sb-promo-icon {
      width: 32px; height: 32px; border-radius: 8px; background: rgba(56,189,248,0.15);
      color: #38bdf8; display: flex; align-items: center; justify-content: center;
      font-size: 16px; margin-bottom: 10px;
    }
    .sb-promo-card p {
      margin: 0; font-size: 11.5px; color: rgba(255,255,255,0.8); line-height: 1.45; font-weight: 500;
    }

    /* ── MAIN CONTENT AREA ── */
    .app-main {
      margin-left: 0; min-height: 100vh; display: flex; flex-direction: column;
      transition: margin-left 0.3s;
    }
    @media (min-width: 901px) { .app-main { margin-left: 250px; } }

    /* Top Bar Header */
    .top-header {
      background: #ffffff; height: 68px; padding: 0 32px;
      display: flex; align-items: center; justify-content: space-between;
      border-bottom: 1px solid #e2e8f0; sticky: top; top: 0; z-index: 100;
    }
    .header-search {
      position: relative; width: 360px; max-width: 100%;
    }
    .header-search input {
      width: 100%; height: 42px; padding: 0 16px 0 42px; border-radius: 99px;
      border: 1px solid #f1f5f9; background: #f8fafc; font-size: 13px; color: #1e293b;
      outline: none; transition: all 0.2s; font-family: 'Inter', sans-serif;
    }
    .header-search input:focus { background: #ffffff; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
    .header-search i {
      position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
      color: #94a3b8; font-size: 14px;
    }

    .header-user { display: flex; align-items: center; gap: 12px; position: relative; }
    .user-profile-wrap { position: relative; }
    .user-profile-btn {
      display: flex; align-items: center; justify-content: center; padding: 2px;
      border-radius: 50%; background: #ffffff; border: 2px solid #e2e8f0;
      cursor: pointer; transition: all 0.2s ease; user-select: none;
      box-shadow: 0 2px 6px rgba(0,0,0,0.03);
    }
    .user-profile-btn:hover { background: #f8fafc; border-color: #2563eb; transform: scale(1.05); box-shadow: 0 4px 12px rgba(37,99,235,0.18); }
    .user-avatar {
      width: 36px; height: 36px; border-radius: 50%;
      background: linear-gradient(135deg, #0284c7, #2563eb); color: #ffffff;
      font-weight: 700; font-size: 13.5px; display: flex; align-items: center; justify-content: center;
      box-shadow: 0 2px 6px rgba(37,99,235,0.3); flex-shrink: 0;
    }
    .user-info strong { display: block; font-size: 13px; font-weight: 700; color: #0f172a; line-height: 1.2; }
    .user-info span { font-size: 11px; color: #64748b; font-weight: 500; }

    .header-logout-btn {
      display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
      border-radius: 99px; background: #fee2e2; color: #dc2626; font-size: 12.5px;
      font-weight: 700; text-decoration: none; border: 1px solid #fca5a5; transition: all 0.2s ease;
    }
    .header-logout-btn:hover { background: #dc2626; color: #ffffff; border-color: #dc2626; text-decoration: none; box-shadow: 0 4px 12px rgba(220,38,38,0.25); }

    .profile-dropdown-menu {
      position: absolute; top: calc(100% + 8px); right: 0; width: 230px;
      background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0;
      box-shadow: 0 10px 30px rgba(0,0,0,0.12); padding: 8px; z-index: 1000;
      display: none; animation: pdmFade 0.2s ease-out;
    }
    .profile-dropdown-menu.show { display: block; }
    @keyframes pdmFade {
      from { opacity: 0; transform: translateY(-6px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .pdm-header { padding: 12px 14px 10px; border-bottom: 1px solid #f1f5f9; margin-bottom: 6px; }
    .pdm-header strong { display: block; font-size: 13px; font-weight: 700; color: #0f172a; }
    .pdm-header span { font-size: 11px; color: #64748b; }
    .pdm-item {
      display: flex; align-items: center; gap: 10px; padding: 10px 14px;
      border-radius: 10px; color: #334155; font-size: 13px; font-weight: 600;
      text-decoration: none; transition: all 0.15s ease;
    }
    .pdm-item:hover { background: #f8fafc; color: #2563eb; text-decoration: none; }
    .pdm-item.danger { color: #dc2626; }
    .pdm-item.danger:hover { background: #fef2f2; color: #b91c1c; text-decoration: none; }

    /* Main Inner Container */
    .content-body { padding: 28px 32px 60px; flex: 1; }

    /* Welcome Hero Banner */
    .hero-banner {
      background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 50%, #38bdf8 100%);
      border-radius: 20px; padding: 28px 36px; color: #ffffff;
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 24px; box-shadow: 0 10px 25px -5px rgba(37,99,235,0.3);
      position: relative; overflow: hidden;
    }
    .hero-text h1 { margin: 0 0 6px; font-size: 24px; font-weight: 800; letter-spacing: -0.4px; }
    .hero-text p { margin: 0; font-size: 13.5px; opacity: 0.9; font-weight: 400; max-width: 500px; }
    .hero-graphic {
      display: flex; align-items: center; justify-content: center; position: relative;
    }
    .hero-graphic-box {
      width: 140px; height: 90px; border-radius: 16px;
      background: rgba(255,255,255,0.18); backdrop-filter: blur(10px);
      border: 1px solid rgba(255,255,255,0.3); display: flex;
      align-items: center; justify-content: center; font-size: 42px; color: #ffffff;
      box-shadow: 0 12px 30px rgba(0,0,0,0.15); transform: rotate(-3deg);
    }

    /* 4-Grid Summary Stat Cards */
    .stats-grid {
      display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 28px;
    }
    @media (max-width: 1100px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 600px) { .stats-grid { grid-template-columns: 1fr; } }

    .stat-card {
      background: #ffffff; border-radius: 16px; padding: 20px;
      border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;
      box-shadow: 0 2px 6px rgba(0,0,0,0.02); transition: transform 0.2s, box-shadow 0.2s;
    }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.06); }
    .stat-card-left { display: flex; align-items: center; gap: 14px; }
    .stat-icon-circle {
      width: 46px; height: 46px; border-radius: 14px; display: flex;
      align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;
    }
    .stat-card-info label { display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 2px; }
    .stat-card-info strong { display: block; font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1.1; }
    .stat-card-info span { display: block; font-size: 11px; color: #94a3b8; margin-top: 2px; }
    .stat-chevron { color: #cbd5e1; font-size: 13px; }

    /* Section Title & Filter Tabs Bar */
    .section-bar {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 20px; flex-wrap: wrap; gap: 16px;
    }
    .section-title h2 { margin: 0; font-size: 20px; font-weight: 800; color: #0f172a; letter-spacing: -0.3px; }
    .section-title p { margin: 3px 0 0; font-size: 12.5px; color: #64748b; }

    .filter-tabs { display: flex; align-items: center; gap: 8px; }
    .tab-pill {
      padding: 8px 18px; border-radius: 99px; font-size: 12.5px; font-weight: 600;
      border: 1px solid #e2e8f0; background: #ffffff; color: #475569;
      cursor: pointer; transition: all 0.18s; font-family: 'Inter', sans-serif;
    }
    .tab-pill.active {
      background: #0f172a; color: #ffffff; border-color: #0f172a;
      box-shadow: 0 4px 12px rgba(15,23,42,0.15);
    }
    .view-toggle {
      display: flex; align-items: center; background: #ffffff; border: 1px solid #e2e8f0;
      border-radius: 10px; padding: 3px; gap: 2px;
    }
    .btn-view-toggle {
      width: 32px; height: 32px; border-radius: 8px; border: none; background: transparent;
      color: #64748b; cursor: pointer; display: flex; align-items: center; justify-content: center;
    }
    .btn-view-toggle.active { background: #2563eb; color: #ffffff; }

    /* ═══════════════ SLEEK, COMPACT & RESPONSIVE CLASS CARDS ═══════════════ */
    .class-cards-container { display: flex; flex-direction: column; gap: 12px; transition: all 0.3s ease; }
    
    .class-card {
      background: #ffffff; border-radius: 14px; border: 1px solid #e2e8f0;
      padding: 12px 18px; display: flex; align-items: center; justify-content: space-between;
      gap: 16px; box-shadow: 0 1.5px 6px rgba(0,0,0,0.02); transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .class-card:hover {
      transform: translateY(-1.5px); box-shadow: 0 6px 18px rgba(0,0,0,0.05);
      border-color: #cbd5e1;
    }

    .cc-main-info { display: flex; align-items: center; gap: 14px; flex: 1.2; min-width: 200px; }
    .cc-thumb {
      width: 58px; height: 58px; border-radius: 12px;
      background: linear-gradient(135deg, #1e3a8a, #3b82f6);
      display: flex; align-items: center; justify-content: center;
      color: #ffffff; font-size: 22px; flex-shrink: 0; position: relative;
      box-shadow: 0 3px 10px rgba(37,99,235,0.22);
    }
    .cc-details { display: flex; flex-direction: column; gap: 2px; }
    .status-tag {
      display: inline-flex; align-items: center; padding: 1.5px 7px; border-radius: 99px;
      font-size: 9.5px; font-weight: 700; background: #dcfce7; color: #166534; width: fit-content;
      margin-bottom: 2px;
    }
    .cc-title { margin: 0; font-size: 14.5px; font-weight: 800; color: #0f172a; line-height: 1.25; }
    .cc-sub { font-size: 11.5px; color: #64748b; font-weight: 500; }
    .cc-teacher { display: flex; align-items: center; gap: 6px; margin-top: 3px; }
    .teacher-av {
      width: 20px; height: 20px; border-radius: 50%; background: #2563eb;
      color: #fff; font-size: 9px; font-weight: 700; display: flex;
      align-items: center; justify-content: center;
    }
    .teacher-name { font-size: 11.5px; color: #334155; font-weight: 600; }

    /* Schedule & Room Column */
    .cc-schedule-col { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 160px; }
    .info-item { display: flex; align-items: center; gap: 8px; }
    .info-item i { color: #64748b; font-size: 13px; width: 14px; text-align: center; }
    .info-text label { display: block; font-size: 9.5px; font-weight: 700; color: #94a3b8; margin: 0; text-transform: uppercase; letter-spacing: 0.3px; }
    .info-text strong { display: block; font-size: 11.5px; font-weight: 700; color: #1e293b; }

    /* Progress & Action Column */
    .cc-action-col { display: flex; align-items: center; gap: 16px; flex-shrink: 0; }
    .progress-circle-box { text-align: center; }
    .circle-wrap { position: relative; width: 44px; height: 44px; }
    .circle-wrap svg { width: 44px; height: 44px; transform: rotate(-90deg); }
    .circle-bg { fill: none; stroke: #f1f5f9; stroke-width: 4.5; }
    .circle-progress { fill: none; stroke: #10b981; stroke-width: 4.5; stroke-linecap: round; transition: stroke-dashoffset 0.6s ease; }
    .circle-text {
      position: absolute; top: 0; left: 0; width: 100%; height: 100%;
      display: flex; align-items: center; justify-content: center;
      font-size: 10.5px; font-weight: 800; color: #0f172a;
    }
    .progress-circle-box span { display: block; font-size: 9.5px; color: #64748b; font-weight: 600; margin-top: 1px; }

    .btn-view-class {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 7px 18px; border-radius: 99px; background: #2563eb;
      color: #ffffff; font-size: 12px; font-weight: 700; text-decoration: none;
      box-shadow: 0 3px 10px rgba(37,99,235,0.22); transition: all 0.18s; border: none;
    }
    .btn-view-class:hover { background: #1d4ed8; color: #ffffff; text-decoration: none; transform: translateY(-1px); }

    .btn-options-dots {
      background: transparent; border: none; color: #94a3b8; font-size: 16px;
      cursor: pointer; padding: 4px 6px; border-radius: 6px; transition: all 0.15s;
    }
    .btn-options-dots:hover { color: #0f172a; background: #f1f5f9; }

    /* ═══════════════ GRID VIEW LAYOUT ═══════════════ */
    .class-cards-container.grid-layout {
      display: grid !important;
      grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)) !important;
      gap: 16px !important;
    }
    .class-cards-container.grid-layout .class-card {
      flex-direction: column !important;
      align-items: stretch !important;
      padding: 16px !important;
      gap: 12px !important;
    }
    .class-cards-container.grid-layout .cc-main-info {
      width: 100% !important;
      min-width: 0 !important;
    }
    .class-cards-container.grid-layout .cc-schedule-col {
      width: 100% !important;
      min-width: 0 !important;
      background: #f8fafc;
      padding: 8px 12px;
      border-radius: 10px;
      border: 1px solid #f1f5f9;
      flex-direction: row !important;
      justify-content: space-between !important;
    }
    .class-cards-container.grid-layout .cc-schedule-col.hide-mobile {
      display: flex !important;
    }
    .class-cards-container.grid-layout .cc-action-col {
      width: 100% !important;
      justify-content: space-between !important;
      padding-top: 10px;
      border-top: 1px solid #f1f5f9;
    }
    .class-cards-container.grid-layout .progress-circle-box.hide-mobile {
      display: block !important;
    }

    /* ═══════════════ RESPONSIVE BREAKPOINTS ═══════════════ */
    @media (max-width: 950px) {
      .class-card { flex-wrap: wrap; padding: 12px 14px; gap: 10px; }
      .cc-main-info { flex: 1 1 100%; }
      .cc-schedule-col { flex: 1 1 100%; flex-direction: row; justify-content: space-between; background: #f8fafc; padding: 8px 12px; border-radius: 10px; }
      .cc-action-col { flex: 1 1 100%; justify-content: space-between; border-top: 1px solid #f1f5f9; padding-top: 8px; margin-top: 2px; }
      .top-header { padding: 0 16px; }
      .content-body { padding: 16px 16px 40px; }
    }
    @media (max-width: 520px) {
      .cc-thumb { width: 48px; height: 48px; font-size: 18px; border-radius: 10px; }
      .cc-title { font-size: 13.5px; }
      .cc-sub { font-size: 11px; }
      .cc-schedule-col { flex-direction: column; gap: 6px; }
      .btn-view-class { padding: 6px 14px; font-size: 11.5px; }
    }
  </style>
</head>
<body>

<!-- Left Sidebar -->
<aside class="app-sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="sb-brand-icon"><i class="fa fa-graduation-cap"></i></div>
    <div class="sb-brand-text">
      <h2>CenLearn</h2>
      <p>Learn &bull; Grow &bull; Succeed</p>
    </div>
  </div>

  <nav class="sb-nav">
    <ul>
      <li><a href="dashboard"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li class="active"><a href="classes"><i class="fa fa-book"></i> My Classes</a></li>
      <li><a href="quizzes"><i class="fa fa-question-circle"></i> Quizzes</a></li>
      <li><a href="assignments"><i class="fa fa-clipboard"></i> Assignments</a></li>
      <li><a href="grades"><i class="fa fa-bar-chart"></i> Grades</a></li>
      <li><a href="attendance"><i class="fa fa-calendar"></i> Attendance</a></li>
    </ul>
  </nav>

  <div class="sb-promo-card">
    <div class="sb-promo-icon"><i class="fa fa-leaf"></i></div>
    <p>Small steps every day lead to big results.</p>
  </div>
</aside>

<!-- Main Area -->
<div class="app-main">

  <!-- Top Header Navigation -->
  <header class="top-header">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu" style="background:none;border:none;font-size:18px;color:#0f172a;cursor:pointer;">
        <i class="fa fa-bars"></i>
      </button>
      <div class="header-search">
        <i class="fa fa-search"></i>
        <input type="text" id="classSearch" placeholder="Search for classes, subjects, or teachers…" oninput="filterCards()">
      </div>
    </div>

    <div class="header-user">
      <button type="button" onclick="$('#joinClassModal').modal('show')" style="border-radius:99px;font-weight:700;font-size:12px;padding:8px 18px;background:#10b981;color:#fff;border:none;box-shadow:0 2px 8px rgba(16,185,129,0.3);display:inline-flex;align-items:center;gap:6px;cursor:pointer;margin-right:4px;">
        <i class="fa fa-plus"></i> Join Class
      </button>

      <div class="user-profile-wrap">
        <div class="user-profile-btn" onclick="toggleProfileMenu(event)" title="<?php echo htmlspecialchars($fullName); ?>">
          <div class="user-avatar"><?php echo $initials; ?></div>
        </div>

        <div class="profile-dropdown-menu" id="profileMenu">
          <div class="pdm-header">
            <strong><?php echo htmlspecialchars($fullName); ?></strong>
            <span>Student &bull; <?php echo htmlspecialchars($user['program_code'] ?? 'Regular'); ?></span>
          </div>
          <a href="javascript:void(0)" class="pdm-item" onclick="openStudentProfileModal()"><i class="fa fa-user-circle"></i> Student Profile</a>
          <div class="pdm-divider"></div>
          <a href="/cenlearn/logout" class="pdm-item danger"><i class="fa fa-sign-out"></i> Log Out</a>
        </div>
      </div>
    </div>
  </header>

  <!-- Main Content Body -->
  <main class="content-body">



    <!-- 4-Grid Summary Metric Cards -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#eff6ff;color:#2563eb;">
            <i class="fa fa-users"></i>
          </div>
          <div class="stat-card-info">
            <label>Enrolled Classes</label>
            <strong><?php echo $classCount; ?></strong>
            <span>Active this semester</span>
          </div>
        </div>
        <i class="fa fa-chevron-right stat-chevron"></i>
      </div>

      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#ecfdf5;color:#10b981;">
            <i class="fa fa-calendar"></i>
          </div>
          <div class="stat-card-info">
            <label>Upcoming Classes</label>
            <strong><?php echo $availCount; ?></strong>
            <span>This week</span>
          </div>
        </div>
        <i class="fa fa-chevron-right stat-chevron"></i>
      </div>

      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#f3e8ff;color:#9333ea;">
            <i class="fa fa-file-text-o"></i>
          </div>
          <div class="stat-card-info">
            <label>Pending Assignments</label>
            <strong><?php echo $pendingAssignCount; ?></strong>
            <span>To be submitted</span>
          </div>
        </div>
        <i class="fa fa-chevron-right stat-chevron"></i>
      </div>

      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#ffedd5;color:#f59e0b;">
            <i class="fa fa-trophy"></i>
          </div>
          <div class="stat-card-info">
            <label>Latest Grade</label>
            <strong><?php echo $avgScoreDisplay; ?></strong>
            <span>Average score</span>
          </div>
        </div>
        <i class="fa fa-chevron-right stat-chevron"></i>
      </div>
    </div>

    <!-- Section Bar with Title & Filter Pills -->
    <div class="section-bar">
      <div class="section-title">
        <h2>My Classes</h2>
        <p>View and manage your enrolled classes</p>
      </div>

      <div style="display:flex;align-items:center;gap:12px;">
        <div class="filter-tabs">
          <button class="tab-pill active" onclick="switchTab('tab-active',this)">All Classes (<?php echo $classCount; ?>)</button>
          <button class="tab-pill" onclick="switchTab('tab-available',this)">Available (<?php echo $availCount; ?>)</button>
          <button class="tab-pill" onclick="switchTab('tab-history',this)">History (<?php echo $archivedCount; ?>)</button>
        </div>

        <div class="view-toggle hide-mobile">
          <button class="btn-view-toggle active" title="List View" onclick="switchLayoutView('list', this)"><i class="fa fa-bars"></i></button>
          <button class="btn-view-toggle" title="Grid View" onclick="switchLayoutView('grid', this)"><i class="fa fa-th-large"></i></button>
        </div>
      </div>
    </div>

    <!-- TAB 1: ACTIVE ENROLLED CLASSES -->
    <div class="tab-panel active" id="tab-active">
      <?php if($classCount === 0 && $availCount === 0): ?>
      <div style="background:#fff;border-radius:18px;padding:48px;text-align:center;border:1px solid #e2e8f0;">
        <i class="fa fa-book" style="font-size:36px;color:#cbd5e1;margin-bottom:12px;"></i>
        <h3 style="margin:0 0 4px;font-size:16px;font-weight:700;color:#0f172a;">No Classes Enrolled</h3>
        <p style="margin:0;font-size:13px;color:#64748b;">Join an available class or enter a class code from your teacher.</p>
      </div>
      <?php else: ?>

      <div class="class-cards-container" id="classesGrid">
        <?php
        $idx = 0;
        while($c = $my_classes->fetch_assoc()):
          $teacherName = trim(($c['teacher_first']??'').' '.($c['teacher_last']??''));
          $teacherInit = strtoupper(substr($c['teacher_first']??'T',0,1));
          $isPending   = ($c['confirm_status'] === 'pending');
          $idx++;
          $subjectThumbIcon = ($idx % 2 === 1) ? 'fa-book' : 'fa-flask';
          $thumbGrad = ($idx % 2 === 1) ? 'linear-gradient(135deg, #1e3a8a, #3b82f6)' : 'linear-gradient(135deg, #065f46, #10b981)';
        ?>
        <div class="class-card" data-name="<?php echo strtolower(htmlspecialchars($c['class_name'].' '.$c['subject'].' '.$teacherName)); ?>">
          <!-- Left Info Section -->
          <div class="cc-main-info">
            <div class="cc-thumb" style="background:<?php echo $thumbGrad; ?>;">
              <i class="fa <?php echo $subjectThumbIcon; ?>"></i>
            </div>
            <div class="cc-details">
              <span class="status-tag">Ongoing</span>
              <h3 class="cc-title"><?php echo htmlspecialchars($c['class_name']); ?></h3>
              <span class="cc-sub">Grade <?php echo $c['year_level'] ?: '10'; ?> &bull; Section <?php echo htmlspecialchars($c['section'] ?: 'A'); ?> &bull; Code: <?php echo htmlspecialchars($c['display_code'] ?: $c['class_code']); ?></span>
              <div class="cc-teacher">
                <div class="teacher-av"><?php echo $teacherInit; ?></div>
                <span class="teacher-name"><?php echo htmlspecialchars($teacherName ?: 'Instructor'); ?></span>
              </div>
            </div>
          </div>

          <!-- Middle Schedule & Room Info -->
          <div class="cc-schedule-col hide-mobile">
            <div class="info-item">
              <i class="fa fa-calendar"></i>
              <div class="info-text">
                <label>Schedule</label>
                <strong><?php echo ($idx%2===1)?'Mon &bull; Wed &bull; Fri &bull; 8:00 AM – 9:30 AM':'Tue &bull; Thu &bull; 10:00 AM – 11:30 AM'; ?></strong>
              </div>
            </div>
            <div class="info-item">
              <i class="fa fa-map-marker"></i>
              <div class="info-text">
                <label>Room</label>
                <strong>Room <?php echo 100 + $idx; ?></strong>
              </div>
            </div>
          </div>

          <!-- Right Progress & Action -->
          <div class="cc-action-col">
            <a href="/cenlearn/shared/live_class?id=<?php echo $c['id']; ?>" class="btn-view-class" style="background:linear-gradient(135deg,#10b981,#059669);box-shadow:0 3px 10px rgba(16,185,129,0.22);text-decoration:none;">
              <i class="fa fa-video-camera"></i> Online Class
            </a>

            <a href="/cenlearn/shared/class_view?id=<?php echo $c['id']; ?>" class="btn-view-class">
              View Class <i class="fa fa-chevron-right" style="font-size:10px;"></i>
            </a>

            <button type="button" class="btn-options-dots" title="Options" onclick="leaveClass(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['class_name'])); ?>')">
              <i class="fa fa-ellipsis-v"></i>
            </button>
          </div>
        </div>
        <?php endwhile; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- TAB 2: AVAILABLE TO JOIN -->
    <div class="tab-panel" id="tab-available" style="display:none;">
      <?php if($availCount > 0): ?>
      <div class="class-cards-container" id="availableGrid">
        <?php
        while($c = $available_classes->fetch_assoc()):
          $teacherName2 = trim(($c['teacher_first']??'').' '.($c['teacher_last']??''));
        ?>
        <div class="class-card" data-name="<?php echo strtolower(htmlspecialchars($c['class_name'].' '.$c['subject'].' '.$teacherName2)); ?>">
          <div class="cc-main-info">
            <div class="cc-thumb" style="background:linear-gradient(135deg, #0284c7, #38bdf8);">
              <i class="fa fa-compass"></i>
            </div>
            <div class="cc-details">
              <span class="status-tag" style="background:#e0f2fe;color:#0369a1;">Available</span>
              <h3 class="cc-title"><?php echo htmlspecialchars($c['class_name']); ?></h3>
              <span class="cc-sub">Code: <?php echo htmlspecialchars($c['class_code']); ?> &bull; <?php echo htmlspecialchars($c['program_code'] ?: 'All Programs'); ?></span>
              <div class="cc-teacher">
                <div class="teacher-av"><?php echo strtoupper(substr($teacherName2?:'T',0,1)); ?></div>
                <span class="teacher-name"><?php echo htmlspecialchars($teacherName2 ?: 'Instructor'); ?></span>
              </div>
            </div>
          </div>
          <div class="cc-action-col">
            <button type="button" class="btn-view-class" onclick="quickJoin(<?php echo $c['id']; ?>, this)" style="background:#10b981;">
              Join Class <i class="fa fa-sign-in"></i>
            </button>
          </div>
        </div>
        <?php endwhile; ?>
      </div>
      <?php else: ?>
      <div style="background:#fff;border-radius:18px;padding:48px;text-align:center;border:1px solid #e2e8f0;">
        <i class="fa fa-check-circle" style="font-size:36px;color:#10b981;margin-bottom:12px;"></i>
        <h3 style="margin:0 0 4px;font-size:16px;font-weight:700;color:#0f172a;">All Available Classes Joined</h3>
        <p style="margin:0;font-size:13px;color:#64748b;">You have joined all available classes for your section.</p>
      </div>
      <?php endif; ?>
    </div>

    <!-- TAB 3: HISTORY -->
    <div class="tab-panel" id="tab-history" style="display:none;">
      <?php if($archivedCount > 0): ?>
      <div class="class-cards-container" id="historyGrid">
        <?php
        while($c = $archived_classes->fetch_assoc()):
          $teacherName3 = trim(($c['teacher_first']??'').' '.($c['teacher_last']??''));
        ?>
        <div class="class-card" data-name="<?php echo strtolower(htmlspecialchars($c['class_name'].' '.$c['subject'].' '.$teacherName3)); ?>" style="opacity:0.85;">
          <div class="cc-main-info">
            <div class="cc-thumb" style="background:linear-gradient(135deg, #475569, #94a3b8);">
              <i class="fa fa-archive"></i>
            </div>
            <div class="cc-details">
              <span class="status-tag" style="background:#f1f5f9;color:#475569;">Archived</span>
              <h3 class="cc-title"><?php echo htmlspecialchars($c['class_name']); ?></h3>
              <span class="cc-sub">Teacher: <?php echo htmlspecialchars($teacherName3); ?></span>
            </div>
          </div>
          <div class="cc-action-col">
            <a href="/cenlearn/shared/class_view?id=<?php echo $c['id']; ?>" class="btn-view-class" style="background:#475569;">
              View Records <i class="fa fa-eye"></i>
            </a>
          </div>
        </div>
        <?php endwhile; ?>
      </div>
      <?php else: ?>
      <div style="background:#fff;border-radius:18px;padding:48px;text-align:center;border:1px solid #e2e8f0;">
        <i class="fa fa-history" style="font-size:36px;color:#cbd5e1;margin-bottom:12px;"></i>
        <h3 style="margin:0 0 4px;font-size:16px;font-weight:700;color:#0f172a;">No Class History</h3>
        <p style="margin:0;font-size:13px;color:#64748b;">Archived classes from previous school years will appear here.</p>
      </div>
      <?php endif; ?>
    </div>

  </main>
</div>

<!-- Join Class Modal -->
<div class="modal fade" id="joinClassModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document" style="max-width:400px;margin:80px auto;">
    <div class="modal-content" style="border-radius:20px;border:none;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);overflow:hidden;">
      <div class="modal-header" style="padding:18px 24px;border-bottom:1px solid #f1f5f9;background:#fff;display:flex;align-items:center;justify-content:space-between;">
        <h4 class="modal-title" style="font-size:16px;font-weight:800;color:#0f172a;margin:0;">Join Class by Code</h4>
        <button type="button" class="close" data-dismiss="modal" style="font-size:24px;color:#94a3b8;border:none;background:none;">&times;</button>
      </div>
      <div class="modal-body" style="padding:24px;">
        <label style="font-size:12.5px;font-weight:700;color:#334155;margin-bottom:8px;display:block;">Class / Subject Code:</label>
        <input type="text" id="joinClassCodeInput" class="form-control" placeholder="e.g. MATH10-A" style="height:44px;font-size:15px;font-weight:700;border-radius:12px;border:1.5px solid #cbd5e1;padding:8px 14px;text-transform:uppercase;">
      </div>
      <div class="modal-footer" style="padding:14px 24px;background:#f8fafc;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" class="btn btn-default" data-dismiss="modal" style="border-radius:99px;font-weight:600;font-size:13px;padding:8px 18px;border:1px solid #cbd5e1;background:#fff;">Cancel</button>
        <button type="button" class="btn btn-primary" id="btnSubmitJoinCode" onclick="joinWithCode()" style="border-radius:99px;font-weight:700;font-size:13px;padding:8px 22px;background:#10b981;border:none;">Join Class</button>
      </div>
    </div>
  </div>
</div>

<script src="/cenlearn/system/bower_components/jquery/dist/jquery.min.js"></script>
<script src="/cenlearn/system/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
function switchLayoutView(mode, btn){
  document.querySelectorAll('.btn-view-toggle').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
  var containers = document.querySelectorAll('.class-cards-container');
  containers.forEach(function(c){
    if(mode === 'grid'){
      c.classList.add('grid-layout');
    } else {
      c.classList.remove('grid-layout');
    }
  });
}

function switchTab(tabId, btn){
  document.querySelectorAll('.tab-panel').forEach(function(p){ p.style.display = 'none'; });
  document.querySelectorAll('.tab-pill').forEach(function(b){ b.classList.remove('active'); });
  document.getElementById(tabId).style.display = 'block';
  btn.classList.add('active');
}

function filterCards(){
  var q = (document.getElementById('classSearch').value || '').toLowerCase();
  var cards = document.querySelectorAll('#classesGrid .class-card, #availableGrid .class-card, #historyGrid .class-card');
  cards.forEach(function(c){
    var name = (c.dataset.name || '').toLowerCase();
    var match = name.includes(q);
    c.style.display = match ? 'flex' : 'none';
  });
}

function quickJoin(classId, btn){
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Joining…';
  $.post('/cenlearn/shared/class_save', { action: 'join_by_id', class_id: classId }, function(res){
    if(res.success){
      btn.innerHTML = '<i class="fa fa-check"></i> Joined!';
      setTimeout(function(){ location.reload(); }, 900);
    } else {
      btn.disabled = false;
      btn.innerHTML = 'Join Class <i class="fa fa-sign-in"></i>';
      alert(res.msg || 'Could not join.');
    }
  }, 'json');
}

function joinWithCode(){
  var code = ($('#joinClassCodeInput').val() || '').trim();
  if(!code){ alert('Please enter a class code.'); $('#joinClassCodeInput').focus(); return; }
  var btn = $('#btnSubmitJoinCode');
  btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Joining…');
  $.post('/cenlearn/shared/class_save', { action: 'join', class_code: code }, function(res){
    if(res.success){
      alert("Successfully joined!");
      location.reload();
    } else {
      btn.prop('disabled', false).html('Join Class');
      alert(res.msg || 'Could not join class.');
    }
  }, 'json');
}

function leaveClass(classId, className){
  if(confirm("Are you sure you want to leave '" + className + "'?")){
    $.post('/cenlearn/shared/class_save', { action: 'leave_class', class_id: classId }, function(res){
      if(res.success){ location.reload(); }
      else { alert(res.msg || 'Could not leave class.'); }
    }, 'json');
  }
}

function openSidebar(){ document.getElementById('sidebar').classList.add('open'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); }
function toggleProfileMenu(e) {
  e.stopPropagation();
  var m = document.getElementById('profileMenu');
  if(m) m.classList.toggle('show');
}
document.addEventListener('click', function(e) {
  var m = document.getElementById('profileMenu');
  if(m && !m.contains(e.target)) m.classList.remove('show');
});
</script>

<?php include '../includes/student_profile_modal.php'; ?>
</body>
</html>
