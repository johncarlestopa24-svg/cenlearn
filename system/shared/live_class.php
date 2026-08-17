<?php
include '../includes/session.php';
include '../includes/conn.php';

$class_id = intval($_GET['id'] ?? 0);
$uc       = $conn->real_escape_string($user['user_code']);
$role     = strtoupper($user['user_group']);

if(!$class_id){ header('location: '.($role==='TEACHER'?'../teacher/dashboard.php':'../student/dashboard.php')); exit; }

$cq = $conn->query("SELECT c.*, u.first_name AS tf, u.last_name AS tl FROM classes c LEFT JOIN users u ON c.teacher_code=u.user_code WHERE c.id=$class_id AND EXISTS (SELECT 1 FROM class_members WHERE class_id=$class_id AND user_code='$uc')");
if($cq->num_rows === 0){ die('Access denied.'); }
$class     = $cq->fetch_assoc();
$isTeacher = ($role === 'TEACHER' && $class['teacher_code'] === $user['user_code']);

$userName = htmlspecialchars($user['first_name'].' '.$user['last_name']);

// Get sessions
$sessQ = $conn->query("SELECT * FROM live_sessions WHERE class_id=$class_id ORDER BY created_at DESC");
$sessions = [];
while($s = $sessQ->fetch_assoc()) $sessions[] = $s;

// Fetch class modules for PowerPoint / presentation share
$pptModules = [];
$pmQ = $conn->query("SELECT id, title, filename, filename AS file_name, original_name, file_size FROM class_modules WHERE class_id=$class_id ORDER BY uploaded_at DESC");
if($pmQ) while($pm = $pmQ->fetch_assoc()) $pptModules[] = $pm;

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
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>CenLearn — Live Class</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    /* ── Base ── */
    *, *::before, *::after { box-sizing: border-box; }
    body { background: #0a0f1e; overflow: hidden; font-family: 'Inter', sans-serif; }

    /* ── Layout shell ── */
    .lc-topbar {
      position: fixed; top: 0; left: 260px; right: 0; height: 58px;
      background: rgba(10,15,30,.97); border-bottom: 1px solid rgba(255,255,255,.07);
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 18px; z-index: 100; backdrop-filter: blur(12px); gap: 12px;
      transition: left .25s;
    }
    .lc-topbar-left  { display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1; }
    .lc-topbar-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
    .lc-class-name {
      font-size: 14px; font-weight: 700; color: #f1f5f9;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 260px;
    }
    .lc-live-badge {
      display: inline-flex; align-items: center; gap: 5px;
      background: #ef4444; color: #fff; padding: 3px 10px;
      border-radius: 99px; font-size: 11px; font-weight: 700; flex-shrink: 0;
    }
    .lc-live-dot { width: 7px; height: 7px; border-radius: 50%; background: #fff; animation: blink 1.4s infinite; }
    @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }

    /* ── Main content area ── */
    .lc-main {
      position: fixed; top: 58px; left: 260px; right: 0; bottom: 0;
      background: #0a0f1e; overflow: hidden;
      display: flex; flex-direction: column;
      transition: left .25s;
    }
    /* Fullscreen call mode: hide sidebar completely when call starts */
    body.in-call .cl-sidebar,
    body.in-call .t-sidebar,
    body.in-call #sidebar {
      transform: translateX(-100%) !important;
      display: none !important;
      pointer-events: none !important;
    }
    body.in-call .cl-hamburger {
      display: none !important;
    }
    body.in-call .lc-topbar { left: 0 !important; }
    body.in-call .lc-main   { left: 0 !important; }
    body.in-call #lcControls::before { width: 0 !important; }

    /* ── Dashboard panel ── */
    #dashPanel {
      position: absolute; inset: 0;
      overflow-y: auto; padding: 24px;
      display: flex; flex-direction: column; gap: 20px;
    }
    .lc-card {
      background: #111827; border: 1px solid rgba(255,255,255,.07);
      border-radius: 16px; overflow: hidden;
    }
    .lc-card-hdr {
      padding: 15px 20px; border-bottom: 1px solid rgba(255,255,255,.06);
      display: flex; align-items: center; justify-content: space-between;
    }
    .lc-card-hdr h3 {
      font-size: 14px; font-weight: 700; color: #f1f5f9; margin: 0;
      display: flex; align-items: center; gap: 8px;
    }
    .lc-card-hdr h3 i { color: <?php echo $accent; ?>; }
    .lc-card-body { padding: 16px 20px; }

    /* ── Session cards ── */
    .sess-card {
      background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.06);
      border-radius: 12px; padding: 14px 16px;
      display: flex; align-items: center; gap: 14px; margin-bottom: 10px;
      transition: background .15s;
    }
    .sess-card:last-child { margin-bottom: 0; }
    .sess-card:hover { background: rgba(255,255,255,.05); }
    .sess-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    .sess-dot.live { background: #ef4444; box-shadow: 0 0 8px #ef4444; animation: blink 1.4s infinite; }
    .sess-dot.scheduled { background: #f59e0b; }
    .sess-dot.ended { background: #334155; }
    .sess-info { flex: 1; min-width: 0; }
    .sess-title { font-size: 13px; font-weight: 700; color: #f1f5f9; margin-bottom: 4px; }
    .sess-meta { font-size: 11px; color: #64748b; display: flex; gap: 12px; flex-wrap: wrap; }
    .sess-meta i { margin-right: 3px; }
    .sess-actions { display: flex; gap: 8px; flex-shrink: 0; flex-wrap: wrap; }
    .empty-state { text-align: center; padding: 32px 16px; color: #475569; font-size: 13px; }
    .empty-state i { font-size: 32px; margin-bottom: 10px; display: block; opacity: .4; }

    /* ── Buttons ── */
    .btn-lc {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 7px 14px; border-radius: 9px; font-size: 12px; font-weight: 700;
      border: none; cursor: pointer; font-family: 'Inter', sans-serif;
      transition: opacity .15s, transform .1s; white-space: nowrap;
    }
    .btn-lc:hover { opacity: .85; transform: translateY(-1px); }
    .btn-lc:active { transform: translateY(0); }
    .btn-lc.accent { background: linear-gradient(135deg, <?php echo $accent; ?>, <?php echo $accentDk; ?>); color: #fff; }
    .btn-lc.red    { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; }
    .btn-lc.blue   { background: linear-gradient(135deg, #1792bb, #0f5f80); color: #fff; }
    .btn-lc.ghost  { background: rgba(255,255,255,.07); color: #94a3b8; border: 1px solid rgba(255,255,255,.1); }
    .btn-lc.sm     { padding: 5px 11px; font-size: 11px; }

    /* ── Attendance table ── */
    .att-table { width: 100%; border-collapse: collapse; }
    .att-table th { padding: 8px 12px; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1px solid rgba(255,255,255,.06); text-align: left; }
    .att-table td { padding: 10px 12px; font-size: 12px; color: #cbd5e1; border-bottom: 1px solid rgba(255,255,255,.04); }
    .att-table tbody tr:last-child td { border-bottom: none; }

    /* ── Video grid ── */
    #videoGrid {
      display: none; position: fixed;
      top: 58px; left: 260px; right: 0; bottom: 72px;
      gap: 12px; padding: 16px; overflow: hidden;
      align-content: center;
      justify-content: center;
      transition: left .25s;
    }
    #videoGrid.in-call { display: grid; }
    body.in-call #videoGrid { left: 0; }
    /* ── Screen share layout ── */
    /* ── Screen share / PowerPoint presentation mode layout ── */
    #videoGrid.screen-mode {
      display: flex !important;
      flex-direction: row !important;
      gap: 0 !important;
      padding: 12px !important;
      overflow: hidden !important;
      align-items: stretch !important;
      justify-content: stretch !important;
    }
    
    /* Screen / PowerPoint Main Stage Container */
    .screen-stage-wrap {
      flex: 0 0 var(--ppt-split-percent, 75%);
      height: 100%;
      display: flex;
      flex-direction: column;
      position: relative;
      min-width: 260px;
      transition: flex-basis 0.05s ease-out;
    }
    
    /* Screen tile styling inside stage wrapper */
    #videoGrid.screen-mode .screen-tile {
      width: 100% !important;
      height: 100% !important;
      border-color: <?php echo $accent; ?>;
      box-shadow: 0 8px 28px rgba(0,0,0,.6);
      border-radius: 14px;
      z-index: 10;
      flex: 1;
    }
    
    /* Resizable Divider Handle between PowerPoint stage and Student tiles */
    .lc-resize-divider {
      width: 12px;
      height: 100%;
      cursor: col-resize;
      display: flex;
      align-items: center;
      justify-content: center;
      background: transparent;
      z-index: 15;
      flex-shrink: 0;
      user-select: none;
      touch-action: none;
      transition: background 0.2s;
    }
    .lc-resize-divider::after {
      content: '';
      width: 4px;
      height: 38px;
      border-radius: 99px;
      background: rgba(255,255,255,.25);
      transition: background 0.2s, height 0.2s;
    }
    .lc-resize-divider:hover::after,
    .lc-resize-divider.dragging::after {
      background: <?php echo $accent; ?>;
      height: 60px;
    }

    /* Student / Participant Videos Side Column */
    .screen-students-wrap {
      flex: 1;
      height: 100%;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 10px;
      padding-left: 6px;
      min-width: 160px;
    }
    .screen-students-wrap .video-tile:not(.screen-tile) {
      width: 100% !important;
      height: auto !important;
      aspect-ratio: 16 / 9 !important;
      flex-shrink: 0;
    }

    /* Responsive Mobile & Tablet Adjustments (< 900px) */
    @media (max-width: 900px) {
      #videoGrid.screen-mode {
        flex-direction: column !important;
      }
      .screen-stage-wrap {
        flex: 0 0 var(--ppt-split-percent, 60%) !important;
        width: 100% !important;
        min-height: 180px !important;
      }
      .lc-resize-divider {
        width: 100% !important;
        height: 12px !important;
        cursor: row-resize !important;
      }
      .lc-resize-divider::after {
        width: 38px !important;
        height: 4px !important;
      }
      .lc-resize-divider:hover::after,
      .lc-resize-divider.dragging::after {
        width: 60px !important;
        height: 4px !important;
      }
      .screen-students-wrap {
        flex-direction: row !important;
        overflow-x: auto !important;
        overflow-y: hidden !important;
        padding-left: 0 !important;
        padding-top: 6px !important;
        height: auto !important;
        flex: 1 !important;
      }
      .screen-students-wrap .video-tile:not(.screen-tile) {
        width: 160px !important;
        height: 100% !important;
        aspect-ratio: 16 / 9 !important;
      }
    }

    /* ── Video tile ── */
    .video-tile {
      position: relative;
      background: #111827;
      border-radius: 14px;
      overflow: hidden;
      min-height: 0;
      border: 2px solid transparent;
      transform: scale(1);
      opacity: 1;
      transition: width 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                  height 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                  transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                  opacity 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                  border-color .25s,
                  box-shadow .25s;
    }
    .video-tile.tile-entering {
      transform: scale(0.6);
      opacity: 0;
    }
    .video-tile.tile-leaving {
      transform: scale(0.6);
      opacity: 0;
      pointer-events: none;
    }
    .video-tile.speaking {
      border-color: <?php echo $accent; ?>;
      box-shadow: 0 0 0 3px rgba(<?php echo $accentRgb; ?>,.25);
    }
    .video-tile.screen-tile { aspect-ratio: unset; border-color: rgba(<?php echo $accentRgb; ?>,.4); }
    .video-tile video {
      position: absolute; inset: 0; width: 100%; height: 100%;
      object-fit: cover; display: block;
    }
    /* Vertical/portrait camera streams should use contain to avoid clipping */
    .video-tile video.portrait { object-fit: contain; background: #000; }
    /* Dynamic Auto Resizing & Responsive Grid Tiles for multi-participant calls */
    .video-tile.tile-lg { border-radius: 14px; }
    .video-tile.tile-md { border-radius: 10px; }
    .video-tile.tile-md .tile-avatar .av-ring { width: 44px; height: 44px; font-size: 16px; }
    .video-tile.tile-md .tile-label { font-size: 10px; padding: 2px 7px; bottom: 6px; left: 6px; }

    .video-tile.tile-sm { border-radius: 8px; }
    .video-tile.tile-sm .tile-avatar { gap: 4px; }
    .video-tile.tile-sm .tile-avatar .av-ring { width: 34px; height: 34px; font-size: 12.5px; box-shadow: 0 2px 8px rgba(<?php echo $accentRgb; ?>,.3); }
    .video-tile.tile-sm .tile-avatar .av-name { font-size: 9.5px; margin-top: 2px; max-width: 100px; }
    .video-tile.tile-sm .tile-label { font-size: 9px; padding: 2px 6px; bottom: 4px; left: 4px; }
    .video-tile.tile-sm .tile-mic-off { font-size: 8px; padding: 2px 5px; bottom: 4px; right: 4px; }
    .video-tile.tile-sm .tile-away { font-size: 8px; padding: 2px 5px; top: 4px; left: 4px; }
    .video-tile.tile-sm .tile-conn-badge { font-size: 8px; padding: 2px 5px; top: 4px; right: 4px; }

    /* ── Tile overlays ── */
    .tile-avatar {
      position: absolute; inset: 0; display: flex; flex-direction: column;
      align-items: center; justify-content: center; gap: 10px;
      background: #0d1424; z-index: 2;
    }
    .tile-avatar .av-ring {
      width: 68px; height: 68px; border-radius: 50%;
      background: linear-gradient(135deg, <?php echo $accent; ?>, <?php echo $accentDk; ?>);
      display: flex; align-items: center; justify-content: center;
      font-size: 24px; font-weight: 800; color: #fff;
      box-shadow: 0 4px 20px rgba(<?php echo $accentRgb; ?>,.4);
    }
    .tile-avatar .av-name {
      font-size: 12px; font-weight: 600; color: rgba(255,255,255,.55);
      max-width: 85%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .tile-label {
      position: absolute; bottom: 9px; left: 10px; z-index: 3;
      background: rgba(0,0,0,.65); color: #fff; padding: 3px 10px;
      border-radius: 99px; font-size: 11px; font-weight: 600;
      backdrop-filter: blur(4px); display: flex; align-items: center; gap: 5px;
    }
    .tile-label .role-dot { width: 6px; height: 6px; border-radius: 50%; background: <?php echo $accent; ?>; }
    .tile-mic-off {
      position: absolute; bottom: 9px; right: 10px; z-index: 3;
      background: rgba(239,68,68,.9); color: #fff; padding: 3px 9px;
      border-radius: 99px; font-size: 10px; font-weight: 700;
      display: none; align-items: center; gap: 4px; backdrop-filter: blur(4px);
    }
    .tile-mic-off.show { display: flex; }
    .tile-away {
      position: absolute; top: 8px; left: 10px; z-index: 3;
      padding: 3px 9px; border-radius: 99px; font-size: 10px; font-weight: 700;
      color: #fff; display: none; align-items: center; gap: 4px;
      backdrop-filter: blur(4px); border: 1px solid rgba(255,255,255,.15);
    }
    .tile-away.show { display: flex; }
    .tile-away.focused { background: rgba(16,185,129,.85); }
    .tile-away.away    { background: rgba(239,68,68,.85); animation: blink 1.4s infinite; }
    .tile-away.partial { background: rgba(245,158,11,.85); }
    .tile-conn-badge {
      position: absolute; top: 8px; right: 10px; z-index: 3;
      display: none; align-items: center; gap: 4px; padding: 3px 8px;
      border-radius: 99px; font-size: 10px; font-weight: 700; color: #fff;
      backdrop-filter: blur(6px); border: 1px solid rgba(255,255,255,.15);
    }
    .tile-conn-badge.show { display: flex; }
    .tile-conn-badge.good    { background: rgba(16,185,129,.85); }
    .tile-conn-badge.fair    { background: rgba(245,158,11,.85); }
    .tile-conn-badge.poor    { background: rgba(239,68,68,.85); }
    .tile-conn-badge.offline { background: rgba(71,85,105,.9); }
    .screen-badge {
      position: absolute; top: 10px; left: 12px; z-index: 3;
      background: rgba(<?php echo $accentRgb; ?>,.9); color: #fff;
      padding: 5px 12px; border-radius: 99px; font-size: 11px; font-weight: 700;
      display: flex; align-items: center; gap: 6px;
      backdrop-filter: blur(4px); box-shadow: 0 2px 8px rgba(0,0,0,.3);
    }
    .screen-stop-btn {
      position: absolute; top: 10px; right: 12px; z-index: 3;
      background: rgba(239,68,68,.9); color: #fff;
      padding: 5px 12px; border-radius: 99px; font-size: 11px; font-weight: 700;
      border: none; cursor: pointer; display: flex; align-items: center; gap: 5px;
      backdrop-filter: blur(4px); box-shadow: 0 2px 8px rgba(0,0,0,.3);
      transition: background .15s;
    }
    .screen-stop-btn:hover { background: rgba(239,68,68,1); }
    /* Fullscreen button on screen tile */
    .screen-fs-btn {
      position: absolute; bottom: 10px; right: 12px; z-index: 3;
      background: rgba(0,0,0,.65); color: #fff;
      padding: 5px 11px; border-radius: 99px; font-size: 11px; font-weight: 700;
      border: none; cursor: pointer; display: flex; align-items: center; gap: 5px;
      backdrop-filter: blur(4px); box-shadow: 0 2px 8px rgba(0,0,0,.3);
      transition: background .15s, opacity .15s;
    }
    .screen-fs-btn:hover { background: rgba(0,0,0,.88); }
    /* Double-click hint overlay */
    #tile_screen:hover .screen-fs-btn { opacity: 1; }
    /* Fullscreen: hide controls inside fullscreen element */
    #tile_screen:fullscreen .screen-badge,
    #tile_screen:fullscreen .screen-stop-btn,
    #tile_screen:fullscreen .tile-label { display: none !important; }
    #tile_screen:-webkit-full-screen .screen-badge,
    #tile_screen:-webkit-full-screen .screen-stop-btn,
    #tile_screen:-webkit-full-screen .tile-label { display: none !important; }
    /* Fullscreen exit button shown only in fullscreen */
    .screen-fs-exit {
      display: none;
      position: fixed; bottom: 24px; right: 24px; z-index: 999999;
      background: rgba(239,68,68,.92); color: #fff;
      padding: 10px 22px; border-radius: 99px; font-size: 13px; font-weight: 700;
      border: none; cursor: pointer; gap: 8px; align-items: center;
      box-shadow: 0 4px 20px rgba(0,0,0,.5); backdrop-filter: blur(4px);
    }
    .screen-fs-exit.show { display: flex; }

    /* ── Floating Screen Share Badge Icon ── */
    .sharing-floating-badge {
      position: fixed; top: 70px; right: 20px; z-index: 999999;
      background: rgba(15, 23, 42, 0.94); border: 1.5px solid rgba(56, 189, 248, 0.45);
      color: #fff; padding: 7px 16px; border-radius: 99px; font-size: 12px; font-weight: 700;
      display: flex; align-items: center; gap: 8px; cursor: pointer;
      backdrop-filter: blur(14px); box-shadow: 0 8px 24px rgba(0, 0, 0, 0.6);
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); animation: slideInRight 0.3s ease-out;
    }
    @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
    .sharing-floating-badge:hover {
      background: rgba(239, 68, 68, 0.95); border-color: #ef4444;
      transform: translateY(-2px); box-shadow: 0 8px 28px rgba(239, 68, 68, 0.5);
    }
    .sharing-floating-badge .pulse-dot {
      width: 8px; height: 8px; border-radius: 50%; background: #10b981;
      box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); animation: pulse-green 1.5s infinite;
    }
    @keyframes pulse-green {
      0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
      70% { transform: scale(1); box-shadow: 0 0 0 7px rgba(16, 185, 129, 0); }
      100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }
    .sharing-floating-badge .stop-btn-tag {
      background: rgba(239, 68, 68, 0.28); color: #fca5a5;
      padding: 3px 9px; border-radius: 99px; font-size: 10px; margin-left: 4px;
      display: flex; align-items: center; gap: 4px; border: 1px solid rgba(239, 68, 68, 0.4);
    }
    .sharing-floating-badge:hover .stop-btn-tag {
      background: rgba(255, 255, 255, 0.25); color: #fff; border-color: transparent;
    }

    /* ── Connecting overlay ── */
    #connectingOverlay {
      position: absolute; inset: 0; background: rgba(10,15,30,.92);
      display: none; flex-direction: column; align-items: center; justify-content: center;
      gap: 16px; z-index: 50; backdrop-filter: blur(6px);
    }
    #connectingOverlay.show { display: flex; }
    .conn-spinner {
      width: 48px; height: 48px; border-radius: 50%;
      border: 3px solid rgba(255,255,255,.1);
      border-top-color: <?php echo $accent; ?>;
      animation: spin .8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    #connectingOverlay p { color: #94a3b8; font-size: 14px; font-weight: 600; margin: 0; }

    /* ── Controls bar ── */
    #lcControls {
      display: none; position: fixed; bottom: 0; left: 0; right: 0;
      height: 56px;
      background: rgba(10,15,30,.98); border-top: 1px solid rgba(255,255,255,.08);
      backdrop-filter: blur(14px); z-index: 99999 !important;
    }
    #lcControls.show { display: flex; align-items: center; justify-content: center; }
    /* Offset center to account for sidebar width */
    #lcControls::before {
      content: ''; display: block; width: 260px; flex-shrink: 0;
      transition: width .25s;
    }
    body.in-call #lcControls::before { width: 0; }
    .ctrl-btns-wrap {
      display: flex; flex-direction: row; align-items: center;
      gap: 10px; flex-wrap: nowrap; flex: 1; justify-content: center;
      padding: 0 8px;
    }
    .ctrl-btn {
      display: inline-flex; align-items: center; justify-content: center;
      width: 38px; height: 38px; min-width: 38px; border-radius: 50%;
      background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12);
      cursor: pointer; color: #cbd5e1; font-size: 14.5px;
      transition: all .18s cubic-bezier(0.4, 0, 0.2, 1);
      flex-shrink: 0; user-select: none; padding: 0;
    }
    .ctrl-btn i { font-size: 14.5px; line-height: 1; }
    .ctrl-btn:hover {
      background: rgba(255,255,255,.16); transform: scale(1.1); color: #fff;
      box-shadow: 0 4px 14px rgba(0,0,0,.4);
    }
    .ctrl-btn:active { transform: scale(0.95); }
    .ctrl-btn.on  {
      background: rgba(<?php echo $accentRgb; ?>,.2); border-color: <?php echo $accent; ?>; color: <?php echo $accent; ?>;
      box-shadow: 0 0 12px rgba(<?php echo $accentRgb; ?>,.25);
    }
    .ctrl-btn.on:hover { background: rgba(<?php echo $accentRgb; ?>,.32); color: #fff; }
    .ctrl-btn.end {
      background: rgba(239,68,68,.18); border-color: rgba(239,68,68,.5); color: #ef4444;
      width: 44px; border-radius: 20px;
    }
    .ctrl-btn.end:hover {
      background: #ef4444; border-color: #ef4444; color: #fff;
      box-shadow: 0 4px 16px rgba(239,68,68,.5);
    }

    /* Hand Raise Active Button Style */
    .ctrl-btn.hand-on {
      background: rgba(245,158,11,.22) !important;
      border-color: #f59e0b !important;
      color: #f59e0b !important;
      box-shadow: 0 0 14px rgba(245,158,11,.4) !important;
    }

    /* Responsive button styles */
    @media (max-width: 600px) {
      #lcControls { height: 50px; }
      .ctrl-btns-wrap { gap: 7px; padding: 0 4px; }
      .ctrl-btn { width: 34px; height: 34px; min-width: 34px; font-size: 13px; }
      .ctrl-btn i { font-size: 13px; }
      .ctrl-btn.end { width: 38px; }
    }

    /* ── Participant count ── */
    .part-count { display: flex; align-items: center; gap: 6px; color: #94a3b8; font-size: 12px; font-weight: 600; }

    /* ── Connectivity indicator (student topbar) ── */
    #connIndicator {
      display: none; align-items: center; gap: 6px; padding: 4px 10px;
      border-radius: 8px; font-size: 11px; font-weight: 700;
    }
    #connIndicator.good    { background: rgba(16,185,129,.12); color: #10b981; }
    #connIndicator.fair    { background: rgba(245,158,11,.12); color: #f59e0b; }
    #connIndicator.poor    { background: rgba(239,68,68,.12); color: #ef4444; }
    #connIndicator.offline { background: rgba(239,68,68,.2); color: #ef4444; animation: blink 1s infinite; }
    .conn-bars { display: flex; align-items: flex-end; gap: 2px; height: 14px; }
    .conn-bar  { width: 3px; border-radius: 1px; background: currentColor; opacity: .25; }
    .conn-bar.lit { opacity: 1; }

    /* ── Teacher connectivity panel ── */
    #connPanel {
      display: none; align-items: center; gap: 6px;
      background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
      border-radius: 9px; padding: 4px 10px; cursor: pointer; transition: background .15s;
    }
    #connPanel:hover { background: rgba(255,255,255,.1); }
    #connPanel .cp-label { font-size: 11px; font-weight: 700; color: rgba(255,255,255,.65); }
    .cp-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .cp-dot.good    { background: #10b981; }
    .cp-dot.fair    { background: #f59e0b; }
    .cp-dot.poor    { background: #ef4444; }
    .cp-dot.offline { background: #475569; }

    /* ── Hand Raise Active Button Style ── */
    .ctrl-btn.hand-on {
      background: rgba(245,158,11,.18) !important;
      border-color: #f59e0b !important;
      color: #f59e0b !important;
      box-shadow: 0 0 12px rgba(245,158,11,.3);
    }

    /* ── Smooth Floating Reactions Stage & Animations ── */
    #reactionsStage {
      position: fixed; inset: 0; pointer-events: none; z-index: 999999; overflow: hidden;
    }
    .floating-reaction {
      position: absolute; bottom: 68px; display: inline-flex; align-items: center; gap: 6px;
      background: rgba(15, 23, 42, 0.88); border: 1px solid rgba(255, 255, 255, 0.16);
      backdrop-filter: blur(12px); padding: 4px 10px; border-radius: 99px; color: #fff;
      font-size: 11px; font-weight: 700; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
      animation: floatUpSmooth 3.2s cubic-bezier(0.22, 1, 0.36, 1) forwards; pointer-events: none;
    }
    .floating-reaction .reaction-emoji { font-size: 20px; line-height: 1; }
    @keyframes floatUpSmooth {
      0% { opacity: 0; transform: translateY(20px) scale(0.5) rotate(0deg); }
      10% { opacity: 1; transform: translateY(-16px) scale(1.15) rotate(-3deg); }
      22% { transform: translateY(-60px) scale(1) rotate(3deg); }
      55% { transform: translateY(-160px) scale(0.98) rotate(-2deg); }
      80% { opacity: 0.9; }
      100% { opacity: 0; transform: translateY(-280px) scale(0.8) rotate(2deg); }
    }

    /* ── Quick Reactions Popover Bar ── */
    .reactions-bar-popover {
      position: fixed; bottom: 68px; left: 50%;
      transform: translateX(-50%) translateY(8px) scale(0.92);
      background: rgba(15, 23, 42, 0.95); border: 1.2px solid rgba(255, 255, 255, 0.15);
      backdrop-filter: blur(20px); border-radius: 99px; padding: 4px 10px;
      display: none; align-items: center; gap: 4px; box-shadow: 0 16px 36px rgba(0, 0, 0, 0.65);
      z-index: 9999999; opacity: 0; transition: opacity 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .reactions-bar-popover.show {
      display: flex; opacity: 1; transform: translateX(-50%) translateY(0) scale(1);
    }
    .react-btn {
      background: transparent; border: none; font-size: 19px; padding: 5px 6px;
      border-radius: 50%; cursor: pointer; transition: transform 0.16s ease, background 0.16s;
      display: flex; align-items: center; justify-content: center; user-select: none; line-height: 1;
    }
    .react-btn:hover { transform: scale(1.35) translateY(-2px); background: rgba(255, 255, 255, 0.14); }
    .react-btn:active { transform: scale(1.05); }

    /* ── Hand Raise Tile Badge ── */
    .video-tile .tile-hand-badge {
      position: absolute; top: 8px; left: 10px; z-index: 5;
      background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff;
      padding: 3px 9px; border-radius: 99px; font-size: 10.5px; font-weight: 800;
      display: none; align-items: center; gap: 4px;
      box-shadow: 0 2px 10px rgba(245, 158, 11, 0.6); animation: handPulse 1.6s infinite;
    }
    .video-tile.hand-raised .tile-hand-badge { display: flex; }
    @keyframes handPulse {
      0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7); }
      50% { transform: scale(1.05); box-shadow: 0 0 0 7px rgba(245, 158, 11, 0); }
    }

    /* ── Modal ── */
    .lc-modal-overlay {
      position: fixed; inset: 0; background: rgba(0,0,0,.72);
      display: flex; align-items: center; justify-content: center;
      z-index: 300; opacity: 0; pointer-events: none;
      transition: opacity .2s; backdrop-filter: blur(5px);
    }
    .lc-modal-overlay.open { opacity: 1; pointer-events: all; }
    .lc-modal {
      background: #111827; border: 1px solid rgba(255,255,255,.1);
      border-radius: 18px; width: 100%; max-width: 460px; margin: 16px;
      box-shadow: 0 24px 64px rgba(0,0,0,.6);
      transform: translateY(24px); transition: transform .2s; overflow: hidden;
    }
    .lc-modal-overlay.open .lc-modal { transform: translateY(0); }
    .lc-modal-head {
      padding: 18px 22px; border-bottom: 1px solid rgba(255,255,255,.06);
      display: flex; align-items: center; justify-content: space-between;
    }
    .lc-modal-head h4 { font-size: 15px; font-weight: 700; color: #f1f5f9; margin: 0; }
    .lc-modal-x {
      width: 28px; height: 28px; border-radius: 7px;
      background: rgba(255,255,255,.08); border: none; cursor: pointer;
      color: #94a3b8; font-size: 16px; display: flex; align-items: center; justify-content: center;
    }
    .lc-modal-x:hover { background: rgba(255,255,255,.14); color: #fff; }
    .lc-modal-body { padding: 20px 22px; }
    .lc-modal-foot {
      padding: 14px 22px; border-top: 1px solid rgba(255,255,255,.06);
      display: flex; justify-content: flex-end; gap: 10px;
    }
    .lc-field { margin-bottom: 14px; }
    .lc-field label { display: block; font-size: 12px; font-weight: 600; color: #94a3b8; margin-bottom: 6px; }
    .lc-field input {
      width: 100%; padding: 10px 13px;
      background: rgba(255,255,255,.06); border: 1.5px solid rgba(255,255,255,.1);
      border-radius: 9px; font-size: 13px; color: #f1f5f9;
      font-family: 'Inter', sans-serif; outline: none; transition: border-color .2s;
    }
    .lc-field input:focus { border-color: <?php echo $accent; ?>; }

    /* ── Toast ── */
    #lcToast {
      position: fixed; bottom: 96px; left: 50%;
      transform: translateX(-50%) translateY(16px);
      padding: 10px 22px; border-radius: 10px; font-size: 13px; font-weight: 600;
      color: #fff; z-index: 9999; opacity: 0; pointer-events: none;
      transition: opacity .3s, transform .3s;
      /* Fix: allow wrapping on narrow phones instead of overflowing */
      white-space: normal; text-align: center;
      max-width: calc(100vw - 32px);
      box-shadow: 0 4px 24px rgba(0,0,0,.5);
    }
    #lcToast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
    #lcToast.green { background: rgba(16,185,129,.95); }
    #lcToast.red   { background: rgba(239,68,68,.95); }
    #lcToast.blue  { background: rgba(23,146,187,.95); }

    /* ── Safe area support for notched phones (iPhone X+, Android notch) ── */
    #lcControls {
      /* Extend bar height to cover home indicator on notched phones */
      padding-bottom: env(safe-area-inset-bottom, 0px);
      height: calc(56px + env(safe-area-inset-bottom, 0px));
    }
    #videoGrid {
      /* Video grid must not overlap the compact notch-adjusted controls bar */
      bottom: calc(56px + env(safe-area-inset-bottom, 0px));
    }

    /* ── Video tile: disable double-tap zoom on iOS (causes accidental zooms during call) ── */
    .video-tile { touch-action: manipulation; }

    /* ── Modal: cap height on small screens so it doesn't exceed viewport ── */
    .lc-modal {
      max-height: calc(100vh - 32px);
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    .lc-modal-body {
      flex: 1;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
    }

    /* ── Responsive: Tablet / small laptop ── */
    @media (max-width: 900px) {
      .lc-topbar, .lc-main { left: 0; }
      .lc-topbar { padding: 0 12px; }
      #lcControls::before { width: 0; }
    }

    /* ── Responsive: Phone (≤600px) ── */
    @media (max-width: 600px) {

      #dashPanel { padding: 12px; gap: 12px; }
      .lc-card { border-radius: 12px; }
      .lc-card-hdr { padding: 10px 14px; }
      .lc-card-hdr h3 { font-size: 13px; }
      .lc-card-body { padding: 12px 14px; }
      .sess-card { padding: 10px 12px; gap: 8px; flex-direction: column; align-items: flex-start; }
      .sess-actions { width: 100%; display: flex; gap: 6px; margin-top: 4px; }
      .btn-lc { padding: 5px 10px; font-size: 11px; height: 28px; }

      /* Fix: reduce gap and padding on narrow phones */
      #videoGrid {
        gap: 6px; padding: 6px;
      }

      /* Scale down initials ring and hide duplicate name inside avatar */
      .tile-avatar .av-ring { width: 44px; height: 44px; font-size: 16px; box-shadow: 0 2px 10px rgba(<?php echo $accentRgb; ?>,.3); }
      .tile-avatar .av-name { display: none !important; }

      /* Scale down tile overlays and badges */
      .tile-label { font-size: 9px; padding: 2px 7px; bottom: 6px; left: 6px; }
      .tile-mic-off { font-size: 8.5px; padding: 2px 7px; bottom: 6px; right: 6px; }
      .tile-away { font-size: 8.5px; padding: 2px 7px; top: 6px; left: 6px; }
      .tile-conn-badge { font-size: 8.5px; padding: 2px 6px; top: 6px; right: 6px; }

      /* Controls bar: icon-only on small phones, bigger touch targets */
      .ctrl-btn span { display: none; }
      .ctrl-btn { padding: 10px 14px; font-size: 14px; gap: 6px; min-width: 44px; min-height: 44px; }
      .ctrl-btn i { font-size: 17px; }
      #lcControls { gap: 6px; padding-left: 6px; padding-right: 6px; }

      /* Topbar: trim class name and hide connectivity text label */
      .lc-class-name { max-width: 120px; font-size: 12px; }
      .cp-label { display: none; } /* Hide "Connectivity" text, keep dots */
      #connIndicator span { display: none; }

      /* Screen share badge labels */
      .screen-badge { font-size: 10px; padding: 4px 9px; }
      .screen-stop-btn { font-size: 10px; padding: 4px 9px; }

      /* Toast position: raised above taller controls bar on phones */
      #lcToast { bottom: calc(88px + env(safe-area-inset-bottom, 0px)); font-size: 12px; }

      /* Session cards on dashboard: stack actions vertically */
      .sess-card { flex-wrap: wrap; gap: 10px; }
      .sess-actions { width: 100%; justify-content: flex-end; }

      /* Modal: near-fullscreen on phones */
      .lc-modal { border-radius: 12px; margin: 10px; max-height: calc(100vh - 20px); }
      .lc-modal-head { padding: 14px 16px; }
      .lc-modal-body { padding: 14px 16px; }
      .lc-modal-foot { padding: 10px 16px; }
    }

    /* ── Responsive: Very small phones (≤380px) ── */
    @media (max-width: 380px) {
      .lc-class-name { max-width: 90px; }
      .ctrl-btn { padding: 8px 10px; min-width: 40px; min-height: 40px; }
      /* Hide part-count on tiny screens to save topbar space */
      .part-count span { display: none; }
    }
    <?php if ($isTeacher): ?>
    .t-sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:linear-gradient(180deg,#0f2027 0%,#203a43 55%,#2c5364 100%);display:flex;flex-direction:column;z-index:200;transition:transform .3s cubic-bezier(.4,0,.2,1);transform:translateX(-260px);}
    .t-sidebar.open{transform:translateX(0);}
    @media(min-width: 901px) { .t-sidebar{transform:translateX(0);} }
    .sb-brand{padding:22px 20px 16px;border-bottom:1px solid rgba(255,255,255,.07);}
    .sb-logo{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#10b981,#059669);display:inline-flex;align-items:center;justify-content:center;margin-bottom:8px;box-shadow:0 4px 14px rgba(16,185,129,.4);}
    .sb-logo i{color:#fff;font-size:17px;}
    .sb-brand h2{color:#fff;font-size:18px;font-weight:800;margin:0;}
    .sb-brand h2 span{color:#10b981;}
    .sb-brand p{color:rgba(255,255,255,.3);font-size:10px;margin:2px 0 0;}
    .sb-nav{flex:1;padding:10px 0;overflow-y:auto;}
    .sb-nav-sec{padding:10px 20px 4px;font-size:9px;font-weight:700;color:rgba(255,255,255,.22);letter-spacing:1.5px;text-transform:uppercase;}
    .sb-nav ul{list-style:none;margin:0;padding:0;}
    .sb-nav li a{display:flex;align-items:center;gap:10px;padding:10px 20px;color:rgba(255,255,255,.55);text-decoration:none;font-size:13px;font-weight:500;transition:all .18s;border-left:3px solid transparent;}
    .sb-nav li a:hover{background:rgba(255,255,255,.06);color:#fff;}
    .sb-nav li.active a{background:rgba(16,185,129,.15);color:#fff;border-left-color:#10b981;}
    .sb-nav li a i{width:16px;text-align:center;font-size:13px;}
    .sb-footer{padding:14px 20px;border-top:1px solid rgba(255,255,255,.07);}
    .sb-user{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
    .sb-av{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;flex-shrink:0;}
    .sb-meta strong{display:block;color:#fff;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;}
    .sb-meta span{color:rgba(255,255,255,.38);font-size:10px;}
    .sb-out{display:flex;align-items:center;justify-content:center;gap:6px;padding:8px;width:100%;background:rgba(255,255,255,.06);color:rgba(255,255,255,.55);border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:12px;font-weight:500;text-decoration:none;transition:all .18s;}
    .sb-out:hover{background:rgba(255,255,255,.12);color:#fff;}
    .sb-submenu{list-style:none;padding:0;margin:0;background:rgba(0,0,0,0.15);border-left:3px solid rgba(16,185,129,0.3);}
    .sb-submenu li a{padding:8px 20px 8px 40px !important;font-size:12px !important;color:rgba(255, 255, 255, 0.6) !important;border-left:none !important;}
    .sb-submenu li a:hover{color:#fff !important;background:rgba(255,255,255,0.05) !important;}
    .sb-submenu li.active a{color:#fff !important;background:rgba(16,185,129,0.15) !important;font-weight:700;}
    @media(min-width: 901px) { .cl-main{margin-left:260px !important;} }
    <?php endif; ?>
  </style>
</head>
<body>

<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<div id="lcToast"></div>
<!-- Fullscreen exit button (shown in fullscreen mode) -->
<button class="screen-fs-exit" id="screenFsExit" onclick="exitScreenFullscreen()">
  <i class="fa fa-compress"></i> Exit Fullscreen
</button>

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
          <li><a href="class_view.php?id=<?php echo $class_id;?>&tab=materials" id="subMaterials"><i class="fa fa-folder-open"></i> Materials</a></li>
          <li><a href="class_view.php?id=<?php echo $class_id;?>&tab=classwork" id="subClasswork"><i class="fa fa-tasks"></i> Classwork</a></li>
          <li class="active"><a href="live_class.php?id=<?php echo $class_id;?>" id="subLiveClass"><i class="fa fa-video-camera"></i> Live Class</a></li>
          <li><a href="class_view.php?id=<?php echo $class_id;?>&tab=performance" id="subPerformance"><i class="fa fa-line-chart"></i> Performance &amp; Analytics</a></li>
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
      <li class="nav-item"><a href="class_view.php?id=<?php echo $class_id;?>"><i class="fa fa-folder-open"></i> Materials</a></li>
      <li class="nav-item"><a href="class_view.php?id=<?php echo $class_id;?>&tab=classwork"><i class="fa fa-tasks"></i> Classwork</a></li>
      <li class="nav-item active"><a href="live_class.php?id=<?php echo $class_id;?>"><i class="fa fa-video-camera"></i> Live Class</a></li>
      <?php if($isTeacher): ?>
      <li class="nav-item"><a href="class_record_detail.php?id=<?php echo $class_id;?>"><i class="fa fa-users"></i> Class Record</a></li>
      <?php endif; ?>
    </ul>
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

<!-- Topbar -->
<div class="lc-topbar">
  <div class="lc-topbar-left">
    <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
    <span class="lc-class-name"><?php echo htmlspecialchars($class['class_name']); ?></span>
    <span class="lc-live-badge" id="liveBadge" style="display:none;">
      <span class="lc-live-dot"></span> LIVE
    </span>
  </div>
  <div class="lc-topbar-right">
    <div class="part-count" id="partWrap" style="display:none;">
      <i class="fa fa-users"></i> <span id="partCount">1</span>
    </div>
    <?php if(!$isTeacher): ?>
    <div id="connIndicator">
      <div class="conn-bars">
        <div class="conn-bar" id="cb1" style="height:4px;"></div>
        <div class="conn-bar" id="cb2" style="height:7px;"></div>
        <div class="conn-bar" id="cb3" style="height:10px;"></div>
        <div class="conn-bar" id="cb4" style="height:14px;"></div>
      </div>
      <span id="connLabel">--</span>
    </div>
    <?php endif; ?>
    <?php if($isTeacher): ?>
    <div id="connPanel" onclick="toggleConnOverlay()" title="Student connectivity">
      <div style="display:flex;gap:4px;align-items:center;" id="cpDots"></div>
      <span class="cp-label">Connectivity</span>
    </div>
    <button class="btn-lc accent sm" onclick="openAttendanceModal()">
      <i class="fa fa-list"></i> Attendance
    </button>
    <?php endif; ?>
  </div>
</div>

<!-- Main area -->
<div class="lc-main" id="lcMain">

  <!-- Dashboard panel -->
  <div id="dashPanel">

    <!-- Sessions card -->
    <div class="lc-card">
      <div class="lc-card-hdr">
        <h3><i class="fa fa-video-camera"></i> Live Sessions</h3>
        <?php if($isTeacher): ?>
        <button class="btn-lc accent sm" onclick="openScheduleModal()">
          <i class="fa fa-plus"></i> New Session
        </button>
        <?php endif; ?>
      </div>
      <div class="lc-card-body" id="sessListWrap">
        <?php if(empty($sessions)): ?>
        <div class="empty-state">
          <i class="fa fa-video-camera"></i>
          <?php echo $isTeacher ? 'No sessions yet. Schedule your first live class.' : 'No live sessions scheduled yet.'; ?>
        </div>
        <?php else: ?>
        <?php foreach($sessions as $s):
          $statusLabel = ['scheduled'=>'Scheduled','live'=>'LIVE','ended'=>'Ended'][$s['status']];
          $attQ = $conn->query("SELECT COUNT(*) AS c FROM live_attendance WHERE session_id={$s['id']}");
          $attCount = $attQ->fetch_assoc()['c'];
        ?>
        <div class="sess-card">
          <div class="sess-dot <?php echo $s['status']; ?>"></div>
          <div class="sess-info">
            <div class="sess-title"><?php echo htmlspecialchars($s['title'] ?: 'Live Class'); ?></div>
            <div class="sess-meta">
              <span><?php echo $statusLabel; ?></span>
              <?php if($s['scheduled_at']): ?>
              <span><i class="fa fa-calendar"></i><?php echo date('M d, Y g:i A', strtotime($s['scheduled_at'])); ?></span>
              <?php endif; ?>
              <?php if($s['started_at']): ?>
              <span><i class="fa fa-play"></i>Started <?php echo date('g:i A', strtotime($s['started_at'])); ?></span>
              <?php endif; ?>
              <span><i class="fa fa-users"></i><?php echo $attCount; ?> attended</span>
            </div>
          </div>
          <div class="sess-actions">
            <?php if($isTeacher): ?>
              <?php if($s['status']==='scheduled'): ?>
              <button class="btn-lc accent sm" onclick="startSession(<?php echo $s['id']; ?>)"><i class="fa fa-play"></i> Start</button>
              <button class="btn-lc ghost sm" onclick="deleteSession(<?php echo $s['id']; ?>)"><i class="fa fa-trash"></i></button>
              <?php elseif($s['status']==='live'): ?>
              <button class="btn-lc accent sm" onclick="joinCall(<?php echo $s['id']; ?>,'<?php echo $s['room_id']; ?>')"><i class="fa fa-video-camera"></i> Join</button>
              <button class="btn-lc red sm" onclick="endSession(<?php echo $s['id']; ?>)"><i class="fa fa-stop"></i> End</button>
              <?php endif; ?>
              <?php if($s['status']==='ended' || $s['status']==='live'): ?>
              <button class="btn-lc blue sm" onclick="viewAttendance(<?php echo $s['id']; ?>,'<?php echo htmlspecialchars(addslashes($s['title']?:'Live Class')); ?>')"><i class="fa fa-list"></i> Attendance</button>
              <?php endif; ?>
            <?php else: ?>
              <?php if($s['status']==='live'): ?>
              <button class="btn-lc accent sm" onclick="joinAsStudent(<?php echo $s['id']; ?>,'<?php echo $s['room_id']; ?>')"><i class="fa fa-sign-in"></i> Join</button>
              <?php elseif($s['status']==='scheduled'): ?>
              <span style="font-size:11px;color:#f59e0b;font-weight:600;"><i class="fa fa-clock-o"></i> Upcoming</span>
              <?php else: ?>
              <span style="font-size:11px;color:#475569;font-weight:600;"><i class="fa fa-check"></i> Ended</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /#dashPanel -->

  <!-- Video grid -->
  <div id="videoGrid">
    <!-- Connecting overlay -->
    <div id="connectingOverlay">
      <div class="conn-spinner"></div>
      <p>Connecting to session...</p>
    </div>
  </div><!-- /#videoGrid -->

  <!-- Floating Reactions Stage -->
  <div id="reactionsStage"></div>

  <!-- Quick Reactions Popover Bar -->
  <div id="reactionsBarPopover" class="reactions-bar-popover">
    <button class="react-btn" onclick="sendReaction('💖')" title="Heart">💖</button>
    <button class="react-btn" onclick="sendReaction('👍')" title="Thumbs Up">👍</button>
    <button class="react-btn" onclick="sendReaction('👏')" title="Clap">👏</button>
    <button class="react-btn" onclick="sendReaction('🎉')" title="Party">🎉</button>
    <button class="react-btn" onclick="sendReaction('🤔')" title="Thinking">🤔</button>
    <button class="react-btn" onclick="sendReaction('😂')" title="Joy">😂</button>
    <button class="react-btn" onclick="sendReaction('😮')" title="Surprise">😮</button>
    <button class="react-btn" onclick="sendReaction('🔥')" title="Fire">🔥</button>
  </div>

  <!-- Controls bar -->
  <div id="lcControls">
    <div class="ctrl-btns-wrap">
      <button class="ctrl-btn on" id="btnMic" onclick="toggleMic()" title="Microphone (M)" aria-label="Microphone">
        <i class="fa fa-microphone" id="micIcon"></i>
      </button>
      <button class="ctrl-btn on" id="btnCam" onclick="toggleCam()" title="Camera (C)" aria-label="Camera">
        <i class="fa fa-video-camera" id="camIcon"></i>
      </button>
      <button class="ctrl-btn" id="btnReactions" onclick="toggleReactionsPopover(event)" title="Reactions" aria-label="Reactions">
        <i class="fa fa-smile-o" style="color:#fbbf24;font-size:16px;"></i>
      </button>
      <button class="ctrl-btn" id="btnRaiseHand" onclick="toggleRaiseHand()" title="Raise / Lower Hand" aria-label="Raise Hand">
        <i class="fa fa-hand-paper-o" id="raiseHandIcon" style="color:#f59e0b;font-size:15px;"></i>
      </button>
      <button class="ctrl-btn" id="btnPpt" onclick="openPptModal()" title="PowerPoint &amp; Presentation" aria-label="PowerPoint">
        <i class="fa fa-file-powerpoint-o" id="pptIcon" style="color:#f97316;font-size:15px;"></i>
      </button>
      <button class="ctrl-btn end" id="btnLeave" onclick="leaveCall()" title="<?php echo $isTeacher ? 'End Session' : 'Leave Call'; ?>" aria-label="<?php echo $isTeacher ? 'End Session' : 'Leave Call'; ?>">
        <i class="fa fa-phone" style="font-size:15px;"></i>
      </button>
    </div>
  </div>

</div><!-- /.lc-main -->

<!-- ── PowerPoint / Presentation Share Modal ── -->
<div class="lc-modal-overlay" id="pptModal">
  <div class="lc-modal" style="max-width:620px;">
    <div class="lc-modal-head" style="background:linear-gradient(135deg,#0c1a2e,#0f4c75);">
      <h4><i class="fa fa-file-powerpoint-o" style="color:#f97316;margin-right:7px;"></i> Share PowerPoint &amp; Presentation</h4>
      <button class="lc-modal-x" onclick="closeModal('pptModal')">&times;</button>
    </div>
    <div class="lc-modal-body" style="padding:20px;background:#0f172a;color:#f8fafc;">
      
      <!-- Option 1: Share PowerPoint Window -->
      <div style="background:#1e293b;border:1px solid #334155;border-radius:12px;padding:14px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <div style="display:flex;align-items:center;gap:12px;">
          <div style="width:40px;height:40px;border-radius:10px;background:rgba(249,115,22,.15);color:#f97316;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;">
            <i class="fa fa-desktop"></i>
          </div>
          <div>
            <div style="font-size:13px;font-weight:700;color:#f8fafc;">Present PowerPoint Application Window</div>
            <div style="font-size:11px;color:#94a3b8;">Present your open PowerPoint software window via Screen Share</div>
          </div>
        </div>
        <button class="btn-lc accent sm" onclick="closeModal('pptModal'); launchPowerPointScreenShare();" style="background:linear-gradient(135deg,#f97316,#ea580c);border:none;">
          <i class="fa fa-play"></i> Present Window
        </button>
      </div>

      <!-- Option 2: Choose Class Materials -->
      <div style="margin-bottom:16px;">
        <label style="font-size:12px;font-weight:700;color:#cbd5e1;margin-bottom:8px;display:block;">
          <i class="fa fa-folder-open" style="color:#38bdf8;"></i> Select from Class Modules / Presentation Materials:
        </label>
        <div id="pptMaterialsList" style="max-height:180px;overflow-y:auto;background:#1e293b;border:1px solid #334155;border-radius:10px;padding:8px;">
          <!-- Populated dynamically -->
        </div>
      </div>

      <!-- Option 3: Pick / Upload Local Presentation File -->
      <div>
        <label style="font-size:12px;font-weight:700;color:#cbd5e1;margin-bottom:8px;display:block;">
          <i class="fa fa-upload" style="color:#10b981;"></i> Or Open Local File (.pptx, .ppt, .pdf):
        </label>
        <div onclick="document.getElementById('pptFileInput').click()" style="border:2px dashed #475569;border-radius:10px;padding:16px;text-align:center;cursor:pointer;background:#1e293b;transition:border-color .2s;">
          <i class="fa fa-cloud-upload" style="font-size:24px;color:#38bdf8;margin-bottom:4px;display:block;"></i>
          <span style="font-size:12px;color:#cbd5e1;font-weight:600;">Click to select PowerPoint (.pptx) or PDF file</span>
          <input type="file" id="pptFileInput" accept=".pptx,.ppt,.pdf" style="display:none;" onchange="handleLocalPptSelect(this)">
        </div>
      </div>

    </div>
    <div class="lc-modal-foot" style="background:#0f172a;border-top:1px solid #334155;">
      <button class="btn-lc ghost" onclick="closeModal('pptModal')">Cancel</button>
    </div>
  </div>
</div>

<!-- ── Modals ── -->
<?php if($isTeacher): ?>

<!-- Connectivity overlay -->
<div class="lc-modal-overlay" id="connOverlay">
  <div class="lc-modal" style="max-width:480px;">
    <div class="lc-modal-head">
      <h4><i class="fa fa-wifi" style="color:<?php echo $accent;?>;margin-right:7px;"></i> Student Connectivity</h4>
      <button class="lc-modal-x" onclick="closeModal('connOverlay')">&times;</button>
    </div>
    <div class="lc-modal-body" id="connOverlayBody" style="max-height:380px;overflow-y:auto;padding:12px 16px;">
      <p style="color:#64748b;font-size:13px;text-align:center;padding:20px 0;">No students connected yet.</p>
    </div>
    <div class="lc-modal-foot">
      <button class="btn-lc ghost" onclick="closeModal('connOverlay')">Close</button>
    </div>
  </div>
</div>

<!-- Schedule modal -->
<div class="lc-modal-overlay" id="scheduleModal">
  <div class="lc-modal">
    <div class="lc-modal-head">
      <h4><i class="fa fa-calendar-plus-o" style="color:<?php echo $accent;?>;margin-right:7px;"></i> Schedule Live Class</h4>
      <button class="lc-modal-x" onclick="closeModal('scheduleModal')">&times;</button>
    </div>
    <div class="lc-modal-body">
      <div class="lc-field"><label>Scheduled Date &amp; Time</label><input type="datetime-local" id="sessDate"></div>
      <div class="lc-field">
        <label style="display:flex;align-items:center;gap:6px;">
          <i class="fa fa-book" style="color:<?php echo $accent;?>;font-size:11px;"></i>
          Class Record Term
        </label>
        <select id="sessTerm" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-family:inherit;background:#fff;color:#1e293b;cursor:pointer;transition:border-color .2s;">
          <option value="midterm">&#128337; Midterm</option>
          <option value="final">&#128338; Final Term</option>
        </select>
      </div>
      <div id="scheduleAlert" style="display:none;margin-top:10px;padding:9px 13px;border-radius:8px;font-size:12px;"></div>
    </div>
    <div class="lc-modal-foot">
      <button class="btn-lc ghost" onclick="closeModal('scheduleModal')">Cancel</button>
      <button class="btn-lc accent" id="btnSchedule" onclick="scheduleSession()"><i class="fa fa-save"></i> Schedule</button>
    </div>
  </div>
</div>

<!-- Attendance modal -->
<div class="lc-modal-overlay" id="attendanceModal">
  <div class="lc-modal" style="max-width:600px;">
    <div class="lc-modal-head">
      <h4 id="attModalTitle"><i class="fa fa-list" style="color:<?php echo $accent;?>;margin-right:7px;"></i> Attendance</h4>
      <button class="lc-modal-x" onclick="closeModal('attendanceModal')">&times;</button>
    </div>
    <div class="lc-modal-body" id="attModalBody" style="max-height:400px;overflow-y:auto;">
      <div style="text-align:center;padding:24px;color:#64748b;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
    </div>
    <div class="lc-modal-foot">
      <button class="btn-lc ghost" onclick="closeModal('attendanceModal')">Close</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script src="../bower_components/peerjs/peerjs.min.js"></script>
<script src="../plugins/face-api.js"></script>
<script src="../plugins/pdf.min.js"></script>
<script>
/* ── Config ── */
var IS_TEACHER = <?php echo $isTeacher ? 'true' : 'false'; ?>;
var CLASS_ID   = <?php echo $class_id; ?>;
var CLASS_NAME = '<?php echo addslashes(htmlspecialchars($class['class_name'])); ?>';
var MY_NAME    = '<?php echo addslashes($userName); ?>';
var MY_CODE    = '<?php echo addslashes($user['user_code']); ?>';

// ── ICE config — fast servers first, reliable TURN as fallback ─────────────
var ICE_SERVERS = [
    // Google STUN — fast, no relay needed for same-network peers
    {urls:'stun:stun.l.google.com:19302'},
    {urls:'stun:stun1.l.google.com:19302'},
    // Metered free TURN — only used if STUN fails (different networks/strict NAT)
    {urls:'turn:openrelay.metered.ca:80',
     username:'openrelayproject', credential:'openrelayproject'},
    {urls:'turn:openrelay.metered.ca:443',
     username:'openrelayproject', credential:'openrelayproject'},
    {urls:'turn:openrelay.metered.ca:443?transport=tcp',
     username:'openrelayproject', credential:'openrelayproject'},
];
var PEER_CONFIG = {
    debug: 0,
    config: {
        iceServers: ICE_SERVERS,
        iceTransportPolicy: 'all',
        iceCandidatePoolSize: 2,    // reduced: pre-gather only 2 to shorten startup delay
        bundlePolicy: 'max-bundle', // reduces handshake round-trips
        rtcpMuxPolicy: 'require',
    }
};

// ── Adaptive quality helpers ─────────────────────────────────────────────────
// Detects if the user is on a slow connection at startup
function _getInitialVideoConstraints(){
  var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
  var effectiveType = conn ? (conn.effectiveType || '4g') : '4g';
  var isMobile = /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent);

  // Tier 1: Slow connection (2G / slow-2G) or mobile — start at lowest quality
  if(effectiveType === 'slow-2g' || effectiveType === '2g'){
    return {width:{ideal:320},height:{ideal:240},frameRate:{ideal:10,max:15}};
  }
  // Tier 2: 3G or mobile on 4G — start at medium quality
  if(effectiveType === '3g' || isMobile){
    return {width:{ideal:480},height:{ideal:360},frameRate:{ideal:15,max:20}};
  }
  // Tier 3: Good connection (4G / WiFi desktop) — start at standard quality
  return {width:{ideal:640},height:{ideal:480},frameRate:{ideal:20,max:24}};
}

// Current quality tier: 0=low, 1=medium, 2=high
var _qualityTier = 1;
var _adaptiveInterval = null;

// Bitrate table per tier and peer count
var BITRATE_TABLE = {
  // [tier][peerCount bucket] => {video, audio}
  0: { few: 120000, some: 80000,  many: 60000  }, // low
  1: { few: 280000, some: 180000, many: 120000 }, // medium
  2: { few: 500000, some: 320000, many: 200000 }, // high
};

function _getPeerBucket(){
  var n = Object.keys(peers).length;
  if(n <= 2) return 'few';
  if(n <= 5) return 'some';
  return 'many';
}

// Apply bitrate + resolution scaling to ALL active peer connections
async function _applyQualityToPeers(tier){
  var bucket  = _getPeerBucket();
  var vbr = BITRATE_TABLE[tier][bucket];
  var abr = 48000; // 48kbps audio — clear voice, not choppy
  var scaleDown = tier === 0 ? 2 : 1; // halve resolution on low tier
  var maxFps    = tier === 0 ? 15 : (tier === 1 ? 20 : 24);

  var pcList = Object.values(peers).map(function(c){ return c.peerConnection; }).filter(Boolean);

  for(var i = 0; i < pcList.length; i++){
    var pc = pcList[i];
    if(!pc || pc.iceConnectionState === 'failed' || pc.iceConnectionState === 'closed') continue;
    try {
      var senders = pc.getSenders();
      for(var j = 0; j < senders.length; j++){
        var sender = senders[j];
        if(!sender.track) continue;
        var params = sender.getParameters();
        if(!params.encodings || !params.encodings.length) params.encodings = [{}];
        var enc = params.encodings[0];
        if(sender.track.kind === 'video'){
          var isScreen = (screenStream && screenStream.getVideoTracks().includes(sender.track));
          if(isScreen){
            // Screen sharing optimization: prefer detail and avoid dynamic downscaling
            enc.maxBitrate            = 1500000; // 1.5 Mbps cap for crisp screen share
            enc.maxFramerate          = 15;
            enc.scaleResolutionDownBy = 1;
            enc.degradationPreference = 'maintain-resolution';
          } else {
            // Camera optimization: prioritize frame rate under low bandwidth
            enc.maxBitrate            = vbr;
            enc.maxFramerate          = maxFps;
            enc.scaleResolutionDownBy = scaleDown;
            enc.degradationPreference = 'maintain-framerate';
          }
        } else if(sender.track.kind === 'audio'){
          enc.maxBitrate = abr;
        }
        await sender.setParameters(params);
      }
    } catch(e){ console.warn('[applyQuality]', e); }
  }
}

// Called whenever connectivity level changes — adjusts quality tier dynamically
function adaptQualityToSignal(level){
  var newTier;
  if(level === 'good')       newTier = 2;
  else if(level === 'fair')  newTier = 1;
  else                       newTier = 0; // poor or offline

  if(newTier === _qualityTier) return; // no change
  _qualityTier = newTier;
  console.log('[adaptive] quality tier ->', newTier, '(' + level + ')');
  _applyQualityToPeers(newTier);
}

// Start periodic adaptive quality check (runs every 20s while in call)
function _startAdaptiveQuality(){
  if(_adaptiveInterval) return;
  _adaptiveInterval = setInterval(function(){
    if(!inCall){ clearInterval(_adaptiveInterval); _adaptiveInterval = null; return; }
    // Re-apply current tier to handle any newly joined peers
    _applyQualityToPeers(_qualityTier);
  }, 20000);
}
function _stopAdaptiveQuality(){
  if(_adaptiveInterval){ clearInterval(_adaptiveInterval); _adaptiveInterval = null; }
  _qualityTier = 1; // reset to medium for next call
}

/* ── State ── */
var peer, myStream, screenStream;
var peers = {}, dataChannels = {};
var micOn = true, camOn = true, screenOn = false;
var currentSessionId = null, currentRoomId = null, inCall = false;
var statusPollInterval = null, sessRefreshInterval = null;
var _peerCleanupInterval = null; // stored at module level so stopCall can clear it

/* ── Modals ── */
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.lc-modal-overlay').forEach(function(el){
  el.addEventListener('click', function(e){ if(e.target === el) el.classList.remove('open'); });
});

/* ── Toast ── */
var _toastTimer = null;
function showToast(msg, color){
  var t = document.getElementById('lcToast');
  t.textContent = msg;
  t.className = 'show ' + (color || 'green');
  clearTimeout(_toastTimer);
  _toastTimer = setTimeout(function(){ t.className = ''; }, 3200);
}

/* ── Sidebar ── */
function openSidebar()  { document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }

/* ── Attendance (topbar button during live call) ── */
function openAttendanceModal(){
  if(currentSessionId){
    viewAttendance(currentSessionId, 'Live Session');
  } else {
    // Find the most recent live session
    $.get('live_handler.php', {action:'session_status', class_id:CLASS_ID}, function(r){
      if(r.session_id) viewAttendance(r.session_id, r.title || 'Live Session');
      else showToast('No active session found.', 'red');
    }, 'json');
  }
}

/* ── Schedule ── */
function openScheduleModal(){
  document.getElementById('sessDate').value  = '';
  document.getElementById('sessTerm').value  = 'midterm';
  document.getElementById('scheduleAlert').style.display = 'none';
  openModal('scheduleModal');
}
function scheduleSession(){
  var date  = $('#sessDate').val();
  var term  = $('#sessTerm').val();
  if(!date){ showSchedAlert('Please select a date and time.'); return; }
  
  // Automatically generate a title based on the scheduled date/time (e.g. "Class: Jul 10, 02:30 PM")
  var d = new Date(date);
  var options = { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true };
  var title = "Live Class: " + d.toLocaleString('en-US', options);

  $('#btnSchedule').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
  $.post('live_handler.php', {action:'schedule', class_id:CLASS_ID, title:title, scheduled_at:date, term:term}, function(r){
    $('#btnSchedule').prop('disabled', false).html('<i class="fa fa-save"></i> Schedule');
    if(r.success){
      document.getElementById('scheduleAlert').innerHTML = '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 13px;font-size:12px;color:#166534;display:flex;align-items:center;gap:8px;margin-top:10px;"><i class="fa fa-check-circle"></i><span style="font-weight:700;">Session scheduled!</span></div>';
      document.getElementById('scheduleAlert').style.display = 'block';
      setTimeout(function(){ closeModal('scheduleModal'); location.reload(); }, 2000);
    }
    else showSchedAlert(r.msg || 'Failed to schedule.');
  }, 'json').fail(function(){
    // BUG 8 FIX: re-enable button on network/server error so user is not stuck
    $('#btnSchedule').prop('disabled', false).html('<i class="fa fa-save"></i> Schedule');
    showSchedAlert('Network error. Please try again.');
  });
}
function showSchedAlert(msg){
  var el = document.getElementById('scheduleAlert');
  el.style.cssText = 'display:flex;align-items:center;gap:8px;padding:9px 13px;border-radius:8px;font-size:12px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;';
  el.innerHTML = '<i class="fa fa-exclamation-circle"></i> ' + msg;
}

/* ── Session management ── */
function startSession(sessionId){
  if(!confirm('Start this live session now?')) return;
  $.post('live_handler.php', {action:'start', session_id:sessionId}, function(r){
    if(r.success) location.reload();
    else showToast(r.msg || 'Failed to start.', 'red');
  }, 'json');
}
function endSession(sessionId){
  if(!confirm('End this live session? Students will be disconnected.')) return;
  $.post('live_handler.php', {action:'end', session_id:sessionId}, function(r){
    if(r.success){ if(inCall) stopCall(); location.reload(); }
    else showToast(r.msg || 'Failed to end.', 'red');
  }, 'json');
}
function deleteSession(sessionId){
  if(!confirm('Delete this scheduled session?')) return;
  $.post('live_handler.php', {action:'delete_session', session_id:sessionId}, function(r){
    if(r.success) location.reload();
  }, 'json');
}

/* ── Attendance ── */
// BUG 9 FIX: helper to escape HTML and prevent XSS from student names/codes
function escHtml(str){
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function viewAttendance(sessionId, title){
  document.getElementById('attModalTitle').innerHTML = '<i class="fa fa-list" style="color:<?php echo $accent;?>;margin-right:7px;"></i> ' + escHtml(title) + ' — Attendance';
  document.getElementById('attModalBody').innerHTML  = '<div style="text-align:center;padding:24px;color:#64748b;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>';
  openModal('attendanceModal');
  $.get('live_handler.php', {action:'attendance', session_id:sessionId}, function(r){
    if(!r.success){ document.getElementById('attModalBody').innerHTML = '<p style="color:#ef4444;padding:16px;">' + escHtml(r.msg) + '</p>'; return; }
    if(!r.attendance.length){ document.getElementById('attModalBody').innerHTML = '<p style="color:#64748b;text-align:center;padding:24px;">No attendance recorded.</p>'; return; }
    var html = '<table class="att-table"><thead><tr><th>#</th><th>Student</th><th>ID</th><th>Joined</th><th>Left</th></tr></thead><tbody>';
    r.attendance.forEach(function(a, i){
      // BUG 9 FIX: all user-supplied values escaped before inserting into innerHTML
      html += '<tr><td style="color:#475569;">' + (i+1) + '</td>'
        + '<td style="font-weight:600;color:#f1f5f9;">' + escHtml(a.first_name) + ' ' + escHtml(a.last_name) + '</td>'
        + '<td><span style="background:rgba(23,146,187,.15);color:#1792bb;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:700;">' + escHtml(a.student_code) + '</span></td>'
        + '<td>' + escHtml(new Date(a.joined_at).toLocaleTimeString()) + '</td>'
        + '<td>' + (a.left_at ? escHtml(new Date(a.left_at).toLocaleTimeString()) : '<span style="color:#10b981;">Still in</span>') + '</td>'
        + '</tr>';
    });
    html += '</tbody></table>';
    document.getElementById('attModalBody').innerHTML = html;
  }, 'json');
}

/* ── Auto-refresh sessions list when NOT in call ── */
function startSessRefresh(){
  if(sessRefreshInterval) return;
  sessRefreshInterval = setInterval(function(){
    if(inCall) return;
    $.get('live_handler.php', {action:'sessions_list', class_id:CLASS_ID}, function(r){
      if(r && r.html) document.getElementById('sessListWrap').innerHTML = r.html;
    }, 'json');
  }, 10000);
}
startSessRefresh();
</script>
<script>
/* ── Call UI helpers ── */
function showCallUI(){
  inCall = true;
  document.body.classList.add('in-call');
  clearInterval(sessRefreshInterval);
  sessRefreshInterval = null;
  document.getElementById('dashPanel').style.display   = 'none';
  document.getElementById('videoGrid').classList.add('in-call');
  document.getElementById('liveBadge').style.display   = 'flex';
  document.getElementById('partWrap').style.display    = 'flex';
  document.getElementById('lcControls').classList.add('show');
  addTile(myStream, MY_NAME + ' (You)', true, 'local');
  updateGrid();
}
function stopCall(){
  inCall = false;

  if(myStream)     myStream.getTracks().forEach(function(t){ t.stop(); });
  if(screenStream) screenStream.getTracks().forEach(function(t){ t.stop(); });

  Object.values(peers).forEach(function(call){ try{ call.close(); }catch(e){} });
  peers = {};
  _prevStats = {};
  dataChannels = {};

  if(peer){ try{ peer.destroy(); }catch(e){} peer = null; }

  clearInterval(statusPollInterval);
  statusPollInterval = null;
  clearInterval(_peerCleanupInterval);
  _peerCleanupInterval = null;
  clearInterval(_peerHeartbeatInterval);
  _peerHeartbeatInterval = null;
  clearInterval(_discoveryInterval);
  _discoveryInterval = null;

  if(!IS_TEACHER){
    stopFaceDetection();
    stopActivityDetection();
    stopConnMonitor();
    // BUG 3 FIX: beacon removed here — leaveCall() already sends the leave request
    // sendBeacon is only used as a last-resort on page unload (see beforeunload handler)
    if(currentSessionId && _leavingByUnload){
      navigator.sendBeacon('live_handler.php', new URLSearchParams({action:'leave', session_id:currentSessionId}));
    }
  }
  // Stop adaptive quality loop on call end (both teacher and student)
  _stopAdaptiveQuality();

  currentSessionId = null;
  currentRoomId    = null;
  micOn = true; camOn = true; screenOn = false;

  /* Reset grid */
  var grid = document.getElementById('videoGrid');
  grid.innerHTML = '<div id="connectingOverlay"><div class="conn-spinner"></div><p>Connecting to session...</p></div>';
  grid.classList.remove('in-call', 'screen-mode', 'p1', 'p2', 'p3', 'p4');

  /* Restore dashboard */
  document.body.classList.remove('in-call');
  document.getElementById('dashPanel').style.display = '';
  document.getElementById('liveBadge').style.display = 'none';
  document.getElementById('partWrap').style.display  = 'none';
  document.getElementById('lcControls').classList.remove('show');

  /* Reset control buttons */
  var mIco = document.getElementById('micIcon'); if(mIco) mIco.className = 'fa fa-microphone';
  var bMic = document.getElementById('btnMic'); if(bMic) bMic.className = 'ctrl-btn on';
  var cIco = document.getElementById('camIcon'); if(cIco) cIco.className = 'fa fa-video-camera';
  var bCam = document.getElementById('btnCam'); if(bCam) bCam.className = 'ctrl-btn on';
  var sIco = document.getElementById('screenIcon'); if(sIco) sIco.className = 'fa fa-desktop';
  var bScr = document.getElementById('btnScreen'); if(bScr) bScr.className = 'ctrl-btn';

  if(IS_TEACHER){
    _studentConn = {};
    var dotsEl = document.getElementById('cpDots');
    var panel  = document.getElementById('connPanel');
    if(dotsEl) dotsEl.innerHTML = '';
    if(panel)  panel.style.display = 'none';
  }

  /* Restart session refresh */
  startSessRefresh();
}

/* ── Deduplicate tiles for same user ── */
function cleanupDuplicateTiles(peerId, studentCode, name){
  document.querySelectorAll('.video-tile').forEach(function(t){
    if(t.id === 'tile_local' || t.id === 'tile_screen') return;
    var match = false;
    if(peerId && t.id === 'tile_' + peerId) match = true;
    if(!match && studentCode && t.dataset.code && String(t.dataset.code).toLowerCase() === String(studentCode).toLowerCase()) match = true;
    if(!match && name){
      var lbl = t.querySelector('.tile-label');
      if(lbl){
        var txt = lbl.textContent.replace(/\(You\)/gi, '').replace(/Muted/gi, '').trim().toLowerCase();
        var searchName = String(name).replace(/\(You\)/gi, '').trim().toLowerCase();
        if(txt && searchName && (txt === searchName || txt.indexOf(searchName) !== -1 || searchName.indexOf(txt) !== -1)){
          match = true;
        }
      }
    }
    if(match){
      var oldPeerId = t.id.replace('tile_', '');
      if(oldPeerId && oldPeerId !== peerId && peers[oldPeerId]){
        try { peers[oldPeerId]._superseded = true; peers[oldPeerId].close(); } catch(e){}
        delete peers[oldPeerId];
      }
      if(oldPeerId && oldPeerId !== peerId) delete dataChannels[oldPeerId];
      t.remove();
    }
  });
}

/* ── Add video tile ── */
function addTile(stream, name, isLocal, tileId, studentCode){
  // Self-tile guard: never add remote tile for local user
  if(!isLocal && (tileId === MY_PEER_ID || tileId === 'local')) return;

  var existing = document.getElementById('tile_' + tileId);
  if(existing) existing.remove();

  // Clean up duplicate/stale tiles of the same student (rejoined/reconnected)
  if(!isLocal){
    cleanupDuplicateTiles(tileId, studentCode, name);
  }

  var grid = document.getElementById('videoGrid');
  var tile = document.createElement('div');
  tile.className = 'video-tile tile-entering';
  tile.id = 'tile_' + tileId;
  if(studentCode) tile.dataset.code = studentCode;

  /* Video element */
  var video = document.createElement('video');
  video.setAttribute('autoplay', '');
  video.setAttribute('playsinline', '');
  video.setAttribute('webkit-playsinline', '');
  if(isLocal){ video.setAttribute('muted', ''); video.muted = true; }
  tile.appendChild(video);

  /* Detect portrait/vertical streams and apply contain to prevent cropping */
  var checkOrientation = function(){
    if(video.videoWidth > 0 && video.videoHeight > video.videoWidth){
      video.classList.add('portrait');
    } else {
      video.classList.remove('portrait');
    }
  };
  video.addEventListener('loadedmetadata', checkOrientation);
  video.addEventListener('resize', checkOrientation);

  /* Avatar (shown when cam off or away) */
  var avatar = document.createElement('div');
  avatar.className = 'tile-avatar';
  avatar.id = 'avatar_' + tileId;
  avatar.style.display = 'none';
  var init = name.split(' ').map(function(w){ return w[0] || ''; }).join('').substring(0, 2).toUpperCase();
  avatar.innerHTML = '<div class="av-ring">' + init + '</div><div class="av-name">' + name + '</div>';
  tile.appendChild(avatar);

  /* Name label */
  var label = document.createElement('div');
  label.className = 'tile-label';
  label.innerHTML = '<span class="role-dot"></span>' + name;
  tile.appendChild(label);

  /* Mic-off badge */
  var micBadge = document.createElement('div');
  micBadge.className = 'tile-mic-off';
  micBadge.id = 'micoff_' + tileId;
  micBadge.innerHTML = '<i class="fa fa-microphone-slash"></i> Muted';
  tile.appendChild(micBadge);

  /* Away badge */
  var awayBadge = document.createElement('div');
  awayBadge.className = 'tile-away';
  awayBadge.id = 'away_' + tileId;
  tile.appendChild(awayBadge);

  /* Hand raise badge */
  var handBadge = document.createElement('div');
  handBadge.className = 'tile-hand-badge';
  handBadge.id = 'hand_' + tileId;
  handBadge.innerHTML = '✋ Hand Raised';
  tile.appendChild(handBadge);

  /* Connectivity badge (teacher only, remote tiles) */
  if(!isLocal && IS_TEACHER){
    var connBadge = document.createElement('div');
    connBadge.className = 'tile-conn-badge';
    connBadge.id = 'conn_' + tileId;
    tile.appendChild(connBadge);
  }

  /* Append tile to grid BEFORE setting srcObject */
  grid.appendChild(tile);

  /* Set srcObject and play AFTER DOM append */
  video.srcObject = stream;
  video.play().catch(function(e){ console.warn('[video.play]', e); });

  if(isLocal){
    var hasVideo = stream.getVideoTracks().length > 0 && stream.getVideoTracks()[0].enabled;
    if(!hasVideo){
      video.style.display = 'none';
      avatar.style.display = 'flex';
    }
  }

  updateGrid();
  
  // Remove entering class in the next frames to trigger transition
  requestAnimationFrame(function(){
    requestAnimationFrame(function(){
      tile.classList.remove('tile-entering');
    });
  });
}

/* ── Remove tile (on disconnect or leave) ── */
function removeTile(peerId, studentCode, name){
  if(peerId) delete peers[peerId];
  if(peerId) delete dataChannels[peerId];
  if(IS_TEACHER && peerId) removeFromConnPanel(peerId);
  
  cleanupDuplicateTiles(peerId, studentCode, name);

  var t = document.getElementById('tile_' + peerId);
  if(t) {
    t.classList.add('tile-leaving');
    t.remove();
  }
  updateGrid();
}

/* ── Grid layout ── */
function updateGrid(){
  var grid  = document.getElementById('videoGrid');
  if(!grid) return;
  
  var isScreenMode = grid.classList.contains('screen-mode');
  
  // Count active video tiles, ignoring leaving animations
  var tiles = Array.from(grid.querySelectorAll('.video-tile:not(.screen-tile):not(.tile-leaving)'));
  var total = tiles.length;
  
  // Include screensharing tiles in participant count
  var screens = grid.querySelectorAll('.screen-tile:not(.tile-leaving)').length;
  document.getElementById('partCount').textContent = total + screens;

  if (isScreenMode) {
    grid.style.gridTemplateColumns = '';
    grid.style.gridAutoRows = '';
    grid.style.justifyContent = '';
    grid.style.alignContent = '';

    // Build or get wrappers
    var stageWrap = document.getElementById('screenStageWrap');
    if(!stageWrap) {
      stageWrap = document.createElement('div');
      stageWrap.className = 'screen-stage-wrap';
      stageWrap.id = 'screenStageWrap';
      grid.appendChild(stageWrap);
    }

    var divider = document.getElementById('lcResizeDivider');
    if(!divider) {
      divider = document.createElement('div');
      divider.className = 'lc-resize-divider';
      divider.id = 'lcResizeDivider';
      divider.title = 'Drag to resize PowerPoint presentation / Double-click to reset';
      grid.appendChild(divider);
      initResizeDivider();
    }

    var studentsWrap = document.getElementById('screenStudentsWrap');
    if(!studentsWrap) {
      studentsWrap = document.createElement('div');
      studentsWrap.className = 'screen-students-wrap';
      studentsWrap.id = 'screenStudentsWrap';
      grid.appendChild(studentsWrap);
    }

    // Move screen/PowerPoint tiles to stageWrap
    var screenTiles = grid.querySelectorAll('.screen-tile');
    screenTiles.forEach(function(tile) {
      if(tile.parentElement !== stageWrap) stageWrap.appendChild(tile);
    });

    // Move camera tiles to studentsWrap
    var cameraTiles = grid.querySelectorAll('.video-tile:not(.screen-tile)');
    cameraTiles.forEach(function(tile) {
      if(tile.parentElement !== studentsWrap) studentsWrap.appendChild(tile);
      tile.style.width = '';
      tile.style.height = '';
    });
    return;
  } else {
    // If exiting screen mode, unpack tiles back to main grid
    var stageWrap = document.getElementById('screenStageWrap');
    var divider = document.getElementById('lcResizeDivider');
    var studentsWrap = document.getElementById('screenStudentsWrap');

    if(studentsWrap) {
      Array.from(studentsWrap.children).forEach(function(tile) {
        grid.appendChild(tile);
      });
      studentsWrap.remove();
    }
    if(stageWrap) {
      Array.from(stageWrap.children).forEach(function(tile) {
        grid.appendChild(tile);
      });
      stageWrap.remove();
    }
    if(divider) divider.remove();
  }
  
  var containerWidth = grid.clientWidth - 32; // padding offset
  var containerHeight = grid.clientHeight - 32;
  
  if (total === 0 || containerWidth <= 0 || containerHeight <= 0) return;
  
  var bestCols = 1;
  var bestRows = 1;
  var bestWidth = 0;
  var bestHeight = 0;
  var maxArea = 0;
  var aspectRatio = 16 / 9;
  
  // Test layouts to find the best matching dimensions for the grid viewport
  for (var cols = 1; cols <= total; cols++) {
    var rows = Math.ceil(total / cols);
    var gapX = (cols - 1) * 12;
    var gapY = (rows - 1) * 12;
    var maxW = (containerWidth - gapX) / cols;
    var maxH = (containerHeight - gapY) / rows;
    
    if (maxW <= 0 || maxH <= 0) continue;
    
    var w = maxW;
    var h = maxW / aspectRatio;
    
    if (h > maxH) {
      h = maxH;
      w = maxH * aspectRatio;
    }
    
    var area = w * h * total;
    if (area > maxArea) {
      maxArea = area;
      bestCols = cols;
      bestRows = rows;
      bestWidth = w;
      bestHeight = h;
    }
  }

  // Minimum tile dimension floor to avoid microscopic unreadable tiles in large rooms
  var minTileW = (window.innerWidth <= 600) ? 110 : 140;
  var minTileH = Math.floor(minTileW / aspectRatio);

  if (bestWidth < minTileW || bestHeight < minTileH) {
    // When participants exceed single-screen capacity, switch to scrollable grid layout
    var maxColsFit = Math.max(1, Math.floor((containerWidth + 12) / (minTileW + 12)));
    bestCols = maxColsFit;
    bestWidth = Math.floor((containerWidth - (maxColsFit - 1) * 12) / maxColsFit);
    bestHeight = Math.floor(bestWidth / aspectRatio);
    grid.style.overflowY = 'auto';
    grid.style.alignContent = 'start';
  } else {
    grid.style.overflowY = 'hidden';
    grid.style.alignContent = 'center';
  }
  
  grid.style.display = 'grid';
  grid.style.gridTemplateColumns = 'repeat(' + bestCols + ', ' + Math.floor(bestWidth) + 'px)';
  grid.style.gridAutoRows = Math.floor(bestHeight) + 'px';
  grid.style.justifyContent = 'center';
  
  tiles.forEach(function(tile){
    tile.style.width = Math.floor(bestWidth) + 'px';
    tile.style.height = Math.floor(bestHeight) + 'px';
    tile.classList.remove('tile-lg', 'tile-md', 'tile-sm');
    if(total <= 2) {
      tile.classList.add('tile-lg');
    } else if(total <= 6) {
      tile.classList.add('tile-md');
    } else {
      tile.classList.add('tile-sm');
    }
  });
}

/* ── Resizable Presentation Divider Drag Handler ── */
var _isDraggingDivider = false;
function initResizeDivider() {
  var divider = document.getElementById('lcResizeDivider');
  if(!divider || divider.dataset.initialized) return;
  divider.dataset.initialized = 'true';

  function onPointerDown(e) {
    _isDraggingDivider = true;
    divider.classList.add('dragging');
    document.body.style.userSelect = 'none';
    if(e.cancelable) e.preventDefault();
  }

  function onPointerMove(e) {
    if(!_isDraggingDivider) return;
    var grid = document.getElementById('videoGrid');
    if(!grid) return;

    var rect = grid.getBoundingClientRect();
    var isMobile = window.innerWidth <= 900;
    var pct;

    if(!isMobile) {
      var clientX = e.touches ? e.touches[0].clientX : e.clientX;
      var relativeX = clientX - rect.left;
      pct = (relativeX / rect.width) * 100;
      pct = Math.max(25, Math.min(88, pct));
    } else {
      var clientY = e.touches ? e.touches[0].clientY : e.clientY;
      var relativeY = clientY - rect.top;
      pct = (relativeY / rect.height) * 100;
      pct = Math.max(25, Math.min(85, pct));
    }

    grid.style.setProperty('--ppt-split-percent', pct.toFixed(1) + '%');
  }

  function onPointerUp() {
    if(_isDraggingDivider) {
      _isDraggingDivider = false;
      divider.classList.remove('dragging');
      document.body.style.userSelect = '';
    }
  }

  divider.addEventListener('mousedown', onPointerDown);
  divider.addEventListener('touchstart', onPointerDown, { passive: false });
  window.addEventListener('mousemove', onPointerMove);
  window.addEventListener('touchmove', onPointerMove, { passive: false });
  window.addEventListener('mouseup', onPointerUp);
  window.addEventListener('touchend', onPointerUp);

  divider.addEventListener('dblclick', function() {
    var isMobile = window.innerWidth <= 900;
    document.getElementById('videoGrid').style.setProperty('--ppt-split-percent', isMobile ? '60%' : '75%');
  });
}

// Watch window resize events to update tile dimensions
window.addEventListener('resize', updateGrid);
</script>
<script>
/* ── Teacher: Join call ── */
function joinCall(sessionId, roomId){
  currentSessionId = sessionId;
  currentRoomId    = roomId;
  var videoConstraints = _getInitialVideoConstraints();
  navigator.mediaDevices.getUserMedia({
    video: videoConstraints,
    audio: {echoCancellation:true, noiseSuppression:true, autoGainControl:true}
  }).then(function(stream){
    myStream = stream;
    showCallUI();
    showConnecting(true);

    peer = new Peer(roomId, PEER_CONFIG);

    peer.on('open', function(myPeerId){
      showConnecting(false);
      // Register in peer table so students can discover teacher
      $.post('live_handler.php',{action:'register_peer',session_id:sessionId,peer_id:myPeerId,name:MY_NAME});
      _startPeerHeartbeat(sessionId);
      _startAdaptiveQuality();
    });

    peer.on('call', function(call){
      var name = (call.metadata && call.metadata.name) ? call.metadata.name : 'Student';
      var studentCode = (call.metadata && call.metadata.code) ? call.metadata.code : null;
      call.answer(myStream);

      // Track if this specific call's tile has been superseded
      var callSuperseded = false;

      call.on('stream', function(rs){
        // Guard: only process the first stream event per call
        if(callSuperseded) return;

        // Remove any existing tile for this exact peer ID
        var existingById = document.getElementById('tile_' + call.peer);
        if(existingById){
          existingById.remove();
          delete dataChannels[call.peer];
        }
        // Remove stale tile from same student (rejoined with new peer ID)
        document.querySelectorAll('.video-tile').forEach(function(t){
          if(t.id === 'tile_local' || t.id === 'tile_screen' || t.id === 'tile_' + call.peer) return;
          var match = false;
          if(studentCode && t.dataset.code === studentCode) match = true;
          if(!match){
            var lbl = t.querySelector('.tile-label');
            if(lbl && lbl.textContent.trim().indexOf(name) !== -1) match = true;
          }
          if(match){
            var oldPeerId = t.id.replace('tile_', '');
            // Mark old call as superseded so its close event won't remove the new tile
            if(peers[oldPeerId]){
              try{ peers[oldPeerId]._superseded = true; peers[oldPeerId].close(); }catch(e){}
              delete peers[oldPeerId];
            }
            delete dataChannels[oldPeerId];
            t.remove();
          }
        });
        peers[call.peer] = call;
        addTile(rs, name, false, call.peer, studentCode);
        setupDataChannel(call, call.peer);
        capBitrate(call.peerConnection);
        // BUG 11 FIX: if screen share is active when a student joins, push the
        // screen track to their connection immediately
        _applyScreenTrackToPeer(call);

        // Monitor ICE connection state
        call.peerConnection.oniceconnectionstatechange = function(){
          if(callSuperseded) return;
          var state = call.peerConnection.iceConnectionState;
          if(state === 'disconnected' || state === 'failed' || state === 'closed'){
            setTimeout(function(){
              if(callSuperseded) return;
              var s = call.peerConnection.iceConnectionState;
              if(s === 'disconnected' || s === 'failed' || s === 'closed'){
                removeTile(call.peer);
                delete dataChannels[call.peer];
              }
            }, 3000);
          }
        };
      });

      call.on('close', function(){
        // Don't remove tile if this call was superseded by a reconnect
        if(call._superseded) return;
        callSuperseded = true;
        removeTile(call.peer);
        delete dataChannels[call.peer];
      });
      call.on('error', function(){
        if(call._superseded) return;
        callSuperseded = true;
        removeTile(call.peer);
        delete dataChannels[call.peer];
      });
    });

    peer.on('error', function(e){ console.warn('[peer error]', e); showConnecting(false); });

    // BUG 5 FIX: moved _peerCleanupInterval INSIDE .then() so it only starts
    // when getUserMedia succeeds and the call is actually established
    clearInterval(_peerCleanupInterval);
    _peerCleanupInterval = setInterval(function(){
      if(!inCall || !IS_TEACHER) return;
      Object.keys(peers).forEach(function(peerId){
        var call = peers[peerId];
        if(!call || !call.peerConnection) return;
        var state = call.peerConnection.iceConnectionState;
        if(state === 'failed' || state === 'closed'){
          removeTile(peerId);
          delete dataChannels[peerId];
        }
      });
    }, 15000);

  }).catch(function(e){ alert('Camera/mic access denied: ' + e.message); });
}

/* ── Student: Join call ── */
function joinAsStudent(sessionId, roomId){
  currentSessionId = sessionId;
  currentRoomId    = roomId;
  var videoConstraints = _getInitialVideoConstraints();
  navigator.mediaDevices.getUserMedia({
    video: videoConstraints,
    audio: {echoCancellation:true, noiseSuppression:true, autoGainControl:true}
  }).then(function(stream){
    myStream = stream;
    showCallUI();
    showConnecting(true);

    // Mute student microphone by default on join to eliminate acoustic feedback in multi-student classes
    micOn = false;
    myStream.getAudioTracks().forEach(function(t){ t.enabled = false; });
    var bMic = document.getElementById('btnMic'); if(bMic) bMic.className = 'ctrl-btn';
    var mIco = document.getElementById('micIcon'); if(mIco) mIco.className = 'fa fa-microphone-slash';
    var badge = document.getElementById('micoff_local'); if(badge) badge.classList.add('show');

    $.post('live_handler.php', {action:'record_attendance', session_id:sessionId});
    startActivityDetection();
    startConnMonitor();

    peer = new Peer(PEER_CONFIG);

    peer.on('open', function(myPeerId){
      // Register peer ID so others can call us
      $.post('live_handler.php',{action:'register_peer',session_id:sessionId,peer_id:myPeerId,name:MY_NAME});
      _startPeerHeartbeat(sessionId);
      _startAdaptiveQuality();

      // ── Call the teacher ──────────────────────────────────────────────
      var teacherCall = peer.call(currentRoomId, myStream, {metadata:{name:MY_NAME, code:MY_CODE, role:'STUDENT'}});
      if(!teacherCall){
        showConnecting(false);
        showToast('Could not connect to session.', 'red');
        return;
      }
      teacherCall.on('stream', function(rs){
        showConnecting(false);
        var existingVid = document.querySelector('#tile_' + teacherCall.peer + ' video');
        if(existingVid){ existingVid.srcObject = rs; existingVid.play().catch(function(){}); return; }
        peers[teacherCall.peer] = teacherCall;
        addTile(rs, 'Teacher', false, teacherCall.peer);
        setupDataChannel(teacherCall, teacherCall.peer);
        capBitrate(teacherCall.peerConnection);
        // Send initial engagement state to teacher immediately (don't wait 15s)
        setTimeout(function(){ _broadcastEngagement(); }, 800);
        setTimeout(function(){
          var localVid = document.querySelector('#tile_local video');
          if(localVid) startFaceDetection(localVid);
        }, 1500);
      });
      teacherCall.on('close', function(){
        showToast('The teacher has ended the class.', 'red');
        var av = document.getElementById('avatar_' + teacherCall.peer);
        var vid = document.querySelector('#tile_' + teacherCall.peer + ' video');
        if(av && vid){ vid.style.display = 'none'; av.style.display = 'flex'; }
        setTimeout(function(){ stopCall(); location.reload(); }, 3000);
      });
      teacherCall.on('error', function(e){ showConnecting(false); console.warn('[teacher call error]', e); });

      // ── Discover and call existing students (mesh) ────────────────────
      // Wait 1s for our registration to propagate, then fetch peers
      setTimeout(function(){
        _discoverAndCallPeers(sessionId);
      }, 1000);
    });

    // ── Accept incoming calls from other students ─────────────────────
    // BUG FIX: renamed parameter from 'inCall' to 'incomingCall' to avoid
    // shadowing the outer global 'inCall' boolean state variable
    peer.on('call', function(incomingCall){
      var name = (incomingCall.metadata && incomingCall.metadata.name) ? incomingCall.metadata.name : 'Student';
      var code = (incomingCall.metadata && incomingCall.metadata.code) ? incomingCall.metadata.code : null;
      // Don't re-answer calls from teacher (teacher uses fixed roomId, we already called them)
      if(incomingCall.peer === currentRoomId){ return; }
      incomingCall.answer(myStream);
      _applyScreenTrackToPeer(incomingCall);
      incomingCall.on('stream', function(rs){
        var existingVid = document.querySelector('#tile_' + incomingCall.peer + ' video');
        if(existingVid){ existingVid.srcObject = rs; existingVid.play().catch(function(){}); return; }
        peers[incomingCall.peer] = incomingCall;
        addTile(rs, name, false, incomingCall.peer, code);
        setupDataChannel(incomingCall, incomingCall.peer);
        capBitrate(incomingCall.peerConnection);
      });
      incomingCall.on('close', function(){ removeTile(incomingCall.peer); delete dataChannels[incomingCall.peer]; });
      incomingCall.on('error',  function(){ removeTile(incomingCall.peer); delete dataChannels[incomingCall.peer]; });
    });

    peer.on('error', function(e){
      showConnecting(false);
      console.warn('[peer error]', e);
      if(e.type === 'peer-unavailable'){
        // Teacher not ready yet — retry after 3 seconds
        showToast('Waiting for teacher... retrying in 3s', 'blue');
        setTimeout(function(){
          if(!inCall) return;
          showConnecting(true);
          var call = peer.call(currentRoomId, myStream, {metadata:{name:MY_NAME, code:MY_CODE}});
          if(!call){ showConnecting(false); showToast('Could not connect. Please try again.', 'red'); return; }
          call.on('stream', function(rs){
            showConnecting(false);
            // Remove existing teacher tile if any
            var existingVid = document.querySelector('#tile_' + call.peer + ' video');
            if(existingVid){ existingVid.srcObject = rs; existingVid.play().catch(function(){}); return; }
            peers[call.peer] = call;
            addTile(rs, 'Teacher', false, call.peer);
            setupDataChannel(call, call.peer);
            capBitrate(call.peerConnection);
          });
          call.on('close', function(){
            showToast('The teacher has ended the class.', 'red');
            setTimeout(function(){ stopCall(); location.reload(); }, 3000);
          });
        }, 3000);
      }
    });

    /* Poll for session end — staggered with random jitter to avoid thundering herd */
    var pollDelay = 10000 + Math.floor(Math.random() * 5000); // 10-15s random
    // BUG 4 FIX: guard flag prevents double stopCall/reload on race condition
    var _sessionEndHandled = false;
    statusPollInterval = setInterval(function(){
      $.get('live_handler.php', {action:'session_status', class_id:CLASS_ID}, function(r){
        if((r.status === 'ended' || r.status === 'none') && !_sessionEndHandled){
          _sessionEndHandled = true;
          clearInterval(statusPollInterval);
          statusPollInterval = null;
          showToast('The live session has ended.', 'red');
          setTimeout(function(){ stopCall(); location.reload(); }, 2500);
        }
      }, 'json');
    }, pollDelay);

  }).catch(function(e){ alert('Camera/mic access denied: ' + e.message); });
}

/* ── Connecting overlay ── */
function showConnecting(show){
  var el = document.getElementById('connectingOverlay');
  if(el) el.classList.toggle('show', show);
}
</script>
<script>
/* ── DataChannel for mic/attention/conn state ── */
// BUG 10 FIX: only ONE side should createDataChannel to avoid 4-channel duplication.
// Teacher (answerer) uses ondatachannel to receive the channel the student created.
// Student (caller) creates the channel. Both sides share one channel per peer pair.
function setupDataChannel(call, peerId){
  // Determine who creates the channel by lexicographic peer ID comparison.
  // This works for both teacher-student AND student-student pairs:
  // the peer with the LOWER ID creates, the other listens via ondatachannel.
  // This guarantees exactly one channel per pair regardless of topology.
  var myPeerId = peer ? peer.id : '';
  var iCreated = (myPeerId < peerId); // consistent rule: lower ID creates

  if(iCreated){
    try {
      var dc = call.peerConnection.createDataChannel('state-' + peerId);
      dc.onopen = function(){
        dataChannels[peerId] = dc;
        if(dc.readyState === 'open'){
          dc.send(JSON.stringify({type:'mic', muted:!micOn}));
          dc.send(JSON.stringify({type:'cam', on:camOn, name:MY_NAME}));
          if(screenOn){
            dc.send(JSON.stringify({type:'screen', sharing:true, name:MY_NAME}));
          }
        }
      };
      dc.onmessage = function(msg){ handleDataMsg(msg.data, peerId); };
    } catch(e){ console.warn('[setupDataChannel create]', e); }
  } else {
    call.peerConnection.ondatachannel = function(e){
      var ch = e.channel;
      dataChannels[peerId] = ch;
      ch.onopen = function(){
        if(ch.readyState === 'open'){
          ch.send(JSON.stringify({type:'mic', muted:!micOn}));
          ch.send(JSON.stringify({type:'cam', on:camOn, name:MY_NAME}));
          if(screenOn){
            ch.send(JSON.stringify({type:'screen', sharing:true, name:MY_NAME}));
          }
        }
      };
      ch.onmessage = function(msg){ handleDataMsg(msg.data, peerId); };
    };
  }
}

function handleDataMsg(raw, peerId){
  try {
    var data = JSON.parse(raw);

    if(data.type === 'reaction'){
      _spawnFloatingReaction(data.emoji, data.name || 'Participant');
    }

    if(data.type === 'hand_raise'){
      var peerTile = document.getElementById('tile_' + peerId);
      if(peerTile) peerTile.classList.toggle('hand-raised', !!data.raised);
      if(data.raised && IS_TEACHER){
        showToast('✋ ' + (data.name || 'A student') + ' raised their hand', 'blue');
      }
    }

    if(data.type === 'force_mute' && !IS_TEACHER){
      if(micOn){
        toggleMic();
        showToast('The teacher has muted all microphones.', 'blue');
      }
    }

    if(data.type === 'nudge' && !IS_TEACHER){
      showToast('🔔 Teacher requested your attention!', 'blue');
    }

    if(data.type === 'question' && !IS_TEACHER){
      showToast('❓ Question: ' + (data.text || ''), 'green');
    }

    if(data.type === 'mic'){
      var badge = document.getElementById('micoff_' + peerId);
      if(badge) badge.classList.toggle('show', data.muted);
    }

    if(data.type === 'cam'){
      // Show/hide the actual video and avatar on the remote tile when camera toggles
      var remoteAv  = document.getElementById('avatar_' + peerId);
      var remoteTile = document.getElementById('tile_' + peerId);
      var remoteVid = remoteTile ? remoteTile.querySelector('video') : null;
      if(remoteAv && remoteVid){
        remoteVid.style.display = data.on ? 'block' : 'none';
        remoteAv.style.display  = data.on ? 'none'  : 'flex';
      }
    }

    if(data.type === 'attention'){
      var ab = document.getElementById('away_' + peerId);
      var lvl   = data.level || (data.focused ? 'focused' : 'away');
      var score = (typeof data.score === 'number') ? data.score : null;
      var reason = '';
      if(data.camOff && !data.tabVisible)      reason = 'Cam Off + Away';
      else if(!data.tabVisible)                reason = 'Tab Hidden';
      else if(!data.winFocused)                reason = 'Window Blur';
      else if(data.camOff)                     reason = 'Cam Off';
      else if(data.faceReason)                 reason = data.faceReason;
      else                                     reason = 'Away';

      if(IS_TEACHER){
        var peerTile = document.getElementById('tile_' + peerId);
        var tileName = peerTile ? (peerTile.querySelector('.tile-label') ? peerTile.querySelector('.tile-label').textContent.trim() : '') : '';
        _studentInteractions[peerId] = {
          name: data.name || tileName || 'Student',
          level: lvl,
          score: score,
          reason: (lvl === 'focused' ? 'Focused' : (lvl === 'partial' ? (data.faceReason || 'Partial') : reason))
        };
        if(document.getElementById('interactionsModal') && document.getElementById('interactionsModal').classList.contains('open')){
          renderInteractionsModal();
        }
      }

      if(ab){
        ab.classList.remove('show', 'focused', 'away', 'partial');

        if(lvl === 'focused'){
          ab.innerHTML = score !== null
            ? '&#128065; Focused <span style="opacity:.75;font-size:9px;margin-left:3px;">' + score + '%</span>'
            : '&#128065; Focused';
          ab.classList.add('show', 'focused');
        } else if(lvl === 'partial'){
          var pReason = data.faceReason || 'Partial';
          ab.innerHTML = score !== null
            ? '&#9888; ' + pReason + ' <span style="opacity:.75;font-size:9px;margin-left:3px;">' + score + '%</span>'
            : '&#9888; ' + pReason;
          ab.style.background = '';
          ab.classList.add('show', 'partial');
        } else {
          ab.innerHTML = '&#9888; ' + reason;
          ab.style.background = '';
          ab.classList.add('show', 'away');
        }
      }

      // Also sync video/avatar visibility when camOff state arrives
      if(typeof data.camOff !== 'undefined'){
        var remoteAv3   = document.getElementById('avatar_' + peerId);
        var remoteTile3 = document.getElementById('tile_' + peerId);
        var remoteVid3  = remoteTile3 ? remoteTile3.querySelector('video') : null;
        if(remoteAv3 && remoteVid3){
          remoteVid3.style.display = data.camOff ? 'none'  : 'block';
          remoteAv3.style.display  = data.camOff ? 'flex'  : 'none';
        }
      }
    }

    if(data.type === 'conn'){
      var cb = document.getElementById('conn_' + peerId);
      if(cb){
        cb.classList.remove('show', 'good', 'fair', 'poor', 'offline');
        var lvl = data.level;
        var barCounts = {good:4, fair:3, poor:2, offline:1};
        var labels    = {good:'Good', fair:'Fair', poor:'Poor', offline:'Offline'};
        var heights   = ['4px','7px','10px','13px'];
        var bars = '';
        for(var b = 0; b < 4; b++){
          bars += '<div style="width:2.5px;height:' + heights[b] + ';border-radius:1px;background:' + (b < (barCounts[lvl]||1) ? '#fff' : 'rgba(255,255,255,.3)') + '"></div>';
        }
        cb.innerHTML = '<div style="display:flex;align-items:flex-end;gap:1.5px;height:13px;">' + bars + '</div><span>' + (labels[lvl]||lvl) + '</span>';
        if(data.ping) cb.innerHTML += '<span style="opacity:.7;font-size:9px;margin-left:4px;">' + data.ping + 'ms</span>';
        cb.classList.add('show', lvl);
      }
      updateConnPanel(peerId, data.name || peerId, data.level, data.ping);
    }

    if(data.type === 'screen'){
      var peerTile = document.getElementById('tile_' + peerId);
      var grid = document.getElementById('videoGrid');
      if(peerTile && grid){
        if(data.sharing){
          peerTile.classList.add('screen-tile');
          var video = peerTile.querySelector('video');
          if(video) {
            video.classList.add('portrait');
          }
          var badge = peerTile.querySelector('.screen-badge');
          if(!badge) {
            badge = document.createElement('div');
            badge.className = 'screen-badge';
            peerTile.appendChild(badge);
          }
          badge.innerHTML = '<i class="fa fa-desktop"></i> ' + (data.name || 'Participant') + ' is sharing';
          
          var fsBtn = peerTile.querySelector('.screen-fs-btn');
          if(!fsBtn) {
            fsBtn = document.createElement('button');
            fsBtn.className = 'screen-fs-btn';
            fsBtn.innerHTML = '<i class="fa fa-expand"></i> Fullscreen';
            fsBtn.onclick = function(e){ e.stopPropagation(); enterScreenFullscreen(peerTile); };
            peerTile.appendChild(fsBtn);
          }
          
          grid.classList.add('screen-mode');
        } else {
          peerTile.classList.remove('screen-tile');
          var video = peerTile.querySelector('video');
          if(video) video.classList.remove('portrait');
          var badge = peerTile.querySelector('.screen-badge');
          if(badge) badge.remove();
          var fsBtn = peerTile.querySelector('.screen-fs-btn');
          if(fsBtn) fsBtn.remove();
          
          if(grid.querySelectorAll('.screen-tile').length === 0){
            grid.classList.remove('screen-mode');
          }
        }
        updateGrid();
      }
    }

    if(data.type === 'ppt_slide'){
      var peerTile = document.getElementById('tile_' + peerId);
      var grid = document.getElementById('videoGrid');
      if(peerTile && grid){
        if(data.sharing){
          peerTile.classList.add('screen-tile');
          var badge = peerTile.querySelector('.screen-badge');
          if(!badge) {
            badge = document.createElement('div');
            badge.className = 'screen-badge';
            peerTile.appendChild(badge);
          }
          badge.innerHTML = '<i class="fa fa-file-powerpoint-o" style="color:#f97316;"></i> ' + escHtml(data.name || 'Presenter') + ' — Slide ' + data.slide + ' of ' + data.total;
          grid.classList.add('screen-mode');
        } else {
          peerTile.classList.remove('screen-tile');
          var badge = peerTile.querySelector('.screen-badge');
          if(badge) badge.remove();
          if(grid.querySelectorAll('.screen-tile').length === 0){
            grid.classList.remove('screen-mode');
          }
        }
        updateGrid();
      }
    }
  } catch(err){}
}

function broadcastMicState(isMicOn){
  Object.keys(dataChannels).forEach(function(peerId){
    var dc = dataChannels[peerId];
    if(dc && dc.readyState === 'open') dc.send(JSON.stringify({type:'mic', muted:!isMicOn}));
  });
}

/* ── Smooth Reactions & Hand Raise System ── */
var _handRaised = false;

function toggleReactionsPopover(e){
  if(e) e.stopPropagation();
  var pop = document.getElementById('reactionsBarPopover');
  if(!pop) return;
  pop.classList.toggle('show');
}

// Close reactions popover when clicking anywhere outside
document.addEventListener('click', function(e){
  var pop = document.getElementById('reactionsBarPopover');
  var btn = document.getElementById('btnReactions');
  if(pop && pop.classList.contains('show')){
    if(!pop.contains(e.target) && (!btn || !btn.contains(e.target))){
      pop.classList.remove('show');
    }
  }
});

function sendReaction(emoji){
  _spawnFloatingReaction(emoji, MY_NAME);
  Object.keys(dataChannels).forEach(function(pid){
    var dc = dataChannels[pid];
    if(dc && dc.readyState === 'open'){
      try { dc.send(JSON.stringify({type:'reaction', emoji:emoji, name:MY_NAME})); } catch(e){}
    }
  });
  var pop = document.getElementById('reactionsBarPopover');
  if(pop) pop.classList.remove('show');
}

function _spawnFloatingReaction(emoji, senderName){
  var stage = document.getElementById('reactionsStage');
  if(!stage) return;

  var el = document.createElement('div');
  el.className = 'floating-reaction';
  // Natural horizontal distribution around center (35% to 65%)
  var rndLeft = 35 + (Math.random() * 30);
  el.style.left = rndLeft + '%';
  el.innerHTML = '<span class="reaction-emoji">' + emoji + '</span><span>' + escHtml(senderName || '') + '</span>';
  stage.appendChild(el);

  setTimeout(function(){
    if(el && el.parentElement) el.remove();
  }, 3300);
}

function toggleRaiseHand(){
  _handRaised = !_handRaised;
  var locTile = document.getElementById('tile_local');
  if(locTile) locTile.classList.toggle('hand-raised', _handRaised);

  var btn = document.getElementById('btnRaiseHand');
  if(btn) {
    btn.classList.toggle('hand-on', _handRaised);
    btn.title = _handRaised ? 'Lower Hand' : 'Raise Hand';
    btn.setAttribute('aria-label', _handRaised ? 'Lower Hand' : 'Raise Hand');
  }

  showToast(_handRaised ? '✋ You raised your hand' : 'Hand lowered', 'blue');

  Object.keys(dataChannels).forEach(function(pid){
    var dc = dataChannels[pid];
    if(dc && dc.readyState === 'open'){
      try { dc.send(JSON.stringify({type:'hand_raise', raised:_handRaised, name:MY_NAME, code:MY_CODE})); } catch(e){}
    }
  });
}

/* ── Teacher Quick Controls ── */
var _studentInteractions = {};

/* ── Controls ── */
function muteAllStudents(){
  if(!IS_TEACHER) return;
  if(!confirm('Mute all students microphones?')) return;
  var count = 0;
  Object.keys(dataChannels).forEach(function(peerId){
    var dc = dataChannels[peerId];
    if(dc && dc.readyState === 'open'){
      try {
        dc.send(JSON.stringify({type:'force_mute'}));
        count++;
      } catch(e){}
    }
  });
  showToast('Mute signal sent to all connected students.', 'green');
}

function nudgeStudents(){
  if(!IS_TEACHER) return;
  var count = 0;
  Object.keys(dataChannels).forEach(function(peerId){
    var dc = dataChannels[peerId];
    if(dc && dc.readyState === 'open'){
      try { dc.send(JSON.stringify({type:'nudge'})); count++; } catch(e){}
    }
  });
  showToast('Attention notification sent to students.', 'blue');
}

function promptClassQuestion(){
  if(!IS_TEACHER) return;
  var q = prompt('Enter a quick question / interaction prompt for the class:');
  if(!q || !q.trim()) return;
  q = q.trim();
  Object.keys(dataChannels).forEach(function(peerId){
    var dc = dataChannels[peerId];
    if(dc && dc.readyState === 'open'){
      try { dc.send(JSON.stringify({type:'question', text:q})); } catch(e){}
    }
  });
  showToast('Question sent to all students.', 'green');
}

/* ── Controls ── */
function muteAllStudents(){
  if(!IS_TEACHER) return;
  if(!confirm('Mute all students microphones?')) return;
  var count = 0;
  Object.keys(dataChannels).forEach(function(peerId){
    var dc = dataChannels[peerId];
    if(dc && dc.readyState === 'open'){
      try {
        dc.send(JSON.stringify({type:'force_mute'}));
        count++;
      } catch(e){}
    }
  });
  showToast('Mute signal sent to all connected students.', 'green');
}

/* ── Browser Autoplay Audio Unblocker ── */
function _unblockAudioOnGesture(){
  document.querySelectorAll('#videoGrid video').forEach(function(v){
    if(v.srcObject && v.paused && !v.muted){
      v.play().catch(function(){});
    }
  });
  if(typeof _audioCtx !== 'undefined' && _audioCtx && _audioCtx.state === 'suspended'){
    _audioCtx.resume().catch(function(){});
  }
}
window.addEventListener('click', _unblockAudioOnGesture, {passive: true});
window.addEventListener('touchstart', _unblockAudioOnGesture, {passive: true});
window.addEventListener('keydown', _unblockAudioOnGesture, {passive: true});

function toggleMic(){
  if(!myStream) return;
  micOn = !micOn;
  myStream.getAudioTracks().forEach(function(t){ t.enabled = micOn; });
  document.getElementById('micIcon').className = micOn ? 'fa fa-microphone' : 'fa fa-microphone-slash';
  document.getElementById('btnMic').className  = micOn ? 'ctrl-btn on' : 'ctrl-btn';
  var badge = document.getElementById('micoff_local');
  if(badge) badge.classList.toggle('show', !micOn);
  broadcastMicState(micOn);
}

function toggleCam(){
  if(!myStream) return;
  camOn = !camOn;
  myStream.getVideoTracks().forEach(function(t){ t.enabled = camOn; });
  document.getElementById('camIcon').className = camOn ? 'fa fa-video-camera' : 'fa fa-ban';
  document.getElementById('btnCam').className  = camOn ? 'ctrl-btn on' : 'ctrl-btn';

  if (camOn) {
    _startFaceDetectionLoop();
  } else {
    _stopFaceDetectionLoop();
    _lastFaceDetected = false;
    _faceReason = 'Cam Off';
  }

  // Update own local tile avatar/video visibility
  var av  = document.getElementById('avatar_local');
  var vid = document.querySelector('#tile_local video');
  if(av && vid){
    av.style.display  = camOn ? 'none'  : 'flex';
    vid.style.display = camOn ? 'block' : 'none';
  }

  // Broadcast cam state to ALL peers (teacher and student both need to see it)
  Object.keys(dataChannels).forEach(function(peerId){
    var dc = dataChannels[peerId];
    if(dc && dc.readyState === 'open'){
      try {
        // 'cam' message: drives the remote tile video/avatar switch
        dc.send(JSON.stringify({type:'cam', on:camOn, name:MY_NAME}));
        // 'attention' message: drives the away badge (students only, but harmless for teacher)
        dc.send(JSON.stringify({type:'attention', focused:camOn, camOff:!camOn, name:MY_NAME}));
      } catch(e){}
    }
  });
}

/* ── Floating Screen Share Badge Helper ── */
function showSharingBadge(title) {
  removeSharingBadge();
  var badge = document.createElement('div');
  badge.id = 'sharingFloatingBadge';
  badge.className = 'sharing-floating-badge';
  badge.title = 'Click to Stop Sharing and return to Live Class view';
  badge.innerHTML = '<span class="pulse-dot"></span>'
                  + '<i class="fa ' + (title && title.indexOf('PowerPoint') !== -1 ? 'fa-file-powerpoint-o' : 'fa-desktop') + '" style="color:' + (title && title.indexOf('PowerPoint') !== -1 ? '#f97316' : '#38bdf8') + ';"></i>'
                  + '<span>' + escHtml(title || 'Sharing Screen') + '</span>'
                  + '<span class="stop-btn-tag"><i class="fa fa-stop"></i> Stop</span>';
  badge.onclick = function() {
    stopScreen();
  };
  document.body.appendChild(badge);
}

function removeSharingBadge() {
  var existing = document.getElementById('sharingFloatingBadge');
  if(existing) existing.remove();
}

function toggleScreen(){
  if(!screenOn){
    navigator.mediaDevices.getDisplayMedia({
      video: {
        width: { max: 1920, ideal: 1280 },
        height: { max: 1080, ideal: 720 },
        frameRate: { max: 15, ideal: 10 }
      },
      audio: false
    }).then(function(stream){
      screenStream = stream;
      screenOn = true;
      var bScr = document.getElementById('btnScreen'); if(bScr) bScr.className = 'ctrl-btn on';
      var sIco = document.getElementById('screenIcon'); if(sIco) sIco.className = 'fa fa-desktop';

      showSharingBadge('Sharing Screen');

      var grid = document.getElementById('videoGrid');
      var tile = document.createElement('div');
      tile.className = 'video-tile screen-tile';
      tile.id = 'tile_screen';

      var vid = document.createElement('video');
      vid.setAttribute('autoplay', '');
      vid.setAttribute('playsinline', '');
      vid.setAttribute('muted', '');
      vid.muted = true;
      vid.style.display = 'none';
      tile.appendChild(vid);

      var placeholder = document.createElement('div');
      placeholder.style.cssText = 'display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;width:100%;background:#0b0f19;color:#fff;gap:12px;padding:20px;text-align:center;position:absolute;inset:0;z-index:2;';
      placeholder.innerHTML = '<div style="width:48px;height:48px;border-radius:50%;background:rgba(56,189,248,.12);display:flex;align-items:center;justify-content:center;color:#38bdf8;font-size:20px;margin-bottom:4px;"><i class="fa fa-desktop"></i></div>'
                            + '<h4 style="margin:0;font-size:14px;font-weight:700;">You are presenting to everyone</h4>'
                            + '<p style="margin:0;font-size:11px;color:#94a3b8;max-width:260px;line-height:1.4;">To prevent an infinite screen loop, your own preview is hidden here. All other participants can see your screen.</p>'
                            + '<button class="btn-lc red sm" onclick="stopScreen()" style="margin-top:6px;padding:5px 12px;font-size:11px;border-radius:6px;font-weight:600;"><i class="fa fa-stop"></i> Stop Presenting</button>';
      tile.appendChild(placeholder);

      grid.insertBefore(tile, grid.firstChild);
      grid.classList.add('screen-mode');
      updateGrid();

      vid.srcObject = stream;
      vid.play().catch(function(){});

      var screenTrack = stream.getVideoTracks()[0];
      Object.values(peers).forEach(function(call){
        var sender = call.peerConnection.getSenders().find(function(s){ return s.track && s.track.kind === 'video'; });
        if(sender) sender.replaceTrack(screenTrack);
      });
      screenTrack.onended = function(){ stopScreen(); };

      Object.keys(dataChannels).forEach(function(peerId){
        var dc = dataChannels[peerId];
        if(dc && dc.readyState === 'open'){
          try { dc.send(JSON.stringify({type:'screen', sharing:true, name:MY_NAME})); } catch(e){}
        }
      });
    }).catch(function(e){
      screenOn = false;
      if(e && (e.name === 'NotAllowedError' || e.name === 'AbortError')){
        showToast('Screen sharing cancelled', 'blue');
      } else {
        console.warn('[screen share]', e);
      }
    });
  } else {
    stopScreen();
  }
}

function stopScreen(){
  removeSharingBadge();

  if(screenStream){
    screenStream.getTracks().forEach(function(t){ t.stop(); });
    screenStream = null;
  }
  screenOn = false;
  var bScr = document.getElementById('btnScreen'); if(bScr) bScr.className = 'ctrl-btn';
  var sIco = document.getElementById('screenIcon'); if(sIco) sIco.className = 'fa fa-desktop';
  var btnPpt = document.getElementById('btnPpt'); if(btnPpt) btnPpt.className = 'ctrl-btn';

  var screenTile = document.getElementById('tile_screen');
  if(screenTile) screenTile.remove();

  var pptTile = document.getElementById('tile_ppt');
  if(pptTile) pptTile.remove();
  pptState.active = false;
  pptState.pdfDoc = null;

  var grid = document.getElementById('videoGrid');
  if(grid) grid.classList.remove('screen-mode');

  if(myStream){
    var camTrack = myStream.getVideoTracks()[0];
    if(camTrack){
      Object.values(peers).forEach(function(call){
        var sender = call.peerConnection.getSenders().find(function(s){
          return s.track && s.track.kind === 'video';
        });
        if(!sender) return;
        if(sender.track !== camTrack){
          sender.replaceTrack(camTrack).catch(function(e){ console.warn('[stopScreen replaceTrack]', e); });
        }
      });
    }
  }

  /* Broadcast screen sharing stop state */
  Object.keys(dataChannels).forEach(function(peerId){
    var dc = dataChannels[peerId];
    if(dc && dc.readyState === 'open'){
      try { dc.send(JSON.stringify({type:'screen', sharing:false})); } catch(e){}
    }
  });

  updateGrid();
  try { window.focus(); } catch(e){}
  showToast('Returned to Live Class view', 'green');
}

/* ── BUG 11 FIX: when a new peer joins while screen share is active,
   send them the screen track immediately instead of the camera track ── */
function _applyScreenTrackToPeer(call){
  if(!screenOn || !screenStream) return;
  var screenTrack = screenStream.getVideoTracks()[0];
  if(!screenTrack) return;
  var sender = call.peerConnection.getSenders().find(function(s){
    return s.track && s.track.kind === 'video';
  });
  if(sender && sender.track !== screenTrack){
    sender.replaceTrack(screenTrack).catch(function(e){ console.warn('[_applyScreenTrackToPeer]', e); });
  }
}

/* ── PowerPoint / Presentation Share & Sync ── */
var CLASS_MODULES = <?php echo json_encode($pptModules); ?>;
var pptState = { active: false, title: '', currentSlide: 1, totalSlides: 1, pdfDoc: null, isPresenter: false };

function openPptModal() {
  var listEl = document.getElementById('pptMaterialsList');
  if(listEl) {
    if(!CLASS_MODULES || CLASS_MODULES.length === 0) {
      listEl.innerHTML = '<div style="padding:16px;text-align:center;color:#94a3b8;font-size:12px;"><i class="fa fa-info-circle"></i> No uploaded modules for this class yet. Upload PowerPoint or PDF materials in Class View.</div>';
    } else {
      var html = '';
      CLASS_MODULES.forEach(function(m) {
        var fname = m.filename || m.file_name || '';
        var isPdf = fname.toLowerCase().endsWith('.pdf');
        var fileUrl = '../uploads/modules/' + fname;
        html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-bottom:1px solid #334155;gap:10px;">'
          + '<div style="display:flex;align-items:center;gap:8px;min-width:0;">'
          + '<i class="fa ' + (isPdf ? 'fa-file-pdf-o' : 'fa-file-powerpoint-o') + '" style="color:' + (isPdf ? '#ef4444' : '#ea580c') + ';font-size:16px;"></i>'
          + '<div style="font-size:12px;color:#f8fafc;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(m.title || m.original_name) + '</div>'
          + '</div>'
          + '<button class="btn-lc accent sm" onclick="closeModal(\'pptModal\'); startPptFromUrl(\'' + escHtml(fileUrl) + '\', \'' + escHtml(m.title || m.original_name) + '\')" style="background:linear-gradient(135deg,#0284c7,#0369a1);border:none;">'
          + '<i class="fa fa-play"></i> Present'
          + '</button>'
          + '</div>';
      });
      listEl.innerHTML = html;
    }
  }
  openModal('pptModal');
}

function launchPowerPointScreenShare() {
  if(navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia) {
    navigator.mediaDevices.getDisplayMedia({
      video: {
        displaySurface: "window",
        width: { max: 1920, ideal: 1280 },
        height: { max: 1080, ideal: 720 }
      },
      audio: false
    }).then(function(stream){
      screenStream = stream;
      screenOn = true;
      var bScr = document.getElementById('btnScreen'); if(bScr) bScr.className = 'ctrl-btn on';
      var bPpt = document.getElementById('btnPpt'); if(bPpt) bPpt.className = 'ctrl-btn on';

      showSharingBadge('Sharing PowerPoint Window');

      var grid = document.getElementById('videoGrid');
      var tile = document.createElement('div');
      tile.className = 'video-tile screen-tile';
      tile.id = 'tile_screen';

      var vid = document.createElement('video');
      vid.setAttribute('autoplay', '');
      vid.setAttribute('playsinline', '');
      vid.setAttribute('muted', '');
      vid.muted = true;
      vid.style.display = 'none';
      tile.appendChild(vid);

      var placeholder = document.createElement('div');
      placeholder.style.cssText = 'display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;width:100%;background:#0b0f19;color:#fff;gap:12px;padding:20px;text-align:center;position:absolute;inset:0;z-index:2;';
      placeholder.innerHTML = '<div style="width:52px;height:52px;border-radius:50%;background:rgba(249,115,22,.15);display:flex;align-items:center;justify-content:center;color:#f97316;font-size:22px;margin-bottom:4px;"><i class="fa fa-file-powerpoint-o"></i></div>'
                            + '<h4 style="margin:0;font-size:14px;font-weight:700;">Presenting PowerPoint Window</h4>'
                            + '<p style="margin:0;font-size:11px;color:#94a3b8;max-width:280px;line-height:1.4;">PowerPoint window is active. All participants are watching your slides in real-time.</p>'
                            + '<button class="btn-lc red sm" onclick="stopScreen()" style="margin-top:6px;padding:5px 12px;font-size:11px;border-radius:6px;font-weight:600;"><i class="fa fa-stop"></i> Stop Presenting</button>';
      tile.appendChild(placeholder);

      grid.insertBefore(tile, grid.firstChild);
      grid.classList.add('screen-mode');

      vid.srcObject = stream;
      vid.play().catch(function(){});

      var screenTrack = stream.getVideoTracks()[0];
      Object.values(peers).forEach(function(call){
        var sender = call.peerConnection.getSenders().find(function(s){ return s.track && s.track.kind === 'video'; });
        if(sender) sender.replaceTrack(screenTrack);
      });
      screenTrack.onended = function(){ stopScreen(); };

      Object.keys(dataChannels).forEach(function(peerId){
        var dc = dataChannels[peerId];
        if(dc && dc.readyState === 'open'){
          try { dc.send(JSON.stringify({type:'screen', sharing:true, name:MY_NAME + ' (PowerPoint)'})); } catch(e){}
        }
      });
    }).catch(function(e){
      screenOn = false;
      if(e && (e.name === 'NotAllowedError' || e.name === 'AbortError')){
        showToast('Presentation cancelled', 'blue');
      } else {
        console.warn('[ppt screen share]', e);
      }
    });
  } else {
    alert('Screen sharing is not supported on this device/browser.');
  }
}

function handleLocalPptSelect(input) {
  if(!input.files || !input.files.length) return;
  var file = input.files[0];
  closeModal('pptModal');
  if(file.name.endsWith('.pdf')) {
    var url = URL.createObjectURL(file);
    startPptFromUrl(url, file.name);
  } else {
    launchPowerPointScreenShare();
  }
}

function startPptFromUrl(url, title) {
  pptState.active = true;
  pptState.title = title || 'Presentation';
  pptState.currentSlide = 1;
  pptState.isPresenter = true;

  showSharingBadge('Presenting: ' + pptState.title);

  if (typeof pdfjsLib !== 'undefined') {
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    pdfjsLib.getDocument(url).promise.then(function(pdfDoc) {
      pptState.pdfDoc = pdfDoc;
      pptState.totalSlides = pdfDoc.numPages;
      renderPptTile();
      renderPptSlide(1);
    }).catch(function() {
      launchPowerPointScreenShare();
    });
  } else {
    launchPowerPointScreenShare();
  }
}

function renderPptTile() {
  var grid = document.getElementById('videoGrid');
  var existing = document.getElementById('tile_ppt');
  if(existing) existing.remove();

  var tile = document.createElement('div');
  tile.className = 'video-tile screen-tile';
  tile.id = 'tile_ppt';
  tile.style.cssText = 'position:relative;background:#0f172a;display:flex;flex-direction:column;align-items:center;justify-content:center;overflow:hidden;';

  var canvas = document.createElement('canvas');
  canvas.id = 'pptCanvas';
  canvas.style.cssText = 'max-width:100%;max-height:calc(100% - 50px);object-fit:contain;box-shadow:0 10px 30px rgba(0,0,0,.5);';
  tile.appendChild(canvas);

  var bar = document.createElement('div');
  bar.style.cssText = 'position:absolute;bottom:10px;left:50%;transform:translateX(-50%);background:rgba(15,23,42,.92);border:1px solid #334155;border-radius:30px;padding:6px 16px;display:flex;align-items:center;gap:12px;z-index:20;backdrop-filter:blur(10px);box-shadow:0 8px 24px rgba(0,0,0,.4);';
  bar.innerHTML = '<button onclick="prevPptSlide()" style="background:none;border:none;color:#f8fafc;font-size:14px;cursor:pointer;padding:4px 8px;"><i class="fa fa-chevron-left"></i></button>'
                + '<span id="pptSlideBadge" style="font-size:12px;font-weight:700;color:#38bdf8;">Slide 1 / ' + pptState.totalSlides + '</span>'
                + '<button onclick="nextPptSlide()" style="background:none;border:none;color:#f8fafc;font-size:14px;cursor:pointer;padding:4px 8px;"><i class="fa fa-chevron-right"></i></button>'
                + '<div style="width:1px;height:16px;background:#334155;"></div>'
                + '<span style="font-size:11px;color:#cbd5e1;font-weight:600;"><i class="fa fa-file-powerpoint-o" style="color:#f97316;"></i> ' + escHtml(pptState.title) + '</span>'
                + '<button onclick="stopPptPresentation()" style="background:#ef4444;color:#fff;border:none;border-radius:20px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;margin-left:6px;"><i class="fa fa-stop"></i> Stop</button>';
  tile.appendChild(bar);

  grid.insertBefore(tile, grid.firstChild);
  grid.classList.add('screen-mode');
  updateGrid();
}

function renderPptSlide(num) {
  if(!pptState.pdfDoc) return;
  pptState.currentSlide = num;
  var badge = document.getElementById('pptSlideBadge');
  if(badge) badge.textContent = 'Slide ' + num + ' / ' + pptState.totalSlides;

  pptState.pdfDoc.getPage(num).then(function(page) {
    var canvas = document.getElementById('pptCanvas');
    if(!canvas) return;
    var ctx = canvas.getContext('2d');
    var viewport = page.getViewport({ scale: 1.5 });
    canvas.height = viewport.height;
    canvas.width  = viewport.width;

    page.render({ canvasContext: ctx, viewport: viewport }).promise.then(function() {
      if(pptState.isPresenter && canvas.captureStream) {
        var canvasStream = canvas.captureStream(10);
        var pptTrack = canvasStream.getVideoTracks()[0];
        if(pptTrack) {
          Object.values(peers).forEach(function(call) {
            var sender = call.peerConnection.getSenders().find(function(s){ return s.track && s.track.kind === 'video'; });
            if(sender) sender.replaceTrack(pptTrack);
          });
        }
      }

      Object.keys(dataChannels).forEach(function(peerId){
        var dc = dataChannels[peerId];
        if(dc && dc.readyState === 'open'){
          try { dc.send(JSON.stringify({type:'ppt_slide', sharing:true, slide:num, total:pptState.totalSlides, title:pptState.title, name:MY_NAME})); } catch(e){}
        }
      });
    });
  });
}

function prevPptSlide() {
  if(pptState.currentSlide > 1) {
    renderPptSlide(pptState.currentSlide - 1);
  }
}

function nextPptSlide() {
  if(pptState.currentSlide < pptState.totalSlides) {
    renderPptSlide(pptState.currentSlide + 1);
  }
}

function stopPptPresentation() {
  pptState.active = false;
  pptState.pdfDoc = null;
  var tile = document.getElementById('tile_ppt');
  if(tile) tile.remove();
  var grid = document.getElementById('videoGrid');
  if(grid && grid.querySelectorAll('.screen-tile').length === 0) {
    grid.classList.remove('screen-mode');
  }

  if(myStream) {
    var camTrack = myStream.getVideoTracks()[0];
    if(camTrack) {
      Object.values(peers).forEach(function(call){
        var sender = call.peerConnection.getSenders().find(function(s){ return s.track && s.track.kind === 'video'; });
        if(sender) sender.replaceTrack(camTrack).catch(function(){});
      });
    }
  }

  Object.keys(dataChannels).forEach(function(peerId){
    var dc = dataChannels[peerId];
    if(dc && dc.readyState === 'open'){
      try { dc.send(JSON.stringify({type:'ppt_slide', sharing:false})); } catch(e){}
    }
  });
  updateGrid();
}

/* ── Screen share fullscreen ─────────────────────────────────────────────── */
function enterScreenFullscreen(tileEl){
  var tile = tileEl || document.querySelector('.screen-tile');
  if(!tile) return;
  var req = tile.requestFullscreen || tile.webkitRequestFullscreen
         || tile.mozRequestFullScreen || tile.msRequestFullscreen;
  if(req){ req.call(tile); }
}

function exitScreenFullscreen(){
  var exit = document.exitFullscreen || document.webkitExitFullscreen
          || document.mozCancelFullScreen || document.msExitFullscreen;
  if(exit){ exit.call(document); }
}

// Show/hide the overlay exit button when fullscreen state changes
document.addEventListener('fullscreenchange',       _onFsChange);
document.addEventListener('webkitfullscreenchange', _onFsChange);
document.addEventListener('mozfullscreenchange',    _onFsChange);
document.addEventListener('MSFullscreenChange',     _onFsChange);

function _onFsChange(){
  var fsEl  = document.fullscreenElement || document.webkitFullscreenElement
           || document.mozFullScreenElement || document.msFullscreenElement;
  var exitBtn = document.getElementById('screenFsExit');
  var fsBtn   = document.getElementById('screenFsBtn');
  if(fsEl && fsEl.classList.contains('screen-tile')){
    // Entered fullscreen — show overlay exit button, update fullscreen btn icon
    if(exitBtn) exitBtn.classList.add('show');
    if(fsBtn)   fsBtn.innerHTML = '<i class="fa fa-compress"></i> Exit';
  } else {
    // Left fullscreen
    if(exitBtn) exitBtn.classList.remove('show');
    if(fsBtn)   fsBtn.innerHTML = '<i class="fa fa-expand"></i> Fullscreen';
  }
}

function leaveCall(){
  if(IS_TEACHER){
    if(!confirm('End the live session for everyone?')) return;
    if(currentSessionId){
      // BUG 12 FIX: added .fail() so teacher is not stuck if AJAX fails
      $.post('live_handler.php', {action:'end', session_id:currentSessionId}, function(){
        stopCall(); location.reload();
      }, 'json').fail(function(){
        showToast('Network error ending session. Please try again.', 'red');
      });
    } else {
      stopCall(); location.reload();
    }
  } else {
    // BUG 3 FIX: only send leave once here; removed duplicate beacon from stopCall path
    // We pass the session ID before stopCall() nulls it
    var sid = currentSessionId;
    if(sid) $.post('live_handler.php', {action:'leave', session_id:sid}, function(){});
    stopCall();
    location.reload();
  }
}

/* ── beforeunload: confirm dialog only, do NOT stop call ── */
var _leavingByUnload = false;
window.addEventListener('beforeunload', function(e){
  if(inCall){
    e.preventDefault();
    e.returnValue = 'You are in a live session. Are you sure you want to leave?';
    // BUG 3 FIX: set flag so stopCall beacon fires only on actual page unload
    _leavingByUnload = true;
    if(!IS_TEACHER && currentSessionId){
      navigator.sendBeacon('live_handler.php', new URLSearchParams({action:'leave', session_id:currentSessionId}));
    }
    return e.returnValue;
  }
});
</script>
<script>
/* ══════════════════════════════════════════════════════════════════════════
   FOCUS DETECTION — works even when camera is OFF
   ══════════════════════════════════════════════════════════════════════════
   Signals used to compute a 0–100 engagement score:

   Signal              Weight   How detected
   ─────────────────── ──────   ──────────────────────────────────────────
   Tab visible            40    document.visibilityState / visibilitychange
   Window focused         20    window blur / focus events
   Recent interaction     25    mousemove, keydown, click, scroll, touch
                                (tiered: <15s=25, <30s=18, <60s=10, else 0)
   Mic audio activity     15    AudioContext RMS analysis on mic stream
   ─────────────────────────
   Total possible        100

   Score thresholds:
     75–100 → Focused  (green)
     40–74  → Partial  (yellow)
     0–39   → Away     (red)

   Broadcast: every 15 s + immediately on any state change (debounced 300ms)
   ══════════════════════════════════════════════════════════════════════════ */

var _awayBroadcastTimer = null;
// Named handler refs so they can be cleanly removed on stopCall (BUG 6 FIX)
var _visibilityHandler = null;
var _blurHandler       = null;
var _focusHandler      = null;
var _mousemoveHandler  = null;
var _mousedownHandler  = null;
var _keydownHandler    = null;
var _touchstartHandler = null;
var _scrollHandler     = null;
var _clickHandler      = null;

// Engagement state
var _engagementScore  = 100;
var _engagementTimer  = null;
var _lastInteraction  = Date.now();
var _tabVisible       = true;
var _windowFocused    = true;
// IDLE_SECS: seconds of no mouse/key/touch before marking as idle
// Used consistently in _computeEngagement — was declared but not referenced before
var IDLE_SECS         = 45; // 45s idle = start losing idle score

// Audio monitor state
var _audioCtx         = null;
var _audioAnalyser    = null;
var _audioSource      = null;
var _audioActive      = false;
var _audioCheckTimer  = null;

/* ── Audio monitor: detect voice/sound on the student's mic ── */
function _startAudioMonitor(stream){
  if(!stream) return;
  try {
    _audioCtx      = new (window.AudioContext || window.webkitAudioContext)();
    _audioAnalyser = _audioCtx.createAnalyser();
    _audioAnalyser.fftSize = 256;
    _audioSource   = _audioCtx.createMediaStreamSource(stream);
    _audioSource.connect(_audioAnalyser);
    var buf = new Uint8Array(_audioAnalyser.frequencyBinCount);
    var THRESHOLD = 35; // RMS above this = voice/sound (raised from 18 to avoid ambient noise false positives)
    _audioCheckTimer = setInterval(function(){
      if(!inCall){ _audioActive = false; return; }
      _audioAnalyser.getByteFrequencyData(buf);
      var sum = 0;
      for(var i = 0; i < buf.length; i++) sum += buf[i];
      _audioActive = (sum / buf.length) > THRESHOLD;
    }, 500);
  } catch(e){ console.warn('[audioMonitor]', e); }
}

function _stopAudioMonitor(){
  _audioActive = false;
  if(_audioCheckTimer){ clearInterval(_audioCheckTimer); _audioCheckTimer = null; }
  try { if(_audioSource)  _audioSource.disconnect(); }  catch(e){}
  try { if(_audioCtx)     _audioCtx.close(); }          catch(e){}
  _audioCtx = null; _audioAnalyser = null; _audioSource = null;
}

/* ── Compute 0–100 engagement score from all signals ── */
/* Score breakdown (total possible = 100):
   Tab visible   : 35 pts  (was 40 — reduced to give audio & interaction more weight)
   Window focused: 15 pts  (was 20 — many students use fullscreen, blur doesn’t mean absent)
   Interaction   : 30 pts  (was 25 — most reliable active-presence signal)
   Audio/voice   : 20 pts  (was 15 — speaking = strong presence signal)

   Interaction tiers use IDLE_SECS:
     < 15s : 30 pts (very active)
     < IDLE_SECS/2 (22s): 20 pts (recently active)
     < IDLE_SECS (45s): 10 pts (somewhat idle)
     ≥ IDLE_SECS : 0 pts (idle)

   A fully-present student (tab visible, window focused, recent interaction, mic active)
   scores 35+15+30+20 = 100 → Focused (green).
   A quiet-but-present student (tab+window, no interaction, muted) scores 35+15+0+8 = 58 → Partial (yellow).
   A tabbed-away student scores ≤0+0+0+0 = 0 → Away (red).
*/
var _lastFaceDetected = true;
var _faceReason = '';
var _faceCheckTimer = null;
var _faceCanvas = null;
var _faceCtx = null;
var _consecutiveAwayFrames = 0;

/* ── Real-time Webcam Face & Focus Detection Loop ── */
function _startFaceDetectionLoop() {
  _stopFaceDetectionLoop();

  if (!_faceCanvas) {
    _faceCanvas = document.createElement('canvas');
    _faceCanvas.width = 160;
    _faceCanvas.height = 120;
    _faceCtx = _faceCanvas.getContext('2d', { willReadFrequently: true });
  }

  _faceCheckTimer = setInterval(function() {
    if (!inCall || !camOn || IS_TEACHER) return;
    var vid = document.querySelector('#tile_local video');
    if (!vid || vid.paused || vid.ended || vid.readyState < 2) return;

    try {
      _faceCtx.drawImage(vid, 0, 0, 160, 120);
      var imgData = _faceCtx.getImageData(0, 0, 160, 120);
      var data = imgData.data;
      var totalPixels = 160 * 120;

      var skinPixels = 0;
      var leftFacePixels = 0;
      var rightFacePixels = 0;

      // Skin-tone & face feature analysis in YCbCr / RGB space
      for (var i = 0; i < data.length; i += 4) {
        var r = data[i];
        var g = data[i+1];
        var b = data[i+2];

        var isSkin = (r > 60 && g > 35 && b > 20 && 
                      (Math.max(r,g,b) - Math.min(r,g,b) > 12) && 
                      Math.abs(r - g) > 12 && r > g && r > b);

        if (isSkin) {
          skinPixels++;
          var pxIndex = i / 4;
          var x = pxIndex % 160;
          if (x < 80) leftFacePixels++;
          else rightFacePixels++;
        }
      }

      var skinRatio = skinPixels / totalPixels;

      if (skinRatio < 0.035) {
        _consecutiveAwayFrames++;
        if (_consecutiveAwayFrames >= 2) {
          _lastFaceDetected = false;
          _faceReason = 'No Face Detected';
        }
      } else {
        var lrRatio = (leftFacePixels > 0 && rightFacePixels > 0) 
          ? Math.min(leftFacePixels, rightFacePixels) / Math.max(leftFacePixels, rightFacePixels)
          : 0;

        if (lrRatio < 0.35) {
          _consecutiveAwayFrames++;
          if (_consecutiveAwayFrames >= 2) {
            _lastFaceDetected = false;
            _faceReason = 'Looking Away';
          }
        } else {
          _consecutiveAwayFrames = 0;
          _lastFaceDetected = true;
          _faceReason = '';
        }
      }

      // Query Python ML API /detect_face endpoint for high-accuracy OpenCV AI analysis
      var base64Img = _faceCanvas.toDataURL('image/jpeg', 0.5);
      var apiEndpoint = 'http://127.0.0.1:5001/detect_face';

      fetch(apiEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          image: base64Img,
          tab_visible: _tabVisible,
          window_focused: _windowFocused,
          mic_active: (micOn && _audioActive)
        })
      }).then(function(r){ return r.json(); }).then(function(res){
        if (res && res.success) {
          _lastFaceDetected = res.face_detected;
          _faceReason = res.face_reason;
        }
        _broadcastEngagement();
      }).catch(function(){
        // Fallback to client-side detection if Python API is offline
        _broadcastEngagement();
      });
    } catch (e) {
      console.warn('[FaceDetect]', e);
    }
  }, 2000);
}

function _stopFaceDetectionLoop() {
  if (_faceCheckTimer) {
    clearInterval(_faceCheckTimer);
    _faceCheckTimer = null;
  }
}

function _computeEngagement(){
  var score = 0;
  var secs = (Date.now() - _lastInteraction) / 1000;
  
  if(camOn){
    // Camera is ON: face detection is the main signal (40 pts)
    if(_tabVisible)    score += 25;
    if(_windowFocused) score += 15;
    
    if(_lastFaceDetected) {
      score += 40;
    } else if(_faceReason === 'Looking Away') {
      score += 15;
    } else {
      score += 0;
    }
    
    // Interaction/Voice: up to 20 pts
    if(secs < 20) score += 20;
    else if(secs < IDLE_SECS) score += 10;
    
    if(micOn && _audioActive) score += 10;
  } else {
    // Camera is OFF: fallback to tab/window/interaction
    if(_tabVisible)    score += 35;
    if(_windowFocused) score += 15;
    
    if(secs < 15)               score += 30;
    else if(secs < IDLE_SECS/2) score += 20;
    else if(secs < IDLE_SECS)   score += 10;
    
    if(micOn && _audioActive)                                  score += 20;
    else if(!micOn && _tabVisible && _windowFocused)           score += 8;
  }
  
  return Math.min(100, Math.max(0, score));
}

/* ── Broadcast full engagement payload — to teacher data channel only ── */
function _broadcastEngagement(){
  if(!inCall) return;
  var score = _computeEngagement();
  _engagementScore = score;

  var level;
  if(!_tabVisible || !_windowFocused) level = 'away';
  else if(score >= 75)                level = 'focused';
  else if(score >= 40)                level = 'partial';
  else                                level = 'away';

  var dc = dataChannels[currentRoomId];
  if(dc && dc.readyState === 'open'){
    try {
      dc.send(JSON.stringify({
        type       : 'attention',
        focused    : level !== 'away',
        level      : level,
        score      : score,
        camOff     : !camOn,
        tabVisible : _tabVisible,
        winFocused : _windowFocused,
        audioActive: micOn && _audioActive,
        faceReason : camOn ? _faceReason : '',
        name       : MY_NAME
      }));
    } catch(e){}
  }
}

function startActivityDetection(){
  if(IS_TEACHER) return;

  _tabVisible    = !document.hidden;
  _windowFocused = document.hasFocus();
  _lastInteraction = Date.now();

  /* State-change handlers — fire immediately on change */
  /* State-change handlers — fire asynchronously on change */
  _visibilityHandler = function(){
    if(!inCall) return;
    requestAnimationFrame(function(){
      _tabVisible = !document.hidden;
      clearTimeout(_awayBroadcastTimer);
      _awayBroadcastTimer = setTimeout(_broadcastEngagement, 300);
    });
  };
  _blurHandler = function(){
    if(!inCall) return;
    requestAnimationFrame(function(){
      _windowFocused = false;
      clearTimeout(_awayBroadcastTimer);
      _awayBroadcastTimer = setTimeout(_broadcastEngagement, 300);
    });
  };
  _focusHandler = function(){
    if(!inCall) return;
    requestAnimationFrame(function(){
      _windowFocused = true;
      clearTimeout(_awayBroadcastTimer);
      _awayBroadcastTimer = setTimeout(_broadcastEngagement, 300);
    });
  };

  /* Interaction handlers — reset the idle timer */
  function _resetActivity(){
    _lastInteraction = Date.now();
  }
  _mousemoveHandler  = _resetActivity;
  _mousedownHandler  = _resetActivity;
  _keydownHandler    = _resetActivity;
  _touchstartHandler = _resetActivity;
  _scrollHandler     = _resetActivity;
  _clickHandler      = _resetActivity;

  document.addEventListener('visibilitychange', _visibilityHandler);
  window.addEventListener('blur',       _blurHandler, {passive: true});
  window.addEventListener('focus',      _focusHandler, {passive: true});
  document.addEventListener('mousemove',  _mousemoveHandler, {passive: true});
  document.addEventListener('mousedown',  _mousedownHandler, {passive: true});
  document.addEventListener('keydown',    _keydownHandler, {passive: true});
  document.addEventListener('touchstart', _touchstartHandler, {passive: true});
  document.addEventListener('scroll',     _scrollHandler, {passive: true, capture: true});
  document.addEventListener('click',      _clickHandler, {passive: true});

  /* Start audio monitor & real-time face detection on student webcam */
  if(myStream) {
    _startAudioMonitor(myStream);
    _startFaceDetectionLoop();
  }

  /* Periodic broadcast every 15 s */
  _engagementTimer = setInterval(function(){
    if(!inCall) return;
    _broadcastEngagement();
  }, 15000);
}

var _idleInterval = null;
function stopActivityDetection(){
  if(_idleInterval)    { clearInterval(_idleInterval);    _idleInterval    = null; }
  if(_engagementTimer) { clearInterval(_engagementTimer); _engagementTimer = null; }
  _stopAudioMonitor();
  _stopFaceDetectionLoop();
  if(_visibilityHandler){ document.removeEventListener('visibilitychange', _visibilityHandler); _visibilityHandler = null; }
  if(_blurHandler)      { window.removeEventListener('blur',  _blurHandler);  _blurHandler  = null; }
  if(_focusHandler)     { window.removeEventListener('focus', _focusHandler); _focusHandler = null; }
  if(_mousemoveHandler) { document.removeEventListener('mousemove',  _mousemoveHandler);  _mousemoveHandler  = null; }
  if(_mousedownHandler) { document.removeEventListener('mousedown',  _mousedownHandler);  _mousedownHandler  = null; }
  if(_keydownHandler)   { document.removeEventListener('keydown',    _keydownHandler);    _keydownHandler    = null; }
  if(_touchstartHandler){ document.removeEventListener('touchstart', _touchstartHandler); _touchstartHandler = null; }
  if(_scrollHandler)    { document.removeEventListener('scroll',     _scrollHandler, true); _scrollHandler   = null; }
  if(_clickHandler)     { document.removeEventListener('click',      _clickHandler);      _clickHandler      = null; }
}

/* Legacy wrapper — delegates to full engagement broadcast */
function broadcastAttention(isFocused){
  _broadcastEngagement();
}

/* ── Connectivity monitor (students) ── */
var _connInterval = null, _lastConnLevel = null;
// BUG 7 FIX: store named handler references so they can be removed on stopCall
var _offlineHandler = null;
var _onlineHandler  = null;
var _connChangeHandler = null;
var _connChangeTarget  = null;

function startConnMonitor(){
  if(IS_TEACHER) return;
  _offlineHandler = function(){ updateConnUI('offline', null); broadcastConn('offline', null); };
  _onlineHandler  = function(){ checkConn(); };
  window.addEventListener('offline', _offlineHandler);
  window.addEventListener('online',  _onlineHandler);
  var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
  if(conn){
    _connChangeHandler = function(){ checkConn(); };
    _connChangeTarget  = conn;
    conn.addEventListener('change', _connChangeHandler);
  }
  // Initial check after 2s, then every 8s (more responsive checkConn loop)
  setTimeout(function(){
    checkConn();
    _connInterval = setInterval(checkConn, 8000);
  }, 2000);
}
function stopConnMonitor(){
  if(_connInterval){ clearInterval(_connInterval); _connInterval = null; }
  // BUG 7 FIX: remove all registered listeners to prevent accumulation on rejoin
  if(_offlineHandler){ window.removeEventListener('offline', _offlineHandler); _offlineHandler = null; }
  if(_onlineHandler) { window.removeEventListener('online',  _onlineHandler);  _onlineHandler  = null; }
  if(_connChangeHandler && _connChangeTarget){
    _connChangeTarget.removeEventListener('change', _connChangeHandler);
    _connChangeHandler = null;
    _connChangeTarget  = null;
  }
}

/* ── WebRTC stats helper for packet loss and media latency ── */
async function getWebRTCStats(){
  var pcList = Object.values(peers).map(function(c){ return c.peerConnection; }).filter(Boolean);
  if(!pcList.length) return null;
  
  var totalLossDelta = 0;
  var totalPacketsDelta = 0;
  var totalRtt = 0;
  var rttCount = 0;
  
  for(var i = 0; i < pcList.length; i++){
    var pc = pcList[i];
    var peerId = Object.keys(peers).find(function(key){ return peers[key].peerConnection === pc; });
    if(!peerId) continue;
    
    try {
      var stats = await pc.getStats();
      stats.forEach(function(report){
        if(report.type === 'candidate-pair' && report.state === 'succeeded'){
          if(typeof report.currentRoundTripTime === 'number'){
            totalRtt += report.currentRoundTripTime * 1000;
            rttCount++;
          }
        }
        if(report.type === 'inbound-rtp' && report.kind === 'video'){
          if(typeof report.packetsLost === 'number' && typeof report.packetsReceived === 'number'){
            var key = peerId + '_' + report.ssrc;
            var prev = _prevStats[key] || { lost: 0, received: 0 };
            
            var lostDelta = Math.max(0, report.packetsLost - prev.lost);
            var recDelta = Math.max(0, report.packetsReceived - prev.received);
            
            totalLossDelta += lostDelta;
            totalPacketsDelta += (recDelta + lostDelta);
            
            // Cache current values for delta calculation on the next interval check
            _prevStats[key] = { lost: report.packetsLost, received: report.packetsReceived };
          }
        }
      });
    } catch(e){}
  }
  
  return {
    rtt: rttCount > 0 ? Math.round(totalRtt / rttCount) : null,
    lossRate: totalPacketsDelta > 0 ? (totalLossDelta / totalPacketsDelta) : 0
  };
}

/* ── Ping server — averaged over N samples to reduce noise spikes ── */
async function pingServer(){
  var SAMPLES = 3;   // average 3 pings to smooth out transient spikes
  var TIMEOUT = 5000; // 5s hard timeout per request
  var total = 0;
  var count = 0;
  for(var i = 0; i < SAMPLES; i++){
    var controller = new AbortController();
    var tid = setTimeout(function(){ controller.abort(); }, TIMEOUT);
    try {
      var start = Date.now();
      await fetch('live_handler.php?action=ping&_=' + start, {
        method: 'GET', cache: 'no-store', signal: controller.signal
      });
      total += Date.now() - start;
      count++;
    } catch(e){
      // aborted or network error — count as a miss
    } finally {
      clearTimeout(tid);
    }
    // Small gap between samples so they don’t fire as one burst
    if(i < SAMPLES - 1) await new Promise(function(r){ setTimeout(r, 200); });
  }
  if(count === 0) return null;  // all samples failed — offline
  return Math.round(total / count); // return average ms
}

async function checkConn(){
  if(!inCall) return;
  
  var rtcStats = await getWebRTCStats();
  var pingMs = null;
  
  // Prefer WebRTC round-trip time since it is computed locally.
  // This bypasses the heavy HTTP ping requests during active calls.
  if(rtcStats && rtcStats.rtt !== null){
    pingMs = rtcStats.rtt;
  } else {
    // Only fall back to PHP server ping if WebRTC connections are not yet active
    pingMs = await pingServer();
  }
  
  var level = 'good';
  var displayPing = pingMs;
  var effectivePing = pingMs;
  var lossRate = rtcStats ? rtcStats.lossRate : 0;
  
  if(effectivePing === null || !navigator.onLine){
    level = 'offline';
  } else if(effectivePing > 700 || lossRate > 0.15){
    level = 'poor';
  } else if(effectivePing > 350 || lossRate > 0.05){
    level = 'fair';
  } else {
    var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if(conn){
      var type = conn.effectiveType || '';
      if(type === 'slow-2g' || type === '2g') level = 'poor';
      else if(type === '3g') level = 'fair';
      else level = 'good';
    } else {
      level = 'good';
    }
  }
  
  updateConnUI(level, displayPing);
  // Send status update directly to the teacher via data channel on every interval
  broadcastConn(level, displayPing);
  
  if(level !== _lastConnLevel){
    _lastConnLevel = level;
    // Adaptive quality: reduce/increase video bitrate+resolution based on signal change
    adaptQualityToSignal(level);
    // If we just recovered from offline/poor, check again quickly
    if(level === 'good' || level === 'fair'){
      setTimeout(checkConn, 3000);
    }
  }
}

function updateConnUI(level, pingMs){
  var el = document.getElementById('connIndicator');
  var lb = document.getElementById('connLabel');
  if(!el) return;
  el.style.display = 'flex';
  el.className = '';
  el.id = 'connIndicator';
  el.classList.add(level);
  var bars = [document.getElementById('cb1'), document.getElementById('cb2'), document.getElementById('cb3'), document.getElementById('cb4')];
  bars.forEach(function(b){ if(b) b.classList.remove('lit'); });
  var litCount = {good:4, fair:3, poor:2, offline:1}[level] || 1;
  for(var i = 0; i < litCount; i++){ if(bars[i]) bars[i].classList.add('lit'); }
  var labels = {good:'Good', fair:'Fair', poor:'Poor', offline:'Offline'};
  lb.textContent = (labels[level] || level) + (pingMs ? ' (' + pingMs + 'ms)' : '');
}

function broadcastConn(level, pingMs){
  // Only send to the teacher peer (currentRoomId) to save student-to-student bandwidth
  var dc = dataChannels[currentRoomId];
  if(dc && dc.readyState === 'open'){
    try { dc.send(JSON.stringify({type:'conn', level:level, name:MY_NAME, ping:pingMs||null})); } catch(e){}
  }
}

/* ── Teacher: connectivity panel ── */
var _studentConn = {};

function updateConnPanel(peerId, name, level, ping){
  if(!IS_TEACHER) return;
  _studentConn[peerId] = {name:name, level:level, ping:ping};
  var dotsEl = document.getElementById('cpDots');
  var panel  = document.getElementById('connPanel');
  if(!dotsEl || !panel) return;
  panel.style.display = 'flex';
  dotsEl.innerHTML = '';
  Object.values(_studentConn).forEach(function(s){
    var d = document.createElement('div');
    d.className = 'cp-dot ' + s.level;
    d.title = s.name + ': ' + s.level + (s.ping ? ' (' + s.ping + 'ms)' : '');
    dotsEl.appendChild(d);
  });
  if(document.getElementById('connOverlay') && document.getElementById('connOverlay').classList.contains('open')){
    renderConnOverlay();
  }
}

function removeFromConnPanel(peerId){
  delete _studentConn[peerId];
  var dotsEl = document.getElementById('cpDots');
  var panel  = document.getElementById('connPanel');
  if(!dotsEl || !panel) return;
  if(!Object.keys(_studentConn).length){ panel.style.display = 'none'; dotsEl.innerHTML = ''; return; }
  dotsEl.innerHTML = '';
  Object.values(_studentConn).forEach(function(s){
    var d = document.createElement('div');
    d.className = 'cp-dot ' + s.level;
    dotsEl.appendChild(d);
  });
}

function renderConnOverlay(){
  var body = document.getElementById('connOverlayBody');
  if(!body) return;
  var keys = Object.keys(_studentConn);
  if(!keys.length){ body.innerHTML = '<p style="color:#64748b;font-size:13px;text-align:center;padding:20px 0;">No students connected yet.</p>'; return; }
  var levelOrder = {good:0, fair:1, poor:2, offline:3};
  var sorted = keys.slice().sort(function(a,b){ return (levelOrder[_studentConn[a].level]||0) - (levelOrder[_studentConn[b].level]||0); });
  var html = '';
  sorted.forEach(function(pid){
    var s = _studentConn[pid];
    var colors = {good:'#10b981', fair:'#f59e0b', poor:'#ef4444', offline:'#475569'};
    var labels = {good:'Good', fair:'Fair', poor:'Poor', offline:'Offline'};
    var barCounts = {good:4, fair:3, poor:2, offline:1};
    var heights = ['4px','7px','10px','13px'];
    var bars = '';
    for(var b = 0; b < 4; b++){
      var lit = b < (barCounts[s.level]||1);
      bars += '<div style="width:3px;height:' + heights[b] + ';border-radius:1px;background:' + (lit ? '#fff' : 'rgba(255,255,255,.25)') + '"></div>';
    }
    var init = s.name.split(' ').map(function(w){ return w[0]||''; }).join('').substring(0,2).toUpperCase();
    html += '<div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);">'
      + '<div style="width:34px;height:34px;border-radius:8px;background:linear-gradient(135deg,<?php echo $accent;?>,<?php echo $accentDk;?>);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;flex-shrink:0;">' + init + '</div>'
      + '<div style="flex:1;min-width:0;">'
        + '<div style="font-size:13px;font-weight:600;color:#f1f5f9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + s.name + '</div>'
        + '<div style="font-size:11px;color:#64748b;">' + (s.ping ? s.ping + 'ms ping' : 'No ping data') + '</div>'
      + '</div>'
      + '<div style="display:flex;align-items:center;gap:7px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:5px 10px;">'
        + '<div style="display:flex;align-items:flex-end;gap:2px;height:13px;">' + bars + '</div>'
        + '<span style="font-size:11px;font-weight:700;color:' + colors[s.level] + ';">' + labels[s.level] + '</span>'
      + '</div>'
      + '</div>';
  });
  body.innerHTML = html;
}

function toggleConnOverlay(){
  var overlay = document.getElementById('connOverlay');
  if(overlay.classList.contains('open')){ closeModal('connOverlay'); }
  else { renderConnOverlay(); openModal('connOverlay'); }
}
</script>
<script>
/* ── Peer discovery helpers (mesh topology) ─────────────────────────────── */
var _peerHeartbeatInterval = null;
var _discoveryInterval     = null;

function _startPeerHeartbeat(sessionId){
  if(IS_TEACHER) return; // teacher has a fixed room ID — no heartbeat needed
  clearInterval(_peerHeartbeatInterval);
  _peerHeartbeatInterval = setInterval(function(){
    if(!inCall) return;
    $.post('live_handler.php', {action:'peer_heartbeat', session_id:sessionId});
  }, 10000); // every 10s
}

function _discoverAndCallPeers(sessionId){
  if(!inCall || IS_TEACHER) return;
  $.get('live_handler.php', {action:'get_peers', session_id:sessionId}, function(r){
    if(!r.success || !r.peers) return;
    r.peers.forEach(function(p){
      // Skip teacher (already connected via fixed roomId)
      if(p.peer_id === currentRoomId) return;
      // Skip if we already have a tile or an active call for this peer
      if(document.getElementById('tile_' + p.peer_id)) return;
      if(peers[p.peer_id]) return;

      // ── Anti-duplicate race fix ────────────────────────────────────────
      // When two students both discover each other at the same time, both
      // would try to call each other → two connections form → duplicate tiles.
      // Fix: only the peer with the HIGHER peer ID initiates. The other side
      // will receive the incoming call via peer.on('call') and answer it.
      var myPeerId = peer ? peer.id : '';
      if(myPeerId < p.peer_id) return; // let the other side call us

      var outCall = peer.call(p.peer_id, myStream, {
        metadata:{name:MY_NAME, code:MY_CODE, role:'STUDENT'}
      });
      if(!outCall) return;
      _applyScreenTrackToPeer(outCall);

      outCall.on('stream', function(rs){
        var existingVid = document.querySelector('#tile_' + outCall.peer + ' video');
        if(existingVid){ existingVid.srcObject = rs; existingVid.play().catch(function(){}); return; }
        peers[outCall.peer] = outCall;
        addTile(rs, p.name, false, outCall.peer, p.user_code);
        setupDataChannel(outCall, outCall.peer);
        capBitrate(outCall.peerConnection);

        // ICE state cleanup for student-to-student calls
        outCall.peerConnection.oniceconnectionstatechange = function(){
          var state = outCall.peerConnection.iceConnectionState;
          if(state === 'disconnected' || state === 'failed' || state === 'closed'){
            setTimeout(function(){
              var s2 = outCall.peerConnection.iceConnectionState;
              if(s2 === 'disconnected' || s2 === 'failed' || s2 === 'closed'){
                removeTile(outCall.peer);
                delete dataChannels[outCall.peer];
                delete peers[outCall.peer];
              }
            }, 3000);
          }
        };
      });
      outCall.on('close', function(){ removeTile(outCall.peer); delete dataChannels[outCall.peer]; delete peers[outCall.peer]; });
      outCall.on('error',  function(){ removeTile(outCall.peer); delete dataChannels[outCall.peer]; delete peers[outCall.peer]; });
    });
  }, 'json');

  // Poll for new peers every 8s while in call (only set interval once)
  if(!_discoveryInterval){
    _discoveryInterval = setInterval(function(){
      if(!inCall){ clearInterval(_discoveryInterval); _discoveryInterval = null; return; }
      _discoverAndCallPeers(sessionId);
    }, 8000);
  }
}

/* ── Bitrate cap — runs once after ICE connects; ongoing adaptation handled by _applyQualityToPeers ── */
async function capBitrate(pc){
  // Wait until connected — setParameters before this silently fails or causes renegotiation
  if(pc.iceConnectionState !== 'connected' && pc.iceConnectionState !== 'completed'){
    var waited = 0;
    await new Promise(function(resolve){
      var check = setInterval(function(){
        waited += 200;
        if(pc.iceConnectionState === 'connected' || pc.iceConnectionState === 'completed' || waited > 8000){
          clearInterval(check);
          resolve();
        }
      }, 200);
    });
  }
  if(pc.iceConnectionState === 'failed' || pc.iceConnectionState === 'closed') return;
  try {
    // Use current adaptive quality tier instead of fixed values
    var bucket = _getPeerBucket();
    var vbr    = BITRATE_TABLE[_qualityTier][bucket];
    var abr    = 48000; // 48kbps — clear, not choppy
    var scaleDown = _qualityTier === 0 ? 2 : 1;
    var maxFps    = _qualityTier === 0 ? 15 : (_qualityTier === 1 ? 20 : 24);

    var senders = pc.getSenders();
    for(var i = 0; i < senders.length; i++){
      var sender = senders[i];
      if(!sender.track) continue;
      var params = sender.getParameters();
      if(!params.encodings || !params.encodings.length) params.encodings = [{}];
      var enc = params.encodings[0];
      if(sender.track.kind === 'video'){
        var isScreen = (screenStream && screenStream.getVideoTracks().includes(sender.track));
        if(isScreen){
          // Screen sharing optimization
          enc.maxBitrate            = 1500000;
          enc.maxFramerate          = 15;
          enc.scaleResolutionDownBy = 1;
          enc.degradationPreference = 'maintain-resolution';
        } else {
          // Camera optimization
          enc.maxBitrate            = vbr;
          enc.maxFramerate          = maxFps;
          enc.scaleResolutionDownBy = scaleDown;
          enc.degradationPreference = 'maintain-framerate';
        }
      } else if(sender.track.kind === 'audio'){
        enc.maxBitrate = abr;
      }
      await sender.setParameters(params);
    }
  } catch(e){ console.warn('[capBitrate]', e); }
}

/* ── Face-api.js attention detection — Optimized (Throttled to 4s to fix CPU delay/lag) ── */
var faceModelsReady = false;
var faceLoading = false;
var _faceDetectionHistory = [];

async function startFaceDetection(videoEl){
  if(IS_TEACHER) return;
  if(faceInterval) clearInterval(faceInterval);
  _faceDetectionHistory = [];
  
  if(!faceModelsReady && !faceLoading){
    faceLoading = true;
    try {
      // Load SSD MobileNet and Face Landmark models from relative path
      await faceapi.nets.ssdMobilenetv1.loadFromUri('../dist/models');
      await faceapi.nets.faceLandmark68Net.loadFromUri('../dist/models');
      faceModelsReady = true;
      faceLoading = false;
    } catch(e) {
      console.warn('[face-api] Model load failed:', e);
      faceLoading = false;
      return;
    }
  }
  
  // Throttle face detection: run every 4 seconds instead of on every frame!
  // This reduces CPU load by 95% and completely fixes the video/audio delays.
  faceInterval = setInterval(async function(){
    if(!inCall || !camOn || !faceModelsReady || !videoEl) return;
    
    // CPU Optimization: skip face-api detection if the tab is hidden or window is blurred,
    // since visibility state change event will handle setting the student to 'Away' anyway.
    if(!_tabVisible || !_windowFocused) return;
    
    try {
      var detection = await faceapi.detectSingleFace(videoEl, new faceapi.SsdMobilenetv1Options({ minConfidence: 0.5 }))
                                    .withFaceLandmarks();
      
      var isLooking = false;
      var reason = 'Away';
      
      if(detection){
        var landmarks = detection.landmarks;
        var nose = landmarks.getNose();
        var jaw = landmarks.getJawOutline();
        
        // Simple gaze approximation: check if nose position is centered relative to jaw outline
        var jawLeft = jaw[0];
        var jawRight = jaw[16];
        var noseTip = nose[3];
        
        var jawWidth = jawRight.x - jawLeft.x;
        if(jawWidth > 0){
          var noseOffset = (noseTip.x - jawLeft.x) / jawWidth;
          if(noseOffset > 0.35 && noseOffset < 0.65){
            isLooking = true;
          } else {
            reason = 'Looking Sideways';
          }
        } else {
          isLooking = true; // fallback
        }
      } else {
        reason = 'No Face Detected';
      }
      
      // Sliding window smoothing: store latest status and keep history length <= 3
      _faceDetectionHistory.push({ success: isLooking, reason: reason });
      if(_faceDetectionHistory.length > 3){
        _faceDetectionHistory.shift();
      }
      
      // Determine smoothed attention status via majority vote (at least 2 of last 3 checks are positive)
      var successCount = _faceDetectionHistory.filter(function(h){ return h.success; }).length;
      var smoothedIsLooking = successCount >= 2;
      
      var smoothedReason = '';
      if(!smoothedIsLooking){
        // Compute the most common reason for being away in history
        var counts = {};
        _faceDetectionHistory.forEach(function(h){
          if(!h.success) counts[h.reason] = (counts[h.reason] || 0) + 1;
        });
        var maxCount = 0;
        for(var r in counts){
          if(counts[r] > maxCount){
            maxCount = counts[r];
            smoothedReason = r;
          }
        }
        if(!smoothedReason) smoothedReason = 'Away';
      }
      
      _lastFaceDetected = smoothedIsLooking;
      _faceReason = smoothedIsLooking ? '' : smoothedReason;
      
      _broadcastEngagement();
    } catch(e){
      console.warn('[face-api] detection error:', e);
    }
  }, 4000);
}

function stopFaceDetection(){
  if(faceInterval){ clearInterval(faceInterval); faceInterval = null; }
  _faceDetectionHistory = [];
  _lastFaceDetected = true;
  _faceReason = '';
}
var faceInterval = null;
</script>
</body>
</html>

