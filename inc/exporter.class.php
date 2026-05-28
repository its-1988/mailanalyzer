<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — CSV exporter service.
GPLv2+
--------------------------------------------------------------------------
 */

/**
 * Streams the audit-log/stats table to the browser as CSV (UTF-8 + BOM
 * for Excel compatibility). Honors the same period filter as the dashboard.
 */
class PluginMailanalyzerExporter
{
    /**
     * Emit a CSV response with HTTP headers, then exit.
     * Must be called from a controller (front/export.php) that has already
     * bootstrapped GLPI and verified rights.
     */
    public static function streamCsv(string $period = '30days'): void
    {
        global $DB;

        $where = self::periodWhere($period);
        $criteria = [
            'FROM'  => PluginMailanalyzerInstaller::TABLE_STATS,
            'ORDER' => ['date_created DESC'],
        ];
        if (!empty($where)) {
            $criteria['WHERE'] = $where;
        }
        $rows = $DB->request($criteria);

        $filename = sprintf('mailanalyzer-%s-%s.csv', $period, date('Ymd-His'));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'wb');
        // UTF-8 BOM so Excel auto-detects encoding
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'date_created',
            'action_type',
            'tickets_id',
            'mailcollectors_id',
            'from_email',
            'subject',
            'subject_hash',
            'message_id',
            'decision_reason',
        ], ',', '"', '\\');

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['date_created']      ?? '',
                $r['action_type']       ?? '',
                $r['tickets_id']        ?? 0,
                $r['mailcollectors_id'] ?? 0,
                $r['from_email']        ?? '',
                $r['subject']           ?? '',
                $r['subject_hash']      ?? '',
                $r['message_id']        ?? '',
                $r['decision_reason']   ?? '',
            ], ',', '"', '\\');
        }

        fclose($out);
        exit;
    }

    /**
     * @return array<string, mixed>
     */
    private static function periodWhere(string $period): array
    {
        return match ($period) {
            '7days'  => ['date_created' => ['>=', date('Y-m-d H:i:s', strtotime('-7 days'))]],
            '30days' => ['date_created' => ['>=', date('Y-m-d H:i:s', strtotime('-30 days'))]],
            '90days' => ['date_created' => ['>=', date('Y-m-d H:i:s', strtotime('-90 days'))]],
            default  => [],
        };
    }
}
