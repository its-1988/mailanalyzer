<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — Audit log service.
GPLv2+
--------------------------------------------------------------------------
 */

/**
 * Append-only audit log of every decision made by the mail analyzer.
 *
 * Backed by the same table as the public stats dashboard (extended with
 * from_email / subject / subject_hash / decision_reason columns), so each
 * row is both a stat and an audit entry. This satisfies the ITIL
 * traceability requirement: who/from where/which decision was made.
 */
class PluginMailanalyzerAuditLog
{
    public static function append(
        string $actionType,
        int $ticketsId,
        int $mailcollectorsId,
        string $messageId,
        string $fromEmail,
        string $subject,
        string $subjectHash,
        string $decisionReason
    ): void {
        global $DB;
        $DB->insert(PluginMailanalyzerInstaller::TABLE_STATS, [
            'action_type'       => $actionType,
            'tickets_id'        => $ticketsId,
            'mailcollectors_id' => $mailcollectorsId,
            'message_id'        => self::clip($messageId, 255),
            'from_email'        => self::clip($fromEmail, 255),
            'subject'           => self::clip($subject, 500),
            'subject_hash'      => self::clip($subjectHash, 40),
            'decision_reason'   => self::clip($decisionReason, 255),
        ]);
    }

    private static function clip(string $value, int $max): string
    {
        return mb_strlen($value, 'UTF-8') > $max
            ? mb_substr($value, 0, $max, 'UTF-8')
            : $value;
    }
}
