<?php
$sql = file_get_contents(__DIR__ . '/../database/cenlearn_db.sql');
preg_match_all('/CREATE TABLE `([^`]+)` \((.*?)\) ENGINE/s', $sql, $tableMatches, PREG_SET_ORDER);

$tableCols = [];
foreach ($tableMatches as $m) {
    $tName = $m[1];
    $body = $m[2];
    $cols = [];
    foreach (explode("\n", $body) as $line) {
        $line = trim($line);
        if (preg_match('/^`([^`]+)`/', $line, $colM)) {
            $cols[] = $colM[1];
        }
    }
    $tableCols[$tName] = $cols;
}

preg_match_all('/INSERT INTO `([^`]+)` VALUES \s*(.*?);/s', $sql, $insertMatches, PREG_SET_ORDER);
foreach ($insertMatches as $im) {
    $tName = $im[1];
    $valStr = trim($im[2]);
    // It could be multiple tuples: (1,...),(2,...)
    // Let's split by ),(
    preg_match_all('/\((.*?)\)(?:,|$)/s', $valStr, $rowMatches);
    foreach ($rowMatches[1] as $idx => $rowStr) {
        $parsed = str_getcsv($rowStr, ',', "'");
        $expectedCount = isset($tableCols[$tName]) ? count($tableCols[$tName]) : 'unknown';
        if ($expectedCount !== 'unknown' && count($parsed) !== $expectedCount) {
            echo "MISMATCH in table '$tName' row $idx: expected $expectedCount, got " . count($parsed) . "\n";
            echo "Cols: " . implode(', ', $tableCols[$tName]) . "\n";
            echo "Row data: " . substr($rowStr, 0, 150) . "...\n\n";
            break; // only print once per table
        }
    }
}
echo "Done.\n";
