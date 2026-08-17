<?php
header('Content-Type: application/json');
// Session is managed by session.php — do not call session_start() here
include 'includes/session.php';

$user = $_SESSION['user'];

if($user['user_group'] !== 'STUDENT'){
    echo json_encode(['success'=>false,'msg'=>'Only students can complete a profile here.']);
    exit;
}

// Sanitize inputs
$fn  = trim($_POST['first_name']   ?? '');
$ln  = trim($_POST['last_name']    ?? '');
$mn  = trim($_POST['middle_name']  ?? '');
$pc  = strtoupper(trim($_POST['program_code'] ?? ''));
$yl  = intval($_POST['year_level'] ?? 0);
$sec = strtoupper(trim($_POST['section']      ?? ''));

// Validate
if(!$fn || !$ln){
    echo json_encode(['success'=>false,'msg'=>'First name and last name are required.']);
    exit;
}
if(!$pc){
    echo json_encode(['success'=>false,'msg'=>'Program is required.']);
    exit;
}
if($yl < 1 || $yl > 5){
    echo json_encode(['success'=>false,'msg'=>'Year level is required.']);
    exit;
}
if(!$sec){
    echo json_encode(['success'=>false,'msg'=>'Section is required.']);
    exit;
}

// Validate program against known list
include 'includes/programs.php';
$validPrograms = array_column($BCC_PROGRAMS, 'code');
if(!in_array($pc, $validPrograms)){
    echo json_encode(['success'=>false,'msg'=>'Invalid program selected.']);
    exit;
}

$uc     = $conn->real_escape_string($user['user_code']);
$esc_fn = $conn->real_escape_string(strtoupper($fn));
$esc_ln = $conn->real_escape_string(strtoupper($ln));
$esc_mn = $conn->real_escape_string(strtoupper($mn));
$esc_pc = $conn->real_escape_string($pc);
$esc_sec= $conn->real_escape_string($sec);

// Get program description
$progDesc = '';
foreach($BCC_PROGRAMS as $p){
    if($p['code'] === $pc){ $progDesc = $p['desc']; break; }
}
$esc_pd = $conn->real_escape_string($progDesc);

$ok = $conn->query("UPDATE users SET
    first_name='$esc_fn',
    last_name='$esc_ln',
    middle_name='$esc_mn',
    program_code='$esc_pc',
    program_description='$esc_pd',
    year_level=$yl,
    section='$esc_sec'
    WHERE user_code='$uc'");

if(!$ok){
    echo json_encode(['success'=>false,'msg'=>'Database error. Please try again.']);
    exit;
}

// Update the session so subsequent pages see the new data immediately
$_SESSION['user']['first_name']          = strtoupper($fn);
$_SESSION['user']['last_name']           = strtoupper($ln);
$_SESSION['user']['middle_name']         = strtoupper($mn);
$_SESSION['user']['program_code']        = $pc;
$_SESSION['user']['program_description'] = $progDesc;
$_SESSION['user']['year_level']          = $yl;
$_SESSION['user']['section']             = $sec;

// Auto-enroll in matching classes now that we have profile data
function autoEnrollStudent($conn, $user_code, $program_code, $year_level, $section){
    if(empty($program_code) && !$year_level && empty($section)) return;
    $uc  = $conn->real_escape_string($user_code);
    $pc  = $conn->real_escape_string(strtoupper(trim($program_code)));
    $yl  = intval($year_level);
    $sec = $conn->real_escape_string(strtoupper(trim($section)));
    $where = [];
    if($pc)  $where[] = "(c.program_code='' OR c.program_code IS NULL OR UPPER(c.program_code)='$pc')";
    if($yl)  $where[] = "(c.year_level=0 OR c.year_level IS NULL OR c.year_level=$yl)";
    if($sec) $where[] = "(c.section='' OR c.section IS NULL OR UPPER(c.section)='$sec')";
    $restrict = "((c.program_code!='' AND c.program_code IS NOT NULL) OR (c.year_level!=0 AND c.year_level IS NOT NULL) OR (c.section!='' AND c.section IS NOT NULL))";
    $clause = $restrict . (!empty($where) ? ' AND '.implode(' AND ',$where) : '');
    $res = $conn->query("SELECT c.id FROM classes c WHERE $clause AND NOT EXISTS (SELECT 1 FROM class_members cm WHERE cm.class_id=c.id AND cm.user_code='$uc')");
    if($res) while($cl=$res->fetch_assoc())
        $conn->query("INSERT IGNORE INTO class_members (class_id,user_code) VALUES ({$cl['id']},'$uc')");
}

autoEnrollStudent($conn, $user['user_code'], $pc, $yl, $sec);

echo json_encode(['success'=>true]);
