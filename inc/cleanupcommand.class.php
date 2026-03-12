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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to clean up orphaned records and old stats.
 *
 * Usage:
 *   php bin/console mailanalyzer:cleanup
 *   php bin/console mailanalyzer:cleanup --stats-days=90
 *   php bin/console mailanalyzer:cleanup --dry-run
 */
class PluginMailanalyzerCleanupCommand extends Command
{
   protected static $defaultName = 'mailanalyzer:cleanup';

   protected function configure(): void
   {
      $this
         ->setDescription('Clean up orphaned message_id records and old statistics')
         ->setHelp('Removes message_id records referencing tickets that no longer exist, and optionally purges old stats entries.')
         ->addOption(
            'stats-days',
            's',
            InputOption::VALUE_REQUIRED,
            'Delete stats older than N days (0 = keep all)',
            0
         )
         ->addOption(
            'dry-run',
            'd',
            InputOption::VALUE_NONE,
            'Show what would be deleted without actually deleting'
         );
   }

   protected function execute(InputInterface $input, OutputInterface $output): int
   {
      global $DB;

      $dryRun = $input->getOption('dry-run');
      $statsDays = (int) $input->getOption('stats-days');

      $output->writeln('<info>╔══════════════════════════════════════╗</info>');
      $output->writeln('<info>║     MailAnalyzer Cleanup Tool        ║</info>');
      $output->writeln('<info>╚══════════════════════════════════════╝</info>');
      $output->writeln('');

      if ($dryRun) {
         $output->writeln('<comment>⚠️  DRY-RUN mode — no changes will be made</comment>');
         $output->writeln('');
      }

      // 1. Count orphaned message_id records
      $output->writeln('<info>📧 Checking for orphaned message_id records...</info>');

      $query = "SELECT COUNT(*) AS cnt FROM `glpi_plugin_mailanalyzer_message_id` m
                LEFT JOIN `glpi_tickets` t ON m.`tickets_id` = t.`id`
                WHERE m.`tickets_id` != 0 AND t.`id` IS NULL";
      $res = $DB->doQuery($query);
      $row = $DB->fetchAssoc($res);
      $orphanCount = (int) $row['cnt'];

      if ($orphanCount > 0) {
         $output->writeln("   Found <comment>$orphanCount</comment> orphaned records");
         if (!$dryRun) {
            $purged = PluginMailanalyzerStats::purgeOrphans();
            $output->writeln("   <info>✅ Purged $purged orphaned records</info>");
         }
      } else {
         $output->writeln('   <info>✅ No orphaned records found</info>');
      }

      $output->writeln('');

      // 2. Count total records in message_id table
      $res = $DB->request([
         'COUNT' => 'cnt',
         'FROM'  => 'glpi_plugin_mailanalyzer_message_id',
      ]);
      $totalRecords = $res->current()['cnt'] ?? 0;
      $output->writeln("📊 Total message_id records: <info>$totalRecords</info>");

      // 3. Optionally purge old stats
      if ($statsDays > 0) {
         $output->writeln('');
         $output->writeln("<info>🗑️  Purging stats older than $statsDays days...</info>");

         $cutoff = date('Y-m-d H:i:s', strtotime("-$statsDays days"));

         // Count first
         $res = $DB->request([
            'COUNT' => 'cnt',
            'FROM'  => 'glpi_plugin_mailanalyzer_stats',
            'WHERE' => ['date_created' => ['<', $cutoff]],
         ]);
         $oldStatsCount = $res->current()['cnt'] ?? 0;

         if ($oldStatsCount > 0) {
            $output->writeln("   Found <comment>$oldStatsCount</comment> old stat records");
            if (!$dryRun) {
               $DB->delete('glpi_plugin_mailanalyzer_stats', [
                  'date_created' => ['<', $cutoff],
               ]);
               $output->writeln("   <info>✅ Purged $oldStatsCount old stat records</info>");
            }
         } else {
            $output->writeln('   <info>✅ No old stats to purge</info>');
         }
      }

      // 4. Show stats summary
      $output->writeln('');
      $output->writeln('<info>📈 Current statistics summary (all time):</info>');
      $summary = PluginMailanalyzerStats::getSummary('all');
      $output->writeln("   Duplicates blocked:  <comment>{$summary[PluginMailanalyzerStats::ACTION_DUPLICATE_BLOCKED]}</comment>");
      $output->writeln("   Followups created:   <comment>{$summary[PluginMailanalyzerStats::ACTION_FOLLOWUP_CREATED]}</comment>");
      $output->writeln("   Tickets linked:      <comment>{$summary[PluginMailanalyzerStats::ACTION_TICKET_LINKED]}</comment>");
      $output->writeln("   New tickets:         <comment>{$summary[PluginMailanalyzerStats::ACTION_NEW_TICKET]}</comment>");
      $total = array_sum($summary);
      $output->writeln("   <info>Total processed:       $total</info>");

      $output->writeln('');
      $output->writeln('<info>Done! ✨</info>');

      return Command::SUCCESS;
   }
}
