<?php
// ============================================================
// CenLearn — Automatic Database Table ID Resequencer
// Fixes all tables so primary key IDs are continuous: 1, 2, 3, 4, 5...
// ============================================================

require_once __DIR__ . '/../includes/conn.php';

// Fetch all tables in current database
$tablesResult = $conn->query("SHOW TABLES");
$tables = [];
if ($tablesResult) {
    while ($row = $tablesResult->fetch_array()) {
        $tables[] = $row[0];
    }
}

$resequenced = [];
$errors = [];

foreach ($tables as $table) {
    // Check if table has an 'id' column
    $colCheck = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'id'");
    if ($colCheck && $colCheck->num_rows > 0) {
        // Disable foreign key checks temporarily during update
        $conn->query("SET FOREIGN_KEY_CHECKS = 0;");
        $conn->query("SET @count = 0;");
        $q1 = $conn->query("UPDATE `$table` SET `id` = (@count := @count + 1);");
        $q2 = $conn->query("ALTER TABLE `$table` AUTO_INCREMENT = 1;");
        $conn->query("SET FOREIGN_KEY_CHECKS = 1;");

        if ($q1 && $q2) {
            $resequenced[] = $table;
        } else {
            $errors[] = "$table: " . $conn->error;
        }
    }
}

// Return JSON if requested or CLI, else render HTML message
if (isset($_GET['json']) || php_sapi_name() === 'cli') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'resequenced_tables' => $resequenced,
        'errors' => $errors
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, user-scalable=yes">
    <title>Database ID Resequencer — CenLearn</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #0f172a; padding: 20px; }
        .card { background: #fff; max-width: 600px; margin: 20px auto; padding: 24px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        h2 { color: #0f172a; margin-top: 0; }
        ul { padding-left: 20px; color: #334155; }
        li { margin-bottom: 6px; }
        .badge { background: #dcfce7; color: #166534; padding: 3px 8px; border-radius: 4px; font-weight: 600; font-size: 12px; }
        .btn { display: inline-block; padding: 10px 20px; background: #0284c7; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="card">
        <h2>✅ Database IDs Successfully Resequenced</h2>
        <p>All database tables with primary key <code>id</code> columns have been updated to continuous sequential numbers (<strong>1, 2, 3, 4, 5, 6, 7, 8, 9...</strong>) without missing numbers.</p>

        <h3>Updated Tables:</h3>
        <ul>
            <?php foreach ($resequenced as $t): ?>
                <li><strong><?php echo htmlspecialchars($t); ?></strong> <span class="badge">Fixed 1..N</span></li>
            <?php endforeach; ?>
        </ul>

        <?php if (!empty($errors)): ?>
            <h3 style="color:#dc2626;">Errors:</h3>
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li style="color:#dc2626;"><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <a href="dashboard.php" class="btn">&larr; Return to Dashboard</a>
    </div>
</body>
</html>
