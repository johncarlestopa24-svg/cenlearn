<?php
include '../includes/session.php';
include '../includes/conn.php';

$uc = $conn->real_escape_string($user['user_code']);
$initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));

// Get student's enrolled classes
$enrolledClassesQ = $conn->query("
    SELECT c.id, c.class_name, c.subject, c.section
    FROM class_members cm
    JOIN classes c ON cm.class_id = c.id
    WHERE cm.user_code = '$uc' AND (c.is_archived = 0 OR c.is_archived IS NULL)
    ORDER BY c.class_name ASC
");
$classes = [];
while($r = $enrolledClassesQ->fetch_assoc()) $classes[] = $r;

$classIds = array_column($classes, 'id');
$classFilter = intval($_GET['class_id'] ?? 0);

// Fetch all quizzes across enrolled classes
$quizzes = [];
if(!empty($classIds)){
    $idsStr = implode(',', $classIds);
    $whereClass = $classFilter > 0 ? "AND q.class_id = $classFilter" : "AND q.class_id IN ($idsStr)";
    
    $res = $conn->query("
        SELECT q.*, c.class_name, c.subject,
               (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = q.id) AS q_count,
               qs.id AS sub_id, qs.score, qs.total_points, qs.submitted_at
        FROM quizzes q
        JOIN classes c ON q.class_id = c.id
        LEFT JOIN quiz_submissions qs ON qs.quiz_id = q.id AND qs.student_code = '$uc'
        WHERE q.is_active = 1 $whereClass
        ORDER BY q.created_at DESC
    ");
    while($r = $res->fetch_assoc()){
        $quizzes[] = $r;
    }
}

// Stats calculation
$totalQuizzes = count($quizzes);
$availableQuizzes = 0;
$completedQuizzes = 0;
$missedQuizzes = 0;
$totalScoreEarned = 0;
$totalScorePossible = 0;

foreach($quizzes as $qz){
    $isSubmitted = !empty($qz['sub_id']);
    $isDue = $qz['due_date'] && strtotime($qz['due_date']) < time();
    
    if($isSubmitted){
        $completedQuizzes++;
        $totalScoreEarned += floatval($qz['score']);
        $totalScorePossible += intval($qz['total_points']);
    } elseif($isDue){
        $missedQuizzes++;
    } else {
        $availableQuizzes++;
    }
}

$avgPct = $totalScorePossible > 0 ? round(($totalScoreEarned / $totalScorePossible) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CenLearn &mdash; My Quizzes</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html,body{margin:0;padding:0;overflow-x:hidden;}
    body { font-family: 'Inter', sans-serif; background: #f0f4f8; color: #1e293b; }

    /* ── Sidebar ── */
    .sd-sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:linear-gradient(180deg,#0c1a2e 0%,#0f2d4a 55%,#0f5f80 100%);display:flex;flex-direction:column;z-index:200;transition:transform .3s cubic-bezier(.4,0,.2,1);transform:translateX(-260px);}
    .sd-sidebar.open{transform:translateX(0);}
    @media(min-width:901px){.sd-sidebar{transform:translateX(0);}}
    .sb-brand{padding:26px 22px 18px;border-bottom:1px solid rgba(255,255,255,.08);}
    .sb-logo{width:42px;height:42px;border-radius:11px;background:linear-gradient(135deg,#1792bb,#0f5f80);display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px;box-shadow:0 4px 14px rgba(23,146,187,.45);}
    .sb-logo i{color:#fff;font-size:18px;}
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

    /* ── Main layout ── */
    .sd-main{margin-left:0;min-height:100vh;display:flex;flex-direction:column;}
    @media(min-width:901px){.sd-main{margin-left:260px;}}
    .sd-topbar{background:#fff;padding:0 28px;height:62px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:50;box-shadow:0 1px 4px rgba(0,0,0,.04);}
    .sd-topbar h3{font-size:16px;font-weight:700;color:#0f172a;margin:0;}
    .sd-topbar p{font-size:12px;color:#64748b;margin:0;}
    .sd-content{padding:24px 28px 48px;flex:1;}

    /* ── Stats Strip ── */
    .stats-strip{display:flex;gap:14px;margin-bottom:24px;flex-wrap:wrap;}
    .stat-pill{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px 18px;flex:1;min-width:140px;transition:box-shadow .2s;}
    .stat-pill:hover{box-shadow:0 4px 12px rgba(0,0,0,.05);}
    .sp-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .stat-pill strong{display:block;font-size:24px;font-weight:800;color:#0f172a;line-height:1;}
    .stat-pill span{font-size:11px;color:#64748b;font-weight:500;}

    /* ── Class Filter Selector ── */
    .class-filter-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px 20px;margin-bottom:22px;box-shadow:0 1px 4px rgba(0,0,0,.03);}
    .class-filter-card h4{font-size:13px;font-weight:700;color:#0f172a;margin:0 0 12px;display:flex;align-items:center;gap:7px;}
    .class-pills{display:flex;flex-wrap:wrap;gap:8px;}
    .class-pill{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:10px;border:1.5px solid #e2e8f0;background:#f8fafc;font-size:12px;font-weight:600;color:#475569;cursor:pointer;text-decoration:none;transition:all .18s;}
    .class-pill:hover{border-color:#1792bb;background:#f0f9ff;color:#0369a1;text-decoration:none;}
    .class-pill.active{border-color:#1792bb;background:linear-gradient(135deg,#1792bb,#0f5f80);color:#fff;box-shadow:0 3px 12px rgba(23,146,187,.3);}

    /* ── Section Header & Tabs ── */
    .quiz-sec-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px;}
    .quiz-sec-header h3{font-size:15px;font-weight:800;color:#0f172a;margin:0;display:flex;align-items:center;gap:8px;}
    .quiz-tabs{display:flex;gap:6px;background:#e2e8f0;padding:3px;border-radius:10px;}
    .quiz-tab-btn{padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;color:#64748b;border:none;background:transparent;cursor:pointer;transition:all .15s;font-family:'Inter',sans-serif;}
    .quiz-tab-btn.active{background:#fff;color:#0f172a;box-shadow:0 1px 3px rgba(0,0,0,.1);}

    /* ── Quizzes Grid & Row Cards ── */
    .quiz-row-list{display:flex;flex-direction:column;gap:10px;}
    .quiz-row-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;transition:all .18s;box-shadow:0 1px 3px rgba(0,0,0,.02);}
    .quiz-row-card:hover{border-color:#cbd5e1;box-shadow:0 4px 12px rgba(0,0,0,.05);}
    .qrc-left{display:flex;align-items:center;gap:14px;flex:1;min-width:0;}
    .qrc-info{display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex:1;min-width:0;}
    .qrc-title{font-size:14px;font-weight:700;color:#0f172a;margin:0;margin-right:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .qz-class-badge{font-size:11px;font-weight:700;color:#0369a1;background:#e0f2fe;padding:2px 8px;border-radius:5px;border:1px solid #bae6fd;display:inline-flex;align-items:center;gap:4px;}
    .qrc-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
    .qz-pill{font-size:11px;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;padding:3px 9px;border-radius:6px;display:inline-flex;align-items:center;gap:5px;font-weight:500;}
    .qrc-right{display:flex;align-items:center;gap:12px;flex-shrink:0;}

    /* Status Pills */
    .status-pill{padding:4px 10px;border-radius:99px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:4px;}
    .status-open{background:#dcfce7;color:#166534;}
    .status-done{background:#e0f2fe;color:#0369a1;}
    .status-closed{background:#f1f5f9;color:#64748b;}
    .status-graded{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;}

    /* Action Buttons */
    .btn-take-quiz{padding:8px 16px;background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;border:none;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 3px 10px rgba(139,92,246,.3);font-family:'Inter',sans-serif;transition:opacity .15s;}
    .btn-take-quiz:hover{opacity:.9;}
    .btn-view-results{padding:7px 14px;background:#f5f3ff;color:#6d28d9;border:1.5px solid #ede9fe;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-family:'Inter',sans-serif;transition:background .15s;}
    .btn-view-results:hover{background:#ede9fe;}

    .qc-empty{text-align:center;padding:56px 20px;background:#fff;border-radius:18px;border:1px solid #e2e8f0;grid-column:1/-1;}
    .qc-empty i{font-size:36px;color:#cbd5e1;display:block;margin-bottom:12px;}
    .qc-empty h4{font-size:15px;font-weight:700;color:#64748b;margin:0 0 4px;}
    .qc-empty p{font-size:12px;color:#94a3b8;margin:0;}

    /* Take Quiz Modal Fullscreen */
    #quizViolationBar{display:none;background:#fef2f2;border-bottom:1px solid #fecaca;color:#991b1b;padding:8px 16px;font-size:12px;font-weight:600;align-items:center;gap:8px;}
    .quiz-q-block{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:14px;}
    .quiz-q-text{font-size:14px;font-weight:700;color:#0f172a;line-height:1.5;}
    .quiz-q-pts{font-size:11px;color:#8b5cf6;font-weight:700;margin-top:2px;}
    .quiz-opt{padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;margin-top:8px;cursor:pointer;font-size:13px;color:#334155;display:flex;align-items:center;gap:10px;transition:all .15s;background:#fff;}
    .quiz-opt:hover{border-color:#8b5cf6;background:#f5f3ff;}
    .quiz-opt.selected{border-color:#8b5cf6;background:#f5f3ff;color:#5b21b6;font-weight:600;}
    .quiz-opt.selected span:first-child{border-color:#8b5cf6;background:#8b5cf6;color:#fff;}
    .quiz-id-input{width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;margin-top:8px;font-family:'Inter',sans-serif;outline:none;}
    .quiz-id-input:focus{border-color:#8b5cf6;}
    .quiz-tf{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px;}

    /* ── High-Visibility Student Quiz Timer ── */
    #quizTimer {
      display: none;
      align-items: center;
      gap: 7px;
      padding: 6px 14px;
      border-radius: 30px;
      font-size: 15px;
      font-weight: 800;
      font-family: 'Inter', -apple-system, monospace;
      letter-spacing: 0.5px;
      background: #ffffff;
      color: #6d28d9;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18), 0 0 0 2px rgba(255, 255, 255, 0.4);
      transition: all 0.25s ease;
      white-space: nowrap;
      user-select: none;
    }
    #quizTimer .timer-icon {
      font-size: 15px;
      color: #7c3aed;
    }
    #quizTimer .timer-text {
      font-size: 16px;
      font-weight: 800;
      font-family: 'Consolas', 'Courier New', monospace;
      letter-spacing: 1px;
    }
    #quizTimer.timer-warning {
      background: #fffbeb !important;
      color: #b45309 !important;
      box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35), 0 0 0 2px #f59e0b !important;
    }
    #quizTimer.timer-warning .timer-icon {
      color: #d97706 !important;
    }
    #quizTimer.timer-danger {
      background: #fef2f2 !important;
      color: #dc2626 !important;
      box-shadow: 0 4px 16px rgba(239, 68, 68, 0.45), 0 0 0 2px #ef4444 !important;
      animation: timerPulse 0.8s ease-in-out infinite;
    }
    #quizTimer.timer-danger .timer-icon {
      color: #dc2626 !important;
    }
    @keyframes timerPulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.05); }
    }

    footer.t-footer{text-align:center;padding:14px;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;background:#fff;}
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
      <li><a href="dashboard.php"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li><a href="classes.php"><i class="fa fa-book"></i> My Classes</a></li>
      <li class="active"><a href="quizzes.php"><i class="fa fa-question-circle"></i> My Quizzes</a></li>
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
      <div>
        <h3 style="display:flex;align-items:center;gap:7px;"><i class="fa fa-question-circle" style="color:#8b5cf6;"></i> My Quizzes</h3>
        <p>View and take quizzes across all your enrolled classes</p>
      </div>
    </div>
    <a href="classes.php" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;background:#f0f9ff;color:#0369a1;border:1.5px solid #bae6fd;border-radius:9px;font-size:12px;font-weight:600;text-decoration:none;transition:all .18s;">
      <i class="fa fa-book"></i> My Classes
    </a>
  </header>

  <div class="sd-content">

    <!-- Stats strip -->
    <div class="stats-strip">
      <div class="stat-pill">
        <div class="sp-icon" style="background:linear-gradient(135deg,rgba(139,92,246,.12),rgba(109,40,217,.06));"><i class="fa fa-question-circle" style="color:#8b5cf6;font-size:18px;"></i></div>
        <div><strong><?php echo $totalQuizzes; ?></strong><span>Total Quizzes</span></div>
      </div>
      <div class="stat-pill">
        <div class="sp-icon" style="background:linear-gradient(135deg,rgba(16,185,129,.12),rgba(5,150,105,.06));"><i class="fa fa-play-circle" style="color:#10b981;font-size:18px;"></i></div>
        <div><strong><?php echo $availableQuizzes; ?></strong><span>Available Now</span></div>
      </div>
      <div class="stat-pill">
        <div class="sp-icon" style="background:linear-gradient(135deg,rgba(23,146,187,.12),rgba(15,95,128,.06));"><i class="fa fa-check-circle" style="color:#1792bb;font-size:18px;"></i></div>
        <div><strong><?php echo $completedQuizzes; ?></strong><span>Completed</span></div>
      </div>
      <div class="stat-pill">
        <div class="sp-icon" style="background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(217,119,6,.06));"><i class="fa fa-star" style="color:#d97706;font-size:18px;"></i></div>
        <div><strong><?php echo $avgPct; ?>%</strong><span>Avg Grade</span></div>
      </div>
    </div>

    <!-- Class Filter Pills -->
    <?php if(!empty($classes)): ?>
    <div class="class-filter-card">
      <h4><i class="fa fa-filter" style="color:#1792bb;"></i> Filter by Class</h4>
      <div class="class-pills">
        <a href="quizzes.php" class="class-pill <?php echo $classFilter===0?'active':''; ?>">
          <i class="fa fa-th-large"></i> All Classes
        </a>
        <?php foreach($classes as $c): ?>
        <a href="quizzes.php?class_id=<?php echo $c['id']; ?>" class="class-pill <?php echo $c['id']===$classFilter?'active':''; ?>">
          <i class="fa fa-book"></i> <?php echo htmlspecialchars($c['class_name']); ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Quizzes Dashboard Cards -->
    <div class="quiz-sec-header">
      <h3><i class="fa fa-list" style="color:#8b5cf6;"></i> Quizzes Overview</h3>
      <div class="quiz-tabs">
        <button class="quiz-tab-btn active" onclick="filterQuizCards('all', this)">All (<?php echo count($quizzes); ?>)</button>
        <button class="quiz-tab-btn" onclick="filterQuizCards('available', this)">Available (<?php echo $availableQuizzes; ?>)</button>
        <button class="quiz-tab-btn" onclick="filterQuizCards('completed', this)">Completed (<?php echo $completedQuizzes; ?>)</button>
      </div>
    </div>

    <?php if(empty($quizzes)): ?>
    <div class="qc-empty">
      <i class="fa fa-inbox"></i>
      <h4>No quizzes available</h4>
      <p>Your teachers haven't posted any quizzes for your classes yet.</p>
    </div>
    <?php else: ?>

    <div class="quiz-row-list" id="quizCardsGrid">
      <?php foreach($quizzes as $qz):
        $isSubmitted = !empty($qz['sub_id']);
        $isDue = $qz['due_date'] && strtotime($qz['due_date']) < time();
        $isUpcoming = !empty($qz['start_date']) && strtotime($qz['start_date']) > time();
        $cardCategory = $isSubmitted ? 'completed' : ($isDue ? 'closed' : ($isUpcoming ? 'scheduled' : 'available'));
      ?>
      <div class="quiz-row-card" data-category="<?php echo $cardCategory; ?>">
        <div class="qrc-left">
          <div class="qrc-info">
            <h5 class="qrc-title"><?php echo htmlspecialchars($qz['title']); ?></h5>
            <span class="qz-class-badge"><i class="fa fa-book"></i> <?php echo htmlspecialchars($qz['class_name']); ?></span>
            <div class="qrc-meta">
              <span class="qz-pill" title="Number of Questions"><i class="fa fa-question-circle" style="color:#6366f1;"></i> <strong><?php echo $qz['q_count']; ?></strong> Questions</span>
              <span class="qz-pill" title="Time Limit"><i class="fa fa-clock-o" style="color:#64748b;"></i> <strong><?php echo $qz['time_limit'] ? $qz['time_limit'].'m' : 'Unlimited'; ?></strong></span>
              <?php if(!empty($qz['start_date'])): ?>
                <span class="qz-pill" title="Start Time">
                  <i class="fa fa-clock-o" style="color:#0284c7;"></i>
                  Starts: <strong><?php echo date('M d, Y g:i A', strtotime($qz['start_date'])); ?></strong>
                </span>
              <?php endif; ?>
              <span class="qz-pill" title="Due / Expiration Date">
                <i class="fa fa-hourglass-end" style="color:#64748b;"></i>
                <?php if($qz['due_date']): ?>
                  Due: <strong style="color:<?php echo $isDue?'#ef4444':'#0f172a'; ?>;"><?php echo date('M d, Y g:i A', strtotime($qz['due_date'])); ?></strong>
                <?php else: ?>
                  No expiration
                <?php endif; ?>
              </span>
            </div>
          </div>
        </div>

        <div class="qrc-right">
          <?php if($isSubmitted): ?>
            <div style="text-align:right;margin-right:4px;">
              <div style="font-size:9px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Score</div>
              <div style="font-size:14px;font-weight:800;color:#0369a1;"><?php echo $qz['score']; ?> / <?php echo $qz['total_points']; ?></div>
            </div>
            <span class="status-pill status-graded"><i class="fa fa-check-circle"></i> Submitted</span>
          <?php elseif($isUpcoming): ?>
            <span class="status-pill" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;"><i class="fa fa-lock"></i> Opens <?php echo date('M d, g:i A', strtotime($qz['start_date'])); ?></span>
          <?php elseif(!$isDue): ?>
            <span class="status-pill status-open"><i class="fa fa-play"></i> Ready</span>
            <button class="btn-take-quiz" onclick="takeQuiz(<?php echo $qz['id']; ?>)"><i class="fa fa-pencil"></i> Take Quiz</button>
          <?php else: ?>
            <span class="status-pill status-closed"><i class="fa fa-times-circle"></i> Missed</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php endif; ?>

  </div>
  <footer class="t-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<!-- ═══════════ TAKE QUIZ MODAL (FULLSCREEN) ═══════════ -->
<div class="cv-modal-overlay" id="takeQuizModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;align-items:stretch;">
  <div class="cv-modal" style="max-width:100%;width:100%;height:100vh;max-height:100vh;border-radius:0;margin:0;display:flex;flex-direction:column;background:#fff;">
    <div class="cv-modal-head" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;color:#fff;">
      <h4 id="takeQuizTitle" style="color:#fff;margin:0;font-size:16px;font-weight:700;"><i class="fa fa-question-circle"></i> Quiz</h4>
      <div style="display:flex;align-items:center;gap:12px;">
        <span id="quizProgress" style="background:rgba(255,255,255,.2);padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;"><i class="fa fa-pencil-square-o"></i> 0/0 Answered</span>
        <span id="quizTimer" style="display:none;"></span>
      </div>
    </div>
    <div id="quizViolationBar"><i class="fa fa-exclamation-triangle"></i> <span id="quizViolationMsg">Warning: suspicious activity detected</span></div>
    <div class="cv-modal-body" id="takeQuizBody" style="flex:1;overflow-y:auto;padding:24px 20px;background:#f8fafc;">
      <div style="text-align:center;padding:32px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
    </div>
    <div class="cv-modal-foot" id="takeQuizFoot" style="display:none;padding:14px 24px;background:#fff;border-top:1px solid #e2e8f0;justify-content:flex-end;gap:10px;">
      <button class="btn-modal-ok" id="btnSubmitQuiz" style="padding:10px 22px;background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;border:none;border-radius:9px;font-weight:700;cursor:pointer;box-shadow:0 3px 10px rgba(139,92,246,.3);"><i class="fa fa-check"></i> Submit Quiz</button>
    </div>
  </div>
</div>

<?php include '../includes/scripts.php'; ?>
<script>
function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }

function filterQuizCards(cat, btn){
  document.querySelectorAll('.quiz-tab-btn').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
  var cards = document.querySelectorAll('#quizCardsGrid .quiz-row-card');
  cards.forEach(function(c){
    if(cat === 'all' || c.getAttribute('data-category') === cat){
      c.style.display = 'flex';
    } else {
      c.style.display = 'none';
    }
  });
}

// ── Take Quiz Logic ──────────────────────────────────────────────────────────
var _quizId = null, _answers = {}, _quizQuestions = [], _timerInt = null, _heartbeatInt = null, _tabSwitches = 0, _fsExits = 0;

function closeQuizModalDirect(){
  var modal = document.getElementById('takeQuizModal');
  if(modal) modal.style.display = 'none';

  var vBar = document.getElementById('quizViolationBar');
  if(vBar) vBar.style.display = 'none';

  if(document.fullscreenElement || document.webkitFullscreenElement){
    if(document.exitFullscreen) document.exitFullscreen().catch(function(){});
    else if(document.webkitExitFullscreen) document.webkitExitFullscreen();
  }

  if(_timerInt) { clearInterval(_timerInt); _timerInt = null; }
  if(_heartbeatInt) { clearInterval(_heartbeatInt); _heartbeatInt = null; }

  _quizId = null;

  // Clean URL: strip ?take=... so it doesn't re-trigger modal on reload
  var cleanUrl = window.location.pathname;
  var params = new URLSearchParams(window.location.search);
  params.delete('take');
  var pStr = params.toString();
  if(pStr) cleanUrl += '?' + pStr;

  window.history.replaceState({}, document.title, cleanUrl);
  window.location.href = cleanUrl;
}

function closeQuizModal(){
  if(confirm("Exit quiz? Your progress will be saved.")){
    closeQuizModalDirect();
  }
}

function takeQuiz(id){
  _quizId = id; _answers = {}; _tabSwitches = 0; _fsExits = 0;
  var bodyEl = document.getElementById('takeQuizBody');
  var footEl = document.getElementById('takeQuizFoot');
  var tEl = document.getElementById('quizTimer');
  
  bodyEl.innerHTML='<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i><p style="margin-top:12px;font-size:13px;">Loading quiz questions...</p></div>';
  footEl.style.display='none';
  if(tEl) tEl.style.display='none';
  document.getElementById('takeQuizModal').style.display='flex';

  var docEl = document.documentElement;
  if (docEl.requestFullscreen) { docEl.requestFullscreen().catch(function(){}); }
  else if (docEl.webkitRequestFullscreen) { docEl.webkitRequestFullscreen(); }

  if (_heartbeatInt) clearInterval(_heartbeatInt);
  _heartbeatInt = setInterval(function(){
    if (_quizId && document.getElementById('takeQuizModal') && document.getElementById('takeQuizModal').style.display === 'flex') {
      $.post('../shared/quiz_handler.php', { action: 'heartbeat', quiz_id: _quizId });
    }
  }, 10000);
  document.getElementById('quizProgress').innerHTML = '<i class="fa fa-pencil-square-o"></i> 0/0 Answered';

  $.post('../shared/quiz_handler.php', {action:'get_questions', quiz_id:id}, function(r){
    if(typeof r === 'string'){
      try { r = JSON.parse(r.trim()); } catch(e){ r = {success:false, msg:'Invalid data'}; }
    }
    if(!r || !r.success){
      bodyEl.innerHTML='<div style="padding:32px;text-align:center;"><div style="width:60px;height:60px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;"><i class="fa fa-exclamation-circle" style="font-size:24px;color:#ef4444;"></i></div><h4 style="margin:0 0 6px;color:#0f172a;">Cannot Start Quiz</h4><p style="color:#64748b;font-size:13px;margin:0 0 16px;">'+(r && r.msg ? r.msg : 'Failed to load quiz')+'</p></div>';
      return;
    }

    if(r.already_submitted){
      var totalPts = parseFloat(r.total) || 0;
      var earnedScore = parseFloat(r.score) || 0;
      var pct = totalPts > 0 ? Math.round((earnedScore / totalPts) * 100) : 0;
      var grade, gclr, gbg;
      if(pct>=90){grade='A';gclr='#166534';gbg='#dcfce7';}
      else if(pct>=80){grade='B';gclr='#1d4ed8';gbg='#dbeafe';}
      else if(pct>=70){grade='C';gclr='#92400e';gbg='#fef3c7';}
      else if(pct>=60){grade='D';gclr='#c2410c';gbg='#ffedd5';}
      else{grade='F';gclr='#991b1b';gbg='#fee2e2';}

      var vBar = document.getElementById('quizViolationBar');
      if(vBar) vBar.style.display = 'none';

      var modalHead = document.querySelector('#takeQuizModal .cv-modal-head div');
      if(modalHead){
        modalHead.innerHTML = '<button class="cv-modal-x" style="background:none;border:none;color:#fff;font-size:24px;cursor:pointer;line-height:1;" onclick="closeQuizModalDirect()">&times;</button>';
      }

      bodyEl.innerHTML=
        '<div style="text-align:center;padding:36px 20px;max-width:620px;margin:0 auto;">'
        +'<div style="width:84px;height:84px;border-radius:50%;background:linear-gradient(135deg,#8b5cf6,#6d28d9);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;box-shadow:0 8px 24px rgba(139,92,246,.35);">'
        +'<i class="fa fa-check" style="color:#fff;font-size:36px;"></i></div>'
        +'<h3 style="font-size:22px;font-weight:800;color:#0f172a;margin:0 0 6px;">Quiz Already Completed</h3>'
        +'<p style="font-size:13.5px;color:#64748b;margin:0 0 28px;">Your submission has been recorded in the class record.</p>'
        +'<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:28px;">'
        +'<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:18px 12px;box-shadow:0 2px 8px rgba(0,0,0,.03);">'
        +'<div style="font-size:26px;font-weight:800;color:#0f172a;">'+earnedScore+'<span style="font-size:14px;color:#94a3b8;font-weight:400;"> / '+totalPts+'</span></div>'
        +'<div style="font-size:11.5px;color:#64748b;font-weight:600;margin-top:4px;text-transform:uppercase;letter-spacing:.5px;">Score</div></div>'
        +'<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:18px 12px;box-shadow:0 2px 8px rgba(0,0,0,.03);">'
        +'<div style="font-size:26px;font-weight:800;color:#8b5cf6;">'+pct+'%</div>'
        +'<div style="font-size:11.5px;color:#64748b;font-weight:600;margin-top:4px;text-transform:uppercase;letter-spacing:.5px;">Percentage</div></div>'
        +'<div style="background:'+gbg+';border:1px solid '+gbg+';border-radius:14px;padding:18px 12px;box-shadow:0 2px 8px rgba(0,0,0,.03);">'
        +'<div style="font-size:26px;font-weight:800;color:'+gclr+';">'+grade+'</div>'
        +'<div style="font-size:11.5px;color:'+gclr+';font-weight:600;margin-top:4px;text-transform:uppercase;letter-spacing:.5px;">Grade</div></div>'
        +'</div>'
        +'<div style="display:flex;align-items:center;justify-content:center;gap:12px;">'
        +'<button type="button" onclick="closeQuizModalDirect()" style="padding:12px 28px;background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(139,92,246,.4);display:inline-flex;align-items:center;gap:8px;">'
        +'<i class="fa fa-times-circle"></i> Close & Return to Quizzes</button>'
        +'</div>'
        +'</div>';
      footEl.style.display='none';
      return;
    }

    _quizQuestions = r.questions || [];
    _tabSwitches = parseInt(r.tab_switches) || 0;
    _answers = r.saved_answers || {};

    document.getElementById('takeQuizTitle').innerHTML='<i class="fa fa-question-circle"></i> ' + escapeCqHtml(r.quiz ? r.quiz.title : 'Quiz');
    updateQuizProgress();

    // Violation Warning Bar
    var vBar = document.getElementById('quizViolationBar');
    var vMsg = document.getElementById('quizViolationMsg');
    if (_tabSwitches > 0 && vBar && vMsg) {
      vMsg.textContent = 'Warning: ' + _tabSwitches + ' of 3 allowed violations (tab switches / page reloads) recorded!';
      vBar.style.display = 'flex';
    } else if (vBar) {
      vBar.style.display = 'none';
    }

    // Timer setup (continuous timer from server started_at)
    var secsLeft = 0;
    if (r.remaining_seconds !== undefined && r.remaining_seconds !== null) {
      secsLeft = parseInt(r.remaining_seconds) || 0;
    } else {
      var tLim = parseInt(r.quiz ? r.quiz.time_limit : 0) || 0;
      secsLeft = tLim * 60;
    }

    if(secsLeft > 0 && tEl){
      tEl.style.display = 'inline-flex';
      if(_timerInt) clearInterval(_timerInt);
      
      var renderTimerDisplay = function(){
        var m = Math.floor(secsLeft / 60);
        var s = secsLeft % 60;
        var mStr = (m < 10 ? '0' : '') + m;
        var sStr = (s < 10 ? '0' : '') + s;
        tEl.innerHTML = '<i class="fa fa-clock-o timer-icon"></i> <span class="timer-text">' + mStr + ':' + sStr + '</span>';
        
        if (secsLeft <= 60) {
          tEl.className = 'timer-danger';
        } else if (secsLeft <= 300) {
          tEl.className = 'timer-warning';
        } else {
          tEl.className = '';
        }
      };

      renderTimerDisplay();

      _timerInt = setInterval(function(){
        secsLeft--;
        renderTimerDisplay();
        if(secsLeft <= 0){
          clearInterval(_timerInt);
          alert('Time is up! Submitting quiz...');
          submitQuizAnswers(true);
        }
      }, 1000);
    } else if(tEl) {
      tEl.style.display = 'none';
    }

    // Render questions (with pre-filled saved answers if resuming after disconnect/reload)
    var html = '';
    if(r.quiz && r.quiz.due_date){
      var d = new Date(r.quiz.due_date);
      var formattedDue = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
      html+='<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#991b1b;display:flex;align-items:center;gap:8px;">'
        +'<i class="fa fa-calendar-times-o" style="flex-shrink:0;"></i>'
        +'<span><strong>Expiration Deadline:</strong> '+formattedDue+' (Submissions not allowed after this time)</span>'
        +'</div>';
    }
    _quizQuestions.forEach(function(q, i){
      var qtype = String(q.question_type || 'multiple_choice').toLowerCase();
      var savedVal = _answers[q.id];
      html += '<div class="quiz-q-block" id="qblock_'+q.id+'">'
        + '<div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:8px;">'
        + '<span style="width:26px;height:26px;border-radius:7px;background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;font-size:11px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;">'+(i+1)+'</span>'
        + '<div style="flex:1;">'
        + '<div class="quiz-q-text">'+escapeCqHtml(q.question_text)+'</div>'
        + '<div class="quiz-q-pts"><i class="fa fa-star-o"></i> '+q.points+' pt'+(q.points!==1?'s':'')+'</div>'
        + '</div></div>';

      var opts = Array.isArray(q.options) ? q.options : [];

      if(qtype === 'true_false' || qtype === 'tf' || qtype === 'boolean'){
        var isTrueSel = (savedVal === 'true');
        var isFalseSel = (savedVal === 'false');
        html += '<div class="quiz-tf">'
          + '<div class="quiz-opt '+(isTrueSel?'selected':'')+'" onclick="selectOpt(this,'+q.id+')" data-qid="'+q.id+'" data-val="true" style="justify-content:center;gap:8px;"><i class="fa fa-check" style="color:#10b981;"></i> <strong>True</strong></div>'
          + '<div class="quiz-opt '+(isFalseSel?'selected':'')+'" onclick="selectOpt(this,'+q.id+')" data-qid="'+q.id+'" data-val="false" style="justify-content:center;gap:8px;"><i class="fa fa-times" style="color:#ef4444;"></i> <strong>False</strong></div>'
          + '</div>';
      } else if(qtype === 'multiple_choice' && opts.length > 0){
        opts.forEach(function(opt, oi){
          var strOpt = String(opt || '');
          var isOptSel = (savedVal === strOpt);
          html += '<div class="quiz-opt '+(isOptSel?'selected':'')+'" onclick="selectOpt(this,'+q.id+')" data-qid="'+q.id+'" data-val="'+escapeCqAttr(strOpt)+'">'
            + '<span style="width:22px;height:22px;border-radius:50%;border:2px solid #d1d5db;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;font-size:10px;font-weight:700;color:#94a3b8;">'+String.fromCharCode(65+oi)+'</span>'
            + '<span style="flex:1;">'+escapeCqHtml(strOpt)+'</span>'
            + '</div>';
        });
      } else if(qtype === 'modified_true_false'){
        html += '<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:9px;padding:10px 13px;margin-bottom:6px;font-size:12px;color:#92400e;"><i class="fa fa-info-circle"></i> If True, write True. If False, write: False — [corrected word]</div><input type="text" class="quiz-id-input" value="'+escapeCqAttr(savedVal || '')+'" placeholder="e.g. True  or  False — corrected word" oninput="updateIdAnswer('+q.id+',this.value)" data-qid="'+q.id+'">';
      } else if(qtype === 'enumeration'){
        html += '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:10px 13px;margin-bottom:6px;font-size:12px;color:#1d4ed8;"><i class="fa fa-info-circle"></i> List items separated by commas</div><input type="text" class="quiz-id-input" value="'+escapeCqAttr(savedVal || '')+'" placeholder="item1, item2, item3" oninput="updateIdAnswer('+q.id+',this.value)" data-qid="'+q.id+'">';
      } else if(qtype === 'essay'){
        html += '<textarea class="quiz-id-input" rows="5" placeholder="Write your essay answer..." oninput="updateIdAnswer('+q.id+',this.value)" data-qid="'+q.id+'" style="resize:vertical;min-height:100px;">'+escapeCqHtml(savedVal || '')+'</textarea>';
      } else {
        if(qtype === 'multiple_choice' && opts.length === 0){
          var isTrueSel = (savedVal === 'true');
          var isFalseSel = (savedVal === 'false');
          html += '<div class="quiz-tf">'
            + '<div class="quiz-opt '+(isTrueSel?'selected':'')+'" onclick="selectOpt(this,'+q.id+')" data-qid="'+q.id+'" data-val="true" style="justify-content:center;gap:8px;"><i class="fa fa-check" style="color:#10b981;"></i> <strong>True</strong></div>'
            + '<div class="quiz-opt '+(isFalseSel?'selected':'')+'" onclick="selectOpt(this,'+q.id+')" data-qid="'+q.id+'" data-val="false" style="justify-content:center;gap:8px;"><i class="fa fa-times" style="color:#ef4444;"></i> <strong>False</strong></div>'
            + '</div>';
        } else {
          html += '<input type="text" class="quiz-id-input" value="'+escapeCqAttr(savedVal || '')+'" placeholder="Type your answer here..." oninput="updateIdAnswer('+q.id+',this.value)" data-qid="'+q.id+'">';
        }
      }
      html += '</div>';
    });

    bodyEl.innerHTML = html;
    footEl.style.display = 'flex';
  }, 'json');
}

function selectOpt(el, qid){
  document.querySelectorAll('.quiz-opt[data-qid="'+qid+'"]').forEach(function(o){ o.classList.remove('selected'); });
  el.classList.add('selected');
  var val = el.getAttribute('data-val');
  if(val !== null && val !== undefined) {
    _answers[qid] = val;
  }
  updateQuizProgress();
  saveDraftAnswers();
}

function updateIdAnswer(qid, val){
  var trimmed = (val || '').trim();
  if(trimmed.length > 0){
    _answers[qid] = trimmed;
  } else {
    delete _answers[qid];
  }
  updateQuizProgress();
  saveDraftAnswers();
}

function saveDraftAnswers(){
  if(_quizId && _answers){
    $.post('../shared/quiz_handler.php', {
      action: 'save_draft',
      quiz_id: _quizId,
      answers: JSON.stringify(_answers)
    });
  }
}

function updateQuizProgress(){
  var answeredCount = Object.keys(_answers).length;
  var totalCount = _quizQuestions ? _quizQuestions.length : 0;
  var pEl = document.getElementById('quizProgress');
  if(pEl){
    pEl.innerHTML = '<i class="fa fa-pencil-square-o"></i> ' + answeredCount + '/' + totalCount + ' Answered';
  }
}

function escapeCqHtml(str){
  return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function escapeCqAttr(str){
  return String(str||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

document.getElementById('btnSubmitQuiz').addEventListener('click', function(){
  submitQuizAnswers(false);
});

function submitQuizAnswers(auto){
  if(!auto){
    var answeredCount = Object.keys(_answers).length;
    var totalCount = _quizQuestions ? _quizQuestions.length : 0;
    var unanswered = totalCount - answeredCount;
    var confirmMsg = "Are you sure you want to submit your quiz answers?";
    if(unanswered > 0){
      confirmMsg = "Warning: You have " + unanswered + " unanswered question(s) out of " + totalCount + "!\n\nAre you sure you want to submit now?";
    }
    if(!confirm(confirmMsg)) return;
  }
  if(_timerInt) clearInterval(_timerInt);
  var btn = document.getElementById('btnSubmitQuiz');
  btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting...';

  $.post('../shared/quiz_handler.php', {
    action: 'submit',
    quiz_id: _quizId,
    answers: JSON.stringify(_answers),
    tab_switches: _tabSwitches,
    fullscreen_exits: _fsExits
  }, function(r){
    btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Submit Quiz';
    if(r.success){
      alert('Quiz submitted! Your score: ' + r.score + ' / ' + r.total);
      closeQuizModalDirect();
    } else {
      alert(r.msg || 'Submission failed');
    }
  }, 'json');
}

// Anti-Cheat Tab Switch Detection (Max 3 Allowed Violations)
document.addEventListener('visibilitychange', function() {
  if (document.hidden && document.getElementById('takeQuizModal') && document.getElementById('takeQuizModal').style.display === 'flex') {
    _tabSwitches++;
    var vBar = document.getElementById('quizViolationBar');
    var vMsg = document.getElementById('quizViolationMsg');
    if (vBar && vMsg) {
      vMsg.textContent = 'Warning: Tab switch or page reload detected! (' + _tabSwitches + ' of 3 allowed violations recorded)';
      vBar.style.display = 'flex';
    }
    $.post('../shared/quiz_handler.php', { action: 'log_violation', quiz_id: _quizId }, function(res){
      if(res && res.limit_reached){
        alert('Maximum 3 violations reached! Your quiz is automatically being submitted.');
        submitQuizAnswers(true);
      }
    }, 'json');
  }
});
<?php if(isset($_GET['take'])): ?>
$(document).ready(function(){
  takeQuiz(<?php echo intval($_GET['take']); ?>);
});
<?php endif; ?>
</script>
</body>
</html>
