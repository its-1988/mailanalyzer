<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — POST endpoint for dashboard period filter.
GPLv2+
--------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

Session::checkLoginUser();
Session::checkRight('config', READ);

// Selecting a dashboard period is a harmless view preference, so this is a GET:
// the kernel does not CSRF-check bodyless GET requests. (The previous POST form
// also called Session::checkCSRF() manually, which double-consumed the token
// the kernel had already validated — an automatic AccessDenied.)
$allowed = ['7days', '30days', '90days', 'all'];
$period  = $_GET['period'] ?? '';
if (in_array($period, $allowed, true)) {
    $_SESSION['plugin_mailanalyzer_stats_period'] = $period;
}

Html::back();
