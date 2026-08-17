<?php
/**
 * CenLearn Unified Schema Manager & Migration Sync
 * =================================================
 * Runs all table creations and column migrations idempotently.
 * Cached to execute only once per version or on explicit ?migrate=1 request,
 * keeping normal page reloads and API responses blazing fast (0ms DDL overhead).
 */

define('CENLEARN_SCHEMA_VERSION', 12);

if (!function_exists('cenlearn_sync_schema')) {
    function cenlearn_sync_schema($conn, $force = false) {
        if (!$conn || $conn->connect_error) {
            return;
        }

        // Check if schema is already up to date in session or database
        if (!$force && !empty($_SESSION['cenlearn_schema_synced_v']) && $_SESSION['cenlearn_schema_synced_v'] >= CENLEARN_SCHEMA_VERSION) {
            return;
        }

        try {
            // 1. Ensure system settings / version table exists
            $conn->query("CREATE TABLE IF NOT EXISTS `system_meta` (
                `meta_key` varchar(50) NOT NULL,
                `meta_value` varchar(255) DEFAULT NULL,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`meta_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            if (!$force) {
                $vq = $conn->query("SELECT meta_value FROM `system_meta` WHERE meta_key='schema_version' LIMIT 1");
                if ($vq && $vq->num_rows > 0) {
                    $curVer = (int)$vq->fetch_assoc()['meta_value'];
                    if ($curVer >= CENLEARN_SCHEMA_VERSION) {
                        $_SESSION['cenlearn_schema_synced_v'] = $curVer;
                        return;
                    }
                }
            }

            // 2. Core Tables Creation
            $tables = [
                // Assignments
                "CREATE TABLE IF NOT EXISTS `assignments` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `class_id` int(11) NOT NULL,
                    `teacher_code` varchar(50) NOT NULL,
                    `title` varchar(200) NOT NULL,
                    `instructions` text DEFAULT NULL,
                    `points` int(11) DEFAULT 100,
                    `due_date` datetime DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `term` varchar(20) NOT NULL DEFAULT 'midterm',
                    PRIMARY KEY (`id`),
                    KEY `class_id` (`class_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Assignment Submissions
                "CREATE TABLE IF NOT EXISTS `assignment_submissions` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `assignment_id` int(11) NOT NULL,
                    `student_code` varchar(50) NOT NULL,
                    `file_name` varchar(255) DEFAULT NULL,
                    `original_name` varchar(255) DEFAULT NULL,
                    `file_size` int(11) DEFAULT NULL,
                    `remarks` text DEFAULT NULL,
                    `grade` decimal(5,2) DEFAULT NULL,
                    `submitted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `assign_student` (`assignment_id`,`student_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Quizzes
                "CREATE TABLE IF NOT EXISTS `quizzes` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `class_id` int(11) NOT NULL,
                    `teacher_code` varchar(50) NOT NULL,
                    `title` varchar(200) NOT NULL,
                    `instructions` text DEFAULT NULL,
                    `time_limit` int(11) DEFAULT NULL,
                    `due_date` datetime DEFAULT NULL,
                    `start_date` datetime DEFAULT NULL,
                    `is_active` tinyint(1) DEFAULT 1,
                    `shuffle_questions` tinyint(1) DEFAULT 0,
                    `shuffle_answers` tinyint(1) DEFAULT 0,
                    `term` varchar(20) NOT NULL DEFAULT 'midterm',
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Quiz Questions
                "CREATE TABLE IF NOT EXISTS `quiz_questions` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `quiz_id` int(11) NOT NULL,
                    `question` text NOT NULL,
                    `question_type` varchar(20) NOT NULL DEFAULT 'multiple_choice',
                    `points` int(11) NOT NULL DEFAULT 1,
                    `options` text DEFAULT NULL,
                    `correct_answer` text DEFAULT NULL,
                    `topic` varchar(255) DEFAULT NULL,
                    `sort_order` int(11) DEFAULT 0,
                    PRIMARY KEY (`id`),
                    KEY `quiz_id` (`quiz_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Quiz Submissions
                "CREATE TABLE IF NOT EXISTS `quiz_submissions` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `quiz_id` int(11) NOT NULL,
                    `student_code` varchar(50) NOT NULL,
                    `answers` text DEFAULT NULL,
                    `score` decimal(5,2) DEFAULT NULL,
                    `total_points` int(11) DEFAULT NULL,
                    `tab_switches` int(11) DEFAULT 0,
                    `fullscreen_exits` int(11) DEFAULT 0,
                    `submitted_at` datetime DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `quiz_student` (`quiz_id`,`student_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Quiz Attempts
                "CREATE TABLE IF NOT EXISTS `quiz_attempts` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `quiz_id` int(11) NOT NULL,
                    `student_code` varchar(50) NOT NULL,
                    `start_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `last_heartbeat` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `total_paused_seconds` int(11) NOT NULL DEFAULT 0,
                    `status` enum('in_progress','completed','timed_out') NOT NULL DEFAULT 'in_progress',
                    PRIMARY KEY (`id`),
                    KEY `quiz_student_attempt` (`quiz_id`,`student_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Live Sessions
                "CREATE TABLE IF NOT EXISTS `live_sessions` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `class_id` int(11) NOT NULL,
                    `teacher_code` varchar(50) NOT NULL,
                    `room_id` varchar(100) NOT NULL,
                    `title` varchar(200) DEFAULT NULL,
                    `scheduled_at` datetime DEFAULT NULL,
                    `started_at` datetime DEFAULT NULL,
                    `ended_at` datetime DEFAULT NULL,
                    `status` enum('scheduled','live','ended') NOT NULL DEFAULT 'scheduled',
                    `term` varchar(20) NOT NULL DEFAULT 'midterm',
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `class_id` (`class_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Live Admission
                "CREATE TABLE IF NOT EXISTS `live_admission` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `session_id` int(11) NOT NULL,
                    `student_code` varchar(50) NOT NULL,
                    `status` enum('waiting','admitted','rejected') NOT NULL DEFAULT 'waiting',
                    `requested_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `session_student` (`session_id`,`student_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Live Attendance
                "CREATE TABLE IF NOT EXISTS `live_attendance` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `session_id` int(11) NOT NULL,
                    `student_code` varchar(50) NOT NULL,
                    `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `left_at` datetime DEFAULT NULL,
                    `duration_minutes` int(11) DEFAULT 0,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `session_student_att` (`session_id`,`student_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Live Peers
                "CREATE TABLE IF NOT EXISTS `live_peers` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `session_id` int(11) NOT NULL,
                    `user_code` varchar(50) NOT NULL,
                    `peer_id` varchar(100) NOT NULL,
                    `role` varchar(20) NOT NULL DEFAULT 'student',
                    `is_video_on` tinyint(1) NOT NULL DEFAULT 1,
                    `is_audio_on` tinyint(1) NOT NULL DEFAULT 1,
                    `is_screen_on` tinyint(1) NOT NULL DEFAULT 0,
                    `is_hand_raised` tinyint(1) NOT NULL DEFAULT 0,
                    `last_heartbeat` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `session_user` (`session_id`,`user_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Class Confirmations
                "CREATE TABLE IF NOT EXISTS `class_confirmations` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `class_id` int(11) NOT NULL,
                    `student_code` varchar(50) NOT NULL,
                    `status` enum('pending','accepted','declined') NOT NULL DEFAULT 'pending',
                    `responded_at` datetime DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `class_student` (`class_id`,`student_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Class Record Columns
                "CREATE TABLE IF NOT EXISTS `class_record_columns` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `class_id` int(11) NOT NULL,
                    `component` enum('written','performance','exam') NOT NULL,
                    `title` varchar(100) NOT NULL,
                    `max_score` decimal(6,2) NOT NULL DEFAULT 100,
                    `sort_order` int(11) DEFAULT 0,
                    `session_id` int(11) DEFAULT NULL,
                    `quiz_id` int(11) DEFAULT NULL,
                    `assignment_id` int(11) DEFAULT NULL,
                    `term` varchar(20) NOT NULL DEFAULT 'midterm',
                    `is_f2f` tinyint(1) NOT NULL DEFAULT 0,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `class_id` (`class_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Class Record Scores
                "CREATE TABLE IF NOT EXISTS `class_record_scores` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `column_id` int(11) NOT NULL,
                    `class_id` int(11) NOT NULL,
                    `student_code` varchar(50) NOT NULL,
                    `score` decimal(6,2) DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `col_student` (`column_id`,`student_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Class Record Weights
                "CREATE TABLE IF NOT EXISTS `class_record_weights` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `class_id` int(11) NOT NULL,
                    `written_pct` int(11) NOT NULL DEFAULT 20,
                    `performance_pct` int(11) NOT NULL DEFAULT 40,
                    `exam_pct` int(11) NOT NULL DEFAULT 30,
                    `attendance_pct` int(11) NOT NULL DEFAULT 10,
                    `grading_method` varchar(20) NOT NULL DEFAULT 'sum_of_points',
                    `base_grade` int(11) NOT NULL DEFAULT 0,
                    `midterm_weight` int(11) NOT NULL DEFAULT 40,
                    `final_weight` int(11) NOT NULL DEFAULT 60,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `class_id` (`class_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Published Grades
                "CREATE TABLE IF NOT EXISTS `published_grades` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `class_id` int(11) NOT NULL,
                    `term` varchar(20) NOT NULL,
                    `student_code` varchar(50) NOT NULL,
                    `grade` decimal(6,2) DEFAULT NULL,
                    `transmuted` varchar(10) DEFAULT NULL,
                    `remarks` varchar(20) DEFAULT NULL,
                    `published_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `class_term_student` (`class_id`,`term`,`student_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Class Modules
                "CREATE TABLE IF NOT EXISTS `class_modules` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `class_id` int(11) NOT NULL,
                    `teacher_code` varchar(50) NOT NULL,
                    `title` varchar(200) NOT NULL,
                    `description` text DEFAULT NULL,
                    `filename` varchar(255) NOT NULL,
                    `original_name` varchar(255) DEFAULT NULL,
                    `file_size` int(11) DEFAULT NULL,
                    `file_type` varchar(100) DEFAULT NULL,
                    `folder_id` int(11) DEFAULT NULL,
                    `term` varchar(20) NOT NULL DEFAULT 'midterm',
                    `topic` varchar(255) DEFAULT NULL,
                    `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `class_id` (`class_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Material Folders
                "CREATE TABLE IF NOT EXISTS `class_material_folders` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `class_id` int(11) NOT NULL,
                    `teacher_code` varchar(50) NOT NULL,
                    `name` varchar(100) NOT NULL,
                    `color` varchar(20) DEFAULT '#1792bb',
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `class_id` (`class_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Material Repository
                "CREATE TABLE IF NOT EXISTS `material_repository` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `teacher_code` varchar(50) NOT NULL,
                    `title` varchar(200) NOT NULL,
                    `description` text DEFAULT NULL,
                    `filename` varchar(255) NOT NULL,
                    `original_name` varchar(255) DEFAULT NULL,
                    `file_size` int(11) DEFAULT NULL,
                    `file_type` varchar(100) DEFAULT NULL,
                    `subject` varchar(100) DEFAULT NULL,
                    `program_code` varchar(20) DEFAULT NULL,
                    `topic` varchar(255) DEFAULT NULL,
                    `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `teacher_code` (`teacher_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Class Module Links
                "CREATE TABLE IF NOT EXISTS `class_module_links` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `class_id` int(11) NOT NULL,
                    `repo_id` int(11) NOT NULL,
                    `term` varchar(20) NOT NULL DEFAULT 'midterm',
                    `folder_id` int(11) DEFAULT NULL,
                    `linked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `class_repo_term` (`class_id`,`repo_id`,`term`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Class Syllabus
                "CREATE TABLE IF NOT EXISTS `class_syllabus` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `class_id` int(11) NOT NULL,
                    `teacher_code` varchar(50) NOT NULL,
                    `course_title` varchar(200) DEFAULT NULL,
                    `course_code` varchar(50) DEFAULT NULL,
                    `course_description` text DEFAULT NULL,
                    `credit_units` varchar(20) DEFAULT NULL,
                    `prerequisites` varchar(200) DEFAULT NULL,
                    `schedule` varchar(100) DEFAULT NULL,
                    `classroom` varchar(50) DEFAULT NULL,
                    `instructor_info` text DEFAULT NULL,
                    `consultation_hours` varchar(100) DEFAULT NULL,
                    `learning_outcomes` text DEFAULT NULL,
                    `grading_system` text DEFAULT NULL,
                    `course_policies` text DEFAULT NULL,
                    `references_list` text DEFAULT NULL,
                    `weeks_json` longtext DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `class_id` (`class_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Material Analysis
                "CREATE TABLE IF NOT EXISTS `class_material_analysis` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `module_id` int(11) NOT NULL,
                    `class_id` int(11) NOT NULL,
                    `word_count` int(11) DEFAULT 0,
                    `reading_time_min` int(11) DEFAULT 0,
                    `key_topics` text DEFAULT NULL,
                    `difficulty_level` varchar(20) DEFAULT 'intermediate',
                    `ai_summary` text DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `module_id` (`module_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Topic Performance
                "CREATE TABLE IF NOT EXISTS `topic_performance` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `student_code` varchar(50) NOT NULL,
                    `topic` varchar(255) NOT NULL,
                    `class_id` int(11) NOT NULL,
                    `total_questions` int(11) NOT NULL DEFAULT 0,
                    `correct_answers` int(11) NOT NULL DEFAULT 0,
                    `score_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
                    `attempts` int(11) NOT NULL DEFAULT 1,
                    `last_updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `student_topic_class` (`student_code`,`topic`,`class_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Class Topic Difficulty
                "CREATE TABLE IF NOT EXISTS `class_topic_difficulty` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `class_id` int(11) NOT NULL,
                    `topic` varchar(255) NOT NULL,
                    `avg_score_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
                    `students_attempted` int(11) NOT NULL DEFAULT 0,
                    `difficulty_label` varchar(20) NOT NULL DEFAULT 'Moderate',
                    `last_updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `class_topic` (`class_id`,`topic`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Subject Logbook
                "CREATE TABLE IF NOT EXISTS `subject_logbook` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `class_id` int(11) NOT NULL,
                    `teacher_code` varchar(50) NOT NULL,
                    `entry_date` date NOT NULL,
                    `term` varchar(20) NOT NULL DEFAULT 'midterm',
                    `topic_covered` varchar(255) NOT NULL,
                    `activities_done` text DEFAULT NULL,
                    `remarks` text DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `class_id` (`class_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Attendance Sessions & Records
                "CREATE TABLE IF NOT EXISTS `class_attendance_sessions` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `class_id` int(11) NOT NULL,
                    `teacher_code` varchar(50) NOT NULL,
                    `session_date` date NOT NULL,
                    `session_time` time DEFAULT NULL,
                    `term` varchar(20) NOT NULL DEFAULT 'midterm',
                    `remarks` varchar(255) DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `class_id` (`class_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                "CREATE TABLE IF NOT EXISTS `class_attendance_records` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `session_id` int(11) NOT NULL,
                    `student_code` varchar(50) NOT NULL,
                    `status` enum('present','late','absent','excused') NOT NULL DEFAULT 'present',
                    `remarks` varchar(255) DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `session_student_rec` (`session_id`,`student_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            ];

            foreach ($tables as $sql) {
                $conn->query($sql);
            }

            // 3. Dynamic Column Migrations (Safe Column Adds)
            safeAddColumns($conn, 'classes', [
                'schedule_json'   => 'text DEFAULT NULL',
                'schedule_room'   => 'varchar(50) DEFAULT NULL',
                'school_year'     => 'varchar(20) DEFAULT NULL',
                'is_archived'     => 'tinyint(1) NOT NULL DEFAULT 0',
                'archived_at'     => 'datetime DEFAULT NULL',
                'is_subject_only' => 'tinyint(1) NOT NULL DEFAULT 0'
            ]);

            safeAddColumns($conn, 'users', [
                'graduated_at'   => 'datetime DEFAULT NULL',
                'user_status'    => "varchar(20) DEFAULT 'enrolled'",
                'session_token'  => 'varchar(64) DEFAULT NULL',
                'api_cached_at'  => 'datetime DEFAULT NULL',
                'admin_override' => 'tinyint(1) DEFAULT 0',
                'department'     => 'varchar(20) DEFAULT NULL'
            ]);

            safeAddColumns($conn, 'class_modules', [
                'topic'     => 'varchar(255) DEFAULT NULL',
                'folder_id' => 'int(11) DEFAULT NULL',
                'term'      => "varchar(20) NOT NULL DEFAULT 'midterm'"
            ]);

            safeAddColumns($conn, 'quizzes', [
                'start_date'        => 'datetime DEFAULT NULL',
                'shuffle_questions' => 'tinyint(1) DEFAULT 0',
                'shuffle_answers'   => 'tinyint(1) DEFAULT 0',
                'term'              => "varchar(20) NOT NULL DEFAULT 'midterm'"
            ]);

            safeAddColumns($conn, 'quiz_questions', [
                'topic' => 'varchar(255) DEFAULT NULL'
            ]);

            safeAddColumns($conn, 'quiz_submissions', [
                'tab_switches'     => 'int(11) DEFAULT 0',
                'fullscreen_exits' => 'int(11) DEFAULT 0'
            ]);

            safeAddColumns($conn, 'quiz_attempts', [
                'last_heartbeat'       => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'total_paused_seconds' => 'int(11) NOT NULL DEFAULT 0'
            ]);

            safeAddColumns($conn, 'live_sessions', [
                'term' => "varchar(20) NOT NULL DEFAULT 'midterm'"
            ]);

            safeAddColumns($conn, 'class_record_columns', [
                'session_id'    => 'int(11) DEFAULT NULL',
                'term'          => "varchar(20) NOT NULL DEFAULT 'midterm'",
                'quiz_id'       => 'int(11) DEFAULT NULL',
                'assignment_id' => 'int(11) DEFAULT NULL',
                'is_f2f'        => 'tinyint(1) NOT NULL DEFAULT 0'
            ]);

            safeAddColumns($conn, 'class_record_weights', [
                'attendance_pct' => 'int(11) NOT NULL DEFAULT 10',
                'grading_method' => "varchar(20) NOT NULL DEFAULT 'sum_of_points'",
                'base_grade'     => 'int(11) NOT NULL DEFAULT 0',
                'midterm_weight' => 'int(11) NOT NULL DEFAULT 40',
                'final_weight'   => 'int(11) NOT NULL DEFAULT 60'
            ]);

            safeAddColumns($conn, 'assignments', [
                'term' => "varchar(20) NOT NULL DEFAULT 'midterm'"
            ]);

            // 4. Ensure Stored Procedure sp_resequence_all_ids exists
            $conn->query("DROP PROCEDURE IF EXISTS `sp_resequence_all_ids`");
            $conn->query("CREATE PROCEDURE `sp_resequence_all_ids`()
            BEGIN
                SET FOREIGN_KEY_CHECKS = 0;
                SET @count = 0; UPDATE `users` SET `id` = (@count := @count + 1); ALTER TABLE `users` AUTO_INCREMENT = 1;
                SET @count = 0; UPDATE `classes` SET `id` = (@count := @count + 1); ALTER TABLE `classes` AUTO_INCREMENT = 1;
                SET @count = 0; UPDATE `class_members` SET `id` = (@count := @count + 1); ALTER TABLE `class_members` AUTO_INCREMENT = 1;
                SET @count = 0; UPDATE `class_modules` SET `id` = (@count := @count + 1); ALTER TABLE `class_modules` AUTO_INCREMENT = 1;
                SET @count = 0; UPDATE `class_syllabus` SET `id` = (@count := @count + 1); ALTER TABLE `class_syllabus` AUTO_INCREMENT = 1;
                SET @count = 0; UPDATE `class_confirmations` SET `id` = (@count := @count + 1); ALTER TABLE `class_confirmations` AUTO_INCREMENT = 1;
                SET @count = 0; UPDATE `assignments` SET `id` = (@count := @count + 1); ALTER TABLE `assignments` AUTO_INCREMENT = 1;
                SET @count = 0; UPDATE `assignment_submissions` SET `id` = (@count := @count + 1); ALTER TABLE `assignment_submissions` AUTO_INCREMENT = 1;
                SET @count = 0; UPDATE `quizzes` SET `id` = (@count := @count + 1); ALTER TABLE `quizzes` AUTO_INCREMENT = 1;
                SET @count = 0; UPDATE `quiz_questions` SET `id` = (@count := @count + 1); ALTER TABLE `quiz_questions` AUTO_INCREMENT = 1;
                SET @count = 0; UPDATE `quiz_submissions` SET `id` = (@count := @count + 1); ALTER TABLE `quiz_submissions` AUTO_INCREMENT = 1;
                SET @count = 0; UPDATE `live_sessions` SET `id` = (@count := @count + 1); ALTER TABLE `live_sessions` AUTO_INCREMENT = 1;
                SET @count = 0; UPDATE `class_record_columns` SET `id` = (@count := @count + 1); ALTER TABLE `class_record_columns` AUTO_INCREMENT = 1;
                SET @count = 0; UPDATE `class_record_scores` SET `id` = (@count := @count + 1); ALTER TABLE `class_record_scores` AUTO_INCREMENT = 1;
                SET @count = 0; UPDATE `class_attendance_sessions` SET `id` = (@count := @count + 1); ALTER TABLE `class_attendance_sessions` AUTO_INCREMENT = 1;
                SET @count = 0; UPDATE `class_attendance_records` SET `id` = (@count := @count + 1); ALTER TABLE `class_attendance_records` AUTO_INCREMENT = 1;
                SET FOREIGN_KEY_CHECKS = 1;
            END");

            // 5. Update Schema Version in Database and Session
            $v = CENLEARN_SCHEMA_VERSION;
            $conn->query("INSERT INTO `system_meta` (`meta_key`, `meta_value`) VALUES ('schema_version', '$v') ON DUPLICATE KEY UPDATE `meta_value`='$v'");
            $_SESSION['cenlearn_schema_synced_v'] = $v;

        } catch (\Throwable $e) {
            error_log("CenLearn Schema Sync Warning: " . $e->getMessage());
        }
    }
}
