<?php
include '../includes/session.php';
include '../includes/conn.php';
include '../shared/analytics_engine.php';

$uc = $conn->real_escape_string($user['user_code']);

// Ensure user profile details
$uq = $conn->query("SELECT * FROM users WHERE user_code='$uc'");
if ($uq && $uq->num_rows > 0) {
    $user = array_merge($user, $uq->fetch_assoc());
}
$user['program_code'] = $user['program_code'] ?? '';
$user['section']      = $user['section'] ?? '';
$user['year_level']   = $user['year_level'] ?? '';

// Load historical study plan snapshots
$studyPlanHistory = cenlearn_get_study_plan_history($conn, $uc);
$historyCount     = count($studyPlanHistory);

// Also fetch current topic performance for longitudinal comparison
$currentTopicsRes = $conn->query("
    SELECT tp.topic,
           ROUND((tp.total_points_earned / NULLIF(tp.total_points_available,0)) * 100, 1) AS current_pct,
           tp.attempts
    FROM topic_performance tp
    WHERE tp.student_code = '$uc' AND tp.total_points_available > 0
");
$currentScores = [];
if ($currentTopicsRes) {
    while ($ctr = $currentTopicsRes->fetch_assoc()) {
        $currentScores[strtolower(trim($ctr['topic']))] = [
            'score'    => floatval($ctr['current_pct']),
            'attempts' => intval($ctr['attempts'])
        ];
    }
}

$initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
$fullName = trim($user['first_name'].' '.($user['middle_name']?$user['middle_name'][0].'. ':'').$user['last_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Study Plan History — CenLearn</title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; overflow-x: hidden; font-family: 'Inter', sans-serif; background: #f0f4f8; color: #1e293b; }
    
    /* Sidebar */
    .sd-sidebar {
      position: fixed; top: 0; left: 0; width: 260px; height: 100vh;
      background: linear-gradient(180deg, #0c1a2e 0%, #0f2d4a 55%, #0f5f80 100%);
      display: flex; flex-direction: column; z-index: 200;
      transition: transform .3s cubic-bezier(.4,0,.2,1);
      transform: translateX(-260px);
    }
    .sd-sidebar.open { transform: translateX(0); }
    @media(min-width:901px){ .sd-sidebar { transform: translateX(0); } }
    .sb-brand { padding: 26px 22px 18px; border-bottom: 1px solid rgba(255,255,255,.08); }
    .sb-logo { width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, #1792bb, #0f5f80); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 10px; box-shadow: 0 4px 12px rgba(23,146,187,.4); }
    .sb-logo i { color: #fff; font-size: 17px; }
    .sb-brand h2 { color: #fff; font-size: 19px; font-weight: 800; margin: 0; }
    .sb-brand h2 span { color: #38bdf8; }
    .sb-brand p { color: rgba(255,255,255,.35); font-size: 10px; margin: 2px 0 0; }
    .sb-nav { flex: 1; padding: 14px 0; overflow-y: auto; }
    .sb-section { padding: 8px 22px 4px; font-size: 9px; font-weight: 700; color: rgba(255,255,255,.25); letter-spacing: 1.4px; text-transform: uppercase; }
    .sb-nav ul { list-style: none; margin: 0; padding: 0; }
    .sb-nav li a { display: flex; align-items: center; gap: 11px; padding: 10px 22px; color: rgba(255,255,255,.6); text-decoration: none; font-size: 13px; font-weight: 500; transition: all .2s; border-left: 3px solid transparent; }
    .sb-nav li a:hover { background: rgba(255,255,255,.07); color: #fff; }
    .sb-nav li.active a { background: rgba(56,189,248,.12); color: #fff; border-left-color: #38bdf8; }
    .sb-nav li a i { width: 17px; text-align: center; font-size: 14px; }
    .sb-footer { padding: 14px 22px; border-top: 1px solid rgba(255,255,255,.07); }
    .sb-user { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .sb-av { width: 36px; height: 36px; border-radius: 9px; background: linear-gradient(135deg,#1792bb,#0f5f80); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; color: #fff; flex-shrink: 0; }
    .sb-meta strong { display: block; color: #fff; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sb-meta span { color: rgba(255,255,255,.4); font-size: 10px; }
    .sb-out { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 8px; width: 100%; background: rgba(255,255,255,.07); color: rgba(255,255,255,.6); border: 1px solid rgba(255,255,255,.1); border-radius: 8px; font-size: 12px; font-weight: 500; text-decoration: none; transition: background .2s; }
    .sb-out:hover { background: rgba(255,255,255,.13); color: #fff; }

    /* Main Area */
    .sd-main { margin-left: 0; min-height: 100vh; display: flex; flex-direction: column; }
    @media(min-width:901px){ .sd-main { margin-left: 260px; } }
    .sd-topbar { background: #fff; padding: 0 28px; height: 64px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 50; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
    .sd-topbar-title h3 { font-size: 16px; font-weight: 800; color: #0f172a; margin: 0; }
    .sd-topbar-title p { font-size: 12px; color: #64748b; margin: 0; }
    .btn-primary-sm { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; background: linear-gradient(135deg, #1792bb, #0f5f80); color: #fff; border: none; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; transition: all .2s; }
    .btn-primary-sm:hover { opacity: .9; color: #fff; transform: translateY(-1px); }

    .sd-content { padding: 24px 28px 40px; flex: 1; }

    /* Hero Banner */
    .hero-banner {
      background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
      border-radius: 16px; padding: 24px 28px; color: #fff; margin-bottom: 24px;
      box-shadow: 0 4px 16px rgba(79,70,229,0.18); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;
    }
    .hero-banner h2 { font-size: 20px; font-weight: 800; margin: 0 0 6px; color: #fff; }
    .hero-banner p { font-size: 13px; color: rgba(255,255,255,0.88); margin: 0; max-width: 620px; line-height: 1.5; }

    /* Snapshot Card */
    .history-card {
      background: #fff; border-radius: 16px; border: 1px solid #e2e8f0;
      margin-bottom: 20px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.03);
      transition: box-shadow 0.2s;
    }
    .history-card:hover { box-shadow: 0 6px 16px rgba(0,0,0,0.06); }
    .history-header {
      padding: 16px 20px; background: #fff; border-bottom: 1px solid #f1f5f9;
      display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
    }
    .history-body { padding: 20px; }

    @media print {
      .sd-sidebar, .sd-topbar, .no-print { display: none !important; }
      .sd-main { margin-left: 0 !important; }
      .history-card { break-inside: avoid; border: 1px solid #ccc !important; }
    }
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
      <li><a href="quizzes.php"><i class="fa fa-question-circle"></i> My Quizzes</a></li>
      <li class="active"><a href="study_plan_history.php"><i class="fa fa-history"></i> Study Plan History</a></li>
    </ul>
  </nav>
  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-av"><?php echo $initials; ?></div>
      <div class="sb-meta">
        <strong><?php echo htmlspecialchars($fullName); ?></strong>
        <span><?php echo htmlspecialchars($user['program_code'] ?: 'Student'); ?></span>
      </div>
    </div>
    <a href="../logout.php" class="sb-out"><i class="fa fa-sign-out"></i> Sign Out</a>
  </div>
</aside>

<div class="sd-main">
  <header class="sd-topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
      <div class="sd-topbar-title">
        <h3>Study Plan &amp; Recommendations History</h3>
        <p>Student Code: <?php echo htmlspecialchars($uc); ?> &bull; <?php echo htmlspecialchars($fullName); ?></p>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <a href="dashboard.php" class="btn-primary-sm" style="background:#fff;color:#4f46e5;border:1.5px solid #c7d2fe;">
        <i class="fa fa-arrow-left"></i> Dashboard
      </a>
      <button type="button" class="btn-primary-sm" onclick="window.print()" style="background:linear-gradient(135deg,#4f46e5,#3b82f6);">
        <i class="fa fa-print"></i> Print Report
      </button>
    </div>
  </header>

  <div class="sd-content">

    <!-- Hero Banner -->
    <div class="hero-banner">
      <div>
        <h2><i class="fa fa-history" style="margin-right:8px;"></i> Historical Study Plans &amp; Learning Guidance</h2>
        <p>
          CenLearn automatically captures diagnostic snapshots as you complete assessments, quizzes, and learning modules. 
          Use this archive to evaluate your progress, verify topic remediation, and maintain your academic trajectory.
        </p>
      </div>
      <div style="background:rgba(255,255,255,0.18);padding:10px 18px;border-radius:12px;backdrop-filter:blur(6px);text-align:center;">
        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;opacity:0.9;">Total Snapshots</span>
        <strong style="display:block;font-size:24px;font-weight:800;"><?php echo $historyCount; ?></strong>
      </div>
    </div>

    <?php if(empty($studyPlanHistory)): ?>
    <div style="text-align:center;padding:56px 20px;background:#fff;border-radius:16px;border:1px solid #e2e8f0;">
      <div style="width:64px;height:64px;border-radius:50%;background:#ede9fe;color:#6366f1;display:inline-flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:14px;">
        <i class="fa fa-calendar-o"></i>
      </div>
      <h4 style="font-size:17px;font-weight:700;color:#0f172a;margin-bottom:6px;">No Historical Study Plans Recorded</h4>
      <p style="font-size:13px;color:#64748b;max-width:460px;margin:0 auto 16px;">
        Once you take quizzes and engage with subject modules, your personalized study plan snapshots will be automatically archived here.
      </p>
      <a href="dashboard.php" class="btn-primary-sm" style="background:#4f46e5;color:#fff;">
        <i class="fa fa-th-large"></i> Go to Dashboard
      </a>
    </div>
    <?php else: ?>

    <!-- Timeline of Historical Snapshots -->
    <div style="display:flex;flex-direction:column;gap:20px;">
      <?php foreach($studyPlanHistory as $idx => $record):
        $rDate = date('F j, Y \a\t g:i A', strtotime($record['created_at']));
        $rRisk = $record['overall_risk'] ?: 'on_track';
        $rScore = intval($record['risk_score']);
        $riskBadge = [
          'on_track'  => ['bg'=>'#dcfce7','color'=>'#166534','border'=>'#bbf7d0','label'=>'On Track'],
          'attention' => ['bg'=>'#fef3c7','color'=>'#92400e','border'=>'#fde68a','label'=>'Needs Attention'],
          'at_risk'   => ['bg'=>'#ffedd5','color'=>'#9a3412','border'=>'#fed7aa','label'=>'At Risk'],
          'high_risk' => ['bg'=>'#fee2e2','color'=>'#991b1b','border'=>'#fecaca','label'=>'High Risk'],
        ][$rRisk] ?? ['bg'=>'#f1f5f9','color'=>'#475569','border'=>'#e2e8f0','label'=>ucwords($rRisk)];

        $quote = $record['quote'] ?? null;
        $items = $record['topic_items'] ?? [];
      ?>
      <div class="history-card">
        
        <!-- Snapshot Header Bar -->
        <div class="history-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <div style="width:34px;height:34px;border-radius:10px;background:#e0e7ff;color:#4f46e5;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;">
              #<?php echo $historyCount - $idx; ?>
            </div>
            <div>
              <div style="font-size:14px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:8px;">
                <i class="fa fa-calendar" style="color:#6366f1;"></i> <?php echo $rDate; ?>
                <?php if($idx === 0): ?>
                <span style="background:#dcfce7;color:#166534;font-size:10px;font-weight:800;padding:2px 8px;border-radius:6px;text-transform:uppercase;">
                  Latest Active Plan
                </span>
                <?php endif; ?>
              </div>
              <div style="font-size:11.5px;color:#64748b;margin-top:2px;">
                Archived Study Plan Snapshot &bull; <?php echo count($items); ?> Focus Recommendation<?php echo count($items)!==1?'s':''; ?>
              </div>
            </div>
          </div>

          <div style="display:flex;align-items:center;gap:10px;">
            <span style="background:<?php echo $riskBadge['bg']; ?>;color:<?php echo $riskBadge['color']; ?>;border:1px solid <?php echo $riskBadge['border']; ?>;padding:4px 12px;border-radius:99px;font-size:12px;font-weight:700;">
              <i class="fa fa-shield"></i> <?php echo $riskBadge['label']; ?> (Risk Score: <?php echo $rScore; ?>/100)
            </span>
          </div>
        </div>

        <!-- Snapshot Content Body -->
        <div class="history-body">
          
          <!-- Historical AI Coach Guidance Message -->
          <?php if(!empty($quote)): ?>
          <div style="background:<?php echo $quote['bg'] ?? '#fffbeb'; ?>;border:1px solid <?php echo $quote['border'] ?? '#fde68a'; ?>;border-left:4px solid <?php echo $quote['color'] ?? '#f59e0b'; ?>;border-radius:12px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:flex-start;gap:12px;">
            <i class="fa <?php echo $quote['icon'] ?? 'fa-graduation-cap'; ?>" style="color:<?php echo $quote['color'] ?? '#f59e0b'; ?>;font-size:18px;margin-top:2px;"></i>
            <div style="flex:1;">
              <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:5px;">
                <strong style="font-size:13px;color:#0f172a;"><?php echo $quote['title'] ?? 'AI Diagnostic Coach'; ?></strong>
                <span style="font-size:11px;font-weight:800;color:<?php echo $quote['color'] ?? '#f59e0b'; ?>;background:#fff;padding:2px 8px;border-radius:99px;border:1px solid <?php echo $quote['border'] ?? '#fde68a'; ?>;">
                  <?php echo htmlspecialchars($quote['badge'] ?? ''); ?>
                </span>
              </div>
              <div style="font-size:12.5px;color:#334155;line-height:1.5;">
                <?php echo $quote['message'] ?? ''; ?>
              </div>
              <?php if(!empty($quote['action'])): ?>
              <div style="font-size:11.5px;color:#475569;margin-top:6px;font-weight:600;">
                <i class="fa fa-arrow-circle-right" style="color:<?php echo $quote['color'] ?? '#f59e0b'; ?>;"></i> <?php echo $quote['action']; ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- Recommendations list -->
          <?php if(!empty($items)): ?>
          <div style="display:flex;flex-direction:column;gap:14px;">
            <?php foreach($items as $iIdx => $item):
              $iScore = $item['score_pct'] ?? null;
              $iTopic = $item['topic'] ?? '';
              $iSubj  = $item['subject'] ?? ($item['class_name'] ?? '');
              $iTitle = $item['title'] ?? ($iTopic ? 'Improve: '.$iTopic : 'Study Recommendation');
              $iDesc  = $item['desc'] ?? '';
              $iStd   = $item['standard_ref'] ?? null;
              $primaryMod = $item['primary_module'] ?? (!empty($item['modules'][0]) ? $item['modules'][0] : null);

              // Compare with current topic performance if available
              $currData = !empty($iTopic) ? ($currentScores[strtolower(trim($iTopic))] ?? null) : null;
            ?>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #6366f1;border-radius:12px;padding:16px 18px;">
              
              <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                <div style="font-size:14px;font-weight:800;color:#0f172a;">
                  <?php echo $iTitle; ?>
                </div>
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                  <?php if($iScore !== null): ?>
                  <span style="font-size:11.5px;font-weight:800;background:<?php echo $iScore < 50 ? '#fee2e2' : ($iScore < 75 ? '#fef3c7' : '#dcfce7'); ?>;color:<?php echo $iScore < 50 ? '#991b1b' : ($iScore < 75 ? '#92400e' : '#166534'); ?>;padding:3px 10px;border-radius:99px;">
                    Snapshot Score: <?php echo $iScore; ?>% <?php echo $iScore < 75 ? '(Developing)' : '(Mastered)'; ?>
                  </span>
                  <?php endif; ?>

                  <?php if($currData !== null): ?>
                  <span style="font-size:11.5px;font-weight:700;background:#ede9fe;color:#5b21b6;padding:3px 10px;border-radius:99px;border:1px solid #c4b5fd;">
                    <i class="fa fa-line-chart"></i> Latest Performance: <?php echo $currData['score']; ?>% (<?php echo $currData['attempts']; ?> attempts)
                  </span>
                  <?php endif; ?>
                </div>
              </div>

              <p style="font-size:12.5px;color:#475569;margin-bottom:12px;line-height:1.5;">
                <?php echo $iDesc; ?>
              </p>

              <!-- Phased Guidance Breakdown -->
              <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:10px;margin-top:10px;">
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:10px 12px;">
                  <strong style="color:#4f46e5;font-size:10.5px;text-transform:uppercase;letter-spacing:0.4px;display:block;margin-bottom:4px;">
                    <i class="fa fa-book"></i> Phase 1: Module Study
                  </strong>
                  <div style="font-size:11.5px;color:#334155;line-height:1.4;">
                    <?php if($primaryMod): ?>
                      Module: <strong><?php echo htmlspecialchars($primaryMod['title']); ?></strong>
                    <?php else: ?>
                      Teacher learning module review
                    <?php endif; ?>
                  </div>
                </div>

                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:10px 12px;">
                  <strong style="color:#d97706;font-size:10.5px;text-transform:uppercase;letter-spacing:0.4px;display:block;margin-bottom:4px;">
                    <i class="fa fa-pencil"></i> Phase 2: Practice Retake
                  </strong>
                  <div style="font-size:11.5px;color:#334155;line-height:1.4;">
                    Analyze error patterns &amp; retake assessment
                  </div>
                </div>

                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:10px 12px;">
                  <strong style="color:#10b981;font-size:10.5px;text-transform:uppercase;letter-spacing:0.4px;display:block;margin-bottom:4px;">
                    <i class="fa fa-bullseye"></i> Phase 3: Mastery Target
                  </strong>
                  <div style="font-size:11.5px;color:#334155;line-height:1.4;">
                    Attain &ge; 75% standard proficiency
                  </div>
                </div>
              </div>

              <?php if(!empty($iStd) && !empty($iStd['bloom_level'])): ?>
              <div style="margin-top:10px;font-size:11px;color:#6366f1;background:#ede9fe;padding:5px 10px;border-radius:7px;display:inline-flex;align-items:center;gap:6px;">
                <i class="fa fa-bookmark"></i> Standard Aligned: <strong><?php echo htmlspecialchars($iStd['bloom_level']); ?></strong> &bull; <?php echo htmlspecialchars($iStd['standard_code'] ?? ''); ?>
              </div>
              <?php endif; ?>

            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
  <footer class="sd-footer" style="text-align:center;padding:16px;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;background:#fff;">
    CenLearn &mdash; Powered by TechnoPal
  </footer>
</div>

<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script src="../bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
</script>
</body>
</html>
