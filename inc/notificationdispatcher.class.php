<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — Notification dispatcher service.
GPLv2+
--------------------------------------------------------------------------
 */

/**
 * Raises a GLPI native NotificationEvent when too many duplicates are
 * blocked in a configurable rolling window — to surface mail loops or spam
 * storms to the service desk operator.
 *
 * Threshold + window are configurable, with sane defaults (20 per hour).
 */
class PluginMailanalyzerNotificationDispatcher
{
    private const DEFAULT_THRESHOLD     = 20;
    private const DEFAULT_WINDOW_SEC    = 3600;
    public  const EVENT_DUPLICATE_ALERT = 'mailanalyzer_duplicate_alert';

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? Config::getConfigurationValues('plugin:mailanalyzer');
    }

    public function notifyDuplicateBlocked(int $mailcollectorsId): void
    {
        global $DB;

        $threshold = max(1, (int) ($this->config['duplicate_alert_threshold'] ?? self::DEFAULT_THRESHOLD));
        $window    = max(60, (int) ($this->config['duplicate_alert_window_seconds'] ?? self::DEFAULT_WINDOW_SEC));

        $cutoff = date('Y-m-d H:i:s', time() - $window);
        $count  = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => PluginMailanalyzerInstaller::TABLE_STATS,
            'WHERE' => [
                'mailcollectors_id' => $mailcollectorsId,
                'action_type'       => PluginMailanalyzerStats::ACTION_DUPLICATE_BLOCKED,
                'date_created'      => ['>=', $cutoff],
            ],
        ])->current()['cpt'] ?? 0;

        $count = (int) $count;
        if ($count <= 0 || $count % $threshold !== 0) {
            return;
        }

        if (!class_exists('NotificationEvent')) {
            return;
        }
        $mc = new MailCollector();
        if (!$mc->getFromDB($mailcollectorsId)) {
            return;
        }
        NotificationEvent::raiseEvent(self::EVENT_DUPLICATE_ALERT, $mc);
        Toolbox::logWarning(sprintf(
            'MailAnalyzer: duplicate-alert raised for MailCollector #%d (%d duplicates in last %d s)',
            $mailcollectorsId,
            $count,
            $window
        ));
    }
}
