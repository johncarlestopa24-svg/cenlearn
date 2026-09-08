<?php
/**
 * CenLearn LMS — Centralized Academic Programs & Metadata Helpers
 * ================================================================
 * Provides dynamic, database-driven program, section, and academic year
 * selectors with intelligent normalization, alias resolution, and safe fallbacks.
 */

// Bago City College — Official Program List (Preserved for backward-compatibility)
$BCC_PROGRAMS = [
    ['code' => 'IS',                 'desc' => 'Information Systems'],
    ['code' => 'CRIM',               'desc' => 'Criminology'],
    ['code' => 'ARTS',               'desc' => 'Arts'],
    ['code' => 'EDUCATION',          'desc' => 'Education'],
    ['code' => 'AB ENGLISH',         'desc' => 'Bachelor of Arts in English Language'],
    ['code' => 'AB HISTORY',         'desc' => 'Bachelor of Arts in History'],
    ['code' => 'BEED',               'desc' => 'Bachelor of Elementary Education'],
    ['code' => 'BPED',               'desc' => 'Bachelor of Physical Education'],
    ['code' => 'BSED-FILIPINO',      'desc' => 'Bachelor of Secondary Education'],
    ['code' => 'BSED-MATHEMATICS',   'desc' => 'Bachelor of Secondary Education'],
    ['code' => 'BSED-SOCIAL STUDIES','desc' => 'Bachelor of Secondary Education'],
    ['code' => 'BSOA',               'desc' => 'Bachelor of Science in Office Administration'],
];

// Course codes — quick suggestions (Preserved for backward-compatibility)
$BCC_COURSE_CODES = [
    'AB ENGLISH', 'AB HISTORY',
    'BEED', 'BPED',
    'BSED-FILIPINO', 'BSED-MATHEMATICS', 'BSED-SOCIAL STUDIES',
    'BSOA', 'CRIM', 'IS',
    'GE 1', 'GE 2', 'GE 3', 'GE 4', 'GE 5', 'GE 6', 'GE 7', 'GE 8',
    'MATH 1', 'MATH 2', 'MATH 3',
    'ENG 1', 'ENG 2', 'ENG 3',
    'SCI 1', 'SCI 2',
    'PE 1', 'PE 2', 'PE 3', 'PE 4',
    'NSTP 1', 'NSTP 2',
    'IT 1', 'IT 2', 'IT 3', 'IT 4',
    'CS 1', 'CS 2', 'CS 3',
    'HIST 1', 'HIST 2',
    'FIL 1', 'FIL 2',
    'SOC SCI 1', 'SOC SCI 2',
    'RIZAL', 'ETHICS', 'LOGIC', 'STAT 1',
];
sort($BCC_COURSE_CODES);

/**
 * Known program code aliases mapped to standard canonical codes.
 */
if (!function_exists('get_program_alias_map')) {
    function get_program_alias_map(): array {
        return [
            'BSIS'                  => 'IS',
            'BS-IS'                 => 'IS',
            'INFO SYSTEMS'          => 'IS',
            'BSCRIM'                => 'CRIM',
            'BS-CRIM'               => 'CRIM',
            'CRIMINOLOGY'           => 'CRIM',
            'EDUC'                  => 'EDUCATION',
            'BSE'                   => 'EDUCATION',
            'BSIT'                  => 'IS',
            'AB-ENGLISH'            => 'AB ENGLISH',
            'AB-HISTORY'            => 'AB HISTORY',
            'BS-OA'                 => 'BSOA',
            'BSED FILIPINO'         => 'BSED-FILIPINO',
            'BSED MATHEMATICS'      => 'BSED-MATHEMATICS',
            'BSED SOCIAL STUDIES'   => 'BSED-SOCIAL STUDIES',
        ];
    }
}

/**
 * Normalize a program code string.
 */
if (!function_exists('normalize_program_code')) {
    function normalize_program_code(?string $code): string {
        if ($code === null) return '';
        $clean = strtoupper(trim($code));
        $clean = preg_replace('/\s+/', ' ', $clean);
        $clean = trim($clean, "-_ \t\n\r\0\x0B");
        if ($clean === '') return '';

        $aliases = get_program_alias_map();
        return $aliases[$clean] ?? $clean;
    }
}

/**
 * Normalize a section identifier.
 */
if (!function_exists('normalize_section')) {
    function normalize_section(?string $section): string {
        if ($section === null) return '';
        $clean = strtoupper(trim($section));
        $clean = preg_replace('/\s+/', ' ', $clean);
        return trim($clean, "-_ \t\n\r\0\x0B");
    }
}

/**
 * Normalize an academic year string into YYYY-YYYY format.
 */
if (!function_exists('normalize_academic_year')) {
    function normalize_academic_year(?string $ay): string {
        if ($ay === null) return '';
        $clean = trim($ay);
        if (preg_match('/^(\d{4})\s*[-–\/]\s*(\d{4})$/', $clean, $m)) {
            return $m[1] . '-' . $m[2];
        }
        if (preg_match('/^\d{4}$/', $clean)) {
            $yr = (int)$clean;
            return $yr . '-' . ($yr + 1);
        }
        return $clean;
    }
}

/**
 * Dynamically fetch all valid academic programs from:
 * 1. BCC official default list
 * 2. Distinct program values in `users`
 * 3. Distinct program values in `classes`
 * Returns array of ['code' => ..., 'desc' => ...]
 */
if (!function_exists('get_all_programs')) {
    function get_all_programs($conn = null): array {
        global $BCC_PROGRAMS;
        $programsByCode = [];

        // 1. Load official default presets
        if (!empty($BCC_PROGRAMS)) {
            foreach ($BCC_PROGRAMS as $p) {
                $c = normalize_program_code($p['code'] ?? '');
                if ($c !== '') {
                    $programsByCode[$c] = [
                        'code' => $c,
                        'desc' => trim($p['desc'] ?? $c)
                    ];
                }
            }
        }

        // 2 & 3. Load dynamic programs from database if connection is provided
        if ($conn && !($conn instanceof mysqli && $conn->connect_error)) {
            try {
                // From active users
                $uq = $conn->query("
                    SELECT DISTINCT program_code, program_description 
                    FROM users 
                    WHERE program_code IS NOT NULL AND program_code != ''
                ");
                if ($uq) {
                    while ($row = $uq->fetch_assoc()) {
                        $c = normalize_program_code($row['program_code']);
                        if ($c === '') continue;
                        $desc = trim($row['program_description'] ?? '');
                        if (!isset($programsByCode[$c])) {
                            $programsByCode[$c] = [
                                'code' => $c,
                                'desc' => $desc !== '' ? $desc : $c
                            ];
                        } elseif ($desc !== '' && $programsByCode[$c]['desc'] === $c) {
                            $programsByCode[$c]['desc'] = $desc;
                        }
                    }
                }

                // From classes
                $cq = $conn->query("
                    SELECT DISTINCT program_code 
                    FROM classes 
                    WHERE program_code IS NOT NULL AND program_code != ''
                ");
                if ($cq) {
                    while ($row = $cq->fetch_assoc()) {
                        $c = normalize_program_code($row['program_code']);
                        if ($c === '' || isset($programsByCode[$c])) continue;
                        $programsByCode[$c] = [
                            'code' => $c,
                            'desc' => $c
                        ];
                    }
                }
            } catch (\Throwable $e) {
                error_log("get_all_programs DB read error: " . $e->getMessage());
            }
        }

        // Sort alphabetically by program code
        ksort($programsByCode, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($programsByCode);
    }
}

/**
 * Dynamically fetch all valid sections from:
 * 1. Default A through J sections
 * 2. Distinct student sections from `users`
 * 3. Distinct class sections from `classes`
 * Returns deduplicated, naturally sorted array of strings.
 */
if (!function_exists('get_all_sections')) {
    function get_all_sections($conn = null): array {
        $sections = [];

        // 1. Default base sections A-J
        foreach (range('A', 'J') as $s) {
            $sections[$s] = true;
        }

        // 2 & 3. From users and classes
        if ($conn && !($conn instanceof mysqli && $conn->connect_error)) {
            try {
                $uq = $conn->query("
                    SELECT DISTINCT section 
                    FROM users 
                    WHERE section IS NOT NULL AND section != '' AND user_group = 'STUDENT'
                ");
                if ($uq) {
                    while ($r = $uq->fetch_assoc()) {
                        $s = normalize_section($r['section'] ?? '');
                        if ($s !== '') $sections[$s] = true;
                    }
                }

                $cq = $conn->query("
                    SELECT DISTINCT section 
                    FROM classes 
                    WHERE section IS NOT NULL AND section != ''
                ");
                if ($cq) {
                    while ($r = $cq->fetch_assoc()) {
                        $s = normalize_section($r['section'] ?? '');
                        if ($s !== '') $sections[$s] = true;
                    }
                }
            } catch (\Throwable $e) {
                error_log("get_all_sections DB read error: " . $e->getMessage());
            }
        }

        $list = array_keys($sections);
        natcasesort($list);
        return array_values($list);
    }
}

/**
 * Dynamically fetch academic years from:
 * 1. Current calculated academic year (August start)
 * 2. Upcoming future academic years (+1, +2, +3)
 * 3. Historical past academic years (-1, -2)
 * 4. Distinct `school_year` stored in `classes`
 * Returns chronologically sorted array of ['year' => '2026-2027', 'label' => '...', 'is_current' => bool].
 */
if (!function_exists('get_academic_years')) {
    function get_academic_years($conn = null): array {
        $curYear  = (int)date('Y');
        $curMonth = (int)date('n');
        // Philippine academic calendar: 1st sem starts around July/August
        $baseStart = ($curMonth >= 7) ? $curYear : ($curYear - 1);
        $currentAy = $baseStart . '-' . ($baseStart + 1);

        $ayMap = [];

        // Helper to register an AY
        $registerAy = function($sy) use (&$ayMap, $currentAy) {
            $normalized = normalize_academic_year($sy);
            if ($normalized === '' || isset($ayMap[$normalized])) return;

            $isCurrent = ($normalized === $currentAy);
            if (preg_match('/^(\d{4})-(\d{4})$/', $normalized, $m)) {
                $start = (int)$m[1];
                $curStart = (int)explode('-', $currentAy)[0];
                if ($isCurrent) {
                    $label = "$normalized (Current Academic Year)";
                } elseif ($start > $curStart) {
                    $label = "$normalized (Upcoming Future Year)";
                } else {
                    $label = "$normalized (Past Year)";
                }
            } else {
                $label = $normalized;
            }

            $ayMap[$normalized] = [
                'year'       => $normalized,
                'label'      => $label,
                'is_current' => $isCurrent
            ];
        };

        // 1 & 2. Register current, past (-2..-1), and future (+1..+3)
        for ($offset = -2; $offset <= 3; $offset++) {
            $s = $baseStart + $offset;
            $registerAy($s . '-' . ($s + 1));
        }

        // 3. Register distinct values from database
        if ($conn && !($conn instanceof mysqli && $conn->connect_error)) {
            try {
                $tblCheck = $conn->query("SHOW TABLES LIKE 'classes'");
                if ($tblCheck && $tblCheck->num_rows > 0) {
                    $colCheck = $conn->query("SHOW COLUMNS FROM `classes` LIKE 'school_year'");
                    if ($colCheck && $colCheck->num_rows > 0) {
                        $res = $conn->query("
                            SELECT DISTINCT school_year 
                            FROM classes 
                            WHERE school_year IS NOT NULL AND school_year != ''
                        ");
                        if ($res) {
                            while ($r = $res->fetch_assoc()) {
                                $registerAy($r['school_year']);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log("get_academic_years DB read error: " . $e->getMessage());
            }
        }

        // Sort chronologically by start year
        uksort($ayMap, function($a, $b) {
            $ayA = (int)explode('-', $a)[0];
            $ayB = (int)explode('-', $b)[0];
            return $ayA <=> $ayB;
        });

        return array_values($ayMap);
    }
}
