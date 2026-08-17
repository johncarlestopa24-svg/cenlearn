<?php
include '../includes/session.php';
include '../includes/conn.php';

$uc = $conn->real_escape_string($user['user_code']);

// Auto-create confirmations table
$conn->query("CREATE TABLE IF NOT EXISTS `class_confirmations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `student_code` varchar(50) NOT NULL,
  `status` enum('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  `responded_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_student` (`class_id`,`student_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Auto-confirm all enrolled subject classes for this student
$conn->query("
    INSERT INTO class_confirmations (class_id, student_code, status, responded_at)
    SELECT cm.class_id, '$uc', 'accepted', NOW()
    FROM class_members cm
    JOIN classes c ON cm.class_id = c.id
    LEFT JOIN class_confirmations cc ON cc.class_id = cm.class_id AND cc.student_code = '$uc'
    WHERE cm.user_code = '$uc' AND (cc.status IS NULL OR cc.status = 'pending')
    ON DUPLICATE KEY UPDATE status='accepted', responded_at=NOW()
");

// Get all class confirmations for this student
$confirmations = $conn->query("
    SELECT c.id AS class_id, c.class_name, c.subject, c.section, c.year_level,
           c.schedule_json, c.schedule_room, c.created_at AS class_created,
           u.first_name AS teacher_first, u.last_name AS teacher_last,
           u.user_code AS teacher_code,
           COALESCE(cc.status,'pending') AS status,
           cc.responded_at, cc.created_at AS notif_created
    FROM class_members cm
    JOIN classes c ON cm.class_id=c.id
    LEFT JOIN users u ON c.teacher_code=u.user_code
    LEFT JOIN class_confirmations cc ON cc.class_id=c.id AND cc.student_code='$uc'
    WHERE cm.user_code='$uc' AND c.teacher_code!='$uc'
      AND (c.is_archived=0 OR c.is_archived IS NULL)
    ORDER BY FIELD(COALESCE(cc.status,'pending'),'pending','accepted','declined'), cm.joined_at DESC
");
$rows = [];
while($r = $confirmations->fetch_assoc()) $rows[] = $r;

$pendingCount  = count(array_filter($rows, fn($r) => $r['status']==='pending'));
$acceptedCount = count(array_filter($rows, fn($r) => $r['status']==='accepted'));
$declinedCount = count(array_filter($rows, fn($r) => $r['status']==='declined'));
$totalCount    = count($rows);

$initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));

// Color palette for class avatars
$palette = ['#1792bb','#10b981','#8b5cf6','#f59e0b','#ef4444','#06b6d4','#ec4899','#0ea5e9'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CenLearn — Notifications</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html,body{margin:0;padding:0;overflow-x:hidden;}
    body { font-family: 'Inter', sans-serif; background: #f0f4f8; color: #1e293b; }

    /* ── Sidebar ── */
    .s-sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: linear-gradient(180deg, #0c1a2e 0%, #0f2d4a 55%, #0f5f80 100%); display: flex; flex-direction: column; z-index: 200; transition: transform .3s cubic-bezier(.4,0,.2,1); transform: translateX(-260px); }
    .s-sidebar.open{transform: translateX(0);}
    @media(min-width:901px){.s-sidebar{transform: translateX(0);}}
    .s-brand { padding: 22px 20px 16px; border-bottom: 1px solid rgba(255,255,255,.07); }
    .s-logo { width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, #1792bb, #0f5f80); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 8px; box-shadow: 0 4px 14px rgba(23,146,187,.4); }
    .s-logo i { color: #fff; font-size: 17px; }
    .s-brand h2 { color: #fff; font-size: 18px; font-weight: 800; margin: 0; }
    .s-brand h2 span { color: #38bdf8; }
    .s-brand p { color: rgba(255,255,255,.3); font-size: 10px; margin: 2px 0 0; }
    .s-nav { flex: 1; padding: 10px 0; overflow-y: auto; }
    .s-nav-sec { padding: 10px 20px 4px; font-size: 9px; font-weight: 700; color: rgba(255,255,255,.22); letter-spacing: 1.5px; text-transform: uppercase; }
    .s-nav ul { list-style: none; margin: 0; padding: 0; }
    .s-nav li a { display: flex; align-items: center; gap: 10px; padding: 10px 20px; color: rgba(255,255,255,.55); text-decoration: none; font-size: 13px; font-weight: 500; transition: all .18s; border-left: 3px solid transparent; }
    .s-nav li a:hover { background: rgba(255,255,255,.06); color: #fff; }
    .s-nav li.active a { background: rgba(56,189,248,.12); color: #fff; border-left-color: #38bdf8; }
    .s-nav li a i { width: 16px; text-align: center; font-size: 13px; }
    .s-footer { padding: 14px 20px; border-top: 1px solid rgba(255,255,255,.07); }
    .s-user { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .s-av { width: 36px; height: 36px; border-radius: 9px; background: linear-gradient(135deg, #1792bb, #0f5f80); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; color: #fff; flex-shrink: 0; }
    .s-meta strong { display: block; color: #fff; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px; }
    .s-meta span { color: rgba(255,255,255,.38); font-size: 10px; }
    .s-out { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 8px; width: 100%; background: rgba(255,255,255,.06); color: rgba(255,255,255,.55); border: 1px solid rgba(255,255,255,.1); border-radius: 8px; font-size: 12px; font-weight: 500; text-decoration: none; transition: all .18s; }
    .s-out:hover { background: rgba(255,255,255,.12); color: #fff; }

    /* ── Layout ── */
    .ib-main { margin-left: 0; min-height: 100vh; display: flex; flex-direction: column; transition: margin 0s;}
    @media(min-width:901px){.ib-main{margin-left: 260px;}}
    .ib-topbar { background: #fff; padding: 0 28px; height: 62px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 50; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
    .ib-topbar-left { display: flex; align-items: center; gap: 12px; }
    .ib-topbar h3 { font-size: 16px; font-weight: 700; color: #0f172a; margin: 0; }
    .ib-topbar p { font-size: 12px; color: #64748b; margin: 0; }
    .ib-content { padding: 28px 28px 60px; flex: 1; max-width: 900px; width: 100%; margin: 0 auto; }

    /* ── Hero banner ── */
    .ib-hero { background: linear-gradient(135deg, #0f2d4a 0%, #1792bb 100%); border-radius: 20px; padding: 28px 32px; margin-bottom: 28px; display: flex; align-items: center; justify-content: space-between; gap: 20px; overflow: hidden; position: relative; }
    .ib-hero::before { content: ''; position: absolute; top: -40px; right: -40px; width: 200px; height: 200px; border-radius: 50%; background: rgba(255,255,255,.05); }
    .ib-hero::after { content: ''; position: absolute; bottom: -60px; right: 80px; width: 140px; height: 140px; border-radius: 50%; background: rgba(255,255,255,.04); }
    .ib-hero-text h2 { color: #fff; font-size: 22px; font-weight: 800; margin: 0 0 6px; }
    .ib-hero-text p { color: rgba(255,255,255,.65); font-size: 13px; margin: 0; }
    .ib-hero-icon { width: 64px; height: 64px; border-radius: 18px; background: rgba(255,255,255,.12); backdrop-filter: blur(8px); display: flex; align-items: center; justify-content: center; flex-shrink: 0; position: relative; z-index: 1; }
    .ib-hero-icon i { font-size: 26px; color: #fff; }
    .ib-hero-badge { position: absolute; top: -6px; right: -6px; width: 22px; height: 22px; background: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 800; color: #fff; border: 2px solid #fff; }

    /* ── Stats row ── */
    .stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 28px; }
    .stat-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; transition: box-shadow .2s, transform .2s; }
    .stat-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.07); transform: translateY(-2px); }
    .stat-card .sc-icon { width: 46px; height: 46px; border-radius: 13px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .stat-card .sc-icon i { font-size: 18px; }
    .stat-card .sc-val { font-size: 28px; font-weight: 800; color: #0f172a; line-height: 1; }
    .stat-card .sc-lbl { font-size: 12px; color: #64748b; font-weight: 500; margin-top: 2px; }
    .stat-card.pending-card { border-top: 3px solid #f59e0b; }
    .stat-card.accepted-card { border-top: 3px solid #10b981; }
    .stat-card.declined-card { border-top: 3px solid #ef4444; }
    @media(max-width:600px){.hide-mobile{display:none !important;}}
  </style>
  <style>
    /* ── Filter tabs ── */
    .filter-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
    .filter-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border: 1.5px solid #e2e8f0; border-radius: 99px; background: #fff; font-size: 12px; font-weight: 600; color: #64748b; cursor: pointer; font-family: 'Inter', sans-serif; transition: all .15s; }
    .filter-btn:hover { border-color: #1792bb; color: #1792bb; }
    .filter-btn.active { background: #1792bb; border-color: #1792bb; color: #fff; }
    .filter-btn .fb-count { background: rgba(255,255,255,.25); padding: 1px 7px; border-radius: 99px; font-size: 10px; font-weight: 700; }
    .filter-btn:not(.active) .fb-count { background: #f1f5f9; color: #64748b; }

    /* ── Notification item ── */
    .notif-list { display: flex; flex-direction: column; gap: 12px; }
    .notif-item { background: #fff; border: 1px solid #e8edf2; border-radius: 18px; overflow: hidden; transition: box-shadow .22s, transform .22s; }
    .notif-item:hover { box-shadow: 0 8px 28px rgba(0,0,0,.09); transform: translateY(-2px); }
    .notif-item.is-pending { border-left: 4px solid #f59e0b; }
    .notif-item.is-accepted { border-left: 4px solid #10b981; }
    .notif-item.is-declined { border-left: 4px solid #ef4444; }

    .notif-header { display: flex; align-items: center; gap: 14px; padding: 18px 20px 14px; }
    .notif-avatar { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 18px; font-weight: 800; color: #fff; }
    .notif-meta { flex: 1; min-width: 0; }
    .notif-title { font-size: 15px; font-weight: 700; color: #0f172a; margin: 0 0 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .notif-subject { font-size: 12px; color: #64748b; margin: 0; }
    .notif-status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 5px 13px; border-radius: 99px; font-size: 11px; font-weight: 700; flex-shrink: 0; }
    .nsb-pending { background: #fef3c7; color: #92400e; }
    .nsb-accepted { background: #dcfce7; color: #166534; }
    .nsb-declined { background: #fee2e2; color: #991b1b; }

    /* ── Schedule grid ── */
    .notif-body { padding: 0 20px 16px; }
    .sched-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
    .sched-pill { display: flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 12px; }
    .sched-pill .sp-day { font-size: 11px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: .5px; min-width: 28px; }
    .sched-pill .sp-time { font-size: 12px; color: #475569; font-weight: 500; }
    .sched-pill .sp-dot { width: 5px; height: 5px; border-radius: 50%; background: #cbd5e1; flex-shrink: 0; }

    /* ── Info chips ── */
    .info-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px; }
    .chip { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .chip i { font-size: 9px; }
    .chip-teacher { background: #eff6ff; color: #1d4ed8; }
    .chip-room { background: #f0f9ff; color: #0369a1; }
    .chip-year { background: #fef3c7; color: #92400e; }
    .chip-sec { background: #fdf4ff; color: #7e22ce; }

    /* ── Action row ── */
    .notif-actions { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-top: 1px solid #f1f5f9; background: #fafbfc; }
    .notif-time { font-size: 11px; color: #94a3b8; display: flex; align-items: center; gap: 5px; }
    .action-btns { display: flex; gap: 8px; }
    .btn-accept { display: inline-flex; align-items: center; gap: 7px; padding: 9px 20px; background: linear-gradient(135deg, #10b981, #059669); color: #fff; border: none; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; font-family: 'Inter', sans-serif; transition: opacity .15s, transform .1s; box-shadow: 0 2px 8px rgba(16,185,129,.3); }
    .btn-accept:hover { opacity: .88; transform: translateY(-1px); }
    .btn-decline { display: inline-flex; align-items: center; gap: 7px; padding: 9px 20px; background: #fff; color: #dc2626; border: 1.5px solid #fca5a5; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; font-family: 'Inter', sans-serif; transition: all .15s; }
    .btn-decline:hover { background: #fef2f2; border-color: #ef4444; }
    .responded-info { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #64748b; }
    .responded-info .ri-badge { display: inline-flex; align-items: center; gap: 5px; padding: 6px 14px; border-radius: 99px; font-size: 12px; font-weight: 700; }
    .ri-accepted { background: #dcfce7; color: #166534; }
    .ri-declined { background: #fee2e2; color: #991b1b; }

    /* ── Empty state ── */
    .empty-state { text-align: center; padding: 72px 24px; background: #fff; border-radius: 20px; border: 1px solid #e2e8f0; }
    .empty-icon { width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, rgba(23,146,187,.08), rgba(23,146,187,.03)); border: 2px dashed rgba(23,146,187,.22); display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
    .empty-icon i { font-size: 32px; color: rgba(23,146,187,.45); }
    .empty-state h5 { font-size: 17px; font-weight: 700; color: #374151; margin: 0 0 8px; }
    .empty-state p { font-size: 13px; color: #94a3b8; margin: 0; max-width: 320px; margin: 0 auto; }

    /* ── Toast ── */
    .ib-toast { position: fixed; bottom: 28px; right: 28px; z-index: 9999; display: flex; align-items: center; gap: 10px; padding: 14px 20px; border-radius: 14px; font-size: 13px; font-weight: 600; color: #fff; box-shadow: 0 8px 28px rgba(0,0,0,.18); transform: translateY(80px); opacity: 0; transition: all .3s cubic-bezier(.34,1.56,.64,1); pointer-events: none; }
    .ib-toast.show { transform: translateY(0); opacity: 1; }
    .ib-toast.success { background: linear-gradient(135deg, #10b981, #059669); }
    .ib-toast.error { background: linear-gradient(135deg, #ef4444, #dc2626); }

    footer.ib-footer { text-align: center; padding: 14px; font-size: 11px; color: #94a3b8; border-top: 1px solid #e2e8f0; background: #fff; }
    @media (max-width: 768px) {
      .ib-main { margin-left: 0; }
      .ib-content { padding: 16px 14px 48px; }
      .stats-row { grid-template-columns: repeat(3, 1fr); gap: 8px; }
      .stat-card { padding: 14px; }
      .stat-card .sc-val { font-size: 22px; }
      .ib-hero { padding: 20px; }
      .ib-hero-text h2 { font-size: 17px; }
      .notif-actions { flex-direction: column; align-items: flex-start; gap: 10px; }
    }
  </style>
</head>
<body>
<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<aside class="s-sidebar" id="sidebar">
  <div class="s-brand">
    <div class="s-logo"><i class="fa fa-graduation-cap"></i></div>
    <h2>Cen<span>Learn</span></h2>
    <p>Learning Management System</p>
  </div>
  <nav class="s-nav">
    <div class="s-nav-sec">Student Menu</div>
    <ul>
      <li><a href="dashboard.php"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li><a href="classes.php"><i class="fa fa-book"></i> My Classes</a></li>


    </ul>
  </nav>
  <div class="s-footer">
    <div class="s-user">
      <div class="s-av"><?php echo $initials; ?></div>
      <div class="s-meta">
        <strong><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></strong>
        <span>Student &bull; <?php echo htmlspecialchars($user['program_code'] ?? 'No program'); ?></span>
      </div>
    </div>
    <a href="../logout.php" class="s-out"><i class="fa fa-sign-out"></i> Sign Out</a>
  </div>
</aside>

<div class="ib-main">
  <header class="ib-topbar">
    <div class="ib-topbar-left">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
      <div>
        <h3>Notifications</h3>
        <p><?php echo $totalCount; ?> notification<?php echo $totalCount !== 1 ? 's' : ''; ?><?php echo $pendingCount > 0 ? ' &bull; '.$pendingCount.' need your response' : ''; ?></p>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <a href="program.php" class="btn-primary-sm" style="background:#fff;color:#1792bb;border:1.5px solid #1792bb;position:relative;width:34px;height:34px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;" title="My Program">
        <i class="fa fa-university" style="font-size:14px;color:#1792bb;"></i>
      </a>
      <a href="classes.php" class="btn-primary-sm" style="background:#fff;color:#1792bb;border:1.5px solid #1792bb;position:relative;width:34px;height:34px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;" title="My Classes">
        <i class="fa fa-book" style="font-size:14px;color:#1792bb;"></i>
      </a>
      <?php if($pendingCount > 0): ?>
      <button class="filter-btn active" id="acceptAllBtn" onclick="acceptAll()" style="background:linear-gradient(135deg,#10b981,#059669);border-color:#10b981;color:#fff;border-radius:10px;padding:9px 18px;height:34px;display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:11px;font-weight:700;border:none;">
        <i class="fa fa-check-double"></i> <span class="hide-mobile">Accept All</span>
      </button>
      <?php endif; ?>
      <?php if($totalCount > 0): ?>
      <button class="filter-btn" onclick="clearAllNotifications()" style="background:#fff;border:1.5px solid #fca5a5;color:#dc2626;border-radius:10px;padding:9px 14px;height:34px;display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:11px;font-weight:700;">
        <i class="fa fa-trash-o"></i> <span class="hide-mobile">Clear All</span>
      </button>
      <?php endif; ?>
    </div>
  </header>

  <div class="ib-content">

    <!-- Hero banner -->
    <div class="ib-hero">
      <div class="ib-hero-text">
        <h2>Class Schedule Notifications</h2>
        <p>Review and respond to class schedules assigned by your teachers.</p>
      </div>
      <div class="ib-hero-icon" style="position:relative;">
        <i class="fa fa-bell"></i>
        <?php if($pendingCount > 0): ?>
        <div class="ib-hero-badge"><?php echo $pendingCount; ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Stats row -->
    <div class="stats-row">
      <div class="stat-card pending-card" onclick="filterByCard('pending')" style="cursor:pointer;">
        <div class="sc-icon" style="background:#fef3c7;"><i class="fa fa-clock-o" style="color:#d97706;"></i></div>
        <div>
          <div class="sc-val"><?php echo $pendingCount; ?></div>
          <div class="sc-lbl">Pending</div>
        </div>
      </div>
      <div class="stat-card accepted-card" onclick="filterByCard('accepted')" style="cursor:pointer;">
        <div class="sc-icon" style="background:#dcfce7;"><i class="fa fa-check-circle" style="color:#16a34a;"></i></div>
        <div>
          <div class="sc-val"><?php echo $acceptedCount; ?></div>
          <div class="sc-lbl">Accepted</div>
        </div>
      </div>
      <div class="stat-card declined-card" onclick="filterByCard('declined')" style="cursor:pointer;">
        <div class="sc-icon" style="background:#fee2e2;"><i class="fa fa-times-circle" style="color:#dc2626;"></i></div>
        <div>
          <div class="sc-val"><?php echo $declinedCount; ?></div>
          <div class="sc-lbl">Declined</div>
        </div>
      </div>
    </div>


    <?php if(empty($rows)): ?>
    <!-- Empty state -->
    <div class="empty-state">
      <div class="empty-icon"><i class="fa fa-inbox"></i></div>
      <h5>Your inbox is empty</h5>
      <p>When a teacher creates a class for you, the notification will appear here.</p>
    </div>
    <?php else: ?>

    <!-- Filter tabs -->
    <div class="filter-bar">
      <button class="filter-btn active" onclick="filterItems('all', this)">
        <i class="fa fa-list"></i> All
        <span class="fb-count"><?php echo $totalCount; ?></span>
      </button>
      <button class="filter-btn" onclick="filterItems('pending', this)">
        <i class="fa fa-clock-o"></i> Pending
        <span class="fb-count"><?php echo $pendingCount; ?></span>
      </button>
      <button class="filter-btn" onclick="filterItems('accepted', this)">
        <i class="fa fa-check"></i> Accepted
        <span class="fb-count"><?php echo $acceptedCount; ?></span>
      </button>
      <button class="filter-btn" onclick="filterItems('declined', this)">
        <i class="fa fa-times"></i> Declined
        <span class="fb-count"><?php echo $declinedCount; ?></span>
      </button>
    </div>

    <!-- Notification list -->
    <div class="notif-list" id="notifList">
    <?php foreach($rows as $idx => $r):
      $schedArr    = !empty($r['schedule_json']) ? json_decode($r['schedule_json'], true) : [];
      $teacherName = trim($r['teacher_first'].' '.$r['teacher_last']);
      $color       = $palette[$idx % count($palette)];
      $classInitial = strtoupper(substr($r['class_name'], 0, 1));
      $timeAgo = '';
      $ts = strtotime($r['notif_created'] ?: $r['class_created']);
      if($ts){
        $diff = time() - $ts;
        if($diff < 60) $timeAgo = 'Just now';
        elseif($diff < 3600) $timeAgo = floor($diff/60).'m ago';
        elseif($diff < 86400) $timeAgo = floor($diff/3600).'h ago';
        elseif($diff < 604800) $timeAgo = floor($diff/86400).'d ago';
        else $timeAgo = date('M d, Y', $ts);
      }
    ?>
    <div class="notif-item is-<?php echo $r['status']; ?>" id="item-<?php echo $r['class_id']; ?>" data-status="<?php echo $r['status']; ?>">

      <!-- Header -->
      <div class="notif-header">
        <div class="notif-avatar" style="background:<?php echo $color; ?>;">
          <?php echo $classInitial; ?>
        </div>
        <div class="notif-meta">
          <div class="notif-title"><?php echo htmlspecialchars($r['class_name']); ?></div>
          <div class="notif-subject">
            <?php if($r['subject']): ?><?php echo htmlspecialchars($r['subject']); ?> &bull; <?php endif; ?>
            <i class="fa fa-user" style="font-size:10px;"></i> <?php echo htmlspecialchars($teacherName); ?>
          </div>
        </div>
        <span class="notif-status-badge nsb-<?php echo $r['status']; ?>">
          <?php if($r['status']==='pending'): ?><i class="fa fa-clock-o"></i> Pending
          <?php elseif($r['status']==='accepted'): ?><i class="fa fa-check-circle"></i> Accepted
          <?php else: ?><i class="fa fa-times-circle"></i> Declined<?php endif; ?>
        </span>
      </div>

      <!-- Body: schedule + chips -->
      <div class="notif-body">

        <?php if(!empty($schedArr)): ?>
        <div class="sched-grid">
          <?php foreach($schedArr as $s):
            $ts2 = date('g:i A', strtotime($s['start']));
            $te2 = date('g:i A', strtotime($s['end']));
          ?>
          <div class="sched-pill">
            <span class="sp-day"><?php echo htmlspecialchars(substr($s['day'],0,3)); ?></span>
            <span class="sp-dot"></span>
            <span class="sp-time"><?php echo $ts2; ?> – <?php echo $te2; ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="info-chips">
          <?php if($r['year_level']): ?><span class="chip chip-year"><i class="fa fa-calendar"></i> Year <?php echo $r['year_level']; ?></span><?php endif; ?>
          <?php if($r['section']): ?><span class="chip chip-sec"><i class="fa fa-users"></i> Sec <?php echo htmlspecialchars($r['section']); ?></span><?php endif; ?>
          <?php if($r['schedule_room']): ?><span class="chip chip-room"><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($r['schedule_room']); ?></span><?php endif; ?>
        </div>

      </div>

      <!-- Action footer -->
      <div class="notif-actions">
        <div class="notif-time">
          <i class="fa fa-clock-o"></i>
          <?php echo $timeAgo; ?>
          <?php if($r['notif_created']): ?>&nbsp;&bull;&nbsp;<?php echo date('M d, Y', strtotime($r['notif_created'])); ?><?php endif; ?>
        </div>

        <?php if($r['status'] === 'pending'): ?>
        <div class="action-btns">
          <button class="btn-decline" onclick="respond(<?php echo $r['class_id']; ?>, 'declined', this)">
            <i class="fa fa-times"></i> Decline
          </button>
          <button class="btn-accept" onclick="respond(<?php echo $r['class_id']; ?>, 'accepted', this)">
            <i class="fa fa-check"></i> Accept Schedule
          </button>
          <button type="button" class="btn btn-default" onclick="deleteNotification(<?php echo $r['class_id']; ?>)" title="Delete Notification" style="border-radius:10px; padding:9px 12px; color:#ef4444; border:1px solid #fca5a5; background:#fff;">
            <i class="fa fa-trash-o"></i>
          </button>
        </div>
        <?php else: ?>
        <div class="responded-info">
          <span class="ri-badge ri-<?php echo $r['status']; ?>">
            <i class="fa <?php echo $r['status']==='accepted' ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
            <?php echo ucfirst($r['status']); ?>
          </span>
          <?php if($r['responded_at']): ?>
          <span><?php echo date('M d, Y \a\t g:i A', strtotime($r['responded_at'])); ?></span>
          <?php endif; ?>
          <button type="button" class="btn btn-default btn-xs" onclick="deleteNotification(<?php echo $r['class_id']; ?>)" title="Delete Notification" style="border-radius:6px; color:#ef4444; border:1px solid #fca5a5; margin-left:8px; padding:3px 8px;">
            <i class="fa fa-trash-o"></i> Delete
          </button>
        </div>
        <?php endif; ?>
      </div>

    </div>
    <?php endforeach; ?>
    </div><!-- /notif-list -->

    <?php endif; ?>

  </div><!-- /ib-content -->
  <footer class="ib-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>

<!-- Toast -->
<div class="ib-toast" id="ibToast"></div>


<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script src="../bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
// ── Toast helper ──────────────────────────────────────────────────────────
function showToast(msg, type){
  var t = document.getElementById('ibToast');
  t.className = 'ib-toast ' + (type || 'success');
  t.innerHTML = '<i class="fa ' + (type === 'error' ? 'fa-times-circle' : 'fa-check-circle') + '"></i> ' + msg;
  t.classList.add('show');
  setTimeout(function(){ t.classList.remove('show'); }, 3200);
}

// ── Respond to a single class ─────────────────────────────────────────────
function respond(classId, status, btn){
  var card = document.getElementById('item-' + classId);
  var btns = card.querySelectorAll('.btn-accept, .btn-decline');
  btns.forEach(function(b){ b.disabled = true; });
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ' + (status === 'accepted' ? 'Accepting…' : 'Declining…');

  $.post('../shared/confirmation_handler.php', { action: 'respond', class_id: classId, status: status }, function(res){
    if(res.success){
      showToast(status === 'accepted' ? 'Schedule accepted successfully.' : 'Schedule declined.', status === 'accepted' ? 'success' : 'error');
      setTimeout(function(){ location.reload(); }, 1000);
    } else {
      btns.forEach(function(b){ b.disabled = false; });
      btn.innerHTML = status === 'accepted' ? '<i class="fa fa-check"></i> Accept Schedule' : '<i class="fa fa-times"></i> Decline';
      showToast(res.msg || 'Something went wrong. Please try again.', 'error');
    }
  }, 'json').fail(function(){
    btns.forEach(function(b){ b.disabled = false; });
    btn.innerHTML = status === 'accepted' ? '<i class="fa fa-check"></i> Accept Schedule' : '<i class="fa fa-times"></i> Decline';
    showToast('Network error. Please try again.', 'error');
  });
}

// ── Accept all pending ────────────────────────────────────────────────────
function acceptAll(){
  var pending = document.querySelectorAll('.notif-item[data-status="pending"]');
  if(!pending.length) return;
  var btn = document.getElementById('acceptAllBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Accepting…';

  var ids = [];
  pending.forEach(function(el){ ids.push(el.id.replace('item-', '')); });

  var completed = 0;
  var failed    = 0;
  var total     = ids.length;

  function next(index){
    if(index >= total){
      if(failed === 0){
        showToast('All ' + total + ' schedule' + (total > 1 ? 's' : '') + ' accepted.', 'success');
        setTimeout(function(){ location.reload(); }, 1200);
      } else {
        showToast(failed + ' item(s) could not be accepted. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-check-double"></i> Accept All Pending';
      }
      return;
    }
    $.post('../shared/confirmation_handler.php',
      { action: 'respond', class_id: ids[index], status: 'accepted' },
      function(res){
        if(res && res.success) completed++;
        else failed++;
        next(index + 1);
      }, 'json'
    ).fail(function(){
      failed++;
      next(index + 1);
    });
  }

  next(0);
}

// ── Delete single notification ───────────────────────────────────────────
function deleteNotification(classId){
  if(!confirm('Are you sure you want to delete this notification?')) return;
  $.post('../shared/confirmation_handler.php', { action: 'delete_notification', class_id: classId }, function(res){
    if(res && res.success){
      showToast('Notification deleted successfully.', 'success');
      var card = document.getElementById('item-' + classId);
      if(card) {
        card.style.transition = 'all .3s';
        card.style.opacity = '0';
        card.style.transform = 'translateY(-10px)';
        setTimeout(function(){ location.reload(); }, 400);
      } else {
        location.reload();
      }
    } else {
      showToast(res.msg || 'Failed to delete notification.', 'error');
    }
  }, 'json');
}

// ── Clear all notifications ─────────────────────────────────────────────
function clearAllNotifications(){
  if(!confirm('Are you sure you want to clear all your notifications?')) return;
  $.post('../shared/confirmation_handler.php', { action: 'clear_all_notifications' }, function(res){
    if(res && res.success){
      showToast('All notifications cleared.', 'success');
      setTimeout(function(){ location.reload(); }, 600);
    } else {
      showToast(res.msg || 'Failed to clear notifications.', 'error');
    }
  }, 'json');
}

// ── Filter tabs ───────────────────────────────────────────────────────────
function filterItems(status, btn){
  if(!btn) return;
  document.querySelectorAll('.filter-btn').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
  document.querySelectorAll('.notif-item').forEach(function(el){
    el.style.display = (status === 'all' || el.dataset.status === status) ? '' : 'none';
  });
}
function filterByCard(status) {
  var btnIndex = {all: 1, pending: 2, accepted: 3, declined: 4}[status];
  var btn = document.querySelector('.filter-btn:nth-child(' + btnIndex + ')');
  if(btn) filterItems(status, btn);
}

// ── Sidebar ───────────────────────────────────────────────────────────────
function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
</script>
</body>
</html>
