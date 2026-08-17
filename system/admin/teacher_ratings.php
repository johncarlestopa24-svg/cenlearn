<?php
include '../includes/session.php';
include '../includes/conn.php';

$role = strtoupper($user['user_group']);
if(!in_array($role, ['ADMIN','SUPERADMIN'])){ header('location: ../index.php?role_mismatch=admin'); exit; }

// ── Dept theme (same logic as dashboard) ─────────────────────────────────
$dept = strtoupper(trim($user['department'] ?? ''));
$DEPT_THEMES = [
    'IS'   => ['name'=>'Information Systems','icon'=>'fa-desktop','dark'=>'#052e16','mid'=>'#14532d','base'=>'#16a34a','light'=>'#4ade80','bg'=>'#f0fdf4','border'=>'#dcfce7','text'=>'#15803d','shadow'=>'rgba(22,163,74,.28)','stat1'=>['g1'=>'#16a34a','g2'=>'#15803d','sh'=>'rgba(22,163,74,.3)'],'stat2'=>['g1'=>'#0d9488','g2'=>'#0f766e','sh'=>'rgba(13,148,136,.3)'],'stat3'=>['g1'=>'#6366f1','g2'=>'#4338ca','sh'=>'rgba(99,102,241,.3)']],
    'CRIM' => ['name'=>'Criminology','icon'=>'fa-balance-scale','dark'=>'#450a0a','mid'=>'#7f1d1d','base'=>'#dc2626','light'=>'#f87171','bg'=>'#fff1f2','border'=>'#fecdd3','text'=>'#b91c1c','shadow'=>'rgba(220,38,38,.28)','stat1'=>['g1'=>'#dc2626','g2'=>'#b91c1c','sh'=>'rgba(220,38,38,.3)'],'stat2'=>['g1'=>'#e11d48','g2'=>'#be123c','sh'=>'rgba(225,29,72,.3)'],'stat3'=>['g1'=>'#9333ea','g2'=>'#7e22ce','sh'=>'rgba(147,51,234,.3)']],
    'EDUC' => ['name'=>'Education','icon'=>'fa-graduation-cap','dark'=>'#172554','mid'=>'#1e3a8a','base'=>'#2563eb','light'=>'#60a5fa','bg'=>'#eff6ff','border'=>'#bfdbfe','text'=>'#1d4ed8','shadow'=>'rgba(37,99,235,.28)','stat1'=>['g1'=>'#2563eb','g2'=>'#1d4ed8','sh'=>'rgba(37,99,235,.3)'],'stat2'=>['g1'=>'#0891b2','g2'=>'#0e7490','sh'=>'rgba(8,145,178,.3)'],'stat3'=>['g1'=>'#7c3aed','g2'=>'#6d28d9','sh'=>'rgba(124,58,237,.3)']],
    'ART'  => ['name'=>'Arts','icon'=>'fa-paint-brush','dark'=>'#451a03','mid'=>'#78350f','base'=>'#d97706','light'=>'#fbbf24','bg'=>'#fffbeb','border'=>'#fde68a','text'=>'#b45309','shadow'=>'rgba(217,119,6,.28)','stat1'=>['g1'=>'#d97706','g2'=>'#b45309','sh'=>'rgba(217,119,6,.3)'],'stat2'=>['g1'=>'#ea580c','g2'=>'#c2410c','sh'=>'rgba(234,88,12,.3)'],'stat3'=>['g1'=>'#0891b2','g2'=>'#0e7490','sh'=>'rgba(8,145,178,.3)']],
];
$T = $DEPT_THEMES[$dept] ?? $DEPT_THEMES['IS'];

// ── Admin course filter ───────────────────────────────────────────────────
$adminCourses = [];
if(!empty($user['program_description'])){
    foreach(explode(',', $user['program_description']) as $c){
        $c = strtoupper(trim($c));
        if($c !== '') $adminCourses[] = $c;
    }
}
$courseInList = '';
if(!empty($adminCourses)){
    $escaped = array_map(fn($c)=>"'".$conn->real_escape_string($c)."'", $adminCourses);
    $courseInList = implode(',', $escaped);
}

// ── Compute teacher ratings ───────────────────────────────────────────────
$teachers = [];

if(!empty($courseInList)){
    $tq = $conn->query("
        SELECT DISTINCT u.user_code, u.first_name, u.last_name, u.email_address, u.is_active
        FROM users u
        INNER JOIN classes c ON c.teacher_code = u.user_code
            AND UPPER(c.program_code) IN ($courseInList)
        WHERE u.user_group = 'TEACHER'
        ORDER BY u.last_name, u.first_name
    ");

    while($t = $tq->fetch_assoc()){
        $tc = $conn->real_escape_string($t['user_code']);
        $cFilter = "AND UPPER(c.program_code) IN ($courseInList)";

        // ── 1. Student Quiz Average across all teacher's classes ─────────
        $qAvg = $conn->query("
            SELECT AVG(qs.score / NULLIF(qs.total_points,0)) AS avg_pct,
                   COUNT(qs.id) AS total_taken
            FROM quiz_submissions qs
            JOIN quizzes q ON qs.quiz_id = q.id
            JOIN classes c ON q.class_id = c.id
            WHERE c.teacher_code = '$tc' AND qs.total_points > 0 $cFilter
        ")->fetch_assoc();
        $quizAvg   = $qAvg['total_taken'] > 0 ? round(floatval($qAvg['avg_pct']) * 100, 1) : null;
        $quizCount = intval($qAvg['total_taken']);

        // ── 2. Assignment Grade Average ───────────────────────────────────
        $aAvg = $conn->query("
            SELECT AVG(s.grade / NULLIF(a.points,0)) AS avg_pct,
                   COUNT(s.id) AS total_graded
            FROM assignment_submissions s
            JOIN assignments a ON s.assignment_id = a.id
            JOIN classes c ON a.class_id = c.id
            WHERE c.teacher_code = '$tc' AND s.grade IS NOT NULL AND a.points > 0 $cFilter
        ")->fetch_assoc();
        $assignAvg   = $aAvg['total_graded'] > 0 ? round(floatval($aAvg['avg_pct']) * 100, 1) : null;
        $assignCount = intval($aAvg['total_graded']);

        // ── 3. Pass Rate from published_grades ────────────────────────────
        $pgRes = $conn->query("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN UPPER(pg.remarks)='PASSED' THEN 1 ELSE 0 END) AS passed
            FROM published_grades pg
            JOIN classes c ON pg.class_id = c.id
            WHERE c.teacher_code = '$tc' $cFilter
        ")->fetch_assoc();
        $passRate  = ($pgRes['total'] > 0) ? round(($pgRes['passed'] / $pgRes['total']) * 100, 1) : null;
        $passTotal = intval($pgRes['total']);

        // ── 4. Student Behavior — quiz tab switches & fullscreen exits ────
        $behRes = $conn->query("
            SELECT COUNT(qs.id) AS total_subs,
                   SUM(qs.tab_switches) AS total_tabs,
                   SUM(qs.fullscreen_exits) AS total_fs
            FROM quiz_submissions qs
            JOIN quizzes q ON qs.quiz_id = q.id
            JOIN classes c ON q.class_id = c.id
            WHERE c.teacher_code = '$tc' $cFilter
        ")->fetch_assoc();
        $totalSubs  = intval($behRes['total_subs']);
        $avgTabs    = $totalSubs > 0 ? round($behRes['total_tabs'] / $totalSubs, 2) : 0;
        $avgFS      = $totalSubs > 0 ? round($behRes['total_fs']   / $totalSubs, 2) : 0;
        // Integrity score: 100 - penalty for avg cheating indicators (capped 0–100)
        $integrityScore = max(0, 100 - min(($avgTabs * 8) + ($avgFS * 12), 100));

        // ── 5. Violations ─────────────────────────────────────────────────
        $vRes = $conn->query("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END) AS unresolved
            FROM teacher_violations tv
            JOIN classes c ON tv.class_id = c.id
            WHERE tv.teacher_code = '$tc' $cFilter
        ")->fetch_assoc();
        $violTotal      = intval($vRes['total']);
        $violUnresolved = intval($vRes['unresolved']);

        // ── 6. Missed Assignments (how many went unsubmitted class-wide) ──
        $missRes = $conn->query("
            SELECT COUNT(*) AS total_assign,
                   SUM(submitted) AS total_submitted
            FROM (
                SELECT a.id,
                       (SELECT COUNT(*) FROM assignment_submissions s
                        JOIN class_members cm ON s.student_code=cm.user_code AND cm.class_id=a.class_id
                        WHERE s.assignment_id=a.id) AS submitted
                FROM assignments a
                JOIN classes c ON a.class_id=c.id
                WHERE c.teacher_code='$tc' $cFilter
                  AND (a.due_date IS NULL OR a.due_date < NOW())
            ) sub
        ")->fetch_assoc();
        $totalAssign     = intval($missRes['total_assign']);
        $totalSubmitted  = intval($missRes['total_submitted']);

        // ── 7. Class count & total students ──────────────────────────────
        $classInfo = $conn->query("
            SELECT COUNT(DISTINCT c.id) AS classes,
                   COUNT(DISTINCT cm.user_code) AS students
            FROM classes c
            LEFT JOIN class_members cm ON cm.class_id=c.id AND cm.user_code!=c.teacher_code
            WHERE c.teacher_code='$tc' $cFilter
              AND (c.is_archived=0 OR c.is_archived IS NULL)
        ")->fetch_assoc();
        $classCount   = intval($classInfo['classes']);
        $studentCount = intval($classInfo['students']);

        // ── Compute final rating (0–100) ──────────────────────────────────
        // Weights: Quiz avg 30%, Assign avg 20%, Pass rate 25%, Integrity 15%, Violation penalty 10%
        $quizScore    = $quizAvg   ?? 50;  // neutral if no data
        $assignScore  = $assignAvg ?? 50;
        $passScore    = $passRate  ?? 50;
        $violPenalty  = min($violUnresolved * 5, 20); // up to 20pts penalty
        $rawRating    = ($quizScore * 0.30) + ($assignScore * 0.20) + ($passScore * 0.25) + ($integrityScore * 0.15) - $violPenalty;
        $rating       = round(max(0, min(100, $rawRating)));

        // ── Rating label ──────────────────────────────────────────────────
        if($rating >= 85)      $rLabel = 'Excellent';
        elseif($rating >= 70)  $rLabel = 'Good';
        elseif($rating >= 55)  $rLabel = 'Average';
        elseif($rating >= 40)  $rLabel = 'Needs Improvement';
        else                   $rLabel = 'Poor';

        $teachers[] = [
            't'              => $t,
            'quiz_avg'       => $quizAvg,
            'quiz_count'     => $quizCount,
            'assign_avg'     => $assignAvg,
            'assign_count'   => $assignCount,
            'pass_rate'      => $passRate,
            'pass_total'     => $passTotal,
            'integrity'      => round($integrityScore),
            'avg_tabs'       => $avgTabs,
            'avg_fs'         => $avgFS,
            'viol_total'     => $violTotal,
            'viol_unresolved'=> $violUnresolved,
            'class_count'    => $classCount,
            'student_count'  => $studentCount,
            'rating'         => $rating,
            'rating_label'   => $rLabel,
        ];
    }

    // Sort by rating descending
    usort($teachers, fn($a,$b) => $b['rating'] - $a['rating']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Teacher Ratings</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <script src="../plugins/chart.umd.min.js"></script>
  <style>
    :root {
      --c-dark:<?php echo $T['dark'];?>;--c-mid:<?php echo $T['mid'];?>;--c-base:<?php echo $T['base'];?>;
      --c-light:<?php echo $T['light'];?>;--c-bg:<?php echo $T['bg'];?>;--c-border:<?php echo $T['border'];?>;
      --c-text:<?php echo $T['text'];?>;--c-shadow:<?php echo $T['shadow'];?>;
      --sl50:#f8fafc;--sl100:#f1f5f9;--sl200:#e2e8f0;--sl400:#94a3b8;--sl600:#475569;--sl800:#1e293b;
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Inter',sans-serif;background:var(--sl100);color:var(--sl800);}
    .cl-sidebar{background:linear-gradient(175deg,var(--c-dark) 0%,var(--c-mid) 100%) !important;}
    .cl-sidebar .sidebar-brand h2{color:#fff !important;}
    .cl-sidebar .sidebar-brand h2 span{color:var(--c-light) !important;}
    .cl-sidebar .sidebar-brand p{color:rgba(255,255,255,.45) !important;}
    .cl-sidebar .nav-section{color:rgba(255,255,255,.3) !important;font-size:10px !important;letter-spacing:1.2px !important;}
    .cl-sidebar .nav-item a{color:rgba(255,255,255,.7) !important;border-radius:10px !important;margin:2px 8px !important;}
    .cl-sidebar .nav-item a:hover{background:rgba(255,255,255,.1) !important;color:#fff !important;}
    .cl-sidebar .nav-item.active a{background:rgba(255,255,255,.15) !important;color:#fff !important;border-left:3px solid var(--c-light) !important;font-weight:600 !important;}
    .cl-sidebar .sidebar-footer{border-top:1px solid rgba(255,255,255,.08) !important;}
    .cl-sidebar .user-meta strong{color:#fff !important;}
    .cl-sidebar .user-meta span{color:rgba(255,255,255,.45) !important;}
    .cl-sidebar .btn-signout{background:rgba(255,255,255,.07) !important;color:rgba(255,255,255,.65) !important;border-radius:10px !important;}
    .cl-sidebar .btn-signout:hover{background:rgba(255,255,255,.14) !important;color:#fff !important;}
    .cl-topbar{border-bottom:1px solid var(--sl200) !important;background:#fff !important;}
    .topbar-title h1{font-size:18px !important;font-weight:700 !important;color:var(--sl800) !important;}
    .topbar-title p{font-size:12px !important;color:var(--sl400) !important;}
    .adm-content{padding:28px 32px;}
    @media(max-width:768px){.adm-content{padding:16px;}}
    /* Hero */
    .hero-banner{border-radius:20px;padding:26px 32px;margin-bottom:28px;background:linear-gradient(135deg,var(--c-dark) 0%,var(--c-mid) 55%,var(--c-base) 100%);box-shadow:0 8px 32px var(--c-shadow);display:flex;align-items:center;gap:20px;flex-wrap:wrap;position:relative;overflow:hidden;}
    .hero-banner::before{content:'';position:absolute;right:-60px;top:-60px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none;}
    .hero-icon{width:60px;height:60px;border-radius:16px;flex-shrink:0;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;}
    .hero-icon i{font-size:26px;color:#fff;}
    .hero-info{flex:1;}
    .hero-info h2{font-size:20px;font-weight:800;color:#fff;margin-bottom:4px;}
    .hero-info p{font-size:13px;color:rgba(255,255,255,.65);margin:0;}
    /* Rating cards */
    .ratings-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:20px;margin-bottom:32px;}
    .rating-card{background:#fff;border-radius:18px;border:1px solid var(--sl200);box-shadow:0 2px 8px rgba(0,0,0,.05);overflow:hidden;transition:transform .2s,box-shadow .2s;}
    .rating-card:hover{transform:translateY(-3px);box-shadow:0 8px 28px rgba(0,0,0,.1);}
    .rc-header{padding:18px 20px;background:linear-gradient(135deg,var(--c-dark),var(--c-mid));display:flex;align-items:center;gap:14px;}
    .rc-av{width:48px;height:48px;border-radius:12px;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:800;color:#fff;flex-shrink:0;}
    .rc-name{flex:1;}
    .rc-name strong{display:block;font-size:14px;font-weight:700;color:#fff;}
    .rc-name span{font-size:11px;color:rgba(255,255,255,.55);}
    .rc-rating-circle{width:54px;height:54px;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.25);flex-shrink:0;}
    .rc-rating-circle .rnum{font-size:18px;font-weight:800;color:#fff;line-height:1;}
    .rc-rating-circle .rpct{font-size:9px;color:rgba(255,255,255,.6);font-weight:600;}
    .rc-body{padding:16px 20px;}
    /* Rating badge */
    .rating-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:99px;font-size:12px;font-weight:700;margin-bottom:14px;}
    /* Metric rows */
    .metric-row{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
    .metric-row:last-child{margin-bottom:0;}
    .metric-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .metric-icon i{font-size:11px;color:#fff;}
    .metric-label{font-size:12px;font-weight:600;color:var(--sl600);min-width:130px;}
    .metric-bar-bg{flex:1;height:6px;background:var(--sl100);border-radius:99px;overflow:hidden;}
    .metric-bar-fill{height:100%;border-radius:99px;}
    .metric-val{font-size:12px;font-weight:700;min-width:42px;text-align:right;}
    /* Chips */
    .chip-row{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px;padding-top:12px;border-top:1px solid var(--sl100);}
    .chip{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:600;}
    /* Summary table */
    .sum-table{width:100%;border-collapse:collapse;}
    .sum-table th{padding:10px 16px;font-size:11px;font-weight:700;color:var(--sl400);text-transform:uppercase;letter-spacing:.6px;background:var(--sl50);border-bottom:1px solid var(--sl200);white-space:nowrap;text-align:left;}
    .sum-table td{padding:13px 16px;font-size:13px;border-bottom:1px solid var(--sl100);vertical-align:middle;}
    .sum-table tr:last-child td{border-bottom:none;}
    .sum-table tr:hover td{background:var(--c-bg);}
    .data-card{background:#fff;border-radius:16px;border:1px solid var(--sl200);box-shadow:0 1px 3px rgba(0,0,0,.05);overflow:hidden;margin-bottom:28px;}
    .data-card-hdr{padding:16px 22px;border-bottom:1px solid var(--sl100);display:flex;align-items:center;gap:10px;}
    .data-card-hdr h4{font-size:14px;font-weight:700;color:var(--sl800);margin:0;}
    .sec-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
    .sec-header h4{font-size:15px;font-weight:700;color:var(--sl800);display:flex;align-items:center;gap:9px;margin:0;}
    .sec-dot{width:9px;height:9px;border-radius:50%;background:var(--c-base);display:inline-block;box-shadow:0 0 0 3px var(--c-border);}
    .sec-count{background:var(--c-bg);color:var(--c-text);padding:4px 13px;border-radius:20px;font-size:12px;font-weight:700;border:1px solid var(--c-border);}
    .user-cell{display:flex;align-items:center;gap:11px;}
    .u-av{width:34px;height:34px;border-radius:9px;flex-shrink:0;background:linear-gradient(135deg,var(--c-base),var(--c-mid));display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px var(--c-shadow);}
    .u-av i{color:#fff;font-size:13px;}
    /* Empty */
    .empty-state{text-align:center;padding:64px 24px;background:#fff;border-radius:18px;border:1px solid var(--sl200);}
    .empty-state i{font-size:36px;color:var(--c-base);opacity:.4;display:block;margin-bottom:14px;}
    .empty-state p{font-size:14px;color:var(--sl400);}
  </style>
</head>
<body>
<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<aside class="cl-sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="logo-icon" style="background:linear-gradient(135deg,<?php echo $T['base'];?>,<?php echo $T['mid'];?>);box-shadow:0 4px 14px <?php echo $T['shadow'];?>;"><i class="fa <?php echo $T['icon'];?>"></i></div>
    <h2>Cen<span>Learn</span></h2>
    <p><?php echo htmlspecialchars($T['name']); ?> Department</p>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Management</div>
    <ul style="list-style:none;padding:0;margin:0;">
      <li class="nav-item"><a href="dashboard.php"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li class="nav-item"><a href="students.php"><i class="fa fa-users"></i> Students</a></li>
      <li class="nav-item active"><a href="teacher_ratings.php"><i class="fa fa-star"></i> Teacher Ratings</a></li>
    </ul>
  </nav>
  <div class="sidebar-footer">
    <div class="user-info">
      <div class="user-avatar" style="background:linear-gradient(135deg,<?php echo $T['base'];?>,<?php echo $T['mid'];?>);"><i class="fa fa-user"></i></div>
      <div class="user-meta">
        <strong><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></strong>
        <span><?php echo htmlspecialchars($dept ?: 'Admin'); ?> Department</span>
      </div>
    </div>
    <a href="../logout.php" class="btn-signout"><i class="fa fa-sign-out"></i> Sign Out</a>
  </div>
</aside>

<div class="cl-main">
  <header class="cl-topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
      <div class="topbar-title">
        <h1>Teacher Performance Ratings</h1>
        <p>Based on student scores, behavior, and violations</p>
      </div>
    </div>
    <div class="topbar-right">
      <span class="topbar-badge" style="background:var(--c-bg);color:var(--c-text);border:1px solid var(--c-border);">
        <i class="fa <?php echo $T['icon'];?>"></i> <?php echo htmlspecialchars($dept ?: 'Admin'); ?>
      </span>
    </div>
  </header>

  <div class="adm-content">

    <?php if(empty($adminCourses)): ?>
    <div class="empty-state"><i class="fa fa-exclamation-triangle"></i><p>No courses assigned. Contact Super Admin.</p></div>
    <?php elseif(empty($teachers)): ?>
    <div class="empty-state"><i class="fa fa-chalkboard-teacher"></i><p>No teachers found for your assigned courses.</p></div>
    <?php else: ?>

    <!-- Hero -->
    <div class="hero-banner">
      <div class="hero-icon"><i class="fa fa-star"></i></div>
      <div class="hero-info">
        <h2>Teacher Performance Ratings</h2>
        <p>Scores computed from student quiz averages (30%), assignment grades (20%), pass rates (25%), student integrity (15%), and violation penalties (10%).</p>
      </div>
    </div>

    <!-- Rating Cards -->
    <div class="sec-header">
      <h4><span class="sec-dot"></span> Teacher Ratings</h4>
      <span class="sec-count"><?php echo count($teachers); ?> teachers</span>
    </div>
    <div class="ratings-grid">
    <?php foreach($teachers as $rank => $tr):
        $t = $tr['t'];
        $initials = strtoupper(substr($t['first_name'],0,1).substr($t['last_name'],0,1));
        $r = $tr['rating'];
        $rColor  = $r>=85?'#10b981':($r>=70?'#2563eb':($r>=55?'#f59e0b':($r>=40?'#f97316':'#ef4444')));
        $rBg     = $r>=85?'#dcfce7':($r>=70?'#dbeafe':($r>=55?'#fef3c7':($r>=40?'#ffedd5':'#fee2e2')));
        $rTxt    = $r>=85?'#166534':($r>=70?'#1d4ed8':($r>=55?'#92400e':($r>=40?'#9a3412':'#991b1b')));
    ?>
    <div class="rating-card">
      <div class="rc-header">
        <div class="rc-av"><?php echo $initials; ?></div>
        <div class="rc-name">
          <strong><?php echo htmlspecialchars($t['first_name'].' '.$t['last_name']); ?></strong>
          <span><?php echo htmlspecialchars($t['user_code']); ?> &bull; Rank #<?php echo $rank+1; ?></span>
        </div>
        <div class="rc-rating-circle">
          <span class="rnum"><?php echo $r; ?></span>
          <span class="rpct">/ 100</span>
        </div>
      </div>
      <div class="rc-body">
        <span class="rating-badge" style="background:<?php echo $rBg;?>;color:<?php echo $rTxt;?>;">
          <i class="fa fa-<?php echo $r>=85?'star':($r>=70?'thumbs-up':($r>=55?'minus-circle':($r>=40?'exclamation-circle':'times-circle'))); ?>"></i>
          <?php echo $tr['rating_label']; ?>
        </span>

        <!-- Quiz Average -->
        <?php $qv = $tr['quiz_avg'] ?? 0; $qcolor = $qv>=75?'#10b981':($qv>=50?'#f59e0b':'#ef4444'); ?>
        <div class="metric-row">
          <div class="metric-icon" style="background:<?php echo $qcolor;?>;"><i class="fa fa-question-circle"></i></div>
          <span class="metric-label">Student Quiz Avg</span>
          <div class="metric-bar-bg"><div class="metric-bar-fill" style="width:<?php echo $qv;?>%;background:<?php echo $qcolor;?>;"></div></div>
          <span class="metric-val" style="color:<?php echo $qcolor;?>;"><?php echo $tr['quiz_avg']!==null?$tr['quiz_avg'].'%':'N/A'; ?></span>
        </div>

        <!-- Assignment Average -->
        <?php $av = $tr['assign_avg'] ?? 0; $acolor = $av>=75?'#10b981':($av>=50?'#f59e0b':'#ef4444'); ?>
        <div class="metric-row">
          <div class="metric-icon" style="background:<?php echo $acolor;?>;"><i class="fa fa-pencil"></i></div>
          <span class="metric-label">Assignment Avg</span>
          <div class="metric-bar-bg"><div class="metric-bar-fill" style="width:<?php echo $av;?>%;background:<?php echo $acolor;?>;"></div></div>
          <span class="metric-val" style="color:<?php echo $acolor;?>;"><?php echo $tr['assign_avg']!==null?$tr['assign_avg'].'%':'N/A'; ?></span>
        </div>

        <!-- Pass Rate -->
        <?php $pv = $tr['pass_rate'] ?? 0; $pcolor = $pv>=75?'#10b981':($pv>=50?'#f59e0b':'#ef4444'); ?>
        <div class="metric-row">
          <div class="metric-icon" style="background:<?php echo $pcolor;?>;"><i class="fa fa-graduation-cap"></i></div>
          <span class="metric-label">Student Pass Rate</span>
          <div class="metric-bar-bg"><div class="metric-bar-fill" style="width:<?php echo $pv;?>%;background:<?php echo $pcolor;?>;"></div></div>
          <span class="metric-val" style="color:<?php echo $pcolor;?>;"><?php echo $tr['pass_rate']!==null?$tr['pass_rate'].'%':'N/A'; ?></span>
        </div>

        <!-- Integrity Score -->
        <?php $iv=$tr['integrity']; $icolor=$iv>=80?'#10b981':($iv>=60?'#f59e0b':'#ef4444'); ?>
        <div class="metric-row">
          <div class="metric-icon" style="background:<?php echo $icolor;?>;"><i class="fa fa-shield"></i></div>
          <span class="metric-label">Student Integrity</span>
          <div class="metric-bar-bg"><div class="metric-bar-fill" style="width:<?php echo $iv;?>%;background:<?php echo $icolor;?>;"></div></div>
          <span class="metric-val" style="color:<?php echo $icolor;?>;"><?php echo $iv; ?>%</span>
        </div>

        <!-- Chips -->
        <div class="chip-row">
          <span class="chip" style="background:#f1f5f9;color:#475569;"><i class="fa fa-book"></i> <?php echo $tr['class_count']; ?> classes</span>
          <span class="chip" style="background:#f1f5f9;color:#475569;"><i class="fa fa-users"></i> <?php echo $tr['student_count']; ?> students</span>
          <?php if($tr['viol_unresolved'] > 0): ?>
          <span class="chip" style="background:#fef2f2;color:#b91c1c;"><i class="fa fa-exclamation-triangle"></i> <?php echo $tr['viol_unresolved']; ?> violation<?php echo $tr['viol_unresolved']!==1?'s':''; ?></span>
          <?php else: ?>
          <span class="chip" style="background:#f0fdf4;color:#166534;"><i class="fa fa-check"></i> No violations</span>
          <?php endif; ?>
          <span class="chip" style="background:#eff6ff;color:#1d4ed8;" title="Avg tab switches per quiz"><i class="fa fa-arrows-alt"></i> <?php echo $tr['avg_tabs']; ?> tabs/quiz</span>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- Summary Table -->
    <div class="sec-header">
      <h4><span class="sec-dot"></span> Comparison Table</h4>
      <span class="sec-count"><?php echo count($teachers); ?> teachers</span>
    </div>
    <div class="data-card">
      <div class="data-card-hdr">
        <div style="width:32px;height:32px;border-radius:9px;background:var(--c-bg);border:1px solid var(--c-border);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fa fa-table" style="color:var(--c-base);font-size:13px;"></i></div>
        <h4>Teacher Performance Summary</h4>
      </div>
      <div style="overflow-x:auto;">
        <table class="sum-table">
          <thead>
            <tr>
              <th>Rank</th><th>Teacher</th><th>Quiz Avg</th><th>Assign Avg</th>
              <th>Pass Rate</th><th>Integrity</th><th>Violations</th>
              <th>Classes</th><th>Students</th><th>Rating</th><th>Label</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($teachers as $rank => $tr):
            $t=$tr['t'];
            $r=$tr['rating'];
            $rColor=$r>=85?'#10b981':($r>=70?'#2563eb':($r>=55?'#f59e0b':($r>=40?'#f97316':'#ef4444')));
            $rBg=$r>=85?'#dcfce7':($r>=70?'#dbeafe':($r>=55?'#fef3c7':($r>=40?'#ffedd5':'#fee2e2')));
            $rTxt=$r>=85?'#166534':($r>=70?'#1d4ed8':($r>=55?'#92400e':($r>=40?'#9a3412':'#991b1b')));
            $initials=strtoupper(substr($t['first_name'],0,1).substr($t['last_name'],0,1));
          ?>
          <tr>
            <td style="font-weight:700;color:var(--sl400);font-size:13px;">#<?php echo $rank+1; ?></td>
            <td>
              <div class="user-cell">
                <div class="u-av"><i class="fa fa-user"></i></div>
                <div>
                  <span style="font-weight:600;color:var(--sl800);font-size:13px;display:block;"><?php echo htmlspecialchars($t['first_name'].' '.$t['last_name']); ?></span>
                  <span style="font-size:11px;color:var(--sl400);"><?php echo htmlspecialchars($t['user_code']); ?></span>
                </div>
              </div>
            </td>
            <td style="font-weight:700;color:<?php echo $tr['quiz_avg']!==null?($tr['quiz_avg']>=60?'#10b981':'#ef4444'):'#94a3b8';?>;"><?php echo $tr['quiz_avg']!==null?$tr['quiz_avg'].'%':'—'; ?></td>
            <td style="font-weight:700;color:<?php echo $tr['assign_avg']!==null?($tr['assign_avg']>=60?'#10b981':'#ef4444'):'#94a3b8';?>;"><?php echo $tr['assign_avg']!==null?$tr['assign_avg'].'%':'—'; ?></td>
            <td style="font-weight:700;color:<?php echo $tr['pass_rate']!==null?($tr['pass_rate']>=60?'#10b981':'#ef4444'):'#94a3b8';?>;"><?php echo $tr['pass_rate']!==null?$tr['pass_rate'].'%':'—'; ?></td>
            <td style="font-weight:700;color:<?php echo $tr['integrity']>=80?'#10b981':($tr['integrity']>=60?'#f59e0b':'#ef4444');?>;"><?php echo $tr['integrity']; ?>%</td>
            <td>
              <?php if($tr['viol_unresolved'] > 0): ?>
              <span style="background:#fef2f2;color:#b91c1c;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;"><?php echo $tr['viol_unresolved']; ?> unresolved</span>
              <?php else: ?>
              <span style="background:#f0fdf4;color:#166534;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;">None</span>
              <?php endif; ?>
            </td>
            <td style="font-weight:600;"><?php echo $tr['class_count']; ?></td>
            <td style="font-weight:600;"><?php echo $tr['student_count']; ?></td>
            <td>
              <span style="font-size:20px;font-weight:800;color:<?php echo $rColor;?>;"><?php echo $r; ?></span>
              <span style="font-size:11px;color:var(--sl400);">/100</span>
            </td>
            <td><span style="background:<?php echo $rBg;?>;color:<?php echo $rTxt;?>;padding:4px 12px;border-radius:99px;font-size:12px;font-weight:700;"><?php echo $tr['rating_label']; ?></span></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Scoring Guide -->
    <div class="data-card">
      <div class="data-card-hdr">
        <div style="width:32px;height:32px;border-radius:9px;background:var(--c-bg);border:1px solid var(--c-border);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fa fa-info-circle" style="color:var(--c-base);font-size:13px;"></i></div>
        <h4>Rating Calculation Guide</h4>
      </div>
      <div style="padding:20px 22px;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;">
        <?php $factors=[
          ['fa-question-circle','#8b5cf6','Student Quiz Avg','30%','Average score of all students across teacher\'s quizzes'],
          ['fa-pencil','#f59e0b','Assignment Avg','20%','Average graded assignment score of all students'],
          ['fa-graduation-cap','#10b981','Pass Rate','25%','% of students who passed (from published grades)'],
          ['fa-shield','#1792bb','Student Integrity','15%','100 minus penalty for avg tab switches & fullscreen exits per quiz'],
          ['fa-exclamation-triangle','#ef4444','Violation Penalty','−10 max','5 pts deducted per unresolved violation (capped at 20 pts)'],
        ];
        foreach($factors as $f): ?>
        <div style="background:var(--sl50);border:1px solid var(--sl200);border-radius:12px;padding:14px 16px;">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <div style="width:30px;height:30px;border-radius:8px;background:<?php echo $f[2];?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
              <i class="fa <?php echo $f[0];?>" style="color:#fff;font-size:12px;"></i>
            </div>
            <strong style="font-size:12px;color:var(--sl800);"><?php echo $f[2]; ?></strong>
            <span style="margin-left:auto;background:var(--c-bg);color:var(--c-text);padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;border:1px solid var(--c-border);"><?php echo $f[3]; ?></span>
          </div>
          <p style="font-size:11px;color:var(--sl400);line-height:1.5;margin:0;"><?php echo $f[4]; ?></p>
        </div>
        <?php endforeach; ?>
        <div style="background:var(--sl50);border:1px solid var(--sl200);border-radius:12px;padding:14px 16px;">
          <strong style="font-size:12px;color:var(--sl800);display:block;margin-bottom:8px;">Rating Levels</strong>
          <?php foreach([['85–100','Excellent','#10b981','#dcfce7','#166534'],['70–84','Good','#2563eb','#dbeafe','#1d4ed8'],['55–69','Average','#f59e0b','#fef3c7','#92400e'],['40–54','Needs Improvement','#f97316','#ffedd5','#9a3412'],['0–39','Poor','#ef4444','#fee2e2','#991b1b']] as $rl): ?>
          <div style="display:flex;align-items:center;gap:6px;margin-bottom:5px;">
            <span style="background:<?php echo $rl[3];?>;color:<?php echo $rl[4];?>;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;"><?php echo $rl[1]; ?></span>
            <span style="font-size:11px;color:var(--sl400);"><?php echo $rl[0]; ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <?php endif; ?>
  </div>
  <footer class="cl-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<?php include '../includes/scripts.php'; ?>
<script>
function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
</script>
</body>
</html>
