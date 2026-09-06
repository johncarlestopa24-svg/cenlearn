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
        WHERE cm.user_code='$uc'
          AND (c.is_subject_only = 0 OR c.is_subject_only IS NULL)
          AND (c.is_archived = 0 OR c.is_archived IS NULL)
          AND ls.status='live' LIMIT 3
    ");
    $out = [];
    if($liveSessions) {
        while($ls = $liveSessions->fetch_assoc()) {
            $out[] = $ls;
        }
    }
    echo json_encode($out); exit;
}

// Student metrics
$enrolledCount = 0;
$c_res = $conn->query("
    SELECT COUNT(*) AS total
    FROM class_members cm
    JOIN classes c ON cm.class_id = c.id
    WHERE cm.user_code='$uc'
      AND (c.is_subject_only = 0 OR c.is_subject_only IS NULL)
      AND (c.is_archived = 0 OR c.is_archived IS NULL)
");
if($c_res && $r = $c_res->fetch_assoc()) $enrolledCount = intval($r['total']);

$quizCount = 0;
$q_res = $conn->query("SELECT COUNT(DISTINCT quiz_id) AS total FROM quiz_submissions WHERE student_code='$uc'");
if($q_res && $r = $q_res->fetch_assoc()) $quizCount = intval($r['total']);

// Fetch ML recommendations & study plan data
$studRecs = cenlearn_student_recommendations($conn, $uc);
$topicRecs = cenlearn_topic_recommendations($conn, $uc);

$weakTopics = $topicRecs['weak_topics'] ?? [];
$weakTopicCount = count($weakTopics);

// Collect recommended modules from topic recommendations & assignment recommendations
$recModules = [];
if (!empty($topicRecs['recommendations'])) {
    foreach ($topicRecs['recommendations'] as $tr) {
        if (!empty($tr['primary_module'])) {
            $mId = $tr['primary_module']['id'];
            if (!isset($recModules[$mId])) {
                $recModules[$mId] = $tr['primary_module'];
            }
        }
        if (!empty($tr['modules'])) {
            foreach ($tr['modules'] as $m) {
                $mId = $m['id'];
                if (!isset($recModules[$mId])) {
                    $recModules[$mId] = $m;
                }
            }
        }
    }
}
$recModuleCount = count($recModules);
$overallPerf = $topicRecs['overall_performance'] ?? [];
$aiActionRecs = $studRecs['recommendations'] ?? [];
$topicRecList = $topicRecs['recommendations'] ?? [];

// Auto-archive study plan snapshot into history when recommendations exist
if (!empty($topicRecList) || !empty($aiActionRecs)) {
    $conn->query("CREATE TABLE IF NOT EXISTS `student_study_plan_history` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `student_code` varchar(50) NOT NULL,
        `overall_risk` varchar(30) NOT NULL DEFAULT 'on_track',
        `risk_score` int(11) NOT NULL DEFAULT 0,
        `recommendations_json` text DEFAULT NULL,
        `topic_plans_json` text DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `student_code` (`student_code`),
        KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $lastHistRes = $conn->query("SELECT created_at, risk_score FROM student_study_plan_history WHERE student_code='$uc' ORDER BY created_at DESC LIMIT 1");
    $shouldArchive = true;
    $currScore = intval($studRecs['risk_score'] ?? 0);
    if ($lastHistRes && $lh = $lastHistRes->fetch_assoc()) {
        $lastTime = strtotime($lh['created_at']);
        if ((time() - $lastTime < 14400) && intval($lh['risk_score']) === $currScore) {
            $shouldArchive = false;
        }
    }
    if ($shouldArchive) {
        $riskStr = $conn->real_escape_string($studRecs['overall_risk'] ?? 'on_track');
        $recsJson = $conn->real_escape_string(json_encode($aiActionRecs));
        $plansJson = $conn->real_escape_string(json_encode($topicRecList));
        $conn->query("INSERT INTO student_study_plan_history (student_code, overall_risk, risk_score, recommendations_json, topic_plans_json) VALUES ('$uc', '$riskStr', $currScore, '$recsJson', '$plansJson')");
    }
}

// Fetch historical study plans
$studyPlanHistory = [];
$sphRes = $conn->query("SELECT * FROM student_study_plan_history WHERE student_code='$uc' ORDER BY created_at DESC LIMIT 20");
if ($sphRes && $sphRes->num_rows > 0) {
    while ($sph = $sphRes->fetch_assoc()) {
        $sph['recommendations'] = json_decode($sph['recommendations_json'] ?? '[]', true);
        $sph['topic_plans'] = json_decode($sph['topic_plans_json'] ?? '[]', true);
        $studyPlanHistory[] = $sph;
    }
}

// Fetch published grades
$publishedGrades = $conn->query("
    SELECT pg.*, c.class_name, c.class_code
    FROM published_grades pg JOIN classes c ON pg.class_id=c.id
    WHERE pg.student_code='$uc'
      AND (c.is_subject_only = 0 OR c.is_subject_only IS NULL)
      AND (c.is_archived = 0 OR c.is_archived IS NULL)
    ORDER BY pg.published_at DESC LIMIT 5
");

// Fetch recent quizzes
$recentQuizzes = $conn->query("
    SELECT q.id, q.title, c.class_name, q.total_marks, qs.score, qs.submitted_at
    FROM quiz_submissions qs
    JOIN quizzes q ON qs.quiz_id = q.id
    JOIN classes c ON q.class_id = c.id
    WHERE qs.student_code = '$uc'
      AND (c.is_subject_only = 0 OR c.is_subject_only IS NULL)
      AND (c.is_archived = 0 OR c.is_archived IS NULL)
    ORDER BY qs.submitted_at DESC LIMIT 4
");

$fullName = trim(($user['first_name'] ?? 'Student') . ' ' . ($user['last_name'] ?? 'User'));
$initials = strtoupper(substr($user['first_name'] ?? 'S', 0, 1) . substr($user['last_name'] ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CenLearn — Student Dashboard</title>
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
    .header-search { position: relative; width: 360px; max-width: 100%; }
    .header-search input {
      width: 100%; height: 42px; padding: 0 16px 0 42px; border-radius: 99px;
      border: 1px solid #f1f5f9; background: #f8fafc; font-size: 13px; color: #1e293b;
      outline: none; transition: all 0.2s; font-family: 'Inter', sans-serif;
    }
    .header-search input:focus { background: #ffffff; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
    .header-search i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; }

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
      border-radius: 20px; padding: 28px 36px; color: #ffffff;
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

    .card-box {
      background: #ffffff; border-radius: 18px; border: 1px solid #e2e8f0;
      padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.02);
    }
    .card-box-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
    .card-box-header h3 { margin: 0; font-size: 17px; font-weight: 800; color: #0f172a; }

    .rec-item {
      background: #f8fafc; border-radius: 14px; border: 1px solid #e2e8f0;
      padding: 16px; margin-bottom: 12px; display: flex; align-items: center;
      justify-content: space-between; gap: 14px; transition: all 0.2s ease;
    }
    .rec-item:hover { border-color: #cbd5e1; box-shadow: 0 4px 12px rgba(0,0,0,0.04); }
    .rec-item-title { font-weight: 700; font-size: 14px; color: #0f172a; margin-bottom: 2px; }
    .rec-item-sub { font-size: 12px; color: #64748b; }
    .btn-rec {
      padding: 8px 18px; border-radius: 99px; background: #2563eb; color: #fff;
      font-size: 12px; font-weight: 700; text-decoration: none; border: none;
      display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0;
    }
    .btn-rec:hover { background: #1d4ed8; color: #fff; text-decoration: none; }

    /* AI Study Plan Styles */
    .study-plan-card {
      background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0;
      padding: 20px; margin-bottom: 16px; position: relative;
    }
    .study-plan-card.danger { border-left: 4px solid #ef4444; }
    .study-plan-card.warning { border-left: 4px solid #f59e0b; }
    .study-plan-card.success { border-left: 4px solid #10b981; }

    .sp-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .sp-title { font-size: 15px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px; }
    .sp-badge { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 99px; text-transform: uppercase; letter-spacing: 0.3px; }
    .sp-badge.danger { background: #fee2e2; color: #991b1b; }
    .sp-badge.warning { background: #fffbeb; color: #92400e; }
    .sp-badge.success { background: #dcfce7; color: #166534; }

    .sp-desc { font-size: 13px; color: #334155; line-height: 1.5; margin-bottom: 16px; }

    .sp-phases { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 14px; background: #f8fafc; padding: 14px; border-radius: 12px; border: 1px solid #f1f5f9; }
    @media (max-width: 850px) { .sp-phases { grid-template-columns: 1fr; } }

    .sp-phase { background: #ffffff; padding: 12px 14px; border-radius: 10px; border: 1px solid #e2e8f0; font-size: 12px; }
    .sp-phase-num { font-weight: 800; color: #2563eb; font-size: 11px; text-transform: uppercase; margin-bottom: 4px; display: block; }
    .sp-phase-action { font-weight: 600; color: #1e293b; margin-bottom: 8px; line-height: 1.4; }
    .sp-phase-link { font-size: 11.5px; font-weight: 700; color: #2563eb; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
    .sp-phase-link:hover { text-decoration: underline; }
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
      <li class="active"><a href="dashboard"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li><a href="classes"><i class="fa fa-book"></i> My Classes</a></li>
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

<div class="app-main">
  <header class="top-header">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu" style="background:none;border:none;font-size:18px;color:#0f172a;cursor:pointer;">
        <i class="fa fa-bars"></i>
      </button>

      <div class="header-search">
        <i class="fa fa-search"></i>
        <input type="text" placeholder="Search classes, quizzes, or subjects…">
      </div>
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
    <div id="liveBannerContainer"></div>



    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-book"></i></div>
          <div class="stat-card-info">
            <label>Enrolled Classes</label>
            <strong><?php echo $enrolledCount; ?></strong>
          </div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#dcfce7;color:#15803d;"><i class="fa fa-question-circle"></i></div>
          <div class="stat-card-info">
            <label>Quizzes Taken</label>
            <strong><?php echo $quizCount; ?></strong>
          </div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#fffbe6;color:#d97706;"><i class="fa fa-bullseye"></i></div>
          <div class="stat-card-info">
            <label>Weak Topics</label>
            <strong><?php echo $weakTopicCount; ?></strong>
          </div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#f3e8ff;color:#9333ea;"><i class="fa fa-lightbulb-o"></i></div>
          <div class="stat-card-info">
            <label>Recommended Modules</label>
            <strong><?php echo $recModuleCount; ?></strong>
          </div>
        </div>
      </div>
    </div>

    <!-- AI Personal Study Plan & Learning Recommendations -->
    <?php if (!empty($topicRecList) || !empty($aiActionRecs) || !empty($recModules)): ?>
    <div class="card-box" style="border: 1px solid #cbd5e1; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);">
      <div class="card-box-header">
        <div>
          <h3 style="display:flex; align-items:center; gap:8px;">
            <i class="fa fa-graduation-cap" style="color:#2563eb; font-size:18px;"></i>
            Study Plan & Learning Recommendation
          </h3>
          <p style="margin:4px 0 0; font-size:12.5px; color:#64748b; font-weight:500;">
            Personalized Bloom's Taxonomy 3-Phase Study Plan & AI Remediation Pathway
          </p>
        </div>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
          <button type="button" class="btn" data-toggle="modal" data-target="#studyPlanHistoryModal" style="background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; font-size:12px; font-weight:700; padding:6px 14px; border-radius:8px; display:inline-flex; align-items:center; gap:6px; cursor:pointer; transition:all 0.15s ease-in-out;">
            <i class="fa fa-history" style="font-size:13px;"></i> View Old Study Plans
          </button>
          <?php if (!empty($overallPerf['badge'])): ?>
          <span class="badge" style="background:<?php echo $overallPerf['bg'] ?? '#eff6ff'; ?>; color:<?php echo $overallPerf['color'] ?? '#2563eb'; ?>; border:1px solid <?php echo $overallPerf['border'] ?? '#bfdbfe'; ?>; padding:6px 12px; font-size:12px; font-weight:700;">
            <i class="fa <?php echo $overallPerf['icon'] ?? 'fa-check-circle'; ?>"></i> <?php echo htmlspecialchars($overallPerf['badge']); ?>
          </span>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($overallPerf['message'])): ?>
      <div style="background:#ffffff; border-radius:14px; border:1px solid #e2e8f0; padding:16px; margin-bottom:18px; line-height:1.5; font-size:13px; color:#334155;">
        <?php echo $overallPerf['message']; ?>
      </div>
      <?php endif; ?>

      <!-- Actionable Learning Recommendations -->
      <?php if (!empty($aiActionRecs)): ?>
      <div style="margin-bottom:18px;">
        <h4 style="font-size:14px; font-weight:800; color:#0f172a; margin:0 0 10px; display:flex; align-items:center; gap:6px;">
          <i class="fa fa-lightbulb-o" style="color:#f59e0b;"></i> Actionable Learning Goals
        </h4>
        <?php foreach ($aiActionRecs as $recItem): ?>
        <div class="rec-item" style="border-left: 4px solid <?php echo $recItem['type'] === 'danger' ? '#ef4444' : ($recItem['type'] === 'warning' ? '#f59e0b' : '#3b82f6'); ?>;">
          <div style="display:flex; align-items:flex-start; gap:12px;">
            <div style="width:34px; height:34px; border-radius:10px; background:<?php echo $recItem['type'] === 'danger' ? '#fee2e2' : ($recItem['type'] === 'warning' ? '#fffbeb' : '#eff6ff'); ?>; color:<?php echo $recItem['type'] === 'danger' ? '#dc2626' : ($recItem['type'] === 'warning' ? '#d97706' : '#2563eb'); ?>; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:14px;">
              <i class="fa <?php echo htmlspecialchars($recItem['icon'] ?? 'fa-info-circle'); ?>"></i>
            </div>
            <div>
              <div class="rec-item-title"><?php echo htmlspecialchars($recItem['title'] ?? 'Recommendation'); ?></div>
              <div class="rec-item-sub" style="font-size:12.5px; line-height:1.45;"><?php echo $recItem['desc'] ?? ''; ?></div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- 3-Phase ML Topic Study Plans -->
      <?php if (!empty($topicRecList)): ?>
      <div style="margin-bottom:18px;">
        <h4 style="font-size:14px; font-weight:800; color:#0f172a; margin:0 0 12px; display:flex; align-items:center; gap:6px;">
          <i class="fa fa-bullseye" style="color:#ef4444;"></i> Targeted Topic Mastery & Remediation Plan
        </h4>
        <?php foreach ($topicRecList as $tr): ?>
        <?php $stdRef = $tr['standard_ref'] ?? []; ?>
        <div class="study-plan-card <?php echo $tr['type'] ?? 'warning'; ?>">
          <div class="sp-header">
            <div class="sp-title">
              <i class="fa <?php echo htmlspecialchars($tr['icon'] ?? 'fa-book'); ?>"></i>
              <?php echo $tr['title'] ?? 'Topic Plan'; ?>
            </div>
            <span class="sp-badge <?php echo $tr['type'] ?? 'warning'; ?>">
              <?php echo htmlspecialchars($tr['priority'] ?? 'Priority'); ?> Priority
            </span>
          </div>

          <div class="sp-desc"><?php echo $tr['desc'] ?? ''; ?></div>

          <?php if (!empty($stdRef['remedial_steps'])): ?>
          <div class="sp-phases">
            <?php foreach ($stdRef['remedial_steps'] as $phaseKey => $step): ?>
            <div class="sp-phase">
              <span class="sp-phase-num"><?php echo htmlspecialchars($step['phase'] ?? 'Phase'); ?></span>
              <div class="sp-phase-action"><?php echo htmlspecialchars($step['action'] ?? ''); ?></div>
              <?php if ($phaseKey === 'phase_1' && !empty($tr['primary_module'])): ?>
              <a href="/cenlearn/shared/module_view?id=<?php echo intval($tr['primary_module']['id']); ?>" class="sp-phase-link">
                <i class="fa fa-file-text-o"></i> View Module (<?php echo htmlspecialchars($tr['primary_module']['title']); ?>) <i class="fa fa-arrow-right"></i>
              </a>
              <?php elseif ($phaseKey === 'phase_2'): ?>
              <a href="quizzes" class="sp-phase-link"><i class="fa fa-question-circle"></i> Review Assessments <i class="fa fa-arrow-right"></i></a>
              <?php elseif ($phaseKey === 'phase_3'): ?>
              <a href="quizzes" class="sp-phase-link"><i class="fa fa-trophy"></i> Practice Target &ge;75% <i class="fa fa-arrow-right"></i></a>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Recommended Learning Modules List -->
      <?php if (!empty($recModules)): ?>
      <div>
        <h4 style="font-size:14px; font-weight:800; color:#0f172a; margin:0 0 12px; display:flex; align-items:center; gap:6px;">
          <i class="fa fa-magic" style="color:#8b5cf6;"></i> Mapped Teacher Learning Modules
        </h4>
        <?php foreach ($recModules as $mod): ?>
        <div class="rec-item">
          <div>
            <div class="rec-item-title"><?php echo htmlspecialchars($mod['title'] ?? 'Study Module'); ?></div>
            <div class="rec-item-sub">Class: <?php echo htmlspecialchars($mod['class_name'] ?? 'Class'); ?> &bull; Recommended to strengthen topic mastery</div>
          </div>
          <a href="/cenlearn/shared/module_view?id=<?php echo intval($mod['id'] ?? 0); ?>" class="btn-rec">View Module <i class="fa fa-chevron-right"></i></a>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Published Grades -->
    <div class="card-box">
      <div class="card-box-header">
        <h3><i class="fa fa-trophy" style="color:#f59e0b;margin-right:8px;"></i> Recent Grades Released</h3>
        <a href="grades" style="font-size:12.5px;font-weight:700;color:#2563eb;text-decoration:none;">View Full Gradebook <i class="fa fa-chevron-right"></i></a>
      </div>
      <?php if($publishedGrades && $publishedGrades->num_rows > 0): ?>
      <table class="table" style="margin:0;">
        <thead>
          <tr style="background:#f8fafc;color:#64748b;font-size:12px;">
            <th>Class</th>
            <th>Term</th>
            <th>Raw Score</th>
            <th>Transmuted</th>
            <th>Remarks</th>
          </tr>
        </thead>
        <tbody>
          <?php while($pg = $publishedGrades->fetch_assoc()): ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($pg['class_name']); ?></strong></td>
            <td><?php echo ucfirst($pg['term']); ?></td>
            <td><?php echo $pg['grade']; ?>%</td>
            <td><strong style="color:#2563eb;"><?php echo $pg['transmuted']; ?></strong></td>
            <td><span class="badge" style="background:<?php echo $pg['remarks']==='Passed'?'#dcfce7':'#fee2e2'; ?>;color:<?php echo $pg['remarks']==='Passed'?'#166534':'#991b1b'; ?>;"><?php echo htmlspecialchars($pg['remarks']); ?></span></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
      <?php else: ?>
      <p style="color:#64748b;font-size:13px;margin:0;text-align:center;padding:16px;">No published grades available yet.</p>
      <?php endif; ?>
    </div>
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
setInterval(function(){
  $.getJSON('dashboard', {action: 'get_live_sessions'}, function(sessions){
    var html = '';
    if(sessions && sessions.length > 0){
      sessions.forEach(function(ls){
        html += '<div style="background:#ef4444;color:#fff;padding:12px 20px;border-radius:14px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">'
             +  '  <div><strong>🔴 Online Class Session:</strong> ' + ls.title + ' (' + ls.class_name + ')</div>'
             +  '  <a href="/cenlearn/shared/live_class?id=' + ls.class_id + '" style="background:#fff;color:#ef4444;padding:6px 14px;border-radius:99px;font-weight:700;text-decoration:none;">Join Online Class</a>'
             +  '</div>';
      });
    }
    $('#liveBannerContainer').html(html);
  });
}, 10000);
</script>

<!-- Study Plan & Learning Recommendation History Modal -->
<div class="modal fade" id="studyPlanHistoryModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document" style="max-width:850px;">
    <div class="modal-content" style="border:none; border-radius:16px; overflow:hidden; box-shadow:0 24px 64px rgba(0,0,0,0.18);">
      <div class="modal-header" style="background:linear-gradient(135deg, #1e40af, #2563eb); color:#ffffff; padding:18px 24px; border:none; display:flex; align-items:center; justify-content:space-between;">
        <h4 class="modal-title" style="margin:0; font-size:16px; font-weight:800; display:flex; align-items:center; gap:10px;">
          <i class="fa fa-history" style="font-size:18px; color:#93c5fd;"></i> Study Plan & Learning Recommendation History
        </h4>
        <button type="button" class="close" data-dismiss="modal" style="color:#ffffff; opacity:0.85; font-size:22px; border:none; background:none;">&times;</button>
      </div>
      <div class="modal-body" style="padding:24px; max-height:75vh; overflow-y:auto; background:#f8fafc;">
        <?php if (!empty($studyPlanHistory)): ?>
          <div style="font-size:13px; color:#64748b; margin-bottom:16px; font-weight:500;">
            Review your previously generated 3-Phase Bloom's Taxonomy study plans and recommendations over time.
          </div>
          <?php foreach ($studyPlanHistory as $idx => $histItem): ?>
            <?php 
              $histDate = date('F j, Y - g:i A', strtotime($histItem['created_at']));
              $histRisk = strtolower($histItem['overall_risk'] ?? 'on_track');
              $histScore = intval($histItem['risk_score'] ?? 0);
              $badgeBg = $histRisk === 'high_risk' ? '#fee2e2' : ($histRisk === 'at_risk' ? '#ffedd5' : ($histRisk === 'attention' ? '#fef3c7' : '#dcfce7'));
              $badgeColor = $histRisk === 'high_risk' ? '#991b1b' : ($histRisk === 'at_risk' ? '#9a3412' : ($histRisk === 'attention' ? '#92400e' : '#166534'));
              $badgeText = $histRisk === 'high_risk' ? 'High Risk' : ($histRisk === 'at_risk' ? 'At Risk' : ($histRisk === 'attention' ? 'Needs Attention' : 'On Track'));
            ?>
            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:18px; margin-bottom:16px; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
              <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:12px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
                <div style="display:flex; align-items:center; gap:8px;">
                  <span style="background:#f1f5f9; color:#475569; font-size:11px; font-weight:800; padding:4px 10px; border-radius:6px;">
                    Snapshot #<?php echo count($studyPlanHistory) - $idx; ?>
                  </span>
                  <span style="font-size:12.5px; color:#64748b; font-weight:600;">
                    <i class="fa fa-calendar" style="margin-right:4px;"></i> <?php echo $histDate; ?>
                  </span>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                  <span class="badge" style="background:<?php echo $badgeBg; ?>; color:<?php echo $badgeColor; ?>; font-size:11.5px; padding:5px 10px; border-radius:6px; font-weight:700;">
                    <?php echo $badgeText; ?> (Health Score: <?php echo max(0, 100 - $histScore); ?>/100)
                  </span>
                </div>
              </div>

              <!-- Recommendations in this snapshot -->
              <?php if (!empty($histItem['recommendations'])): ?>
              <div style="margin-bottom:12px;">
                <strong style="font-size:12px; text-transform:uppercase; color:#94a3b8; letter-spacing:0.5px; display:block; margin-bottom:6px;">Historical Learning Goals</strong>
                <?php foreach ($histItem['recommendations'] as $rec): ?>
                <div style="font-size:12.5px; color:#334155; padding:6px 10px; background:#f8fafc; border-left:3px solid #3b82f6; border-radius:4px; margin-bottom:6px;">
                  <strong><?php echo htmlspecialchars($rec['title'] ?? 'Goal'); ?>:</strong> <?php echo $rec['desc'] ?? ''; ?>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <!-- Topic Plans in this snapshot -->
              <?php if (!empty($histItem['topic_plans'])): ?>
              <div>
                <strong style="font-size:12px; text-transform:uppercase; color:#94a3b8; letter-spacing:0.5px; display:block; margin-bottom:6px;">Historical Topic Remediation Plans</strong>
                <?php foreach ($histItem['topic_plans'] as $tp): ?>
                <div style="font-size:12.5px; color:#1e293b; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px 12px; margin-bottom:6px;">
                  <div style="font-weight:700; color:#166534;"><i class="fa fa-book" style="margin-right:6px;"></i> <?php echo $tp['title'] ?? 'Topic Remediation'; ?></div>
                  <div style="font-size:12px; color:#374151; margin-top:4px;"><?php echo $tp['desc'] ?? ''; ?></div>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div style="text-align:center; padding:40px 20px; color:#64748b;">
            <i class="fa fa-info-circle fa-2x" style="color:#94a3b8; margin-bottom:10px; display:block;"></i>
            <h5 style="font-size:15px; font-weight:700; color:#334155; margin:0 0 6px;">No Previous History Yet</h5>
            <p style="font-size:13px; margin:0;">As your learning progress and topic scores update, past study plans will be saved here automatically.</p>
          </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer" style="padding:14px 24px; background:#ffffff; border-top:1px solid #f1f5f9; display:flex; justify-content:flex-end;">
        <button type="button" class="btn" data-dismiss="modal" style="padding:8px 20px; background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer;">Close</button>
      </div>
    </div>
  </div>
</div>

<?php include '../includes/student_profile_modal.php'; ?>
</body>
</html>
