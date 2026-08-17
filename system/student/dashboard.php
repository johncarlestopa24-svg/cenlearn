<?php
include '../includes/session.php';
include '../includes/conn.php';
include '../shared/analytics_engine.php';

$uc = $conn->real_escape_string($user['user_code']);

// AJAX check for live sessions
if (($_GET['action'] ?? '') === 'get_live_sessions') {
    header('Content-Type: application/json');
    $liveSessions = $conn->query("
        SELECT ls.*, c.class_name, c.id AS class_id
        FROM live_sessions ls JOIN classes c ON ls.class_id=c.id JOIN class_members cm ON cm.class_id=c.id
        WHERE cm.user_code='$uc' AND ls.status='live' LIMIT 3
    ");
    $sessions = [];
    while($ls = $liveSessions->fetch_assoc()){
        $sessions[] = [
            'class_id' => $ls['class_id'],
            'class_name' => $ls['class_name'],
            'title' => $ls['title'] ?: 'Live Class'
        ];
    }
    echo json_encode($sessions);
    exit;
}

$student_analytics = cenlearn_student_recommendations($conn, $uc);
$topic_recs        = cenlearn_topic_recommendations($conn, $uc);
$uq = $conn->query("SELECT * FROM users WHERE user_code='$uc'");
if($uq->num_rows > 0) $user = array_merge($user, $uq->fetch_assoc());

// Check if background sync is requested via AJAX
if (($_GET['action'] ?? '') === 'sync_profile') {
    header('Content-Type: application/json');
    $synced = false;
    $ch = curl_init('https://web.bagocitycollege.com/BCCWeb/TPLoginAPI');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['txtUserName' => $uc, 'txtPassword' => '']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $raw = @curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200 && $raw) {
        $cleaned = iconv('UTF-8', 'UTF-8//IGNORE', mb_convert_encoding($raw, 'UTF-8', 'UTF-8'));
        $api_data = json_decode(trim($cleaned), true);
        if (is_array($api_data) && !empty($api_data['program_code'])) {
            $pc = $conn->real_escape_string($api_data['program_code'] ?? '');
            $pd = $conn->real_escape_string($api_data['program_description'] ?? '');
            $yl = intval($api_data['year_level'] ?? 0);
            $sec = $conn->real_escape_string($api_data['section'] ?? '');
            $fn = $conn->real_escape_string($api_data['first_name'] ?? '');
            $mn = $conn->real_escape_string($api_data['middle_name'] ?? '');
            $ln = $conn->real_escape_string($api_data['last_name'] ?? '');
            $now = date('Y-m-d H:i:s');
            $conn->query("UPDATE users SET program_code='$pc', program_description='$pd', year_level=$yl, section='$sec', first_name='$fn', middle_name='$mn', last_name='$ln', api_cached_at='$now' WHERE user_code='$uc'");
            $synced = true;
        }
    }
    echo json_encode(['success' => $synced]);
    exit;
}

$user['program_code']        = $user['program_code']        ?? '';
$user['program_description'] = $user['program_description'] ?? '';
$user['year_level']          = $user['year_level']          ?? '';
$user['section']             = $user['section']             ?? '';
$user['gender']              = $user['gender']              ?? '';
$user['cp_number']           = $user['cp_number']           ?? '';
$user['email_address']       = $user['email_address']       ?? '';
$user['middle_name']         = $user['middle_name']         ?? '';

$isGraduated = !empty($user['graduated_at']) || strtoupper($user['user_group'] ?? '') === 'ALUMNI';
$graduatedAt = $user['graduated_at'] ?? null;

$classCount = $conn->query("SELECT COUNT(*) AS c FROM class_members cm JOIN classes c ON cm.class_id=c.id WHERE cm.user_code='$uc' AND c.teacher_code!='$uc'")->fetch_assoc()['c'];

// Quiz & Assignment KPI stats
$totalQuizzesCount = (int)($conn->query("SELECT COUNT(*) AS c FROM quizzes q JOIN class_members cm ON cm.class_id=q.class_id WHERE cm.user_code='$uc' AND q.is_active=1")->fetch_assoc()['c'] ?? 0);
$completedQuizzesCount = (int)($conn->query("SELECT COUNT(DISTINCT quiz_id) AS c FROM quiz_submissions WHERE student_code='$uc'")->fetch_assoc()['c'] ?? 0);

$totalAssignCount = (int)($conn->query("SELECT COUNT(*) AS c FROM assignments a JOIN class_members cm ON cm.class_id=a.class_id WHERE cm.user_code='$uc'")->fetch_assoc()['c'] ?? 0);
$completedAssignCount = (int)($conn->query("SELECT COUNT(DISTINCT assignment_id) AS c FROM assignment_submissions WHERE student_code='$uc'")->fetch_assoc()['c'] ?? 0);

$pendingAssign = $conn->query("
    SELECT a.id AS assignment_id, a.title, a.due_date, a.points, a.instructions, c.class_name, c.id AS class_id
    FROM assignments a JOIN classes c ON a.class_id=c.id JOIN class_members cm ON cm.class_id=c.id
    WHERE cm.user_code='$uc'
      AND NOT EXISTS (SELECT 1 FROM assignment_submissions s WHERE s.assignment_id=a.id AND s.student_code='$uc')
    ORDER BY a.due_date IS NULL, a.due_date ASC LIMIT 10
");

$pendingQuiz = $conn->query("
    SELECT q.id, q.title, q.due_date, q.time_limit, (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id=q.id) AS q_count, c.class_name, c.id AS class_id
    FROM quizzes q JOIN classes c ON q.class_id=c.id JOIN class_members cm ON cm.class_id=c.id
    WHERE cm.user_code='$uc' AND q.is_active=1
      AND NOT EXISTS (SELECT 1 FROM quiz_submissions s WHERE s.quiz_id=q.id AND s.student_code='$uc')
    ORDER BY q.due_date IS NULL, q.due_date ASC LIMIT 10
");

$recentGrades = $conn->query("
    SELECT s.grade, s.submitted_at, a.title, a.points, c.class_name
    FROM assignment_submissions s JOIN assignments a ON s.assignment_id=a.id JOIN classes c ON a.class_id=c.id
    WHERE s.student_code='$uc' AND s.grade IS NOT NULL
    ORDER BY s.submitted_at DESC LIMIT 5
");

$publishedGrades = $conn->query("
    SELECT pg.*, c.class_name, c.subject, u.first_name AS teacher_first, u.last_name AS teacher_last
    FROM published_grades pg
    JOIN classes c ON pg.class_id=c.id
    LEFT JOIN users u ON c.teacher_code=u.user_code
    WHERE pg.student_code='$uc'
    ORDER BY pg.published_at DESC
");

$liveSessions = $conn->query("
    SELECT ls.*, c.class_name, c.id AS class_id
    FROM live_sessions ls JOIN classes c ON ls.class_id=c.id JOIN class_members cm ON cm.class_id=c.id
    WHERE cm.user_code='$uc' AND ls.status='live' LIMIT 3
");

// Initials for avatar
$initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
$fullName  = trim($user['first_name'].' '.($user['middle_name']?$user['middle_name'][0].'. ':'').$user['last_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Student Dashboard</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    *{box-sizing:border-box;}
    html,body{margin:0;padding:0;overflow-x:hidden;}
    body{font-family:'Inter',sans-serif;background:#f0f4f8;color:#1e293b;}

    /* ── Sidebar ── */
    .sd-sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:linear-gradient(180deg,#0c1a2e 0%,#0f2d4a 55%,#0f5f80 100%);display:flex;flex-direction:column;z-index:200;transition:transform .3s cubic-bezier(.4,0,.2,1);transform:translateX(-260px);}
    .sd-sidebar.open{transform:translateX(0);}
    @media(min-width:901px){.sd-sidebar{transform:translateX(0);}}
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

    /* ── Main ── */
    .sd-main{margin-left:0;min-height:100vh;display:flex;flex-direction:column;transition:margin 0s;}
    @media(min-width:901px){.sd-main{margin-left:260px;}}
    .sd-topbar{background:#fff;padding:0 28px;height:60px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:50;box-shadow:0 1px 4px rgba(0,0,0,.04);}
    .sd-topbar-title h3{font-size:16px;font-weight:700;color:#0f172a;margin:0;}
    .sd-topbar-title p{font-size:12px;color:#64748b;margin:0;}
    .btn-primary-sm{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:linear-gradient(135deg,#1792bb,#0f5f80);color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;text-decoration:none;transition:opacity .2s;}
    .btn-primary-sm:hover{opacity:.88;color:#fff;}

    /* ── Content ── */
    .sd-content{padding:24px 28px 40px;flex:1;}

    /* ── Profile Hero Card ── */
    .profile-hero{background:linear-gradient(135deg,#0f2d4a 0%,#1792bb 100%);border-radius:20px;padding:0;overflow:hidden;margin-bottom:24px;position:relative;min-height:140px;}
    .profile-hero-dots{position:absolute;inset:0;opacity:.06;background-image:radial-gradient(circle,#fff 1.5px,transparent 1.5px);background-size:24px 24px;pointer-events:none;}
    .profile-hero-inner{position:relative;z-index:1;padding:28px 32px;display:flex;align-items:center;gap:24px;flex-wrap:wrap;}
    .profile-av{width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,.15);border:3px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#fff;flex-shrink:0;box-shadow:0 4px 20px rgba(0,0,0,.2);}
    .profile-info{flex:1;min-width:0;}
    .profile-info h2{font-size:22px;font-weight:800;color:#fff;margin:0 0 4px;text-shadow:0 2px 8px rgba(0,0,0,.2);}
    .profile-info .uid{font-size:12px;color:rgba(255,255,255,.6);margin-bottom:10px;font-family:monospace;letter-spacing:1px;}
    .profile-pills{display:flex;flex-wrap:wrap;gap:8px;}
    .ppill{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.15);color:rgba(255,255,255,.92);padding:4px 12px;border-radius:99px;font-size:11px;font-weight:600;border:1px solid rgba(255,255,255,.2);backdrop-filter:blur(4px);}
    .ppill i{font-size:10px;opacity:.8;}
    .profile-stats{display:flex;gap:20px;flex-shrink:0;}
    .pstat{text-align:center;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:12px;padding:12px 20px;min-width:90px;}
    .pstat strong{display:block;font-size:24px;font-weight:800;color:#fff;line-height:1;}
    .pstat span{font-size:10px;color:rgba(255,255,255,.6);font-weight:600;text-transform:uppercase;letter-spacing:.5px;}

    /* ── Live banner ── */
    .live-banner{background:linear-gradient(135deg,#ef4444,#dc2626);border-radius:14px;padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:12px;min-height:54px;}
    .live-dot{width:9px;height:9px;border-radius:50%;background:#fff;animation:blink 1.4s infinite;flex-shrink:0;}
    @keyframes blink{0%,100%{opacity:1;}50%{opacity:.3;}}

    /* ── Grid ── */
    .sd-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
    .sd-card{background:#fff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.04);}
    .sd-card-hdr{padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;}
    .sd-card-hdr h4{font-size:13px;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:7px;}
    .sd-card-hdr a{font-size:11px;color:#1792bb;font-weight:600;text-decoration:none;}
    .sd-card-hdr a:hover{text-decoration:underline;}

    /* ── Todo items ── */
    .todo-row{display:flex;align-items:center;gap:12px;padding:11px 18px;border-bottom:1px solid #f8fafc;transition:background .15s;}
    .todo-row:last-child{border-bottom:none;}
    .todo-row:hover{background:#f8fafc;}
    .todo-ico{width:32px;height:32px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;}
    .todo-ico i{font-size:13px;color:#fff;}
    .todo-body{flex:1;min-width:0;}
    .todo-body strong{display:block;font-size:13px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .todo-body span{font-size:11px;color:#94a3b8;}
    .todo-due{font-size:11px;font-weight:700;white-space:nowrap;padding:3px 8px;border-radius:6px;}
    .due-soon{background:#fef2f2;color:#ef4444;}
    .due-ok{background:#f1f5f9;color:#64748b;}
    .empty-msg{text-align:center;padding:28px 16px;color:#94a3b8;font-size:13px;}
    .empty-msg i{display:block;font-size:24px;margin-bottom:8px;opacity:.4;}

    /* ── Grade row ── */
    .grade-row{display:flex;align-items:center;gap:12px;padding:11px 18px;border-bottom:1px solid #f8fafc;}
    .grade-row:last-child{border-bottom:none;}
    .grade-pct{font-size:13px;font-weight:800;white-space:nowrap;min-width:52px;text-align:right;}
    .grade-bar-wrap{flex:1;background:#f1f5f9;border-radius:99px;height:5px;overflow:hidden;}
    .grade-bar{height:100%;border-radius:99px;transition:width .4s;}

    /* ── AI Study Assistant ── */
    .ai-assistant-card {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 24px;
      margin-bottom: 24px;
      box-shadow: 0 4px 20px rgba(0,0,0,.03);
      position: relative;
      overflow: hidden;
    }
    .ai-assistant-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0; height: 4px;
      background: linear-gradient(90deg, #1792bb, #0f5f80);
    }
    .ai-hdr {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 18px;
      flex-wrap: wrap;
      gap: 12px;
    }
    .ai-title {
      font-size: 15px;
      font-weight: 800;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 9px;
    }
    .ai-title i {
      color: #f59e0b;
      font-size: 16px;
    }
    @keyframes pulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.1); }
    }
    .ai-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 12px;
      border-radius: 99px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px;
    }
    .ai-struggle-box {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 16px;
      margin-bottom: 20px;
    }
    .ai-struggle-title {
      font-size: 12px;
      font-weight: 700;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: .8px;
      margin-bottom: 6px;
    }
    .ai-struggle-val {
      font-size: 14px;
      font-weight: 800;
      color: #ef4444;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .ai-struggle-desc {
      font-size: 12px;
      color: #64748b;
      margin-top: 4px;
    }
    .ai-recs-title {
      font-size: 13px;
      font-weight: 700;
      color: #0f172a;
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .ai-rec-item {
      display: flex;
      gap: 14px;
      padding: 14px;
      border-radius: 10px;
      background: #f8fafc;
      border-left: 4px solid #cbd5e1;
      margin-bottom: 10px;
      transition: transform .2s, box-shadow .2s;
    }
    .ai-rec-item:last-child {
      margin-bottom: 0;
    }
    .ai-rec-item:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(0,0,0,.04);
    }
    .ai-rec-item.danger { border-left-color: #ef4444; }
    .ai-rec-item.warning { border-left-color: #f59e0b; }
    .ai-rec-item.info { border-left-color: #3b82f6; }
    .ai-rec-item.success { border-left-color: #10b981; }
    
    .ai-rec-ico {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .ai-rec-ico i { font-size: 13px; color: #fff; }
    .ai-rec-ico.danger { background: linear-gradient(135deg, #ef4444, #dc2626); }
    .ai-rec-ico.warning { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .ai-rec-ico.info { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
    .ai-rec-ico.success { background: linear-gradient(135deg, #10b981, #059669); }
    
    .ai-rec-body { flex: 1; }
    .ai-rec-body strong { display: block; font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 2px; }
    .ai-rec-body p { font-size: 12px; color: #475569; margin: 0; line-height: 1.5; }
 
    footer.sd-footer{text-align:center;padding:14px;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;background:#fff;}
 
    @media(max-width:960px){.sd-grid{grid-template-columns:1fr;}}
    @media(max-width:768px){
      .profile-hero-inner{padding:20px; flex-direction:column; text-align:center; align-items:center;}
      .profile-pills{justify-content:center;}
      .profile-stats{width:100%; justify-content:center; gap:10px;}
      .pstat{flex:1; min-width:70px; padding:10px 10px;}
      .sd-content{padding:16px 14px 32px;}
    }
    @media(max-width:600px){.hide-mobile{display:none !important;}}
  </style>
</head>
<body>

<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<aside class="sd-sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo"><i class="fa fa-graduation-cap"></i></div>
    <h2>Cen<span>Learn</span></h2>
    <p>Learning Management System</p>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Student Menu</div>
    <ul>
      <li class="active"><a href="dashboard.php"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li><a href="classes.php"><i class="fa fa-book"></i> My Classes</a></li>
      <li><a href="quizzes.php"><i class="fa fa-question-circle"></i> My Quizzes</a></li>
    </ul>
  </nav>
  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-av"><?php echo $initials; ?></div>
      <div class="sb-meta">
        <strong><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></strong>
        <span><?php echo htmlspecialchars($user['program_code'] ?: 'Student'); ?></span>
      </div>
    </div>
    <a href="../logout.php" class="sb-out"><i class="fa fa-sign-out"></i> Sign Out</a>
  </div>
</aside>

<div class="sd-main">
  <header class="sd-topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
      <div class="sd-topbar-title">
        <h3>Dashboard</h3>
        <p><?php echo date('l, F j, Y'); ?></p>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <a href="program.php" class="btn-primary-sm" style="background:#fff;color:#1792bb;border:1.5px solid #1792bb;position:relative;width:34px;height:34px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;" title="My Program">
        <i class="fa fa-university" style="font-size:14px;color:#1792bb;"></i>
      </a>
    </div>
  </header>

  <div class="sd-content">

    <?php if($isGraduated): ?>
    <div style="background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:14px;padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:12px;">
      <i class="fa fa-graduation-cap" style="font-size:22px;color:#fff;flex-shrink:0;"></i>
      <div>
        <div style="font-size:14px;font-weight:700;color:#fff;">Congratulations, Graduate!</div>
        <div style="font-size:12px;color:rgba(255,255,255,.85);">Graduated<?php echo $graduatedAt?' on '.date('F d, Y',strtotime($graduatedAt)):''; ?>. You can still view your class materials.</div>
      </div>
    </div>
    <?php endif; ?>

    <div id="liveBannerContainer">
    <?php if($liveSessions->num_rows > 0): ?>
    <?php while($ls = $liveSessions->fetch_assoc()): ?>
    <div class="live-banner">
      <div class="live-dot"></div>
      <div style="flex:1;">
        <div style="font-size:13px;font-weight:700;color:#fff;">🔴 Live Class Now</div>
        <div style="font-size:12px;color:rgba(255,255,255,.85);"><?php echo htmlspecialchars($ls['title']?:'Live Class'); ?> &mdash; <?php echo htmlspecialchars($ls['class_name']); ?></div>
      </div>
      <a href="../shared/live_class.php?id=<?php echo $ls['class_id']; ?>" style="background:rgba(255,255,255,.2);color:#fff;padding:7px 16px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;border:1px solid rgba(255,255,255,.3);">
        <i class="fa fa-sign-in"></i> Join Now
      </a>
    </div>
    <?php endwhile; ?>
    <?php endif; ?>
    </div>

    <!-- Profile Hero -->
    <?php
      $yrVal = intval($user['year_level'] ?? 0);
      $yrLabel = $yrVal == 1 ? '1st Year' : ($yrVal == 2 ? '2nd Year' : ($yrVal == 3 ? '3rd Year' : ($yrVal == 4 ? '4th Year' : ($yrVal > 0 ? $yrVal.'th Year' : ''))));
      $courseStanding = trim(($user['program_code'] ?? '').($yrLabel ? ' • '.$yrLabel : '').($user['section'] ? ' (Sec '.$user['section'].')' : ''));
    ?>
    <div class="profile-hero">
      <div class="profile-hero-dots"></div>
      <div class="profile-hero-inner">
        <div class="profile-av"><?php echo $initials; ?></div>
        <div class="profile-info">
          <h2><?php echo htmlspecialchars($fullName); ?></h2>
          <div class="uid"><?php echo htmlspecialchars($user['user_code']); ?></div>
          <div class="profile-pills">
            <?php if(!empty($courseStanding)): ?>
            <span class="ppill" style="background:rgba(56,189,248,0.25); border-color:rgba(56,189,248,0.4); color:#ffffff; font-weight:700;">
              <i class="fa fa-graduation-cap"></i> <?php echo htmlspecialchars($courseStanding); ?>
            </span>
            <?php endif; ?>
            <?php if($user['program_description']): ?>
            <span class="ppill" title="Program Description"><i class="fa fa-university"></i> <?php echo htmlspecialchars($user['program_description']); ?></span>
            <?php endif; ?>
            <?php if($user['gender']): ?>
            <span class="ppill"><i class="fa fa-user"></i> <?php echo htmlspecialchars($user['gender']); ?></span>
            <?php endif; ?>
            <span class="ppill"><i class="fa fa-circle" style="color:#4ade80;font-size:7px;"></i> <?php echo $isGraduated?'Graduate':'Enrolled'; ?></span>
          </div>
        </div>
        <div class="profile-stats">
          <div class="pstat">
            <strong><?php echo $classCount; ?></strong>
            <span>Classes</span>
          </div>
          <div class="pstat">
            <strong><?php echo $pendingAssign->num_rows; ?></strong>
            <span>Tasks</span>
          </div>
          <div class="pstat">
            <strong><?php echo $pendingQuiz->num_rows; ?></strong>
            <span>Quizzes</span>
          </div>
        </div>
      </div>
    </div>
 
    <!-- Recommendations Card -->
    <?php if ($student_analytics['status'] === 'active'): ?>
    <div class="ai-assistant-card">
      <div class="ai-hdr">
        <div class="ai-title">
          <i class="fa fa-lightbulb-o"></i> Recommendations
        </div>
        <span class="ai-badge" style="background: <?php echo $student_analytics['overall_bg']; ?>; color: <?php echo $student_analytics['overall_textColor']; ?>;">
          Overall Status: <?php echo htmlspecialchars($student_analytics['overall_label']); ?>
        </span>
      </div>
 
      <div class="ai-recs-title">
        <i class="fa fa-lightbulb-o" style="color: #f59e0b; font-size: 14px;"></i> Actionable Study Recommendations
      </div>
      <div class="ai-recs-list">
        <?php foreach ($student_analytics['recommendations'] as $rec): ?>
        <div class="ai-rec-item <?php echo $rec['type']; ?>">
          <div class="ai-rec-ico <?php echo $rec['type']; ?>">
            <i class="fa <?php echo $rec['icon']; ?>"></i>
          </div>
          <div class="ai-rec-body">
            <strong><?php echo htmlspecialchars($rec['title']); ?></strong>
            <p><?php echo $rec['desc']; ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Topic Weakness & ML Recommendations Card -->
    <?php if($topic_recs['has_data']): ?>
    <div class="ai-assistant-card" style="margin-bottom:24px;">
      <div class="ai-hdr">
        <div class="ai-title">
          <i class="fa fa-bar-chart" style="color:#8b5cf6;"></i> Topic Performance &amp; Recommendations
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <?php if($topic_recs['ml_active']): ?>
          <span class="ai-badge" style="background:#ede9fe;color:#5b21b6;">
            <i class="fa fa-magic" style="font-size:10px;"></i> ML Active
          </span>
          <?php endif; ?>
          <span class="ai-badge" style="background:#f1f5f9;color:#475569;">
            <?php echo $topic_recs['total_topics']; ?> topic<?php echo $topic_recs['total_topics']!==1?'s':''; ?> tracked
          </span>
        </div>
      </div>

      <?php if($topic_recs['weak_count'] > 0): ?>
      <!-- Weak topic bars with module links & standard references -->
      <div style="margin-bottom:16px;">
        <div class="ai-recs-title" style="margin-bottom:10px;">
          <i class="fa fa-exclamation-triangle" style="color:#ef4444;font-size:13px;"></i>
          Weak Topics (<?php echo $topic_recs['weak_count']; ?> need improvement) &bull; <span style="font-size:11px;color:#6366f1;font-weight:600;"><i class="fa fa-globe"></i> International Standards Tracked</span>
        </div>
        <?php foreach(array_slice($topic_recs['weak_topics'], 0, 5) as $wt_idx => $wt):
          $wpct  = $wt['score_pct'];
          $wclr  = $wpct < 40 ? '#ef4444' : '#f97316';
          $wbg   = $wpct < 40 ? '#fee2e2' : '#ffedd5';
          $wtxt  = $wpct < 40 ? '#991b1b' : '#9a3412';
          $badge = $wpct < 40 ? '🔴 Critical Gap' : '🟡 Needs Practice';
          $stdRef = $wt['standard_ref'] ?? null;
          $uid   = 'wt_'.$wt_idx;
        ?>
        <div style="margin-bottom:14px;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.02);">
          <!-- Topic header row -->
          <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;background:#fafafa;cursor:pointer;" onclick="toggleWtPanel('<?php echo $uid; ?>')">
            <div style="flex:1;min-width:0;">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
                <span style="font-size:12.5px;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($wt['topic']); ?></span>
                <span style="background:<?php echo $wbg;?>;color:<?php echo $wtxt;?>;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;"><?php echo $badge; ?></span>
                <?php if($stdRef): ?>
                <span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;" title="<?php echo htmlspecialchars($stdRef['bloom_desc']); ?>">
                  <i class="fa fa-graduation-cap"></i> <?php echo htmlspecialchars($stdRef['bloom_level']); ?>
                </span>
                <?php endif; ?>
                <span style="font-size:10.5px;color:#94a3b8;"><?php echo htmlspecialchars($wt['subject']); ?></span>
              </div>
              <div style="height:7px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                <div style="height:100%;width:<?php echo $wpct;?>%;background:<?php echo $wclr;?>;border-radius:99px;transition:width .5s;"></div>
              </div>
            </div>
            <span style="background:<?php echo $wbg;?>;color:<?php echo $wclr;?>;padding:4px 12px;border-radius:99px;font-size:13px;font-weight:800;flex-shrink:0;"><?php echo $wpct; ?>%</span>
            <i class="fa fa-chevron-down" id="<?php echo $uid;?>_arrow" style="color:#94a3b8;font-size:11px;transition:transform .2s;flex-shrink:0;"></i>
          </div>

          <!-- Expandable detail panel -->
          <div id="<?php echo $uid;?>_panel" style="display:none;border-top:1px solid #f1f5f9;">

            <!-- International Standard Reference Banner -->
            <?php if($stdRef): ?>
            <div style="padding:10px 14px;background:#f5f3ff;border-bottom:1px solid #ede9fe;">
              <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px;">
                <div style="font-size:10.5px;font-weight:800;color:#5b21b6;text-transform:uppercase;letter-spacing:.8px;display:flex;align-items:center;gap:5px;">
                  <i class="fa fa-globe"></i> International Standard Competency Benchmark
                </div>
                <span style="font-size:9.5px;background:#ddd6fe;color:#4c1d95;padding:1px 6px;border-radius:4px;font-weight:700;"><?php echo htmlspecialchars($stdRef['standard_code']); ?></span>
              </div>
              <p style="font-size:11.5px;color:#475569;margin:0 0 4px;line-height:1.4;"><?php echo htmlspecialchars($stdRef['standard_rec']); ?></p>
              <div style="font-size:10px;color:#6d28d9;font-weight:600;">
                <i class="fa fa-bullseye"></i> Target Standard: <strong><?php echo htmlspecialchars($stdRef['target_benchmark']); ?></strong>
              </div>
            </div>
            <?php endif; ?>

            <!-- Quiz context (Tracked Weak Quizzes) -->
            <?php if(!empty($wt['quiz_context'])): ?>
            <div style="padding:10px 14px;background:#fffbeb;border-bottom:1px solid #fef3c7;">
              <div style="font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px;">
                <i class="fa fa-question-circle" style="margin-right:4px;"></i>Tracked Quiz Performance on this Topic
              </div>
              <?php foreach($wt['quiz_context'] as $qc):
                $qpct = $qc['total'] > 0 ? round(($qc['earned'] / $qc['total']) * 100) : 0;
                $qclr = $qpct >= 75 ? '#10b981' : ($qpct >= 50 ? '#f59e0b' : '#ef4444');
                $gapPct = max(0, 100 - $qpct);
              ?>
              <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;padding:7px 10px;background:#fff;border-radius:8px;border:1px solid #fde68a;">
                <i class="fa fa-file-text-o" style="color:#f59e0b;font-size:13px;flex-shrink:0;"></i>
                <div style="flex:1;min-width:0;">
                  <span style="font-size:12px;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($qc['quiz_title']); ?></span>
                  <span style="font-size:10px;color:#94a3b8;margin-left:6px;"><?php echo htmlspecialchars($qc['class_name']); ?></span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
                  <span style="font-size:11px;font-weight:800;color:<?php echo $qclr;?>;"><?php echo $qc['earned']; ?>/<?php echo $qc['total']; ?> pts (<?php echo $qpct; ?>%)</span>
                  <span style="font-size:9.5px;background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:4px;font-weight:700;">-<?php echo $gapPct; ?>% Gap</span>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Study materials (Weak Module Tracker) -->
            <div style="padding:10px 14px;">
              <div style="font-size:10px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:.8px;margin-bottom:7px;">
                <i class="fa fa-book" style="margin-right:4px;"></i>Weak Module Study Materials
              </div>
              <?php if(!empty($wt['modules'])): ?>
                <?php foreach($wt['modules'] as $mod):
                  $ext = strtolower(pathinfo($mod['original_name'], PATHINFO_EXTENSION));
                  $modIco = in_array($ext,['pdf']) ? 'fa-file-pdf-o' : (in_array($ext,['doc','docx']) ? 'fa-file-word-o' : (in_array($ext,['ppt','pptx']) ? 'fa-file-powerpoint-o' : 'fa-file-o'));
                  $modClr = in_array($ext,['pdf']) ? '#ef4444' : (in_array($ext,['doc','docx']) ? '#1d4ed8' : (in_array($ext,['ppt','pptx']) ? '#ea580c' : '#64748b'));
                ?>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;padding:8px 10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
                  <i class="fa <?php echo $modIco;?>" style="color:<?php echo $modClr;?>;font-size:16px;flex-shrink:0;"></i>
                  <div style="flex:1;min-width:0;">
                    <div style="font-size:12px;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($mod['title']); ?></div>
                    <div style="font-size:10px;color:#64748b;"><?php echo htmlspecialchars($mod['original_name']); ?> &bull; <?php echo htmlspecialchars($mod['class_name']); ?></div>
                  </div>
                  <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
                    <a href="../shared/module_view.php?id=<?php echo $mod['id']; ?>" target="_blank"
                       style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;background:linear-gradient(135deg,#1792bb,#0f5f80);color:#fff;border-radius:7px;font-size:11px;font-weight:700;text-decoration:none;transition:opacity .2s;"
                       onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                      <i class="fa fa-eye"></i> View
                    </a>
                    <a href="../shared/module_download.php?id=<?php echo $mod['id']; ?>" target="_blank"
                       style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;background:rgba(23,146,187,.12);color:#0f5f80;border-radius:7px;font-size:11px;font-weight:700;text-decoration:none;transition:background .2s;"
                       onmouseover="this.style.background='rgba(23,146,187,.22)'" onmouseout="this.style.background='rgba(23,146,187,.12)'">
                      <i class="fa fa-download"></i> Download
                    </a>
                  </div>
                </div>
                <?php endforeach; ?>
              <?php else: ?>
              <div style="display:flex;align-items:center;gap:8px;padding:10px;background:#f8fafc;border-radius:8px;border:1px dashed #cbd5e1;">
                <i class="fa fa-info-circle" style="color:#94a3b8;font-size:13px;"></i>
                <span style="font-size:12px;color:#64748b;">No study materials tagged for this topic yet. Ask your teacher to upload materials tagged <strong>"<?php echo htmlspecialchars($wt['topic']); ?>"</strong>.</span>
              </div>
              <?php endif; ?>
            </div>
          </div><!-- /panel -->
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if($topic_recs['strong_count'] > 0): ?>
      <!-- Strong topics -->
      <div style="margin-bottom:16px;">
        <div class="ai-recs-title" style="margin-bottom:10px;">
          <i class="fa fa-check-circle" style="color:#10b981;font-size:13px;"></i>
          Strong Topics (<?php echo $topic_recs['strong_count']; ?> mastered) &bull; <span style="font-size:11px;color:#10b981;font-weight:600;">Bloom L5-L6 Competency Met</span>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;">
          <?php foreach(array_slice($topic_recs['strong_topics'], 0, 8) as $st): ?>
          <span style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:4px 12px;border-radius:99px;font-size:12px;font-weight:600;">
            <i class="fa fa-star" style="font-size:10px;color:#10b981;"></i>
            <?php echo htmlspecialchars($st['topic']); ?> &mdash; <?php echo $st['score_pct']; ?>%
          </span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ML Recommendations with Standard Citations -->
      <div class="ai-recs-title" style="margin-bottom:10px;">
        <i class="fa fa-lightbulb-o" style="color:#f59e0b;font-size:14px;"></i>
        <?php echo $topic_recs['ml_active'] ? 'ML-Powered' : 'Pedagogical'; ?> Standard Study Suggestions
      </div>
      <div>
        <?php foreach($topic_recs['recommendations'] as $rec):
          $rStd = $rec['standard_ref'] ?? null;
        ?>
        <div class="ai-rec-item <?php echo $rec['type']; ?>" style="margin-bottom:10px;">
          <div class="ai-rec-ico <?php echo $rec['type']; ?>">
            <i class="fa <?php echo $rec['icon']; ?>"></i>
          </div>
          <div class="ai-rec-body">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:3px;">
              <strong><?php echo $rec['title']; ?></strong>
              <?php if($rStd): ?>
              <span style="font-size:9.5px;background:#f3e8ff;color:#6b21a8;padding:1px 6px;border-radius:4px;font-weight:700;">
                <i class="fa fa-globe"></i> <?php echo htmlspecialchars($rStd['standard_code']); ?>
              </span>
              <?php endif; ?>
            </div>
            <p style="margin-bottom:6px;"><?php echo $rec['desc']; ?></p>
            <?php if($rStd && !empty($rStd['remedial_steps'])): ?>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">
              <?php foreach($rStd['remedial_steps'] as $sKey => $sVal): ?>
              <span style="font-size:10px;background:#fff;border:1px solid #e2e8f0;padding:2px 7px;border-radius:5px;color:#334155;">
                <strong><?php echo htmlspecialchars($sVal['phase']); ?>:</strong> <?php echo htmlspecialchars($sVal['standard']); ?>
              </span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php if($rec['score_pct'] !== null): ?>
          <div style="font-size:13px;font-weight:800;color:<?php echo $rec['score_pct']<40?'#ef4444':'#f97316';?>;flex-shrink:0;white-space:nowrap;"><?php echo $rec['score_pct']; ?>%</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ── Suggested International Standard Study Plan card ── -->
    <?php
    $planItems = array_filter($topic_recs['recommendations'], fn($r) => !empty($r['modules']) || $r['score_pct'] !== null);
    $criticalRecs = array_filter($planItems, fn($r) => ($r['priority'] ?? '') === 'High');
    $reviewRecs   = array_filter($planItems, fn($r) => ($r['priority'] ?? '') === 'Medium');
    if(!empty($planItems)):
    ?>
    <div class="ai-assistant-card" style="margin-bottom:24px;border-top:4px solid #8b5cf6;">
      <div class="ai-hdr" style="margin-bottom:14px;">
        <div class="ai-title">
          <i class="fa fa-map" style="color:#8b5cf6;"></i> International Standard Remediation Study Plan
        </div>
        <span class="ai-badge" style="background:#ede9fe;color:#5b21b6;">
          <i class="fa fa-graduation-cap"></i> Bloom's Taxonomy &amp; ACM/IEEE CC2020 Aligned
        </span>
      </div>

      <?php if(!empty($criticalRecs)): ?>
      <div style="margin-bottom:16px;">
        <div style="font-size:11px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#ef4444;flex-shrink:0;"></span>
          🔴 Critical Priority (Bloom L1-L2 Recall Deficit) &mdash; Remediate First
        </div>
        <?php foreach($criticalRecs as $ci => $rec): 
          $rStd = $rec['standard_ref'] ?? null;
        ?>
        <div style="display:flex;align-items:flex-start;gap:12px;padding:14px;background:#fff5f5;border:1px solid #fecaca;border-radius:12px;margin-bottom:10px;">
          <div style="width:30px;height:30px;background:linear-gradient(135deg,#ef4444,#dc2626);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 2px 6px rgba(239,68,68,.3);">
            <span style="color:#fff;font-size:12px;font-weight:800;"><?php echo $ci+1; ?></span>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
              <span style="font-size:13.5px;font-weight:700;color:#0f172a;"><?php echo $rec['topic'] ? htmlspecialchars($rec['topic']) : $rec['title']; ?></span>
              <span style="font-size:11px;font-weight:800;color:#ef4444;background:#fee2e2;padding:1px 8px;border-radius:99px;"><?php echo $rec['score_pct']; ?>% Mastery</span>
              <?php if($rStd): ?>
              <span style="font-size:10px;font-weight:700;color:#6d28d9;background:#ede9fe;padding:1px 6px;border-radius:4px;"><?php echo htmlspecialchars($rStd['standard_code']); ?></span>
              <?php endif; ?>
            </div>

            <!-- 3-Phase Roadmap -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:8px;margin-top:8px;margin-bottom:8px;">
              <div style="background:#fff;border:1px solid #fecaca;border-radius:8px;padding:8px 10px;">
                <div style="font-size:10px;font-weight:800;color:#991b1b;text-transform:uppercase;"><i class="fa fa-book"></i> Phase 1: Module Study</div>
                <div style="font-size:11px;color:#475569;margin-top:2px;">Re-read core principles &amp; definitions in module.</div>
              </div>
              <div style="background:#fff;border:1px solid #fecaca;border-radius:8px;padding:8px 10px;">
                <div style="font-size:10px;font-weight:800;color:#b45309;text-transform:uppercase;"><i class="fa fa-check-square-o"></i> Phase 2: Formative Retake</div>
                <div style="font-size:11px;color:#475569;margin-top:2px;">Analyze missed quiz items and solve practice test.</div>
              </div>
              <div style="background:#fff;border:1px solid #fecaca;border-radius:8px;padding:8px 10px;">
                <div style="font-size:10px;font-weight:800;color:#15803d;text-transform:uppercase;"><i class="fa fa-bullseye"></i> Phase 3: Benchmark Target</div>
                <div style="font-size:11px;color:#475569;margin-top:2px;">Target &ge; 75% score on next assessment.</div>
              </div>
            </div>

            <!-- Weak Module Document Links -->
            <?php if(!empty($rec['modules'])): ?>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;align-items:center;">
              <span style="font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;">Tagged Material:</span>
              <?php foreach(array_slice($rec['modules'],0,3) as $m): ?>
              <a href="../shared/module_view.php?id=<?php echo $m['id']; ?>" target="_blank"
                 style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none;"
                 onmouseover="this.style.background='#fecaca'" onmouseout="this.style.background='#fee2e2'">
                <i class="fa fa-file-text-o" style="font-size:10px;"></i> <?php echo htmlspecialchars($m['title']); ?>
              </a>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if(!empty($reviewRecs)): ?>
      <div>
        <div style="font-size:11px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#f59e0b;flex-shrink:0;"></span>
          🟡 Needs Practice (Bloom L2-L3 Application Gap)
        </div>
        <?php foreach($reviewRecs as $ri => $rec): 
          $rStd = $rec['standard_ref'] ?? null;
        ?>
        <div style="display:flex;align-items:flex-start;gap:12px;padding:14px;background:#fffbeb;border:1px solid #fde68a;border-radius:12px;margin-bottom:10px;">
          <div style="width:30px;height:30px;background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 2px 6px rgba(245,158,11,.3);">
            <span style="color:#fff;font-size:12px;font-weight:800;"><?php echo $ri+1; ?></span>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
              <span style="font-size:13.5px;font-weight:700;color:#0f172a;"><?php echo $rec['topic'] ? htmlspecialchars($rec['topic']) : $rec['title']; ?></span>
              <span style="font-size:11px;font-weight:800;color:#d97706;background:#fef3c7;padding:1px 8px;border-radius:99px;"><?php echo $rec['score_pct']; ?>% Mastery</span>
              <?php if($rStd): ?>
              <span style="font-size:10px;font-weight:700;color:#6d28d9;background:#ede9fe;padding:1px 6px;border-radius:4px;"><?php echo htmlspecialchars($rStd['standard_code']); ?></span>
              <?php endif; ?>
            </div>

            <!-- 3-Phase Roadmap -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:8px;margin-top:8px;margin-bottom:8px;">
              <div style="background:#fff;border:1px solid #fde68a;border-radius:8px;padding:8px 10px;">
                <div style="font-size:10px;font-weight:800;color:#92400e;text-transform:uppercase;"><i class="fa fa-book"></i> Phase 1: Example Tracing</div>
                <div style="font-size:11px;color:#475569;margin-top:2px;">Work through step-by-step module examples.</div>
              </div>
              <div style="background:#fff;border:1px solid #fde68a;border-radius:8px;padding:8px 10px;">
                <div style="font-size:10px;font-weight:800;color:#b45309;text-transform:uppercase;"><i class="fa fa-pencil"></i> Phase 2: Problem Solving</div>
                <div style="font-size:11px;color:#475569;margin-top:2px;">Practice applied problem sets &amp; retake quizzes.</div>
              </div>
              <div style="background:#fff;border:1px solid #fde68a;border-radius:8px;padding:8px 10px;">
                <div style="font-size:10px;font-weight:800;color:#15803d;text-transform:uppercase;"><i class="fa fa-bullseye"></i> Phase 3: Benchmark Target</div>
                <div style="font-size:11px;color:#475569;margin-top:2px;">Achieve &ge; 75% on upcoming tests.</div>
              </div>
            </div>

            <!-- Weak Module Document Links -->
            <?php if(!empty($rec['modules'])): ?>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;align-items:center;">
              <span style="font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;">Tagged Material:</span>
              <?php foreach(array_slice($rec['modules'],0,3) as $m): ?>
              <a href="../shared/module_view.php?id=<?php echo $m['id']; ?>" target="_blank"
                 style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none;"
                 onmouseover="this.style.background='#fde68a'" onmouseout="this.style.background='#fef3c7'">
                <i class="fa fa-file-text-o" style="font-size:10px;"></i> <?php echo htmlspecialchars($m['title']); ?>
              </a>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>




    <!-- Quiz & Assignment Dashboard Overview Hub -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));gap:14px;margin-bottom:24px;">
      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 10px rgba(0,0,0,.02);">
        <div>
          <div style="font-size:11px;font-weight:700;color:#8b5cf6;text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px;"><i class="fa fa-question-circle"></i> Open Quizzes</div>
          <div style="font-size:24px;font-weight:800;color:#0f172a;"><?php echo $pendingQuiz->num_rows; ?> <span style="font-size:13px;color:#94a3b8;font-weight:500;">/ <?php echo $totalQuizzesCount; ?> Available</span></div>
        </div>
        <a href="quizzes.php" style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#8b5cf6,#6d28d9);display:flex;align-items:center;justify-content:center;color:#fff;text-decoration:none;box-shadow:0 3px 10px rgba(139,92,246,.3);" title="Go to My Quizzes">
          <i class="fa fa-play" style="font-size:12px;"></i>
        </a>
      </div>

      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 10px rgba(0,0,0,.02);">
        <div>
          <div style="font-size:11px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px;"><i class="fa fa-pencil-square-o"></i> Pending Tasks</div>
          <div style="font-size:24px;font-weight:800;color:#0f172a;"><?php echo $pendingAssign->num_rows; ?> <span style="font-size:13px;color:#94a3b8;font-weight:500;">/ <?php echo $totalAssignCount; ?> Total</span></div>
        </div>
        <a href="classes.php" style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;color:#fff;text-decoration:none;box-shadow:0 3px 10px rgba(245,158,11,.3);" title="View Assignments">
          <i class="fa fa-pencil" style="font-size:12px;"></i>
        </a>
      </div>

      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 10px rgba(0,0,0,.02);">
        <div>
          <div style="font-size:11px;font-weight:700;color:#10b981;text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px;"><i class="fa fa-check-circle"></i> Completed Quizzes</div>
          <div style="font-size:24px;font-weight:800;color:#166534;"><?php echo $completedQuizzesCount; ?></div>
        </div>
        <div style="width:38px;height:38px;border-radius:10px;background:#dcfce7;display:flex;align-items:center;justify-content:center;color:#166534;">
          <i class="fa fa-trophy" style="font-size:15px;"></i>
        </div>
      </div>

      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 10px rgba(0,0,0,.02);">
        <div>
          <div style="font-size:11px;font-weight:700;color:#0284c7;text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px;"><i class="fa fa-folder-open"></i> Submitted Tasks</div>
          <div style="font-size:24px;font-weight:800;color:#0369a1;"><?php echo $completedAssignCount; ?></div>
        </div>
        <div style="width:38px;height:38px;border-radius:10px;background:#e0f2fe;display:flex;align-items:center;justify-content:center;color:#0284c7;">
          <i class="fa fa-file-text-o" style="font-size:14px;"></i>
        </div>
      </div>
    </div>

    <!-- Main grid -->
    <div class="sd-grid">

      <!-- Open Quizzes Card -->
      <div class="sd-card">
        <div class="sd-card-hdr">
          <h4><i class="fa fa-question-circle" style="color:#8b5cf6;"></i> Open Quizzes</h4>
          <a href="quizzes.php" style="color:#8b5cf6;font-weight:700;text-decoration:none;font-size:12px;">View All Quizzes &rarr;</a>
        </div>
        <?php if($pendingQuiz->num_rows === 0): ?>
        <div class="empty-msg"><i class="fa fa-check-circle"></i>No open quizzes right now!</div>
        <?php else: ?>
        <?php while($q = $pendingQuiz->fetch_assoc()):
          $soon = $q['due_date'] && strtotime($q['due_date']) < strtotime('+2 days');
        ?>
        <div class="todo-row" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-bottom:1px solid #f1f5f9;">
          <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0;">
            <div class="todo-ico" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);"><i class="fa fa-question"></i></div>
            <div class="todo-body">
              <strong style="color:#0f172a;font-size:13px;"><?php echo htmlspecialchars($q['title']); ?></strong>
              <span style="font-size:11.5px;color:#64748b;"><?php echo htmlspecialchars($q['class_name']); ?><?php echo !empty($q['q_count'])?' &bull; '.$q['q_count'].' questions':''; ?><?php echo $q['time_limit']?' &bull; '.$q['time_limit'].' min':''; ?></span>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
            <?php if($q['due_date']): ?>
            <span class="todo-due <?php echo $soon?'due-soon':'due-ok'; ?>"><?php echo date('M d',strtotime($q['due_date'])); ?></span>
            <?php endif; ?>
            <a href="quizzes.php?take=<?php echo $q['id']; ?>" class="btn-take-quiz-sm" style="padding:6px 12px;background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;border-radius:8px;font-size:11px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:5px;box-shadow:0 2px 8px rgba(139,92,246,.3);">
              <i class="fa fa-pencil"></i> Take Quiz
            </a>
          </div>
        </div>
        <?php endwhile; ?>
        <?php endif; ?>
      </div>

      <!-- Pending Assignments Card -->
      <div class="sd-card">
        <div class="sd-card-hdr">
          <h4><i class="fa fa-pencil-square-o" style="color:#f59e0b;"></i> Pending Assignments</h4>
          <a href="classes.php" style="color:#f59e0b;font-weight:700;text-decoration:none;font-size:12px;">View All Classes &rarr;</a>
        </div>
        <?php if($pendingAssign->num_rows === 0): ?>
        <div class="empty-msg"><i class="fa fa-check-circle"></i>All assignments completed!</div>
        <?php else: ?>
        <?php while($a = $pendingAssign->fetch_assoc()):
          $soon = $a['due_date'] && strtotime($a['due_date']) < strtotime('+2 days');
        ?>
        <div class="todo-row" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-bottom:1px solid #f1f5f9;">
          <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0;">
            <div class="todo-ico" style="background:linear-gradient(135deg,#f59e0b,#d97706);"><i class="fa fa-pencil"></i></div>
            <div class="todo-body">
              <strong style="color:#0f172a;font-size:13px;"><?php echo htmlspecialchars($a['title']); ?></strong>
              <span style="font-size:11.5px;color:#64748b;"><?php echo htmlspecialchars($a['class_name']); ?><?php echo !empty($a['points'])?' &bull; '.$a['points'].' pts':''; ?></span>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
            <?php if($a['due_date']): ?>
            <span class="todo-due <?php echo $soon?'due-soon':'due-ok'; ?>"><?php echo date('M d',$soon?strtotime($a['due_date']):strtotime($a['due_date'])); ?></span>
            <?php endif; ?>
            <a href="../shared/class_view.php?id=<?php echo $a['class_id']; ?>&tab=classwork" style="padding:6px 12px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:8px;font-size:11px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
              <i class="fa fa-upload"></i> Submit
            </a>
          </div>
        </div>
        <?php endwhile; ?>
        <?php endif; ?>
      </div>

      <!-- Recent Grades -->
      <div class="sd-card" style="grid-column:1/-1;">
        <div class="sd-card-hdr">
          <h4><i class="fa fa-bar-chart" style="color:#10b981;"></i> Recent Grades</h4>
        </div>
        <?php if($recentGrades->num_rows === 0): ?>
        <div class="empty-msg"><i class="fa fa-inbox"></i>No grades yet.</div>
        <?php else: ?>
        <?php while($g = $recentGrades->fetch_assoc()):
          $pct = $g['points']>0 ? round(($g['grade']/$g['points'])*100) : 0;
          $clr = $pct>=75?'#10b981':($pct>=50?'#f59e0b':'#ef4444');
        ?>
        <div class="grade-row">
          <div class="todo-ico" style="background:linear-gradient(135deg,#10b981,#059669);flex-shrink:0;"><i class="fa fa-check" style="font-size:13px;color:#fff;"></i></div>
          <div class="todo-body">
            <strong><?php echo htmlspecialchars($g['title']); ?></strong>
            <span><?php echo htmlspecialchars($g['class_name']); ?></span>
          </div>
          <div class="grade-bar-wrap">
            <div class="grade-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $clr; ?>;"></div>
          </div>
          <div class="grade-pct" style="color:<?php echo $clr; ?>;"><?php echo $g['grade'].'/'.$g['points']; ?></div>
        </div>
        <?php endwhile; ?>
        <?php endif; ?>
      </div>

      <!-- Published Term Grades Card -->
      <div class="sd-card" style="grid-column:1/-1; margin-top: 10px;">
        <div class="sd-card-hdr" style="background: linear-gradient(135deg,#0c1a2e,#1792bb); color: #fff; padding: 14px 18px;">
          <h4 style="color:#fff; margin:0;"><i class="fa fa-graduation-cap" style="color:#fff;"></i> Officially Released Term Grades</h4>
        </div>
        <div style="padding: 16px;">
          <?php if($publishedGrades->num_rows === 0): ?>
          <div class="empty-msg" style="text-align:center; padding: 32px; color: #94a3b8; font-style:italic;"><i class="fa fa-check-circle-o fa-2x" style="display:block; margin-bottom:8px; opacity:0.6;"></i>No term grades released yet.</div>
          <?php else: ?>
          <div class="table-responsive" style="margin: 0; overflow-x: auto;">
            <table class="table table-hover" style="font-size:13px; margin:0; border-collapse: collapse; width:100%;">
              <thead>
                <tr style="color:#64748b; font-weight:700; text-transform:uppercase; font-size:11px; border-bottom: 2px solid #e2e8f0; text-align: left;">
                  <th style="padding: 10px 8px;">Class / Subject</th>
                  <th style="padding: 10px 8px;">Term</th>
                  <th style="padding: 10px 8px;">Grade %</th>
                  <th style="padding: 10px 8px;">Transmuted</th>
                  <th style="padding: 10px 8px;">Status</th>
                  <th style="padding: 10px 8px;">Date Released</th>
                </tr>
              </thead>
              <tbody>
                <?php while($pg = $publishedGrades->fetch_assoc()):
                  $termBadge = $pg['term'] === 'midterm' ? 'background:#d1fae5;color:#065f46;' : 'background:#e0e7ff;color:#3730a3;';
                  $statusBadge = $pg['remarks'] === 'Passed' ? 'background:#dcfce7;color:#166534;' : 'background:#fee2e2;color:#991b1b;';
                ?>
                <tr style="border-bottom: 1px solid #e2e8f0;">
                  <td style="padding: 12px 8px;">
                    <strong style="color:#0f172a; display:block;"><?php echo htmlspecialchars($pg['class_name']); ?></strong>
                    <span style="font-size:11px; color:#64748b; font-weight: 500;"><?php echo htmlspecialchars($pg['subject']?:'No Subject'); ?> &bull; Prof. <?php echo htmlspecialchars($pg['teacher_first'].' '.$pg['teacher_last']); ?></span>
                  </td>
                  <td style="padding: 12px 8px; vertical-align:middle;">
                    <span style="padding:4px 10px; border-radius:6px; font-weight:700; font-size:11px; text-transform:uppercase; <?php echo $termBadge; ?>"><?php echo htmlspecialchars($pg['term']); ?></span>
                  </td>
                  <td style="padding: 12px 8px; vertical-align:middle; font-weight:800; font-size:14px; color:#0f172a;"><?php echo number_format($pg['grade'], 2); ?>%</td>
                  <td style="padding: 12px 8px; vertical-align:middle; font-weight:800; color:#5b21b6;"><?php echo htmlspecialchars($pg['transmuted']); ?></td>
                  <td style="padding: 12px 8px; vertical-align:middle;">
                    <span style="padding:3px 10px; border-radius:6px; font-weight:700; font-size:11px; <?php echo $statusBadge; ?>"><?php echo htmlspecialchars($pg['remarks']); ?></span>
                  </td>
                  <td style="padding: 12px 8px; vertical-align:middle; color:#64748b; font-size:12px;"><?php echo date('M d, Y g:i A', strtotime($pg['published_at'])); ?></td>
                </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
  <footer class="sd-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script src="../bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
function toggleWtPanel(uid){
  var panel = document.getElementById(uid+'_panel');
  var arrow = document.getElementById(uid+'_arrow');
  if(!panel) return;
  var open = panel.style.display !== 'none';
  panel.style.display = open ? 'none' : 'block';
  if(arrow) arrow.style.transform = open ? '' : 'rotate(180deg)';
}

// Real-time Live Class Banner Check
setInterval(function(){
  $.getJSON('dashboard.php', {action: 'get_live_sessions'}, function(sessions){
    var html = '';
    if(sessions && sessions.length > 0){
      sessions.forEach(function(ls){
        html += '<div class="live-banner">'
             +  '  <div class="live-dot"></div>'
             +  '  <div style="flex:1;">'
             +  '    <div style="font-size:13px;font-weight:700;color:#fff;">🔴 Live Class Now</div>'
             +  '    <div style="font-size:12px;color:rgba(255,255,255,.85);">' + ls.title + ' &mdash; ' + ls.class_name + '</div>'
             +  '  </div>'
             +  '  <a href="../shared/live_class.php?id=' + ls.class_id + '" style="background:rgba(255,255,255,.2);color:#fff;padding:7px 16px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;border:1px solid rgba(255,255,255,.3);">'
             +  '    <i class="fa fa-sign-in"></i> Join Now'
             +  '  </a>'
             +  '</div>';
      });
    }
    $('#liveBannerContainer').html(html);
  });
}, 10000);
</script>
</body>
</html>
