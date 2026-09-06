<?php
include __DIR__ . '/../system/includes/conn.php';
include __DIR__ . '/../system/shared/analytics_engine.php';

// Find student code for JOHN CARL DARA-UG or first student
$stuQ = $conn->query("SELECT user_code, first_name, last_name, user_group FROM users WHERE first_name LIKE '%JOHN%' OR last_name LIKE '%DARA%' LIMIT 10");
echo "=== STUDENTS IN SYSTEM ===\n";
$firstStudent = null;
while($s = $stuQ->fetch_assoc()) {
    echo "Code: {$s['user_code']} | Group: {$s['user_group']} | Name: {$s['first_name']} {$s['last_name']}\n";
    if(strpos(strtoupper($s['first_name']), 'JOHN CARL') !== false || strpos(strtoupper($s['last_name']), 'DARA') !== false) {
        $firstStudent = $s['user_code'];
    }
}
if(!$firstStudent) {
    $stuQ2 = $conn->query("SELECT DISTINCT student_code FROM quiz_submissions LIMIT 5");
    if($stuQ2 && $r2 = $stuQ2->fetch_assoc()) {
        $firstStudent = $r2['student_code'];
    }
}

$uc = '2023119490'; // JOHN CARL DARA-UG
$stuChk = $conn->query("SELECT user_code, first_name, last_name FROM users WHERE user_code='$uc'");
if($stuChk && $stuChk->num_rows > 0) {
    $sRow = $stuChk->fetch_assoc();
    echo "\nAnalyzing for: {$sRow['first_name']} {$sRow['last_name']} ({$uc})\n";
} else {
    $uc = $firstStudent;
    echo "\nAnalyzing for default student: {$uc}\n";
}

echo "\n=== ENROLLED CLASSES FOR {$uc} ===\n";
$cq = $conn->query("SELECT cm.class_id, c.class_name, c.subject FROM class_members cm JOIN classes c ON cm.class_id=c.id WHERE cm.user_code='$uc'");
while($c = $cq->fetch_assoc()) {
    echo "Class ID: {$c['class_id']} | Class: {$c['class_name']} | Subject: {$c['subject']}\n";
}

echo "\n=== TOPIC PERFORMANCE ENTRIES (topic_performance table) ===\n";
$tpq = $conn->query("SELECT tp.*, c.class_name FROM topic_performance tp JOIN classes c ON tp.class_id=c.id WHERE tp.student_code='$uc'");
if($tpq && $tpq->num_rows > 0) {
    while($tp = $tpq->fetch_assoc()) {
        $pct = round(($tp['total_points_earned'] / max(1, $tp['total_points_available'])) * 100, 1);
        echo "Class: {$tp['class_name']} (ID: {$tp['class_id']}) | Topic: {$tp['topic']} | Score: {$pct}% | Attempts: {$tp['attempts']} | Pts: {$tp['total_points_earned']}/{$tp['total_points_available']}\n";
    }
} else {
    echo "NO entries found in topic_performance table for {$uc}!\n";
}

echo "\n=== QUIZ SUBMISSIONS FOR {$uc} ===\n";
$sq = $conn->query("SELECT qs.*, q.title AS quiz_title, c.class_name, c.id AS class_id FROM quiz_submissions qs JOIN quizzes q ON qs.quiz_id=q.id JOIN classes c ON q.class_id=c.id WHERE qs.student_code='$uc'");
if($sq && $sq->num_rows > 0) {
    while($s = $sq->fetch_assoc()) {
        $pct = round(($s['score'] / max(1, $s['total_points'])) * 100, 1);
        echo "Class: {$s['class_name']} (ID: {$s['class_id']}) | Quiz: {$s['quiz_title']} (ID: {$s['quiz_id']}) | Score: {$s['score']}/{$s['total_points']} ({$pct}%)\n";
    }
} else {
    echo "NO quiz submissions found for {$uc}!\n";
}

echo "\n=== ALL QUIZZES IN SYSTEM BY CLASS ===\n";
$allQ = $conn->query("SELECT q.id, q.title, q.class_id, q.is_active, c.class_name FROM quizzes q JOIN classes c ON q.class_id=c.id ORDER BY c.id, q.id");
if($allQ) {
    while($qRow = $allQ->fetch_assoc()) {
        echo "Class ID: {$qRow['class_id']} ({$qRow['class_name']}) | Quiz ID: {$qRow['id']} | Title: {$qRow['title']} | Active: {$qRow['is_active']}\n";
    }
}

echo "\n=== EXECUTING cenlearn_topic_recommendations() ===\n";
$recs = cenlearn_topic_recommendations($conn, $uc);
echo "Has Data: " . ($recs['has_data'] ? 'YES' : 'NO') . "\n";
echo "Total Topic Recs: " . count($recs['weak_topics']) . "\n";
foreach($recs['weak_topics'] as $w) {
    echo "- Class: {$w['class_name']} | Topic: {$w['topic']} | Score: {$w['score_pct']}%\n";
}
