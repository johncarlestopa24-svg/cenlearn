<?php
include '../includes/session.php';
include '../includes/conn.php';

if(strtoupper($user['user_group']) !== 'TEACHER'){
    header('location: ../index.php'); exit;
}

header('location: quizzes.php?create=1');
exit;

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn &mdash; Excel Auto-Paste Quiz Creator</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    :root {
      --bg-main: #f0f4f8;
      --bg-card: #ffffff;
      --text-main: #1e293b;
      --text-muted: #64748b;
      --border-color: #e2e8f0;
      --primary: #0284c7;
      --primary-hover: #0369a1;
      --primary-light: #e0f2fe;
      --shadow-sm: 0 1px 3px rgba(0,0,0,.03);
      --shadow-md: 0 4px 14px rgba(0,0,0,.06);
      --shadow-lg: 0 12px 24px -6px rgba(0,0,0,.09);
    }

    body.dark-mode {
      --bg-main: #0f172a;
      --bg-card: #1e293b;
      --text-main: #f8fafc;
      --text-muted: #94a3b8;
      --border-color: #334155;
      --primary: #38bdf8;
      --primary-hover: #7dd3fc;
      --primary-light: rgba(56,189,248,.15);
      --shadow-sm: 0 1px 3px rgba(0,0,0,.2);
      --shadow-md: 0 4px 14px rgba(0,0,0,.3);
      --shadow-lg: 0 12px 24px rgba(0,0,0,.4);
    }

    *, *::before, *::after { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; overflow-x: hidden; }
    body { font-family: 'Inter', sans-serif; background: var(--bg-main); color: var(--text-main); transition: background .2s, color .2s; }

    /* ── Sidebar ── */
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

    .td-topbar { height: 60px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 24px; position: sticky; top: 0; z-index: 100; box-shadow: var(--shadow-sm); }
    .td-topbar-title h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--text-main); }
    .td-topbar-title p { margin: 0; font-size: 11px; color: var(--text-muted); }
    .cl-hamburger { background: none; border: none; font-size: 18px; color: var(--text-main); cursor: pointer; display: none; }
    @media(max-width: 900px) { .cl-hamburger { display: inline-block; } }

    .td-content { padding: 24px; flex: 1; }

    /* ── Banner ── */
    .qc-banner { background: linear-gradient(135deg, #0c1a2e 0%, #0f4c75 50%, #0284c7 100%); border-radius: 18px; padding: 26px 30px; color: #fff; margin-bottom: 24px; box-shadow: 0 10px 25px -5px rgba(2,132,199,.3); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
    .qc-banner-info h2 { font-size: 22px; font-weight: 800; margin: 0 0 6px; display: flex; align-items: center; gap: 10px; }
    .qc-banner-info p { margin: 0; color: rgba(255,255,255,.8); font-size: 13px; max-width: 600px; line-height: 1.5; }

    .btn-theme-toggle { background: rgba(255,255,255,.15); color: #fff; border: 1px solid rgba(255,255,255,.25); padding: 8px 16px; border-radius: 10px; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all .2s; }
    .btn-theme-toggle:hover { background: rgba(255,255,255,.3); }

    /* ── Modern Auto-Paste UI (Figma-Style) ── */
    .import-card { background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color); padding: 22px 24px; margin-bottom: 24px; box-shadow: var(--shadow-md); }
    
    .creator-tabs-nav { display: flex; gap: 10px; border-bottom: 2px solid var(--border-color); padding-bottom: 12px; margin-bottom: 20px; }
    .creator-tab-btn { padding: 9px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; border: 1px solid transparent; background: transparent; color: var(--text-muted); cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all .2s; }
    .creator-tab-btn:hover { color: var(--text-main); background: rgba(0,0,0,.03); }
    .creator-tab-btn.active { background: #f3e8ff; color: #7c3aed; border-color: #ddd6fe; }
    body.dark-mode .creator-tab-btn.active { background: rgba(124,58,237,.2); color: #a78bfa; border-color: rgba(124,58,237,.4); }

    .shortcuts-label { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
    .shortcuts-pills { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
    .shortcut-pill { padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: transform .15s, opacity .15s; }
    .shortcut-pill:hover { transform: translateY(-1px); opacity: 0.9; }
    .pill-mc { background: #f3e8ff; color: #7c3aed; }
    .pill-tf { background: #dcfce7; color: #15803d; }
    .pill-id { background: #fef3c7; color: #b45309; }
    .pill-enum { background: #dbeafe; color: #1d4ed8; }
    .pill-mtf { background: #ffe4e6; color: #e11d48; }
    .pill-essay { background: #f1f5f9; color: #475569; }

    /* Code-style Editor Container */
    .editor-container { border: 1.5px solid var(--border-color); border-radius: 14px; background: var(--bg-card); overflow: hidden; margin-bottom: 16px; box-shadow: inset 0 2px 4px rgba(0,0,0,.02); }
    .editor-body { display: flex; min-height: 180px; max-height: 380px; position: relative; }
    .code-input { flex: 1; padding: 14px 16px; border: none; outline: none; font-family: 'Consolas', 'Monaco', monospace; font-size: 13px; line-height: 1.6; background: transparent; color: var(--text-main); resize: vertical; min-height: 180px; width: 100%; box-sizing: border-box; }
    .editor-footer { padding: 8px 16px; background: var(--bg-main); border-top: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; font-size: 11px; color: var(--text-muted); font-weight: 500; }

    /* Info Callout Box */
    .info-callout { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 12px 18px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    body.dark-mode .info-callout { background: rgba(30,58,138,.2); border-color: rgba(30,58,138,.4); }
    .info-callout-text { font-size: 12px; font-weight: 500; color: #1e40af; display: flex; align-items: center; gap: 8px; }
    body.dark-mode .info-callout-text { color: #93c5fd; }
    .btn-guide-outline { padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; background: #ffffff; color: #1d4ed8; border: 1.5px solid #93c5fd; cursor: pointer; transition: all .15s; }
    .btn-guide-outline:hover { background: #dbeafe; }

    /* Bottom Action Bar */
    .import-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .btn-clear-out { padding: 9px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; background: var(--bg-card); color: #e11d48; border: 1.5px solid #fca5a5; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all .15s; }
    .btn-clear-out:hover { background: #ffe4e6; }
    .btn-parse-purple { padding: 10px 22px; border-radius: 10px; font-size: 13px; font-weight: 700; background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: #ffffff; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 14px rgba(109,40,217,.3); transition: all .15s; }
    .btn-parse-purple:hover { background: linear-gradient(135deg, #7c3aed, #5b21b6); transform: translateY(-1px); }

    .drop-zone { border: 2px dashed var(--border-color); border-radius: 12px; padding: 30px 20px; text-align: center; background: var(--bg-card); transition: all .2s; cursor: pointer; }
    .drop-zone.dragover { border-color: var(--primary); background: var(--primary-light); }
    .drop-zone i { font-size: 32px; color: #7c3aed; margin-bottom: 8px; }

    /* ── Quiz Settings Form ── */
    .settings-card { background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color); padding: 22px 24px; margin-bottom: 24px; box-shadow: var(--shadow-md); }
    .settings-card h4 { font-size: 15px; font-weight: 700; color: var(--text-main); margin: 0 0 16px; display: flex; align-items: center; gap: 8px; }

    .form-control-custom { width: 100%; padding: 9px 13px; border: 1.5px solid var(--border-color); border-radius: 9px; font-size: 13px; font-family: 'Inter', sans-serif; background: var(--bg-card); color: var(--text-main); outline: none; transition: border-color .2s; }
    .form-control-custom:focus { border-color: var(--primary); }

    /* ── Live Preview Right Panel (Figma Style) ── */
    .preview-panel-card { background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color); padding: 22px 24px; margin-bottom: 24px; box-shadow: var(--shadow-md); display: flex; flex-direction: column; height: calc(100% - 24px); min-height: 520px; }
    .preview-panel-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
    .preview-title { font-size: 15px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px; }
    .detected-badge { background: #dcfce7; color: #15803d; font-size: 12px; font-weight: 700; padding: 5px 12px; border-radius: 99px; }

    .preview-cards-list { flex: 1; overflow-y: auto; padding-right: 6px; max-height: 540px; }

    .preview-bottom-banner { background: #f3e8ff; border-radius: 12px; padding: 12px 18px; margin-top: 16px; font-size: 12px; font-weight: 600; color: #6b21a8; display: flex; align-items: center; gap: 8px; }
    body.dark-mode .preview-bottom-banner { background: rgba(124,58,237,.2); color: #c4b5fd; }

    /* Question Preview Card Items */
    .preview-q-card { background: var(--bg-card); border-radius: 14px; border: 1.5px solid var(--border-color); border-left: 5px solid #7c3aed; padding: 16px; margin-bottom: 14px; box-shadow: var(--shadow-sm); position: relative; }
    .preview-q-header { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
    .preview-q-badge { width: 26px; height: 26px; border-radius: 50%; background: #f97316; color: #fff; font-size: 12px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .preview-q-text { font-weight: 700; font-size: 13px; color: var(--text-main); flex: 1; line-height: 1.4; }
    .preview-type-tag { font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 6px; text-transform: uppercase; }
    .tag-mc { background: #f3e8ff; color: #7c3aed; }
    .tag-tf { background: #dcfce7; color: #15803d; }
    .tag-id { background: #fef3c7; color: #b45309; }
    .tag-enum { background: #dbeafe; color: #1d4ed8; }
    .tag-mtf { background: #ffe4e6; color: #e11d48; }
    .tag-essay { background: #f1f5f9; color: #475569; }

    .preview-ans-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 10px 14px; margin-top: 10px; }
    body.dark-mode .preview-ans-box { background: rgba(22,163,74,.15); border-color: rgba(22,163,74,.3); }
    .preview-ans-label { font-size: 9px; font-weight: 800; color: #16a34a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
    .preview-ans-val { font-size: 13px; font-weight: 700; color: #15803d; display: flex; align-items: center; gap: 6px; }
    body.dark-mode .preview-ans-val { color: #4ade80; }

    .preview-q-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 12px; padding-top: 10px; border-top: 1px dashed var(--border-color); font-size: 12px; flex-wrap: wrap; }
    .preview-meta-input { display: inline-flex; align-items: center; gap: 6px; color: var(--text-muted); }
    .preview-input-sm { padding: 4px 8px; border-radius: 6px; border: 1px solid var(--border-color); font-size: 12px; background: var(--bg-card); color: var(--text-main); }

    /* Sticky Bottom Footer Bar */
    .creator-bottom-bar { position: sticky; bottom: 0; left: 0; right: 0; background: var(--bg-card); border-top: 1.5px solid var(--border-color); padding: 14px 28px; display: flex; align-items: center; justify-content: flex-end; box-shadow: 0 -6px 25px rgba(0,0,0,.08); z-index: 1000; margin: 20px -20px -20px -20px; }
    .btn-cancel-out { padding: 9px 22px; border-radius: 10px; font-size: 13px; font-weight: 600; background: var(--bg-card); color: var(--text-main); border: 1.5px solid var(--border-color); cursor: pointer; transition: all .15s; }
    .btn-cancel-out:hover { background: var(--bg-main); }
    .btn-create-purple { padding: 10px 26px; border-radius: 10px; font-size: 13px; font-weight: 700; background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: #ffffff; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 14px rgba(109,40,217,.35); transition: all .15s; }
    .btn-create-purple:hover { background: linear-gradient(135deg, #7c3aed, #5b21b6); transform: translateY(-1px); }

    /* ── Action Toolbar ── */
    .toolbar-card { background: var(--bg-card); border-radius: 14px; border: 1px solid var(--border-color); padding: 14px 20px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; box-shadow: var(--shadow-sm); }
    .tool-group { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

    .btn-act { padding: 8px 14px; border-radius: 9px; font-size: 12px; font-weight: 600; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all .15s; }
    .btn-act:hover { background: var(--primary-light); color: var(--primary); border-color: var(--primary); }
    .btn-act.btn-primary-custom { background: var(--primary); color: #fff; border-color: var(--primary); }
    .btn-act.btn-primary-custom:hover { background: var(--primary-hover); }

    .badge-counter { font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 99px; }
    .badge-valid { background: #dcfce7; color: #15803d; }
    .badge-invalid { background: #fee2e2; color: #b91c1c; }

    /* ── Question Cards Grid ── */
    .q-card { background: var(--bg-card); border-radius: 16px; border: 1.5px solid var(--border-color); padding: 20px; margin-bottom: 18px; box-shadow: var(--shadow-md); position: relative; transition: all .2s; }
    .q-card.invalid-card { border-color: #ef4444; background: rgba(239,68,68,.02); }

    .q-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
    .q-card-num { font-size: 14px; font-weight: 800; color: var(--primary); display: flex; align-items: center; gap: 8px; }

    .q-choice-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 12px; }
    @media(max-width: 640px) { .q-choice-grid { grid-template-columns: 1fr; } }

    .choice-item { display: flex; align-items: center; gap: 8px; background: var(--bg-main); padding: 8px 12px; border-radius: 9px; border: 1px solid var(--border-color); }
    .choice-item.correct-choice { border-color: #10b981; background: rgba(16,185,129,.1); }

    .img-preview { max-height: 80px; border-radius: 8px; margin-top: 8px; display: none; }
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
      <li><a href="logbook.php"><i class="fa fa-pencil-square-o"></i> Manage Subject</a></li>
      <li><a href="class_record.php"><i class="fa fa-table"></i> Class Record</a></li>
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
        <h3>Auto-Paste Quiz Creator from Excel / Sheets</h3>
        <p><?php echo date('l, F j, Y'); ?></p>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
      <button class="btn-theme-toggle" onclick="toggleDarkMode()"><i class="fa fa-moon-o"></i> Dark Mode</button>
      <a href="quizzes.php" class="btn-act"><i class="fa fa-arrow-left"></i> Back to Quizzes</a>
      <button class="btn-parse-purple" onclick="saveQuizToDatabase()" style="padding:8px 18px;font-size:12px;"><i class="fa fa-paper-plane"></i> Publish Quiz</button>
    </div>
  </header>

  <div class="td-content">



    <!-- Global Quiz Settings Card -->
    <div class="settings-card">
      <h4><i class="fa fa-cog" style="color:var(--primary);"></i> Quiz Configuration</h4>
      <div class="row">
        <div class="col-md-4 form-group">
          <label>Grading Term <span class="text-danger">*</span></label>
          <select class="form-control-custom" id="quizTerm" required>
            <option value="midterm">Midterm</option>
            <option value="final">Final</option>
            <option value="none">None (Practice)</option>
          </select>
        </div>

        <div class="col-md-4 form-group">
          <label>Time Limit (minutes)</label>
          <input type="number" class="form-control-custom" id="quizTimeLimit" placeholder="0 = Unlimited" min="0">
        </div>

        <div class="col-md-4 form-group" style="padding-top:26px;">
          <label class="mr-3"><input type="checkbox" id="shuffleQ" checked> Shuffle Questions</label>
          <label><input type="checkbox" id="shuffleA" checked> Shuffle Choices</label>
        </div>
      </div>

      <div class="form-group mb-0">
        <label>Instructions</label>
        <textarea class="form-control-custom" id="quizInstructions" rows="2" placeholder="Directions for students..."></textarea>
      </div>
    </div>

    <!-- 2-Column Split Layout: Editor on Left, Live Preview on Right -->
    <div class="row">
      <!-- Left Column: Auto-Paste Question Editor -->
      <div class="col-lg-6 col-md-12">
        <div class="import-card">
          <!-- Top Tab Navigation -->
          <div class="creator-tabs-nav">
            <button type="button" class="creator-tab-btn active" id="tabBtnPaste" onclick="switchCreatorTab('paste')">
              <i class="fa fa-paste"></i> Paste Questions
            </button>
            <button type="button" class="creator-tab-btn" id="tabBtnUpload" onclick="switchCreatorTab('upload')">
              <i class="fa fa-cloud-upload"></i> Upload File
            </button>
            <button type="button" class="creator-tab-btn" id="tabBtnManual" onclick="switchCreatorTab('manual')">
              <i class="fa fa-pencil"></i> Add Manually
            </button>
          </div>

          <!-- TAB 1: PASTE QUESTIONS (Default) -->
          <div id="tabContentPaste">
            <!-- Insert Shortcuts Section -->
            <div class="shortcuts-label">Insert shortcut:</div>
            <div class="shortcuts-pills">
              <button type="button" class="shortcut-pill pill-mc" onclick="insertShortcutTemplate('mc')">
                <i class="fa fa-plus-circle"></i> MC Multiple Choice
              </button>
              <button type="button" class="shortcut-pill pill-tf" onclick="insertShortcutTemplate('tf')">
                <i class="fa fa-plus-circle"></i> T/F True / False
              </button>
              <button type="button" class="shortcut-pill pill-id" onclick="insertShortcutTemplate('id')">
                <i class="fa fa-plus-circle"></i> ID Identification
              </button>
              <button type="button" class="shortcut-pill pill-enum" onclick="insertShortcutTemplate('enum')">
                <i class="fa fa-plus-circle"></i> ENUM Enumeration
              </button>
              <button type="button" class="shortcut-pill pill-mtf" onclick="insertShortcutTemplate('mtf')">
                <i class="fa fa-plus-circle"></i> MTF Modified T/F
              </button>
              <button type="button" class="shortcut-pill pill-essay" onclick="insertShortcutTemplate('essay')">
                <i class="fa fa-plus-circle"></i> ESSAY Essay
              </button>
            </div>

            <label style="font-weight:600;font-size:13px;color:var(--text-main);margin-bottom:8px;display:block;">
              <i class="fa fa-keyboard-o" style="color:#7c3aed;"></i> Paste your questions here <span class="text-danger">*</span>
            </label>

            <!-- Editor Container -->
            <div class="editor-container">
              <div class="editor-body">
                <textarea class="code-input" id="pasteArea" placeholder="1. Which organ pumps blood throughout the human body?&#10;A. Brain&#10;B. Lungs&#10;C. Heart&#10;D. Liver&#10;Answer: C. Heart&#10;points: 2&#10;&#10;2. The Earth revolves around the Sun.&#10;True / False&#10;Answer: True&#10;points: 2&#10;&#10;3. It is the largest land animal on Earth.&#10;Answer: Elephant&#10;points: 2&#10;&#10;4. Name the three states of matter.&#10;Answer: Solid, Liquid, Gas&#10;points: 2&#10;&#10;5. Why is water important to living things? (2–3 sentences)&#10;points: 10" oninput="updateEditorStats()"></textarea>
              </div>
              <div class="editor-footer">
                <span id="editorLines">Lines: 1</span>
                <span id="editorChars">Characters: 0</span>
              </div>
            </div>

            <!-- Info Banner -->
            <div class="info-callout">
              <div class="info-callout-text">
                <i class="fa fa-info-circle" style="font-size:16px;"></i> Use the Insert buttons above or follow the format examples.
              </div>
              <button type="button" class="btn-guide-outline" onclick="openExcelInstructions()">View Format Guide</button>
            </div>

            <!-- Actions Footer -->
            <div class="import-actions">
              <button type="button" class="btn-clear-out" onclick="clearPasteEditor()">
                <i class="fa fa-trash-o"></i> Clear
              </button>
              <button type="button" class="btn-parse-purple" onclick="parseAndPreview()">
                <i class="fa fa-magic"></i> Parse & Preview Questions
              </button>
            </div>
          </div>

          <!-- TAB 2: UPLOAD FILE -->
          <div id="tabContentUpload" style="display:none;">
            <div class="drop-zone" id="dropZone" onclick="$('#fileInput').click()">
              <i class="fa fa-cloud-upload"></i>
              <div style="font-weight:700;font-size:14px;color:var(--text-main);margin-top:6px;">Drop CSV / TSV / TXT File Here</div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Click or drag file from your computer</div>
              <input type="file" id="fileInput" accept=".csv,.tsv,.txt" style="display:none;" onchange="handleFileSelect(this.files)">
            </div>
          </div>
        </div>
      </div>

      <!-- Right Column: Live Preview Panel -->
      <div class="col-lg-6 col-md-12">
        <div class="preview-panel-card">
          <div class="preview-panel-header">
            <div class="preview-title"><i class="fa fa-eye" style="color:#7c3aed;"></i> Live Preview</div>
            <span class="detected-badge" id="detectedQuestionsBadge">0 Questions Detected (0 pts)</span>
          </div>

          <!-- Cards Scrollable List Container -->
          <div class="preview-cards-list" id="questionCardsContainer"></div>

          <!-- Bottom Purple Callout -->
          <div class="preview-bottom-banner" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <span><i class="fa fa-lightbulb-o" style="font-size:16px;"></i> Looks good! Click "Publish Quiz" to finalize.</span>
            <button type="button" class="btn-parse-purple" onclick="saveQuizToDatabase()" style="padding:6px 16px;font-size:12px;"><i class="fa fa-paper-plane"></i> Publish Quiz</button>
          </div>
        </div>
      </div>
  </div>

  <!-- Sticky Bottom Footer Bar -->
  <div class="creator-bottom-bar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button type="button" class="btn-cancel-out" onclick="window.location.href='quizzes.php'">Cancel</button>
      <button type="button" class="btn-create-purple" onclick="saveQuizToDatabase()"><i class="fa fa-floppy-o"></i> Create Quiz</button>
    </div>
  </div>
</div>

<!-- Modal: Excel Format Guide -->
<div class="modal fade" id="excelGuideModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content" style="border-radius:16px;overflow:hidden;">
      <div class="modal-header" style="background:linear-gradient(135deg,#0c1a2e,#0f4c75);color:#fff;padding:16px 24px;">
        <h5 class="modal-title" style="font-weight:700;"><i class="fa fa-table"></i> Spreadsheet Auto-Paste Format Guide</h5>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:0.8;">&times;</button>
      </div>
      <div class="modal-body" style="font-size:13px;padding:20px 24px;">
        <p style="color:var(--text-muted);margin-bottom:16px;">Copy contiguous rows from Excel or Google Sheets. You can paste <strong>Multiple Choice</strong>, <strong>True/False</strong>, <strong>Modified T/F</strong>, <strong>Identification</strong>, <strong>Enumeration</strong>, or <strong>Essay</strong> formats:</p>

        <!-- Format Tabs -->
        <ul class="nav nav-tabs" style="border-bottom:2px solid var(--border-color);margin-bottom:16px;">
          <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-mc" style="font-weight:600;"><i class="fa fa-list-ul"></i> Multiple Choice</a></li>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-tf" style="font-weight:600;"><i class="fa fa-check-square-o"></i> True / False</a></li>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-mtf" style="font-weight:600;"><i class="fa fa-pencil-square-o"></i> Modified T/F</a></li>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-id" style="font-weight:600;"><i class="fa fa-font"></i> Identification</a></li>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-enum" style="font-weight:600;"><i class="fa fa-bars"></i> Enumeration</a></li>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-essay" style="font-weight:600;"><i class="fa fa-align-left"></i> Essay</a></li>
        </ul>

        <div class="tab-content">
          <!-- 1. Multiple Choice -->
          <div class="tab-pane fade show active" id="tab-mc">
            <div class="alert alert-info" style="font-size:12px;margin-bottom:12px;">
              <strong>Format:</strong> <code>Col A: Question</code> | <code>Col B: Option A</code> | <code>Col C: Option B</code> | <code>Col D: Option C</code> | <code>Col E: Option D</code> | <code>Col F: Correct Answer (A/B/C/D)</code>
            </div>
            <table class="table table-sm table-bordered table-striped" style="font-size:12px;">
              <thead>
                <tr style="background:#f1f5f9;">
                  <th>Col A: Question</th>
                  <th>Col B: Opt A</th>
                  <th>Col C: Opt B</th>
                  <th>Col D: Opt C</th>
                  <th>Col E: Opt D</th>
                  <th>Col F: Answer / Type</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>What is HTML?</td>
                  <td>HyperText Markup Language</td>
                  <td>Home Tool Language</td>
                  <td>Hyperlinks Text</td>
                  <td>Hyper Tool Markup</td>
                  <td>A</td>
                </tr>
                <tr>
                  <td>Which symbol is used for ID in CSS?</td>
                  <td>#</td>
                  <td>.</td>
                  <td>&</td>
                  <td>*</td>
                  <td>A</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- 2. True or False -->
          <div class="tab-pane fade" id="tab-tf">
            <div class="alert alert-success" style="font-size:12px;margin-bottom:12px;">
              <strong>Format:</strong> <code>Col A: Statement</code> | <code>Col B: True</code> | <code>Col C: False</code> | <code>Col F: Correct Answer (True / False)</code> (or Type <code>true_false</code>)
            </div>
            <table class="table table-sm table-bordered table-striped" style="font-size:12px;">
              <thead>
                <tr style="background:#f1f5f9;">
                  <th>Col A: Statement</th>
                  <th>Col B</th>
                  <th>Col C</th>
                  <th>Col D-E</th>
                  <th>Col F: Correct Answer</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>HTML is a markup language.</td>
                  <td>True</td>
                  <td>False</td>
                  <td>(Leave blank)</td>
                  <td>True</td>
                </tr>
                <tr>
                  <td>CSS stands for Computer Style Sheets.</td>
                  <td>True</td>
                  <td>False</td>
                  <td>(Leave blank)</td>
                  <td>False</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- 3. Modified True or False -->
          <div class="tab-pane fade" id="tab-mtf">
            <div class="alert alert-warning" style="font-size:12px;margin-bottom:12px;">
              <strong>Format:</strong> <code>Col A: Statement</code> | <code>Col B: True</code> | <code>Col C: False (Correction Term)</code> | <code>Col F: Correct Answer or Correct Term</code>
            </div>
            <table class="table table-sm table-bordered table-striped" style="font-size:12px;">
              <thead>
                <tr style="background:#f1f5f9;">
                  <th>Col A: Statement</th>
                  <th>Col B</th>
                  <th>Col C: Correction if False</th>
                  <th>Col F: Correct Answer</th>
                  <th>Col G: Type Indicator</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>The earth is spherical.</td>
                  <td>True</td>
                  <td>False</td>
                  <td>True</td>
                  <td>modified_true_false</td>
                </tr>
                <tr>
                  <td>Water boils at 50 degrees Celsius at sea level.</td>
                  <td>True</td>
                  <td>False (100°C)</td>
                  <td>False (100°C)</td>
                  <td>modified_true_false</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- 4. Identification -->
          <div class="tab-pane fade" id="tab-id">
            <div class="alert alert-purple" style="background:#f3e8ff;color:#6b21a8;font-size:12px;margin-bottom:12px;">
              <strong>Format:</strong> <code>Col A: Question / Prompt</code> | <code>Col B: Correct Answer (Exact match)</code> | <code>Col F: identification</code>
            </div>
            <table class="table table-sm table-bordered table-striped" style="font-size:12px;">
              <thead>
                <tr style="background:#f1f5f9;">
                  <th>Col A: Question</th>
                  <th>Col B: Correct Answer</th>
                  <th>Col C-E</th>
                  <th>Col F: Type Indicator</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>What acronym stands for Central Processing Unit?</td>
                  <td>CPU</td>
                  <td>(Leave blank)</td>
                  <td>identification</td>
                </tr>
                <tr>
                  <td>What is the capital city of the Philippines?</td>
                  <td>Manila</td>
                  <td>(Leave blank)</td>
                  <td>identification</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- 5. Enumeration -->
          <div class="tab-pane fade" id="tab-enum">
            <div class="alert alert-primary" style="font-size:12px;margin-bottom:12px;">
              <strong>Format:</strong> <code>Col A: Question / Prompt</code> | <code>Col B: Items separated by commas (e.g. Item 1, Item 2, Item 3)</code> | <code>Col F: enumeration</code>
            </div>
            <table class="table table-sm table-bordered table-striped" style="font-size:12px;">
              <thead>
                <tr style="background:#f1f5f9;">
                  <th>Col A: Question</th>
                  <th>Col B: Acceptable Items</th>
                  <th>Col C-E</th>
                  <th>Col F: Type Indicator</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Give the 3 primary colors in traditional art.</td>
                  <td>Red, Yellow, Blue</td>
                  <td>(Leave blank)</td>
                  <td>enumeration</td>
                </tr>
                <tr>
                  <td>Name the 3 branches of government in the Philippines.</td>
                  <td>Executive, Legislative, Judicial</td>
                  <td>(Leave blank)</td>
                  <td>enumeration</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- 6. Essay -->
          <div class="tab-pane fade" id="tab-essay">
            <div class="alert alert-secondary" style="font-size:12px;margin-bottom:12px;">
              <strong>Format:</strong> <code>Col A: Essay Question / Prompt</code> | <code>Col B: Sample Answer or Rubric (Optional)</code> | <code>Col F: essay</code>
            </div>
            <table class="table table-sm table-bordered table-striped" style="font-size:12px;">
              <thead>
                <tr style="background:#f1f5f9;">
                  <th>Col A: Question</th>
                  <th>Col B: Rubric / Instructions</th>
                  <th>Col C-E</th>
                  <th>Col F: Type Indicator</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Explain the difference between compiled and interpreted languages.</td>
                  <td>Rubric: Technical Accuracy (50%), Clarity (50%)</td>
                  <td>(Leave blank)</td>
                  <td>essay</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div style="background:#f8fafc;padding:12px 16px;border-radius:10px;border:1px solid var(--border-color);margin-top:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
          <div>
            <i class="fa fa-lightbulb-o" style="color:var(--primary);"></i> <strong>Tip:</strong> You can also prefix questions with tags like <code>[MC]</code>, <code>[TF]</code>, <code>[MTF]</code>, <code>[ID]</code>, <code>[ENUM]</code>, or <code>[ESSAY]</code>!
          </div>
          <button class="btn-act btn-primary-custom" onclick="copySamplePasteData(); $('#excelGuideModal').modal('hide');">
            <i class="fa fa-clipboard"></i> Try Auto-Pasting Sample Quiz
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Publish Quiz Configuration -->
<div class="modal fade" id="publishModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content" style="border-radius:16px;">
      <div class="modal-header" style="background:linear-gradient(135deg,#0c1a2e,#0f4c75);color:#fff;">
        <h5 class="modal-title"><i class="fa fa-paper-plane"></i> Publish Quiz Settings</h5>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group mb-3">
          <label style="font-weight:600;">Quiz Title <span class="text-danger">*</span></label>
          <input type="text" class="form-control-custom" id="pubTitle" placeholder="e.g. Midterm Exam: Computer Science Fundamentals" required>
        </div>
        <div class="form-group mb-3">
          <label style="font-weight:600;">Target Class <span class="text-danger">*</span></label>
          <select class="form-control-custom" id="pubClassId" required>
            <option value="">Select Target Class...</option>
            <?php foreach($classes as $c): ?>
              <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['class_name'].' - '.$c['subject']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group mb-3">
          <label style="font-weight:600;">Due Date (Optional)</label>
          <input type="datetime-local" class="form-control-custom" id="pubDueDate">
        </div>
      </div>
      <div class="modal-footer" style="background:#f8fafc;border-top:1px solid var(--border-color);">
        <button type="button" class="btn-act" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn-act btn-primary-custom" onclick="submitPublishQuiz()"><i class="fa fa-check-circle"></i> Confirm & Publish Quiz</button>
      </div>
    </div>
  </div>
</div>

<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script src="../bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
let questionsData = [];
let historyStack = [];
let historyIndex = -1;

function toggleSidebar() { $('#sidebar').toggleClass('open'); }
function toggleDarkMode() { $('body').toggleClass('dark-mode'); }
function openExcelInstructions() { $('#excelGuideModal').modal('show'); }

// ── Tab Switching Logic ──────────────────────────────────────────────────────
function switchCreatorTab(tab) {
  if(tab === 'paste') {
    $('#tabBtnPaste').addClass('active');
    $('#tabBtnUpload').removeClass('active');
    $('#tabBtnManual').removeClass('active');
    $('#tabContentPaste').show();
    $('#tabContentUpload').hide();
  } else if(tab === 'upload') {
    $('#tabBtnPaste').removeClass('active');
    $('#tabBtnUpload').addClass('active');
    $('#tabBtnManual').removeClass('active');
    $('#tabContentPaste').hide();
    $('#tabContentUpload').show();
  } else if(tab === 'manual') {
    addNewQuestion();
    if($('#questionCardsContainer').offset()) {
      $('html, body').animate({ scrollTop: $('#questionCardsContainer').offset().top - 80 }, 400);
    }
  }
}

// ── Character & Line Stats ────────────────────────────────────────────────
function updateEditorStats() {
  const text = $('#pasteArea').val();
  const lines = text.split('\n');
  const lineCount = lines.length;
  const charCount = text.length;

  $('#editorLines').text(`Lines: ${lineCount}`);
  $('#editorChars').text(`Characters: ${charCount}`);
}

// ── Insert Shortcuts Templates ──────────────────────────────────────────────
function insertShortcutTemplate(type) {
  const textarea = document.getElementById('pasteArea');
  let snippet = '';

  if(type === 'mc') {
    snippet = `\n1. What is the primary purpose of HTML?\na) Structure web pages\nb) Style web pages\nc) Store database data\nd) Run server code\nAnswer: a\nPoints: 1\n`;
  } else if(type === 'tf') {
    snippet = `\n2. Python is an interpreted programming language.\nAnswer: True\nPoints: 1\n`;
  } else if(type === 'id') {
    snippet = `\n3. What acronym stands for Central Processing Unit?\nAnswer: CPU\nPoints: 1\n`;
  } else if(type === 'enum') {
    snippet = `\n4. Enumerate the 3 primary colors in traditional art.\nAnswer: Red, Yellow, Blue\nPoints: 1\n`;
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

  updateEditorStats();
}

function clearPasteEditor() {
  if($('#pasteArea').val().trim() && !confirm('Are you sure you want to clear the editor text?')) return;
  $('#pasteArea').val('');
  updateEditorStats();
}

function parseAndPreview() {
  const text = $('#pasteArea').val();
  if(!text.trim()) {
    alert('Please paste or enter question text first.');
    return;
  }
  parsePastedSpreadsheet(text);
  if($('#questionCardsContainer').children().length > 0) {
    $('html, body').animate({ scrollTop: $('#questionCardsContainer').offset().top - 80 }, 400);
  }
}

// ── File Drag & Drop ─────────────────────────────────────────────────────────
const dropZone = document.getElementById('dropZone');
dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', (e) => {
  e.preventDefault();
  dropZone.classList.remove('dragover');
  if(e.dataTransfer.files.length) handleFileSelect(e.dataTransfer.files);
});

function handleFileSelect(files) {
  const file = files[0];
  if(!file) return;
  const reader = new FileReader();
  reader.onload = function(e) {
    parsePastedSpreadsheet(e.target.result);
  };
  reader.readAsText(file);
}

// ── Parse Spreadsheet TSV / CSV / Text Blocks ────────────────────────────────
function parsePastedSpreadsheet(text) {
  saveHistory();
  if(!text || !text.trim()) {
    alert('Please paste or enter questions in the text area first.');
    return;
  }

  const lines = text.split(/\r?\n/);
  let importedCount = 0;
  const isTSV = text.includes('\t');

  if(isTSV) {
    // Process TSV spreadsheet rows
    lines.forEach((line, index) => {
      if(!line.trim()) return;
      let cols = line.split('\t').map(c => c.replace(/^["']|["']$/g, '').trim());
      if(cols.length < 1) return;

      const firstColLower = cols[0].toLowerCase();
      if(index === 0 && (firstColLower.includes('question') || firstColLower.includes('item') || firstColLower.includes('col a'))) return;

      const qText = cols[0] || '';
      if(!qText) return;

      let qType = 'multiple_choice';
      let options = ['', '', '', ''];
      let correctIndex = 0;
      let correctAnswer = '';

      const lastCol = (cols[cols.length - 1] || '').toLowerCase().trim();
      const secondCol = (cols[1] || '').trim();
      const thirdCol = (cols[2] || '').trim();

      const tagMatch = qText.match(/^\[(MC|TF|MTF|ID|ENUM|ESSAY|TRUE_FALSE|MODIFIED_TRUE_FALSE|IDENTIFICATION|ENUMERATION)\]\s*/i);
      let cleanQText = qText;

      if(tagMatch) {
        const tag = tagMatch[1].toUpperCase();
        cleanQText = qText.substring(tagMatch[0].length).trim();
        if(tag === 'TF' || tag === 'TRUE_FALSE') qType = 'true_false';
        else if(tag === 'MTF' || tag === 'MODIFIED_TRUE_FALSE') qType = 'modified_true_false';
        else if(tag === 'ID' || tag === 'IDENTIFICATION') qType = 'identification';
        else if(tag === 'ENUM' || tag === 'ENUMERATION') qType = 'enumeration';
        else if(tag === 'ESSAY') qType = 'essay';
        else qType = 'multiple_choice';
      } else if(['multiple_choice','true_false','modified_true_false','identification','enumeration','essay','mc','tf','mtf','id','enum'].includes(lastCol)) {
        if(lastCol === 'tf' || lastCol === 'true_false') qType = 'true_false';
        else if(lastCol === 'mtf' || lastCol === 'modified_true_false') qType = 'modified_true_false';
        else if(lastCol === 'id' || lastCol === 'identification') qType = 'identification';
        else if(lastCol === 'enum' || lastCol === 'enumeration') qType = 'enumeration';
        else if(lastCol === 'essay') qType = 'essay';
        else qType = 'multiple_choice';
      } else {
        const secondLower = secondCol.toLowerCase();
        const thirdLower = thirdCol.toLowerCase();
        
        if((secondLower === 'true' && thirdLower === 'false') || (secondLower === 'false' && thirdLower === 'true')) {
          qType = 'true_false';
        } else if(secondLower === 'true' && (thirdLower.startsWith('false') || thirdLower !== '')) {
          qType = 'modified_true_false';
        } else if(cols.length <= 2 || (!cols[2] && !cols[3] && !cols[4])) {
          if(!secondCol) {
            qType = 'essay';
          } else if(secondCol.includes(',') || secondCol.includes(';') || lastCol === 'enum') {
            qType = 'enumeration';
          } else if(lastCol === 'essay' || secondLower.includes('rubric')) {
            qType = 'essay';
          } else {
            qType = 'identification';
          }
        } else {
          qType = 'multiple_choice';
        }
      }

      if(qType === 'true_false') {
        options = ['True', 'False'];
        const rawAns = (cols[5] || cols[1] || 'True').trim();
        correctAnswer = (rawAns.toLowerCase() === 'false' || rawAns.toUpperCase() === 'B') ? 'False' : 'True';
        correctIndex = correctAnswer === 'True' ? 0 : 1;
      } else if(qType === 'modified_true_false') {
        options = ['True', 'False'];
        correctAnswer = cols[5] || cols[2] || cols[1] || 'True';
        correctIndex = (correctAnswer.toLowerCase().includes('false') || correctAnswer.toUpperCase() === 'B') ? 1 : 0;
      } else if(qType === 'identification' || qType === 'enumeration' || qType === 'essay') {
        options = [];
        correctAnswer = cols[1] || cols[5] || '';
      } else {
        const optA = cols[1] || '';
        const optB = cols[2] || '';
        const optC = cols[3] || '';
        const optD = cols[4] || '';
        const rawCorrect = (cols[5] || cols[cols.length - 1] || 'A').toUpperCase();

        options = [optA, optB, optC, optD].filter(o => o !== '');
        while(options.length < 4) options.push('');

        correctIndex = 0;
        if(rawCorrect === 'A' || rawCorrect === '1') correctIndex = 0;
        else if(rawCorrect === 'B' || rawCorrect === '2') correctIndex = 1;
        else if(rawCorrect === 'C' || rawCorrect === '3') correctIndex = 2;
        else if(rawCorrect === 'D' || rawCorrect === '4') correctIndex = 3;
        else {
          const foundIdx = options.findIndex(o => o.toLowerCase() === rawCorrect.toLowerCase());
          if(foundIdx !== -1) correctIndex = foundIdx;
        }
        correctAnswer = options[correctIndex] || '';
      }

      questionsData.push({
        id: Date.now() + Math.random(),
        question_type: qType,
        question_text: cleanQText,
        options: options,
        correct_index: correctIndex,
        correct_answer: correctAnswer,
        points: 1,
        topic: 'General',
        difficulty: 'medium',
        image_url: ''
      });

      importedCount++;
    });
  } else {
    // Process Formatted Text Blocks
    let currentSection = '';
    const blocks = text.split(/\n\s*\n/);

    blocks.forEach(block => {
      const bLines = block.split(/\r?\n/).map(l => l.trim()).filter(l => l !== '');
      if(bLines.length === 0) return;

      // Check for section headers (e.g. "Multiple Choice", "True or False", "Modified True or False", "Identification", "Enumeration", "Essay")
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
          if(cleanL && !cleanL.toLowerCase().startsWith('answer:') && !cleanL.toLowerCase().startsWith('points:') && !cleanL.toLowerCase().startsWith('topic:')) {
            qStatement = qStatement ? (qStatement + ' ' + cleanL) : cleanL;
          }
        }
      });

      if(!qStatement) return;

      const lowerStmt = qStatement.toLowerCase();
      const lowerAns = correctAnswer.toLowerCase();

      let qType = 'multiple_choice';
      let correctIndex = 0;

      // 1. Intelligent Essay Detection
      const isEssayKeyword = lowerStmt.startsWith('why ') || lowerStmt.startsWith('explain ') || lowerStmt.startsWith('describe ') || 
                             lowerStmt.startsWith('discuss ') || lowerStmt.startsWith('summarize ') || lowerStmt.startsWith('elaborate ') || 
                             lowerStmt.startsWith('compare ') || lowerStmt.includes('(2–3 sentences)') || lowerStmt.includes('(2-3 sentences)') || 
                             lowerStmt.includes('sentence') || lowerStmt.includes('essay') || lowerStmt.includes('in your own words');

      if(currentSection === 'essay' || isEssayKeyword || (options.length === 0 && !correctAnswer && points >= 5)) {
        qType = 'essay';
        options = [];
      }
      // 2. Multiple Choice
      else if(options.length >= 2) {
        qType = 'multiple_choice';
        let idx = 0;
        // Check if answer is "C. Heart" or "C" or "Heart"
        const letterMatch = lowerAns.match(/^([a-d])[\.\:\)]?\s*(.*)$/i);
        if(letterMatch) {
          const char = letterMatch[1].toLowerCase();
          idx = char.charCodeAt(0) - 97;
          if(letterMatch[2] && options[idx]) {
            correctAnswer = options[idx];
          } else if(options[idx]) {
            correctAnswer = options[idx];
          }
        } else {
          const found = options.findIndex(o => o.toLowerCase() === lowerAns);
          if(found !== -1) idx = found;
          correctAnswer = options[idx] || options[0] || '';
        }
        correctIndex = (idx >= 0 && idx < options.length) ? idx : 0;
        while(options.length < 4) options.push('');
      }
      // 3. True / False
      else if(currentSection === 'tf' || lowerAns === 'true' || lowerAns === 'false' || lowerAns === 't' || lowerAns === 'f') {
        qType = 'true_false';
        options = ['True', 'False'];
        correctAnswer = (lowerAns === 'false' || lowerAns === 'f') ? 'False' : 'True';
        correctIndex = correctAnswer === 'True' ? 0 : 1;
      }
      // 4. Modified True / False
      else if(currentSection === 'mtf') {
        qType = 'modified_true_false';
        options = ['True', 'False'];
        correctIndex = (lowerAns.includes('false') || lowerAns === 'f') ? 1 : 0;
      }
      // 5. Enumeration
      else if(currentSection === 'enum' || correctAnswer.includes(',') || lowerStmt.startsWith('name ') || lowerStmt.startsWith('give ') || lowerStmt.startsWith('enumerate ') || lowerStmt.startsWith('list ')) {
        qType = 'enumeration';
        options = [];
      }
      // 6. Identification
      else {
        qType = 'identification';
        options = [];
      }

      questionsData.push({
        id: Date.now() + Math.random(),
        question_type: qType,
        question_text: qStatement,
        options: options,
        correct_index: correctIndex,
        correct_answer: correctAnswer,
        points: points,
        topic: topic,
        difficulty: 'medium',
        image_url: ''
      });

      importedCount++;
    });
  }

  renderCards();
}

// ── Copy Sample Test Data to Paste Area ──────────────────────────────────────
function copySamplePasteData() {
  const sampleData = `Multiple Choice

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

  $('#pasteArea').val(sampleData);
  updateEditorStats();
  parsePastedSpreadsheet(sampleData);
}

// ── Render Question Cards ────────────────────────────────────────────────────
function renderCards() {
  const container = $('#questionCardsContainer');
  container.empty();

  let validCount = 0;
  let invalidCount = 0;
  let totalPoints = 0;

  if(questionsData.length === 0) {
    container.html(`
      <div style="text-align:center;padding:40px 16px;background:var(--bg-card);border-radius:14px;border:1.5px dashed var(--border-color);">
        <i class="fa fa-clipboard" style="font-size:36px;color:var(--text-muted);margin-bottom:10px;"></i>
        <h5 style="font-weight:700;color:var(--text-main);margin:0 0 4px;">No Questions Detected</h5>
        <p style="font-size:12px;color:var(--text-muted);margin:0 0 12px;">Paste text on the left or use shortcut buttons to generate questions.</p>
      </div>
    `);
    $('#validCountBadge').text('0 Valid');
    $('#invalidCountBadge').text('0 Invalid');
    $('#detectedQuestionsBadge').text('0 Questions Detected (0 pts)');
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

  questionsData.forEach((q, idx) => {
    const qType = q.question_type || 'multiple_choice';
    let isValid = q.question_text.trim() !== '';

    if(qType === 'multiple_choice') {
      isValid = isValid && q.options.filter(o => o.trim() !== '').length >= 2;
    } else if(qType === 'true_false') {
      isValid = isValid && (q.correct_answer === 'True' || q.correct_answer === 'False');
    } else if(qType === 'modified_true_false' || qType === 'identification' || qType === 'enumeration') {
      isValid = isValid && (q.correct_answer || '').trim() !== '';
    }

    if(isValid) validCount++; else invalidCount++;
    const pts = parseInt(q.points) || 1;
    totalPoints += pts;

    const bColor = badgeColors[qType] || '#7c3aed';
    const tagInfo = typeTags[qType] || { label: 'MC', class: 'tag-mc' };

    let choicesOrAnswerHtml = '';

    if(qType === 'multiple_choice') {
      let choicesHtml = '';
      for(let i = 0; i < 4; i++) {
        const optVal = q.options[i] || '';
        const isChecked = q.correct_index === i ? 'checked' : '';
        const choiceClass = q.correct_index === i ? 'correct-choice' : '';

        choicesHtml += `
          <div class="choice-item ${choiceClass}" style="padding:4px 8px;font-size:12px;">
            <input type="radio" name="correct_${idx}" value="${i}" ${isChecked} onchange="updateCorrectIndex(${idx}, ${i})">
            <span style="font-weight:700;color:var(--primary);width:14px;">${String.fromCharCode(65+i)}</span>
            <input type="text" class="form-control-custom" style="padding:2px 6px;font-size:12px;" value="${escapeHtml(optVal)}" placeholder="Option ${String.fromCharCode(65+i)}" oninput="updateOptionText(${idx}, ${i}, this.value)">
          </div>
        `;
      }
      choicesOrAnswerHtml = `<div class="q-choice-grid" style="grid-template-columns:1fr 1fr;gap:6px;margin-top:8px;">${choicesHtml}</div>`;
    } else {
      // Clean Correct Answer Box matching screenshot
      const ansDisplay = q.correct_answer || 'None specified';
      choicesOrAnswerHtml = `
        <div class="preview-ans-box">
          <div class="preview-ans-label">Correct Answer</div>
          <div class="preview-ans-val">
            <i class="fa fa-check" style="color:#16a34a;"></i>
            <input type="text" class="form-control-custom" style="padding:2px 6px;font-size:12px;font-weight:700;border:none;background:transparent;color:#15803d;width:100%;" value="${escapeHtml(ansDisplay)}" oninput="updateCorrectAnswerDirect(${idx}, this.value)">
          </div>
        </div>
      `;
    }

    const cardHtml = `
      <div class="preview-q-card" id="qcard_${idx}" style="border-left-color:${bColor};">
        <div class="preview-q-header">
          <span class="preview-q-badge" style="background:${bColor};">${idx + 1}</span>
          <div class="preview-q-text">
            <textarea class="form-control-custom" rows="2" style="font-size:12px;font-weight:700;border:none;background:transparent;padding:0;resize:vertical;" oninput="updateQuestionText(${idx}, this.value)" placeholder="Enter question statement...">${escapeHtml(q.question_text)}</textarea>
          </div>
          <span class="preview-type-tag ${tagInfo.class}">${tagInfo.label}</span>
        </div>

        ${choicesOrAnswerHtml}

        <div class="preview-q-foot">
          <div style="display:flex;align-items:center;gap:10px;flex:1;">
            <span class="preview-meta-input"><i class="fa fa-tag"></i> Topic: <input type="text" class="preview-input-sm" style="width:110px;" value="${escapeHtml(q.topic)}" placeholder="e.g. Loops" oninput="updateTopic(${idx}, this.value)"></span>
            <span class="preview-meta-input">Points: <input type="number" class="preview-input-sm" style="width:50px;" value="${pts}" min="1" onchange="updatePoints(${idx}, this.value)"></span>
          </div>

          <div style="display:flex;align-items:center;gap:4px;">
            <select class="preview-input-sm" style="font-size:11px;font-weight:700;" onchange="updateQuestionType(${idx}, this.value)">
              <option value="multiple_choice" ${qType==='multiple_choice'?'selected':''}>MC</option>
              <option value="true_false" ${qType==='true_false'?'selected':''}>T/F</option>
              <option value="modified_true_false" ${qType==='modified_true_false'?'selected':''}>MTF</option>
              <option value="identification" ${qType==='identification'?'selected':''}>ID</option>
              <option value="enumeration" ${qType==='enumeration'?'selected':''}>ENUM</option>
              <option value="essay" ${qType==='essay'?'selected':''}>ESSAY</option>
            </select>
            <button class="btn-act" style="padding:3px 7px;font-size:11px;" onclick="moveQuestion(${idx}, -1)" title="Move Up"><i class="fa fa-arrow-up"></i></button>
            <button class="btn-act" style="padding:3px 7px;font-size:11px;" onclick="moveQuestion(${idx}, 1)" title="Move Down"><i class="fa fa-arrow-down"></i></button>
            <button class="btn-act text-danger" style="padding:3px 7px;font-size:11px;" onclick="deleteQuestion(${idx})" title="Delete"><i class="fa fa-trash"></i></button>
          </div>
        </div>
      </div>
    `;

    container.append(cardHtml);
  });

  $('#validCountBadge').text(`${validCount} Valid`);
  $('#invalidCountBadge').text(`${invalidCount} Invalid`);
  $('#detectedQuestionsBadge').text(`${questionsData.length} Questions Detected (${totalPoints} pts)`);
}

// ── Item Mutations ───────────────────────────────────────────────────────────
function escapeHtml(str) { return (str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function addNewQuestion() {
  saveHistory();
  questionsData.push({
    id: Date.now(),
    question_type: 'multiple_choice',
    question_text: '',
    options: ['', '', '', ''],
    correct_index: 0,
    correct_answer: '',
    points: 1,
    topic: 'General',
    difficulty: 'medium',
    image_url: ''
  });
  renderCards();
}

function updateQuestionType(idx, val) {
  saveHistory();
  questionsData[idx].question_type = val;
  if(val === 'true_false') {
    questionsData[idx].options = ['True', 'False'];
    questionsData[idx].correct_answer = 'True';
    questionsData[idx].correct_index = 0;
  } else if(val === 'modified_true_false') {
    questionsData[idx].options = ['True', 'False'];
    questionsData[idx].correct_answer = 'True';
    questionsData[idx].correct_index = 0;
  } else if(val === 'multiple_choice') {
    if(!questionsData[idx].options || questionsData[idx].options.length < 4) {
      questionsData[idx].options = ['', '', '', ''];
    }
    questionsData[idx].correct_index = 0;
    questionsData[idx].correct_answer = questionsData[idx].options[0] || '';
  } else {
    questionsData[idx].options = [];
    questionsData[idx].correct_answer = '';
  }
  renderCards();
}

function updateQuestionText(idx, val) { questionsData[idx].question_text = val; }
function updateOptionText(idx, oIdx, val) {
  questionsData[idx].options[oIdx] = val;
  if(questionsData[idx].correct_index === oIdx) {
    questionsData[idx].correct_answer = val;
  }
}
function updateCorrectIndex(idx, cIdx) {
  questionsData[idx].correct_index = cIdx;
  questionsData[idx].correct_answer = questionsData[idx].options[cIdx] || '';
  renderCards();
}

function updateTFAnswer(idx, val) {
  questionsData[idx].correct_answer = val;
  questionsData[idx].correct_index = val === 'True' ? 0 : 1;
  renderCards();
}

function updateMTFType(idx, val) {
  if(val === 'True') {
    questionsData[idx].correct_answer = 'True';
  } else {
    questionsData[idx].correct_answer = 'False (Correction)';
  }
  renderCards();
}

function updateMTFText(idx, val) {
  questionsData[idx].correct_answer = val;
}

function updateCorrectAnswerDirect(idx, val) {
  questionsData[idx].correct_answer = val;
}

function updateTopic(idx, val) { questionsData[idx].topic = val; }
function updatePoints(idx, val) { questionsData[idx].points = parseInt(val) || 1; }
function updateImageUrl(idx, val) { questionsData[idx].image_url = val; }

function duplicateQuestion(idx) {
  saveHistory();
  const copy = JSON.parse(JSON.stringify(questionsData[idx]));
  copy.id = Date.now();
  questionsData.splice(idx + 1, 0, copy);
  renderCards();
}

function deleteQuestion(idx) {
  saveHistory();
  questionsData.splice(idx, 1);
  renderCards();
}

function moveQuestion(idx, dir) {
  const target = idx + dir;
  if(target < 0 || target >= questionsData.length) return;
  saveHistory();
  const temp = questionsData[idx];
  questionsData[idx] = questionsData[target];
  questionsData[target] = temp;
  renderCards();
}

function randomizeQuestions() {
  saveHistory();
  const groups = {};
  questionsData.forEach(q => {
    const type = q.question_type || 'multiple_choice';
    if(!groups[type]) groups[type] = [];
    groups[type].push(q);
  });
  let shuffled = [];
  Object.keys(groups).forEach(type => {
    groups[type].sort(() => Math.random() - 0.5);
    shuffled = shuffled.concat(groups[type]);
  });
  questionsData = shuffled;
  renderCards();
}

function bulkDelete() {
  if(!confirm('Clear all questions?')) return;
  saveHistory();
  questionsData = [];
  renderCards();
}

function filterCards() {
  const query = $('#searchQuestion').val().toLowerCase();
  questionsData.forEach((q, idx) => {
    const card = $(`#qcard_${idx}`);
    if(q.question_text.toLowerCase().includes(query)) card.show(); else card.hide();
  });
}

// ── Undo / Redo History Stack ────────────────────────────────────────────────
function saveHistory() {
  historyStack = historyStack.slice(0, historyIndex + 1);
  historyStack.push(JSON.stringify(questionsData));
  historyIndex++;
}

function undo() {
  if(historyIndex > 0) {
    historyIndex--;
    questionsData = JSON.parse(historyStack[historyIndex]);
    renderCards();
  }
}

function redo() {
  if(historyIndex < historyStack.length - 1) {
    historyIndex++;
    questionsData = JSON.parse(historyStack[historyIndex]);
    renderCards();
  }
}

$(document).keydown(function(e) {
  if(e.ctrlKey && e.key === 'z') { e.preventDefault(); undo(); }
  if(e.ctrlKey && e.key === 'y') { e.preventDefault(); redo(); }
});

// ── Save Quiz & Open Publish Settings Modal ─────────────────────────────────
function saveQuizToDatabase() {
  if(questionsData.length === 0) {
    alert('Please add at least one question before publishing.');
    return;
  }
  $('#publishModal').modal('show');
}

function submitPublishQuiz() {
  const classId = $('#pubClassId').val();
  const title = $('#pubTitle').val().trim();
  const dueDate = $('#pubDueDate').val();

  if(!classId || !title) {
    alert('Please select a target class and enter a quiz title to publish.');
    return;
  }

  if(questionsData.length === 0) {
    alert('Please add at least one question before saving.');
    return;
  }

  // Convert questions to CenLearn format
  const formattedQuestions = [];
  let hasErrors = false;

  questionsData.forEach((q, idx) => {
    const qText = q.question_text.trim();
    const qType = q.question_type || 'multiple_choice';

    let isValid = qText !== '';
    let opts = [];
    let correctAns = q.correct_answer || '';

    if(qType === 'multiple_choice') {
      opts = (q.options || []).filter(o => o.trim() !== '');
      if(opts.length < 2) isValid = false;
      correctAns = q.options[q.correct_index] || opts[0] || '';
    } else if(qType === 'true_false') {
      opts = ['True', 'False'];
      correctAns = (q.correct_answer || 'True').toLowerCase() === 'false' ? 'False' : 'True';
    } else if(qType === 'modified_true_false') {
      opts = ['True', 'False'];
      correctAns = q.correct_answer || 'True';
    } else if(qType === 'identification' || qType === 'enumeration') {
      opts = [];
      correctAns = q.correct_answer || '';
      if(!correctAns.trim()) isValid = false;
    } else if(qType === 'essay') {
      opts = [];
      correctAns = q.correct_answer || '';
    }

    if(!isValid) {
      hasErrors = true;
      return;
    }

    formattedQuestions.push({
      question_type: qType,
      question_text: qText,
      topic: q.topic || 'General',
      points: q.points || 1,
      options: opts,
      correct_answer: correctAns
    });
  });

  if(hasErrors) {
    if(!confirm('Some question cards are incomplete. Do you want to skip incomplete questions and save valid ones?')) return;
  }

  const payload = {
    action: 'create',
    class_id: classId,
    title: title,
    instructions: $('#quizInstructions').val().trim(),
    time_limit: $('#quizTimeLimit').val() || 0,
    due_date: dueDate || '',
    shuffle_questions: ($('#shuffleQ').length ? ($('#shuffleQ').is(':checked') ? 1 : 0) : 1),
    shuffle_answers: ($('#shuffleA').length ? ($('#shuffleA').is(':checked') ? 1 : 0) : 1),
    term: $('#quizTerm').val(),
    questions: JSON.stringify(formattedQuestions)
  };

  $.post('../shared/quiz_handler.php', payload, function(res) {
    if(res.success) {
      alert('Quiz created and published successfully!');
      window.location.href = 'quizzes.php';
    } else {
      alert(res.msg || 'Error saving quiz.');
    }
  }, 'json');
}

function checkCreatorRelevance() {
  const classId = $('#pubClassId').val() || $('#quizClassId').val();
  if(!classId) {
    alert('Please select a Target Class in the Publish Quiz modal or dropdown first.');
    $('#publishModal').modal('show');
    return;
  }
  if(questionsData.length === 0) {
    alert('Please add or paste questions first.');
    return;
  }

  const formattedQuestions = questionsData.map(q => ({
    question_type: 'multiple_choice',
    question_text: q.question_text,
    topic: q.topic || 'General',
    options: q.options
  }));

  $.post('../shared/quiz_handler.php', {
    action: 'analyze_relevance',
    class_id: classId,
    questions: JSON.stringify(formattedQuestions)
  }, function(res) {
    if(!res.success || !res.analytics) {
      alert(res.msg || 'Failed to check relevance.');
      return;
    }
    const a = res.analytics;
    alert(`AI Relevance Report:\n- Material Relevance: ${a.relevance_score}%\n- Syllabus Coverage: ${a.coverage_score}%\n- Quality Score: ${a.quality_score}/100\n- Predicted Pass Rate: ${a.predicted_pass_rate}%\n\nRecommendations:\n${a.recommendations.join('\n')}`);
  }, 'json');
}

// Initial state
saveHistory();
copySamplePasteData();
</script>
</body>
</html>
