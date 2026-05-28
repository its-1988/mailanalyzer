<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — ThreadResolver service.
GPLv2+
--------------------------------------------------------------------------
 */

/**
 * Resolves an inbound email to an existing conversation thread:
 *  - extracts references (References header + optional Thread-Index),
 *  - looks them up in the plugin's message_id table,
 *  - returns the matching ticket row, or null if this is a new conversation.
 */
class PluginMailanalyzerThreadResolver
{
    /**
     * Extract candidate Message-ID references from email headers.
     *
     * @return array<int, string>
     */
    public static function getReferences(string $threadindex, string $references): array
    {
        $ids = [];
        if ($threadindex !== '') {
            $ids[] = $threadindex;
        }
        if ($references !== '' && preg_match_all('/<.*?>/', $references, $m)) {
            $ids = array_merge($ids, $m[0]);
        }
        return array_values(array_filter(
            $ids,
            static fn(string $v): bool => trim($v, '< >') !== ''
        ));
    }

    /**
     * Find an existing related ticket in the message_id table.
     *
     * @param array<int, string> $messageIds list of message-ids / thread-index
     * @return array{tickets_id:int}|null
     */
    public static function findRelatedTicket(array $messageIds, int $mailcollectorsId): ?array
    {
        global $DB;

        if (empty($messageIds)) {
            return null;
        }

        $res = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM'   => PluginMailanalyzerInstaller::TABLE_MESSAGE_ID,
            'WHERE'  => [
                'tickets_id'        => ['!=', 0],
                'message_id'        => $messageIds,
                'mailcollectors_id' => $mailcollectorsId,
            ],
            'ORDER'  => ['tickets_id DESC'],
            'LIMIT'  => 1,
        ]);

        $row = $res->current();
        return $row ? ['tickets_id' => (int) $row['tickets_id']] : null;
    }

    /**
     * Look up an exact Message-ID duplicate.
     */
    public static function findDuplicateByMessageId(string $messageId, int $mailcollectorsId): ?int
    {
        global $DB;
        if ($messageId === '') {
            return null;
        }
        $res = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM'   => PluginMailanalyzerInstaller::TABLE_MESSAGE_ID,
            'WHERE'  => [
                'tickets_id'        => ['!=', 0],
                'message_id'        => $messageId,
                'mailcollectors_id' => $mailcollectorsId,
            ],
            'LIMIT'  => 1,
        ]);
        $row = $res->current();
        return $row ? (int) $row['tickets_id'] : null;
    }

    /**
     * Look up a recent duplicate by normalized subject hash within a window of N seconds.
     * Used when Message-ID is empty or rotated (some forwarders).
     */
    public static function findDuplicateBySubjectHash(
        string $subjectHash,
        int $mailcollectorsId,
        int $windowSeconds
    ): ?int {
        global $DB;
        if ($subjectHash === '' || $windowSeconds <= 0) {
            return null;
        }
        $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);
        $res = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM'   => PluginMailanalyzerInstaller::TABLE_MESSAGE_ID,
            'WHERE'  => [
                'tickets_id'        => ['!=', 0],
                'subject_hash'      => $subjectHash,
                'mailcollectors_id' => $mailcollectorsId,
                'date_created'      => ['>=', $cutoff],
            ],
            'ORDER'  => ['date_created DESC'],
            'LIMIT'  => 1,
        ]);
        $row = $res->current();
        return $row ? (int) $row['tickets_id'] : null;
    }

    /**
     * Persist references and the inbound message-id so future emails can be linked.
     *
     * @param array<int, string> $references
     */
    public static function persistReferences(
        array $references,
        string $messageId,
        int $mailcollectorsId,
        string $subjectHash
    ): void {
        global $DB;
        $all = $references;
        if ($messageId !== '') {
            $all[] = $messageId;
        }
        $all = array_unique($all);

        foreach ($all as $ref) {
            $exists = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => PluginMailanalyzerInstaller::TABLE_MESSAGE_ID,
                'WHERE' => [
                    'message_id'        => $ref,
                    'mailcollectors_id' => $mailcollectorsId,
                ],
            ])->current()['cpt'] ?? 0;

            if ((int) $exists === 0) {
                $DB->insert(PluginMailanalyzerInstaller::TABLE_MESSAGE_ID, [
                    'message_id'        => $ref,
                    'mailcollectors_id' => $mailcollectorsId,
                    'subject_hash'      => $subjectHash,
                ]);
            }
        }
    }

    /**
     * After a brand-new ticket is created, attach its ID to the placeholder rows
     * inserted during pre_item_add.
     *
     * @param array<int, string> $references
     */
    public static function attachTicketToReferences(
        array $references,
        int $ticketsId,
        int $mailcollectorsId
    ): bool {
        global $DB;
        if (empty($references)) {
            return false;
        }
        return (bool) $DB->update(
            PluginMailanalyzerInstaller::TABLE_MESSAGE_ID,
            ['tickets_id' => $ticketsId],
            [
                'WHERE' => [
                    'tickets_id'        => 0,
                    'mailcollectors_id' => $mailcollectorsId,
                    'message_id'        => $references,
                ],
            ]
        );
    }

    /**
     * Normalize a subject (strip Re:/Fwd:/Fw:/Re[2]: prefixes, whitespace, case) and hash it.
     */
    public static function hashSubject(string $subject): string
    {
        $s = trim($subject);
        // strip nested Re:/Fwd:/Fw: prefixes (latin + cyrillic)
        $s = preg_replace(
            '/^\s*((re|fwd|fw|sv|aw|тт|про|пер|отв)(\[[0-9]+\])?:\s*)+/i',
            '',
            $s
        ) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = mb_strtolower(trim($s), 'UTF-8');
        return $s === '' ? '' : sha1($s);
    }
}
