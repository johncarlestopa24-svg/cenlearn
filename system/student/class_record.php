<?php
include '../includes/session.php';
include '../includes/conn.php';

if(strtoupper($user['user_group']) !== 'STUDENT'){
    header('location: dashboard'); exit;
}

$class_id = intval($_GET['id'] ?? 0);
$uc = $conn->real_escape_string($user['user_code']);
if(!$class_id){ header('location: classes'); exit; }

// Validate enrollment
$memQ = $conn->query("SELECT 1 FROM class_members WHERE class_id=$class_id AND user_code='$uc'");
if($memQ->num_rows === 0){ die('Access denied.'); }

// Fetch class
$cq = $conn->query("SELECT c.*, u.first_name AS tf, u.last_name AS tl FROM classes c LEFT JOIN users u ON c.teacher_code=u.user_code WHERE c.id=$class_id AND (c.is_subject_only = 0 OR c.is_subject_only IS NULL) AND (c.is_archived = 0 OR c.is_archived IS NULL)");
if($cq->num_rows === 0){ die('Class not found.'); }
$class = $cq->fetch_assoc();
$teacherName = trim($class['tf'].' '.$class['tl']);

$term = $_GET['term'] ?? 'midterm';
if(!in_array($term, ['midterm', 'final'])) $term = 'midterm';

// Load weights
$wq = $conn->query("SELECT * FROM class_record_weights WHERE class_id=$class_id");
$weights = $wq->num_rows > 0 ? $wq->fetch_assoc() : [
    'written_pct'=>20,
    'performance_pct'=>40,
    'exam_pct'=>30,
    'attendance_pct'=>10,
    'grading_method'=>'sum_of_points',
    'base_grade'=>0,
    'midterm_weight'=>40,
    'final_weight'=>60,
    'extra_weights'=>'[]'
];
if(!isset($weights['grading_method'])) $weights['grading_method'] = 'sum_of_points';
if(!isset($weights['base_grade'])) $weights['base_grade'] = 0;
if(!isset($weights['midterm_weight'])) $weights['midterm_weight'] = 40;
if(!isset($weights['final_weight'])) $weights['final_weight'] = 60;
if(!isset($weights['extra_weights'])) $weights['extra_weights'] = '[]';

// Load columns & scores for both terms
$allColsQ = $conn->query("SELECT * FROM class_record_columns WHERE class_id=$class_id ORDER BY component,sort_order,id");
$allColumns = [];
while($r = $allColsQ->fetch_assoc()) $allColumns[] = $r;

// Separate columns by term
$midtermCols = array_filter($allColumns, fn($c) => $c['term'] === 'midterm');
$finalCols = array_filter($allColumns, fn($c) => $c['term'] === 'final');
$columns = $term === 'midterm' ? $midtermCols : $finalCols;

$scoresQ = $conn->query("SELECT * FROM class_record_scores WHERE class_id=$class_id AND student_code='$uc'");
$scores = [];
while($r = $scoresQ->fetch_assoc()) $scores[$r['column_id']] = $r['score'];

// Organize columns by component for current term
$colsByComp = ['written'=>[],'performance'=>[],'exam'=>[],'deportment'=>[],'attendance'=>[]];
foreach($columns as $col) {
    if($col['component'] === 'deportment') {
        $colsByComp['deportment'][] = $col;
    } elseif(!empty($col['session_id']) || $col['component'] === 'attendance') {
        $colsByComp['attendance'][] = $col;
    } else {
        $compKey = $col['component'];
        if(!isset($colsByComp[$compKey])) $colsByComp[$compKey] = [];
        $colsByComp[$compKey][] = $col;
    }
}

// Organize columns by component for both terms
$midtermColsByComp = ['written'=>[],'performance'=>[],'exam'=>[],'deportment'=>[],'attendance'=>[]];
foreach($midtermCols as $col) {
    if($col['component'] === 'deportment') {
        $midtermColsByComp['deportment'][] = $col;
    } elseif(!empty($col['session_id']) || $col['component'] === 'attendance') {
        $midtermColsByComp['attendance'][] = $col;
    } else {
        $compKey = $col['component'];
        if(!isset($midtermColsByComp[$compKey])) $midtermColsByComp[$compKey] = [];
        $midtermColsByComp[$compKey][] = $col;
    }
}

// Organize columns by component for both terms
$finalColsByComp = ['written'=>[],'performance'=>[],'exam'=>[],'deportment'=>[],'attendance'=>[]];
foreach($finalCols as $col) {
    if($col['component'] === 'deportment') {
        $finalColsByComp['deportment'][] = $col;
    } elseif(!empty($col['session_id']) || $col['component'] === 'attendance') {
        $finalColsByComp['attendance'][] = $col;
    } else {
        $compKey = $col['component'];
        if(!isset($finalColsByComp[$compKey])) $finalColsByComp[$compKey] = [];
        $finalColsByComp[$compKey][] = $col;
    }
}

// Compute grades
function computeStudentGrade($studentCode, $colsByComp, $scores, $weights) {
    $method = $weights['grading_method'] ?? 'sum_of_points';
    $base = (int)($weights['base_grade'] ?? 0);
    if ($base < 0 || $base >= 100) $base = 0;

    $compAvg = [];
    foreach(['written','performance','exam','deportment'] as $comp) {
        $cols = $colsByComp[$comp] ?? [];
        $regularCols = array_filter($cols, fn($c) => empty($c['session_id']));
        if(empty($regularCols)){ $compAvg[$comp] = null; }
        else {
            if ($method === 'avg_of_pct') {
                $pcts = [];
                foreach($regularCols as $col) {
                    $sc = $scores[$col['id']] ?? null;
                    if($sc !== null && $col['max_score'] > 0){
                        $pcts[] = ($sc / $col['max_score']) * 100;
                    }
                }
                $raw = count($pcts) ? (array_sum($pcts) / count($pcts)) : null;
            } else {
                $total = 0; $max = 0; $hasAny = false;
                foreach($regularCols as $col) {
                    $sc = $scores[$col['id']] ?? null;
                    if($sc !== null){ 
                        $total += $sc; 
                        $max += $col['max_score']; 
                        $hasAny = true; 
                    }
                }
                $raw = ($hasAny && $max > 0) ? ($total / $max) * 100 : null;
            }

            if ($raw !== null) {
                $compAvg[$comp] = round($raw * (100 - $base) / 100 + $base, 2);
            } else {
                $compAvg[$comp] = null;
            }
        }
    }

    $wTotal = 0; $wWeight = 0;
    $compMap = [
        'written'    => 'written_pct',
        'performance'=> 'performance_pct',
        'exam'       => 'exam_pct',
        'deportment' => 'attendance_pct',
    ];
    foreach($compMap as $comp => $key) {
        if(isset($compAvg[$comp]) && $compAvg[$comp] !== null && isset($weights[$key])) {
            $wTotal  += $compAvg[$comp] * $weights[$key];
            $wWeight += $weights[$key];
        }
    }
    if(!empty($weights['extra_weights'])){
        $extraArr = json_decode($weights['extra_weights'], true);
        if(is_array($extraArr)){
            foreach($extraArr as $ew){
                $wWeight += intval($ew['pct'] ?? 0);
            }
        }
    }
    $final = $wWeight > 0 ? round($wTotal / $wWeight, 2) : null;
    return ['components'=>$compAvg,'final'=>$final];
}

$myGrade = computeStudentGrade($uc, $colsByComp, $scores, $weights);
$myMidtermGrade = computeStudentGrade($uc, $midtermColsByComp, $scores, $weights);
$myFinalGrade = computeStudentGrade($uc, $finalColsByComp, $scores, $weights);

$midPct = floatval($weights['midterm_weight'] ?? 40) / 100;
$finPct = floatval($weights['final_weight'] ?? 60) / 100;

// Calculate overall grade using custom term weights
$midVal = $myMidtermGrade['final'];
$finVal = $myFinalGrade['final'];

if ($midVal !== null && $finVal !== null) {
    $myOverallGrade = round(($midVal * $midPct) + ($finVal * $finPct), 2);
} elseif ($midVal !== null) {
    $myOverallGrade = $midVal;
} elseif ($finVal !== null) {
    $myOverallGrade = $finVal;
} else {
    $myOverallGrade = null;
}

// Fetch published term grade if any
$pubQ = $conn->query("SELECT * FROM published_grades WHERE class_id=$class_id AND term='$term' AND student_code='$uc'");
$publishedGrade = $pubQ->num_rows > 0 ? $pubQ->fetch_assoc() : null;

// Helper transmutation
function transmuteGrade($grade) {
    if($grade === null) return '—';
    if($grade >= 99) return '1.00'; if($grade >= 96) return '1.25'; if($grade >= 93) return '1.50';
    if($grade >= 90) return '1.75'; if($grade >= 87) return '2.00'; if($grade >= 84) return '2.25';
    if($grade >= 81) return '2.50'; if($grade >= 78) return '2.75'; if($grade >= 75) return '3.00';
    return '5.00';
}

// Live attendance sessions
$sessQ = $conn->query("
    SELECT ls.id, ls.title, ls.started_at, la.joined_at
    FROM live_sessions ls
    LEFT JOIN live_attendance la ON la.session_id=ls.id AND la.student_code='$uc'
    WHERE ls.class_id=$class_id AND ls.status='ended'
    ORDER BY ls.started_at ASC
");
$sessions = [];
while($r = $sessQ->fetch_assoc()) $sessions[] = $r;
$totalSessions = count($sessions);
$totalAttPoints = 0;
foreach ($sessions as $s) {
    if (!empty($s['joined_at'])) {
        $startedTime = strtotime($s['started_at']);
        $joinedTime = strtotime($s['joined_at']);
        $diffMinutes = ($joinedTime - $startedTime) / 60;
        if ($diffMinutes >= 15) {
            $totalAttPoints += 1.00;
        } else {
            $totalAttPoints += 2.00;
        }
    }
}
$attPct = $totalSessions > 0 ? round(($totalAttPoints / ($totalSessions * 2)) * 100, 1) : null;

$initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
?>
<!DOCTYPE html>
<html lang="en">
<head>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CenLearn — My Course Record</title>
  <link rel="stylesheet" href="/cenlearn/system/bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/cenlearn/system/bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/cenlearn/system/dist/css/cenlearn.css">
  <style>
    *{box-sizing:border-box;}
    body{font-family:'Inter',sans-serif;background:#f0f4f8;margin:0;color:#1e293b;}

    /* ── Sidebar ── */
    .s-sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:linear-gradient(180deg,#0c1a2e 0%,#0f2d4a 55%,#0f5f80 100%);display:flex;flex-direction:column;z-index:200;transition:transform .3s;}
    .sb-brand{padding:26px 22px 18px;border-bottom:1px solid rgba(255,255,255,.08);}
    .sb-logo{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#1792bb,#0f5f80);display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px;box-shadow:0 4px 12px rgba(23,146,187,.4);}
    .sb-logo i{color:#fff;font-size:17px;}
    .sb-brand h2{color:#fff;font-size:19px;font-weight:800;margin:0;}
    .sb-brand h2 span{color:#38bdf8;}
    .sb-brand p{color:rgba(255,255,255,.35);font-size:10px;margin:2px 0 0;}
    .sb-nav{flex:1;padding:14px 0;overflow-y:auto;}
    .sb-section{padding:8px 22px 4px;font-size:9px;font-weight:700;color:rgba(255,255,255,.25);letter-spacing:1.4px;text-transform:uppercase;}
    .sb-nav ul{list-style:none;margin:0;padding:0;}
    .sb-nav li a{display:flex;align-items:center;gap:11px;padding:10px 22px;color:rgba(255,255,255,.6);text-decoration:none;font-size:13px;font-weight:500;transition:all .2s;border-left:3px solid transparent;}
    .sb-nav li a:hover{background:rgba(255,255,255,.07);color:#fff;}
    .sb-nav li.active a{background:rgba(56,189,248,.12);color:#fff;border-left-color:#38bdf8;}
    .sb-nav li a i{width:17px;text-align:center;font-size:14px;}
    .sb-footer{padding:14px 22px;border-top:1px solid rgba(255,255,255,.07);}
    .sb-user{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
    .sb-av{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#1792bb,#0f5f80);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;flex-shrink:0;}
    .sb-meta strong{display:block;color:#fff;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .sb-meta span{color:rgba(255,255,255,.4);font-size:10px;}
    .sb-out{display:flex;align-items:center;justify-content:center;gap:6px;padding:8px;width:100%;background:rgba(255,255,255,.07);color:rgba(255,255,255,.6);border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:12px;font-weight:500;text-decoration:none;transition:background .2s;}
    .sb-out:hover{background:rgba(255,255,255,.13);color:#fff;}

    /* ── Main Layout ── */
    .sd-main{margin-left:260px;min-height:100vh;display:flex;flex-direction:column;}
    .sd-topbar{background:#fff;padding:0 28px;height:60px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:50;box-shadow:0 1px 4px rgba(0,0,0,.04);}
    .sd-topbar-title h3{font-size:16px;font-weight:700;color:#0f172a;margin:0;}
    .sd-topbar-title p{font-size:12px;color:#64748b;margin:0;}
    .sd-content{padding:24px 28px 40px;flex:1;}

    /* ── Hero Card ── */
    .record-hero{background:linear-gradient(135deg,#0c1a2e 0%,#0f2d4a 60%,#0f5f80 100%);border-radius:20px;padding:28px 32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;box-shadow:0 10px 25px rgba(12,26,46,0.15);}
    .record-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none;}
    .record-hero-inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;}
    .record-hero h2{font-size:22px;font-weight:800;margin:0 0 6px;}
    .record-hero p{color:rgba(255,255,255,.7);font-size:13px;margin:0;}

    /* ── KPIs ── */
    .kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;}
    .kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px 20px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.02);transition:transform .15s;}
    .kpi-card:hover{transform:translateY(-2px);}
    .kpi-card strong{display:block;font-size:26px;font-weight:800;line-height:1.1;margin-bottom:4px;}
    .kpi-card span{font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;}

    /* ── Tab Switcher ── */
    .tab-bar{display:flex;gap:4px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:5px;margin-bottom:20px;overflow-x:auto;}
    .tab-btn{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border:none;background:transparent;border-radius:10px;font-size:13px;font-weight:600;color:#64748b;cursor:pointer;font-family:'Inter',sans-serif;transition:all .15s;white-space:nowrap;text-decoration:none !important;}
    .tab-btn:hover{background:#f1f5f9;color:#0f172a;}
    .tab-btn.active{background:linear-gradient(135deg,#1792bb,#0f5f80);color:#fff;box-shadow:0 4px 12px rgba(23,146,187,.25);}
    .tab-panel{display:none;}
    .tab-panel.active{display:block;}

    /* ── Cards & Lists ── */
    .record-card{background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:24px;box-shadow:0 1px 4px rgba(0,0,0,.03);margin-bottom:20px;}
    .record-card-title{font-size:14px;font-weight:700;color:#0f172a;margin:0 0 16px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #f1f5f9;padding-bottom:12px;}
    
    /* ── Table style ── */
    .custom-table{width:100%;border-collapse:collapse;font-size:13px;}
    .custom-table th,.custom-table td{padding:10px 12px;text-align:left;border-bottom:1px solid #e2e8f0;}
    .custom-table th{background:#f8fafc;color:#64748b;font-weight:700;text-transform:uppercase;font-size:11px;letter-spacing:0.5px;}
    .custom-table tr:hover td{background:#f8fafc;}

    /* Badges */
    .term-badge{font-size:11px;font-weight:700;padding:4px 10px;border-radius:6px;text-transform:uppercase;display:inline-block;}
    .status-badge{font-size:11px;font-weight:700;padding:3px 8px;border-radius:5px;display:inline-flex;align-items:center;gap:4px;}
    .badge-pass{background:#dcfce7;color:#166534;}
    .badge-fail{background:#fee2e2;color:#991b1b;}
    .badge-pending{background:#fef3c7;color:#92400e;}

    /* Progress bar */
    .grade-bar-wrap{background:#f1f5f9;border-radius:99px;height:8px;width:100%;overflow:hidden;margin-top:4px;}
    .grade-bar{height:100%;border-radius:99px;transition:width 0.4s ease-out;}

    @media (max-width: 768px) {
      .sd-main{margin-left:0;}
      .sd-content{padding:16px 14px;}
    /* Header User Profile & Logout Dropdown */
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
  </style>
</head>
<body>
<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<aside class="s-sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo"><i class="fa fa-graduation-cap"></i></div>
    <h2>Cen<span>Learn</span></h2>
    <p>Learning Management System</p>
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
  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-av"><?php echo $initials; ?></div>
      <div class="sb-meta">
        <strong><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></strong>
        <span>Student &bull; <?php echo htmlspecialchars($user['program_code'] ?? 'No program'); ?></span>
      </div>
    </div>
    <a href="/cenlearn/logout" class="sb-out"><i class="fa fa-sign-out"></i> Sign Out</a>
  </div>
</aside>

<div class="sd-main">
  <header class="sd-topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
      <div class="sd-topbar-title">
        <h3>My Class Record</h3>
        <p><?php echo htmlspecialchars($class['class_name']); ?> &bull; Prof. <?php echo htmlspecialchars($teacherName); ?></p>
      </div>
    </div>
    <div class="header-user">
      <div class="user-profile-wrap">
        <div class="user-profile-btn" onclick="toggleProfileMenu(event)" title="<?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?>">
          <div class="user-avatar"><?php echo $initials; ?></div>
        </div>

        <div class="profile-dropdown-menu" id="profileMenu">
          <div class="pdm-header">
            <strong><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></strong>
            <span>Student &bull; <?php echo htmlspecialchars($user['program_code'] ?? 'Regular'); ?></span>
          </div>
          <a href="javascript:void(0)" class="pdm-item" onclick="openStudentProfileModal()"><i class="fa fa-user-circle"></i> Student Profile</a>
          <div class="pdm-divider"></div>
          <a href="/cenlearn/logout" class="pdm-item danger"><i class="fa fa-sign-out"></i> Log Out</a>
        </div>
      </div>
    </div>
  </header>

  <div class="sd-content">

    <!-- Record Hero Banner -->
    <div class="record-hero">
      <div class="record-hero-inner">
        <div>
          <h2><?php echo htmlspecialchars($class['class_name']); ?> Record</h2>
          <p><?php echo htmlspecialchars($class['subject']?:'Detailed Gradebook & Attendance Breakdown'); ?></p>
        </div>
        <!-- Term selector sliding tabs -->
        <div style="display:flex;gap:4px;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:4px;">
          <a href="?id=<?php echo $class_id; ?>&term=midterm" class="btn btn-sm <?php echo $term==='midterm'?'btn-primary':''; ?>" style="<?php echo $term==='midterm'?'background:#10b981;border:none;box-shadow:0 2px 6px rgba(16,185,129,0.3);':'color:#fff;'; ?> font-weight:700; border-radius:8px; font-size:11px; padding:6px 14px;"><i class="fa fa-clock-o"></i> Midterm</a>
          <a href="?id=<?php echo $class_id; ?>&term=final" class="btn btn-sm <?php echo $term==='final'?'btn-primary':''; ?>" style="<?php echo $term==='final'?'background:#10b981;border:none;box-shadow:0 2px 6px rgba(16,185,129,0.3);':'color:#fff;'; ?> font-weight:700; border-radius:8px; font-size:11px; padding:6px 14px;"><i class="fa fa-clock-o"></i> Final</a>
        </div>
      </div>
    </div>

    <!-- KPIs Metric Cards -->
    <div class="kpi-row">
      <div class="kpi-card">
        <strong style="color:#0f5f80;"><?php echo $myGrade['final'] !== null ? $myGrade['final'].'%' : '—'; ?></strong>
        <span>Running Term Grade</span>
      </div>
      <div class="kpi-card">
        <strong style="color:#92400e;"><?php echo $myOverallGrade !== null ? $myOverallGrade.'%' : '—'; ?></strong>
        <span>Overall Final Grade (40% Mid + 60% Final)</span>
      </div>
      <div class="kpi-card">
        <strong style="color:#5b21b6;"><?php echo transmuteGrade($myOverallGrade); ?></strong>
        <span>Transmuted Grade</span>
      </div>
      <div class="kpi-card">
        <strong style="color:#10b981;"><?php echo $attPct !== null ? $attPct.'%' : '—'; ?></strong>
        <span>Attendance Rate</span>
      </div>
    </div>

    <!-- Tab Switching Menu -->
    <div class="tab-bar">
      <button class="tab-btn active" onclick="switchTab('tab-summary',this)"><i class="fa fa-pie-chart"></i> Weighted Summary</button>
      <button class="tab-btn" onclick="switchTab('tab-written',this)"><i class="fa fa-pencil"></i> Quiz</button>
      <button class="tab-btn" onclick="switchTab('tab-performance',this)"><i class="fa fa-tasks"></i> Performance Task</button>
      <button class="tab-btn" onclick="switchTab('tab-exam',this)"><i class="fa fa-graduation-cap"></i> Exam</button>
      <button class="tab-btn" onclick="switchTab('tab-deportment',this)"><i class="fa fa-smile-o"></i> Deportment</button>
      <button class="tab-btn" onclick="switchTab('tab-attendance',this)"><i class="fa fa-calendar-check-o"></i> Attendance Log</button>
    </div>

    <!-- ===== TAB 1: SUMMARY ===== -->
    <div class="tab-panel active" id="tab-summary">
      <div class="row">
        <div class="col-md-7">
          <div class="record-card">
            <h4 class="record-card-title"><i class="fa fa-pie-chart" style="color:#1792bb;"></i> Weighted Grade Component Breakdown</h4>
            <div class="table-responsive">
              <table class="custom-table">
                <thead>
                  <tr>
                    <th>Component Name</th>
                    <th>Weight</th>
                    <th>Your Avg %</th>
                    <th>Contribution</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $compNames = ['written'=>'Quiz', 'performance'=>'Performance Task', 'exam'=>'Exam', 'attendance'=>'Deportment'];
                  $compKeys  = ['written'=>'written_pct', 'performance'=>'performance_pct', 'exam'=>'exam_pct', 'attendance'=>'attendance_pct'];
                  foreach($compNames as $comp => $label):
                    $avg = $myGrade['components'][$comp] ?? null;
                    $weight = $weights[$compKeys[$comp]] ?? 0;
                    $contrib = ($avg !== null) ? round($avg * $weight / 100, 2) : null;
                  ?>
                  <tr>
                    <td><strong><?php echo $label; ?></strong></td>
                    <td><?php echo $weight; ?>%</td>
                    <td style="font-weight:700; color:<?php echo $avg>=75?'#166534':'#0f172a'; ?>;"><?php echo $avg !== null ? $avg.'%' : '—'; ?></td>
                    <td style="font-weight:700; color:#1792bb;"><?php echo $contrib !== null ? $contrib.'%' : '—'; ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-md-5">
          <!-- Official Released Card -->
          <div class="record-card" style="background: linear-gradient(135deg, #1e293b, #0f172a); color:#fff; text-shadow:0 1px 3px rgba(0,0,0,0.3);">
            <h4 class="record-card-title" style="color:#fff; border-color: rgba(255,255,255,0.1);"><i class="fa fa-check-circle" style="color:#10b981;"></i> Official Released Status</h4>
            <?php if($publishedGrade): ?>
              <div style="text-align:center; padding:10px 0;">
                <div style="font-size:11px; color:#cbd5e1; text-transform:uppercase; letter-spacing:1px;">Officially Published Grade</div>
                <div style="font-size:38px; font-weight:800; color:#10b981; margin:5px 0;"><?php echo number_format($publishedGrade['grade'], 2); ?>%</div>
                <div style="display:inline-flex; align-items:center; gap:8px; margin-bottom:12px;">
                  <span class="term-badge" style="background:#e0e7ff; color:#3730a3;"><?php echo htmlspecialchars($publishedGrade['term']); ?></span>
                  <span class="term-badge <?php echo $publishedGrade['remarks']==='Passed'?'badge-pass':'badge-fail'; ?>"><?php echo htmlspecialchars($publishedGrade['remarks']); ?></span>
                </div>
                <div style="font-size:14px; font-weight:700; color:#c084fc;">Transmuted Grade: <?php echo htmlspecialchars($publishedGrade['transmuted']); ?></div>
                <div style="font-size:11px; color:#94a3b8; margin-top:14px;"><i class="fa fa-calendar"></i> Released: <?php echo date('M d, Y g:i A', strtotime($publishedGrade['published_at'])); ?></div>
              </div>
            <?php else: ?>
              <div style="text-align:center; padding:32px 16px;">
                <i class="fa fa-clock-o fa-3x" style="color:#f59e0b; margin-bottom:12px; display:block; opacity:0.8;"></i>
                <strong style="font-size:15px; display:block; color:#f59e0b; margin-bottom:4px;">Pending Teacher Release</strong>
                <p style="font-size:12px; color:#94a3b8; margin:0; line-height:1.4;">Your teacher has not officially submitted this term's grades yet. TheRunning Grade calculated on the left is a real-time estimation.</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ===== TAB 2: QUIZ ===== -->
    <div class="tab-panel" id="tab-written">
      <div class="record-card">
        <h4 class="record-card-title"><i class="fa fa-pencil" style="color:#3b82f6;"></i> Scored Quizzes</h4>
        <div class="table-responsive">
          <table class="custom-table">
            <thead>
              <tr>
                <th>Column Title</th>
                <th>Score Ratio</th>
                <th>Percentage</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $writtenCols = array_filter($colsByComp['written'], fn($c) => empty($c['session_id']));
              if(empty($writtenCols)):
              ?>
              <tr><td colspan="4" style="text-align:center; color:#94a3b8; padding:32px;">No quiz scores recorded yet.</td></tr>
              <?php else: ?>
              <?php foreach($writtenCols as $col):
                $sc = $scores[$col['id']] ?? null;
                $pct = ($sc !== null && $col['max_score'] > 0) ? round($sc / $col['max_score'] * 100, 1) : null;
              ?>
              <tr>
                <td><strong><?php echo htmlspecialchars($col['title']); ?></strong></td>
                <td><?php echo $sc !== null ? $sc : '—'; ?> / <?php echo (int)$col['max_score']; ?></td>
                <td style="font-weight:700;"><?php echo $pct !== null ? $pct.'%' : '—'; ?></td>
                <td>
                  <?php if($pct === null): ?>
                    <span class="status-badge badge-pending">Pending Score</span>
                  <?php else: ?>
                    <span class="status-badge <?php echo $pct>=75?'badge-pass':'badge-fail'; ?>"><?php echo $pct>=75?'Passed':'Failed'; ?></span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ===== TAB 3: PERFORMANCE TASK ===== -->
    <div class="tab-panel" id="tab-performance">
      <div class="record-card">
        <h4 class="record-card-title"><i class="fa fa-tasks" style="color:#10b981;"></i> Scored Performance Tasks</h4>
        <div class="table-responsive">
          <table class="custom-table">
            <thead>
              <tr>
                <th>Column Title</th>
                <th>Score Ratio</th>
                <th>Percentage</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if(empty($colsByComp['performance'])): ?>
              <tr><td colspan="4" style="text-align:center; color:#94a3b8; padding:32px;">No performance task scores recorded yet.</td></tr>
              <?php else: ?>
              <?php foreach($colsByComp['performance'] as $col):
                $sc = $scores[$col['id']] ?? null;
                $pct = ($sc !== null && $col['max_score'] > 0) ? round($sc / $col['max_score'] * 100, 1) : null;
              ?>
              <tr>
                <td><strong><?php echo htmlspecialchars($col['title']); ?></strong></td>
                <td><?php echo $sc !== null ? $sc : '—'; ?> / <?php echo (int)$col['max_score']; ?></td>
                <td style="font-weight:700;"><?php echo $pct !== null ? $pct.'%' : '—'; ?></td>
                <td>
                  <?php if($pct === null): ?>
                    <span class="status-badge badge-pending">Pending Score</span>
                  <?php else: ?>
                    <span class="status-badge <?php echo $pct>=75?'badge-pass':'badge-fail'; ?>"><?php echo $pct>=75?'Passed':'Failed'; ?></span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ===== TAB 4: EXAM ===== -->
    <div class="tab-panel" id="tab-exam">
      <div class="record-card">
        <h4 class="record-card-title"><i class="fa fa-graduation-cap" style="color:#8b5cf6;"></i> Term Exam</h4>
        <div class="table-responsive">
          <table class="custom-table">
            <thead>
              <tr>
                <th>Exam Title</th>
                <th>Score Ratio</th>
                <th>Percentage</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if(empty($colsByComp['exam'])): ?>
              <tr><td colspan="4" style="text-align:center; color:#94a3b8; padding:32px;">No exam scores recorded yet.</td></tr>
              <?php else: ?>
              <?php foreach($colsByComp['exam'] as $col):
                $sc = $scores[$col['id']] ?? null;
                $pct = ($sc !== null && $col['max_score'] > 0) ? round($sc / $col['max_score'] * 100, 1) : null;
              ?>
              <tr>
                <td><strong><?php echo htmlspecialchars($col['title']); ?></strong></td>
                <td><?php echo $sc !== null ? $sc : '—'; ?> / <?php echo (int)$col['max_score']; ?></td>
                <td style="font-weight:700;"><?php echo $pct !== null ? $pct.'%' : '—'; ?></td>
                <td>
                  <?php if($pct === null): ?>
                    <span class="status-badge badge-pending">Pending Score</span>
                  <?php else: ?>
                    <span class="status-badge <?php echo $pct>=75?'badge-pass':'badge-fail'; ?>"><?php echo $pct>=75?'Passed':'Failed'; ?></span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ===== TAB 5: DEPORTMENT ===== -->
    <div class="tab-panel" id="tab-deportment">
      <div class="record-card">
        <h4 class="record-card-title"><i class="fa fa-smile-o" style="color:#1d4ed8;"></i> Deportment Grades</h4>
        <div class="table-responsive">
          <table class="custom-table">
            <thead>
              <tr>
                <th>Deportment Title</th>
                <th>Date</th>
                <th style="text-align:center;">Score</th>
                <th style="text-align:center;">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $myDepCols = array_filter($columns, fn($c) => !empty($c['is_f2f']));
              if(empty($myDepCols)): ?>
              <tr><td colspan="4" style="text-align:center; color:#94a3b8; padding:32px;">No deportment grades recorded yet.</td></tr>
              <?php else: ?>
              <?php foreach($myDepCols as $col): 
                $sc = $scores[$col['id']] ?? null;
              ?>
              <tr>
                <td><strong><?php echo htmlspecialchars($col['title']); ?></strong></td>
                <td><?php echo date('M d, Y', strtotime($col['created_at'])); ?></td>
                <td style="text-align:center; font-weight:700;">
                  <?php echo $sc !== null ? ($sc == 1 ? 'Present (1.00 / 1.00)' : 'Absent (0.00 / 1.00)') : '—'; ?>
                </td>
                <td style="text-align:center;">
                  <?php if($sc !== null && $sc == 1): ?>
                    <span class="status-badge badge-pass"><i class="fa fa-check"></i> Passed</span>
                  <?php elseif($sc !== null && $sc == 0): ?>
                    <span class="status-badge badge-fail"><i class="fa fa-times"></i> Failed</span>
                  <?php else: ?>
                    <span class="status-badge badge-pending">Unrecorded</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ===== TAB 6: ATTENDANCE ===== -->
    <div class="tab-panel" id="tab-attendance">
      <div class="record-card">
        <h4 class="record-card-title"><i class="fa fa-calendar-check-o" style="color:#f59e0b;"></i> Attendance Log</h4>
        <div class="table-responsive">
          <table class="custom-table">
            <thead>
              <tr>
                <th>Session Title</th>
                <th>Session Date</th>
                <th>Attended At</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if(empty($sessions)): ?>
              <tr><td colspan="4" style="text-align:center; color:#94a3b8; padding:32px;">No live session attendance logs recorded yet.</td></tr>
              <?php else: ?>
              <?php foreach($sessions as $sess): 
                $statusHtml = '<span class="status-badge badge-fail"><i class="fa fa-times"></i> Absent</span>';
                if(!empty($sess['joined_at'])) {
                    $startedTime = strtotime($sess['started_at']);
                    $joinedTime = strtotime($sess['joined_at']);
                    $diffMinutes = ($joinedTime - $startedTime) / 60;
                    if($diffMinutes >= 15) {
                        $statusHtml = '<span class="status-badge badge-pending" style="background:#fff3cd; color:#856404;"><i class="fa fa-exclamation-triangle"></i> Late (1 pt)</span>';
                    } else {
                        $statusHtml = '<span class="status-badge badge-pass"><i class="fa fa-check"></i> Present (2 pts)</span>';
                    }
                }
              ?>
              <tr>
                <td><strong><?php echo htmlspecialchars($sess['title'] ?: 'Live Session'); ?></strong></td>
                <td><?php echo date('M d, Y g:i A', strtotime($sess['started_at'])); ?></td>
                <td><?php echo $sess['joined_at'] ? date('g:i A', strtotime($sess['joined_at'])) : '—'; ?></td>
                <td>
                  <?php echo $statusHtml; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
  <footer class="sd-footer" style="text-align:center; padding:16px; border-top:1px solid #e2e8f0; font-size:11px; color:#94a3b8; background:#fff; margin-top:40px;">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<script src="/cenlearn/system/bower_components/jquery/dist/jquery.min.js"></script>
<script src="/cenlearn/system/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
function switchTab(tabId, btn) {
  document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
  document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
  document.getElementById(tabId).classList.add('active');
  btn.classList.add('active');
}
function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
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
