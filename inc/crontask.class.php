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

class PluginMailanalyzerCrontask extends CommonDBTM {

   /**
    * Description and parameters of the task
    *
    * @param string $name Name of the task
    * @return array
    */
   static function cronInfo($name) {
      return [
         'description' => __('Cleanup orphan entries and old statistics', 'mailanalyzer'),
         'parameter'   => __('Days to keep statistics (0 = infinite)', 'mailanalyzer')
      ];
   }

   /**
    * Execute the task
    *
    * @param CronTask $task Action object
    * @return int 0: Nothing to do, 1: Done, -1: Error
    */
   static function cronMailanalyzerCleanup(CronTask $task) {
      $days_to_keep = (int)$task->fields['param'];
      $processed = 0;

      $task->addVolume(1); // Set volume to at least 1 to trigger completion

      // 1. Limpar registros orfãos
      $purged = PluginMailanalyzerStats::purgeOrphans();
      if ($purged > 0) {
          $task->log("Orphaned records purged: $purged");
          $processed += $purged;
      }

      // 2. Limpar estatísticas antigas se o parametro for maior que 0
      if ($days_to_keep > 0) {
         global $DB;
         $cutoff = date('Y-m-d H:i:s', strtotime("-$days_to_keep days"));
         
         $DB->delete('glpi_plugin_mailanalyzer_stats', [
            'date_created' => ['<', $cutoff],
         ]);
         
         $purged_stats = $DB->affectedRows();
         if ($purged_stats > 0) {
             $task->log("Old statistics records purged: $purged_stats");
             $processed += $purged_stats;
         }
      }

      if ($processed > 0) {
          return 1;
      }
      
      return 0;
   }
}
