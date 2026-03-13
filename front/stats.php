<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI
Copyright (C) 2011-2025 by Raynet SAS a company of A.Raymond Network.
-------------------------------------------------------------------------
 */

// Este arquivo intercepta apenas requisições POST para trocar filtros da aba sem perder contexto

include ("../../../inc/includes.php");

Session::checkLoginUser();

if (isset($_POST["filter_stats"])) {
    $_SESSION['plugin_mailanalyzer_stats_period'] = $_POST['period'];
}

Html::back();
