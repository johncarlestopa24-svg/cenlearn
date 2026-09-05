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

    /* ── Fullscreen Quiz Modal Auto-Fit & Responsive ── */
    #takeQuizModal.cv-modal-overlay {
      position: fixed !important;
      inset: 0 !important;
      top: 0 !important;
      left: 0 !important;
      width: 100vw !important;
      height: 100vh !important;
      margin: 0 !important;
      padding: 0 !important;
      border: none !important;
      border-radius: 0 !important;
      background: #f8fafc !important;
      z-index: 999999 !important;
      display: none;
      flex-direction: column !important;
      align-items: stretch !important;
      justify-content: stretch !important;
    }
    #takeQuizModal .cv-modal {
      width: 100vw !important;
      max-width: 100vw !important;
      height: 100vh !important;
      max-height: 100vh !important;
      margin: 0 !important;
      padding: 0 !important;
      border-radius: 0 !important;
      border: none !important;
      display: flex !important;
      flex-direction: column !important;
      flex: 1 1 100% !important;
      background: #f8fafc !important;
      box-shadow: none !important;
    }
    #takeQuizModal .cv-modal-body {
      flex: 1 1 auto !important;
      overflow-y: auto !important;
      padding: 24px 20px !important;
      display: flex !important;
      flex-direction: column !important;
      align-items: center !important;
      width: 100% !important;
    }
    #takeQuizModal #quizQuestionCardArea {
      width: 100% !important;
      max-width: 980px !important;
      margin: 0 auto !important;
      position: relative !important;
      z-index: 10 !important;
    }
    .quiz-matching-tables-grid {
      display: grid !important;
      grid-template-columns: 1.25fr 1fr !important;
      gap: 18px !important;
      align-items: start !important;
      margin-top: 14px !important;
    }
    #quizTimer {
      background: #ffffff !important;
      color: #dc2626 !important;
      border: 2px solid #ef4444 !important;
      padding: 5px 18px !important;
      border-radius: 24px !important;
      font-size: 16px !important;
      font-weight: 800 !important;
      letter-spacing: 0.5px !important;
      box-shadow: 0 4px 14px rgba(220, 38, 38, 0.25) !important;
      display: inline-flex !important;
      align-items: center !important;
      gap: 7px !important;
    }
    #quizTimer .timer-icon {
      color: #dc2626 !important;
      font-size: 16px !important;
    }
    @media (max-width: 768px) {
      .quiz-matching-tables-grid {
        grid-template-columns: 1fr !important;
      }
      #takeQuizModal .cv-modal-body {
        padding: 14px 10px !important;
      }
      .quiz-single-card {
        padding: 16px 14px !important;
        border-radius: 12px !important;
      }
    }

    /* ── Single-Question Step-by-Step Layout ── */
    .quiz-stepper-header {
      background: #ffffff;
      border-bottom: 1px solid #e2e8f0;
      padding: 10px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      flex-wrap: wrap;
      z-index: 15;
    }
    .quiz-palette-wrap {
      display: flex;
      align-items: center;
      gap: 7px;
      overflow-x: auto;
      padding: 4px 2px;
      scrollbar-width: thin;
      flex: 1;
      max-width: 100%;
    }
    .quiz-palette-btn {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      border: 1.5px solid #cbd5e1;
      background: #f8fafc;
      color: #475569;
      font-size: 11.5px;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      flex-shrink: 0;
      transition: all .15s;
      position: relative;
      font-family: 'Inter', sans-serif;
    }
    .quiz-palette-btn:hover {
      border-color: #8b5cf6;
      background: #f5f3ff;
      color: #6d28d9;
    }
    .quiz-palette-btn.current {
      border-color: #8b5cf6 !important;
      background: #8b5cf6 !important;
      color: #fff !important;
      box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.35) !important;
      font-weight: 800;
    }
    .quiz-palette-btn.answered {
      border-color: #10b981;
      background: #ecfdf5;
      color: #059669;
    }
    .quiz-palette-btn.answered.current {
      border-color: #059669 !important;
      background: #059669 !important;
      color: #fff !important;
      box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.35) !important;
    }
    .quiz-palette-btn.answered::after {
      content: '';
      position: absolute;
      bottom: 2px;
      right: 2px;
      width: 5px;
      height: 5px;
      border-radius: 50%;
      background: #10b981;
    }
    .quiz-palette-btn.current::after {
      background: #fff !important;
    }

    /* Single Question Card */
    .quiz-single-card {
      background: #ffffff;
      border: 1.5px solid #e2e8f0;
      border-radius: 18px;
      padding: 28px 32px;
      max-width: 860px;
      margin: 0 auto;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
      position: relative;
      animation: fadeInCard .22s ease-out;
    }
    @keyframes fadeInCard {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .quiz-card-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding-bottom: 18px;
      border-bottom: 1px solid #f1f5f9;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }
    .quiz-card-qnum {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 15px;
      font-weight: 800;
      color: #0f172a;
    }
    .quiz-card-qnum-pill {
      background: linear-gradient(135deg,#8b5cf6,#6d28d9);
      color: #fff;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 700;
    }
    .quiz-card-badges {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .quiz-card-pts {
      font-size: 12px;
      font-weight: 700;
      color: #7c3aed;
      background: #f5f3ff;
      border: 1px solid #ddd6fe;
      padding: 4px 10px;
      border-radius: 8px;
    }
    .quiz-card-status {
      font-size: 11.5px;
      font-weight: 700;
      padding: 4px 10px;
      border-radius: 8px;
    }
    .quiz-card-status.answered {
      background: #dcfce7;
      color: #15803d;
      border: 1px solid #bbf7d0;
    }
    .quiz-card-status.unanswered {
      background: #f1f5f9;
      color: #64748b;
      border: 1px solid #e2e8f0;
    }
    .quiz-card-text {
      font-size: 16.5px;
      font-weight: 700;
      color: #0f172a;
      line-height: 1.6;
      margin-bottom: 24px;
    }

    /* Single Question Options */
    .quiz-single-card .quiz-opt {
      padding: 14px 18px;
      font-size: 14.5px;
      border-radius: 12px;
      margin-top: 12px;
    }
    .quiz-single-card .quiz-opt span:first-child {
      width: 28px;
      height: 28px;
      font-size: 12px;
    }
    .quiz-single-card .quiz-id-input {
      padding: 14px 18px;
      font-size: 15px;
      border-radius: 12px;
    }

    /* Stepper Navigation Footer */
    .quiz-stepper-foot {
      padding: 14px 28px;
      background: #ffffff;
      border-top: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      z-index: 20;
    }
    .btn-step-nav {
      padding: 10px 22px;
      border-radius: 10px;
      font-size: 13.5px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all .18s;
      border: 1.5px solid transparent;
      font-family: 'Inter', sans-serif;
    }
    .btn-step-prev {
      background: #f8fafc;
      border-color: #cbd5e1;
      color: #334155;
    }
    .btn-step-prev:hover:not(:disabled) {
      background: #e2e8f0;
      color: #0f172a;
    }
    .btn-step-prev:disabled {
      opacity: 0.4;
      cursor: not-allowed;
    }
    .btn-step-next {
      background: linear-gradient(135deg,#8b5cf6,#6d28d9);
      color: #fff;
      box-shadow: 0 3px 12px rgba(139, 92, 246, 0.35);
    }
    .btn-step-next:hover {
      opacity: 0.92;
      box-shadow: 0 4px 16px rgba(139, 92, 246, 0.45);
    }
    .btn-step-submit {
      background: linear-gradient(135deg,#10b981,#059669);
      color: #fff;
      box-shadow: 0 3px 12px rgba(16, 185, 129, 0.35);
    }
    .btn-step-submit:hover {
      opacity: 0.92;
      box-shadow: 0 4px 16px rgba(16, 185, 129, 0.45);
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
            <span class="status-pill status-graded" style="font-weight:700;"><i class="fa fa-check-circle"></i> Completed</span>
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
var _currentQuestionIdx = 0;

function closeQuizModalDirect(){
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
      $.post('../shared/quiz_handler.php', { action: 'heartbeat', quiz_id: _quizId });
    }
  }, 10000);
  document.getElementById('quizProgress').innerHTML = '<i class="fa fa-pencil-square-o"></i> 0/0 Answered';

  $.post('../shared/quiz_handler.php', {action:'get_questions', quiz_id:id}, function(r){
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

  $.post('../shared/quiz_handler.php', {
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
</script>
</body>
</html>
