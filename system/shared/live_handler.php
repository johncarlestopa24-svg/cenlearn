<?php
session_start();
include '../includes/conn.php';
header('Content-Type: application/json');

if(empty($_SESSION['user'])){ echo json_encode(['success'=>false,'msg'=>'Not logged in']); exit; }
$user = $_SESSION['user'];
$uc   = $conn->real_escape_string($user['user_code']);
$role = strtoupper($user['user_group']);

// BUG 17 FIX: resolve accent colors here so sessions_list HTML builder can use them
// (live_class.php has these too, but this file is a separate request)
$_isTeacherRole = ($role === 'TEACHER');
$_accent   = $_isTeacherRole ? '#10b981' : '#1792bb';
$_accentDk = $_isTeacherRole ? '#059669' : '#0f5f80';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Teacher: Schedule a session ───────────────────────────────────────────
if($action === 'schedule' && $role === 'TEACHER'){
    $class_id    = intval($_POST['class_id'] ?? 0);
    $title       = $conn->real_escape_string(trim($_POST['title'] ?? ''));
    if(!$title)  $title = $conn->real_escape_string('Live Class: ' . date('M d, g:i A'));
    $scheduled   = trim($_POST['scheduled_at'] ?? '');
    $room_id     = $conn->real_escape_string('cenlearn_'.md5($uc.'_'.$class_id));
    $term        = $conn->real_escape_string(trim($_POST['term'] ?? 'midterm'));
    if(!in_array($term, ['midterm', 'final'])) $term = 'midterm';

    $q = $conn->query("SELECT id FROM classes WHERE id=$class_id AND teacher_code='$uc'");
    if($q->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Unauthorized']); exit; }

    // Use NULL if no date provided, otherwise escape it
    $scheduledSql = $scheduled ? "'".$conn->real_escape_string($scheduled)."'" : 'NULL';

    $conn->query("INSERT INTO live_sessions (class_id,teacher_code,room_id,title,scheduled_at,status,term)
                  VALUES ($class_id,'$uc','$room_id','$title',$scheduledSql,'scheduled','$term')");
    echo json_encode(['success'=>true,'id'=>$conn->insert_id]);
    exit;
}

// ── Teacher: Start session ────────────────────────────────────────────────
if($action === 'start' && $role === 'TEACHER'){
    $session_id = intval($_POST['session_id'] ?? 0);
    // Get the class_id and room_id for this session
    $sr = $conn->query("SELECT class_id, room_id FROM live_sessions WHERE id=$session_id AND teacher_code='$uc'");
    if($sr->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Session not found']); exit; }
    $sRow = $sr->fetch_assoc();
    $class_id_for_session = intval($sRow['class_id']);
    $room_id = $sRow['room_id'];
    // End any currently live session for this class
    $conn->query("UPDATE live_sessions SET status='ended', ended_at=NOW()
                  WHERE class_id=$class_id_for_session AND status='live' AND teacher_code='$uc' AND id != $session_id");
    $conn->query("UPDATE live_sessions SET status='live', started_at=NOW()
                  WHERE id=$session_id AND teacher_code='$uc'");
    echo json_encode(['success'=>true, 'room_id'=>$room_id, 'session_id'=>$session_id]);
    exit;
}

// ── Teacher: End session ──────────────────────────────────────────────────
if($action === 'end' && $role === 'TEACHER'){
    $session_id = intval($_POST['session_id'] ?? 0);
    $conn->query("UPDATE live_sessions SET status='ended', ended_at=NOW()
                  WHERE id=$session_id AND teacher_code='$uc'");
    // Mark all waiting/admitted as denied, close attendance
    $conn->query("UPDATE live_admission SET status='denied' WHERE session_id=$session_id AND status='waiting'");
    $conn->query("UPDATE live_attendance SET left_at=NOW() WHERE session_id=$session_id AND left_at IS NULL");
    echo json_encode(['success'=>true]);
    exit;
}

// ── Teacher: Delete scheduled session ────────────────────────────────────
if($action === 'delete_session' && $role === 'TEACHER'){
    $session_id = intval($_POST['session_id'] ?? 0);
    // BUG 18 FIX: verify ownership FIRST before deleting any related records.
    // Previously, live_admission and live_attendance were deleted before the
    // ownership check on live_sessions, allowing any teacher to wipe another's data.
    $own = $conn->query("SELECT id FROM live_sessions WHERE id=$session_id AND teacher_code='$uc' AND status='scheduled'");
    if(!$own || $own->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Unauthorized or session not found']); exit; }
    $conn->query("DELETE FROM live_admission WHERE session_id=$session_id");
    $conn->query("DELETE FROM live_attendance WHERE session_id=$session_id");
    $conn->query("DELETE FROM live_sessions WHERE id=$session_id AND teacher_code='$uc' AND status='scheduled'");
    echo json_encode(['success'=>true]);
    exit;
}

// ── Student: Record attendance on join ───────────────────────────────────
if($action === 'record_attendance' && $role === 'STUDENT'){
    $session_id = intval($_POST['session_id'] ?? 0);

    // Verify session is scheduled or live and get class_id
    $sq = $conn->query("SELECT ls.id, ls.class_id, ls.title, COALESCE(ls.started_at, ls.scheduled_at, NOW()) AS started_at
                        FROM live_sessions ls
                        WHERE ls.id=$session_id AND ls.status IN ('live','scheduled')");
    if($sq->num_rows === 0){ echo json_encode(['success'=>true]); exit; }
    $sess = $sq->fetch_assoc();
    $class_id = intval($sess['class_id']);

    // 1. Record in live_attendance
    $conn->query("INSERT IGNORE INTO live_attendance (session_id,student_code)
                  VALUES ($session_id,'$uc')");

    // 2. Auto-insert into class_record_columns + class_record_scores
    //    Each live session gets its own "Attendance" column (component = 'written')
    //    Title format: "Att: <session title or date>"
    $conn->query("CREATE TABLE IF NOT EXISTS `class_record_columns`
        (`id` int(11) NOT NULL AUTO_INCREMENT,
         `class_id` int(11) NOT NULL,
         `component` enum('written','performance','exam') NOT NULL,
         `title` varchar(100) NOT NULL,
         `max_score` decimal(6,2) NOT NULL DEFAULT 1,
         `sort_order` int(11) DEFAULT 0,
         `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
         `session_id` int(11) DEFAULT NULL,
         PRIMARY KEY (`id`),
         KEY `class_id` (`class_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add session_id column if it doesn't exist yet (migration)
    safeAddColumns($conn, 'class_record_columns', ['session_id' => 'int(11) DEFAULT NULL AFTER `sort_order`']);

    // Calculate score: 2 points if present on time, 1 point if late by 15 mins (only for started live sessions)
    $score = 2.00;
    if(($sess['status'] ?? '') === 'live' && !empty($sess['started_at'])){
        $startedTime = strtotime($sess['started_at']);
        $currentTime = time();
        $diffMinutes = ($currentTime - $startedTime) / 60;
        if ($diffMinutes >= 15) {
            $score = 1.00;
        }
    }

    // Find or create the attendance column for this specific session
    $dateStr = date('M d', strtotime($sess['started_at'] ?: 'now'));
    $attTitle = $conn->real_escape_string($dateStr);

    $colQ = $conn->query("SELECT id FROM class_record_columns
                          WHERE class_id=$class_id AND session_id=$session_id LIMIT 1");
    if($colQ->num_rows === 0){
        // Reliably read the term from the live_sessions row (set by the teacher when scheduling)
        $termRow = $conn->query("SELECT term FROM live_sessions WHERE id=$session_id LIMIT 1");
        $termToUse = ($termRow && $termRow->num_rows > 0) ? $termRow->fetch_assoc()['term'] : 'midterm';
        if(!in_array($termToUse, ['midterm', 'final'])) $termToUse = 'midterm';
        // Create the attendance column for this session with max_score = 2.00
        $conn->query("INSERT INTO class_record_columns
                      (class_id, component, title, max_score, sort_order, session_id, term)
                      VALUES ($class_id, 'written', '$attTitle', 2.00, 0, $session_id, '$termToUse')");
        $col_id = $conn->insert_id;
    } else {
        $col_id = intval($colQ->fetch_assoc()['id']);
        // Ensure max_score is updated to 2.00 for live class attendance
        $conn->query("UPDATE class_record_columns SET max_score = 2.00 WHERE id = $col_id");
    }

    // Insert score for this student
    $conn->query("CREATE TABLE IF NOT EXISTS `class_record_scores`
        (`id` int(11) NOT NULL AUTO_INCREMENT,
         `column_id` int(11) NOT NULL,
         `class_id` int(11) NOT NULL,
         `student_code` varchar(50) NOT NULL,
         `score` decimal(6,2) DEFAULT NULL,
         PRIMARY KEY (`id`),
         UNIQUE KEY `col_student` (`column_id`,`student_code`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("INSERT INTO class_record_scores (column_id, class_id, student_code, score)
                  VALUES ($col_id, $class_id, '$uc', $score)
                  ON DUPLICATE KEY UPDATE score = GREATEST(COALESCE(score, 0), $score)");

    echo json_encode(['success'=>true]);
    exit;
}

// ── Get session status (polling) ──────────────────────────────────────────
if($action === 'session_status'){
    $class_id = intval($_GET['class_id'] ?? 0);
    // Add HTTP cache headers — browsers/proxies can cache for 5s
    // This dramatically reduces DB load when 30 students poll simultaneously
    header('Cache-Control: private, max-age=5');
    $sq = $conn->query("SELECT id, status, title, scheduled_at, started_at, room_id
                        FROM live_sessions WHERE class_id=$class_id AND status IN ('live','scheduled')
                        ORDER BY FIELD(status,'live','scheduled'), scheduled_at ASC LIMIT 1");
    if($sq->num_rows === 0){ echo json_encode(['status'=>'none']); exit; }
    $s = $sq->fetch_assoc();
    echo json_encode(['status'=>$s['status'],'session_id'=>$s['id'],'title'=>$s['title'],
                      'scheduled_at'=>$s['scheduled_at'],'started_at'=>$s['started_at'],'room_id'=>$s['room_id']]);
    exit;
}

// ── Get attendance for a session ──────────────────────────────────────────
if($action === 'attendance' && $role === 'TEACHER'){
    $session_id = intval($_GET['session_id'] ?? 0);
    $res = $conn->query("SELECT a.student_code, a.joined_at, a.left_at,
                                u.first_name, u.last_name, u.year_level, u.section
                         FROM live_attendance a LEFT JOIN users u ON a.student_code=u.user_code
                         WHERE a.session_id=$session_id ORDER BY a.joined_at ASC");
    $list = [];
    while($r = $res->fetch_assoc()) $list[] = $r;
    echo json_encode(['success'=>true,'attendance'=>$list]);
    exit;
}

// ── Get all sessions for a class ──────────────────────────────────────────
if($action === 'get_sessions'){
    $class_id = intval($_GET['class_id'] ?? 0);
    $res = $conn->query("SELECT s.*,
                         (SELECT COUNT(*) FROM live_attendance WHERE session_id=s.id) AS attendee_count
                         FROM live_sessions s WHERE s.class_id=$class_id
                         ORDER BY s.created_at DESC");
    $list = [];
    while($r = $res->fetch_assoc()) $list[] = $r;
    echo json_encode(['success'=>true,'sessions'=>$list]);
    exit;
}

// ── Student: Leave (record left_at) ──────────────────────────────────────
if($action === 'leave' && $role === 'STUDENT'){
    $session_id = intval($_POST['session_id'] ?? 0);
    $conn->query("UPDATE live_attendance SET left_at=NOW()
                  WHERE session_id=$session_id AND student_code='$uc' AND left_at IS NULL");
    echo json_encode(['success'=>true]);
    exit;
}

// ── Ping (connectivity check) ─────────────────────────────────────────────
// Ping (connectivity check)
if($action === 'ping'){
    echo json_encode(['ok'=>true,'ts'=>time()]);
    exit;
}

// Peer registry: register this user's PeerJS ID
if($action === 'register_peer'){
    $session_id = intval($_POST['session_id'] ?? 0);
    $peer_id    = $conn->real_escape_string(trim($_POST['peer_id'] ?? ''));
    $name       = $conn->real_escape_string(trim($_POST['name'] ?? ''));
    $reqRole    = trim($_POST['role'] ?? '');
    if(!$session_id || !$peer_id){ echo json_encode(['success'=>false]); exit; }
    $r = ($role === 'TEACHER' || strtoupper($reqRole) === 'TEACHER') ? 'TEACHER' : 'STUDENT';
    $conn->query("INSERT INTO live_peers (session_id,user_code,peer_id,name,role)
                  VALUES ($session_id,'$uc','$peer_id','$name','$r')
                  ON DUPLICATE KEY UPDATE peer_id='$peer_id', name='$name', role='$r', last_seen=NOW()");
    
    if($r === 'TEACHER'){
        $conn->query("UPDATE live_sessions SET room_id='$peer_id' WHERE id=$session_id AND teacher_code='$uc'");
    }
    echo json_encode(['success'=>true]);
    exit;
}

// Peer registry: get all active peers in a session
if($action === 'get_peers'){
    $session_id = intval($_GET['session_id'] ?? 0);
    if(!$session_id){ echo json_encode(['success'=>false,'peers'=>[]]); exit; }
    $res = $conn->query("SELECT peer_id, name, role, user_code FROM live_peers
                         WHERE session_id=$session_id
                           AND last_seen > DATE_SUB(NOW(), INTERVAL 45 SECOND)
                           AND user_code != '$uc'");
    $peerList = [];
    while($row = $res->fetch_assoc()) $peerList[] = $row;
    echo json_encode(['success'=>true,'peers'=>$peerList]);
    exit;
}

// Peer registry: heartbeat to keep registration fresh
if($action === 'peer_heartbeat'){
    $session_id = intval($_POST['session_id'] ?? 0);
    if($session_id){
        $conn->query("UPDATE live_peers SET last_seen=NOW()
                      WHERE session_id=$session_id AND user_code='$uc'");
    }
    echo json_encode(['ok'=>true]);
    exit;
}
if($action === 'sessions_list'){
    $class_id = intval($_GET['class_id'] ?? 0);
    // BUG 17 FIX: use $role already set at top — don't re-declare and shadow it
    $uc2  = $conn->real_escape_string($user['user_code']);

    // Verify access
    $cq = $conn->query("SELECT * FROM classes WHERE id=$class_id AND (teacher_code='$uc2' OR EXISTS (SELECT 1 FROM class_members WHERE class_id=$class_id AND user_code='$uc2'))");
    if(!$cq || $cq->num_rows === 0){ echo json_encode(['success'=>false]); exit; }
    $class = $cq->fetch_assoc();
    $isTeacher = (in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN']) || strcasecmp($class['teacher_code'] ?? '', $user['user_code'] ?? '') === 0);

    $sessQ = $conn->query("SELECT * FROM live_sessions WHERE class_id=$class_id ORDER BY created_at DESC");
    $sessions = [];
    while($s = $sessQ->fetch_assoc()) $sessions[] = $s;

    if(empty($sessions)){
        $html = '<div class="empty-state"><i class="fa fa-video-camera"></i>'
              . ($isTeacher ? 'No sessions yet. Schedule your first live class.' : 'No live sessions scheduled yet.')
              . '</div>';
    } else {
        $html = '';
        foreach($sessions as $s){
            $statusLabel = ['scheduled'=>'Scheduled','live'=>'LIVE','ended'=>'Ended'][$s['status']];
            $attQ = $conn->query("SELECT COUNT(*) AS c FROM live_attendance WHERE session_id={$s['id']}");
            $attCount = $attQ->fetch_assoc()['c'];
            $dotClass = htmlspecialchars($s['status']);
            $title = htmlspecialchars($s['title'] ?: 'Live Class');
            $html .= '<div class="sess-card">';
            $html .= '<div class="sess-dot '.$dotClass.'"></div>';
            $html .= '<div class="sess-info"><div class="sess-title">'.$title.'</div>';
            $html .= '<div class="sess-meta"><span>'.$statusLabel.'</span>';
            if($s['scheduled_at']) $html .= '<span><i class="fa fa-calendar"></i>'.date('M d, Y g:i A', strtotime($s['scheduled_at'])).'</span>';
            if($s['started_at'])   $html .= '<span><i class="fa fa-play"></i>Started '.date('g:i A', strtotime($s['started_at'])).'</span>';
            $html .= '<span><i class="fa fa-users"></i>'.$attCount.' attended</span></div></div>';
            $html .= '<div class="sess-actions">';
            $roomIdAttr = htmlspecialchars(addslashes($s['room_id'] ?? ''));
            if($isTeacher){
                if($s['status']==='scheduled'){
                    $html .= '<button class="btn-lc accent sm" onclick="startSession('.$s['id'].',\''.$roomIdAttr.'\')"><i class="fa fa-play"></i> Start</button>';
                    $html .= '<button class="btn-lc ghost sm" onclick="deleteSession('.$s['id'].')"><i class="fa fa-trash"></i></button>';
                } elseif($s['status']==='live'){
                    $html .= '<button class="btn-lc accent sm" onclick="joinCall('.$s['id'].',\''.$roomIdAttr.'\')"><i class="fa fa-video-camera"></i> Join</button>';
                    $html .= '<button class="btn-lc red sm" onclick="endSession('.$s['id'].')"><i class="fa fa-stop"></i> End</button>';
                }
                if($s['status']==='ended' || $s['status']==='live'){
                    $html .= '<button class="btn-lc blue sm" onclick="viewAttendance('.$s['id'].',\''.htmlspecialchars(addslashes($s['title']?:'Live Class')).'\')"><i class="fa fa-list"></i> Attendance</button>';
                }
            } else {
                if($s['status']==='live'){
                    $html .= '<button class="btn-lc accent sm" onclick="joinAsStudent('.$s['id'].',\''.$roomIdAttr.'\')"><i class="fa fa-sign-in"></i> Join</button>';
                } elseif($s['status']==='scheduled'){
                    $html .= '<span style="font-size:11px;color:#f59e0b;font-weight:600;"><i class="fa fa-clock-o"></i> Upcoming</span>';
                } else {
                    $html .= '<span style="font-size:11px;color:#475569;font-weight:600;"><i class="fa fa-check"></i> Ended</span>';
                }
            }
            $html .= '</div></div>';
        }
    }
    echo json_encode(['success'=>true, 'html'=>$html]);
    exit;
}

echo json_encode(['success'=>false,'msg'=>'Invalid action']);