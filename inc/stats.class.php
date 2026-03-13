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
 * Statistics tracking and dashboard display for the MailAnalyzer plugin.
 * Records email processing events and provides a visual summary.
 */
class PluginMailanalyzerStats extends CommonDBTM
{
   // Action type constants
   public const ACTION_DUPLICATE_BLOCKED = 'duplicate_blocked';
   public const ACTION_FOLLOWUP_CREATED  = 'followup_created';
   public const ACTION_TICKET_LINKED     = 'ticket_linked';
   public const ACTION_NEW_TICKET        = 'new_ticket';

   public static function getTable($classname = null): string
   {
      return 'glpi_plugin_mailanalyzer_stats';
   }

   /**
    * Record a stats event in the database.
    *
    * @param string $action_type One of the ACTION_* constants
    * @param int    $tickets_id Related ticket ID
    * @param int    $mailcollectors_id Mail collector ID
    * @param string $message_id Email Message-ID
    * @return void
    */
   public static function record(
      string $action_type,
      int $tickets_id = 0,
      int $mailcollectors_id = 0,
      string $message_id = ''
   ): void {
      global $DB;

      $result = $DB->insert(
         'glpi_plugin_mailanalyzer_stats',
         [
            'action_type'       => $action_type,
            'tickets_id'        => $tickets_id,
            'mailcollectors_id' => $mailcollectors_id,
            'message_id'        => $message_id,
         ]
      );
      if (!$result) {
         Toolbox::logError("MailAnalyzer: Failed to record stat event: $action_type");
      }
   }

   /**
    * Get summary counts for the dashboard.
    *
    * @param string $period 'all', '7days', '30days', '90days'
    * @return array<string, int>
    */
   public static function getSummary(string $period = 'all'): array
   {
      global $DB;

      $where = [];
      switch ($period) {
         case '7days':
            $where['date_created'] = ['>=', date('Y-m-d H:i:s', strtotime('-7 days'))];
            break;
         case '30days':
            $where['date_created'] = ['>=', date('Y-m-d H:i:s', strtotime('-30 days'))];
            break;
         case '90days':
            $where['date_created'] = ['>=', date('Y-m-d H:i:s', strtotime('-90 days'))];
            break;
      }

      $summary = [
         self::ACTION_DUPLICATE_BLOCKED => 0,
         self::ACTION_FOLLOWUP_CREATED  => 0,
         self::ACTION_TICKET_LINKED     => 0,
         self::ACTION_NEW_TICKET        => 0,
      ];

      $criteria = [
         'SELECT' => ['action_type', 'COUNT' => 'action_type AS count'],
         'FROM'   => 'glpi_plugin_mailanalyzer_stats',
         'GROUPBY' => 'action_type',
      ];

      if (!empty($where)) {
         $criteria['WHERE'] = $where;
      }

      $res = $DB->request($criteria);
      foreach ($res as $row) {
         if (isset($summary[$row['action_type']])) {
            $summary[$row['action_type']] = (int) $row['count'];
         }
      }

      return $summary;
   }

   /**
    * Get recent events for the activity log.
    *
    * @param int $limit Number of events to return
    * @return array
    */
   public static function getRecentEvents(int $limit = 10): array
   {
      global $DB;

      $events = [];
      $res = $DB->request([
         'FROM'    => 'glpi_plugin_mailanalyzer_stats',
         'ORDER'   => 'date_created DESC',
         'LIMIT'   => $limit,
      ]);

      foreach ($res as $row) {
         $events[] = $row;
      }
      return $events;
   }

   /**
    * Display the statistics dashboard in the config tab.
    *
    * @param string $period Period filter
    * @return void
    */
   public static function showDashboard(string $period = '30days'): void
   {
      $summary = self::getSummary($period);
      $total = array_sum($summary);
      $events = self::getRecentEvents(15);

      // Period selector using native GLPI Form
      echo "<div class='center mb-3'>";
      echo "<form method='post' action='" . Plugin::getWebDir('mailanalyzer') . "/front/stats.php'>";
      echo "<input type='hidden' name='config_context' value='plugin:mailanalyzer'>";
      echo "<input type='hidden' name='_glpi_tab' value='PluginMailanalyzerConfig$1'>";
      $periods = [
         '7days'  => __('Last 7 days', 'mailanalyzer'),
         '30days' => __('Last 30 days', 'mailanalyzer'),
         '90days' => __('Last 90 days', 'mailanalyzer'),
         'all'    => __('All time', 'mailanalyzer'),
      ];
      echo "<table class='tab_cadre_fixe'>";
      echo "<tr><th colspan='2'>" . __('Filter Statistics', 'mailanalyzer') . "</th></tr>";
      echo "<tr class='tab_bg_1'>";
      echo "<td>" . __('Period', 'mailanalyzer') . "</td>";
      echo "<td>";
      Dropdown::showFromArray('period', $periods, ['value' => $period]);
      echo "&nbsp;<input type='submit' name='filter_stats' class='btn btn-primary btn-sm' value='" . _sx('button', 'Apply') . "'>";
      echo "</td>";
      echo "</tr>";
      echo "</table>";
      Html::closeForm();
      echo "</div>";

      // Stats cards
      echo "<div class='center'>";
      echo "<table class='tab_cadre_fixe'>";

      echo "<tr><th colspan='4'>";
      echo "<i class='fas fa-chart-bar me-1'></i> ";
      echo __('Mail Analyzer Statistics', 'mailanalyzer');
      echo "</th></tr>";

      echo "<tr class='tab_bg_1'>";

      // Card: Duplicates Blocked
      echo "<td class='center' style='width:25%;padding:8px;'>";
      echo "<div style='background:linear-gradient(135deg,#ff6b6b,#ee5a24);color:#fff;border-radius:10px;padding:12px 8px;line-height:1.2;'>";
      echo "<i class='fas fa-ban'></i> ";
      echo "<span style='font-size:2em;font-weight:bold;'>{$summary[self::ACTION_DUPLICATE_BLOCKED]}</span><br>";
      echo "<small>" . __('Duplicates Blocked', 'mailanalyzer') . "</small>";
      echo "</div></td>";

      // Card: Followups Created
      echo "<td class='center' style='width:25%;padding:8px;'>";
      echo "<div style='background:linear-gradient(135deg,#4ecdc4,#44bd62);color:#fff;border-radius:10px;padding:12px 8px;line-height:1.2;'>";
      echo "<i class='fas fa-comments'></i> ";
      echo "<span style='font-size:2em;font-weight:bold;'>{$summary[self::ACTION_FOLLOWUP_CREATED]}</span><br>";
      echo "<small>" . __('Followups Created', 'mailanalyzer') . "</small>";
      echo "</div></td>";

      // Card: Tickets Linked
      echo "<td class='center' style='width:25%;padding:8px;'>";
      echo "<div style='background:linear-gradient(135deg,#a29bfe,#6c5ce7);color:#fff;border-radius:10px;padding:12px 8px;line-height:1.2;'>";
      echo "<i class='fas fa-link'></i> ";
      echo "<span style='font-size:2em;font-weight:bold;'>{$summary[self::ACTION_TICKET_LINKED]}</span><br>";
      echo "<small>" . __('Tickets Linked', 'mailanalyzer') . "</small>";
      echo "</div></td>";

      // Card: New Tickets
      echo "<td class='center' style='width:25%;padding:8px;'>";
      echo "<div style='background:linear-gradient(135deg,#74b9ff,#0984e3);color:#fff;border-radius:10px;padding:12px 8px;line-height:1.2;'>";
      echo "<i class='fas fa-ticket-alt'></i> ";
      echo "<span style='font-size:2em;font-weight:bold;'>{$summary[self::ACTION_NEW_TICKET]}</span><br>";
      echo "<small>" . __('New Tickets', 'mailanalyzer') . "</small>";
      echo "</div></td>";

      echo "</tr>";

      // Total processed row
      echo "<tr class='tab_bg_2'>";
      echo "<td colspan='4' class='center' style='padding:10px;'>";
      echo "<strong><i class='fas fa-envelope me-1'></i> ";
      echo __('Total emails processed', 'mailanalyzer') . ": $total</strong>";
      echo "</td></tr>";

      echo "</table>";
      echo "</div>";

      // Recent activity table
      if (!empty($events)) {
         echo "<div class='center mt-3'>";
         echo "<table class='tab_cadre_fixe'>";

         echo "<tr><th colspan='5'>";
         echo "<i class='fas fa-history me-1'></i> ";
         echo __('Recent Activity', 'mailanalyzer');
         echo "</th></tr>";

         echo "<tr class='tab_bg_2'>";
         echo "<th>" . __('Date', 'mailanalyzer') . "</th>";
         echo "<th>" . __('Action', 'mailanalyzer') . "</th>";
         echo "<th>" . __('Ticket', 'mailanalyzer') . "</th>";
         echo "<th>" . __('Mail Collector', 'mailanalyzer') . "</th>";
         echo "<th>" . __('Message ID', 'mailanalyzer') . "</th>";
         echo "</tr>";

         $actionLabels = [
            self::ACTION_DUPLICATE_BLOCKED => '<span class="badge bg-danger"><i class="fas fa-ban"></i> ' . __('Duplicate Blocked', 'mailanalyzer') . '</span>',
            self::ACTION_FOLLOWUP_CREATED  => '<span class="badge bg-success"><i class="fas fa-comments"></i> ' . __('Followup Created', 'mailanalyzer') . '</span>',
            self::ACTION_TICKET_LINKED     => '<span class="badge bg-info"><i class="fas fa-link"></i> ' . __('Ticket Linked', 'mailanalyzer') . '</span>',
            self::ACTION_NEW_TICKET        => '<span class="badge bg-primary"><i class="fas fa-ticket-alt"></i> ' . __('New Ticket', 'mailanalyzer') . '</span>',
         ];

         foreach ($events as $event) {
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . Html::convDateTime($event['date_created']) . "</td>";
            echo "<td>" . ($actionLabels[$event['action_type']] ?? $event['action_type']) . "</td>";

            // Ticket link
            if ($event['tickets_id'] > 0) {
               $ticket = new Ticket();
               if ($ticket->getFromDB($event['tickets_id'])) {
                  echo "<td><a href='" . Ticket::getFormURLWithID($event['tickets_id']) . "'>";
                  echo "<i class='fas fa-hashtag'></i> " . $event['tickets_id'] . " - " . $ticket->getName();
                  echo "</a></td>";
               } else {
                  echo "<td><i class='fas fa-hashtag'></i> " . $event['tickets_id'] . " <small class='text-muted'>(" . __('deleted', 'mailanalyzer') . ")</small></td>";
               }
            } else {
               echo "<td>—</td>";
            }

            // Mail collector
            if ($event['mailcollectors_id'] > 0) {
               echo "<td><i class='fas fa-inbox'></i> #" . $event['mailcollectors_id'] . "</td>";
            } else {
               echo "<td>—</td>";
            }

            // Message ID (truncated)
            $msgId = htmlspecialchars($event['message_id']);
            $shortId = strlen($msgId) > 40 ? substr($msgId, 0, 40) . '…' : $msgId;
            echo "<td><small title='$msgId'>$shortId</small></td>";

            echo "</tr>";
         }

         echo "</table>";
         echo "</div>";
      }
   }

   /**
    * Purge orphaned records from the message_id table.
    * Removes records referencing tickets that no longer exist.
    *
    * @return int Number of records purged
    */
   public static function purgeOrphans(): int
   {
      global $DB;

      // Find message_id records where the ticket no longer exists
      $query = "DELETE m FROM `glpi_plugin_mailanalyzer_message_id` m
                LEFT JOIN `glpi_tickets` t ON m.`tickets_id` = t.`id`
                WHERE m.`tickets_id` != 0 AND t.`id` IS NULL";

      $DB->doQuery($query);
      $purged = $DB->affectedRows();

      if ($purged > 0) {
         Toolbox::logInfo("MailAnalyzer: Purged $purged orphaned message_id records");
      }

      return $purged;
   }
}
