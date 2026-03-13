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

      // Whitelist
      echo "<tr class='tab_bg_1'>";
      echo "<td class='col-form-label'>";
      echo "<i class='fas fa-check-circle me-1 text-success'></i> ";
      echo __('Whitelist Domains', 'mailanalyzer');
      echo "<br><small class='text-muted'>";
      echo __('Never block emails from these domains (one per line, e.g., @important.com)', 'mailanalyzer');
      echo "</small>";
      echo "</td>";
      echo "<td>";
      echo "<textarea name='whitelist_domains' class='form-control' rows='3'>" . Html::entities_deep($config['whitelist_domains'] ?? '') . "</textarea>";
      echo "</td></tr>";

      // Blacklist
      echo "<tr class='tab_bg_1'>";
      echo "<td class='col-form-label'>";
      echo "<i class='fas fa-times-circle me-1 text-danger'></i> ";
      echo __('Blacklist Domains', 'mailanalyzer');
      echo "<br><small class='text-muted'>";
      echo __('Always block emails from these domains (one per line, e.g., @spam.com)', 'mailanalyzer');
      echo "</small>";
      echo "</td>";
      echo "<td>";
      echo "<textarea name='blacklist_domains' class='form-control' rows='3'>" . Html::entities_deep($config['blacklist_domains'] ?? '') . "</textarea>";
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

      // Show Health Check
      self::showHealthCheck();

      // Show statistics dashboard below the config form
      echo "<br>";
      $period = $_SESSION['plugin_mailanalyzer_stats_period'] ?? '30days';
      PluginMailanalyzerStats::showDashboard($period);

      return false;
   }

   /**
    * Display Health Check for Mail Collectors
    */
   public static function showHealthCheck(): void
   {
      global $DB;

      echo "<div class='center mt-4'>";
      echo "<table class='tab_cadre_fixe'>";
      echo "<tr><th colspan='4'>";
      echo "<i class='fas fa-heartbeat me-1'></i> ";
      echo __('Mail Collectors Health Check', 'mailanalyzer');
      echo "</th></tr>";
      echo "<tr class='tab_bg_2'>";
      echo "<th>" . __('Collector Name', 'mailanalyzer') . "</th>";
      echo "<th>" . __('Connection Status', 'mailanalyzer') . "</th>";
      echo "<th>" . __('Last Email Processed (Analyzer)', 'mailanalyzer') . "</th>";
      echo "<th>" . __('Errors Count', 'mailanalyzer') . "</th>";
      echo "</tr>";

      $res = $DB->request([
         'FROM'  => 'glpi_mailcollectors'
      ]);

      if (!count($res)) {
         echo "<tr class='tab_bg_1'><td colspan='4' class='center'>" . __('No active mail collectors found.', 'mailanalyzer') . "</td></tr>";
      }

      foreach ($res as $mc) {
         echo "<tr class='tab_bg_1'>";
         
         // Name
         echo "<td><i class='fas fa-inbox me-1'></i> " . htmlspecialchars($mc['name']) . "</td>";

         // Connection Status
         $hasErrors = (int)$mc['errors'] > 0;
         if ($hasErrors) {
            echo "<td><span class='badge bg-danger'><i class='fas fa-exclamation-triangle'></i> " . __('Failing', 'mailanalyzer') . "</span></td>";
         } else {
            echo "<td><span class='badge bg-success'><i class='fas fa-check-circle'></i> " . __('OK', 'mailanalyzer') . "</span></td>";
         }

         // Last Email Processed
         $lastDate = __('Never', 'mailanalyzer');
         $resStats = $DB->request([
            'SELECT' => ['MAX' => 'date_created AS last_date'],
            'FROM'   => 'glpi_plugin_mailanalyzer_stats',
            'WHERE'  => ['mailcollectors_id' => $mc['id']]
         ]);
         if ($row = $resStats->current()) {
            if (!empty($row['last_date'])) {
               $lastDate = Html::convDateTime($row['last_date']);
            }
         }
         echo "<td>" . $lastDate . "</td>";

         // Errors Count
         echo "<td>" . (int)$mc['errors'] . "</td>";
         
         echo "</tr>";
      }

      echo "</table>";
      echo "</div>";
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
