<?php
include '../includes/session.php';
include '../includes/conn.php';

$uc = $conn->real_escape_string($user['user_code']);

// Fetch published grades for student enrolled class sections only
$pg_query = $conn->query("
    SELECT pg.*, c.class_name, c.class_code, u.first_name AS teacher_first, u.last_name AS teacher_last
    FROM published_grades pg
    JOIN classes c ON pg.class_id = c.id
    LEFT JOIN users u ON c.teacher_code = u.user_code
    WHERE pg.student_code = '$uc'
      AND (c.is_subject_only = 0 OR c.is_subject_only IS NULL)
      AND (c.is_archived = 0 OR c.is_archived IS NULL)
    ORDER BY pg.published_at DESC
");

$publishedGradesByClass = [];
if ($pg_query) {
    while ($row = $pg_query->fetch_assoc()) {
        $cid = (int)$row['class_id'];
        $termKey = strtolower($row['term']);
        $publishedGradesByClass[$cid][$termKey] = $row;
    }
}

// Fetch enrolled student class sections (excluding subject-only catalog templates)
$classes_query = $conn->query("
    SELECT c.*, u.first_name AS teacher_first, u.last_name AS teacher_last
    FROM class_members cm
    JOIN classes c ON cm.class_id = c.id
    LEFT JOIN users u ON c.teacher_code = u.user_code
    WHERE cm.user_code = '$uc'
      AND (c.is_subject_only = 0 OR c.is_subject_only IS NULL)
      AND (c.is_archived = 0 OR c.is_archived IS NULL)
    ORDER BY c.class_name ASC, c.class_code ASC
");

$enrolledClasses = [];
if ($classes_query) {
    while ($c = $classes_query->fetch_assoc()) {
        $enrolledClasses[] = $c;
    }
}

// Helper transmutation
function transmuteGradeValue($grade) {
    if ($grade === null || $grade === '') return '—';
    $grade = floatval($grade);
    if ($grade >= 99) return '1.00';
    if ($grade >= 96) return '1.25';
    if ($grade >= 93) return '1.50';
    if ($grade >= 90) return '1.75';
    if ($grade >= 87) return '2.00';
    if ($grade >= 84) return '2.25';
    if ($grade >= 81) return '2.50';
    if ($grade >= 78) return '2.75';
    if ($grade >= 75) return '3.00';
    return '5.00';
}

// Real-time calculation for unpublished term grades
function getRealTimeClassGrade($conn, $class_id, $student_code, $term) {
    $wq = $conn->query("SELECT * FROM class_record_weights WHERE class_id=$class_id");
    $weights = ($wq && $wq->num_rows > 0) ? $wq->fetch_assoc() : [
        'written_pct'=>20, 'performance_pct'=>40, 'exam_pct'=>30, 'attendance_pct'=>10,
        'grading_method'=>'sum_of_points', 'base_grade'=>0
    ];

    $colsQ = $conn->query("SELECT * FROM class_record_columns WHERE class_id=$class_id AND term='$term'");
    if (!$colsQ || $colsQ->num_rows === 0) return null;

    $cols = [];
    $colIds = [];
    while ($r = $colsQ->fetch_assoc()) {
        $cols[] = $r;
        $colIds[] = (int)$r['id'];
    }
    if (empty($colIds)) return null;

    $colIdList = implode(',', $colIds);
    $scoresQ = $conn->query("SELECT column_id, score FROM class_record_scores WHERE column_id IN ($colIdList) AND student_code='$student_code'");
    $scores = [];
    if ($scoresQ) {
        while ($r = $scoresQ->fetch_assoc()) {
            if ($r['score'] !== null) {
                $scores[(int)$r['column_id']] = floatval($r['score']);
            }
        }
    }
    if (empty($scores)) return null;

    $method = $weights['grading_method'] ?? 'sum_of_points';
    $base = (int)($weights['base_grade'] ?? 0);
    if ($base < 0 || $base >= 100) $base = 0;

    $colsByComp = ['written'=>[], 'performance'=>[], 'exam'=>[], 'attendance'=>[]];
    foreach ($cols as $col) {
        $comp = (!empty($col['is_f2f']) || !empty($col['session_id']) || $col['component'] === 'attendance') ? 'attendance' : $col['component'];
        if (!isset($colsByComp[$comp])) $colsByComp[$comp] = [];
        $colsByComp[$comp][] = $col;
    }

    $compAvg = [];
    foreach (['written','performance','exam','attendance'] as $comp) {
        $cList = $colsByComp[$comp] ?? [];
        if (empty($cList)) { $compAvg[$comp] = null; continue; }

        $total = 0; $max = 0; $hasAny = false; $pcts = [];
        foreach ($cList as $col) {
            $sc = $scores[(int)$col['id']] ?? null;
            if ($sc !== null && floatval($col['max_score']) > 0) {
                $total += $sc;
                $max += floatval($col['max_score']);
                $pcts[] = ($sc / floatval($col['max_score'])) * 100;
                $hasAny = true;
            }
        }
        if (!$hasAny) {
            $compAvg[$comp] = null;
        } else {
            $raw = ($method === 'avg_of_pct') ? (array_sum($pcts) / count($pcts)) : (($total / $max) * 100);
            $compAvg[$comp] = round($raw * (100 - $base) / 100 + $base, 2);
        }
    }

    $wTotal = 0; $wWeight = 0;
    $compMap = ['written'=>'written_pct', 'performance'=>'performance_pct', 'exam'=>'exam_pct', 'attendance'=>'attendance_pct'];
    foreach ($compMap as $comp => $key) {
        if (isset($compAvg[$comp]) && $compAvg[$comp] !== null && isset($weights[$key])) {
            $wTotal += $compAvg[$comp] * intval($weights[$key]);
            $wWeight += intval($weights[$key]);
        }
    }

    return $wWeight > 0 ? round($wTotal / $wWeight, 2) : null;
}

// Compute statistics across enrolled classes
$totalTransmuted = 0;
$gradeCount = 0;
$passedCount = 0;
$failedCount = 0;

foreach ($enrolledClasses as $c) {
    $cid = (int)$c['id'];
    $pubMid = $publishedGradesByClass[$cid]['midterm'] ?? null;
    $pubFinal = $publishedGradesByClass[$cid]['final'] ?? null;

    $classGradeNum = null;
    $remarks = null;

    if ($pubFinal) {
        $classGradeNum = is_numeric($pubFinal['transmuted']) ? floatval($pubFinal['transmuted']) : null;
        $remarks = $pubFinal['remarks'];
    } elseif ($pubMid) {
        $classGradeNum = is_numeric($pubMid['transmuted']) ? floatval($pubMid['transmuted']) : null;
        $remarks = $pubMid['remarks'];
    } else {
        $rtFinal = getRealTimeClassGrade($conn, $cid, $uc, 'final');
        $rtMid = getRealTimeClassGrade($conn, $cid, $uc, 'midterm');
        $rtVal = $rtFinal ?? $rtMid;
        if ($rtVal !== null) {
            $tr = transmuteGradeValue($rtVal);
            $classGradeNum = is_numeric($tr) ? floatval($tr) : null;
            $remarks = ($rtVal >= 75) ? 'Passed' : 'Failed';
        }
    }

    if ($classGradeNum !== null) {
        $totalTransmuted += $classGradeNum;
        $gradeCount++;
    }
    if ($remarks === 'Passed') {
        $passedCount++;
    } elseif ($remarks === 'Failed') {
        $failedCount++;
    }
}

$gwa = $gradeCount > 0 ? number_format($totalTransmuted / $gradeCount, 2) : '—';

$fullName = trim(($user['first_name'] ?? 'Student') . ' ' . ($user['last_name'] ?? 'User'));
$initials = strtoupper(substr($user['first_name'] ?? 'S', 0, 1) . substr($user['last_name'] ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CenLearn — Student Grades</title>
  <link rel="stylesheet" href="/cenlearn/system/bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/cenlearn/system/bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/cenlearn/system/dist/css/cenlearn.css?v=3.0">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #f4f7fa; font-family: 'Inter', sans-serif; color: #1e293b; overflow-x: hidden; }

    .app-sidebar {
      position: fixed; top: 0; left: 0; width: 250px; height: 100vh;
      background: #0b1727; display: flex; flex-direction: column; z-index: 300;
      transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      transform: translateX(-250px);
    }
    .app-sidebar.open { transform: translateX(0); }
    @media (min-width: 901px) { .app-sidebar { transform: translateX(0); } }

    .sb-brand { padding: 24px 22px 18px; display: flex; align-items: center; gap: 12px; }
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
    .sb-nav li.active a { background: #2563eb; color: #ffffff; font-weight: 600; box-shadow: 0 4px 14px rgba(37,99,235,0.35); }
    .sb-nav li a i { width: 18px; text-align: center; font-size: 15px; }

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
    .sb-promo-card p { margin: 0; font-size: 11.5px; color: rgba(255,255,255,0.8); line-height: 1.45; font-weight: 500; }

    .app-main { margin-left: 0; min-height: 100vh; display: flex; flex-direction: column; transition: margin-left 0.3s; }
    @media (min-width: 901px) { .app-main { margin-left: 250px; } }

    .top-header {
      background: #ffffff; height: 68px; padding: 0 32px;
      display: flex; align-items: center; justify-content: space-between;
      border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 100;
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

    .content-body { padding: 28px 32px 60px; flex: 1; }

    .hero-banner {
      background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 50%, #38bdf8 100%);
      border-radius: 20px; padding: 26px 36px; color: #ffffff;
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 24px; box-shadow: 0 10px 25px -5px rgba(37,99,235,0.3);
    }
    .hero-text h1 { margin: 0 0 6px; font-size: 24px; font-weight: 800; letter-spacing: -0.4px; }
    .hero-text p { margin: 0; font-size: 13.5px; opacity: 0.9; font-weight: 400; }

    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 28px; }
    @media (max-width: 1100px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 600px) { .stats-grid { grid-template-columns: 1fr; } }

    .stat-card {
      background: #ffffff; border-radius: 16px; padding: 20px;
      border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;
      box-shadow: 0 2px 6px rgba(0,0,0,0.02);
    }
    .stat-card-left { display: flex; align-items: center; gap: 14px; }
    .stat-icon-circle { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .stat-card-info label { display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 2px; }
    .stat-card-info strong { display: block; font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1.1; }

    .grade-card {
      background: #ffffff; border-radius: 18px; border: 1px solid #e2e8f0;
      padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.02);
    }
    .gc-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
    .gc-title h3 { margin: 0; font-size: 17px; font-weight: 800; color: #0f172a; }
    .gc-title span { font-size: 12px; color: #64748b; font-weight: 500; }

    .grade-badge-pass { background: #dcfce7; color: #166534; padding: 4px 12px; border-radius: 99px; font-size: 12px; font-weight: 700; border: 1px solid #bbf7d0; }
    .grade-badge-fail { background: #fee2e2; color: #991b1b; padding: 4px 12px; border-radius: 99px; font-size: 12px; font-weight: 700; border: 1px solid #fca5a5; }

    .grade-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .grade-table th, .grade-table td { padding: 12px 14px; font-size: 13px; text-align: left; border-bottom: 1px solid #f1f5f9; }
    .grade-table th { background: #f8fafc; font-weight: 700; color: #475569; }
  </style>
</head>
<body>

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
      <li><a href="classes"><i class="fa fa-book"></i> My Classes</a></li>
      <li><a href="quizzes"><i class="fa fa-question-circle"></i> Quizzes</a></li>
      <li><a href="assignments"><i class="fa fa-clipboard"></i> Assignments</a></li>
      <li><a href="grades" style="font-weight:700;color:#fff;"><i class="fa fa-bar-chart"></i> Grades</a></li>
      <li><a href="attendance"><i class="fa fa-calendar"></i> Attendance</a></li>
    </ul>
  </nav>

  <div class="sb-promo-card">
    <div class="sb-promo-icon"><i class="fa fa-leaf"></i></div>
    <p>Small steps every day lead to big results.</p>
  </div>
</aside>

<div class="app-main">
  <header class="top-header">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu" style="background:none;border:none;font-size:18px;color:#0f172a;cursor:pointer;">
        <i class="fa fa-bars"></i>
      </button>
    </div>
    <div class="header-user">
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

  <main class="content-body">

    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-trophy"></i></div>
          <div class="stat-card-info">
            <label>General Weighted Average</label>
            <strong><?php echo $gwa; ?><?php echo is_numeric($gwa) ? '%' : ''; ?></strong>
          </div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#dcfce7;color:#15803d;"><i class="fa fa-check-circle"></i></div>
          <div class="stat-card-info">
            <label>Passed Classes</label>
            <strong><?php echo $passedCount; ?></strong>
          </div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#fee2e2;color:#991b1b;"><i class="fa fa-times-circle"></i></div>
          <div class="stat-card-info">
            <label>Failed Classes</label>
            <strong><?php echo $failedCount; ?></strong>
          </div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#f3e8ff;color:#9333ea;"><i class="fa fa-graduation-cap"></i></div>
          <div class="stat-card-info">
            <label>Program Code</label>
            <strong><?php echo htmlspecialchars($user['program_code'] ?: 'Regular'); ?></strong>
          </div>
        </div>
      </div>
    </div>

    <div style="margin-bottom:20px;">
      <h2 style="margin:0;font-size:20px;font-weight:800;color:#0f172a;">Course Gradebooks</h2>
      <p style="margin:3px 0 0;font-size:12.5px;color:#64748b;">Official term grades and real-time performance summaries by subject section</p>
    </div>

    <?php if (empty($enrolledClasses)): ?>
      <div style="background:#fff;border-radius:18px;padding:48px;text-align:center;border:1px solid #e2e8f0;">
        <i class="fa fa-bar-chart" style="font-size:36px;color:#cbd5e1;margin-bottom:12px;"></i>
        <h3 style="margin:0 0 4px;font-size:16px;font-weight:700;color:#0f172a;">No Classes Enrolled</h3>
        <p style="margin:0;font-size:13px;color:#64748b;">You are not currently enrolled in any active class sections.</p>
      </div>
    <?php else: ?>
      <?php foreach ($enrolledClasses as $c):
        $cid = (int)$c['id'];
        $teacherName = trim(($c['teacher_first'] ?? '') . ' ' . ($c['teacher_last'] ?? ''));
        if (!$teacherName) $teacherName = 'Instructor Unassigned';

        $pubMid = $publishedGradesByClass[$cid]['midterm'] ?? null;
        $pubFinal = $publishedGradesByClass[$cid]['final'] ?? null;

        $rtMid = getRealTimeClassGrade($conn, $cid, $uc, 'midterm');
        $rtFinal = getRealTimeClassGrade($conn, $cid, $uc, 'final');

        $cardStatus = 'Enrolled';
        $cardBadgeClass = 'grade-badge-pass';
        if ($pubFinal) {
            $cardStatus = ($pubFinal['remarks'] === 'Passed') ? 'Passed' : 'Failed';
            $cardBadgeClass = ($pubFinal['remarks'] === 'Passed') ? 'grade-badge-pass' : 'grade-badge-fail';
        } elseif ($pubMid) {
            $cardStatus = 'Midterm Released';
            $cardBadgeClass = 'grade-badge-pass';
        }
      ?>
      <div class="grade-card">
        <div class="gc-header">
          <div class="gc-title">
            <h3><?php echo htmlspecialchars($c['class_name']); ?></h3>
            <span>Subject Code: <strong><?php echo htmlspecialchars($c['class_code']); ?></strong> &bull; Instructor: <?php echo htmlspecialchars($teacherName); ?></span>
          </div>
          <span class="<?php echo $cardBadgeClass; ?>"><?php echo $cardStatus; ?></span>
        </div>
        <table class="grade-table">
          <thead>
            <tr>
              <th>Term</th>
              <th>Raw Average</th>
              <th>Transmuted Grade</th>
              <th>Remarks</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <!-- Midterm Row -->
            <?php
            if ($pubMid):
              $rawMid = ($pubMid['grade'] !== null && $pubMid['grade'] !== '') ? number_format(floatval($pubMid['grade']), 1) . '%' : '—';
              $transMid = $pubMid['transmuted'] ?: '—';
              $remMid = $pubMid['remarks'] ?: '—';
              $stBadgeMid = '<span class="grade-badge-pass"><i class="fa fa-check-circle"></i> Published (' . date('M d, Y', strtotime($pubMid['published_at'])) . ')</span>';
            elseif ($rtMid !== null):
              $rawMid = number_format($rtMid, 1) . '%';
              $transMid = transmuteGradeValue($rtMid);
              $remMid = ($rtMid >= 75) ? 'Passed' : 'Failed';
              $stBadgeMid = '<span style="background:#fef3c7;color:#92400e;padding:4px 12px;border-radius:99px;font-size:11px;font-weight:700;border:1px solid #fde68a;"><i class="fa fa-clock-o"></i> In Progress (Unpublished)</span>';
            else:
              $rawMid = '—';
              $transMid = '—';
              $remMid = '—';
              $stBadgeMid = '<span style="background:#f1f5f9;color:#64748b;padding:4px 12px;border-radius:99px;font-size:11px;font-weight:600;border:1px solid #e2e8f0;">Pending</span>';
            endif;
            ?>
            <tr>
              <td><strong>Midterm</strong></td>
              <td><?php echo $rawMid; ?></td>
              <td><strong style="color:#2563eb;"><?php echo $transMid; ?></strong></td>
              <td><span class="<?php echo $remMid === 'Passed' ? 'grade-badge-pass' : ($remMid === 'Failed' ? 'grade-badge-fail' : ''); ?>"><?php echo $remMid; ?></span></td>
              <td><?php echo $stBadgeMid; ?></td>
            </tr>

            <!-- Final Term Row -->
            <?php
            if ($pubFinal):
              $rawFin = ($pubFinal['grade'] !== null && $pubFinal['grade'] !== '') ? number_format(floatval($pubFinal['grade']), 1) . '%' : '—';
              $transFin = $pubFinal['transmuted'] ?: '—';
              $remFin = $pubFinal['remarks'] ?: '—';
              $stBadgeFin = '<span class="grade-badge-pass"><i class="fa fa-check-circle"></i> Published (' . date('M d, Y', strtotime($pubFinal['published_at'])) . ')</span>';
            elseif ($rtFinal !== null):
              $rawFin = number_format($rtFinal, 1) . '%';
              $transFin = transmuteGradeValue($rtFinal);
              $remFin = ($rtFinal >= 75) ? 'Passed' : 'Failed';
              $stBadgeFin = '<span style="background:#fef3c7;color:#92400e;padding:4px 12px;border-radius:99px;font-size:11px;font-weight:700;border:1px solid #fde68a;"><i class="fa fa-clock-o"></i> In Progress (Unpublished)</span>';
            else:
              $rawFin = '—';
              $transFin = '—';
              $remFin = '—';
              $stBadgeFin = '<span style="background:#f1f5f9;color:#64748b;padding:4px 12px;border-radius:99px;font-size:11px;font-weight:600;border:1px solid #e2e8f0;">Pending</span>';
            endif;
            ?>
            <tr>
              <td><strong>Final Term</strong></td>
              <td><?php echo $rawFin; ?></td>
              <td><strong style="color:#2563eb;"><?php echo $transFin; ?></strong></td>
              <td><span class="<?php echo $remFin === 'Passed' ? 'grade-badge-pass' : ($remFin === 'Failed' ? 'grade-badge-fail' : ''); ?>"><?php echo $remFin; ?></span></td>
              <td><?php echo $stBadgeFin; ?></td>
            </tr>
          </tbody>
        </table>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
</div>

<script src="/cenlearn/system/bower_components/jquery/dist/jquery.min.js"></script>
<script src="/cenlearn/system/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
function openSidebar(){ document.getElementById('sidebar').classList.add('open'); }
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
