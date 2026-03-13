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
 * Main class for the MailAnalyzer plugin.
 * Handles email conversation tracking by analyzing Message-ID, References,
 * and Thread-Index headers to combine related emails into a single ticket.
 */
class PluginMailAnalyzer
{

   /**
    * Open a mail collector connection for reading email headers.
    *
    * @param int $mailcollectors_id ID of the mail collector in GLPI DB
    * @return PluginMailanalyzerMailCollector
    */
   public static function openMailgate(int $mailcollectors_id): PluginMailanalyzerMailCollector
   {
      $mailgate = new PluginMailanalyzerMailCollector();
      $mailgate->getFromDB($mailcollectors_id);
      $mailgate->uid = -1;
      $mailgate->connect();

      return $mailgate;
   }


   /**
    * Hook called before a Ticket is added.
    * Analyzes incoming emails to detect duplicates and conversation threads.
    * - If the email was already received, prevents ticket creation.
    * - If the email belongs to an existing conversation, creates a followup instead.
    * - If the referenced ticket is closed, creates a new linked ticket.
    *
    * @param Ticket $parm The ticket being created
    * @return void
    */
   public static function plugin_pre_item_add_mailanalyzer(Ticket $parm): void
   {
      global $DB, $mailgate;

      $mailgateId = $parm->input['_mailgate'] ?? false;
      if (!$mailgateId) {
         return;
      }

      // this ticket has been created via email receiver.
      // Analyze emails to establish conversation

      // search for 'Thread-Index'?
      $config = Config::getConfigurationValues('plugin:mailanalyzer');
      $use_threadindex = isset($config['use_threadindex']) && $config['use_threadindex'];

      if (isset($mailgate)) {
         // mailgate has been opened by web page call, then use it
         $local_mailgate = $mailgate;
         // if use of threadindex is true then must open a new mailgate
         // to be able to get the threadindex of the email
         if ($use_threadindex) {
            $local_mailgate = self::openMailgate($mailgateId);
         }
      } else {
         // mailgate is not open. Called by cron
         // then locally create a mailgate
         try {
            $local_mailgate = self::openMailgate($mailgateId);
         } catch (\Throwable $e) {
            // can't connect to the mail server, then cancel ticket creation
            Toolbox::logError("MailAnalyzer: Failed to open mailgate #$mailgateId - " . $e->getMessage());
            $parm->input = false;
            return;
         }
      }

      // Check Whitelist / Blacklist
      $from = $parm->input['_head']['from'] ?? '';
      $fromDomain = '';
      if (preg_match('/@([a-zA-Z0-9.-]+)/', $from, $matches)) {
         $fromDomain = strtolower($matches[1]);
      }

      if (!empty($fromDomain)) {
         // Blacklist: reject the email entirely
         $blacklist = array_filter(array_map('trim', explode("\n", $config['blacklist_domains'] ?? '')));
         foreach ($blacklist as $bDomain) {
            $bDomain = ltrim(strtolower($bDomain), '@');
            if ($bDomain === $fromDomain) {
               Toolbox::logInfo("MailAnalyzer: Email rejected by Blacklist from domain $fromDomain");
               $parm->input = false;
               $local_mailgate->deleteMails($parm->input['_uid'], MailCollector::REFUSED_FOLDER);
               return;
            }
         }

         // Whitelist: let GLPI process normally, bypassing MailAnalyzer
         $whitelist = array_filter(array_map('trim', explode("\n", $config['whitelist_domains'] ?? '')));
         foreach ($whitelist as $wDomain) {
            $wDomain = ltrim(strtolower($wDomain), '@');
            if ($wDomain === $fromDomain) {
               Toolbox::logInfo("MailAnalyzer: Email bypassed by Whitelist from domain $fromDomain");
               return; 
            }
         }
      }

      if ($use_threadindex) {
         try {
            $local_message = $local_mailgate->getMessage($parm->input['_uid']);
            $threadindex = $local_mailgate->getThreadIndex($local_message);
            if ($threadindex) {
               // add threadindex to the '_head' of the input
               $parm->input['_head']['threadindex'] = $threadindex;
            }
         } catch (\Throwable $e) {
            Toolbox::logWarning("MailAnalyzer: Failed to get Thread-Index for UID {$parm->input['_uid']} - " . $e->getMessage());
         }
      }

      // we must check if this email has not been received yet!
      // test if 'message-id' is in the DB
      $messageId = html_entity_decode($parm->input['_head']['message_id']);
      $uid = $parm->input['_uid'];
      $res = $DB->request(
         'glpi_plugin_mailanalyzer_message_id',
         [
            'AND' =>
               [
                  'tickets_id' => ['!=', 0],
                  'message_id' => $messageId,
                  'mailcollectors_id' => $mailgateId
               ]
         ]
      );
      if ($row = $res->current()) {
         // email already received — prevent ticket creation
         Toolbox::logInfo("MailAnalyzer: Duplicate email blocked (message_id: $messageId, existing ticket: #{$row['tickets_id']})");
         PluginMailanalyzerStats::record(
            PluginMailanalyzerStats::ACTION_DUPLICATE_BLOCKED,
            (int) $row['tickets_id'],
            (int) $mailgateId,
            $messageId
         );

         // Check if we have too many duplicates recently and raise an alert on MailCollector
         $resCount = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_plugin_mailanalyzer_stats',
            'WHERE' => [
               'mailcollectors_id' => clone $mailgateId,
               'action_type'       => PluginMailanalyzerStats::ACTION_DUPLICATE_BLOCKED,
               'date_created'      => ['>=', date('Y-m-d H:i:s', time() - 3600)]
            ]
         ]);
         $count = $resCount->current()['cpt'] ?? 0;
         if ($count > 0 && $count % 20 === 0) {
            if (class_exists('NotificationEvent')) {
               $mc = new MailCollector();
               if ($mc->getFromDB($mailgateId)) {
                  NotificationEvent::raiseEvent('mailanalyzer_duplicate_alert', $mc);
                  Toolbox::logWarning("MailAnalyzer: Raised high volume of duplicates alert for MailCollector #{$mailgateId} ($count duplicates in last hour)");
               }
            }
         }

         $parm->input = false;

         // as Ticket creation is cancelled, email is not deleted from mailbox
         // so we need to move/delete this email from mailbox folder
         $local_mailgate->deleteMails($uid, MailCollector::REFUSED_FOLDER);

         return;
      }

      // search for 'Thread-Index' and 'References'
      $messages_id = self::getMailReferences(
         $parm->input['_head']['threadindex'] ?? '',
         html_entity_decode($parm->input['_head']['references'] ?? '')
      );

      if (count($messages_id) > 0) {
         $res = $DB->request(
            'glpi_plugin_mailanalyzer_message_id',
            [
               'AND' =>
                  [
                     'tickets_id' => ['!=', 0],
                     'message_id' => $messages_id,
                     'mailcollectors_id' => $mailgateId
                  ],
               'ORDER' => 'tickets_id DESC'
            ]
         );
         if ($row = $res->current()) {
            // TicketFollowup creation only if ticket status is not closed
            $locTicket = new Ticket();
            $locTicket->getFromDB((int) $row['tickets_id']);
            if ($locTicket->fields['status'] != CommonITILObject::CLOSED) {
               $ticketfollowup = new ITILFollowup();
               $input = $parm->input;
               $input['items_id'] = $row['tickets_id'];
               $input['users_id'] = $parm->input['_users_id_requester'];
               $input['add_reopen'] = 1;
               $input['itemtype'] = 'Ticket';

               unset($input['urgency']);
               unset($input['entities_id']);
               unset($input['_ruleid']);

               $followup_id = $ticketfollowup->add($input);

               if ($followup_id) {
                  Toolbox::logInfo("MailAnalyzer: Followup #{$followup_id} created on ticket #{$row['tickets_id']} instead of new ticket");
                  PluginMailanalyzerStats::record(
                     PluginMailanalyzerStats::ACTION_FOLLOWUP_CREATED,
                     (int) $row['tickets_id'],
                     (int) $mailgateId,
                     $messageId
                  );
               } else {
                  Toolbox::logError("MailAnalyzer: Failed to create followup on ticket #{$row['tickets_id']}");
               }

               // add message id to DB in case of another email will use it
               $result = $DB->insert(
                  'glpi_plugin_mailanalyzer_message_id',
                  [
                     'message_id' => $messageId,
                     'tickets_id' => $input['items_id'],
                     'mailcollectors_id' => $mailgateId
                  ]
               );
               if (!$result) {
                  Toolbox::logError("MailAnalyzer: Failed to insert message_id record for followup");
               }

               // prevent Ticket creation
               $parm->input = false;

               // as Ticket creation is cancelled, email is not deleted from mailbox
               // so we need to move/delete this email from mailbox folder
               $local_mailgate->deleteMails($uid, MailCollector::ACCEPTED_FOLDER);

               return;

            } else {
               // ticket creation, but linked to the closed one...
               Toolbox::logInfo("MailAnalyzer: Referenced ticket #{$row['tickets_id']} is closed — creating new linked ticket");
               PluginMailanalyzerStats::record(
                  PluginMailanalyzerStats::ACTION_TICKET_LINKED,
                  (int) $row['tickets_id'],
                  (int) $mailgateId,
                  $messageId
               );
               $parm->input['_link'] = ['link' => '1', 'tickets_id_1' => '0', 'tickets_id_2' => $row['tickets_id']];
            }
         }
      }

      // can't find ref into DB, then this is a new ticket, insert refs and message_id into DB
      $messages_id[] = $messageId;

      // this is a new ticket — add references and message_id to DB
      foreach ($messages_id as $ref) {
         $res = $DB->request('glpi_plugin_mailanalyzer_message_id', ['message_id' => $ref, 'mailcollectors_id' => $mailgateId]);
         if (count($res) <= 0) {
            $result = $DB->insert('glpi_plugin_mailanalyzer_message_id', ['message_id' => $ref, 'mailcollectors_id' => $mailgateId]);
            if (!$result) {
               Toolbox::logError("MailAnalyzer: Failed to insert message_id reference: $ref");
            }
         }
      }
   }


   /**
    * Hook called after a Ticket is added.
    * Updates the tickets_id in the message_id table for newly created tickets.
    *
    * @param Ticket $parm The ticket that was created
    * @return void
    */
   public static function plugin_item_add_mailanalyzer(Ticket $parm): void
   {
      global $DB;
      if (isset($parm->input['_mailgate'])) {
         // this ticket has been created via email receiver.
         // update the ticket ID for the message_id only for newly created tickets (tickets_id == 0)

         // Are 'Thread-Index' or 'References' present?
         $messages_id = self::getMailReferences(
            $parm->input['_head']['threadindex'] ?? '',
            html_entity_decode($parm->input['_head']['references'] ?? '')
         );
         $messages_id[] = html_entity_decode($parm->input['_head']['message_id']);

         $result = $DB->update(
            'glpi_plugin_mailanalyzer_message_id',
            [
               'tickets_id' => $parm->fields['id']
            ],
            [
               'WHERE' =>
                  [
                     'AND' =>
                        [
                           'tickets_id' => 0,
                           'message_id' => $messages_id
                        ]
                  ]
            ]
         );
         if ($result) {
            PluginMailanalyzerStats::record(
               PluginMailanalyzerStats::ACTION_NEW_TICKET,
               (int) $parm->fields['id'],
               (int) $parm->input['_mailgate'],
               html_entity_decode($parm->input['_head']['message_id'])
            );
         } else {
            Toolbox::logError("MailAnalyzer: Failed to update tickets_id for ticket #{$parm->fields['id']}");
         }
      }
   }


   /**
    * Extract email references from Thread-Index and References headers.
    *
    * @param string $threadindex Thread-Index header value (hex-encoded)
    * @param string $references  References header value (space-separated message IDs)
    * @return array<string> List of message IDs found in the headers
    */
   private static function getMailReferences(string $threadindex, string $references): array
   {
      $messages_id = [];

      if (!empty($threadindex)) {
         $messages_id[] = $threadindex;
      }

      // search for 'References'
      if (!empty($references)) {
         // we may have a forwarded email that looks like reply-to
         if (preg_match_all('/<.*?>/', $references, $matches)) {
            $messages_id = array_merge($messages_id, $matches[0]);
         }
      }

      // clean $messages_id array — remove empty or whitespace-only entries
      return array_filter($messages_id, function (string $val): bool {
         return trim($val, '< >') !== '';
      });
   }


   /**
    * Hook called when a Ticket is purged.
    * Cleans up the corresponding message_id records from the plugin table.
    *
    * @param Ticket $item The ticket being purged
    * @return void
    */
   public static function plugin_item_purge_mailanalyzer(Ticket $item): void
   {
      global $DB;
      // the ticket is purged, then we are going to purge the matching rows
      $result = $DB->delete('glpi_plugin_mailanalyzer_message_id', ['tickets_id' => $item->getID()]);
      if (!$result) {
         Toolbox::logError("MailAnalyzer: Failed to purge message_id records for ticket #{$item->getID()}");
      }
   }
}
