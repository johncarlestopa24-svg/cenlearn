<?php
/**
 * CenLearn Root Entry Point & Environment Router
 * Redirects to the system application folder while preserving query strings.
 */
$queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: system/index.php' . $queryString);
exit;
