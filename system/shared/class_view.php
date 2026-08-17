<?php
include '../includes/session.php';
include '../includes/conn.php';
include '../shared/analytics_engine.php';

$class_id = intval($_GET['id'] ?? 0);
$tab      = $_GET['tab'] ?? 'materials';
if($tab === 'topic_analytics') {
    $tab = 'performance';
}
$uc       = $conn->real_escape_string($user['user_code']);
$role     = strtoupper($user['user_group']);

if(!$class_id){ header('location: '.($role==='TEACHER'?'../teacher/dashboard.php':'../student/dashboard.php')); exit; }

$cq = $conn->query("SELECT c.*, u.first_name AS tf, u.last_name AS tl FROM classes c LEFT JOIN users u ON c.teacher_code=u.user_code WHERE c.id=$class_id AND (c.teacher_code='$uc' OR EXISTS (SELECT 1 FROM class_members WHERE class_id=$class_id AND user_code='$uc'))");
if($cq->num_rows === 0){ die('Access denied.'); }
$class     = $cq->fetch_assoc();
$isTeacher = (in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN']) || strcasecmp($class['teacher_code'] ?? '', $user['user_code'] ?? '') === 0);

// Get performance data if teacher
$students = [];
$onTrack = 0; $attention = 0; $atRisk = 0; $highRisk = 0; $total = 0; $avgScore = 0; $avgRisk = 0; $avgHealth = 100;
if($isTeacher){
    $students = cenlearn_class_analytics($conn, $class_id);
    $total = count($students);
    $onTrack = count(array_filter($students, fn($s) => $s['level']==='on_track'));
    $attention = count(array_filter($students, fn($s) => $s['level']==='attention'));
    $atRisk = count(array_filter($students, fn($s) => $s['level']==='at_risk'));
    $highRisk = count(array_filter($students, fn($s) => $s['level']==='high_risk'));
    $avgRisk = $total > 0 ? round(array_sum(array_column($students,'score')) / $total) : 0;
    $avgHealth = $total > 0 ? round(array_sum(array_column($students,'academic_health')) / $total) : 100;
    $avgScore = $avgRisk;
}

// ── Materials & Folders Setup ──────────────────────────────────────────────
$current_folder_id = intval($_GET['folder_id'] ?? 0);
$currentFolder = null;

if ($current_folder_id > 0) {
    $fChkQ = $conn->query("SELECT * FROM class_material_folders WHERE id=$current_folder_id AND class_id=$class_id");
    if ($fChkQ && $fChkQ->num_rows > 0) {
        $currentFolder = $fChkQ->fetch_assoc();
        // If student and not allowed to view
        if (!$isTeacher && (!$currentFolder['allow_student_view'] || !$currentFolder['is_shared'])) {
            $currentFolder = null;
            $current_folder_id = 0;
        }
    } else {
        $current_folder_id = 0;
    }
}

// Fetch folders for this class
$folderFilter = $isTeacher ? "" : " AND allow_student_view = 1 AND is_shared = 1";
$foldersQ = $conn->query("SELECT f.*, 
    (SELECT COUNT(*) FROM class_modules WHERE folder_id=f.id) AS file_count,
    (SELECT COUNT(*) FROM class_modules WHERE folder_id=f.id AND (original_name LIKE '%.ppt' OR original_name LIKE '%.pptx')) AS ppt_count
    FROM class_material_folders f 
    WHERE f.class_id=$class_id $folderFilter 
    ORDER BY f.created_at ASC");
$classFolders = [];
if ($foldersQ) {
    while ($fRow = $foldersQ->fetch_assoc()) $classFolders[] = $fRow;
}

// Fetch modules
if ($current_folder_id > 0) {
    $mq = $conn->query("SELECT * FROM class_modules WHERE class_id=$class_id AND folder_id=$current_folder_id ORDER BY uploaded_at DESC");
} else {
    $mq = $conn->query("SELECT * FROM class_modules WHERE class_id=$class_id AND (folder_id IS NULL OR folder_id = 0) ORDER BY uploaded_at DESC");
}
$modules = [];
while($m = $mq->fetch_assoc()) $modules[] = $m;

// ── Assignments ────────────────────────────────────────────────────────────
$assignments = [];
$aq = $conn->query("SELECT a.*, (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id=a.id) AS sub_count FROM assignments a WHERE a.class_id=$class_id ORDER BY a.created_at DESC");
if($aq) while($a = $aq->fetch_assoc()) $assignments[] = $a;

// ── Quizzes ────────────────────────────────────────────────────────────────
$quizzes = [];
$cNameEsc = $conn->real_escape_string($class['class_name'] ?? '');
$cSubEsc  = $conn->real_escape_string($class['subject'] ?? '');
$cTeachEsc= $conn->real_escape_string($class['teacher_code'] ?? '');
$activeCond = $isTeacher ? "" : " AND q.is_active = 1";

$qq = $conn->query("
    SELECT q.*, 
           (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=q.id) AS q_count, 
           (SELECT COUNT(DISTINCT s.student_code) FROM quiz_submissions s JOIN class_members cm ON cm.user_code = s.student_code AND cm.class_id = $class_id WHERE s.quiz_id=q.id) AS sub_count 
    FROM quizzes q 
    WHERE (
        q.class_id = $class_id 
        OR q.class_id IN (
            SELECT id FROM classes 
            WHERE teacher_code = '$cTeachEsc' 
              AND (
                (class_name = '$cNameEsc' AND '$cNameEsc' != '') 
                OR (subject = '$cNameEsc' AND '$cNameEsc' != '') 
                OR (subject = '$cSubEsc' AND '$cSubEsc' != '')
              )
        )
    ) $activeCond
    ORDER BY q.id DESC
");
if($qq) while($q = $qq->fetch_assoc()) $quizzes[] = $q;

// ── Student submission status ──────────────────────────────────────────────
$myAssignSubs = []; $myQuizSubs = [];
if(!$isTeacher){
    $as = $conn->query("SELECT assignment_id, grade FROM assignment_submissions WHERE student_code='$uc'");
    if($as) while($r = $as->fetch_assoc()) $myAssignSubs[$r['assignment_id']] = $r;
    $qs2 = $conn->query("SELECT quiz_id, score, total_points FROM quiz_submissions WHERE student_code='$uc'");
    if($qs2) while($r = $qs2->fetch_assoc()) $myQuizSubs[$r['quiz_id']] = $r;
}

function fmtSize($b){ if($b>=1048576) return round($b/1048576,1).' MB'; if($b>=1024) return round($b/1024,1).' KB'; return $b.' B'; }
function fileIcon($name){
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = ['pdf'=>['fa-file-pdf-o','#ef4444','#fef2f2'],'doc'=>['fa-file-word-o','#2563eb','#eff6ff'],'docx'=>['fa-file-word-o','#2563eb','#eff6ff'],'ppt'=>['fa-file-powerpoint-o','#ea580c','#fff7ed'],'pptx'=>['fa-file-powerpoint-o','#ea580c','#fff7ed'],'xls'=>['fa-file-excel-o','#16a34a','#f0fdf4'],'xlsx'=>['fa-file-excel-o','#16a34a','#f0fdf4'],'zip'=>['fa-file-archive-o','#7c3aed','#f5f3ff'],'png'=>['fa-file-image-o','#0891b2','#ecfeff'],'jpg'=>['fa-file-image-o','#0891b2','#ecfeff'],'jpeg'=>['fa-file-image-o','#0891b2','#ecfeff'],'txt'=>['fa-file-text-o','#64748b','#f8fafc']];
    return $map[$ext] ?? ['fa-file-o','#94a3b8','#f8fafc'];
}

$accent    = $isTeacher ? '#10b981' : '#1792bb';
$accentDk  = $isTeacher ? '#059669' : '#0f5f80';
$accentRgb = $isTeacher ? '16,185,129' : '23,146,187';
$theme     = $isTeacher ? 'theme-green' : 'theme-blue';
$dashLink  = $isTeacher ? '../teacher/dashboard.php' : '../student/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — <?php echo htmlspecialchars($class['class_name']); ?></title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <script src="../plugins/chart.umd.min.js"></script>
  <script src="../bower_components/jquery/dist/jquery.min.js"></script>
  <script src="../bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
  <script>
    var CLASS_ID = <?php echo $class_id; ?>;
    
    function escapeCqHtml(str){ return (str||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
    function escapeCqAttr(str){ return (str||'').toString().replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }

    function openModal(id){ var el = document.getElementById(id); if(el){ el.style.display='flex'; el.classList.add('open'); } }
    function closeModal(id){ var el = document.getElementById(id); if(el){ el.classList.remove('open'); el.style.display='none'; } }
    function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
    function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
    document.addEventListener('DOMContentLoaded', function(){
      document.querySelectorAll('.cv-modal-overlay').forEach(function(el){
        el.addEventListener('click', function(e){ if(e.target===el){ el.classList.remove('open'); el.style.display='none'; } });
      });
    });
    
    function showAlert(elId, type, msg){
      var el = document.getElementById(elId);
      if(!el) return;
      var ic = type==='success'?'check-circle':'exclamation-circle';
      el.style.cssText = 'padding:10px 13px;border-radius:99px;font-size:12px;display:flex;align-items:flex-start;gap:8px;margin-top:12px;'+(type==='success'?'background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;':'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;');
      el.innerHTML = '<i class="fa fa-'+ic+'"></i> <span>'+msg+'</span>';
      el.style.display = 'flex';
    }
    
    function showImportAlert(type, msg){
      showAlert('importAlert', type, msg);
    }
  </script>
  <style>
    <?php if ($isTeacher): ?>
    .t-sidebar{position:fixed;top:0;left:0;width:240px;height:100vh;background:linear-gradient(180deg,#0f2027 0%,#203a43 55%,#2c5364 100%);display:flex;flex-direction:column;z-index:200;transition:transform .25s cubic-bezier(.4,0,.2,1);transform:translateX(-240px);}
    .t-sidebar.open{transform:translateX(0);}
    @media(min-width: 901px) { .t-sidebar{transform:translateX(0);} }
    .sb-brand{padding:18px 18px 14px;border-bottom:1px solid rgba(255,255,255,.07);}
    .sb-logo{width:34px;height:34px;border-radius:8px;background:linear-gradient(135deg,#10b981,#059669);display:inline-flex;align-items:center;justify-content:center;margin-bottom:6px;box-shadow:0 3px 10px rgba(16,185,129,.35);}
    .sb-logo i{color:#fff;font-size:15px;}
    .sb-brand h2{color:#fff;font-size:16px;font-weight:800;margin:0;}
    .sb-brand h2 span{color:#10b981;}
    .sb-brand p{color:rgba(255,255,255,.3);font-size:9.5px;margin:2px 0 0;}
    .sb-nav{flex:1;padding:10px 0;overflow-y:auto;}
    .sb-nav-sec{padding:8px 18px 4px;font-size:9px;font-weight:700;color:rgba(255,255,255,.22);letter-spacing:1.4px;text-transform:uppercase;}
    .sb-nav ul{list-style:none;margin:0;padding:0;}
    .sb-nav li a{display:flex;align-items:center;gap:10px;padding:9px 18px;color:rgba(255,255,255,.55);text-decoration:none;font-size:12.5px;font-weight:500;transition:all .18s;border-left:3px solid transparent;}
    .sb-nav li a:hover{background:rgba(255,255,255,.06);color:#fff;}
    .sb-nav li.active a{background:rgba(16,185,129,.15);color:#fff;border-left-color:#10b981;}
    .sb-nav li a i{width:16px;text-align:center;font-size:13px;}
    .sb-footer{padding:12px 18px;border-top:1px solid rgba(255,255,255,.07);}
    .sb-user{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
    .sb-av{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;flex-shrink:0;}
    .sb-meta strong{display:block;color:#fff;font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;}
    .sb-meta span{color:rgba(255,255,255,.38);font-size:9.5px;}
    .sb-out{display:flex;align-items:center;justify-content:center;gap:6px;padding:7px;width:100%;background:rgba(255,255,255,.06);color:rgba(255,255,255,.55);border:1px solid rgba(255,255,255,.1);border-radius:6px;font-size:11.5px;font-weight:500;text-decoration:none;transition:all .18s;}
    .sb-out:hover{background:rgba(255,255,255,.12);color:#fff;}
    .sb-submenu{list-style:none;padding:0;margin:0;background:rgba(0,0,0,0.15);border-left:3px solid rgba(16,185,129,0.3);}
    .sb-submenu li a{padding:7px 18px 7px 34px !important;font-size:11.5px !important;color:rgba(255, 255, 255, 0.6) !important;border-left:none !important;}
    .sb-submenu li a:hover{color:#fff !important;background:rgba(255,255,255,0.05) !important;}
    .sb-submenu li.active a{color:#fff !important;background:rgba(16,185,129,0.15) !important;font-weight:700;}
    @media(min-width: 901px) { .cl-main{margin-left:240px !important;} }
    <?php endif; ?>
    html,body{margin:0;padding:0;overflow-x:hidden;}
    .cv-cover { min-height:65px;position:relative;overflow:hidden;background:linear-gradient(135deg,<?php echo $accent;?> 0%,<?php echo $accentDk;?> 100%); display:flex; align-items:flex-end; padding:12px 18px 10px; }
    .cv-cover-dots { position:absolute;inset:0;opacity:.07;background-image:radial-gradient(circle,#fff 1.5px,transparent 1.5px);background-size:20px 20px; }
    .cv-cover-fade { position:absolute;inset:0;background:linear-gradient(to bottom,transparent 30%,rgba(0,0,0,.3) 100%); }
    .cv-cover-inner { width:100%; display:flex;align-items:flex-end;justify-content:space-between;gap:12px; position:relative; z-index:2; }
    .cv-cover-left h1 { color:#fff;font-size:15px;font-weight:800;margin:0 0 3px;text-shadow:0 1.5px 4px rgba(0,0,0,.2);line-height:1.2; word-break:break-word; }
    .cv-cover-meta { display:flex;align-items:center;gap:4px;flex-wrap:wrap; }
    .cv-meta-pill { display:inline-flex;align-items:center;gap:3px;background:rgba(0,0,0,.22);color:rgba(255,255,255,.92);padding:2px 7px;border-radius:99px;font-size:9.5px;font-weight:600;backdrop-filter:blur(4px); }
    .cv-meta-pill i { font-size:8.5px;opacity:.85; }
    .cv-code-badge { display:inline-block;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);color:#fff;padding:4px 10px;border-radius:99px;font-size:12px;font-weight:800;letter-spacing:3px;font-family:monospace;backdrop-filter:blur(6px); }

    /* Compact Toolbar */
    .cv-toolbar { background:#fff;border-bottom:1px solid #e2e8f0;padding:6px 16px;height:44px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;gap:8px; }
    .cv-toolbar-left { display:flex;align-items:center;gap:6px; }
    .cv-toolbar-right { display:flex;align-items:center;gap:6px; }
     /* Compact Responsive Module Cards */
    .mod-grid { display:flex;flex-direction:column;gap:6px; }
    .mod-card { background:#fff;border:1px solid #e8edf2;border-radius:8px;display:flex;align-items:center;gap:10px;padding:8px 12px;transition:box-shadow .18s,border-color .18s; }
    .mod-card:hover { border-color:rgba(<?php echo $accentRgb;?>,.3);box-shadow:0 2px 8px rgba(<?php echo $accentRgb;?>,.08); }
    .mod-card-icon { width:32px;height:32px;border-radius:6px;flex-shrink:0;display:flex;align-items:center;justify-content:center; }
    .mod-card-icon i { font-size:14px; }
    .mod-card-body { flex:1;min-width:0; }
    .mod-card-title { font-size:12.5px;font-weight:700;color:#0f172a;margin:0 0 1px;word-break:break-word;line-height:1.3; }
    .mod-card-meta { display:flex;align-items:center;gap:8px;flex-wrap:wrap; }
    .mod-card-meta span { font-size:10px;color:#64748b;font-weight:500;display:flex;align-items:center;gap:3px; }
    .mod-card-actions { display:flex;align-items:center;gap:5px;flex-shrink:0; }
    .btn-view { display:inline-flex;align-items:center;gap:4px;padding:4px 10px;height:28px;border-radius:6px;font-size:11px;font-weight:700;font-family:'Inter',sans-serif;text-decoration:none;border:none;cursor:pointer;background:linear-gradient(135deg,<?php echo $accent;?>,<?php echo $accentDk;?>);color:#fff;box-shadow:0 2px 5px rgba(<?php echo $accentRgb;?>,.2);transition:opacity .15s; }
    .btn-view:hover { opacity:.9;text-decoration:none;color:#fff; }
    .btn-download { display:inline-flex;align-items:center;gap:4px;padding:4px 10px;height:28px;border-radius:6px;font-size:11px;font-weight:700;font-family:'Inter',sans-serif;text-decoration:none;border:none;cursor:pointer;background:rgba(<?php echo $accentRgb;?>,.1);color:<?php echo $accentDk;?>;transition:background .15s; }
    .btn-download:hover { background:rgba(<?php echo $accentRgb;?>,.2);text-decoration:none;color:<?php echo $accentDk;?>; }
    .btn-icon-del { display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:9px;background:#fef2f2;color:#dc2626;border:none;cursor:pointer;font-size:13px;transition:background .15s;text-decoration:none; }
    .btn-icon-del:hover { background:#fee2e2;color:#dc2626;text-decoration:none; }

    /* Compact Responsive Material Folders */
    .folder-grid { display:grid;grid-template-columns:repeat(auto-fill, minmax(190px, 1fr));gap:8px;margin-bottom:16px; }
    .folder-card { background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:9px 12px;display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit;transition:all .15s ease-in-out;box-shadow:0 1px 2px rgba(0,0,0,0.02);cursor:pointer; }
    .folder-card:hover { border-color:#f59e0b;background:#fffdfa;transform:translateY(-1px);box-shadow:0 3px 10px rgba(245,158,11,0.12);color:inherit;text-decoration:none; }
    .folder-card-icon { width:32px;height:32px;border-radius:7px;background:#fffbeb;border:1px solid #fef3c7;display:flex;align-items:center;justify-content:center;font-size:16px;color:#d97706;flex-shrink:0; }
    .folder-card-info { flex:1;min-width:0; }
    .folder-title { font-size:12.5px;font-weight:700;color:#0f172a;margin:0 0 2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
    .folder-meta { display:flex;align-items:center;gap:6px;font-size:10.5px;color:#64748b;font-weight:500; }
    .folder-meta span { display:inline-flex;align-items:center;gap:3px; }
    .folder-tag-pill { display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:99px;font-size:9.5px;font-weight:700; }
    .folder-tag-viewable { background:#dcfce7;color:#166534;border:1px solid #bbf7d0; }
    .folder-tag-locked { background:#fee2e2;color:#991b1b;border:1px solid #fecaca; }
    .folder-nav-bar { display:flex;align-items:center;justify-content:space-between;background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:9px 14px;margin-bottom:12px;gap:10px;flex-wrap:wrap;box-shadow:0 1px 2px rgba(0,0,0,0.02); }
    .folder-breadcrumb { display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#64748b;flex-wrap:wrap; }
    .folder-breadcrumb a { color:<?php echo $accent;?>;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:4px; }
    .folder-breadcrumb a:hover { text-decoration:underline; }

    @media(max-width:540px){
      .mod-card { padding:8px 10px;gap:8px; }
      .mod-card-actions { gap:4px; }
      .btn-view, .btn-download { padding:4px 8px;font-size:10.5px;height:26px; }
      .folder-grid { grid-template-columns:repeat(2, 1fr);gap:6px; }
      .folder-card { padding:8px 10px;gap:8px; }
      .folder-card-icon { width:28px;height:28px;font-size:14px; }
      .folder-title { font-size:11.5px; }
      .folder-meta { font-size:9.5px; }
    }

    /* Analytics & Performance Tab compact styles */
    .an-hero{background:linear-gradient(135deg,#0a1f0f 0%,#10b981 100%);border-radius:14px;padding:14px 18px;margin-bottom:14px;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:14px;}
    .an-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.05);}
    .an-hero-text h2{color:#fff;font-size:17px;font-weight:800;margin:0 0 3px;}
    .an-hero-text p{color:rgba(255,255,255,.75);font-size:11.5px;margin:0;line-height:1.4;}
    .an-hero-chips{display:flex;align-items:center;flex-wrap:wrap;gap:6px;margin-top:8px;}
    .hero-chip{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.14);color:#fff;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:600;}
    .an-hero-icon{width:42px;height:42px;border-radius:10px;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative;z-index:1;}
    .an-hero-icon i{font-size:18px;color:#fff;}
    .summary-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px;}
    .sum-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:10px 12px;text-align:center;transition:transform .15s,box-shadow .15s;position:relative;overflow:hidden;}
    .sum-card:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.04);}
    .sum-card .sc-num{font-size:22px;font-weight:800;line-height:1.1;margin-bottom:2px;}
    .sum-card .sc-lbl{font-size:10px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.4px;}
    .sum-card .sc-bar{height:3px;border-radius:99px;margin-top:6px;}
    .an-grid{display:grid;grid-template-columns:1fr 310px;gap:14px;align-items:start;}
    .an-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.02);}
    .an-card-hdr{padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
    .an-card-hdr h4{font-size:13px;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:6px;}
    .table-toolbar{padding:8px 14px;background:#fafbfc;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;}
    .tb-search{position:relative;flex:1;min-width:180px;max-width:280px;}
    .tb-search input{width:100%;padding:5px 9px 5px 28px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:11.5px;font-family:'Inter',sans-serif;outline:none;transition:border-color .15s;background:#fff;}
    .tb-search input:focus{border-color:#10b981;}
    .tb-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);font-size:11px;color:#94a3b8;}
    .risk-filter-pills{display:flex;align-items:center;gap:4px;flex-wrap:wrap;}
    .rf-pill{background:#fff;border:1px solid #e2e8f0;padding:3px 8px;border-radius:6px;font-size:10.5px;font-weight:600;color:#64748b;cursor:pointer;transition:all .15s;}
    .rf-pill:hover{background:#f1f5f9;color:#1e293b;}
    .rf-pill.active{background:#0f172a;color:#fff;border-color:#0f172a;}
    .table-responsive-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
    .stu-table{width:100%;border-collapse:collapse;min-width:620px;}
    .stu-table th{padding:9px 12px;font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e2e8f0;background:#fafbfc;text-align:left;white-space:nowrap;}
    .stu-table td{padding:9px 12px;border-bottom:1px solid #f1f5f9;font-size:12px;vertical-align:middle;}
    .stu-table tr:hover td{background:#f8fafc;}
    .stu-info{display:flex;align-items:center;gap:8px;}
    .stu-av{width:26px;height:26px;border-radius:6px;background:#e2e8f0;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#475569;flex-shrink:0;}
    .stu-name{font-weight:600;color:#0f172a;font-size:12px;line-height:1.2;}
    .stu-id{font-size:10px;color:#94a3b8;font-family:monospace;margin-top:1px;}
    .risk-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:99px;font-size:10.5px;font-weight:700;white-space:nowrap;}
    .score-wrap{display:flex;align-items:center;gap:6px;}
    .score-bar-bg{flex:1;height:5px;background:#e2e8f0;border-radius:99px;overflow:hidden;min-width:48px;}
    .score-bar-fill{height:100%;border-radius:99px;transition:width .4s ease;}
    .score-num{font-size:11.5px;font-weight:700;min-width:24px;text-align:right;}
    .detail-btn{background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b;cursor:pointer;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:4px;transition:all .15s;}
    .detail-btn:hover{background:#e2e8f0;color:#0f172a;}
    .breakdown-panel{background:#f8fafc;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;padding:12px 14px;}
    .bd-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:8px;margin-bottom:10px;}
    .bd-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;}
    .bd-card-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;font-size:11px;font-weight:700;color:#334155;}
    .bd-card-bar{height:4px;background:#f1f5f9;border-radius:99px;overflow:hidden;margin:4px 0 5px;}
    .bd-card-desc{font-size:10px;color:#64748b;line-height:1.3;}
    .bd-rec-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 10px;font-size:11px;color:#1e40af;display:flex;align-items:flex-start;gap:7px;}
    .chart-wrap{padding:14px;text-align:center;}
    .guide-row{display:flex;align-items:flex-start;gap:8px;margin-bottom:9px;}
    .guide-row:last-child{margin-bottom:0;}
    .guide-icon{width:26px;height:26px;border-radius:6px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#10b981;font-size:11px;}
    .guide-title{font-size:11.5px;font-weight:700;color:#0f172a;}
    .guide-pts{background:#f1f5f9;color:#64748b;padding:1px 6px;border-radius:99px;font-size:9.5px;font-weight:600;}
    .guide-desc{font-size:10.5px;color:#64748b;margin-top:1px;}
    .topic-section-hdr{display:flex;align-items:center;justify-content:space-between;margin:24px 0 12px;}
    .topic-section-hdr h3{font-size:14px;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:6px;}
    .topic-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    @media(max-width:1100px){
      .an-grid{grid-template-columns:1fr;}
      .summary-grid{grid-template-columns:repeat(3,1fr);}
      .topic-grid{grid-template-columns:1fr;}
    }
    @media(max-width:768px){
      .summary-grid{grid-template-columns:repeat(2,1fr);gap:8px;}
      .summary-grid .sum-card:last-child{grid-column:span 2;}
      .an-hero{padding:12px 14px;flex-direction:column;align-items:flex-start;}
      .an-hero-icon{display:none;}
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
    .cv-empty-ring { width:72px;height:72px;border-radius:50%;margin:0 auto 16px;background:linear-gradient(135deg,rgba(<?php echo $accentRgb;?>,.12),rgba(<?php echo $accentRgb;?>,.04));border:2px dashed rgba(<?php echo $accentRgb;?>,.25);display:flex;align-items:center;justify-content:center; }
    .cv-empty-ring i { font-size:26px;color:rgba(<?php echo $accentRgb;?>,.5); }
    .cv-empty h3 { font-size:16px;font-weight:700;color:#374151;margin:0 0 6px; }
    .cv-empty p  { font-size:13px;color:#94a3b8;margin:0 0 20px; }

    /* Action buttons */
    .btn-accent { display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:10px;border:none;cursor:pointer;background:linear-gradient(135deg,<?php echo $accent;?>,<?php echo $accentDk;?>);color:#fff;font-size:13px;font-weight:700;font-family:'Inter',sans-serif;box-shadow:0 4px 14px rgba(<?php echo $accentRgb;?>,.35);transition:opacity .2s,transform .1s; }
    .btn-accent:hover { opacity:.9;transform:translateY(-1px); }
    .btn-accent-sm { padding:7px 14px;font-size:12px;border-radius:8px;box-shadow:0 2px 8px rgba(<?php echo $accentRgb;?>,.25); }

    /* ── Modals shared ── */
    .cv-modal-overlay { position:fixed;inset:0;background:rgba(15,23,42,.6);display:flex;align-items:center;justify-content:center;z-index:99999;opacity:0;pointer-events:none;transition:opacity .2s;backdrop-filter:blur(4px); }
    .cv-modal-overlay.open { opacity:1;pointer-events:all; }
    .cv-modal { background:#fff;border-radius:20px;width:100%;margin:16px;box-shadow:0 28px 72px rgba(0,0,0,.25);transform:translateY(28px) scale(.96);transition:transform .22s;overflow:hidden;max-height:90vh;display:flex;flex-direction:column; }
    .cv-modal-overlay.open .cv-modal { transform:translateY(0) scale(1); }
    .cv-modal-head { padding:20px 24px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0; }
    .cv-modal-head h4 { color:#fff;font-size:16px;font-weight:700;margin:0;display:flex;align-items:center;gap:9px; }
    .cv-modal-x { width:30px;height:30px;border-radius:8px;border:none;cursor:pointer;background:rgba(255,255,255,.2);color:#fff;font-size:16px;display:flex;align-items:center;justify-content:center;transition:background .15s; }
    .cv-modal-x:hover { background:rgba(255,255,255,.32); }
    .cv-modal-body { padding:24px;overflow-y:auto;flex:1; }
    .cv-modal-foot { padding:16px 24px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0; }
    .btn-modal-cancel { padding:10px 18px;background:#f1f5f9;color:#475569;border:none;border-radius:9px;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;transition:background .15s; }
    .btn-modal-cancel:hover { background:#e2e8f0; }
    .btn-modal-ok { padding:10px 22px;border:none;border-radius:9px;cursor:pointer;background:linear-gradient(135deg,<?php echo $accent;?>,<?php echo $accentDk;?>);color:#fff;font-size:13px;font-weight:700;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:7px;box-shadow:0 3px 10px rgba(<?php echo $accentRgb;?>,.3);transition:opacity .2s; }
    .btn-modal-ok:disabled { opacity:.55;cursor:not-allowed; }

    /* Form fields */
    .cv-field { margin-bottom:16px; }
    .cv-field label { display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px; }
    .cv-field label .req { color:#ef4444; }
    .cv-fc { width:100%;padding:11px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;color:#1e293b;background:#f9fafb;font-family:'Inter',sans-serif;transition:border-color .2s,box-shadow .2s;box-sizing:border-box; }
    .cv-fc:focus { outline:none;border-color:<?php echo $accent;?>;box-shadow:0 0 0 3px rgba(<?php echo $accentRgb;?>,.12);background:#fff; }
    .cv-fc::placeholder { color:#94a3b8; }
    textarea.cv-fc { resize:vertical;min-height:80px; }

    /* Quiz builder */
    .q-builder { display:flex;flex-direction:column;gap:14px; }
    .q-item { background:#fafafa;border:1px solid #e2e8f0;border-radius:14px;padding:18px; }
    .q-item-hdr { display:flex;align-items:center;justify-content:space-between;margin-bottom:12px; }
    .q-item-hdr span { font-size:13px;font-weight:700;color:#374151;display:flex;align-items:center;gap:8px; }
    .q-options { display:flex;flex-direction:column;gap:8px;margin-top:8px; }
    .q-option-row { display:flex;align-items:center;gap:8px; }
    .q-option-row input[type=text] { flex:1;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:'Inter',sans-serif;background:#fff;transition:border-color .15s; }
    .q-option-row input[type=text]:focus { outline:none;border-color:<?php echo $accent;?>; }
    .q-option-row input[type=text][readonly] { background:#f8fafc;color:#64748b; }
    .opt-letter { width:26px;height:26px;border-radius:7px;background:#f1f5f9;color:#64748b;font-size:11px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0; }
    .opt-correct-lbl { display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:#64748b;white-space:nowrap;cursor:pointer;padding:6px 10px;border-radius:7px;border:1.5px solid #e2e8f0;background:#fff;transition:all .15s; }
    .opt-correct-lbl:has(input:checked) { background:#f0fdf4;border-color:#10b981;color:#166534; }
    .btn-add-opt { font-size:12px;color:<?php echo $accent;?>;background:none;border:1.5px dashed rgba(<?php echo $accentRgb;?>,.3);border-radius:8px;cursor:pointer;font-weight:600;padding:8px 14px;width:100%;margin-top:6px;transition:background .15s; }
    .btn-add-opt:hover { background:rgba(<?php echo $accentRgb;?>,.06); }
    .btn-rm-q { width:30px;height:30px;border-radius:7px;background:#fef2f2;color:#dc2626;border:none;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;transition:background .15s; }
    .btn-rm-q:hover { background:#fee2e2; }

    /* Submission list */
    .sub-table { width:100%;border-collapse:collapse; }
    .sub-table th { padding:10px 14px;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;background:#f8fafc;border-bottom:1px solid #e2e8f0;text-align:left; }
    .sub-table td { padding:12px 14px;font-size:13px;color:#334155;border-bottom:1px solid #f1f5f9; }
    .sub-table tbody tr:hover { background:#f8fafc; }
    .sub-table tbody tr:last-child td { border-bottom:none; }
    .grade-input { width:70px;padding:5px 8px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;font-family:'Inter',sans-serif;text-align:center; }
    .grade-input:focus { outline:none;border-color:<?php echo $accent;?>; }

    /* Quiz take modal */
    .quiz-q-block { background:#fff;border:1px solid #e8edf2;border-radius:14px;padding:18px;margin-bottom:12px;transition:border-color .15s; }
    .quiz-q-block:focus-within { border-color:rgba(<?php echo $accentRgb;?>,.3); }
    .quiz-q-text { font-size:14px;font-weight:600;color:#0f172a;margin-bottom:4px;line-height:1.5; }
    .quiz-q-pts  { font-size:11px;color:#94a3b8;margin-bottom:12px;display:flex;align-items:center;gap:4px; }
    /* Fullscreen quiz body — center content with max-width for readability */
    #takeQuizBody { background:#f8fafc; }
    #takeQuizBody > * { max-width:720px; margin-left:auto; margin-right:auto; }
    /* Override cv-modal-body padding for fullscreen */
    #takeQuizModal .cv-modal-body { padding:24px 20px; }
    .quiz-opt {
      display:flex;align-items:center;gap:12px;padding:11px 14px;
      border-radius:10px;border:1.5px solid #e2e8f0;background:#fafafa;
      cursor:pointer;margin-bottom:8px;transition:all .15s;font-size:13px;color:#374151;
    }
    .quiz-opt:hover { border-color:rgba(<?php echo $accentRgb;?>,.4);background:rgba(<?php echo $accentRgb;?>,.04); }
    .quiz-opt.selected { border-color:<?php echo $accent;?>;background:rgba(<?php echo $accentRgb;?>,.08);color:<?php echo $accentDk;?>; }
    .quiz-opt.selected span:first-child { background:<?php echo $accent;?>;color:#fff;border-color:<?php echo $accent;?>; }
    .quiz-tf { display:grid;grid-template-columns:1fr 1fr;gap:10px; }
    .quiz-id-input { width:100%;padding:11px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-family:'Inter',sans-serif;transition:border-color .2s,box-shadow .2s;box-sizing:border-box; }
    /* ── High-Visibility Student Quiz Timer ── */
    #quizTimer {
      display: none;
      align-items: center;
      gap: 7px;
      padding: 6px 14px;
      border-radius: 30px;
      font-size: 15px;
      font-weight: 800;
      font-family: 'Inter', -apple-system, monospace;
      letter-spacing: 0.5px;
      background: #ffffff;
      color: #6d28d9;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18), 0 0 0 2px rgba(255, 255, 255, 0.4);
      transition: all 0.25s ease;
      white-space: nowrap;
      user-select: none;
    }
    #quizTimer .timer-icon { font-size: 15px; color: #7c3aed; }
    #quizTimer .timer-text { font-size: 16px; font-weight: 800; font-family: 'Consolas', 'Courier New', monospace; letter-spacing: 1px; }
    #quizTimer.timer-warning { background: #fffbeb !important; color: #b45309 !important; box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35), 0 0 0 2px #f59e0b !important; }
    #quizTimer.timer-warning .timer-icon { color: #d97706 !important; }
    #quizTimer.timer-danger { background: #fef2f2 !important; color: #dc2626 !important; box-shadow: 0 4px 16px rgba(239, 68, 68, 0.45), 0 0 0 2px #ef4444 !important; animation: timerPulse 0.8s ease-in-out infinite; }
    #quizTimer.timer-danger .timer-icon { color: #dc2626 !important; }
    /* Proctoring warning banner */
    #quizViolationBar { display:none;position:sticky;top:0;z-index:10;background:#fef2f2;border-bottom:2px solid #fecaca;padding:8px 16px;font-size:12px;font-weight:700;color:#991b1b;align-items:center;gap:8px; }
    #quizViolationBar.show { display:flex; }
    .violation-count { background:#ef4444;color:#fff;border-radius:99px;padding:1px 8px;font-size:11px;margin-left:4px; }

    @media(max-width:860px){ .cv-cover-inner,.cv-toolbar,.cv-content { padding-left:20px;padding-right:20px; } .cv-content { padding-top:20px; } 
      .summary-grid { grid-template-columns: repeat(3, 1fr) !important; }
      .an-grid { grid-template-columns: 1fr !important; }
    }
    @media(max-width:540px){ .cv-cover { min-height:170px; height:auto; padding:24px 20px 24px; } .cv-cover-left h1 { font-size:20px; } .cv-cover-right { display:none; } .mod-card { flex-wrap:wrap; } 
      .summary-grid { grid-template-columns: repeat(3, 1fr) !important; gap:6px !important; }
      .sum-card { padding:8px 6px !important; min-height:62px !important; }
      .sum-card .sc-num { font-size:18px !important; }
      .sum-card .sc-lbl { font-size:9px !important; }
      .stu-table th, .stu-table td { padding:10px 8px; font-size:11px; }
      .stu-name { font-size:12px; }
    }
    @media(max-width:400px){ .format-grid { grid-template-columns:1fr !important; } 
      .summary-grid { grid-template-columns: repeat(2, 1fr) !important; gap:6px !important; }
    }

    /* Quiz Creator UI Redesign */
    .quiz-split-container {
      display: grid;
      grid-template-columns: 1.15fr 0.85fr;
      gap: 20px;
      margin-top: 14px;
      height: calc(100vh - 290px);
      min-height: 480px;
    }
    @media (max-width: 991px) {
      .quiz-split-container {
        grid-template-columns: 1fr;
        height: auto;
        min-height: 0;
      }
    }
    .quiz-left-panel, .quiz-right-panel {
      display: flex;
      flex-direction: column;
      background: #fff;
      border: 1.5px solid #e2e8f0;
      border-radius: 16px;
      padding: 18px;
      box-shadow: 0 1px 3px rgba(0,0,0,.02);
      height: 100%;
      min-height: 0;
    }
    .quiz-right-panel {
      background: #f8fafc;
    }
    .quiz-info-card {
      background: #fff;
      border: 1.5px solid #e2e8f0;
      border-radius: 16px;
      padding: 16px 20px;
      box-shadow: 0 1px 3px rgba(0,0,0,.02);
      margin-bottom: 4px;
    }
    .quiz-info-fields {
      display: grid;
      grid-template-columns: 1.5fr 1fr 1.2fr 1fr;
      gap: 16px;
    }
    @media (max-width: 768px) {
      .quiz-info-fields {
        grid-template-columns: 1fr;
        gap: 12px;
      }
    }
    .quiz-check-label {
      display: flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      font-size: 12px;
      font-weight: 600;
      color: #475569;
      background: #f1f5f9;
      padding: 4px 10px;
      border-radius: 20px;
      transition: background .15s;
    }
    .quiz-check-label input {
      width: 14px;
      height: 14px;
      accent-color: #8b5cf6;
      cursor: pointer;
      margin: 0;
    }
    .quiz-input-icon {
      position: relative;
      display: flex;
      align-items: center;
    }
    .quiz-input-icon i {
      position: absolute;
      left: 11px;
      color: #94a3b8;
      font-size: 13px;
    }
    
    /* Editor Tabs */
    .quiz-editor-tabs {
      display: flex;
      gap: 8px;
      border-bottom: 1.5px solid #e2e8f0;
      padding-bottom: 10px;
      margin-bottom: 14px;
    }
    .quiz-editor-tab {
      padding: 6px 14px;
      font-size: 13px;
      font-weight: 600;
      color: #64748b;
      cursor: pointer;
      border-radius: 8px;
      transition: all .15s;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .quiz-editor-tab.active {
      color: #6d28d9;
      background: #f5f3ff;
      box-shadow: inset 0 -2px 0 #8b5cf6;
    }
    .quiz-editor-tab.disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    
    /* Shortcut insert buttons */
    .quiz-insert-shortcuts {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 12px;
    }
    .quiz-shortcut-btn {
      border: none;
      padding: 5px 11px;
      font-size: 11px;
      font-weight: 700;
      border-radius: 6px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 5px;
      transition: transform .1s, opacity .15s;
    }
    .quiz-shortcut-btn:hover {
      opacity: 0.9;
    }
    .quiz-shortcut-btn:active {
      transform: scale(0.96);
    }
    .btn-sc-mc    { background: #ede9fe; color: #5b21b6; }
    .btn-sc-tf    { background: #dcfce7; color: #166534; }
    .btn-sc-id    { background: #fef3c7; color: #92400e; }
    .btn-sc-enum  { background: #dbeafe; color: #1d4ed8; }
    .btn-sc-mtf   { background: #fee2e2; color: #991b1b; }
    .btn-sc-essay { background: #f3f4f6; color: #374151; }

    /* Editor Layout */
    .quiz-editor-container {
      display: flex;
      flex-direction: column;
      flex: 1;
      min-height: 0;
    }
    .quiz-editor-wrapper {
      display: flex;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      background: #fff;
      overflow: hidden;
      flex: 1;
      margin-bottom: 6px;
      height: 250px;
    }
    .quiz-line-numbers {
      width: 36px;
      background: #f8fafc;
      border-right: 1px solid #e2e8f0;
      padding: 10px 6px 10px 0;
      text-align: right;
      font-family: monospace;
      font-size: 12px;
      line-height: 1.8;
      color: #94a3b8;
      user-select: none;
      overflow-y: hidden;
      box-sizing: border-box;
    }
    .quiz-line-numbers div {
      height: 21.6px; /* Line height match */
    }
    .quiz-textarea {
      flex: 1;
      padding: 10px 12px;
      border: none;
      outline: none;
      font-family: monospace;
      font-size: 12px;
      line-height: 1.8;
      resize: none;
      color: #334155;
      background: transparent;
      overflow-y: auto;
      box-sizing: border-box;
    }
    .quiz-textarea::placeholder {
      color: #cbd5e1;
    }
    .quiz-editor-meta {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 11px;
      color: #64748b;
      padding: 0 4px;
      margin-bottom: 12px;
    }
    
    /* Info banners */
    .quiz-info-banner {
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 12px;
      color: #1e40af;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 14px;
    }
    
    /* Left panel foot buttons */
    .quiz-left-foot {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      margin-top: auto;
    }
    .btn-clear-quiz {
      background: #fff;
      color: #ef4444;
      border: 1.5px solid #fecaca;
      border-radius: 10px;
      padding: 10px 16px;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all .15s;
    }
    .btn-clear-quiz:hover {
      background: #fee2e2;
      border-color: #fca5a5;
    }
    .btn-parse-quiz {
      background: linear-gradient(135deg,#8b5cf6,#6d28d9);
      color: #fff;
      border: none;
      border-radius: 10px;
      padding: 10px 20px;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 4px 12px rgba(139,92,246,.2);
      transition: all .15s;
    }
    .btn-parse-quiz:hover {
      opacity: 0.95;
      transform: translateY(-1px);
    }
    .btn-parse-quiz:active {
      transform: translateY(0);
    }
    
    /* Preview Pane styles */
    .quiz-preview-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 14px;
      border-bottom: 1.5px solid #e2e8f0;
      padding-bottom: 10px;
      flex-shrink: 0;
    }
    .quiz-preview-title {
      font-size: 14px;
      font-weight: 700;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .quiz-detected-badge {
      background: #dcfce7;
      color: #166534;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 700;
    }
    .quiz-preview-list-scroll {
      flex: 1;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 12px;
      padding-right: 4px;
    }
    
    /* Beautiful Preview Cards */
    .quiz-preview-card {
      background: #fff;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      padding: 14px 16px;
      box-shadow: 0 2px 4px rgba(0,0,0,.02);
      transition: all .2s;
      position: relative;
    }
    .quiz-preview-card:hover {
      box-shadow: 0 4px 8px rgba(0,0,0,.04);
      border-color: #cbd5e1;
    }
    .card-border-mc    { border-left: 5px solid #8b5cf6; }
    .card-border-tf    { border-left: 5px solid #10b981; }
    .card-border-id    { border-left: 5px solid #f59e0b; }
    .card-border-enum  { border-left: 5px solid #3b82f6; }
    .card-border-mtf   { border-left: 5px solid #ef4444; }
    .card-border-essay { border-left: 5px solid #6b7280; }
    
    .quiz-preview-card-head {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      margin-bottom: 12px;
    }
    .quiz-q-num {
      width: 22px;
      height: 22px;
      border-radius: 50%;
      color: #fff;
      font-size: 11px;
      font-weight: 800;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      margin-top: 1px;
    }
    .num-bg-mc    { background: #8b5cf6; }
    .num-bg-tf    { background: #10b981; }
    .num-bg-id    { background: #f59e0b; }
    .num-bg-enum  { background: #3b82f6; }
    .num-bg-mtf   { background: #ef4444; }
    .num-bg-essay { background: #6b7280; }
    
    .quiz-q-text {
      flex: 1;
      font-size: 13px;
      font-weight: 600;
      color: #0f172a;
      line-height: 1.5;
    }
    .quiz-card-badge {
      font-size: 10px;
      font-weight: 800;
      padding: 2px 7px;
      border-radius: 5px;
      text-transform: uppercase;
      flex-shrink: 0;
    }
    
    /* MC option list */
    .quiz-preview-options {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-bottom: 10px;
    }
    .quiz-preview-opt {
      padding: 6px 12px;
      border-radius: 8px;
      background: #f8fafc;
      color: #475569;
      font-size: 12px;
      border: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .quiz-preview-opt.correct {
      background: #dcfce7;
      color: #166534;
      border-color: #bbf7d0;
      font-weight: 600;
    }
    
    /* ID, Enum, MTF, Essay answers */
    .quiz-preview-answer-box {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-bottom: 10px;
    }
    .quiz-answer-col {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 6px 10px;
    }
    .quiz-answer-col.correct {
      background: #f0fdf4;
      border-color: #bbf7d0;
      color: #166534;
    }
    .quiz-col-lbl {
      font-size: 9px;
      font-weight: 700;
      color: #94a3b8;
      margin-bottom: 2px;
      text-transform: uppercase;
    }
    .quiz-col-val {
      font-size: 12px;
      font-weight: 600;
      color: #334155;
    }
    .quiz-answer-col.correct .quiz-col-val {
      color: #166534;
    }
    
    /* Inline inputs */
    .quiz-card-inputs {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 12px;
      border-top: 1px solid #f1f5f9;
      padding-top: 8px;
      margin-top: 8px;
    }
    .quiz-card-input-group {
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .quiz-card-input-group label {
      font-size: 11px;
      color: #64748b;
      font-weight: 600;
      margin: 0;
    }
    .quiz-card-input-group input {
      padding: 4px 8px;
      border: 1.5px solid #e2e8f0;
      border-radius: 6px;
      font-size: 12px;
      background: #fff;
      font-family: 'Inter', sans-serif;
      box-sizing: border-box;
    }
    
    /* Preview Foot Tip banner */
    .quiz-preview-foot-banner {
      background: #f3e8ff;
      border: 1px solid #e9d5ff;
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 12px;
      color: #6b21a8;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 12px;
      flex-shrink: 0;
    }
  </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<?php if ($isTeacher): 
  $initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
?>
<aside class="t-sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo"><i class="fa fa-graduation-cap"></i></div>
    <h2>Cen<span>Learn</span></h2>
    <p>Learning Management System</p>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-sec">Main</div>
    <ul>
      <li><a href="../teacher/dashboard.php"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li class="active">
        <a href="../teacher/classes.php"><i class="fa fa-book"></i> Classes</a>
        <ul class="sb-submenu" id="classSubmenu" style="display: block;">
          <li class="<?php echo $tab==='materials'?'active':''; ?>"><a href="class_view.php?id=<?php echo $class_id;?>&tab=materials" id="subMaterials"><i class="fa fa-folder-open"></i> Materials</a></li>
          <li class="<?php echo $tab==='classwork'?'active':''; ?>"><a href="class_view.php?id=<?php echo $class_id;?>&tab=classwork" id="subClasswork"><i class="fa fa-tasks"></i> Classwork</a></li>
          <li><a href="live_class.php?id=<?php echo $class_id;?>" id="subLiveClass"><i class="fa fa-video-camera"></i> Live Class</a></li>
          <li class="<?php echo $tab==='performance'?'active':''; ?>"><a href="class_view.php?id=<?php echo $class_id;?>&tab=performance" id="subPerformance"><i class="fa fa-line-chart"></i> Performance &amp; Analytics</a></li>
          <li><a href="class_record_detail.php?id=<?php echo $class_id;?>" id="subRecord"><i class="fa fa-book"></i> Subject Class Record</a></li>
        </ul>
      </li>
      <li><a href="../teacher/quizzes.php"><i class="fa fa-question-circle"></i> Quizzes</a></li>
      <li><a href="../teacher/assignments.php"><i class="fa fa-tasks"></i> Assignments</a></li>
      <li><a href="../teacher/attendance.php"><i class="fa fa-calendar-check-o"></i> Attendance</a></li>
      <li><a href="../teacher/logbook.php"><i class="fa fa-pencil-square-o"></i> Manage Subject</a></li>
      <li><a href="../teacher/class_record.php"><i class="fa fa-table"></i> Class Record</a></li>
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
<?php else: ?>
<aside class="cl-sidebar <?php echo $theme; ?>" id="sidebar">
  <div class="sidebar-brand">
    <div class="logo-icon" style="background:linear-gradient(135deg,<?php echo $accent;?>,<?php echo $accentDk;?>);box-shadow:0 4px 12px rgba(<?php echo $accentRgb;?>,.4);"><i class="fa fa-graduation-cap"></i></div>
    <h2>Cen<span style="color:<?php echo $accent;?>">Learn</span></h2>
    <p>Learning Management System</p>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Navigation</div>
    <ul style="list-style:none;margin:0;padding:0;">
      <li class="nav-item"><a href="<?php echo $dashLink;?>"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li class="nav-item <?php echo $tab==='materials'?'active':''; ?>"><a href="class_view.php?id=<?php echo $class_id;?>&tab=materials"><i class="fa fa-folder-open"></i> Materials</a></li>
      <li class="nav-item <?php echo $tab==='classwork'?'active':''; ?>"><a href="class_view.php?id=<?php echo $class_id;?>&tab=classwork"><i class="fa fa-tasks"></i> Classwork</a></li>
      <li class="nav-item"><a href="live_class.php?id=<?php echo $class_id;?>"><i class="fa fa-video-camera"></i> Live Class</a></li>
      <?php if($isTeacher): ?>
      <li class="nav-item <?php echo $tab==='performance'?'active':''; ?>"><a href="class_view.php?id=<?php echo $class_id;?>&tab=performance"><i class="fa fa-line-chart"></i> Performance &amp; Analytics</a></li>
      <li class="nav-item"><a href="class_record_detail.php?id=<?php echo $class_id;?>"><i class="fa fa-book"></i> Class Record</a></li>
      <?php endif; ?>    </ul>
  </nav>
  <div class="sidebar-footer">
    <div class="user-info">
      <div class="user-avatar" style="background:linear-gradient(135deg,<?php echo $accent;?>,<?php echo $accentDk;?>);"><i class="fa fa-user"></i></div>
      <div class="user-meta">
        <strong><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></strong>
        <span><?php echo $isTeacher ? 'Teacher' : 'Student'; ?></span>
      </div>
    </div>
    <a href="../logout.php" class="btn-signout"><i class="fa fa-sign-out"></i> Sign Out</a>
  </div>
</aside>
<?php endif; ?>

<!-- MAIN -->
<div class="cl-main" style="background:#f1f5f9;">

  <!-- Cover -->
  <div class="cv-cover">
    <div class="cv-cover-dots"></div>
    <div class="cv-cover-fade"></div>
    <div class="cv-cover-inner">
      <div class="cv-cover-left">
        <h1><?php echo htmlspecialchars($class['class_name']); ?></h1>
        <div class="cv-cover-meta">
          <?php if($class['subject']): ?><span class="cv-meta-pill"><i class="fa fa-book"></i><?php echo htmlspecialchars($class['subject']); ?></span><?php endif; ?>
          <?php if($class['section']): ?><span class="cv-meta-pill"><i class="fa fa-tag"></i>Section <?php echo htmlspecialchars($class['section']); ?></span><?php endif; ?>
          <?php if($class['year_level']): ?><span class="cv-meta-pill"><i class="fa fa-calendar"></i>Year <?php echo htmlspecialchars($class['year_level']); ?></span><?php endif; ?>
          <?php if($class['program_code']): ?><span class="cv-meta-pill"><i class="fa fa-university"></i><?php echo htmlspecialchars($class['program_code']); ?></span><?php endif; ?>
          <span class="cv-meta-pill"><i class="fa fa-user"></i><?php echo htmlspecialchars($class['tf'].' '.$class['tl']); ?></span>
        </div>
      </div>
      <div class="cv-cover-right"></div>
    </div>
  </div>

  <!-- Toolbar -->
  <div class="cv-toolbar">
    <div class="cv-toolbar-left"></div>
    <div class="cv-toolbar-right">
      <?php if($tab === 'materials' && $isTeacher): ?>
      <button class="btn-accent btn-accent-sm" onclick="openCreateFolderModal()"><i class="fa fa-folder"></i> + New Folder</button>
      <button class="btn-accent btn-accent-sm" onclick="openUploadModal(<?php echo $current_folder_id; ?>)"><i class="fa fa-upload"></i> Upload Module</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Content -->
  <div class="cv-content">

  <?php if($tab === 'materials'): ?>
  <!-- ═══════════════ MATERIALS TAB ═══════════════ -->

    <?php if($current_folder_id > 0 && $currentFolder): ?>
      <!-- INSIDE A FOLDER -->
      <div class="folder-nav-bar">
        <div class="folder-breadcrumb">
          <a href="class_view.php?id=<?php echo $class_id;?>&tab=materials"><i class="fa fa-folder-open-o"></i> Class Materials</a>
          <i class="fa fa-angle-right" style="color:#cbd5e1;"></i>
          <span><i class="fa fa-folder-open" style="color:#f59e0b;"></i> <?php echo htmlspecialchars($currentFolder['name']); ?></span>
          <?php if($currentFolder['allow_student_view']): ?>
            <span class="folder-tag-pill folder-tag-viewable"><i class="fa fa-eye"></i> Allowed to View</span>
          <?php else: ?>
            <span class="folder-tag-pill folder-tag-locked"><i class="fa fa-lock"></i> Teacher Only</span>
          <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
          <?php if($isTeacher): ?>
          <button class="btn btn-default btn-xs" style="border-radius:6px;font-size:11px;font-weight:700;padding:4px 10px;background:#fff;border:1px solid #d1d5db;cursor:pointer;" onclick="toggleFolderStudentView(<?php echo $current_folder_id; ?>)">
            <i class="fa <?php echo $currentFolder['allow_student_view'] ? 'fa-eye-slash text-warning' : 'fa-eye text-success'; ?>"></i> <?php echo $currentFolder['allow_student_view'] ? 'Hide from Students' : 'Send to Students (Allow View)'; ?>
          </button>
          <button class="btn btn-default btn-xs" style="border-radius:6px;font-size:11px;font-weight:700;padding:4px 10px;background:#fff;border:1px solid #d1d5db;cursor:pointer;" onclick="openEditFolderModal(<?php echo htmlspecialchars(json_encode($currentFolder), ENT_QUOTES, 'UTF-8'); ?>)">
            <i class="fa fa-pencil"></i> Edit
          </button>
          <button class="btn btn-default btn-xs" style="border-radius:6px;font-size:11px;font-weight:700;padding:4px 10px;background:#fef2f2;border:1px solid #fecaca;color:#ef4444;cursor:pointer;" onclick="deleteFolder(<?php echo $current_folder_id; ?>)">
            <i class="fa fa-trash"></i>
          </button>
          <button class="btn-accent btn-accent-sm" onclick="openUploadModal(<?php echo $current_folder_id; ?>)"><i class="fa fa-upload"></i> Upload to this Folder</button>
          <?php else: ?>
          <button class="btn-accent btn-accent-sm" onclick="openUploadModal(<?php echo $current_folder_id; ?>)"><i class="fa fa-upload"></i> Upload PPT / File</button>
          <?php endif; ?>
          <a href="class_view.php?id=<?php echo $class_id;?>&tab=materials" class="btn btn-default btn-xs" style="border-radius:6px;font-size:11px;font-weight:700;padding:4px 10px;background:#f8fafc;border:1px solid #e2e8f0;text-decoration:none;color:#475569;display:inline-flex;align-items:center;gap:4px;">
            <i class="fa fa-arrow-left"></i> All Materials
          </a>
        </div>
      </div>

      <!-- Modules inside this folder -->
      <?php if(empty($modules)): ?>
      <div class="cv-empty">
        <div class="cv-empty-ring"><i class="fa fa-folder-open-o"></i></div>
        <h3>No files in this folder</h3>
        <p><?php echo $isTeacher ? 'Upload modules or presentations for students into this folder.' : 'No files uploaded into this folder yet.'; ?></p>
      </div>
      <?php else: ?>
      <div class="mod-grid">
        <?php foreach($modules as $m): [$ico,$clr,$bg] = fileIcon($m['original_name']); 
          $isPpt = preg_match('/\.(ppt|pptx)$/i', $m['original_name']);
        ?>
        <div class="mod-card">
          <div class="mod-card-icon" style="background:<?php echo $bg;?>;"><i class="fa <?php echo $ico;?>" style="color:<?php echo $clr;?>;"></i></div>
          <div class="mod-card-body">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
              <div class="mod-card-title" title="<?php echo htmlspecialchars($m['title']); ?>"><?php echo htmlspecialchars($m['title']); ?></div>
              <?php if($isPpt): ?>
                <span style="font-size:9.5px;background:#fff7ed;color:#ea580c;border:1px solid #fed7aa;padding:1px 6px;border-radius:4px;font-weight:700;"><i class="fa fa-file-powerpoint-o"></i> PPT</span>
              <?php endif; ?>
            </div>
            <?php if(!empty($m['topic'])): ?>
              <div style="font-size:11px;color:#64748b;margin-bottom:4px;"><i class="fa fa-tag" style="margin-right:4px;"></i><?php echo htmlspecialchars($m['topic']); ?></div>
            <?php endif; ?>
            <div class="mod-card-meta">
              <span><i class="fa fa-file-o"></i><?php echo htmlspecialchars($m['original_name']); ?></span>
              <span><i class="fa fa-database"></i><?php echo fmtSize($m['file_size']); ?></span>
              <span><i class="fa fa-clock-o"></i><?php echo date('M d, Y', strtotime($m['uploaded_at'])); ?></span>
              <?php if(!empty($m['uploaded_by'])): ?>
                <span><i class="fa fa-user"></i> <?php echo htmlspecialchars($m['uploaded_by']); ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="mod-card-actions">
            <a href="module_view.php?id=<?php echo $m['id']; ?>" target="_blank" class="btn-view" style="<?php echo $isPpt ? 'background:linear-gradient(135deg,#f97316,#ea580c);' : ''; ?>">
              <i class="fa <?php echo $isPpt ? 'fa-play-circle' : 'fa-eye'; ?>"></i> <?php echo $isPpt ? 'View Presentation' : 'View Module'; ?>
            </a>
            <a href="module_download.php?id=<?php echo $m['id']; ?>" class="btn-download"><i class="fa fa-download"></i> Download</a>
            <?php if($isTeacher || ($m['uploaded_by'] === $user['user_code'])): ?>
            <button type="button" class="btn-icon-del" onclick="deleteModule(<?php echo (int)$m['id']; ?>)" title="Delete"><i class="fa fa-trash"></i></button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    <?php else: ?>
      <!-- ROOT MATERIALS VIEW -->

      <!-- 1. FOLDERS SECTION -->
      <?php if(!empty($classFolders)): ?>
      <div class="cv-section-hdr">
        <h2><i class="fa fa-folder" style="color:#f59e0b;"></i> Folders</h2>
        <span style="font-size:12px;color:#94a3b8;font-weight:500;"><?php echo count($classFolders); ?> folder<?php echo count($classFolders)!==1?'s':''; ?></span>
      </div>
      <div class="folder-grid">
        <?php foreach($classFolders as $f): ?>
        <div class="folder-card" onclick="window.location.href='class_view.php?id=<?php echo $class_id;?>&tab=materials&folder_id=<?php echo $f['id'];?>'">
          <div class="folder-card-icon">
            <i class="fa fa-folder"></i>
          </div>
          <div class="folder-card-info">
            <div class="folder-title" title="<?php echo htmlspecialchars($f['name']); ?>"><?php echo htmlspecialchars($f['name']); ?></div>
            <div class="folder-meta">
              <span><i class="fa fa-file-o"></i> <?php echo $f['file_count']; ?> file<?php echo $f['file_count']!==1?'s':''; ?></span>
              <?php if($f['allow_student_view']): ?>
                <span style="color:#166534;" title="Allowed to view"><i class="fa fa-eye"></i> Viewable</span>
              <?php else: ?>
                <span style="color:#991b1b;" title="Teacher only"><i class="fa fa-lock"></i> Locked</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- 2. FILES SECTION -->
      <div class="cv-section-hdr" style="margin-top:<?php echo !empty($classFolders)?'14px':'0'; ?>;">
        <h2><i class="fa fa-file-text-o" style="color:#3b82f6;"></i> <?php echo !empty($classFolders) ? 'Files' : 'Class Materials'; ?></h2>
        <span style="font-size:12px;color:#94a3b8;font-weight:500;"><?php echo count($modules); ?> file<?php echo count($modules)!==1?'s':''; ?></span>
      </div>

      <?php if(empty($modules) && empty($classFolders)): ?>
      <div class="cv-empty">
        <div class="cv-empty-ring"><i class="fa fa-cloud-upload"></i></div>
        <h3>No materials yet</h3>
        <p><?php echo $isTeacher ? 'Create a folder or upload your first module to get started.' : 'Your teacher hasn\'t uploaded any materials yet.'; ?></p>
        <?php if($isTeacher): ?>
          <div style="display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;">
            <button class="btn-accent" onclick="openCreateFolderModal()"><i class="fa fa-folder"></i> + New Folder</button>
            <button class="btn-accent" onclick="openUploadModal(0)"><i class="fa fa-upload"></i> Upload Module</button>
          </div>
        <?php endif; ?>
      </div>
      <?php elseif(empty($modules) && !empty($classFolders)): ?>
      <div style="background:#fff;border:1px dashed #cbd5e1;border-radius:10px;padding:24px;text-align:center;color:#64748b;margin-bottom:14px;">
        <i class="fa fa-info-circle" style="color:#94a3b8;font-size:18px;margin-bottom:6px;display:block;"></i>
        All files are organized in the folders above. Click any folder to browse and view presentation slides.
      </div>
      <?php else: ?>
      <div class="mod-grid">
        <?php foreach($modules as $m): [$ico,$clr,$bg] = fileIcon($m['original_name']); 
          $isPpt = preg_match('/\.(ppt|pptx)$/i', $m['original_name']);
        ?>
        <div class="mod-card">
          <div class="mod-card-icon" style="background:<?php echo $bg;?>;"><i class="fa <?php echo $ico;?>" style="color:<?php echo $clr;?>;"></i></div>
          <div class="mod-card-body">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
              <div class="mod-card-title" title="<?php echo htmlspecialchars($m['title']); ?>"><?php echo htmlspecialchars($m['title']); ?></div>
              <?php if($isPpt): ?>
                <span style="font-size:9.5px;background:#fff7ed;color:#ea580c;border:1px solid #fed7aa;padding:1px 6px;border-radius:4px;font-weight:700;"><i class="fa fa-file-powerpoint-o"></i> PPT Presentation</span>
              <?php endif; ?>
            </div>
            <?php if(!empty($m['topic'])): ?>
              <div style="font-size:11px;color:#64748b;margin-bottom:4px;"><i class="fa fa-tag" style="margin-right:4px;"></i><?php echo htmlspecialchars($m['topic']); ?></div>
            <?php endif; ?>
            <div class="mod-card-meta">
              <span><i class="fa fa-file-o"></i><?php echo htmlspecialchars($m['original_name']); ?></span>
              <span><i class="fa fa-database"></i><?php echo fmtSize($m['file_size']); ?></span>
              <span><i class="fa fa-clock-o"></i><?php echo date('M d, Y', strtotime($m['uploaded_at'])); ?></span>
            </div>
          </div>
          <div class="mod-card-actions">
            <a href="module_view.php?id=<?php echo $m['id']; ?>" target="_blank" class="btn-view" style="<?php echo $isPpt ? 'background:linear-gradient(135deg,#f97316,#ea580c);' : ''; ?>">
              <i class="fa <?php echo $isPpt ? 'fa-play-circle' : 'fa-eye'; ?>"></i> <?php echo $isPpt ? 'View Presentation' : 'View Module'; ?>
            </a>
            <a href="module_download.php?id=<?php echo $m['id']; ?>" class="btn-download"><i class="fa fa-download"></i> Download</a>
            <?php if($isTeacher || ($m['uploaded_by'] === $user['user_code'])): ?>
            <button type="button" class="btn-icon-del" onclick="deleteModule(<?php echo (int)$m['id']; ?>)" title="Delete"><i class="fa fa-trash"></i></button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    <?php endif; ?>

  <?php elseif($tab === 'classwork'): ?>
  <!-- ═══════════════ CLASSWORK TAB ═══════════════ -->

    <!-- ASSIGNMENTS SECTION -->
    <div class="cv-section-hdr" style="margin-bottom:12px;">
      <h2><i class="fa fa-pencil-square-o" style="color:#f59e0b;"></i> Assignments</h2>
      <span style="font-size:12px;color:#94a3b8;font-weight:500;"><?php echo count($assignments); ?> total</span>
    </div>

    <?php if(empty($assignments)): ?>
    <div class="cv-empty" style="margin-bottom:28px;">
      <div class="cv-empty-ring" style="background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(245,158,11,.04));border-color:rgba(245,158,11,.25);"><i class="fa fa-pencil-square-o" style="color:rgba(245,158,11,.5);"></i></div>
      <h3>No assignments yet</h3>
      <p><?php echo $isTeacher ? 'Create your first assignment.' : 'No assignments have been posted yet.'; ?></p>
    </div>
    <?php else: ?>
    <div class="cw-grid" style="margin-bottom:28px;">
      <?php foreach($assignments as $a):
        $isDue = $a['due_date'] && strtotime($a['due_date']) < time();
        $mySub = $myAssignSubs[$a['id']] ?? null;
      ?>
      <div class="cw-card">
        <div class="cw-card-top">
          <div class="cw-type-badge" style="background:linear-gradient(135deg,#f59e0b,#d97706);"><i class="fa fa-pencil-square-o"></i></div>
          <div class="cw-card-info">
            <div class="cw-card-title"><?php echo htmlspecialchars($a['title']); ?></div>
            <div class="cw-card-meta">
              <span><i class="fa fa-star-o"></i><?php echo $a['points']; ?> pts</span>
              <?php if($a['due_date']): ?>
              <span style="color:<?php echo $isDue?'#ef4444':'#94a3b8'; ?>;"><i class="fa fa-calendar"></i>Due <?php echo date('M d, Y g:i A', strtotime($a['due_date'])); ?></span>
              <?php endif; ?>
              <?php if($isTeacher): ?><span><i class="fa fa-users"></i><?php echo $a['sub_count']; ?> submitted</span><?php endif; ?>
            </div>
            <?php if($a['instructions']): ?><p style="font-size:12px;color:#64748b;margin:6px 0 0;"><?php echo nl2br(htmlspecialchars($a['instructions'])); ?></p><?php endif; ?>
          </div>
          <div class="cw-card-actions">
            <?php if($isTeacher): ?>
            <button type="button" class="btn-accent btn-accent-sm" style="background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:none;cursor:pointer;" onclick="viewSubmissions(<?php echo (int)$a['id']; ?>, <?php echo htmlspecialchars(json_encode($a['title']), ENT_QUOTES, 'UTF-8'); ?>)"><i class="fa fa-list"></i> Submissions</button>
            <button type="button" class="btn-icon-del" onclick="deleteAssignment(<?php echo (int)$a['id']; ?>)"><i class="fa fa-trash"></i></button>
            <?php else: ?>
              <?php if($mySub): ?>
                <?php if($mySub['grade'] !== null): ?>
                <span class="status-pill status-graded"><i class="fa fa-check"></i> Graded: <?php echo $mySub['grade']; ?>/<?php echo $a['points']; ?></span>
                <?php else: ?>
                <span class="status-pill status-submitted"><i class="fa fa-check"></i> Submitted</span>
                <?php endif; ?>
              <?php elseif(!$isDue): ?>
              <button type="button" class="btn-accent btn-accent-sm" style="background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:none;cursor:pointer;" onclick="submitAssignment(<?php echo (int)$a['id']; ?>, <?php echo htmlspecialchars(json_encode($a['title']), ENT_QUOTES, 'UTF-8'); ?>)"><i class="fa fa-upload"></i> Submit</button>
              <?php else: ?>
              <span class="status-pill status-closed"><i class="fa fa-times"></i> Past Due</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- QUIZZES SECTION -->
    <div class="cv-section-hdr" style="margin-bottom:12px;">
      <h2><i class="fa fa-question-circle" style="color:#8b5cf6;"></i> Quizzes</h2>
      <span style="font-size:12px;color:#94a3b8;font-weight:500;"><?php echo count($quizzes); ?> total</span>
    </div>

    <?php if(empty($quizzes)): ?>
    <div class="cv-empty">
      <div class="cv-empty-ring" style="background:linear-gradient(135deg,rgba(139,92,246,.12),rgba(139,92,246,.04));border-color:rgba(139,92,246,.25);"><i class="fa fa-question-circle" style="color:rgba(139,92,246,.5);"></i></div>
      <h3>No quizzes yet</h3>
      <p><?php echo $isTeacher ? 'Create your first quiz.' : 'No quizzes have been posted yet.'; ?></p>
    </div>
    <?php else: ?>
    <div class="cw-grid">
      <?php foreach($quizzes as $qz):
        $mySub = $myQuizSubs[$qz['id']] ?? null;
        $isDue = $qz['due_date'] && strtotime($qz['due_date']) < time();
        $isUpcoming = !empty($qz['start_date']) && strtotime($qz['start_date']) > time();
      ?>
      <div class="cw-card">
        <div class="cw-card-top">
          <div class="cw-type-badge" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);"><i class="fa fa-question-circle"></i></div>
          <div class="cw-card-info">
            <div class="cw-card-title"><?php echo htmlspecialchars($qz['title']); ?></div>
            <div class="cw-card-meta">
              <span><i class="fa fa-list-ol"></i><?php echo $qz['q_count']; ?> questions</span>
              <?php if($qz['time_limit']): ?><span><i class="fa fa-clock-o"></i><?php echo $qz['time_limit']; ?> min</span><?php endif; ?>
              <?php if(!empty($qz['start_date'])): ?><span style="color:#0284c7;"><i class="fa fa-clock-o"></i>Starts <?php echo date('M d, Y g:i A', strtotime($qz['start_date'])); ?></span><?php endif; ?>
              <?php if($qz['due_date']): ?><span style="color:<?php echo $isDue?'#ef4444':'#94a3b8'; ?>;"><i class="fa fa-hourglass-end"></i>Due <?php echo date('M d, Y g:i A', strtotime($qz['due_date'])); ?></span><?php endif; ?>
              <?php if($isTeacher): ?><span><i class="fa fa-users"></i><?php echo $qz['sub_count']; ?> submitted</span><?php endif; ?>
              <span class="status-pill <?php echo $qz['is_active']?'status-open':'status-closed'; ?>"><?php echo $qz['is_active']?'Open':'Closed'; ?></span>
            </div>
            <?php if($qz['instructions']): ?><p style="font-size:12px;color:#64748b;margin:6px 0 0;"><?php echo nl2br(htmlspecialchars($qz['instructions'])); ?></p><?php endif; ?>
          </div>
          <div class="cw-card-actions">
            <?php if($isTeacher): ?>
            <button type="button" class="btn-accent btn-accent-sm" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);box-shadow:none;cursor:pointer;" onclick="viewQuizResults(<?php echo (int)$qz['id']; ?>, <?php echo htmlspecialchars(json_encode($qz['title']), ENT_QUOTES, 'UTF-8'); ?>)"><i class="fa fa-bar-chart"></i> Results</button>
            <button type="button" class="btn-icon-del" onclick="deleteQuiz(<?php echo (int)$qz['id']; ?>)"><i class="fa fa-trash"></i></button>
            <?php else: ?>
              <?php if($mySub): ?>
              <span class="status-pill status-done"><i class="fa fa-check"></i> <?php echo $mySub['score']; ?>/<?php echo $mySub['total_points']; ?></span>
              <?php elseif($isUpcoming): ?>
              <span class="status-pill" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;"><i class="fa fa-lock"></i> Scheduled</span>
              <?php elseif($qz['is_active'] && !$isDue): ?>
              <button type="button" class="btn-accent btn-accent-sm" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);box-shadow:none;cursor:pointer;" onclick="takeQuiz(<?php echo (int)$qz['id']; ?>)"><i class="fa fa-play"></i> Take Quiz</button>
              <?php else: ?>
              <span class="status-pill status-closed"><i class="fa fa-times"></i> <?php echo $isDue?'Past Due':'Closed'; ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  <?php elseif($tab === 'performance' && $isTeacher): ?>
  <!-- ═══════════════ PERFORMANCE TAB (FULL ANALYTICS) ═══════════════ -->

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
          <span class="hero-chip" style="background:rgba(0,0,0,.2);"><i class="fa fa-users"></i> Class Avg Mastery: <?php echo $avgHealth; ?>%</span>
          <span class="hero-chip" style="background:rgba(0,0,0,.2);"><i class="fa fa-shield"></i> Avg Risk: <?php echo $avgRisk; ?>/100</span>
        </div>
      </div>
      <div class="an-hero-icon"><i class="fa fa-line-chart"></i></div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
      <div class="sum-card">
        <div class="sc-num" style="color:#0f172a;"><?php echo $total; ?></div>
        <div class="sc-lbl">Total Students</div>
        <div class="sc-bar" style="background:#cbd5e1;"></div>
      </div>
      <div class="sum-card">
        <div class="sc-num" style="color:#10b981;"><?php echo $onTrack; ?></div>
        <div class="sc-lbl">On Track</div>
        <div class="sc-bar" style="background:#10b981;width:<?php echo $total>0?round($onTrack/$total*100):0;?>%;"></div>
      </div>
      <div class="sum-card">
        <div class="sc-num" style="color:#f59e0b;"><?php echo $attention; ?></div>
        <div class="sc-lbl">Needs Attention</div>
        <div class="sc-bar" style="background:#f59e0b;width:<?php echo $total>0?round($attention/$total*100):0;?>%;"></div>
      </div>
      <div class="sum-card">
        <div class="sc-num" style="color:#f97316;"><?php echo $atRisk; ?></div>
        <div class="sc-lbl">At Risk</div>
        <div class="sc-bar" style="background:#f97316;width:<?php echo $total>0?round($atRisk/$total*100):0;?>%;"></div>
      </div>
      <div class="sum-card">
        <div class="sc-num" style="color:#ef4444;"><?php echo $highRisk; ?></div>
        <div class="sc-lbl">High Risk</div>
        <div class="sc-bar" style="background:#ef4444;width:<?php echo $total>0?round($highRisk/$total*100):0;?>%;"></div>
      </div>
    </div>

    <!-- Main grid: table + chart -->
    <div class="an-grid">
      <!-- Student Risk Table -->
      <div class="an-card">
        <div class="an-card-hdr">
          <h4><i class="fa fa-users" style="color:#10b981;"></i> Student Performance &amp; Risk Roster</h4>
          <span style="font-size:11.5px;color:#64748b;">Class Avg Mastery: <strong style="color:#10b981;"><?php echo $avgHealth;?>%</strong> &bull; Avg Risk: <strong style="color:#0f172a;"><?php echo $avgRisk;?>/100</strong></span>
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
        <div class="an-empty">
          <i class="fa fa-users"></i>
          <p>No students enrolled in this class yet.</p>
        </div>
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
                <span class="risk-badge" style="background:<?php echo $s['bg'];?>;color:<?php echo $s['textColor'];?>;">
                  <i class="fa fa-circle" style="font-size:6px;"></i> <?php echo $s['label'];?>
                </span>
              </td>
              <td>
                <div class="score-wrap">
                  <div class="score-bar-bg">
                    <div class="score-bar-fill" style="width:<?php echo $health;?>%;background:<?php echo $healthClr;?>;"></div>
                  </div>
                  <div class="score-num" style="color:<?php echo $healthClr;?>;"><?php echo $health;?>%</div>
                </div>
              </td>
              <td>
                <div class="score-wrap">
                  <div class="score-bar-bg">
                    <div class="score-bar-fill" style="width:<?php echo $s['score'];?>%;background:<?php echo $s['color'];?>;"></div>
                  </div>
                  <div class="score-num" style="color:<?php echo $s['color'];?>;"><?php echo $s['score'];?>/100</div>
                </div>
              </td>
              <td>
                <?php if(!empty($s['ml_active'])): ?>
                <span title="AI Confidence: <?php echo round(($s['ml_confidence']??0.85)*100);?>%"
                      style="display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:99px;font-size:10.5px;font-weight:700;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">
                  <i class="fa fa-magic" style="font-size:8.5px;"></i>
                  <?php echo htmlspecialchars($s['ml_label'] ?? 'AI Forecast');?>
                  <span style="opacity:.65;font-weight:500;"><?php echo round(($s['ml_confidence']??0.85)*100);?>%</span>
                </span>
                <?php else: ?>
                <span style="font-size:10.5px;color:#94a3b8;" title="Rule-based calculation">
                  <i class="fa fa-check-circle-o"></i> Rule-based
                </span>
                <?php endif; ?>
              </td>
              <td style="text-align:right;">
                <button class="detail-btn" onclick="toggleBreakdown(<?php echo $idx;?>)" title="View assessment breakdown">
                  <span>Breakdown</span>
                  <i class="fa fa-chevron-down" id="chevron-<?php echo $idx;?>"></i>
                </button>
              </td>
            </tr>
            <tr id="bd-<?php echo $idx;?>" class="bd-row-container" style="display:none;">
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
                        <span><i class="fa <?php echo $ic; ?>" style="color:<?php echo $bdStatusClr; ?>;margin-right:4px;"></i> <?php echo $bd['label'];?></span>
                        <span style="color:<?php echo $bdStatusClr;?>;font-weight:800;"><?php echo htmlspecialchars($bd['value']);?></span>
                      </div>
                      <div class="bd-card-bar">
                        <div style="height:100%;width:<?php echo $barFillPct;?>%;background:<?php echo $bdStatusClr;?>;border-radius:99px;"></div>
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

      <!-- Right column: chart + legend -->
      <div style="display:flex;flex-direction:column;gap:14px;">
        <!-- Donut Chart -->
        <div class="an-card">
          <div class="an-card-hdr"><h4><i class="fa fa-pie-chart" style="color:#8b5cf6;"></i> Risk Distribution</h4></div>
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

        <!-- Compact Scoring guide -->
        <div class="an-card">
          <div class="an-card-hdr"><h4><i class="fa fa-info-circle" style="color:#1792bb;"></i> Evaluation Weights</h4></div>
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
                <div class="guide-title"><?php echo $f[0];?> <span class="guide-pts"><?php echo $f[1];?></span></div>
                <div class="guide-desc"><?php echo $f[2];?></div>
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
      <span style="font-size:11.5px;color:#64748b;">Class-wide difficulty &amp; individual student weaknesses</span>
    </div>

    <div class="topic-grid">
      <div class="an-card">
        <div class="an-card-hdr">
          <h4><i class="fa fa-fire" style="color:#ef4444;"></i> Hardest Topics (Class-wide)</h4>
        </div>
        <div id="classTopicsArea" style="padding:12px 14px;">
          <div style="text-align:center;padding:24px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
        </div>
      </div>
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
  </div><!-- /.cv-content -->
  <footer class="cl-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div><!-- /.cl-main -->

<!-- ═══════════ UPLOAD MODULE MODAL ═══════════ -->
<div class="cv-modal-overlay" id="uplModal">
  <div class="cv-modal" style="max-width:500px;">
    <div class="cv-modal-head" style="background:linear-gradient(135deg,<?php echo $accent;?>,<?php echo $accentDk;?>);">
      <h4><i class="fa fa-upload"></i> Upload Learning Material / PPT</h4>
      <button class="cv-modal-x" onclick="closeModal('uplModal')">&times;</button>
    </div>
    <div class="cv-modal-body">

      <!-- Target Folder (Teachers only) -->
      <?php if($isTeacher): ?>
      <div class="cv-field">
        <label><i class="fa fa-folder-open" style="color:<?php echo $accent;?>;"></i> Target Folder</label>
        <select id="uplFolderId" class="cv-fc">
          <option value="0">&#128193; Root / General (No Folder)</option>
          <?php foreach($classFolders as $fOpt): ?>
            <option value="<?php echo $fOpt['id']; ?>" <?php echo ($current_folder_id === intval($fOpt['id'])) ? 'selected' : ''; ?>>
              &#128193; <?php echo htmlspecialchars($fOpt['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
      <input type="hidden" id="uplFolderId" value="<?php echo $current_folder_id; ?>">
      <?php endif; ?>

      <div class="cv-field" style="margin-bottom:0;">
        <label>File <span class="req">*</span></label>
        <div class="upl-drop" id="uplDrop" onclick="document.getElementById('fileInput').click()" style="border:2px dashed #d1d5db;border-radius:12px;padding:28px 20px;text-align:center;cursor:pointer;background:#f9fafb;transition:border-color .2s,background .2s;">
          <div style="width:48px;height:48px;border-radius:12px;margin:0 auto 10px;background:rgba(<?php echo $accentRgb;?>,.1);display:flex;align-items:center;justify-content:center;"><i class="fa fa-cloud-upload" style="font-size:20px;color:<?php echo $accent;?>;"></i></div>
          <p style="font-size:13px;font-weight:600;color:#374151;margin:0 0 3px;">Drop file or <strong style="color:<?php echo $accent;?>;">click to browse</strong></p>
          <p style="font-size:11px;color:#94a3b8;margin:0;">PowerPoint (.ppt, .pptx), Word, PDF, Excel, ZIP &bull; Max 50MB</p>
          <div id="uplChosen" style="display:none;margin-top:10px;padding:7px 12px;border-radius:8px;background:rgba(<?php echo $accentRgb;?>,.08);border:1px solid rgba(<?php echo $accentRgb;?>,.2);font-size:12px;font-weight:600;color:<?php echo $accentDk;?>;"><i class="fa fa-check-circle"></i> <span id="uplChosenName"></span></div>
        </div>
        <input type="file" id="fileInput" style="display:none" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip,.png,.jpg,.jpeg">
      </div>
      <div id="uplProg" style="display:none;margin-top:14px;"><div style="display:flex;justify-content:space-between;font-size:11px;color:#64748b;margin-bottom:5px;"><span><i class="fa fa-spinner fa-spin"></i> Uploading...</span><span id="uplPct">0%</span></div><div style="background:#e2e8f0;border-radius:99px;height:5px;overflow:hidden;"><div id="uplFill" style="height:100%;border-radius:99px;width:0%;background:linear-gradient(90deg,<?php echo $accent;?>,<?php echo $accentDk;?>);transition:width .2s;"></div></div></div>
      <div id="uplAlert" style="display:none;margin-top:12px;"></div>
    </div>
    <div class="cv-modal-foot">
      <button class="btn-modal-cancel" onclick="closeModal('uplModal')">Cancel</button>
      <button class="btn-modal-ok" id="btnUpload"><i class="fa fa-upload"></i> Upload</button>
    </div>
  </div>
</div>

<?php if($isTeacher): ?>
<!-- ═══════════ CREATE FOLDER MODAL ═══════════ -->
<div class="cv-modal-overlay" id="createFolderModal">
  <div class="cv-modal" style="max-width:500px;">
    <div class="cv-modal-head" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
      <h4><i class="fa fa-folder"></i> Create Material Folder</h4>
      <button class="cv-modal-x" onclick="closeModal('createFolderModal')">&times;</button>
    </div>
    <div class="cv-modal-body">
      <div class="cv-field">
        <label>Folder Name <span class="req">*</span></label>
        <input type="text" id="fName" class="cv-fc" placeholder="e.g. Student Presentations">
      </div>
      <div class="cv-field" style="background:#f8fafc;padding:12px 14px;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:0;">
        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin:0;">
          <input type="checkbox" id="fAllowView" checked style="width:18px;height:18px;margin-top:2px;accent-color:#10b981;cursor:pointer;">
          <div>
            <strong style="font-size:13px;color:#0f172a;display:block;"><i class="fa fa-eye" style="color:#10b981;"></i> Send to Students &amp; Allow to View</strong>
            <span style="font-size:11.5px;color:#64748b;font-weight:400;display:block;margin-top:2px;">When enabled, students enrolled in this class can browse this folder and view PPT presentations directly in their browser.</span>
          </div>
        </label>
      </div>
      <div id="createFolderAlert" style="display:none;margin-top:12px;"></div>
    </div>
    <div class="cv-modal-foot">
      <button class="btn-modal-cancel" onclick="closeModal('createFolderModal')">Cancel</button>
      <button class="btn-modal-ok" id="btnSaveFolder" style="background:linear-gradient(135deg,#f59e0b,#d97706);"><i class="fa fa-save"></i> Create Folder</button>
    </div>
  </div>
</div>

<!-- ═══════════ EDIT FOLDER MODAL ═══════════ -->
<div class="cv-modal-overlay" id="editFolderModal">
  <div class="cv-modal" style="max-width:500px;">
    <div class="cv-modal-head" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);">
      <h4><i class="fa fa-pencil"></i> Edit Material Folder</h4>
      <button class="cv-modal-x" onclick="closeModal('editFolderModal')">&times;</button>
    </div>
    <div class="cv-modal-body">
      <input type="hidden" id="editFolderId">
      <div class="cv-field">
        <label>Folder Name <span class="req">*</span></label>
        <input type="text" id="editFName" class="cv-fc">
      </div>
      <div class="cv-field" style="background:#f8fafc;padding:12px 14px;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:0;">
        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin:0;">
          <input type="checkbox" id="editFAllowView" style="width:18px;height:18px;margin-top:2px;accent-color:#10b981;cursor:pointer;">
          <div>
            <strong style="font-size:13px;color:#0f172a;display:block;"><i class="fa fa-eye" style="color:#10b981;"></i> Send to Students &amp; Allow to View</strong>
            <span style="font-size:11.5px;color:#64748b;font-weight:400;display:block;margin-top:2px;">Allow students to view and open presentations in this folder.</span>
          </div>
        </label>
      </div>
      <div id="editFolderAlert" style="display:none;margin-top:12px;"></div>
    </div>
    <div class="cv-modal-foot">
      <button class="btn-modal-cancel" onclick="closeModal('editFolderModal')">Cancel</button>
      <button class="btn-modal-ok" id="btnUpdateFolder" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);"><i class="fa fa-save"></i> Save Changes</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if($isTeacher): ?>
<!-- ═══════════ CREATE ASSIGNMENT MODAL ═══════════ -->
<div class="cv-modal-overlay" id="assignModal">
  <div class="cv-modal" style="max-width:540px;">
    <div class="cv-modal-head" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
      <h4><i class="fa fa-pencil-square-o"></i> New Assignment</h4>
      <button class="cv-modal-x" onclick="closeModal('assignModal')">&times;</button>
    </div>
    <div class="cv-modal-body">
      <div class="cv-field"><label>Title <span class="req">*</span></label><input type="text" id="aTitle" class="cv-fc" placeholder="e.g. Lab Exercise 1"></div>
      <div class="cv-field"><label>Instructions</label><textarea id="aInstructions" class="cv-fc" placeholder="Describe what students need to do..."></textarea></div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
        <div class="cv-field"><label>Points</label><input type="number" id="aPoints" class="cv-fc" value="100" min="1"></div>
        <div class="cv-field"><label>Due Date</label><input type="datetime-local" id="aDueDate" class="cv-fc"></div>
        <div class="cv-field">
          <label style="display:flex;align-items:center;gap:5px;">
            <i class="fa fa-book" style="color:#f59e0b;font-size:11px;"></i>
            Class Record Term
          </label>
          <select id="aTerm" class="cv-fc" style="cursor:pointer;">
            <option value="midterm">&#128337; Midterm</option>
            <option value="final">&#128338; Final</option>
            <option value="none">&#8212; None (no record)</option>
          </select>
        </div>
      </div>
      <div id="assignAlert" style="display:none;"></div>
    </div>
    <div class="cv-modal-foot">
      <button class="btn-modal-cancel" onclick="closeModal('assignModal')">Cancel</button>
      <button class="btn-modal-ok" id="btnCreateAssign" style="background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 3px 10px rgba(245,158,11,.3);"><i class="fa fa-save"></i> Create</button>
    </div>
  </div>
</div>



<!-- ═══════════ FORMAT GUIDE MODAL ═══════════ -->
<div class="cv-modal-overlay" id="formatGuideModal" style="z-index: 100000;">
  <div class="cv-modal" style="max-width:850px; width:90%; border-radius:14px;">
    <div class="cv-modal-head" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);">
      <h4 style="color:#fff;font-weight:700;"><i class="fa fa-info-circle"></i> Supported Question Formats</h4>
      <button class="cv-modal-x" onclick="closeModal('formatGuideModal')">&times;</button>
    </div>
    <div class="cv-modal-body" style="background:#f8fafc; padding:20px; max-height: 70vh; overflow-y: auto;">
      
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:14px;margin-bottom:12px;">
        <!-- Multiple Choice -->
        <div style="background:#fff;border:1px solid #e9d5ff;border-radius:12px;padding:14px;box-shadow:0 2px 4px rgba(0,0,0,.02);">
          <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
            <span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:800;">MC</span>
            <span style="font-size:11px;font-weight:700;color:#374151;">Multiple Choice</span>
          </div>
          <pre style="margin:0;font-size:11px;color:#475569;line-height:1.7;font-family:monospace;background:#f8fafc;border-radius:6px;padding:8px;overflow-x:auto;">1. Question?
a) Option A
b) Option B
c) Option C
Answer: a
Points: 2</pre>
        </div>

        <!-- True/False -->
        <div style="background:#fff;border:1px solid #bbf7d0;border-radius:12px;padding:14px;box-shadow:0 2px 4px rgba(0,0,0,.02);">
          <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
            <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:800;">T/F</span>
            <span style="font-size:11px;font-weight:700;color:#374151;">True / False</span>
          </div>
          <pre style="margin:0;font-size:11px;color:#475569;line-height:1.7;font-family:monospace;background:#f8fafc;border-radius:6px;padding:8px;overflow-x:auto;">2. Statement here?
Answer: True
Points: 1</pre>
        </div>

        <!-- Identification -->
        <div style="background:#fff;border:1px solid #fde68a;border-radius:12px;padding:14px;box-shadow:0 2px 4px rgba(0,0,0,.02);">
          <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
            <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:800;">ID</span>
            <span style="font-size:11px;font-weight:700;color:#374151;">Identification</span>
          </div>
          <pre style="margin:0;font-size:11px;color:#475569;line-height:1.7;font-family:monospace;background:#f8fafc;border-radius:6px;padding:8px;overflow-x:auto;">3. Question?
Answer: exact word
Points: 3</pre>
        </div>

        <!-- Modified True/False -->
        <div style="background:#fff;border:1px solid #fecaca;border-radius:12px;padding:14px;box-shadow:0 2px 4px rgba(0,0,0,.02);">
          <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
            <span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:800;">MTF</span>
            <span style="font-size:11px;font-weight:700;color:#374151;">Modified T/F</span>
          </div>
          <pre style="margin:0;font-size:11px;color:#475569;line-height:1.7;font-family:monospace;background:#f8fafc;border-radius:6px;padding:8px;overflow-x:auto;">4. Statement here?
MTF: False — correction
Points: 2</pre>
        </div>

        <!-- Enumeration -->
        <div style="background:#fff;border:1px solid #bfdbfe;border-radius:12px;padding:14px;box-shadow:0 2px 4px rgba(0,0,0,.02);">
          <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
            <span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:800;">ENUM</span>
            <span style="font-size:11px;font-weight:700;color:#374151;">Enumeration</span>
          </div>
          <pre style="margin:0;font-size:11px;color:#475569;line-height:1.7;font-family:monospace;background:#f8fafc;border-radius:6px;padding:8px;overflow-x:auto;">5. List 3 items:
Enum: item1, item2, item3
Points: 5</pre>
        </div>

        <!-- Essay -->
        <div style="background:#fff;border:1px solid #d1d5db;border-radius:12px;padding:14px;box-shadow:0 2px 4px rgba(0,0,0,.02);">
          <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
            <span style="background:#f3f4f6;color:#374151;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:800;">ESSAY</span>
            <span style="font-size:11px;font-weight:700;color:#374151;">Essay</span>
          </div>
          <pre style="margin:0;font-size:11px;color:#475569;line-height:1.7;font-family:monospace;background:#f8fafc;border-radius:6px;padding:8px;overflow-x:auto;">6. Explain in detail:
Essay: (teacher grades)</pre>
        </div>
      </div>

      <div style="padding:10px 14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;font-size:12px;color:#0369a1;display:flex;align-items:center;gap:8px;">
        <i class="fa fa-lightbulb-o" style="font-size:14px;"></i>
        <span>Options can use <strong>a)</strong> or <strong>A.</strong> or <strong>1.</strong> format. Separate each question with a blank line.</span>
      </div>

    </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════ SUBMISSIONS MODAL ═══════════ -->
<div class="cv-modal-overlay" id="subsModal">
  <div class="cv-modal" style="max-width:700px;">
    <div class="cv-modal-head" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
      <h4 id="subsModalTitle"><i class="fa fa-list"></i> Submissions</h4>
      <button class="cv-modal-x" onclick="closeModal('subsModal')">&times;</button>
    </div>
    <div class="cv-modal-body" id="subsModalBody"><div style="text-align:center;padding:32px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i></div></div>
    <div class="cv-modal-foot"><button class="btn-modal-cancel" onclick="closeModal('subsModal')">Close</button></div>
  </div>
</div>

<!-- ═══════════ QUIZ RESULTS / SUBMISSIONS MODAL ═══════════ -->
<div class="cv-modal-overlay" id="quizResModal">
  <div class="cv-modal" style="max-width:92%;width:1050px;border-radius:16px;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,0.18);">
    <div class="cv-modal-head" style="background:linear-gradient(135deg,#0f2d4a,#1e3a5f);color:#fff;padding:16px 22px;display:flex;align-items:center;justify-content:space-between;">
      <h4 id="quizResTitle" style="font-weight:700;display:flex;align-items:center;gap:8px;margin:0;font-size:15px;color:#fff;">
        <i class="fa fa-eye" style="color:#60a5fa;"></i> Submissions
      </h4>
      <button class="cv-modal-x" onclick="closeModal('quizResModal')" style="color:#fff;opacity:.9;background:none;border:none;font-size:22px;cursor:pointer;">&times;</button>
    </div>
    <div class="cv-modal-body" id="quizResBody" style="padding:22px;background:#fff;max-height:calc(85vh - 120px);overflow-y:auto;">
      <div style="text-align:center;padding:32px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
    </div>
    <div class="cv-modal-foot" style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 20px;display:flex;justify-content:flex-end;">
      <button class="btn btn-default" onclick="closeModal('quizResModal')" style="border-radius:8px;font-weight:600;padding:6px 16px;background:#fff;border:1px solid #d1d5db;cursor:pointer;">Close</button>
    </div>
  </div>
</div>

<!-- ═══════════ STUDENT ANSWERS REVIEW MODAL ═══════════ -->
<div class="cv-modal-overlay" id="answersModal">
  <div class="cv-modal" style="max-width:92%;width:880px;border-radius:16px;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,0.18);">
    <div class="cv-modal-head" style="background:linear-gradient(135deg,#4f46e5,#4338ca);color:#fff;padding:16px 22px;display:flex;align-items:center;justify-content:space-between;">
      <h4 id="answersModalTitle" style="font-weight:700;display:flex;align-items:center;gap:8px;margin:0;font-size:15px;color:#fff;">
        <i class="fa fa-list-alt" style="color:#a5b4fc;"></i> Student Quiz Answers Review
      </h4>
      <button class="cv-modal-x" onclick="closeModal('answersModal')" style="color:#fff;opacity:.9;background:none;border:none;font-size:22px;cursor:pointer;">&times;</button>
    </div>
    <div class="cv-modal-body" id="answersModalBody" style="padding:22px;background:#f8fafc;max-height:calc(85vh - 120px);overflow-y:auto;">
      <div style="text-align:center;padding:32px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
    </div>
    <div class="cv-modal-foot" style="background:#fff;border-top:1px solid #e2e8f0;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;">
      <button class="btn btn-default" onclick="closeModal('answersModal'); openModal('quizResModal');" style="border-radius:8px;font-weight:600;padding:6px 16px;background:#fff;border:1px solid #d1d5db;cursor:pointer;"><i class="fa fa-arrow-left"></i> Back to Submissions</button>
      <button class="btn btn-default" onclick="closeModal('answersModal')" style="border-radius:8px;font-weight:600;padding:6px 16px;background:#fff;border:1px solid #d1d5db;cursor:pointer;">Close</button>
    </div>
  </div>
</div>

<?php if(!$isTeacher): ?>
<!-- ═══════════ SUBMIT ASSIGNMENT MODAL ═══════════ -->
<div class="cv-modal-overlay" id="submitAssignModal">
  <div class="cv-modal" style="max-width:500px;">
    <div class="cv-modal-head" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
      <h4 id="submitAssignTitle"><i class="fa fa-upload"></i> Submit Assignment</h4>
      <button class="cv-modal-x" onclick="closeModal('submitAssignModal')">&times;</button>
    </div>
    <div class="cv-modal-body">
      <input type="hidden" id="submitAssignId">
      <div class="cv-field"><label>Remarks / Notes</label><textarea id="submitRemarks" class="cv-fc" placeholder="Optional notes for your teacher..."></textarea></div>
      <div class="cv-field" style="margin-bottom:0;">
        <label>Attach File (optional)</label>
        <div onclick="document.getElementById('submitFile').click()" style="border:2px dashed #d1d5db;border-radius:12px;padding:22px 20px;text-align:center;cursor:pointer;background:#f9fafb;">
          <i class="fa fa-paperclip" style="font-size:20px;color:#94a3b8;display:block;margin-bottom:8px;"></i>
          <p style="font-size:13px;color:#64748b;margin:0;">Click to attach a file</p>
          <div id="submitFileChosen" style="display:none;margin-top:8px;font-size:12px;font-weight:600;color:#f59e0b;"></div>
        </div>
        <input type="file" id="submitFile" style="display:none" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip,.png,.jpg,.jpeg">
      </div>
      <div id="submitAssignAlert" style="display:none;margin-top:12px;"></div>
    </div>
    <div class="cv-modal-foot">
      <button class="btn-modal-cancel" onclick="closeModal('submitAssignModal')">Cancel</button>
      <button class="btn-modal-ok" id="btnSubmitAssign" style="background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 3px 10px rgba(245,158,11,.3);"><i class="fa fa-upload"></i> Submit</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════ TAKE QUIZ MODAL ═══════════ -->
<div class="cv-modal-overlay" id="takeQuizModal" style="padding:0;align-items:stretch;z-index:99999;">
  <div class="cv-modal" style="max-width:100%;width:100%;height:100vh;max-height:100vh;border-radius:0;margin:0;display:flex;flex-direction:column;">
    <div class="cv-modal-head" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);flex-shrink:0;">
      <h4 id="takeQuizTitle"><i class="fa fa-question-circle"></i> Quiz</h4>
      <div style="display:flex;align-items:center;gap:10px;">
        <span id="quizTimer" style="display:none;"></span>
      </div>
    </div>
    <div id="quizViolationBar"><i class="fa fa-exclamation-triangle"></i> <span id="quizViolationMsg">Warning: suspicious activity detected</span></div>
    <div class="cv-modal-body" id="takeQuizBody" style="flex:1;overflow-y:auto;max-height:none;"><div style="text-align:center;padding:32px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i></div></div>
    <div class="cv-modal-foot" id="takeQuizFoot" style="display:none;flex-shrink:0;">
      <button class="btn-modal-ok" id="btnSubmitQuiz" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);box-shadow:0 3px 10px rgba(139,92,246,.3);"><i class="fa fa-check"></i> Submit Quiz</button>
    </div>
  </div>
</div>

<script>

// ── Upload Module & Folder Management ──────────────────────────────────────
function openUploadModal(folderId){
  var fi = document.getElementById('fileInput');
  if(fi) fi.value = '';
  var chosen = document.getElementById('uplChosen');
  if(chosen) chosen.style.display = 'none';
  var alertEl = document.getElementById('uplAlert');
  if(alertEl) alertEl.style.display = 'none';
  var prog = document.getElementById('uplProg');
  if(prog) prog.style.display = 'none';
  var fSelect = document.getElementById('uplFolderId');
  if(fSelect && folderId !== undefined){
    fSelect.value = folderId;
  }
  openModal('uplModal');
}


(function(){
  var drop = document.getElementById('uplDrop');
  var fi   = document.getElementById('fileInput');
  if(!drop) return;
  var sel  = null;
  drop.addEventListener('dragover',  function(e){ e.preventDefault(); drop.style.borderColor='<?php echo $accent;?>'; });
  drop.addEventListener('dragleave', function(){ drop.style.borderColor='#d1d5db'; });
  drop.addEventListener('drop', function(e){ e.preventDefault(); drop.style.borderColor='#d1d5db'; if(e.dataTransfer.files.length) setFile(e.dataTransfer.files[0]); });
  fi.addEventListener('change', function(){ if(this.files.length) setFile(this.files[0]); });
  function setFile(f){ sel=f; document.getElementById('uplChosenName').textContent=f.name+' ('+fmt(f.size)+')'; document.getElementById('uplChosen').style.display='block'; }
  function fmt(b){ if(b>=1048576) return (b/1048576).toFixed(1)+' MB'; if(b>=1024) return (b/1024).toFixed(1)+' KB'; return b+' B'; }
  document.getElementById('btnUpload').addEventListener('click', function(){
    var file  = sel||(fi.files.length?fi.files[0]:null);
    if(!file) { showAlert('uplAlert','danger','Please select a file.'); return; }
    var title = file.name.replace(/\.[^/.]+$/, ''); // use filename (no extension) as title
    var folderId = document.getElementById('uplFolderId') ? document.getElementById('uplFolderId').value : '0';
    var fd = new FormData(); 
    fd.append('class_id', CLASS_ID); 
    fd.append('folder_id', folderId);
    fd.append('title', title); 
    fd.append('file', file);
    
    document.getElementById('uplProg').style.display='block'; 
    document.getElementById('uplAlert').style.display='none'; 
    this.disabled=true;
    var xhr = new XMLHttpRequest(); 
    xhr.open('POST','module_upload.php');
    xhr.upload.onprogress=function(e){ if(e.lengthComputable){ var p=Math.round(e.loaded/e.total*100); document.getElementById('uplFill').style.width=p+'%'; document.getElementById('uplPct').textContent=p+'%'; } };
    xhr.onload=function(){ 
      document.getElementById('btnUpload').disabled=false; 
      document.getElementById('uplProg').style.display='none'; 
      try{ 
        var r=JSON.parse(xhr.responseText); 
        if(r.success){ 
          showAlert('uplAlert','success','Uploaded successfully!'); 
          setTimeout(function(){ 
            if(parseInt(folderId) > 0) {
              location.href = 'class_view.php?id=' + CLASS_ID + '&tab=materials&folder_id=' + folderId;
            } else {
              location.href = 'class_view.php?id=' + CLASS_ID + '&tab=materials';
            }
          }, 900); 
        } else {
          showAlert('uplAlert','danger',r.msg||'Failed'); 
        }
      }catch(e){ showAlert('uplAlert','danger','Error uploading file'); } 
    };
    xhr.onerror=function(){ document.getElementById('btnUpload').disabled=false; document.getElementById('uplProg').style.display='none'; showAlert('uplAlert','danger','Upload failed.'); };
    xhr.send(fd);
  });
})();

function openCreateFolderModal(){
  document.getElementById('fName').value = '';
  document.getElementById('fAllowView').checked = true;
  document.getElementById('createFolderAlert').style.display = 'none';
  openModal('createFolderModal');
}

<?php if($isTeacher): ?>
var btnSaveF = document.getElementById('btnSaveFolder');
if(btnSaveF){
  btnSaveF.addEventListener('click', function(){
    var name = document.getElementById('fName').value.trim();
    if(!name){ showAlert('createFolderAlert', 'danger', 'Folder name is required.'); return; }
    var allow = document.getElementById('fAllowView').checked ? 1 : 0;

    this.disabled = true; this.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Creating...';
    var btn = this;
    $.post('folder_handler.php', {
      action: 'create_folder',
      class_id: CLASS_ID,
      name: name,
      folder_type: 'student_ppt',
      description: '',
      allow_student_view: allow
    }, function(r){
      btn.disabled = false; btn.innerHTML = '<i class="fa fa-save"></i> Create Folder';
      if(r.success){
        showAlert('createFolderAlert', 'success', 'Folder created!');
        setTimeout(function(){
          location.href = 'class_view.php?id=' + CLASS_ID + '&tab=materials&folder_id=' + r.folder_id;
        }, 800);
      } else {
        showAlert('createFolderAlert', 'danger', r.msg || 'Failed');
      }
    }, 'json');
  });
}

function openEditFolderModal(f){
  if(typeof f === 'string') { try { f = JSON.parse(f); } catch(e){} }
  if(!f) return;
  document.getElementById('editFolderId').value = f.id;
  document.getElementById('editFName').value = f.name || '';
  document.getElementById('editFAllowView').checked = (parseInt(f.allow_student_view) === 1);
  document.getElementById('editFolderAlert').style.display = 'none';
  openModal('editFolderModal');
}

var btnUpdF = document.getElementById('btnUpdateFolder');
if(btnUpdF){
  btnUpdF.addEventListener('click', function(){
    var fid = document.getElementById('editFolderId').value;
    var name = document.getElementById('editFName').value.trim();
    if(!name){ showAlert('editFolderAlert', 'danger', 'Folder name is required.'); return; }
    var allow = document.getElementById('editFAllowView').checked ? 1 : 0;

    this.disabled = true; this.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';
    var btn = this;
    $.post('folder_handler.php', {
      action: 'edit_folder',
      folder_id: fid,
      name: name,
      folder_type: 'student_ppt',
      description: '',
      allow_student_view: allow
    }, function(r){
      btn.disabled = false; btn.innerHTML = '<i class="fa fa-save"></i> Save Changes';
      if(r.success){
        showAlert('editFolderAlert', 'success', 'Folder updated!');
        setTimeout(function(){ location.reload(); }, 800);
      } else {
        showAlert('editFolderAlert', 'danger', r.msg || 'Failed');
      }
    }, 'json');
  });
}

function toggleFolderStudentView(fid){
  $.post('folder_handler.php', { action: 'toggle_student_view', folder_id: fid }, function(r){
    if(r.success){
      location.reload();
    } else {
      alert(r.msg || 'Failed to update folder view permission');
    }
  }, 'json');
}

function deleteFolder(fid){
  if(!confirm('Are you sure you want to delete this folder? Files inside will be kept in general class materials.')) return;
  $.post('folder_handler.php', { action: 'delete_folder', folder_id: fid, delete_files: 0 }, function(r){
    if(r.success){
      location.href = 'class_view.php?id=' + CLASS_ID + '&tab=materials';
    } else {
      alert(r.msg || 'Failed to delete folder');
    }
  }, 'json');
}

// ── Delete Module ──────────────────────────────────────────────────────────
function deleteModule(modId){
  if(!confirm('Delete this learning material / presentation?')) return;
  $.post('module_delete.php', { id: modId }, function(r){
    if(r.success){
      location.reload();
    } else {
      alert(r.msg || 'Failed to delete module');
    }
  }, 'json');
}
<?php endif; ?>

<?php if($isTeacher): ?>
// ── Create Assignment ──────────────────────────────────────────────────────
document.getElementById('btnCreateAssign').addEventListener('click', function(){
  var title = document.getElementById('aTitle').value.trim();
  if(!title){ showAlert('assignAlert','danger','Title is required.'); return; }
  this.disabled=true; this.innerHTML='<i class="fa fa-spinner fa-spin"></i> Saving...';
  var btn = this;
  $.post('assignment_handler.php',{ action:'create', class_id:CLASS_ID, title:title, instructions:$('#aInstructions').val(), points:$('#aPoints').val(), due_date:$('#aDueDate').val(), term:$('#aTerm').val() },function(r){
    btn.disabled=false; btn.innerHTML='<i class="fa fa-save"></i> Create';
    if(r.success){ showAlert('assignAlert','success','Assignment created!'); setTimeout(function(){ location.href='class_view.php?id='+CLASS_ID+'&tab=classwork'; },900); }
    else showAlert('assignAlert','danger',r.msg||'Failed');
  },'json');
});

function deleteAssignment(id){
  if(!confirm('Delete this assignment and all submissions?')) return;
  $.post('assignment_handler.php',{action:'delete',id:id},function(r){ if(r.success) location.reload(); else alert(r.msg); },'json');
}

// ── View Submissions ───────────────────────────────────────────────────────
function viewSubmissions(assignId, title){
  document.getElementById('subsModalTitle').innerHTML='<i class="fa fa-list"></i> '+title;
  document.getElementById('subsModalBody').innerHTML='<div style="text-align:center;padding:32px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>';
  openModal('subsModal');
  $.get('classwork_data.php',{action:'submissions',assignment_id:assignId},function(r){
    if(!r.success){ document.getElementById('subsModalBody').innerHTML='<p style="color:#ef4444;">'+r.msg+'</p>'; return; }
    var subs = r.submissions;
    if(!subs.length){ document.getElementById('subsModalBody').innerHTML='<div style="text-align:center;padding:32px;color:#94a3b8;"><i class="fa fa-inbox fa-2x" style="display:block;margin-bottom:10px;"></i>No submissions yet</div>'; return; }
    var html='<table class="sub-table"><thead><tr><th>Student</th><th>File</th><th>Remarks</th><th>Grade</th><th></th></tr></thead><tbody>';
    subs.forEach(function(s){
      html+='<tr><td><strong>'+s.student_name+'</strong></td>';
      html+='<td>'+(s.file_name?'<a href="submission_download.php?id='+s.id+'" style="color:#1792bb;font-size:12px;"><i class="fa fa-download"></i> '+s.original_name+'</a>':'<span style="color:#94a3b8;font-size:12px;">No file</span>')+'</td>';
      html+='<td style="font-size:12px;color:#64748b;">'+(s.remarks||'—')+'</td>';
      html+='<td><input type="number" class="grade-input" id="grade_'+s.id+'" value="'+(s.grade!==null?s.grade:'')+'" min="0" placeholder="—"></td>';
      html+='<td><button onclick="saveGrade('+s.id+')" style="padding:5px 10px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;">Save</button></td></tr>';
    });
    html+='</tbody></table>';
    document.getElementById('subsModalBody').innerHTML=html;
  },'json');
}

function saveGrade(subId){
  var g = document.getElementById('grade_'+subId).value;
  $.post('assignment_handler.php',{action:'grade',sub_id:subId,grade:g},function(r){ if(r.success) alert('Grade saved!'); },'json');
}

// ── Smart Import Parser ────────────────────────────────────────────────────
var _parsedQuestions = [];

function insertTemplate(type){
  var textarea = document.getElementById('qImportText');
  var start = textarea.selectionStart;
  var end = textarea.selectionEnd;
  var text = textarea.value;
  
  // Choose standard templates
  var templates = {
    multiple_choice: "\n\n1. Question text here?\na) Option A\nb) Option B\nc) Option C\nd) Option D\nAnswer: a\nPoints: 2\n\n",
    true_false: "\n\n2. Statement here?\nAnswer: True\nPoints: 1\n\n",
    identification: "\n\n3. Question here?\nAnswer: exact word\nPoints: 3\n\n",
    enumeration: "\n\n4. List items here:\nEnum: item1, item2, item3\nPoints: 5\n\n",
    modified_true_false: "\n\n5. Statement here?\nMTF: False — correction\nPoints: 2\n\n",
    essay: "\n\n6. Question here?\nEssay: (teacher grades)\nPoints: 10\n\n"
  };
  var template = templates[type] || "";
  textarea.value = text.substring(0, start) + template + text.substring(end);
  textarea.focus();
  textarea.selectionStart = textarea.selectionEnd = start + template.length;
  updateTextareaCounts();
}

function clearImportText(){
  if(confirm("Are you sure you want to clear the questions?")){
    document.getElementById('qImportText').value = '';
    updateTextareaCounts();
  }
}

function updateTextareaCounts(){
  var textarea = document.getElementById('qImportText');
  var lineNumbers = document.getElementById('lineNumbers');
  if(!textarea || !lineNumbers) return;
  
  var lines = textarea.value.split('\n');
  var linesCount = lines.length;
  
  var lineNumsHtml = '';
  for (var i = 1; i <= linesCount; i++) {
    lineNumsHtml += '<div>' + i + '</div>';
  }
  lineNumbers.innerHTML = lineNumsHtml;
  
  // Keep line numbers scrolled in sync
  lineNumbers.scrollTop = textarea.scrollTop;

  // character count
  document.getElementById('linesLabel').textContent = "Lines: " + linesCount;
  document.getElementById('charsLabel').textContent = "Characters: " + textarea.value.length;
}

function openNewQuizModal(){
  openModal('quizModal');
  setTimeout(function(){
    updateTextareaCounts();
    initQuizUploadDragAndDrop();
  }, 100);
}

function switchQuizEditorTab(mode){
  var tabPaste = document.getElementById('tabPasteQ');
  var tabUpload = document.getElementById('tabUploadF');
  var editorContainer = document.getElementById('quizEditorContainer');
  var uploadWorkspace = document.getElementById('quizUploadWorkspace');
  
  if(mode === 'paste'){
    tabPaste.classList.add('active');
    tabUpload.classList.remove('active');
    editorContainer.style.display = 'flex';
    uploadWorkspace.style.display = 'none';
  } else if(mode === 'upload'){
    tabUpload.classList.add('active');
    tabPaste.classList.remove('active');
    editorContainer.style.display = 'none';
    uploadWorkspace.style.display = 'flex';
  }
}

function handleQuizFileSelect(input){
  if(!input.files || !input.files.length) return;
  var file = input.files[0];
  
  var reader = new FileReader();
  reader.onload = function(e){
    var text = e.target.result;
    document.getElementById('qImportText').value = text;
    updateTextareaCounts();
    
    // Switch tab back to editor
    switchQuizEditorTab('paste');
    
    // Auto-parse & preview
    parseAndPreview();
  };
  reader.readAsText(file);
  input.value = '';
}

var _quizDragDropInited = false;
function initQuizUploadDragAndDrop(){
  if(_quizDragDropInited) return;
  var dropZone = document.getElementById('quizUploadWorkspace');
  if(!dropZone) return;
  
  ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function(eventName){
    dropZone.addEventListener(eventName, function(e){
      e.preventDefault();
      e.stopPropagation();
    }, false);
  });
  
  ['dragenter', 'dragover'].forEach(function(eventName){
    dropZone.addEventListener(eventName, function(){
      dropZone.style.borderColor = '#8b5cf6';
      dropZone.style.background = '#f5f3ff';
    }, false);
  });
  
  ['dragleave', 'drop'].forEach(function(eventName){
    dropZone.addEventListener(eventName, function(){
      dropZone.style.borderColor = '#cbd5e1';
      dropZone.style.background = '#f9fafb';
    }, false);
  });
  
  dropZone.addEventListener('drop', function(e){
    var dt = e.dataTransfer;
    var files = dt.files;
    var fileInput = document.getElementById('quizFileInput');
    if(fileInput && files.length){
      fileInput.files = files;
      handleQuizFileSelect(fileInput);
    }
  }, false);
  
  _quizDragDropInited = true;
}

function parseAndPreview(){
  var text = document.getElementById('qImportText').value.trim();
  if(!text){ showImportAlert('danger','Please paste your questions first.'); return; }

  _parsedQuestions = parseQuizText(text);

  if(!_parsedQuestions.length){
    showImportAlert('danger','Could not parse any questions. Check the format and try again.');
    return;
  }

  document.getElementById('importAlert').style.display='none';

  var totalPts = _parsedQuestions.reduce(function(s,q){ return s+(q.points||1); },0);
  document.getElementById('importPreviewCount').textContent = _parsedQuestions.length + " Question" + (_parsedQuestions.length!==1?'s':'') + " Detected (" + totalPts + " pts)";

  var html = '';
  _parsedQuestions.forEach(function(q,i){
    var typeKey   = q.question_type; 
    var typeShort = {
      multiple_choice: 'mc',
      true_false: 'tf',
      identification: 'id',
      enumeration: 'enum',
      modified_true_false: 'mtf',
      essay: 'essay'
    }[typeKey] || 'id';

    var typeLabel = {
      multiple_choice: 'MC',
      true_false: 'T/F',
      identification: 'ID',
      enumeration: 'ENUM',
      modified_true_false: 'MTF',
      essay: 'ESSAY'
    }[typeKey] || 'ID';

    var typeBg = {
      multiple_choice: '#ede9fe',
      true_false: '#dcfce7',
      identification: '#fef3c7',
      enumeration: '#dbeafe',
      modified_true_false: '#fee2e2',
      essay: '#f3f4f6'
    }[typeKey] || '#f3f4f6';

    var typeClr = {
      multiple_choice: '#5b21b6',
      true_false: '#166534',
      identification: '#92400e',
      enumeration: '#1d4ed8',
      modified_true_false: '#991b1b',
      essay: '#374151'
    }[typeKey] || '#374151';

    html += '<div class="quiz-preview-card card-border-' + typeShort + '">'
      + '<div class="quiz-preview-card-head">'
      + '<div class="quiz-q-num num-bg-' + typeShort + '">' + (i+1) + '</div>'
      + '<div class="quiz-q-text">' + q.question_text + '</div>'
      + '<div class="quiz-card-badge" style="background:' + typeBg + ';color:' + typeClr + ';">' + typeLabel + '</div>'
      + '</div>';

    // MC Options
    if(typeKey === 'multiple_choice' && q.options && q.options.length){
      html += '<div class="quiz-preview-options">';
      q.options.forEach(function(opt){
        var isCorrect = opt === q.correct_answer;
        var optClass = isCorrect ? 'quiz-preview-opt correct' : 'quiz-preview-opt';
        var optIcon = isCorrect ? '<i class="fa fa-check-circle"></i> ' : '<i class="fa fa-circle-o"></i> ';
        html += '<div class="' + optClass + '">' + optIcon + opt + '</div>';
      });
      html += '</div>';
    } 
    // True/False, ID, Enumeration, Modified T/F, Essay answers
    else {
      var correctTxt = q.correct_answer || '—';
      if(typeKey === 'essay'){
        correctTxt = 'Teacher grades manually';
      }
      var ansIcon = typeKey === 'essay' ? '<i class="fa fa-pencil"></i> ' : '<i class="fa fa-check"></i> ';
      html += '<div class="quiz-preview-answer-box">'
        + '<div class="quiz-answer-col correct" style="grid-column: 1 / span 2;">'
        + '<div class="quiz-col-lbl">Correct Answer</div>'
        + '<div class="quiz-col-val">' + ansIcon + correctTxt + '</div>'
        + '</div>'
        + '</div>';
    }

    // Dynamic inputs: pts & topic
    html += '<div class="quiz-card-inputs">'
      + '<div class="quiz-card-input-group">'
      + '<label><i class="fa fa-tag"></i> Topic:</label>'
      + '<input type="text" value="' + (q.topic||'') + '" onchange="updateQuestionTopic(' + i + ',this.value)" placeholder="e.g. Loops" style="width:130px;height:28px;">'
      + '</div>'
      + '<div class="quiz-card-input-group">'
      + '<label>Points:</label>'
      + '<input type="number" min="1" max="100" value="' + (q.points||1) + '" onchange="updateQuestionPoints(' + i + ',this.value)" style="width:50px;height:28px;font-weight:700;text-align:center;">'
      + '</div>'
      + '</div>'
      + '</div>';
  });

  document.getElementById('importPreviewList').innerHTML = html;
  
  // Update banner tip
  var tipBanner = document.getElementById('quizPreviewTipBanner');
  var tipText   = document.getElementById('quizPreviewTipText');
  if(tipBanner && tipText){
    tipBanner.style.background = '#f3e8ff';
    tipBanner.style.borderColor = '#e9d5ff';
    tipBanner.style.color = '#6b21a8';
    tipText.innerHTML = 'Looks good! Click <strong>"Create Quiz"</strong> at the bottom right to finalize.';
  }
}

function updateQuestionPoints(idx, val){
  var pts = Math.max(1, parseInt(val)||1);
  if(_parsedQuestions[idx]) _parsedQuestions[idx].points = pts;
  // Update total display
  var total = _parsedQuestions.reduce(function(s,q){ return s+(q.points||1); },0);
  var el = document.getElementById('previewTotalPts');
  if(el) el.textContent = total+' pt'+(total!==1?'s':'')+' total';
}

function updateQuestionTopic(idx, val){
  if(_parsedQuestions[idx]) _parsedQuestions[idx].topic = val.trim();
}

function parseQuizText(text){
  var questions = [];

  // Normalize line endings
  text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');

  // ── Pre-process: detect section headers like "Essay", "Multiple Choice", etc.
  // Replace section headers with a marker so questions below inherit the type
  var currentSectionType = null;
  var processedLines = [];

  text.split('\n').forEach(function(line){
    var trimmed = line.trim();
    // Detect standalone section headers (whole line is just a type keyword)
    if(/^essay\s*[:：]?\s*$/i.test(trimmed)){
      currentSectionType = 'essay'; return;
    }
    if(/^(multiple[\s\-]?choice|mc)\s*[:：]?\s*$/i.test(trimmed)){
      currentSectionType = 'multiple_choice'; return;
    }
    if(/^(true[\s\/]?false|t\/f)\s*[:：]?\s*$/i.test(trimmed)){
      currentSectionType = 'true_false'; return;
    }
    if(/^(identification|identify)\s*[:：]?\s*$/i.test(trimmed)){
      currentSectionType = 'identification'; return;
    }
    if(/^(enumeration|enum)\s*[:：]?\s*$/i.test(trimmed)){
      currentSectionType = 'enumeration'; return;
    }
    if(/^(modified[\s\-]?true[\s\/]?false|mtf)\s*[:：]?\s*$/i.test(trimmed)){
      currentSectionType = 'modified_true_false'; return;
    }
    // Tag numbered lines with current section type
    if(currentSectionType && /^\d+[\.\)\:]\s/.test(trimmed)){
      processedLines.push('__SECTION__'+currentSectionType+'__'+line);
    } else {
      processedLines.push(line);
    }
  });

  text = processedLines.join('\n');

  // Split by question numbers
  var blocks = text.split(/\n(?=(?:__SECTION__\w+__)?\s*\d+[\.\)\:]\s)/);
  if(blocks.length <= 1) blocks = text.split(/\n\s*\n/);

  blocks.forEach(function(block){
    block = block.trim();
    if(!block) return;

    var lines = block.split('\n').map(function(l){ return l.trim(); }).filter(Boolean);
    if(!lines.length) return;

    // Extract section type tag if present
    var forcedType = null;
    var firstLine = lines[0];
    var sectionMatch = firstLine.match(/^__SECTION__(\w+)__(.+)/);
    if(sectionMatch){
      forcedType = sectionMatch[1];
      lines[0]   = sectionMatch[2].trim();
    }

    // Remove leading number from question text
    var qText = lines[0].replace(/^\s*\d+[\.\)\:]\s*/, '').trim();
    if(!qText) return;

    var options       = [];
    var correctAnswer = '';
    var questionType  = forcedType || 'identification';
    var isEssay       = (forcedType === 'essay');
    var topic         = '';
    var parsedPoints  = 1;

    lines.slice(1).forEach(function(line){
      // Topic line
      var topicMatch = line.match(/^Topic\s*[:：]\s*(.+)/i);
      if(topicMatch){ topic = topicMatch[1].trim(); return; }
      // Inline essay keyword — "Essay:" alone OR "Essay: some note" OR "[essay]"
      if(/^[\(\[]?essay[\)\]]?\s*[:：]/i.test(line) || /^[\(\[]?essay[\)\]]?\s*$/i.test(line)){
        isEssay = true; questionType = 'essay'; correctAnswer = ''; return;
      }
      // MTF
      var mtfMatch = line.match(/^MTF\s*[:：]\s*(.+)/i);
      if(mtfMatch){ correctAnswer = mtfMatch[1].trim(); questionType = 'modified_true_false'; return; }
      // Enumeration
      var enumMatch = line.match(/^Enum(?:eration)?\s*[:：]\s*(.+)/i);
      if(enumMatch){ correctAnswer = enumMatch[1].trim(); questionType = 'enumeration'; return; }
      // Answer line
      var ansMatch = line.match(/^(?:Answer|Ans)\s*[:：]\s*(.+)/i);
      if(ansMatch){
        var ans = ansMatch[1].trim();
        // Detect essay placeholder answers like "(teacher grades)", "N/A", "manual"
        if(/^\(?\s*(teacher\s*(grades?|checks?|marks?)|manual\s*grad|n\/?a)\s*\)?.*$/i.test(ans)){
          isEssay = true; questionType = 'essay'; correctAnswer = ''; return;
        }
        if(/^[a-dA-D]$/.test(ans) && options.length){
          var idx = ans.toLowerCase().charCodeAt(0) - 97;
          correctAnswer = options[idx] || ans;
        } else if(/^true$/i.test(ans)){
          correctAnswer = 'true'; questionType = 'true_false';
        } else if(/^false$/i.test(ans)){
          correctAnswer = 'false'; questionType = 'true_false';
        } else {
          correctAnswer = ans;
        }
        return;
      }
      // Points line  e.g. "Points: 5"  or  "pts: 2"
      var ptsMatch = line.match(/^(?:Points?|pts?)\s*[:：]\s*(\d+)/i);
      if(ptsMatch){ parsedPoints = Math.max(1, parseInt(ptsMatch[1])||1); return; }
      // Options (MC)
      if(!isEssay && questionType !== 'enumeration' && questionType !== 'modified_true_false'){
        var optMatch = line.match(/^[a-dA-D1-4][\.\)]\s*(.+)/);
        if(optMatch){ options.push(optMatch[1].trim()); questionType = 'multiple_choice'; }
      }
    });

    // True/False auto-detect
    if(options.length === 2){
      var o = options.map(function(x){ return x.toLowerCase().trim(); });
      if((o[0]==='true'&&o[1]==='false')||(o[0]==='false'&&o[1]==='true')){
        questionType = 'true_false'; options = [];
      }
    }

    // Accept if: has answer, is essay (any detection path), or has MC options
    if(correctAnswer || isEssay || questionType === 'essay' || options.length > 0){
      questions.push({
        question_text: qText,
        question_type: questionType,
        options:       options,
        correct_answer:correctAnswer,
        points:        parsedPoints,
        topic:         topic
      });
    }
  });

  return questions;
}

function showImportAlert(type, msg){
  var el = document.getElementById('importAlert');
  el.style.cssText='padding:10px 13px;border-radius:9px;font-size:12px;display:flex;align-items:flex-start;gap:8px;margin-top:10px;'
    +(type==='success'?'background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;':'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;');
  el.innerHTML='<i class="fa fa-'+(type==='success'?'check-circle':'exclamation-circle')+'"></i> '+msg;
  el.style.display='flex';
}

var _btnCQ = document.getElementById('btnCreateQuiz');
if(_btnCQ) _btnCQ.addEventListener('click', function(){
  var title = document.getElementById('qTitle').value.trim();
  if(!title){ showAlert('quizAlert','danger','Title is required.'); return; }

  var questions = _parsedQuestions;
  if(!questions.length){
    var text = document.getElementById('qImportText').value.trim();
    if(!text){ showAlert('quizAlert','danger','Please paste your questions first.'); return; }
    questions = parseQuizText(text);
  }
  if(!questions.length){ showAlert('quizAlert','danger','Could not parse any questions. Check the format.'); return; }

  this.disabled=true; this.innerHTML='<i class="fa fa-spinner fa-spin"></i> Saving...';
  var btn=this;
  $.post('quiz_handler.php',{ action:'create', class_id:CLASS_ID, title:title, time_limit:$('#qTimeLimit').val(), due_date:$('#qDueDate').val(), shuffle_questions:$('#qShuffleQ').is(':checked')?1:0, shuffle_answers:$('#qShuffleA').is(':checked')?1:0, questions:JSON.stringify(questions), term:$('#qTerm').val() },function(r){
    btn.disabled=false; btn.innerHTML='<i class="fa fa-save"></i> Create Quiz';
    if(r.success){ showAlert('quizAlert','success','Quiz created!'); setTimeout(function(){ location.href='class_view.php?id='+CLASS_ID+'&tab=classwork'; },900); }
    else showAlert('quizAlert','danger',r.msg||'Failed');
  },'json');
});

function deleteQuiz(id){
  if(!confirm('Delete this quiz and all submissions?')) return;
  $.post('quiz_handler.php',{action:'delete',id:id},function(r){ if(r.success) location.reload(); else alert(r.msg); },'json');
}

var _currentQuizId = null;

function viewQuizResults(quizId, title){
  _currentQuizId = quizId;
  var displayTitle = (title && typeof title === 'string') ? title : 'Quiz';
  var titleEl = document.getElementById('quizResTitle');
  var bodyEl = document.getElementById('quizResBody');
  if(titleEl) titleEl.innerHTML = '<i class="fa fa-eye" style="color:#60a5fa;"></i> ' + escapeCqHtml(displayTitle) + ' &bull; Submissions';
  if(bodyEl) bodyEl.innerHTML = '<div style="text-align:center;padding:36px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i><p style="margin-top:10px;font-size:13px;">Loading student submissions...</p></div>';
  openModal('quizResModal');

  var postUrl = (window.location.pathname.indexOf('/shared/') !== -1) ? 'classwork_data.php' : '../shared/classwork_data.php';
  
  $.ajax({
    url: postUrl,
    type: 'GET',
    data: { action: 'quiz_results', quiz_id: quizId, class_id: CLASS_ID },
    dataType: 'text'
  }).done(function(rawText){
    var r;
    try {
      var jsonStart = rawText.indexOf('{');
      var jsonEnd = rawText.lastIndexOf('}');
      if(jsonStart !== -1 && jsonEnd !== -1){
        r = JSON.parse(rawText.substring(jsonStart, jsonEnd + 1));
      } else {
        r = JSON.parse(rawText.trim());
      }
    } catch(e){
      document.getElementById('quizResBody').innerHTML = '<div style="padding:30px;text-align:center;color:#ef4444;"><i class="fa fa-exclamation-circle fa-2x"></i><p style="margin-top:8px;">Failed to parse quiz results server response.</p></div>';
      return;
    }

    if(!r || !r.success){
      document.getElementById('quizResBody').innerHTML = '<div style="padding:30px;text-align:center;color:#ef4444;"><i class="fa fa-exclamation-circle fa-2x"></i><p style="margin-top:8px;">' + (r && r.msg ? r.msg : 'Failed to load results') + '</p></div>';
      return;
    }

    if(r.quiz_title){
      document.getElementById('quizResTitle').innerHTML = '<i class="fa fa-eye" style="color:#60a5fa;"></i> ' + escapeCqHtml(r.quiz_title) + ' &bull; Submissions';
    }

    var subs = r.submissions || [];
    var total_students = subs.length;
    if(!total_students){
      document.getElementById('quizResBody').innerHTML = '<div style="text-align:center;padding:48px 24px;color:#94a3b8;"><i class="fa fa-inbox fa-3x" style="display:block;margin-bottom:12px;opacity:0.6;"></i><h5 style="margin:0 0 6px;color:#64748b;font-weight:700;">No Submissions Yet</h5><p style="margin:0;font-size:12px;">Students enrolled in this class have not submitted this quiz yet.</p></div>';
      return;
    }

    // Summary stats
    var totalScore = 0, sumPct = 0, highScore = 0, totalAlerts = 0;
    subs.forEach(function(s){
      var sc = parseFloat(s.score || 0);
      totalScore += sc;
      if(sc > highScore) highScore = sc;
      var tp = parseInt(s.total_points || 0);
      if(tp > 0){
        sumPct += (sc / tp) * 100;
      }
      var violations = parseInt(s.tab_switches || 0) + parseInt(s.fullscreen_exits || 0);
      if(violations > 0) totalAlerts += violations;
    });
    var avgPct = total_students > 0 ? (sumPct / total_students).toFixed(1) : 0;

    var html = '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;">'
      + '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.03);">'
      + '<strong style="font-size:22px;color:#0f172a;display:block;">' + total_students + '</strong>'
      + '<span style="font-size:11.5px;color:#64748b;font-weight:600;display:block;margin-top:2px;">Attempts</span>'
      + '</div>'
      + '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.03);">'
      + '<strong style="font-size:22px;color:#0f172a;display:block;">' + avgPct + '%</strong>'
      + '<span style="font-size:11.5px;color:#64748b;font-weight:600;display:block;margin-top:2px;">Average Score</span>'
      + '</div>'
      + '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.03);">'
      + '<strong style="font-size:22px;color:#0f172a;display:block;">' + highScore + ' pts</strong>'
      + '<span style="font-size:11.5px;color:#64748b;font-weight:600;display:block;margin-top:2px;">High Score</span>'
      + '</div>'
      + '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.03);">'
      + '<strong style="font-size:22px;color:' + (totalAlerts > 0 ? '#dc2626' : '#166534') + ';display:block;">' + totalAlerts + '</strong>'
      + '<span style="font-size:11.5px;color:#64748b;font-weight:600;display:block;margin-top:2px;">Anti-Cheat Alerts</span>'
      + '</div>'
      + '</div>';

    html += '<div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;"><table style="width:100%;border-collapse:collapse;font-size:13px;">'
      + '<thead><tr style="background:#0f2d4a;color:#fff;">'
      + '<th style="padding:12px 14px;font-size:12px;font-weight:700;text-align:left;">Student</th>'
      + '<th style="padding:12px 10px;font-size:12px;font-weight:700;text-align:left;">Student Code</th>'
      + '<th style="padding:12px 10px;font-size:12px;font-weight:700;text-align:left;">Score</th>'
      + '<th style="padding:12px 10px;font-size:12px;font-weight:700;text-align:center;">Percentage</th>'
      + '<th style="padding:12px 10px;font-size:12px;font-weight:700;text-align:left;">Anti-Cheat Log</th>'
      + '<th style="padding:12px 10px;font-size:12px;font-weight:700;text-align:left;">Submitted Date</th>'
      + '<th style="padding:12px 10px;font-size:12px;font-weight:700;text-align:center;">Action</th>'
      + '</tr></thead><tbody>';

    subs.forEach(function(s){
      var totalPts = parseInt(s.total_points || 0);
      var scoreVal = parseFloat(s.score || 0);
      var pct = totalPts > 0 ? ((scoreVal / totalPts) * 100).toFixed(1) : '0.0';
      var pctNum = parseFloat(pct);
      
      var pctBg = pctNum >= 75 ? '#dcfce7' : (pctNum >= 50 ? '#fef3c7' : '#fee2e2');
      var pctColor = pctNum >= 75 ? '#166534' : (pctNum >= 50 ? '#92400e' : '#991b1b');

      // Anti-Cheat Log cell
      var tabSw = parseInt(s.tab_switches || 0);
      var fsEx = parseInt(s.fullscreen_exits || 0);
      var totalViolations = tabSw + fsEx;
      var integrityHtml;
      if(totalViolations === 0){
        integrityHtml = '<span style="background:#dcfce7;color:#166534;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:4px;"><i class="fa fa-check"></i> Clean</span>';
      } else {
        var flagClr = totalViolations >= 3 ? '#991b1b' : '#92400e';
        var flagBg  = totalViolations >= 3 ? '#fee2e2' : '#fef3c7';
        integrityHtml = '<span style="background:' + flagBg + ';color:' + flagClr + ';padding:3px 8px;border-radius:4px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:4px;">'
          + '<i class="fa fa-exclamation-triangle"></i> ' + tabSw + ' tab switches, ' + fsEx + ' fs exits</span>';
      }

      var subQuizId = s.quiz_id || _currentQuizId;

      html += '<tr style="border-bottom:1px solid #f1f5f9;">'
        + '<td style="padding:12px 14px;font-weight:800;color:#0f172a;">' + escapeCqHtml(s.student_name) + '</td>'
        + '<td style="padding:12px 10px;"><span style="color:#e11d48;background:#ffe4e6;padding:2px 8px;border-radius:4px;font-family:monospace;font-size:11.5px;font-weight:600;">' + escapeCqHtml(s.student_code) + '</span></td>'
        + '<td style="padding:12px 10px;font-weight:700;color:#0f172a;">' + scoreVal.toFixed(2) + ' / ' + totalPts + '</td>'
        + '<td style="padding:12px 10px;text-align:center;"><span style="background:' + pctBg + ';color:' + pctColor + ';padding:3px 8px;border-radius:4px;font-size:11.5px;font-weight:700;">' + pct + '%</span></td>'
        + '<td style="padding:12px 10px;">' + integrityHtml + '</td>'
        + '<td style="padding:12px 10px;color:#475569;font-size:12px;">' + escapeCqHtml(s.submitted_at) + '</td>'
        + '<td style="padding:12px 10px;text-align:center;">'
        + '<button type="button" onclick="viewStudentAnswers(' + subQuizId + ',\'' + escapeCqAttr(s.student_code) + '\')" style="border-radius:6px;font-weight:700;padding:6px 14px;background:linear-gradient(135deg,#4f46e5,#4338ca);color:#fff;border:none;box-shadow:0 2px 5px rgba(79,70,229,0.25);display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;"><i class="fa fa-list-alt"></i> View Answers</button>'
        + '</td>'
        + '</tr>';
    });
    html += '</tbody></table></div>';
    document.getElementById('quizResBody').innerHTML = html;
  }).fail(function(xhr, status, err){
    document.getElementById('quizResBody').innerHTML = '<div style="padding:30px;text-align:center;color:#ef4444;"><i class="fa fa-exclamation-triangle fa-2x"></i><p style="margin-top:8px;">Failed to load results. (' + (err || status) + ')</p></div>';
  });
}

function viewStudentAnswers(quizId, studentCode){
  document.getElementById('answersModalTitle').innerHTML = '<i class="fa fa-list-alt" style="color:#a5b4fc;"></i> Student Quiz Answers Review';
  document.getElementById('answersModalBody').innerHTML = '<div style="text-align:center;padding:36px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i><p style="margin-top:10px;font-size:13px;">Loading student answers...</p></div>';
  openModal('answersModal');

  var postUrl = (window.location.pathname.indexOf('/shared/') !== -1) ? 'classwork_data.php' : '../shared/classwork_data.php';
  
  $.ajax({
    url: postUrl,
    type: 'GET',
    data: { action: 'student_answers', quiz_id: quizId, student_code: studentCode },
    dataType: 'text'
  }).done(function(rawText){
    var r;
    try {
      var jsonStart = rawText.indexOf('{');
      var jsonEnd = rawText.lastIndexOf('}');
      if(jsonStart !== -1 && jsonEnd !== -1){
        r = JSON.parse(rawText.substring(jsonStart, jsonEnd + 1));
      } else {
        r = JSON.parse(rawText.trim());
      }
    } catch(e){
      document.getElementById('answersModalBody').innerHTML = '<div style="padding:30px;text-align:center;color:#ef4444;"><i class="fa fa-exclamation-circle fa-2x"></i><p style="margin-top:8px;">Failed to parse student answers server response.</p></div>';
      return;
    }

    if(!r || !r.success){
      document.getElementById('answersModalBody').innerHTML = '<div style="padding:30px;text-align:center;color:#ef4444;"><i class="fa fa-exclamation-circle fa-2x"></i><p style="margin-top:8px;">' + (r && r.msg ? r.msg : 'Failed to load student answers') + '</p></div>';
      return;
    }

    var pct = r.total_points > 0 ? ((r.score / r.total_points) * 100).toFixed(1) : '0.0';
    var tabSw = r.tab_switches || 0, fsEx = r.fullscreen_exits || 0, totalV = tabSw + fsEx;
    var intClr = totalV === 0 ? '#166534' : (totalV >= 3 ? '#991b1b' : '#92400e');
    var intBg  = totalV === 0 ? '#dcfce7' : (totalV >= 3 ? '#fee2e2' : '#fef3c7');
    var intTxt = totalV === 0 ? '✅ Clean Attempt' : '⚠ ' + totalV + ' Flag' + (totalV !== 1 ? 's' : '') + ' (Tabs: ' + tabSw + ', Fullscreen: ' + fsEx + ')';

    var html = '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin-bottom:18px;display:grid;grid-template-columns:repeat(4,1fr);gap:12px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.03);">'
      + '<div><div style="font-size:20px;font-weight:800;color:#0f172a;">' + r.score + ' / ' + r.total_points + '</div><div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Score</div></div>'
      + '<div><div style="font-size:20px;font-weight:800;color:#4f46e5;">' + pct + '%</div><div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Percentage</div></div>'
      + '<div><div style="font-size:12px;font-weight:700;padding:4px 8px;border-radius:8px;background:' + intBg + ';color:' + intClr + ';">' + intTxt + '</div><div style="font-size:11px;color:#64748b;margin-top:4px;text-transform:uppercase;font-weight:600;">Integrity</div></div>'
      + '<div><div style="font-size:12px;font-weight:600;color:#64748b;">' + r.submitted_at + '</div><div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Submitted</div></div>'
      + '</div>';

    var qList = r.questions || [];
    if(qList.length === 0){
      html += '<div class="alert alert-info">No questions found for this quiz.</div>';
    } else {
      qList.forEach(function(q, i){
        var typeLabel = {multiple_choice:'Multiple Choice', true_false:'True / False', modified_true_false:'Modified T/F', enumeration:'Enumeration', essay:'Essay', identification:'Identification'}[q.question_type] || (q.question_type || 'Question').toUpperCase();
        var typeBg    = {multiple_choice:'#dbeafe', true_false:'#dcfce7', modified_true_false:'#fee2e2', enumeration:'#dbeafe', essay:'#f3f4f6', identification:'#fef3c7'}[q.question_type] || '#f3f4f6';
        var typeClr   = {multiple_choice:'#1d4ed8', true_false:'#166534', modified_true_false:'#991b1b', enumeration:'#1d4ed8', essay:'#374151', identification:'#92400e'}[q.question_type] || '#374151';

        var given   = q.given_answer || '';
        var correct = q.correct_answer || '';

        var statusBadge, cardBorder;
        if(q.is_correct === null){
          statusBadge = '<span style="background:#fef3c7;color:#92400e;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:700;"><i class="fa fa-pencil"></i> Manual Grading</span>';
          cardBorder = '#e2e8f0';
        } else if(q.is_correct){
          statusBadge = '<span style="background:#dcfce7;color:#166534;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:700;"><i class="fa fa-check"></i> Correct (' + (q.earned_points !== undefined ? q.earned_points : q.points) + '/' + q.points + ' pts)</span>';
          cardBorder = '#86efac';
        } else {
          statusBadge = '<span style="background:#fee2e2;color:#991b1b;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:700;"><i class="fa fa-times"></i> Incorrect (0/' + q.points + ' pts)</span>';
          cardBorder = '#fca5a5';
        }

        var topicTag = (q.topic && q.topic !== 'General') ? '<span style="background:#e0f2fe;color:#0369a1;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;"><i class="fa fa-tag"></i> ' + escapeCqHtml(q.topic) + '</span>' : '';

        html += '<div style="background:#fff;border:1.5px solid ' + cardBorder + ';border-radius:12px;padding:16px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);">'
          + '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;flex-wrap:wrap;">'
          + '<div style="display:flex;align-items:center;gap:6px;">'
          + '<span style="background:#f1f5f9;color:#0f172a;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:800;">#' + (i + 1) + '</span>'
          + '<span style="background:' + typeBg + ';color:' + typeClr + ';padding:2px 8px;border-radius:5px;font-size:10px;font-weight:800;text-transform:uppercase;">' + typeLabel + '</span>'
          + topicTag
          + '</div>'
          + '<div>' + statusBadge + '</div>'
          + '</div>'
          + '<div style="font-size:13.5px;font-weight:700;color:#0f172a;line-height:1.4;margin-bottom:10px;">' + escapeCqHtml(q.question_text) + '</div>';

        // Show choices for Multiple Choice
        if(q.question_type === 'multiple_choice' && q.options && q.options.length){
          html += '<div style="display:flex;flex-direction:column;gap:5px;margin-bottom:8px;">';
          q.options.forEach(function(opt, optIdx){
            var letter = String.fromCharCode(65 + optIdx);
            var isGiven   = (given.toLowerCase() === opt.toLowerCase() || given.toLowerCase() === letter.toLowerCase());
            var isCorrect = (correct.toLowerCase() === opt.toLowerCase() || correct.toLowerCase() === letter.toLowerCase());
            
            var bg = '#f8fafc', clr = '#475569', bdr = '1px solid #e2e8f0', tag = '';
            if(isGiven && isCorrect){
              bg = '#f0fdf4'; clr = '#166534'; bdr = '1.5px solid #22c55e';
              tag = '<span style="margin-left:auto;font-size:11px;font-weight:700;color:#166534;"><i class="fa fa-check-circle"></i> Student Selected (Correct)</span>';
            } else if(isGiven && !isCorrect){
              bg = '#fef2f2'; clr = '#991b1b'; bdr = '1.5px solid #ef4444';
              tag = '<span style="margin-left:auto;font-size:11px;font-weight:700;color:#991b1b;"><i class="fa fa-times-circle"></i> Student Selected</span>';
            } else if(isCorrect){
              bg = '#f0fdf4'; clr = '#166534'; bdr = '1.5px dashed #22c55e';
              tag = '<span style="margin-left:auto;font-size:11px;font-weight:700;color:#166534;"><i class="fa fa-check"></i> Correct Answer</span>';
            }
            html += '<div style="padding:7px 12px;border-radius:8px;background:' + bg + ';color:' + clr + ';border:' + bdr + ';font-size:12.5px;display:flex;align-items:center;gap:8px;">'
              + '<strong style="width:20px;">' + letter + '.</strong> <span>' + escapeCqHtml(opt) + '</span>' + tag
              + '</div>';
          });
          html += '</div>';
        } else {
          var givenBg = (q.is_correct === false) ? '#fef2f2' : (q.is_correct === true ? '#f0fdf4' : '#fffbeb');
          var givenClr = (q.is_correct === false) ? '#991b1b' : (q.is_correct === true ? '#166534' : '#92400e');
          var givenBdr = (q.is_correct === false) ? '#fecaca' : (q.is_correct === true ? '#bbf7d0' : '#fde68a');
          var givenIco = (q.is_correct === false) ? '<i class="fa fa-times-circle" style="color:#ef4444;"></i>' : (q.is_correct === true ? '<i class="fa fa-check-circle" style="color:#10b981;"></i>' : '<i class="fa fa-pencil"></i>');

          html += '<div style="display:flex;flex-direction:column;gap:8px;margin-top:6px;">'
            + '<div style="background:' + givenBg + ';color:' + givenClr + ';border:1px solid ' + givenBdr + ';padding:8px 12px;border-radius:8px;font-size:12.5px;">'
            + givenIco + ' <strong>Student Answer:</strong> ' + (given ? escapeCqHtml(given) : '<em style="color:#94a3b8;">(No answer provided)</em>')
            + '</div>'
            + ((q.is_correct === false && correct) ? ('<div style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:8px 12px;border-radius:8px;font-size:12.5px;"><i class="fa fa-check-circle"></i> <strong>Expected Answer:</strong> ' + escapeCqHtml(correct) + '</div>') : '')
            + '</div>';
        }

        html += '</div>';
      });
    }

    document.getElementById('answersModalTitle').innerHTML = '<i class="fa fa-list-alt" style="color:#a5b4fc;"></i> ' + escapeCqHtml(r.student_name || 'Student') + ' &bull; Quiz Answers';
    document.getElementById('answersModalBody').innerHTML = html;
  }).fail(function(xhr, status, err){
    document.getElementById('answersModalBody').innerHTML = '<div style="padding:30px;text-align:center;color:#ef4444;"><i class="fa fa-exclamation-triangle fa-2x"></i><p style="margin-top:8px;">Failed to load student answers. (' + (err || status) + ')</p></div>';
  });
}
<?php endif; ?>

<?php if(!$isTeacher): ?>
// ── Submit Assignment ──────────────────────────────────────────────────────
function submitAssignment(id, title){
  document.getElementById('submitAssignId').value = id;
  document.getElementById('submitAssignTitle').innerHTML='<i class="fa fa-upload"></i> '+title;
  document.getElementById('submitRemarks').value='';
  document.getElementById('submitFile').value='';
  document.getElementById('submitFileChosen').style.display='none';
  document.getElementById('submitAssignAlert').style.display='none';
  openModal('submitAssignModal');
}
document.getElementById('submitFile').addEventListener('change', function(){
  if(this.files.length){ document.getElementById('submitFileChosen').textContent=this.files[0].name; document.getElementById('submitFileChosen').style.display='block'; }
});
document.getElementById('btnSubmitAssign').addEventListener('click', function(){
  var id = document.getElementById('submitAssignId').value;
  var fd = new FormData();
  fd.append('action','submit'); fd.append('assignment_id',id);
  fd.append('remarks',document.getElementById('submitRemarks').value);
  var fi = document.getElementById('submitFile');
  if(fi.files.length) fd.append('file',fi.files[0]);
  this.disabled=true; this.innerHTML='<i class="fa fa-spinner fa-spin"></i> Submitting...';
  var btn=this;
  var xhr=new XMLHttpRequest(); xhr.open('POST','assignment_handler.php');
  xhr.onload=function(){ btn.disabled=false; btn.innerHTML='<i class="fa fa-upload"></i> Submit';
    try{ var r=JSON.parse(xhr.responseText); if(r.success){ showAlert('submitAssignAlert','success',r.msg); setTimeout(function(){ location.reload(); },1000); } else showAlert('submitAssignAlert','danger',r.msg); }catch(e){ showAlert('submitAssignAlert','danger','Error'); } };
  xhr.send(fd);
});

// ── Take Quiz ──────────────────────────────────────────────────────────────
var _quizId=null, _timerInterval=null, _quizAnswers={}, _quizTotal=0;
var _tabSwitches=0, _fsExits=0, _proctoringActive=false;

// ── Proctoring ─────────────────────────────────────────────────────────────
function _onVisibilityChange(){
  if(!_proctoringActive) return;
  if(document.hidden){
    _tabSwitches++;
    _showViolation('Warning: Tab switch or page reload detected! <span class="violation-count">'+_tabSwitches+' of 3 allowed violations</span>');
    var postUrl = (window.location.pathname.indexOf('/shared/') !== -1) ? 'quiz_handler.php' : '../shared/quiz_handler.php';
    $.post(postUrl, { action: 'log_violation', quiz_id: _quizId }, function(res){
      if(res && res.limit_reached){
        alert('Maximum 3 violations reached! Your quiz is automatically being submitted.');
        if(document.getElementById('btnSubmitQuiz')) document.getElementById('btnSubmitQuiz').click();
      }
    }, 'json');
  }
}
function _onFsChange(){
  if(!_proctoringActive) return;
  if(!document.fullscreenElement && !document.webkitFullscreenElement){
    _fsExits++;
    _showViolation('Fullscreen exited! <span class="violation-count">'+_fsExits+'x</span> — returning to fullscreen...');
    setTimeout(function(){
      var el = document.documentElement;
      if(el && _proctoringActive){
        if(el.requestFullscreen) el.requestFullscreen().catch(function(){});
        else if(el.webkitRequestFullscreen) el.webkitRequestFullscreen();
      }
    }, 800);
  }
}
function _showViolation(msg){
  var bar = document.getElementById('quizViolationBar');
  var txt = document.getElementById('quizViolationMsg');
  if(bar && txt){ txt.innerHTML = msg; bar.classList.add('show'); }
}
function startProctoring(initSwitches){
  _tabSwitches = initSwitches || 0; _fsExits=0; _proctoringActive=true;
  if (_tabSwitches > 0) {
    _showViolation('Warning: ' + _tabSwitches + ' of 3 allowed violations (tab switches / page reloads) recorded!');
  }
  document.addEventListener('visibilitychange', _onVisibilityChange);
  document.addEventListener('fullscreenchange', _onFsChange);
  document.addEventListener('webkitfullscreenchange', _onFsChange);
  var el = document.documentElement;
  if(el && el.requestFullscreen) {
    el.requestFullscreen().catch(function(){});
  } else if(el && el.webkitRequestFullscreen) {
    el.webkitRequestFullscreen();
  }
}
var _timerInterval = null, _heartbeatInt = null;

function stopProctoring(){
  _proctoringActive=false;
  if(_heartbeatInt) clearInterval(_heartbeatInt);
  document.removeEventListener('visibilitychange', _onVisibilityChange);
  document.removeEventListener('fullscreenchange', _onFsChange);
  document.removeEventListener('webkitfullscreenchange', _onFsChange);
  if(document.exitFullscreen) document.exitFullscreen().catch(function(){});
  else if(document.webkitExitFullscreen) document.webkitExitFullscreen();
  var bar = document.getElementById('quizViolationBar');
  if(bar) bar.classList.remove('show');
}

function closeQuizModalDirect(){
  var modal = document.getElementById('takeQuizModal');
  if(modal) modal.style.display = 'none';

  var bar = document.getElementById('quizViolationBar');
  if(bar) bar.classList.remove('show');

  if(document.fullscreenElement || document.webkitFullscreenElement){
    if(document.exitFullscreen) document.exitFullscreen().catch(function(){});
    else if(document.webkitExitFullscreen) document.webkitExitFullscreen();
  }

  stopProctoring();
  if(_timerInterval) { clearInterval(_timerInterval); _timerInterval = null; }
  if(_heartbeatInt) { clearInterval(_heartbeatInt); _heartbeatInt = null; }

  _quizId = null;

  // Clean URL: strip ?take=... so it doesn't re-trigger modal on reload
  var cleanUrl = window.location.pathname;
  var params = new URLSearchParams(window.location.search);
  params.delete('take');
  var pStr = params.toString();
  if(pStr) cleanUrl += '?' + pStr;

  window.history.replaceState({}, document.title, cleanUrl);
  window.location.href = cleanUrl;
}

function escapeCqHtml(str){ return (str||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function escapeCqAttr(str){ return (str||'').toString().replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }

function takeQuiz(id){
  _quizId=id; _quizAnswers={}; _quizTotal=0; _tabSwitches=0; _fsExits=0;
  var bodyEl = document.getElementById('takeQuizBody');
  var footEl = document.getElementById('takeQuizFoot');
  var timerEl = document.getElementById('quizTimer');
  var violEl = document.getElementById('quizViolationBar');

  if(bodyEl) bodyEl.innerHTML='<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-2x"></i><p style="margin-top:12px;font-size:13px;">Loading quiz...</p></div>';
  if(footEl) footEl.style.display='none';
  if(timerEl) timerEl.style.display='none';
  if(violEl) violEl.classList.remove('show');
  openModal('takeQuizModal');

  var postUrl = (window.location.pathname.indexOf('/shared/') !== -1) ? 'quiz_handler.php' : '../shared/quiz_handler.php';

  if(_heartbeatInt) clearInterval(_heartbeatInt);
  _heartbeatInt = setInterval(function(){
    if(_quizId && document.getElementById('takeQuizModal') && document.getElementById('takeQuizModal').style.display === 'flex'){
      $.post(postUrl, { action: 'heartbeat', quiz_id: _quizId });
    }
  }, 10000);

  $.post(postUrl,{action:'get_questions',quiz_id:id},function(r){
    if(typeof r === 'string'){
      try { r = JSON.parse(r.trim()); } catch(e){ r = {success:false, msg:'Invalid quiz data'}; }
    }
    if(!r || !r.success){
      if(bodyEl) bodyEl.innerHTML='<div style="padding:32px;text-align:center;"><div style="width:60px;height:60px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;"><i class="fa fa-exclamation-circle" style="color:#ef4444;font-size:24px;"></i></div><p style="color:#374151;font-weight:600;margin:0 0 4px;">'+(r && r.msg ? r.msg : 'Failed to load quiz')+'</p></div>';
      return;
    }

    if(r.already_submitted){
      var totalPts = parseFloat(r.total) || 0;
      var earnedScore = parseFloat(r.score) || 0;
      var pct = totalPts > 0 ? Math.round((earnedScore / totalPts) * 100) : 0;
      var grade, gclr, gbg;
      if(pct>=90){grade='A';gclr='#166534';gbg='#dcfce7';}
      else if(pct>=80){grade='B';gclr='#1d4ed8';gbg='#dbeafe';}
      else if(pct>=70){grade='C';gclr='#92400e';gbg='#fef3c7';}
      else if(pct>=60){grade='D';gclr='#c2410c';gbg='#ffedd5';}
      else{grade='F';gclr='#991b1b';gbg='#fee2e2';}

      var vBar = document.getElementById('quizViolationBar');
      if(vBar) vBar.classList.remove('show');

      var modalHead = document.querySelector('#takeQuizModal .cv-modal-head div');
      if(modalHead){
        modalHead.innerHTML = '<button class="cv-modal-x" style="background:none;border:none;color:#fff;font-size:24px;cursor:pointer;line-height:1;" onclick="closeQuizModalDirect()">&times;</button>';
      }

      if(bodyEl) bodyEl.innerHTML=
        '<div style="text-align:center;padding:36px 20px;max-width:620px;margin:0 auto;">'
        +'<div style="width:84px;height:84px;border-radius:50%;background:linear-gradient(135deg,#8b5cf6,#6d28d9);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;box-shadow:0 8px 24px rgba(139,92,246,.35);">'
        +'<i class="fa fa-check" style="color:#fff;font-size:36px;"></i></div>'
        +'<h3 style="font-size:22px;font-weight:800;color:#0f172a;margin:0 0 6px;">Quiz Already Completed</h3>'
        +'<p style="font-size:13.5px;color:#64748b;margin:0 0 28px;">Your submission has been recorded in the class record.</p>'
        +'<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:28px;">'
        +'<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:18px 12px;box-shadow:0 2px 8px rgba(0,0,0,.03);">'
        +'<div style="font-size:26px;font-weight:800;color:#0f172a;">'+earnedScore+'<span style="font-size:14px;color:#94a3b8;font-weight:400;"> / '+totalPts+'</span></div>'
        +'<div style="font-size:11.5px;color:#64748b;font-weight:600;margin-top:4px;text-transform:uppercase;letter-spacing:.5px;">Score</div></div>'
        +'<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:18px 12px;box-shadow:0 2px 8px rgba(0,0,0,.03);">'
        +'<div style="font-size:26px;font-weight:800;color:#8b5cf6;">'+pct+'%</div>'
        +'<div style="font-size:11.5px;color:#64748b;font-weight:600;margin-top:4px;text-transform:uppercase;letter-spacing:.5px;">Percentage</div></div>'
        +'<div style="background:'+gbg+';border:1px solid '+gbg+';border-radius:14px;padding:18px 12px;box-shadow:0 2px 8px rgba(0,0,0,.03);">'
        +'<div style="font-size:26px;font-weight:800;color:'+gclr+';">'+grade+'</div>'
        +'<div style="font-size:11.5px;color:'+gclr+';font-weight:600;margin-top:4px;text-transform:uppercase;letter-spacing:.5px;">Grade</div></div>'
        +'</div>'
        +'<div style="display:flex;align-items:center;justify-content:center;gap:12px;">'
        +'<button type="button" onclick="closeQuizModalDirect()" style="padding:12px 28px;background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(139,92,246,.4);display:inline-flex;align-items:center;gap:8px;">'
        +'<i class="fa fa-times-circle"></i> Close & Return to Class</button>'
        +'</div>'
        +'</div>';
      if(footEl) footEl.style.display='none';
      return;
    }

    _quizTotal = (r.questions && r.questions.length) ? r.questions.length : 0;
    _quizAnswers = r.saved_answers || {};
    _tabSwitches = parseInt(r.tab_switches) || 0;

    var titleEl = document.getElementById('takeQuizTitle');
    if(titleEl) titleEl.innerHTML='<i class="fa fa-question-circle"></i> '+ escapeCqHtml(r.quiz ? r.quiz.title : 'Quiz');

    var html='';
    if(r.quiz && r.quiz.due_date){
      var d = new Date(r.quiz.due_date);
      var formattedDue = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
      html+='<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#991b1b;display:flex;align-items:center;gap:8px;">'
        +'<i class="fa fa-calendar-times-o" style="flex-shrink:0;"></i>'
        +'<span><strong>Expiration Deadline:</strong> '+formattedDue+' (Submissions not allowed after this time)</span>'
        +'</div>';
    }
    html+='<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#92400e;display:flex;align-items:flex-start;gap:8px;">'
      +'<i class="fa fa-shield" style="margin-top:1px;flex-shrink:0;"></i>'
      +'<span><strong>Proctored Quiz</strong> — Maximum 3 violation attempts (tab switches / page reloads) allowed before auto-submission.</span>'
      +'</div>';
    html+='<div id="quizProgress" style="margin-bottom:16px;">'
      +'<div style="display:flex;justify-content:space-between;font-size:11px;color:#64748b;font-weight:600;margin-bottom:6px;">'
      +'<span id="progressLabel">0 / '+_quizTotal+' answered</span>'
      +'<span>'+_quizTotal+' question'+(_quizTotal!==1?'s':'')+'</span>'
      +'</div>'
      +'<div style="background:#e2e8f0;border-radius:99px;height:5px;overflow:hidden;">'
      +'<div id="progressFill" style="height:100%;border-radius:99px;width:0%;background:linear-gradient(90deg,#8b5cf6,#6d28d9);transition:width .3s;"></div>'
      +'</div></div>';

    if(r.quiz && r.quiz.instructions){
      html+='<div style="background:#f5f3ff;border:1px solid #e9d5ff;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#374151;display:flex;gap:10px;align-items:flex-start;">'
        +'<i class="fa fa-info-circle" style="color:#8b5cf6;margin-top:1px;flex-shrink:0;"></i><span>'+escapeCqHtml(r.quiz.instructions)+'</span></div>';
    }

    if(r.questions && r.questions.length > 0){
      r.questions.forEach(function(q,i){
        var qtype = String(q.question_type || 'multiple_choice').toLowerCase();
        var savedVal = _quizAnswers[q.id];
        html+='<div class="quiz-q-block" id="qblock_'+q.id+'">'
          +'<div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:8px;">'
          +'<span style="width:26px;height:26px;border-radius:7px;background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;font-size:11px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;">'+(i+1)+'</span>'
          +'<div style="flex:1;">'
          +'<div class="quiz-q-text">'+escapeCqHtml(q.question_text)+'</div>'
          +'<div class="quiz-q-pts"><i class="fa fa-star-o"></i> '+q.points+' point'+(q.points!==1?'s':'')+'</div>'
          +'</div></div>';

        var opts = Array.isArray(q.options) ? q.options : [];
        if(qtype === 'true_false' || qtype === 'tf' || qtype === 'boolean'){
          var isTrueSel = (savedVal === 'true');
          var isFalseSel = (savedVal === 'false');
          html+='<div class="quiz-tf">'
            +'<div class="quiz-opt '+(isTrueSel?'selected':'')+'" onclick="selectOpt(this,'+q.id+',\'true\')" data-qid="'+q.id+'" style="justify-content:center;gap:8px;">'
            +'<i class="fa fa-check" style="color:#10b981;"></i> <strong>True</strong></div>'
            +'<div class="quiz-opt '+(isFalseSel?'selected':'')+'" onclick="selectOpt(this,'+q.id+',\'false\')" data-qid="'+q.id+'" style="justify-content:center;gap:8px;">'
            +'<i class="fa fa-times" style="color:#ef4444;"></i> <strong>False</strong></div>'
            +'</div>';
        } else if(qtype === 'multiple_choice' && opts.length > 0){
          opts.forEach(function(opt,oi){
            var strOpt = String(opt || '');
            var isOptSel = (savedVal === strOpt);
            html+='<div class="quiz-opt '+(isOptSel?'selected':'')+'" onclick="selectOpt(this,'+q.id+',\''+escapeCqAttr(strOpt)+'\')" data-qid="'+q.id+'">'
              +'<span style="width:22px;height:22px;border-radius:50%;border:2px solid #d1d5db;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;font-size:10px;font-weight:700;color:#94a3b8;">'+String.fromCharCode(65+oi)+'</span>'
              +'<span style="flex:1;">'+escapeCqHtml(strOpt)+'</span>'
              +'</div>';
          });
        } else if(qtype === 'modified_true_false'){
          html+='<input type="text" class="quiz-id-input" value="'+escapeCqAttr(savedVal || '')+'" placeholder="e.g. True  or  False — corrected word" oninput="updateIdAnswer('+q.id+',this.value)" data-qid="'+q.id+'">';
        } else if(qtype === 'enumeration'){
          html+='<input type="text" class="quiz-id-input" value="'+escapeCqAttr(savedVal || '')+'" placeholder="item1, item2, item3" oninput="updateIdAnswer('+q.id+',this.value)" data-qid="'+q.id+'">';
        } else if(qtype === 'essay'){
          html+='<textarea class="quiz-id-input" rows="5" placeholder="Write your answer..." oninput="updateIdAnswer('+q.id+',this.value)" data-qid="'+q.id+'" style="resize:vertical;min-height:100px;">'+escapeCqHtml(savedVal || '')+'</textarea>';
        } else {
          html+='<input type="text" class="quiz-id-input" value="'+escapeCqAttr(savedVal || '')+'" placeholder="Type your answer here..." oninput="updateIdAnswer('+q.id+',this.value)" data-qid="'+q.id+'">';
        }
        html+='</div>';
      });
    }

    document.getElementById('takeQuizBody').innerHTML=html;
    document.getElementById('takeQuizFoot').style.display='flex';
    updateProgress();
    startProctoring(_tabSwitches);

    var secs = 0;
    if (r.remaining_seconds !== undefined && r.remaining_seconds !== null) {
      secs = parseInt(r.remaining_seconds) || 0;
    } else if (r.quiz && r.quiz.time_limit && r.quiz.time_limit > 0) {
      secs = r.quiz.time_limit * 60;
    }

    if(secs > 0){
      var timerEl = document.getElementById('quizTimer');
      if(timerEl){
        timerEl.style.display = 'inline-flex';
        if(_timerInterval) clearInterval(_timerInterval);

        var renderTimerDisplay = function(){
          var m = Math.floor(secs / 60);
          var s = secs % 60;
          var mStr = (m < 10 ? '0' : '') + m;
          var sStr = (s < 10 ? '0' : '') + s;
          timerEl.innerHTML = '<i class="fa fa-clock-o timer-icon"></i> <span class="timer-text">' + mStr + ':' + sStr + '</span>';

          if (secs <= 60) {
            timerEl.className = 'timer-danger';
          } else if (secs <= 300) {
            timerEl.className = 'timer-warning';
          } else {
            timerEl.className = '';
          }
        };

        renderTimerDisplay();

        _timerInterval = setInterval(function(){
          secs--;
          renderTimerDisplay();
          if(secs <= 0){
            clearInterval(_timerInterval);
            document.getElementById('btnSubmitQuiz').click();
          }
        }, 1000);
      }
    }
  },'json');
}

function updateProgress(){
  var answered = Object.keys(_quizAnswers).filter(function(k){ return _quizAnswers[k]!==undefined&&_quizAnswers[k]!==''; }).length;
  var pct = _quizTotal>0?Math.round(answered/_quizTotal*100):0;
  var lbl = document.getElementById('progressLabel');
  var fill = document.getElementById('progressFill');
  if(lbl) lbl.textContent = answered+' / '+_quizTotal+' answered';
  if(fill) fill.style.width = pct+'%';
}

function selectOpt(el, qid, val){
  document.querySelectorAll('.quiz-opt[data-qid="'+qid+'"]').forEach(function(e){ e.classList.remove('selected'); });
  el.classList.add('selected');
  _quizAnswers[qid]=val;
  updateProgress();
  saveDraftAnswers();
}

function updateIdAnswer(qid, val){
  _quizAnswers[qid]=val;
  updateProgress();
  saveDraftAnswers();
}

function saveDraftAnswers(){
  if(_quizId && _quizAnswers){
    var postUrl = (window.location.pathname.indexOf('/shared/') !== -1) ? 'quiz_handler.php' : '../shared/quiz_handler.php';
    $.post(postUrl, {
      action: 'save_draft',
      quiz_id: _quizId,
      answers: JSON.stringify(_quizAnswers)
    });
  }
}

var btnSubmitQuizEl = document.getElementById('btnSubmitQuiz');
if(btnSubmitQuizEl){
  btnSubmitQuizEl.addEventListener('click', function(){
    if(!_quizId) return;
    var unanswered = _quizTotal - Object.keys(_quizAnswers).filter(function(k){ return _quizAnswers[k]!==undefined&&_quizAnswers[k]!==''; }).length;
    if(unanswered>0 && !confirm(unanswered+' question'+(unanswered!==1?'s':'')+' unanswered. Submit anyway?')) return;
    if(_timerInterval) clearInterval(_timerInterval);
    stopProctoring();
    this.disabled=true; this.innerHTML='<i class="fa fa-spinner fa-spin"></i> Submitting...';
    var btn=this;
    var postUrl = (window.location.pathname.indexOf('/shared/') !== -1) ? 'quiz_handler.php' : '../shared/quiz_handler.php';
    $.post(postUrl,{action:'submit',quiz_id:_quizId,answers:JSON.stringify(_quizAnswers),tab_switches:_tabSwitches,fullscreen_exits:_fsExits},function(r){
    btn.disabled=false; btn.innerHTML='<i class="fa fa-check"></i> Submit Quiz';
    if(r.success){
      var pct = r.total>0?Math.round(r.score/r.total*100):0;
      var grade, gclr, gbg;
      if(pct>=90){grade='A';gclr='#166534';gbg='#dcfce7';}
      else if(pct>=80){grade='B';gclr='#1d4ed8';gbg='#dbeafe';}
      else if(pct>=70){grade='C';gclr='#92400e';gbg='#fef3c7';}
      else if(pct>=60){grade='D';gclr='#c2410c';gbg='#ffedd5';}
      else{grade='F';gclr='#991b1b';gbg='#fee2e2';}

      var barW = pct+'%';
      var barClr = pct>=75?'#10b981':pct>=50?'#f59e0b':'#ef4444';

      document.getElementById('takeQuizBody').innerHTML=
        '<div style="text-align:center;padding:32px 24px;">'
        +'<div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#8b5cf6,#6d28d9);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;box-shadow:0 8px 24px rgba(139,92,246,.35);">'
        +'<i class="fa fa-check" style="color:#fff;font-size:32px;"></i></div>'
        +'<h3 style="font-size:20px;font-weight:800;color:#0f172a;margin:0 0 4px;">Quiz Submitted!</h3>'
        +'<p style="font-size:13px;color:#64748b;margin:0 0 24px;">Here\'s how you did</p>'
        +'<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px;">'
        +'<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;">'
        +'<div style="font-size:24px;font-weight:800;color:#0f172a;">'+r.score+'<span style="font-size:14px;color:#94a3b8;font-weight:400;"> / '+r.total+'</span></div>'
        +'<div style="font-size:11px;color:#64748b;font-weight:600;margin-top:2px;">Score</div></div>'
        +'<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;">'
        +'<div style="font-size:24px;font-weight:800;color:#8b5cf6;">'+pct+'%</div>'
        +'<div style="font-size:11px;color:#64748b;font-weight:600;margin-top:2px;">Percentage</div></div>'
        +'<div style="background:'+gbg+';border:1px solid '+gbg+';border-radius:12px;padding:16px;">'
        +'<div style="font-size:24px;font-weight:800;color:'+gclr+';">'+grade+'</div>'
        +'<div style="font-size:11px;color:'+gclr+';font-weight:600;margin-top:2px;opacity:.7;">Grade</div></div>'
        +'</div>'
        +'<div style="background:#f8fafc;border-radius:10px;padding:12px 16px;">'
        +'<div style="display:flex;justify-content:space-between;font-size:12px;color:#64748b;margin-bottom:6px;font-weight:600;">'
        +'<span>Performance</span><span>'+pct+'%</span></div>'
        +'<div style="background:#e2e8f0;border-radius:99px;height:8px;overflow:hidden;">'
        +'<div style="height:100%;border-radius:99px;width:'+barW+';background:'+barClr+';transition:width .5s;"></div></div></div>'
        +'</div>';
      document.getElementById('takeQuizFoot').style.display='none';
      setTimeout(function(){ closeQuizModalDirect(); }, 3000);
    } else {
      document.getElementById('takeQuizBody').insertAdjacentHTML('beforeend',
        '<div style="padding:10px 13px;border-radius:9px;font-size:12px;display:flex;align-items:flex-start;gap:8px;margin-top:12px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;">'
        +'<i class="fa fa-exclamation-circle"></i> '+r.msg+'</div>');
    }
  },'json');
});
}
<?php if(isset($_GET['take']) && intval($_GET['take']) > 0): ?>
document.addEventListener('DOMContentLoaded', function(){
  takeQuiz(<?php echo intval($_GET['take']); ?>);
});
<?php endif; ?>
<?php endif; ?>

<?php if($isTeacher && $tab === 'performance'): ?>
<?php if($total > 0): ?>
(function(){
  setTimeout(function(){
    var ctx = document.getElementById('riskChart');
    if(ctx){
      new Chart(ctx.getContext('2d'), {
        type: 'doughnut',
        data: {
          labels: ['On Track','Needs Attention','At Risk','High Risk'],
          datasets: [{
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
  }, 50);
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

$(document).ready(function(){
  var handlerUrl = (window.location.pathname.indexOf('/shared/') !== -1) ? 'topic_analytics_handler.php' : '../shared/topic_analytics_handler.php';
  $.get(handlerUrl, { action:'get_class_analytics', class_id:<?php echo $class_id; ?> }, function(r){
    if(!r.success) {
      $('#classTopicsArea').html('<p style="color:#ef4444;font-size:12px;">'+r.msg+'</p>');
      $('#studentTopicsArea').html('<p style="color:#ef4444;font-size:12px;">'+r.msg+'</p>');
      return;
    }

    // Render class topic difficulty
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
      $('#classTopicsArea').html('<div style="text-align:center;padding:24px;color:#94a3b8;font-size:12px;"><i class="fa fa-inbox fa-2x" style="display:block;margin-bottom:8px;opacity:.35;"></i><p>No topic data recorded yet. Topic performance is tracked when students take quizzes with topic tags.</p></div>');
    }

    // Render student weak topics & predictive recommendations with International Standard Tracking
    if(r.student_weak_topics && r.student_weak_topics.length) {
      var html = '<div class="table-responsive-wrap"><table class="stu-table" style="min-width:540px;"><thead><tr><th>Student</th><th>Weak Topic &amp; Context</th><th>Mastery &amp; Standard</th><th>Recommended Remedial Action</th></tr></thead><tbody>';
      r.student_weak_topics.forEach(function(t){
        var badge = t.risk_level === 'critical' ? '<span style="background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:99px;font-size:9.5px;font-weight:700;">🔴 Critical</span>' : (t.risk_level === 'warning' ? '<span style="background:#ffedd5;color:#9a3412;padding:2px 6px;border-radius:99px;font-size:9.5px;font-weight:700;">🟡 Review</span>' : '<span style="background:#dcfce7;color:#166534;padding:2px 6px;border-radius:99px;font-size:9.5px;font-weight:700;">🟢 Good</span>');
        var color = t.risk_level === 'critical' ? '#ef4444' : (t.risk_level === 'warning' ? '#f97316' : '#10b981');
        
        var modBadge = '';
        if(t.matched_modules && t.matched_modules.length > 0) {
          modBadge = '<div style="margin-top:3px;"><a href="module_view.php?id=' + t.matched_modules[0].id + '" target="_blank" style="background:#eff6ff;color:#1d4ed8;padding:2px 6px;border-radius:5px;font-size:10px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:3px;"><i class="fa fa-book"></i> Module: ' + t.matched_modules[0].title + '</a></div>';
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
      $('#studentTopicsArea').html('<div style="text-align:center;padding:24px;color:#94a3b8;font-size:12px;"><i class="fa fa-inbox fa-2x" style="display:block;margin-bottom:8px;opacity:.35;"></i><p>No student weak topics identified yet.</p></div>');
    }

  },'json');
});
<?php endif; ?>
function takeQuiz(quizId) {
  window.location.href = '../student/quizzes.php?take=' + quizId;
}
</script>
</body>
</html>
