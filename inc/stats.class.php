<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — Statistics dashboard & maintenance.
Copyright (C) 2011-2026 by Raynet SAS a company of A.Raymond Network.
GPLv2+
--------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;

/**
 * Statistics aggregation + dashboard rendering.
 * Persistence is handled by PluginMailanalyzerAuditLog (same table).
 */
class PluginMailanalyzerStats extends CommonDBTM
{
    // Action type constants — public so other services can record events
    public const ACTION_DUPLICATE_BLOCKED  = 'duplicate_blocked';
    public const ACTION_FOLLOWUP_CREATED   = 'followup_created';
    public const ACTION_TICKET_LINKED      = 'ticket_linked';
    public const ACTION_NEW_TICKET         = 'new_ticket';
    public const ACTION_BLACKLIST_REJECTED = 'blacklist_rejected';
    public const ACTION_AUTH_REJECTED      = 'auth_rejected';
    public const ACTION_ATTACHMENT_DEDUPED = 'attachment_deduped';

    public static function getTable($classname = null): string
    {
        return PluginMailanalyzerInstaller::TABLE_STATS;
    }

    /**
     * Lightweight helper: record without enriched audit fields.
     * Most callers should use PluginMailanalyzerAuditLog::append() directly.
     */
    public static function record(
        string $actionType,
        int $ticketsId = 0,
        int $mailcollectorsId = 0,
        string $messageId = ''
    ): void {
        PluginMailanalyzerAuditLog::append(
            $actionType,
            $ticketsId,
            $mailcollectorsId,
            $messageId,
            '',
            '',
            '',
            ''
        );
    }

    /**
     * Get aggregated action counts for a period.
     *
     * @return array<string, int>
     */
    public static function getSummary(string $period = 'all'): array
    {
        global $DB;

        $summary = [
            self::ACTION_DUPLICATE_BLOCKED  => 0,
            self::ACTION_FOLLOWUP_CREATED   => 0,
            self::ACTION_TICKET_LINKED      => 0,
            self::ACTION_NEW_TICKET         => 0,
            self::ACTION_BLACKLIST_REJECTED => 0,
            self::ACTION_AUTH_REJECTED      => 0,
            self::ACTION_ATTACHMENT_DEDUPED => 0,
        ];

        $criteria = [
            'SELECT'  => ['action_type', 'COUNT' => 'action_type AS count'],
            'FROM'    => PluginMailanalyzerInstaller::TABLE_STATS,
            'GROUPBY' => 'action_type',
        ];
        $where = self::periodWhere($period);
        if (!empty($where)) {
            $criteria['WHERE'] = $where;
        }
        foreach ($DB->request($criteria) as $row) {
            if (isset($summary[$row['action_type']])) {
                $summary[$row['action_type']] = (int) $row['count'];
            }
        }
        return $summary;
    }

    /**
     * Recent events for the activity log table.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getRecentEvents(int $limit = 15): array
    {
        global $DB;

        $events = [];
        $res = $DB->request([
            'FROM'  => PluginMailanalyzerInstaller::TABLE_STATS,
            'ORDER' => ['date_created DESC'],
            'LIMIT' => $limit,
        ]);
        foreach ($res as $row) {
            $events[] = $row;
        }
        return $events;
    }

    /**
     * Render the dashboard via Twig.
     */
    public static function showDashboard(string $period = '30days'): void
    {
        global $CFG_GLPI;
        $base = $CFG_GLPI['root_doc'] . '/plugins/mailanalyzer';

        $summary = self::getSummary($period);
        $events  = self::getRecentEvents(15);
        $total   = array_sum($summary);

        $rows = [];
        foreach ($events as $e) {
            $ticketLink = null;
            if ((int) $e['tickets_id'] > 0) {
                $t = new Ticket();
                if ($t->getFromDB((int) $e['tickets_id'])) {
                    $ticketLink = [
                        'href'   => Ticket::getFormURLWithID((int) $e['tickets_id']),
                        'label'  => sprintf('#%d — %s', (int) $e['tickets_id'], $t->getName()),
                        'exists' => true,
                    ];
                } else {
                    $ticketLink = [
                        'href'   => '',
                        'label'  => sprintf('#%d', (int) $e['tickets_id']),
                        'exists' => false,
                    ];
                }
            }
            $msgId = (string) ($e['message_id'] ?? '');
            $rows[] = [
                'date_created'    => Html::convDateTime($e['date_created']),
                'action_type'     => $e['action_type'],
                'action_label'    => self::actionLabel($e['action_type']),
                'tickets_id'      => (int) $e['tickets_id'],
                'ticket_link'     => $ticketLink,
                'mailcollectors_id' => (int) $e['mailcollectors_id'],
                'from_email'      => (string) ($e['from_email'] ?? ''),
                'subject'         => (string) ($e['subject'] ?? ''),
                'message_id'      => $msgId,
                'message_id_short' => mb_strlen($msgId) > 40
                    ? mb_substr($msgId, 0, 40) . '…'
                    : $msgId,
                'decision_reason' => (string) ($e['decision_reason'] ?? ''),
            ];
        }

        TemplateRenderer::getInstance()->display('@mailanalyzer/dashboard.html.twig', [
            'period'      => $period,
            'periods'     => [
                '7days'  => __('Last 7 days', 'mailanalyzer'),
                '30days' => __('Last 30 days', 'mailanalyzer'),
                '90days' => __('Last 90 days', 'mailanalyzer'),
                'all'    => __('All time', 'mailanalyzer'),
            ],
            'summary'     => $summary,
            'total'       => $total,
            'events'      => $rows,
            'stats_url'   => $base . '/front/stats.php',
            'export_url'  => $base . '/front/export.php',
            'csrf_token_value' => Session::getNewCSRFToken(),
        ]);
    }

    /**
     * Render the mail-collector health-check panel via Twig.
     */
    public static function showHealthCheck(): void
    {
        global $DB;

        $items = [];
        foreach ($DB->request(['FROM' => 'glpi_mailcollectors']) as $mc) {
            $lastDate = null;
            $res = $DB->request([
                'SELECT' => ['MAX' => 'date_created AS last_date'],
                'FROM'   => PluginMailanalyzerInstaller::TABLE_STATS,
                'WHERE'  => ['mailcollectors_id' => $mc['id']],
            ]);
            if ($row = $res->current()) {
                $lastDate = $row['last_date'] ?: null;
            }
            $items[] = [
                'name'        => $mc['name'],
                'errors'      => (int) $mc['errors'],
                'is_active'   => (int) $mc['is_active'] === 1,
                'last_date'   => $lastDate ? Html::convDateTime($lastDate) : __('Never', 'mailanalyzer'),
            ];
        }

        TemplateRenderer::getInstance()->display('@mailanalyzer/healthcheck.html.twig', [
            'collectors' => $items,
        ]);
    }

    /**
     * Purge orphaned records: message-id rows pointing to tickets that no
     * longer exist in glpi_tickets.
     */
    public static function purgeOrphans(): int
    {
        global $DB;

        $orphans = $DB->request([
            'SELECT' => ['m.id'],
            'FROM'   => PluginMailanalyzerInstaller::TABLE_MESSAGE_ID . ' AS m',
            'LEFT JOIN' => [
                'glpi_tickets AS t' => [
                    'ON' => ['m' => 'tickets_id', 't' => 'id'],
                ],
            ],
            'WHERE' => [
                'm.tickets_id' => ['!=', 0],
                'TYPE'         => 'AND',
                'OR' => [
                    ['t.id' => null],
                ],
            ],
        ]);

        $ids = [];
        foreach ($orphans as $row) {
            $ids[] = (int) $row['id'];
        }
        if (empty($ids)) {
            return 0;
        }
        $DB->delete(PluginMailanalyzerInstaller::TABLE_MESSAGE_ID, ['id' => $ids]);
        Toolbox::logInfo('MailAnalyzer: Purged ' . count($ids) . ' orphaned message_id records');
        return count($ids);
    }

    private static function periodWhere(string $period): array
    {
        return match ($period) {
            '7days'  => ['date_created' => ['>=', date('Y-m-d H:i:s', strtotime('-7 days'))]],
            '30days' => ['date_created' => ['>=', date('Y-m-d H:i:s', strtotime('-30 days'))]],
            '90days' => ['date_created' => ['>=', date('Y-m-d H:i:s', strtotime('-90 days'))]],
            default  => [],
        };
    }

    private static function actionLabel(string $actionType): array
    {
        $map = [
            self::ACTION_DUPLICATE_BLOCKED  => ['icon' => 'fa-ban',        'css' => 'bg-danger',  'text' => __('Duplicate Blocked', 'mailanalyzer')],
            self::ACTION_FOLLOWUP_CREATED   => ['icon' => 'fa-comments',   'css' => 'bg-success', 'text' => __('Followup Created', 'mailanalyzer')],
            self::ACTION_TICKET_LINKED      => ['icon' => 'fa-link',       'css' => 'bg-info',    'text' => __('Ticket Linked', 'mailanalyzer')],
            self::ACTION_NEW_TICKET         => ['icon' => 'fa-ticket-alt', 'css' => 'bg-primary', 'text' => __('New Ticket', 'mailanalyzer')],
            self::ACTION_BLACKLIST_REJECTED => ['icon' => 'fa-shield-alt', 'css' => 'bg-dark',    'text' => __('Blacklist Rejected', 'mailanalyzer')],
            self::ACTION_AUTH_REJECTED      => ['icon' => 'fa-user-shield','css' => 'bg-dark',    'text' => __('Auth Failed', 'mailanalyzer')],
            self::ACTION_ATTACHMENT_DEDUPED => ['icon' => 'fa-clone',      'css' => 'bg-warning', 'text' => __('Attachment Deduped', 'mailanalyzer')],
        ];
        return $map[$actionType] ?? ['icon' => 'fa-question', 'css' => 'bg-secondary', 'text' => $actionType];
    }

    /**
     * GLPI search options for the audit-log table.
     */
    public function rawSearchOptions(): array
    {
        $tab = [];
        $tab[] = [
            'id'   => 'common',
            'name' => __('Audit log', 'mailanalyzer'),
        ];
        $tab[] = [
            'id'            => 1,
            'table'         => self::getTable(),
            'field'         => 'date_created',
            'name'          => __('Date'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 2,
            'table'         => self::getTable(),
            'field'         => 'action_type',
            'name'          => __('Action'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 3,
            'table'         => self::getTable(),
            'field'         => 'tickets_id',
            'name'          => __('Ticket'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 4,
            'table'         => self::getTable(),
            'field'         => 'mailcollectors_id',
            'name'          => __('Mail Collector'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 5,
            'table'         => self::getTable(),
            'field'         => 'message_id',
            'name'          => __('Message ID'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 6,
            'table'         => self::getTable(),
            'field'         => 'from_email',
            'name'          => __('From', 'mailanalyzer'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 7,
            'table'         => self::getTable(),
            'field'         => 'subject',
            'name'          => __('Subject', 'mailanalyzer'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 8,
            'table'         => self::getTable(),
            'field'         => 'subject_hash',
            'name'          => __('Subject hash', 'mailanalyzer'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 9,
            'table'         => self::getTable(),
            'field'         => 'decision_reason',
            'name'          => __('Reason', 'mailanalyzer'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        return $tab;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Mail Analyzer audit entry', 'Mail Analyzer audit entries', $nb, 'mailanalyzer');
    }

    public static function getIcon(): string
    {
        return 'fas fa-history';
    }
}
