<?php
include '../includes/session.php';
include '../includes/conn.php';

if(strtoupper($user['user_group']) !== 'TEACHER'){
    header('location: /cenlearn/login'); exit;
}

$tc = $conn->real_escape_string($user['user_code']);
$initials = strtoupper(substr($user['first_name'] ?? 'T', 0, 1) . substr($user['last_name'] ?? 'U', 0, 1));

// Fetch teacher's active created classes for filter and modal dropdowns
$classesQ = $conn->query("
    SELECT id, class_name, subject, section, program_code
    FROM classes
    WHERE teacher_code = '$tc' 
      AND (is_archived = 0 OR is_archived IS NULL)
      AND (is_subject_only = 0 OR is_subject_only IS NULL)
    ORDER BY class_name ASC, section ASC
");
$classes = [];
if($classesQ){
    while($r = $classesQ->fetch_assoc()) $classes[] = $r;
}

// Filters
$classFilter  = intval($_GET['class_id'] ?? 0);
$termFilter   = trim($_GET['term'] ?? '');
$statusFilter = isset($_GET['status']) && $_GET['status'] !== '' ? intval($_GET['status']) : -1;

$whereConds = ["(a.teacher_code = '$tc' OR c.teacher_code = '$tc')", "(c.is_archived = 0 OR c.is_archived IS NULL)"];
if($classFilter > 0)  $whereConds[] = "a.class_id = $classFilter";
if(in_array($termFilter, ['midterm','final','none'])) $whereConds[] = "a.term = '$termFilter'";
if($statusFilter === 1 || $statusFilter === 0) $whereConds[] = "a.is_active = $statusFilter";

$whereSql = implode(' AND ', $whereConds);

// Single pass join query for assignments & submissions
$assignmentsQ = $conn->query("
    SELECT a.*, 
           COALESCE(c.class_name, 'Unassigned Class') AS class_name, 
           COALESCE(c.subject, 'General') AS subject, 
           COALESCE(c.section, '') AS section,
           COALESCE(c.class_code, c.subject) AS display_code,
           COALESCE(sub.submission_count, 0) AS submission_count,
           COALESCE(sub.avg_grade_pct, 0) AS avg_grade_pct
    FROM assignments a
    LEFT JOIN classes c ON a.class_id = c.id
    LEFT JOIN (
        SELECT s.assignment_id, 
               COUNT(DISTINCT s.student_code) AS submission_count, 
               AVG(s.grade / NULLIF(a2.points,0)*100) AS avg_grade_pct
        FROM assignment_submissions s
        JOIN assignments a2 ON s.assignment_id = a2.id
        WHERE s.grade IS NOT NULL
        GROUP BY s.assignment_id
    ) sub ON sub.assignment_id = a.id
    WHERE $whereSql
    ORDER BY a.id DESC
");

$rawAssignments = [];
if($assignmentsQ){
    while($row = $assignmentsQ->fetch_assoc()){
        $rawAssignments[] = $row;
    }
}

// Multi-class Grouping by lowercased assignment title
$groupedAssignments = [];
foreach($rawAssignments as $row){
    $titleKey = trim(strtolower($row['title']));
    if(!isset($groupedAssignments[$titleKey])){
        $groupedAssignments[$titleKey] = [
            'id' => $row['id'],
            'ids' => [$row['id']],
            'title' => $row['title'],
            'instructions' => $row['instructions'],
            'points' => intval($row['points']),
            'due_date' => $row['due_date'],
            'term' => $row['term'],
            'is_active' => intval($row['is_active'] ?? 1),
            'submission_count' => intval($row['submission_count']),
            'avg_grade_pct_sum' => floatval($row['avg_grade_pct']) * intval($row['submission_count']),
            'subjects' => []
        ];
    } else {
        $groupedAssignments[$titleKey]['ids'][] = $row['id'];
        $groupedAssignments[$titleKey]['submission_count'] += intval($row['submission_count']);
        $groupedAssignments[$titleKey]['avg_grade_pct_sum'] += floatval($row['avg_grade_pct']) * intval($row['submission_count']);
        if(intval($row['is_active']) == 1) $groupedAssignments[$titleKey]['is_active'] = 1;
    }

    $cid = intval($row['class_id'] ?? 0);
    if($cid > 0){
        $dispCode = trim($row['display_code'] ?? '');
        if(empty($dispCode) || $dispCode === $row['class_name']) {
            $dispCode = trim($row['subject'] ?? '');
        }
        $cName = trim($row['class_name'] ?? '');
        
        $subKey = $dispCode . '___' . $cName . '___' . $cid;
        if(!isset($groupedAssignments[$titleKey]['subjects'][$subKey])){
            $groupedAssignments[$titleKey]['subjects'][$subKey] = [
                'assignment_id' => intval($row['id']),
                'class_id' => $cid,
                'code' => $dispCode,
                'name' => $cName,
                'section' => trim($row['section'] ?? ''),
                'submissions' => intval($row['submission_count'])
            ];
        }
    }
}

$assignments = [];
$totalAssignments = count($groupedAssignments);
$activeAssignments = 0;
$totalSubmissions = 0;
$sumAvgPct = 0;
$assignmentsWithSubmissions = 0;

foreach($groupedAssignments as $ga){
    $subCount = $ga['submission_count'];
    $avgPct = $subCount > 0 ? round($ga['avg_grade_pct_sum'] / $subCount, 1) : null;
    $ga['avg_grade_pct'] = $avgPct;
    $ga['all_ids'] = implode(',', $ga['ids']);
    $assignments[] = $ga;

    if($ga['is_active'] == 1) $activeAssignments++;
    $totalSubmissions += $subCount;
    if($subCount > 0 && $avgPct !== null){
        $sumAvgPct += $avgPct;
        $assignmentsWithSubmissions++;
    }
}

$overallAvgPct = $assignmentsWithSubmissions > 0 ? round($sumAvgPct / $assignmentsWithSubmissions, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn &mdash; Assignment Dashboard</title>
  <link rel="stylesheet" href="/cenlearn/system/bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/cenlearn/system/bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/cenlearn/system/dist/css/cenlearn.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; overflow-x: hidden; }
    body { font-family: 'Inter', sans-serif; background: #f0f4f8; color: #1e293b; }

    /* ── Sidebar Styling ── */
    .td-sidebar { position: fixed; top: 0; left: 0; width: 240px; height: 100vh; background: linear-gradient(180deg, #0c1a2e 0%, #0f2d4a 55%, #0f5f80 100%); display: flex; flex-direction: column; z-index: 200; transition: transform .25s cubic-bezier(.4,0,.2,1); transform: translateX(-240px); }
    .td-sidebar.open { transform: translateX(0); }
    @media(min-width: 901px) { .td-sidebar { transform: translateX(0); } }
    .sb-brand { padding: 18px 18px 14px; border-bottom: 1px solid rgba(255,255,255,.08); }
    .sb-logo { width: 34px; height: 34px; border-radius: 8px; background: linear-gradient(135deg, #10b981, #059669); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 6px; box-shadow: 0 3px 10px rgba(16,185,129,.35); }
    .sb-logo i { color: #fff; font-size: 15px; }
    .sb-brand h2 { color: #fff; font-size: 16px; font-weight: 800; margin: 0; }
    .sb-brand h2 span { color: #34d399; }
    .sb-brand p { color: rgba(255,255,255,.35); font-size: 9.5px; margin: 2px 0 0; }
    .sb-nav { flex: 1; padding: 10px 0; overflow-y: auto; }
    .sb-section { padding: 8px 18px 4px; font-size: 9px; font-weight: 700; color: rgba(255,255,255,.25); letter-spacing: 1.4px; text-transform: uppercase; }
    .sb-nav ul { list-style: none; margin: 0; padding: 0; }
    .sb-nav li a { display: flex; align-items: center; gap: 10px; padding: 9px 18px; color: rgba(255,255,255,.6); text-decoration: none; font-size: 12.5px; font-weight: 500; transition: all .18s; border-left: 3px solid transparent; }
    .sb-nav li a:hover, .sb-nav li.active a { color: #fff; background: rgba(255,255,255,.07); border-left-color: #34d399; }
    .sb-footer { padding: 12px 18px; border-top: 1px solid rgba(255,255,255,.08); background: rgba(0,0,0,.15); }
    .sb-user { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
    .sb-av { width: 32px; height: 32px; border-radius: 50%; background: #34d399; color: #0c1a2e; font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: 12px; }
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

    .btn-create-quiz { background: linear-gradient(135deg, #10b981, #059669); color: #fff; border: none; padding: 7px 15px; border-radius: 8px; font-weight: 700; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all .18s cubic-bezier(.4,0,.2,1); box-shadow: 0 3px 10px rgba(16,185,129,.28); min-height: 34px; }
    .btn-create-quiz:hover { opacity: 0.92; transform: translateY(-1px); color: #fff; text-decoration: none; }

    /* ── Stat Cards Grid ── */
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

    /* ── Filters & Search Controls ── */
    .qz-controls { background: #fff; border-radius: 12px; padding: 10px 14px; border: 1px solid #e2e8f0; margin-bottom: 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.02); }
    .qz-filter-group { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .qz-select { padding: 6px 10px; border: 1.5px solid #cbd5e1; border-radius: 7px; font-size: 11.5px; font-family: 'Inter', sans-serif; background: #f8fafc; color: #334155; outline: none; transition: border-color .18s; }
    .qz-select:focus { border-color: #10b981; background: #fff; }
    .qz-search-box { position: relative; min-width: 200px; }
    .qz-search-box i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 12px; }
    .qz-search-input { width: 100%; padding: 6px 10px 6px 30px; border: 1.5px solid #cbd5e1; border-radius: 7px; font-size: 11.5px; font-family: 'Inter', sans-serif; background: #f8fafc; outline: none; transition: border-color .18s; }
    .qz-search-input:focus { border-color: #10b981; background: #fff; }

    /* ── Horizontal Row Cards ── */
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
      background: linear-gradient(135deg, #10b981, #059669);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ffffff;
      font-size: 14px;
      flex-shrink: 0;
      box-shadow: 0 3px 8px rgba(16, 185, 129, 0.22);
    }
    .qrc-info {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
      min-width: 0;
    }
    .qrc-title {
      font-size: 13.5px;
      font-weight: 700;
      color: #0f172a;
      margin: 0;
      letter-spacing: -0.1px;
    }
    .qz-class-badge {
      font-size: 10px;
      font-weight: 700;
      color: #059669;
      background: #d1fae5;
      padding: 2px 6px;
      border-radius: 5px;
      border: 1px solid #a7f3d0;
      display: inline-flex;
      align-items: center;
      gap: 3px;
    }
    .qz-term-badge { font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; padding: 2px 6px; border-radius: 5px; }
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
      gap: 3px;
      padding: 2px 7px;
      border-radius: 5px;
      font-size: 10.5px;
      font-weight: 500;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      color: #475569;
      transition: all 0.15s;
    }
    .qrc-right {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-shrink: 0;
    }

    /* Active / Draft Status Switch */
    .qz-status-toggle { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 600; cursor: pointer; user-select: none; }
    .qz-switch { position: relative; display: inline-block; width: 32px; height: 18px; }
    .qz-switch input { opacity: 0; width: 0; height: 0; }
    .qz-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .25s; border-radius: 20px; }
    .qz-slider:before { position: absolute; content: ""; height: 12px; width: 12px; left: 3px; bottom: 3px; background-color: white; transition: .25s; border-radius: 50%; }
    input:checked + .qz-slider { background-color: #10b981; }
    input:checked + .qz-slider:before { transform: translateX(14px); }

    .qz-actions { display: flex; align-items: center; gap: 5px; }
    .qz-act-btn { width: 30px; height: 30px; border-radius: 6px; border: 1.5px solid #cbd5e1; background: #fff; color: #475569; display: inline-flex; align-items: center; justify-content: center; font-size: 11.5px; cursor: pointer; transition: all .15s; text-decoration: none; min-height: 30px; }
    .qz-act-btn:hover { background: #10b981; color: #fff; border-color: #10b981; }
    .qz-act-btn.btn-danger:hover { background: #ef4444; color: #fff; border-color: #ef4444; }

    /* Mobile Responsive Overrides */
    @media(max-width: 768px) {
      .td-topbar { padding: 8px 12px !important; }
      .td-topbar-title p { display: none !important; }
      .qz-stats-grid { grid-template-columns: repeat(2, 1fr) !important; gap: 8px !important; }
      .qz-stat-card { padding: 10px 12px !important; gap: 10px !important; }
      .stat-icon { width: 34px !important; height: 34px !important; font-size: 14px !important; }
      .stat-meta strong { font-size: 16px !important; }
      .qz-controls { flex-direction: column !important; align-items: stretch !important; gap: 8px !important; padding: 10px 12px !important; }
      .qz-filter-group { width: 100% !important; flex-wrap: wrap !important; }
      .qz-select { flex: 1 !important; min-width: 110px !important; }
      .qz-search-box { width: 100% !important; }
      .quiz-row-card { flex-direction: column !important; align-items: stretch !important; gap: 10px !important; padding: 10px 12px !important; }
      .qrc-left { width: 100% !important; }
      .qrc-right { width: 100% !important; justify-content: space-between !important; border-top: 1px dashed #f1f5f9 !important; padding-top: 8px !important; margin-top: 2px !important; }
    }

    /* Empty state */
    .qz-empty { text-align: center; padding: 48px 16px; background: #fff; border-radius: 14px; border: 1.5px dashed #cbd5e1; grid-column: 1 / -1; }
    .qz-empty i { font-size: 40px; color: #cbd5e1; margin-bottom: 12px; }
    .qz-empty h4 { font-size: 15px; font-weight: 700; color: #334155; margin: 0 0 4px; }
    .qz-empty p { font-size: 12.5px; color: #64748b; margin: 0 0 14px; }

    /* Custom Form Controls */
    .form-control-custom { width: 100%; padding: 8px 11px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 12.5px; font-family: 'Inter', sans-serif; background: #fff; color: #1e293b; outline: none; transition: border-color .15s; }
    .form-control-custom:focus { border-color: #10b981; }

    /* ── Create New Assignment Resizable & Responsive Modal Styles ── */
    #createAssignmentModal .modal-dialog { max-width: 820px; width: 92%; margin: 20px auto; transition: all .25s cubic-bezier(.4,0,.2,1); }
    #createAssignmentModal .modal-dialog.ca-modal-fullscreen { max-width: 100vw; width: 100vw; height: 100vh; margin: 0; padding: 0; }

    .ca-modal-content { border-radius: 16px; border: none; background: #ffffff; box-shadow: 0 20px 40px -12px rgba(0,0,0,0.2); overflow: hidden; display: flex; flex-direction: column; max-height: 90vh; }
    #createAssignmentModal .modal-dialog.ca-modal-fullscreen .ca-modal-content { height: 100vh; max-height: 100vh; border-radius: 0; }

    .ca-modal-header { padding: 14px 20px; background: linear-gradient(135deg, #10b981, #059669); color: #ffffff; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .ca-header-title { display: flex; align-items: center; gap: 8px; }
    .ca-title-icon { width: 32px; height: 32px; border-radius: 8px; background: rgba(255,255,255,0.2); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; }
    .ca-header-title h3 { font-size: 15.5px; font-weight: 800; color: #ffffff; margin: 0; }

    .ca-fs-toggle-btn { background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.3); font-size: 11.5px; color: #ffffff; cursor: pointer; padding: 4px 10px; border-radius: 6px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; transition: all .15s; }
    .ca-fs-toggle-btn:hover { background: rgba(255,255,255,0.3); color: #ffffff; }
    .ca-close-btn { background: none; border: none; font-size: 22px; color: rgba(255,255,255,0.8); cursor: pointer; padding: 0 4px; line-height: 1; transition: color .15s; }
    .ca-close-btn:hover { color: #ffffff; }

    .ca-modal-body { padding: 18px 20px; background: #f8fafc; flex: 1; overflow-y: auto; }
    .ca-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }

    .ca-instructions-area { min-height: 110px; resize: vertical; font-family: 'Inter', sans-serif; line-height: 1.5; font-size: 12.5px; }

    .ca-modal-footer { padding: 12px 20px; background: #ffffff; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }

    footer.td-footer { text-align: center; padding: 14px; font-size: 11px; color: #94a3b8; border-top: 1px solid #e2e8f0; background: #fff; margin-top: 30px; }
  </style>
</head>
<body>

<!-- Sidebar -->
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
      <li><a href="quizzes"><i class="fa fa-question-circle"></i> Quizzes</a></li>
      <li class="active"><a href="assignments"><i class="fa fa-tasks"></i> Assignments</a></li>
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

<!-- Main Container -->
<div class="td-main">
  <header class="td-topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="toggleSidebar()"><i class="fa fa-bars"></i></button>
      <div class="td-topbar-title">
        <h3>Assignment Management Dashboard</h3>
        <p><?php echo date('l, F j, Y'); ?></p>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <button class="btn-create-quiz" onclick="openCreateAssignmentModal()"><i class="fa fa-plus-circle"></i> Create New Assignment</button>
    </div>
  </header>

  <div class="td-content">

    <!-- Overview Stat Cards -->
    <div class="qz-stats-grid">
      <div class="qz-stat-card">
        <div class="stat-icon stat-purple"><i class="fa fa-tasks"></i></div>
        <div class="stat-meta">
          <strong><?php echo $totalAssignments; ?></strong>
          <span>Total Assignments</span>
        </div>
      </div>
      <div class="qz-stat-card">
        <div class="stat-icon stat-green"><i class="fa fa-check-circle"></i></div>
        <div class="stat-meta">
          <strong><?php echo $activeAssignments; ?></strong>
          <span>Active Assignments</span>
        </div>
      </div>
      <div class="qz-stat-card">
        <div class="stat-icon stat-blue"><i class="fa fa-paper-plane-o"></i></div>
        <div class="stat-meta">
          <strong><?php echo $totalSubmissions; ?></strong>
          <span>Submissions Received</span>
        </div>
      </div>
      <div class="qz-stat-card">
        <div class="stat-icon stat-amber"><i class="fa fa-pie-chart"></i></div>
        <div class="stat-meta">
          <strong><?php echo $overallAvgPct; ?>%</strong>
          <span>Class Avg Grade</span>
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
        <input type="text" class="qz-search-input" id="searchAssignment" placeholder="Search assignment title..." onkeyup="filterBySearch()">
      </div>
    </div>

    <!-- Assignments Horizontal Row List -->
    <div class="qz-list" id="assignmentsGrid">
      <?php if(empty($assignments)): ?>
        <div class="qz-empty">
          <i class="fa fa-folder-open-o"></i>
          <h4>No Assignments Found</h4>
          <p>You haven't created any assignments for the selected filters yet.</p>
          <button class="btn-create-quiz" onclick="openCreateAssignmentModal()"><i class="fa fa-plus-circle"></i> Create New Assignment</button>
        </div>
      <?php else: ?>
        <?php foreach($assignments as $a):
          $avgPct = $a['avg_grade_pct'] !== null ? round(floatval($a['avg_grade_pct']), 1).'%' : 'N/A';
          $termClass = 'qz-term-'.$a['term'];
          $allIds = $a['all_ids'];
          $dueDateFormatted = !empty($a['due_date']) ? date('M d, Y g:i A', strtotime($a['due_date'])) : 'No due date';
        ?>
          <div class="quiz-row-card" data-title="<?php echo htmlspecialchars(strtolower($a['title'])); ?>">
            <div class="qrc-left">
              <div class="qrc-icon">
                <i class="fa fa-tasks"></i>
              </div>
              <div class="qrc-info">
                <h5 class="qrc-title"><?php echo htmlspecialchars($a['title']); ?></h5>
                <?php 
                  $classCount = count($a['subjects']);
                  $subjectsJson = htmlspecialchars(json_encode(array_values($a['subjects'])), ENT_QUOTES, 'UTF-8');
                  if($classCount > 1):
                ?>
                  <span class="qz-class-badge" style="cursor:pointer;" onclick="viewAssignedClasses('<?php echo addslashes($a['title']); ?>', <?php echo $subjectsJson; ?>)" title="Click to view all assigned classes">
                    <i class="fa fa-users"></i> <strong><?php echo $classCount; ?></strong> Classes Assigned <i class="fa fa-chevron-down" style="font-size:9px;margin-left:3px;"></i>
                  </span>
                <?php elseif($classCount === 1): 
                  $firstSub = reset($a['subjects']);
                  $sCode = trim($firstSub['code'] ?? '');
                  $sName = trim($firstSub['name'] ?? '');
                  $sLabel = (!empty($sCode) && strtolower($sCode) !== 'general' && strtolower($sCode) !== strtolower($sName))
                    ? '<strong>' . htmlspecialchars($sCode) . '</strong>: ' . htmlspecialchars($sName)
                    : htmlspecialchars($sName);
                ?>
                  <span class="qz-class-badge" style="cursor:pointer;" onclick="viewAssignedClasses('<?php echo addslashes($a['title']); ?>', <?php echo $subjectsJson; ?>)" title="Click to view class details">
                    <i class="fa fa-graduation-cap"></i> <?php echo $sLabel; ?>
                  </span>
                <?php else: ?>
                  <span class="qz-class-badge" style="background:#f1f5f9;color:#64748b;border-color:#e2e8f0;">
                    <i class="fa fa-file-text-o"></i> Unassigned Template
                  </span>
                <?php endif; ?>
                <span class="qz-term-badge <?php echo $termClass; ?>"><?php echo strtoupper($a['term']); ?></span>
                
                <div class="qrc-meta">
                  <span class="qz-pill" title="Max Points"><i class="fa fa-trophy" style="color:#f59e0b;"></i> <strong><?php echo $a['points']; ?></strong> Pts</span>
                  <span class="qz-pill" title="Due Date"><i class="fa fa-calendar-o" style="color:#0284c7;"></i> Due: <strong><?php echo $dueDateFormatted; ?></strong></span>
                  <span class="qz-pill" title="Submissions"><i class="fa fa-paper-plane-o" style="color:#10b981;"></i> <strong><?php echo $a['submission_count']; ?></strong> Submissions</span>
                  <span class="qz-pill" title="Average Grade"><i class="fa fa-bar-chart" style="color:#8b5cf6;"></i> Avg: <strong><?php echo $avgPct; ?></strong></span>
                </div>
              </div>
            </div>

            <div class="qrc-right">
              <label class="qz-status-toggle" title="Toggle Publish Status" style="margin:0;">
                <span class="qz-switch">
                  <input type="checkbox" <?php echo $a['is_active'] == 1 ? 'checked' : ''; ?> onchange="toggleActive('<?php echo $allIds; ?>', this.checked)">
                  <span class="qz-slider"></span>
                </span>
                <span style="font-size:11px;color:<?php echo $a['is_active']==1?'#10b981':'#64748b'; ?>;"><?php echo $a['is_active']==1?'Active':'Draft'; ?></span>
              </label>

              <div class="qz-actions">
                <button class="qz-act-btn" title="AI Relevance & Quality Analytics" onclick="analyzeAssignmentRelevance(<?php echo $a['id']; ?>)">
                  <i class="fa fa-magic" style="color:#0284c7;"></i>
                </button>
                <button class="qz-act-btn" title="View Submissions & Grade" onclick="viewSubmissions('<?php echo $allIds; ?>', '<?php echo addslashes($a['title']); ?>')">
                  <i class="fa fa-eye" style="color:#10b981;"></i>
                </button>
                <button class="qz-act-btn" title="Add Class / Assign to Class" onclick="openCopyModal(<?php echo $a['id']; ?>, '<?php echo addslashes($a['title']); ?>')">
                  <i class="fa fa-plus-circle" style="color:#059669;"></i>
                </button>
                <button class="qz-act-btn btn-danger" title="Delete Assignment" onclick="deleteAssignment('<?php echo $allIds; ?>')">
                  <i class="fa fa-trash-o"></i>
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>
  <footer class="td-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<!-- ── Resizable & Responsive Create Assignment Modal ── -->
<div class="modal fade" id="createAssignmentModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="ca-modal-content">
      <div class="ca-modal-header">
        <div class="ca-header-title">
          <div class="ca-title-icon"><i class="fa fa-tasks"></i></div>
          <h3>Create New Assignment</h3>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <button type="button" class="ca-fs-toggle-btn" onclick="toggleCaFullscreenModal()" title="Toggle Resize Fullscreen / Windowed Mode">
            <i class="fa fa-expand" id="caFsIcon"></i><span id="caFsText"> Fullscreen</span>
          </button>
          <button type="button" class="ca-close-btn" data-dismiss="modal">&times;</button>
        </div>
      </div>

      <div class="ca-modal-body">
        <form id="createAssignmentForm" onsubmit="event.preventDefault(); submitCreateAssignment();">
          
          <!-- Unified Multi-Course & Multi-Class Target Selector -->
          <div class="row">
            <div class="col-md-5 form-group mb-3">
              <label style="font-weight:700; font-size:12px; color:#1e293b;"><i class="fa fa-filter" style="color:#0284c7;"></i> Course / Program Filter</label>
              <select class="form-control-custom" id="asProgramCode" onchange="filterAssignmentClassesByProgram()">
                <option value="">— All Courses / Programs —</option>
                <option value="IS">IS</option>
                <option value="CRIM">CRIM</option>
                <option value="ARTS">ARTS (BSOA, AB)</option>
                <option value="EDUCATION">EDUCATION (BEED, BSED, BPED)</option>
              </select>
            </div>
            
            <div class="col-md-7 form-group mb-3">
              <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
                <label style="font-weight:700; font-size:12px; color:#1e293b; margin:0;"><i class="fa fa-graduation-cap" style="color:#10b981;"></i> Target Class & Section(s) <span class="text-danger">*</span></label>
                <div style="font-size:11px;">
                  <a href="javascript:void(0)" onclick="selectAllTargetClasses(true)" style="color:#10b981; font-weight:700; text-decoration:none; margin-right:8px;"><i class="fa fa-check-square-o"></i> Select All</a>
                  <a href="javascript:void(0)" onclick="selectAllTargetClasses(false)" style="color:#64748b; font-weight:600; text-decoration:none;"><i class="fa fa-square-o"></i> Clear</a>
                </div>
              </div>
              <select class="form-control-custom" id="asClassId" multiple style="height:115px; padding:6px; border-radius:10px;" required>
                <?php foreach($classes as $c): ?>
                  <option value="<?php echo $c['id']; ?>" data-prog="<?php echo htmlspecialchars($c['program_code'] ?? ''); ?>">
                    <?php echo htmlspecialchars($c['class_name'].' ('.$c['subject'].') - Sec '.$c['section']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <small style="color:#64748b; font-size:11px; margin-top:4px; display:block;"><i class="fa fa-info-circle"></i> Hold <kbd>Ctrl</kbd> (or <kbd>Cmd</kbd> on Mac) to select multiple courses and classes simultaneously.</small>
            </div>
          </div>

          <!-- Assignment Title -->
          <div class="form-group mb-3">
            <label style="font-weight:700; font-size:12px; color:#1e293b;">Assignment Title <span class="text-danger">*</span></label>
            <input type="text" class="form-control-custom" id="asTitle" placeholder="e.g. Midterm Case Study, Research Proposal, Lab Experiment 1" required style="font-size:14px; font-weight:600;">
          </div>

          <!-- Instructions & Guidelines -->
          <div class="form-group mb-3">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
              <label style="font-weight:700; font-size:12px; color:#1e293b; margin:0;"><i class="fa fa-keyboard-o" style="color:#0284c7;"></i> Instructions & Submission Guidelines</label>
              <button type="button" class="btn btn-xs btn-outline-info" onclick="aiEnhanceInstructions()" style="font-size:11px; font-weight:600; border-radius:6px;">
                <i class="fa fa-magic"></i> AI Format Assistant
              </button>
            </div>
            <textarea class="form-control-custom ca-instructions-area" id="asInstructions" rows="5" placeholder="Enter assignment guidelines, required format (PDF/DOCX), scoring criteria, or submission rules..." oninput="updateInstructionStats()"></textarea>
            <div style="display:flex; justify-content:space-between; font-size:11px; color:#64748b; margin-top:4px;">
              <span id="caInstructionStats">Words: 0 | Characters: 0</span>
              <span>Supported formats: PDF, DOCX, ZIP, PPT, TXT, Images</span>
            </div>
          </div>

          <!-- 3 Columns Config Grid -->
          <div class="ca-form-grid">
            <div class="form-group mb-3">
              <label style="font-weight:700; font-size:12px; color:#1e293b;">Max Points <span class="text-danger">*</span></label>
              <input type="number" class="form-control-custom" id="asPoints" value="100" min="1" required style="font-weight:700; color:#0f172a;">
            </div>

            <div class="form-group mb-3">
              <label style="font-weight:700; font-size:12px; color:#1e293b;">Grading Term <span class="text-danger">*</span></label>
              <select class="form-control-custom" id="asTerm" required>
                <option value="midterm">Midterm Term</option>
                <option value="final">Final Term</option>
                <option value="none">Practice (No Gradebook)</option>
              </select>
            </div>

            <div class="form-group mb-3">
              <label style="font-weight:700; font-size:12px; color:#1e293b;">Due Date & Time</label>
              <input type="datetime-local" class="form-control-custom" id="asDueDate">
            </div>
          </div>

          <!-- Initial Active Switch -->
          <div style="background:#ffffff; border:1.5px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-top:6px; display:flex; align-items:center; justify-content:space-between;">
            <div>
              <strong style="font-size:13px; color:#0f172a; display:block;">Initial Status</strong>
              <span style="font-size:11px; color:#64748b;">Publish immediately or save as a draft for later.</span>
            </div>
            <label class="qz-status-toggle" style="margin:0;">
              <span class="qz-switch">
                <input type="checkbox" id="asIsActive" checked>
                <span class="qz-slider"></span>
              </span>
              <span style="font-size:12px; font-weight:700; color:#10b981;">Active</span>
            </label>
          </div>

        </form>
      </div>

      <div class="ca-modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal" style="border-radius:9px; font-weight:600;">Cancel</button>
        <button type="button" class="btn btn-primary" id="btnSaveAssignment" onclick="submitCreateAssignment()" style="background:linear-gradient(135deg,#10b981,#059669); border:none; padding:9px 24px; font-weight:700; border-radius:9px; box-shadow:0 4px 14px rgba(16,185,129,0.35);"><i class="fa fa-save"></i> Save Assignment</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Assigned Classes Dashboard Modal ── -->
<div class="modal fade" id="assignedClassesModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document" style="max-width:560px;">
    <div class="modal-content" style="border-radius:14px;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,0.15);">
      <div class="modal-header" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;padding:16px 20px;">
        <h5 class="modal-title" id="acModalTitle" style="font-weight:700;display:flex;align-items:center;gap:8px;margin:0;font-size:15px;color:#fff;">
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

<!-- ── Copy / Assign to Class Modal ── -->
<div class="modal fade" id="copyAssignmentModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document" style="max-width:480px;">
    <div class="modal-content" style="border-radius:14px;overflow:hidden;">
      <div class="modal-header" style="background:linear-gradient(135deg,#0284c7,#0369a1);color:#fff;padding:16px 20px;">
        <h5 class="modal-title" style="font-weight:700;display:flex;align-items:center;gap:8px;margin:0;font-size:15px;color:#fff;">
          <i class="fa fa-plus-circle"></i> Assign to Additional Class
        </h5>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;">&times;</button>
      </div>
      <div class="modal-body" style="padding:20px;background:#fff;">
        <p style="font-size:13px;color:#64748b;margin-bottom:14px;">Assign <strong><span id="copyAssignmentTitle"></span></strong> to another class section:</p>
        <input type="hidden" id="copySourceAssignmentId">
        <div class="form-group">
          <label style="font-weight:600;font-size:12px;">Select Target Class Section <span class="text-danger">*</span></label>
          <select class="form-control-custom" id="copyTargetClassId">
            <option value="">— Select Target Class —</option>
            <?php foreach($classes as $c): ?>
              <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['class_name'].' ('.$c['subject'].') - Sec '.$c['section']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer" style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 20px;">
        <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="btnSubmitCopy" onclick="submitCopyAssignment()" style="background:linear-gradient(135deg,#0284c7,#0369a1);border:none;font-weight:600;"><i class="fa fa-check"></i> Assign to Class</button>
      </div>
    </div>
  </div>
</div>

<!-- ── View Submissions & Grade Modal ── -->
<div class="modal fade" id="submissionsModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content" style="border-radius:16px; border:none; overflow:hidden;">
      <div class="modal-header" style="background:#0f172a; color:#fff; padding:18px 22px;">
        <h5 class="modal-title" style="font-weight:700; color:#fff; display:flex; align-items:center; gap:8px;"><i class="fa fa-paper-plane"></i> Submissions & Grading: <span id="subModalTitle"></span></h5>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff; opacity:.8;">&times;</button>
      </div>
      <div class="modal-body" style="padding:20px 24px; max-height:70vh; overflow-y:auto;">
        <div id="submissionsLoading" class="text-center py-4" style="display:none;">
          <i class="fa fa-spinner fa-spin fa-2x text-success"></i>
          <p class="mt-2 text-muted">Loading student submissions...</p>
        </div>
        <div id="submissionsContainer"></div>
      </div>
      <div class="modal-footer" style="background:#f8fafc; border-top:1px solid #e2e8f0;">
        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ── AI Assignment Relevance & Quality Analytics Modal ── -->
<div class="modal fade" id="relevanceModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document" style="max-width:620px;">
    <div class="modal-content" style="border-radius:16px; overflow:hidden; border:none;">
      <div class="modal-header" style="background:linear-gradient(135deg, #0c1a2e, #0f4c75); color:#fff; padding:18px 22px;">
        <h5 class="modal-title" style="font-weight:700; color:#fff; display:flex; align-items:center; gap:8px;">
          <i class="fa fa-magic" style="color:#38bdf8;"></i> AI Assignment Relevance & Quality Analytics
        </h5>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff; opacity:.8;">&times;</button>
      </div>
      <div class="modal-body" id="relevanceModalBody" style="padding:24px; background:#f8fafc;">
        <div class="text-center py-4">
          <i class="fa fa-spinner fa-spin fa-2x text-info"></i>
          <p class="mt-2 text-muted">Evaluating assignment prompt quality & clarity...</p>
        </div>
      </div>
      <div class="modal-footer" style="background:#fff; border-top:1px solid #e2e8f0; padding:12px 20px;">
        <button type="button" class="btn btn-default" data-dismiss="modal" style="border-radius:8px; font-weight:600;">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="/cenlearn/system/bower_components/jquery/dist/jquery.min.js"></script>
<script src="/cenlearn/system/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
}

function toggleCaFullscreenModal() {
  var dialog = $('#createAssignmentModal .modal-dialog');
  dialog.toggleClass('ca-modal-fullscreen');
  var isFs = dialog.hasClass('ca-modal-fullscreen');
  $('#caFsIcon').attr('class', isFs ? 'fa fa-compress' : 'fa fa-expand');
  $('#caFsText').text(isFs ? ' Windowed' : ' Fullscreen');
}

function updateInstructionStats() {
  var text = $('#asInstructions').val() || '';
  var chars = text.length;
  var words = text.trim() ? text.trim().split(/\s+/).length : 0;
  $('#caInstructionStats').text('Words: ' + words + ' | Characters: ' + chars);
}

function aiEnhanceInstructions() {
  var title = $('#asTitle').val().trim();
  var inst = $('#asInstructions').val().trim();

  if(!title) { alert('Please enter an assignment title first.'); return; }

  var enhanced = inst;
  if(!inst) {
    enhanced = "OBJECTIVE:\nWrite and submit a comprehensive report/deliverable for \"" + title + "\".\n\nGUIDELINES & FORMATTING:\n1. Include cover page with Student Name, Course, Section, and Submission Date.\n2. Font: Arial / Calibri 11pt, 1.5 line spacing.\n3. Accepted File Formats: PDF or Word document (.docx).\n\nCRITERIA FOR EVALUATION:\n- Content Depth & Accuracy (40%)\n- Organization & Clarity (30%)\n- Formatting & Completeness (30%)";
  } else {
    enhanced += "\n\nCRITERIA FOR EVALUATION:\n- Clarity & Depth of Analysis (40%)\n- Relevance to Course Module (30%)\n- Organization & Formatting (30%)";
  }
  
  $('#asInstructions').val(enhanced);
  updateInstructionStats();
}

function filterAssignmentClassesByProgram() {
  var prog = $('#asProgramCode').val();
  $('#asClassId option').each(function(){
    var optProg = $(this).data('prog');
    if(!prog || !optProg || optProg === prog) {
      $(this).show();
    } else {
      $(this).hide();
      $(this).prop('selected', false);
    }
  });
}

function selectAllTargetClasses(selectState) {
  $('#asClassId option:visible').prop('selected', selectState);
}

function openCreateAssignmentModal() {
  $('#createAssignmentForm')[0].reset();
  filterAssignmentClassesByProgram();
  updateInstructionStats();
  $('#createAssignmentModal').modal('show');
}

function submitCreateAssignment() {
  var title = $('#asTitle').val().trim();
  var instructions = $('#asInstructions').val().trim();
  var points = $('#asPoints').val();
  var term = $('#asTerm').val();
  var due_date = $('#asDueDate').val();
  var is_active = $('#asIsActive').is(':checked') ? 1 : 0;

  var selectedClasses = $('#asClassId').val() || [];

  if(!selectedClasses || selectedClasses.length === 0) { alert('Please select at least one target class section.'); return; }
  if(!title) { alert('Please enter an assignment title.'); return; }

  $('#btnSaveAssignment').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');

  $.post('/cenlearn/shared/assignment_handler', {
    action: 'create',
    class_ids: selectedClasses,
    title: title,
    instructions: instructions,
    points: points,
    term: term,
    due_date: due_date,
    is_active: is_active
  }, function(res){
    $('#btnSaveAssignment').prop('disabled', false).html('<i class="fa fa-save"></i> Save Assignment');
    if(res.success){
      alert('Assignment created successfully!');
      location.reload();
    } else {
      alert(res.msg || 'Error creating assignment.');
    }
  }, 'json').fail(function(xhr){
    $('#btnSaveAssignment').prop('disabled', false).html('<i class="fa fa-save"></i> Save Assignment');
    var msg = 'Failed to create assignment.';
    try {
      var json = JSON.parse(xhr.responseText);
      if(json && json.msg) msg = json.msg;
    } catch(e) {}
    alert(msg);
  });
}

function toggleActive(ids, isChecked) {
  var activeVal = isChecked ? 1 : 0;
  $.post('/cenlearn/shared/assignment_handler', {
    action: 'toggle_active',
    ids: ids,
    is_active: activeVal
  }, function(res){
    if(!res.success){
      alert(res.msg || 'Failed to update publish status.');
      location.reload();
    }
  }, 'json');
}

function viewAssignedClasses(title, subjects) {
  $('#acModalTitle').html('<i class="fa fa-users"></i> Assigned Classes: ' + escapeHtml(title));
  var realSubjects = (subjects || []).filter(function(s){
    return s && parseInt(s.class_id) > 0 && s.name !== 'Unassigned Class';
  });
  var html = '<div class="list-group" style="margin:0;">';
  if(realSubjects.length === 0) {
    html += '<div style="text-align:center;padding:24px 16px;color:#64748b;"><i class="fa fa-info-circle" style="font-size:24px;color:#94a3b8;margin-bottom:8px;display:block;"></i><p style="margin:0;font-size:13.5px;font-weight:500;">No classes assigned to this assignment yet.<br><span style="font-size:12px;color:#94a3b8;">Use the green <strong>+ (Add Class)</strong> button on the card to assign it to a class section.</span></p></div>';
  } else {
    realSubjects.forEach(function(s){
      var classUrl = `../shared/class_view?id=${s.class_id}&tab=classwork`;
      html += '<div class="list-group-item" style="border-radius:10px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;padding:12px 16px;">';
      html += '<div>';
      html += '<h6 style="margin:0 0 3px 0;font-weight:700;color:#0f172a;font-size:13px;">' + escapeHtml(s.name) + '</h6>';
      html += '<span style="font-size:11px;color:#64748b;"><span class="badge-code-green" style="margin-right:6px;"><i class="fa fa-tag"></i> ' + escapeHtml(s.code) + '</span> ' + (s.section ? 'Section: <strong>' + escapeHtml(s.section) + '</strong>' : '') + '</span>';
      html += '</div>';
      html += '<div style="display:flex;align-items:center;gap:10px;">';
      html += '<span class="badge badge-success" style="font-size:11px;background:#10b981;padding:6px 10px;">' + (s.submissions || 0) + ' Submissions</span>';
      html += '<a href="' + classUrl + '" class="btn btn-xs btn-info" style="border-radius:6px;font-weight:700;padding:5px 12px;background:linear-gradient(135deg,#0284c7,#0369a1);border:none;"><i class="fa fa-external-link"></i> Open</a>';
      html += '</div>';
      html += '</div>';
    });
  }
  html += '</div>';
  $('#acModalBody').html(html);
  $('#assignedClassesModal').modal('show');
}

function openCopyModal(assignmentId, title) {
  $('#copySourceAssignmentId').val(assignmentId);
  $('#copyAssignmentTitle').text(title);
  $('#copyTargetClassId').val('');
  $('#copyAssignmentModal').modal('show');
}

function submitCopyAssignment() {
  var assignmentId = $('#copySourceAssignmentId').val();
  var targetClassId = $('#copyTargetClassId').val();

  if(!targetClassId) { alert('Please select a target class section.'); return; }

  $('#btnSubmitCopy').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Assigning...');

  $.post('/cenlearn/shared/assignment_handler', {
    action: 'assign_to_class',
    assignment_id: assignmentId,
    target_class_id: targetClassId
  }, function(res){
    $('#btnSubmitCopy').prop('disabled', false).html('<i class="fa fa-check"></i> Assign to Class');
    if(res.success){
      alert('Assignment assigned to target class successfully!');
      location.reload();
    } else {
      alert(res.msg || 'Failed to assign assignment.');
    }
  }, 'json');
}

function deleteAssignment(ids) {
  if(!confirm('Are you sure you want to delete this assignment and all associated student submissions?')) return;
  $.post('/cenlearn/shared/assignment_handler', { action: 'delete', ids: ids }, function(res){
    if(res.success){
      location.reload();
    } else {
      alert(res.msg || 'Error deleting assignment.');
    }
  }, 'json');
}

function viewSubmissions(assignmentIds, title) {
  $('#subModalTitle').text(title || 'Student Submissions');
  $('#submissionsContainer').empty();
  $('#submissionsLoading').show();
  $('#submissionsModal').modal('show');

  var idList = assignmentIds.toString().split(',');
  var firstId = idList[0];

  $.get('/cenlearn/shared/assignment_handler', { action: 'get_submissions', assignment_id: firstId }, function(res){
    $('#submissionsLoading').hide();
    if(res.success) {
      renderSubmissionsList(res.submissions);
    } else {
      $('#submissionsContainer').html('<div class="alert alert-danger">'+(res.msg||'Failed to load submissions.')+'</div>');
    }
  }, 'json');
}

function renderSubmissionsList(subs) {
  var container = $('#submissionsContainer');
  container.empty();

  if(!subs || subs.length === 0) {
    container.html('<div class="text-center py-4 text-muted"><i class="fa fa-folder-open-o fa-2x mb-2"></i><p>No student submissions received yet.</p></div>');
    return;
  }

  var html = '<div class="table-responsive"><table class="table table-hover align-middle" style="font-size:13px;width:100%;min-width:550px;">';
  html += '<thead><tr style="background:#f8fafc;"><th>Student</th><th>Submission Date</th><th>Attached File</th><th>Remarks</th><th style="width:150px;">Score / Grade</th></tr></thead><tbody>';

  subs.forEach(function(s){
    var studentName = s.first_name + ' ' + s.last_name + ' (' + s.student_code + ')';
    var fileLink = s.file_name ? '<a href="../shared/submission_download?id='+s.id+'" class="btn btn-xs btn-outline-info" style="font-size:11px;font-weight:600;"><i class="fa fa-download"></i> '+ escapeHtml(s.original_name || 'Download File') +'</a>' : '<span class="text-muted" style="font-size:11px;">No File</span>';
    var gradeVal = s.grade !== null ? s.grade : '';

    html += '<tr>';
    html += '<td><strong>' + escapeHtml(studentName) + '</strong><br><small class="text-muted">' + (s.program_code || '') + ' ' + (s.section || '') + '</small></td>';
    html += '<td style="font-size:12px;color:#475569;">' + s.submitted_at + '</td>';
    html += '<td>' + fileLink + '</td>';
    html += '<td><small class="text-muted">' + (s.remarks ? escapeHtml(s.remarks) : 'None') + '</small></td>';
    html += '<td>';
    html += '<div class="input-group input-group-sm">';
    html += '<input type="number" step="0.5" class="form-control" id="grade_input_' + s.id + '" value="' + gradeVal + '" placeholder="Score" style="font-weight:600;">';
    html += '<span class="input-group-btn">';
    html += '<button class="btn btn-success" type="button" onclick="saveGrade(' + s.id + ')" title="Save Grade"><i class="fa fa-check"></i></button>';
    html += '</span>';
    html += '</div>';
    html += '</td>';
    html += '</tr>';
  });

  html += '</tbody></table></div>';
  container.html(html);
}

function saveGrade(subId) {
  var gradeVal = $('#grade_input_' + subId).val();
  if(gradeVal === '') { alert('Please enter a grade score.'); return; }

  $.post('/cenlearn/shared/assignment_handler', { action: 'grade', sub_id: subId, grade: gradeVal }, function(res){
    if(res.success){
      alert('Grade saved successfully!');
    } else {
      alert(res.msg || 'Failed to save grade.');
    }
  }, 'json');
}

function analyzeAssignmentRelevance(assignmentId) {
  $('#relevanceModalBody').html('<div class="text-center py-4"><i class="fa fa-spinner fa-spin fa-2x text-info"></i><p class="mt-2 text-muted">Analyzing assignment structure & quality metrics...</p></div>');
  $('#relevanceModal').modal('show');

  $.get('/cenlearn/shared/assignment_handler', { action: 'analyze_relevance', assignment_id: assignmentId }, function(res){
    if(res.success && res.analysis){
      var a = res.analysis;
      var html = '<div style="margin-bottom:20px; background:#fff; padding:16px; border-radius:12px; border:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between;">';
      html += '<div>';
      html += '<h4 style="margin:0 0 4px; font-weight:800; color:#0f172a;">' + escapeHtml(a.title) + '</h4>';
      html += '<p style="margin:0; font-size:12px; color:#64748b;">Subject: <strong>' + escapeHtml(a.subject) + '</strong> | ' + escapeHtml(a.class_name) + '</p>';
      html += '</div>';
      html += '<div style="text-align:right;">';
      html += '<div style="font-size:24px; font-weight:800; color:#0284c7;">' + a.overall_score + '<span style="font-size:14px; color:#64748b;">/100</span></div>';
      html += '<span style="font-size:10px; font-weight:700; text-transform:uppercase; color:#059669; background:#d1fae5; padding:2px 6px; border-radius:4px;">AI Quality Rating</span>';
      html += '</div>';
      html += '</div>';

      html += '<div class="row text-center mb-3" style="gap:0;">';
      html += '<div class="col-xs-4"><div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:10px;"><strong style="font-size:18px; color:#1d4ed8;">' + a.clarity_score + '%</strong><br><span style="font-size:10px; font-weight:600; color:#64748b;">Clarity</span></div></div>';
      html += '<div class="col-xs-4"><div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:10px;"><strong style="font-size:18px; color:#15803d;">' + a.alignment_score + '%</strong><br><span style="font-size:10px; font-weight:600; color:#64748b;">Deadline Alignment</span></div></div>';
      html += '<div class="col-xs-4"><div style="background:#fef3c7; border:1px solid #fde68a; border-radius:10px; padding:10px;"><strong style="font-size:18px; color:#b45309;">' + a.rubric_score + '%</strong><br><span style="font-size:10px; font-weight:600; color:#64748b;">Point Scale</span></div></div>';
      html += '</div>';

      html += '<div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px; margin-bottom:16px;">';
      html += '<h5 style="margin:0 0 10px; font-weight:700; font-size:13px; color:#0f172a;"><i class="fa fa-info-circle" style="color:#0284c7;"></i> Assignment Profile</h5>';
      html += '<p style="margin:0 0 4px; font-size:12px; color:#475569;">Bloom Taxonomy Cognitive Level: <strong>' + escapeHtml(a.bloom_level) + '</strong></p>';
      html += '<p style="margin:0; font-size:12px; color:#475569;">Estimated Completion Effort: <strong>' + escapeHtml(a.estimated_time) + '</strong></p>';
      html += '</div>';

      html += '<div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px;">';
      html += '<h5 style="margin:0 0 10px; font-weight:700; font-size:13px; color:#0f172a;"><i class="fa fa-lightbulb-o" style="color:#f59e0b;"></i> AI Optimization Recommendations</h5>';
      html += '<ul style="margin:0; padding-left:18px; font-size:12px; color:#334155;">';
      a.suggestions.forEach(function(s){
        html += '<li style="margin-bottom:6px;">' + escapeHtml(s) + '</li>';
      });
      html += '</ul>';
      html += '</div>';

      $('#relevanceModalBody').html(html);
    } else {
      $('#relevanceModalBody').html('<div class="alert alert-danger">' + (res.msg || 'Failed to analyze assignment.') + '</div>');
    }
  }, 'json');
}

function escapeHtml(text) {
  if (!text) return '';
  return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}
</script>
</body>
</html>
