<?php
include '../includes/session.php';
include '../includes/conn.php';
include '../shared/analytics_engine.php';

if(strtoupper($user['user_group']) !== 'TEACHER') {
    header('location: /cenlearn/dashboard'); exit;
}

$tc = $conn->real_escape_string($user['user_code']);

// Get teacher's classes
$classesQ = $conn->query("
    SELECT c.id, c.class_name, c.subject, c.section, c.year_level, c.program_code,
           (SELECT COUNT(DISTINCT cm.user_code) FROM class_members cm JOIN users u ON cm.user_code=u.user_code WHERE cm.class_id=c.id AND u.user_group='STUDENT') AS student_count
    FROM classes c
    WHERE c.teacher_code='$tc' AND (c.is_archived=0 OR c.is_archived IS NULL) AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)
    ORDER BY c.created_at DESC
");
$classes = [];
while($c = $classesQ->fetch_assoc()) $classes[] = $c;

// Selected class
$selectedId = intval($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
$selectedClass = null;
foreach($classes as $c) { if((int)$c['id'] === $selectedId) { $selectedClass = $c; break; } }

// Compute analytics for selected class
$students = $selectedId ? cenlearn_class_analytics($conn, $selectedId) : [];

// Summary counts
$onTrack   = count(array_filter($students, fn($s) => $s['level']==='on_track'));
$attention = count(array_filter($students, fn($s) => $s['level']==='attention'));
$atRisk    = count(array_filter($students, fn($s) => $s['level']==='at_risk'));
$highRisk  = count(array_filter($students, fn($s) => $s['level']==='high_risk'));
$total     = count($students);
$avgRisk   = $total > 0 ? round(array_sum(array_column($students,'score')) / $total) : 0;
$avgHealth = $total > 0 ? round(array_sum(array_column($students,'academic_health')) / $total) : 100;

$initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CenLearn — Teacher Performance &amp; Analytics</title>
  <link rel="stylesheet" href="/cenlearn/system/bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/cenlearn/system/bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/cenlearn/system/dist/css/cenlearn.css">
  <script src="/cenlearn/system/plugins/chart.umd.min.js"></script>
  <style>
    *,*::before,*::after{box-sizing:border-box;}
    body{font-family:'Inter',sans-serif;background:#f0f4f8;margin:0;color:#1e293b;-webkit-font-smoothing:antialiased;}
    
    /* ── Sidebar ── */
    .td-sidebar{position:fixed;top:0;left:0;width:240px;height:100vh;background:linear-gradient(180deg,#0a1f0f 0%,#0d3320 55%,#065f46 100%);display:flex;flex-direction:column;z-index:200;transition:transform .25s cubic-bezier(.4,0,.2,1);transform:translateX(-240px);}
    .td-sidebar.open{transform:translateX(0);}
    @media(min-width:901px){.td-sidebar{transform:translateX(0);}}
    .sb-brand{padding:18px 18px 14px;border-bottom:1px solid rgba(255,255,255,.07);}
    .sb-logo{width:34px;height:34px;border-radius:8px;background:linear-gradient(135deg,#10b981,#059669);display:inline-flex;align-items:center;justify-content:center;margin-bottom:6px;box-shadow:0 3px 10px rgba(16,185,129,.35);}
    .sb-logo i{color:#fff;font-size:15px;}
    .sb-brand h2{color:#fff;font-size:16px;font-weight:800;margin:0;}
    .sb-brand h2 span{color:#34d399;}
    .sb-brand p{color:rgba(255,255,255,.3);font-size:9.5px;margin:2px 0 0;}
    .sb-nav{flex:1;padding:10px 0;overflow-y:auto;}
    .sb-section{padding:8px 18px 4px;font-size:9px;font-weight:700;color:rgba(255,255,255,.22);letter-spacing:1.4px;text-transform:uppercase;}
    .sb-nav ul{list-style:none;margin:0;padding:0;}
    .sb-nav li a{display:flex;align-items:center;gap:10px;padding:9px 18px;color:rgba(255,255,255,.55);text-decoration:none;font-size:12.5px;font-weight:500;transition:all .18s;border-left:3px solid transparent;}
    .sb-nav li a:hover{background:rgba(255,255,255,.06);color:#fff;}
    .sb-nav li.active a{background:rgba(52,211,153,.12);color:#fff;border-left-color:#34d399;}
    .sb-nav li a i{width:16px;text-align:center;font-size:13px;}
    .sb-footer{padding:12px 18px;border-top:1px solid rgba(255,255,255,.07);}
    .sb-user{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
    .sb-av{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;flex-shrink:0;}
    .sb-meta strong{display:block;color:#fff;font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;}
    .sb-meta span{color:rgba(255,255,255,.38);font-size:9.5px;}
    .sb-out{display:flex;align-items:center;justify-content:center;gap:6px;padding:7px;width:100%;background:rgba(255,255,255,.06);color:rgba(255,255,255,.55);border:1px solid rgba(255,255,255,.1);border-radius:6px;font-size:11.5px;font-weight:500;text-decoration:none;transition:all .18s;}
    .sb-out:hover{background:rgba(255,255,255,.12);color:#fff;}
    
    /* ── Main Layout ── */
    .an-main{margin-left:0;min-height:100vh;display:flex;flex-direction:column;transition:margin 0s;}
    @media(min-width:901px){.an-main{margin-left:240px;}}
    .an-topbar{background:#fff;padding:0 20px;height:54px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:50;box-shadow:0 1px 3px rgba(0,0,0,.03);gap:10px;}
    .an-topbar h3{font-size:15px;font-weight:700;color:#0f172a;margin:0;}
    .an-topbar p{font-size:11px;color:#64748b;margin:0;}
    .an-content{padding:16px 20px 48px;flex:1;max-width:1440px;margin:0 auto;width:100%;}
    
    /* ── Compact Hero Banner ── */
    .an-hero{background:linear-gradient(135deg,#0a1f0f 0%,#10b981 100%);border-radius:14px;padding:14px 18px;margin-bottom:14px;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:14px;}
    .an-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.05);}
    .an-hero-text h2{color:#fff;font-size:17px;font-weight:800;margin:0 0 3px;}
    .an-hero-text p{color:rgba(255,255,255,.75);font-size:11.5px;margin:0;line-height:1.4;}
    .an-hero-chips{display:flex;align-items:center;flex-wrap:wrap;gap:6px;margin-top:8px;}
    .hero-chip{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.14);color:#fff;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:600;}
    .an-hero-icon{width:42px;height:42px;border-radius:10px;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative;z-index:1;}
    .an-hero-icon i{font-size:18px;color:#fff;}
    
    /* ── Class Selector & Filter Bar ── */
    .class-selector-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
    .cs-left{display:flex;align-items:center;gap:10px;flex:1;min-width:260px;}
    .cs-label{font-size:11.5px;font-weight:700;color:#374151;white-space:nowrap;display:flex;align-items:center;gap:6px;}
    .cs-select{flex:1;padding:7px 11px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12.5px;font-family:'Inter',sans-serif;color:#1e293b;background:#f8fafc;cursor:pointer;outline:none;transition:border-color .15s;}
    .cs-select:focus{border-color:#10b981;background:#fff;}
    .cs-badges{display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
    .cs-badge{padding:3px 8px;border-radius:6px;font-size:10.5px;font-weight:600;}
    
    /* ── Summary Stat Grid ── */
    .summary-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px;}
    .sum-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:10px 12px;text-align:center;transition:transform .15s,box-shadow .15s;position:relative;overflow:hidden;}
    .sum-card:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.04);}
    .sum-card .sc-num{font-size:22px;font-weight:800;line-height:1.1;margin-bottom:2px;}
    .sum-card .sc-lbl{font-size:10px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.4px;}
    .sum-card .sc-bar{height:3px;border-radius:99px;margin-top:6px;}
    
    /* ── Main 2-Column Grid ── */
    .an-grid{display:grid;grid-template-columns:1fr 310px;gap:14px;align-items:start;}
    .an-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.02);}
    .an-card-hdr{padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
    .an-card-hdr h4{font-size:13px;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:6px;}
    
    /* ── Table Filter Controls ── */
    .table-toolbar{padding:8px 14px;background:#fafbfc;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;}
    .tb-search{position:relative;flex:1;min-width:180px;max-width:280px;}
    .tb-search input{width:100%;padding:5px 9px 5px 28px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:11.5px;font-family:'Inter',sans-serif;outline:none;transition:border-color .15s;background:#fff;}
    .tb-search input:focus{border-color:#10b981;}
    .tb-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);font-size:11px;color:#94a3b8;}
    .risk-filter-pills{display:flex;align-items:center;gap:4px;flex-wrap:wrap;}
    .rf-pill{background:#fff;border:1px solid #e2e8f0;padding:3px 8px;border-radius:6px;font-size:10.5px;font-weight:600;color:#64748b;cursor:pointer;transition:all .15s;}
    .rf-pill:hover{background:#f1f5f9;color:#1e293b;}
    .rf-pill.active{background:#0f172a;color:#fff;border-color:#0f172a;}
    
    /* ── Compact Student Table ── */
    .table-responsive-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
    .stu-table{width:100%;border-collapse:collapse;min-width:620px;}
    .stu-table th{padding:9px 12px;font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e2e8f0;background:#fafbfc;text-align:left;white-space:nowrap;}
    .stu-table td{padding:9px 12px;border-bottom:1px solid #f1f5f9;font-size:12px;vertical-align:middle;}
    .stu-table tr:hover td{background:#f8fafc;}
    .stu-info{display:flex;align-items:center;gap:8px;}
    .stu-av{width:26px;height:26px;border-radius:6px;background:#e2e8f0;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#475569;flex-shrink:0;}
    .stu-name{font-weight:600;color:#0f172a;font-size:12px;line-height:1.2;}
    .stu-id{font-size:10px;color:#94a3b8;font-family:monospace;margin-top:1px;}
    
    /* ── Risk & Score Indicators ── */
    .risk-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:99px;font-size:10.5px;font-weight:700;white-space:nowrap;}
    .score-wrap{display:flex;align-items:center;gap:6px;}
    .score-bar-bg{flex:1;height:5px;background:#e2e8f0;border-radius:99px;overflow:hidden;min-width:48px;}
    .score-bar-fill{height:100%;border-radius:99px;transition:width .4s ease;}
    .score-num{font-size:11.5px;font-weight:700;min-width:24px;text-align:right;}
    
    /* ── Detail Accordion & Breakdown Panel ── */
    .detail-btn{background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b;cursor:pointer;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:4px;transition:all .15s;}
    .detail-btn:hover{background:#e2e8f0;color:#0f172a;}
    .breakdown-panel{background:#f8fafc;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;padding:12px 14px;}
    .bd-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:8px;margin-bottom:10px;}
    .bd-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;}
    .bd-card-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;font-size:11px;font-weight:700;color:#334155;}
    .bd-card-bar{height:4px;background:#f1f5f9;border-radius:99px;overflow:hidden;margin:4px 0 5px;}
    .bd-card-desc{font-size:10px;color:#64748b;line-height:1.3;}
    .bd-rec-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 10px;font-size:11px;color:#1e40af;display:flex;align-items:flex-start;gap:7px;}
    
    /* ── Right Column Cards ── */
    .chart-wrap{padding:14px;text-align:center;}
    .guide-row{display:flex;align-items:flex-start;gap:8px;margin-bottom:9px;}
    .guide-row:last-child{margin-bottom:0;}
    .guide-icon{width:26px;height:26px;border-radius:6px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#10b981;font-size:11px;}
    .guide-title{font-size:11.5px;font-weight:700;color:#0f172a;}
    .guide-pts{background:#f1f5f9;color:#64748b;padding:1px 6px;border-radius:99px;font-size:9.5px;font-weight:600;}
    .guide-desc{font-size:10.5px;color:#64748b;margin-top:1px;}
    
    /* ── Topic Analytics Section ── */
    .topic-section-hdr{display:flex;align-items:center;justify-content:space-between;margin:24px 0 12px;}
    .topic-section-hdr h3{font-size:14px;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:6px;}
    .topic-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    
    .an-empty{text-align:center;padding:40px 16px;color:#94a3b8;}
    .an-empty i{font-size:28px;opacity:.35;display:block;margin-bottom:8px;}
    .an-empty p{font-size:12px;margin:0;}
    footer.an-footer{text-align:center;padding:12px;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;background:#fff;margin-top:auto;}

    /* ── Responsive Breakpoints ── */
    @media(max-width:1100px){
      .an-grid{grid-template-columns:1fr;}
      .summary-grid{grid-template-columns:repeat(3,1fr);}
      .topic-grid{grid-template-columns:1fr;}
    }
    @media(max-width:768px){
      .an-topbar{padding:0 14px;}
      .an-content{padding:12px 12px 36px;}
      .summary-grid{grid-template-columns:repeat(2,1fr);gap:8px;}
      .summary-grid .sum-card:last-child{grid-column:span 2;}
      .an-hero{padding:12px 14px;flex-direction:column;align-items:flex-start;}
      .an-hero-icon{display:none;}
      .class-selector-card{flex-direction:column;align-items:stretch;}
      .cs-left{min-width:0;}
    }
    @media(max-width:480px){
      .summary-grid{grid-template-columns:1fr 1fr;}
      .sum-card{padding:8px;}
      .sum-card .sc-num{font-size:18px;}
      .sum-card .sc-lbl{font-size:9px;}
      .table-toolbar{flex-direction:column;align-items:stretch;}
      .tb-search{max-width:none;}
      .risk-filter-pills{justify-content:space-between;}
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

<div class="an-main">
  <header class="an-topbar">
    <div style="display:flex;align-items:center;gap:10px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
      <div>
        <h3>Performance &amp; Predictive Analytics</h3>
        <p>Student academic health, risk forecasting &amp; difficulty insights</p>
      </div>
    </div>
    <?php if($selectedClass && ($atRisk + $highRisk) > 0): ?>
    <span style="background:#fee2e2;color:#991b1b;padding:4px 10px;border-radius:99px;font-size:11px;font-weight:700;border:1px solid #fecaca;white-space:nowrap;">
      <i class="fa fa-exclamation-triangle"></i> <?php echo ($atRisk + $highRisk); ?> student<?php echo ($atRisk+$highRisk)!==1?'s':''; ?> need attention
    </span>
    <?php endif; ?>
  </header>

  <div class="an-content">

    <!-- Compact Hero -->
    <div class="an-hero">
      <div class="an-hero-text">
        <h2>Academic Risk &amp; Performance Forecaster</h2>
        <p>Evaluates quiz mastery, assignment submissions, live class attendance, and on-time task delivery.</p>
        <?php $mlActive = !empty($students) && !empty($students[0]['ml_active']); ?>
        <div class="an-hero-chips">
          <?php if($mlActive): ?>
          <span class="hero-chip" style="background:rgba(255,255,255,.2);"><i class="fa fa-magic"></i> AI/ML Hybrid Model Active</span>
          <?php else: ?>
          <span class="hero-chip" style="background:rgba(255,255,255,.1);"><i class="fa fa-check-circle-o"></i> Rule-Based Analytical Engine</span>
          <?php endif; ?>
          <?php if($selectedClass): ?>
          <span class="hero-chip" style="background:rgba(0,0,0,.2);"><i class="fa fa-users"></i> Class Avg Mastery: <?php echo $avgHealth; ?>%</span>
          <span class="hero-chip" style="background:rgba(0,0,0,.2);"><i class="fa fa-shield"></i> Avg Risk: <?php echo $avgRisk; ?>/100</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="an-hero-icon"><i class="fa fa-line-chart"></i></div>
    </div>

    <!-- Class Selector -->
    <?php if(empty($classes)): ?>
    <div class="an-empty" style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;">
      <i class="fa fa-book"></i>
      <p>No classes found. Create a class first to view performance analytics.</p>
    </div>
    <?php else: ?>
    <div class="class-selector-card">
      <div class="cs-left">
        <label class="cs-label"><i class="fa fa-book" style="color:#10b981;"></i> Select Class:</label>
        <select class="cs-select" onchange="location.href='analytics?class_id='+this.value">
          <?php foreach($classes as $c): ?>
          <option value="<?php echo $c['id']; ?>" <?php echo (int)$c['id']===$selectedId?'selected':''; ?>>
            <?php echo htmlspecialchars($c['class_name']); ?>
            <?php if($c['subject']): ?> — <?php echo htmlspecialchars($c['subject']); ?><?php endif; ?>
            (<?php echo $c['student_count']; ?> students)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if($selectedClass): ?>
      <div class="cs-badges">
        <?php if($selectedClass['program_code']): ?>
        <span class="cs-badge" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;"><?php echo htmlspecialchars($selectedClass['program_code']); ?></span>
        <?php endif; ?>
        <?php if($selectedClass['year_level']): ?>
        <span class="cs-badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">Year <?php echo $selectedClass['year_level']; ?></span>
        <?php endif; ?>
        <?php if($selectedClass['section']): ?>
        <span class="cs-badge" style="background:#fdf4ff;color:#7e22ce;border:1px solid #f5d0fe;">Sec <?php echo htmlspecialchars($selectedClass['section']); ?></span>
        <?php endif; ?>
        <a href="../shared/class_view?id=<?php echo $selectedId; ?>&tab=performance" class="cs-badge" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;text-decoration:none;">
          <i class="fa fa-external-link"></i> Class View
        </a>
      </div>
      <?php endif; ?>
    </div>

    <!-- Summary Grid -->
    <div class="summary-grid">
      <div class="sum-card">
        <div class="sc-num" style="color:#0f172a;"><?php echo $total; ?></div>
        <div class="sc-lbl">Total Students</div>
        <div class="sc-bar" style="background:#cbd5e1;"></div>
      </div>
      <div class="sum-card">
        <div class="sc-num" style="color:#10b981;"><?php echo $onTrack; ?></div>
        <div class="sc-lbl">On Track</div>
        <div class="sc-bar" style="background:#10b981;width:<?php echo $total>0?round($onTrack/$total*100):0; ?>%;"></div>
      </div>
      <div class="sum-card">
        <div class="sc-num" style="color:#f59e0b;"><?php echo $attention; ?></div>
        <div class="sc-lbl">Needs Attention</div>
        <div class="sc-bar" style="background:#f59e0b;width:<?php echo $total>0?round($attention/$total*100):0; ?>%;"></div>
      </div>
      <div class="sum-card">
        <div class="sc-num" style="color:#f97316;"><?php echo $atRisk; ?></div>
        <div class="sc-lbl">At Risk</div>
        <div class="sc-bar" style="background:#f97316;width:<?php echo $total>0?round($atRisk/$total*100):0; ?>%;"></div>
      </div>
      <div class="sum-card">
        <div class="sc-num" style="color:#ef4444;"><?php echo $highRisk; ?></div>
        <div class="sc-lbl">High Risk</div>
        <div class="sc-bar" style="background:#ef4444;width:<?php echo $total>0?round($highRisk/$total*100):0; ?>%;"></div>
      </div>
    </div>

    <!-- Main Grid: Student Table + Sidebar -->
    <div class="an-grid">

      <!-- Left Column: Student Table -->
      <div class="an-card">
        <div class="an-card-hdr">
          <h4><i class="fa fa-users" style="color:#10b981;"></i> Student Performance &amp; Risk Roster</h4>
          <span style="font-size:11.5px;color:#64748b;">Class Avg Mastery: <strong style="color:#10b981;"><?php echo $avgHealth; ?>%</strong> &bull; Avg Risk: <strong style="color:#0f172a;"><?php echo $avgRisk; ?>/100</strong></span>
        </div>

        <!-- Toolbar: Search + Risk filter pills -->
        <div class="table-toolbar">
          <div class="tb-search">
            <i class="fa fa-search"></i>
            <input type="text" id="stuSearch" placeholder="Search student name or ID..." onkeyup="filterStudents()">
          </div>
          <div class="risk-filter-pills">
            <button class="rf-pill active" onclick="setRiskFilter('all', this)">All (<?php echo $total; ?>)</button>
            <button class="rf-pill" onclick="setRiskFilter('on_track', this)">On Track (<?php echo $onTrack; ?>)</button>
            <button class="rf-pill" onclick="setRiskFilter('attention', this)">Attention (<?php echo $attention; ?>)</button>
            <button class="rf-pill" onclick="setRiskFilter('at_risk', this)">At Risk (<?php echo $atRisk; ?>)</button>
            <button class="rf-pill" onclick="setRiskFilter('high_risk', this)">High Risk (<?php echo $highRisk; ?>)</button>
          </div>
        </div>

        <?php if(empty($students)): ?>
        <div class="an-empty"><i class="fa fa-users"></i><p>No students enrolled in this class yet.</p></div>
        <?php else: ?>
        <div class="table-responsive-wrap">
          <table class="stu-table" id="stuTable">
            <thead>
              <tr>
                <th>Student</th>
                <th>Status</th>
                <th style="min-width:130px;">Academic Mastery</th>
                <th style="min-width:110px;">Risk Score</th>
                <th>Prediction</th>
                <th style="text-align:right;">Details</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($students as $idx => $s): 
              $health = $s['academic_health'] ?? max(0, min(100, 100 - $s['score']));
              $healthClr = $health >= 75 ? '#10b981' : ($health >= 50 ? '#f59e0b' : '#ef4444');
            ?>
            <tr class="stu-row" data-name="<?php echo htmlspecialchars(strtolower($s['first_name'].' '.$s['last_name'])); ?>" data-code="<?php echo htmlspecialchars(strtolower($s['user_code'])); ?>" data-level="<?php echo $s['level']; ?>">
              <td>
                <div class="stu-info">
                  <div class="stu-av"><?php echo strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1)); ?></div>
                  <div>
                    <div class="stu-name"><?php echo htmlspecialchars($s['first_name'].' '.$s['last_name']); ?></div>
                    <div class="stu-id"><?php echo htmlspecialchars($s['user_code']); ?></div>
                  </div>
                </div>
              </td>
              <td>
                <span class="risk-badge" style="background:<?php echo $s['bg']; ?>;color:<?php echo $s['textColor']; ?>;">
                  <i class="fa fa-circle" style="font-size:6px;"></i> <?php echo $s['label']; ?>
                </span>
              </td>
              <td>
                <div class="score-wrap">
                  <div class="score-bar-bg">
                    <div class="score-bar-fill" style="width:<?php echo $health; ?>%;background:<?php echo $healthClr; ?>;"></div>
                  </div>
                  <div class="score-num" style="color:<?php echo $healthClr; ?>;"><?php echo $health; ?>%</div>
                </div>
              </td>
              <td>
                <div class="score-wrap">
                  <div class="score-bar-bg">
                    <div class="score-bar-fill" style="width:<?php echo $s['score']; ?>%;background:<?php echo $s['color']; ?>;"></div>
                  </div>
                  <div class="score-num" style="color:<?php echo $s['color']; ?>;"><?php echo $s['score']; ?>/100</div>
                </div>
              </td>
              <td>
                <?php if(!empty($s['ml_active'])): ?>
                <span title="AI Confidence: <?php echo round(($s['ml_confidence']??0.85)*100); ?>%"
                      style="display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:99px;font-size:10.5px;font-weight:700;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">
                  <i class="fa fa-magic" style="font-size:8.5px;"></i>
                  <?php echo htmlspecialchars($s['ml_label'] ?? 'AI Forecast'); ?>
                  <span style="opacity:.65;font-weight:500;"><?php echo round(($s['ml_confidence']??0.85)*100); ?>%</span>
                </span>
                <?php else: ?>
                <span style="font-size:10.5px;color:#94a3b8;" title="Rule-based calculation">
                  <i class="fa fa-check-circle-o"></i> Rule-based
                </span>
                <?php endif; ?>
              </td>
              <td style="text-align:right;">
                <button class="detail-btn" onclick="toggleBreakdown(<?php echo $idx; ?>)" title="View assessment breakdown">
                  <span>Breakdown</span>
                  <i class="fa fa-chevron-down" id="chevron-<?php echo $idx; ?>"></i>
                </button>
              </td>
            </tr>
            <tr id="bd-<?php echo $idx; ?>" class="bd-row-container" style="display:none;">
              <td colspan="6" style="padding:0;background:#f8fafc;">
                <div class="breakdown-panel">
                  
                  <div class="bd-grid">
                    <?php foreach($s['breakdown'] as $key => $bd): 
                      $bdStatusClr = $bd['status']==='good'?'#10b981':($bd['status']==='warn'?'#f59e0b':($bd['status']==='bad'?'#ef4444':'#64748b'));
                      $barFillPct  = isset($bd['pct']) ? $bd['pct'] : ($bd['max'] > 0 ? (100 - round($bd['penalty']/$bd['max']*100)) : 100);
                      $ic = $key==='quiz'?'fa-question-circle':($key==='assignment_grade'?'fa-tasks':($key==='missed'?'fa-exclamation-triangle':($key==='attendance'?'fa-video-camera':'fa-clock-o')));
                    ?>
                    <div class="bd-card">
                      <div class="bd-card-hdr">
                        <span><i class="fa <?php echo $ic; ?>" style="color:<?php echo $bdStatusClr; ?>;margin-right:4px;"></i> <?php echo $bd['label']; ?></span>
                        <span style="color:<?php echo $bdStatusClr; ?>;font-weight:800;"><?php echo htmlspecialchars($bd['value']); ?></span>
                      </div>
                      <div class="bd-card-bar">
                        <div style="height:100%;width:<?php echo $barFillPct; ?>%;background:<?php echo $bdStatusClr; ?>;border-radius:99px;"></div>
                      </div>
                      <div class="bd-card-desc">
                        <?php echo htmlspecialchars($bd['detail'] ?? ''); ?>
                        <div style="margin-top:2px;font-weight:600;color:<?php echo $bd['penalty']>0?'#dc2626':'#166534'; ?>;">
                          <?php echo $bd['penalty']>0 ? "+{$bd['penalty']} pts risk deduction" : "0 penalty points"; ?>
                        </div>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  </div>

                  <div class="bd-rec-box">
                    <i class="fa fa-lightbulb-o" style="color:#2563eb;font-size:14px;margin-top:1px;"></i>
                    <div>
                      <strong>Recommended Action:</strong> <?php echo htmlspecialchars($s['recommendation'] ?? 'Keep up the good academic performance!'); ?>
                    </div>
                  </div>

                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div id="noMatchMsg" style="display:none;padding:30px 16px;text-align:center;color:#94a3b8;font-size:12px;">
          <i class="fa fa-search" style="font-size:20px;display:block;margin-bottom:6px;opacity:.4;"></i>
          No students match the current filter or search criteria.
        </div>
        <?php endif; ?>
      </div>

      <!-- Right Column: Chart + Scoring Guide -->
      <div style="display:flex;flex-direction:column;gap:14px;">

        <!-- Risk Distribution Chart -->
        <div class="an-card">
          <div class="an-card-hdr">
            <h4><i class="fa fa-pie-chart" style="color:#8b5cf6;"></i> Risk Distribution</h4>
          </div>
          <div class="chart-wrap">
            <?php if($total > 0): ?>
            <div style="position:relative;max-width:200px;margin:0 auto;">
              <canvas id="riskChart" height="150"></canvas>
            </div>
            <?php else: ?>
            <div class="an-empty"><i class="fa fa-pie-chart"></i><p>No student data yet.</p></div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Compact Scoring Guide -->
        <div class="an-card">
          <div class="an-card-hdr">
            <h4><i class="fa fa-info-circle" style="color:#1792bb;"></i> Evaluation Weights</h4>
          </div>
          <div style="padding:12px 14px;">
            <?php
            $factors = [
              ['Quiz Performance','30 pts','Penalty applied if quiz avg < 60%'],
              ['Assignment Grades','25 pts','Penalty applied if assignment avg < 60%'],
              ['Missed Deadlines','20 pts','5 pts penalty per past-due task unsubmitted'],
              ['Virtual Attendance','15 pts','Penalty applied if attendance < 50%'],
              ['Late Submissions','10 pts','Penalty applied if > 50% submissions late'],
            ];
            foreach($factors as $f): ?>
            <div class="guide-row">
              <div class="guide-icon"><i class="fa fa-check"></i></div>
              <div>
                <div class="guide-title"><?php echo $f[0]; ?> <span class="guide-pts"><?php echo $f[1]; ?></span></div>
                <div class="guide-desc"><?php echo $f[2]; ?></div>
              </div>
            </div>
            <?php endforeach; ?>

            <div style="border-top:1px solid #f1f5f9;padding-top:10px;margin-top:8px;">
              <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">Risk Tiers</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px;">
                <div style="display:flex;align-items:center;gap:5px;font-size:10.5px;">
                  <span style="width:8px;height:8px;border-radius:50%;background:#10b981;"></span>
                  <span><strong>0–30:</strong> On Track</span>
                </div>
                <div style="display:flex;align-items:center;gap:5px;font-size:10.5px;">
                  <span style="width:8px;height:8px;border-radius:50%;background:#f59e0b;"></span>
                  <span><strong>31–55:</strong> Attention</span>
                </div>
                <div style="display:flex;align-items:center;gap:5px;font-size:10.5px;">
                  <span style="width:8px;height:8px;border-radius:50%;background:#f97316;"></span>
                  <span><strong>56–75:</strong> At Risk</span>
                </div>
                <div style="display:flex;align-items:center;gap:5px;font-size:10.5px;">
                  <span style="width:8px;height:8px;border-radius:50%;background:#ef4444;"></span>
                  <span><strong>76–100:</strong> High Risk</span>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- Topic Performance Analytics Section -->
    <div class="topic-section-hdr">
      <h3><i class="fa fa-lightbulb-o" style="color:#7c3aed;"></i> Topic Performance Analytics</h3>
      <span style="font-size:11.5px;color:#64748b;">Class topic difficulty &amp; individual student weaknesses</span>
    </div>

    <div class="topic-grid">
      <!-- Hardest Topics Class-wide -->
      <div class="an-card">
        <div class="an-card-hdr">
          <h4><i class="fa fa-fire" style="color:#ef4444;"></i> Hardest Topics (Class-wide)</h4>
        </div>
        <div id="classTopicsArea" style="padding:12px 14px;">
          <div style="text-align:center;padding:24px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
        </div>
      </div>

      <!-- Student Weak Topics & Recommendations -->
      <div class="an-card">
        <div class="an-card-hdr">
          <h4><i class="fa fa-user-circle" style="color:#3b82f6;"></i> Student Weak Topics &amp; Recommendations</h4>
        </div>
        <div id="studentTopicsArea" style="padding:12px 14px;">
          <div style="text-align:center;padding:24px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
        </div>
      </div>
    </div>

    <?php endif; ?>

  </div>
  <footer class="an-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<script src="/cenlearn/system/bower_components/jquery/dist/jquery.min.js"></script>
<script src="/cenlearn/system/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
<?php if($total > 0): ?>
(function(){
  var ctx = document.getElementById('riskChart');
  if(ctx){
    new Chart(ctx.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: ['On Track','Needs Attention','At Risk','High Risk'],
        datasets:[{
          data: [<?php echo $onTrack; ?>, <?php echo $attention; ?>, <?php echo $atRisk; ?>, <?php echo $highRisk; ?>],
          backgroundColor: ['#10b981','#f59e0b','#f97316','#ef4444'],
          borderWidth: 2,
          borderColor: '#fff',
          hoverOffset: 4
        }]
      },
      options: {
        cutout: '72%',
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
          legend: { position: 'bottom', labels: { font: { family: 'Inter', size: 10.5 }, padding: 10, usePointStyle: true, boxWidth: 7 } },
          tooltip: { callbacks: { label: function(c){ return ' '+c.label+': '+c.raw+' student'+(c.raw!==1?'s':''); } } }
        }
      }
    });
  }
})();
<?php endif; ?>

var currentRiskFilter = 'all';

function setRiskFilter(level, btn){
  currentRiskFilter = level;
  $('.rf-pill').removeClass('active');
  $(btn).addClass('active');
  filterStudents();
}

function filterStudents(){
  var query = ($('#stuSearch').val() || '').toLowerCase().trim();
  var rows = $('.stu-row');
  var visibleCount = 0;

  rows.each(function(){
    var name = $(this).data('name') || '';
    var code = $(this).data('code') || '';
    var level = $(this).data('level') || '';
    var idx = $(this).find('.detail-btn').attr('onclick').match(/\d+/)[0];

    var matchQuery = (name.indexOf(query) !== -1 || code.indexOf(query) !== -1);
    var matchLevel = (currentRiskFilter === 'all' || level === currentRiskFilter);

    if(matchQuery && matchLevel){
      $(this).show();
      visibleCount++;
    } else {
      $(this).hide();
      $('#bd-' + idx).hide();
      $('#chevron-' + idx).removeClass('fa-chevron-up').addClass('fa-chevron-down');
    }
  });

  if(visibleCount === 0){
    $('#noMatchMsg').show();
  } else {
    $('#noMatchMsg').hide();
  }
}

function toggleBreakdown(idx){
  var row = document.getElementById('bd-'+idx);
  var chev = document.getElementById('chevron-'+idx);
  if(row){
    var open = row.style.display !== 'none';
    row.style.display = open ? 'none' : 'table-row';
    if(chev) chev.className = open ? 'fa fa-chevron-down' : 'fa fa-chevron-up';
  }
}

function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }

<?php if($selectedId): ?>
$(document).ready(function(){
  $.get('/cenlearn/shared/topic_analytics_handler', { action:'get_class_analytics', class_id:<?php echo $selectedId; ?> }, function(r){
    if(!r.success) {
      $('#classTopicsArea').html('<p style="color:#ef4444;font-size:12px;">'+r.msg+'</p>');
      $('#studentTopicsArea').html('<p style="color:#ef4444;font-size:12px;">'+r.msg+'</p>');
      return;
    }

    // Class topic difficulty table
    if(r.topic_difficulty && r.topic_difficulty.length) {
      var html = '<div class="table-responsive-wrap"><table class="stu-table" style="min-width:400px;"><thead><tr><th>Topic</th><th>Avg Mastery</th><th>Attempts</th><th>Difficulty</th></tr></thead><tbody>';
      r.topic_difficulty.forEach(function(t){
        var clr = t.avg_score_pct < 50 ? '#ef4444' : (t.avg_score_pct < 75 ? '#f59e0b' : '#10b981');
        var bg  = t.avg_score_pct < 50 ? '#fee2e2' : (t.avg_score_pct < 75 ? '#fef3c7' : '#dcfce7');
        html += '<tr><td><strong>'+t.topic+'</strong></td><td><span style="font-weight:700;color:'+clr+';">'+t.avg_score_pct+'%</span></td><td>'+t.total_attempts+'</td><td><span style="background:'+bg+';color:'+clr+';padding:2px 7px;border-radius:99px;font-size:10px;font-weight:700;">'+t.difficulty_level+'</span></td></tr>';
      });
      html += '</tbody></table></div>';
      $('#classTopicsArea').html(html);
    } else {
      $('#classTopicsArea').html('<div class="an-empty" style="padding:20px;"><i class="fa fa-inbox"></i><p>No topic data recorded yet. Topic performance is tracked when students take quizzes with topic tags.</p></div>');
    }

    // Student weak topics table with International Standard Tracking
    if(r.student_weak_topics && r.student_weak_topics.length) {
      var html = '<div class="table-responsive-wrap"><table class="stu-table" style="min-width:540px;"><thead><tr><th>Student</th><th>Weak Topic &amp; Context</th><th>Mastery &amp; Standard</th><th>Recommended Remedial Action</th></tr></thead><tbody>';
      r.student_weak_topics.forEach(function(t){
        var badge = t.risk_level === 'critical' ? '<span style="background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:99px;font-size:9.5px;font-weight:700;">🔴 Critical</span>' : (t.risk_level === 'warning' ? '<span style="background:#ffedd5;color:#9a3412;padding:2px 6px;border-radius:99px;font-size:9.5px;font-weight:700;">🟡 Review</span>' : '<span style="background:#dcfce7;color:#166534;padding:2px 6px;border-radius:99px;font-size:9.5px;font-weight:700;">🟢 Good</span>');
        var color = t.risk_level === 'critical' ? '#ef4444' : (t.risk_level === 'warning' ? '#f97316' : '#10b981');
        
        var modBadge = '';
        if(t.matched_modules && t.matched_modules.length > 0) {
          modBadge = '<div style="margin-top:3px;"><a href="../shared/module_view?id=' + t.matched_modules[0].id + '" target="_blank" style="background:#eff6ff;color:#1d4ed8;padding:2px 6px;border-radius:5px;font-size:10px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:3px;"><i class="fa fa-book"></i> Module: ' + t.matched_modules[0].title + '</a></div>';
        }
        
        var quizBadge = '';
        if(t.quiz_context && t.quiz_context.length > 0) {
          var q = t.quiz_context[0];
          quizBadge = '<div style="margin-top:2px;"><span style="background:#fffbeb;color:#92400e;border:1px solid #fde68a;padding:1px 5px;border-radius:4px;font-size:9.5px;font-weight:600;"><i class="fa fa-question-circle"></i> ' + q.quiz_title + ' (' + q.earned + '/' + q.total + ' pts)</span></div>';
        }

        var stdBadge = '';
        if(t.standard_ref) {
          stdBadge = '<div style="margin-top:3px;"><span style="background:#ede9fe;color:#5b21b6;padding:1px 5px;border-radius:4px;font-size:9.5px;font-weight:700;" title="' + (t.standard_ref.standard_code || '') + '"><i class="fa fa-globe"></i> ' + (t.standard_ref.bloom_level ? t.standard_ref.bloom_level.split(':')[0] : "Bloom's Ref") + '</span></div>';
        }

        html += '<tr>' +
                '<td><strong>'+t.student_name+'</strong><div class="stu-id">'+t.student_code+'</div></td>' +
                '<td><strong>'+t.topic+'</strong>' + modBadge + quizBadge + '</td>' +
                '<td><span style="font-size:12.5px;font-weight:800;color:'+color+';">'+(t.mastery_score !== undefined ? t.mastery_score : (100 - t.weakness_score))+'%</span><br>' + badge + stdBadge + '</td>' +
                '<td style="font-size:11px;color:#334155;line-height:1.4;">'+ (t.recommendation || 'Assign practice exercises') +'</td>' +
                '</tr>';
      });
      html += '</tbody></table></div>';
      $('#studentTopicsArea').html(html);
    } else {
      $('#studentTopicsArea').html('<div class="an-empty" style="padding:20px;"><i class="fa fa-inbox"></i><p>No student weak topics identified yet.</p></div>');
    }
  },'json');
});
<?php endif; ?>
</script>
</body>
</html>
