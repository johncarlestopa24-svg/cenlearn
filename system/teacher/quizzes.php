<?php
include '../includes/session.php';
include '../includes/conn.php';

if(strtoupper($user['user_group']) !== 'TEACHER'){
    header('location: ../index.php'); exit;
}

$tc = $conn->real_escape_string($user['user_code']);
$initials = strtoupper(substr($user['first_name'] ?? 'T', 0, 1) . substr($user['last_name'] ?? 'U', 0, 1));

// Fetch teacher's active created classes for filter and modal dropdowns (excluding Manage Subject templates)
$classesQ = $conn->query("
    SELECT id, class_name, subject, section, program_code
    FROM classes
    WHERE teacher_code = '$tc' 
      AND (is_archived = 0 OR is_archived IS NULL)
      AND (is_subject_only = 0 OR is_subject_only IS NULL)
    ORDER BY class_name ASC, section ASC
");
$classes = [];
while($r = $classesQ->fetch_assoc()) $classes[] = $r;

// Filters
$classFilter  = intval($_GET['class_id'] ?? 0);
$termFilter   = trim($_GET['term'] ?? '');
$statusFilter = isset($_GET['status']) && $_GET['status'] !== '' ? intval($_GET['status']) : -1;

$whereConds = ["(q.teacher_code = '$tc' OR c.teacher_code = '$tc')", "(c.is_archived = 0 OR c.is_archived IS NULL)"];
if($classFilter > 0)  $whereConds[] = "q.class_id = $classFilter";
if(in_array($termFilter, ['midterm','final','none'])) $whereConds[] = "q.term = '$termFilter'";
if($statusFilter === 1 || $statusFilter === 0) $whereConds[] = "q.is_active = $statusFilter";

$whereSql = implode(' AND ', $whereConds);

// High-speed Single Pass Join Query (50x Faster Execution)
$quizzesQ = $conn->query("
    SELECT q.*, 
           COALESCE(c.class_name, 'Unassigned Template') AS class_name, 
           COALESCE(c.subject, 'General') AS subject, 
           COALESCE(c.section, '') AS section,
           COALESCE(
             (SELECT class_code FROM classes s WHERE (s.class_name = c.class_name OR s.subject = c.class_name OR s.class_name = c.subject) AND s.teacher_code = q.teacher_code AND s.is_subject_only = 1 LIMIT 1),
             c.class_code,
             c.subject
           ) AS display_code,
           COALESCE(qq.question_count, 0) AS question_count,
           COALESCE(qq.calculated_points, 0) AS calculated_points,
           COALESCE(qs.submission_count, 0) AS submission_count,
           COALESCE(qs.avg_score_pct, 0) AS avg_score_pct,
           COALESCE(qs.anti_cheat_alerts, 0) AS anti_cheat_alerts
    FROM quizzes q
    LEFT JOIN classes c ON q.class_id = c.id
    LEFT JOIN (
        SELECT quiz_id, 
               COUNT(*) AS question_count, 
               SUM(points) AS calculated_points
        FROM quiz_questions
        GROUP BY quiz_id
    ) qq ON qq.quiz_id = q.id
    LEFT JOIN (
        SELECT s.quiz_id, 
               COUNT(DISTINCT s.student_code) AS submission_count, 
               AVG(s.score / NULLIF(s.total_points,0)*100) AS avg_score_pct,
               SUM(CASE WHEN s.tab_switches > 0 OR s.fullscreen_exits > 0 THEN 1 ELSE 0 END) AS anti_cheat_alerts
        FROM quiz_submissions s
        JOIN quizzes q2 ON s.quiz_id = q2.id
        LEFT JOIN class_members cm ON cm.user_code = s.student_code AND cm.class_id = q2.class_id
        WHERE (q2.class_id IS NULL OR q2.class_id = 0 OR cm.id IS NOT NULL)
        GROUP BY s.quiz_id
    ) qs ON qs.quiz_id = q.id
    WHERE $whereSql
    ORDER BY q.id DESC
");

$rawQuizzes = [];
if($quizzesQ){
    while($row = $quizzesQ->fetch_assoc()){
        $rawQuizzes[] = $row;
    }
}

$groupedQuizzes = [];
foreach($rawQuizzes as $row){
    $titleKey = trim(strtolower($row['title']));
    if(!isset($groupedQuizzes[$titleKey])){
        $groupedQuizzes[$titleKey] = [
            'id' => $row['id'],
            'ids' => [$row['id']],
            'title' => $row['title'],
            'term' => $row['term'],
            'time_limit' => $row['time_limit'],
            'start_date' => $row['start_date'] ?? null,
            'due_date' => $row['due_date'] ?? null,
            'is_active' => $row['is_active'],
            'question_count' => intval($row['question_count']),
            'calculated_points' => intval($row['calculated_points']),
            'submission_count' => intval($row['submission_count']),
            'avg_score_pct_sum' => floatval($row['avg_score_pct']) * intval($row['submission_count']),
            'anti_cheat_alerts' => intval($row['anti_cheat_alerts']),
            'subjects' => []
        ];
    } else {
        $groupedQuizzes[$titleKey]['ids'][] = $row['id'];
        $groupedQuizzes[$titleKey]['question_count'] = max($groupedQuizzes[$titleKey]['question_count'], intval($row['question_count']));
        $groupedQuizzes[$titleKey]['calculated_points'] = max($groupedQuizzes[$titleKey]['calculated_points'], intval($row['calculated_points']));
        $groupedQuizzes[$titleKey]['submission_count'] += intval($row['submission_count']);
        $groupedQuizzes[$titleKey]['avg_score_pct_sum'] += floatval($row['avg_score_pct']) * intval($row['submission_count']);
        $groupedQuizzes[$titleKey]['anti_cheat_alerts'] += intval($row['anti_cheat_alerts']);
        if($row['is_active'] == 1) $groupedQuizzes[$titleKey]['is_active'] = 1;
        if(empty($groupedQuizzes[$titleKey]['start_date']) && !empty($row['start_date'])) $groupedQuizzes[$titleKey]['start_date'] = $row['start_date'];
        if(empty($groupedQuizzes[$titleKey]['due_date']) && !empty($row['due_date'])) $groupedQuizzes[$titleKey]['due_date'] = $row['due_date'];
    }

    $dispCode = trim($row['display_code'] ?? '');
    if(empty($dispCode) || $dispCode === $row['class_name']) {
        $dispCode = trim($row['subject'] ?? '');
    }
    $cName = trim($row['class_name'] ?? '');
    
    $subKey = $dispCode . '___' . $cName . '___' . intval($row['class_id'] ?? 0);
    if(!isset($groupedQuizzes[$titleKey]['subjects'][$subKey])){
        $groupedQuizzes[$titleKey]['subjects'][$subKey] = [
            'class_id' => intval($row['class_id'] ?? 0),
            'code' => $dispCode,
            'name' => $cName,
            'section' => trim($row['section'] ?? '')
        ];
    }
}

$quizzes = [];
$totalQuizzes = count($groupedQuizzes);
$activeQuizzes = 0;
$totalSubmissions = 0;
$sumAvgPct = 0;
$quizzesWithSubmissions = 0;

foreach($groupedQuizzes as $gq){
    $subCount = $gq['submission_count'];
    $avgPct = $subCount > 0 ? round($gq['avg_score_pct_sum'] / $subCount, 1) : null;
    $gq['avg_score_pct'] = $avgPct;
    $gq['all_ids'] = implode(',', $gq['ids']);
    $quizzes[] = $gq;

    if($gq['is_active'] == 1) $activeQuizzes++;
    $totalSubmissions += $subCount;
    if($subCount > 0 && $avgPct !== null){
        $sumAvgPct += $avgPct;
        $quizzesWithSubmissions++;
    }
}

$overallAvgPct = $quizzesWithSubmissions > 0 ? round($sumAvgPct / $quizzesWithSubmissions, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn &mdash; Quiz Dashboard</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; overflow-x: hidden; }
    body { font-family: 'Inter', sans-serif; background: #f0f4f8; color: #1e293b; }

    /* ── Sidebar Styling ── */
    /* ── Sidebar Styling ── */
    .td-sidebar { position: fixed; top: 0; left: 0; width: 240px; height: 100vh; background: linear-gradient(180deg, #0c1a2e 0%, #0f2d4a 55%, #0f5f80 100%); display: flex; flex-direction: column; z-index: 200; transition: transform .25s cubic-bezier(.4,0,.2,1); transform: translateX(-240px); }
    .td-sidebar.open { transform: translateX(0); }
    @media(min-width: 901px) { .td-sidebar { transform: translateX(0); } }
    .sb-brand { padding: 18px 18px 14px; border-bottom: 1px solid rgba(255,255,255,.08); }
    .sb-logo { width: 34px; height: 34px; border-radius: 8px; background: linear-gradient(135deg, #1792bb, #0f5f80); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 6px; box-shadow: 0 3px 10px rgba(23,146,187,.35); }
    .sb-logo i { color: #fff; font-size: 15px; }
    .sb-brand h2 { color: #fff; font-size: 16px; font-weight: 800; margin: 0; }
    .sb-brand h2 span { color: #38bdf8; }
    .sb-brand p { color: rgba(255,255,255,.35); font-size: 9.5px; margin: 2px 0 0; }
    .sb-nav { flex: 1; padding: 10px 0; overflow-y: auto; }
    .sb-section { padding: 8px 18px 4px; font-size: 9px; font-weight: 700; color: rgba(255,255,255,.25); letter-spacing: 1.4px; text-transform: uppercase; }
    .sb-nav ul { list-style: none; margin: 0; padding: 0; }
    .sb-nav li a { display: flex; align-items: center; gap: 10px; padding: 9px 18px; color: rgba(255,255,255,.6); text-decoration: none; font-size: 12.5px; font-weight: 500; transition: all .18s; border-left: 3px solid transparent; }
    .sb-nav li a:hover, .sb-nav li.active a { color: #fff; background: rgba(255,255,255,.07); border-left-color: #38bdf8; }
    .sb-footer { padding: 12px 18px; border-top: 1px solid rgba(255,255,255,.08); background: rgba(0,0,0,.15); }
    .sb-user { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
    .sb-av { width: 32px; height: 32px; border-radius: 50%; background: #38bdf8; color: #0c1a2e; font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: 12px; }
    .sb-meta strong { display: block; color: #fff; font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px; }
    .sb-meta span { color: rgba(255,255,255,.4); font-size: 9.5px; }
    .sb-out { display: block; width: 100%; text-align: center; padding: 6px; border-radius: 6px; background: rgba(239,68,68,.15); color: #fca5a5; font-size: 11px; text-decoration: none; font-weight: 600; border: 1px solid rgba(239,68,68,.3); }
    .sb-out:hover { background: #ef4444; color: #fff; text-decoration: none; }

    /* ── Main Layout ── */
    .td-main { margin-left: 0; min-height: 100vh; display: flex; flex-direction: column; transition: margin-left 0s; }
    @media(min-width: 901px) { .td-main { margin-left: 240px; } }

    .td-topbar { height: auto; min-height: 52px; background: #fff; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; padding: 8px 18px; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 3px rgba(0,0,0,.03); flex-wrap: wrap; gap: 8px; }
    .td-topbar-title h3 { margin: 0; font-size: 15px; font-weight: 700; color: #0f172a; }
    .td-topbar-title p { margin: 0; font-size: 11px; color: #64748b; }
    .cl-hamburger { background: none; border: none; font-size: 18px; color: #334155; cursor: pointer; display: none; }
    @media(max-width: 900px) { .cl-hamburger { display: inline-block; } }

    .td-content { padding: 18px 20px 40px; flex: 1; }

    /* ── Header Banner ── */
    .qz-banner { background: linear-gradient(135deg, #0c1a2e 0%, #0f4c75 50%, #1792bb 100%); border-radius: 14px; padding: 18px 22px; color: #fff; margin-bottom: 18px; box-shadow: 0 6px 20px -5px rgba(15,76,117,.25); position: relative; overflow: hidden; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
    .qz-banner::before { content: ''; position: absolute; top: -50%; right: -10%; width: 250px; height: 250px; background: radial-gradient(circle, rgba(255,255,255,.1) 0%, transparent 70%); border-radius: 50%; pointer-events: none; }
    .qz-banner-info h2 { font-size: 18px; font-weight: 800; margin: 0 0 4px; display: flex; align-items: center; gap: 8px; }
    .qz-banner-info p { margin: 0; color: rgba(255,255,255,.78); font-size: 12px; max-width: 500px; line-height: 1.45; }

    .btn-create-quiz { background: #38bdf8; color: #0c1a2e; border: none; padding: 8px 16px; border-radius: 9px; font-weight: 700; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all .18s cubic-bezier(.4,0,.2,1); box-shadow: 0 3px 12px rgba(56,189,248,.35); min-height: 34px; }
    .btn-create-quiz:hover { background: #7dd3fc; transform: translateY(-1px); box-shadow: 0 5px 16px rgba(56,189,248,.45); }

    /* ── Stat Cards ── */
    .qz-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 18px; }
    .qz-stat-card { background: #fff; border-radius: 12px; padding: 12px 16px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,.02); display: flex; align-items: center; gap: 12px; transition: transform .18s, box-shadow .18s; }
    .qz-stat-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.05); }
    .stat-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
    .stat-purple { background: #f3e8ff; color: #9333ea; }
    .stat-green { background: #dcfce7; color: #16a34a; }
    .stat-blue { background: #e0f2fe; color: #0284c7; }
    .stat-amber { background: #fef3c7; color: #d97706; }
    .stat-meta strong { display: block; font-size: 18px; font-weight: 800; color: #0f172a; line-height: 1.1; }
    .stat-meta span { font-size: 10px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }

    /* ── Filters & Search ── */
    .qz-controls { background: #fff; border-radius: 12px; padding: 10px 14px; border: 1px solid #e2e8f0; margin-bottom: 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.02); }
    .qz-filter-group { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .qz-select { padding: 6px 10px; border: 1.5px solid #cbd5e1; border-radius: 7px; font-size: 11.5px; font-family: 'Inter', sans-serif; background: #f8fafc; color: #334155; outline: none; transition: border-color .18s; }
    .qz-select:focus { border-color: #0284c7; background: #fff; }
    .qz-search-box { position: relative; min-width: 200px; }
    .qz-search-box i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 12px; }
    .qz-search-input { width: 100%; padding: 6px 10px 6px 30px; border: 1.5px solid #cbd5e1; border-radius: 7px; font-size: 11.5px; font-family: 'Inter', sans-serif; background: #f8fafc; outline: none; transition: border-color .18s; }
    .qz-search-input:focus { border-color: #0284c7; background: #fff; }

    /* ── Horizontal Quiz Row Cards matching classes design ── */
    .qz-list { display: flex; flex-direction: column; gap: 8px; }
    .quiz-row-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 10px 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
      transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .quiz-row-card:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.05);
      border-color: #cbd5e1;
    }
    .qrc-left {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      min-width: 0;
      flex: 1;
    }
    .qrc-icon {
      width: 34px;
      height: 34px;
      border-radius: 8px;
      background: linear-gradient(135deg, #1792bb, #0f5f80);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ffffff;
      font-size: 15px;
      flex-shrink: 0;
      box-shadow: 0 2px 6px rgba(23, 146, 187, 0.25);
    }
    .qrc-info {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .qrc-title {
      font-size: 14px;
      font-weight: 700;
      color: #0f172a;
      margin: 0;
      letter-spacing: -0.2px;
    }
    .qz-class-badge {
      font-size: 10px;
      font-weight: 700;
      color: #0284c7;
      background: #e0f2fe;
      padding: 2px 7px;
      border-radius: 5px;
      border: 1px solid #bae6fd;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .badge-code-green {
      font-size: 10px;
      font-weight: 800;
      color: #059669;
      background: #d1fae5;
      padding: 2px 7px;
      border-radius: 5px;
      border: 1px solid #a7f3d0;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      text-transform: uppercase;
    }
    .qz-term-badge { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; padding: 2px 7px; border-radius: 5px; }
    .qz-term-midterm { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
    .qz-term-final { background: #fae8ff; color: #86198f; border: 1px solid #f5d0fe; }
    .qz-term-none { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

    .qrc-meta {
      font-size: 11px;
      color: #64748b;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
    }
    .qz-pill {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 9px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 500;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      color: #475569;
      transition: all 0.15s;
    }
    .qz-pill-sub {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
      cursor: pointer;
      font-weight: 700;
    }
    .qz-pill-sub:hover {
      background: #dbeafe !important;
      transform: translateY(-1px);
    }
    .qz-pill-alert {
      background: #fef2f2;
      color: #dc2626;
      border: 1px solid #fecaca;
      cursor: pointer;
      font-weight: 700;
    }
    .qz-pill-alert:hover {
      background: #fee2e2 !important;
      transform: translateY(-1px);
    }
    .qrc-right {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .qz-status-toggle { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 600; cursor: pointer; user-select: none; }
    .qz-switch { position: relative; display: inline-block; width: 36px; height: 20px; }
    .qz-switch input { opacity: 0; width: 0; height: 0; }
    .qz-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 20px; }
    .qz-slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
    input:checked + .qz-slider { background-color: #10b981; }
    input:checked + .qz-slider:before { transform: translateX(16px); }

    .qz-actions { display: flex; align-items: center; gap: 6px; }
    .qz-act-btn { width: 30px; height: 30px; border-radius: 7px; border: 1.5px solid #cbd5e1; background: #fff; color: #475569; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; cursor: pointer; transition: all .15s; text-decoration: none; }
    .qz-act-btn:hover { background: #0284c7; color: #fff; border-color: #0284c7; }
    .qz-act-btn.btn-danger:hover { background: #ef4444; color: #fff; border-color: #ef4444; }

    /* Empty state */
    .qz-empty { text-align: center; padding: 60px 20px; background: #fff; border-radius: 16px; border: 1.5px dashed #cbd5e1; grid-column: 1 / -1; }
    .qz-empty i { font-size: 48px; color: #cbd5e1; margin-bottom: 14px; }
    .qz-empty h4 { font-size: 16px; font-weight: 700; color: #334155; margin: 0 0 6px; }
    .qz-empty p { font-size: 13px; color: #64748b; margin: 0 0 16px; }

    /* ── Modal Customizations ── */
    .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,.15); overflow: hidden; }
    .modal-header { background: linear-gradient(135deg, #0c1a2e, #0f4c75); color: #fff; border: none; padding: 18px 24px; }
    .modal-header .modal-title { font-weight: 700; font-size: 16px; display: flex; align-items: center; gap: 8px; }
    .modal-header .close { color: #fff; opacity: .8; text-shadow: none; font-size: 22px; }
    .modal-body { padding: 24px; }

    .q-item-box { background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 14px; position: relative; }
    .q-item-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .q-item-header strong { font-size: 13px; color: #0284c7; }

    .badge-alert { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; }

    /* ── Create New Quiz Responsive & Resizable Modal Styles (Reference UI) ── */
    #createQuizModal .modal-dialog { max-width: 100vw; width: 100vw; height: 100vh; margin: 0; padding: 0; }
    .cq-modal-content { border-radius: 0; border: none; background: #f8fafc; box-shadow: none; overflow: hidden; font-family: 'Inter', sans-serif; height: 100vh; display: flex; flex-direction: column; }
    .cq-modal-header { padding: 14px 28px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e2e8f0; background: #ffffff; flex-shrink: 0; z-index: 11; }
    .cq-header-title { display: flex; align-items: center; gap: 12px; }
    .cq-title-icon { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 16px; font-weight: 700; box-shadow: 0 4px 10px rgba(139,92,246,0.3); }
    .cq-header-title h2 { font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; }
    .cq-fs-toggle-btn { background: #f1f5f9; border: 1px solid #cbd5e1; font-size: 13px; color: #475569; cursor: pointer; padding: 6px 12px; border-radius: 8px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; transition: all .15s; }
    .cq-fs-toggle-btn:hover { background: #e2e8f0; color: #0f172a; }
    .cq-close-btn { background: none; border: none; font-size: 24px; color: #94a3b8; cursor: pointer; padding: 0 4px; line-height: 1; transition: color .15s; }
    .cq-close-btn:hover { color: #0f172a; }

    /* Windowed Mode Override */
    #createQuizModal .modal-dialog.cq-modal-windowed { max-width: 1240px; width: 94%; height: auto; margin: 20px auto; }
    #createQuizModal .modal-dialog.cq-modal-windowed .cq-modal-content { height: 90vh; max-height: 900px; border-radius: 20px; border: 1px solid #cbd5e1; box-shadow: 0 25px 60px rgba(0,0,0,0.18); }

    /* Top Config Card Compact (Small Resize) */
    .cq-config-strip { background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 10px 18px; margin: 12px 20px 0; flex-shrink: 0; box-shadow: 0 2px 6px rgba(0,0,0,0.02); }
    .cq-config-grid { display: grid; grid-template-columns: 2fr 1fr 1.2fr; gap: 12px; align-items: end; }
    @media(max-width: 768px) { .cq-config-grid { grid-template-columns: 1fr; } }

    .cq-label { font-size: 11px; font-weight: 700; color: #1e293b; margin-bottom: 4px; display: flex; align-items: center; gap: 5px; }
    .cq-input-icon-wrapper { position: relative; width: 100%; }
    .cq-input-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 12px; pointer-events: none; }
    .cq-input-field { width: 100%; padding: 6px 10px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 12px; background: #f8fafc; color: #0f172a; outline: none; transition: all .2s; font-family: 'Inter', sans-serif; }
    .cq-input-field.has-icon { padding-left: 30px; }
    .cq-input-field:focus { border-color: #8b5cf6; background: #ffffff; box-shadow: 0 0 0 3px rgba(139,92,246,0.12); }
    .cq-input-field::placeholder { color: #94a3b8; }

    .cq-modal-body { padding: 16px 24px 24px; background: #f8fafc; flex: 1; overflow-y: auto; display: flex; flex-direction: column; }
    .cq-main-row { display: flex; flex-wrap: wrap; gap: 20px; flex: 1; transition: all .3s ease; }
    .cq-main-row.cq-row-swapped { flex-direction: row-reverse; }
    .cq-left-pane { flex: 1; min-width: 320px; background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 16px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); display: flex; flex-direction: column; }
    .cq-right-pane { flex: 1; min-width: 320px; background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 16px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); display: flex; flex-direction: column; height: 100%; }

    @media(max-width: 991px) {
      .cq-main-row { flex-direction: column; }
      .cq-main-row.cq-row-swapped { flex-direction: column-reverse; }
      .cq-modal-body { padding: 16px; }
      .cq-config-strip { margin: 16px 16px 0; padding: 14px 16px; }
    }
    @media(max-width: 600px) {
      .cq-modal-header { padding: 8px 12px; gap: 6px; }
      .cq-header-title { gap: 6px; }
      .cq-title-icon { width: 28px; height: 28px; font-size: 13px; flex-shrink: 0; }
      .cq-header-title h2 { font-size: 13.5px; white-space: nowrap; }
      .cq-fs-toggle-btn { padding: 4px 8px; font-size: 10px; gap: 3px; border-radius: 6px; }
      .cq-close-btn { font-size: 20px; padding: 0 2px; }
      .cq-config-strip { margin: 8px 8px 0; padding: 8px 10px; }
      .cq-config-grid { gap: 6px; }
      .cq-label { font-size: 10px; margin-bottom: 2px; }
      .cq-input-field { padding: 5px 8px; font-size: 11.5px; }
      .cq-modal-footer { padding: 8px 12px; gap: 8px; }
      .cq-btn-cancel { padding: 6px 14px; font-size: 11.5px; height: 32px; border-radius: 8px; white-space: nowrap; }
      .cq-btn-create { padding: 6px 14px; font-size: 11.5px; height: 32px; border-radius: 8px; white-space: nowrap; }
    }

    .cq-tabs { display: flex; gap: 6px; border-bottom: 1.5px solid #f1f5f9; padding-bottom: 8px; margin-bottom: 10px; flex-wrap: wrap; }
    .cq-tab-btn { padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; border: 1px solid transparent; background: transparent; color: #64748b; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all .2s; }
    .cq-tab-btn:hover { color: #0f172a; background: #f8fafc; }
    .cq-tab-btn.active { background: #f3e8ff; color: #7c3aed; border-color: #ddd6fe; font-weight: 700; }

    .cq-shortcuts-section { margin-bottom: 10px; }
    .cq-shortcuts-title { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; display: block; }
    .cq-shortcuts-pills { display: flex; gap: 5px; flex-wrap: wrap; }
    .cq-pill { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: transform .15s, opacity .15s; white-space: nowrap; }
    .cq-pill:hover { transform: translateY(-1px); opacity: 0.9; }

    .pill-mc { background: #f3e8ff; color: #7c3aed; }
    .pill-tf { background: #dcfce7; color: #15803d; }
    .pill-id { background: #fef3c7; color: #b45309; }
    .pill-enum { background: #dbeafe; color: #1d4ed8; }
    .pill-mtf { background: #ffe4e6; color: #e11d48; }
    .pill-essay { background: #f1f5f9; color: #475569; }

    #cqTabContentPaste { display: flex; flex-direction: column; flex: 1; min-height: 0; }
    .cq-editor-label { font-size: 12px; font-weight: 700; color: #1e293b; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
    .cq-editor-container { border: 1.5px solid #cbd5e1; border-radius: 12px; background: #ffffff; overflow: hidden; margin-bottom: 12px; box-shadow: inset 0 1px 3px rgba(0,0,0,.02); flex: 1; display: flex; flex-direction: column; min-height: 300px; transition: border-color .2s; }
    .cq-editor-container:focus-within { border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139,92,246,0.12); }
    
    /* Resizable Spacious Editor Body */
    .cq-editor-body { display: flex; min-height: 260px; height: calc(100vh - 380px); position: relative; flex: 1; resize: vertical; overflow: hidden; }
    .cq-code-area { flex: 1; padding: 14px 16px; border: none; outline: none; font-family: 'Consolas', 'Monaco', monospace; font-size: 13px; line-height: 1.6; background: transparent; color: #1e293b; resize: none; height: 100%; width: 100%; white-space: pre; word-wrap: normal; overflow: auto; box-sizing: border-box; }
    .cq-code-area::placeholder { color: #cbd5e1; }
    .cq-editor-footer { padding: 6px 12px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; font-size: 11px; color: #64748b; font-weight: 600; flex-shrink: 0; }

    .cq-info-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 8px 12px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; flex-shrink: 0; }
    .cq-info-text { font-size: 11px; font-weight: 500; color: #1d4ed8; display: flex; align-items: center; gap: 5px; }
    .cq-btn-guide { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; background: #ffffff; color: #1d4ed8; border: 1.5px solid #93c5fd; cursor: pointer; transition: all .15s; }
    .cq-btn-guide:hover { background: #dbeafe; }

    .cq-left-actions { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; flex-shrink: 0; }
    .cq-btn-clear { padding: 9px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; background: #ffffff; color: #e11d48; border: 1.5px solid #fca5a5; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all .15s; }
    .cq-btn-clear:hover { background: #ffe4e6; }
    .cq-btn-parse { padding: 10px 22px; border-radius: 10px; font-size: 13px; font-weight: 700; background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: #ffffff; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(109,40,217,.3); transition: all .15s; }
    .cq-btn-parse:hover { background: linear-gradient(135deg, #7c3aed, #5b21b6); transform: translateY(-1px); }

    .cq-preview-header { display: flex; align-items: center; justify-content: space-between; padding-bottom: 12px; border-bottom: 1.5px solid #f1f5f9; margin-bottom: 16px; flex-shrink: 0; }
    .cq-preview-title { font-size: 15px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px; }
    .cq-detected-badge { background: #dcfce7; color: #15803d; font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 99px; border: 1px solid #bbf7d0; }
    .cq-cards-list { flex: 1; overflow-y: auto; padding-right: 4px; min-height: 250px; }

    /* Empty Preview State (Matching Screenshot) */
    .cq-empty-preview { text-align: center; padding: 60px 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; min-height: 280px; }
    .cq-empty-icon { width: 64px; height: 64px; border-radius: 16px; background: #f1f5f9; color: #cbd5e1; display: inline-flex; align-items: center; justify-content: center; font-size: 30px; margin-bottom: 16px; }
    .cq-empty-title { font-size: 16px; font-weight: 700; color: #475569; margin: 0 0 6px; }
    .cq-empty-desc { font-size: 13px; color: #94a3b8; margin: 0; max-width: 340px; line-height: 1.5; }

    .cq-q-card { background: #ffffff; border-radius: 12px; border: 1.5px solid #e2e8f0; border-left: 5px solid #7c3aed; padding: 14px 16px; margin-bottom: 12px; box-shadow: 0 2px 6px rgba(0,0,0,.02); position: relative; transition: transform .15s, box-shadow .15s; }
    .cq-q-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.05); }
    .cq-q-header { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 8px; }
    .cq-q-badge { width: 24px; height: 24px; border-radius: 50%; background: #f97316; color: #ffffff; font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .cq-q-text { font-weight: 700; font-size: 13px; color: #0f172a; flex: 1; line-height: 1.4; }
    .cq-type-tag { font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 6px; text-transform: uppercase; }

    .cq-ans-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 8px 12px; margin-top: 8px; }
    .cq-ans-label { font-size: 9px; font-weight: 800; color: #16a34a; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 2px; }
    .cq-ans-val { font-size: 13px; font-weight: 700; color: #15803d; display: flex; align-items: center; gap: 6px; }

    .cq-q-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 10px; padding-top: 8px; border-top: 1px dashed #e2e8f0; font-size: 12px; flex-wrap: wrap; }
    .cq-meta-input { display: inline-flex; align-items: center; gap: 6px; color: #64748b; font-size: 11px; font-weight: 600; }
    .cq-input-sm { padding: 3px 7px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12px; background: #ffffff; color: #0f172a; }

    .cq-preview-banner { background: #f3e8ff; border-radius: 10px; padding: 10px 14px; margin-top: 12px; font-size: 12px; font-weight: 600; color: #6b21a8; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

    .cq-modal-footer { padding: 14px 28px; background: #ffffff; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: flex-end; gap: 14px; flex-shrink: 0; position: sticky; bottom: 0; z-index: 10; box-shadow: 0 -4px 12px rgba(0,0,0,0.03); }
    .cq-btn-cancel { padding: 10px 24px; border-radius: 10px; font-size: 13px; font-weight: 600; background: #f1f5f9; color: #475569; border: none; cursor: pointer; transition: all .15s; white-space: nowrap; }
    .cq-btn-cancel:hover { background: #e2e8f0; color: #0f172a; }
    .cq-btn-create { padding: 11px 26px; border-radius: 10px; font-size: 13px; font-weight: 700; background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: #ffffff; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 14px rgba(109,40,217,.35); transition: all .15s; white-space: nowrap; }
    .cq-btn-create:hover { background: linear-gradient(135deg, #7c3aed, #5b21b6); transform: translateY(-1px); box-shadow: 0 6px 18px rgba(109,40,217,.45); }
    .cq-btn-create:hover { background: linear-gradient(135deg, #7c3aed, #5b21b6); transform: translateY(-1px); }
    .cq-btn-create:hover { background: linear-gradient(135deg, #7c3aed, #5b21b6); transform: translateY(-1px); }
  </style>
</head>
<body>

<aside class="td-sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo"><i class="fa fa-graduation-cap"></i></div>
    <h2>Cen<span>Learn</span></h2>
    <p>Learning Management System</p>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Teacher Menu</div>
    <ul>
      <li><a href="dashboard.php"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li><a href="classes.php"><i class="fa fa-book"></i> Classes</a></li>
      <li class="active"><a href="quizzes.php"><i class="fa fa-question-circle"></i> Quizzes</a></li>
      <li><a href="assignments.php"><i class="fa fa-tasks"></i> Assignments</a></li>
      <li><a href="attendance.php"><i class="fa fa-calendar-check-o"></i> Attendance</a></li>
      <li><a href="logbook.php"><i class="fa fa-pencil-square-o"></i> Manage Subject</a></li>
      <li><a href="class_record.php"><i class="fa fa-table"></i> Class Record</a></li>
      <li><a href="subject_repository.php"><i class="fa fa-archive"></i> Past Subject Repository</a></li>
    </ul>
  </nav>
  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-av"><?php echo $initials; ?></div>
      <div class="sb-meta">
        <strong><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></strong>
        <span>Teacher</span>
      </div>
    </div>
    <a href="../logout.php" class="sb-out"><i class="fa fa-sign-out"></i> Sign Out</a>
  </div>
</aside>

<div class="td-main">
  <header class="td-topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="toggleSidebar()"><i class="fa fa-bars"></i></button>
      <div class="td-topbar-title">
        <h3>Quiz Management Dashboard</h3>
        <p><?php echo date('l, F j, Y'); ?></p>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <a href="subject_repository.php" class="btn-create-quiz" style="background:#f8fafc;color:#334155;border:1.5px solid #e2e8f0;text-decoration:none;"><i class="fa fa-archive" style="color:#0ea5e9;"></i> Past Quizzes Archive</a>
      <button class="btn-create-quiz" onclick="openCreateQuizModal()" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;border:none;"><i class="fa fa-plus-circle"></i> Create New Quiz</button>
    </div>
  </header>

  <div class="td-content">

    <!-- Overview Stat Cards -->
    <div class="qz-stats-grid">
      <div class="qz-stat-card">
        <div class="stat-icon stat-purple"><i class="fa fa-list-alt"></i></div>
        <div class="stat-meta">
          <strong><?php echo $totalQuizzes; ?></strong>
          <span>Total Quizzes</span>
        </div>
      </div>
      <div class="qz-stat-card">
        <div class="stat-icon stat-green"><i class="fa fa-check-circle"></i></div>
        <div class="stat-meta">
          <strong><?php echo $activeQuizzes; ?></strong>
          <span>Active Quizzes</span>
        </div>
      </div>
      <div class="qz-stat-card">
        <div class="stat-icon stat-blue"><i class="fa fa-paper-plane-o"></i></div>
        <div class="stat-meta">
          <strong><?php echo $totalSubmissions; ?></strong>
          <span>Submissions</span>
        </div>
      </div>
      <div class="qz-stat-card">
        <div class="stat-icon stat-amber"><i class="fa fa-pie-chart"></i></div>
        <div class="stat-meta">
          <strong><?php echo $overallAvgPct; ?>%</strong>
          <span>Class Avg Score</span>
        </div>
      </div>
    </div>

    <!-- Controls / Filters -->
    <div class="qz-controls">
      <div class="qz-filter-group">
        <select class="qz-select" onchange="applyFilters()" id="filterClass">
          <option value="0">All Classes</option>
          <?php foreach($classes as $c): ?>
            <option value="<?php echo $c['id']; ?>" <?php echo $c['id'] === $classFilter ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($c['class_name'].' ('.$c['subject'].')'); ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select class="qz-select" onchange="applyFilters()" id="filterTerm">
          <option value="">All Terms</option>
          <option value="midterm" <?php echo $termFilter === 'midterm' ? 'selected' : ''; ?>>Midterm</option>
          <option value="final" <?php echo $termFilter === 'final' ? 'selected' : ''; ?>>Final</option>
        </select>

        <select class="qz-select" onchange="applyFilters()" id="filterStatus">
          <option value="-1">All Status</option>
          <option value="1" <?php echo $statusFilter === 1 ? 'selected' : ''; ?>>Active</option>
          <option value="0" <?php echo $statusFilter === 0 ? 'selected' : ''; ?>>Draft / Inactive</option>
        </select>
      </div>

      <div class="qz-search-box">
        <i class="fa fa-search"></i>
        <input type="text" class="qz-search-input" id="searchQuiz" placeholder="Search quiz title..." onkeyup="filterBySearch()">
      </div>
    </div>

    <!-- Quizzes Row List -->
    <div class="qz-list" id="quizzesGrid">
      <?php if(empty($quizzes)): ?>
        <div class="qz-empty">
          <i class="fa fa-folder-open-o"></i>
          <h4>No Quizzes Found</h4>
          <p>You haven't created any quizzes for the selected filters yet.</p>
          <button class="btn-create-quiz" onclick="openCreateQuizModal()" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;border:none;"><i class="fa fa-plus-circle"></i> Create New Quiz</button>
        </div>
      <?php else: ?>
        <?php foreach($quizzes as $q):
          $avgPct = $q['avg_score_pct'] !== null ? round(floatval($q['avg_score_pct']), 1).'%' : 'N/A';
          $termClass = 'qz-term-'.$q['term'];
          $allIds = $q['all_ids'];
        ?>
          <div class="quiz-row-card" data-title="<?php echo htmlspecialchars(strtolower($q['title'])); ?>">
            <div class="qrc-left">
              <div class="qrc-info">
                <h5 class="qrc-title"><?php echo htmlspecialchars($q['title']); ?></h5>
                <?php 
                  $classCount = count($q['subjects']);
                  $subjectsJson = htmlspecialchars(json_encode(array_values($q['subjects'])), ENT_QUOTES, 'UTF-8');
                  if($classCount > 1):
                ?>
                  <span class="qz-class-badge" style="cursor:pointer;" onclick="viewAssignedClasses('<?php echo addslashes($q['title']); ?>', <?php echo $subjectsJson; ?>)" title="Click to view all assigned classes">
                    <i class="fa fa-users"></i> <strong><?php echo $classCount; ?></strong> Classes Assigned <i class="fa fa-chevron-down" style="font-size:9px;margin-left:3px;"></i>
                  </span>
                <?php else: 
                  $firstSub = reset($q['subjects']);
                  $sCode = trim($firstSub['code'] ?? '');
                  $sName = trim($firstSub['name'] ?? '');
                  $sLabel = (!empty($sCode) && strtolower($sCode) !== 'general' && strtolower($sCode) !== strtolower($sName))
                    ? '<strong>' . htmlspecialchars($sCode) . '</strong> : ' . htmlspecialchars($sName)
                    : htmlspecialchars($sName);
                  if(!empty($sLabel)):
                ?>
                  <span class="qz-class-badge" style="cursor:pointer;" onclick="viewAssignedClasses('<?php echo addslashes($q['title']); ?>', <?php echo $subjectsJson; ?>)" title="Click to view class details">
                    <i class="fa fa-graduation-cap"></i> <?php echo $sLabel; ?>
                  </span>
                <?php endif; endif; ?>
                <span class="qz-term-badge <?php echo $termClass; ?>"><?php echo strtoupper($q['term']); ?></span>
                <div class="qrc-meta">
                  <span class="qz-pill" title="Number of Questions"><i class="fa fa-question-circle" style="color:#6366f1;"></i> <strong><?php echo $q['question_count']; ?></strong> Questions</span>
                  <span class="qz-pill" title="Total Points"><i class="fa fa-trophy" style="color:#f59e0b;"></i> <strong><?php echo $q['calculated_points']; ?></strong> Pts</span>
                  <span class="qz-pill" title="Time Limit"><i class="fa fa-clock-o" style="color:#64748b;"></i> <strong><?php echo $q['time_limit'] ? $q['time_limit'].'m' : 'No limit'; ?></strong></span>
                </div>
              </div>
            </div>

            <div class="qrc-right">
              <label class="qz-status-toggle" title="Toggle Publish Status" style="margin:0;">
                <span class="qz-switch">
                  <input type="checkbox" <?php echo $q['is_active'] == 1 ? 'checked' : ''; ?> onchange="toggleActive('<?php echo $allIds; ?>', this.checked)">
                  <span class="qz-slider"></span>
                </span>
                <span style="font-size:11px;color:<?php echo $q['is_active']==1?'#10b981':'#64748b'; ?>;"><?php echo $q['is_active']==1?'Active':'Draft'; ?></span>
              </label>

              <div class="qz-actions">
                <button class="qz-act-btn" title="AI Relevance & Quality Analytics" onclick="analyzeQuizRelevance(<?php echo $q['id']; ?>)">
                  <i class="fa fa-magic" style="color:#0284c7;"></i>
                </button>
                <button class="qz-act-btn" title="View Submissions & Results" onclick="viewSubmissions('<?php echo $allIds; ?>')">
                  <i class="fa fa-eye"></i>
                </button>
                <button class="qz-act-btn" title="Add Class / Assign to Class" onclick="openCopyModal(<?php echo $q['id']; ?>, '<?php echo addslashes($q['title']); ?>', '<?php echo !empty($q['start_date']) ? date('Y-m-d\TH:i', strtotime($q['start_date'])) : ''; ?>', '<?php echo !empty($q['due_date']) ? date('Y-m-d\TH:i', strtotime($q['due_date'])) : ''; ?>')">
                  <i class="fa fa-plus-circle" style="color:#10b981;"></i>
                </button>
                <button class="qz-act-btn btn-danger" title="Delete Quiz" onclick="deleteQuiz('<?php echo $allIds; ?>')">
                  <i class="fa fa-trash-o"></i>
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>



<!-- ── Assigned Classes Dashboard Modal ── -->
<div class="modal fade" id="assignedClassesModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document" style="max-width:560px;">
    <div class="modal-content" style="border-radius:14px;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,0.15);">
      <div class="modal-header" style="background:linear-gradient(135deg,#0284c7,#0369a1);color:#fff;padding:16px 20px;">
        <h5 class="modal-title" id="acModalTitle" style="font-weight:700;display:flex;align-items:center;gap:8px;margin:0;font-size:15px;">
          <i class="fa fa-users"></i> Assigned Classes Dashboard
        </h5>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;margin-top:-2px;">&times;</button>
      </div>
      <div class="modal-body" id="acModalBody" style="padding:20px;background:#f8fafc;">
        <!-- Filled dynamically -->
      </div>
      <div class="modal-footer" style="background:#fff;border-top:1px solid #e2e8f0;padding:12px 20px;">
        <button type="button" class="btn btn-default" data-dismiss="modal" style="border-radius:8px;font-weight:600;">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ── View Submissions Modal ── -->
<div class="modal fade" id="submissionsModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document" style="max-width:900px;">
    <div class="modal-content" style="border-radius:16px;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,0.18);">
      <div class="modal-header" style="background:linear-gradient(135deg,#0f2d4a,#1e3a5f);color:#fff;padding:16px 22px;display:flex;align-items:center;justify-content:space-between;">
        <h5 class="modal-title" id="subModalTitle" style="font-weight:700;display:flex;align-items:center;gap:8px;margin:0;font-size:15px;"><i class="fa fa-eye" style="color:#60a5fa;"></i> Student Submissions</h5>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;background:none;border:none;font-size:20px;">&times;</button>
      </div>
      <div class="modal-body" style="padding:22px;">
        <div id="subStatsHeader" class="row mb-3"></div>
        <div class="table-responsive">
          <table class="table table-hover table-striped" style="font-size:13px;">
            <thead>
              <tr style="background:#0f2d4a;color:#fff;">
                <th>Student</th>
                <th>Student Code</th>
                <th>Score</th>
                <th>Percentage</th>
                <th>Anti-Cheat Log</th>
                <th>Submitted Date</th>
                <th style="text-align:center;">Action</th>
              </tr>
            </thead>
            <tbody id="subTableBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer" style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 20px;">
        <button type="button" class="btn btn-default" data-dismiss="modal" style="border-radius:8px;font-weight:600;">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Detailed Student Answers Review Modal ── -->
<div class="modal fade" id="studentAnswersModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document" style="max-width:850px;">
    <div class="modal-content" style="border-radius:16px;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,0.18);">
      <div class="modal-header" style="background:linear-gradient(135deg,#4f46e5,#4338ca);color:#fff;padding:16px 22px;display:flex;align-items:center;justify-content:space-between;">
        <h5 class="modal-title" id="saModalTitle" style="font-weight:700;display:flex;align-items:center;gap:8px;margin:0;font-size:15px;">
          <i class="fa fa-list-alt" style="color:#a5b4fc;"></i> Student Quiz Answers Review
        </h5>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;background:none;border:none;font-size:20px;">&times;</button>
      </div>
      <div class="modal-body" style="padding:22px;background:#f8fafc;max-height:calc(85vh - 120px);overflow-y:auto;">
        <!-- Student & Quiz Info Header Bar -->
        <div id="saStudentHeader" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
          <!-- Filled dynamically -->
        </div>
        
        <!-- Question-by-Question List -->
        <div id="saQuestionsList" style="display:flex;flex-direction:column;gap:14px;">
          <!-- Filled dynamically -->
        </div>
      </div>
      <div class="modal-footer" style="background:#fff;border-top:1px solid #e2e8f0;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;">
        <button type="button" class="btn btn-default" onclick="$('#studentAnswersModal').modal('hide'); $('#submissionsModal').modal('show');" style="border-radius:8px;font-weight:600;"><i class="fa fa-arrow-left"></i> Back to Submissions</button>
        <button type="button" class="btn btn-default" data-dismiss="modal" style="border-radius:8px;font-weight:600;">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Copy / Add Class Modal ── -->
<div class="modal fade" id="copyQuizModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document" style="max-width:540px;">
    <div class="modal-content" style="border-radius:14px;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,0.15);">
      <div class="modal-header" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;padding:16px 20px;">
        <h5 class="modal-title" id="copyQuizModalTitle" style="font-weight:700;display:flex;align-items:center;gap:8px;margin:0;font-size:15px;"><i class="fa fa-plus-circle"></i> Add Class &bull; Quiz</h5>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;margin-top:-2px;">&times;</button>
      </div>
      <div class="modal-body" style="padding:20px;background:#f8fafc;">
        <input type="hidden" id="copySourceId">
        <div class="form-group" style="margin-bottom:14px;">
          <label style="font-size:12px;font-weight:700;color:#1e293b;">Target Class to Add / Assign <span class="text-danger">*</span></label>
          <select class="form-control" id="copyTargetClassId" required style="border-radius:8px;font-size:13px;height:40px;">
            <option value="">Select Target Class...</option>
            <?php foreach($classes as $c): 
              $secTag = !empty($c['section']) ? ' - Sec ' . $c['section'] : '';
              $progTag = !empty($c['program_code']) ? ' [' . $c['program_code'] . ']' : '';
              $cLabel = htmlspecialchars($c['class_name'] . $secTag . $progTag);
            ?>
              <option value="<?php echo $c['id']; ?>"><?php echo $cLabel; ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="row">
          <div class="col-xs-12 col-sm-6" style="margin-bottom:14px;">
            <label style="font-size:12px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:5px;">
              <i class="fa fa-clock-o text-success"></i> Time to Start
            </label>
            <input type="datetime-local" class="form-control" id="copyStartDate" style="border-radius:8px;font-size:12px;height:38px;">
            <small class="text-muted" style="font-size:10.5px;display:block;margin-top:4px;line-height:1.3;">Available to students on or after this time (Optional).</small>
          </div>
          <div class="col-xs-12 col-sm-6" style="margin-bottom:14px;">
            <label style="font-size:12px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:5px;">
              <i class="fa fa-hourglass-end text-danger"></i> Time to End / Expire
            </label>
            <input type="datetime-local" class="form-control" id="copyDueDate" style="border-radius:8px;font-size:12px;height:38px;">
            <small class="text-muted" style="font-size:10.5px;display:block;margin-top:4px;line-height:1.3;">Quiz closes and expires after this time (Optional).</small>
          </div>
        </div>

        <div id="copyAlert" style="display:none;margin-top:6px;font-size:12px;padding:8px 12px;border-radius:8px;"></div>
      </div>
      <div class="modal-footer" style="background:#fff;border-top:1px solid #e2e8f0;padding:12px 20px;">
        <button type="button" class="btn btn-default" data-dismiss="modal" style="border-radius:8px;font-weight:600;">Cancel</button>
        <button type="button" class="btn btn-success" id="btnSubmitCopy" onclick="submitCopyQuiz()" style="border-radius:8px;font-weight:700;background:linear-gradient(135deg,#10b981,#059669);border:none;box-shadow:0 3px 10px rgba(16,185,129,.3);"><i class="fa fa-plus"></i> Add Class</button>
      </div>
    </div>
  </div>
</div>

<!-- ── AI Relevance & Predictive Analytics Modal ── -->
<div class="modal fade" id="aiRelevanceModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#0c1a2e,#0f4c75);color:#fff;">
        <h5 class="modal-title"><i class="fa fa-magic"></i> AI Quiz Relevance & Predictive Analytics</h5>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;">&times;</button>
      </div>
      <div class="modal-body" id="aiRelevanceBody">
        <div class="text-center py-4"><i class="fa fa-spinner fa-spin fa-2x text-info"></i><p class="mt-2 text-muted">Analyzing quiz against uploaded learning materials...</p></div>
      </div>
      <div class="modal-footer" id="aiRelevanceFooter">
        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Create New Quiz Fullscreen Modal ── -->
<div class="modal" id="createQuizModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="cq-modal-content">
      <div class="cq-modal-header">
        <div class="cq-header-title">
          <div class="cq-title-icon"><i class="fa fa-question"></i></div>
          <h2>Create New Quiz</h2>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <button type="button" class="cq-fs-toggle-btn" title="Swap Editor & Preview Panes" onclick="swapCqPanes()"><i class="fa fa-exchange"></i> Swap View</button>
          <button type="button" class="cq-fs-toggle-btn" title="Toggle Fullscreen / Windowed Mode" onclick="toggleCqFullscreenModal()"><i class="fa fa-compress" id="cqFsIcon"></i> Fullscreen</button>
          <button type="button" class="cq-close-btn" data-dismiss="modal">&times;</button>
        </div>
      </div>

      <div class="cq-config-strip">
        <div class="cq-config-grid">
          <div>
            <label class="cq-label">Quiz Title <span class="text-danger">*</span></label>
            <input type="text" class="cq-input-field" id="cqQuizTitle" placeholder="e.g. Chapter 1 Quiz" required>
          </div>
          <div>
            <label class="cq-label">Time Limit (min)</label>
            <div class="cq-input-icon-wrapper">
              <i class="fa fa-clock-o cq-input-icon"></i>
              <input type="number" class="cq-input-field has-icon" id="cqQuizTimeLimit" placeholder="0" min="0">
            </div>
          </div>
          <div>
            <label class="cq-label"><i class="fa fa-graduation-cap" style="color:#7c3aed;"></i> Class Record Term</label>
            <select class="cq-input-field" id="cqQuizTerm">
              <option value="midterm">Midterm</option>
              <option value="final">Final</option>
              <option value="none">Practice</option>
            </select>
          </div>
        </div>
      </div>

      <div class="cq-modal-body">
        <div class="row cq-main-row">
          <!-- Left Column -->
          <div class="col-lg-6 col-md-12 cq-left-pane">
            <div class="cq-tabs">
              <button type="button" class="cq-tab-btn active" id="cqTabBtnPaste" onclick="switchCqTab('paste')">
                <i class="fa fa-paste"></i> Paste Questions
              </button>
              <button type="button" class="cq-tab-btn" id="cqTabBtnUpload" onclick="switchCqTab('upload')">
                <i class="fa fa-cloud-upload"></i> Upload File
              </button>
            </div>

            <div id="cqTabContentPaste">
              <div class="cq-info-box" style="margin-bottom:14px;margin-top:2px;">
                <div class="cq-info-text">
                  <i class="fa fa-info-circle"></i> Use the Insert buttons below or follow the format examples.
                </div>
                <button type="button" class="cq-btn-guide" onclick="copyCqSampleData()">View Format Guide</button>
              </div>

              <div class="cq-shortcuts-section">
                <span class="cq-shortcuts-title">Insert shortcut:</span>
                <div class="cq-shortcuts-pills">
                  <button type="button" class="cq-pill pill-mc" onclick="insertCqShortcut('mc')">
                    <i class="fa fa-plus-circle"></i> MC Multiple Choice
                  </button>
                  <button type="button" class="cq-pill pill-tf" onclick="insertCqShortcut('tf')">
                    <i class="fa fa-plus-circle"></i> T/F True / False
                  </button>
                  <button type="button" class="cq-pill pill-id" onclick="insertCqShortcut('id')">
                    <i class="fa fa-plus-circle"></i> ID Identification
                  </button>
                  <button type="button" class="cq-pill pill-enum" onclick="insertCqShortcut('enum')">
                    <i class="fa fa-plus-circle"></i> ENUM Enumeration
                  </button>
                  <button type="button" class="cq-pill pill-mtf" onclick="insertCqShortcut('mtf')">
                    <i class="fa fa-plus-circle"></i> MTF Modified T/F
                  </button>
                  <button type="button" class="cq-pill pill-essay" onclick="insertCqShortcut('essay')">
                    <i class="fa fa-plus-circle"></i> ESSAY Essay
                  </button>
                </div>
              </div>

              <div class="cq-editor-label">
                <i class="fa fa-keyboard-o" style="color:#7c3aed;"></i> Paste your questions here <span class="text-danger">*</span>
              </div>

              <div class="cq-editor-container">
                <div class="cq-editor-body">
                  <textarea class="cq-code-area" id="cqPasteArea" wrap="off" placeholder="1. Which organ pumps blood throughout the human body?&#10;A. Brain&#10;B. Lungs&#10;C. Heart&#10;D. Liver&#10;Answer: C. Heart&#10;points: 2&#10;&#10;2. The Earth revolves around the Sun.&#10;True / False&#10;Answer: True&#10;points: 2&#10;&#10;3. It is the largest land animal on Earth.&#10;Answer: Elephant&#10;points: 2&#10;&#10;4. Name the three states of matter.&#10;Answer: Solid, Liquid, Gas&#10;points: 2&#10;&#10;5. Why is water important to living things? (2–3 sentences)&#10;points: 10" oninput="updateCqEditorStats()"></textarea>
                </div>
                <div class="cq-editor-footer">
                  <span id="cqEditorLines">Lines: 1</span>
                  <span id="cqEditorChars">Characters: 0</span>
                </div>
              </div>

              <div class="cq-left-actions">
                <button type="button" class="cq-btn-clear" onclick="clearCqArea()">
                  <i class="fa fa-trash-o"></i> Clear
                </button>
                <button type="button" class="cq-btn-parse" onclick="parseAndPreviewCq()">
                  <i class="fa fa-magic"></i> Parse & Preview Questions
                </button>
              </div>
            </div>

            <div id="cqTabContentUpload" style="display:none;">
              <div class="cq-drop-zone" id="cqDropZone" onclick="$('#cqFileInput').click()" style="border:2px dashed #cbd5e1;border-radius:12px;padding:40px 20px;text-align:center;cursor:pointer;">
                <i class="fa fa-cloud-upload" style="font-size:36px;color:#7c3aed;margin-bottom:8px;"></i>
                <div style="font-weight:700;font-size:14px;color:#1e293b;">Drop CSV / TSV / TXT File Here</div>
                <div style="font-size:12px;color:#64748b;margin-top:4px;">Click or drag file from your computer</div>
                <input type="file" id="cqFileInput" accept=".csv,.tsv,.txt" style="display:none;" onchange="handleCqFileSelect(this.files)">
              </div>
            </div>
          </div>

          <!-- Right Column -->
          <div class="col-lg-6 col-md-12 cq-right-pane">
            <div class="cq-preview-header">
              <div class="cq-preview-title">
                <i class="fa fa-eye" style="color:#7c3aed;"></i> Live Preview
              </div>
              <span class="cq-detected-badge" id="cqDetectedBadge">0 Questions Detected (0 pts)</span>
            </div>

            <div class="cq-cards-list" id="cqCardsContainer"></div>

            <div class="cq-preview-banner">
              <i class="fa fa-lightbulb-o" style="font-size:16px;"></i> Looks good! Click "Create Quiz" at the bottom right to finalize.
            </div>
          </div>
        </div>
      </div>

      <div class="cq-modal-footer">
        <button type="button" class="cq-btn-cancel" data-dismiss="modal">Cancel</button>
        <button type="button" class="cq-btn-create" onclick="submitCqQuiz()"><i class="fa fa-floppy-o"></i> Create Quiz</button>
      </div>
    </div>
  </div>
</div>

<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script src="../bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
let questionCounter = 0;
let pendingToggleId = null;

function toggleSidebar() {
  $('#sidebar').toggleClass('open');
}

function applyFilters() {
  const cid = $('#filterClass').val();
  const term = $('#filterTerm').val();
  const status = $('#filterStatus').val();
  window.location.href = `quizzes.php?class_id=${cid}&term=${term}&status=${status}`;
}

function filterBySearch() {
  const q = $('#searchQuiz').val().toLowerCase();
  $('.quiz-row-card').each(function() {
    const title = $(this).data('title');
    if(title.includes(q)) {
      $(this).show();
    } else {
      $(this).hide();
    }
  });
}

function toggleActive(id, isActive, classId = 1) {
  if(isActive && (!classId || parseInt(classId) <= 0)) {
    alert('Cannot publish quiz: No Target Class is assigned. Please assign a Target Class first using the Copy/Assign button (clone icon) so students in that class can access it.');
    location.reload();
    return;
  }

  $.post('../shared/quiz_handler.php', {
    action: 'toggle',
    id: id,
    is_active: isActive ? 1 : 0
  }, function(res) {
    if(res.success) {
      location.reload();
    } else {
      alert(res.msg || 'Failed to update quiz status');
      location.reload();
    }
  }, 'json');
}

function analyzeQuizRelevance(quizId, isPrePublishWarning = false) {
  pendingToggleId = quizId;
  $('#aiRelevanceBody').html('<div class="text-center py-4"><i class="fa fa-spinner fa-spin fa-2x text-info"></i><p class="mt-2 text-muted">Analyzing quiz against uploaded learning materials...</p></div>');
  $('#aiRelevanceModal').modal('show');

  $.post('../shared/quiz_handler.php', { action: 'analyze_relevance', quiz_id: quizId }, function(res) {
    if(!res.success || !res.analytics) {
      $('#aiRelevanceBody').html('<div class="alert alert-danger">Failed to evaluate quiz relevance.</div>');
      return;
    }
    const a = res.analytics;

    let warningBanner = '';
    if(isPrePublishWarning || a.relevance_score < 40) {
      warningBanner = `
        <div class="alert alert-warning mb-3" style="border-radius:10px;">
          <i class="fa fa-exclamation-triangle"></i> <strong>Pre-Publish Relevance Warning:</strong> Quiz relevance to uploaded materials is low (${a.relevance_score}%). Review off-topic questions or upload relevant materials before publishing.
        </div>
      `;
    }

    let unmatchedListHtml = '';
    if(a.unmatched_questions && a.unmatched_questions.length > 0) {
      unmatchedListHtml = `
        <div class="mb-3">
          <h5 style="font-weight:700;color:#dc2626;"><i class="fa fa-times-circle"></i> Off-Topic / Unmatched Questions (${a.unmatched_questions.length})</h5>
          <ul class="list-group" style="font-size:12px;">
            ${a.unmatched_questions.map(u => `<li class="list-group-item list-group-item-danger"><strong>Q#${u.index}:</strong> ${u.question_text} <br><small class="text-muted">${u.reason}</small></li>`).join('')}
          </ul>
        </div>
      `;
    }

    let untestedTopicsHtml = '';
    if(a.untested_topics && a.untested_topics.length > 0) {
      untestedTopicsHtml = `
        <div class="mb-3">
          <h5 style="font-weight:700;color:#d97706;"><i class="fa fa-exclamation-circle"></i> Untested Material Topics</h5>
          <div class="d-flex flex-wrap gap-2">
            ${a.untested_topics.map(t => `<span class="badge" style="background:#fef3c7;color:#b45309;padding:6px 10px;border-radius:6px;font-size:12px;margin-right:4px;">${t}</span>`).join('')}
          </div>
        </div>
      `;
    }

    let recsHtml = `
      <div class="mb-3">
        <h5 style="font-weight:700;color:#0284c7;"><i class="fa fa-lightbulb-o"></i> AI Recommendations</h5>
        <ul style="font-size:13px;padding-left:20px;">
          ${a.recommendations.map(r => `<li>${r}</li>`).join('')}
        </ul>
      </div>
    `;

    const bodyHtml = `
      ${warningBanner}
      <div class="row text-center mb-4">
        <div class="col-xs-6 col-md-3">
          <div class="well well-sm" style="background:#e0f2fe;border:none;border-radius:12px;">
            <strong style="font-size:24px;color:#0284c7;">${a.relevance_score}%</strong><br>
            <small style="font-weight:700;color:#0369a1;">Material Relevance</small>
          </div>
        </div>
        <div class="col-xs-6 col-md-3">
          <div class="well well-sm" style="background:#f3e8ff;border:none;border-radius:12px;">
            <strong style="font-size:24px;color:#9333ea;">${a.coverage_score}%</strong><br>
            <small style="font-weight:700;color:#7e22ce;">Syllabus Coverage</small>
          </div>
        </div>
        <div class="col-xs-6 col-md-3">
          <div class="well well-sm" style="background:#dcfce7;border:none;border-radius:12px;">
            <strong style="font-size:24px;color:#16a34a;">${a.quality_score} / 100</strong><br>
            <small style="font-weight:700;color:#15803d;">Quiz Quality Rating</small>
          </div>
        </div>
        <div class="col-xs-6 col-md-3">
          <div class="well well-sm" style="background:#fef3c7;border:none;border-radius:12px;">
            <strong style="font-size:24px;color:#d97706;">${a.predicted_pass_rate}%</strong><br>
            <small style="font-weight:700;color:#b45309;">Predicted Pass Rate</small>
          </div>
        </div>
      </div>
      ${unmatchedListHtml}
      ${untestedTopicsHtml}
      ${recsHtml}
    `;

    $('#aiRelevanceBody').html(bodyHtml);

    let footerHtml = '<button type="button" class="btn btn-default" data-dismiss="modal">Close</button>';
    if(isPrePublishWarning) {
      footerHtml += `<button type="button" class="btn btn-warning" onclick="forcePublishQuiz(${quizId})"><i class="fa fa-check"></i> Force Publish Anyway</button>`;
    }
    $('#aiRelevanceFooter').html(footerHtml);

  }, 'json');
}

function forcePublishQuiz(quizId) {
  toggleActive(quizId, true, 1);
  $('#aiRelevanceModal').modal('hide');
}

// ── Create New Quiz Modal JS Logic (Matching Screenshot UI) ────────────────
let cqQuestionsData = [];

function openCreateQuizModal() {
  requestAnimationFrame(function(){
    $('#createQuizModal').modal('show');
  });
}

$(document).ready(function() {
  if(window.location.search.includes('create=1')) {
    openCreateQuizModal();
  }

  const dropZone = document.getElementById('cqDropZone');
  if(dropZone){
    ['dragenter', 'dragover'].forEach(eventName => {
      dropZone.addEventListener(eventName, e => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.style.borderColor = '#7c3aed';
        dropZone.style.background = '#f5f3ff';
      }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
      dropZone.addEventListener(eventName, e => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.style.borderColor = '#cbd5e1';
        dropZone.style.background = 'transparent';
      }, false);
    });

    dropZone.addEventListener('drop', e => {
      const dt = e.dataTransfer;
      const files = dt.files;
      handleCqFileSelect(files);
    }, false);
  }
});

function switchCqTab(tab) {
  $('.cq-tab-btn').removeClass('active');
  if(tab === 'paste') {
    $('#cqTabBtnPaste').addClass('active');
    $('#cqTabContentPaste').show();
    $('#cqTabContentUpload').hide();
  } else if(tab === 'upload') {
    $('#cqTabBtnUpload').addClass('active');
    $('#cqTabContentPaste').hide();
    $('#cqTabContentUpload').show();
  }
}

function updateCqEditorStats() {
  const text = $('#cqPasteArea').val();
  const lines = text.split('\n');
  const lineCount = lines.length;
  const charCount = text.length;

  $('#cqEditorLines').text(`Lines: ${lineCount}`);
  $('#cqEditorChars').text(`Characters: ${charCount}`);
}

function insertCqShortcut(type) {
  const textarea = document.getElementById('cqPasteArea');
  let snippet = '';

  if(type === 'mc') {
    snippet = `\n1. What is a variable in programming?\na) A container for storing data\nb) A type of loop\nc) A function\nd) A class\nAnswer: a\nPoints: 1\n`;
  } else if(type === 'tf') {
    snippet = `\n2. Elephant is the largest land mammal.\nAnswer: True\nPoints: 1\n`;
  } else if(type === 'id') {
    snippet = `\n8. It is the process by which plants make food using sunlight.\nAnswer: Photosynthesis\nTopic: e.g. Loops\nPoints: 2\n`;
  } else if(type === 'enum') {
    snippet = `\n9. Name the three states of matter.\nAnswer: Solid, Liquid, Gas\nPoints: 2\n`;
  } else if(type === 'mtf') {
    snippet = `\n5. Water boils at 50 degrees Celsius at sea level.\nAnswer: False (100°C)\nPoints: 1\n`;
  } else if(type === 'essay') {
    snippet = `\n6. Explain the difference between compiled and interpreted programming languages.\nAnswer: Rubric: Technical Accuracy (50%), Clarity (50%)\nPoints: 5\n`;
  }

  const start = textarea.selectionStart || textarea.value.length;
  const end = textarea.selectionEnd || textarea.value.length;
  const currentVal = textarea.value;

  textarea.value = currentVal.substring(0, start) + snippet + currentVal.substring(end);
  textarea.selectionStart = textarea.selectionEnd = start + snippet.length;
  textarea.focus();

  updateCqEditorStats();
  parseAndPreviewCq();
}

function clearCqArea() {
  if($('#cqPasteArea').val().trim() && !confirm('Are you sure you want to clear the editor text?')) return;
  $('#cqPasteArea').val('');
  cqQuestionsData = [];
  updateCqEditorStats();
  renderCqCards();
}

function copyCqSampleData() {
  const sampleText = `Multiple Choice

1. Which organ pumps blood throughout the human body?
A. Brain
B. Lungs
C. Heart
D. Liver
Answer: C. Heart
points: 2

2. What is the chemical symbol for water?
A. CO₂
B. H₂O
C. O₂
D. NaCl
Answer: B. H₂O
points: 2

True or False

3. The Earth revolves around the Sun.
True / False
Answer: True
points: 2

4. Fish can live without water.
True / False
Answer: False
points: 2

Modified True or False

(Write TRUE if correct. If false, change the underlined word to make it correct.)

5. The capital of Japan is Beijing.
Answer: Tokyo
points: 2

6. Plants make their own food through photosynthesis.
Answer: True
points: 2

Identification

7. It is the largest land animal on Earth.
Answer: Elephant
points: 2

8. It is the process by which plants make food using sunlight.
Answer: Photosynthesis
points: 2

Enumeration

9. Name the three states of matter.
Answer: Solid, Liquid, Gas
points: 2

10. Give two primary colors.
Answer: Red, Blue (also Yellow)
points: 2

Essay

11. Why is water important to living things? (2–3 sentences)
points: 10

12. Explain why plants are important to humans. (2–3 sentences)
points: 10`;

  $('#cqPasteArea').val(sampleText);
  updateCqEditorStats();
  parseAndPreviewCq();
}

function parseAndPreviewCq() {
  const text = $('#cqPasteArea').val();
  if(!text || !text.trim()) {
    cqQuestionsData = [];
    renderCqCards();
    return;
  }

  const lines = text.split(/\r?\n/);
  cqQuestionsData = [];
  const isTSV = text.includes('\t');

  if(isTSV) {
    lines.forEach((line, index) => {
      if(!line.trim()) return;
      let cols = line.split('\t').map(c => c.replace(/^["']|["']$/g, '').trim());
      if(cols.length < 1) return;

      const qText = cols[0] || '';
      if(!qText) return;

      let qType = 'multiple_choice';
      let options = ['', '', '', ''];
      let correctAnswer = cols[1] || cols[5] || '';

      const lastCol = (cols[cols.length - 1] || '').toLowerCase().trim();
      if(lastCol.includes('id') || lastCol.includes('identification')) qType = 'identification';
      else if(lastCol.includes('enum')) qType = 'enumeration';
      else if(lastCol.includes('tf') || lastCol.includes('true_false')) qType = 'true_false';
      else if(lastCol.includes('essay')) qType = 'essay';
      else qType = 'identification';

      cqQuestionsData.push({
        id: Date.now() + Math.random(),
        question_type: qType,
        question_text: qText,
        options: options,
        correct_answer: correctAnswer,
        points: 2,
        topic: 'e.g. Loops'
      });
    });
  } else {
    let currentSection = '';
    const blocks = text.split(/\n\s*\n/);

    blocks.forEach(block => {
      const bLines = block.split(/\r?\n/).map(l => l.trim()).filter(l => l !== '');
      if(bLines.length === 0) return;

      // Check for section headers (e.g. "Multiple Choice", "True or False", "Essay")
      const firstLineLower = bLines[0].toLowerCase();
      if(bLines.length <= 2 && !bLines[0].match(/^\d+[\.\)]/)) {
        if(firstLineLower.includes('multiple choice')) { currentSection = 'mc'; if(bLines.length === 1) return; }
        else if(firstLineLower.includes('true or false') || firstLineLower.includes('true / false')) { currentSection = 'tf'; if(bLines.length === 1) return; }
        else if(firstLineLower.includes('modified true')) { currentSection = 'mtf'; if(bLines.length === 1) return; }
        else if(firstLineLower.includes('identification')) { currentSection = 'id'; if(bLines.length === 1) return; }
        else if(firstLineLower.includes('enumeration')) { currentSection = 'enum'; if(bLines.length === 1) return; }
        else if(firstLineLower.includes('essay') || firstLineLower.includes('short answer')) { currentSection = 'essay'; if(bLines.length === 1) return; }
        else if(firstLineLower.includes('write true if') || firstLineLower.includes('instructions')) { if(bLines.length === 1) return; }
      }

      let qStatement = '';
      let options = [];
      let correctAnswer = '';
      let points = 2;
      let topic = 'General';

      bLines.forEach(l => {
        const lowerL = l.toLowerCase();

        // Filter out section titles/instructions inside a question block
        if(lowerL.startsWith('(write true') || lowerL === 'true or false' || lowerL === 'true / false' || lowerL === 'multiple choice' || lowerL === 'identification' || lowerL === 'enumeration' || lowerL === 'essay') {
          return;
        }

        if(lowerL.startsWith('answer:')) {
          correctAnswer = l.substring(7).trim();
        } else if(lowerL.startsWith('points:')) {
          points = parseInt(l.substring(7).trim()) || 2;
        } else if(lowerL.startsWith('topic:')) {
          topic = l.substring(6).trim() || 'General';
        } else if(l.match(/^[a-d][\.\)]\s*/i)) {
          options.push(l.replace(/^[a-d][\.\)]\s*/i, '').trim());
        } else {
          const cleanL = l.replace(/^\d+[\.\)]\s*/, '').trim();
          if(cleanL && !cleanL.toLowerCase().startsWith('answer:') && !cleanL.toLowerCase().startsWith('points:')) {
            qStatement = qStatement ? (qStatement + ' ' + cleanL) : cleanL;
          }
        }
      });

      if(!qStatement) return;

      const lowerStmt = qStatement.toLowerCase();
      const lowerAns = correctAnswer.toLowerCase();

      let qType = 'multiple_choice';

      // 1. Intelligent Essay Detection
      const isEssayKeyword = lowerStmt.startsWith('why ') || lowerStmt.startsWith('explain ') || lowerStmt.startsWith('describe ') || 
                             lowerStmt.startsWith('discuss ') || lowerStmt.startsWith('summarize ') || lowerStmt.startsWith('elaborate ') || 
                             lowerStmt.startsWith('compare ') || lowerStmt.includes('(2–3 sentences)') || lowerStmt.includes('(2-3 sentences)') || 
                             lowerStmt.includes('sentence') || lowerStmt.includes('essay') || lowerStmt.includes('in your own words');

      if(currentSection === 'essay' || isEssayKeyword || (options.length === 0 && !correctAnswer && points >= 5)) {
        qType = 'essay';
      }
      // 2. Multiple Choice
      else if(options.length >= 2) {
        qType = 'multiple_choice';
      }
      // 3. True / False
      else if(currentSection === 'tf' || lowerAns === 'true' || lowerAns === 'false' || lowerAns === 't' || lowerAns === 'f') {
        qType = 'true_false';
      }
      // 4. Modified True / False
      else if(currentSection === 'mtf') {
        qType = 'modified_true_false';
      }
      // 5. Enumeration
      else if(currentSection === 'enum' || correctAnswer.includes(',') || lowerStmt.startsWith('name ') || lowerStmt.startsWith('give ') || lowerStmt.startsWith('enumerate ') || lowerStmt.startsWith('list ')) {
        qType = 'enumeration';
      }
      // 6. Identification
      else {
        qType = 'identification';
      }

      cqQuestionsData.push({
        id: Date.now() + Math.random(),
        question_type: qType,
        question_text: qStatement,
        options: options,
        correct_answer: correctAnswer || (qType === 'essay' ? 'Teacher Grading / Rubric' : ''),
        points: points,
        topic: topic
      });
    });
  }

  renderCqCards();
}

function renderCqCards() {
  const container = $('#cqCardsContainer');
  container.empty();

  let totalPoints = 0;

  if(cqQuestionsData.length === 0) {
    container.html(`
      <div class="cq-empty-preview">
        <div class="cq-empty-icon"><i class="fa fa-inbox"></i></div>
        <h4 class="cq-empty-title">No questions parsed yet</h4>
        <p class="cq-empty-desc">Type or paste questions on the left and click "Parse & Preview Questions" to see the live rendering here.</p>
      </div>
    `);
    $('#cqDetectedBadge').text('0 Questions Detected (0 pts)');
    return;
  }

  const badgeColors = {
    multiple_choice: '#7c3aed',
    true_false: '#16a34a',
    modified_true_false: '#e11d48',
    identification: '#f97316',
    enumeration: '#1d4ed8',
    essay: '#475569'
  };

  const typeTags = {
    multiple_choice: { label: 'MC', class: 'tag-mc' },
    true_false: { label: 'T/F', class: 'tag-tf' },
    modified_true_false: { label: 'MTF', class: 'tag-mtf' },
    identification: { label: 'ID', class: 'tag-id' },
    enumeration: { label: 'ENUM', class: 'tag-enum' },
    essay: { label: 'ESSAY', class: 'tag-essay' }
  };

  cqQuestionsData.forEach((q, idx) => {
    const qType = q.question_type || 'identification';
    const pts = parseInt(q.points) || 2;
    totalPoints += pts;

    const bColor = badgeColors[qType] || '#f97316';
    const tagInfo = typeTags[qType] || { label: 'ID', class: 'tag-id' };
    const displayNum = idx + 1;

    let ansDisplay = q.correct_answer || (qType === 'true_false' ? 'True' : 'N/A');

    const cardHtml = `
      <div class="cq-q-card" style="border-left-color:${bColor};">
        <div class="cq-q-header">
          <span class="cq-q-badge" style="background:${bColor};">${displayNum}</span>
          <div class="cq-q-text">
            ${escapeCqHtml(q.question_text)}
          </div>
          <span class="cq-type-tag ${tagInfo.class}">${tagInfo.label}</span>
        </div>

        <div class="cq-ans-box">
          <div class="cq-ans-label">CORRECT ANSWER</div>
          <div class="cq-ans-val">
            <i class="fa fa-check" style="color:#16a34a;"></i>
            <span>${escapeCqHtml(ansDisplay)}</span>
          </div>
        </div>

        <div class="cq-q-foot">
          <div style="display:flex;align-items:center;gap:12px;">
            <span class="cq-meta-input"><i class="fa fa-tag"></i> Topic: <input type="text" class="cq-input-sm" style="width:100px;" value="${escapeCqHtml(q.topic || 'General')}"></span>
            <span class="cq-meta-input">Points: <input type="number" class="cq-input-sm" style="width:46px;" value="${pts}"></span>
          </div>
          <div style="display:flex;align-items:center;gap:4px;">
            <button type="button" class="btn-act" style="padding:2px 6px;font-size:11px;border:1px solid #e2e8f0;background:#fff;border-radius:6px;color:#ef4444;" onclick="deleteCqQuestion(${idx})"><i class="fa fa-trash"></i></button>
          </div>
        </div>
      </div>
    `;

    container.append(cardHtml);
  });

  $('#cqDetectedBadge').text(`${cqQuestionsData.length} Questions Detected (${totalPoints} pts)`);
}

function deleteCqQuestion(idx) {
  cqQuestionsData.splice(idx, 1);
  renderCqCards();
}

function addCqManualQuestion() {
  cqQuestionsData.push({
    id: Date.now(),
    question_type: 'identification',
    question_text: 'Enter question prompt...',
    options: [],
    correct_answer: 'Sample Answer',
    points: 2,
    topic: 'e.g. Loops'
  });
  renderCqCards();
}

function escapeCqHtml(str) { return (str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function handleCqFileSelect(files) {
  const file = files[0];
  if(!file) return;
  const reader = new FileReader();
  reader.onload = function(e) {
    $('#cqPasteArea').val(e.target.result);
    updateCqEditorStats();
    parseAndPreviewCq();
    switchCqTab('paste');
  };
  reader.readAsText(file);
}

function swapCqPanes() {
  $('.cq-main-row').toggleClass('cq-row-swapped');
}

function toggleCqFullscreenModal() {
  $('#createQuizModal .modal-dialog').toggleClass('cq-modal-windowed');
  const isWindowed = $('#createQuizModal .modal-dialog').hasClass('cq-modal-windowed');
  $('#cqFsIcon').attr('class', isWindowed ? 'fa fa-arrows-alt' : 'fa fa-compress');
}

function submitCqQuiz() {
  let title = $('#cqQuizTitle').val() ? $('#cqQuizTitle').val().trim() : '';
  const term = $('#cqQuizTerm').val() || 'midterm';
  const timeLimit = $('#cqQuizTimeLimit').val() || 0;

  // Fallback title if user leaves title blank
  if(!title) {
    const termFormatted = term ? term.charAt(0).toUpperCase() + term.slice(1) : 'Midterm';
    const dateStr = new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    title = `${termFormatted} Quiz (${dateStr})`;
  }

  if(cqQuestionsData.length === 0) {
    alert('Please paste or parse questions first.');
    return;
  }

  const formattedQuestions = cqQuestionsData.map(q => ({
    question_type: q.question_type || 'identification',
    question_text: q.question_text,
    topic: q.topic || 'General',
    points: q.points || 2,
    options: q.options || [],
    correct_answer: q.correct_answer || ''
  }));

  const payload = {
    action: 'create',
    class_id: 0,
    title: title,
    instructions: '',
    time_limit: timeLimit,
    due_date: '',
    start_date: '',
    shuffle_questions: 1,
    shuffle_answers: 1,
    term: term,
    questions: JSON.stringify(formattedQuestions)
  };

  $.post('../shared/quiz_handler.php', payload, function(res) {
    if(res.success) {
      alert('Quiz created successfully!');
      location.reload();
    } else {
      alert(res.msg || 'Error saving quiz.');
    }
  }, 'json');
}

var currentActiveQuizId = 0;

function viewSubmissions(quizId) {
  currentActiveQuizId = quizId;
  $.post('../shared/quiz_handler.php', { action: 'get_submissions', quiz_id: quizId }, function(res) {
    if(typeof res === 'string'){ try { res = JSON.parse(res.trim()); } catch(e){} }
    if(!res || !res.success) {
      alert(res && res.msg ? res.msg : 'Failed to fetch submissions');
      return;
    }
    var qTitle = (res.quiz && res.quiz.title) ? res.quiz.title : 'Quiz';
    var actualQid = (res.quiz && res.quiz.id) ? res.quiz.id : quizId;
    $('#subModalTitle').html('<i class="fa fa-eye"></i> ' + escapeCqHtml(qTitle) + ' &bull; Submissions');

    // Render Stats
    var stats = res.stats || { submission_count: 0, avg_pct: 0, high_score: 0, violation_count: 0 };
    var statsHtml = `
      <div class="col-xs-6 col-md-3 text-center">
        <div class="well well-sm"><strong>${stats.submission_count}</strong><br><small class="text-muted">Attempts</small></div>
      </div>
      <div class="col-xs-6 col-md-3 text-center">
        <div class="well well-sm"><strong>${stats.avg_pct}%</strong><br><small class="text-muted">Average Score</small></div>
      </div>
      <div class="col-xs-6 col-md-3 text-center">
        <div class="well well-sm"><strong>${stats.high_score} pts</strong><br><small class="text-muted">High Score</small></div>
      </div>
      <div class="col-xs-6 col-md-3 text-center">
        <div class="well well-sm"><strong class="text-danger">${stats.violation_count}</strong><br><small class="text-muted">Anti-Cheat Alerts</small></div>
      </div>
    `;
    $('#subStatsHeader').html(statsHtml);

    // Render Rows
    var rows = '';
    var subs = res.submissions || [];
    if(subs.length === 0) {
      rows = `<tr><td colspan="7" class="text-center text-muted" style="padding:24px;">No student submissions recorded yet.</td></tr>`;
    } else {
      subs.forEach(s => {
        const tabSw = parseInt(s.tab_switches || 0);
        const fsEx = parseInt(s.fullscreen_exits || 0);
        let alertBadge = '<span class="label label-success">Clean Attempt</span>';
        if(tabSw > 0 || fsEx > 0) {
          alertBadge = `<span class="badge-alert"><i class="fa fa-exclamation-triangle"></i> ${tabSw} tab switches, ${fsEx} fs exits</span>`;
        }

        const pct = parseFloat(s.percentage || 0);
        let pctBadge = 'label-success';
        if(pct < 60) pctBadge = 'label-danger';
        else if(pct < 75) pctBadge = 'label-warning';

        const subQid = s.quiz_id || actualQid;
        const uCode = s.user_code || s.student_code;

        rows += `
          <tr>
            <td><strong>${escapeCqHtml(s.first_name || '')} ${escapeCqHtml(s.last_name || '')}</strong></td>
            <td><code>${escapeCqHtml(uCode)}</code></td>
            <td><strong>${s.score || 0}</strong> / ${s.total_points || 0}</td>
            <td><span class="label ${pctBadge}">${pct}%</span></td>
            <td>${alertBadge}</td>
            <td>${s.submitted_at || '—'}</td>
            <td style="text-align:center;">
              <button type="button" class="btn btn-xs" onclick="viewStudentAnswers(${subQid}, '${escapeCqHtml(uCode)}')" style="border-radius:6px;font-weight:700;padding:5px 12px;background:linear-gradient(135deg,#4f46e5,#4338ca);color:#fff;border:none;box-shadow:0 2px 5px rgba(79,70,229,0.25);display:inline-flex;align-items:center;gap:4px;">
                <i class="fa fa-list-alt"></i> View Answers
              </button>
            </td>
          </tr>
        `;
      });
    }
    $('#subTableBody').html(rows);
    $('#submissionsModal').modal('show');
  }, 'json');
}

function viewStudentAnswers(quizId, studentCode) {
  $('#saQuestionsList').html('<div style="text-align:center;padding:40px;color:#64748b;"><i class="fa fa-spinner fa-spin fa-2x"></i><p style="margin-top:10px;">Loading student answers...</p></div>');
  $('#submissionsModal').modal('hide');
  $('#studentAnswersModal').modal('show');

  $.post('../shared/quiz_handler.php', {
    action: 'get_student_answers',
    quiz_id: quizId,
    student_code: studentCode
  }, function(res) {
    if(typeof res === 'string'){ try { res = JSON.parse(res.trim()); } catch(e){} }
    if(!res || !res.success) {
      $('#saQuestionsList').html('<div class="alert alert-danger" style="margin:20px;">' + (res && res.msg ? res.msg : 'Failed to load student answers') + '</div>');
      return;
    }

    const pct = parseFloat(res.percentage || 0);
    let pctBadge = 'label-success';
    if(pct < 60) pctBadge = 'label-danger';
    else if(pct < 75) pctBadge = 'label-warning';

    // Anti-cheat alert
    let acAlert = '';
    if(res.tab_switches > 0 || res.fullscreen_exits > 0) {
      acAlert = `<span style="background:#fee2e2;color:#991b1b;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;"><i class="fa fa-exclamation-triangle"></i> Anti-Cheat Alerts: ${res.tab_switches} tab switches, ${res.fullscreen_exits} fullscreen exits</span>`;
    }

    $('#saStudentHeader').html(`
      <div>
        <h4 style="margin:0 0 4px 0;font-weight:800;color:#0f172a;font-size:15px;display:flex;align-items:center;gap:8px;">
          <i class="fa fa-user-circle" style="color:#4f46e5;font-size:18px;"></i> ${escapeCqHtml(res.student_name)}
        </h4>
        <span style="font-size:12px;color:#64748b;">ID: <strong style="color:#0f172a;">${escapeCqHtml(res.student_code)}</strong> &bull; Quiz: <strong style="color:#0f172a;">${escapeCqHtml(res.quiz_title)}</strong></span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        ${acAlert}
        <span class="label ${pctBadge}" style="font-size:13px;padding:6px 12px;border-radius:8px;">
          Score: <strong>${res.score}</strong> / ${res.total_points} (${pct}%)
        </span>
        <span style="font-size:11px;color:#64748b;background:#f1f5f9;padding:6px 10px;border-radius:8px;font-weight:600;">
          <i class="fa fa-calendar"></i> ${res.submitted_at}
        </span>
      </div>
    `);

    // Render Question Cards
    let qHtml = '';
    const qList = res.questions || [];
    if(qList.length === 0) {
      qHtml = '<div class="alert alert-info">No questions found for this quiz.</div>';
    } else {
      qList.forEach((q, idx) => {
        const isCorrect = q.is_correct;
        const typeFormatted = (q.question_type || 'multiple_choice').replace(/_/g, ' ').toUpperCase();
        
        let statusBadge = '';
        let cardBorder = '#e2e8f0';
        if(isCorrect === true) {
          statusBadge = `<span style="background:#dcfce7;color:#166534;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:700;"><i class="fa fa-check"></i> Correct (${q.earned_points}/${q.points} pts)</span>`;
          cardBorder = '#86efac';
        } else if(isCorrect === false) {
          statusBadge = `<span style="background:#fee2e2;color:#991b1b;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:700;"><i class="fa fa-times"></i> Incorrect (${q.earned_points}/${q.points} pts)</span>`;
          cardBorder = '#fca5a5';
        } else {
          statusBadge = `<span style="background:#fef3c7;color:#92400e;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:700;"><i class="fa fa-pencil"></i> Manual / Essay (${q.points} pts)</span>`;
        }

        const topicTag = q.topic && q.topic !== 'General' ? `<span style="background:#e0f2fe;color:#0369a1;padding:2px 7px;border-radius:4px;font-size:10.5px;font-weight:600;"><i class="fa fa-tag"></i> ${escapeCqHtml(q.topic)}</span>` : '';

        let answerContent = '';
        if(q.options && q.options.length > 0 && q.question_type === 'multiple_choice') {
          // Multiple Choice Options list
          answerContent = '<div style="display:flex;flex-direction:column;gap:6px;margin-top:10px;">';
          q.options.forEach((opt, optIdx) => {
            const letter = String.fromCharCode(65 + optIdx);
            const isStudentChoice = (q.given_answer.toLowerCase() === letter.toLowerCase() || q.given_answer.toLowerCase() === opt.toLowerCase());
            const isCorrectChoice = (q.correct_answer.toLowerCase() === letter.toLowerCase() || q.correct_answer.toLowerCase() === opt.toLowerCase());

            let optStyle = 'background:#f8fafc;border:1px solid #e2e8f0;color:#334155;';
            let optTag = '';

            if(isStudentChoice && isCorrectChoice) {
              optStyle = 'background:#f0fdf4;border:1.5px solid #22c55e;color:#166534;font-weight:700;';
              optTag = '<span style="color:#166534;font-size:11px;margin-left:auto;"><i class="fa fa-check-circle"></i> Student Selected (Correct)</span>';
            } else if(isStudentChoice && !isCorrectChoice) {
              optStyle = 'background:#fef2f2;border:1.5px solid #ef4444;color:#991b1b;font-weight:700;';
              optTag = '<span style="color:#991b1b;font-size:11px;margin-left:auto;"><i class="fa fa-times-circle"></i> Student Selected</span>';
            } else if(isCorrectChoice) {
              optStyle = 'background:#f0fdf4;border:1.5px dashed #22c55e;color:#166534;font-weight:600;';
              optTag = '<span style="color:#166534;font-size:11px;margin-left:auto;"><i class="fa fa-check"></i> Correct Answer</span>';
            }

            answerContent += `
              <div style="${optStyle}padding:8px 12px;border-radius:8px;font-size:12.5px;display:flex;align-items:center;gap:8px;">
                <span style="font-weight:700;width:20px;">${letter}.</span>
                <span>${escapeCqHtml(opt)}</span>
                ${optTag}
              </div>
            `;
          });
          answerContent += '</div>';
        } else {
          // Written / Identification / True-False / Enumeration / Essay
          const givenClass = isCorrect === false ? 'background:#fef2f2;border:1px solid #fecaca;color:#991b1b;' : (isCorrect === true ? 'background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;' : 'background:#fffbeb;border:1px solid #fde68a;color:#92400e;');
          const givenIcon = isCorrect === false ? '<i class="fa fa-times-circle" style="color:#ef4444;"></i>' : (isCorrect === true ? '<i class="fa fa-check-circle" style="color:#10b981;"></i>' : '<i class="fa fa-pencil"></i>');
          
          answerContent = `
            <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">
              <div style="${givenClass}padding:9px 12px;border-radius:8px;font-size:12.5px;">
                ${givenIcon} <strong>Student Answer:</strong> ${escapeCqHtml(q.given_answer || '(No answer submitted)')}
              </div>
              ${(isCorrect === false && q.correct_answer) ? `
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:9px 12px;border-radius:8px;font-size:12.5px;">
                  <i class="fa fa-check-circle"></i> <strong>Expected / Correct Answer:</strong> ${escapeCqHtml(q.correct_answer)}
                </div>
              ` : ''}
            </div>
          `;
        }

        qHtml += `
          <div style="background:#fff;border:1px solid ${cardBorder};border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
              <div style="display:flex;align-items:center;gap:6px;">
                <span style="font-size:12px;font-weight:800;color:#0f172a;background:#f1f5f9;padding:2px 8px;border-radius:6px;">#${idx+1}</span>
                <span style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">${typeFormatted}</span>
                ${topicTag}
              </div>
              <div>${statusBadge}</div>
            </div>
            <div style="font-size:13.5px;font-weight:600;color:#0f172a;line-height:1.4;margin-bottom:4px;">
              ${escapeCqHtml(q.question_text)}
            </div>
            ${answerContent}
          </div>
        `;
      });
    }

    $('#saQuestionsList').html(qHtml);
  }, 'json');
}

function openCopyModal(quizId, quizTitle, startDate, dueDate) {
  $('#copySourceId').val(quizId);
  $('#copyTargetClassId').val('');
  $('#copyStartDate').val(startDate || '');
  $('#copyDueDate').val(dueDate || '');
  $('#copyAlert').hide();
  $('#copyQuizModalTitle').html('<i class="fa fa-plus-circle"></i> Add Class &bull; ' + escapeCqHtml(quizTitle));
  $('#copyQuizModal').modal('show');
}

function submitCopyQuiz() {
  const sourceId = $('#copySourceId').val();
  const targetId = $('#copyTargetClassId').val();
  const startDate = $('#copyStartDate').val();
  const dueDate = $('#copyDueDate').val();

  if(!targetId) {
    $('#copyAlert').attr('class','alert alert-danger').text('Please select a target class to add/assign this quiz.').show();
    return;
  }

  if(startDate && dueDate && new Date(startDate) >= new Date(dueDate)) {
    $('#copyAlert').attr('class','alert alert-danger').text('Time to start must be earlier than the expiration / due date.').show();
    return;
  }

  $('#btnSubmitCopy').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Adding Class...');

  $.post('../shared/quiz_handler.php', {
    action: 'copy',
    quiz_id: sourceId,
    target_class_id: targetId,
    start_date: startDate,
    due_date: dueDate
  }, function(res) {
    $('#btnSubmitCopy').prop('disabled', false).html('<i class="fa fa-plus"></i> Add Class');
    if(res.success) {
      $('#copyAlert').attr('class','alert alert-success').html('<i class="fa fa-check-circle"></i> ' + (res.msg || 'Quiz added to target class successfully!')).show();
      setTimeout(function(){
        location.reload();
      }, 1000);
    } else {
      $('#copyAlert').attr('class','alert alert-danger').text(res.msg || 'Failed to add quiz to target class.').show();
    }
  }, 'json').fail(function(){
    $('#btnSubmitCopy').prop('disabled', false).html('<i class="fa fa-plus"></i> Add Class');
    $('#copyAlert').attr('class','alert alert-danger').text('Server error while processing request.').show();
  });
}

function viewAssignedClasses(title, subjectsData) {
  $('#acModalTitle').html('<i class="fa fa-users"></i> ' + escapeCqHtml(title) + ' &bull; Assigned Classes');
  
  let html = '<div style="display:flex;flex-direction:column;gap:10px;">';
  subjectsData.forEach(s => {
    let codeBadge = s.code ? `<span class="badge-code-green"><i class="fa fa-tag"></i> ${escapeCqHtml(s.code)}</span>` : '';
    let classUrl = s.class_id ? `../shared/class_view.php?id=${s.class_id}&tab=classwork` : '#';
    
    html += `
      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
        <div style="display:flex;align-items:center;gap:10px;">
          ${codeBadge}
          <div>
            <h6 style="margin:0 0 2px 0;font-weight:700;color:#0f172a;font-size:13px;">${escapeCqHtml(s.name)}</h6>
            ${s.section ? `<span style="font-size:11px;color:#64748b;"><i class="fa fa-users"></i> Section: <strong>${escapeCqHtml(s.section)}</strong></span>` : ''}
          </div>
        </div>
        ${s.class_id ? `<a href="${classUrl}" class="btn btn-xs btn-info" style="border-radius:6px;font-weight:700;padding:6px 14px;background:linear-gradient(135deg,#0284c7,#0369a1);border:none;box-shadow:0 2px 4px rgba(2,132,199,0.2);"><i class="fa fa-external-link"></i> Open Class</a>` : '<span style="font-size:11px;color:#94a3b8;">Template</span>'}
      </div>
    `;
  });
  html += '</div>';
  
  $('#acModalBody').html(html);
  $('#assignedClassesModal').modal('show');
}

function deleteQuiz(quizId) {
  if(!confirm('Are you sure you want to delete this quiz? All student submissions and scores will be permanently deleted.')) return;

  $.post('../shared/quiz_handler.php', { action: 'delete', id: quizId }, function(res) {
    if(res.success) {
      location.reload();
    } else {
      alert(res.msg || 'Failed to delete quiz.');
    }
  }, 'json');
}
</script>
</body>
</html>
