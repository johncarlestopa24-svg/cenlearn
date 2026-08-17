<?php
include '../includes/session.php';
include '../includes/conn.php';
include '../includes/programs.php';

if (strtoupper($user['user_group']) !== 'TEACHER') {
    header('location: ../index.php');
    exit;
}

$tc = $conn->real_escape_string($user['user_code']);
$initials = strtoupper(substr($user['first_name'] ?? 'T', 0, 1) . substr($user['last_name'] ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Subject &amp; Past Quiz Repository</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    *,*::before,*::after{box-sizing:border-box;}
    body{font-family:'Inter',sans-serif;background:#f0f4f8;margin:0;color:#1e293b;-webkit-font-smoothing:antialiased;}

    /* Sidebar */
    .td-sidebar{position:fixed;top:0;left:0;width:240px;height:100vh;background:linear-gradient(180deg,#0a1f0f 0%,#0d3320 55%,#065f46 100%);display:flex;flex-direction:column;z-index:200;transition:transform .25s cubic-bezier(.4,0,.2,1);transform:translateX(-240px);}
    .td-sidebar.open{transform:translateX(0);}
    @media(min-width:901px){.td-sidebar{transform:translateX(0);}}
    .sb-brand{padding:14px 16px 12px;border-bottom:1px solid rgba(255,255,255,.08);}
    .sb-logo{width:30px;height:30px;border-radius:7px;background:linear-gradient(135deg,#10b981,#059669);display:inline-flex;align-items:center;justify-content:center;margin-bottom:4px;box-shadow:0 2px 8px rgba(16,185,129,.35);}
    .sb-logo i{color:#fff;font-size:13px;}
    .sb-brand h2{color:#fff;font-size:15px;font-weight:800;margin:0;}
    .sb-brand h2 span{color:#34d399;}
    .sb-brand p{color:rgba(255,255,255,.35);font-size:9px;margin:1px 0 0;}
    .sb-nav{flex:1;padding:8px 0;overflow-y:auto;}
    .sb-section{padding:6px 16px 3px;font-size:8.5px;font-weight:700;color:rgba(255,255,255,.25);letter-spacing:1.3px;text-transform:uppercase;}
    .sb-nav ul{list-style:none;margin:0;padding:0;}
    .sb-nav li a{display:flex;align-items:center;gap:9px;padding:8px 16px;color:rgba(255,255,255,.6);text-decoration:none;font-size:12px;font-weight:500;transition:all .18s;border-left:3px solid transparent;}
    .sb-nav li a:hover{background:rgba(255,255,255,.07);color:#fff;}
    .sb-nav li.active a{background:rgba(52,211,153,.12);color:#fff;border-left-color:#34d399;}
    .sb-nav li a i{width:16px;text-align:center;font-size:13px;}
    .sb-footer{padding:10px 16px;border-top:1px solid rgba(255,255,255,.07);}
    .sb-user{display:flex;align-items:center;gap:8px;margin-bottom:6px;}
    .sb-av{width:28px;height:28px;border-radius:7px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;flex-shrink:0;}
    .sb-meta strong{display:block;color:#fff;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;}
    .sb-meta span{color:rgba(255,255,255,.4);font-size:9px;}
    .sb-out{display:flex;align-items:center;justify-content:center;gap:5px;padding:6px;width:100%;background:rgba(255,255,255,.07);color:rgba(255,255,255,.6);border:1px solid rgba(255,255,255,.1);border-radius:6px;font-size:11px;font-weight:500;text-decoration:none;transition:background .2s;}
    .sb-out:hover{background:rgba(255,255,255,.13);color:#fff;}

    /* Main Layout */
    .repo-main{margin-left:0;min-height:100vh;display:flex;flex-direction:column;}
    @media(min-width:901px){.repo-main{margin-left:240px;}}
    .repo-topbar{background:#fff;padding:0 16px;height:48px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:50;box-shadow:0 1px 3px rgba(0,0,0,.02);gap:8px;}
    .repo-content{padding:10px 14px 36px;flex:1;max-width:1380px;margin:0 auto;width:100%;}

    /* Hero */
    .repo-hero{background:linear-gradient(135deg,#0a1f0f 0%,#059669 100%);border-radius:10px;padding:10px 14px;margin-bottom:10px;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:10px;}
    .repo-hero::before{content:'';position:absolute;top:-30px;right:-30px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.06);}
    .repo-hero-text h2{color:#fff;font-size:14px;font-weight:800;margin:0 0 2px;}
    .repo-hero-text p{color:rgba(255,255,255,.82);font-size:10.5px;margin:0;line-height:1.35;}
    .repo-chips{display:flex;align-items:center;flex-wrap:wrap;gap:4px;margin-top:6px;}
    .repo-chip{display:inline-flex;align-items:center;gap:4px;background:rgba(255,255,255,.15);color:#fff;padding:2px 7px;border-radius:99px;font-size:9.5px;font-weight:600;}
    .repo-hero-icon{width:34px;height:34px;border-radius:8px;background:rgba(255,255,255,.14);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .repo-hero-icon i{font-size:15px;color:#fff;}

    /* Stats Grid */
    .repo-stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px;}
    .r-stat-card{background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:6px 10px;text-align:center;}
    .r-stat-num{font-size:17px;font-weight:800;line-height:1.1;margin-bottom:1px;}
    .r-stat-lbl{font-size:8.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;}

    /* Filter Bar */
    .filter-card{background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:7px 10px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:6px;flex-wrap:wrap;}
    .filter-group{display:flex;align-items:center;gap:6px;flex-wrap:wrap;flex:1;}
    .fc-sm{padding:4px 8px;height:30px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:11px;font-family:'Inter',sans-serif;color:#1e293b;background:#f8fafc;outline:none;cursor:pointer;transition:border-color .15s;}
    .fc-sm:focus{border-color:#10b981;background:#fff;}
    .search-wrap{position:relative;min-width:180px;flex:1;}
    .search-wrap input{width:100%;height:30px;padding:4px 8px 4px 26px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:11px;font-family:'Inter',sans-serif;outline:none;background:#fff;}
    .search-wrap input:focus{border-color:#10b981;}
    .search-wrap i{position:absolute;left:8px;top:50%;transform:translateY(-50%);font-size:10px;color:#94a3b8;}

    /* Subject Cards Grid */
    .subjects-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:8px;}
    .subj-card{background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:9px 11px;display:flex;flex-direction:column;transition:transform .15s,box-shadow .15s;}
    .subj-card:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.04);border-color:#cbd5e1;}
    .sc-header{display:flex;align-items:flex-start;justify-content:space-between;gap:6px;margin-bottom:5px;}
    .sc-title{font-size:12px;font-weight:700;color:#0f172a;margin:0 0 1px;line-height:1.25;}
    .sc-code{font-size:9.5px;color:#64748b;font-family:monospace;font-weight:600;}
    .sc-badge{padding:1.5px 5px;border-radius:4px;font-size:9px;font-weight:700;white-space:nowrap;}
    .sc-instructor{display:flex;align-items:center;gap:5px;margin:3px 0 6px;padding:4px 6px;background:#f8fafc;border-radius:6px;}
    .sc-inst-av{width:20px;height:20px;border-radius:5px;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:8.5px;font-weight:800;color:#475569;}
    .sc-inst-name{font-size:10.5px;font-weight:600;color:#334155;}
    .sc-counts{display:grid;grid-template-columns:repeat(4,1fr);gap:2px;text-align:center;padding:5px 0;border-top:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;margin-bottom:7px;}
    .sc-cnt-num{font-size:12px;font-weight:800;color:#0f172a;}
    .sc-cnt-lbl{font-size:8px;color:#64748b;text-transform:uppercase;font-weight:700;}
    .sc-actions{display:flex;align-items:center;gap:5px;margin-top:auto;}
    .btn-repo{flex:1;height:26px;padding:3px 6px;border-radius:5px;font-size:10.5px;font-weight:600;font-family:'Inter',sans-serif;text-align:center;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:4px;border:1px solid transparent;transition:all .15s;}
    .btn-repo-primary{background:linear-gradient(135deg,#10b981,#059669);color:#fff;}
    .btn-repo-primary:hover{opacity:.9;color:#fff;}
    .btn-repo-secondary{background:#f1f5f9;border-color:#e2e8f0;color:#475569;}
    .btn-repo-secondary:hover{background:#e2e8f0;color:#0f172a;}

    /* Modals */
    .q-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:7px 9px;margin-bottom:5px;}
    .q-item-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:2px;font-size:10px;font-weight:700;color:#64748b;}
    .q-text{font-size:11.5px;font-weight:600;color:#0f172a;margin-bottom:4px;}
    .q-opt{font-size:10.5px;padding:3px 6px;border-radius:4px;margin-bottom:2px;background:#fff;border:1px solid #e2e8f0;}
    .q-opt.correct{background:#dcfce7;border-color:#86efac;color:#166534;font-weight:700;}

    .an-empty{text-align:center;padding:30px 14px;color:#94a3b8;}
    .an-empty i{font-size:24px;opacity:.35;display:block;margin-bottom:6px;}
    .an-empty p{font-size:11.5px;margin:0;}
    footer.repo-footer{text-align:center;padding:10px;font-size:10.5px;color:#94a3b8;border-top:1px solid #e2e8f0;background:#fff;margin-top:auto;}

    @media(max-width:900px){
      .repo-topbar{padding:0 12px;height:44px;}
      .repo-content{padding:8px 10px 30px;}
      .repo-stats-grid{grid-template-columns:repeat(2,1fr);gap:6px;}
    }
    @media(max-width:600px){
      .repo-hero{flex-direction:column;align-items:flex-start;padding:8px 10px;}
      .repo-hero-icon{display:none;}
      .filter-card{flex-direction:column;align-items:stretch;}
      .filter-group{flex-direction:column;align-items:stretch;}
      .search-wrap{min-width:0;width:100%;}
      .fc-sm{width:100%;}
      .subjects-grid{grid-template-columns:1fr;}
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
      <li><a href="class_record.php"><i class="fa fa-table"></i> Class Record</a></li>
      <li class="active"><a href="subject_repository.php"><i class="fa fa-archive"></i> Past Subject Repository</a></li>
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

<div class="repo-main">
  <header class="repo-topbar">
    <div style="display:flex;align-items:center;gap:8px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
      <div>
        <h3 style="font-size:13.5px;font-weight:700;color:#0f172a;margin:0;">Institutional Curriculum &amp; Past Quiz Repository</h3>
        <p style="font-size:10px;color:#64748b;margin:0;">Cross-faculty historical subject explorer &amp; question bank archive</p>
      </div>
    </div>
    <button class="btn-repo btn-repo-secondary" onclick="loadSubjects()" style="padding:3px 8px;font-size:11px;height:26px;flex:none;">
      <i class="fa fa-refresh"></i> Refresh
    </button>
  </header>

  <div class="repo-content">

    <!-- Hero Banner -->
    <div class="repo-hero">
      <div class="repo-hero-text">
        <h2>Curriculum Archive &amp; Past Subjects Repository</h2>
        <p>Browse institutional subjects, inspect historical quizzes created by faculty, and import question banks for teaching continuity.</p>
        <div class="repo-chips">
          <span class="repo-chip"><i class="fa fa-institution"></i> College-Wide</span>
          <span class="repo-chip"><i class="fa fa-clone"></i> 1-Click Quiz Cloning</span>
          <span class="repo-chip"><i class="fa fa-history"></i> Past &amp; Active</span>
        </div>
      </div>
      <div class="repo-hero-icon"><i class="fa fa-archive"></i></div>
    </div>

    <!-- Stats Grid -->
    <div class="repo-stats-grid">
      <div class="r-stat-card">
        <div class="r-stat-num" id="statTotalSubs" style="color:#0f172a;">-</div>
        <div class="r-stat-lbl">Institutional Subjects</div>
      </div>
      <div class="r-stat-card">
        <div class="r-stat-num" id="statTotalQuizzes" style="color:#8b5cf6;">-</div>
        <div class="r-stat-lbl">Past &amp; Active Quizzes</div>
      </div>
      <div class="r-stat-card">
        <div class="r-stat-num" id="statTotalFaculty" style="color:#10b981;">-</div>
        <div class="r-stat-lbl">Teaching Faculty</div>
      </div>
      <div class="r-stat-card">
        <div class="r-stat-num" id="statArchived" style="color:#f59e0b;">-</div>
        <div class="r-stat-lbl">Past / Archived</div>
      </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-card">
      <div class="filter-group">
        <div class="search-wrap">
          <i class="fa fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search subject title, class code, or instructor..." onkeyup="filterSubjects()">
        </div>
        <select id="programSelect" class="fc-sm" onchange="loadSubjects()">
          <option value="">— All Programs —</option>
          <option value="IS">IS — Information Systems</option>
          <option value="CRIM">CRIM — Criminology</option>
          <option value="ARTS">ARTS — Arts (BSOA, AB)</option>
          <option value="EDUCATION">EDUCATION — Education</option>
        </select>
        <select id="statusSelect" class="fc-sm" onchange="loadSubjects()">
          <option value="all">All Semesters (Active &amp; Past)</option>
          <option value="active">Active Classes Only</option>
          <option value="archived">Past / Archived Classes Only</option>
        </select>
      </div>
    </div>

    <!-- Subjects Container -->
    <div id="subjectsContainer">
      <div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
    </div>

  </div>
  <footer class="repo-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<!-- Modal: Subject Details & Learning Materials -->
<div class="modal fade" id="subjectDetailModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border:none;border-radius:12px;overflow:hidden;">
      <div style="padding:10px 14px;background:linear-gradient(135deg,#0a1f0f,#059669);display:flex;align-items:center;justify-content:space-between;color:#fff;">
        <h4 id="sdModalTitle" style="font-size:13px;font-weight:700;margin:0;color:#fff;"><i class="fa fa-book"></i> Subject Details</h4>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;">&times;</button>
      </div>
      <div class="modal-body" style="padding:12px 14px;" id="sdModalBody">
        <div style="text-align:center;padding:20px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Past Quizzes of Subject -->
<div class="modal fade" id="pastQuizzesModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border:none;border-radius:12px;overflow:hidden;">
      <div style="padding:10px 14px;background:linear-gradient(135deg,#6d28d9,#8b5cf6);display:flex;align-items:center;justify-content:space-between;color:#fff;">
        <h4 id="pqModalTitle" style="font-size:13px;font-weight:700;margin:0;color:#fff;"><i class="fa fa-question-circle"></i> Subject Quizzes</h4>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;">&times;</button>
      </div>
      <div class="modal-body" style="padding:12px 14px;" id="pqModalBody">
        <div style="text-align:center;padding:20px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Preview Quiz Questions -->
<div class="modal fade" id="previewQuizModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border:none;border-radius:12px;overflow:hidden;">
      <div style="padding:10px 14px;background:linear-gradient(135deg,#0284c7,#0369a1);display:flex;align-items:center;justify-content:space-between;color:#fff;">
        <div>
          <h4 id="prevQuizTitle" style="font-size:13px;font-weight:700;margin:0;color:#fff;"><i class="fa fa-eye"></i> Question Bank Preview</h4>
          <span id="prevQuizMeta" style="font-size:10px;opacity:.85;"></span>
        </div>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;">&times;</button>
      </div>
      <div class="modal-body" style="padding:12px 14px;max-height:70vh;overflow-y:auto;" id="prevQuizBody">
        <div style="text-align:center;padding:20px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
      </div>
      <div class="modal-footer" style="padding:8px 14px;background:#fafbfc;border-top:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
        <span id="prevQuizFooterInfo" style="font-size:10.5px;color:#64748b;"></span>
        <button type="button" id="btnCloneFromPreview" class="btn-repo btn-repo-primary" style="padding:5px 12px;height:28px;flex:none;">
          <i class="fa fa-clone"></i> Clone This Quiz to My Class
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Clone Quiz to My Active Class -->
<div class="modal fade" id="cloneQuizModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border:none;border-radius:12px;overflow:hidden;">
      <div style="padding:10px 14px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:space-between;color:#fff;">
        <h4 style="font-size:13px;font-weight:700;margin:0;color:#fff;"><i class="fa fa-clone"></i> Clone Quiz to My Class</h4>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;">&times;</button>
      </div>
      <div class="modal-body" style="padding:14px;">
        <input type="hidden" id="clone_source_quiz_id">
        <div style="margin-bottom:10px;">
          <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:3px;">Original Quiz Title:</label>
          <div id="clone_orig_title" style="font-size:11.5px;padding:6px 8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;color:#0f172a;font-weight:600;"></div>
        </div>
        <div style="margin-bottom:10px;">
          <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:3px;">New Quiz Title in My Class:</label>
          <input type="text" id="clone_new_title" class="fc-sm" style="width:100%;" placeholder="Enter title...">
        </div>
        <div style="margin-bottom:12px;">
          <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:3px;">Select Destination Class <span style="color:#ef4444;">*</span>:</label>
          <select id="clone_target_class" class="fc-sm" style="width:100%;">
            <option value="">— Loading your active classes —</option>
          </select>
        </div>
        <div id="cloneAlert" style="display:none;font-size:11.5px;padding:7px 10px;border-radius:6px;"></div>
      </div>
      <div class="modal-footer" style="padding:10px 14px;border-top:1px solid #f1f5f9;">
        <button type="button" class="btn-repo btn-repo-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" id="btnSubmitClone" class="btn-repo btn-repo-primary" onclick="submitCloneQuiz()"><i class="fa fa-check"></i> Clone Quiz</button>
      </div>
    </div>
  </div>
</div>

<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script src="../bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
var rawSubjects = [];
var myActiveClasses = [];
var currentPreviewQuizId = 0;

function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }

$(document).ready(function(){
  loadSubjects();
  loadMyClasses();
});

function loadMyClasses(){
  $.get('../shared/repository_handler.php', { action:'get_my_active_classes' }, function(r){
    if(r.success){
      myActiveClasses = r.classes || [];
      var sel = $('#clone_target_class');
      sel.empty();
      if(myActiveClasses.length === 0){
        sel.append('<option value="">⚠️ No active classes found. Create a class first.</option>');
      } else {
        sel.append('<option value="">— Select destination class —</option>');
        myActiveClasses.forEach(function(c){
          var sec = c.section ? ' (Sec ' + c.section + ')' : '';
          sel.append('<option value="'+c.id+'">'+c.class_name + sec+'</option>');
        });
      }
    }
  }, 'json');
}

function loadSubjects(){
  $('#subjectsContainer').html('<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>');
  
  var params = {
    action: 'get_subjects',
    program_code: $('#programSelect').val(),
    status: $('#statusSelect').val(),
    search: $('#searchInput').val().trim()
  };

  $.get('../shared/repository_handler.php', params, function(r){
    if(!r.success){
      $('#subjectsContainer').html('<div class="an-empty"><p style="color:#ef4444;">'+r.msg+'</p></div>');
      return;
    }

    rawSubjects = r.subjects || [];

    // Populate stats
    $('#statTotalSubs').text(r.stats.total_subjects);
    $('#statTotalQuizzes').text(r.stats.total_quizzes);
    $('#statTotalFaculty').text(r.stats.total_faculty);
    $('#statArchived').text(r.stats.archived_count);

    renderSubjects(rawSubjects);
  }, 'json');
}

function filterSubjects(){
  var q = ($('#searchInput').val() || '').toLowerCase().trim();
  if(!q){
    renderSubjects(rawSubjects);
    return;
  }
  var filtered = rawSubjects.filter(function(s){
    var name = (s.class_name || '').toLowerCase();
    var subj = (s.subject || '').toLowerCase();
    var code = (s.class_code || '').toLowerCase();
    var tName = ((s.teacher_first_name || '') + ' ' + (s.teacher_last_name || '')).toLowerCase();
    return name.indexOf(q) !== -1 || subj.indexOf(q) !== -1 || code.indexOf(q) !== -1 || tName.indexOf(q) !== -1;
  });
  renderSubjects(filtered);
}

function renderSubjects(list){
  if(!list || list.length === 0){
    $('#subjectsContainer').html('<div class="an-empty" style="background:#fff;border-radius:9px;border:1px solid #e2e8f0;"><i class="fa fa-inbox"></i><p>No subjects found matching your filter criteria.</p></div>');
    return;
  }

  var html = '<div class="subjects-grid">';
  list.forEach(function(s){
    var isArch = s.is_archived == 1;
    var statusBadge = isArch 
      ? '<span class="sc-badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">Past</span>'
      : '<span class="sc-badge" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;">Active</span>';

    var progBadge = s.program_code ? '<span class="sc-badge" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;margin-left:3px;">'+s.program_code+'</span>' : '';
    var secTag = s.section ? ' &bull; Sec ' + s.section : '';
    var yrTag = s.year_level ? 'Yr ' + s.year_level : '';

    var tFirst = s.teacher_first_name || 'Faculty';
    var tLast  = s.teacher_last_name || '';
    var tInit  = (tFirst.charAt(0) + (tLast.charAt(0) || '')).toUpperCase();
    var isOwn  = s.is_own_class ? ' <span style="font-size:9px;background:#10b981;color:#fff;padding:1px 4px;border-radius:4px;margin-left:3px;">You</span>' : '';

    html += '<div class="subj-card">' +
            '  <div class="sc-header">' +
            '    <div>' +
            '      <h4 class="sc-title">'+(s.class_name || s.subject)+'</h4>' +
            '      <div class="sc-code">'+(s.class_code ? s.class_code : (s.subject || '')) + (yrTag ? ' &bull; ' + yrTag : '') + secTag + '</div>' +
            '    </div>' +
            '    <div>' + statusBadge + progBadge + '</div>' +
            '  </div>' +
            '  <div class="sc-instructor">' +
            '    <div class="sc-inst-av">'+tInit+'</div>' +
            '    <div class="sc-inst-name"><i class="fa fa-user" style="color:#94a3b8;font-size:9px;margin-right:2px;"></i> '+tFirst+' '+tLast + isOwn + '</div>' +
            '  </div>' +
            '  <div class="sc-counts">' +
            '    <div><div class="sc-cnt-num" style="color:#8b5cf6;">'+s.quiz_count+'</div><div class="sc-cnt-lbl">Quizzes</div></div>' +
            '    <div><div class="sc-cnt-num" style="color:#3b82f6;">'+s.module_count+'</div><div class="sc-cnt-lbl">Modules</div></div>' +
            '    <div><div class="sc-cnt-num" style="color:#f59e0b;">'+s.assign_count+'</div><div class="sc-cnt-lbl">Tasks</div></div>' +
            '    <div><div class="sc-cnt-num" style="color:#10b981;">'+s.student_count+'</div><div class="sc-cnt-lbl">Students</div></div>' +
            '  </div>' +
            '  <div class="sc-actions">' +
            '    <button class="btn-repo btn-repo-secondary" onclick="openSubjectDetails('+s.id+')"><i class="fa fa-file-text-o"></i> Materials</button>' +
            '    <button class="btn-repo btn-repo-primary" onclick="openSubjectQuizzes('+s.id+')"><i class="fa fa-question-circle"></i> Quizzes ('+s.quiz_count+')</button>' +
            '  </div>' +
            '</div>';
  });
  html += '</div>';

  $('#subjectsContainer').html(html);
}

function openSubjectDetails(classId){
  $('#sdModalTitle').html('<i class="fa fa-spinner fa-spin"></i> Loading Subject Details...');
  $('#sdModalBody').html('<div style="text-align:center;padding:24px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>');
  $('#subjectDetailModal').modal('show');

  $.get('../shared/repository_handler.php', { action:'get_subject_details', class_id:classId }, function(r){
    if(!r.success){
      $('#sdModalBody').html('<p style="color:#ef4444;">'+r.msg+'</p>');
      return;
    }
    var c = r.class;
    $('#sdModalTitle').html('<i class="fa fa-book"></i> ' + (c.class_name || c.subject) + ' &mdash; Materials &amp; Content');

    var html = '<div style="margin-bottom:10px;padding:8px 10px;background:#f8fafc;border-radius:6px;font-size:11.5px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;">' +
               '  <div><strong>Instructor:</strong> '+(c.teacher_first_name||'')+' '+(c.teacher_last_name||'')+' ('+c.teacher_code+')</div>' +
               '  <div><strong>Program:</strong> '+(c.program_code||'General')+' | <strong>Year/Sec:</strong> '+(c.year_level||'-')+'/'+(c.section||'-')+'</div>' +
               '</div>';

    html += '<h5 style="font-size:12px;font-weight:700;color:#0f172a;margin:10px 0 6px;"><i class="fa fa-folder-open" style="color:#3b82f6;"></i> Learning Modules &amp; Materials ('+r.modules.length+')</h5>';
    if(r.modules.length === 0){
      html += '<p style="font-size:11px;color:#94a3b8;font-style:italic;">No modules uploaded for this class.</p>';
    } else {
      html += '<div style="display:grid;grid-template-columns:1fr;gap:5px;margin-bottom:10px;">';
      r.modules.forEach(function(m){
        var topicTag = m.topic ? '<span style="background:#eff6ff;color:#1d4ed8;padding:1px 5px;border-radius:3px;font-size:9.5px;font-weight:600;margin-left:4px;"><i class="fa fa-tag"></i> '+m.topic+'</span>' : '';
        html += '<div style="padding:6px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;display:flex;align-items:center;justify-content:space-between;font-size:11.5px;">' +
                '  <div><strong>'+m.title+'</strong> '+topicTag+'<div style="font-size:10px;color:#64748b;">'+(m.original_name||m.file_name||'')+'</div></div>' +
                '  <a href="../uploads/modules/'+(m.file_name||'')+'" target="_blank" class="btn-repo btn-repo-secondary" style="padding:3px 7px;font-size:10px;height:24px;flex:none;"><i class="fa fa-download"></i> View</a>' +
                '</div>';
      });
      html += '</div>';
    }

    html += '<h5 style="font-size:12px;font-weight:700;color:#0f172a;margin:10px 0 6px;"><i class="fa fa-tasks" style="color:#f59e0b;"></i> Class Assignments ('+r.assignments.length+')</h5>';
    if(r.assignments.length === 0){
      html += '<p style="font-size:11px;color:#94a3b8;font-style:italic;">No assignments recorded for this class.</p>';
    } else {
      html += '<div style="display:grid;grid-template-columns:1fr;gap:5px;">';
      r.assignments.forEach(function(a){
        html += '<div style="padding:6px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;font-size:11.5px;">' +
                '  <div style="display:flex;justify-content:space-between;"><strong>'+a.title+'</strong><span style="font-size:10.5px;font-weight:700;color:#f59e0b;">'+(a.points||100)+' pts ('+(a.term||'term')+')</span></div>' +
                '  <div style="font-size:10.5px;color:#64748b;margin-top:1px;">'+(a.instructions||'No specific instructions')+'</div>' +
                '</div>';
      });
      html += '</div>';
    }

    $('#sdModalBody').html(html);
  }, 'json');
}

function openSubjectQuizzes(classId){
  $('#pqModalTitle').html('<i class="fa fa-spinner fa-spin"></i> Loading Subject Quizzes...');
  $('#pqModalBody').html('<div style="text-align:center;padding:24px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>');
  $('#pastQuizzesModal').modal('show');

  $.get('../shared/repository_handler.php', { action:'get_subject_details', class_id:classId }, function(r){
    if(!r.success){
      $('#pqModalBody').html('<p style="color:#ef4444;">'+r.msg+'</p>');
      return;
    }
    var c = r.class;
    $('#pqModalTitle').html('<i class="fa fa-question-circle"></i> ' + (c.class_name || c.subject) + ' &mdash; Past Quizzes');

    if(r.quizzes.length === 0){
      $('#pqModalBody').html('<div class="an-empty" style="padding:16px;"><i class="fa fa-question-circle"></i><p>No quizzes have been created under this subject yet.</p></div>');
      return;
    }

    var html = '<div style="display:grid;grid-template-columns:1fr;gap:6px;">';
    r.quizzes.forEach(function(q){
      var termBadge = '<span style="background:#f1f5f9;color:#475569;padding:1px 6px;border-radius:99px;font-size:9px;font-weight:700;text-transform:uppercase;">'+(q.term||'Midterm')+'</span>';
      html += '<div style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">' +
              '  <div>' +
              '    <div style="font-size:12px;font-weight:700;color:#0f172a;">'+q.title+' '+termBadge+'</div>' +
              '    <div style="font-size:10.5px;color:#64748b;margin-top:1px;">' +
              '      <strong>'+q.question_count+'</strong> questions &bull; <strong>'+q.total_points+'</strong> total pts' +
              (q.time_limit ? ' &bull; ' + q.time_limit + ' mins' : '') +
              '    </div>' +
              '  </div>' +
              '  <div style="display:flex;align-items:center;gap:5px;">' +
              '    <button class="btn-repo btn-repo-secondary" onclick="previewQuizQuestions('+q.id+')" style="padding:3px 8px;font-size:10.5px;height:24px;"><i class="fa fa-eye"></i> Preview</button>' +
              '    <button class="btn-repo btn-repo-primary" onclick="openCloneModal('+q.id+', \''+escapeHtml(q.title)+'\')" style="padding:3px 8px;font-size:10.5px;height:24px;"><i class="fa fa-clone"></i> Clone</button>' +
              '  </div>' +
              '</div>';
    });
    html += '</div>';

    $('#pqModalBody').html(html);
  }, 'json');
}

function previewQuizQuestions(quizId){
  currentPreviewQuizId = quizId;
  $('#prevQuizTitle').html('<i class="fa fa-spinner fa-spin"></i> Loading Questions...');
  $('#prevQuizBody').html('<div style="text-align:center;padding:24px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>');
  $('#previewQuizModal').modal('show');

  $.get('../shared/repository_handler.php', { action:'preview_quiz', quiz_id:quizId }, function(r){
    if(!r.success){
      $('#prevQuizBody').html('<p style="color:#ef4444;">'+r.msg+'</p>');
      return;
    }
    var qz = r.quiz;
    $('#prevQuizTitle').html('<i class="fa fa-eye"></i> ' + qz.title);
    $('#prevQuizMeta').text('Created by ' + (qz.teacher_first_name||'') + ' ' + (qz.teacher_last_name||'') + ' for ' + (qz.class_name||qz.subject||''));
    $('#prevQuizFooterInfo').text(r.questions.length + ' questions in question bank');

    $('#btnCloneFromPreview').off('click').on('click', function(){
      $('#previewQuizModal').modal('hide');
      openCloneModal(qz.id, qz.title);
    });

    if(r.questions.length === 0){
      $('#prevQuizBody').html('<div class="an-empty" style="padding:16px;"><i class="fa fa-inbox"></i><p>No questions found in this quiz.</p></div>');
      return;
    }

    var html = '';
    r.questions.forEach(function(q, i){
      var topicBadge = q.topic ? '<span style="background:#eff6ff;color:#1d4ed8;padding:1px 5px;border-radius:3px;font-size:9.5px;font-weight:600;margin-left:4px;"><i class="fa fa-tag"></i> '+q.topic+'</span>' : '';
      var typeLbl = q.question_type ? q.question_type.replace('_',' ') : 'multiple choice';

      html += '<div class="q-item">' +
              '  <div class="q-item-hdr">' +
              '    <span>Q' + (i+1) + ' &bull; ' + typeLbl.toUpperCase() + ' ' + topicBadge + '</span>' +
              '    <span>' + (q.points || 1) + ' pt' + ((q.points!=1)?'s':'') + '</span>' +
              '  </div>' +
              '  <div class="q-text">' + q.question_text + '</div>';

      if(q.options && q.options.length > 0){
        html += '<div style="margin-top:3px;">';
        q.options.forEach(function(opt){
          var isCorrect = (String(opt).trim().toLowerCase() === String(q.correct_answer).trim().toLowerCase());
          var cls = isCorrect ? 'q-opt correct' : 'q-opt';
          var check = isCorrect ? '<i class="fa fa-check-circle" style="color:#166534;margin-right:3px;"></i>' : '';
          html += '<div class="'+cls+'">'+check+opt+'</div>';
        });
        html += '</div>';
      } else if(q.correct_answer) {
        html += '<div style="font-size:10.5px;margin-top:3px;color:#166534;font-weight:700;"><i class="fa fa-check"></i> Correct Answer: ' + q.correct_answer + '</div>';
      }

      html += '</div>';
    });

    $('#prevQuizBody').html(html);
  }, 'json');
}

function openCloneModal(quizId, quizTitle){
  $('#clone_source_quiz_id').val(quizId);
  $('#clone_orig_title').text(quizTitle);
  $('#clone_new_title').val(quizTitle + ' (Imported)');
  $('#cloneAlert').hide();
  $('#cloneQuizModal').modal('show');
}

function submitCloneQuiz(){
  var srcId   = $('#clone_source_quiz_id').val();
  var trgId   = $('#clone_target_class').val();
  var title   = $('#clone_new_title').val().trim();

  if(!trgId){
    $('#cloneAlert').attr('class','alert alert-danger').text('Please select a destination class from your active classes.').show();
    return;
  }

  $('#btnSubmitClone').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Cloning...');

  $.post('../shared/repository_handler.php', {
    action: 'clone_quiz',
    source_quiz_id: srcId,
    target_class_id: trgId,
    title: title
  }, function(res){
    $('#btnSubmitClone').prop('disabled', false).html('<i class="fa fa-check"></i> Clone Quiz');
    if(res.success){
      $('#cloneAlert').attr('class','alert alert-success').html('<i class="fa fa-check-circle"></i> ' + res.msg).show();
      setTimeout(function(){
        $('#cloneQuizModal').modal('hide');
      }, 1800);
    } else {
      $('#cloneAlert').attr('class','alert alert-danger').text(res.msg).show();
    }
  }, 'json');
}

function escapeHtml(text) {
  var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
  return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}
</script>
</body>
</html>
