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

$classCount = $conn->query("SELECT COUNT(*) AS c FROM class_members cm JOIN classes c ON cm.class_id=c.id WHERE cm.user_code='$uc' AND c.teacher_code!='$uc' AND (c.is_archived=0 OR c.is_archived IS NULL) AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)")->fetch_assoc()['c'];

// Quiz & Assignment KPI stats
$totalQuizzesCount = (int)($conn->query("SELECT COUNT(*) AS c FROM quizzes q JOIN class_members cm ON cm.class_id=q.class_id JOIN classes c ON q.class_id=c.id WHERE cm.user_code='$uc' AND q.is_active=1 AND (c.is_archived=0 OR c.is_archived IS NULL) AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)")->fetch_assoc()['c'] ?? 0);
$completedQuizzesCount = (int)($conn->query("SELECT COUNT(DISTINCT quiz_id) AS c FROM quiz_submissions WHERE student_code='$uc'")->fetch_assoc()['c'] ?? 0);

$totalAssignCount = (int)($conn->query("SELECT COUNT(*) AS c FROM assignments a JOIN class_members cm ON cm.class_id=a.class_id JOIN classes c ON a.class_id=c.id WHERE cm.user_code='$uc' AND (c.is_archived=0 OR c.is_archived IS NULL) AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)")->fetch_assoc()['c'] ?? 0);
$completedAssignCount = (int)($conn->query("SELECT COUNT(DISTINCT assignment_id) AS c FROM assignment_submissions WHERE student_code='$uc'")->fetch_assoc()['c'] ?? 0);

$pendingAssign = $conn->query("
    SELECT a.id AS assignment_id, a.title, a.due_date, a.points, a.instructions, c.class_name, c.id AS class_id
    FROM assignments a JOIN classes c ON a.class_id=c.id JOIN class_members cm ON cm.class_id=c.id
    WHERE cm.user_code='$uc'
      AND (c.is_archived=0 OR c.is_archived IS NULL)
      AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)
      AND NOT EXISTS (SELECT 1 FROM assignment_submissions s WHERE s.assignment_id=a.id AND s.student_code='$uc')
    ORDER BY a.due_date IS NULL, a.due_date ASC LIMIT 10
");

$pendingQuiz = $conn->query("
    SELECT q.id, q.title, q.due_date, q.time_limit, (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id=q.id) AS q_count, c.class_name, c.id AS class_id
    FROM quizzes q JOIN classes c ON q.class_id=c.id JOIN class_members cm ON cm.class_id=c.id
    WHERE cm.user_code='$uc' AND q.is_active=1
      AND (c.is_archived=0 OR c.is_archived IS NULL)
      AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)
      AND NOT EXISTS (SELECT 1 FROM quiz_submissions s WHERE s.quiz_id=q.id AND s.student_code='$uc')
    GROUP BY q.id
    ORDER BY q.due_date IS NULL, q.due_date ASC LIMIT 10
");

$recentGrades = $conn->query("
    SELECT s.grade, s.submitted_at, a.title, a.points, c.class_name
    FROM assignment_submissions s JOIN assignments a ON s.assignment_id=a.id JOIN classes c ON a.class_id=c.id
    WHERE s.student_code='$uc' AND s.grade IS NOT NULL
      AND (c.is_archived=0 OR c.is_archived IS NULL)
      AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)
    ORDER BY s.submitted_at DESC LIMIT 5
");

$publishedGrades = $conn->query("
    SELECT pg.*, c.class_name, c.subject, u.first_name AS teacher_first, u.last_name AS teacher_last
    FROM published_grades pg
    JOIN classes c ON pg.class_id=c.id
    LEFT JOIN users u ON c.teacher_code=u.user_code
    WHERE pg.student_code='$uc'
      AND (c.is_archived=0 OR c.is_archived IS NULL)
      AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)
    ORDER BY pg.published_at DESC
");

$liveSessions = $conn->query("
    SELECT ls.*, c.class_name, c.id AS class_id
    FROM live_sessions ls JOIN classes c ON ls.class_id=c.id JOIN class_members cm ON cm.class_id=c.id
    WHERE cm.user_code='$uc' AND ls.status='live'
      AND (c.is_archived=0 OR c.is_archived IS NULL)
      AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)
    LIMIT 3
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
 


    <!-- ── Study Plan & Learning Recommendations Card ── -->
    <?php
    // Pre-fetch ALL teacher-uploaded modules this student can access, keyed by class_id
    // Ensures Phase 1 ALWAYS shows a specific module, never a generic button
    $studentModulesCache = [];
    $smRes = $conn->query("
        SELECT cm.id, cm.title, cm.original_name, cm.topic, cm.class_id
        FROM class_modules cm
        JOIN class_members mem ON mem.class_id = cm.class_id AND mem.user_code = '$uc'
        ORDER BY cm.class_id, cm.uploaded_at DESC
    ");
    if ($smRes) {
        while ($smRow = $smRes->fetch_assoc()) {
            $cId = intval($smRow['class_id']);
            if (!isset($studentModulesCache[$cId])) $studentModulesCache[$cId] = [];
            $studentModulesCache[$cId][] = [
                'id'   => intval($smRow['id']),
                'title'=> $smRow['title'],
                'topic'=> $smRow['topic'] ?? '',
            ];
        }
    }

    // Resolve the best module from ONLY the same class as the quiz/assignment.
    // NEVER returns a module from a different class — that would mislead the student.
    function cenlearn_card_resolve_module($classId, $topic, $quizTitle, &$cache) {
        $cid = intval($classId);
        $mods = $cache[$cid] ?? [];
        if (empty($mods)) return null; // No module in this class — return null, don't cross classes
        if (count($mods) === 1) return $mods[0]; // Only one module — return it directly
        $key = strtolower(trim($topic . ' ' . $quizTitle));
        $best = $mods[0]; $bestScore = 0;
        foreach ($mods as $mod) {
            $s = 0;
            $mt = strtolower($mod['topic'] ?? '');
            $ml = strtolower($mod['title'] ?? '');
            if ($mt && strpos($key, $mt) !== false) $s += 10;
            if ($ml && strpos($key, $ml) !== false) $s += 8;
            foreach (preg_split('/[\s,\.\-_]+/', $mt . ' ' . $ml) as $w) {
                if (strlen($w) >= 4 && strpos($key, $w) !== false) $s += 2;
            }
            if ($s > $bestScore) { $bestScore = $s; $best = $mod; }
        }
        return $best;
    }

    $planItems = !empty($topic_recs['recommendations'])
      ? array_values(array_filter($topic_recs['recommendations'], fn($r) => $r['score_pct'] !== null || !empty($r['modules'])))
      : [];
    if(!empty($planItems) || !empty($topic_recs['motivational_quote'])):
      $mq = $topic_recs['motivational_quote'] ?? null;
    ?>
    <div class="ai-assistant-card" style="margin-bottom:24px;border-top:4px solid #6366f1;">
      <div class="ai-hdr" style="margin-bottom:14px;">
        <div class="ai-title">
          <i class="fa fa-book" style="color:#6366f1;"></i> Study Plan &amp; Learning Recommendations
        </div>
        <span class="ai-badge" style="background:#ede9fe;color:#4f46e5;">
          <i class="fa fa-lightbulb-o"></i> Personalized Guidance
        </span>
      </div>

      <!-- ── AI Performance Coach & Diagnostic Feedback Banner ── -->
      <?php if(!empty($mq)): ?>
      <div style="background:<?php echo $mq['bg']; ?>;border:1px solid <?php echo $mq['border']; ?>;border-left:4px solid <?php echo $mq['color']; ?>;border-radius:12px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:flex-start;gap:14px;box-shadow:0 2px 8px rgba(0,0,0,0.03);">
        <div style="width:36px;height:36px;border-radius:10px;background:#fff;display:flex;align-items:center;justify-content:center;color:<?php echo $mq['color']; ?>;font-size:16px;flex-shrink:0;box-shadow:0 2px 6px rgba(0,0,0,.08);border:1px solid <?php echo $mq['border']; ?>;">
          <i class="fa <?php echo $mq['icon'] ?? 'fa-graduation-cap'; ?>"></i>
        </div>
        <div style="flex:1;">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:5px;">
            <strong style="font-size:13px;color:#0f172a;letter-spacing:-0.2px;">
              <?php echo $mq['title']; ?>
            </strong>
            <span style="font-size:11px;font-weight:800;color:<?php echo $mq['color']; ?>;background:#fff;padding:2px 10px;border-radius:99px;border:1px solid <?php echo $mq['border']; ?>;">
              <?php echo htmlspecialchars($mq['badge']); ?>
            </span>
          </div>
          <div style="font-size:12.5px;color:#334155;line-height:1.55;">
            <?php echo $mq['message']; ?>
          </div>
          <?php if(!empty($mq['action'])): ?>
          <div style="font-size:11.5px;color:#475569;margin-top:7px;display:flex;align-items:center;gap:6px;font-weight:600;">
            <i class="fa fa-arrow-circle-right" style="color:<?php echo $mq['color']; ?>;"></i> <?php echo $mq['action']; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div style="display:flex;flex-direction:column;gap:12px;">
        <?php foreach($planItems as $idx => $rec):
          $isHigh       = ($rec['priority'] ?? '') === 'High' || ($rec['score_pct'] !== null && $rec['score_pct'] < 50);
          $isMedium     = ($rec['score_pct'] !== null && $rec['score_pct'] >= 50 && $rec['score_pct'] < 75);
          $isSuccess    = ($rec['type'] ?? '') === 'success';
          $cardBg       = $isHigh ? '#fff8f8' : ($isMedium ? '#fffef5' : ($isSuccess ? '#f0fdf4' : '#f8fafc'));
          $cardBorder   = $isHigh ? '#fecaca' : ($isMedium ? '#fde68a' : ($isSuccess ? '#bbf7d0' : '#e2e8f0'));
          $badgeBg      = $isHigh ? '#fee2e2' : ($isMedium ? '#fef9c3' : '#e0f2fe');
          $badgeClr     = $isHigh ? '#991b1b' : ($isMedium ? '#92400e' : '#0369a1');
          $numGrad      = $isHigh ? 'linear-gradient(135deg,#ef4444,#dc2626)' : ($isMedium ? 'linear-gradient(135deg,#f59e0b,#d97706)' : ($isSuccess ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#3b82f6,#2563eb)'));
          $isAssignment = !empty($rec['is_assignment']);
          $quizId       = !empty($rec['quiz_id']) ? $rec['quiz_id'] : null;
          $recClassId   = intval($rec['class_id'] ?? 0);
          $recTopic     = $rec['topic'] ?? '';
          $recQuizTitle = $rec['quiz_title'] ?? '';
          // Resolve module ONLY from the same class — no cross-class fallback
          $firstMod = !empty($rec['primary_module']) ? $rec['primary_module']
                    : (!empty($rec['modules'][0]) ? $rec['modules'][0] : null);
          if (!$firstMod && $recClassId > 0) {
              $firstMod = cenlearn_card_resolve_module($recClassId, $recTopic, $recQuizTitle, $studentModulesCache);
          }
          // Only show module pills from the SAME class — never cross-class
          $allRecMods = !empty($rec['modules']) ? array_filter($rec['modules'], fn($m) => empty($m['class_id']) || intval($m['class_id']) === $recClassId) : [];
          if (empty($allRecMods) && $recClassId > 0 && !empty($studentModulesCache[$recClassId])) {
              $allRecMods = array_slice($studentModulesCache[$recClassId], 0, 4);
          }
          $hasClassModule  = !empty($firstMod);  // true only if this class has a module
          $isAutoMatched   = $hasClassModule && !empty($firstMod['auto_matched']);
          $noModuleInClass = !$hasClassModule;    // teacher hasn't uploaded module to this class yet
        ?>
        <div style="display:flex;align-items:flex-start;gap:12px;padding:14px;background:<?php echo $cardBg; ?>;border:1px solid <?php echo $cardBorder; ?>;border-radius:12px;">
          <div style="width:30px;height:30px;background:<?php echo $numGrad; ?>;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 2px 6px rgba(0,0,0,.08);">
            <span style="color:#fff;font-size:12px;font-weight:800;"><?php echo $idx+1; ?></span>
          </div>

          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
              <strong style="font-size:13.5px;color:#0f172a;"><?php echo $rec['title']; ?></strong>
              <?php if($rec['score_pct'] !== null): ?>
              <span style="font-size:11.5px;font-weight:800;color:<?php echo $badgeClr; ?>;background:<?php echo $badgeBg; ?>;padding:2px 9px;border-radius:99px;">
                Score: <?php echo $rec['score_pct']; ?>% <?php echo $isHigh ? '(Needs Improvement)' : '(Developing)'; ?>
              </span>
              <?php endif; ?>
            </div>

            <p style="font-size:12px;color:#475569;margin-bottom:8px;line-height:1.45;"><?php echo $rec['desc']; ?></p>

            <?php if(!$isSuccess): ?>
            <!-- Topic to Study highlight -->
            <?php if(!empty($recTopic)): ?>
            <div style="background:#fafafa;border:1px solid #e2e8f0;border-left:3px solid #6366f1;border-radius:8px;padding:8px 12px;margin-bottom:10px;display:flex;align-items:center;gap:10px;">
              <i class="fa fa-graduation-cap" style="color:#6366f1;font-size:15px;flex-shrink:0;"></i>
              <div>
                <div style="font-size:10px;font-weight:800;color:#6366f1;text-transform:uppercase;letter-spacing:.5px;">Topic to Study</div>
                <div style="font-size:13px;font-weight:700;color:#0f172a;margin-top:1px;"><?php echo htmlspecialchars($recTopic); ?></div>
                <?php if(!empty($recQuizTitle)): ?>
                <div style="font-size:11px;color:#64748b;margin-top:2px;">Based on: <em><?php echo htmlspecialchars($recQuizTitle); ?></em></div>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>


            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(190px, 1fr));gap:8px;margin-top:6px;margin-bottom:8px;">
              
              <!-- Phase 1: Module Study -->
              <div style="background:#fff;border:1px solid <?php echo $hasClassModule ? '#c7d2fe' : '#fde68a'; ?>;border-top:3px solid <?php echo $hasClassModule ? '#6366f1' : '#f59e0b'; ?>;border-radius:9px;padding:10px 12px;display:flex;flex-direction:column;gap:6px;">
                <div style="font-size:10px;font-weight:800;color:<?php echo $hasClassModule ? '#4f46e5' : '#b45309'; ?>;text-transform:uppercase;letter-spacing:.4px;">
                  <i class="fa <?php echo $hasClassModule ? 'fa-book' : 'fa-warning'; ?>"></i>
                  Phase 1: <?php echo $hasClassModule ? 'Module Study' : 'No Module Yet'; ?>
                  <?php if($isAutoMatched): ?>
                  <span style="background:#ede9fe;color:#5b21b6;border-radius:4px;padding:1px 5px;font-size:8.5px;border:1px solid #c4b5fd;margin-left:3px;"><i class="fa fa-magic"></i> ML</span>
                  <?php endif; ?>
                </div>
                <?php if($hasClassModule): ?>
                <div style="font-size:11px;color:#475569;line-height:1.35;">
                  Read this teacher-uploaded module to review the topics tested in your <?php echo $isAssignment ? 'assignment' : 'quiz'; ?>.
                </div>
                <a href="../shared/module_view.php?id=<?php echo $firstMod['id']; ?>" target="_blank"
                   style="display:inline-flex;align-items:center;gap:5px;padding:6px 11px;background:#4f46e5;color:#fff;border-radius:7px;font-size:11px;font-weight:700;text-decoration:none;margin-top:2px;box-shadow:0 2px 6px rgba(79,70,229,.25);">
                  <i class="fa fa-file-text-o"></i> Open: <?php echo htmlspecialchars(mb_strimwidth($firstMod['title'], 0, 22, '…')); ?>
                </a>
                <?php else: ?>
                <div style="font-size:11px;color:#92400e;line-height:1.4;">
                  Your teacher has not uploaded a learning module for <strong><?php echo htmlspecialchars($rec['class_name'] ?? $rec['subject'] ?? 'this class'); ?></strong> yet.
                  Ask your teacher to upload a module so you can review the topics for this <?php echo $isAssignment ? 'assignment' : 'quiz'; ?>.
                </div>
                <a href="classes.php" style="display:inline-flex;align-items:center;gap:5px;padding:5px 10px;background:#fef9c3;color:#92400e;border:1px solid #fde68a;border-radius:7px;font-size:11px;font-weight:700;text-decoration:none;margin-top:2px;">
                  <i class="fa fa-arrow-right"></i> View My Classes
                </a>
                <?php endif; ?>
              </div>

              <!-- Phase 2: Practice Retake / Resubmit -->
              <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:9px 11px;display:flex;flex-direction:column;justify-content:space-between;">
                <div>
                  <div style="font-size:10px;font-weight:800;color:#f59e0b;text-transform:uppercase;">
                    <i class="fa <?php echo $isAssignment ? 'fa-upload' : 'fa-pencil'; ?>"></i>
                    Phase 2: <?php echo $isAssignment ? 'Improve & Resubmit' : 'Practice Retake'; ?>
                  </div>
                  <div style="font-size:11px;color:#475569;margin-top:3px;line-height:1.35;">
                    <?php if($isAssignment): ?>
                      Review the matched module then resubmit a stronger answer.
                    <?php else: ?>
                      Analyze missed quiz questions and retake assessment.
                    <?php endif; ?>
                  </div>
                </div>
                <div style="margin-top:8px;">
                  <?php if($isAssignment): ?>
                  <a href="classwork.php" style="display:inline-flex;align-items:center;gap:4px;padding:4px 9px;background:#fffbeb;color:#b45309;border:1px solid #fde68a;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none;">
                    <i class="fa fa-upload"></i> Go to Assignments
                  </a>
                  <?php else: ?>
                  <a href="quizzes.php<?php echo $quizId ? '?open_quiz='.$quizId : ''; ?>"
                     style="display:inline-flex;align-items:center;gap:4px;padding:4px 9px;background:#fffbeb;color:#b45309;border:1px solid #fde68a;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none;">
                    <i class="fa fa-play-circle"></i> Retake Practice Quiz
                  </a>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Phase 3: Mastery Target -->
              <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:9px 11px;display:flex;flex-direction:column;justify-content:space-between;">
                <div>
                  <div style="font-size:10px;font-weight:800;color:#10b981;text-transform:uppercase;"><i class="fa fa-bullseye"></i> Phase 3: Mastery Target</div>
                  <div style="font-size:11px;color:#475569;margin-top:3px;line-height:1.35;">
                    Target &ge; 75% score on next assessment to achieve standard proficiency.
                  </div>
                </div>
                <div style="margin-top:8px;">
                  <span style="display:inline-flex;align-items:center;gap:4px;padding:4px 9px;background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;border-radius:6px;font-size:11px;font-weight:700;">
                    <i class="fa fa-check-circle"></i> Benchmark: &ge; 75%
                  </span>
                </div>
              </div>

            </div>

            <!-- Teacher-Uploaded Modules for this class -->
            <?php if(!empty($allRecMods)): ?>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:10px 12px;margin-top:8px;">
              <div style="font-size:10.5px;font-weight:800;color:#334155;margin-bottom:7px;">
                <i class="fa fa-folder-open" style="color:#6366f1;"></i>
                Teacher Modules for <?php echo htmlspecialchars($rec['class_name'] ?? $rec['subject'] ?? 'this class'); ?>:
                <?php if($isAutoMatched): ?>
                <span style="font-size:9px;color:#5b21b6;background:#ede9fe;padding:1px 6px;border-radius:4px;border:1px solid #c4b5fd;margin-left:4px;"><i class="fa fa-magic"></i> ML-Matched</span>
                <?php endif; ?>
              </div>
              <div style="display:flex;flex-wrap:wrap;gap:6px;">
                <?php foreach(array_slice($allRecMods,0,4) as $m):
                  $mlTag = !empty($m['auto_matched']);
                  $mTopic = !empty($m['topic']) ? $m['topic'] : null;
                ?>
                <a href="../shared/module_view.php?id=<?php echo $m['id']; ?>" target="_blank"
                   style="display:inline-flex;align-items:center;gap:5px;padding:5px 11px;background:<?php echo $mlTag?'#f5f3ff':'#eef2ff'; ?>;color:<?php echo $mlTag?'#5b21b6':'#4f46e5'; ?>;border:1px solid <?php echo $mlTag?'#c4b5fd':'#c7d2fe'; ?>;border-radius:7px;font-size:11.5px;font-weight:700;text-decoration:none;">
                  <i class="fa fa-file-text-o" style="font-size:11px;"></i>
                  <?php echo htmlspecialchars($m['title']); ?>
                  <?php if($mTopic): ?><em style="font-size:10px;color:#6366f1;font-weight:500;"> [<?php echo htmlspecialchars($mTopic); ?>]</em><?php endif; ?>
                </a>
                <?php endforeach; ?>
              </div>
            </div>
            <?php elseif($noModuleInClass && !$isSuccess): ?>
            <!-- No module uploaded yet for this class -->
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:10px 14px;margin-top:8px;display:flex;align-items:flex-start;gap:10px;">
              <i class="fa fa-info-circle" style="color:#f59e0b;font-size:15px;flex-shrink:0;margin-top:1px;"></i>
              <div>
                <div style="font-size:11.5px;font-weight:700;color:#92400e;margin-bottom:3px;">No Learning Module Uploaded</div>
                <div style="font-size:11px;color:#78350f;line-height:1.45;">
                  Your teacher has not uploaded a learning module for <strong><?php echo htmlspecialchars($rec['class_name'] ?? $rec['subject'] ?? 'this class'); ?></strong> yet.
                  The study recommendation is based on your quiz and performance data. Ask your teacher to upload a module for this topic.
                </div>
              </div>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <!-- ── High-Performer Continuous Excellence & Growth Pathway ── -->
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-left:3px solid #10b981;border-radius:9px;padding:10px 14px;margin-bottom:10px;">
              <div style="font-size:11px;font-weight:800;color:#065f46;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:6px;">
                <i class="fa fa-trophy" style="color:#10b981;"></i> Continuous Growth &amp; Mastery Strategy
              </div>
              <div style="font-size:12px;color:#166534;margin-top:4px;line-height:1.45;font-style:italic;">
                &ldquo;Success isn't about being the best. It's about always getting better than you were yesterday.&rdquo;
              </div>
            </div>

            <!-- 3-Pillar Excellence Actions for Quiz, Assignment & Performance -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(190px, 1fr));gap:8px;margin-top:6px;margin-bottom:10px;">

              <!-- Pillar 1: Quiz Speed & Precision -->
              <div style="background:#fff;border:1px solid #bbf7d0;border-top:3px solid #10b981;border-radius:9px;padding:10px 12px;display:flex;flex-direction:column;justify-content:space-between;">
                <div>
                  <div style="font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;letter-spacing:.4px;">
                    <i class="fa fa-bolt"></i> Quiz Precision
                  </div>
                  <div style="font-size:11px;color:#475569;margin-top:4px;line-height:1.35;">
                    Challenge yourself with timed problem-solving and master edge-case question scenarios to lock in 90%+ scores.
                  </div>
                </div>
                <div style="margin-top:8px;">
                  <a href="quizzes.php" style="display:inline-flex;align-items:center;gap:4px;padding:4px 9px;background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none;">
                    <i class="fa fa-play-circle"></i> Practice Quizzes
                  </a>
                </div>
              </div>

              <!-- Pillar 2: Assignment Synthesis -->
              <div style="background:#fff;border:1px solid #bfdbfe;border-top:3px solid #3b82f6;border-radius:9px;padding:10px 12px;display:flex;flex-direction:column;justify-content:space-between;">
                <div>
                  <div style="font-size:10px;font-weight:800;color:#1e40af;text-transform:uppercase;letter-spacing:.4px;">
                    <i class="fa fa-file-text-o"></i> Assignment Quality
                  </div>
                  <div style="font-size:11px;color:#475569;margin-top:4px;line-height:1.35;">
                    Elevate your submissions with empirical case studies, structured citations, and capstone-ready deliverables.
                  </div>
                </div>
                <div style="margin-top:8px;">
                  <a href="classwork.php" style="display:inline-flex;align-items:center;gap:4px;padding:4px 9px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none;">
                    <i class="fa fa-tasks"></i> View Assignments
                  </a>
                </div>
              </div>

              <!-- Pillar 3: Leadership & Advanced Synthesis -->
              <div style="background:#fff;border:1px solid #e9d5ff;border-top:3px solid #a855f7;border-radius:9px;padding:10px 12px;display:flex;flex-direction:column;justify-content:space-between;">
                <div>
                  <div style="font-size:10px;font-weight:800;color:#7e22ce;text-transform:uppercase;letter-spacing:.4px;">
                    <i class="fa fa-users"></i> Peer Mentorship
                  </div>
                  <div style="font-size:11px;color:#475569;margin-top:4px;line-height:1.35;">
                    Share insights in study groups and read ahead in course modules to prepare early for capstone defense.
                  </div>
                </div>
                <div style="margin-top:8px;">
                  <span style="display:inline-flex;align-items:center;gap:4px;padding:4px 9px;background:#faf5ff;color:#7e22ce;border:1px solid #e9d5ff;border-radius:6px;font-size:11px;font-weight:700;">
                    <i class="fa fa-star"></i> Honors Benchmark
                  </span>
                </div>
              </div>

            </div>

            <!-- Enrichment & Advanced Study Modules -->
            <?php if(!empty($allRecMods)): ?>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:10px 12px;margin-top:8px;">
              <div style="font-size:10.5px;font-weight:800;color:#334155;margin-bottom:7px;">
                <i class="fa fa-book" style="color:#10b981;"></i>
                Enrichment &amp; Advanced Reading Modules:
              </div>
              <div style="display:flex;flex-wrap:wrap;gap:6px;">
                <?php foreach(array_slice($allRecMods,0,4) as $m):
                  $mTopic = !empty($m['topic']) ? $m['topic'] : null;
                ?>
                <a href="../shared/module_view.php?id=<?php echo $m['id']; ?>" target="_blank"
                   style="display:inline-flex;align-items:center;gap:5px;padding:5px 11px;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;border-radius:7px;font-size:11.5px;font-weight:700;text-decoration:none;">
                  <i class="fa fa-file-text-o" style="font-size:11px;"></i>
                  <?php echo htmlspecialchars($m['title']); ?>
                  <?php if($mTopic): ?><em style="font-size:10px;color:#059669;font-weight:500;"> [<?php echo htmlspecialchars($mTopic); ?>]</em><?php endif; ?>
                </a>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>
            <?php endif; // isSuccess ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
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
