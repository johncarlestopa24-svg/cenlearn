<?php
include '../includes/session.php';
include '../includes/conn.php';
include '../includes/programs.php';

if(strtoupper($user['user_group']) !== 'TEACHER'){
    header('location: dashboard.php'); exit;
}

$tc = $conn->real_escape_string($user['user_code']);
$initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));

// Fetch all active classes for this teacher with student counts
$classesQ = $conn->query("
    SELECT c.*,
           COALESCE(
             (SELECT class_code FROM classes s WHERE (s.class_name = c.class_name OR s.subject = c.class_name) AND s.teacher_code = c.teacher_code AND s.is_subject_only = 1 LIMIT 1),
             c.class_code
           ) AS display_code,
           COUNT(DISTINCT CASE WHEN u.user_group='STUDENT' THEN cm.user_code END) AS student_count
    FROM classes c
    LEFT JOIN class_members cm ON c.id = cm.class_id
    LEFT JOIN users u ON cm.user_code = u.user_code
    WHERE c.teacher_code = '$tc' AND (c.is_archived = 0 OR c.is_archived IS NULL) AND (c.is_subject_only = 0 OR c.is_subject_only IS NULL)
    GROUP BY c.id ORDER BY c.created_at DESC
");
$classRows = [];
while($r = $classesQ->fetch_assoc()) $classRows[] = $r;
$totalUniqueStudents = (int)($conn->query("SELECT COUNT(DISTINCT cm.user_code) AS c FROM class_members cm JOIN classes c ON cm.class_id=c.id JOIN users u ON cm.user_code=u.user_code WHERE c.teacher_code='$tc' AND u.user_group='STUDENT' AND (c.is_archived=0 OR c.is_archived IS NULL) AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)")->fetch_assoc()['c'] ?? 0);

// For each class, get a summary of graded entries
$classSummary = [];
foreach($classRows as $c) {
    $cid = (int)$c['id'];
    $colCount   = $conn->query("SELECT COUNT(*) AS n FROM class_record_columns WHERE class_id=$cid")->fetch_assoc()['n'];
    $scoredCount= $conn->query("SELECT COUNT(DISTINCT s.student_code) AS n FROM class_record_scores s JOIN class_record_columns col ON s.column_id=col.id WHERE col.class_id=$cid AND s.score IS NOT NULL")->fetch_assoc()['n'];
    $classSummary[$cid] = ['columns' => (int)$colCount, 'scored_students' => (int)$scoredCount];
}

$palette = [
    'linear-gradient(135deg,#10b981,#059669)',
    'linear-gradient(135deg,#1792bb,#0f5f80)',
    'linear-gradient(135deg,#8b5cf6,#6d28d9)',
    'linear-gradient(135deg,#f59e0b,#d97706)',
    'linear-gradient(135deg,#ef4444,#dc2626)',
    'linear-gradient(135deg,#06b6d4,#0891b2)',
    'linear-gradient(135deg,#ec4899,#db2777)',
];
$icons = ['fa-table','fa-calculator','fa-book','fa-pencil','fa-flask','fa-globe','fa-code'];

// Pending inbox badge
$pendingQ = $conn->query("
    SELECT COUNT(*) AS c FROM class_confirmations cc
    JOIN classes c ON cc.class_id = c.id
    WHERE c.teacher_code = '$tc' AND cc.status = 'pending'
      AND (c.is_archived = 0 OR c.is_archived IS NULL)
      AND (c.is_subject_only = 0 OR c.is_subject_only IS NULL)
");
$pendingCount = $pendingQ ? (int)$pendingQ->fetch_assoc()['c'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Class Record</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: 'Inter', sans-serif; background: #f0f4f8; margin: 0; color: #1e293b; }

    /* Sidebar */
    .td-sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: linear-gradient(180deg,#0a1f0f 0%,#0d3320 55%,#065f46 100%); display: flex; flex-direction: column; z-index: 200; transition: transform .3s; }
    .sb-brand { padding: 26px 22px 18px; border-bottom: 1px solid rgba(255,255,255,.08); }
    .sb-logo { width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg,#10b981,#059669); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 10px; box-shadow: 0 4px 12px rgba(16,185,129,.4); }
    .sb-logo i { color: #fff; font-size: 17px; }
    .sb-brand h2 { color: #fff; font-size: 19px; font-weight: 800; margin: 0; }
    .sb-brand h2 span { color: #34d399; }
    .sb-brand p { color: rgba(255,255,255,.35); font-size: 10px; margin: 2px 0 0; }
    .sb-nav { flex: 1; padding: 14px 0; overflow-y: auto; }
    .sb-section { padding: 8px 22px 4px; font-size: 9px; font-weight: 700; color: rgba(255,255,255,.25); letter-spacing: 1.4px; text-transform: uppercase; }
    .sb-nav ul { list-style: none; margin: 0; padding: 0; }
    .sb-nav li a { display: flex; align-items: center; gap: 11px; padding: 10px 22px; color: rgba(255,255,255,.6); text-decoration: none; font-size: 13px; font-weight: 500; transition: all .2s; border-left: 3px solid transparent; }
    .sb-nav li a:hover { background: rgba(255,255,255,.07); color: #fff; }
    .sb-nav li.active a { background: rgba(52,211,153,.12); color: #fff; border-left-color: #34d399; }
    .sb-nav li a i { width: 17px; text-align: center; font-size: 14px; }
    .sb-footer { padding: 14px 22px; border-top: 1px solid rgba(255,255,255,.07); }
    .sb-user { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .sb-av { width: 36px; height: 36px; border-radius: 9px; background: linear-gradient(135deg,#10b981,#059669); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; color: #fff; flex-shrink: 0; }
    .sb-meta strong { display: block; color: #fff; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px; }
    .sb-meta span { color: rgba(255,255,255,.4); font-size: 10px; }
    .sb-out { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 8px; width: 100%; background: rgba(255,255,255,.07); color: rgba(255,255,255,.6); border: 1px solid rgba(255,255,255,.1); border-radius: 8px; font-size: 12px; font-weight: 500; text-decoration: none; transition: background .2s; }
    .sb-out:hover { background: rgba(255,255,255,.13); color: #fff; }

    /* Main layout */
    .td-main { margin-left: 260px; min-height: 100vh; display: flex; flex-direction: column; }
    .td-topbar { background: #fff; padding: 0 28px; height: 60px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 50; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
    .td-topbar h3 { font-size: 16px; font-weight: 700; color: #0f172a; margin: 0; }
    .td-topbar p { font-size: 12px; color: #64748b; margin: 0; }
    .td-content { padding: 28px 28px 48px; flex: 1; }

    /* Page header */
    .page-hero { background: linear-gradient(135deg,#0a1f0f 0%,#10b981 100%); border-radius: 20px; padding: 28px 32px; margin-bottom: 28px; position: relative; overflow: hidden; }
    .page-hero::before { content: ''; position: absolute; inset: 0; opacity: .05; background-image: radial-gradient(circle,#fff 1.5px,transparent 1.5px); background-size: 24px 24px; pointer-events: none; }
    .page-hero-inner { position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
    .page-hero h2 { font-size: 22px; font-weight: 800; color: #fff; margin: 0 0 4px; }
    .page-hero p { font-size: 13px; color: rgba(255,255,255,.7); margin: 0; }
    .hero-stat { display: flex; gap: 16px; }
    .hstat { text-align: center; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18); border-radius: 12px; padding: 12px 20px; }
    .hstat strong { display: block; font-size: 22px; font-weight: 800; color: #fff; line-height: 1; }
    .hstat span { font-size: 10px; color: rgba(255,255,255,.65); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }

    /* Search */
    .search-wrap { position: relative; margin-bottom: 22px; }
    .search-wrap input { width: 100%; padding: 12px 16px 12px 44px; border: 1.5px solid #e2e8f0; border-radius: 12px; font-size: 13px; font-family: 'Inter',sans-serif; background: #fff; color: #1e293b; transition: all .2s; }
    .search-wrap input:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.1); }
    .search-wrap i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; }

    /* Class row list for Class Record */
    .cr-list { display: flex; flex-direction: column; gap: 10px; }
    .class-row-card {
      background: #ffffff;
      border: 1.5px solid #e2e8f0;
      border-radius: 10px;
      padding: 10px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02), 0 1px 2px rgba(0, 0, 0, 0.02);
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .class-row-card:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 18px rgba(0, 0, 0, 0.05);
      border-color: #cbd5e1;
    }
    .crc-left {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }
    .crc-icon {
      width: 36px;
      height: 36px;
      border-radius: 8px;
      background: linear-gradient(135deg, #10b981, #059669);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ffffff;
      font-size: 15px;
      flex-shrink: 0;
      box-shadow: 0 2px 6px rgba(16, 185, 129, 0.2);
    }
    .crc-info {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .crc-title {
      font-size: 14px;
      font-weight: 700;
      color: #0f172a;
      margin: 0;
      letter-spacing: -0.2px;
    }
    .badge-code-green {
      background: #dcfce7;
      color: #15803d;
      font-size: 10px;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 5px;
      font-family: monospace;
      letter-spacing: 0.5px;
      border: 1px solid #bbf7d0;
    }
    .badge-prog-blue {
      background: #e0f2fe;
      color: #0369a1;
      font-size: 10px;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 5px;
      border: 1px solid #bae6fd;
    }
    .badge-sec-purple {
      background: #f3e8ff;
      color: #7e22ce;
      font-size: 10px;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 5px;
      border: 1px solid #e9d5ff;
    }
    .crc-meta {
      font-size: 12px;
      color: #64748b;
      font-weight: 500;
    }
    .crc-actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .crc-btn {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 5px 12px;
      border-radius: 7px;
      font-size: 12px;
      font-weight: 600;
      font-family: 'Inter', sans-serif;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.15s ease-in-out;
    }
    .crc-btn.open-btn {
      border: 1.5px solid #10b981;
      background: #f0fdf4;
      color: #059669;
    }
    .crc-btn.open-btn:hover {
      background: #dcfce7;
      text-decoration: none;
    }
    .crc-btn.view-btn {
      border: 1.5px solid #cbd5e1;
      background: #f8fafc;
      color: #475569;
    }
    .crc-btn.view-btn:hover {
      background: #f1f5f9;
      color: #1e293b;
      text-decoration: none;
    }

    /* Empty */
    .empty-state { text-align: center; padding: 72px 24px; background: #fff; border-radius: 18px; border: 1px solid #e2e8f0; }
    .empty-icon { width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg,rgba(16,185,129,.08),rgba(16,185,129,.03)); border: 2px dashed rgba(16,185,129,.22); display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
    .empty-icon i { font-size: 32px; color: rgba(16,185,129,.45); }
    .empty-state h5 { font-size: 17px; font-weight: 700; color: #374151; margin: 0 0 8px; }
    .empty-state p { font-size: 13px; color: #94a3b8; margin: 0 0 20px; }

    footer.td-footer { text-align: center; padding: 14px; font-size: 11px; color: #94a3b8; border-top: 1px solid #e2e8f0; background: #fff; }

    @media(max-width: 900px) {
      .td-main { margin-left: 0; }
      .td-sidebar { transform: translateX(-100%); }
      .td-sidebar.open { transform: translateX(0); }
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
      <li><a href="dashboard.php"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li><a href="classes.php"><i class="fa fa-book"></i> Classes</a></li>
      <li><a href="quizzes.php"><i class="fa fa-question-circle"></i> Quizzes</a></li>
      <li><a href="assignments.php"><i class="fa fa-tasks"></i> Assignments</a></li>
      <li><a href="attendance.php"><i class="fa fa-calendar-check-o"></i> Attendance</a></li>
      <li><a href="logbook.php"><i class="fa fa-pencil-square-o"></i> Manage Subject</a></li>
      <li class="active"><a href="class_record.php"><i class="fa fa-table"></i> Class Record</a></li>
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
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
      <div>
        <h3>Class Record</h3>
        <p><?php echo count($classRows); ?> active class<?php echo count($classRows) !== 1 ? 'es' : ''; ?></p>
      </div>
    </div>
  </header>

  <div class="td-content">

    <!-- Hero -->
    <div class="page-hero">
      <div class="page-hero-inner">
        <div>
          <h2><i class="fa fa-table" style="font-size:20px;margin-right:10px;opacity:.85;"></i>Class Record Book</h2>
          <p>View and manage grades, scores, and performance records for all your classes.</p>
        </div>
        <div class="hero-stat">
          <div class="hstat"><strong><?php echo count($classRows); ?></strong><span>Classes</span></div>
          <div class="hstat"><strong><?php echo $totalUniqueStudents; ?></strong><span>Students</span></div>
          <div class="hstat"><strong><?php echo array_sum(array_column($classSummary,'columns')); ?></strong><span>Score Columns</span></div>
        </div>
      </div>
    </div>

    <?php if(!empty($classRows)): ?>

    <!-- Search -->
    <div class="search-wrap">
      <i class="fa fa-search"></i>
      <input type="text" id="crSearch" placeholder="Search classes by name or subject..." oninput="filterCards()">
    </div>

    <!-- Class row list -->
    <div class="cr-list" id="crGrid">
      <?php foreach($classRows as $i => $c):
        $sc     = max(0, (int)$c['student_count']);
        $cid    = (int)$c['id'];
        $sum    = $classSummary[$cid];
        $hasData = $sum['columns'] > 0;
        $displayCode = !empty($c['display_code']) ? $c['display_code'] : (!empty($c['class_code']) ? $c['class_code'] : '');
      ?>
      <div class="class-row-card" data-name="<?php echo strtolower(htmlspecialchars($c['class_name'].' '.$c['subject'].' '.$displayCode.' '.$c['program_code'])); ?>">
        <div class="crc-left">
          <div class="crc-icon">
            <i class="fa fa-table"></i>
          </div>
          <div class="crc-info">
            <h5 class="crc-title"><?php echo htmlspecialchars($c['class_name']); ?></h5>
            <?php if(!empty($displayCode)): ?>
              <span class="badge-code-green"><?php echo htmlspecialchars($displayCode); ?></span>
            <?php endif; ?>
            <?php if(!empty($c['program_code'])): ?>
              <span class="badge-prog-blue"><?php echo htmlspecialchars($c['program_code']); ?></span>
            <?php endif; ?>
            <?php if(!empty($c['year_level']) || !empty($c['section'])): ?>
              <span class="badge-sec-purple">
                <?php echo !empty($c['year_level']) ? 'Yr '.$c['year_level'] : ''; ?>
                <?php echo (!empty($c['year_level']) && !empty($c['section'])) ? ' &bull; ' : ''; ?>
                <?php echo !empty($c['section']) ? 'Sec '.htmlspecialchars($c['section']) : ''; ?>
              </span>
            <?php endif; ?>
            <span class="crc-meta">
              &bull; <?php echo $sc; ?> student<?php echo $sc!==1?'s':''; ?>
              &bull; <?php echo $hasData ? $sum['columns'].' col'.($sum['columns']!==1?'s':'') : 'No entries'; ?>
            </span>
          </div>
        </div>

        <div class="crc-actions">
          <a href="../shared/class_record_detail.php?id=<?php echo $cid; ?>" class="crc-btn open-btn">
            <i class="fa fa-table"></i> Open Record
          </a>
          <a href="../shared/class_view.php?id=<?php echo $cid; ?>" class="crc-btn view-btn">
            <i class="fa fa-folder-open"></i> Class
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon"><i class="fa fa-table"></i></div>
      <h5>No active classes</h5>
      <p>Create a class first before accessing class records.</p>
      <a href="classes.php" style="display:inline-flex;align-items:center;gap:7px;padding:10px 20px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border-radius:10px;font-size:13px;font-weight:600;text-decoration:none;">
        <i class="fa fa-book"></i> Go to My Classes
      </a>
    </div>
    <?php endif; ?>

  </div>
  <footer class="td-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<?php include '../includes/scripts.php'; ?>
<script>
function filterCards() {
  var q = document.getElementById('crSearch').value.toLowerCase();
  document.querySelectorAll('#crGrid .class-row-card').forEach(function(card) {
    card.style.display = card.dataset.name.includes(q) ? '' : 'none';
  });
}
function openSidebar() {
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sidebarOverlay').classList.add('active');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('active');
}
</script>
</body>
</html>
