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
 * Summary of plugin_mailanalyzer_install
 * @return boolean
 */
function plugin_mailanalyzer_install()
{
   global $DB;

   if (!$DB->tableExists("glpi_plugin_mailanalyzer_message_id")) {
      $query = "CREATE TABLE `glpi_plugin_mailanalyzer_message_id` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` VARCHAR(255) NOT NULL DEFAULT '0',
            `tickets_id` INT UNSIGNED NOT NULL DEFAULT '0',
            `mailcollectors_id` int UNSIGNED NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            UNIQUE INDEX `message_id` (`message_id`,`mailcollectors_id`),
            INDEX `tickets_id` (`tickets_id`)
         )
         COLLATE='utf8mb4_unicode_ci'
         ENGINE=innoDB;
         ";

      $DB->doQuery($query) or throw new \RuntimeException("error creating glpi_plugin_mailanalyzer_message_id " . $DB->error);
   } else {
      if (count($DB->listTables('glpi_plugin_mailanalyzer_message_id', ['engine' => 'MyIsam'])) > 0) {
         $query = "ALTER TABLE glpi_plugin_mailanalyzer_message_id ENGINE = InnoDB";
         $DB->doQuery($query) or throw new \RuntimeException("error updating ENGINE in glpi_plugin_mailanalyzer_message_id " . $DB->error);
      }
   }
   if ($DB->fieldExists("glpi_plugin_mailanalyzer_message_id", "mailgate_id")) {
      //STEP - UPDATE MAILGATE_ID INTO MAILCOLLECTORS_ID
      $query = "ALTER TABLE `glpi_plugin_mailanalyzer_message_id`
                CHANGE COLUMN `mailgate_id` `mailcollectors_id` INT UNSIGNED NOT NULL DEFAULT '0' AFTER `message_id`,
                DROP INDEX `message_id`,
                ADD UNIQUE INDEX `message_id` (`message_id`, `mailcollectors_id`) USING BTREE;";
      $DB->doQuery($query) or throw new \RuntimeException("error updating ENGINE in glpi_plugin_mailanalyzer_message_id " . $DB->error);
   }
   if (!$DB->fieldExists("glpi_plugin_mailanalyzer_message_id", "mailcollectors_id")) {
      //STEP - ADD mailcollectors_id
      $query = "ALTER TABLE glpi_plugin_mailanalyzer_message_id ADD COLUMN `mailcollectors_id` int UNSIGNED NOT NULL DEFAULT 0 AFTER `message_id`";
      $DB->doQuery($query) or throw new \RuntimeException("error updating ENGINE in glpi_plugin_mailanalyzer_message_id " . $DB->error);

      //STEP - REMOVE UNICITY CONSTRAINT
      $query = "ALTER TABLE glpi_plugin_mailanalyzer_message_id DROP INDEX `message_id`";
      $DB->doQuery($query) or throw new \RuntimeException("error updating ENGINE in glpi_plugin_mailanalyzer_message_id " . $DB->error);
      //STEP - ADD NEW UNICITY CONSTRAINT
      $query = "ALTER TABLE glpi_plugin_mailanalyzer_message_id ADD UNIQUE KEY `message_id` (`message_id`,`mailcollectors_id`);";
      $DB->doQuery($query) or throw new \RuntimeException("error updating ENGINE in glpi_plugin_mailanalyzer_message_id " . $DB->error);
   }

   if (!$DB->fieldExists('glpi_plugin_mailanalyzer_message_id', 'tickets_id')) {
      // then we must change the name and the length of id and ticket_id to 11
      $query = "ALTER TABLE `glpi_plugin_mailanalyzer_message_id`
                  CHANGE COLUMN `id` `id` INT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
                  CHANGE COLUMN `ticket_id` `tickets_id` INT UNSIGNED NOT NULL DEFAULT '0' AFTER `message_id`,
                  DROP INDEX `ticket_id`,
                  ADD INDEX `ticket_id` (`tickets_id`);";
      $DB->doQuery($query) or throw new \RuntimeException('Cannot alter glpi_plugin_mailanalyzer_message_id table! ' . $DB->error);
   }

   // Stats table for tracking email processing events
   if (!$DB->tableExists("glpi_plugin_mailanalyzer_stats")) {
      $query = "CREATE TABLE `glpi_plugin_mailanalyzer_stats` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `action_type` VARCHAR(50) NOT NULL,
            `tickets_id` INT UNSIGNED NOT NULL DEFAULT '0',
            `mailcollectors_id` INT UNSIGNED NOT NULL DEFAULT '0',
            `message_id` VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            INDEX `date_created` (`date_created`),
            INDEX `action_type` (`action_type`),
            INDEX `tickets_id` (`tickets_id`)
         )
         COLLATE='utf8mb4_unicode_ci'
         ENGINE=InnoDB;
         ";
      $DB->doQuery($query) or throw new \RuntimeException("error creating glpi_plugin_mailanalyzer_stats " . $DB->error);
   }

   return true;
}


/**
 * Summary of plugin_mailanalyzer_uninstall
 * @return boolean
 */
function plugin_mailanalyzer_uninstall()
{

   // nothing to uninstall
   // do not delete table

   return true;
}


