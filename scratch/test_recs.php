<?php
include 'system/includes/conn.php';
include 'system/shared/analytics_engine.php';

// Find a student code
$res = $conn->query("SELECT user_code FROM users WHERE user_group='STUDENT' LIMIT 1");
if($row = $res->fetch_assoc()) {
    $uc = $row['user_code'];
    echo "Testing for student: $uc\n\n";

    $studRecs = cenlearn_student_recommendations($conn, $uc);
    echo "cenlearn_student_recommendations keys:\n";
    print_r(array_keys($studRecs));
    echo "Recommendations count: " . count($studRecs['recommendations'] ?? []) . "\n\n";

    $topicRecs = cenlearn_topic_recommendations($conn, $uc);
    echo "cenlearn_topic_recommendations keys:\n";
    print_r(array_keys($topicRecs));
    echo "Weak topics count: " . count($topicRecs['weak_topics'] ?? []) . "\n";
    echo "Topic recommendations count: " . count($topicRecs['recommendations'] ?? []) . "\n";
}
