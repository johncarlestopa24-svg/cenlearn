<?php
/**
 * CenLearn Root Entry Point & Environment Router
 * Serves system application seamlessly at root without changing the browser URL.
 */
chdir(__DIR__ . '/system');
require __DIR__ . '/system/index.php';

