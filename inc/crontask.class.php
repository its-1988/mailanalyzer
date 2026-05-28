<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — Native CronTask handler.
GPLv2+
--------------------------------------------------------------------------
 */

/**
 * Daily housekeeping job:
 *  - purge orphan message_id rows (tickets that no longer exist)
 *  - trim stats older than N days (CronTask `param`)
 */
class PluginMailanalyzerCrontask extends CommonDBTM
{
    /**
     * @param string $name task name (here only "MailanalyzerCleanup")
     */
    public static function cronInfo($name): array
    {
        return [
            'description' => __('Cleanup orphan entries and old statistics', 'mailanalyzer'),
            'parameter'   => __('Days to keep statistics (0 = infinite)', 'mailanalyzer'),
        ];
    }

    /**
     * Executed by GLPI cron.
     *
     * @return int 0: nothing to do, 1: done, -1: error
     */
    public static function cronMailanalyzerCleanup(CronTask $task): int
    {
        global $DB;

        $daysToKeep = (int) $task->fields['param'];
        $processed  = 0;
        $task->addVolume(1);

        $purged = PluginMailanalyzerStats::purgeOrphans();
        if ($purged > 0) {
            $task->log("Orphaned records purged: $purged");
            $processed += $purged;
        }

        if ($daysToKeep > 0) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-$daysToKeep days"));
            $DB->delete(PluginMailanalyzerInstaller::TABLE_STATS, [
                'date_created' => ['<', $cutoff],
            ]);
            $statsPurged = $DB->affectedRows();
            if ($statsPurged > 0) {
                $task->log("Old statistics records purged: $statsPurged");
                $processed += $statsPurged;
            }
        }

        return $processed > 0 ? 1 : 0;
    }
}
