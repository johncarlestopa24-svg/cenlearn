<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include '../includes/conn.php';
header('Content-Type: application/json');

if(empty($_SESSION['user'])){
    echo json_encode(['success'=>false,'msg'=>'Not logged in']);
    exit;
}

$user   = $_SESSION['user'];
$action = $_POST['action'] ?? '';

// ── Create Class ──────────────────────────────────────────────────────────
if($action === 'create'){
    $subject      = trim($_POST['subject']      ?? '');
    $subject_code = trim($_POST['subject_code'] ?? '');
    $course       = trim($_POST['course']       ?? '');
    $section      = trim($_POST['section']      ?? '');
    $year_level   = intval($_POST['year_level'] ?? 0);
    $program_code = trim($_POST['program_code'] ?? '');

    // Schedule fields
    $schedule_json       = trim($_POST['schedule_json'] ?? '');
    $schedule_room       = trim($_POST['schedule_room'] ?? '');

    $creation_type    = trim($_POST['creation_type'] ?? 'standard');
    $master_list_text = trim($_POST['master_list_text'] ?? '');

    // Helper function to strip gmail/email addresses and section markers from student names
    if (!function_exists('cleanStudentNameField')) {
        function cleanStudentNameField($str) {
            if (empty($str)) return '';
            // Strip email addresses (e.g. user@gmail.com or anything containing @)
            $str = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/i', '', $str);
            $str = preg_replace('/\b\S+@\S*\b/i', '', $str);

            // Strip section indicators (Is-3, Is-, IS-3, BSIS-3, Section 3, etc.)
            $str = preg_replace('/\b(?:BSIS|BSIT|BEED|BSED|BSCRIM|ARTS|BSOA|ACT|BLIS|BTVTED|BSNE|CS|IT)[-\s]?\d?[-\s]?[A-Z0-9]*\b/i', '', $str);
            $str = preg_replace('/\bIS[-\s]?\d*[-A-Z0-9]*\b/i', '', $str);
            $str = preg_replace('/\bIs-?\d*[-A-Z0-9]*\b/i', '', $str);
            $str = preg_replace('/\bSec(?:tion)?[-\s]?[A-Z0-9]+\b/i', '', $str);
            $str = preg_replace('/\bYr(?:ear)?[-\s]?\d+\b/i', '', $str);
            $str = preg_replace('/\bIs-?\b/i', '', $str);
            $str = preg_replace('/\bI\b$/i', '', $str);

            // Remove remaining non-letter trailing artifacts like standalone dashes or commas
            return trim(preg_replace('/\s+/', ' ', $str), " \t\n\r\0\x0B,-");
        }
    }

    // Helper function to parse Excel .xlsx file rows
    if (!function_exists('parseXlsxRosterFile')) {
        function parseXlsxRosterFile($filePath) {
            if (!class_exists('ZipArchive')) return null;
            $zip = new ZipArchive();
            if ($zip->open($filePath) !== true) return null;

            $sharedStrings = [];
            $ssXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($ssXml) {
                $xml = @simplexml_load_string($ssXml);
                if ($xml && isset($xml->si)) {
                    foreach ($xml->si as $si) {
                        if (isset($si->t)) {
                            $sharedStrings[] = (string)$si->t;
                        } elseif (isset($si->r)) {
                            $t = '';
                            foreach ($si->r as $r) $t .= (string)$r->t;
                            $sharedStrings[] = $t;
                        } else {
                            $sharedStrings[] = '';
                        }
                    }
                }
            }

            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if (!$sheetXml) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (strpos($name, 'xl/worksheets/sheet') === 0 && substr($name, -4) === '.xml') {
                        $sheetXml = $zip->getFromIndex($i);
                        break;
                    }
                }
            }

            $lines = [];
            if ($sheetXml) {
                $xml = @simplexml_load_string($sheetXml);
                if ($xml && isset($xml->sheetData->row)) {
                    foreach ($xml->sheetData->row as $rowNode) {
                        $rowData = [];
                        $maxCol = 0;
                        foreach ($rowNode->c as $cell) {
                            $r = (string)$cell['r'];
                            $colLetter = preg_replace('/[0-9]/', '', $r);
                            $colIdx = 0;
                            for ($ci = 0; $ci < strlen($colLetter); $ci++) {
                                $colIdx = $colIdx * 26 + (ord(strtoupper($colLetter[$ci])) - ord('A') + 1);
                            }
                            $colIdx -= 1;

                            $val = '';
                            $type = (string)$cell['t'];
                            if ($type === 's') {
                                $ssIdx = intval((string)$cell->v);
                                $val = $sharedStrings[$ssIdx] ?? '';
                            } elseif ($type === 'inlineStr') {
                                $val = (string)($cell->is->t ?? '');
                            } else {
                                $val = (string)($cell->v ?? '');
                            }

                            $rowData[$colIdx] = trim($val);
                            if ($colIdx > $maxCol) $maxCol = $colIdx;
                        }
                        $filledRow = [];
                        for ($ci = 0; $ci <= $maxCol; $ci++) {
                            $filledRow[] = $rowData[$ci] ?? '';
                        }
                        $rowStr = implode("\t", $filledRow);
                        if (trim($rowStr) !== '') {
                            $lines[] = $rowStr;
                        }
                    }
                }
            }
            $zip->close();
            return $lines;
        }
    }

    // Helper function to parse individual master list row
    if (!function_exists('parseMasterListRowData')) {
        function parseMasterListRowData($rawLine) {
            $line = trim($rawLine);
            if (empty($line)) return null;

            // Skip table header rows
            if (preg_match('/(student\s*id|user\s*code|student\s*name|last\s*name|first\s*name|middle\s*name|mobile\s*number|course|section|year\s*level|email|gmail)/i', $line)) {
                return null;
            }

            $user_code   = '';
            $last_name   = '';
            $first_name  = '';
            $middle_name = '';
            $cp_number   = '';

            // 1. Extract phone number (+639... or 09...)
            if (preg_match('/(?:\+639|09)\d{9}\b/', $line, $mPhone)) {
                $cp_number = $mPhone[0];
                $line = str_replace($mPhone[0], ' ', $line);
            }

            // 2. Extract and STRIP Email addresses
            $line = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/i', ' ', $line);
            $line = preg_replace('/\b\S+@\S*\b/i', ' ', $line);

            // 3. Extract Student ID (6 to 15 digits)
            if (preg_match('/\b\d{6,15}\b/', $line, $mCode)) {
                $user_code = $mCode[0];
                $line = trim(preg_replace('/\b' . preg_quote($mCode[0], '/') . '\b/', ' ', $line));
            }

            // 4. Strip section / program markers (e.g. BSIS-3, IS-3, Sec A)
            $secPatterns = [
                '/\b(?:BSIS|BSIT|BEED|BSED|BSCRIM|ARTS|BSOA|ACT|BLIS|BTVTED|BSNE|CS|IT)[-\s]?\d?[-\s]?[A-Z0-9]*\b/i',
                '/\bIS[-\s]?\d*[-A-Z0-9]*\b/i',
                '/\bIs-?\d*[-A-Z0-9]*\b/i',
                '/\bSec(?:tion)?[-\s]?[A-Z0-9]+\b/i',
                '/\bYr(?:ear)?[-\s]?\d+\b/i',
                '/\b\d{1,2}[-A-Z]\b/i',
                '/\bI\b$/i'
            ];
            foreach ($secPatterns as $pat) {
                $line = preg_replace($pat, ' ', $line);
            }

            // 5. Strip leading row numbers (e.g. "1.", "2)", "3 -", "#4", "1 ")
            $line = preg_replace('/^\s*#?\d{1,4}(?:[\.\)\:\-]\s*|\s+)/', '', trim($line));

            // 6. Delimiter detection (Tab, Semicolon, Comma)
            if (strpos($line, "\t") !== false) {
                $cols = array_values(array_filter(array_map('trim', explode("\t", $line)), fn($v) => $v !== ''));
            } elseif (strpos($line, ";") !== false) {
                $cols = array_values(array_filter(array_map('trim', explode(";", $line)), fn($v) => $v !== ''));
            } elseif (strpos($line, ",") !== false) {
                $cols = array_values(array_filter(array_map('trim', explode(",", $line)), fn($v) => $v !== ''));
            } else {
                $cols = [$line];
            }

            // Filter out any leftover index column
            if (!empty($cols) && preg_match('/^\d{1,4}[\.\)]?$/', $cols[0])) {
                array_shift($cols);
            }

            // Extract student ID from first col if numeric
            if (!empty($cols) && empty($user_code) && preg_match('/^\d{6,15}$/', $cols[0])) {
                $user_code = array_shift($cols);
            }

            if (count($cols) >= 3) {
                // Format: Last Name, First Name, Middle Name
                $last_name   = $cols[0];
                $first_name  = $cols[1];
                $middle_name = $cols[2];
            } elseif (count($cols) == 2) {
                // Format: Last Name, First Name (which might contain Middle Name/Initial)
                $last_name = $cols[0];
                $rest = $cols[1];
                $parts = preg_split('/\s+/', $rest);
                if (count($parts) > 1) {
                    $lastPart = end($parts);
                    if (preg_match('/^[A-Z]\.?$/i', $lastPart) || count($parts) >= 2) {
                        $middle_name = array_pop($parts);
                        $middle_name = rtrim($middle_name, '.');
                        $first_name = implode(' ', $parts);
                    } else {
                        $first_name = $rest;
                    }
                } else {
                    $first_name = $rest;
                }
            } elseif (count($cols) == 1) {
                $str = $cols[0];
                if (strpos($str, ',') !== false) {
                    [$ln, $fn] = explode(',', $str, 2) + ['', ''];
                    $last_name = trim($ln);
                    $rest = trim($fn);
                    $parts = preg_split('/\s+/', $rest);
                    if (count($parts) > 1) {
                        $middle_name = array_pop($parts);
                        $middle_name = rtrim($middle_name, '.');
                        $first_name = implode(' ', $parts);
                    } else {
                        $first_name = $rest;
                    }
                } else {
                    $parts = preg_split('/\s+/', $str);
                    if (count($parts) >= 3) {
                        $last_name = array_shift($parts);
                        $middle_name = array_pop($parts);
                        $first_name = implode(' ', $parts);
                    } elseif (count($parts) == 2) {
                        $last_name = $parts[0];
                        $first_name = $parts[1];
                    } else {
                        $last_name = $str;
                    }
                }
            }

            $last_name   = cleanStudentNameField($last_name);
            $first_name  = cleanStudentNameField($first_name);
            $middle_name = cleanStudentNameField($middle_name);

            if (empty($last_name) && empty($first_name)) return null;

            return [
                'user_code'   => $user_code,
                'last_name'   => mb_convert_case($last_name, MB_CASE_TITLE, "UTF-8"),
                'first_name'  => mb_convert_case($first_name, MB_CASE_TITLE, "UTF-8"),
                'middle_name' => mb_convert_case($middle_name, MB_CASE_TITLE, "UTF-8"),
                'cp_number'   => $cp_number
            ];
        }
    }

    // Smart Master List Parsing
    $master_student_records = []; // Stores array of ['user_code', 'first_name', 'middle_name', 'last_name', 'cp_number']
    if ($creation_type === 'masterlist') {
        $extractedLines = [];

        // Check if an Excel or CSV file was uploaded
        if (isset($_FILES['master_list_file']) && $_FILES['master_list_file']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['master_list_file']['tmp_name'];
            $origName = strtolower($_FILES['master_list_file']['name'] ?? '');

            if (substr($origName, -5) === '.xlsx') {
                $xlsxRows = parseXlsxRosterFile($tmpPath);
                if (!empty($xlsxRows)) {
                    $extractedLines = array_merge($extractedLines, $xlsxRows);
                }
            } else {
                $fileContent = @file_get_contents($tmpPath);
                if ($fileContent) {
                    $extractedLines = array_merge($extractedLines, preg_split('/\r\n|\r|\n/', $fileContent));
                }
            }
        }

        // Also check if text was directly provided / pasted
        if (!empty($master_list_text)) {
            $extractedLines = array_merge($extractedLines, preg_split('/\r\n|\r|\n/', $master_list_text));
        }

        // Self-heal any existing student records in DB that contain email or section markers
        $corruptedUsers = $conn->query("SELECT id, user_code, first_name, last_name FROM users WHERE user_group='STUDENT' AND (first_name LIKE '%@%' OR last_name LIKE '%@%' OR first_name REGEXP 'IS-[0-9]' OR last_name REGEXP 'IS-[0-9]' OR first_name LIKE '%Is-%' OR last_name LIKE '%Is-%')");
        if ($corruptedUsers && $corruptedUsers->num_rows > 0) {
            while ($uRow = $corruptedUsers->fetch_assoc()) {
                $uid   = (int)$uRow['id'];
                $cleanFn = cleanStudentNameField($uRow['first_name']);
                $cleanLn = cleanStudentNameField($uRow['last_name']);
                if (!empty($cleanFn) || !empty($cleanLn)) {
                    $fnEsc = $conn->real_escape_string($cleanFn);
                    $lnEsc = $conn->real_escape_string($cleanLn);
                    $conn->query("UPDATE users SET first_name='$fnEsc', last_name='$lnEsc' WHERE id=$uid");
                }
            }
        }

        foreach ($extractedLines as $line) {
            $parsed = parseMasterListRowData($line);
            if (!$parsed) continue;

            $user_code   = $parsed['user_code'];
            $first_name  = $parsed['first_name'];
            $middle_name = $parsed['middle_name'];
            $last_name   = $parsed['last_name'];
            $cp_number   = $parsed['cp_number'];

            $matchedUser = null;

            // 1. Match by Student ID / user_code in database if provided
            if (!empty($user_code)) {
                $ucEsc = $conn->real_escape_string($user_code);
                $q = $conn->query("SELECT user_code, first_name, middle_name, last_name, cp_number FROM users WHERE user_code='$ucEsc' LIMIT 1");
                if ($q && $q->num_rows > 0) {
                    $matchedUser = $q->fetch_assoc();
                    $cleanFn = cleanStudentNameField($matchedUser['first_name'] ?? '');
                    $cleanLn = cleanStudentNameField($matchedUser['last_name'] ?? '');
                    $cleanMn = cleanStudentNameField($matchedUser['middle_name'] ?? '');
                    if ($cleanFn !== $matchedUser['first_name'] || $cleanLn !== $matchedUser['last_name'] || (empty($cleanMn) && !empty($middle_name))) {
                        $matchedUser['first_name']  = $cleanFn ?: $first_name;
                        $matchedUser['last_name']   = $cleanLn ?: $last_name;
                        $matchedUser['middle_name'] = $cleanMn ?: $middle_name;
                        $fnEsc = $conn->real_escape_string($matchedUser['first_name']);
                        $lnEsc = $conn->real_escape_string($matchedUser['last_name']);
                        $mnEsc = $conn->real_escape_string($matchedUser['middle_name']);
                        $conn->query("UPDATE users SET first_name='$fnEsc', last_name='$lnEsc', middle_name='$mnEsc' WHERE user_code='$ucEsc'");
                    }
                }
            }

            // 2. Multi-strategy Match by Name (Last Name, First Name, Middle Name)
            if (!$matchedUser && (!empty($last_name) || !empty($first_name))) {
                $lnEsc = $conn->real_escape_string($last_name);
                $fnEsc = $conn->real_escape_string($first_name);
                $mnEsc = $conn->real_escape_string($middle_name);

                $q = $conn->query("
                    SELECT user_code, first_name, middle_name, last_name, cp_number 
                    FROM users 
                    WHERE UPPER(TRIM(last_name)) = UPPER('$lnEsc') 
                      AND (
                        UPPER(TRIM(first_name)) = UPPER('$fnEsc')
                        OR UPPER(TRIM(CONCAT(first_name, ' ', COALESCE(middle_name,'')))) = UPPER('$fnEsc')
                        OR UPPER(TRIM(CONCAT(first_name, ' ', COALESCE(middle_name,'')))) = UPPER(TRIM('$fnEsc $mnEsc'))
                        OR (UPPER(TRIM(first_name)) = UPPER('$fnEsc') AND UPPER(TRIM(COALESCE(middle_name,''))) = UPPER('$mnEsc'))
                        OR (UPPER(TRIM(first_name)) = UPPER('$fnEsc') AND UPPER(LEFT(TRIM(COALESCE(middle_name,'')), 1)) = UPPER(LEFT('$mnEsc', 1)))
                      )
                    LIMIT 1
                ");
                if ($q && $q->num_rows > 0) {
                    $matchedUser = $q->fetch_assoc();
                    if (empty($matchedUser['middle_name']) && !empty($middle_name)) {
                        $matchedUser['middle_name'] = $middle_name;
                        $ucEsc = $conn->real_escape_string($matchedUser['user_code']);
                        $mnEsc = $conn->real_escape_string($middle_name);
                        $conn->query("UPDATE users SET middle_name='$mnEsc' WHERE user_code='$ucEsc'");
                    }
                }
            }

            if ($matchedUser && !empty($matchedUser['user_code'])) {
                $master_student_records[$matchedUser['user_code']] = $matchedUser;
            } else {
                if (empty($user_code)) {
                    $nameSlug = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $last_name . '_' . $first_name));
                    $user_code = !empty($nameSlug) ? $nameSlug : ('STU_' . rand(10000, 99999));
                    // Ensure code is unique in our batch
                    if (isset($master_student_records[$user_code])) {
                        $user_code .= '_' . rand(100, 999);
                    }
                }

                $master_student_records[$user_code] = [
                    'user_code'   => $user_code,
                    'first_name'  => $first_name,
                    'middle_name' => $middle_name,
                    'last_name'   => $last_name,
                    'cp_number'   => $cp_number
                ];
            }
        }
    }

    // Auto-detect Year Level from text if masterlist text contains year indicators
    if ($creation_type === 'masterlist') {
        if (preg_match('/(?:3rd|year\s*3|3\-?[a-z]|BSIS\s*3|BSCRIM\s*3|BEED\s*3|BSED\s*3)/i', $master_list_text)) {
            if (!$year_level || $year_level == 1) $year_level = 3;
        } elseif (preg_match('/(?:2nd|year\s*2|2\-?[a-z]|BSIS\s*2|BSCRIM\s*2|BEED\s*2|BSED\s*2)/i', $master_list_text)) {
            if (!$year_level || $year_level == 1) $year_level = 2;
        } elseif (preg_match('/(?:4th|year\s*4|4\-?[a-z]|BSIS\s*4|BSCRIM\s*4|BEED\s*4|BSED\s*4)/i', $master_list_text)) {
            if (!$year_level || $year_level == 1) $year_level = 4;
        }
    }

    if(!$subject){ echo json_encode(['success'=>false,'msg'=>'Subject is required']); exit; }
    if(!$program_code){ echo json_encode(['success'=>false,'msg'=>'Program is required']); exit; }
    if(!$year_level){ echo json_encode(['success'=>false,'msg'=>'Year level is required']); exit; }

    if($creation_type !== 'masterlist') {
        if(!$section){ echo json_encode(['success'=>false,'msg'=>'Section is required']); exit; }
    } else {
        if(!$section) $section = 'A';
    }


    $sec_parts = explode(',', $section);
    $autoEnrolledTotal = 0;
    $created_codes = [];

    // Ensure schedule columns exist (auto-migrate)
    safeAddColumns($conn, 'classes', [
        'schedule_json' => 'text DEFAULT NULL',
        'schedule_room' => 'varchar(50) DEFAULT NULL'
    ]);

    // Auto-create confirmations table if needed
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

    foreach ($sec_parts as $sec_part) {
        $sec_part = trim($sec_part);
        if ($sec_part === '') continue;

        $tc   = $conn->real_escape_string($user['user_code']);
        $sb   = $conn->real_escape_string($subject);
        $sc   = $conn->real_escape_string($sec_part);
        $yl_val = $year_level > 0 ? $year_level : 0;
        $pc   = $conn->real_escape_string($program_code);

        // Fetch official Subject Code from Manage Subject catalog if not explicitly sent
        if (empty($subject_code)) {
            $managedSubjQ = $conn->query("
                SELECT class_code FROM classes 
                WHERE (UPPER(TRIM(class_name)) = UPPER(TRIM('$sb')) OR UPPER(TRIM(subject)) = UPPER(TRIM('$sb'))) 
                  AND teacher_code = '$tc' 
                  AND is_subject_only = 1 
                LIMIT 1
            ");
            if ($managedSubjQ && $managedSubjQ->num_rows > 0) {
                $subject_code = trim($managedSubjQ->fetch_assoc()['class_code']);
            }
        }

        // Check if class with same subject name, program, year level, and section already exists
        $dupCheck = $conn->query("
            SELECT c.class_code, 
                   COALESCE(
                     (SELECT class_code FROM classes s WHERE (s.class_name = c.class_name OR s.subject = c.class_name) AND s.teacher_code = c.teacher_code AND s.is_subject_only = 1 LIMIT 1),
                     c.class_code
                   ) AS subject_code
            FROM classes c
            WHERE UPPER(TRIM(c.class_name)) = UPPER(TRIM('$sb'))
              AND UPPER(TRIM(c.program_code)) = UPPER(TRIM('$pc'))
              AND c.year_level = $yl_val
              AND UPPER(TRIM(c.section)) = UPPER(TRIM('$sc'))
              AND (c.is_archived = 0 OR c.is_archived IS NULL)
            LIMIT 1
        ");

        if ($dupCheck && $dupCheck->num_rows > 0) {
            $existingRow = $dupCheck->fetch_assoc();
            $displaySubjCode = !empty($subject_code) ? $subject_code : (!empty($existingRow['subject_code']) ? $existingRow['subject_code'] : $existingRow['class_code']);
            echo json_encode([
                'success' => false,
                'msg'     => "A class for '$subject' already exists for $program_code Year $year_level Sec $sec_part (Subject Code: {$displaySubjCode}). Creation blocked because this section and year level already has this class."
            ]);
            exit;
        }

        // Base subject code from Manage Subject
        $baseCode = !empty($subject_code) ? $subject_code : strtoupper(preg_replace('/[^A-Z0-9]/i', '', substr($sb, 0, 8)));
        $code = $baseCode;

        // Ensure unique class_code in database to prevent UNIQUE constraint error on live server
        $cEsc = $conn->real_escape_string($code);
        $ck = $conn->query("SELECT id FROM classes WHERE class_code='$cEsc' LIMIT 1");
        if ($ck && $ck->num_rows > 0) {
            $code = $baseCode . '-' . strtoupper($sc);
            $counter = 1;
            while (true) {
                $cEsc2 = $conn->real_escape_string($code);
                $ck2 = $conn->query("SELECT id FROM classes WHERE class_code='$cEsc2' LIMIT 1");
                if (!$ck2 || $ck2->num_rows === 0) {
                    break;
                }
                $code = $baseCode . '-' . strtoupper($sc ?: 'SEC') . '-' . $counter;
                $counter++;
            }
        }

        $cr   = $conn->real_escape_string($course);
        $yl   = $year_level > 0 ? $year_level : 'NULL';
        $sjson = $conn->real_escape_string($schedule_json);
        $srm  = $conn->real_escape_string($schedule_room);

        $codeEsc = $conn->real_escape_string($code);
        $insRes = @$conn->query("INSERT INTO classes (class_code,class_name,subject,section,year_level,program_code,teacher_code,schedule_json,schedule_room)
                      VALUES ('$codeEsc','$sb','$cr','$sc',".($yl==='NULL'?'NULL':$yl).",'$pc','$tc','$sjson','$srm')");

        if (!$insRes) {
            // Fallback for duplicate key error on live server: append random unique suffix and retry
            $codeEsc = $conn->real_escape_string($baseCode . '-' . strtoupper($sc) . '-' . substr(md5(uniqid()), 0, 4));
            $insRes = $conn->query("INSERT INTO classes (class_code,class_name,subject,section,year_level,program_code,teacher_code,schedule_json,schedule_room)
                          VALUES ('$codeEsc','$sb','$cr','$sc',".($yl==='NULL'?'NULL':$yl).",'$pc','$tc','$sjson','$srm')");
        }

        if(!$insRes) {
            echo json_encode(['success'=>false, 'msg'=>'Failed to create class: ' . $conn->error]);
            exit;
        }
        $class_id = $conn->insert_id;

        // Auto-add teacher as member
        $conn->query("INSERT IGNORE INTO class_members (class_id,user_code) VALUES ($class_id,'$tc')");

        // Auto-enroll students
        $autoEnrolled = 0;
        if ($creation_type === 'masterlist' && !empty($master_student_records)) {
            $memVals = [];
            $confVals = [];
            foreach ($master_student_records as $mRec) {
                $sc2 = $conn->real_escape_string($mRec['user_code']);
                if ($sc2 === $tc) continue;

                $fnEsc = $conn->real_escape_string($mRec['first_name'] ?? '');
                $mnEsc = $conn->real_escape_string($mRec['middle_name'] ?? '');
                $lnEsc = $conn->real_escape_string($mRec['last_name'] ?? '');
                $cpEsc = $conn->real_escape_string($mRec['cp_number'] ?? '');

                // Ensure user account exists with proper first_name, middle_name, and last_name!
                $userCheck = $conn->query("SELECT id, first_name, middle_name, last_name FROM users WHERE user_code='$sc2' LIMIT 1");
                if (!$userCheck || $userCheck->num_rows === 0) {
                    $conn->query("INSERT IGNORE INTO users (user_code, first_name, middle_name, last_name, cp_number, user_group, program_code, year_level, section, is_active)
                                  VALUES ('$sc2', '$fnEsc', '$mnEsc', '$lnEsc', '$cpEsc', 'STUDENT', '$pc', ".($yl==='NULL'?0:$yl).", '$sc', 1)");
                } else {
                    $existing = $userCheck->fetch_assoc();
                    $exFn = $existing['first_name'] ?? '';
                    $exMn = $existing['middle_name'] ?? '';
                    $exLn = $existing['last_name'] ?? '';
                    // Update user record if existing name is empty OR contains email or section markers
                    $updates = [];
                    if (empty($exFn) || strpos($exFn, '@') !== false || preg_match('/\bIS-?\d*\b/i', $exFn) || preg_match('/\bIs-?\b/i', $exFn)) {
                        $updates[] = "first_name='$fnEsc'";
                    }
                    if (empty($exLn) || strpos($exLn, '@') !== false || preg_match('/\bIS-?\d*\b/i', $exLn) || preg_match('/\bIs-?\b/i', $exLn)) {
                        $updates[] = "last_name='$lnEsc'";
                    }
                    if (empty($exMn) && !empty($mnEsc)) {
                        $updates[] = "middle_name='$mnEsc'";
                    }
                    if (!empty($updates)) {
                        $conn->query("UPDATE users SET " . implode(', ', $updates) . " WHERE user_code='$sc2'");
                    }
                }

                $memVals[]  = "($class_id,'$sc2')";
                $confVals[] = "($class_id,'$sc2','accepted',NOW())";
                $autoEnrolled++;
            }

            if (!empty($memVals)) {
                $conn->query("INSERT IGNORE INTO class_members (class_id,user_code) VALUES " . implode(',', $memVals));
                $conn->query("INSERT INTO class_confirmations (class_id,student_code,status,responded_at) VALUES " . implode(',', $confVals) . " ON DUPLICATE KEY UPDATE status='accepted', responded_at=NOW()");
            }
        } elseif ($program_code || $year_level > 0 || $sec_part) {
            $where = ["user_group='STUDENT'", "user_code != '$tc'", "(graduated_at IS NULL OR graduated_at = '')", "is_active = 1"];
            if($program_code) $where[] = "UPPER(program_code) = '".strtoupper($conn->real_escape_string($program_code))."'";
            if($year_level > 0) $where[] = "year_level = $year_level";
            if($sec_part) $where[] = "UPPER(section) = '".strtoupper($conn->real_escape_string($sec_part))."'";

            $students = $conn->query("SELECT user_code FROM users WHERE ".implode(' AND ', $where));

            $memVals = [];
            $confVals = [];
            if($students){
                while($s = $students->fetch_assoc()){
                    $sc2 = $conn->real_escape_string($s['user_code']);
                    $memVals[] = "($class_id,'$sc2')";
                    $confVals[] = "($class_id,'$sc2','pending')";
                    $autoEnrolled++;
                }

                if(!empty($memVals)){
                    $conn->query("INSERT IGNORE INTO class_members (class_id,user_code) VALUES " . implode(',', $memVals));
                    $conn->query("INSERT IGNORE INTO class_confirmations (class_id,student_code,status) VALUES " . implode(',', $confVals));
                }
            }
        }
        $autoEnrolledTotal += $autoEnrolled;
        $created_codes[] = $code;
    }

    echo json_encode([
        'success' => true,
        'class_code' => implode(', ', $created_codes),
        'class_name' => $subject,
        'auto_enrolled' => $autoEnrolledTotal
    ]);
    exit;
}

// ── Join Class ────────────────────────────────────────────────────────────
if($action === 'join'){
    $code = strtoupper(trim($_POST['class_code'] ?? ''));
    $uc   = $conn->real_escape_string($user['user_code']);

    if(!$code){ echo json_encode(['success'=>false,'msg'=>'Class code is required']); exit; }

    $q = $conn->query("SELECT * FROM classes WHERE class_code='$code'");
    if($q->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Class not found. Check the code and try again.']); exit; }

    $class = $q->fetch_assoc();
    $cid   = $class['id'];

    // Already joined?
    $check = $conn->query("SELECT id FROM class_members WHERE class_id=$cid AND user_code='$uc'");
    if($check->num_rows > 0){ echo json_encode(['success'=>false,'msg'=>'You have already joined this class.']); exit; }

    // ── Enrollment restriction: match program_code, year_level, section ──
    $role = strtoupper($user['user_group']);
    if($role === 'STUDENT'){
        $errors = [];

        // Check program_code (course)
        if(!empty($class['program_code'])){
            $student_program = strtoupper(trim($user['program_code'] ?? ''));
            $class_program   = strtoupper(trim($class['program_code']));
            if($student_program !== $class_program){
                $errors[] = 'Course/Program (<b>'.$class_program.'</b>)';
            }
        }

        // Check year_level
        if(!empty($class['year_level'])){
            $student_year = intval($user['year_level'] ?? 0);
            $class_year   = intval($class['year_level']);
            if($student_year !== $class_year){
                $errors[] = 'Year Level (<b>Year '.$class_year.'</b>)';
            }
        }

        // Check section
        if(!empty($class['section'])){
            $student_section = strtoupper(trim($user['section'] ?? ''));
            $class_section   = strtoupper(trim($class['section']));
            if($student_section !== $class_section){
                $errors[] = 'Section (<b>'.$class_section.'</b>)';
            }
        }

        if(!empty($errors)){
            $mismatch = implode(', ', $errors);
            echo json_encode([
                'success' => false,
                'msg'     => 'You are not eligible to join this class. Your profile does not match the required: '.$mismatch.'.'
            ]);
            exit;
        }
    }

    $conn->query("INSERT INTO class_members (class_id,user_code) VALUES ($cid,'$uc')");

    // Auto-create confirmations table if needed
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

    // Students who join by code are auto-accepted (they chose to join)
    if(strtoupper($user['user_group']) === 'STUDENT'){
        $conn->query("INSERT IGNORE INTO class_confirmations (class_id,student_code,status,responded_at)
                      VALUES ($cid,'$uc','accepted',NOW())");
    }

    echo json_encode(['success'=>true,'class_name'=>$class['class_name'],'class_code'=>$code]);
    exit;
}

// ── Join by ID (no code needed — student matches class restrictions) ──────
if($action === 'join_by_id'){
    $class_id = intval($_POST['class_id'] ?? 0);
    $uc       = $conn->real_escape_string($user['user_code']);
    $role     = strtoupper($user['user_group'] ?? '');

    if(!$class_id || $role !== 'STUDENT'){
        echo json_encode(['success'=>false,'msg'=>'Invalid request']); exit;
    }

    $q = $conn->query("SELECT * FROM classes WHERE id=$class_id");
    if($q->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Class not found']); exit; }
    $class = $q->fetch_assoc();

    // Already joined?
    $chk = $conn->query("SELECT id FROM class_members WHERE class_id=$class_id AND user_code='$uc'");
    if($chk->num_rows > 0){ echo json_encode(['success'=>false,'msg'=>'Already enrolled']); exit; }

    // Re-verify the student still matches restrictions
    $errors = [];
    if(!empty($class['program_code'])){
        if(strtoupper(trim($user['program_code'] ?? '')) !== strtoupper(trim($class['program_code'])))
            $errors[] = 'program';
    }
    if(!empty($class['year_level'])){
        if(intval($user['year_level'] ?? 0) !== intval($class['year_level']))
            $errors[] = 'year level';
    }
    if(!empty($class['section'])){
        if(strtoupper(trim($user['section'] ?? '')) !== strtoupper(trim($class['section'])))
            $errors[] = 'section';
    }
    if(!empty($errors)){
        echo json_encode(['success'=>false,'msg'=>'Your profile no longer matches this class.']); exit;
    }

    $conn->query("INSERT IGNORE INTO class_members (class_id,user_code) VALUES ($class_id,'$uc')");

    // Auto-create confirmations table if needed
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

    // Students who join via the Available section are auto-accepted (they chose to join)
    $conn->query("INSERT IGNORE INTO class_confirmations (class_id,student_code,status,responded_at)
                  VALUES ($class_id,'$uc','accepted',NOW())");

    echo json_encode(['success'=>true,'class_name'=>$class['class_name']]);
    exit;
}

// ── Leave Class (Student or Teacher) ──────────────────────────────────────
if($action === 'leave_class'){
    $class_id = intval($_POST['class_id'] ?? 0);
    $uc       = $conn->real_escape_string($user['user_code']);
    $role     = strtoupper($user['user_group'] ?? '');

    if(!$class_id){
        echo json_encode(['success'=>false,'msg'=>'Missing class_id']); exit;
    }

    $q = $conn->query("SELECT * FROM classes WHERE id=$class_id");
    if(!$q || $q->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Class not found']); exit; }
    $class = $q->fetch_assoc();

    // If student: unenroll student from class
    if($role === 'STUDENT'){
        $conn->query("DELETE FROM class_members WHERE class_id=$class_id AND user_code='$uc'");
        $conn->query("DELETE FROM class_confirmations WHERE class_id=$class_id AND student_code='$uc'");
        echo json_encode(['success'=>true, 'msg'=>'You have left the class successfully.']);
        exit;
    }

    // If teacher: unassign teacher from class
    if(in_array($role, ['TEACHER', 'FACULTY', 'INSTRUCTOR'])){
        $conn->query("DELETE FROM class_members WHERE class_id=$class_id AND user_code='$uc'");
        echo json_encode(['success'=>true, 'msg'=>'You have left the class.']);
        exit;
    }

    echo json_encode(['success'=>false, 'msg'=>'Unauthorized']);
    exit;
}

echo json_encode(['success'=>false,'msg'=>'Invalid action']);
?>
