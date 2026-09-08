<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../includes/conn.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user'])) {
    echo json_encode(['success' => false, 'msg' => 'Authentication required. Please log in.']);
    exit;
}

$user = $_SESSION['user'];
$role = strtoupper($user['user_group'] ?? '');
if (!in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN'])) {
    echo json_encode(['success' => false, 'msg' => 'Access denied. Only faculty and administrators can sync subjects.']);
    exit;
}

// Target teacher code: admin can sync for specific teacher if passed, otherwise current teacher
$teacher_code = trim($_POST['teacher_code'] ?? $user['user_code']);
if (empty($teacher_code)) {
    echo json_encode(['success' => false, 'msg' => 'No teacher code provided.']);
    exit;
}

// Academic Year and Semester
$ay = trim($_POST['ay'] ?? '');
if (empty($ay)) {
    // Current or default Academic Year
    $currentYear = (int)date('Y');
    $currentMonth = (int)date('n');
    // In Philippine academic calendar, 1st sem starts around Aug-Sep
    if ($currentMonth >= 8) {
        $ay = $currentYear . '-' . ($currentYear + 1);
    } else {
        $ay = ($currentYear - 1) . '-' . $currentYear;
    }
}
$sem = trim($_POST['sem'] ?? '1');

if (!defined('TECHNOPAL_API_URL') || !defined('TECHNOPAL_API_TOKEN')) {
    echo json_encode(['success' => false, 'msg' => 'TechnoPal API configuration is missing.']);
    exit;
}

// ── Call TechnoPal API ────────────────────────────────────────────────────────
$apiUrl = TECHNOPAL_API_URL . '?' . http_build_query([
    'action'    => 'teacher_schedule_students',
    'token'     => TECHNOPAL_API_TOKEN,
    'user_code' => $teacher_code,
    'ay'        => $ay,
    'sem'       => $sem,
]);

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_USERAGENT      => 'CenLearn/2.0 (TechnoPal Sync)',
]);

$rawResponse = curl_exec($ch);
$curlError   = curl_error($ch);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError || $httpCode !== 200 || empty($rawResponse)) {
    echo json_encode([
        'success' => false,
        'msg'     => 'Could not reach TechnoPal API: ' . ($curlError ?: "HTTP Status $httpCode"),
    ]);
    exit;
}

$data = json_decode(trim($rawResponse), true);
if (!is_array($data) || empty($data['ok'])) {
    $err = $data['error'] ?? 'Invalid response from TechnoPal API';
    echo json_encode(['success' => false, 'msg' => "TechnoPal error: $err"]);
    exit;
}

$groups = $data['groups'] ?? [];
if (empty($groups)) {
    echo json_encode([
        'success' => true,
        'msg'     => "No assigned subjects or student schedules found for teacher ($teacher_code) in $ay (Semester $sem).",
        'synced_classes'  => 0,
        'synced_students' => 0,
    ]);
    exit;
}

// Ensure schedule columns exist in classes table
safeAddColumns($conn, 'classes', [
    'schedule_json' => 'text DEFAULT NULL',
    'schedule_room' => 'varchar(50) DEFAULT NULL',
    'school_year'   => 'varchar(20) DEFAULT NULL'
]);

// Auto-create confirmations table if missing
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

$tc_esc = $conn->real_escape_string($teacher_code);
$ay_esc = $conn->real_escape_string($ay);
$archiveOld = isset($_POST['archive_old']) ? (int)$_POST['archive_old'] : 1;

$createdClassesCount = 0;
$updatedClassesCount = 0;
$totalEnrolledCount  = 0;
$archivedOldCount    = 0;
$syncedDetails       = [];

// ── Auto-Archive Previous Academic Year Subjects ──────────────────────────
// When a new academic year arrives, automatically archive previous active classes
// so they cleanly move to the Past Subject Repository and Archived tab.
if ($archiveOld && !empty($groups)) {
    $findOld = $conn->query("
        SELECT id, school_year FROM classes 
        WHERE teacher_code = '$tc_esc'
          AND (is_archived = 0 OR is_archived IS NULL)
          AND (is_subject_only = 0 OR is_subject_only IS NULL)
          AND (school_year != '$ay_esc' OR school_year IS NULL OR school_year = '')
    ");
    if ($findOld && $findOld->num_rows > 0) {
        $oldIds = [];
        while ($row = $findOld->fetch_assoc()) {
            $oldIds[] = (int)$row['id'];
        }
        if (!empty($oldIds)) {
            $idList = implode(',', $oldIds);
            // Default previous school year if not set
            $prevAy = (intval(substr($ay, 0, 4)) - 1) . '-' . substr($ay, 0, 4);
            $prevAyEsc = $conn->real_escape_string($prevAy);
            $conn->query("
                UPDATE classes 
                SET is_archived = 1, 
                    archived_at = NOW(),
                    school_year = IF(school_year IS NULL OR school_year = '', '$prevAyEsc', school_year)
                WHERE id IN ($idList)
            ");
            $archivedOldCount = count($oldIds);
        }
    }
}

foreach ($groups as $group) {
    $subj_code   = trim($group['subject_code'] ?? '');
    $subj_name   = trim($group['subject_description'] ?? '');
    $course_code = trim($group['course_code'] ?? '');
    $year_lvl    = intval($group['year_level'] ?? 0);
    $sec_name    = trim($group['section_name'] ?? '');
    $students    = $group['students'] ?? [];
    $sched_codes = $group['schedule_codes'] ?? [];

    if (empty($subj_name) && empty($subj_code)) continue;
    if (empty($subj_name)) $subj_name = $subj_code;
    if (empty($subj_code)) $subj_code = $subj_name;

    $subj_code_esc = $conn->real_escape_string($subj_code);
    $subj_name_esc = $conn->real_escape_string($subj_name);
    $course_esc    = $conn->real_escape_string($course_code);
    $sec_esc       = $conn->real_escape_string($sec_name);

    // 1. Maintain Master Subject Catalog (is_subject_only = 1)
    $catCheck = $conn->query("
        SELECT id FROM classes 
        WHERE teacher_code = '$tc_esc' 
          AND is_subject_only = 1 
          AND (UPPER(TRIM(class_name)) = UPPER(TRIM('$subj_name_esc')) OR UPPER(TRIM(subject)) = UPPER(TRIM('$subj_code_esc')))
        LIMIT 1
    ");
    if (!$catCheck || $catCheck->num_rows === 0) {
        $catCode = strtoupper(preg_replace('/[^A-Z0-9]/i', '', substr($subj_code, 0, 8)));
        $catCodeEsc = $conn->real_escape_string($catCode);
        $conn->query("INSERT IGNORE INTO classes 
            (class_code, class_name, subject, program_code, teacher_code, is_subject_only, is_archived, created_at)
            VALUES 
            ('$catCodeEsc', '$subj_name_esc', '$subj_code_esc', '$course_esc', '$tc_esc', 1, 0, NOW())");
    }

    // 2. Check if the active class already exists for this Section & Year
    $classQuery = $conn->query("
        SELECT id, class_code FROM classes 
        WHERE teacher_code = '$tc_esc'
          AND (is_subject_only = 0 OR is_subject_only IS NULL)
          AND (is_archived = 0 OR is_archived IS NULL)
          AND UPPER(TRIM(class_name)) = UPPER(TRIM('$subj_name_esc'))
          AND UPPER(TRIM(program_code)) = UPPER(TRIM('$course_esc'))
          AND year_level = $year_lvl
          AND UPPER(TRIM(section)) = UPPER(TRIM('$sec_esc'))
          AND (school_year = '$ay_esc' OR school_year IS NULL OR school_year = '')
        LIMIT 1
    ");

    $class_id = 0;
    if ($classQuery && $classQuery->num_rows > 0) {
        $existing = $classQuery->fetch_assoc();
        $class_id = (int)$existing['id'];
        $updatedClassesCount++;
        // Update school_year if not set
        $conn->query("UPDATE classes SET school_year = '$ay_esc' WHERE id = $class_id AND (school_year IS NULL OR school_year = '')");
    } else {
        // Generate unique 6-character class code
        $baseCode = strtoupper(preg_replace('/[^A-Z0-9]/i', '', substr($subj_code, 0, 6)));
        if (strlen($baseCode) < 3) $baseCode = 'CLS';
        $randSuffix = strtoupper(substr(md5(uniqid($subj_code . $sec_name, true)), 0, 3));
        $newCode = substr($baseCode, 0, 3) . $randSuffix;
        
        // Ensure uniqueness
        while (true) {
            $codeEsc = $conn->real_escape_string($newCode);
            $checkUnique = $conn->query("SELECT id FROM classes WHERE class_code = '$codeEsc' LIMIT 1");
            if (!$checkUnique || $checkUnique->num_rows === 0) break;
            $newCode = substr($baseCode, 0, 2) . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
        }

        $codeEsc = $conn->real_escape_string($newCode);
        $schedJson = $conn->real_escape_string(json_encode([
            'technopal_schedule_codes' => $sched_codes,
            'synced_at'                => date('Y-m-d H:i:s'),
            'academic_year'            => $ay,
            'semester'                 => $sem,
        ]));

        $insClass = $conn->query("
            INSERT INTO classes 
            (class_code, class_name, subject, section, year_level, program_code, teacher_code, schedule_json, school_year, is_subject_only, is_archived, created_at)
            VALUES 
            ('$codeEsc', '$subj_name_esc', '$subj_code_esc', '$sec_esc', $year_lvl, '$course_esc', '$tc_esc', '$schedJson', '$ay_esc', 0, 0, NOW())
        ");

        if ($insClass) {
            $class_id = (int)$conn->insert_id;
            $createdClassesCount++;
            // Auto-enroll teacher in class_members
            $conn->query("INSERT IGNORE INTO class_members (class_id, user_code) VALUES ($class_id, '$tc_esc')");
        }
    }

    if (!$class_id) continue;

    // 3. Process and Enroll Students from this Group
    $groupEnrolled = 0;
    foreach ($students as $st) {
        $st_code = trim($st['user_code'] ?? '');
        if (empty($st_code)) continue;

        $st_fn = trim($st['first_name'] ?? '');
        $st_mn = trim($st['middle_name'] ?? '');
        $st_ln = trim($st['last_name'] ?? '');

        $st_code_esc = $conn->real_escape_string($st_code);
        $st_fn_esc   = $conn->real_escape_string($st_fn);
        $st_mn_esc   = $conn->real_escape_string($st_mn);
        $st_ln_esc   = $conn->real_escape_string($st_ln);

        // Ensure user account exists in users table
        $checkUser = $conn->query("SELECT id, user_code FROM users WHERE user_code = '$st_code_esc' LIMIT 1");
        if (!$checkUser || $checkUser->num_rows === 0) {
            $defaultPw = $conn->real_escape_string(password_hash($st_code, PASSWORD_DEFAULT));
            $conn->query("
                INSERT INTO users 
                (user_code, password_hash, first_name, middle_name, last_name, year_level, section, program_code, user_group, is_active, last_login, api_cached_at)
                VALUES 
                ('$st_code_esc', '$defaultPw', '$st_fn_esc', '$st_mn_esc', '$st_ln_esc', $year_lvl, '$sec_esc', '$course_esc', 'STUDENT', 1, NULL, NOW())
            ");
        } else {
            // Update names if blank
            $conn->query("
                UPDATE users SET
                    first_name  = IF(first_name = '' OR first_name IS NULL, '$st_fn_esc', first_name),
                    middle_name = IF(middle_name = '' OR middle_name IS NULL, '$st_mn_esc', middle_name),
                    last_name   = IF(last_name = '' OR last_name IS NULL, '$st_ln_esc', last_name),
                    year_level  = IF(year_level = 0 OR year_level IS NULL, $year_lvl, year_level),
                    section     = IF(section = '' OR section IS NULL, '$sec_esc', section),
                    program_code= IF(program_code = '' OR program_code IS NULL, '$course_esc', program_code)
                WHERE user_code = '$st_code_esc'
            ");
        }

        // Enroll in class_members
        $insMember = $conn->query("INSERT IGNORE INTO class_members (class_id, user_code) VALUES ($class_id, '$st_code_esc')");
        if ($insMember && $conn->affected_rows > 0) {
            $groupEnrolled++;
            $totalEnrolledCount++;
        }

        // Set class_confirmation to accepted
        $conn->query("
            INSERT INTO class_confirmations (class_id, student_code, status, responded_at)
            VALUES ($class_id, '$st_code_esc', 'accepted', NOW())
            ON DUPLICATE KEY UPDATE status = 'accepted', responded_at = NOW()
        ");
    }

    $syncedDetails[] = [
        'subject'   => "$subj_code - $subj_name",
        'section'   => "$course_code $year_lvl$sec_name",
        'students'  => count($students),
        'enrolled'  => $groupEnrolled,
    ];
}

$archiveMsg = $archivedOldCount > 0 ? ", $archivedOldCount past subjects archived to repository" : "";
echo json_encode([
    'success'              => true,
    'msg'                  => "Successfully synced with TechnoPal! ($createdClassesCount new classes created$archiveMsg, $updatedClassesCount updated, $totalEnrolledCount student enrollments added).",
    'created_classes'      => $createdClassesCount,
    'updated_classes'      => $updatedClassesCount,
    'archived_old_classes' => $archivedOldCount,
    'total_enrolled'       => $totalEnrolledCount,
    'details'              => $syncedDetails,
]);
