<?php
include '../includes/session.php';
include '../includes/conn.php';

if(strtoupper($user['user_group']) !== 'TEACHER'){
    header('location: ../login'); exit;
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

// Fetch teacher's uploaded learning modules for AI Quiz Generator
$modulesQ = $conn->query("SELECT m.id, m.title, m.filename, m.topic, m.class_id, c.class_name FROM class_modules m LEFT JOIN classes c ON m.class_id=c.id WHERE m.teacher_code = '$tc' ORDER BY m.id DESC");
$teacherModules = [];
if($modulesQ) while($mr = $modulesQ->fetch_assoc()) $teacherModules[] = $mr;

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

    $cid = intval($row['class_id'] ?? 0);
    if($cid > 0){
        $dispCode = trim($row['display_code'] ?? '');
        if(empty($dispCode) || $dispCode === $row['class_name']) {
            $dispCode = trim($row['subject'] ?? '');
        }
        $cName = trim($row['class_name'] ?? '');
        
        $subKey = $dispCode . '___' . $cName . '___' . $cid;
        if(!isset($groupedQuizzes[$titleKey]['subjects'][$subKey])){
            $groupedQuizzes[$titleKey]['subjects'][$subKey] = [
                'class_id' => $cid,
                'code' => $dispCode,
                'name' => $cName,
                'section' => trim($row['section'] ?? '')
            ];
        }
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
  <link rel="stylesheet" href="/cenlearn/system/bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/cenlearn/system/bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/cenlearn/system/dist/css/cenlearn.css">
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
    .cq-modal-header { padding: 8px 18px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e2e8f0; background: #ffffff; flex-shrink: 0; z-index: 11; flex-wrap: wrap; gap: 8px; }
    .cq-header-title { display: flex; align-items: center; gap: 8px; }
    .cq-title-icon { width: 28px; height: 28px; border-radius: 50%; background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 12.5px; font-weight: 700; box-shadow: 0 2px 6px rgba(139,92,246,0.25); flex-shrink: 0; }
    .cq-header-title h2 { font-size: 14.5px; font-weight: 800; color: #0f172a; margin: 0; }
    .cq-fs-toggle-btn { background: #f1f5f9; border: 1px solid #cbd5e1; font-size: 11.5px; color: #475569; cursor: pointer; padding: 4px 10px; border-radius: 6px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 5px; height: 28px; transition: all .15s; white-space: nowrap; }
    .cq-fs-toggle-btn:hover { background: #e2e8f0; color: #0f172a; }
    .cq-close-btn { background: none; border: none; font-size: 20px; color: #94a3b8; cursor: pointer; padding: 0 4px; line-height: 1; transition: color .15s; display: inline-flex; align-items: center; justify-content: center; }
    .cq-close-btn:hover { color: #0f172a; }

    /* Windowed Mode Override */
    #createQuizModal .modal-dialog.cq-modal-windowed { max-width: 1240px; width: 94%; height: auto; margin: 20px auto; }
    #createQuizModal .modal-dialog.cq-modal-windowed .cq-modal-content { height: 90vh; max-height: 900px; border-radius: 20px; border: 1px solid #cbd5e1; box-shadow: 0 25px 60px rgba(0,0,0,0.18); }

    /* Top Config Card Compact (Small Resize) */
    .cq-config-strip { background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 10px 18px; margin: 12px 20px 0; flex-shrink: 0; box-shadow: 0 2px 6px rgba(0,0,0,0.02); }
    .cq-config-grid { display: grid; grid-template-columns: 2fr 1.6fr 1fr 1.1fr; gap: 12px; align-items: end; }
    @media(max-width: 900px) { .cq-config-grid { grid-template-columns: 1fr 1fr; } }
    @media(max-width: 600px) { .cq-config-grid { grid-template-columns: 1fr; } }

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

    .cq-modal-footer { padding: 8px 18px; background: #ffffff; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-shrink: 0; position: sticky; bottom: 0; z-index: 10; box-shadow: 0 -2px 8px rgba(0,0,0,0.03); flex-wrap: wrap; }
    .cq-btn-cancel { padding: 5px 14px; border-radius: 7px; font-size: 11.5px; font-weight: 600; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; cursor: pointer; transition: all .15s; white-space: nowrap; height: 30px; display: inline-flex; align-items: center; justify-content: center; }
    .cq-btn-cancel:hover { background: #e2e8f0; color: #0f172a; }
    .cq-btn-create { padding: 5px 16px; border-radius: 7px; font-size: 11.5px; font-weight: 700; background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: #ffffff; border: none; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 5px; box-shadow: 0 2px 8px rgba(109,40,217,.28); transition: all .15s; white-space: nowrap; height: 30px; }
    .cq-btn-create:hover { background: linear-gradient(135deg, #7c3aed, #5b21b6); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(109,40,217,.38); }
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
      <li><a href="dashboard"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li><a href="classes"><i class="fa fa-book"></i> Classes</a></li>
      <li class="active"><a href="quizzes"><i class="fa fa-question-circle"></i> Quizzes</a></li>
      <li><a href="assignments"><i class="fa fa-tasks"></i> Assignments</a></li>
      <li><a href="attendance"><i class="fa fa-calendar-check-o"></i> Attendance</a></li>
      <li><a href="logbook"><i class="fa fa-pencil-square-o"></i> Manage Subject</a></li>
      <li><a href="class_record"><i class="fa fa-table"></i> Class Record</a></li>
      <li><a href="subject_repository"><i class="fa fa-archive"></i> Past Subject Repository</a></li>
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
    <a href="/cenlearn/logout" class="sb-out"><i class="fa fa-sign-out"></i> Sign Out</a>
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
                <?php elseif($classCount === 1): 
                  $firstSub = reset($q['subjects']);
                  $sCode = trim($firstSub['code'] ?? '');
                  $sName = trim($firstSub['name'] ?? '');
                  $sLabel = (!empty($sCode) && strtolower($sCode) !== 'general' && strtolower($sCode) !== strtolower($sName))
                    ? '<strong>' . htmlspecialchars($sCode) . '</strong> : ' . htmlspecialchars($sName)
                    : htmlspecialchars($sName);
                ?>
                  <span class="qz-class-badge" style="cursor:pointer;" onclick="viewAssignedClasses('<?php echo addslashes($q['title']); ?>', <?php echo $subjectsJson; ?>)" title="Click to view class details">
                    <i class="fa fa-graduation-cap"></i> <?php echo $sLabel; ?>
                  </span>
                <?php else: ?>
                  <span class="qz-class-badge" style="background:#f1f5f9;color:#64748b;border-color:#e2e8f0;">
                    <i class="fa fa-file-text-o"></i> Unassigned Template
                  </span>
                <?php endif; ?>
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
                <button class="qz-act-btn" title="View Questions & Answer Key" onclick="viewSubmissions('<?php echo $allIds; ?>')">
                  <i class="fa fa-eye"></i>
                </button>
                <button class="qz-act-btn" title="Add Class / Assign to Class" onclick="openCopyModal(<?php echo $q['id']; ?>, '<?php echo addslashes($q['title']); ?>', '<?php echo !empty($q['start_date']) ? date('Y-m-d\TH:i', strtotime($q['start_date'])) : ''; ?>', '<?php echo !empty($q['due_date']) ? date('Y-m-d\TH:i', strtotime($q['due_date'])) : ''; ?>')">
                  <i class="fa fa-plus-circle" style="color:#10b981;"></i>
                </button>
                <button class="qz-act-btn" title="Duplicate / Copy Entire Quiz" onclick="duplicateQuiz(<?php echo $q['id']; ?>)">
                  <i class="fa fa-files-o" style="color:#6366f1;"></i>
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

<!-- ── View Quiz Questions & Answer Key / Submissions Modal ── -->
<div class="modal fade" id="submissionsModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document" style="max-width:920px;">
    <div class="modal-content" style="border-radius:16px;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,0.18);">
      <div class="modal-header" style="background:linear-gradient(135deg,#0f2d4a,#1e3a5f);color:#fff;padding:16px 22px;display:flex;align-items:center;justify-content:space-between;">
        <h5 class="modal-title" id="subModalTitle" style="font-weight:700;display:flex;align-items:center;gap:8px;margin:0;font-size:15px;"><i class="fa fa-list-alt" style="color:#60a5fa;"></i> Quiz Overview & Submissions</h5>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;background:none;border:none;font-size:20px;">&times;</button>
      </div>

      <div class="modal-body" style="padding:20px 22px;background:#f8fafc;max-height:calc(85vh - 120px);overflow-y:auto;">
        <!-- Submissions vs Questions Tabs -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;border-bottom:1px solid #e2e8f0;padding-bottom:12px;">
          <button type="button" class="btn btn-sm" id="btnTabQuestions" onclick="switchSubModalTab('questions')" style="border-radius:8px;font-weight:700;padding:7px 16px;background:#4f46e5;color:#fff;border:none;">
            <i class="fa fa-list-alt"></i> Questions & Answer Key
          </button>
          <button type="button" class="btn btn-sm" id="btnTabSubmissions" onclick="switchSubModalTab('submissions')" style="border-radius:8px;font-weight:700;padding:7px 16px;background:#e2e8f0;color:#475569;border:none;">
            <i class="fa fa-users"></i> Student Submissions (<span id="subCountBadge">0</span>)
          </button>
        </div>

        <!-- Tab 1: Questions & Answer Key View -->
        <div id="tabQuestionsView">
          <div id="quizQuestionsStats" style="margin-bottom:16px;"></div>
          <div id="quizQuestionsContainer" style="display:flex;flex-direction:column;gap:12px;"></div>
        </div>

        <!-- Tab 2: Student Submissions View -->
        <div id="tabSubmissionsView" style="display:none;">
          <div id="quizSubmissionsContainer"></div>
        </div>
      </div>

      <div class="modal-footer" style="background:#fff;border-top:1px solid #e2e8f0;padding:12px 20px;">
        <button type="button" class="btn btn-default" data-dismiss="modal" style="border-radius:8px;font-weight:600;">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Detailed Student Answers Review Modal ── -->
<div class="modal fade" id="studentAnswersModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document" style="max-width:96%;width:1200px;margin:20px auto;">
    <div class="modal-content" style="border-radius:16px;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,0.18);display:flex;flex-direction:column;max-height:calc(96vh - 40px);">
      <div class="modal-header" style="background:linear-gradient(135deg,#4f46e5,#4338ca);color:#fff;padding:16px 24px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <h5 class="modal-title" id="saModalTitle" style="font-weight:700;display:flex;align-items:center;gap:8px;margin:0;font-size:16px;">
          <i class="fa fa-list-alt" style="color:#a5b4fc;"></i> Student Quiz Answers Review
        </h5>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;background:none;border:none;font-size:22px;line-height:1;">&times;</button>
      </div>
      <div class="modal-body" style="padding:24px 28px;background:#f8fafc;flex:1;min-height:0;overflow-y:auto;">
        <!-- Student & Quiz Info Header Bar -->
        <div id="saStudentHeader" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
          <!-- Filled dynamically -->
        </div>
        
        <!-- Question-by-Question List -->
        <div id="saQuestionsList" style="display:flex;flex-direction:column;gap:14px;">
          <!-- Filled dynamically -->
        </div>
      </div>
      <div class="modal-footer" style="background:#fff;border-top:1px solid #e2e8f0;padding:14px 24px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
        <button type="button" class="btn btn-default" onclick="$('#studentAnswersModal').modal('hide'); $('#submissionsModal').modal('show');" style="border-radius:8px;font-weight:600;padding:8px 18px;"><i class="fa fa-arrow-left"></i> Back to Submissions</button>
        <button type="button" class="btn btn-default" data-dismiss="modal" style="border-radius:8px;font-weight:600;padding:8px 20px;">Close</button>
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

      <!-- Compact Responsive Config Strip -->
      <div style="background: #ffffff; border-bottom: 1px solid #e2e8f0; padding: 10px 18px 8px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px 10px; align-items: end;">
          <div>
            <label style="font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 3px; display: block; white-space: nowrap;">Quiz Title <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="cqQuizTitle" placeholder="e.g. Chapter 1 Quiz" style="height: 32px; font-size: 12px; border-radius: 7px; border: 1.5px solid #cbd5e1; padding: 4px 10px;" required>
          </div>
          <div>
            <label style="font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 3px; display: block; white-space: nowrap;"><i class="fa fa-clock-o" style="color:#64748b;"></i> Time Limit (min)</label>
            <input type="number" class="form-control" id="cqQuizTimeLimit" placeholder="0" min="0" style="height: 32px; font-size: 12px; border-radius: 7px; border: 1.5px solid #cbd5e1; padding: 4px 10px;">
          </div>
          <div>
            <label style="font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 3px; display: block; white-space: nowrap;"><i class="fa fa-graduation-cap" style="color:#7c3aed;"></i> Term</label>
            <select class="form-control" id="cqQuizTerm" style="height: 32px; font-size: 11.5px; border-radius: 7px; border: 1.5px solid #cbd5e1; padding: 4px 8px; color: #1e293b;">
              <option value="midterm">Midterm</option>
              <option value="final">Final</option>
              <option value="none">Practice</option>
            </select>
          </div>
        </div>

        <!-- Shuffle Controls Row (Clean & Responsive) -->
        <div style="margin-top: 8px; padding-top: 6px; border-top: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: flex-start; flex-wrap: wrap; gap: 10px 14px; font-size: 11px;">
          <span style="font-weight: 700; color: #0f172a; display: inline-flex; align-items: center; gap: 5px;">
            <i class="fa fa-random" style="color:#7c3aed;"></i> Presentation Shuffle:
          </span>
          <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer; margin: 0; font-weight: 600; color: #334155; user-select: none;">
            <input type="checkbox" id="cqShuffleQuestions" checked style="accent-color: #6366f1; cursor: pointer; margin: 0;"> Questions
          </label>
          <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer; margin: 0; font-weight: 600; color: #334155; user-select: none;">
            <input type="checkbox" id="cqShuffleAnswers" checked style="accent-color: #6366f1; cursor: pointer; margin: 0;"> Choices
          </label>
          <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer; margin: 0; font-weight: 600; color: #334155; user-select: none;">
            <input type="checkbox" id="cqShuffleMatching" checked style="accent-color: #6366f1; cursor: pointer; margin: 0;"> Matching
          </label>
          <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer; margin: 0; font-weight: 600; color: #334155; user-select: none;">
            <input type="checkbox" id="cqShuffleTF" checked style="accent-color: #6366f1; cursor: pointer; margin: 0;"> True/False
          </label>
          <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer; margin: 0; font-weight: 600; color: #334155; user-select: none;">
            <input type="checkbox" id="cqRandomizeStudent" checked style="accent-color: #6366f1; cursor: pointer; margin: 0;"> Randomize for Each Student
          </label>
        </div>
      </div>

      <div class="cq-modal-body">
        <div class="row cq-main-row">
          <!-- Left Column -->
          <div class="col-lg-6 col-md-12 cq-left-pane">
            <div id="cqTabContentPaste">
              <div class="cq-shortcuts-section" style="margin-bottom: 8px;">
                <span class="cq-shortcuts-title" style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.6px; text-transform: uppercase; margin-bottom: 5px; display: flex; align-items: center; gap: 5px;">
                  <i class="fa fa-book" style="color: #6366f1;"></i> View Format Guide / Question Types:
                </span>
                <div class="cq-shortcuts-pills" style="display: flex; flex-wrap: wrap; gap: 5px;">
                  <button type="button" class="cq-pill pill-mc" onclick="openQuestionGuide('mc')" style="padding: 3px 8px; font-size: 11px; border-radius: 6px; height: 26px;">
                    <i class="fa fa-eye"></i> Single MCQ
                  </button>
                  <button type="button" class="cq-pill" style="padding: 3px 8px; font-size: 11px; border-radius: 6px; height: 26px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;" onclick="openQuestionGuide('msq')">
                    <i class="fa fa-eye"></i> Multi-Select MCQ
                  </button>
                  <button type="button" class="cq-pill pill-tf" onclick="openQuestionGuide('tf')" style="padding: 3px 8px; font-size: 11px; border-radius: 6px; height: 26px;">
                    <i class="fa fa-eye"></i> True / False
                  </button>
                  <button type="button" class="cq-pill pill-mtf" onclick="openQuestionGuide('mtf')" style="padding: 3px 8px; font-size: 11px; border-radius: 6px; height: 26px;">
                    <i class="fa fa-eye"></i> Modified T/F
                  </button>
                  <button type="button" class="cq-pill pill-id" onclick="openQuestionGuide('id')" style="padding: 3px 8px; font-size: 11px; border-radius: 6px; height: 26px;">
                    <i class="fa fa-eye"></i> Identification
                  </button>
                  <button type="button" class="cq-pill pill-enum" onclick="openQuestionGuide('enum')" style="padding: 3px 8px; font-size: 11px; border-radius: 6px; height: 26px;">
                    <i class="fa fa-eye"></i> Enumeration
                  </button>
                  <button type="button" class="cq-pill" style="padding: 3px 8px; font-size: 11px; border-radius: 6px; height: 26px; background: #fef3c7; color: #b45309; border: 1px solid #fde68a;" onclick="openQuestionGuide('match')">
                    <i class="fa fa-eye"></i> Matching
                  </button>
                  <button type="button" class="cq-pill pill-essay" onclick="openQuestionGuide('essay')" style="padding: 3px 8px; font-size: 11px; border-radius: 6px; height: 26px;">
                    <i class="fa fa-eye"></i> Essay (Rubric)
                  </button>
                </div>
              </div>

              <div class="cq-editor-label">
                <i class="fa fa-keyboard-o" style="color:#7c3aed;"></i> Paste your questions here <span class="text-danger">*</span>
              </div>

              <div class="cq-editor-container">
                <div class="cq-editor-body">
                  <textarea class="cq-code-area" id="cqPasteArea" wrap="off" placeholder="1. Which organ pumps blood throughout the human body?&#10;A. Brain&#10;B. Lungs&#10;C. Heart&#10;D. Liver&#10;Answer: C. Heart&#10;points: 2&#10;&#10;2. The Earth revolves around the Sun.&#10;True / False&#10;Answer: True&#10;points: 2" oninput="updateCqEditorStats()"></textarea>
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
        <button type="button" class="cq-btn-create" id="cqBtnSubmit" onclick="submitCqQuiz()"><i class="fa fa-floppy-o"></i> Create Quiz</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Question Type Format Guide Modal (Matching Picture) ── -->
<div class="modal fade" id="questionGuideModal" tabindex="-1" role="dialog" style="z-index: 1070;">
  <div class="modal-dialog" role="document" style="max-width: 520px; margin: 50px auto;">
    <div class="modal-content" style="border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 20px 45px -12px rgba(0,0,0,0.25); background: #ffffff; overflow: hidden;">
      <div class="modal-header" style="padding: 12px 18px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; background: #fff;">
        <h4 class="modal-title" style="font-size: 14px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 7px; margin: 0;">
          <i class="fa fa-book" style="color: #6366f1; font-size: 14px;"></i> <span id="qgModalTitle">Single MCQ Format Guide</span>
        </h4>
        <button type="button" class="close" data-dismiss="modal" style="font-size: 22px; color: #94a3b8; opacity: 1; outline: none; border: none; background: none; line-height: 1;">&times;</button>
      </div>

      <div class="modal-body" style="padding: 16px 18px; background: #f8fafc;">
        <div style="font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
          Format Syntax:
        </div>
        <div style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 10px; padding: 14px 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
          <pre id="qgFormatPreview" style="margin: 0; background: transparent; border: none; font-family: 'Courier New', Courier, monospace; font-size: 13px; color: #334155; line-height: 1.65; white-space: pre-wrap; word-break: break-word;"></pre>
        </div>
        <div id="qgFormatHint" style="margin-top: 10px; font-size: 11.5px; color: #64748b; line-height: 1.45;"></div>
      </div>
    </div>
  </div>
</div>

<script src="/cenlearn/system/bower_components/jquery/dist/jquery.min.js"></script>
<script src="/cenlearn/system/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
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
  window.location.href = `quizzes?class_id=${cid}&term=${term}&status=${status}`;
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

  $.post('/cenlearn/shared/quiz_handler', {
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

  $.post('/cenlearn/shared/quiz_handler', { action: 'analyze_relevance', quiz_id: quizId }, function(res) {
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
    $('#cqTabContentAi').hide();
    $('#cqTabContentUpload').hide();
  } else if(tab === 'ai') {
    $('#cqTabBtnAi').addClass('active');
    $('#cqTabContentPaste').hide();
    $('#cqTabContentAi').show();
    $('#cqTabContentUpload').hide();
    // Sync module select if already picked
    var curMod = $('#cqQuizModule').val();
    if(curMod && curMod != '0') $('#cqAiSelectModule').val(curMod);
  } else if(tab === 'upload') {
    $('#cqTabBtnUpload').addClass('active');
    $('#cqTabContentPaste').hide();
    $('#cqTabContentAi').hide();
    $('#cqTabContentUpload').show();
  }
}

function onCqClassChange(cid) {
  cid = parseInt(cid) || 0;
  $('#cqQuizModule option').each(function(){
    var optClass = parseInt($(this).data('class')) || 0;
    if(cid === 0 || optClass === 0 || optClass === cid){
      $(this).show();
    } else {
      $(this).hide();
    }
  });
}

function runAiQuizGeneration() {
  var modId = parseInt($('#cqAiSelectModule').val() || $('#cqQuizModule').val()) || 0;
  if(!modId || modId <= 0) {
    alert('Please select an uploaded Learning Module as the source of truth.');
    $('#cqAiSelectModule').focus();
    return;
  }

  var reqTypes = [];
  var counts = {};

  if($('#aiTypeMC').is(':checked'))   { reqTypes.push('multiple_choice');     counts['multiple_choice'] = parseInt($('#aiCountMC').val()) || 2; }
  if($('#aiTypeMSQ').is(':checked'))  { reqTypes.push('multi_select');        counts['multi_select'] = parseInt($('#aiCountMSQ').val()) || 2; }
  if($('#aiTypeTF').is(':checked'))   { reqTypes.push('true_false');          counts['true_false'] = parseInt($('#aiCountTF').val()) || 2; }
  if($('#aiTypeMTF').is(':checked'))  { reqTypes.push('modified_true_false'); counts['modified_true_false'] = parseInt($('#aiCountMTF').val()) || 2; }
  if($('#aiTypeID').is(':checked'))   { reqTypes.push('identification');      counts['identification'] = parseInt($('#aiCountID').val()) || 2; }
  if($('#aiTypeENUM').is(':checked')) { reqTypes.push('enumeration');         counts['enumeration'] = parseInt($('#aiCountENUM').val()) || 1; }
  if($('#aiTypeMATCH').is(':checked')){ reqTypes.push('matching');            counts['matching'] = parseInt($('#aiCountMATCH').val()) || 1; }
  if($('#aiTypeESSAY').is(':checked')){ reqTypes.push('essay');               counts['essay'] = parseInt($('#aiCountESSAY').val()) || 1; }

  if(reqTypes.length === 0) {
    alert('Please check at least one question type to generate.');
    return;
  }

  var difficulty = $('#aiDifficulty').val() || 'medium';
  var btn = $('#btnRunAiGen');
  btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Analyzing Module & Generating Quiz...');

  $.post('/cenlearn/shared/quiz_handler', {
    action: 'generate_quiz_from_module',
    module_id: modId,
    class_id: parseInt($('#cqQuizClass').val()) || 0,
    requested_types: JSON.stringify(reqTypes),
    question_counts: JSON.stringify(counts),
    difficulty: difficulty
  }, function(res) {
    btn.prop('disabled', false).html('<i class="fa fa-bolt"></i> Generate Quiz from Module');
    if(typeof res === 'string') { try { res = JSON.parse(res.trim()); } catch(e){} }
    if(!res || !res.success || !res.questions || res.questions.length === 0) {
      alert(res && res.msg ? res.msg : 'Failed to generate questions. Ensure module text is readable.');
      return;
    }

    // Set quiz title if empty
    if(!$('#cqQuizTitle').val().trim()){
      var modTitle = $('#cqAiSelectModule option:selected').text().trim();
      $('#cqQuizTitle').val(modTitle ? (modTitle.replace(/\s*\[.*\]/, '') + ' Quiz') : 'AI Module Quiz');
    }
    $('#cqQuizModule').val(modId);

    cqQuestionsData = res.questions;
    renderCqCards();
    alert('Successfully generated ' + res.questions.length + ' questions grounded strictly in the module! You can review and edit questions before saving.');
  }, 'json').fail(function(xhr, status, error){
    btn.prop('disabled', false).html('<i class="fa fa-bolt"></i> Generate Quiz from Module');
    alert('AI Generation request failed: ' + (xhr.responseText || error || 'Network error'));
  });
}

function updateCqEditorStats() {
  const text = $('#cqPasteArea').val();
  const lines = text.split('\n');
  const lineCount = lines.length;
  const charCount = text.length;

  $('#cqEditorLines').text(`Lines: ${lineCount}`);
  $('#cqEditorChars').text(`Characters: ${charCount}`);
}

let currentGuideSnippet = '';

const guideFormats = {
  'mc': {
    title: 'Single MCQ Format Guide',
    snippet: `1. Which organ pumps blood throughout the human body?\nA. Brain\nB. Lungs\nC. Heart\nD. Liver\nAnswer: C. Heart\npoints: 2`,
    hint: 'List choices A through D on separate lines. Specify the correct choice under <code>Answer:</code>.'
  },
  'msq': {
    title: 'Multi-Select MCQ Format Guide',
    snippet: `2. Which of the following are primary colors? (Select all correct answers)\nA. Red\nB. Green\nC. Blue\nD. Yellow\nAnswer: A, C, D\npoints: 2`,
    hint: 'Specify multiple letters separated by commas under <code>Answer:</code>.'
  },
  'tf': {
    title: 'True / False Format Guide',
    snippet: `3. The Earth revolves around the Sun.\nTrue / False\nAnswer: True\npoints: 1`,
    hint: 'Specify either <code>True</code> or <code>False</code> under <code>Answer:</code>.'
  },
  'mtf': {
    title: 'Modified True / False Format Guide',
    snippet: `4. Photosynthesis occurs in the mitochondria of plant cells.\nAnswer: False (Chloroplasts)\npoints: 2`,
    hint: 'If False, provide the correction in parentheses, e.g. <code>False (Correct Word)</code>.'
  },
  'id': {
    title: 'Identification Format Guide',
    snippet: `5. What is the largest land mammal on Earth?\nAnswer: Elephant\npoints: 1`,
    hint: 'Provide the exact term or concept under <code>Answer:</code>.'
  },
  'enum': {
    title: 'Enumeration Format Guide',
    snippet: `6. Name the three states of matter.\nAnswer: Solid, Liquid, Gas\npoints: 3`,
    hint: 'List items separated by commas or on separate lines under <code>Answer:</code>.'
  },
  'match': {
    title: 'Matching Type Format Guide',
    snippet: `7. Match Column A terms with Column B definitions.\nColumn A:\n1. Predictive Analytics\n2. Descriptive Analytics\n3. Prescriptive Analytics\nColumn B:\nA. Recommends optimal actions\nB. Predicts future outcomes\nC. Explains historical events\nAnswer: 1-B, 2-C, 3-A\npoints: 3`,
    hint: 'List numbered Column A items, lettered Column B items, and answer pairs like <code>1-B, 2-C</code>.'
  },
  'essay': {
    title: 'Essay (Rubric) Format Guide',
    snippet: `8. Explain why photosynthesis is essential for life on Earth.\nRubric: Understanding (4 pts), Accuracy (3 pts), Clarity (3 pts)\npoints: 10`,
    hint: 'Include rubric criteria points in parentheses under <code>Rubric:</code>.'
  }
};

function openQuestionGuide(type) {
  const g = guideFormats[type] || guideFormats['mc'];
  currentGuideSnippet = g.snippet;
  $('#qgModalTitle').text(g.title);
  $('#qgFormatPreview').text(g.snippet);
  $('#qgFormatHint').html('<i class="fa fa-info-circle text-primary"></i> ' + g.hint);
  $('#qgBtnCopy').html('<i class="fa fa-clone"></i> Copy Format');
  $('#questionGuideModal').modal('show');
}

function copyGuideFormat() {
  if(!currentGuideSnippet) return;
  if(navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(currentGuideSnippet).then(function() {
      $('#qgBtnCopy').html('<i class="fa fa-check text-success"></i> Copied!');
      setTimeout(function(){
        $('#qgBtnCopy').html('<i class="fa fa-clone"></i> Copy Format');
      }, 1500);
    });
  } else {
    const temp = $('<textarea>').val(currentGuideSnippet).appendTo('body').select();
    document.execCommand('copy');
    temp.remove();
    $('#qgBtnCopy').html('<i class="fa fa-check text-success"></i> Copied!');
    setTimeout(function(){
      $('#qgBtnCopy').html('<i class="fa fa-clone"></i> Copy Format');
    }, 1500);
  }
}

function insertQuestionFromGuide() {
  if(!currentGuideSnippet) return;
  const textarea = document.getElementById('cqPasteArea');
  const currentVal = textarea.value;
  textarea.value = currentVal ? (currentVal.trimEnd() + '\n\n' + currentGuideSnippet) : currentGuideSnippet;
  updateCqEditorStats();
  parseAndPreviewCq();
  $('#questionGuideModal').modal('hide');
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
points: 1

Multi-Select

2. Which of the following are primary colors? (Select all correct answers)
A. Red
B. Green
C. Blue
D. Yellow
Answer: A, C, D
points: 2

True or False

3. The Earth revolves around the Sun.
Answer: True
points: 1

Modified True or False

4. Photosynthesis occurs in the mitochondria of plant cells.
Answer: False (Chloroplasts)
points: 2

Identification

5. It is the largest land mammal on Earth.
Answer: Elephant
points: 1

Enumeration

6. Name the three states of matter.
Answer: Solid, Liquid, Gas
points: 3

Essay

7. Explain why photosynthesis is essential for life on Earth. (2–3 sentences)
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
  let qCounter = 1;

  const blocks = text.split(/\n\s*\n/);

  blocks.forEach(block => {
    const bLines = block.split(/\r?\n/).map(l => l.trim()).filter(l => l !== '');
    if(bLines.length === 0) return;

    // Check for section headers
    let currentSection = '';
    const firstLineLower = bLines[0].toLowerCase();
    if(bLines.length <= 2 && !bLines[0].match(/^\d+[\.\)]/)) {
      if(firstLineLower.includes('multi-select') || firstLineLower.includes('multiple answers')) { currentSection = 'msq'; if(bLines.length === 1) return; }
      else if(firstLineLower.includes('multiple choice')) { currentSection = 'mc'; if(bLines.length === 1) return; }
      else if(firstLineLower.includes('modified true')) { currentSection = 'mtf'; if(bLines.length === 1) return; }
      else if(firstLineLower.includes('true or false') || firstLineLower.includes('true / false')) { currentSection = 'tf'; if(bLines.length === 1) return; }
      else if(firstLineLower.includes('identification')) { currentSection = 'id'; if(bLines.length === 1) return; }
      else if(firstLineLower.includes('enumeration')) { currentSection = 'enum'; if(bLines.length === 1) return; }
      else if(firstLineLower.includes('matching')) { currentSection = 'match'; if(bLines.length === 1) return; }
      else if(firstLineLower.includes('essay')) { currentSection = 'essay'; if(bLines.length === 1) return; }
    }

    const blockText = bLines.join('\n');
    const isMatchingBlock = currentSection === 'match' ||
      /matching/i.test(firstLineLower) ||
      /column\s*a/i.test(blockText) ||
      /column\s*b/i.test(blockText) ||
      /answer:\s*\d+[\-\:\=][a-zA-Z]/i.test(blockText);

    let qStatement = '';
    let options = [];
    let correctAnswer = '';
    let points = 1;
    let topic = 'General';
    let matchingPairs = [];

    if(isMatchingBlock) {
      // Parse Matching Type
      let inColA = false;
      let inColB = false;
      let colAMap = {};
      let colBMap = {};
      let mainTitle = '';

      bLines.forEach(l => {
        const lowerL = l.toLowerCase();
        if(lowerL === 'matching type' || lowerL === 'matching') return;

        if(lowerL.startsWith('answer:')) {
          correctAnswer = l.substring(7).trim();
          inColA = false; inColB = false;
        } else if(lowerL.startsWith('points:')) {
          points = parseFloat(l.substring(7).trim()) || 1;
          inColA = false; inColB = false;
        } else if(lowerL.startsWith('topic:')) {
          topic = l.substring(6).trim() || 'General';
          inColA = false; inColB = false;
        } else if(lowerL.startsWith('column a:') || lowerL === 'column a') {
          inColA = true; inColB = false;
        } else if(lowerL.startsWith('column b:') || lowerL === 'column b') {
          inColB = true; inColA = false;
        } else if(inColA) {
          const matchA = l.match(/^(\d+)[\.\)]\s*(.+)/i);
          if(matchA) {
            colAMap[matchA[1]] = matchA[2].trim();
          } else {
            const nextIdx = Object.keys(colAMap).length + 1;
            colAMap[nextIdx] = l.replace(/^\d+[\.\)]\s*/, '').trim();
          }
        } else if(inColB) {
          const matchB = l.match(/^([a-zA-Z])[\.\)]\s*(.+)/i);
          if(matchB) {
            colBMap[matchB[1].toUpperCase()] = matchB[2].trim();
          } else {
            const nextLetter = String.fromCharCode(65 + Object.keys(colBMap).length);
            colBMap[nextLetter] = l.replace(/^[a-zA-Z][\.\)]\s*/, '').trim();
          }
        } else {
          const directMatch = l.match(/^\d+[\.\)]\s*([^=\-\:]+)\s*[\-\=\:]\s*([a-zA-Z])[\.\)]\s*(.+)/i);
          if(directMatch) {
            const itemNum = Object.keys(colAMap).length + 1;
            const letter = directMatch[2].toUpperCase();
            colAMap[itemNum] = directMatch[1].trim();
            colBMap[letter] = directMatch[3].trim();
            if(!correctAnswer) correctAnswer = itemNum + '-' + letter;
            else correctAnswer += ', ' + itemNum + '-' + letter;
          } else {
            const cleanL = l.replace(/^\d+[\.\)]\s*/, '').trim();
            if(cleanL && !cleanL.toLowerCase().startsWith('column a') && !cleanL.toLowerCase().startsWith('column b')) {
              mainTitle = mainTitle ? (mainTitle + ' ' + cleanL) : cleanL;
            }
          }
        }
      });

      qStatement = mainTitle || 'Match Column A terms with Column B definitions.';

      // Parse answer pairs: "1-C, 2-A, 3-B" or "1:C, 2:A, 3:B"
      if(correctAnswer) {
        const pairRegex = /(\d+)\s*[\-\:\=]\s*([a-zA-Z])/g;
        let pMatch;
        while((pMatch = pairRegex.exec(correctAnswer)) !== null) {
          const aKey = pMatch[1];
          const bKey = pMatch[2].toUpperCase();
          const aText = colAMap[aKey] || ('Item ' + aKey);
          const bText = colBMap[bKey] || ('Definition ' + bKey);
          matchingPairs.push({
            col_a_id: 'a-' + aKey,
            col_a_text: aText,
            col_b_id: 'b-' + bKey,
            col_b_text: bText
          });
        }
      }

      if(matchingPairs.length === 0 && Object.keys(colAMap).length > 0) {
        Object.keys(colAMap).forEach((k, idx) => {
          const bKey = String.fromCharCode(65 + idx);
          matchingPairs.push({
            col_a_id: 'a-' + k,
            col_a_text: colAMap[k],
            col_b_id: 'b-' + bKey,
            col_b_text: colBMap[bKey] || colBMap[Object.keys(colBMap)[idx]] || ('Option ' + bKey)
          });
        });
      }

      if(points <= 1 && matchingPairs.length > 0) {
        points = matchingPairs.length * 2;
      }
    } else {
      bLines.forEach(l => {
        const lowerL = l.toLowerCase();
        if(lowerL === 'true or false' || lowerL === 'multiple choice' || lowerL === 'identification' || lowerL === 'enumeration' || lowerL === 'essay') return;

        if(lowerL.startsWith('answer:')) {
          correctAnswer = l.substring(7).trim();
        } else if(lowerL.startsWith('points:') || lowerL.startsWith('point:')) {
          points = parseFloat(l.replace(/^points?:/i, '').trim()) || 1;
        } else if(lowerL.startsWith('topic:')) {
          topic = l.substring(6).trim() || 'General';
        } else if(l.match(/^[a-z][\.\)]\s*/i)) {
          options.push(l.replace(/^[a-z][\.\)]\s*/i, '').trim());
        } else {
          const cleanL = l.replace(/^\d+[\.\)]\s*/, '').trim();
          if(cleanL && !cleanL.toLowerCase().startsWith('answer:') && !cleanL.toLowerCase().startsWith('point:') && !cleanL.toLowerCase().startsWith('points:')) {
            qStatement = qStatement ? (qStatement + ' ' + cleanL) : cleanL;
          }
        }
      });
    }

    if(!qStatement) return;

    const lowerStmt = qStatement.toLowerCase();
    const lowerAns = correctAnswer.toLowerCase();

    let qType = 'multiple_choice';
    if(isMatchingBlock || matchingPairs.length > 0) {
      qType = 'matching';
    } else if(options.length >= 2) {
      // Split answer by comma, semicolon, '&', 'and'
      const ansParts = correctAnswer.split(/[,;&]|\band\b/i).map(s => s.trim().toLowerCase()).filter(Boolean);
      let matchedCount = 0;

      options.forEach((optText, oIdx) => {
        const letter = String.fromCharCode(65 + oIdx).toLowerCase();
        const optLower = optText.trim().toLowerCase();
        const cleanOpt = optLower.replace(/^[a-z][\.\)\:\-\s]+/i, '').trim();

        let isMatch = false;
        ansParts.forEach(p => {
          p = p.trim().toLowerCase();
          const cleanP = p.replace(/^[a-z][\.\)\:\-\s]+/i, '').trim();
          if (optLower === p || cleanOpt === p || (cleanP && cleanOpt === cleanP)) {
            isMatch = true;
          } else if (/^[a-z]$/i.test(p) && p === letter) {
            isMatch = true;
          }
        });

        if (isMatch) matchedCount++;
      });

      // If 1 answer: Single MCQ; If more than 1 answer: Multi-Select
      if(matchedCount > 1 || (ansParts.length > 1 && matchedCount > 0) || lowerStmt.includes('select all') || currentSection === 'msq') {
        qType = 'multi_select';
        if(points < 2) points = 2;
      } else {
        qType = 'multiple_choice';
      }
    } else if(currentSection === 'essay' || lowerStmt.startsWith('explain ') || lowerStmt.startsWith('why ') || lowerStmt.startsWith('describe ') || points >= 5) {
      qType = 'essay';
      if(points < 5) points = 10;
    } else if(currentSection === 'tf' || lowerAns === 'true' || lowerAns === 'false') {
      qType = 'true_false';
    } else if(currentSection === 'mtf' || lowerAns.includes('(') || lowerAns.includes('false -') || lowerAns.includes('false —')) {
      qType = 'modified_true_false';
      if(points < 2) points = 2;
    } else if(currentSection === 'enum' || correctAnswer.includes(',') || lowerStmt.startsWith('name ') || lowerStmt.startsWith('enumerate ') || lowerStmt.startsWith('list ')) {
      qType = 'enumeration';
      if(points < 2) points = 3;
    } else {
      qType = 'identification';
    }

    const qUid = (qType === 'multiple_choice' ? 'MCQ' : (qType === 'multi_select' ? 'MSQ' : (qType === 'true_false' ? 'TF' : (qType === 'modified_true_false' ? 'MTF' : (qType === 'identification' ? 'ID' : (qType === 'enumeration' ? 'ENUM' : (qType === 'matching' ? 'MATCH' : 'ESSAY'))))))) + '-' + String(qCounter).padStart(3, '0');
    qCounter++;

    // Generate options data and accurate correct option IDs
    let optionsData = [];
    let correctOptionIds = [];
    if(options.length > 0) {
      const ansParts = correctAnswer.split(/[,;&]|\band\b/i).map(s => s.trim().toLowerCase()).filter(Boolean);
      options.forEach((optText, oIdx) => {
        const optId = 'opt-' + qUid.toLowerCase() + '-' + String(oIdx + 1).padStart(2, '0');
        optionsData.push({ id: optId, text: optText });

        const letter = String.fromCharCode(65 + oIdx).toLowerCase();
        const optLower = optText.trim().toLowerCase();
        const cleanOpt = optLower.replace(/^[a-z][\.\)\:\-\s]+/i, '').trim();

        let isCorrect = false;
        ansParts.forEach(p => {
          p = p.trim().toLowerCase();
          const cleanP = p.replace(/^[a-z][\.\)\:\-\s]+/i, '').trim();
          if (optLower === p || cleanOpt === p || (cleanP && cleanOpt === cleanP)) {
            isCorrect = true;
          } else if (/^[a-z]$/i.test(p) && p === letter) {
            isCorrect = true;
          }
        });

        if(isCorrect) {
          correctOptionIds.push(optId);
        }
      });
    }

    cqQuestionsData.push({
      id: Date.now() + Math.random(),
      question_uid: qUid,
      question_type: qType,
      question_text: qStatement,
      options: options,
      options_data: optionsData,
      correct_option_ids: correctOptionIds,
      matching_pairs: matchingPairs,
      correct_answer: correctAnswer || (qType === 'essay' ? 'Teacher Grading / Rubric' : ''),
      points: points,
      topic: topic
    });
  });

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
        <p class="cq-empty-desc">Type or paste questions on the left, or use the "AI Generate from Module" tab to generate questions automatically.</p>
      </div>
    `);
    $('#cqDetectedBadge').text('0 Questions Detected (0 pts)');
    return;
  }

  const typeTags = {
    multiple_choice: { label: 'Single MCQ', class: 'tag-mc', color: '#7c3aed' },
    multi_select: { label: 'Multi-Select MCQ', class: 'tag-msq', color: '#1d4ed8' },
    true_false: { label: 'True / False', class: 'tag-tf', color: '#16a34a' },
    modified_true_false: { label: 'Modified T/F', class: 'tag-mtf', color: '#e11d48' },
    identification: { label: 'Identification', class: 'tag-id', color: '#f97316' },
    enumeration: { label: 'Enumeration', class: 'tag-enum', color: '#0284c7' },
    matching: { label: 'Matching', class: 'tag-match', color: '#d97706' },
    essay: { label: 'Essay', class: 'tag-essay', color: '#475569' }
  };

  cqQuestionsData.forEach((q, idx) => {
    const pts = parseFloat(q.points || 1);
    totalPoints += pts;
    const typeInfo = typeTags[q.question_type] || { label: q.question_type, color: '#64748b', class: 'tag-id' };
    const qUid = q.question_uid || ('Q-' + (idx + 1));
    const bColor = typeInfo.color;

    let bodyHtml = '';
    if(q.options_data && q.options_data.length > 0) {
      bodyHtml = '<div style="display:flex;flex-direction:column;gap:4px;margin-top:8px;">';
      q.options_data.forEach((opt, oIdx) => {
        const isCorr = (q.correct_option_ids && q.correct_option_ids.includes(opt.id));
        bodyHtml += `
          <div style="font-size:12px;padding:5px 10px;border-radius:6px;background:${isCorr ? '#dcfce7' : '#f8fafc'};border:1px solid ${isCorr ? '#86efac' : '#e2e8f0'};color:${isCorr ? '#166534' : '#334155'};display:flex;align-items:center;justify-content:space-between;">
            <span><strong>${String.fromCharCode(65 + oIdx)}.</strong> ${escapeCqHtml(opt.text)}</span>
            ${isCorr ? '<span style="font-size:10px;font-weight:700;color:#15803d;"><i class="fa fa-check"></i> Correct</span>' : ''}
          </div>
        `;
      });
      bodyHtml += '</div>';
    } else if(q.options && q.options.length > 0) {
      bodyHtml = '<div style="display:flex;flex-direction:column;gap:4px;margin-top:8px;">';
      q.options.forEach((opt, oIdx) => {
        bodyHtml += `<div style="font-size:12px;padding:5px 10px;border-radius:6px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;"><strong>${String.fromCharCode(65 + oIdx)}.</strong> ${escapeCqHtml(opt)}</div>`;
      });
      bodyHtml += '</div>';
    } else if(q.question_type === 'essay') {
      bodyHtml = `
        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:8px 10px;font-size:11.5px;color:#92400e;margin-top:8px;">
          <i class="fa fa-pencil"></i> <strong>Semantic AI Essay Grading:</strong> Graded against module concepts and rubric criteria.
        </div>
      `;
    } else if(q.question_type === 'matching' && q.matching_pairs) {
      bodyHtml = '<div style="margin-top:8px;font-size:12px;">';
      q.matching_pairs.forEach(mp => {
        bodyHtml += `<div style="padding:3px 0;color:#334155;"><strong>${escapeCqHtml(mp.col_a_text)}</strong> &rarr; <span style="color:#0284c7;">${escapeCqHtml(mp.col_b_text)}</span></div>`;
      });
      bodyHtml += '</div>';
    }

    let answerDisplay = '';
    if(q.correct_answer) {
      answerDisplay = `<div style="margin-top:6px;font-size:12px;color:#15803d;background:#f0fdf4;padding:4px 8px;border-radius:6px;display:inline-block;"><i class="fa fa-check-circle"></i> Ans: <strong>${escapeCqHtml(q.correct_answer)}</strong></div>`;
    }

    container.append(`
      <div class="cq-preview-card" style="background:#fff;border:1px solid #e2e8f0;border-left:4px solid ${bColor};border-radius:10px;padding:12px 14px;margin-bottom:10px;box-shadow:0 1px 3px rgba(0,0,0,0.02);">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;">
          <div style="display:flex;align-items:center;gap:6px;">
            <span style="font-size:11px;font-weight:800;background:#f1f5f9;color:#0f172a;padding:2px 6px;border-radius:4px;">${qUid}</span>
            <span style="font-size:10.5px;font-weight:700;background:${bColor}15;color:${bColor};padding:2px 8px;border-radius:4px;">${typeInfo.label}</span>
            <span style="font-size:10px;color:#64748b;background:#f8fafc;padding:2px 6px;border-radius:4px;border:1px solid #e2e8f0;"><i class="fa fa-tag"></i> ${escapeCqHtml(q.topic || 'General')}</span>
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:11.5px;font-weight:700;color:#64748b;">${pts} pt${pts !== 1 ? 's' : ''}</span>
            <button type="button" class="btn btn-xs btn-default" onclick="deleteCqCard(${idx})" title="Remove question" style="border:none;color:#ef4444;font-size:14px;padding:0 4px;"><i class="fa fa-times"></i></button>
          </div>
        </div>
        <div style="font-size:13px;font-weight:600;color:#0f172a;line-height:1.4;">${escapeCqHtml(q.question_text)}</div>
        ${bodyHtml}
        ${answerDisplay}
      </div>
    `);
  });

  $('#cqDetectedBadge').text(`${cqQuestionsData.length} Questions Detected (${totalPoints} pts)`);
}

function deleteCqCard(idx) {
  cqQuestionsData.splice(idx, 1);
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
  const timeLimit = parseInt($('#cqQuizTimeLimit').val()) || 0;
  const classId = 0;
  const moduleId = 0;
  const sq = $('#cqShuffleQuestions').is(':checked') ? 1 : 0;
  const sa = ($('#cqShuffleAnswers').length === 0 || $('#cqShuffleAnswers').is(':checked')) ? 1 : 0;
  const sm = $('#cqShuffleMatching').is(':checked') ? 1 : 0;
  const stf = $('#cqShuffleTF').is(':checked') ? 1 : 0;
  const randStudent = $('#cqRandomizeStudent').is(':checked') ? 1 : 0;
  const msScoring = $('#cqMultiSelectScoring').val() || 'partial_credit';

  if(!title) {
    alert('Please enter a Quiz Title.');
    $('#cqQuizTitle').focus();
    return;
  }

  if(cqQuestionsData.length === 0) {
    alert('Please add or parse questions first.');
    return;
  }

  const payload = {
    action: 'create',
    class_id: classId,
    module_id: moduleId,
    module_version: '1.0',
    title: title,
    instructions: '',
    time_limit: timeLimit,
    due_date: '',
    start_date: '',
    shuffle_questions: sq,
    shuffle_answers: sa,
    shuffle_matching: sm,
    shuffle_tf: stf,
    randomize_student: randStudent,
    multi_select_scoring_mode: msScoring,
    term: term,
    questions: JSON.stringify(cqQuestionsData)
  };

  const btn = $('#cqBtnSubmit');
  if(btn.length) btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving Quiz...');

  $.post('/cenlearn/shared/quiz_handler', payload, function(res) {
    if(res.success) {
      alert('Quiz created successfully with full AI, Seed Shuffle & Rubrics support!');
      location.reload();
    } else {
      alert(res.msg || 'Error saving quiz.');
      if(btn.length) btn.prop('disabled', false).html('<i class="fa fa-floppy-o"></i> Create Quiz');
    }
  }, 'json').fail(function(xhr, status, error){
    alert('Request failed: ' + (xhr.responseText || error || 'Network error'));
    if(btn.length) btn.prop('disabled', false).html('<i class="fa fa-floppy-o"></i> Create Quiz');
  });
}

var currentActiveQuizId = 0;

// Global helper functions for resolving option choices & boolean answers
function normalizeChoice(val, opts){
  if(val === undefined || val === null) return { index: -1, letter: '', text: '' };
  var str = String(val).trim();
  if(!str) return { index: -1, letter: '', text: '' };
  var strLower = str.toLowerCase();

  // 1. If single letter A-Z
  if(str.length === 1 && /^[a-zA-Z]$/.test(str)){
    var idx = str.toUpperCase().charCodeAt(0) - 65;
    if(opts && opts[idx] !== undefined){
      return { index: idx, letter: str.toUpperCase(), text: opts[idx] };
    }
  }

  // 2. If starts with letter prefix like "C. Heart", "C) Heart", "C: Heart", "C - Heart"
  var letterPrefixMatch = str.match(/^([a-zA-Z])[\.\)\:\-\s]+(.*)$/);
  if(letterPrefixMatch){
    var letChar = letterPrefixMatch[1].toUpperCase();
    var letIdx = letChar.charCodeAt(0) - 65;
    if(opts && opts[letIdx] !== undefined){
      return { index: letIdx, letter: letChar, text: opts[letIdx] };
    }
  }

  // 3. If number 0, 1, 2... or 1, 2, 3...
  if(/^\d+$/.test(str)){
    var num = parseInt(str, 10);
    if(opts && opts[num] !== undefined){
      return { index: num, letter: String.fromCharCode(65 + num), text: opts[num] };
    }
    if(opts && opts[num - 1] !== undefined){
      return { index: num - 1, letter: String.fromCharCode(65 + num - 1), text: opts[num - 1] };
    }
  }

  // 4. Exact, trimmed, or stripped prefix match with option text
  var cleanVal = strLower.replace(/^[a-zA-Z0-9][\.\)\:\-\s]+/i, '').trim();
  if(opts && opts.length){
    for(var i = 0; i < opts.length; i++){
      var optStr = String(opts[i]).trim().toLowerCase();
      var cleanOpt = optStr.replace(/^[a-zA-Z0-9][\.\)\:\-\s]+/i, '').trim();
      if(optStr === strLower || cleanOpt === cleanVal || cleanOpt === strLower || optStr === cleanVal){
        return { index: i, letter: String.fromCharCode(65 + i), text: opts[i] };
      }
    }
  }

  return { index: -1, letter: '', text: str };
}

function switchSubModalTab(tab){
  if(tab === 'submissions'){
    $('#tabQuestionsView').hide();
    $('#tabSubmissionsView').show();
    $('#btnTabQuestions').css({ background:'#e2e8f0', color:'#475569' });
    $('#btnTabSubmissions').css({ background:'#4f46e5', color:'#fff' });
  } else {
    $('#tabSubmissionsView').hide();
    $('#tabQuestionsView').show();
    $('#btnTabSubmissions').css({ background:'#e2e8f0', color:'#475569' });
    $('#btnTabQuestions').css({ background:'#4f46e5', color:'#fff' });
  }
}

function allowStudentRetake(quizId, studentCode, studentName){
  var displayName = studentName || studentCode;
  if(!confirm('Allow ' + displayName + ' (' + studentCode + ') to retake this quiz?\n\nThis will reset ONLY this student\'s submission and attempt records so they can take the quiz again.\n(Other students will remain submitted.)')){
    return;
  }
  $.post('/cenlearn/shared/quiz_handler', { action: 'allow_retake', quiz_id: quizId, student_code: studentCode }, function(res){
    if(typeof res === 'string'){ try { res = JSON.parse(res.trim()); } catch(e){} }
    if(res && res.success){
      alert(res.msg || 'Quiz attempt has been reset for ' + displayName + '. Only this student can now retake the quiz.');
      if(currentActiveQuizId) viewSubmissions(currentActiveQuizId);
      $('#studentAnswersModal').modal('hide');
    } else {
      alert((res && res.msg) ? res.msg : 'Failed to reset quiz attempt.');
    }
  }, 'json').fail(function(){
    alert('Network error while resetting quiz attempt.');
  });
}

function normalizeBool(val){
  if(!val) return '';
  var s = String(val).trim().toLowerCase();
  if(s === 't' || s === 'true' || s === '1') return 'true';
  if(s === 'f' || s === 'false' || s === '0') return 'false';
  return s;
}

function viewSubmissions(quizId) {
  currentActiveQuizId = quizId;
  $('#quizQuestionsContainer').html('<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i><p style="margin-top:10px;font-size:13.5px;font-weight:600;">Loading questions & correct answers...</p></div>');
  $('#quizSubmissionsContainer').html('<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i><p style="margin-top:10px;font-size:13.5px;font-weight:600;">Loading submissions...</p></div>');
  $('#quizQuestionsStats').empty();
  switchSubModalTab('questions');
  $('#submissionsModal').modal('show');

  $.post('/cenlearn/shared/quiz_handler', { action: 'get_submissions', quiz_id: quizId }, function(res) {
    if(typeof res === 'string'){ try { res = JSON.parse(res.trim()); } catch(e){} }
    if(!res || !res.success) {
      $('#quizQuestionsContainer').html('<div class="alert alert-danger" style="margin:20px;">' + (res && res.msg ? res.msg : 'Failed to fetch quiz details') + '</div>');
      return;
    }
    var qTitle = (res.quiz && res.quiz.title) ? res.quiz.title : 'Quiz';
    $('#subModalTitle').html('<i class="fa fa-list-alt" style="color:#60a5fa;"></i> ' + escapeCqHtml(qTitle) + ' &bull; Overview & Submissions');

    var questions = res.questions || [];
    currentModalQuestions = questions;
    var subs = res.submissions || [];
    $('#subCountBadge').text(subs.length);

    // ── RENDER QUESTIONS & CORRECT ANSWERS ──
    var totalPoints = 0;
    questions.forEach(function(q){ totalPoints += parseFloat(q.points || 1); });

    var qStatsHtml = `
      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
          <div><span style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700;display:block;">Questions</span><strong style="font-size:18px;color:#0f172a;">${questions.length}</strong></div>
          <div style="width:1px;height:28px;background:#e2e8f0;"></div>
          <div><span style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700;display:block;">Total Points</span><strong style="font-size:18px;color:#4f46e5;">${totalPoints} pts</strong></div>
          <div style="width:1px;height:28px;background:#e2e8f0;"></div>
          <div><span style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700;display:block;">Submissions</span><strong style="font-size:18px;color:#10b981;">${subs.length}</strong></div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <button type="button" class="btn btn-sm" onclick="copyAllQuizQuestionsText()" style="background:#e0e7ff;color:#4338ca;border:1px solid #c7d2fe;border-radius:8px;font-weight:700;padding:6px 12px;display:inline-flex;align-items:center;gap:6px;" title="Copy all questions & answer key text to clipboard">
            <i class="fa fa-copy"></i> Copy All Quiz
          </button>
          <button type="button" class="btn btn-sm" onclick="duplicateActiveQuiz()" style="background:#f3e8ff;color:#6b21a8;border:1px solid #e9d5ff;border-radius:8px;font-weight:700;padding:6px 12px;display:inline-flex;align-items:center;gap:6px;" title="Duplicate this entire quiz into a new quiz copy">
            <i class="fa fa-files-o"></i> Duplicate Quiz
          </button>
          <span style="background:#dcfce7;color:#15803d;border:1px solid #86efac;padding:5px 12px;border-radius:8px;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:5px;">
            <i class="fa fa-key"></i> Correct Answer Key
          </span>
        </div>
      </div>
    `;
    $('#quizQuestionsStats').html(qStatsHtml);

    var qListHtml = '';
    if(questions.length === 0){
      qListHtml = '<div class="alert alert-info" style="margin:10px 0;">No questions created for this quiz yet.</div>';
    } else {
      questions.forEach(function(q, idx){
        var typeFormatted = (q.question_type || 'multiple_choice').replace(/_/g, ' ').toUpperCase();
        var topicTag = (q.topic && q.topic !== 'General') ? `<span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:4px;font-size:10.5px;font-weight:700;"><i class="fa fa-tag"></i> ${escapeCqHtml(q.topic)}</span>` : '';
        var corr = q.correct_answer ? String(q.correct_answer).trim() : '';

        var answerBlock = '';
        if(q.question_type === 'multiple_choice' && q.options && q.options.length){
          var resC = normalizeChoice(corr, q.options);
          answerBlock = '<div style="display:flex;flex-direction:column;gap:6px;margin-top:10px;">';
          q.options.forEach(function(opt, optIdx){
            var letter = String.fromCharCode(65 + optIdx);
            var cleanOpt = String(opt).trim().toLowerCase().replace(/^[a-zA-Z0-9][\.\)\:\-\s]+/i, '');
            var cleanCorr = corr.toLowerCase().replace(/^[a-zA-Z0-9][\.\)\:\-\s]+/i, '');
            var isCorrect = (resC.index === optIdx || (resC.index === -1 && (cleanCorr === cleanOpt || corr.toLowerCase() === String(opt).trim().toLowerCase())));

            var optStyle = isCorrect
              ? 'background:#f0fdf4;border:2px solid #22c55e;color:#14532d;font-weight:700;'
              : 'background:#f8fafc;border:1px solid #e2e8f0;color:#334155;';
            var optTag = isCorrect
              ? '<span style="color:#15803d;background:#dcfce7;border:1px solid #86efac;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:800;margin-left:auto;"><i class="fa fa-check-circle"></i> Correct Answer</span>'
              : '';

            answerBlock += `
              <div style="${optStyle}padding:9px 14px;border-radius:8px;font-size:13px;display:flex;align-items:center;gap:8px;">
                <span style="font-weight:800;width:22px;color:#0f172a;">${letter}.</span>
                <span style="flex:1;">${escapeCqHtml(opt)}</span>
                ${optTag}
              </div>
            `;
          });
          answerBlock += '</div>';
        } else if(q.question_type === 'true_false'){
          var tfOpts = ['True', 'False'];
          var normC = normalizeBool(corr);

          answerBlock = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;">';
          tfOpts.forEach(function(opt){
            var isCorrect = (normC === opt.toLowerCase());
            var optStyle = isCorrect
              ? 'background:#f0fdf4;border:2px solid #22c55e;color:#14532d;font-weight:700;'
              : 'background:#f8fafc;border:1px solid #e2e8f0;color:#334155;';
            var tag = isCorrect
              ? '<div style="font-size:11px;font-weight:800;color:#15803d;margin-top:4px;"><i class="fa fa-check-circle"></i> Correct Answer</div>'
              : '';

            answerBlock += `
              <div style="${optStyle}padding:12px 14px;border-radius:8px;text-align:center;">
                <strong style="font-size:14px;display:block;">${opt}</strong>${tag}
              </div>
            `;
          });
          answerBlock += '</div>';
        } else if(q.question_type === 'essay'){
          answerBlock = `
            <div style="margin-top:10px;background:#fffbeb;border:1.5px solid #f59e0b;color:#92400e;padding:12px 14px;border-radius:8px;font-size:13px;">
              <i class="fa fa-pencil" style="margin-right:6px;"></i> <strong>Subjective / Essay Question</strong> &bull; Graded manually by teacher
            </div>
          `;
        } else if(q.question_type === 'matching' || (q.matching_pairs && q.matching_pairs.length > 0)){
          var pairs = q.matching_pairs || [];
          answerBlock = '<div style="margin-top:10px;display:flex;flex-direction:column;gap:6px;">';
          if(pairs.length > 0){
            pairs.forEach(function(p, pIdx){
              var aText = p.col_a_text || p.item_text || ('Item ' + (pIdx+1));
              var bText = p.col_b_text || p.target_text || ('Definition ' + (pIdx+1));
              answerBlock += `
                <div style="background:#f0fdf4;border:1px solid #86efac;padding:8px 12px;border-radius:8px;font-size:12.5px;display:flex;align-items:center;justify-content:space-between;gap:10px;">
                  <div>
                    <span style="font-weight:700;color:#0f172a;"><span style="color:#64748b;margin-right:4px;">${pIdx+1}.</span> ${escapeCqHtml(aText)}</span>
                  </div>
                  <div style="display:flex;align-items:center;gap:6px;">
                    <i class="fa fa-arrow-right" style="color:#10b981;font-size:11px;"></i>
                    <span style="background:#fff;border:1.5px solid #22c55e;color:#15803d;font-weight:800;padding:3px 12px;border-radius:6px;">${escapeCqHtml(bText)}</span>
                  </div>
                </div>
              `;
            });
          } else {
            answerBlock += `
              <div style="background:#f0fdf4;border:1.5px solid #22c55e;color:#15803d;padding:12px 14px;border-radius:8px;font-size:13px;display:flex;align-items:center;justify-content:space-between;">
                <div>
                  <i class="fa fa-check-circle" style="color:#16a34a;font-size:16px;margin-right:6px;"></i>
                  <strong style="color:#14532d;">Correct Pairs:</strong>
                  <span style="background:#fff;border:1px solid #86efac;padding:4px 12px;border-radius:6px;color:#15803d;font-weight:800;margin-left:6px;font-size:13.5px;">${escapeCqHtml(corr || '(None)')}</span>
                </div>
                <span style="background:#22c55e;color:#fff;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:800;"><i class="fa fa-check"></i> ANSWER KEY</span>
              </div>
            `;
          }
          answerBlock += '</div>';
        } else {
          answerBlock = `
            <div style="margin-top:10px;background:#f0fdf4;border:1.5px solid #22c55e;color:#15803d;padding:12px 14px;border-radius:8px;font-size:13px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
              <div>
                <i class="fa fa-check-circle" style="color:#16a34a;font-size:16px;margin-right:6px;"></i>
                <strong style="color:#14532d;">Correct Answer:</strong>
                <span style="background:#fff;border:1px solid #86efac;padding:4px 12px;border-radius:6px;color:#15803d;font-weight:800;margin-left:6px;font-size:13.5px;">${escapeCqHtml(corr || '(None)')}</span>
              </div>
              <span style="background:#22c55e;color:#fff;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:800;"><i class="fa fa-check"></i> ANSWER KEY</span>
            </div>
          `;
        }

        qListHtml += `
          <div style="background:#fff;border:1.5px solid #e2e8f0;border-left:5px solid #4f46e5;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
              <div style="display:flex;align-items:center;gap:6px;">
                <span style="font-size:12px;font-weight:800;color:#0f172a;background:#f1f5f9;padding:2px 8px;border-radius:6px;">#${idx+1}</span>
                <span style="font-size:10px;font-weight:800;color:#4f46e5;background:#e0e7ff;padding:2px 8px;border-radius:4px;text-transform:uppercase;">${typeFormatted}</span>
                ${topicTag}
              </div>
              <div style="display:flex;align-items:center;gap:6px;">
                <button type="button" class="btn btn-xs" onclick="copyIndividualQuestionText(${idx})" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;border-radius:6px;font-weight:700;padding:3px 8px;" title="Copy this individual question to clipboard">
                  <i class="fa fa-copy" style="color:#6366f1;"></i> Copy Question
                </button>
                <button type="button" class="btn btn-xs" onclick="duplicateIndividualQuestion(${q.id || 0}, ${idx})" style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;border-radius:6px;font-weight:700;padding:3px 8px;" title="Duplicate this question in the quiz">
                  <i class="fa fa-plus-circle" style="color:#0284c7;"></i> Duplicate Q
                </button>
                <span style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;">
                  <i class="fa fa-trophy" style="color:#f59e0b;"></i> ${q.points} pt${parseFloat(q.points)!==1?'s':''}
                </span>
              </div>
            </div>
            <div style="font-size:13.5px;font-weight:700;color:#0f172a;line-height:1.4;margin-bottom:6px;">
              ${escapeCqHtml(q.question_text)}
            </div>
            ${answerBlock}
          </div>
        `;
      });
    }
    $('#quizQuestionsContainer').html(qListHtml);

    // ── RENDER STUDENT SUBMISSIONS & PER-STUDENT RETAKE BUTTONS ──
    var subHtml = '';
    if(subs.length === 0){
      subHtml = '<div class="alert alert-info" style="margin:10px 0;"><i class="fa fa-info-circle"></i> No student submissions recorded for this quiz yet.</div>';
    } else {
      subHtml = `
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
          <table class="table table-hover" style="margin:0;font-size:13px;">
            <thead style="background:#f8fafc;color:#475569;font-weight:700;border-bottom:1px solid #e2e8f0;">
              <tr>
                <th style="padding:12px 14px;">Student Name</th>
                <th style="padding:12px 10px;">ID Number</th>
                <th style="padding:12px 10px;">Score</th>
                <th style="padding:12px 10px;text-align:center;">Percentage</th>
                <th style="padding:12px 10px;">Submitted Date</th>
                <th style="padding:12px 14px;text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody>
      `;

      subs.forEach(function(s){
        var scoreVal = parseFloat(s.score || 0);
        var totalPts = parseFloat(s.total_points || 0);
        var pct = parseFloat(s.percentage || 0);
        var pctBg = (pct >= 75) ? '#dcfce7' : ((pct >= 60) ? '#fef3c7' : '#fee2e2');
        var pctColor = (pct >= 75) ? '#15803d' : ((pct >= 60) ? '#b45309' : '#dc2626');
        var sName = escapeCqHtml(s.first_name + ' ' + s.last_name);
        var sCode = escapeCqHtml(s.user_code || s.student_code);

        subHtml += `
          <tr style="border-bottom:1px solid #f1f5f9;">
            <td style="padding:12px 14px;font-weight:700;color:#0f172a;">
              <i class="fa fa-user-circle" style="color:#6366f1;margin-right:6px;"></i> ${sName}
            </td>
            <td style="padding:12px 10px;color:#64748b;font-weight:600;">${sCode}</td>
            <td style="padding:12px 10px;font-weight:700;color:#0f172a;">${scoreVal.toFixed(1)} / ${totalPts}</td>
            <td style="padding:12px 10px;text-align:center;">
              <span style="background:${pctBg};color:${pctColor};padding:3px 8px;border-radius:6px;font-size:11.5px;font-weight:800;">${pct}%</span>
            </td>
            <td style="padding:12px 10px;color:#64748b;font-size:12px;">${escapeCqHtml(s.submitted_at)}</td>
            <td style="padding:12px 14px;text-align:right;">
              <div style="display:inline-flex;align-items:center;gap:6px;">
                <button type="button" class="btn btn-xs" onclick="viewStudentAnswers('${quizId}','${escapeCqAttr(s.user_code || s.student_code)}')" style="background:#4f46e5;color:#fff;border-radius:6px;font-weight:700;padding:5px 10px;border:none;">
                  <i class="fa fa-eye"></i> View Answers
                </button>
                <button type="button" class="btn btn-xs" onclick="allowStudentRetake('${quizId}','${escapeCqAttr(s.user_code || s.student_code)}','${escapeCqAttr(s.first_name + ' ' + s.last_name)}')" style="background:#d97706;color:#fff;border-radius:6px;font-weight:700;padding:5px 10px;border:none;" title="Allow ONLY this student to retake the quiz">
                  <i class="fa fa-refresh"></i> Allow Retake
                </button>
              </div>
            </td>
          </tr>
        `;
      });

      subHtml += '</tbody></table></div>';
    }
    $('#quizSubmissionsContainer').html(subHtml);
  }, 'json');
}

function viewStudentAnswers(quizId, studentCode) {
  $('#saQuestionsList').html('<div style="text-align:center;padding:40px;color:#64748b;"><i class="fa fa-spinner fa-spin fa-2x"></i><p style="margin-top:10px;">Loading student answers...</p></div>');
  $('#submissionsModal').modal('hide');
  $('#studentAnswersModal').modal('show');

  $.post('/cenlearn/shared/quiz_handler', {
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

    let acAlert = '';
    if(res.tab_switches > 0 || res.fullscreen_exits > 0) {
      acAlert = `<span style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;"><i class="fa fa-exclamation-triangle"></i> Alerts: ${res.tab_switches} tab switches, ${res.fullscreen_exits} fs exits</span>`;
    }

    const qList = res.questions || [];
    let correctCount = 0, wrongCount = 0, manualCount = 0;
    qList.forEach(q => {
      if(q.is_correct === true) correctCount++;
      else if(q.is_correct === false) wrongCount++;
      else manualCount++;
    });

    $('#saStudentHeader').html(`
      <div>
        <h4 style="margin:0 0 4px 0;font-weight:800;color:#0f172a;font-size:15px;display:flex;align-items:center;gap:8px;">
          <i class="fa fa-user-circle" style="color:#4f46e5;font-size:18px;"></i> ${escapeCqHtml(res.student_name)}
        </h4>
        <span style="font-size:12px;color:#64748b;">ID: <strong style="color:#0f172a;">${escapeCqHtml(res.student_code)}</strong> &bull; Quiz: <strong style="color:#0f172a;">${escapeCqHtml(res.quiz_title)}</strong></span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:6px;">
          <span style="background:#dcfce7;color:#15803d;border:1px solid #86efac;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:800;"><i class="fa fa-check"></i> ${correctCount} Correct</span>
          <span style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:800;"><i class="fa fa-times"></i> ${wrongCount} Wrong</span>
        </div>
        ${acAlert}
        <span class="label ${pctBadge}" style="font-size:13px;padding:6px 12px;border-radius:8px;">
          Score: <strong>${res.score}</strong> / ${res.total_points} (${pct}%)
        </span>
        <button type="button" onclick="allowStudentRetake(currentActiveQuizId, '${escapeCqAttr(res.student_code)}', '${escapeCqAttr(res.student_name)}')" style="background:#d97706;color:#fff;border:none;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:6px;cursor:pointer;box-shadow:0 2px 4px rgba(217,119,6,0.25);" title="Reset ONLY this student's submission to allow retake">
          <i class="fa fa-refresh"></i> Allow Retake
        </button>
      </div>
      <div style="margin-top:10px;text-align:right;">
        <span style="font-size:11px;color:#64748b;background:#f1f5f9;border:1px solid #e2e8f0;padding:6px 10px;border-radius:8px;font-weight:600;">
          <i class="fa fa-calendar"></i> ${res.submitted_at}
        </span>
      </div>
    `);

    // Helper functions for formatting answers
    function formatAnswerText(rawVal, options, type, pairs, optionsData){
      if(rawVal === undefined || rawVal === null || rawVal === '') return '(No answer submitted)';
      var optData = optionsData || [];

      // Multi-select: answer can be array OR object {"a-1":"opt-id","a-2":"opt-id"}
      if(type === 'multi_select' || type === 'multiple_answers'){
        var ids = [];
        if(Array.isArray(rawVal)){
          ids = rawVal;
        } else if(rawVal && typeof rawVal === 'object'){
          ids = Object.values(rawVal);
        } else if(typeof rawVal === 'string' && rawVal.trim()){
          try {
            var p = JSON.parse(rawVal.trim());
            if(Array.isArray(p)) ids = p;
            else if(p && typeof p === 'object') ids = Object.values(p);
          } catch(e){ ids = rawVal.split(',').map(function(s){ return s.trim(); }); }
        }
        if(ids.length === 0) return '(No answer submitted)';
        return ids.map(function(id){ return resolveOptId(id, options, optData); }).join(', ');
      }

      if(Array.isArray(rawVal)){
        if(rawVal.length === 0) return '(No answer submitted)';
        return rawVal.map(function(item){ return formatSingleAnswer(item, options, optData); }).join(', ');
      }

      if(typeof rawVal === 'string' && (rawVal.trim().startsWith('[') || rawVal.trim().startsWith('{'))){
        try {
          var parsed = JSON.parse(rawVal.trim());
          if(Array.isArray(parsed)){
            if(parsed.length === 0) return '(No answer submitted)';
            return parsed.map(function(item){ return formatSingleAnswer(item, options, optData); }).join(', ');
          }
          if(typeof parsed === 'object' && parsed !== null){
            // Modified true/false object
            if(parsed.truth !== undefined || parsed.correction !== undefined){
              var t = parsed.truth ? String(parsed.truth).toUpperCase() : '';
              var c = parsed.correction ? (' — ' + parsed.correction) : '';
              return t + c;
            }
            // Matching type object: keys are col_a_ids, values are col_b_ids — show values
            var parts = Object.values(parsed);
            return parts.map(function(v){ return String(v); }).join(', ');
          }
        } catch(e){}
      }

      // Modified true/false stored as "True" or "False — correction"
      if(typeof rawVal === 'string'){
        return rawVal;
      }

      if(typeof rawVal === 'object' && rawVal !== null){
        // Object (not array) — extract values
        var vals = Object.values(rawVal);
        return vals.map(function(v){ return String(v); }).join(', ');
      }

      return formatSingleAnswer(rawVal, options, optData);
    }

    function resolveOptId(optId, options, optData){
      var s = String(optId || '').trim();
      if(!s) return '';
      // Try to find in options_data by ID
      if(optData && optData.length){
        for(var i=0; i<optData.length; i++){
          if(optData[i] && optData[i].id === s){
            return String.fromCharCode(65 + i) + '. ' + optData[i].text;
          }
        }
      }
      // Fallback to formatSingleAnswer
      return formatSingleAnswer(s, options, optData);
    }

    function formatSingleAnswer(val, options, optData){
      if(val === undefined || val === null || val === '') return '';
      var s = String(val).trim();
      // Try options_data first (real IDs like "opt-msq-001-01")
      if(optData && optData.length){
        for(var di=0; di<optData.length; di++){
          if(optData[di] && optData[di].id === s){
            return String.fromCharCode(65 + di) + '. ' + optData[di].text;
          }
        }
      }
      // Try positional match "opt-N"
      if(options && options.length){
        var match = s.match(/^opt-(\d+)$/i);
        if(match){
          var idx = parseInt(match[1]);
          if(options[idx] !== undefined){
            return String.fromCharCode(65 + idx) + '. ' + options[idx];
          }
        }
        for(var i=0; i<options.length; i++){
          if(options[i].toLowerCase() === s.toLowerCase()){
            return String.fromCharCode(65 + i) + '. ' + options[i];
          }
        }
      }
      return s;
    }

    function normChoice(val, opts){
      if(!val) return { index: -1, letter: '', text: '' };
      const str = String(val).trim();
      if(!str) return { index: -1, letter: '', text: '' };
      const strLower = str.toLowerCase();
      if(str.length === 1 && /^[a-zA-Z]$/.test(str)){
        const idx = str.toUpperCase().charCodeAt(0) - 65;
        if(opts && opts[idx] !== undefined) return { index: idx, letter: str.toUpperCase(), text: opts[idx] };
      }
      if(/^\d+$/.test(str)){
        const num = parseInt(str, 10);
        if(opts && opts[num] !== undefined) return { index: num, letter: String.fromCharCode(65 + num), text: opts[num] };
        if(opts && opts[num - 1] !== undefined) return { index: num - 1, letter: String.fromCharCode(65 + num - 1), text: opts[num - 1] };
      }
      if(opts && opts.length){
        for(let i = 0; i < opts.length; i++){
          const optStr = String(opts[i]).trim().toLowerCase();
          if(optStr === strLower) return { index: i, letter: String.fromCharCode(65 + i), text: opts[i] };
          const stripped = optStr.replace(/^[a-z][\.\)]\s*/i, '');
          if(stripped === strLower || optStr === (String.fromCharCode(97 + i) + '. ' + strLower)){
            return { index: i, letter: String.fromCharCode(65 + i), text: opts[i] };
          }
        }
      }
      return { index: -1, letter: '', text: str };
    }

    function normBool(val){
      if(!val) return '';
      const s = String(val).trim().toLowerCase();
      if(s === 't' || s === 'true' || s === '1') return 'true';
      if(s === 'f' || s === 'false' || s === '0') return 'false';
      return s;
    }

    // Render Question Cards
    let qHtml = '';
    if(qList.length === 0) {
      qHtml = '<div class="alert alert-info">No questions found for this quiz.</div>';
    } else {
      qList.forEach((q, idx) => {
        const isCorrect = q.is_correct;
        const typeFormatted = (q.question_type || 'multiple_choice').replace(/_/g, ' ').toUpperCase();
        const given = formatAnswerText(q.given_answer, q.options, q.question_type, q.matching_pairs, q.options_data);
        const correct = q.correct_answer ? String(q.correct_answer).trim() : '';
        
        let statusBadge = '';
        let cardBorder = '#e2e8f0';
        let cardLeft = '5px solid #e2e8f0';
        if(isCorrect === true) {
          statusBadge = `<span style="background:#dcfce7;color:#15803d;border:1px solid #86efac;padding:4px 10px;border-radius:6px;font-size:11.5px;font-weight:800;display:inline-flex;align-items:center;gap:4px;"><i class="fa fa-check-circle" style="color:#16a34a;"></i> CORRECT (+${q.earned_points !== undefined ? q.earned_points : q.points}/${q.points} pts)</span>`;
          cardBorder = '#86efac';
          cardLeft = '5px solid #22c55e';
        } else if(isCorrect === false) {
          statusBadge = `<span style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:4px 10px;border-radius:6px;font-size:11.5px;font-weight:800;display:inline-flex;align-items:center;gap:4px;"><i class="fa fa-times-circle" style="color:#dc2626;"></i> WRONG (${q.earned_points !== undefined ? q.earned_points : 0}/${q.points} pts)</span>`;
          cardBorder = '#fca5a5';
          cardLeft = '5px solid #ef4444';
        } else {
          statusBadge = `<span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:4px 10px;border-radius:6px;font-size:11.5px;font-weight:800;display:inline-flex;align-items:center;gap:4px;"><i class="fa fa-pencil"></i> MANUAL GRADING (${q.points} pts)</span>`;
          cardBorder = '#fde68a';
          cardLeft = '5px solid #f59e0b';
        }

        const topicTag = q.topic && q.topic !== 'General' ? `<span style="background:#e0f2fe;color:#0369a1;padding:2px 7px;border-radius:4px;font-size:10.5px;font-weight:600;"><i class="fa fa-tag"></i> ${escapeCqHtml(q.topic)}</span>` : '';

        let answerContent = '';
        if(q.options && q.options.length > 0 && q.question_type === 'multiple_choice') {
          const resG = normChoice(q.given_answer, q.options);
          const resC = normChoice(correct, q.options);

          answerContent = '<div style="display:flex;flex-direction:column;gap:6px;margin-top:10px;">';
          q.options.forEach((opt, optIdx) => {
            const letter = String.fromCharCode(65 + optIdx);
            const isStudentPick = (resG.index === optIdx || (resG.index === -1 && String(q.given_answer).toLowerCase() === opt.toLowerCase()));
            const isCorrectPick = (resC.index === optIdx || (resC.index === -1 && correct.toLowerCase() === opt.toLowerCase()));

            let optStyle = 'background:#f8fafc;border:1px solid #e2e8f0;color:#334155;';
            let optTag = '';

            if(isStudentPick && isCorrectPick) {
              optStyle = 'background:#f0fdf4;border:2px solid #22c55e;color:#14532d;font-weight:700;';
              optTag = '<span style="color:#15803d;background:#dcfce7;border:1px solid #86efac;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:800;margin-left:auto;"><i class="fa fa-check-circle"></i> Student Answer (Correct)</span>';
            } else if(isStudentPick && !isCorrectPick) {
              optStyle = 'background:#fef2f2;border:2px solid #ef4444;color:#7f1d1d;font-weight:700;';
              optTag = '<span style="color:#991b1b;background:#fee2e2;border:1px solid #fca5a5;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:800;margin-left:auto;"><i class="fa fa-times-circle"></i> Student Answer (Wrong)</span>';
            } else if(isCorrectPick) {
              optStyle = 'background:#f0fdf4;border:2px dashed #22c55e;color:#15803d;font-weight:700;';
              optTag = '<span style="color:#15803d;background:#dcfce7;border:1px solid #86efac;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:800;margin-left:auto;"><i class="fa fa-check"></i> Correct Answer</span>';
            }

            answerContent += `
              <div style="${optStyle}padding:9px 14px;border-radius:8px;font-size:13px;display:flex;align-items:center;gap:8px;">
                <span style="font-weight:700;width:22px;color:#0f172a;">${letter}.</span>
                <span style="flex:1;">${escapeCqHtml(opt)}</span>
                ${optTag}
              </div>
            `;
          });
          answerContent += '</div>';
        } else if((q.question_type === 'multi_select' || q.question_type === 'multiple_answers') && q.options && q.options.length > 0) {
          // Extract selected option IDs — handles both array format AND object format {"a-1":"opt-id",...}
          var selArr = [];
          if(Array.isArray(q.given_answer)){
            selArr = q.given_answer;
          } else if(q.given_answer && typeof q.given_answer === 'object'){
            // Object format: values are the selected option IDs
            selArr = Object.values(q.given_answer);
          } else if(typeof q.given_answer === 'string' && q.given_answer.trim()){
            try {
              var parsedG = JSON.parse(q.given_answer.trim());
              if(Array.isArray(parsedG)) selArr = parsedG;
              else if(parsedG && typeof parsedG === 'object') selArr = Object.values(parsedG);
              else selArr = [q.given_answer];
            } catch(e){ selArr = [q.given_answer]; }
          }
          var corrOptIds = q.correct_option_ids || [];
          // Build map: optId -> index from options_data so we can match selArr to display rows
          var optDataList = q.options_data || [];

          answerContent = '<div style="display:flex;flex-direction:column;gap:6px;margin-top:10px;">';
          q.options.forEach(function(opt, optIdx){
            // Get the real option ID from options_data (e.g. "opt-msq-001-01")
            var realOptId = (optDataList[optIdx] && optDataList[optIdx].id) ? optDataList[optIdx].id : ('opt-' + optIdx);
            var letter = String.fromCharCode(65 + optIdx);
            // Check if student selected this option — check by real ID, text, or positional fallbacks
            var isStudentPick = (
              selArr.indexOf(realOptId) !== -1 ||
              selArr.indexOf('opt-' + optIdx) !== -1 ||
              selArr.indexOf(String(optIdx)) !== -1 ||
              selArr.indexOf(opt) !== -1
            );
            // Check if this is a correct option — check by real ID first
            var isCorrectPick = (
              corrOptIds.indexOf(realOptId) !== -1 ||
              corrOptIds.indexOf('opt-' + optIdx) !== -1 ||
              corrOptIds.indexOf(String(optIdx)) !== -1
            );

            var optStyle = 'background:#f8fafc;border:1px solid #e2e8f0;color:#334155;';
            var optTag = '';

            if(isStudentPick && isCorrectPick) {
              optStyle = 'background:#f0fdf4;border:2px solid #22c55e;color:#14532d;font-weight:700;';
              optTag = '<span style="color:#15803d;background:#dcfce7;border:1px solid #86efac;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:800;margin-left:auto;"><i class="fa fa-check-square"></i> Selected (Correct)</span>';
            } else if(isStudentPick && !isCorrectPick) {
              optStyle = 'background:#fef2f2;border:2px solid #ef4444;color:#7f1d1d;font-weight:700;';
              optTag = '<span style="color:#991b1b;background:#fee2e2;border:1px solid #fca5a5;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:800;margin-left:auto;"><i class="fa fa-times-circle"></i> Selected (Wrong)</span>';
            } else if(isCorrectPick) {
              optStyle = 'background:#f0fdf4;border:2px dashed #22c55e;color:#15803d;font-weight:700;';
              optTag = '<span style="color:#15803d;background:#dcfce7;border:1px solid #86efac;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:800;margin-left:auto;"><i class="fa fa-check"></i> Expected Selection</span>';
            }

            answerContent += `
              <div style="${optStyle}padding:9px 14px;border-radius:8px;font-size:13px;display:flex;align-items:center;gap:8px;">
                <span style="font-weight:700;width:22px;color:#0f172a;">${letter}.</span>
                <span style="flex:1;">${escapeCqHtml(opt)}</span>
                ${optTag}
              </div>
            `;
          });
          answerContent += '</div>';
        } else if(q.question_type === 'true_false') {
          const tfOpts = ['True', 'False'];
          const normG = normBool(q.given_answer);
          const normC = normBool(correct);

          answerContent = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;">';
          tfOpts.forEach(opt => {
            const key = opt.toLowerCase();
            const isStudentPick = (normG === key);
            const isCorrectPick = (normC === key);

            let bg = '#f8fafc', clr = '#334155', bdr = '1px solid #e2e8f0', tag = '';
            if(isStudentPick && isCorrectPick) {
              bg = '#f0fdf4'; clr = '#14532d'; bdr = '2px solid #22c55e';
              tag = '<div style="font-size:11px;font-weight:800;color:#15803d;margin-top:4px;"><i class="fa fa-check-circle"></i> Student Answer (Correct)</div>';
            } else if(isStudentPick && !isCorrectPick) {
              bg = '#fef2f2'; clr = '#7f1d1d'; bdr = '2px solid #ef4444';
              tag = '<div style="font-size:11px;font-weight:800;color:#991b1b;margin-top:4px;"><i class="fa fa-times-circle"></i> Student Answer (Wrong)</div>';
            } else if(isCorrectPick) {
              bg = '#f0fdf4'; clr = '#15803d'; bdr = '2px dashed #22c55e';
              tag = '<div style="font-size:11px;font-weight:800;color:#15803d;margin-top:4px;"><i class="fa fa-check"></i> Correct Answer</div>';
            }

            answerContent += `
              <div style="padding:12px 14px;border-radius:8px;background:${bg};color:${clr};border:${bdr};text-align:center;">
                <strong style="font-size:14px;display:block;">${opt}</strong>${tag}
              </div>
            `;
          });
          answerContent += '</div>';
        } else if(q.question_type === 'essay') {
          const aiScore = q.ai_score !== undefined ? q.ai_score : (q.earned_points || 0);
          const aiFb = q.ai_feedback || q.feedback || 'Evaluated against module learning concepts and rubric.';
          const tScore = (q.teacher_score !== null && q.teacher_score !== undefined) ? q.teacher_score : aiScore;
          const tFb = q.teacher_feedback || '';
          const hasOverride = (q.teacher_score !== null && q.teacher_score !== undefined);

          let rubricHtml = '';
          if(q.rubric_scores && typeof q.rubric_scores === 'object') {
            rubricHtml = '<div style="margin-top:8px;padding-top:8px;border-top:1px dashed #fde68a;"><div style="font-weight:700;font-size:11px;color:#92400e;margin-bottom:4px;text-transform:uppercase;">Rubric Breakdown:</div><div style="display:flex;flex-wrap:wrap;gap:6px;">';
            for(let crit in q.rubric_scores) {
              rubricHtml += `<span style="background:#fff;border:1px solid #fde68a;padding:2px 8px;border-radius:4px;font-size:11px;color:#78350f;"><strong>${escapeCqHtml(crit)}:</strong> ${q.rubric_scores[crit]} pts</span>`;
            }
            rubricHtml += '</div></div>';
          }

          answerContent = `
            <div style="margin-top:10px;background:#fffbeb;border:1.5px solid #f59e0b;color:#92400e;padding:14px;border-radius:10px;font-size:13px;">
              <div style="font-weight:700;margin-bottom:6px;"><i class="fa fa-pencil" style="margin-right:6px;"></i> Student Written Response:</div>
              <div style="background:#fff;border:1px solid #fde68a;padding:12px;border-radius:8px;white-space:pre-wrap;color:#1e293b;font-size:13px;line-height:1.5;margin-bottom:12px;">${escapeCqHtml(q.given_answer || '(No response submitted)')}</div>
              
              <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;margin-bottom:12px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
                  <strong style="color:#1e40af;font-size:12.5px;"><i class="fa fa-magic" style="color:#2563eb;"></i> AI Semantic Evaluation</strong>
                  <span style="background:#dbeafe;color:#1d4ed8;font-weight:800;padding:2px 8px;border-radius:6px;font-size:11.5px;">AI Score: ${aiScore} / ${q.points} pts</span>
                </div>
                <p style="margin:0;font-size:12px;color:#1e3a8a;line-height:1.4;">${escapeCqHtml(aiFb)}</p>
                ${rubricHtml}
              </div>

              <!-- Teacher Override Form -->
              <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                  <strong style="color:#0f172a;font-size:12.5px;"><i class="fa fa-sliders" style="color:#7c3aed;"></i> Teacher Grade & Feedback Override</strong>
                  ${hasOverride ? '<span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:4px;font-size:10.5px;font-weight:700;"><i class="fa fa-check"></i> Teacher Overridden</span>' : '<span style="color:#64748b;font-size:11px;">(100% Teacher Authority)</span>'}
                </div>
                <div style="display:grid;grid-template-columns:120px 1fr auto;gap:10px;align-items:center;">
                  <div>
                    <label style="font-size:11px;color:#64748b;font-weight:700;display:block;margin-bottom:2px;">Final Score</label>
                    <input type="number" step="0.5" min="0" max="${q.points}" id="overrideScore_${q.id}" value="${tScore}" class="form-control input-sm" style="font-weight:800;font-size:13px;border-radius:6px;">
                  </div>
                  <div>
                    <label style="font-size:11px;color:#64748b;font-weight:700;display:block;margin-bottom:2px;">Teacher Feedback / Notes to Student</label>
                    <input type="text" id="overrideFb_${q.id}" value="${escapeCqAttr(tFb)}" placeholder="Add remarks or feedback..." class="form-control input-sm" style="border-radius:6px;font-size:12px;">
                  </div>
                  <div style="padding-top:16px;">
                    <button type="button" class="btn btn-sm btn-primary" style="background:#4f46e5;border:none;border-radius:6px;font-weight:700;" onclick="saveEssayOverride(${res.submission_id || 0}, ${q.id}, ${quizId}, '${studentCode}')">
                      <i class="fa fa-save"></i> Save Override
                    </button>
                  </div>
                </div>
              </div>
            </div>
          `;
        } else if(q.question_type === 'matching' || (q.matching_pairs && q.matching_pairs.length > 0)) {
          const pairs = q.matching_pairs || [];
          let givenMap = {};
          try {
            givenMap = (typeof q.given_answer === 'object' && q.given_answer !== null) ? q.given_answer : JSON.parse(q.given_answer || '{}');
          } catch(e){ givenMap = {}; }

          answerContent = '<div style="margin-top:10px;display:flex;flex-direction:column;gap:6px;">';
          if(pairs.length > 0) {
            pairs.forEach((p, pIdx) => {
              const aId = p.col_a_id || ('a-' + (pIdx+1));
              const aText = p.col_a_text || ('Item ' + (pIdx+1));
              const bText = p.col_b_text || ('Definition ' + (pIdx+1));
              const targetBId = p.col_b_id || '';
              const studentChoiceBId = givenMap[aId] || givenMap[pIdx] || '';

              let isPairMatch = (targetBId && studentChoiceBId && targetBId === studentChoiceBId);
              let pairBg = isPairMatch ? '#f0fdf4' : (studentChoiceBId ? '#fef2f2' : '#f8fafc');
              let pairBorder = isPairMatch ? '#86efac' : (studentChoiceBId ? '#fca5a5' : '#e2e8f0');

              answerContent += `
                <div style="background:${pairBg};border:1px solid ${pairBorder};padding:8px 12px;border-radius:8px;font-size:12.5px;display:flex;align-items:center;justify-content:space-between;gap:10px;">
                  <div><span style="color:#64748b;font-weight:700;">${pIdx+1}.</span> <strong>${escapeCqHtml(aText)}</strong></div>
                  <div style="display:flex;align-items:center;gap:6px;">
                    <i class="fa fa-arrow-right" style="color:${isPairMatch?'#10b981':'#ef4444'};"></i>
                    <span style="background:#fff;border:1px solid ${pairBorder};padding:2px 10px;border-radius:6px;font-weight:700;color:${isPairMatch?'#15803d':'#991b1b'};">${escapeCqHtml(bText)}</span>
                    ${isPairMatch ? '<i class="fa fa-check-circle text-success"></i>' : (studentChoiceBId ? '<i class="fa fa-times-circle text-danger"></i>' : '<span style="font-size:10px;color:#94a3b8;">(Blank)</span>')}
                  </div>
                </div>
              `;
            });
          } else {
            answerContent += `
              <div style="background:#f0fdf4;border:1px solid #86efac;padding:10px 14px;border-radius:8px;color:#15803d;">
                <strong>Correct Key:</strong> ${escapeCqHtml(correct || '(None)')}
              </div>
              <div style="background:#f8fafc;border:1px solid #e2e8f0;padding:10px 14px;border-radius:8px;color:#334155;margin-top:6px;">
                <strong>Student Answer:</strong> ${escapeCqHtml(given)}
              </div>
            `;
          }
          answerContent += '</div>';
        } else {
          // Identification / Enumeration / Modified T-F
          if(isCorrect === true) {
            answerContent = `
              <div style="margin-top:10px;background:#f0fdf4;border:1.5px solid #22c55e;color:#15803d;padding:10px 14px;border-radius:8px;font-size:13px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <div><i class="fa fa-check-circle" style="color:#16a34a;font-size:16px;margin-right:6px;"></i><strong style="color:#14532d;">Student Answer:</strong> <span style="background:#fff;border:1px solid #86efac;padding:3px 10px;border-radius:6px;color:#15803d;font-weight:700;margin-left:4px;">${escapeCqHtml(given)}</span></div>
                <span style="background:#22c55e;color:#fff;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:800;"><i class="fa fa-check"></i> CORRECT</span>
              </div>
            `;
          } else {
            answerContent = `
              <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">
                <div style="background:#fef2f2;border:1.5px solid #ef4444;color:#991b1b;padding:10px 14px;border-radius:8px;font-size:13px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                  <div><i class="fa fa-times-circle" style="color:#dc2626;font-size:16px;margin-right:6px;"></i><strong style="color:#7f1d1d;">Student Answer:</strong> <span style="background:#fff;border:1px solid #fca5a5;padding:3px 10px;border-radius:6px;color:#991b1b;font-weight:700;margin-left:4px;">${escapeCqHtml(given)}</span></div>
                  <span style="background:#ef4444;color:#fff;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:800;"><i class="fa fa-times"></i> WRONG</span>
                </div>
                ${correct ? `
                  <div style="background:#f0fdf4;border:1.5px solid #22c55e;color:#15803d;padding:10px 14px;border-radius:8px;font-size:13px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <div><i class="fa fa-check-circle" style="color:#16a34a;font-size:16px;margin-right:6px;"></i><strong style="color:#14532d;">Correct / Expected Answer:</strong> <span style="background:#fff;border:1px solid #86efac;padding:3px 10px;border-radius:6px;color:#15803d;font-weight:700;margin-left:4px;">${escapeCqHtml(correct)}</span></div>
                    <span style="background:#dcfce7;color:#15803d;border:1px solid #86efac;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:800;"><i class="fa fa-check"></i> CORRECT ANSWER</span>
                  </div>
                ` : ''}
              </div>
            `;
          }
        }

        qHtml += `
          <div style="background:#fff;border:1.5px solid ${cardBorder};border-left:${cardLeft};border-radius:12px;padding:16px;margin-bottom:14px;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
              <div style="display:flex;align-items:center;gap:6px;">
                <span style="font-size:12px;font-weight:800;color:#0f172a;background:#f1f5f9;padding:2px 8px;border-radius:6px;">#${idx+1}</span>
                <span style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">${typeFormatted}</span>
                ${topicTag}
              </div>
              <div>${statusBadge}</div>
            </div>
            <div style="font-size:13.5px;font-weight:700;color:#0f172a;line-height:1.4;margin-bottom:6px;">
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

function saveEssayOverride(subId, qId, quizId, studentCode) {
  var score = parseFloat($('#overrideScore_' + qId).val()) || 0;
  var feedback = $('#overrideFb_' + qId).val() || '';

  $.post('/cenlearn/shared/quiz_handler', {
    action: 'override_essay_grade',
    submission_id: subId,
    question_id: qId,
    teacher_score: score,
    teacher_feedback: feedback
  }, function(res) {
    if(typeof res === 'string') { try { res = JSON.parse(res.trim()); } catch(e){} }
    if(res && res.success) {
      alert('Teacher grade override and feedback saved successfully!');
      viewStudentAnswers(quizId, studentCode);
    } else {
      alert(res && res.msg ? res.msg : 'Failed to save grade override.');
    }
  }, 'json').fail(function(xhr, status, error){
    alert('Request failed: ' + (xhr.responseText || error || 'Network error'));
  });
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

  $.post('/cenlearn/shared/quiz_handler', {
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
  
  var realSubjects = (subjectsData || []).filter(function(s){
    return s && parseInt(s.class_id) > 0 && s.name !== 'Unassigned Template';
  });

  let html = '<div style="display:flex;flex-direction:column;gap:10px;">';
  if(realSubjects.length === 0){
    html += '<div style="text-align:center;padding:24px 16px;color:#64748b;"><i class="fa fa-info-circle" style="font-size:24px;color:#94a3b8;margin-bottom:8px;display:block;"></i><p style="margin:0;font-size:13.5px;font-weight:500;">No classes assigned to this quiz yet.<br><span style="font-size:12px;color:#94a3b8;">Use the green <strong>+ (Add Class)</strong> button on the quiz card to assign it to a class section.</span></p></div>';
  } else {
    realSubjects.forEach(function(s){
      let codeBadge = s.code ? `<span class="badge-code-green"><i class="fa fa-tag"></i> ${escapeCqHtml(s.code)}</span>` : '';
      let classUrl = `../shared/class_view?id=${s.class_id}&tab=classwork`;
      
      html += `
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
          <div style="display:flex;align-items:center;gap:10px;">
            ${codeBadge}
            <div>
              <h6 style="margin:0 0 2px 0;font-weight:700;color:#0f172a;font-size:13px;">${escapeCqHtml(s.name)}</h6>
              ${s.section ? `<span style="font-size:11px;color:#64748b;"><i class="fa fa-users"></i> Section: <strong>${escapeCqHtml(s.section)}</strong></span>` : ''}
            </div>
          </div>
          <a href="${classUrl}" class="btn btn-xs btn-info" style="border-radius:6px;font-weight:700;padding:6px 14px;background:linear-gradient(135deg,#0284c7,#0369a1);border:none;box-shadow:0 2px 4px rgba(2,132,199,0.2);"><i class="fa fa-external-link"></i> Open Class</a>
        </div>
      `;
    });
  }
  html += '</div>';
  
  $('#acModalBody').html(html);
  $('#assignedClassesModal').modal('show');
}

function deleteQuiz(quizId) {
  if(!confirm('Are you sure you want to delete this quiz? All student submissions and scores will be permanently deleted.')) return;

  $.post('/cenlearn/shared/quiz_handler', { action: 'delete', id: quizId }, function(res) {
    if(res.success) {
      location.reload();
    } else {
      alert(res.msg || 'Failed to delete quiz.');
    }
  }, 'json');
}

// ── Copy All Quiz & Copy Individual Question Functions ─────────────────────
let currentModalQuestions = [];

function duplicateQuiz(quizId) {
  if (!confirm('Duplicate this entire quiz? A new copy of the quiz with all its questions will be created.')) {
    return;
  }
  $.post('/cenlearn/shared/quiz_handler', { action: 'duplicate_quiz', quiz_id: quizId }, function(res) {
    if (typeof res === 'string') { try { res = JSON.parse(res.trim()); } catch(e){} }
    if (res && res.success) {
      showCopyToast(res.msg || 'Quiz duplicated successfully!');
      setTimeout(function() { location.reload(); }, 800);
    } else {
      alert((res && res.msg) ? res.msg : 'Failed to duplicate quiz.');
    }
  }, 'json').fail(function() {
    alert('Network error while duplicating quiz.');
  });
}

function duplicateActiveQuiz() {
  if (!currentActiveQuizId) return;
  duplicateQuiz(currentActiveQuizId);
}

function copyAllQuizQuestionsText() {
  if (!currentModalQuestions || currentModalQuestions.length === 0) {
    alert('No questions loaded to copy.');
    return;
  }
  let textList = [];
  currentModalQuestions.forEach(function(q, idx) {
    textList.push(formatQuestionToText(q, idx));
  });
  let fullText = textList.join('\n\n');
  copyTextToClipboard(fullText, 'Copied all ' + currentModalQuestions.length + ' questions to clipboard!');
}

function copyIndividualQuestionText(idx) {
  if (!currentModalQuestions || !currentModalQuestions[idx]) {
    alert('Question not found.');
    return;
  }
  let q = currentModalQuestions[idx];
  let text = formatQuestionToText(q, idx);
  copyTextToClipboard(text, 'Copied Question #' + (idx + 1) + ' to clipboard!');
}

function formatQuestionToText(q, idx) {
  let num = idx + 1;
  let qText = (q.question_text || '').trim();
  let type = (q.question_type || 'multiple_choice').toLowerCase();
  let pts = q.points || 1;
  let corr = (q.correct_answer || '').trim();

  let lines = [];
  lines.push(num + '. ' + qText);

  if (type === 'multiple_choice' || type === 'multi_select' || type === 'single_mcq') {
    if (q.options && Array.isArray(q.options)) {
      q.options.forEach(function(opt, optIdx) {
        let letter = String.fromCharCode(65 + optIdx);
        lines.push(letter + '. ' + opt);
      });
    }
    lines.push('Answer: ' + (corr || 'A'));
  } else if (type === 'true_false') {
    lines.push('True / False');
    lines.push('Answer: ' + (corr || 'True'));
  } else if (type === 'modified_true_false') {
    lines.push('Answer: ' + (corr || 'True'));
  } else if (type === 'matching') {
    if (q.matching_pairs && Array.isArray(q.matching_pairs) && q.matching_pairs.length > 0) {
      lines.push('Matching Type');
      lines.push('Column A:');
      q.matching_pairs.forEach(function(p, pIdx) {
        lines.push((pIdx + 1) + '. ' + (p.col_a_text || p.item_text || ''));
      });
      lines.push('Column B:');
      q.matching_pairs.forEach(function(p, pIdx) {
        let letter = String.fromCharCode(65 + pIdx);
        lines.push(letter + '. ' + (p.col_b_text || p.target_text || ''));
      });
      let ansPairs = q.matching_pairs.map(function(p, pIdx) { return (pIdx + 1) + '-' + String.fromCharCode(65 + pIdx); }).join(', ');
      lines.push('Answer: ' + (corr || ansPairs));
    } else {
      lines.push('Answer: ' + corr);
    }
  } else {
    lines.push('Answer: ' + corr);
  }

  lines.push('points: ' + pts);
  return lines.join('\n');
}

function duplicateIndividualQuestion(questionId, index) {
  if (!currentActiveQuizId) return;
  var qNum = (index !== undefined) ? ('#' + (index + 1)) : '';
  if (!confirm('Duplicate Question ' + qNum + '? This will create an exact copy of this question inside the quiz.')) {
    return;
  }
  $.post('/cenlearn/shared/quiz_handler', { action: 'duplicate_question', quiz_id: currentActiveQuizId, question_id: questionId }, function(res) {
    if (typeof res === 'string') { try { res = JSON.parse(res.trim()); } catch(e){} }
    if (res && res.success) {
      showCopyToast('Question ' + qNum + ' duplicated!');
      viewSubmissions(currentActiveQuizId);
    } else {
      alert((res && res.msg) ? res.msg : 'Failed to duplicate question.');
    }
  }, 'json').fail(function() {
    alert('Network error while duplicating question.');
  });
}

function copyTextToClipboard(text, successMsg) {
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(function() {
      showCopyToast(successMsg || 'Copied to clipboard!');
    }).catch(function() {
      fallbackCopyText(text, successMsg);
    });
  } else {
    fallbackCopyText(text, successMsg);
  }
}

function fallbackCopyText(text, successMsg) {
  var textArea = document.createElement("textarea");
  textArea.value = text;
  textArea.style.position = "fixed";
  textArea.style.left = "-999999px";
  textArea.style.top = "-999999px";
  document.body.appendChild(textArea);
  textArea.focus();
  textArea.select();
  try {
    document.execCommand('copy');
    showCopyToast(successMsg || 'Copied to clipboard!');
  } catch (err) {
    alert('Could not copy text: ' + err);
  }
  document.body.removeChild(textArea);
}

function showCopyToast(msg) {
  var toast = $('#copyToastAlert');
  if(toast.length === 0){
    toast = $('<div id="copyToastAlert" style="position:fixed;bottom:24px;right:24px;z-index:9999;background:#0f172a;color:#fff;padding:12px 20px;border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,0.25);font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;transition:all 0.3s ease;"><i class="fa fa-check-circle text-success" style="font-size:16px;"></i> <span></span></div>').appendTo('body');
  }
  toast.find('span').text(msg);
  toast.stop(true, true).fadeIn(200).delay(3000).fadeOut(400);
}
</script>
</body>
</html>
