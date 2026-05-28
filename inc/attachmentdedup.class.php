<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — Attachment dedup service.
GPLv2+
--------------------------------------------------------------------------
 */

/**
 * Deduplicates attachments on Tickets by SHA-256 content hash.
 *
 * Hooks into `pre_item_add` on `Document_Item`. If the document file's
 * SHA-256 already exists for the target ticket, the Document_Item add is
 * cancelled AND the orphan Document is purged (it was just created by
 * GLPI's mail-importer microseconds earlier). Otherwise the hash is
 * recorded so future duplicates can be detected.
 *
 * Only applies to itemtype="Ticket" with a non-empty documents_id.
 */
class PluginMailanalyzerAttachmentDedup
{
    public const TABLE = 'glpi_plugin_mailanalyzer_attachments';

    public static function isEnabled(): bool
    {
        $cfg = Config::getConfigurationValues('plugin:mailanalyzer');
        return (int) ($cfg['enable_attachment_dedup'] ?? 0) === 1;
    }

    /**
     * pre_item_add hook on Document_Item.
     * Cancels the add if the document content hash already exists for this ticket.
     */
    public static function plugin_pre_item_add(Document_Item $parm): void
    {
        if (!self::isEnabled()) {
            return;
        }
        if (($parm->input['itemtype'] ?? '') !== 'Ticket') {
            return;
        }
        $ticketsId   = (int) ($parm->input['items_id']     ?? 0);
        $documentsId = (int) ($parm->input['documents_id'] ?? 0);
        if ($ticketsId <= 0 || $documentsId <= 0) {
            return;
        }

        $hash = self::hashDocument($documentsId);
        if ($hash === '') {
            return;
        }

        global $DB;
        $existing = $DB->request([
            'SELECT' => ['documents_id'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['tickets_id' => $ticketsId, 'sha256' => $hash],
            'LIMIT'  => 1,
        ])->current();

        if ($existing) {
            // Same content already attached to this ticket — drop the orphan Document and cancel link
            self::purgeDocument($documentsId);
            PluginMailanalyzerAuditLog::append(
                PluginMailanalyzerStats::ACTION_ATTACHMENT_DEDUPED,
                $ticketsId,
                0,
                '',
                '',
                '',
                $hash,
                sprintf('duplicate attachment SHA-256 — existing doc #%d', (int) $existing['documents_id'])
            );
            $parm->input = false;
            return;
        }

        $DB->insert(self::TABLE, [
            'tickets_id'   => $ticketsId,
            'documents_id' => $documentsId,
            'sha256'       => $hash,
        ]);
    }

    /**
     * item_purge hook on Ticket: drop attachment-hash bookkeeping for the ticket.
     */
    public static function plugin_item_purge_ticket(Ticket $item): void
    {
        global $DB;
        $DB->delete(self::TABLE, ['tickets_id' => $item->getID()]);
    }

    /**
     * Compute SHA-256 of the underlying file of a Document.
     * Returns '' if the file is missing or unreadable.
     */
    public static function hashDocument(int $documentsId): string
    {
        $doc = new Document();
        if (!$doc->getFromDB($documentsId)) {
            return '';
        }
        $relative = (string) ($doc->fields['filepath'] ?? '');
        if ($relative === '') {
            return '';
        }
        $path = GLPI_DOC_DIR . DIRECTORY_SEPARATOR . $relative;
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }
        $hash = @hash_file('sha256', $path);
        return is_string($hash) ? $hash : '';
    }

    private static function purgeDocument(int $documentsId): void
    {
        $doc = new Document();
        if (!$doc->getFromDB($documentsId)) {
            return;
        }
        // Force-purge so the underlying file is also removed
        $doc->delete(['id' => $documentsId], 1);
    }
}
