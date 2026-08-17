<?php
include '../includes/session.php';
include '../includes/conn.php';

if(strtoupper($user['user_group']) !== 'TEACHER'){
    header('location: ../index.php'); exit;
}

$tc = $conn->real_escape_string($user['user_code']);
$initials = strtoupper(substr($user['first_name'] ?? 'T', 0, 1) . substr($user['last_name'] ?? 'U', 0, 1));

// Fetch teacher's active classes
$classesQ = $conn->query("
    SELECT id, class_name, subject, section, program_code
    FROM classes
    WHERE teacher_code = '$tc' AND (is_archived = 0 OR is_archived IS NULL)
    ORDER BY class_name ASC
");
$classes = [];
while($r = $classesQ->fetch_assoc()) $classes[] = $r;

$classFilter = intval($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));

// Ensure auto-sync runs for current selected class
if($classFilter > 0) {
    // Include sync function
    require_once '../shared/syllabus_handler.php';
    syncClassMaterialsToSyllabus($conn, $classFilter, $tc);
}

// Fetch syllabus items for selected class
$syllabusItems = [];
$topics = [];
$totalItems = 0;
$totalPublished = 0;

if($classFilter > 0) {
    $res = $conn->query("
        SELECT s.*, c.class_name, c.subject
        FROM class_syllabus s
        JOIN classes c ON s.class_id = c.id
        WHERE s.class_id = $classFilter
        ORDER BY s.topic ASC, s.created_at DESC
    ");
    while($r = $res->fetch_assoc()) {
        $syllabusItems[] = $r;
        $t = trim($r['topic'] ?: 'General Materials');
        if(!isset($topics[$t])) $topics[$t] = [];
        $topics[$t][] = $r;
        $totalItems++;
        if($r['is_sent'] == 1) $totalPublished++;
    }
}

$activeClassObj = null;
foreach($classes as $c) {
    if($c['id'] === $classFilter) { $activeClassObj = $c; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn &mdash; Course Syllabus & Materials Dashboard</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; overflow-x: hidden; }
    body { font-family: 'Inter', sans-serif; background: #f0f4f8; color: #1e293b; }

    /* ── Sidebar Styling ── */
    .td-sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: linear-gradient(180deg, #0c1a2e 0%, #0f2d4a 55%, #0f5f80 100%); display: flex; flex-direction: column; z-index: 200; transition: transform .3s cubic-bezier(.4,0,.2,1); transform: translateX(-260px); }
    .td-sidebar.open { transform: translateX(0); }
    @media(min-width: 901px) { .td-sidebar { transform: translateX(0); } }
    .sb-brand { padding: 24px 20px 16px; border-bottom: 1px solid rgba(255,255,255,.08); }
    .sb-logo { width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, #1792bb, #0f5f80); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 8px; box-shadow: 0 4px 12px rgba(23,146,187,.4); }
    .sb-logo i { color: #fff; font-size: 18px; }
    .sb-brand h2 { color: #fff; font-size: 18px; font-weight: 800; margin: 0; }
    .sb-brand h2 span { color: #38bdf8; }
    .sb-brand p { color: rgba(255,255,255,.35); font-size: 10px; margin: 2px 0 0; }
    .sb-nav { flex: 1; padding: 14px 0; overflow-y: auto; }
    .sb-section { padding: 8px 20px 4px; font-size: 9px; font-weight: 700; color: rgba(255,255,255,.25); letter-spacing: 1.4px; text-transform: uppercase; }
    .sb-nav ul { list-style: none; margin: 0; padding: 0; }
    .sb-nav li a { display: flex; align-items: center; gap: 11px; padding: 10px 20px; color: rgba(255,255,255,.6); text-decoration: none; font-size: 13px; font-weight: 500; transition: all .2s; border-left: 3px solid transparent; }
    .sb-nav li a:hover, .sb-nav li.active a { color: #fff; background: rgba(255,255,255,.07); border-left-color: #38bdf8; }
    .sb-footer { padding: 14px 20px; border-top: 1px solid rgba(255,255,255,.08); background: rgba(0,0,0,.15); }
    .sb-user { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .sb-av { width: 34px; height: 34px; border-radius: 50%; background: #38bdf8; color: #0c1a2e; font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: 12px; }
    .sb-meta strong { display: block; color: #fff; font-size: 12px; }
    .sb-meta span { color: rgba(255,255,255,.4); font-size: 10px; }
    .sb-out { display: block; width: 100%; text-align: center; padding: 7px; border-radius: 6px; background: rgba(239,68,68,.15); color: #fca5a5; font-size: 11px; text-decoration: none; font-weight: 600; border: 1px solid rgba(239,68,68,.3); }
    .sb-out:hover { background: #ef4444; color: #fff; text-decoration: none; }

    /* ── Main Layout ── */
    .td-main { margin-left: 0; min-height: 100vh; display: flex; flex-direction: column; transition: margin-left .3s; }
    @media(min-width: 901px) { .td-main { margin-left: 260px; } }

    .td-topbar { height: 60px; background: #fff; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; padding: 0 24px; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 3px rgba(0,0,0,.03); }
    .td-topbar-title h3 { margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; }
    .td-topbar-title p { margin: 0; font-size: 11px; color: #64748b; }
    .cl-hamburger { background: none; border: none; font-size: 18px; color: #334155; cursor: pointer; display: none; }
    @media(max-width: 900px) { .cl-hamburger { display: inline-block; } }

    .td-content { padding: 24px; flex: 1; }

    /* ── Banner ── */
    .syl-banner { background: linear-gradient(135deg, #0c1a2e 0%, #0f4c75 50%, #0f5f80 100%); border-radius: 18px; padding: 26px 30px; color: #fff; margin-bottom: 24px; box-shadow: 0 10px 25px -5px rgba(15,95,128,.3); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; position: relative; overflow: hidden; }
    .syl-banner-info h2 { font-size: 24px; font-weight: 800; margin: 0 0 6px; display: flex; align-items: center; gap: 10px; }
    .syl-banner-info p { margin: 0; color: rgba(255,255,255,.78); font-size: 13px; max-width: 580px; line-height: 1.5; }

    .btn-sync-syl { background: #38bdf8; color: #0c1a2e; border: none; padding: 12px 20px; border-radius: 12px; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; transition: all .2s; box-shadow: 0 4px 14px rgba(56,189,248,.35); }
    .btn-sync-syl:hover { background: #7dd3fc; transform: translateY(-2px); }

    /* ── Stat Cards ── */
    .syl-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
    .syl-stat-card { background: #fff; border-radius: 14px; padding: 18px 20px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,.03); display: flex; align-items: center; gap: 16px; }
    .stat-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
    .stat-blue { background: #e0f2fe; color: #0284c7; }
    .stat-purple { background: #f3e8ff; color: #9333ea; }
    .stat-green { background: #dcfce7; color: #16a34a; }
    .stat-amber { background: #fef3c7; color: #d97706; }
    .stat-meta strong { display: block; font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1.1; }
    .stat-meta span { font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }

    /* ── Class Controls ── */
    .syl-controls { background: #fff; border-radius: 14px; padding: 16px 20px; border: 1px solid #e2e8f0; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; }
    .syl-select { padding: 9px 14px; border: 1.5px solid #cbd5e1; border-radius: 9px; font-size: 13px; font-family: 'Inter', sans-serif; background: #f8fafc; color: #334155; outline: none; transition: border-color .2s; min-width: 260px; }
    .syl-select:focus { border-color: #0284c7; background: #fff; }

    /* ── Topic Cards & Deduplicated Material List ── */
    .syl-topic-box { background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.03); }
    .syl-topic-head { padding: 16px 22px; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; }
    .syl-topic-title { font-size: 15px; font-weight: 700; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 10px; }
    .syl-topic-count { font-size: 11px; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 2px 9px; border-radius: 99px; }

    .syl-file-list { padding: 0; margin: 0; list-style: none; }
    .syl-file-item { display: flex; align-items: center; justify-content: space-between; padding: 14px 22px; border-bottom: 1px solid #f1f5f9; transition: background .15s; }
    .syl-file-item:last-child { border-bottom: none; }
    .syl-file-item:hover { background: #f8fafc; }

    .syl-file-info { display: flex; align-items: center; gap: 14px; flex: 1; min-width: 0; }
    .syl-file-icon { width: 40px; height: 40px; border-radius: 10px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .syl-file-meta strong { display: block; font-size: 13px; font-weight: 700; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 480px; }
    .syl-file-meta span { font-size: 11px; color: #64748b; }

    .syl-file-actions { display: flex; align-items: center; gap: 10px; }

    /* Switch */
    .syl-switch { position: relative; display: inline-block; width: 34px; height: 18px; }
    .syl-switch input { opacity: 0; width: 0; height: 0; }
    .syl-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 20px; }
    .syl-slider:before { position: absolute; content: ""; height: 12px; width: 12px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
    input:checked + .syl-slider { background-color: #10b981; }
    input:checked + .syl-slider:before { transform: translateX(16px); }

    .btn-download-syl { padding: 6px 14px; background: #f0f9ff; color: #0284c7; border: 1px solid #bae6fd; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; transition: all .15s; }
    .btn-download-syl:hover { background: #0284c7; color: #fff; border-color: #0284c7; text-decoration: none; }
    .btn-delete-syl { width: 30px; height: 30px; border-radius: 7px; border: 1px solid #fee2e2; background: #fff; color: #ef4444; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all .15s; }
    .btn-delete-syl:hover { background: #ef4444; color: #fff; }

    .syl-empty { text-align: center; padding: 60px 20px; background: #fff; border-radius: 16px; border: 1.5px dashed #cbd5e1; }
    .syl-empty i { font-size: 48px; color: #cbd5e1; margin-bottom: 14px; }
    .syl-empty h4 { font-size: 16px; font-weight: 700; color: #334155; margin: 0 0 6px; }
    .syl-empty p { font-size: 13px; color: #64748b; margin: 0 0 16px; }
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
      <li><a href="quizzes.php"><i class="fa fa-question-circle"></i> Quizzes</a></li>
      <li><a href="assignments.php"><i class="fa fa-tasks"></i> Assignments</a></li>
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
        <h3>Class Syllabus Dashboard</h3>
        <p><?php echo date('l, F j, Y'); ?></p>
      </div>
    </div>
    <div>
      <button class="btn-sync-syl" onclick="triggerAutoSync()"><i class="fa fa-refresh"></i> Auto-Sync Materials</button>
    </div>
  </header>

  <div class="td-content">
    <!-- Header Banner -->
    <div class="syl-banner">
      <div class="syl-banner-info">
        <h2><i class="fa fa-file-text-o"></i> Course Syllabus & Materials Hub</h2>
        <p>Class learning materials and uploaded modules automatically display on the class syllabus outline. Strict deduplication ensures duplicate files are never repeated or cluttering your course syllabus.</p>
      </div>
      <div>
        <button class="btn-sync-syl" onclick="triggerAutoSync()"><i class="fa fa-bolt"></i> Auto-Sync Now</button>
      </div>
    </div>

    <!-- Stat Cards -->
    <div class="syl-stats-grid">
      <div class="syl-stat-card">
        <div class="stat-icon stat-blue"><i class="fa fa-folder-open"></i></div>
        <div class="stat-meta">
          <strong><?php echo count($topics); ?></strong>
          <span>Syllabus Topics</span>
        </div>
      </div>
      <div class="syl-stat-card">
        <div class="stat-icon stat-purple"><i class="fa fa-file-text"></i></div>
        <div class="stat-meta">
          <strong><?php echo $totalItems; ?></strong>
          <span>Total Materials</span>
        </div>
      </div>
      <div class="syl-stat-card">
        <div class="stat-icon stat-green"><i class="fa fa-check-circle"></i></div>
        <div class="stat-meta">
          <strong><?php echo $totalPublished; ?></strong>
          <span>Published Items</span>
        </div>
      </div>
      <div class="syl-stat-card">
        <div class="stat-icon stat-amber"><i class="fa fa-shield"></i></div>
        <div class="stat-meta">
          <strong>Clean</strong>
          <span>Deduplication Active</span>
        </div>
      </div>
    </div>

    <!-- Controls -->
    <div class="syl-controls">
      <div style="display:flex;align-items:center;gap:12px;">
        <label style="margin:0;font-weight:700;font-size:13px;color:#334155;">Select Class:</label>
        <select class="syl-select" onchange="changeClass(this.value)">
          <?php foreach($classes as $c): ?>
            <option value="<?php echo $c['id']; ?>" <?php echo $c['id'] === $classFilter ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($c['class_name'].' - '.$c['subject']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if($activeClassObj): ?>
        <div style="font-size:12px;color:#64748b;font-weight:600;">
          Subject: <strong style="color:#0f172a;"><?php echo htmlspecialchars($activeClassObj['subject']); ?></strong>
        </div>
      <?php endif; ?>
    </div>

    <!-- Syllabus Content Accordion -->
    <?php if(empty($topics)): ?>
      <div class="syl-empty">
        <i class="fa fa-inbox"></i>
        <h4>No Syllabus Materials Found</h4>
        <p>Upload materials to your class modules or click Auto-Sync below to populate the syllabus automatically without duplicates.</p>
        <button class="btn-sync-syl" onclick="triggerAutoSync()"><i class="fa fa-refresh"></i> Run Auto-Sync Now</button>
      </div>
    <?php else: ?>
      <?php foreach($topics as $topicName => $items): ?>
        <div class="syl-topic-box">
          <div class="syl-topic-head">
            <h4 class="syl-topic-title">
              <i class="fa fa-bookmark" style="color:#0284c7;"></i>
              <?php echo htmlspecialchars($topicName); ?>
            </h4>
            <span class="syl-topic-count"><?php echo count($items); ?> Material<?php echo count($items)!==1?'s':''; ?></span>
          </div>

          <ul class="syl-file-list">
            <?php foreach($items as $item):
              $ext = strtolower(pathinfo($item['original_name'], PATHINFO_EXTENSION));
              $iconClass = 'fa-file-o';
              if(in_array($ext, ['pdf'])) $iconClass = 'fa-file-pdf-o';
              elseif(in_array($ext, ['doc','docx'])) $iconClass = 'fa-file-word-o';
              elseif(in_array($ext, ['ppt','pptx'])) $iconClass = 'fa-file-powerpoint-o';
              elseif(in_array($ext, ['xls','xlsx'])) $iconClass = 'fa-file-excel-o';
              elseif(in_array($ext, ['png','jpg','jpeg'])) $iconClass = 'fa-file-image-o';
              elseif(in_array($ext, ['zip'])) $iconClass = 'fa-file-archive-o';

              $sizeKb = $item['file_size'] ? round($item['file_size'] / 1024, 1).' KB' : 'N/A';
            ?>
              <li class="syl-file-item">
                <div class="syl-file-info">
                  <div class="syl-file-icon"><i class="fa <?php echo $iconClass; ?>"></i></div>
                  <div class="syl-file-meta">
                    <strong><?php echo htmlspecialchars($item['title']); ?></strong>
                    <span>File: <?php echo htmlspecialchars($item['original_name']); ?> &bull; <?php echo $sizeKb; ?> &bull; Added: <?php echo date('M d, Y', strtotime($item['created_at'])); ?></span>
                  </div>
                </div>

                <div class="syl-file-actions">
                  <label style="display:flex;align-items:center;gap:6px;margin:0;cursor:pointer;" title="Publish to Students">
                    <span class="syl-switch">
                      <input type="checkbox" <?php echo $item['is_sent'] == 1 ? 'checked' : ''; ?> onchange="togglePublish(<?php echo $item['id']; ?>, this.checked)">
                      <span class="syl-slider"></span>
                    </span>
                    <span style="font-size:11px;font-weight:600;color:<?php echo $item['is_sent']==1?'#10b981':'#64748b'; ?>;">
                      <?php echo $item['is_sent']==1?'Published':'Hidden'; ?>
                    </span>
                  </label>

                  <a href="../uploads/modules/<?php echo urlencode($item['filename']); ?>" target="_blank" download="<?php echo htmlspecialchars($item['original_name']); ?>" class="btn-download-syl">
                    <i class="fa fa-download"></i> Download
                  </a>

                  <button type="button" class="btn-delete-syl" title="Remove Item" onclick="deleteSyllabusItem(<?php echo $item['id']; ?>)">
                    <i class="fa fa-trash-o"></i>
                  </button>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  </div>
</div>

<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script src="../bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
function toggleSidebar() {
  $('#sidebar').toggleClass('open');
}

function changeClass(cid) {
  window.location.href = `syllabus.php?class_id=${cid}`;
}

function triggerAutoSync() {
  const cid = '<?php echo $classFilter; ?>';
  if(!cid || cid == '0') {
    alert('Please select a valid class first.');
    return;
  }

  $.post('../shared/syllabus_handler.php', { action: 'auto_sync', class_id: cid }, function(res) {
    if(res.success) {
      alert(res.msg);
      location.reload();
    } else {
      alert(res.msg || 'Auto-sync failed.');
    }
  }, 'json');
}

function togglePublish(id, isSent) {
  $.post('../shared/syllabus_handler.php', {
    action: 'toggle_sent',
    id: id,
    is_sent: isSent ? 1 : 0
  }, function(res) {
    if(!res.success) {
      alert(res.msg || 'Failed to update publish status.');
    }
  }, 'json');
}

function deleteSyllabusItem(id) {
  if(!confirm('Remove this material from class syllabus?')) return;

  $.post('../shared/syllabus_handler.php', { action: 'delete', id: id }, function(res) {
    if(res.success) {
      location.reload();
    } else {
      alert(res.msg || 'Failed to remove item.');
    }
  }, 'json');
}
</script>
</body>
</html>
