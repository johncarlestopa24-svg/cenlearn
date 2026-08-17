<?php
$callerDir = str_replace('\\', '/', realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__)));
$systemDir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$rel = ltrim(str_replace($systemDir, '', $callerDir), '/');
$depth = ($rel === '') ? 0 : (substr_count($rel, '/') + 1);
$assetPrefix = str_repeat('../', $depth);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, viewport-fit=cover">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>CenLearn</title>
  
  <!-- Preconnect & DNS-Prefetch for Fast Asset Loading -->
  <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  
  <!-- Core Stylesheets -->
  <link rel="stylesheet" href="<?php echo $assetPrefix; ?>bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?php echo $assetPrefix; ?>bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
  <link rel="stylesheet" href="<?php echo $assetPrefix; ?>dist/css/cenlearn.css?v=2.6">
</head>
