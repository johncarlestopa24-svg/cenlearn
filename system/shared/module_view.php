<?php
session_start();
include '../includes/conn.php';

if (empty($_SESSION['user'])) {
    header('location: /cenlearn/login');
    exit;
}

$user = $_SESSION['user'];
$uc   = $conn->real_escape_string($user['user_code']);
$role = strtoupper($user['user_group'] ?? '');

$repo_id = intval($_GET['repo_id'] ?? 0);
$id      = intval($_GET['id']      ?? 0);
$raw     = intval($_GET['raw']     ?? 0);

if ($repo_id) {
    $q = $conn->query("SELECT * FROM material_repository WHERE id=$repo_id AND teacher_code='$uc'");
    if (!$q || $q->num_rows === 0) { die('File not found or access denied'); }
    $mod = $q->fetch_assoc();
} elseif ($id) {
    $q = $conn->query("SELECT * FROM class_modules WHERE id=$id");
    if (!$q || $q->num_rows === 0) { die('File not found'); }
    $mod = $q->fetch_assoc();
    
    $cid = intval($mod['class_id']);
    $isTeacher = in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN']);
    
    if (!$isTeacher) {
        $chk = $conn->query("SELECT id FROM class_members WHERE class_id=$cid AND user_code='$uc'");
        if (!$chk || $chk->num_rows === 0) { die('Access denied'); }
    }
} else {
    die('Invalid request');
}

$filepath = __DIR__ . '/../uploads/modules/' . $mod['filename'];
if (!file_exists($filepath)) { die('File not found on server'); }

$filename = $mod['original_name'] ?? $mod['filename'];
$ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

// Format bytes
function formatSizeUnits($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' B';
    } elseif ($bytes == 1) {
        return $bytes . ' B';
    } else {
        return '0 B';
    }
}

$fileSizeBytes = file_exists($filepath) ? filesize($filepath) : 0;
$fileSizeFormatted = formatSizeUnits($fileSizeBytes);

// Detect MIME type
$mimeTypes = [
    'pdf'   => 'application/pdf',
    'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'doc'   => 'application/msword',
    'pptx'  => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'ppt'   => 'application/vnd.ms-powerpoint',
    'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'xls'   => 'application/vnd.ms-excel',
    'csv'   => 'text/csv; charset=UTF-8',
    'png'   => 'image/png',
    'jpg'   => 'image/jpeg',
    'jpeg'  => 'image/jpeg',
    'gif'   => 'image/gif',
    'webp'  => 'image/webp',
    'svg'   => 'image/svg+xml',
    'bmp'   => 'image/bmp',
    'ico'   => 'image/x-icon',
    'mp4'   => 'video/mp4',
    'webm'  => 'video/webm',
    'ogv'   => 'video/ogg',
    'mp3'   => 'audio/mpeg',
    'wav'   => 'audio/wav',
    'ogg'   => 'audio/ogg',
    'm4a'   => 'audio/mp4',
    'aac'   => 'audio/aac',
    'txt'   => 'text/plain; charset=UTF-8',
    'md'    => 'text/markdown; charset=UTF-8',
    'html'  => 'text/html; charset=UTF-8',
    'htm'   => 'text/html; charset=UTF-8',
    'css'   => 'text/css; charset=UTF-8',
    'js'    => 'application/javascript; charset=UTF-8',
    'json'  => 'application/json; charset=UTF-8',
    'xml'   => 'application/xml; charset=UTF-8',
    'sql'   => 'text/plain; charset=UTF-8',
    'php'   => 'text/plain; charset=UTF-8',
    'py'    => 'text/plain; charset=UTF-8',
    'java'  => 'text/plain; charset=UTF-8',
    'c'     => 'text/plain; charset=UTF-8',
    'cpp'   => 'text/plain; charset=UTF-8',
    'zip'   => 'application/zip',
];

$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

// Stream raw content if raw=1 is set
if ($raw) {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: public, max-age=3600');
    readfile($filepath);
    exit;
}

$isTeacher = (in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN']));
$accent    = $isTeacher ? '#10b981' : '#1792bb';
$accentDk  = $isTeacher ? '#059669' : '#0f5f80';
$dashLink  = isset($cid) ? "class_view?id=$cid&tab=materials" : ($isTeacher ? '/cenlearn/teacher/dashboard' : '/cenlearn/student/dashboard');
$rawUrl    = "module_view?" . ($id ? "id=$id" : "repo_id=$repo_id") . "&raw=1";
$dlUrl     = "module_download?" . ($id ? "id=$id" : "repo_id=$repo_id");

// Icon & Color mapping
$fileIcons = [
    'docx' => ['icon' => 'fa-file-word-o', 'color' => '#3b82f6', 'label' => 'DOCX Document'],
    'doc'  => ['icon' => 'fa-file-word-o', 'color' => '#3b82f6', 'label' => 'Word Document'],
    'xlsx' => ['icon' => 'fa-file-excel-o', 'color' => '#10b981', 'label' => 'Excel Spreadsheet'],
    'xls'  => ['icon' => 'fa-file-excel-o', 'color' => '#10b981', 'label' => 'Excel Spreadsheet'],
    'csv'  => ['icon' => 'fa-table', 'color' => '#10b981', 'label' => 'CSV Spreadsheet'],
    'pptx' => ['icon' => 'fa-file-powerpoint-o', 'color' => '#f97316', 'label' => 'PowerPoint Presentation'],
    'ppt'  => ['icon' => 'fa-file-powerpoint-o', 'color' => '#f97316', 'label' => 'PowerPoint Presentation'],
    'pdf'  => ['icon' => 'fa-file-pdf-o', 'color' => '#ef4444', 'label' => 'PDF Document'],
    'txt'  => ['icon' => 'fa-file-text-o', 'color' => '#06b6d4', 'label' => 'Text Document'],
    'md'   => ['icon' => 'fa-file-code-o', 'color' => '#8b5cf6', 'label' => 'Markdown Document'],
    'json' => ['icon' => 'fa-code', 'color' => '#eab308', 'label' => 'JSON File'],
    'sql'  => ['icon' => 'fa-database', 'color' => '#06b6d4', 'label' => 'SQL File'],
    'zip'  => ['icon' => 'fa-file-archive-o', 'color' => '#eab308', 'label' => 'ZIP Archive'],
    'mp4'  => ['icon' => 'fa-file-video-o', 'color' => '#ec4899', 'label' => 'Video File'],
    'webm' => ['icon' => 'fa-file-video-o', 'color' => '#ec4899', 'label' => 'Video File'],
    'mp3'  => ['icon' => 'fa-file-audio-o', 'color' => '#f59e0b', 'label' => 'Audio File'],
    'wav'  => ['icon' => 'fa-file-audio-o', 'color' => '#f59e0b', 'label' => 'Audio File'],
];

$iconInfo = $fileIcons[$ext] ?? [
    'icon' => 'fa-file-o',
    'color' => $accent,
    'label' => strtoupper($ext) . ' File'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($mod['title'] ?? $filename); ?> — Module Viewer</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/cenlearn/system/bower_components/font-awesome/css/font-awesome.min.css">
    
    <!-- Document Viewers: JSZip, Docx-Preview, Mammoth, SheetJS, Marked -->
    <script src="/cenlearn/system/plugins/doc_viewer/jszip.min.js"></script>
    <script src="/cenlearn/system/plugins/doc_viewer/docx-preview.min.js"></script>
    <script src="/cenlearn/system/plugins/doc_viewer/mammoth.browser.min.js"></script>
    <script src="/cenlearn/system/plugins/doc_viewer/xlsx.full.min.js"></script>
    <script src="/cenlearn/system/plugins/doc_viewer/marked.min.js"></script>
    
    <!-- CDN Fallback in case local files are missing -->
    <script>
        if (typeof JSZip === 'undefined') {
            document.write('<script src="/cenlearn/system/plugins/doc_viewer/jszip.min.js"><\/script>');
        }
    </script>
    <script>
        if (typeof docx === 'undefined') {
            document.write('<script src="/cenlearn/system/plugins/doc_viewer/docx-preview.min.js"><\/script>');
        }
    </script>
    <script>
        if (typeof mammoth === 'undefined') {
            document.write('<script src="/cenlearn/system/plugins/doc_viewer/mammoth.browser.min.js"><\/script>');
        }
    </script>
    <script>
        if (typeof XLSX === 'undefined') {
            document.write('<script src="/cenlearn/system/plugins/doc_viewer/xlsx.full.min.js"><\/script>');
        }
    </script>
    <script>
        if (typeof marked === 'undefined') {
            document.write('<script src="/cenlearn/system/plugins/doc_viewer/marked.min.js"><\/script>');
        }
    </script>

    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; height: 100%; width: 100%; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: #0b1120; color: #f8fafc; overflow: hidden; display: flex; flex-direction: column; }
        
        /* Header */
        .hdr {
            background: #1e293b;
            border-bottom: 1px solid #334155;
            height: 62px;
            padding: 0 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            z-index: 50;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
        }
        .hdr-left {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }
        .hdr-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 20px;
            color: <?php echo $iconInfo['color']; ?>;
        }
        .hdr-meta {
            min-width: 0;
        }
        .hdr-title {
            font-size: 15px;
            font-weight: 700;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 450px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .hdr-badge {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 2px 7px;
            border-radius: 6px;
            background: <?php echo $iconInfo['color']; ?>22;
            color: <?php echo $iconInfo['color']; ?>;
            border: 1px solid <?php echo $iconInfo['color']; ?>44;
            letter-spacing: 0.5px;
        }
        .hdr-sub {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Middle Toolbar Controls */
        .hdr-toolbar {
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid #334155;
            padding: 4px 8px;
            border-radius: 10px;
        }
        .tool-btn {
            background: transparent;
            border: none;
            color: #cbd5e1;
            padding: 6px 10px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }
        .tool-btn:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }
        .tool-btn:active {
            transform: scale(0.96);
        }
        .tool-divider {
            width: 1px;
            height: 18px;
            background: #334155;
            margin: 0 4px;
        }
        .zoom-indicator {
            font-size: 12px;
            font-weight: 700;
            color: #94a3b8;
            min-width: 48px;
            text-align: center;
            font-family: 'JetBrains Mono', monospace;
        }

        /* Right Actions */
        .hdr-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.15s;
        }
        .btn-ghost {
            background: rgba(255,255,255,0.08);
            color: #e2e8f0;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .btn-ghost:hover {
            background: rgba(255,255,255,0.16);
            color: #fff;
        }
        .btn-accent {
            background: <?php echo $accent; ?>;
            color: #fff;
            box-shadow: 0 2px 8px <?php echo $accent; ?>44;
        }
        .btn-accent:hover {
            opacity: 0.92;
            color: #fff;
            box-shadow: 0 4px 12px <?php echo $accent; ?>66;
        }

        /* Main View Container */
        .view-container {
            flex: 1;
            position: relative;
            background: #090d16;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* Scroll Area for Document Types */
        .doc-scroll-area {
            flex: 1;
            overflow: auto;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px 20px 60px;
            background: #090d16;
            scroll-behavior: smooth;
        }
        
        /* Modern Scrollbars */
        .doc-scroll-area::-webkit-scrollbar,
        .custom-scroll::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }
        .doc-scroll-area::-webkit-scrollbar-track,
        .custom-scroll::-webkit-scrollbar-track {
            background: #090d16;
        }
        .doc-scroll-area::-webkit-scrollbar-thumb,
        .custom-scroll::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 6px;
            border: 2px solid #090d16;
        }
        .doc-scroll-area::-webkit-scrollbar-thumb:hover,
        .custom-scroll::-webkit-scrollbar-thumb:hover {
            background: #475569;
        }

        /* Loading Spinner */
        .viewer-loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            text-align: center;
            z-index: 20;
        }
        .spinner {
            width: 44px;
            height: 44px;
            border: 4px solid rgba(255,255,255,0.1);
            border-top-color: <?php echo $iconInfo['color']; ?>;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .loading-text {
            font-size: 14px;
            color: #94a3b8;
        }

        /* Error Box */
        .render-error {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            padding: 36px 40px;
            text-align: center;
            max-width: 460px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            margin: auto;
        }
        .render-error h3 {
            font-size: 17px;
            margin: 0 0 8px;
            color: #f1f5f9;
        }

        /* ----------------------------------------------------
           DOCX VIEWER STYLES
           ---------------------------------------------------- */
        .docx-wrapper-container {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            transition: transform 0.15s cubic-bezier(0.4, 0, 0.2, 1);
            transform-origin: top center;
        }
        .docx-wrapper-container .docx-wrapper {
            background: transparent !important;
            padding: 0 !important;
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
        }
        .docx-wrapper-container .docx-wrapper > section {
            background: #ffffff !important;
            color: #1e293b !important;
            box-shadow: 0 12px 36px rgba(0,0,0,0.5), 0 2px 6px rgba(0,0,0,0.2) !important;
            border-radius: 4px !important;
            margin: 0 auto 30px auto !important;
            padding: 60px 70px !important;
            box-sizing: border-box;
            max-width: 860px;
            width: 100%;
            min-height: 1100px;
            font-family: 'Segoe UI', Arial, sans-serif !important;
        }
        .docx-wrapper-container .docx-wrapper > section * {
            max-width: 100%;
        }

        /* Fallback Mammoth Rendered Doc */
        .mammoth-doc-card {
            background: #ffffff;
            color: #1e293b;
            box-shadow: 0 12px 36px rgba(0,0,0,0.5);
            border-radius: 6px;
            margin: 0 auto 30px;
            padding: 60px 70px;
            max-width: 860px;
            width: 100%;
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 15px;
            line-height: 1.7;
        }
        .mammoth-doc-card h1, .mammoth-doc-card h2, .mammoth-doc-card h3 {
            color: #0f172a;
            margin-top: 1.5em;
            margin-bottom: 0.5em;
        }
        .mammoth-doc-card table {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0;
        }
        .mammoth-doc-card th, .mammoth-doc-card td {
            border: 1px solid #cbd5e1;
            padding: 8px 12px;
        }
        .mammoth-doc-card img {
            max-width: 100%;
            height: auto;
            border-radius: 4px;
        }

        /* ----------------------------------------------------
           SPREADSHEET (EXCEL/CSV) VIEWER STYLES
           ---------------------------------------------------- */
        .sheet-container {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            background: #0f172a;
        }
        .sheet-toolbar-sub {
            height: 44px;
            background: #1e293b;
            border-bottom: 1px solid #334155;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            flex-shrink: 0;
        }
        .sheet-search-input {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 6px;
            padding: 5px 12px;
            color: #fff;
            font-size: 12px;
            outline: none;
            width: 220px;
        }
        .sheet-search-input:focus {
            border-color: #10b981;
        }
        .sheet-table-wrap {
            flex: 1;
            overflow: auto;
            background: #ffffff;
            color: #0f172a;
            padding: 0;
            position: relative;
        }
        .sheet-table-wrap table {
            border-collapse: collapse;
            width: 100%;
            font-size: 13px;
            font-family: 'Segoe UI', Arial, sans-serif;
            table-layout: auto;
        }
        .sheet-table-wrap th, .sheet-table-wrap td {
            border: 1px solid #e2e8f0;
            padding: 8px 14px;
            white-space: nowrap;
            text-align: left;
        }
        .sheet-table-wrap tr:first-child th, 
        .sheet-table-wrap tr:first-child td {
            background: #f1f5f9;
            font-weight: 700;
            position: sticky;
            top: 0;
            z-index: 10;
            border-bottom: 2px solid #cbd5e1;
            color: #0f172a;
        }
        .sheet-table-wrap tr:nth-child(even) td {
            background: #f8fafc;
        }
        .sheet-table-wrap tr:hover td {
            background: #e0f2fe !important;
        }
        .sheet-tabs-bar {
            height: 42px;
            background: #1e293b;
            border-top: 1px solid #334155;
            display: flex;
            align-items: center;
            padding: 0 12px;
            gap: 6px;
            overflow-x: auto;
            flex-shrink: 0;
        }
        .sheet-tab-btn {
            background: rgba(255,255,255,0.06);
            border: 1px solid #334155;
            color: #94a3b8;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .sheet-tab-btn:hover {
            background: rgba(255,255,255,0.12);
            color: #fff;
        }
        .sheet-tab-btn.active {
            background: #10b981;
            border-color: #10b981;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);
        }

        /* ----------------------------------------------------
           PRESENTATION (POWERPOINT) VIEWER STYLES
           ---------------------------------------------------- */
        .pptx-container {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            background: #090d16;
            position: relative;
        }
        .pptx-slide-viewer {
            flex: 1;
            overflow: auto;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 20px;
        }
        .pptx-slide-card {
            background: #ffffff;
            color: #0f172a;
            width: 100%;
            max-width: 960px;
            min-height: 540px;
            aspect-ratio: 16 / 9;
            border-radius: 12px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.6);
            padding: 48px 56px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            position: relative;
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        .pptx-slide-title {
            font-size: 26px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 20px;
            border-bottom: 2px solid #f97316;
            padding-bottom: 12px;
        }
        .pptx-slide-body {
            font-size: 16px;
            line-height: 1.8;
            color: #334155;
            flex: 1;
            overflow-y: auto;
        }
        .pptx-slide-body ul {
            padding-left: 24px;
            margin: 0;
        }
        .pptx-slide-body li {
            margin-bottom: 10px;
        }
        .pptx-slide-num {
            position: absolute;
            bottom: 18px;
            right: 24px;
            font-size: 12px;
            font-weight: 700;
            color: #94a3b8;
        }
        .pptx-nav-bar {
            height: 54px;
            background: #1e293b;
            border-top: 1px solid #334155;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            flex-shrink: 0;
        }
        .pptx-embed-frame {
            width: 100%;
            height: 100%;
            border: none;
            background: #090d16;
        }

        /* ----------------------------------------------------
           PDF & EMBED VIEWER STYLES
           ---------------------------------------------------- */
        .full-frame {
            width: 100%;
            height: 100%;
            border: none;
            background: #1e293b;
            display: block;
        }

        /* ----------------------------------------------------
           CODE, TEXT, MARKDOWN VIEWER STYLES
           ---------------------------------------------------- */
        .text-doc-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            max-width: 900px;
            width: 100%;
            margin: 0 auto 30px;
            overflow: hidden;
        }
        .text-doc-header {
            background: #0f172a;
            border-bottom: 1px solid #334155;
            padding: 10px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            color: #94a3b8;
        }
        .text-doc-content {
            padding: 24px 28px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            line-height: 1.7;
            color: #e2e8f0;
            white-space: pre-wrap;
            word-break: break-word;
            overflow-x: auto;
        }
        .md-doc-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            max-width: 860px;
            width: 100%;
            margin: 0 auto 30px;
            padding: 48px 56px;
            color: #f1f5f9;
            line-height: 1.7;
            font-size: 15px;
        }
        .md-doc-card h1, .md-doc-card h2, .md-doc-card h3 {
            color: #38bdf8;
            margin-top: 1.5em;
            margin-bottom: 0.5em;
            border-bottom: 1px solid #334155;
            padding-bottom: 8px;
        }
        .md-doc-card code {
            background: #0f172a;
            padding: 2px 6px;
            border-radius: 4px;
            color: #f43f5e;
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
        }
        .md-doc-card pre {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 16px;
            overflow-x: auto;
        }
        .md-doc-card pre code {
            background: transparent;
            padding: 0;
            color: #e2e8f0;
        }
        .md-doc-card table {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0;
        }
        .md-doc-card th, .md-doc-card td {
            border: 1px solid #334155;
            padding: 8px 12px;
        }
        .md-doc-card th {
            background: #0f172a;
        }

        /* ----------------------------------------------------
           IMAGE & MEDIA VIEWER STYLES
           ---------------------------------------------------- */
        .media-viewer-wrap {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow: auto;
            position: relative;
        }
        .media-img {
            max-width: 92%;
            max-height: 88vh;
            object-fit: contain;
            border-radius: 8px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.6);
            transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .media-video {
            max-width: 90%;
            max-height: 85vh;
            border-radius: 12px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.6);
            outline: none;
            background: #000;
        }
        .media-audio-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 15px 40px rgba(0,0,0,0.5);
            max-width: 480px;
            width: 100%;
        }
        .media-audio-card audio {
            width: 100%;
            margin-top: 24px;
            outline: none;
        }

        /* ----------------------------------------------------
           ZIP ARCHIVE VIEWER STYLES
           ---------------------------------------------------- */
        .zip-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            max-width: 800px;
            width: 100%;
            margin: 0 auto;
            overflow: hidden;
        }
        .zip-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 18px;
            border-bottom: 1px solid #334155;
            font-size: 13px;
        }
        .zip-item:last-child {
            border-bottom: none;
        }
        .zip-item:hover {
            background: rgba(255,255,255,0.03);
        }

        /* Responsive Mobile Breakpoint */
        @media (max-width: 768px) {
            .hdr {
                padding: 8px 12px;
                gap: 8px;
                flex-wrap: wrap;
            }
            .hdr-toolbar {
                order: 3;
                width: 100%;
                justify-content: center;
            }
            .doc-scroll-area {
                padding: 12px 8px;
            }
            .docx-wrapper-container .docx-wrapper > section,
            .mammoth-doc-card {
                padding: 24px 16px !important;
                border-radius: 8px;
                font-size: 13.5px;
            }
            .pptx-slide-card {
                padding: 20px 16px;
                min-height: auto;
            }
        }

        /* Print styles */
        @media print {
            body { background: #fff !important; color: #000 !important; }
            .hdr { display: none !important; }
            .doc-scroll-area { padding: 0 !important; overflow: visible !important; }
            .docx-wrapper-container .docx-wrapper > section,
            .mammoth-doc-card {
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
            }
        }
    </style>
</head>
<body>

    <!-- Header Navigation & Controls -->
    <div class="hdr">
        <div class="hdr-left">
            <div class="hdr-icon">
                <i class="fa <?php echo $iconInfo['icon']; ?>"></i>
            </div>
            <div class="hdr-meta">
                <div class="hdr-title" title="<?php echo htmlspecialchars($mod['title'] ?? $filename); ?>">
                    <?php echo htmlspecialchars($mod['title'] ?? $filename); ?>
                    <span class="hdr-badge"><?php echo strtoupper($ext); ?></span>
                </div>
                <div class="hdr-sub" title="<?php echo htmlspecialchars($filename); ?>">
                    <?php echo htmlspecialchars($filename); ?> &bull; <?php echo $fileSizeFormatted; ?>
                </div>
            </div>
        </div>

        <!-- Dynamic Toolbar for Documents, Spreadsheets, Images -->
        <div class="hdr-toolbar" id="top-toolbar">
            <?php if (in_array($ext, ['docx', 'doc', 'xlsx', 'xls', 'csv', 'md', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'])): ?>
                <button class="tool-btn" onclick="zoomOut()" title="Zoom Out"><i class="fa fa-search-minus"></i></button>
                <span class="zoom-indicator" id="zoom-val">100%</span>
                <button class="tool-btn" onclick="zoomIn()" title="Zoom In"><i class="fa fa-search-plus"></i></button>
                <button class="tool-btn" onclick="zoomReset()" title="Reset Zoom"><i class="fa fa-arrows-alt"></i> Fit</button>
                <div class="tool-divider"></div>
            <?php endif; ?>

            <?php if (in_array($ext, ['docx', 'doc', 'md', 'txt', 'html'])): ?>
                <button class="tool-btn" onclick="window.print()" title="Print Document"><i class="fa fa-print"></i> Print</button>
                <div class="tool-divider"></div>
            <?php endif; ?>

            <button class="tool-btn" onclick="toggleFullScreen()" title="Toggle Fullscreen" id="fs-btn">
                <i class="fa fa-expand"></i> Fullscreen
            </button>
        </div>

        <div class="hdr-right">
            <a href="<?php echo $dlUrl; ?>" class="btn btn-accent" download>
                <i class="fa fa-download"></i> Download
            </a>
        </div>
    </div>

    <!-- Viewer Canvas -->
    <div class="view-container">

        <!-- 1. WORD DOCUMENT (.docx, .doc) -->
        <?php if (in_array($ext, ['docx', 'doc'])): ?>
            <div class="doc-scroll-area custom-scroll" id="doc-scroll-wrap">
                <div id="viewer-loading" class="viewer-loading">
                    <div class="spinner"></div>
                    <div class="loading-text">Rendering <strong><?php echo htmlspecialchars($filename); ?></strong>...</div>
                </div>
                <div id="docx-container" class="docx-wrapper-container" style="display:none;"></div>
            </div>

            <script>
                (function() {
                    const rawUrl = <?php echo json_encode($rawUrl); ?>;
                    const docContainer = document.getElementById('docx-container');
                    const loadingEl = document.getElementById('viewer-loading');
                    let currentZoom = 100;

                    window.zoomIn = function() {
                        if (currentZoom < 180) {
                            currentZoom += 10;
                            updateDocZoom();
                        }
                    };

                    window.zoomOut = function() {
                        if (currentZoom > 60) {
                            currentZoom -= 10;
                            updateDocZoom();
                        }
                    };

                    window.zoomReset = function() {
                        currentZoom = 100;
                        updateDocZoom();
                    };

                    function updateDocZoom() {
                        const zv = document.getElementById('zoom-val');
                        if (zv) zv.innerText = currentZoom + '%';
                        if (docContainer) {
                            docContainer.style.transform = `scale(${currentZoom / 100})`;
                        }
                    }

                    // Fetch and render DOCX directly
                    fetch(rawUrl)
                        .then(res => {
                            if (!res.ok) throw new Error('HTTP error ' + res.status);
                            return res.blob();
                        })
                        .then(blob => {
                            if (typeof docx !== 'undefined' && docx.renderAsync) {
                                return docx.renderAsync(blob, docContainer, null, {
                                    className: 'docx-page',
                                    inWrapper: true,
                                    ignoreWidth: false,
                                    ignoreHeight: false,
                                    ignoreFonts: false,
                                    breakPages: true,
                                    useBase64URL: true,
                                    renderHeaders: true,
                                    renderFooters: true,
                                    renderFootnotes: true,
                                    renderEndnotes: true
                                }).then(() => {
                                    if (loadingEl) loadingEl.style.display = 'none';
                                    docContainer.style.display = 'flex';
                                });
                            } else if (typeof mammoth !== 'undefined') {
                                return blob.arrayBuffer().then(ab => mammoth.convertToHtml({arrayBuffer: ab})).then(result => {
                                    docContainer.innerHTML = '<div class="mammoth-doc-card">' + result.value + '</div>';
                                    if (loadingEl) loadingEl.style.display = 'none';
                                    docContainer.style.display = 'flex';
                                });
                            } else {
                                throw new Error('DOCX rendering library not available');
                            }
                        })
                        .catch(err => {
                            console.warn('Primary DOCX renderer encountered an issue, trying Mammoth HTML fallback:', err);
                            fetch(rawUrl)
                                .then(r => r.arrayBuffer())
                                .then(ab => {
                                    if (typeof mammoth !== 'undefined') {
                                        return mammoth.convertToHtml({arrayBuffer: ab});
                                    }
                                    throw new Error('Mammoth fallback unavailable');
                                })
                                .then(result => {
                                    docContainer.innerHTML = '<div class="mammoth-doc-card">' + (result.value || '<p>Document loaded.</p>') + '</div>';
                                    if (loadingEl) loadingEl.style.display = 'none';
                                    docContainer.style.display = 'flex';
                                })
                                .catch(e => {
                                    console.error('All docx renderers failed:', e);
                                    if (loadingEl) {
                                        loadingEl.innerHTML = `
                                            <div class="render-error">
                                                <i class="fa fa-file-word-o" style="font-size:48px;color:#3b82f6;margin-bottom:14px;display:block;"></i>
                                                <h3>Unable to render Word Document directly</h3>
                                                <p style="color:#94a3b8;font-size:13px;margin-bottom:16px;">This document format can be downloaded or opened directly.</p>
                                                <a href="<?php echo $dlUrl; ?>" class="btn btn-accent" download><i class="fa fa-download"></i> Download File</a>
                                            </div>
                                        `;
                                    }
                                });
                        });
                })();
            </script>

        <!-- 2. SPREADSHEET (.xlsx, .xls, .csv) -->
        <?php elseif (in_array($ext, ['xlsx', 'xls', 'csv'])): ?>
            <div class="sheet-container" id="sheet-container">
                <div class="sheet-toolbar-sub">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <i class="fa fa-search" style="color:#94a3b8;font-size:12px;"></i>
                        <input type="text" id="sheet-filter" class="sheet-search-input" placeholder="Search in spreadsheet..." onkeyup="filterSheetRows()">
                    </div>
                    <div id="sheet-row-count" style="font-size:12px;color:#94a3b8;">Loading rows...</div>
                </div>
                <div class="sheet-table-wrap custom-scroll" id="sheet-table-wrap">
                    <div id="viewer-loading" class="viewer-loading">
                        <div class="spinner"></div>
                        <div class="loading-text">Parsing spreadsheet...</div>
                    </div>
                </div>
                <div class="sheet-tabs-bar" id="sheet-tabs-bar"></div>
            </div>

            <script>
                (function() {
                    const rawUrl = <?php echo json_encode($rawUrl); ?>;
                    const tableWrap = document.getElementById('sheet-table-wrap');
                    const tabsBar = document.getElementById('sheet-tabs-bar');
                    let workbook = null;
                    let currentSheetIndex = 0;
                    let currentZoom = 100;

                    window.zoomIn = function() {
                        if (currentZoom < 180) {
                            currentZoom += 10;
                            tableWrap.style.zoom = currentZoom + '%';
                            document.getElementById('zoom-val').innerText = currentZoom + '%';
                        }
                    };
                    window.zoomOut = function() {
                        if (currentZoom > 60) {
                            currentZoom -= 10;
                            tableWrap.style.zoom = currentZoom + '%';
                            document.getElementById('zoom-val').innerText = currentZoom + '%';
                        }
                    };
                    window.zoomReset = function() {
                        currentZoom = 100;
                        tableWrap.style.zoom = '100%';
                        document.getElementById('zoom-val').innerText = '100%';
                    };

                    fetch(rawUrl)
                        .then(r => r.arrayBuffer())
                        .then(ab => {
                            if (typeof XLSX === 'undefined') throw new Error('SheetJS XLSX not found');
                            workbook = XLSX.read(ab, {type: 'array', cellDates: true});
                            if (!workbook.SheetNames || workbook.SheetNames.length === 0) throw new Error('No sheets found');

                            tabsBar.innerHTML = workbook.SheetNames.map((name, i) => `
                                <button class="sheet-tab-btn ${i === 0 ? 'active' : ''}" onclick="switchSheet(${i})">
                                    <i class="fa fa-table"></i> ${escapeHtml(name)}
                                </button>
                            `).join('');

                            switchSheet(0);
                        })
                        .catch(err => {
                            console.error('XLSX error:', err);
                            tableWrap.innerHTML = `
                                <div class="render-error" style="margin-top:60px;">
                                    <i class="fa fa-file-excel-o" style="font-size:48px;color:#10b981;margin-bottom:14px;display:block;"></i>
                                    <h3>Unable to parse spreadsheet</h3>
                                    <p style="color:#94a3b8;font-size:13px;margin-bottom:16px;">This spreadsheet could not be converted to table view.</p>
                                    <a href="<?php echo $dlUrl; ?>" class="btn btn-accent" download><i class="fa fa-download"></i> Download Spreadsheet</a>
                                </div>
                            `;
                        });

                    window.switchSheet = function(idx) {
                        currentSheetIndex = idx;
                        document.querySelectorAll('.sheet-tab-btn').forEach((btn, i) => {
                            if (i === idx) btn.classList.add('active');
                            else btn.classList.remove('active');
                        });
                        const name = workbook.SheetNames[idx];
                        const sheet = workbook.Sheets[name];
                        const html = XLSX.utils.sheet_to_html(sheet, {header: '', footer: ''});
                        tableWrap.innerHTML = html;

                        const rows = tableWrap.querySelectorAll('tr');
                        const rowCountEl = document.getElementById('sheet-row-count');
                        if (rowCountEl) rowCountEl.innerText = (rows.length ? rows.length - 1 : 0) + ' rows';
                    };

                    window.filterSheetRows = function() {
                        const filter = document.getElementById('sheet-filter').value.toLowerCase();
                        const rows = tableWrap.querySelectorAll('tbody tr, table tr');
                        let visible = 0;
                        rows.forEach((row, i) => {
                            if (i === 0) return; // Keep header
                            const text = row.innerText.toLowerCase();
                            if (!filter || text.includes(filter)) {
                                row.style.display = '';
                                visible++;
                            } else {
                                row.style.display = 'none';
                            }
                        });
                        const rowCountEl = document.getElementById('sheet-row-count');
                        if (rowCountEl) rowCountEl.innerText = visible + ' matching rows';
                    };

                    function escapeHtml(str) {
                        return (str || '').replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[m]);
                    }
                })();
            </script>

        <!-- 3. POWERPOINT (.pptx, .ppt) -->
        <?php elseif (in_array($ext, ['pptx', 'ppt'])): ?>
            <div class="pptx-container" id="pptx-container">
                <div class="pptx-slide-viewer custom-scroll" id="pptx-slide-viewer">
                    <div id="viewer-loading" class="viewer-loading">
                        <div class="spinner"></div>
                        <div class="loading-text">Loading presentation slides...</div>
                    </div>
                    <div id="pptx-slide-card" class="pptx-slide-card" style="display:none;">
                        <div class="pptx-slide-title" id="pptx-slide-title">Slide Title</div>
                        <div class="pptx-slide-body custom-scroll" id="pptx-slide-body">Slide Content</div>
                        <div class="pptx-slide-num" id="pptx-slide-num">Slide 1 of 1</div>
                    </div>
                </div>
                <div class="pptx-nav-bar">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <button class="tool-btn" onclick="prevSlide()" id="pptx-prev-btn"><i class="fa fa-chevron-left"></i> Previous</button>
                        <span id="pptx-nav-counter" style="font-size:13px;font-weight:700;color:#94a3b8;min-width:90px;text-align:center;">Slide 1 / 1</span>
                        <button class="tool-btn" onclick="nextSlide()" id="pptx-next-btn">Next <i class="fa fa-chevron-right"></i></button>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <button class="tool-btn" onclick="toggleIframeView()" id="pptx-embed-toggle">
                            <i class="fa fa-window-maximize"></i> Alternate Viewer
                        </button>
                    </div>
                </div>
            </div>

            <script>
                (function() {
                    const rawUrl = <?php echo json_encode($rawUrl); ?>;
                    const loadingEl = document.getElementById('viewer-loading');
                    const slideCard = document.getElementById('pptx-slide-card');
                    const slideTitle = document.getElementById('pptx-slide-title');
                    const slideBody = document.getElementById('pptx-slide-body');
                    const slideNum = document.getElementById('pptx-slide-num');
                    const navCounter = document.getElementById('pptx-nav-counter');
                    
                    let slidesData = [];
                    let currentSlide = 0;
                    let usingIframe = false;

                    // Parse PPTX using JSZip
                    fetch(rawUrl)
                        .then(r => r.arrayBuffer())
                        .then(ab => JSZip.loadAsync(ab))
                        .then(zip => {
                            // Find all slides: ppt/slides/slide1.xml, slide2.xml...
                            const slideFileNames = [];
                            zip.forEach((path, file) => {
                                if (path.match(/^ppt\/slides\/slide\d+\.xml$/i)) {
                                    slideFileNames.push(path);
                                }
                            });

                            // Sort slides numerically
                            slideFileNames.sort((a, b) => {
                                const na = parseInt((a.match(/\d+/) || [0])[0]);
                                const nb = parseInt((b.match(/\d+/) || [0])[0]);
                                return na - nb;
                            });

                            if (slideFileNames.length === 0) throw new Error('No slides found in PPTX');

                            // Read all slides XML
                            const promises = slideFileNames.map((path, idx) => {
                                return zip.file(path).async('string').then(xmlStr => {
                                    const parser = new DOMParser();
                                    const doc = parser.parseFromString(xmlStr, 'application/xml');
                                    
                                    // Extract text from text elements (<a:t>)
                                    const textNodes = Array.from(doc.getElementsByTagName('a:t'));
                                    const texts = textNodes.map(t => t.textContent.trim()).filter(t => t.length > 0);
                                    
                                    const title = texts.length > 0 ? texts[0] : ('Slide ' + (idx + 1));
                                    const bullets = texts.length > 1 ? texts.slice(1) : [];

                                    return {
                                        num: idx + 1,
                                        title: title,
                                        bullets: bullets
                                    };
                                });
                            });

                            return Promise.all(promises);
                        })
                        .then(slides => {
                            slidesData = slides;
                            if (loadingEl) loadingEl.style.display = 'none';
                            slideCard.style.display = 'flex';
                            renderSlide(0);
                        })
                        .catch(err => {
                            console.warn('Direct PPTX XML parse fallback:', err);
                            // Fallback to Office / Google Docs viewer embed or direct view
                            toggleIframeView();
                        });

                    function renderSlide(idx) {
                        if (!slidesData || slidesData.length === 0) return;
                        currentSlide = idx;
                        const s = slidesData[idx];
                        slideTitle.innerText = s.title;
                        
                        if (s.bullets.length > 0) {
                            slideBody.innerHTML = '<ul>' + s.bullets.map(b => '<li>' + escapeHtml(b) + '</li>').join('') + '</ul>';
                        } else {
                            slideBody.innerHTML = '<p style="color:#64748b;font-style:italic;">(No additional text on this slide)</p>';
                        }

                        slideNum.innerText = `Slide ${s.num} of ${slidesData.length}`;
                        navCounter.innerText = `Slide ${s.num} / ${slidesData.length}`;
                        
                        document.getElementById('pptx-prev-btn').disabled = (currentSlide === 0);
                        document.getElementById('pptx-next-btn').disabled = (currentSlide === slidesData.length - 1);
                    }

                    window.prevSlide = function() {
                        if (currentSlide > 0) renderSlide(currentSlide - 1);
                    };

                    window.nextSlide = function() {
                        if (currentSlide < slidesData.length - 1) renderSlide(currentSlide + 1);
                    };

                    window.toggleIframeView = function() {
                        const container = document.getElementById('pptx-slide-viewer');
                        if (!usingIframe) {
                            usingIframe = true;
                            const officeViewerUrl = `https://view.officeapps.live.com/op/embed.aspx?src=${encodeURIComponent(window.location.origin + '/' + rawUrl)}`;
                            const gdocsViewerUrl = `https://docs.google.com/viewer?url=${encodeURIComponent(window.location.origin + '/' + rawUrl)}&embedded=true`;
                            
                            container.innerHTML = `
                                <iframe src="${officeViewerUrl}" class="pptx-embed-frame" onerror="this.src='${gdocsViewerUrl}'"></iframe>
                            `;
                            document.getElementById('pptx-embed-toggle').innerHTML = '<i class="fa fa-th-large"></i> Slide Deck View';
                        } else {
                            location.reload();
                        }
                    };

                    function escapeHtml(str) {
                        return (str || '').replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[m]);
                    }

                    // Keyboard navigation
                    window.addEventListener('keydown', e => {
                        if (e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' ') {
                            nextSlide();
                        } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
                            prevSlide();
                        }
                    });
                })();
            </script>

        <!-- 4. PDF DOCUMENT (.pdf) -->
        <?php elseif ($ext === 'pdf'): ?>
            <iframe src="<?php echo $rawUrl; ?>" class="full-frame" type="application/pdf"></iframe>

        <!-- 5. MARKDOWN DOCUMENT (.md) -->
        <?php elseif ($ext === 'md'): ?>
            <div class="doc-scroll-area custom-scroll">
                <div id="viewer-loading" class="viewer-loading">
                    <div class="spinner"></div>
                    <div class="loading-text">Rendering Markdown...</div>
                </div>
                <div class="md-doc-card" id="md-content" style="display:none;"></div>
            </div>

            <script>
                fetch(<?php echo json_encode($rawUrl); ?>)
                    .then(r => r.text())
                    .then(txt => {
                        document.getElementById('viewer-loading').style.display = 'none';
                        const mdCard = document.getElementById('md-content');
                        mdCard.innerHTML = (typeof marked !== 'undefined') ? marked.parse(txt) : txt;
                        mdCard.style.display = 'block';
                    });
            </script>

        <!-- 6. CODE & TEXT DOCUMENTS (.txt, .json, .sql, .html, .css, .js, .py, .php, etc.) -->
        <?php elseif (in_array($ext, ['txt', 'json', 'sql', 'html', 'htm', 'css', 'js', 'py', 'php', 'java', 'c', 'cpp', 'xml'])): ?>
            <div class="doc-scroll-area custom-scroll">
                <div id="viewer-loading" class="viewer-loading">
                    <div class="spinner"></div>
                    <div class="loading-text">Loading file contents...</div>
                </div>
                <div class="text-doc-card" id="text-card" style="display:none;">
                    <div class="text-doc-header">
                        <span><i class="fa fa-code"></i> Source Code / Text View</span>
                        <button class="tool-btn" onclick="copyTextContent()"><i class="fa fa-copy"></i> Copy</button>
                    </div>
                    <pre class="text-doc-content" id="text-content"></pre>
                </div>
            </div>

            <script>
                fetch(<?php echo json_encode($rawUrl); ?>)
                    .then(r => r.text())
                    .then(txt => {
                        document.getElementById('viewer-loading').style.display = 'none';
                        const textCard = document.getElementById('text-card');
                        document.getElementById('text-content').innerText = txt;
                        textCard.style.display = 'block';
                    });

                window.copyTextContent = function() {
                    const text = document.getElementById('text-content').innerText;
                    navigator.clipboard.writeText(text).then(() => {
                        alert('Copied to clipboard!');
                    });
                };
            </script>

        <!-- 7. IMAGES (.png, .jpg, .jpeg, .gif, .webp, .svg, .bmp) -->
        <?php elseif (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'ico'])): ?>
            <div class="media-viewer-wrap custom-scroll">
                <img src="<?php echo $rawUrl; ?>" alt="Module Preview" class="media-img" id="media-img">
            </div>

            <script>
                let imgZoom = 100;
                const img = document.getElementById('media-img');
                window.zoomIn = function() {
                    if (imgZoom < 250) {
                        imgZoom += 15;
                        img.style.transform = `scale(${imgZoom / 100})`;
                        document.getElementById('zoom-val').innerText = imgZoom + '%';
                    }
                };
                window.zoomOut = function() {
                    if (imgZoom > 40) {
                        imgZoom -= 15;
                        img.style.transform = `scale(${imgZoom / 100})`;
                        document.getElementById('zoom-val').innerText = imgZoom + '%';
                    }
                };
                window.zoomReset = function() {
                    imgZoom = 100;
                    img.style.transform = 'scale(1)';
                    document.getElementById('zoom-val').innerText = '100%';
                };
            </script>

        <!-- 8. VIDEO (.mp4, .webm, .ogv) -->
        <?php elseif (in_array($ext, ['mp4', 'webm', 'ogv'])): ?>
            <div class="media-viewer-wrap">
                <video controls autoplay src="<?php echo $rawUrl; ?>" class="media-video"></video>
            </div>

        <!-- 9. AUDIO (.mp3, .wav, .ogg, .m4a, .aac) -->
        <?php elseif (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac'])): ?>
            <div class="media-viewer-wrap">
                <div class="media-audio-card">
                    <i class="fa fa-music" style="font-size:48px;color:#f59e0b;margin-bottom:12px;display:block;"></i>
                    <h3 style="margin:0 0 6px;color:#fff;font-size:18px;"><?php echo htmlspecialchars($filename); ?></h3>
                    <p style="color:#94a3b8;font-size:13px;margin:0;"><?php echo $fileSizeFormatted; ?> &bull; Audio Track</p>
                    <audio controls autoplay src="<?php echo $rawUrl; ?>"></audio>
                </div>
            </div>

        <!-- 10. ZIP ARCHIVE (.zip) -->
        <?php elseif ($ext === 'zip'): ?>
            <div class="doc-scroll-area custom-scroll">
                <div id="viewer-loading" class="viewer-loading">
                    <div class="spinner"></div>
                    <div class="loading-text">Reading archive structure...</div>
                </div>
                <div class="zip-card" id="zip-card" style="display:none;">
                    <div class="text-doc-header">
                        <span><i class="fa fa-archive"></i> Archive File Contents</span>
                        <a href="<?php echo $dlUrl; ?>" class="btn btn-accent" download><i class="fa fa-download"></i> Download All</a>
                    </div>
                    <div id="zip-list"></div>
                </div>
            </div>

            <script>
                fetch(<?php echo json_encode($rawUrl); ?>)
                    .then(r => r.arrayBuffer())
                    .then(ab => JSZip.loadAsync(ab))
                    .then(zip => {
                        document.getElementById('viewer-loading').style.display = 'none';
                        const zipList = document.getElementById('zip-list');
                        const entries = [];
                        zip.forEach((relativePath, zipEntry) => {
                            entries.push(zipEntry);
                        });
                        
                        zipList.innerHTML = entries.map(entry => `
                            <div class="zip-item">
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <i class="fa ${entry.dir ? 'fa-folder-o' : 'fa-file-o'}" style="color:${entry.dir ? '#eab308' : '#94a3b8'}"></i>
                                    <span>${escapeHtml(entry.name)}</span>
                                </div>
                                <span style="font-size:11px;color:#64748b;">${entry.dir ? 'Folder' : (entry._data ? formatBytes(entry._data.uncompressedSize || 0) : 'File')}</span>
                            </div>
                        `).join('');
                        
                        document.getElementById('zip-card').style.display = 'block';
                    })
                    .catch(err => {
                        console.error('ZIP error:', err);
                    });

                function formatBytes(bytes) {
                    if (!bytes) return '0 B';
                    const k = 1024;
                    const sizes = ['B', 'KB', 'MB', 'GB'];
                    const i = Math.floor(Math.log(bytes) / Math.log(k));
                    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
                }
                function escapeHtml(str) {
                    return (str || '').replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[m]);
                }
            </script>

        <!-- 11. ANY OTHER EXTENSION FALLBACK (DIRECT EMBED / DOWNLOAD) -->
        <?php else: ?>
            <iframe src="<?php echo $rawUrl; ?>" class="full-frame"></iframe>
        <?php endif; ?>

    </div>

    <!-- Fullscreen script -->
    <script>
        function toggleFullScreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().then(() => {
                    const fsBtn = document.getElementById('fs-btn');
                    if (fsBtn) fsBtn.innerHTML = '<i class="fa fa-compress"></i> Exit';
                }).catch(err => console.log(err));
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen().then(() => {
                        const fsBtn = document.getElementById('fs-btn');
                        if (fsBtn) fsBtn.innerHTML = '<i class="fa fa-expand"></i> Fullscreen';
                    });
                }
            }
        }
    </script>
</body>
</html>
