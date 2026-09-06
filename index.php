<?php
/**
 * CenLearn Root Entry Point & Environment Router
 * Redirects to the clean login endpoint while preserving query strings.
 */
$queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: login' . $queryString);
exit;

