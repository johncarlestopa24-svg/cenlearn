<?php
include '../includes/session.php';
include '../includes/conn.php';

$uc = $conn->real_escape_string($user['user_code']);

// Handle assignment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_assignment') {
    header('Content-Type: application/json');
    $cw_id = intval($_POST['classwork_id'] ?? 0);
    $notes = $conn->real_escape_string($_POST['submission_text'] ?? '');
    
    $filePath = '';
    if (isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/submissions/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $_FILES['submission_file']['name']);
        $targetFile = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['submission_file']['tmp_name'], $targetFile)) {
            $filePath = 'uploads/submissions/' . $fileName;
        }
    }

    // Check if submission exists
    $chk = $conn->query("SELECT id FROM submissions WHERE classwork_id=$cw_id AND student_code='$uc'");
    if ($chk && $chk->num_rows > 0) {
        $sub = $chk->fetch_assoc();
        $subId = $sub['id'];
        $conn->query("UPDATE submissions SET submitted_at=NOW(), file_path=IF('$filePath'!='','$filePath',file_path), submission_text='$notes', status='submitted' WHERE id=$subId");
    } else {
        $conn->query("INSERT INTO submissions (classwork_id, student_code, submitted_at, file_path, submission_text, status) VALUES ($cw_id, '$uc', NOW(), '$filePath', '$notes', 'submitted')");
    }
    echo json_encode(['success' => true, 'msg' => 'Assignment submitted successfully!']);
    exit;
}

// Fetch all assignments for enrolled classes
$assignments_query = $conn->query("
    SELECT cw.*, c.class_name, c.class_code, u.first_name AS teacher_first, u.last_name AS teacher_last,
           s.id AS submission_id, s.submitted_at, s.score, s.feedback, s.status AS submission_status, s.file_path AS student_file
    FROM classwork cw
    JOIN classes c ON cw.class_id = c.id
    JOIN class_members cm ON cm.class_id = c.id
    LEFT JOIN users u ON c.teacher_code = u.user_code
    LEFT JOIN submissions s ON s.classwork_id = cw.id AND s.student_code = '$uc'
    WHERE cm.user_code = '$uc'
      AND (c.is_subject_only = 0 OR c.is_subject_only IS NULL)
      AND (c.is_archived = 0 OR c.is_archived IS NULL)
      AND (cw.type = 'assignment' OR cw.type IS NULL OR cw.type = '')
    ORDER BY cw.due_date ASC, cw.id DESC
");

$assignmentList = [];
$totalCount = 0;
$pendingCount = 0;
$submittedCount = 0;
$gradedCount = 0;

if ($assignments_query) {
    while ($row = $assignments_query->fetch_assoc()) {
        $assignmentList[] = $row;
        $totalCount++;
        if (!empty($row['submission_id'])) {
            if ($row['score'] !== null && $row['score'] !== '') {
                $gradedCount++;
            } else {
                $submittedCount++;
            }
        } else {
            $pendingCount++;
        }
    }
}

$fullName = trim(($user['first_name'] ?? 'Student') . ' ' . ($user['last_name'] ?? 'User'));
$initials = strtoupper(substr($user['first_name'] ?? 'S', 0, 1) . substr($user['last_name'] ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CenLearn — Student Assignments</title>
  <link rel="stylesheet" href="/cenlearn/system/bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/cenlearn/system/bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/cenlearn/system/dist/css/cenlearn.css?v=3.0">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #f4f7fa; font-family: 'Inter', sans-serif; color: #1e293b; overflow-x: hidden; }

    .app-sidebar {
      position: fixed; top: 0; left: 0; width: 250px; height: 100vh;
      background: #0b1727; display: flex; flex-direction: column; z-index: 300;
      transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      transform: translateX(-250px);
    }
    .app-sidebar.open { transform: translateX(0); }
    @media (min-width: 901px) { .app-sidebar { transform: translateX(0); } }

    .sb-brand { padding: 24px 22px 18px; display: flex; align-items: center; gap: 12px; }
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
    .sb-nav li.active a { background: #2563eb; color: #ffffff; font-weight: 600; box-shadow: 0 4px 14px rgba(37,99,235,0.35); }
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
    .sb-promo-card p { margin: 0; font-size: 11.5px; color: rgba(255,255,255,0.8); line-height: 1.45; font-weight: 500; }

    .app-main { margin-left: 0; min-height: 100vh; display: flex; flex-direction: column; transition: margin-left 0.3s; }
    @media (min-width: 901px) { .app-main { margin-left: 250px; } }

    .top-header {
      background: #ffffff; height: 68px; padding: 0 32px;
      display: flex; align-items: center; justify-content: space-between;
      border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 100;
    }
    .header-search { position: relative; width: 360px; max-width: 100%; }
    .header-search input {
      width: 100%; height: 42px; padding: 0 16px 0 42px; border-radius: 99px;
      border: 1px solid #f1f5f9; background: #f8fafc; font-size: 13px; color: #1e293b;
      outline: none; transition: all 0.2s; font-family: 'Inter', sans-serif;
    }
    .header-search input:focus { background: #ffffff; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
    .header-search i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; }

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

    .content-body { padding: 28px 32px 60px; flex: 1; }

    .hero-banner {
      background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 50%, #38bdf8 100%);
      border-radius: 20px; padding: 26px 36px; color: #ffffff;
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 24px; box-shadow: 0 10px 25px -5px rgba(37,99,235,0.3);
    }
    .hero-text h1 { margin: 0 0 6px; font-size: 24px; font-weight: 800; letter-spacing: -0.4px; }
    .hero-text p { margin: 0; font-size: 13.5px; opacity: 0.9; font-weight: 400; }

    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 28px; }
    @media (max-width: 1100px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 600px) { .stats-grid { grid-template-columns: 1fr; } }

    .stat-card {
      background: #ffffff; border-radius: 16px; padding: 20px;
      border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;
      box-shadow: 0 2px 6px rgba(0,0,0,0.02);
    }
    .stat-card-left { display: flex; align-items: center; gap: 14px; }
    .stat-icon-circle { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .stat-card-info label { display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 2px; }
    .stat-card-info strong { display: block; font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1.1; }

    .section-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 16px; }
    .section-title h2 { margin: 0; font-size: 20px; font-weight: 800; color: #0f172a; letter-spacing: -0.3px; }
    .section-title p { margin: 3px 0 0; font-size: 12.5px; color: #64748b; }

    .filter-tabs { display: flex; align-items: center; gap: 8px; }
    .tab-pill {
      padding: 8px 18px; border-radius: 99px; font-size: 12.5px; font-weight: 600;
      border: 1px solid #e2e8f0; background: #ffffff; color: #475569;
      cursor: pointer; transition: all 0.18s; font-family: 'Inter', sans-serif;
    }
    .tab-pill.active { background: #0f172a; color: #ffffff; border-color: #0f172a; box-shadow: 0 4px 12px rgba(15,23,42,0.15); }

    .assign-card {
      background: #ffffff; border-radius: 18px; border: 1px solid #e2e8f0;
      padding: 20px 24px; margin-bottom: 16px; display: flex; align-items: center;
      justify-content: space-between; gap: 20px; transition: transform 0.2s, box-shadow 0.2s;
    }
    .assign-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.06); border-color: #cbd5e1; }
    .ac-left { display: flex; align-items: center; gap: 18px; flex: 1; }
    .ac-icon {
      width: 50px; height: 50px; border-radius: 14px; background: #eff6ff; color: #2563eb;
      display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;
    }
    .ac-details h3 { margin: 0 0 4px; font-size: 16px; font-weight: 800; color: #0f172a; }
    .ac-meta { font-size: 12px; color: #64748b; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .badge-status { padding: 4px 10px; border-radius: 99px; font-size: 11px; font-weight: 700; }
    .badge-pending { background: #fffbe6; color: #d97706; border: 1px solid #fef08a; }
    .badge-submitted { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
    .badge-graded { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }

    .btn-submit-assign {
      padding: 9px 20px; border-radius: 99px; background: #2563eb; color: #fff;
      font-size: 12.5px; font-weight: 700; border: none; cursor: pointer;
      display: inline-flex; align-items: center; gap: 6px; text-decoration: none;
    }
    .btn-submit-assign:hover { background: #1d4ed8; color: #fff; text-decoration: none; }
  </style>
</head>
<body>

<aside class="app-sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="sb-brand-icon"><i class="fa fa-graduation-cap"></i></div>
    <div class="sb-brand-text">
      <h2>CenLearn</h2>
      <p>Learn &bull; Grow &bull; Succeed</p>
    </div>
  </div>

  <nav class="sb-nav">
    <ul>
      <li><a href="dashboard"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li><a href="classes"><i class="fa fa-book"></i> My Classes</a></li>
      <li><a href="quizzes"><i class="fa fa-question-circle"></i> Quizzes</a></li>
      <li class="active"><a href="assignments"><i class="fa fa-clipboard"></i> Assignments</a></li>
      <li><a href="grades"><i class="fa fa-bar-chart"></i> Grades</a></li>
      <li><a href="attendance"><i class="fa fa-calendar"></i> Attendance</a></li>
    </ul>
  </nav>

  <div class="sb-promo-card">
    <div class="sb-promo-icon"><i class="fa fa-leaf"></i></div>
    <p>Small steps every day lead to big results.</p>
  </div>
</aside>

<div class="app-main">
  <header class="top-header">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu" style="background:none;border:none;font-size:18px;color:#0f172a;cursor:pointer;">
        <i class="fa fa-bars"></i>
      </button>
      <div class="header-search">
        <i class="fa fa-search"></i>
        <input type="text" id="assignSearch" placeholder="Search assignments..." oninput="filterAssignments()">
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

  <main class="content-body">

    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-clipboard"></i></div>
          <div class="stat-card-info">
            <label>Total Assignments</label>
            <strong><?php echo $totalCount; ?></strong>
          </div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#fffbe6;color:#d97706;"><i class="fa fa-clock-o"></i></div>
          <div class="stat-card-info">
            <label>Pending Submissions</label>
            <strong><?php echo $pendingCount; ?></strong>
          </div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#e0f2fe;color:#0284c7;"><i class="fa fa-check-circle"></i></div>
          <div class="stat-card-info">
            <label>Submitted</label>
            <strong><?php echo $submittedCount; ?></strong>
          </div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#dcfce7;color:#15803d;"><i class="fa fa-star"></i></div>
          <div class="stat-card-info">
            <label>Graded</label>
            <strong><?php echo $gradedCount; ?></strong>
          </div>
        </div>
      </div>
    </div>

    <div class="section-bar">
      <div class="section-title">
        <h2>My Assignments</h2>
        <p>Manage and submit your classwork assignments</p>
      </div>
      <div class="filter-tabs">
        <button class="tab-pill active" onclick="filterTab('all', this)">All (<?php echo $totalCount; ?>)</button>
        <button class="tab-pill" onclick="filterTab('pending', this)">Pending (<?php echo $pendingCount; ?>)</button>
        <button class="tab-pill" onclick="filterTab('submitted', this)">Submitted (<?php echo $submittedCount; ?>)</button>
        <button class="tab-pill" onclick="filterTab('graded', this)">Graded (<?php echo $gradedCount; ?>)</button>
      </div>
    </div>

    <div id="assignmentListContainer">
      <?php if ($totalCount === 0): ?>
      <div style="background:#fff;border-radius:18px;padding:48px;text-align:center;border:1px solid #e2e8f0;">
        <i class="fa fa-clipboard" style="font-size:36px;color:#cbd5e1;margin-bottom:12px;"></i>
        <h3 style="margin:0 0 4px;font-size:16px;font-weight:700;color:#0f172a;">No Assignments Found</h3>
        <p style="margin:0;font-size:13px;color:#64748b;">Assignments posted by your teachers will appear here.</p>
      </div>
      <?php else: ?>
        <?php foreach ($assignmentList as $a):
          $isSubmitted = !empty($a['submission_id']);
          $isGraded = $a['score'] !== null && $a['score'] !== '';
          $statusFilter = $isGraded ? 'graded' : ($isSubmitted ? 'submitted' : 'pending');
          $dueDateFormatted = !empty($a['due_date']) ? date('M d, Y g:i A', strtotime($a['due_date'])) : 'No Due Date';
        ?>
        <div class="assign-card" data-status="<?php echo $statusFilter; ?>" data-title="<?php echo strtolower(htmlspecialchars($a['title'] . ' ' . $a['class_name'])); ?>">
          <div class="ac-left">
            <div class="ac-icon"><i class="fa fa-file-text-o"></i></div>
            <div class="ac-details">
              <h3><?php echo htmlspecialchars($a['title']); ?></h3>
              <div class="ac-meta">
                <span><i class="fa fa-book" style="color:#2563eb;"></i> <?php echo htmlspecialchars($a['class_name']); ?></span>
                <span>&bull;</span>
                <span><i class="fa fa-user"></i> <?php echo htmlspecialchars(trim(($a['teacher_first']??'').' '.($a['teacher_last']??''))); ?></span>
                <span>&bull;</span>
                <span><i class="fa fa-clock-o"></i> Due: <?php echo $dueDateFormatted; ?></span>
                <span>&bull;</span>
                <span><strong>Points: <?php echo intval($a['points'] ?: 100); ?></strong></span>
              </div>
            </div>
          </div>

          <div style="display:flex;align-items:center;gap:14px;">
            <?php if ($isGraded): ?>
              <span class="badge-status badge-graded">Score: <?php echo $a['score']; ?> / <?php echo intval($a['points'] ?: 100); ?></span>
            <?php elseif ($isSubmitted): ?>
              <span class="badge-status badge-submitted">Submitted</span>
            <?php else: ?>
              <span class="badge-status badge-pending">Pending</span>
            <?php endif; ?>

            <button type="button" class="btn-submit-assign" onclick="openSubmitModal(<?php echo $a['id']; ?>, '<?php echo htmlspecialchars(addslashes($a['title'])); ?>', '<?php echo htmlspecialchars(addslashes($a['description'] ?? '')); ?>')">
              <i class="fa fa-upload"></i> <?php echo $isSubmitted ? 'View / Update' : 'Submit'; ?>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </main>
</div>

<!-- Submission Modal -->
<div class="modal fade" id="submitModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document" style="max-width:500px;margin:80px auto;">
    <div class="modal-content" style="border-radius:20px;border:none;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);overflow:hidden;">
      <form id="submissionForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="submit_assignment">
        <input type="hidden" name="classwork_id" id="modalClassworkId">
        <div class="modal-header" style="padding:18px 24px;border-bottom:1px solid #f1f5f9;background:#fff;display:flex;align-items:center;justify-content:space-between;">
          <h4 class="modal-title" id="modalAssignTitle" style="font-size:16px;font-weight:800;color:#0f172a;margin:0;">Submit Assignment</h4>
          <button type="button" class="close" data-dismiss="modal" style="font-size:24px;color:#94a3b8;border:none;background:none;">&times;</button>
        </div>
        <div class="modal-body" style="padding:24px;">
          <p id="modalAssignDesc" style="font-size:13px;color:#64748b;margin-bottom:16px;"></p>

          <label style="font-size:12.5px;font-weight:700;color:#334155;margin-bottom:6px;display:block;">Submission Notes / Answer:</label>
          <textarea name="submission_text" class="form-control" rows="4" placeholder="Write your submission details here..." style="border-radius:10px;border:1.5px solid #cbd5e1;padding:10px;font-size:13px;margin-bottom:16px;"></textarea>

          <label style="font-size:12.5px;font-weight:700;color:#334155;margin-bottom:6px;display:block;">Attach File (Optional):</label>
          <input type="file" name="submission_file" class="form-control" style="border-radius:10px;border:1.5px solid #cbd5e1;padding:8px;">
        </div>
        <div class="modal-footer" style="padding:14px 24px;background:#f8fafc;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-default" data-dismiss="modal" style="border-radius:99px;font-weight:600;font-size:13px;padding:8px 18px;border:1px solid #cbd5e1;background:#fff;">Cancel</button>
          <button type="submit" class="btn btn-primary" style="border-radius:99px;font-weight:700;font-size:13px;padding:8px 22px;background:#2563eb;border:none;">Submit Assignment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="/cenlearn/system/bower_components/jquery/dist/jquery.min.js"></script>
<script src="/cenlearn/system/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
function openSidebar(){ document.getElementById('sidebar').classList.add('open'); }
function filterTab(status, btn){
  document.querySelectorAll('.tab-pill').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
  document.querySelectorAll('.assign-card').forEach(function(c){
    if (status === 'all' || c.dataset.status === status) {
      c.style.display = 'flex';
    } else {
      c.style.display = 'none';
    }
  });
}
function filterAssignments(){
  var q = (document.getElementById('assignSearch').value || '').toLowerCase();
  document.querySelectorAll('.assign-card').forEach(function(c){
    var title = c.dataset.title || '';
    c.style.display = title.includes(q) ? 'flex' : 'none';
  });
}
function openSubmitModal(id, title, desc){
  $('#modalClassworkId').val(id);
  $('#modalAssignTitle').text(title);
  $('#modalAssignDesc').text(desc);
  $('#submitModal').modal('show');
}
$('#submissionForm').on('submit', function(e){
  e.preventDefault();
  var formData = new FormData(this);
  $.ajax({
    url: 'assignments',
    type: 'POST',
    data: formData,
    contentType: false,
    processData: false,
    dataType: 'json',
    success: function(res){
      if(res.success){
        alert(res.msg);
        location.reload();
      } else {
        alert(res.msg || 'Submission failed.');
      }
    }
  });
});
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
