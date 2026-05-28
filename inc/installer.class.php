<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — Installer service.
Copyright (C) 2011-2026 by Raynet SAS a company of A.Raymond Network.
GPLv2+ — see LICENSE.
--------------------------------------------------------------------------
 */

/**
 * Database installer for GLPI 11. Builds schema in one pass.
 * No legacy upgrade paths from GLPI 9.x / 10.x — this plugin assumes a clean GLPI 11 install.
 */
class PluginMailanalyzerInstaller
{
    public const TABLE_MESSAGE_ID  = 'glpi_plugin_mailanalyzer_message_id';
    public const TABLE_STATS       = 'glpi_plugin_mailanalyzer_stats';
    public const TABLE_ATTACHMENTS = 'glpi_plugin_mailanalyzer_attachments';

    public static function install(): bool
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE_MESSAGE_ID)) {
            $DB->doQuery(
                "CREATE TABLE `" . self::TABLE_MESSAGE_ID . "` (
                    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `message_id`        VARCHAR(255) NOT NULL DEFAULT '',
                    `tickets_id`        INT UNSIGNED NOT NULL DEFAULT '0',
                    `mailcollectors_id` INT UNSIGNED NOT NULL DEFAULT '0',
                    `subject_hash`      CHAR(40) NOT NULL DEFAULT '',
                    `date_created`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE INDEX `message_id` (`message_id`, `mailcollectors_id`),
                    INDEX `tickets_id` (`tickets_id`),
                    INDEX `subject_hash` (`subject_hash`, `mailcollectors_id`, `date_created`)
                )
                COLLATE='utf8mb4_unicode_ci'
                ENGINE=InnoDB"
            );
        } else {
            self::ensureColumn(
                self::TABLE_MESSAGE_ID,
                'subject_hash',
                "ALTER TABLE `" . self::TABLE_MESSAGE_ID . "`
                 ADD COLUMN `subject_hash` CHAR(40) NOT NULL DEFAULT '' AFTER `mailcollectors_id`,
                 ADD INDEX `subject_hash` (`subject_hash`, `mailcollectors_id`)"
            );
            self::ensureColumn(
                self::TABLE_MESSAGE_ID,
                'date_created',
                "ALTER TABLE `" . self::TABLE_MESSAGE_ID . "`
                 ADD COLUMN `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `subject_hash`"
            );
        }

        if (!$DB->tableExists(self::TABLE_STATS)) {
            $DB->doQuery(
                "CREATE TABLE `" . self::TABLE_STATS . "` (
                    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `date_created`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `action_type`       VARCHAR(50) NOT NULL,
                    `tickets_id`        INT UNSIGNED NOT NULL DEFAULT '0',
                    `mailcollectors_id` INT UNSIGNED NOT NULL DEFAULT '0',
                    `message_id`        VARCHAR(255) NOT NULL DEFAULT '',
                    `from_email`        VARCHAR(255) NOT NULL DEFAULT '',
                    `subject`           VARCHAR(500) NOT NULL DEFAULT '',
                    `subject_hash`      CHAR(40) NOT NULL DEFAULT '',
                    `decision_reason`   VARCHAR(255) NOT NULL DEFAULT '',
                    PRIMARY KEY (`id`),
                    INDEX `date_created` (`date_created`),
                    INDEX `action_type` (`action_type`),
                    INDEX `tickets_id` (`tickets_id`),
                    INDEX `from_email` (`from_email`)
                )
                COLLATE='utf8mb4_unicode_ci'
                ENGINE=InnoDB"
            );
        } else {
            self::ensureColumn(
                self::TABLE_STATS,
                'from_email',
                "ALTER TABLE `" . self::TABLE_STATS . "`
                 ADD COLUMN `from_email` VARCHAR(255) NOT NULL DEFAULT '' AFTER `message_id`,
                 ADD INDEX `from_email` (`from_email`)"
            );
            self::ensureColumn(
                self::TABLE_STATS,
                'subject',
                "ALTER TABLE `" . self::TABLE_STATS . "`
                 ADD COLUMN `subject` VARCHAR(500) NOT NULL DEFAULT '' AFTER `from_email`"
            );
            self::ensureColumn(
                self::TABLE_STATS,
                'subject_hash',
                "ALTER TABLE `" . self::TABLE_STATS . "`
                 ADD COLUMN `subject_hash` CHAR(40) NOT NULL DEFAULT '' AFTER `subject`"
            );
            self::ensureColumn(
                self::TABLE_STATS,
                'decision_reason',
                "ALTER TABLE `" . self::TABLE_STATS . "`
                 ADD COLUMN `decision_reason` VARCHAR(255) NOT NULL DEFAULT '' AFTER `subject_hash`"
            );
        }

        if (!$DB->tableExists(self::TABLE_ATTACHMENTS)) {
            $DB->doQuery(
                "CREATE TABLE `" . self::TABLE_ATTACHMENTS . "` (
                    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tickets_id`   INT UNSIGNED NOT NULL,
                    `documents_id` INT UNSIGNED NOT NULL,
                    `sha256`       CHAR(64) NOT NULL,
                    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE INDEX `ticket_hash` (`tickets_id`, `sha256`),
                    INDEX `documents_id` (`documents_id`)
                )
                COLLATE='utf8mb4_unicode_ci'
                ENGINE=InnoDB"
            );
        }

        // Register CronTask for housekeeping
        $cron = new CronTask();
        if (!$cron->getFromDBbyName('PluginMailanalyzerCrontask', 'MailanalyzerCleanup')) {
            $cron->add([
                'itemtype'  => 'PluginMailanalyzerCrontask',
                'name'      => 'MailanalyzerCleanup',
                'frequency' => DAY_TIMESTAMP,
                'param'     => 180,
                'state'     => 1,
                'mode'      => 1,
            ]);
        }

        return true;
    }

    public static function uninstall(): bool
    {
        // Preserve data tables — purge via bin/console mailanalyzer:cleanup
        // or drop manually if needed.

        $cron = new CronTask();
        if ($cron->getFromDBbyName('PluginMailanalyzerCrontask', 'MailanalyzerCleanup')) {
            $cron->delete(['id' => $cron->getID()]);
        }

        // Drop plugin config context
        Config::deleteConfigurationValues('plugin:mailanalyzer', [
            'use_threadindex',
            'whitelist_domains',
            'blacklist_domains',
            'vip_senders',
            'incident_keywords',
            'request_keywords',
            'default_request_type',
            'auto_priority_keywords',
            'duplicate_alert_threshold',
            'duplicate_alert_window_seconds',
            'enable_smart_classification',
            'enable_subject_hash_dedup',
            'subject_hash_window_minutes',
            'enable_auth_validation',
            'reject_on_spf_fail',
            'reject_on_dkim_fail',
            'reject_on_dmarc_fail',
            'enable_attachment_dedup',
        ]);

        return true;
    }

    private static function ensureColumn(string $table, string $column, string $alter): void
    {
        global $DB;
        if (!$DB->fieldExists($table, $column)) {
            $DB->doQuery($alter);
        }
    }
}
