<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — Message-ID search page.
GPLv2+
--------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

Session::checkLoginUser();
Session::checkRight('config', READ);

Html::header(
    PluginMailanalyzerMessageId::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'config',
    PluginMailanalyzerConfig::class
);

Search::show(PluginMailanalyzerMessageId::class);

Html::footer();
