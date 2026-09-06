<?php
include '../includes/session.php';
include '../includes/conn.php';
include '../includes/programs.php';

if(strtoupper($user['user_group']) !== 'TEACHER'){
    header('location: dashboard'); exit;
}

$tc = $conn->real_escape_string($user['user_code']);
$initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));

$action = $_POST['action'] ?? $_GET['action'] ?? '';// Fetch active classes for this teacher
$classesQ = $conn->query("
    SELECT c.*,
           COUNT(DISTINCT CASE WHEN u.user_group='STUDENT' THEN cm.user_code END) AS student_count
    FROM classes c
    LEFT JOIN class_members cm ON c.id = cm.class_id
    LEFT JOIN users u ON cm.user_code = u.user_code
    WHERE c.teacher_code = '$tc' AND (c.is_archived = 0 OR c.is_archived IS NULL) AND (c.is_subject_only = 0 OR c.is_subject_only IS NULL)
    GROUP BY c.id ORDER BY c.created_at DESC
");
$classes = [];
while($r = $classesQ->fetch_assoc()) $classes[] = $r;

// Active selected class
$selected_class_id = intval($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
$active_term = $_GET['term'] ?? 'midterm';
if(!in_array($active_term, ['midterm','final'])) $active_term = 'midterm';

$currentClass = null;
foreach($classes as $c){
    if((int)$c['id'] === $selected_class_id){
        $currentClass = $c;
        break;
    }
}

// Fetch enrolled students for selected class
$studentRows = [];
if($selected_class_id){
    $stQ = $conn->query("
        SELECT u.user_code, u.first_name, u.middle_name, u.last_name, u.year_level, u.section, u.program_code
        FROM class_members cm
        JOIN users u ON cm.user_code = u.user_code
        WHERE cm.class_id = $selected_class_id AND u.user_group = 'STUDENT'
        ORDER BY u.last_name, u.first_name
    ");
    while($s = $stQ->fetch_assoc()) $studentRows[] = $s;
}

// Fetch attendance sessions for selected class & active term
$sessions = [];
if($selected_class_id){
    $sessQ = $conn->query("
        SELECT * FROM class_attendance_sessions
        WHERE class_id = $selected_class_id AND term = '$active_term'
        ORDER BY attendance_date ASC, id ASC
    ");
    while($s = $sessQ->fetch_assoc()) $sessions[] = $s;
}

// Fetch student attendance records map: [session_id][student_code] => status
$attMap = [];
if($selected_class_id && !empty($sessions)){
    $sessIds = implode(',', array_column($sessions, 'id'));
    $recQ = $conn->query("
        SELECT session_id, student_code, status, remarks
        FROM class_attendance_records
        WHERE session_id IN ($sessIds)
    ");
    while($r = $recQ->fetch_assoc()){
        $attMap[$r['session_id']][$r['student_code']] = $r['status'];
    }
}

// Compute overview statistics
$totalStudentsCount = count($studentRows);
$totalSessionsCount = count($sessions);
$presentCountTotal  = 0;
$lateCountTotal     = 0;
$absentCountTotal   = 0;
$excusedCountTotal  = 0;
$totalMarksPossible = $totalStudentsCount * $totalSessionsCount;

foreach($sessions as $sess){
    $sid = $sess['id'];
    foreach($studentRows as $st){
        $stCode = $st['user_code'];
        $stStatus = $attMap[$sid][$stCode] ?? null;
        if($stStatus === 'present') $presentCountTotal++;
        elseif($stStatus === 'late') $lateCountTotal++;
        elseif($stStatus === 'absent') $absentCountTotal++;
        elseif($stStatus === 'excused') $excusedCountTotal++;
    }
}

$presentPctTotal = $totalMarksPossible > 0 ? round(($presentCountTotal / $totalMarksPossible) * 100, 1) : 0;
$latePctTotal    = $totalMarksPossible > 0 ? round(($lateCountTotal / $totalMarksPossible) * 100, 1) : 0;
$absentPctTotal  = $totalMarksPossible > 0 ? round(($absentCountTotal / $totalMarksPossible) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Teacher Attendance Dashboard</title>
  <link rel="stylesheet" href="/cenlearn/system/bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/cenlearn/system/bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/cenlearn/system/dist/css/cenlearn.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: 'Inter', sans-serif; background: #f0f4f8; margin: 0; color: #1e293b; }

    /* Sidebar */
    .td-sidebar { position: fixed; top: 0; left: 0; width: 240px; height: 100vh; background: linear-gradient(180deg,#0a1f0f 0%,#0d3320 55%,#065f46 100%); display: flex; flex-direction: column; z-index: 200; transition: transform .25s; transform: translateX(-240px); }
    .td-sidebar.open { transform: translateX(0); }
    @media(min-width: 901px) { .td-sidebar { transform: translateX(0); } }
    .sb-brand { padding: 18px 18px 14px; border-bottom: 1px solid rgba(255,255,255,.08); }
    .sb-logo { width: 34px; height: 34px; border-radius: 8px; background: linear-gradient(135deg,#10b981,#059669); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 6px; box-shadow: 0 3px 10px rgba(16,185,129,.35); }
    .sb-logo i { color: #fff; font-size: 15px; }
    .sb-brand h2 { color: #fff; font-size: 16px; font-weight: 800; margin: 0; }
    .sb-brand h2 span { color: #34d399; }
    .sb-brand p { color: rgba(255,255,255,.35); font-size: 9.5px; margin: 2px 0 0; }
    .sb-nav { flex: 1; padding: 10px 0; overflow-y: auto; }
    .sb-section { padding: 8px 18px 4px; font-size: 9px; font-weight: 700; color: rgba(255,255,255,.25); letter-spacing: 1.4px; text-transform: uppercase; }
    .sb-nav ul { list-style: none; margin: 0; padding: 0; }
    .sb-nav li a { display: flex; align-items: center; gap: 10px; padding: 9px 18px; color: rgba(255,255,255,.6); text-decoration: none; font-size: 12.5px; font-weight: 500; transition: all .18s; border-left: 3px solid transparent; }
    .sb-nav li a:hover { background: rgba(255,255,255,.07); color: #fff; }
    .sb-nav li.active a { background: rgba(52,211,153,.12); color: #fff; border-left-color: #34d399; }
    .sb-nav li a i { width: 17px; text-align: center; font-size: 14px; }
    .sb-footer { padding: 12px 18px; border-top: 1px solid rgba(255,255,255,.07); }
    .sb-user { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
    .sb-av { width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg,#10b981,#059669); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; color: #fff; flex-shrink: 0; }
    .sb-meta strong { display: block; color: #fff; font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px; }
    .sb-meta span { color: rgba(255,255,255,.4); font-size: 9.5px; }
    .sb-out { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 7px; width: 100%; background: rgba(255,255,255,.07); color: rgba(255,255,255,.6); border: 1px solid rgba(255,255,255,.1); border-radius: 6px; font-size: 11.5px; font-weight: 500; text-decoration: none; transition: background .2s; }
    .sb-out:hover { background: rgba(255,255,255,.13); color: #fff; }

    /* Main Layout */
    .td-main { margin-left: 0; min-height: 100vh; display: flex; flex-direction: column; }
    @media(min-width: 901px) { .td-main { margin-left: 240px; } }
    .td-topbar { background: #fff; padding: 8px 18px; height: auto; min-height: 52px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 50; box-shadow: 0 1px 4px rgba(0,0,0,.04); flex-wrap: wrap; gap: 8px; }
    .td-topbar h3 { font-size: 15px; font-weight: 700; color: #0f172a; margin: 0; }
    .td-topbar p { font-size: 11px; color: #64748b; margin: 0; }
    .td-content { padding: 18px 20px 40px; flex: 1; }

    /* Page Hero */
    .page-hero { background: linear-gradient(135deg,#0a1f0f 0%,#10b981 100%); border-radius: 12px; padding: 14px 18px; margin-bottom: 14px; position: relative; overflow: hidden; }
    .page-hero::before { content: ''; position: absolute; inset: 0; opacity: .05; background-image: radial-gradient(circle,#fff 1.5px,transparent 1.5px); background-size: 24px 24px; pointer-events: none; }
    .page-hero-inner { position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
    .page-hero h2 { font-size: 16px; font-weight: 800; color: #fff; margin: 0 0 2px; }
    .page-hero p { font-size: 11px; color: rgba(255,255,255,.8); margin: 0; }

    /* Filter Bar */
    .filter-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.03); }
    .filter-group { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; flex: 1; }
    .filter-item { display: inline-flex; align-items: center; gap: 6px; }
    .filter-label { font-size: 11px; font-weight: 700; color: #475569; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
    .select-custom { height: 32px; padding: 0 10px; border: 1.5px solid #cbd5e1; border-radius: 7px; font-size: 11.5px; font-family: 'Inter',sans-serif; background: #fff; color: #0f172a; font-weight: 600; cursor: pointer; outline: none; transition: all .18s; max-width: 260px; text-overflow: ellipsis; }
    .select-custom:focus { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.12); }
    .filter-actions { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

    /* View Switcher */
    .view-switcher { display: inline-flex; align-items: center; gap: 2px; background: #f1f5f9; border-radius: 7px; padding: 2px; border: 1px solid #e2e8f0; height: 32px; }
    .view-tab-btn { border: none; background: transparent; height: 100%; padding: 0 10px; border-radius: 5px; font-size: 11px; font-weight: 700; color: #64748b; cursor: pointer; transition: all .15s; display: inline-flex; align-items: center; gap: 4px; font-family: 'Inter',sans-serif; white-space: nowrap; }
    .view-tab-btn.active { background: #fff; color: #10b981; box-shadow: 0 1px 4px rgba(0,0,0,.08); }

    /* KPI Grid */
    .kpi-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 14px; }
    .kpi-box { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px; text-align: center; box-shadow: 0 2px 6px rgba(0,0,0,.02); }
    .kpi-box strong { display: block; font-size: 16px; font-weight: 800; line-height: 1.1; margin-bottom: 2px; }
    .kpi-box span { font-size: 9px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }

    @media(max-width: 768px) {
      .page-hero { padding: 12px 14px !important; margin-bottom: 12px !important; }
      .page-hero h2 { font-size: 15px !important; }
      .page-hero p { font-size: 10.5px !important; }
      .filter-card { flex-direction: column !important; align-items: stretch !important; padding: 10px 12px !important; gap: 10px !important; }
      .filter-group { display: flex !important; flex-direction: column !important; align-items: stretch !important; gap: 8px !important; width: 100% !important; }
      .filter-item { display: flex !important; flex-direction: column !important; align-items: flex-start !important; gap: 4px !important; width: 100% !important; }
      .select-custom { width: 100% !important; max-width: 100% !important; height: 34px !important; font-size: 11.5px !important; }
      .filter-actions { width: 100% !important; justify-content: flex-start !important; gap: 6px !important; }
      .filter-actions .btn-green, .filter-actions .btn-outline { flex: 1 !important; justify-content: center !important; min-width: 110px !important; height: 32px !important; font-size: 11px !important; }
      .view-switcher { width: 100% !important; justify-content: center !important; }
      .view-tab-btn { flex: 1 !important; justify-content: center !important; }
      .kpi-grid { grid-template-columns: repeat(3, 1fr) !important; gap: 6px !important; margin-bottom: 12px !important; }
      .kpi-box { padding: 8px 6px !important; border-radius: 8px !important; }
      .kpi-box strong { font-size: 15px !important; }
      .kpi-box span { font-size: 8.5px !important; }
      .calendar-card { padding: 10px !important; border-radius: 10px !important; }
      .cal-day-cell { min-height: 55px !important; padding: 4px !important; }
      .cal-day-num { font-size: 11px !important; }
    }

    /* Calendar Grid Component */
    .calendar-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px; box-shadow: 0 4px 18px rgba(0,0,0,.03); margin-bottom: 22px; }
    .calendar-header { padding: 12px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
    .calendar-header h4 { font-size: 14px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px; }
    .cal-nav-btn { width: 30px; height: 30px; border-radius: 7px; border: 1px solid #cbd5e1; background: #fff; color: #334155; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all .15s; }
    .cal-nav-btn:hover { background: #10b981; color: #fff; border-color: #10b981; }

    .calendar-body-wrapper { border: 1.5px solid #e2e8f0; border-radius: 14px; overflow: hidden; background: #f8fafc; padding: 6px; box-shadow: 0 2px 8px rgba(0,0,0,.02); }

    .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
    .cal-day-hdr { background: #fff; padding: 8px 4px; text-align: center; font-size: 10.5px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: .4px; border-radius: 6px; border: 1px solid #e2e8f0; }

    .cal-day-cell { min-height: 70px; padding: 6px; border: 1.5px solid #e2e8f0; border-radius: 10px; background: #fff; position: relative; cursor: pointer; transition: all .15s ease; display: flex; flex-direction: column; }
    .cal-day-cell:hover { background: #fff; border-color: #10b981; box-shadow: 0 4px 12px rgba(16,185,129,.12); transform: translateY(-1px); }
    .cal-day-cell.other-month { background: #f9fafb; opacity: .55; border-color: #f1f5f9; cursor: pointer; }
    .cal-day-cell.other-month:hover { opacity: 1; background: #fff; border-color: #cbd5e1; }
    .cal-day-cell.today { background: #f0fdf4; border: 2px solid #10b981; box-shadow: 0 2px 8px rgba(16,185,129,.15); }
    
    .cal-day-num { font-size: 12px; font-weight: 800; color: #0f172a; margin: 2px 2px 6px 2px; display: flex; align-items: center; justify-content: space-between; }
    .cal-day-num .today-badge { font-size: 7.5px; font-weight: 800; background: #10b981; color: #fff; padding: 2px 5px; border-radius: 5px; text-transform: uppercase; }

    .cal-event-pill { margin-top: auto; padding: 4px 6px; border-radius: 6px; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: space-between; gap: 4px; border: 1px solid transparent; }
    .cal-event-pill.has-att { background: #dcfce7; color: #15803d; border-color: #bbf7d0; box-shadow: 0 1px 3px rgba(16,185,129,.15); }
    .cal-add-prompt { display: none; margin-top: auto; font-size: 9px; font-weight: 700; color: #10b981; text-align: center; border: 1px dashed #10b981; border-radius: 5px; padding: 3px; background: #f0fdf4; }
    .cal-day-cell:hover .cal-add-prompt { display: block; }

    /* Attendance Matrix Table */
    .table-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,.03); margin-bottom: 20px; }
    .table-header { padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
    .table-header h4 { font-size: 13.5px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 6px; }

    .table-responsive-custom { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table.att-table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
    table.att-table th, table.att-table td { border: 1px solid #e8edf2; padding: 7px 9px; text-align: center; white-space: nowrap; }
    table.att-table th { background: #f8fafc; font-size: 9.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; position: sticky; top: 0; z-index: 2; }
    table.att-table td.col-name { text-align: left; position: sticky; left: 0; background: #fff; z-index: 3; font-weight: 600; color: #0f172a; min-width: 170px; }
    table.att-table th.col-name { position: sticky; left: 0; z-index: 4; background: #f8fafc; }

    /* Status Pills & Buttons */
    .status-pill { display: inline-flex; align-items: center; justify-content: center; gap: 3px; padding: 3px 8px; border-radius: 5px; font-size: 10.5px; font-weight: 700; border: none; cursor: pointer; transition: all .15s; }
    .status-pill.present { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
    .status-pill.late { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
    .status-pill.absent { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
    .status-pill.excused { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
    .status-pill.unrecorded { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }

    /* Action Buttons */
    .btn-green { display: inline-flex; align-items: center; justify-content: center; gap: 5px; height: 32px; padding: 0 12px; background: linear-gradient(135deg,#10b981,#059669); color: #fff; border: none; border-radius: 7px; font-size: 11px; font-weight: 700; font-family: 'Inter',sans-serif; cursor: pointer; text-decoration: none; transition: opacity .2s; white-space: nowrap; }
    .btn-green:hover { opacity: .9; color: #fff; text-decoration: none; }
    .btn-outline { display: inline-flex; align-items: center; justify-content: center; gap: 5px; height: 32px; padding: 0 11px; background: #fff; color: #475569; border: 1.5px solid #cbd5e1; border-radius: 7px; font-size: 11px; font-weight: 600; font-family: 'Inter',sans-serif; cursor: pointer; transition: all .15s; text-decoration: none; white-space: nowrap; }
    .btn-outline:hover { border-color: #10b981; color: #10b981; text-decoration: none; }

    /* FULL-SCREEN SPACIOUS ATTENDANCE MODAL */
    .cr-modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.65); display: flex; align-items: center; justify-content: center; z-index: 1000; opacity: 0; pointer-events: none; transition: opacity .2s; backdrop-filter: blur(5px); padding: 4px; }
    .cr-modal-overlay.open { opacity: 1; pointer-events: all; }
    
    .cr-modal { 
      background: #fff; 
      border-radius: 12px; 
      width: 99vw; 
      height: 98vh;
      max-width: 100vw; 
      max-height: 98vh; 
      margin: auto; 
      box-shadow: 0 25px 70px rgba(0,0,0,.35); 
      transform: translateY(6px); 
      transition: all .25s ease-in-out; 
      overflow: hidden; 
      display: flex; 
      flex-direction: column; 
    }
    .cr-modal-overlay.open .cr-modal { transform: translateY(0); }
    
    .cr-modal-body { padding: 10px 16px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; }
    .cr-modal-foot { padding: 8px 16px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 10px; background: #f8fafc; flex-shrink: 0; }
    .cr-field { margin-bottom: 4px; }
    .cr-field label { display: block; font-size: 11px; font-weight: 700; color: #374151; margin-bottom: 2px; }
    .cr-fc { width: 100%; padding: 6px 10px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 12px; font-family: 'Inter',sans-serif; background: #fff; font-weight: 600; color: #0f172a; }
    .cr-fc:focus { outline: none; border-color: #10b981; }

    /* Student Roster Table in Modal (Single 1-Column Full-Width Roster Layout) */
    .roster-container { 
      flex: 1; 
      overflow-y: auto; 
      border: 1px solid #e2e8f0; 
      border-radius: 12px; 
      margin-top: 6px; 
      background: #fff; 
      box-shadow: 0 2px 10px rgba(15, 23, 42, 0.02);
    }
    .roster-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .roster-table th, .roster-table td { border-bottom: 1px solid #f1f5f9; padding: 8px 16px; text-align: left; vertical-align: middle; }
    .roster-table th { 
      background: #f8fafc; 
      font-size: 11px; 
      font-weight: 800; 
      color: #475569; 
      text-transform: uppercase; 
      letter-spacing: .6px; 
      position: sticky; 
      top: 0; 
      z-index: 10; 
      border-bottom: 2px solid #e2e8f0; 
      padding: 10px 16px; 
    }
    .roster-table tr { transition: background 0.15s ease; }
    .roster-table tbody tr:nth-child(even) { background: #fafafa; }
    .roster-table tbody tr:hover { background: #f0fdf4 !important; }

    .st-avatar-badge {
      width: 28px;
      height: 28px;
      border-radius: 8px;
      background: linear-gradient(135deg, #10b981, #059669);
      color: #fff;
      font-size: 10px;
      font-weight: 800;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      box-shadow: 0 2px 6px rgba(16, 185, 129, 0.2);
    }

    .status-radio-group { 
      display: inline-flex; 
      align-items: center; 
      gap: 4px; 
      background: #f1f5f9; 
      padding: 3px; 
      border-radius: 9px; 
      border: 1px solid #e2e8f0; 
      white-space: nowrap; 
      flex-wrap: nowrap; 
      margin: 1px 0; 
    }
    .status-radio-item { 
      display: inline-flex; 
      align-items: center; 
      gap: 5px; 
      padding: 4px 10px; 
      border-radius: 7px; 
      font-size: 11px; 
      font-weight: 700; 
      cursor: pointer; 
      user-select: none; 
      transition: all .15s ease; 
      border: 1px solid transparent; 
      white-space: nowrap; 
      background: #fff;
      color: #475569;
    }
    .status-radio-item input[type=radio] { cursor: pointer; width: 13px; height: 13px; margin: 0; flex-shrink: 0; }
    
    .status-radio-item.opt-present { color: #15803d; border-color: #bbf7d0; background: #f0fdf4; }
    .status-radio-item.opt-present input[type=radio] { accent-color: #166534; }
    .status-radio-item.opt-late { color: #b45309; border-color: #fde68a; background: #fffbeb; }
    .status-radio-item.opt-late input[type=radio] { accent-color: #b45309; }
    .status-radio-item.opt-absent { color: #b91c1c; border-color: #fecaca; background: #fef2f2; }
    .status-radio-item.opt-absent input[type=radio] { accent-color: #b91c1c; }
    .status-radio-item.opt-excused { color: #0369a1; border-color: #bae6fd; background: #f0f9ff; }
    .status-radio-item.opt-excused input[type=radio] { accent-color: #0369a1; }
    
    .status-radio-item:hover { transform: translateY(-1px); box-shadow: 0 2px 6px rgba(0,0,0,.06); }

    @media(max-width: 900px){
      .td-main { margin-left: 0; }
      .td-sidebar { transform: translateX(-100%); }
      .td-sidebar.open { transform: translateX(0); }
      .filter-card { padding: 10px; gap: 8px; }
      .filter-group, .filter-actions { width: 100%; justify-content: flex-start; }
      .filter-item { flex: 1 1 auto; min-width: 140px; }
      .select-custom { width: 100%; max-width: 100%; }
      .kpi-grid { grid-template-columns: repeat(2, 1fr); }
      .cal-day-cell { min-height: 58px; padding: 4px; }
      .cal-day-num { font-size: 11px; }
      .cal-event-pill { font-size: 9px; padding: 2px 4px; }
      .cal-add-prompt { font-size: 8.5px; padding: 2px; }
      .cr-modal { width: 100vw; height: 100vh; max-width: 100vw; max-height: 100vh; margin: 0; border-radius: 0; }
    }
  </style>
</head>
<body>
<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

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
      <li><a href="assignments"><i class="fa fa-tasks"></i> Assignments</a></li>
      <li class="active"><a href="attendance"><i class="fa fa-calendar-check-o"></i> Attendance</a></li>
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
      <button class="cl-hamburger" onclick="openSidebar()"><i class="fa fa-bars"></i></button>
      <div>
        <h3>Teacher Attendance Dashboard</h3>
        <p>Record, manage, and auto-sync student attendance to Class Records</p>
      </div>
    </div>
  </header>

  <div class="td-content">

    <!-- Page Hero -->
    <div class="page-hero">
      <div class="page-hero-inner">
        <div>
          <h2><i class="fa fa-calendar-check-o" style="margin-right:8px;opacity:.9;"></i>Class Attendance Tracking & Calendar</h2>
          <p>Click on any date in the calendar below to set the date and record or view class attendance.</p>
        </div>
      </div>
    </div>

    <!-- Filter Card & View Switcher -->
    <div class="filter-card">
      <div class="filter-group">
        <div class="filter-item">
          <span class="filter-label"><i class="fa fa-book" style="color:#10b981;"></i> Select Class:</span>
          <select class="select-custom" id="selectClassFilter" onchange="changeFilters()">
            <?php foreach($classes as $c): ?>
              <option value="<?php echo $c['id']; ?>" <?php echo $c['id'] == $selected_class_id ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($c['class_name'] . ' (' . ($c['class_code']?:$c['subject']) . ')'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="filter-item">
          <span class="filter-label"><i class="fa fa-clock-o" style="color:#3b82f6;"></i> Term:</span>
          <select class="select-custom" id="selectTermFilter" onchange="changeFilters()">
            <option value="midterm" <?php echo $active_term === 'midterm' ? 'selected' : ''; ?>>Midterm Term</option>
            <option value="final" <?php echo $active_term === 'final' ? 'selected' : ''; ?>>Final Term</option>
          </select>
        </div>
      </div>

      <div class="filter-actions">
        <div class="view-switcher">
          <button class="view-tab-btn active" id="btnTabCalendar" onclick="switchView('calendar')"><i class="fa fa-calendar"></i> Attendance Calendar</button>
          <button class="view-tab-btn" id="btnTabMatrix" onclick="switchView('matrix')"><i class="fa fa-th"></i> Matrix Sheet</button>
        </div>

        <?php if($selected_class_id): ?>
          <a href="../shared/class_record_detail?id=<?php echo $selected_class_id; ?>&term=<?php echo $active_term; ?>" class="btn-outline">
            <i class="fa fa-table" style="color:#10b981;"></i> Open Class Record
          </a>
        <?php endif; ?>
        <button class="btn-outline" onclick="exportAttendanceCSV()"><i class="fa fa-download"></i> Export CSV</button>
      </div>
    </div>

    <!-- KPI Row -->
    <div class="kpi-grid">
      <div class="kpi-box">
        <strong style="color:#0f172a;"><?php echo $totalStudentsCount; ?></strong>
        <span>Enrolled Students</span>
      </div>
      <div class="kpi-box">
        <strong style="color:#10b981;"><?php echo $totalSessionsCount; ?></strong>
        <span>Sessions Logged</span>
      </div>
      <div class="kpi-box">
        <strong style="color:#166534;"><?php echo $presentPctTotal; ?>%</strong>
        <span>Present Average</span>
      </div>
      <div class="kpi-box">
        <strong style="color:#b45309;"><?php echo $latePctTotal; ?>%</strong>
        <span>Late Average</span>
      </div>
      <div class="kpi-box">
        <strong style="color:#b91c1c;"><?php echo $absentPctTotal; ?>%</strong>
        <span>Absent Average</span>
      </div>
    </div>

    <!-- VIEW 1: INTERACTIVE ATTENDANCE CALENDAR -->
    <div id="viewCalendar">
      <div class="calendar-card">
        <div class="calendar-header">
          <h4><i class="fa fa-calendar-check-o" style="color:#10b981;"></i> Attendance Calendar</h4>
          <div style="display:flex;align-items:center;gap:10px;">
            <button class="cal-nav-btn" onclick="prevCalMonth()"><i class="fa fa-chevron-left"></i></button>
            <span id="calMonthYearLabel" style="font-size:14px;font-weight:800;color:#0f172a;min-width:140px;text-align:center;"></span>
            <button class="cal-nav-btn" onclick="nextCalMonth()"><i class="fa fa-chevron-right"></i></button>
            <button class="btn-outline" style="padding:4px 10px;font-size:11px;" onclick="todayCalMonth()">Today</button>
          </div>
        </div>

        <div class="calendar-body-wrapper">
          <div class="calendar-grid">
            <div class="cal-day-hdr">Sun</div>
            <div class="cal-day-hdr">Mon</div>
            <div class="cal-day-hdr">Tue</div>
            <div class="cal-day-hdr">Wed</div>
            <div class="cal-day-hdr">Thu</div>
            <div class="cal-day-hdr">Fri</div>
            <div class="cal-day-hdr">Sat</div>
          </div>

          <div class="calendar-grid" id="calGridBody">
            <!-- Calendar Days Rendered Dynamically via JS -->
          </div>
        </div>
      </div>
    </div>

    <!-- VIEW 2: ATTENDANCE MATRIX SHEET -->
    <div id="viewMatrix" style="display:none;">
      <div class="table-card">
        <div class="table-header">
          <h4><i class="fa fa-th-list" style="color:#10b981;"></i> Attendance Matrix Sheet &bull; <?php echo ucfirst($active_term); ?> Term</h4>
          <span style="font-size:11px;color:#64748b;font-weight:600;">
            <span style="color:#15803d;">● Present (1.0)</span> &bull;
            <span style="color:#b45309;">● Late (0.5)</span> &bull;
            <span style="color:#b91c1c;">● Absent (0.0)</span>
          </span>
        </div>

        <?php if(empty($studentRows)): ?>
          <div style="text-align:center;padding:48px 20px;color:#94a3b8;">
            <i class="fa fa-users" style="font-size:36px;margin-bottom:10px;opacity:.5;"></i>
            <p style="margin:0;">No students enrolled in this class yet.</p>
          </div>
        <?php elseif(empty($sessions)): ?>
          <div style="text-align:center;padding:48px 20px;color:#94a3b8;">
            <i class="fa fa-calendar-check-o" style="font-size:36px;margin-bottom:10px;opacity:.5;"></i>
            <p style="margin:0;font-size:14px;font-weight:600;color:#334155;">No attendance sessions created yet for <?php echo ucfirst($active_term); ?> term.</p>
            <p style="margin:4px 0;font-size:12px;">Select today's date in the Calendar to record class attendance.</p>
          </div>
        <?php else: ?>
          <div class="table-responsive-custom">
            <table class="att-table" id="matrixAttTable">
              <thead>
                <tr>
                  <th style="width:36px;">#</th>
                  <th class="col-name">Student Name</th>
                  <?php foreach($sessions as $sess): ?>
                    <th style="min-width:100px;">
                      <span style="font-weight:700;color:#0f172a;"><?php echo date('M d, Y', strtotime($sess['attendance_date'])); ?></span>
                      <br>
                      <button style="border:none;background:none;color:#ef4444;font-size:10px;cursor:pointer;opacity:.6;" onclick="deleteSession(<?php echo $sess['id']; ?>)" title="Delete session"><i class="fa fa-trash"></i></button>
                    </th>
                  <?php endforeach; ?>
                  <th style="min-width:65px;background:#f0fdf4;color:#166534;">Present</th>
                  <th style="min-width:65px;background:#fffbeb;color:#b45309;">Late</th>
                  <th style="min-width:65px;background:#fef2f2;color:#991b1b;">Absent</th>
                  <th style="min-width:75px;background:#eff6ff;color:#1d4ed8;">Att %</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($studentRows as $idx => $st):
                  $uc = $st['user_code'];
                  $initialsSt = strtoupper(substr($st['first_name'],0,1).substr($st['last_name'],0,1));
                  $stPresent = 0; $stLate = 0; $stAbsent = 0; $stExcused = 0;
                  foreach($sessions as $sess){
                    $stStatus = $attMap[$sess['id']][$uc] ?? null;
                    if($stStatus === 'present') $stPresent++;
                    elseif($stStatus === 'late') $stLate++;
                    elseif($stStatus === 'absent') $stAbsent++;
                    elseif($stStatus === 'excused') $stExcused++;
                  }
                  $stTotalEarned = ($stPresent * 1.0) + ($stLate * 0.5) + ($stExcused * 1.0);
                  $stTotalMax    = count($sessions) * 1.0;
                  $stPct         = $stTotalMax > 0 ? round(($stTotalEarned / $stTotalMax) * 100, 1) : 0;
                ?>
                <tr>
                  <td style="color:#94a3b8;font-size:11px;"><?php echo $idx + 1; ?></td>
                  <td class="col-name">
                    <div style="display:flex;align-items:center;gap:8px;">
                      <div style="width:26px;height:26px;border-radius:6px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;color:#fff;flex-shrink:0;"><?php echo $initialsSt; ?></div>
                      <div>
                        <div style="font-size:12px;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($st['last_name'].', '.$st['first_name']); ?></div>
                      </div>
                    </div>
                  </td>

                  <?php foreach($sessions as $sess):
                    $sid = $sess['id'];
                    $status = $attMap[$sid][$uc] ?? 'unrecorded';
                    $label = ucfirst($status);
                    if($status === 'unrecorded') $label = '—';
                  ?>
                  <td>
                    <button class="status-pill <?php echo $status; ?>" onclick="cycleStatus(<?php echo $sid; ?>, '<?php echo addslashes($uc); ?>', '<?php echo $status; ?>')">
                      <?php echo $label; ?>
                    </button>
                  </td>
                  <?php endforeach; ?>

                  <td style="font-weight:700;color:#166534;background:#f0fdf4;"><?php echo $stPresent; ?></td>
                  <td style="font-weight:700;color:#b45309;background:#fffbeb;"><?php echo $stLate; ?></td>
                  <td style="font-weight:700;color:#991b1b;background:#fef2f2;"><?php echo $stAbsent; ?></td>
                  <td style="font-weight:800;color:<?php echo $stPct >= 75 ? '#166534' : '#991b1b'; ?>;background:#eff6ff;">
                    <?php echo $stPct; ?>%
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<!-- Modal: Take / Edit Attendance Session -->
<div class="cr-modal-overlay" id="takeAttModal">
  <div class="cr-modal" id="takeAttModalContainer">
    <div class="cr-modal-body">
      <input type="hidden" id="attModalSessionId" value="0">
      <input type="hidden" id="attModalTerm" value="<?php echo $active_term; ?>">
      
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:8px;">
          <i class="fa fa-calendar" style="color:#10b981;font-size:16px;"></i>
          <label for="attModalDate" style="font-size:12.5px;font-weight:700;color:#1e293b;margin:0;">Attendance Date:</label>
        </div>
        <input type="date" id="attModalDate" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" onchange="onModalDateChange(this.value)" style="padding:6px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;font-weight:600;color:#0f172a;background:#ffffff;outline:none;font-family:'Inter',sans-serif;">
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;margin:4px 0 12px;">
        <strong style="font-size:14px;color:#0f172a;"><i class="fa fa-users" style="color:#10b981;"></i> Enrolled Student Roster (<?php echo count($studentRows); ?>)</strong>
      </div>

      <!-- Single 1-Column Full-Width Roster Layout -->
      <div class="roster-container">
        <table class="roster-table">
          <thead>
            <tr>
              <th style="width:40px;text-align:center;">#</th>
              <th>STUDENT NAME</th>
              <th style="text-align:center;">ATTENDANCE STATUS</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($studentRows as $i => $st): 
              $stInitials = strtoupper(substr($st['first_name'],0,1).substr($st['last_name'],0,1));
            ?>
              <tr>
                <td style="color:#94a3b8;font-weight:700;text-align:center;font-size:11.5px;"><?php echo $i + 1; ?></td>
                <td>
                  <div style="display:flex;align-items:center;gap:10px;">
                    <div class="st-avatar-badge"><?php echo $stInitials; ?></div>
                    <div>
                      <strong style="font-size:12.5px;color:#0f172a;display:block;"><?php echo htmlspecialchars($st['last_name'].', '.$st['first_name']); ?></strong>
                    </div>
                  </div>
                </td>
                <td style="text-align:center;">
                  <div class="status-radio-group">
                    <label class="status-radio-item opt-present">
                      <input type="radio" name="roster_status_<?php echo $st['user_code']; ?>" value="present">
                      <span>Present</span>
                    </label>
                    <label class="status-radio-item opt-late">
                      <input type="radio" name="roster_status_<?php echo $st['user_code']; ?>" value="late">
                      <span>Late</span>
                    </label>
                    <label class="status-radio-item opt-absent">
                      <input type="radio" name="roster_status_<?php echo $st['user_code']; ?>" value="absent">
                      <span>Absent</span>
                    </label>
                    <label class="status-radio-item opt-excused">
                      <input type="radio" name="roster_status_<?php echo $st['user_code']; ?>" value="excused">
                      <span>Excused</span>
                    </label>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div id="attModalAlert" style="display:none;margin-top:10px;padding:8px 12px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;font-size:12px;"></div>
    </div>

    <div class="cr-modal-foot">
      <button class="btn-outline" onclick="closeModal('takeAttModal')">Cancel</button>
      <button class="btn-green" id="btnSubmitAttendance" onclick="submitAttendanceSession()"><i class="fa fa-save"></i> Save & Auto-Sync to Class Record</button>
    </div>
  </div>
</div>

<script src="/cenlearn/system/bower_components/jquery/dist/jquery.min.js"></script>
<script src="/cenlearn/system/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
var CLASS_ID = <?php echo $selected_class_id; ?>;
var STUDENTS = <?php echo json_encode(array_column($studentRows, 'user_code')); ?>;
var SESSIONS_LIST = <?php echo json_encode($sessions); ?>;
var ATT_MAP = <?php echo json_encode($attMap); ?>;

var currentCalDate = new Date();

function escapeHtml(text) {
  return text
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
}

function changeFilters() {
  var cid = document.getElementById('selectClassFilter').value;
  var term = document.getElementById('selectTermFilter').value;
  window.location.href = '?class_id=' + cid + '&term=' + term;
}

function switchView(view) {
  if(view === 'calendar') {
    document.getElementById('viewCalendar').style.display = 'block';
    document.getElementById('viewMatrix').style.display = 'none';
    document.getElementById('btnTabCalendar').classList.add('active');
    document.getElementById('btnTabMatrix').classList.remove('active');
  } else {
    document.getElementById('viewCalendar').style.display = 'none';
    document.getElementById('viewMatrix').style.display = 'block';
    document.getElementById('btnTabCalendar').classList.remove('active');
    document.getElementById('btnTabMatrix').classList.add('active');
  }
}

function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }

function toggleModalFullscreen() {
  var el = document.getElementById('takeAttModalContainer');
  var icon = document.getElementById('modalFsIcon');
  if(el.classList.contains('is-fullscreen')) {
    el.classList.remove('is-fullscreen');
    icon.className = 'fa fa-expand';
  } else {
    el.classList.add('is-fullscreen');
    icon.className = 'fa fa-compress';
  }
}

function formatDateTitle(dateStr) {
  if(!dateStr) return '';
  var parts = dateStr.split('-');
  var monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
  if(parts.length === 3) {
    var mIndex = parseInt(parts[1], 10) - 1;
    var day = parseInt(parts[2], 10);
    return monthNames[mIndex] + ' ' + day + ', ' + parts[0];
  }
  return dateStr;
}

function onModalDateChange(newDate) {
  var formatted = formatDateTitle(newDate);
  var titleEl = document.getElementById('attModalHeaderTitle');
  if (titleEl) titleEl.innerHTML = '<i class="fa fa-calendar-check-o"></i> Record Attendance for ' + formatted;
}

function openTakeAttendanceModal(){
  if(!CLASS_ID){ alert('Please select a class first.'); return; }
  document.getElementById('attModalSessionId').value = 0;
  var todayVal = document.getElementById('attModalDate').value;
  onModalDateChange(todayVal);
  document.getElementById('attModalAlert').style.display = 'none';
  openModal('takeAttModal');
}

function clearAllRoster() {
  STUDENTS.forEach(function(code){
    var radios = document.getElementsByName('roster_status_' + code);
    for(var i=0; i<radios.length; i++){
      radios[i].checked = false;
    }
  });
}

function openTakeAttendanceModalWithDate(dateStr, defaultTitle, sessionId) {
  if(!CLASS_ID){ alert('Please select a class first.'); return; }

  document.getElementById('attModalSessionId').value = sessionId || 0;
  document.getElementById('attModalDate').value = dateStr;
  onModalDateChange(dateStr);
  document.getElementById('attModalAlert').style.display = 'none';

  if(sessionId > 0) {
    var titleEl = document.getElementById('attModalHeaderTitle');
    if (titleEl) titleEl.innerHTML = '<i class="fa fa-pencil-square-o"></i> Edit Attendance for ' + formatDateTitle(dateStr);
    var sessRecs = ATT_MAP[sessionId] || {};
    STUDENTS.forEach(function(code){
      var stStatus = sessRecs[code] || '';
      var radios = document.getElementsByName('roster_status_' + code);
      for(var i=0; i<radios.length; i++){
        if(radios[i].value === stStatus){
          radios[i].checked = true;
        } else {
          radios[i].checked = false;
        }
      }
    });
  } else {
    // Clear all radio choices so NO student is auto-marked Present by default
    clearAllRoster();
  }

  openModal('takeAttModal');
}

// ── Render Monthly Calendar ───────────────────────────────────────────────────
function renderCalendar() {
  var year = currentCalDate.getFullYear();
  var month = currentCalDate.getMonth();

  var monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
  document.getElementById('calMonthYearLabel').textContent = monthNames[month] + " " + year;

  var firstDay = new Date(year, month, 1).getDay();
  var daysInMonth = new Date(year, month + 1, 0).getDate();
  var prevMonthDays = new Date(year, month, 0).getDate();

  var container = document.getElementById('calGridBody');
  if(!container) return;
  container.innerHTML = '';

  var now = new Date();
  var todayStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');

  // Map sessions by date
  var sessMapByDate = {};
  SESSIONS_LIST.forEach(function(s){
    sessMapByDate[s.attendance_date] = s;
  });

  // Previous month trailing days
  var prevMonth = month === 0 ? 11 : month - 1;
  var prevYear  = month === 0 ? year - 1 : year;

  for (var i = firstDay - 1; i >= 0; i--) {
    var d = prevMonthDays - i;
    var monthStrP = String(prevMonth + 1).padStart(2, '0');
    var dayStrP = String(d).padStart(2, '0');
    var dateStrP = prevYear + '-' + monthStrP + '-' + dayStrP;

    var cell = document.createElement('div');
    cell.className = 'cal-day-cell other-month';

    var sessP = sessMapByDate[dateStrP];
    var htmlP = '<div class="cal-day-num"><span>' + d + '</span></div>';
    if (sessP) {
      var displayDateTextP = formatDateTitle(dateStrP);
      htmlP += '<div class="cal-event-pill has-att"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><i class="fa fa-check-circle"></i> ' + escapeHtml(displayDateTextP) + '</span></div>';
    }

    cell.innerHTML = htmlP;

    (function(dStr, sObj) {
      cell.onclick = function() {
        if (sObj) {
          openTakeAttendanceModalWithDate(dStr, sObj.title, sObj.id);
        } else if (dStr <= todayStr) {
          openTakeAttendanceModalWithDate(dStr, '', 0);
        } else {
          alert('Attendance cannot be recorded for future dates.');
        }
      };
    })(dateStrP, sessP);

    container.appendChild(cell);
  }

  // Current month days
  for (var day = 1; day <= daysInMonth; day++) {
    var monthStr = String(month + 1).padStart(2, '0');
    var dayStr = String(day).padStart(2, '0');
    var dateStr = year + '-' + monthStr + '-' + dayStr;

    var cell = document.createElement('div');
    var isToday = (dateStr === todayStr);
    cell.className = 'cal-day-cell' + (isToday ? ' today' : '');

    var sess = sessMapByDate[dateStr];

    var html = '<div class="cal-day-num"><span>' + day + '</span>' + (isToday ? '<span class="today-badge">TODAY</span>' : '') + '</div>';

    if (sess) {
      var displayDateText = formatDateTitle(dateStr);
      html += '<div class="cal-event-pill has-att"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><i class="fa fa-check-circle"></i> ' + escapeHtml(displayDateText) + '</span></div>';
    } else {
      html += '<div class="cal-add-prompt"><i class="fa fa-plus"></i> Record Attendance</div>';
    }

    cell.innerHTML = html;

    (function(dStr, sObj) {
      cell.onclick = function() {
        if (sObj) {
          openTakeAttendanceModalWithDate(dStr, sObj.title, sObj.id);
        } else if (dStr <= todayStr) {
          openTakeAttendanceModalWithDate(dStr, '', 0);
        } else {
          alert('Attendance cannot be recorded for future dates.');
        }
      };
    })(dateStr, sess);

    container.appendChild(cell);
  }

  // Next month leading days
  var nextMonth = month === 11 ? 0 : month + 1;
  var nextYear  = month === 11 ? year + 1 : year;

  var totalCells = firstDay + daysInMonth;
  var trailingCells = (7 - (totalCells % 7)) % 7;
  for (var nextDay = 1; nextDay <= trailingCells; nextDay++) {
    var monthStrN = String(nextMonth + 1).padStart(2, '0');
    var dayStrN = String(nextDay).padStart(2, '0');
    var dateStrN = nextYear + '-' + monthStrN + '-' + dayStrN;

    var cell = document.createElement('div');
    cell.className = 'cal-day-cell other-month';

    var sessN = sessMapByDate[dateStrN];
    var htmlN = '<div class="cal-day-num"><span>' + nextDay + '</span></div>';
    if (sessN) {
      var displayDateTextN = formatDateTitle(dateStrN);
      htmlN += '<div class="cal-event-pill has-att"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><i class="fa fa-check-circle"></i> ' + escapeHtml(displayDateTextN) + '</span></div>';
    }

    cell.innerHTML = htmlN;

    (function(dStr, sObj) {
      cell.onclick = function() {
        if (sObj) {
          openTakeAttendanceModalWithDate(dStr, sObj.title, sObj.id);
        } else if (dStr <= todayStr) {
          openTakeAttendanceModalWithDate(dStr, '', 0);
        } else {
          alert('Attendance cannot be recorded for future dates.');
        }
      };
    })(dateStrN, sessN);

    container.appendChild(cell);
  }
}

function prevCalMonth() {
  currentCalDate.setMonth(currentCalDate.getMonth() - 1);
  renderCalendar();
}
function nextCalMonth() {
  currentCalDate.setMonth(currentCalDate.getMonth() + 1);
  renderCalendar();
}
function todayCalMonth() {
  currentCalDate = new Date();
  renderCalendar();
}

function markAllRoster(status) {
  STUDENTS.forEach(function(code){
    var radios = document.getElementsByName('roster_status_' + code);
    for(var i=0; i<radios.length; i++){
      if(radios[i].value === status){
        radios[i].checked = true;
      }
    }
  });
}

function submitAttendanceSession(){
  var sessionId = document.getElementById('attModalSessionId').value || 0;
  var date      = document.getElementById('attModalDate').value;
  var term      = document.getElementById('attModalTerm').value;

  if(!date){
    document.getElementById('attModalAlert').textContent = 'Attendance date is required.';
    document.getElementById('attModalAlert').style.display = 'block';
    return;
  }

  // Auto-generate title directly from selected Date (e.g. "Aug 06, 2026")
  var title = formatDateTitle(date);

  var records = [];
  STUDENTS.forEach(function(code){
    var selectedVal = 'present';
    var radios = document.getElementsByName('roster_status_' + code);
    for(var i=0; i<radios.length; i++){
      if(radios[i].checked){
        selectedVal = radios[i].value;
        break;
      }
    }
    records.push({ student_code: code, status: selectedVal });
  });

  document.getElementById('btnSubmitAttendance').disabled = true;

  $.post('attendance_handler.php', {
    action: 'save_attendance',
    class_id: CLASS_ID,
    session_id: sessionId,
    title: title,
    date: date,
    term: term,
    records: JSON.stringify(records)
  }, function(r){
    document.getElementById('btnSubmitAttendance').disabled = false;
    if(r.success){
      closeModal('takeAttModal');
      location.reload();
    } else {
      document.getElementById('attModalAlert').textContent = r.msg || 'Failed to save attendance.';
      document.getElementById('attModalAlert').style.display = 'block';
    }
  }, 'json').fail(function(){
    document.getElementById('btnSubmitAttendance').disabled = false;
    document.getElementById('attModalAlert').textContent = 'Network or server error while saving attendance.';
    document.getElementById('attModalAlert').style.display = 'block';
  });
}

function cycleStatus(sessionId, studentCode, currentStatus) {
  var nextStatus = 'present';
  if(currentStatus === 'present') nextStatus = 'late';
  else if(currentStatus === 'late') nextStatus = 'absent';
  else if(currentStatus === 'absent') nextStatus = 'excused';
  else if(currentStatus === 'excused') nextStatus = 'present';
  else nextStatus = 'present';

  $.post('attendance_handler.php', {
    action: 'update_student_status',
    class_id: CLASS_ID,
    session_id: sessionId,
    student_code: studentCode,
    status: nextStatus
  }, function(r){
    if(r.success){
      location.reload();
    } else {
      alert(r.msg || 'Failed to update status');
    }
  }, 'json').fail(function(){
    alert('Network or server error while updating status.');
  });
}

function deleteSession(sessionId) {
  if(!confirm('Are you sure you want to delete this attendance session? This will also remove its column from the Class Record.')) return;

  $.post('attendance_handler.php', {
    action: 'delete_session',
    class_id: CLASS_ID,
    session_id: sessionId
  }, function(r){
    if(r.success) location.reload();
    else alert(r.msg || 'Failed to delete session');
  }, 'json').fail(function(){
    alert('Network or server error while deleting session.');
  });
}

function exportAttendanceCSV() {
  var table = document.getElementById('matrixAttTable');
  if(!table) return;
  var rows = table.querySelectorAll('tr');
  var csv = [];
  rows.forEach(function(row){
    var cols = row.querySelectorAll('th, td');
    var rowData = [];
    cols.forEach(function(col){
      var btn = col.querySelector('button.status-pill');
      var text = btn ? btn.innerText.trim() : col.innerText.replace(/\n/g,' ').trim();
      text = text.replace(/,/g,';');
      rowData.push('"' + text + '"');
    });
    csv.push(rowData.join(','));
  });

  var blob = new Blob([csv.join('\n')], {type:'text/csv'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'Attendance_Class_' + CLASS_ID + '_' + document.getElementById('selectTermFilter').value + '.csv';
  a.click();
}

function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }

// Initialize calendar on page load
$(document).ready(function(){
  renderCalendar();
});
</script>
</body>
</html>
