<?php
include '../includes/session.php';
include '../includes/conn.php';
include '../includes/programs.php';

$tc = $conn->real_escape_string($user['user_code']);

$totalClasses  = $conn->query("SELECT COUNT(*) AS c FROM classes WHERE teacher_code='$tc' AND (is_subject_only=0 OR is_subject_only IS NULL)")->fetch_assoc()['c'];
$totalStudents = $conn->query("SELECT COUNT(DISTINCT cm.user_code) AS c FROM class_members cm JOIN classes c ON cm.class_id=c.id JOIN users u ON cm.user_code=u.user_code WHERE c.teacher_code='$tc' AND u.user_group='STUDENT' AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)")->fetch_assoc()['c'];
$totalAssign   = $conn->query("SELECT COUNT(*) AS c FROM assignments a JOIN classes c ON a.class_id=c.id WHERE c.teacher_code='$tc' AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)")->fetch_assoc()['c'];
$totalQuizzes  = $conn->query("SELECT COUNT(*) AS c FROM quizzes q JOIN classes c ON q.class_id=c.id WHERE c.teacher_code='$tc' AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)")->fetch_assoc()['c'];

$recentSubs = $conn->query("
    SELECT s.submitted_at, u.first_name, u.last_name, a.title AS assign_title, c.class_name
    FROM assignment_submissions s JOIN assignments a ON s.assignment_id=a.id
    JOIN classes c ON a.class_id=c.id JOIN users u ON s.student_code=u.user_code
    WHERE c.teacher_code='$tc' AND (c.is_subject_only=0 OR c.is_subject_only IS NULL) ORDER BY s.submitted_at DESC LIMIT 6
");
$recentQuizSubs = $conn->query("
    SELECT qs.submitted_at, qs.score, qs.total_points, u.first_name, u.last_name, q.title AS quiz_title, c.class_name
    FROM quiz_submissions qs JOIN quizzes q ON qs.quiz_id=q.id
    JOIN classes c ON q.class_id=c.id JOIN users u ON qs.student_code=u.user_code
    WHERE c.teacher_code='$tc' AND (c.is_subject_only=0 OR c.is_subject_only IS NULL) ORDER BY qs.submitted_at DESC LIMIT 6
");
$liveSession = $conn->query("
    SELECT ls.*, c.class_name FROM live_sessions ls JOIN classes c ON ls.class_id=c.id
    WHERE c.teacher_code='$tc' AND ls.status='live' AND (c.is_subject_only=0 OR c.is_subject_only IS NULL) LIMIT 1
")->fetch_assoc();

$sectionsQ = $conn->query("SELECT DISTINCT section FROM users WHERE section!='' AND section IS NOT NULL AND user_group='STUDENT' ORDER BY section");
$sections = [];
while($s = $sectionsQ->fetch_assoc()) $sections[] = $s['section'];

$subjects_query = $conn->query("
    SELECT id, class_code, subject, class_name, program_code FROM classes 
    WHERE teacher_code='$tc' AND is_subject_only=1 
    ORDER BY subject ASC
");
$managed_subjects = [];
if ($subjects_query) {
    while($row = $subjects_query->fetch_assoc()) {
        $managed_subjects[] = $row;
    }
}

$initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Teacher Dashboard</title>
  <link rel="stylesheet" href="/cenlearn/system/bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/cenlearn/system/bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/cenlearn/system/dist/css/cenlearn.css">
  <style>
    *{box-sizing:border-box;}
    html,body{margin:0;padding:0;overflow-x:hidden;}
    body{font-family:'Inter',sans-serif;background:#f0f4f8;color:#1e293b;}
    
    /* Navigation Sidebar */
    .td-sidebar{position:fixed;top:0;left:0;width:240px;height:100vh;background:linear-gradient(180deg,#0a1f0f 0%,#0d3320 55%,#065f46 100%);display:flex;flex-direction:column;z-index:200;transition:transform .25s cubic-bezier(.4,0,.2,1);transform:translateX(-240px);}
    .td-sidebar.open{transform:translateX(0);}
    @media(min-width:901px){.td-sidebar{transform:translateX(0);}}
    .sb-brand{padding:18px 18px 14px;border-bottom:1px solid rgba(255,255,255,.08);}
    .sb-logo{width:34px;height:34px;border-radius:8px;background:linear-gradient(135deg,#10b981,#059669);display:inline-flex;align-items:center;justify-content:center;margin-bottom:6px;box-shadow:0 3px 10px rgba(16,185,129,.35);}
    .sb-logo i{color:#fff;font-size:15px;}
    .sb-brand h2{color:#fff;font-size:16px;font-weight:800;margin:0;}
    .sb-brand h2 span{color:#34d399;}
    .sb-brand p{color:rgba(255,255,255,.35);font-size:9.5px;margin:2px 0 0;}
    .sb-nav{flex:1;padding:10px 0;overflow-y:auto;}
    .sb-section{padding:8px 18px 4px;font-size:9px;font-weight:700;color:rgba(255,255,255,.25);letter-spacing:1.4px;text-transform:uppercase;}
    .sb-nav ul{list-style:none;margin:0;padding:0;}
    .sb-nav li a{display:flex;align-items:center;gap:10px;padding:9px 18px;color:rgba(255,255,255,.6);text-decoration:none;font-size:12.5px;font-weight:500;transition:all .18s;border-left:3px solid transparent;}
    .sb-nav li a:hover{background:rgba(255,255,255,.07);color:#fff;}
    .sb-nav li.active a{background:rgba(52,211,153,.12);color:#fff;border-left-color:#34d399;}
    .sb-nav li a i{width:17px;text-align:center;font-size:14px;}
    .sb-footer{padding:12px 18px;border-top:1px solid rgba(255,255,255,.07);}
    .sb-user{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
    .sb-av{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;flex-shrink:0;}
    .sb-meta strong{display:block;color:#fff;font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;}
    .sb-meta span{color:rgba(255,255,255,.4);font-size:9.5px;}
    .sb-out{display:flex;align-items:center;justify-content:center;gap:6px;padding:7px;width:100%;background:rgba(255,255,255,.07);color:rgba(255,255,255,.6);border:1px solid rgba(255,255,255,.1);border-radius:6px;font-size:11.5px;font-weight:500;text-decoration:none;transition:background .2s;}
    .sb-out:hover{background:rgba(255,255,255,.13);color:#fff;}

    /* Layout Main */
    .td-main{margin-left:0;min-height:100vh;display:flex;flex-direction:column;transition:margin 0s;}
    @media(min-width:901px){.td-main{margin-left:240px;}}
    .td-topbar{background:#fff;padding:8px 18px;height:auto;min-height:52px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:50;box-shadow:0 1px 4px rgba(0,0,0,.04);flex-wrap:wrap;gap:8px;}
    .td-topbar-title h3{font-size:15px;font-weight:700;color:#0f172a;margin:0;}
    .td-topbar-title p{font-size:11px;color:#64748b;margin:0;}
    .btn-green-sm{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:7px;font-size:11.5px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;text-decoration:none;transition:opacity .2s;}
    .btn-green-sm:hover{opacity:.88;color:#fff;}
    .td-content{padding:16px 18px 36px;flex:1;}
    
    /* Compact Profile Hero */
    .profile-hero{background:linear-gradient(135deg,#0a1f0f 0%,#10b981 100%);border-radius:14px;overflow:hidden;margin-bottom:16px;position:relative;}
    .ph-dots{position:absolute;inset:0;opacity:.06;background-image:radial-gradient(circle,#fff 1.5px,transparent 1.5px);background-size:24px 24px;pointer-events:none;}
    .ph-inner{position:relative;z-index:1;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
    .ph-av{width:54px;height:54px;border-radius:50%;background:rgba(255,255,255,.15);border:2.5px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:800;color:#fff;flex-shrink:0;box-shadow:0 3px 12px rgba(0,0,0,.15);}
    .ph-info{flex:1;min-width:180px;}
    .ph-info h2{font-size:18px;font-weight:800;color:#fff;margin:0 0 3px;text-shadow:0 2px 6px rgba(0,0,0,.18);}
    .ph-info .uid{font-size:11px;color:rgba(255,255,255,.65);margin-bottom:6px;font-family:monospace;letter-spacing:.8px;}
    .ph-pills{display:flex;flex-wrap:wrap;gap:6px;}
    .phpill{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.15);color:rgba(255,255,255,.92);padding:3px 10px;border-radius:99px;font-size:10.5px;font-weight:600;border:1px solid rgba(255,255,255,.2);}
    .phpill i{font-size:9.5px;opacity:.85;}
    .ph-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;flex-shrink:0;}
    .phstat{text-align:center;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:10px;padding:8px 12px;min-width:70px;}
    .phstat strong{display:block;font-size:17px;font-weight:800;color:#fff;line-height:1;}
    .phstat span{font-size:9px;color:rgba(255,255,255,.7);font-weight:600;text-transform:uppercase;letter-spacing:.4px;}
    
    /* Live banner */
    .live-banner{background:linear-gradient(135deg,#ef4444,#dc2626);border-radius:10px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:10px;}
    .live-dot{width:8px;height:8px;border-radius:50%;background:#fff;animation:blink 1.4s infinite;flex-shrink:0;}
    @keyframes blink{0%,100%{opacity:1;}50%{opacity:.3;}}
    
    /* Grid Layout */
    .td-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
    .td-card{background:#fff;border-radius:10px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.02);}
    .td-card-hdr{padding:8px 14px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;}
    .td-card-hdr h4{font-size:12px;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:6px;}
    .act-row{display:flex;align-items:center;gap:8px;padding:7px 12px;border-bottom:1px solid #f8fafc;transition:background .15s;}
    .act-row:last-child{border-bottom:none;}
    .act-row:hover{background:#f8fafc;}
    .act-ico{width:26px;height:26px;border-radius:6px;flex-shrink:0;display:flex;align-items:center;justify-content:center;}
    .act-ico i{font-size:11px;color:#fff;}
    .act-body{flex:1;min-width:0;}
    .act-body strong{display:block;font-size:12px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .act-body span{font-size:10px;color:#94a3b8;}
    .act-time{font-size:10px;color:#94a3b8;white-space:nowrap;flex-shrink:0;}
    .act-score{font-size:11px;font-weight:700;white-space:nowrap;margin-left:4px;}
    .empty-msg{text-align:center;padding:14px 12px;color:#94a3b8;font-size:11.5px;}
    .empty-msg i{display:block;font-size:16px;margin-bottom:4px;opacity:.4;}
    
    /* Quick actions */
    .qa-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px 14px;}
    .qa-btn{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:9px;border:1.5px solid #e2e8f0;background:#f8fafc;text-decoration:none;color:#374151;font-size:12px;font-weight:600;transition:all .15s;cursor:pointer;font-family:'Inter',sans-serif;}
    .qa-btn:hover{border-color:#10b981;background:#f0fdf4;color:#059669;text-decoration:none;}
    .qa-btn i{font-size:15px;}
    /* Form fields */
    .fc{width:100%;padding:8px 11px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;font-family:'Inter',sans-serif;background:#f9fafb;color:#1e293b;transition:border-color .2s;}
    .fc:focus{outline:none;border-color:#10b981;background:#fff;}
    .fc option{padding:5px;}
    .m-alert{padding:8px 12px;border-radius:8px;font-size:12px;display:flex;align-items:flex-start;gap:8px;margin-top:10px;}
    .m-alert.success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
    .m-alert.danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
    footer.td-footer{text-align:center;padding:12px;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;background:#fff;}
    
    /* Responsive Media Breakpoints */
    @media(max-width:900px){
      .td-grid{grid-template-columns:1fr;}
      .ph-inner{flex-direction:column;align-items:flex-start;}
      .ph-stats{width:100%;margin-top:4px;grid-template-columns:repeat(4,1fr);}
    }
    @media(max-width:540px){
      .ph-inner{padding:14px 14px;}
      .ph-av{width:46px;height:46px;font-size:17px;}
      .ph-info h2{font-size:16px;}
      .ph-stats{grid-template-columns:repeat(2,1fr);gap:6px;}
      .qa-grid{grid-template-columns:1fr;}
      .td-topbar{padding:6px 12px;}
      .td-content{padding:12px 12px 30px;}
      .act-row{padding:8px 12px;}
      .hide-mobile{display:none !important;}
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
      <li class="active"><a href="dashboard"><i class="fa fa-th-large"></i> Dashboard</a></li>
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
<div class="td-main">
  <header class="td-topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
      <div class="td-topbar-title">
        <h3>Dashboard</h3>
        <p><?php echo date('l, F j, Y'); ?></p>
      </div>
    </div>
  </header>
  <div class="td-content">

    <?php if($liveSession): ?>
    <div class="live-banner">
      <div class="live-dot"></div>
      <div style="flex:1;">
        <div style="font-size:13px;font-weight:700;color:#fff;">🔴 Live Session Active</div>
        <div style="font-size:12px;color:rgba(255,255,255,.85);"><?php echo htmlspecialchars($liveSession['title']?:'Live Class'); ?> &mdash; <?php echo htmlspecialchars($liveSession['class_name']); ?></div>
      </div>
      <a href="../shared/live_class?id=<?php echo $liveSession['class_id']; ?>" style="background:rgba(255,255,255,.2);color:#fff;padding:7px 16px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;border:1px solid rgba(255,255,255,.3);"><i class="fa fa-video-camera"></i> Rejoin</a>
    </div>
    <?php endif; ?>

    <!-- Profile Hero -->
    <div class="profile-hero">
      <div class="ph-dots"></div>
      <div class="ph-inner">
        <div class="ph-info">
          <h2><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></h2>
          <div class="uid"><?php echo htmlspecialchars($user['user_code']); ?></div>
          <div class="ph-pills">
            <span class="phpill"><i class="fa fa-chalkboard-teacher"></i> Teacher</span>
            <?php if(!empty($user['email_address'])): ?>
            <span class="phpill"><i class="fa fa-envelope"></i><?php echo htmlspecialchars($user['email_address']); ?></span>
            <?php endif; ?>
            <span class="phpill"><i class="fa fa-circle" style="color:#4ade80;font-size:7px;"></i> Active</span>
          </div>
        </div>
        <div class="ph-stats">
          <div class="phstat"><strong><?php echo $totalClasses; ?></strong><span>Classes</span></div>
          <div class="phstat"><strong><?php echo $totalStudents; ?></strong><span>Students</span></div>
          <div class="phstat"><strong><?php echo $totalAssign; ?></strong><span>Assignments</span></div>
          <div class="phstat"><strong><?php echo $totalQuizzes; ?></strong><span>Quizzes</span></div>
        </div>
      </div>
    </div>

    <div class="td-grid">
      <!-- Quick Actions -->
      <div class="td-card">
        <div class="td-card-hdr"><h4><i class="fa fa-bolt" style="color:#f59e0b;"></i> Quick Actions</h4></div>
        <div class="qa-grid">
          <a href="classes" class="qa-btn"><i class="fa fa-book" style="color:#10b981;"></i> Classes</a>
          <a href="quizzes" class="qa-btn"><i class="fa fa-question-circle" style="color:#8b5cf6;"></i> Quizzes</a>
          <a href="subject_repository" class="qa-btn"><i class="fa fa-archive" style="color:#0ea5e9;"></i> Past Subjects</a>
          <a href="class_record" class="qa-btn"><i class="fa fa-table" style="color:#10b981;"></i> Class Record</a>
        </div>
      </div>

      <!-- Recent Submissions -->
      <div class="td-card">
        <div class="td-card-hdr"><h4><i class="fa fa-upload" style="color:#f59e0b;"></i> Recent Submissions</h4></div>
        <?php if($recentSubs->num_rows===0): ?>
        <div class="empty-msg"><i class="fa fa-inbox"></i>No submissions yet.</div>
        <?php else: ?>
        <?php while($s=$recentSubs->fetch_assoc()): ?>
        <div class="act-row">
          <div class="act-ico" style="background:linear-gradient(135deg,#f59e0b,#d97706);"><i class="fa fa-upload"></i></div>
          <div class="act-body">
            <strong><?php echo htmlspecialchars($s['first_name'].' '.$s['last_name']); ?></strong>
            <span><?php echo htmlspecialchars($s['assign_title']); ?> &bull; <?php echo htmlspecialchars($s['class_name']); ?></span>
          </div>
          <div class="act-time"><?php echo date('M d',strtotime($s['submitted_at'])); ?></div>
        </div>
        <?php endwhile; ?>
        <?php endif; ?>
      </div>

      <!-- Recent Quiz Results -->
      <div class="td-card" style="grid-column:1/-1;">
        <div class="td-card-hdr"><h4><i class="fa fa-question-circle" style="color:#8b5cf6;"></i> Recent Quiz Results</h4></div>
        <?php if($recentQuizSubs->num_rows===0): ?>
        <div class="empty-msg"><i class="fa fa-inbox"></i>No quiz submissions yet.</div>
        <?php else: ?>
        <?php while($s=$recentQuizSubs->fetch_assoc()):
          $pct=$s['total_points']>0?round(($s['score']/$s['total_points'])*100):0;
          $clr=$pct>=75?'#10b981':($pct>=50?'#f59e0b':'#ef4444');
        ?>
        <div class="act-row">
          <div class="act-ico" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);"><i class="fa fa-check"></i></div>
          <div class="act-body">
            <strong><?php echo htmlspecialchars($s['first_name'].' '.$s['last_name']); ?></strong>
            <span><?php echo htmlspecialchars($s['quiz_title']); ?> &bull; <?php echo htmlspecialchars($s['class_name']); ?></span>
          </div>
          <div class="act-score" style="color:<?php echo $clr;?>"><?php echo $s['score'].'/'.$s['total_points']; ?> (<?php echo $pct;?>%)</div>
          <div class="act-time" style="margin-left:8px;"><?php echo date('M d',strtotime($s['submitted_at'])); ?></div>
        </div>
        <?php endwhile; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>
  <footer class="td-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<!-- Create Class Modal -->
<div class="modal fade" id="createClassModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;">
      <div style="padding:20px 24px;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#10b981,#059669);">
        <h4 style="color:#fff;font-size:16px;font-weight:700;margin:0;"><i class="fa fa-plus-circle"></i> Create New Class</h4>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;font-size:20px;">&times;</button>
      </div>
      <div class="modal-body" style="padding:24px;">
        <div style="margin-bottom:14px;">
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Course / Program <span style="color:#ef4444;">*</span></label>
          <select id="create_program" class="fc">
            <option value="">— Select course / program —</option>
            <option value="IS">IS — Information Systems</option>
            <option value="CRIM">CRIM — Criminology</option>
            <option value="ARTS">ARTS — Arts (BSOA, AB)</option>
            <option value="EDUCATION">EDUCATION — Education (BEED, BSED, BPED)</option>
          </select>
        </div>
        <div style="margin-bottom:14px;">
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Subject Name <span style="color:#ef4444;">*</span></label>
          <select id="create_subject" class="fc">
            <option value="">— Select subject —</option>
            <?php foreach($managed_subjects as $subj): ?>
            <option value="<?php echo htmlspecialchars($subj['subject']); ?>" data-program="<?php echo htmlspecialchars($subj['program_code'] ?? ''); ?>">
              <?php echo htmlspecialchars($subj['subject']); ?><?php if(!empty($subj['class_code'])): ?> (<?php echo htmlspecialchars($subj['class_code']); ?>)<?php endif; ?><?php if(!empty($subj['program_code'])): ?> [<?php echo htmlspecialchars($subj['program_code']); ?>]<?php endif; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <hr style="border:none;border-top:1px solid #f1f5f9;margin:16px 0 12px;">
        <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;margin-bottom:14px;">
          <i class="fa fa-filter" style="color:#10b981;margin-right:5px;"></i> Enrollment Restrictions — only matching students auto-enroll
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Year Level</label>
            <select id="create_year" class="fc">
              <option value="">— Any year —</option>
              <option value="1">Year 1</option>
              <option value="2">Year 2</option>
              <option value="3">Year 3</option>
              <option value="4">Year 4</option>
            </select>
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Section</label>
            <select id="create_section" class="fc">
              <option value="">— Any section —</option>
              <?php foreach($sections as $sec): ?>
              <option value="<?php echo htmlspecialchars($sec); ?>"><?php echo htmlspecialchars($sec); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div id="createAlert" style="display:none;margin-top:12px;"></div>
      </div>
      <div style="padding:16px 24px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" style="padding:10px 18px;background:#f1f5f9;color:#475569;border:none;border-radius:9px;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;" data-dismiss="modal">Cancel</button>
        <button type="button" id="btnCreate" style="padding:10px 22px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:7px;"><i class="fa fa-save"></i> Create Class</button>
      </div>
    </div>
  </div>
</div>

<?php include '../includes/scripts.php'; ?>
<script>
var rawManagedSubjects = <?php echo json_encode($managed_subjects); ?>;

function filterSubjectsByProgram() {
  var selectedProg = ($('#create_program').val() || '').trim().toUpperCase();
  var subjectSelect = $('#create_subject');
  var currentVal = subjectSelect.val();

  subjectSelect.empty();
  subjectSelect.append('<option value="">— Select subject —</option>');

  var count = 0;
  rawManagedSubjects.forEach(function(s) {
    var sProg = (s.program_code || '').trim().toUpperCase();
    var isMatch = false;

    if (!selectedProg) {
      isMatch = true;
    } else if (sProg === selectedProg) {
      isMatch = true;
    } else if (selectedProg === 'ARTS' && ['ARTS', 'BSOA', 'AB ENGLISH', 'AB HISTORY', 'AB'].indexOf(sProg) !== -1) {
      isMatch = true;
    } else if (selectedProg === 'EDUCATION' && ['EDUCATION', 'BEED', 'BPED', 'BSED', 'BSED-FILIPINO', 'BSED-MATHEMATICS', 'BSED-SOCIAL STUDIES'].indexOf(sProg) !== -1) {
      isMatch = true;
    } else if ((sProg === 'ARTS' && ['BSOA', 'AB ENGLISH', 'AB HISTORY', 'AB'].indexOf(selectedProg) !== -1) ||
               (sProg === 'EDUCATION' && ['BEED', 'BPED', 'BSED', 'BSED-FILIPINO', 'BSED-MATHEMATICS', 'BSED-SOCIAL STUDIES'].indexOf(selectedProg) !== -1)) {
      isMatch = true;
    }

    if (isMatch) {
      var codeTag = s.class_code ? ' (' + s.class_code + ')' : '';
      var progTag = s.program_code ? ' [' + s.program_code + ']' : '';
      subjectSelect.append($('<option>', {
        value: s.subject,
        text: s.subject + codeTag + progTag,
        'data-program': s.program_code || ''
      }));
      count++;
    }
  });

  if (count === 0 && selectedProg) {
    subjectSelect.append($('<option>', {
      value: '',
      text: '⚠️ No subjects created for ' + selectedProg + ' yet',
      disabled: true
    }));
  } else if (currentVal) {
    subjectSelect.val(currentVal);
  }
}

$('#create_program').on('change', filterSubjectsByProgram);

$('#create_subject').on('change', function(){
  var selectedOpt = $(this).find('option:selected');
  var prog = selectedOpt.attr('data-program');
  if (prog && !$('#create_program').val()) {
    $('#create_program').val(prog);
    filterSubjectsByProgram();
    $(this).val(selectedOpt.val());
  }
});

$('#createClassModal').on('show.bs.modal', function(){
  filterSubjectsByProgram();
});

$('#btnCreate').on('click', function(){
  var subject = $('#create_subject').val().trim();
  if(!subject){ showAlert('#createAlert','danger','Subject name is required.'); return; }
  $(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Creating...');
  $.post('/cenlearn/shared/class_save',{
    action:'create', subject:subject,
    program_code:$('#create_program').val().trim().toUpperCase(),
    year_level:$('#create_year').val(),
    section:$('#create_section').val().trim().toUpperCase()
  },function(res){
    $('#btnCreate').prop('disabled',false).html('<i class="fa fa-save"></i> Create Class');
    if(res.success){
      showAlert('#createAlert','success','Class created!'+(res.auto_enrolled>0?' &mdash; <b>'+res.auto_enrolled+'</b> student'+(res.auto_enrolled!==1?'s':'')+' auto-enrolled':''));
      setTimeout(function(){ location.reload(); }, 1600);
    } else {
      showAlert('#createAlert','danger',res.msg);
    }
  },'json');
});
function showAlert(el,type,msg){$(el).attr('class','m-alert '+type).html('<i class="fa fa-'+(type==='success'?'check-circle':'exclamation-circle')+'"></i> <span>'+msg+'</span>').show();}
function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
</script>
</body>
</html>
