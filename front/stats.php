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

if (isset($_POST['filter_stats'])) {
    Session::checkCSRF($_POST);
    $allowed = ['7days', '30days', '90days', 'all'];
    $period  = $_POST['period'] ?? '30days';
    if (in_array($period, $allowed, true)) {
        $_SESSION['plugin_mailanalyzer_stats_period'] = $period;
    }
}

Html::back();
