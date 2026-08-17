<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if(empty($_SESSION['user']) || !$_SESSION['user']['is_valid']){
    header('location: ../index.php'); exit;
}
$user = $_SESSION['user'];

$isGrad = !empty($user['graduated_at']);
// Only redirect away if NOT actually graduated — never infer graduation from missing fields
if(!$isGrad){ header('location: dashboard.php'); exit; }

include '../includes/conn.php';
$uc2     = $conn->real_escape_string($user['user_code']);
$name    = htmlspecialchars(trim(($user['first_name']??'').' '.($user['last_name']??'')));
$program = htmlspecialchars($user['program_code'] ?? '');
$gradDate= !empty($user['graduated_at']) ? date('F d, Y', strtotime($user['graduated_at'])) : date('Y');

$quotes = [
    ["The beautiful thing about learning is that no one can take it away from you.", "B.B. King"],
    ["Education is the most powerful weapon which you can use to change the world.", "Nelson Mandela"],
    ["The roots of education are bitter, but the fruit is sweet.", "Aristotle"],
    ["Success is not the key to happiness. Happiness is the key to success.", "Albert Schweitzer"],
    ["The future belongs to those who believe in the beauty of their dreams.", "Eleanor Roosevelt"],
    ["It always seems impossible until it's done.", "Nelson Mandela"],
    ["Don't watch the clock; do what it does. Keep going.", "Sam Levenson"],
    ["Believe you can and you're halfway there.", "Theodore Roosevelt"],
    ["Your education is a dress rehearsal for a life that is yours to lead.", "Nora Ephron"],
    ["The secret of getting ahead is getting started.", "Mark Twain"],
    ["You are braver than you believe, stronger than you seem, and smarter than you think.", "A.A. Milne"],
    ["Go confidently in the direction of your dreams. Live the life you have imagined.", "Henry David Thoreau"],
    ["In the middle of every difficulty lies opportunity.", "Albert Einstein"],
    ["The only way to do great work is to love what you do.", "Steve Jobs"],
    ["Dream big and dare to fail.", "Norman Vaughan"],
];
$q = $quotes[array_rand($quotes)];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Congratulations, <?php echo htmlspecialchars($user['first_name']??''); ?>!</title>
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Inter', sans-serif;
      min-height: 100vh;
      background: #0a1628;
      display: flex; flex-direction: column;
      align-items: center;
      padding: 0;
      overflow-x: hidden;
    }

    /* ── Hero banner ── */
    .hero {
      width: 100%;
      background: linear-gradient(135deg, #b45309 0%, #d97706 40%, #f59e0b 100%);
      position: relative; overflow: hidden;
      padding: 56px 24px 80px;
      text-align: center;
    }
    .hero::before {
      content: ''; position: absolute; inset: 0;
      background-image: radial-gradient(circle, rgba(255,255,255,.07) 1px, transparent 1px);
      background-size: 24px 24px;
    }
    .hero::after {
      content: ''; position: absolute;
      bottom: -40px; left: 50%; transform: translateX(-50%);
      width: 120%; height: 80px;
      background: #0a1628;
      border-radius: 50% 50% 0 0 / 100% 100% 0 0;
    }
    .hero-inner { position: relative; z-index: 2; }
    .cap-ring {
      width: 96px; height: 96px; border-radius: 50%;
      background: rgba(255,255,255,.15);
      border: 3px solid rgba(255,255,255,.3);
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 20px;
      box-shadow: 0 8px 32px rgba(0,0,0,.2);
    }
    .cap-ring i { font-size: 40px; color: #fff; }
    .hero h1 {
      font-size: clamp(24px, 5vw, 36px);
      font-weight: 800; color: #fff;
      margin-bottom: 8px;
      text-shadow: 0 2px 12px rgba(0,0,0,.2);
    }
    .hero p {
      font-size: 15px; color: rgba(255,255,255,.85);
      line-height: 1.6;
    }
    .hero-chips {
      display: flex; align-items: center; justify-content: center;
      gap: 10px; flex-wrap: wrap; margin-top: 16px;
    }
    .hero-chip {
      display: inline-flex; align-items: center; gap: 6px;
      background: rgba(0,0,0,.2); color: rgba(255,255,255,.9);
      padding: 5px 14px; border-radius: 99px;
      font-size: 12px; font-weight: 600;
      border: 1px solid rgba(255,255,255,.15);
      backdrop-filter: blur(4px);
    }

    /* ── Main content ── */
    .main {
      width: 100%; max-width: 680px;
      padding: 0 20px 48px;
      margin-top: -20px;
      position: relative; z-index: 3;
    }

    /* ── Card ── */
    .card {
      background: #1e293b;
      border: 1px solid rgba(255,255,255,.07);
      border-radius: 20px;
      overflow: hidden;
      margin-bottom: 16px;
    }
    .card-hdr {
      padding: 16px 22px;
      border-bottom: 1px solid rgba(255,255,255,.06);
      display: flex; align-items: center; gap: 10px;
    }
    .card-hdr-icon {
      width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
    }
    .card-hdr-icon i { font-size: 14px; color: #fff; }
    .card-hdr h3 { font-size: 13px; font-weight: 700; color: #fff; margin: 0; }
    .card-body { padding: 20px 22px; }

    /* ── Student info ── */
    .info-grid {
      display: grid; grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    .info-item {
      background: rgba(255,255,255,.04);
      border: 1px solid rgba(255,255,255,.06);
      border-radius: 10px; padding: 12px 14px;
    }
    .info-item .lbl { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 4px; }
    .info-item .val { font-size: 13px; font-weight: 600; color: #e2e8f0; }

    /* ── Quote ── */
    .quote-card {
      background: linear-gradient(135deg, #1c1a0a, #2a2000);
      border: 1px solid rgba(245,158,11,.2);
      border-radius: 20px; padding: 28px 28px 22px;
      margin-bottom: 16px; position: relative; overflow: hidden;
    }
    .quote-card::before {
      content: '\201C';
      font-size: 120px; line-height: 1; color: #f59e0b;
      position: absolute; top: -20px; left: 12px;
      font-family: Georgia, serif; opacity: .15;
      pointer-events: none;
    }
    .quote-text {
      font-size: 16px; font-weight: 500; color: #fef3c7;
      line-height: 1.7; font-style: italic;
      position: relative; z-index: 1; margin-bottom: 14px;
    }
    .quote-divider {
      height: 1px; background: rgba(245,158,11,.2); margin-bottom: 12px;
    }
    .quote-author {
      display: flex; align-items: center; gap: 8px;
      font-size: 12px; font-weight: 700; color: #f59e0b;
    }
    .quote-author::before {
      content: ''; width: 24px; height: 2px;
      background: #f59e0b; border-radius: 99px;
    }

    /* ── Class list ── */
    .class-item {
      display: flex; align-items: center; gap: 14px;
      padding: 14px 0;
      border-bottom: 1px solid rgba(255,255,255,.05);
      text-decoration: none;
      transition: opacity .15s;
    }
    .class-item:last-child { border-bottom: none; }
    .class-item:hover { opacity: .8; text-decoration: none; }
    .class-ico {
      width: 42px; height: 42px; border-radius: 11px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
    }
    .class-ico i { font-size: 17px; color: #fff; }
    .class-info { flex: 1; min-width: 0; }
    .class-info strong { display: block; font-size: 13px; font-weight: 700; color: #f1f5f9; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .class-info span   { font-size: 11px; color: #64748b; }
    .class-arrow { color: #334155; font-size: 12px; flex-shrink: 0; }

    .empty-note { text-align: center; padding: 28px; color: #475569; font-size: 13px; }
    .empty-note i { font-size: 28px; display: block; margin-bottom: 8px; color: #334155; }

    /* ── Sign out ── */
    .btn-signout {
      display: flex; align-items: center; justify-content: center; gap: 9px;
      width: 100%; padding: 14px; border: none; border-radius: 12px; cursor: pointer;
      background: linear-gradient(135deg, #ef4444, #dc2626);
      color: #fff; font-size: 14px; font-weight: 700;
      font-family: 'Inter', sans-serif; text-decoration: none;
      transition: opacity .2s, transform .1s;
      box-shadow: 0 4px 16px rgba(239,68,68,.35);
    }
    .btn-signout:hover { opacity: .9; transform: translateY(-1px); color: #fff; text-decoration: none; }
    .btn-signout:active { transform: scale(.98); }

    /* ── Footer ── */
    .page-footer {
      text-align: center; font-size: 11px; color: #334155;
      padding: 0 20px 24px; margin-top: -4px;
    }

    @media(max-width:480px){
      .hero { padding: 44px 20px 72px; }
      .info-grid { grid-template-columns: 1fr; }
      .card-body { padding: 16px 18px; }
      .quote-card { padding: 22px 20px 18px; }
    }
  </style>
</head>
<body>

<!-- Hero -->
<div class="hero">
  <div class="hero-inner">
    <div class="cap-ring"><i class="fa fa-graduation-cap"></i></div>
    <h1>Congratulations, <?php echo htmlspecialchars($user['first_name'] ?? ''); ?>!</h1>
    <p>You have successfully completed your academic journey.<br>
    <?php echo $program ? 'Program: <strong style="color:#fff;">'.$program.'</strong>' : ''; ?></p>
    <div class="hero-chips">
      <span class="hero-chip"><i class="fa fa-calendar"></i> Class of <?php echo $gradDate; ?></span>
      <span class="hero-chip"><i class="fa fa-id-badge"></i> <?php echo htmlspecialchars($user['user_code'] ?? ''); ?></span>
      <span class="hero-chip"><i class="fa fa-check-circle"></i> Graduate</span>
    </div>
  </div>
</div>

<!-- Main -->
<div class="main">

  <!-- Student info -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-hdr">
      <div class="card-hdr-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);"><i class="fa fa-user"></i></div>
      <h3>Graduate Profile</h3>
    </div>
    <div class="card-body">
      <div class="info-grid">
        <div class="info-item">
          <div class="lbl">Full Name</div>
          <div class="val"><?php echo $name; ?></div>
        </div>
        <div class="info-item">
          <div class="lbl">Student ID</div>
          <div class="val"><?php echo htmlspecialchars($user['user_code'] ?? ''); ?></div>
        </div>
        <div class="info-item">
          <div class="lbl">Program</div>
          <div class="val"><?php echo $program ?: '—'; ?></div>
        </div>
        <div class="info-item">
          <div class="lbl">Graduated</div>
          <div class="val"><?php echo $gradDate; ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Motivational quote -->
  <div class="quote-card">
    <div class="quote-text"><?php echo htmlspecialchars($q[0]); ?></div>
    <div class="quote-divider"></div>
    <div class="quote-author"><?php echo htmlspecialchars($q[1]); ?></div>
  </div>

  <!-- Sign out -->
  <a href="../logout.php" class="btn-signout">
    <i class="fa fa-sign-out"></i> Sign Out
  </a>

</div>

<div class="page-footer">CenLearn &mdash; Powered by TechnoPal</div>

</body>
</html>
