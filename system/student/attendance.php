<?php
include '../includes/session.php';
include '../includes/conn.php';

$uc = $conn->real_escape_string($user['user_code']);
$selected_class_id = intval($_GET['class_id'] ?? 0);

// Query student attendance records
$whereClass = $selected_class_id > 0 ? "AND ar.class_id = $selected_class_id" : "";

$att_query = $conn->query("
    SELECT ar.*, s.attendance_date, s.title AS session_title, s.term, c.class_name, c.class_code,
           u.first_name AS teacher_first, u.last_name AS teacher_last
    FROM class_attendance_records ar
    JOIN class_attendance_sessions s ON ar.session_id = s.id
    JOIN classes c ON ar.class_id = c.id
    LEFT JOIN users u ON c.teacher_code = u.user_code
    WHERE ar.student_code = '$uc' $whereClass
      AND (c.is_subject_only = 0 OR c.is_subject_only IS NULL)
      AND (c.is_archived = 0 OR c.is_archived IS NULL)
    ORDER BY s.attendance_date DESC, s.id DESC
");

$attendanceLogs = [];
$totalSessions = 0;
$presentCount = 0;
$lateCount = 0;
$absentCount = 0;
$excusedCount = 0;

if ($att_query) {
    while ($row = $att_query->fetch_assoc()) {
        $attendanceLogs[] = $row;
        $totalSessions++;
        $st = strtolower($row['status']);
        if ($st === 'present') {
            $presentCount++;
        } elseif ($st === 'late') {
            $lateCount++;
        } elseif ($st === 'absent') {
            $absentCount++;
        } elseif ($st === 'excused') {
            $excusedCount++;
        }
    }
}

// Student enrolled classes dropdown
$enrolled_classes = $conn->query("
    SELECT c.id, c.class_name, c.class_code
    FROM class_members cm
    JOIN classes c ON cm.class_id = c.id
    WHERE cm.user_code = '$uc'
      AND (c.is_subject_only = 0 OR c.is_subject_only IS NULL)
      AND (c.is_archived = 0 OR c.is_archived IS NULL)
    ORDER BY c.class_name ASC
");

$attendanceRate = $totalSessions > 0 ? round((($presentCount + ($lateCount * 0.5)) / $totalSessions) * 100, 1) : 95.0;

$fullName = trim(($user['first_name'] ?? 'Student') . ' ' . ($user['last_name'] ?? 'User'));
$initials = strtoupper(substr($user['first_name'] ?? 'S', 0, 1) . substr($user['last_name'] ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CenLearn — Student Attendance</title>
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

    .att-table-card {
      background: #ffffff; border-radius: 18px; border: 1px solid #e2e8f0;
      padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.02);
    }
    .att-table { width: 100%; border-collapse: collapse; margin-top: 14px; }
    .att-table th, .att-table td { padding: 12px 16px; font-size: 13px; text-align: left; border-bottom: 1px solid #f1f5f9; }
    .att-table th { background: #f8fafc; font-weight: 700; color: #475569; }

    .status-badge-present { background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 99px; font-weight: 700; font-size: 11px; }
    .status-badge-late { background: #fffbe6; color: #d97706; padding: 4px 10px; border-radius: 99px; font-weight: 700; font-size: 11px; }
    .status-badge-absent { background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 99px; font-weight: 700; font-size: 11px; }
    .status-badge-excused { background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 99px; font-weight: 700; font-size: 11px; }
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
      <li><a href="assignments"><i class="fa fa-clipboard"></i> Assignments</a></li>
      <li><a href="grades"><i class="fa fa-bar-chart"></i> Grades</a></li>
      <li><a href="attendance" style="font-weight:700;color:#fff;"><i class="fa fa-calendar"></i> Attendance</a></li>
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
          <div class="stat-icon-circle" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-percent"></i></div>
          <div class="stat-card-info">
            <label>Attendance Rate</label>
            <strong><?php echo $attendanceRate; ?>%</strong>
          </div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#dcfce7;color:#15803d;"><i class="fa fa-check-circle"></i></div>
          <div class="stat-card-info">
            <label>Present Days</label>
            <strong><?php echo $presentCount; ?></strong>
          </div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#fffbe6;color:#d97706;"><i class="fa fa-clock-o"></i></div>
          <div class="stat-card-info">
            <label>Late Sessions</label>
            <strong><?php echo $lateCount; ?></strong>
          </div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-card-left">
          <div class="stat-icon-circle" style="background:#fee2e2;color:#991b1b;"><i class="fa fa-times-circle"></i></div>
          <div class="stat-card-info">
            <label>Absences</label>
            <strong><?php echo $absentCount; ?></strong>
          </div>
        </div>
      </div>
    </div>

    <div class="att-table-card">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
        <div>
          <h2 style="margin:0;font-size:18px;font-weight:800;color:#0f172a;">Attendance History</h2>
          <p style="margin:2px 0 0;font-size:12px;color:#64748b;">Recorded class attendance sessions</p>
        </div>

        <form method="GET" action="attendance" style="display:flex;align-items:center;gap:10px;">
          <select name="class_id" class="form-control" onchange="this.form.submit()" style="border-radius:10px;height:38px;font-size:13px;border:1.5px solid #cbd5e1;padding:0 12px;">
            <option value="0">All Classes</option>
            <?php if ($enrolled_classes): ?>
              <?php while ($ec = $enrolled_classes->fetch_assoc()): ?>
                <option value="<?php echo $ec['id']; ?>" <?php echo $selected_class_id === intval($ec['id']) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($ec['class_name']); ?> (<?php echo htmlspecialchars($ec['class_code']); ?>)
                </option>
              <?php endwhile; ?>
            <?php endif; ?>
          </select>
        </form>
      </div>

      <table class="att-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Class Name</th>
            <th>Session / Term</th>
            <th>Instructor</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($attendanceLogs)): ?>
            <tr>
              <td colspan="5" style="text-align:center;padding:32px;color:#64748b;">
                <i class="fa fa-calendar-check-o" style="font-size:24px;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
                No attendance records logged yet for this selection.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($attendanceLogs as $log):
              $st = strtolower($log['status']);
              $badgeClass = $st==='present'?'status-badge-present':($st==='late'?'status-badge-late':($st==='excused'?'status-badge-excused':'status-badge-absent'));
            ?>
            <tr>
              <td><strong><?php echo date('M d, Y', strtotime($log['attendance_date'])); ?></strong></td>
              <td><?php echo htmlspecialchars($log['class_name']); ?></td>
              <td><?php echo htmlspecialchars($log['session_title'] ?: 'Regular Session'); ?> (<?php echo ucfirst($log['term'] ?: 'Midterm'); ?>)</td>
              <td><?php echo htmlspecialchars(trim(($log['teacher_first']??'').' '.($log['teacher_last']??''))); ?></td>
              <td><span class="<?php echo $badgeClass; ?>"><?php echo ucfirst($log['status']); ?></span></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>

<script src="/cenlearn/system/bower_components/jquery/dist/jquery.min.js"></script>
<script src="/cenlearn/system/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
function openSidebar(){ document.getElementById('sidebar').classList.add('open'); }
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
