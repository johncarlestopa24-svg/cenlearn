<?php
include '../includes/session.php';
include '../includes/conn.php';

if(empty($user) || strtoupper($user['user_group']) !== 'STUDENT'){
    header('Location: ../index.php');
    exit;
}

$uc = $conn->real_escape_string($user['user_code']);

// Refresh user data from DB
$uq = $conn->query("SELECT * FROM users WHERE user_code='$uc'");
if($uq && $uq->num_rows > 0) $user = array_merge($user, $uq->fetch_assoc());

// Always attempt to pull fresh data from TechnoPal to keep year_level/section/program current
// Pull fresh data from TechnoPal if cache is older than 6 hours or explicitly requested
$adminOverride = !empty($user['admin_override']);
$isCacheStale  = empty($user['api_cached_at']) || (time() - strtotime($user['api_cached_at']) > 21600) || isset($_GET['sync']);

if(!$adminOverride && $isCacheStale){
    $ch = curl_init('https://web.bagocitycollege.com/BCCWeb/TPLoginAPI');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query(['txtUserName'=>$uc,'txtPassword'=>'']),
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_SSL_VERIFYHOST=>false,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>2,
        CURLOPT_TIMEOUT=>3,
        CURLOPT_USERAGENT=>'Mozilla/5.0',
    ]);
    $raw=@curl_exec($ch);
    $http_code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($raw && $http_code==200){
        $cleaned=iconv('UTF-8','UTF-8//IGNORE',mb_convert_encoding($raw,'UTF-8','UTF-8'));
        $trimmed=trim($cleaned);
        if($trimmed && ($trimmed[0]==='{' || $trimmed[0]==='[')){
            $api=json_decode($trimmed,true);
            if(is_array($api)&&!empty($api['program_code'])){
                $pc=$conn->real_escape_string($api['program_code']??'');
                $pd=$conn->real_escape_string($api['program_description']??'');
                $yl=intval($api['year_level']??0);
                $sec=$conn->real_escape_string($api['section']??'');
                $now=date('Y-m-d H:i:s');
                $conn->query("UPDATE users SET program_code='$pc',program_description='$pd',year_level=$yl,section='$sec',api_cached_at='$now' WHERE user_code='$uc'");
                $uq=$conn->query("SELECT * FROM users WHERE user_code='$uc'");
                if($uq&&$uq->num_rows>0) $user=array_merge($user,$uq->fetch_assoc());
            }
        }
    }
}

// Normalize fields
$programCode = $user['program_code'] ?? '';
$programDesc = $user['program_description'] ?? '';
$yearLevel   = $user['year_level'] ?? '';
$section     = $user['section'] ?? '';
$department  = $user['department'] ?? '';
$isGraduated = !empty($user['graduated_at']) || strtoupper($user['user_group']??'') === 'ALUMNI';

// Year labels
$yearLabels = [1=>'1st Year',2=>'2nd Year',3=>'3rd Year',4=>'4th Year',5=>'5th Year'];
$yearLabel  = isset($yearLabels[(int)$yearLevel]) ? $yearLabels[(int)$yearLevel] : ($yearLevel ? "Year $yearLevel" : '—');

// Classes in this program
$classRows = [];
if($programCode){
    $pc = $conn->real_escape_string($programCode);
    $cq = $conn->query("
        SELECT c.*, u.first_name, u.last_name,
               COUNT(DISTINCT cm2.id) AS member_count
        FROM classes c
        JOIN class_members cm ON cm.class_id=c.id AND cm.user_code='$uc'
        LEFT JOIN users u ON c.teacher_code=u.user_code
        LEFT JOIN class_members cm2 ON cm2.class_id=c.id AND cm2.user_code!=c.teacher_code
        WHERE UPPER(c.program_code)=UPPER('$pc')
          AND c.teacher_code!='$uc'
          AND (c.is_archived=0 OR c.is_archived IS NULL)
          AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)
        GROUP BY c.id
        ORDER BY c.class_name ASC
    ");
    if($cq) while($r=$cq->fetch_assoc()) $classRows[]=$r;
}

// Classmates in same program + year + section
$classmates = [];
if($programCode && $yearLevel && $section){
    $pc2=$conn->real_escape_string($programCode);
    $s2=$conn->real_escape_string($section);
    $mq=$conn->query("
        SELECT user_code, first_name, last_name, gender
        FROM users
        WHERE user_group='STUDENT'
          AND UPPER(program_code)=UPPER('$pc2')
          AND year_level=".intval($yearLevel)."
          AND UPPER(section)=UPPER('$s2')
          AND user_code!='$uc'
          AND is_active=1
        ORDER BY last_name,first_name
        LIMIT 40
    ");
    if($mq) while($m=$mq->fetch_assoc()) $classmates[]=$m;
}

// Pending assignments count for this program
$pendingCount = 0;
$paq = $conn->query("
    SELECT COUNT(*) AS c FROM assignments a
    JOIN classes c ON a.class_id=c.id
    JOIN class_members cm ON cm.class_id=c.id AND cm.user_code='$uc'
    WHERE NOT EXISTS (SELECT 1 FROM assignment_submissions s WHERE s.assignment_id=a.id AND s.student_code='$uc')
      AND (c.is_archived=0 OR c.is_archived IS NULL)
      AND (c.is_subject_only=0 OR c.is_subject_only IS NULL)
      AND (a.due_date IS NULL OR a.due_date > NOW())
");
if($paq) $pendingCount = (int)$paq->fetch_assoc()['c'];

// Initials
$initials = strtoupper(substr($user['first_name']??'',0,1).substr($user['last_name']??'',0,1));
$fullName  = trim(($user['first_name']??'').' '.(isset($user['middle_name'])&&$user['middle_name']?$user['middle_name'][0].'. ':'').($user['last_name']??''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — My Program</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    *{box-sizing:border-box;}
    html,body{margin:0;padding:0;overflow-x:hidden;}
    body{font-family:'Inter',sans-serif;background:#f0f4f8;color:#1e293b;}
    /* Sidebar */
    .sd-sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:linear-gradient(180deg,#0c1a2e 0%,#0f2d4a 55%,#0f5f80 100%);display:flex;flex-direction:column;z-index:200;transition:transform .3s cubic-bezier(.4,0,.2,1);transform:translateX(-260px);}
    .sd-sidebar.open{transform:translateX(0);}
    @media(min-width:901px){.sd-sidebar{transform:translateX(0);}}
    .sb-brand{padding:26px 22px 18px;border-bottom:1px solid rgba(255,255,255,.08);}
    .sb-logo{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#1792bb,#0f5f80);display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px;box-shadow:0 4px 12px rgba(23,146,187,.4);}
    .sb-logo i{color:#fff;font-size:17px;}
    .sb-brand h2{color:#fff;font-size:19px;font-weight:800;margin:0;}
    .sb-brand h2 span{color:#38bdf8;}
    .sb-brand p{color:rgba(255,255,255,.35);font-size:10px;margin:2px 0 0;}
    .sb-nav{flex:1;padding:14px 0;overflow-y:auto;}
    .sb-section{padding:8px 22px 4px;font-size:9px;font-weight:700;color:rgba(255,255,255,.25);letter-spacing:1.4px;text-transform:uppercase;}
    .sb-nav ul{list-style:none;margin:0;padding:0;}
    .sb-nav li a{display:flex;align-items:center;gap:11px;padding:10px 22px;color:rgba(255,255,255,.6);text-decoration:none;font-size:13px;font-weight:500;transition:all .2s;border-left:3px solid transparent;}
    .sb-nav li a:hover{background:rgba(255,255,255,.07);color:#fff;}
    .sb-nav li.active a{background:rgba(56,189,248,.12);color:#fff;border-left-color:#38bdf8;}
    .sb-nav li a i{width:17px;text-align:center;font-size:14px;}
    .sb-footer{padding:14px 22px;border-top:1px solid rgba(255,255,255,.07);}
    .sb-user{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
    .sb-av{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#1792bb,#0f5f80);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;flex-shrink:0;}
    .sb-meta strong{display:block;color:#fff;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .sb-meta span{color:rgba(255,255,255,.4);font-size:10px;}
    .sb-out{display:flex;align-items:center;justify-content:center;gap:6px;padding:8px;width:100%;background:rgba(255,255,255,.07);color:rgba(255,255,255,.6);border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:12px;font-weight:500;text-decoration:none;transition:background .2s;}
    .sb-out:hover{background:rgba(255,255,255,.13);color:#fff;}
    /* Main */
    .sd-main{margin-left:0;min-height:100vh;display:flex;flex-direction:column;}
    @media(min-width:901px){.sd-main{margin-left:260px;}}
    .sd-topbar{background:#fff;padding:0 28px;height:60px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:50;box-shadow:0 1px 4px rgba(0,0,0,.04);}
    .sd-topbar-title h3{font-size:16px;font-weight:700;color:#0f172a;margin:0;}
    .sd-topbar-title p{font-size:12px;color:#64748b;margin:0;}
    .sd-content{padding:24px 28px 40px;flex:1;}
    /* Hero */
    .prog-hero{border-radius:20px;overflow:hidden;margin-bottom:24px;background:linear-gradient(135deg,#0f2d4a 0%,#1792bb 100%);position:relative;min-height:130px;}
    .prog-hero-dots{position:absolute;inset:0;opacity:.06;background-image:radial-gradient(circle,#fff 1.5px,transparent 1.5px);background-size:24px 24px;pointer-events:none;}
    .prog-hero-inner{position:relative;z-index:1;padding:28px 32px;display:flex;align-items:center;gap:24px;flex-wrap:wrap;}
    .prog-icon{width:72px;height:72px;border-radius:16px;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .prog-icon i{font-size:30px;color:#fff;}
    .prog-info{flex:1;min-width:0;}
    .prog-info h2{font-size:22px;font-weight:800;color:#fff;margin:0 0 4px;}
    .prog-info .prog-desc{font-size:13px;color:rgba(255,255,255,.75);margin-bottom:10px;}
    .prog-pills{display:flex;flex-wrap:wrap;gap:8px;}
    .ppill{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.15);color:rgba(255,255,255,.92);padding:4px 12px;border-radius:99px;font-size:11px;font-weight:600;border:1px solid rgba(255,255,255,.2);backdrop-filter:blur(4px);}
    .ppill i{font-size:10px;opacity:.8;}
    .prog-stats{display:flex;gap:16px;flex-shrink:0;}
    .pstat{text-align:center;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:12px;padding:12px 18px;min-width:80px;}
    .pstat strong{display:block;font-size:22px;font-weight:800;color:#fff;line-height:1;}
    .pstat span{font-size:10px;color:rgba(255,255,255,.6);font-weight:600;text-transform:uppercase;letter-spacing:.5px;}
    /* Cards */
    .pg-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
    @media(max-width:900px){.pg-grid{grid-template-columns:1fr;}}
    .pg-card{background:#fff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.04);}
    .pg-card-hdr{padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;}
    .pg-card-hdr h4{font-size:13px;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:7px;}
    .pg-card-hdr a{font-size:11px;color:#1792bb;font-weight:600;text-decoration:none;}
    .pg-card-hdr a:hover{text-decoration:underline;}
    /* Info grid */
    .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:#f1f5f9;}
    .info-cell{background:#fff;padding:16px 18px;}
    .info-cell .lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#94a3b8;margin-bottom:4px;}
    .info-cell .val{font-size:15px;font-weight:700;color:#0f172a;}
    .info-cell .val.empty{font-weight:400;color:#94a3b8;font-size:13px;}
    /* Class rows */
    .cl-row{display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid #f8fafc;transition:background .15s;}
    .cl-row:last-child{border-bottom:none;}
    .cl-row:hover{background:#f8fafc;}
    .cl-ico{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#1792bb,#0f5f80);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .cl-ico i{font-size:14px;color:#fff;}
    .cl-body{flex:1;min-width:0;}
    .cl-body strong{display:block;font-size:13px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .cl-body span{font-size:11px;color:#94a3b8;}
    .cl-chip{font-size:11px;font-weight:600;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;padding:3px 9px;border-radius:6px;white-space:nowrap;}
    /* Classmates */
    .cm-row{display:flex;align-items:center;gap:12px;padding:10px 18px;border-bottom:1px solid #f8fafc;}
    .cm-row:last-child{border-bottom:none;}
    .cm-av{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,#6366f1,#4338ca);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;flex-shrink:0;}
    .cm-name{font-size:13px;font-weight:600;color:#0f172a;}
    .cm-code{font-size:11px;color:#94a3b8;}
    /* Empty */
    .empty-msg{text-align:center;padding:32px 16px;color:#94a3b8;font-size:13px;}
    .empty-msg i{display:block;font-size:26px;margin-bottom:8px;opacity:.35;}
    /* No program */
    .no-prog{text-align:center;padding:60px 24px;background:#fff;border-radius:16px;border:1px solid #e2e8f0;}
    .no-prog i{font-size:48px;color:#cbd5e1;margin-bottom:16px;display:block;}
    .no-prog h4{font-size:18px;font-weight:700;color:#334155;margin-bottom:8px;}
    .no-prog p{font-size:13px;color:#64748b;max-width:340px;margin:0 auto;}
    footer.sd-footer{text-align:center;padding:14px;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;background:#fff;}
    @media(max-width:600px){.hide-mobile{display:none !important;}}
  </style>
</head>
<body>
<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<aside class="sd-sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo"><i class="fa fa-graduation-cap"></i></div>
    <h2>Cen<span>Learn</span></h2>
    <p>Learning Management System</p>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Student Menu</div>
    <ul>
      <li><a href="dashboard.php"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li><a href="classes.php"><i class="fa fa-book"></i> My Classes</a></li>


    </ul>
  </nav>
  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-av"><?php echo htmlspecialchars($initials); ?></div>
      <div class="sb-meta">
        <strong><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></strong>
        <span><?php echo htmlspecialchars($programCode ?: 'Student'); ?></span>
      </div>
    </div>
    <a href="../logout.php" class="sb-out"><i class="fa fa-sign-out"></i> Sign Out</a>
  </div>
</aside>

<div class="sd-main">
  <header class="sd-topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Open menu"><i class="fa fa-bars"></i></button>
      <div class="sd-topbar-title">
        <h3>My Program</h3>
        <p><?php echo date('l, F j, Y'); ?></p>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
    </div>
  </header>

  <div class="sd-content">

  <?php if(empty($programCode)): ?>
  <!-- No program assigned -->
  <div class="no-prog">
    <i class="fa fa-university"></i>
    <h4>No Program Assigned</h4>
    <p>Your program information hasn't been set yet. Please contact your registrar or log in again to sync your data from TechnoPal.</p>
    <a href="dashboard.php" style="margin-top:16px;display:inline-block;padding:9px 20px;background:linear-gradient(135deg,#1792bb,#0f5f80);color:#fff;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;">
      <i class="fa fa-home"></i> Back to Dashboard
    </a>
  </div>

  <?php else: ?>

  <!-- Hero Banner -->
  <div class="prog-hero">
    <div class="prog-hero-dots"></div>
    <div class="prog-hero-inner">
      <div class="prog-icon"><i class="fa fa-university"></i></div>
      <div class="prog-info">
        <h2><?php echo htmlspecialchars($programCode); ?></h2>
        <div class="prog-desc"><?php echo htmlspecialchars($programDesc ?: 'Bago City College'); ?></div>
        <div class="prog-pills">
          <span class="ppill"><i class="fa fa-calendar"></i><?php echo htmlspecialchars($yearLabel); ?></span>
          <?php if($section): ?><span class="ppill"><i class="fa fa-tag"></i>Section <?php echo htmlspecialchars($section); ?></span><?php endif; ?>
          <?php if($department): ?><span class="ppill"><i class="fa fa-building"></i><?php echo htmlspecialchars($department); ?></span><?php endif; ?>
          <span class="ppill"><i class="fa fa-circle" style="color:#4ade80;font-size:7px;"></i><?php echo $isGraduated?'Graduate':'Enrolled'; ?></span>
        </div>
      </div>
      <div class="prog-stats">
        <div class="pstat">
          <strong><?php echo count($classRows); ?></strong>
          <span>Classes</span>
        </div>
        <div class="pstat">
          <strong><?php echo count($classmates); ?></strong>
          <span>Classmates</span>
        </div>
        <div class="pstat">
          <strong><?php echo $pendingCount; ?></strong>
          <span>Pending</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Program Details + Classmates row -->
  <div class="pg-grid" style="margin-bottom:20px;">

    <!-- Program Info Card -->
    <div class="pg-card">
      <div class="pg-card-hdr">
        <h4><i class="fa fa-id-card" style="color:#1792bb;"></i> Program Details</h4>
      </div>
      <div class="info-grid">
        <div class="info-cell">
          <div class="lbl">Program Code</div>
          <div class="val"><?php echo htmlspecialchars($programCode ?: '—'); ?></div>
        </div>
        <div class="info-cell">
          <div class="lbl">Year Level</div>
          <div class="val"><?php echo htmlspecialchars($yearLabel); ?></div>
        </div>
        <div class="info-cell" style="grid-column:1/-1;">
          <div class="lbl">Program Description</div>
          <div class="val" style="font-size:13px;"><?php echo htmlspecialchars($programDesc ?: '—'); ?></div>
        </div>
        <div class="info-cell">
          <div class="lbl">Section</div>
          <div class="val"><?php echo htmlspecialchars($section ?: '—'); ?></div>
        </div>
        <div class="info-cell">
          <div class="lbl">Student ID</div>
          <div class="val" style="font-size:13px;font-family:monospace;"><?php echo htmlspecialchars($user['user_code']); ?></div>
        </div>
        <div class="info-cell">
          <div class="lbl">Student Name</div>
          <div class="val" style="font-size:13px;"><?php echo htmlspecialchars($fullName); ?></div>
        </div>
        <div class="info-cell">
          <div class="lbl">Status</div>
          <div class="val">
            <?php if($isGraduated): ?>
              <span style="background:#fef3c7;color:#d97706;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700;">Graduate</span>
            <?php else: ?>
              <span style="background:#dcfce7;color:#15803d;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700;">Enrolled</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Classmates Card -->
    <div class="pg-card">
      <div class="pg-card-hdr">
        <h4><i class="fa fa-users" style="color:#8b5cf6;"></i> Classmates
          <span style="background:#f1f5f9;color:#64748b;border-radius:99px;padding:1px 8px;font-size:11px;font-weight:600;margin-left:6px;"><?php echo count($classmates); ?></span>
        </h4>
      </div>
      <?php if(empty($classmates)): ?>
      <div class="empty-msg"><i class="fa fa-users"></i>No classmates found in your section.</div>
      <?php else: ?>
      <div style="max-height:330px;overflow-y:auto;">
        <?php foreach($classmates as $cm):
          $cIni = strtoupper(substr($cm['first_name']??'',0,1).substr($cm['last_name']??'',0,1));
        ?>
        <div class="cm-row">
          <div class="cm-av"><?php echo htmlspecialchars($cIni); ?></div>
          <div>
            <div class="cm-name"><?php echo htmlspecialchars($cm['last_name'].', '.$cm['first_name']); ?></div>
            <div class="cm-code"><?php echo htmlspecialchars($cm['user_code']); ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- Program Classes -->
  <div class="pg-card" style="margin-bottom:20px;">
    <div class="pg-card-hdr">
      <h4><i class="fa fa-book" style="color:#1792bb;"></i> My Classes in <?php echo htmlspecialchars($programCode); ?></h4>
      <a href="classes.php">View all classes</a>
    </div>
    <?php if(empty($classRows)): ?>
    <div class="empty-msg"><i class="fa fa-book"></i>No classes found for your program yet.</div>
    <?php else: ?>
    <?php foreach($classRows as $cl):
      $teacher = trim(($cl['first_name']??'').' '.($cl['last_name']??''));
    ?>
    <div class="cl-row">
      <div class="cl-ico"><i class="fa fa-book-open"></i></div>
      <div class="cl-body">
        <strong><?php echo htmlspecialchars($cl['class_name']); ?></strong>
        <span><?php echo $teacher ? 'Teacher: '.htmlspecialchars($teacher) : htmlspecialchars($cl['class_code']); ?></span>
      </div>
      <?php if(!empty($cl['subject'])): ?>
      <span class="cl-chip"><?php echo htmlspecialchars($cl['subject']); ?></span>
      <?php endif; ?>
      <a href="classes.php" style="font-size:12px;color:#1792bb;text-decoration:none;margin-left:8px;white-space:nowrap;"><i class="fa fa-arrow-right"></i></a>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php endif; // end if programCode ?>

  </div><!-- sd-content -->

  <footer class="sd-footer">&copy; <?php echo date('Y'); ?> CenLearn &mdash; Bago City College LMS</footer>
</div><!-- sd-main -->

<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script>
function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('sidebarOverlay').style.display='block';}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebarOverlay').style.display='none';}
</script>
</body>
</html>
