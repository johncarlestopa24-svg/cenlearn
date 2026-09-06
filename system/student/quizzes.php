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
    WHERE cm.user_code = '$uc' AND (c.is_archived = 0 OR c.is_archived IS NULL) AND (c.is_subject_only = 0 OR c.is_subject_only IS NULL)
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
               COALESCE(qs.id, qs_same.id) AS sub_id,
               COALESCE(qs.score, qs_same.score) AS score,
               COALESCE(qs.total_points, qs_same.total_points) AS total_points,
               COALESCE(qs.submitted_at, qs_same.submitted_at) AS submitted_at
        FROM quizzes q
        JOIN classes c ON q.class_id = c.id
        LEFT JOIN quiz_submissions qs ON qs.quiz_id = q.id AND qs.student_code = '$uc'
        LEFT JOIN (
            SELECT qs2.id, qs2.score, qs2.total_points, qs2.submitted_at, LOWER(TRIM(q2.title)) AS title_clean
            FROM quiz_submissions qs2
            JOIN quizzes q2 ON qs2.quiz_id = q2.id
            WHERE qs2.student_code = '$uc'
        ) qs_same ON qs_same.title_clean = LOWER(TRIM(q.title))
        WHERE q.is_active = 1 $whereClass
        GROUP BY q.id
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
$fullName = trim(($user['first_name'] ?? 'Student').' '.($user['last_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CenLearn &mdash; My Quizzes</title>
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
      border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 100;
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

    /* Class Cards List / Grid */
    /* ═══════════════ SLEEK, COMPACT & RESPONSIVE QUIZ CARDS ═══════════════ */
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

    /* Schedule & Info Column */
    .cc-schedule-col { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 160px; }
    .info-item { display: flex; align-items: center; gap: 8px; }
    .info-item i { color: #64748b; font-size: 13px; width: 14px; text-align: center; }
    .info-text label { display: block; font-size: 9.5px; font-weight: 700; color: #94a3b8; margin: 0; text-transform: uppercase; letter-spacing: 0.3px; }
    .info-text strong { display: block; font-size: 11.5px; font-weight: 700; color: #1e293b; }

    /* Action Column */
    .cc-action-col { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }

    .btn-view-class {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 7px 18px; border-radius: 99px; background: #2563eb;
      color: #ffffff; font-size: 12px; font-weight: 700; text-decoration: none;
      box-shadow: 0 3px 10px rgba(37,99,235,0.22); transition: all 0.18s; border: none;
      cursor: pointer;
    }
    .btn-view-class:hover { background: #1d4ed8; color: #ffffff; text-decoration: none; transform: translateY(-1px); }

    @media (max-width: 850px) {
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

    /* Take Quiz Modal Fullscreen */
    #quizViolationBar{display:none;background:#fef2f2;border-bottom:1px solid #fecaca;color:#991b1b;padding:8px 16px;font-size:12px;font-weight:600;align-items:center;gap:8px;}
    .quiz-q-block{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:14px;}
    .quiz-q-text{font-size:14px;font-weight:700;color:#0f172a;line-height:1.5;}
    .quiz-q-pts{font-size:11px;color:#8b5cf6;font-weight:700;margin-top:2px;}
    .quiz-opt{padding:14px 18px;border:1.5px solid #e2e8f0;border-radius:12px;margin-top:10px;cursor:pointer;font-size:14.5px;color:#1e293b;display:flex;align-items:center;gap:14px;transition:all .18s ease;background:#fff;user-select:none;}
    .quiz-opt:hover{border-color:#8b5cf6;background:#f5f3ff;}
    .quiz-opt.selected{border-color:#8b5cf6 !important;background:#f5f3ff !important;color:#4c1d95 !important;font-weight:700;box-shadow:0 2px 10px rgba(139,92,246,0.12);}
    .quiz-opt .quiz-opt-box{width:24px;height:24px;border-radius:6px;border:2px solid #cbd5e1;background:#fff;color:transparent;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;font-size:12.5px;font-weight:800;transition:all .15s ease;}
    .quiz-opt:hover .quiz-opt-box{border-color:#8b5cf6;}
    .quiz-opt.selected .quiz-opt-box{border-color:#8b5cf6 !important;background:#8b5cf6 !important;color:#fff !important;box-shadow:0 2px 6px rgba(139,92,246,0.35);}
    .quiz-opt.selected .quiz-opt-box i{color:#fff !important;opacity:1 !important;display:inline-block !important;}
    .quiz-opt .quiz-opt-circle{width:28px;height:28px;border-radius:50%;border:2px solid #cbd5e1;background:#fff;color:#64748b;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;font-size:12.5px;font-weight:800;transition:all .15s ease;}
    .quiz-opt:hover .quiz-opt-circle{border-color:#8b5cf6;color:#8b5cf6;}
    .quiz-opt.selected .quiz-opt-circle{border-color:#8b5cf6 !important;background:#8b5cf6 !important;color:#fff !important;box-shadow:0 2px 6px rgba(139,92,246,0.35);}
    .quiz-id-input{width:100%;padding:12px 16px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;margin-top:8px;font-family:'Inter',sans-serif;outline:none;}
    .quiz-id-input:focus{border-color:#8b5cf6;}
    .quiz-tf{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px;}

    /* High-Visibility Student Quiz Timer */
    #quizTimer {
      display: none; align-items: center; gap: 7px; padding: 6px 14px; border-radius: 30px;
      font-size: 15px; font-weight: 800; font-family: 'Inter', -apple-system, monospace;
      letter-spacing: 0.5px; background: #ffffff; color: #6d28d9;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18), 0 0 0 2px rgba(255, 255, 255, 0.4);
      transition: all 0.25s ease; white-space: nowrap; user-select: none;
    }
    #quizTimer .timer-icon { font-size: 15px; color: #7c3aed; }
    #quizTimer .timer-text { font-size: 16px; font-weight: 800; font-family: 'Consolas', 'Courier New', monospace; letter-spacing: 1px; }
    #quizTimer.timer-warning { background: #fffbeb !important; color: #b45309 !important; box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35), 0 0 0 2px #f59e0b !important; }
    #quizTimer.timer-warning .timer-icon { color: #d97706 !important; }
    #quizTimer.timer-danger { background: #fef2f2 !important; color: #dc2626 !important; box-shadow: 0 4px 16px rgba(239, 68, 68, 0.45), 0 0 0 2px #ef4444 !important; animation: timerPulse 0.8s ease-in-out infinite; }
    #quizTimer.timer-danger .timer-icon { color: #dc2626 !important; }
    @keyframes timerPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.05); } }

    /* Fullscreen Quiz Modal Auto-Fit & Responsive */
    #takeQuizModal.cv-modal-overlay { position: fixed !important; inset: 0 !important; top: 0 !important; left: 0 !important; width: 100vw !important; height: 100vh !important; margin: 0 !important; padding: 0 !important; border: none !important; border-radius: 0 !important; background: #f8fafc !important; z-index: 999999 !important; display: none; flex-direction: column !important; align-items: stretch !important; justify-content: stretch !important; }
    #takeQuizModal .cv-modal { width: 100vw !important; max-width: 100vw !important; height: 100vh !important; max-height: 100vh !important; margin: 0 !important; padding: 0 !important; border-radius: 0 !important; border: none !important; display: flex !important; flex-direction: column !important; flex: 1 1 100% !important; background: #f8fafc !important; box-shadow: none !important; }
    #takeQuizModal .cv-modal-body { flex: 1 1 auto !important; overflow-y: auto !important; padding: 24px 20px !important; display: flex !important; flex-direction: column !important; align-items: center !important; width: 100% !important; }
    #takeQuizModal #quizQuestionCardArea { width: 100% !important; max-width: 980px !important; margin: 0 auto !important; position: relative !important; z-index: 10 !important; }
    .quiz-matching-tables-grid { display: grid !important; grid-template-columns: 1.25fr 1fr !important; gap: 18px !important; align-items: start !important; margin-top: 14px !important; }

    /* Stepper Header & Palette */
    .quiz-stepper-header { background: #ffffff; border-bottom: 1px solid #e2e8f0; padding: 10px 24px; display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; z-index: 15; }
    .quiz-palette-wrap { display: flex; align-items: center; gap: 7px; overflow-x: auto; padding: 4px 2px; scrollbar-width: thin; flex: 1; max-width: 100%; }
    .quiz-palette-btn { width: 32px; height: 32px; border-radius: 8px; border: 1.5px solid #cbd5e1; background: #f8fafc; color: #475569; font-size: 11.5px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; transition: all .15s; position: relative; font-family: 'Inter', sans-serif; }
    .quiz-palette-btn:hover { border-color: #8b5cf6; background: #f5f3ff; color: #6d28d9; }
    .quiz-palette-btn.current { border-color: #8b5cf6 !important; background: #8b5cf6 !important; color: #fff !important; box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.35) !important; font-weight: 800; }
    .quiz-palette-btn.answered { border-color: #10b981; background: #ecfdf5; color: #059669; }
    .quiz-palette-btn.answered.current { border-color: #059669 !important; background: #059669 !important; color: #fff !important; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.35) !important; }

    .quiz-single-card { background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 18px; padding: 28px 32px; max-width: 860px; margin: 0 auto; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04); position: relative; animation: fadeInCard .22s ease-out; }
    @keyframes fadeInCard { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

    .quiz-stepper-foot { padding: 14px 28px; background: #ffffff; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 16px; z-index: 20; }
    .btn-step-nav { padding: 10px 22px; border-radius: 10px; font-size: 13.5px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all .18s; border: 1.5px solid transparent; font-family: 'Inter', sans-serif; }
    .btn-step-prev { background: #f8fafc; border-color: #cbd5e1; color: #334155; }
    .btn-step-prev:hover:not(:disabled) { background: #e2e8f0; color: #0f172a; }
    .btn-step-prev:disabled { opacity: 0.4; cursor: not-allowed; }
    .btn-step-next { background: linear-gradient(135deg,#8b5cf6,#6d28d9); color: #fff; box-shadow: 0 3px 12px rgba(139, 92, 246, 0.35); }
    .btn-step-next:hover { opacity: 0.92; box-shadow: 0 4px 16px rgba(139, 92, 246, 0.45); }
    .btn-step-submit { background: linear-gradient(135deg,#10b981,#059669); color: #fff; box-shadow: 0 3px 12px rgba(16, 185, 129, 0.35); }
    .btn-step-submit:hover { opacity: 0.92; box-shadow: 0 4px 16px rgba(16, 185, 129, 0.45); }

    footer.t-footer{text-align:center;padding:20px;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0;background:#fff;margin-top:auto;}
  </style>
</head>
<body>

<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ── LEFT SIDEBAR ── -->
<aside class="app-sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="sb-brand-icon"><i class="fa fa-graduation-cap"></i></div>
    <div class="sb-brand-text">
      <h2>CenLearn</h2>
      <p>Learn &middot; Grow &middot; Succeed</p>
    </div>
  </div>
  <nav class="sb-nav">
    <ul>
      <li><a href="dashboard"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li><a href="classes"><i class="fa fa-book"></i> My Classes</a></li>
      <li class="active"><a href="quizzes"><i class="fa fa-question-circle"></i> Quizzes</a></li>
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

<!-- ── MAIN CONTENT AREA ── -->
<div class="app-main">
  <!-- Top Bar Header -->
  <header class="top-header">
    <div style="display:flex;align-items:center;gap:16px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu" style="background:none;border:none;font-size:18px;color:#0f172a;cursor:pointer;"><i class="fa fa-bars"></i></button>
      <div class="header-search">
        <i class="fa fa-search"></i>
        <input type="text" placeholder="Search for quizzes, topics, or subjects..." onkeyup="searchQuizzes(this.value)">
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

  <!-- Main Content Body -->
  <div class="content-body">

    <!-- 4-Grid Summary Stat Cards -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:rgba(139,92,246,0.1);color:#8b5cf6;">
            <i class="fa fa-question-circle"></i>
          </div>
          <div class="stat-card-info">
            <label>Total Quizzes</label>
            <strong><?php echo $totalQuizzes; ?></strong>
            <span>Assigned</span>
          </div>
        </div>
        <i class="fa fa-chevron-right stat-chevron"></i>
      </div>
      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:rgba(16,185,129,0.1);color:#10b981;">
            <i class="fa fa-play-circle"></i>
          </div>
          <div class="stat-card-info">
            <label>Available Now</label>
            <strong><?php echo $availableQuizzes; ?></strong>
            <span>Active &amp; Ready</span>
          </div>
        </div>
        <i class="fa fa-chevron-right stat-chevron"></i>
      </div>
      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:rgba(37,99,235,0.1);color:#2563eb;">
            <i class="fa fa-check-circle"></i>
          </div>
          <div class="stat-card-info">
            <label>Completed</label>
            <strong><?php echo $completedQuizzes; ?></strong>
            <span>Submitted</span>
          </div>
        </div>
        <i class="fa fa-chevron-right stat-chevron"></i>
      </div>
      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:rgba(245,158,11,0.1);color:#d97706;">
            <i class="fa fa-star"></i>
          </div>
          <div class="stat-card-info">
            <label>Average Grade</label>
            <strong><?php echo $avgPct; ?>%</strong>
            <span>Overall Score</span>
          </div>
        </div>
        <i class="fa fa-chevron-right stat-chevron"></i>
      </div>
    </div>

    <!-- Class Filter Selector -->
    <?php if(!empty($classes)): ?>
    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:18px;padding:18px 24px;margin-bottom:24px;box-shadow:0 2px 6px rgba(0,0,0,0.02);">
      <h4 style="font-size:13px;font-weight:700;color:#0f172a;margin:0 0 12px;display:flex;align-items:center;gap:8px;">
        <i class="fa fa-filter" style="color:#2563eb;"></i> Filter by Class
      </h4>
      <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <a href="quizzes" class="tab-pill <?php echo $classFilter===0?'active':''; ?>" style="text-decoration:none;">
          <i class="fa fa-th-large"></i> All Classes
        </a>
        <?php foreach($classes as $c): ?>
        <a href="quizzes?class_id=<?php echo $c['id']; ?>" class="tab-pill <?php echo $c['id']===$classFilter?'active':''; ?>" style="text-decoration:none;">
          <i class="fa fa-book"></i> <?php echo htmlspecialchars($c['class_name']); ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Section Header & Filter Tabs -->
    <div class="section-bar">
      <div class="section-title">
        <h2>Quizzes Overview</h2>
        <p>Select a quiz to attempt or review your completed test results</p>
      </div>
      <div class="filter-tabs">
        <button type="button" class="tab-pill active" onclick="filterQuizCards('all', this)">All (<?php echo count($quizzes); ?>)</button>
        <button type="button" class="tab-pill" onclick="filterQuizCards('available', this)">Available (<?php echo $availableQuizzes; ?>)</button>
        <button type="button" class="tab-pill" onclick="filterQuizCards('completed', this)">Completed (<?php echo $completedQuizzes; ?>)</button>
      </div>
    </div>

    <?php if(empty($quizzes)): ?>
    <div style="text-align:center;padding:56px 20px;background:#fff;border-radius:18px;border:1px solid #e2e8f0;margin-bottom:24px;">
      <i class="fa fa-inbox" style="font-size:40px;color:#cbd5e1;display:block;margin-bottom:12px;"></i>
      <h4 style="font-size:16px;font-weight:700;color:#64748b;margin:0 0 4px;">No Quizzes Available</h4>
      <p style="font-size:13px;color:#94a3b8;margin:0;">Your instructors have not posted any quizzes for your classes yet.</p>
    </div>
    <?php else: ?>

    <div class="class-cards-container" id="quizCardsGrid">
      <?php foreach($quizzes as $qz):
        $isSubmitted = !empty($qz['sub_id']);
        $isDue = $qz['due_date'] && strtotime($qz['due_date']) < time();
        $isUpcoming = !empty($qz['start_date']) && strtotime($qz['start_date']) > time();
        $cardCategory = $isSubmitted ? 'completed' : ($isDue ? 'closed' : ($isUpcoming ? 'scheduled' : 'available'));
      ?>
      <div class="class-card quiz-searchable-card" data-category="<?php echo $cardCategory; ?>" data-search="<?php echo htmlspecialchars(strtolower($qz['title'].' '.$qz['class_name'].' '.$qz['subject'])); ?>">
        <div class="cc-main-info">
          <div class="cc-thumb" style="background: linear-gradient(135deg, #1d4ed8, #2563eb);">
            <i class="fa fa-question-circle"></i>
          </div>
          <div class="cc-details">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px;">
              <?php if($isSubmitted): ?>
                <span class="status-tag" style="background:#e0f2fe;color:#0369a1;"><i class="fa fa-check-circle" style="margin-right:4px;"></i> Completed</span>
              <?php elseif($isUpcoming): ?>
                <span class="status-tag" style="background:#fef3c7;color:#b45309;"><i class="fa fa-clock-o" style="margin-right:4px;"></i> Upcoming</span>
              <?php elseif(!$isDue): ?>
                <span class="status-tag"><i class="fa fa-play" style="margin-right:4px;"></i> Active</span>
              <?php else: ?>
                <span class="status-tag" style="background:#f1f5f9;color:#64748b;"><i class="fa fa-times-circle" style="margin-right:4px;"></i> Closed</span>
              <?php endif; ?>
              <span style="font-size:11px;font-weight:700;color:#2563eb;background:#eff6ff;padding:2px 8px;border-radius:6px;">
                <i class="fa fa-book"></i> <?php echo htmlspecialchars($qz['class_name']); ?>
              </span>
            </div>
            <h3 class="cc-title"><?php echo htmlspecialchars($qz['title']); ?></h3>
            <span class="cc-sub"><?php echo htmlspecialchars($qz['subject'] ?? 'General Subject'); ?></span>
          </div>
        </div>

        <div class="cc-schedule-col">
          <div class="info-item">
            <i class="fa fa-list-ol"></i>
            <div class="info-text">
              <label>Questions &amp; Limit</label>
              <strong><?php echo $qz['q_count']; ?> Questions &middot; <?php echo $qz['time_limit'] ? $qz['time_limit'].' mins' : 'No Limit'; ?></strong>
            </div>
          </div>
          <div class="info-item">
            <i class="fa fa-calendar"></i>
            <div class="info-text">
              <label>Due Date</label>
              <strong style="color:<?php echo $isDue && !$isSubmitted ? '#ef4444' : '#1e293b'; ?>;">
                <?php echo $qz['due_date'] ? date('M d, Y @ g:i A', strtotime($qz['due_date'])) : 'No expiration'; ?>
              </strong>
            </div>
          </div>
        </div>

        <div class="cc-action-col">
          <?php if($isSubmitted): ?>
            <div style="text-align:right;margin-right:8px;">
              <span style="display:block;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Your Score</span>
              <strong style="font-size:16px;font-weight:800;color:#059669;"><?php echo $qz['score']; ?> / <?php echo $qz['total_points']; ?></strong>
            </div>
            <button class="btn-view-class" style="background:#f1f5f9;color:#475569;box-shadow:none;cursor:default;" disabled>
              <i class="fa fa-check"></i> Done
            </button>
          <?php elseif($isUpcoming): ?>
            <button class="btn-view-class" style="background:#e2e8f0;color:#64748b;box-shadow:none;cursor:not-allowed;" disabled>
              <i class="fa fa-lock"></i> Locked
            </button>
          <?php elseif(!$isDue): ?>
            <button class="btn-view-class" onclick="takeQuiz(<?php echo $qz['id']; ?>)">
              <i class="fa fa-pencil"></i> Take Quiz <i class="fa fa-arrow-right" style="font-size:11px;"></i>
            </button>
          <?php else: ?>
            <button class="btn-view-class" style="background:#f1f5f9;color:#94a3b8;box-shadow:none;cursor:not-allowed;" disabled>
              <i class="fa fa-times"></i> Closed
            </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
  <footer class="t-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div> class="t-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<!-- ═══════════ TAKE QUIZ MODAL (FULLSCREEN) ═══════════ -->
<div class="cv-modal-overlay" id="takeQuizModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;align-items:stretch;">
  <div class="cv-modal" style="max-width:100%;width:100%;height:100vh;max-height:100vh;border-radius:0;margin:0;display:flex;flex-direction:column;background:#f8fafc;position:relative;overflow:hidden;">
    <div class="cv-modal-head" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);padding:14px 24px;display:flex;align-items:center;justify-content:space-between;color:#fff;z-index:20;position:relative;">
      <h4 id="takeQuizTitle" style="color:#fff;margin:0;font-size:16px;font-weight:700;"><i class="fa fa-question-circle"></i> Quiz</h4>
      <div id="quizTimerWrap" style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);display:flex;align-items:center;justify-content:center;">
        <span id="quizTimer" style="display:none;"></span>
      </div>
      <div style="display:flex;align-items:center;gap:12px;">
        <span id="quizProgress" style="background:rgba(255,255,255,.2);padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;"><i class="fa fa-pencil-square-o"></i> 0/0 Answered</span>
      </div>
    </div>
    <!-- Question Stepper Palette Bar -->
    <div id="quizPaletteBar" class="quiz-stepper-header" style="display:none;">
      <div style="font-size:12px;font-weight:700;color:#64748b;display:flex;align-items:center;gap:6px;">
        <i class="fa fa-th-large" style="color:#8b5cf6;"></i> <span>Questions:</span>
      </div>
      <div class="quiz-palette-wrap" id="quizPaletteWrap"></div>
      <div style="font-size:12px;font-weight:700;color:#64748b;white-space:nowrap;" id="paletteSummaryText">
        0 of 0 Answered
      </div>
    </div>
    <div id="quizViolationBar" style="display:none;"><i class="fa fa-exclamation-triangle"></i> <span id="quizViolationMsg">Warning: suspicious activity detected</span></div>
    <div class="cv-modal-body" id="takeQuizBody" style="flex:1;overflow-y:auto;padding:24px 20px;background:#f8fafc;position:relative;">
      <div id="quizQuestionCardArea" style="position:relative;z-index:10;">
        <div style="text-align:center;padding:32px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
      </div>
    </div>
    <!-- Stepper Navigation Footer -->
    <div class="quiz-stepper-foot" id="takeQuizFoot" style="display:none;">
      <div>
        <button type="button" class="btn-step-nav btn-step-prev" id="btnPrevQuestion" onclick="prevQuestion()" disabled>
          <i class="fa fa-chevron-left"></i> Previous
        </button>
      </div>
      <div id="stepCounterText" style="font-size:13px;font-weight:700;color:#64748b;display:flex;align-items:center;gap:6px;">
        Question 1 of 1
      </div>
      <div style="display:flex;align-items:center;gap:10px;">
        <button type="button" class="btn-step-nav btn-step-next" id="btnNextQuestion" onclick="nextQuestion()">
          Next <i class="fa fa-chevron-right"></i>
        </button>
        <button type="button" class="btn-step-nav btn-step-submit" id="btnSubmitQuiz" onclick="submitQuizAnswers(false)" style="display:none;">
          <i class="fa fa-check"></i> Submit Quiz
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Anti-Cheat Protection Warning & Fullscreen Recovery Overlay ── -->
<div id="antiCheatOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.96);z-index:999999;flex-direction:column;align-items:center;justify-content:center;padding:24px;backdrop-filter:blur(10px);text-align:center;">
  <div style="width:72px;height:72px;border-radius:50%;background:#fef2f2;border:2px solid #fecaca;display:flex;align-items:center;justify-content:center;color:#ef4444;font-size:32px;margin-bottom:18px;box-shadow:0 0 30px rgba(239,68,68,0.3);animation:pulse 1.5s infinite;">
    <i class="fa fa-shield"></i>
  </div>
  <h3 style="color:#fff;font-size:22px;font-weight:800;margin:0 0 8px;">Anti-Cheat Security Violation Detected!</h3>
  <p id="antiCheatReasonText" style="color:#cbd5e1;font-size:14px;max-width:480px;line-height:1.5;margin:0 0 16px;">
    You attempted to switch tabs, press ESC, or exit full-screen mode during an active quiz attempt.
  </p>
  <div id="antiCheatViolationCountPill" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:6px 18px;border-radius:99px;font-size:13px;font-weight:800;margin-bottom:24px;">
    Violations Recorded: 1 / 3 Allowed
  </div>
  <p style="color:#94a3b8;font-size:12.5px;max-width:440px;margin-bottom:24px;">
    <strong>Notice:</strong> Reaching 3 violations will automatically submit your quiz attempt immediately with your current answers.
  </p>
  <button type="button" onclick="resumeQuizFullscreen()" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;border:none;padding:12px 28px;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(139,92,246,0.4);display:inline-flex;align-items:center;gap:8px;font-family:'Inter',sans-serif;">
    <i class="fa fa-arrows-alt"></i> Re-Enter Full-Screen &amp; Resume Quiz
  </button>
</div>

<?php include '../includes/scripts.php'; ?>
<script>
function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }

function filterQuizCards(cat, btn){
  document.querySelectorAll('.filter-tabs .tab-pill').forEach(function(b){ b.classList.remove('active'); });
  if(btn) btn.classList.add('active');
  var cards = document.querySelectorAll('#quizCardsGrid .class-card');
  cards.forEach(function(c){
    if(cat === 'all' || c.getAttribute('data-category') === cat){
      c.style.display = 'flex';
    } else {
      c.style.display = 'none';
    }
  });
}

function searchQuizzes(query) {
  var q = query.toLowerCase().trim();
  var cards = document.querySelectorAll('#quizCardsGrid .class-card');
  cards.forEach(function(c) {
    var searchData = (c.getAttribute('data-search') || '').toLowerCase();
    if(!q || searchData.includes(q)) {
      c.style.display = 'flex';
    } else {
      c.style.display = 'none';
    }
  });
}

// ── Take Quiz Logic ──────────────────────────────────────────────────────────
var _quizId = null, _answers = {}, _quizQuestions = [], _timerInt = null, _heartbeatInt = null, _tabSwitches = 0, _fsExits = 0;
var _currentQuestionIdx = 0;

function closeQuizModalDirect(){
  stopAntiCheatEngine();
  var modal = document.getElementById('takeQuizModal');
  if(modal) modal.style.display = 'none';

  var vBar = document.getElementById('quizViolationBar');
  if(vBar) vBar.style.display = 'none';

  var palBar = document.getElementById('quizPaletteBar');
  if(palBar) palBar.style.display = 'none';

  if(document.fullscreenElement || document.webkitFullscreenElement){
    if(document.exitFullscreen) document.exitFullscreen().catch(function(){});
    else if(document.webkitExitFullscreen) document.webkitExitFullscreen();
  }

  if(_timerInt) { clearInterval(_timerInt); _timerInt = null; }
  if(_heartbeatInt) { clearInterval(_heartbeatInt); _heartbeatInt = null; }

  _quizId = null;
  _currentQuestionIdx = 0;

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

// ── ANTI-CHEAT PROTECTION ENGINE ─────────────────────────────────────────────
var _antiCheatActive = false;
var _violationCooldown = false;

function initAntiCheatEngine() {
  if (_antiCheatActive) return;
  _antiCheatActive = true;
  _violationCooldown = false;

  requestQuizFullscreen();

  document.addEventListener('fullscreenchange', handleFullscreenChange);
  document.addEventListener('webkitfullscreenchange', handleFullscreenChange);
  document.addEventListener('mozfullscreenchange', handleFullscreenChange);
  document.addEventListener('MSFullscreenChange', handleFullscreenChange);

  document.addEventListener('visibilitychange', handleVisibilityChange);
  window.addEventListener('blur', handleWindowBlur);
  window.addEventListener('pagehide', handleWindowBlur);

  document.addEventListener('contextmenu', preventDefaultAntiCheat, true);
  document.addEventListener('copy', preventDefaultAntiCheat, true);
  document.addEventListener('cut', preventDefaultAntiCheat, true);
  document.addEventListener('paste', preventDefaultAntiCheat, true);
  document.addEventListener('selectstart', preventDefaultAntiCheat, true);
  window.addEventListener('keydown', handleAntiCheatKeydown, true);
}

function stopAntiCheatEngine() {
  _antiCheatActive = false;
  _violationCooldown = false;

  document.removeEventListener('fullscreenchange', handleFullscreenChange);
  document.removeEventListener('webkitfullscreenchange', handleFullscreenChange);
  document.removeEventListener('mozfullscreenchange', handleFullscreenChange);
  document.removeEventListener('MSFullscreenChange', handleFullscreenChange);

  document.removeEventListener('visibilitychange', handleVisibilityChange);
  window.removeEventListener('blur', handleWindowBlur);
  window.removeEventListener('pagehide', handleWindowBlur);

  document.removeEventListener('contextmenu', preventDefaultAntiCheat, true);
  document.removeEventListener('copy', preventDefaultAntiCheat, true);
  document.removeEventListener('cut', preventDefaultAntiCheat, true);
  document.removeEventListener('paste', preventDefaultAntiCheat, true);
  document.removeEventListener('selectstart', preventDefaultAntiCheat, true);
  window.removeEventListener('keydown', handleAntiCheatKeydown, true);

  hideAntiCheatOverlay();
}

function requestQuizFullscreen() {
  var docEl = document.documentElement;
  if (docEl.requestFullscreen) { docEl.requestFullscreen().catch(function(){}); }
  else if (docEl.webkitRequestFullscreen) { docEl.webkitRequestFullscreen(); }
  else if (docEl.mozRequestFullScreen) { docEl.mozRequestFullScreen(); }
  else if (docEl.msRequestFullscreen) { docEl.msRequestFullscreen(); }
}

function isFullscreenActive() {
  return !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement);
}

function resumeQuizFullscreen() {
  requestQuizFullscreen();
  hideAntiCheatOverlay();
}

function showAntiCheatOverlay(reason, count) {
  var overlay = document.getElementById('antiCheatOverlay');
  var reasonEl = document.getElementById('antiCheatReasonText');
  var countEl = document.getElementById('antiCheatViolationCountPill');

  if (reasonEl) reasonEl.textContent = reason || 'Exited full-screen or switched tabs during quiz!';
  if (countEl) countEl.textContent = 'Violations Recorded: ' + (count || 1) + ' / 3 Allowed';
  if (overlay) overlay.style.display = 'flex';
}

function hideAntiCheatOverlay() {
  var overlay = document.getElementById('antiCheatOverlay');
  if (overlay) overlay.style.display = 'none';
}

function handleFullscreenChange() {
  if (!_antiCheatActive || _isSubmitting) return;
  if (!isFullscreenActive()) {
    _fsExits++;
    registerAntiCheatViolation('Full-screen mode exited (ESC key / window resize detected)!');
  } else {
    hideAntiCheatOverlay();
  }
}

function handleVisibilityChange() {
  if (!_antiCheatActive || _isSubmitting) return;
  if (document.hidden || document.visibilityState === 'hidden') {
    _tabSwitches++;
    registerAntiCheatViolation('Tab switch / browser window minimize detected!');
  }
}

function handleWindowBlur() {
  if (!_antiCheatActive || _isSubmitting) return;
  if (!_violationCooldown) {
    _tabSwitches++;
    registerAntiCheatViolation('Window focus lost (Alt+Tab / application switch detected)!');
  }
}

function preventDefaultAntiCheat(e) {
  if (!_antiCheatActive) return;
  var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
  if (e.type === 'selectstart' && (tag === 'input' || tag === 'textarea')) return;

  e.preventDefault();
  e.stopPropagation();
  return false;
}

function handleAntiCheatKeydown(e) {
  if (!_antiCheatActive) return;
  var code = e.keyCode || e.which;
  var key = e.key ? e.key.toLowerCase() : '';

  // Prevent ESC key default behavior inside active quiz
  if (code === 27 || key === 'escape') {
    e.preventDefault();
    _fsExits++;
    registerAntiCheatViolation('ESC key pressed during active quiz!');
    return false;
  }

  // Block F12, Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+Shift+C (DevTools)
  if (code === 123 || (e.ctrlKey && e.shiftKey && (code === 73 || code === 74 || code === 67 || key === 'i' || key === 'j' || key === 'c'))) {
    e.preventDefault(); e.stopPropagation(); return false;
  }
  // Block Ctrl+U (View Source), Ctrl+C (Copy), Ctrl+V (Paste), Ctrl+S (Save), Ctrl+P (Print)
  if (e.ctrlKey && (code === 85 || code === 67 || code === 86 || code === 83 || code === 80 || key === 'u' || key === 'c' || key === 'v' || key === 's' || key === 'p')) {
    var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
    if (code === 85 || (e.ctrlKey && key === 'u')) { e.preventDefault(); e.stopPropagation(); return false; }
    if (tag !== 'input' && tag !== 'textarea') {
      e.preventDefault(); e.stopPropagation(); return false;
    }
  }
}

function registerAntiCheatViolation(reason) {
  if (_violationCooldown || _isSubmitting) return;
  _violationCooldown = true;

  var totalViolations = (_tabSwitches + _fsExits);
  
  if (_quizId) {
    $.post('/cenlearn/shared/quiz_handler', {
      action: 'log_violation',
      quiz_id: _quizId,
      tab_switches: _tabSwitches,
      fullscreen_exits: _fsExits,
      reason: reason
    }, function(res){
      if (res && res.tab_switches) {
        totalViolations = parseInt(res.tab_switches) || totalViolations;
      }
    }, 'json');
  }

  var vBar = document.getElementById('quizViolationBar');
  var vMsg = document.getElementById('quizViolationMsg');
  if (vBar && vMsg) {
    vMsg.innerHTML = '<strong>ANTI-CHEAT WARNING:</strong> ' + reason + ' (' + totalViolations + ' of 3 violations)';
    vBar.style.display = 'flex';
  }

  showAntiCheatOverlay(reason, totalViolations);

  if (totalViolations >= 3) {
    setTimeout(function(){
      alert('SECURITY ALERT: Maximum 3 anti-cheat violations reached! Your quiz is being automatically submitted now.');
      submitQuizAnswers(true);
    }, 400);
  } else {
    setTimeout(function(){ _violationCooldown = false; }, 1500);
  }
}

function takeQuiz(id){
  _quizId = id; _answers = {}; _tabSwitches = 0; _fsExits = 0; _currentQuestionIdx = 0; _isSubmitting = false;
  var bodyEl = document.getElementById('takeQuizBody');
  var cardArea = document.getElementById('quizQuestionCardArea');
  var footEl = document.getElementById('takeQuizFoot');
  var tEl = document.getElementById('quizTimer');
  var palBar = document.getElementById('quizPaletteBar');
  
  if(palBar) palBar.style.display = 'none';
  if(cardArea) cardArea.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i><p style="margin-top:12px;font-size:13px;">Loading quiz questions...</p></div>';
  if(footEl) footEl.style.display = 'none';
  if(tEl) tEl.style.display = 'none';
  document.getElementById('takeQuizModal').style.display = 'flex';

  var docEl = document.documentElement;
  if (docEl.requestFullscreen) { docEl.requestFullscreen().catch(function(){}); }
  else if (docEl.webkitRequestFullscreen) { docEl.webkitRequestFullscreen(); }

  if (_heartbeatInt) clearInterval(_heartbeatInt);
  _heartbeatInt = setInterval(function(){
    if (_quizId && document.getElementById('takeQuizModal') && document.getElementById('takeQuizModal').style.display === 'flex') {
      $.post('/cenlearn/shared/quiz_handler', { action: 'heartbeat', quiz_id: _quizId });
    }
  }, 10000);
  document.getElementById('quizProgress').innerHTML = '<i class="fa fa-pencil-square-o"></i> 0/0 Answered';

  $.post('/cenlearn/shared/quiz_handler', {action:'get_questions', quiz_id:id}, function(r){
    if(typeof r === 'string'){
      try { r = JSON.parse(r.trim()); } catch(e){ r = {success:false, msg:'Invalid data'}; }
    }
    if(!r || !r.success){
      if(cardArea) cardArea.innerHTML='<div style="padding:32px;text-align:center;"><div style="width:60px;height:60px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;"><i class="fa fa-exclamation-circle" style="font-size:24px;color:#ef4444;"></i></div><h4 style="margin:0 0 6px;color:#0f172a;">Cannot Start Quiz</h4><p style="color:#64748b;font-size:13px;margin:0 0 16px;">'+(r && r.msg ? r.msg : 'Failed to load quiz')+'</p></div>';
      return;
    }

    if(r.already_submitted){
      closeQuizModalDirect();
      return;
    }

    _quizQuestions = r.questions || [];
    _tabSwitches = parseInt(r.tab_switches) || 0;
    _answers = r.saved_answers || {};
    _currentQuestionIdx = 0;

    initAntiCheatEngine();

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

    // Initialize Stepper Palette & Render First Question
    initQuestionPalette();
    renderCurrentQuestion();
    populateWatermark();
    if(footEl) footEl.style.display = 'flex';
  }, 'json');
}

// ── Stepper Palette & Card Render Functions ────────────────────────────────────
function initQuestionPalette(){
  var wrap = document.getElementById('quizPaletteWrap');
  var palBar = document.getElementById('quizPaletteBar');
  if(!wrap) return;
  
  if(!_quizQuestions || _quizQuestions.length <= 1){
    if(palBar) palBar.style.display = 'none';
    return;
  }
  
  var html = '';
  _quizQuestions.forEach(function(q, i){
    html += '<button type="button" class="quiz-palette-btn" id="palBtn_' + i + '" data-idx="' + i + '" onclick="goToQuestion(' + i + ')" title="Question ' + (i+1) + '">' + (i+1) + '</button>';
  });
  wrap.innerHTML = html;
  if(palBar) palBar.style.display = 'flex';
  updatePaletteButtons();
}

function updatePaletteButtons(){
  if(!_quizQuestions) return;
  var answeredCount = 0;
  _quizQuestions.forEach(function(q, i){
    var btn = document.getElementById('palBtn_' + i);
    var ans = _answers[q.id];
    var isAns = (ans !== undefined && ans !== null && String(ans).trim() !== '');
    if(isAns) answeredCount++;
    if(btn){
      btn.classList.toggle('answered', isAns);
      btn.classList.toggle('current', i === _currentQuestionIdx);
    }
  });

  var sumText = document.getElementById('paletteSummaryText');
  if(sumText){
    sumText.textContent = answeredCount + ' of ' + _quizQuestions.length + ' Answered';
  }
}

function renderCurrentQuestion(){
  var cardArea = document.getElementById('quizQuestionCardArea');
  var footEl = document.getElementById('takeQuizFoot');
  if(!cardArea || !_quizQuestions || _quizQuestions.length === 0) return;

  var total = _quizQuestions.length;
  if(_currentQuestionIdx >= total) _currentQuestionIdx = total - 1;
  if(_currentQuestionIdx < 0) _currentQuestionIdx = 0;

  var q = _quizQuestions[_currentQuestionIdx];
  var qtype = String(q.question_type || 'multiple_choice').toLowerCase();
  var savedVal = _answers[q.id];
  var isAns = (savedVal !== undefined && savedVal !== null && String(savedVal).trim() !== '');

  // Detect matching question
  var isMatchingQuestion = (qtype === 'matching') || (Array.isArray(q.matching_pairs) && q.matching_pairs.length > 0) || (/column\s*a[\s\S]*column\s*b/i.test(q.question_text || ''));
  if(isMatchingQuestion){
    qtype = 'matching';
  }

  var typeLabels = {
    'multiple_choice': 'Single Multiple Choice',
    'multi_select': 'Multi-Select Multiple Choice',
    'true_false': 'True or False',
    'tf': 'True or False',
    'boolean': 'True or False',
    'modified_true_false': 'Modified True / False',
    'identification': 'Identification',
    'enumeration': 'Enumeration',
    'matching': 'Matching Type',
    'essay': 'Essay'
  };
  var typeLabel = typeLabels[qtype] || 'Question';

  var displayQuestionText = q.question_text || '';
  if(isMatchingQuestion){
    // Keep instruction line like "Match Column A terms with Column B definitions. (2 points each)"
    var insMatch = displayQuestionText.match(/^(Match\s+Column\s+A[\s\S]*?definitions\.?(?:\s*\([^\)]*\))?)/i);
    if(insMatch && insMatch[1]){
      displayQuestionText = insMatch[1].trim();
    } else {
      var beforeColA = displayQuestionText.split(/Column\s*A\s*:/i)[0].trim();
      displayQuestionText = (beforeColA && beforeColA.length > 8) ? beforeColA : 'Match Column A terms with Column B definitions.';
    }
  }

  var html = '<div class="quiz-single-card">'
    + '<div class="quiz-card-head">'
    + '  <div class="quiz-card-qnum">'
    + '    <span class="quiz-card-qnum-pill"><i class="fa fa-pencil"></i> Question ' + (_currentQuestionIdx + 1) + ' of ' + total + '</span>'
    + '    <span class="quiz-card-pts"><i class="fa fa-star-o"></i> ' + q.points + ' pt' + (q.points !== 1 ? 's' : '') + '</span>'
    + '  </div>'
    + '  <div class="quiz-card-badges">'
    + '    <span style="font-size:11.5px;font-weight:700;color:#64748b;background:#f8fafc;border:1px solid #e2e8f0;padding:4px 10px;border-radius:8px;">' + escapeCqHtml(typeLabel) + '</span>'
    + '    <span id="cardStatusBadge" class="quiz-card-status ' + (isAns ? 'answered' : 'unanswered') + '">'
    + (isAns ? '<i class="fa fa-check-circle"></i> Answered' : '<i class="fa fa-circle-o"></i> Unanswered')
    + '    </span>'
    + '  </div>'
    + '</div>'
    + '<div class="quiz-card-text" style="font-size:16px;font-weight:700;color:#0f172a;line-height:1.4;margin-bottom:16px;">' + escapeCqHtml(displayQuestionText) + '</div>';

  var optsData = Array.isArray(q.options_data) ? q.options_data : [];
  var opts = Array.isArray(q.options) ? q.options : [];

  // 1. SINGLE MULTIPLE CHOICE
  if(qtype === 'multiple_choice'){
    var list = (optsData.length > 0) ? optsData : opts.map(function(t, idx){ return { id: 'opt_' + idx, text: t }; });
    html += '<div style="display:flex;flex-direction:column;gap:8px;margin-top:12px;">';
    list.forEach(function(opt, oi){
      var optId = (typeof opt === 'object' && opt.id) ? opt.id : opt;
      var optText = (typeof opt === 'object' && opt.text) ? opt.text : opt;
      var isOptSel = (savedVal === optId || savedVal === optText);

      html += '<div class="quiz-opt ' + (isOptSel ? 'selected' : '') + '" onclick="selectOpt(this,' + q.id + ')" data-qid="' + q.id + '" data-val="' + escapeCqAttr(optId) + '">'
        + '<span class="quiz-opt-circle">' + String.fromCharCode(65 + oi) + '</span>'
        + '<span style="flex:1;font-size:14.5px;font-weight:600;">' + escapeCqHtml(optText) + '</span>'
        + '</div>';
    });
    html += '</div>';
  }
  // 2. MULTI-SELECT MULTIPLE CHOICE
  else if(qtype === 'multi_select'){
    var curSelected = Array.isArray(savedVal) ? savedVal : (savedVal ? [savedVal] : []);
    var list = (optsData.length > 0) ? optsData : opts.map(function(t, idx){ return { id: 'opt_' + idx, text: t }; });
    html += '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 12px;margin:8px 0;font-size:12px;color:#1e40af;"><i class="fa fa-check-square-o"></i> Select all correct answers that apply:</div>';
    html += '<div style="display:flex;flex-direction:column;gap:8px;margin-top:8px;">';
    list.forEach(function(opt, oi){
      var optId = (typeof opt === 'object' && opt.id) ? opt.id : opt;
      var optText = (typeof opt === 'object' && opt.text) ? opt.text : opt;
      var isOptSel = curSelected.includes(optId) || curSelected.includes(optText);

      html += '<div class="quiz-opt ' + (isOptSel ? 'selected' : '') + '" onclick="toggleMultiOpt(this,' + q.id + ',\'' + escapeCqAttr(optId) + '\')" data-qid="' + q.id + '" data-val="' + escapeCqAttr(optId) + '">'
        + '<span class="quiz-opt-box"><i class="fa fa-check"></i></span>'
        + '<span style="flex:1;font-size:14.5px;font-weight:600;">' + escapeCqHtml(optText) + '</span>'
        + '</div>';
    });
    html += '</div>';
  }
  // 3. TRUE OR FALSE
  else if(qtype === 'true_false' || qtype === 'tf' || qtype === 'boolean'){
    var isTrueSel = (savedVal === 'true' || savedVal === true);
    var isFalseSel = (savedVal === 'false' || savedVal === false);
    html += '<div class="quiz-tf">'
      + '<div class="quiz-opt ' + (isTrueSel ? 'selected' : '') + '" onclick="selectOpt(this,' + q.id + ')" data-qid="' + q.id + '" data-val="true" style="justify-content:center;gap:10px;padding:16px;"><i class="fa fa-check" style="color:#10b981;font-size:16px;"></i> <strong>True</strong></div>'
      + '<div class="quiz-opt ' + (isFalseSel ? 'selected' : '') + '" onclick="selectOpt(this,' + q.id + ')" data-qid="' + q.id + '" data-val="false" style="justify-content:center;gap:10px;padding:16px;"><i class="fa fa-times" style="color:#ef4444;font-size:16px;"></i> <strong>False</strong></div>'
      + '</div>';
  }
  // 4. MODIFIED TRUE OR FALSE
  else if(qtype === 'modified_true_false'){
    var curValStr = typeof savedVal === 'string' ? savedVal : '';
    var isT = (curValStr.toLowerCase() === 'true');
    var isF = (curValStr.toLowerCase().startsWith('false') || (curValStr !== '' && !isT));
    var corrPart = isF ? (curValStr.replace(/^false\s*[\—\-\:]\s*/i, '').trim()) : '';

    html += '<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:9px;padding:12px 14px;margin-bottom:12px;font-size:12.5px;color:#92400e;"><i class="fa fa-info-circle"></i> If True, click True. If False, click False and provide the corrected word/phrase.</div>';
    html += '<div class="quiz-tf" style="margin-bottom:12px;">'
      + '<div class="quiz-opt ' + (isT ? 'selected' : '') + '" onclick="selectMtfChoice(' + q.id + ', true)" style="justify-content:center;gap:8px;padding:12px;"><i class="fa fa-check" style="color:#10b981;"></i> <strong>True</strong></div>'
      + '<div class="quiz-opt ' + (isF ? 'selected' : '') + '" onclick="selectMtfChoice(' + q.id + ', false)" style="justify-content:center;gap:8px;padding:12px;"><i class="fa fa-times" style="color:#ef4444;"></i> <strong>False</strong></div>'
      + '</div>';
    html += '<div id="mtfCorrectionBox_' + q.id + '" style="display:' + (isF ? 'block' : 'none') + ';">'
      + '<label style="font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;display:block;">Correct replacement word/phrase:</label>'
      + '<input type="text" class="quiz-id-input" id="mtfInput_' + q.id + '" value="' + escapeCqAttr(corrPart) + '" placeholder="e.g. Mitochondria" oninput="updateMtfInput(' + q.id + ', this.value)">'
      + '</div>';
  }
  // 5. MATCHING TYPE (Two-Table Column A & Column B Design matching mockup)
  else if(qtype === 'matching'){
    var matchAns = (typeof savedVal === 'object' && savedVal !== null) ? savedVal : {};
    
    // Extract Column A items and Column B items
    var pairs = Array.isArray(q.matching_pairs) && q.matching_pairs.length > 0 ? q.matching_pairs : [];
    var colA = [];
    var colB = [];

    if(pairs.length > 0){
      pairs.forEach(function(p, idx){
        var aId = p.col_a_id || p.pair_id || ('a-' + (idx + 1));
        var bId = p.col_b_id || ('b-' + (idx + 1));
        colA.push({ id: aId, text: p.col_a_text || ('Item ' + (idx + 1)) });
        colB.push({ id: bId, letter: String.fromCharCode(65 + idx), text: p.col_b_text || ('Definition ' + (idx + 1)) });
      });
    } else {
      // Fallback: parse from question_text Column A and options Column B
      var qText = q.question_text || '';
      var aItems = [];
      
      var colAPart = '';
      var colASplit = qText.split(/Column\s*A\s*[:\-]/i);
      if(colASplit.length > 1){
        colAPart = colASplit[1].split(/Column\s*B/i)[0].trim();
      }
      
      if(colAPart){
        colAPart = colAPart.replace(/[\(\[\{].*?[\)\]\}]/g, '').trim();
        var splits = colAPart.split(/(?:\r?\n|\s*\d+[\.\)\-]\s*|,\s*|\s+(?=[A-Z][a-z]+))/).map(function(s){ return s.trim(); }).filter(function(s){
          return s && !/^(terms|with|and|definitions|column)$/i.test(s);
        });
        if(splits.length > 0){
          aItems = splits;
        }
      }

      if(aItems.length === 0){
        aItems = ['Dog', 'Mars', 'Rose'];
      }

      var bList = (optsData.length > 0) ? optsData : opts;
      bList.forEach(function(opt, bIdx){
        var bId = (typeof opt === 'object' && opt.id) ? opt.id : ('opt_' + bIdx);
        var bText = (typeof opt === 'object' && opt.text) ? opt.text : opt;
        var cleanBText = String(bText).replace(/^[a-zA-Z0-9][\.\)\:\-\s]+/i, '').trim();
        colB.push({ id: bId, letter: String.fromCharCode(65 + bIdx), text: cleanBText || bText });
      });

      aItems.forEach(function(aText, aIdx){
        colA.push({ id: 'a-' + (aIdx + 1), text: aText });
      });
    }

    html += '<div style="margin-top:14px;">'
      
      // ── COLUMN A TABLE ──────────────────────────────
      + '<div style="border:1.5px solid #93c5fd;border-radius:12px;overflow:hidden;background:#fff;box-shadow:0 2px 10px rgba(0,0,0,0.03);">'
      + '  <div style="background:#eff6ff;color:#1e40af;font-size:15px;font-weight:800;text-align:center;padding:12px;border-bottom:1.5px solid #93c5fd;letter-spacing:0.5px;">'
      + '    COLUMN A'
      + '  </div>'
      + '  <table style="width:100%;border-collapse:collapse;">'
      + '    <tbody>';

    colA.forEach(function(itemA, aIdx){
      var pairKey = itemA.id;
      var curVal = matchAns[pairKey] || '';
      var isMatched = (curVal !== undefined && curVal !== null && String(curVal).trim() !== '');

      // Find chosen text / label
      var matchedOpt = colB.find(function(b){ return b.id === curVal || b.letter === curVal || b.text === curVal; });
      var matchedDisplay = matchedOpt ? (matchedOpt.letter + '. ' + matchedOpt.text) : '';

      html += '<tr style="border-bottom:1.5px solid #dbeafe;background:' + (isMatched ? '#f8fafc' : '#fff') + ';">'
        + '  <td style="width:52px;text-align:center;font-weight:800;font-size:15px;color:#0f172a;border-right:1.5px solid #bfdbfe;padding:16px 8px;vertical-align:middle;">' + (aIdx + 1) + '.</td>'
        + '  <td style="padding:14px 18px;border-right:1.5px solid #bfdbfe;vertical-align:middle;">'
        + '    <strong style="font-size:15px;color:#0f172a;display:block;">' + escapeCqHtml(itemA.text) + '</strong>'
        + (isMatched ? '<span style="display:inline-flex;align-items:center;gap:4px;color:#15803d;background:#dcfce7;border:1px solid #86efac;padding:2px 8px;border-radius:6px;font-size:11.5px;font-weight:700;margin-top:6px;"><i class="fa fa-check-circle"></i> ' + escapeCqHtml(matchedDisplay) + '</span>' : '')
        + '  </td>'
        + '  <td style="padding:12px 16px;width:240px;vertical-align:middle;">'
        + '    <select class="form-control" style="border-radius:8px;font-size:13.5px;font-weight:700;height:40px;border:1.5px solid ' + (isMatched ? '#22c55e' : '#cbd5e1') + ';background:' + (isMatched ? '#f0fdf4' : '#fff') + ';color:' + (isMatched ? '#15803d' : '#334155') + ';cursor:pointer;" onchange="updateMatchingChoice(' + q.id + ',\'' + escapeCqAttr(pairKey) + '\',this.value)">'
        + '      <option value="">Select answer ▾</option>';

      colB.forEach(function(itemB){
        var isSel = (curVal === itemB.id || curVal === itemB.letter || curVal === itemB.text);
        html += '<option value="' + escapeCqAttr(itemB.id) + '" ' + (isSel ? 'selected' : '') + '>' + itemB.letter + '. ' + escapeCqHtml(itemB.text) + '</option>';
      });

      html += '    </select>'
        + '  </td>'
        + '</tr>';
    });

    html += '    </tbody>'
      + '  </table>'
      + '</div>'
      + '</div>';
  }
  // 6. ENUMERATION
  else if(qtype === 'enumeration'){
    html += '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:12px 14px;margin-bottom:10px;font-size:12.5px;color:#1d4ed8;"><i class="fa fa-info-circle"></i> List items separated by commas or newlines (e.g. Solid, Liquid, Gas)</div>'
      + '<input type="text" class="quiz-id-input" value="' + escapeCqAttr(savedVal || '') + '" placeholder="Item 1, Item 2, Item 3" oninput="updateIdAnswer(' + q.id + ',this.value)" data-qid="' + q.id + '">';
  }
  // 7. ESSAY
  else if(qtype === 'essay'){
    var valStr = String(savedVal || '');
    var wordCount = valStr.trim() ? valStr.trim().split(/\s+/).length : 0;
    html += '<div style="margin-bottom:8px;"><textarea class="quiz-id-input" rows="7" placeholder="Write your essay answer here in complete sentences..." oninput="updateEssayAnswer(' + q.id + ',this.value)" data-qid="' + q.id + '" style="resize:vertical;min-height:160px;line-height:1.6;font-size:13.5px;">' + escapeCqHtml(valStr) + '</textarea></div>'
      + '<div style="display:flex;align-items:center;justify-content:space-between;font-size:11.5px;color:#64748b;">'
      + '<span><i class="fa fa-magic" style="color:#6366f1;"></i> Graded with Semantic Understanding (ideas & concepts)</span>'
      + '<span id="essayWordCount_' + q.id + '" style="font-weight:700;color:#0f172a;">' + wordCount + ' words</span>'
      + '</div>';
  }
  // 8. IDENTIFICATION
  else {
    html += '<input type="text" class="quiz-id-input" value="' + escapeCqAttr(savedVal || '') + '" placeholder="Type your answer here..." oninput="updateIdAnswer(' + q.id + ',this.value)" data-qid="' + q.id + '">';
  }

  html += '</div>';
  cardArea.innerHTML = html;

  // Update navigation controls
  var prevBtn = document.getElementById('btnPrevQuestion');
  var nextBtn = document.getElementById('btnNextQuestion');
  var subBtn = document.getElementById('btnSubmitQuiz');
  var countText = document.getElementById('stepCounterText');

  if(prevBtn) prevBtn.disabled = (_currentQuestionIdx === 0);
  if(countText) countText.textContent = 'Question ' + (_currentQuestionIdx + 1) + ' of ' + total;

  if(nextBtn){
    if(_currentQuestionIdx >= total - 1){
      nextBtn.style.display = 'none';
    } else {
      nextBtn.style.display = 'inline-flex';
    }
  }
  if(subBtn){
    if(_currentQuestionIdx >= total - 1){
      subBtn.style.display = 'inline-flex';
    } else {
      subBtn.style.display = 'none';
    }
  }

  if(footEl) footEl.style.display = 'flex';
  updatePaletteButtons();

  // Scroll active palette button into view smoothly
  var activeBtn = document.getElementById('palBtn_' + _currentQuestionIdx);
  if(activeBtn && activeBtn.scrollIntoView){
    activeBtn.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
  }
}

function goToQuestion(idx){
  if(!_quizQuestions || idx < 0 || idx >= _quizQuestions.length) return;
  _currentQuestionIdx = idx;
  renderCurrentQuestion();
}

function prevQuestion(){
  if(_currentQuestionIdx > 0){
    goToQuestion(_currentQuestionIdx - 1);
  }
}

function nextQuestion(){
  if(_quizQuestions && _currentQuestionIdx < _quizQuestions.length - 1){
    goToQuestion(_currentQuestionIdx + 1);
  }
}

function selectOpt(el, qid){
  document.querySelectorAll('.quiz-opt[data-qid="'+qid+'"]').forEach(function(o){ o.classList.remove('selected'); });
  el.classList.add('selected');
  var val = el.getAttribute('data-val');
  if(val !== null && val !== undefined) {
    _answers[qid] = val;
  }
  updateCardAnswerStatus(true);
  updateQuizProgress();
  updatePaletteButtons();
  saveDraftAnswers();
}

function toggleMultiOpt(el, qid, optId){
  if(!Array.isArray(_answers[qid])) {
    _answers[qid] = _answers[qid] ? [_answers[qid]] : [];
  }
  var idx = _answers[qid].indexOf(optId);
  if(idx > -1) {
    _answers[qid].splice(idx, 1);
    el.classList.remove('selected');
  } else {
    _answers[qid].push(optId);
    el.classList.add('selected');
  }
  if(_answers[qid].length === 0) delete _answers[qid];
  updateCardAnswerStatus(_answers[qid] && _answers[qid].length > 0);
  updateQuizProgress();
  updatePaletteButtons();
  saveDraftAnswers();
}

function selectMtfChoice(qid, isTrue){
  var box = document.getElementById('mtfCorrectionBox_' + qid);
  if(isTrue){
    _answers[qid] = 'True';
    if(box) box.style.display = 'none';
  } else {
    var inp = document.getElementById('mtfInput_' + qid);
    var corr = inp ? inp.value.trim() : '';
    _answers[qid] = corr ? ('False — ' + corr) : 'False';
    if(box) box.style.display = 'block';
  }
  renderCurrentQuestion();
  updateQuizProgress();
  updatePaletteButtons();
  saveDraftAnswers();
}

function updateMtfInput(qid, val){
  val = (val || '').trim();
  _answers[qid] = val ? ('False — ' + val) : 'False';
  updateCardAnswerStatus(true);
  updateQuizProgress();
  updatePaletteButtons();
  saveDraftAnswers();
}

function updateMatchingChoice(qid, pairKey, val){
  if(typeof _answers[qid] !== 'object' || _answers[qid] === null){
    _answers[qid] = {};
  }
  if(val){
    _answers[qid][pairKey] = val;
  } else {
    delete _answers[qid][pairKey];
  }
  var hasMatches = Object.keys(_answers[qid]).length > 0;
  if(!hasMatches) delete _answers[qid];
  updateCardAnswerStatus(hasMatches);
  updateQuizProgress();
  updatePaletteButtons();
  saveDraftAnswers();
}

function updateEssayAnswer(qid, val){
  var trimmed = (val || '').trim();
  var isAns = false;
  if(trimmed.length > 0){
    _answers[qid] = val;
    isAns = true;
    var wcEl = document.getElementById('essayWordCount_' + qid);
    if(wcEl) wcEl.textContent = trimmed.split(/\s+/).length + ' words';
  } else {
    delete _answers[qid];
    isAns = false;
  }
  updateCardAnswerStatus(isAns);
  updateQuizProgress();
  updatePaletteButtons();
  saveDraftAnswers();
}

function updateIdAnswer(qid, val){
  var trimmed = (val || '').trim();
  var isAns = false;
  if(trimmed.length > 0){
    _answers[qid] = trimmed;
    isAns = true;
  } else {
    delete _answers[qid];
    isAns = false;
  }
  updateCardAnswerStatus(isAns);
  updateQuizProgress();
  updatePaletteButtons();
  saveDraftAnswers();
}

function updateCardAnswerStatus(isAns){
  var statusBadge = document.getElementById('cardStatusBadge');
  if(statusBadge){
    statusBadge.className = 'quiz-card-status ' + (isAns ? 'answered' : 'unanswered');
    statusBadge.innerHTML = isAns ? '<i class="fa fa-check-circle"></i> Answered' : '<i class="fa fa-circle-o"></i> Unanswered';
  }
}

function saveDraftAnswers(){
  if(_quizId && _answers){
    $.post('/cenlearn/shared/quiz_handler', {
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

var _isSubmitting = false;

function submitQuizAnswers(auto){
  if(_isSubmitting) return;
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
  _isSubmitting = true;
  if(_timerInt) { clearInterval(_timerInt); _timerInt = null; }
  var btn = document.getElementById('btnSubmitQuiz');
  if(btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting...'; }

  $.post('/cenlearn/shared/quiz_handler', {
    action: 'submit',
    quiz_id: _quizId,
    answers: JSON.stringify(_answers),
    tab_switches: _tabSwitches,
    fullscreen_exits: _fsExits
  }, function(r){
    _isSubmitting = false;
    if(btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Submit Quiz'; }
    if(r && (r.success || r.already_submitted || (r.msg && r.msg.toLowerCase().indexOf('already submitted') !== -1))){
      closeQuizModalDirect();
    } else {
      alert((r && r.msg) || 'Submission failed');
    }
  }, 'json').fail(function(){
    _isSubmitting = false;
    if(btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Submit Quiz'; }
    alert('Network error while submitting. Please try again.');
  });
}

// ── Keyboard Navigation for Quiz Questions ──────────────────────────────────
window.addEventListener('keydown', function(e){
  if(!_quizId || document.getElementById('takeQuizModal').style.display !== 'flex') return;

  var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
  if(tag !== 'input' && tag !== 'textarea'){
    if(e.key === 'ArrowLeft'){
      prevQuestion();
    } else if(e.key === 'ArrowRight'){
      nextQuestion();
    }
  }
}, true);

<?php if(isset($_GET['take'])): ?>
$(document).ready(function(){
  takeQuiz(<?php echo intval($_GET['take']); ?>);
});
<?php endif; ?>
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
