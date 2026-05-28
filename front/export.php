<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — CSV export endpoint.
GPLv2+
--------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

Session::checkLoginUser();
Session::checkRight('config', READ);

$allowed = ['7days', '30days', '90days', 'all'];
$period  = $_GET['period'] ?? ($_SESSION['plugin_mailanalyzer_stats_period'] ?? '30days');
if (!in_array($period, $allowed, true)) {
    $period = '30days';
}

PluginMailanalyzerExporter::streamCsv($period);
