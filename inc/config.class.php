<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI
Copyright (C) 2011-2025 by Raynet SAS a company of A.Raymond Network.

https://www.araymond.com/
-------------------------------------------------------------------------

LICENSE

This file is part of MailAnalyzer plugin for GLPI.

This file is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This plugin is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this plugin. If not, see <http://www.gnu.org/licenses/>.
--------------------------------------------------------------------------
 */

/**
 * Configuration class for the MailAnalyzer plugin.
 * Provides a single settings tab in the GLPI Configuration page
 * with both configuration and statistics.
 */
class PluginMailanalyzerConfig extends CommonDBTM
{

   /**
    * @param int $nb Plural count
    * @return string
    */
   public static function getTypeName($nb = 0): string
   {
      return __('Mail Analyzer setup', 'mailanalyzer');
   }

   /**
    * @param bool $with_comment Include comment
    * @return string
    */
   public function getName($with_comment = 0): string
   {
      return __('MailAnalyzer', 'mailanalyzer');
   }

   /**
    * Icon displayed in the sidebar tab.
    * @return string FontAwesome icon class
    */
   public static function getIcon(): string
   {
      return 'ti ti-mail';
   }


   /**
    * Display the configuration form for the plugin.
    *
    * @param CommonGLPI $item The config item
    * @return bool
    */
   public static function showConfigForm(CommonGLPI $item): bool
   {
      $config = Config::getConfigurationValues('plugin:mailanalyzer');

      if (!isset($config['use_threadindex'])) {
         $config['use_threadindex'] = 0;
      }

      echo "<form name='form' action=\"" . Toolbox::getItemTypeFormURL('Config') . "\" method='post' data-track-changes='true'>";

      echo "<div class='center'>";
      echo "<table class='tab_cadre_fixe'>";

      // Header
      echo "<tr><th colspan='2'>";
      echo "<i class='fas fa-cogs me-1'></i> ";
      echo __('Mail Analyzer setup', 'mailanalyzer');
      echo "</th></tr>";

      // Thread-Index option
      echo "<tr class='tab_bg_1'>";
      echo "<td class='col-form-label'>";
      echo "<i class='fas fa-project-diagram me-1 text-muted'></i> ";
      echo __('Use of Thread index', 'mailanalyzer');
      echo "<br><small class='text-muted'>";
      echo __('Enable Microsoft Exchange Thread-Index header support for improved conversation tracking', 'mailanalyzer');
      echo "</small>";
      echo "</td>";
      echo "<td>";
      Dropdown::showYesNo("use_threadindex", $config['use_threadindex']);
      echo "</td></tr>";

      // Info row
      echo "<tr class='tab_bg_1'>";
      echo "<td colspan='2'>";
      echo "<div class='alert alert-info d-flex align-items-center'>";
      echo "<i class='fas fa-info-circle fa-lg me-2'></i>";
      echo "<div>";
      echo "<strong>" . __('How it works', 'mailanalyzer') . "</strong><br>";
      echo __('This plugin analyzes email headers (Message-ID, References, Thread-Index) to automatically combine related emails into the same ticket, preventing duplicates when CC recipients use "Reply to All".', 'mailanalyzer');
      echo "</div>";
      echo "</div>";
      echo "</td></tr>";

      // Save button
      echo "<tr class='tab_bg_2'>";
      echo "<td colspan='2' class='center'>";
      echo "<input type='submit' name='update' class='btn btn-primary' value=\"" . _sx('button', 'Save') . "\">";
      echo "</td></tr>";

      echo "</table></div>";

      echo "<input type='hidden' name='id' value='1'>";
      echo "<input type='hidden' name='config_context' value='plugin:mailanalyzer'>";

      Html::closeForm();

      // Show statistics dashboard below the config form
      echo "<br>";
      $period = $_GET['period'] ?? '30days';
      PluginMailanalyzerStats::showDashboard($period);

      return false;
   }


   /**
    * @param CommonGLPI $item
    * @param int $withtemplate
    * @return string
    */
   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
   {
      if ($item->getType() == 'Config') {
         return "<span class='d-flex align-items-center'><i class='ti ti-mail me-2'></i>" . __('Mail Analyzer', 'mailanalyzer') . "</span>";
      }
      return '';
   }


   /**
    * @param CommonGLPI $item
    * @param int $tabnum
    * @param int $withtemplate
    * @return bool
    */
   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
   {
      if ($item->getType() == 'Config') {
         self::showConfigForm($item);
      }
      return true;
   }
}
