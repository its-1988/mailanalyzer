<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — Audit-log search page.
GPLv2+
--------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

Session::checkLoginUser();
Session::checkRight('config', READ);

Html::header(
    PluginMailanalyzerStats::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'config',
    PluginMailanalyzerConfig::class
);

Search::show(PluginMailanalyzerStats::class);

Html::footer();
