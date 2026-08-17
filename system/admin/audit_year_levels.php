<?php
if(session_status() === PHP_SESSION_NONE) session_start();
include '../includes/conn.php';

if(empty($_SESSION['user']) || !in_array($_SESSION['user']['user_group'], ['ADMIN','SUPERADMIN'])){
    header('Location: ../index.php?role_mismatch=admin'); exit;
}

$students = [];
$q = $conn->query("SELECT user_code, first_name, last_name, year_level, section, program_code FROM users WHERE user_group='STUDENT' AND is_active=1 ORDER BY last_name, first_name");
while($r = $q->fetch_assoc()) $students[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, user-scalable=yes">
<title>Year Level Audit — CenLearn</title>
<link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  body{font-family:'Inter',sans-serif;background:#f0f4f8;padding:24px;color:#1e293b;}
  @media(max-width:768px){body{padding:14px;}}
  h2{font-weight:800;color:#0f172a;margin-bottom:20px;font-size:20px;}
  .box{background:#fff;border-radius:16px;border:1px solid #e2e8f0;box-shadow:0 2px 8px rgba(0,0,0,.04);overflow-x:auto;-webkit-overflow-scrolling:touch;}
  table{width:100%;min-width:640px;border-collapse:collapse;}
  thead th{background:#f1f5f9;font-size:11px;font-weight:700;color:#475569;padding:10px 14px;text-align:left;border-bottom:1px solid #e2e8f0;}
  tbody td{font-size:13px;color:#334155;padding:10px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
  tbody tr:last-child td{border-bottom:none;}
  .match{background:#dcfce7;color:#166534;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;}
  .mismatch{background:#fef2f2;color:#991b1b;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;}
  .pending{background:#fef9c3;color:#92400e;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;}
  .api-err{background:#f1f5f9;color:#64748b;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;}
  #progress{margin-bottom:16px;font-size:13px;color:#64748b;}
  .btn-fix-all{background:#ef4444;color:#fff;border:none;padding:10px 22px;border-radius:10px;font-weight:800;cursor:pointer;margin-bottom:20px;}
  .btn-fix-all:disabled{opacity:.5;cursor:not-allowed;}
</style>
</head>
<body>
<a href="students.php" style="font-size:13px;color:#1792bb;">&larr; Back to Students</a>
<h2 style="margin-top:12px;"><i class="fa fa-search"></i> Year Level Audit — DB vs TechnoPal API</h2>

<button class="btn-fix-all" id="btnFixAll" onclick="fixAll()">
  <i class="fa fa-wrench"></i> Apply All API Values to DB
</button>

<div id="progress">Loading student data from TechnoPal... <span id="counter">0</span> / <?php echo count($students); ?></div>

<div class="box">
<table>
  <thead>
    <tr>
      <th>Student ID</th>
      <th>Name</th>
      <th>Program</th>
      <th>DB Year</th>
      <th>DB Section</th>
      <th>API Year</th>
      <th>API Section</th>
      <th>Status</th>
    </tr>
  </thead>
  <tbody id="auditBody">
    <?php foreach($students as $s): ?>
    <tr id="row-<?php echo htmlspecialchars($s['user_code']); ?>">
      <td><?php echo htmlspecialchars($s['user_code']); ?></td>
      <td><?php echo htmlspecialchars($s['last_name'].', '.$s['first_name']); ?></td>
      <td><?php echo htmlspecialchars($s['program_code'] ?: '—'); ?></td>
      <td><b><?php echo $s['year_level'] ?: '—'; ?></b></td>
      <td><?php echo htmlspecialchars($s['section'] ?: '—'); ?></td>
      <td class="api-year">—</td>
      <td class="api-section">—</td>
      <td class="api-status"><span class="pending">Pending</span></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script>
var students = <?php echo json_encode(array_column($students, 'user_code')); ?>;
var results  = {};
var done     = 0;

function checkNext(i){
  if(i >= students.length){
    $('#progress').text('Done. ' + students.length + ' students checked.');
    $('#btnFixAll').prop('disabled', false);
    return;
  }
  var uc = students[i];
  $.ajax({
    url: 'check_student_api.php',
    data: { user_code: uc },
    dataType: 'json',
    success: function(res){
      var row    = $('#row-' + uc);
      var dbYear = res.db ? (res.db.year_level || '—') : '—';
      var apiYear, apiSec, statusHtml;

      if(res.api && res.api.year_level !== undefined && res.api.year_level !== 'NOT_RETURNED'){
        apiYear = res.api.year_level || '—';
        apiSec  = res.api.section   || '—';
        results[uc] = res.api;

        if(String(apiYear) === String(dbYear)){
          statusHtml = '<span class="match"><i class="fa fa-check"></i> Match</span>';
        } else {
          statusHtml = '<span class="mismatch"><i class="fa fa-exclamation"></i> Mismatch (DB:'+dbYear+' API:'+apiYear+')</span>';
        }
      } else {
        apiYear = '—';
        apiSec  = '—';
        statusHtml = '<span class="api-err"><i class="fa fa-minus"></i> API no data</span>';
      }

      row.find('.api-year').text(apiYear);
      row.find('.api-section').text(apiSec);
      row.find('.api-status').html(statusHtml);
    },
    error: function(){
      $('#row-' + uc).find('.api-status').html('<span class="api-err">Error</span>');
    },
    complete: function(){
      done++;
      $('#counter').text(done);
      checkNext(i + 1);
    }
  });
}

// Start checking sequentially to avoid hammering the API
checkNext(0);
$('#btnFixAll').prop('disabled', true);

function fixAll(){
  var mismatches = Object.keys(results).filter(function(uc){
    var row = $('#row-' + uc);
    return row.find('.api-status .mismatch').length > 0;
  });

  if(mismatches.length === 0){ alert('No mismatches to fix.'); return; }
  if(!confirm('Update ' + mismatches.length + ' students in DB with API values?')) return;

  $('#btnFixAll').prop('disabled', true).text('Fixing...');

  $.ajax({
    url: 'apply_api_year_levels.php',
    method: 'POST',
    contentType: 'application/json',
    data: JSON.stringify(results),
    dataType: 'json',
    success: function(res){
      alert('Updated ' + res.updated + ' students. Reload to see changes.');
      location.reload();
    },
    error: function(){ alert('Failed to apply updates.'); }
  });
}
</script>
</body>
</html>
