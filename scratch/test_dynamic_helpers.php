<?php
/**
 * Test Suite for CenLearn Dynamic Metadata Helpers
 */
require_once __DIR__ . '/../system/includes/programs.php';

$allPassed = true;
function assertEqual($actual, $expected, $label) {
    global $allPassed;
    if ($actual === $expected) {
        echo "  [PASS] $label\n";
    } else {
        echo "  [FAIL] $label - Expected: " . json_encode($expected) . ", Got: " . json_encode($actual) . "\n";
        $allPassed = false;
    }
}

echo "=== 1. Testing Normalization Helpers ===\n";
assertEqual(normalize_program_code(' bsis '), 'IS', 'normalize_program_code alias BSIS -> IS');
assertEqual(normalize_program_code('BS-CRIM'), 'CRIM', 'normalize_program_code alias BS-CRIM -> CRIM');
assertEqual(normalize_program_code('BSED-FILIPINO'), 'BSED-FILIPINO', 'normalize_program_code BSED-FILIPINO');
assertEqual(normalize_program_code(null), '', 'normalize_program_code null -> empty string');
assertEqual(normalize_program_code('   '), '', 'normalize_program_code whitespace -> empty string');

assertEqual(normalize_section(' a '), 'A', 'normalize_section lowercase -> uppercase');
assertEqual(normalize_section('Section-1A'), 'SECTION-1A', 'normalize_section compound');
assertEqual(normalize_section(null), '', 'normalize_section null -> empty string');

assertEqual(normalize_academic_year('2026-2027'), '2026-2027', 'normalize_academic_year standard');
assertEqual(normalize_academic_year('2026/2027'), '2026-2027', 'normalize_academic_year slash -> dash');
assertEqual(normalize_academic_year('2026'), '2026-2027', 'normalize_academic_year single year -> range');
assertEqual(normalize_academic_year(null), '', 'normalize_academic_year null -> empty string');

echo "\n=== 2. Testing Fallback With Null Connection ===\n";
$nullPrograms = get_all_programs(null);
assertEqual(!empty($nullPrograms), true, 'get_all_programs(null) returns non-empty presets');
assertEqual(isset($nullPrograms[0]['code']), true, 'get_all_programs(null) has code property');

$nullSections = get_all_sections(null);
assertEqual(!empty($nullSections), true, 'get_all_sections(null) returns base A-J');
assertEqual(in_array('A', $nullSections), true, 'get_all_sections(null) contains A');
assertEqual(in_array('J', $nullSections), true, 'get_all_sections(null) contains J');

$nullAys = get_academic_years(null);
assertEqual(!empty($nullAys), true, 'get_academic_years(null) returns calculated years');
$currentFound = false;
foreach ($nullAys as $ay) {
    if ($ay['is_current']) $currentFound = true;
}
assertEqual($currentFound, true, 'get_academic_years(null) identifies current academic year');

echo "\n=== 3. Testing With Live DB Connection ===\n";
require_once __DIR__ . '/../system/includes/conn.php';
if (isset($conn) && $conn && !$conn->connect_error) {
    $dbPrograms = get_all_programs($conn);
    echo "  Loaded " . count($dbPrograms) . " programs from DB & presets.\n";
    assertEqual(is_array($dbPrograms), true, 'get_all_programs($conn) returns array');

    // Ensure no duplicates
    $codes = array_column($dbPrograms, 'code');
    assertEqual(count($codes), count(array_unique($codes)), 'get_all_programs has zero duplicate codes');

    $dbSections = get_all_sections($conn);
    echo "  Loaded " . count($dbSections) . " sections from DB & presets.\n";
    assertEqual(is_array($dbSections), true, 'get_all_sections($conn) returns array');
    assertEqual(count($dbSections), count(array_unique($dbSections)), 'get_all_sections has zero duplicate sections');

    $dbAys = get_academic_years($conn);
    echo "  Loaded " . count($dbAys) . " academic years.\n";
    assertEqual(is_array($dbAys), true, 'get_academic_years($conn) returns array');
} else {
    echo "  (Live DB connection skipped or offline)\n";
}

echo "\n=== Result ===\n";
if ($allPassed) {
    echo "ALL PHASE 1 TESTS PASSED!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
