<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI
Copyright (C) 2011-2026 by Raynet SAS a company of A.Raymond Network.
GPLv2+
--------------------------------------------------------------------------
 */

/**
 * Thin orchestration layer that wires GLPI hooks into the analyzer services.
 *
 * Responsibilities are split across services:
 *   - PluginMailanalyzerDomainFilter            : whitelist / blacklist / VIP
 *   - PluginMailanalyzerThreadResolver          : Message-ID / Thread-Index / References
 *   - PluginMailanalyzerClassifier              : Smart Incident/Request + auto-priority
 *   - PluginMailanalyzerAuditLog                : append-only event log
 *   - PluginMailanalyzerNotificationDispatcher  : duplicate-storm alerts
 *   - PluginMailanalyzerMailCollector           : IMAP/POP access (Thread-Index, deleteMails)
 */
class PluginMailAnalyzer
{
    /** State carried from pre_item_add → item_add (hooks fire sequentially per ticket). */
    public static array  $pendingReferences  = [];
    public static string $pendingMessageId   = '';
    public static string $pendingSubjectHash = '';
    /** When set, item_add will NOT log a NEW_TICKET event (already logged a different action). */
    public static string $pendingActionType  = '';

    /**
     * Open a dedicated MailCollector connection for inspecting raw headers
     * (Thread-Index lives in the IMAP message, not in $parm->input).
     *
     * @throws Throwable on connection failure
     */
    public static function openMailgate(int $mailcollectorsId): PluginMailanalyzerMailCollector
    {
        $mg = new PluginMailanalyzerMailCollector();
        $mg->getFromDB($mailcollectorsId);
        $mg->uid = -1;
        $mg->connect();
        return $mg;
    }

    /**
     * pre_item_add hook on Ticket.
     *
     * Decision tree:
     *   - non-mail ticket          → no-op
     *   - sender on blacklist      → refuse, move to REFUSED_FOLDER
     *   - exact Message-ID dup     → block + audit + maybe alert
     *   - subject-hash dup (opt.)  → block as duplicate
     *   - references match ticket  → followup (open) or linked-ticket (closed)
     *   - otherwise                → new ticket; persist refs for future emails
     *
     * Smart classification is applied to the surviving ticket *input* so it
     * propagates into GLPI's business rules.
     */
    public static function plugin_pre_item_add_mailanalyzer(Ticket $parm): void
    {
        global $mailgate;

        // Reset any leftover state from a previous invocation that didn't reach item_add
        self::$pendingReferences  = [];
        self::$pendingMessageId   = '';
        self::$pendingSubjectHash = '';
        self::$pendingActionType  = '';

        $mailgateId = (int) ($parm->input['_mailgate'] ?? 0);
        if ($mailgateId <= 0) {
            return;
        }

        $config         = Config::getConfigurationValues('plugin:mailanalyzer');
        $useThreadindex = (int) ($config['use_threadindex'] ?? 0) === 1;

        // Resolve a usable mail connection
        try {
            $localMailgate = ($mailgate instanceof PluginMailanalyzerMailCollector && !$useThreadindex)
                ? $mailgate
                : self::openMailgate($mailgateId);
        } catch (\Throwable $e) {
            Toolbox::logError("MailAnalyzer: openMailgate #$mailgateId failed - " . $e->getMessage());
            $parm->input = false;
            return;
        }

        $uid           = (string) ($parm->input['_uid'] ?? '');
        $head          = $parm->input['_head'] ?? [];
        $from          = (string) ($head['from']       ?? '');
        $messageId     = trim(html_entity_decode((string) ($head['message_id'] ?? '')));
        $referencesRaw = html_entity_decode((string) ($head['references'] ?? ''));
        $subjectRaw    = trim((string) ($parm->input['name'] ?? $head['subject'] ?? ''));
        $subjectHash   = PluginMailanalyzerThreadResolver::hashSubject($subjectRaw);
        $bodyRaw       = (string) ($parm->input['content'] ?? '');

        // Domain filter (blacklist / whitelist / VIP)
        $domainFilter = new PluginMailanalyzerDomainFilter($config);
        $verdict      = $domainFilter->classify($from);

        if ($verdict === PluginMailanalyzerDomainFilter::RESULT_BLACKLIST) {
            Toolbox::logInfo("MailAnalyzer: blacklist hit for $from — refusing email UID=$uid");
            PluginMailanalyzerAuditLog::append(
                PluginMailanalyzerStats::ACTION_BLACKLIST_REJECTED,
                0,
                $mailgateId,
                $messageId,
                $from,
                $subjectRaw,
                $subjectHash,
                'sender domain blacklisted'
            );
            $parm->input = false;
            self::tryDeleteMail($localMailgate, $uid, MailCollector::REFUSED_FOLDER);
            return;
        }

        // Optional: enrich head with Thread-Index from the raw IMAP message
        if ($useThreadindex && $uid !== '') {
            try {
                $msg = $localMailgate->getMessage($uid);
                $threadIndex = $localMailgate->getThreadIndex($msg);
                if ($threadIndex !== null) {
                    $parm->input['_head']['threadindex'] = $threadIndex;
                    $head['threadindex'] = $threadIndex;
                }
            } catch (\Throwable $e) {
                Toolbox::logWarning("MailAnalyzer: Thread-Index lookup failed UID=$uid - " . $e->getMessage());
            }
        }

        // === SPF / DKIM / DMARC validation (RFC 8601 Authentication-Results) ===
        $authValidator = new PluginMailanalyzerAuthValidator($config);
        if ($authValidator->isEnabled() && $verdict !== PluginMailanalyzerDomainFilter::RESULT_VIP) {
            $authHeader = PluginMailanalyzerAuthValidator::readAuthResults($localMailgate, $uid);
            if ($authHeader !== '') {
                $authVerdict = $authValidator->parse($authHeader);
                $reject = $authValidator->shouldReject($authVerdict);
                if ($reject !== null) {
                    Toolbox::logInfo("MailAnalyzer: rejecting email — $reject (from=$from, uid=$uid)");
                    PluginMailanalyzerAuditLog::append(
                        PluginMailanalyzerStats::ACTION_AUTH_REJECTED,
                        0,
                        $mailgateId,
                        $messageId,
                        $from,
                        $subjectRaw,
                        $subjectHash,
                        $reject . sprintf(
                            ' (spf=%s, dkim=%s, dmarc=%s)',
                            $authVerdict['spf'],
                            $authVerdict['dkim'],
                            $authVerdict['dmarc']
                        )
                    );
                    $parm->input = false;
                    self::tryDeleteMail($localMailgate, $uid, MailCollector::REFUSED_FOLDER);
                    return;
                }
            }
        }

        // === Duplicate detection ===
        // 1) Exact Message-ID match
        $dupTicketId = PluginMailanalyzerThreadResolver::findDuplicateByMessageId($messageId, $mailgateId);

        // 2) Optional fallback: normalized-subject hash inside a recent window
        if (
            $dupTicketId === null
            && (int) ($config['enable_subject_hash_dedup'] ?? 0) === 1
            && $verdict !== PluginMailanalyzerDomainFilter::RESULT_WHITELIST
            && $verdict !== PluginMailanalyzerDomainFilter::RESULT_VIP
        ) {
            $windowMin = max(1, (int) ($config['subject_hash_window_minutes'] ?? 5));
            $dupTicketId = PluginMailanalyzerThreadResolver::findDuplicateBySubjectHash(
                $subjectHash,
                $mailgateId,
                $windowMin * 60
            );
        }

        if ($dupTicketId !== null) {
            Toolbox::logInfo("MailAnalyzer: duplicate blocked (existing ticket #$dupTicketId)");
            PluginMailanalyzerAuditLog::append(
                PluginMailanalyzerStats::ACTION_DUPLICATE_BLOCKED,
                $dupTicketId,
                $mailgateId,
                $messageId,
                $from,
                $subjectRaw,
                $subjectHash,
                $messageId !== ''
                    ? 'message-id match'
                    : 'subject-hash match (window)'
            );

            (new PluginMailanalyzerNotificationDispatcher($config))
                ->notifyDuplicateBlocked($mailgateId);

            $parm->input = false;
            self::tryDeleteMail($localMailgate, $uid, MailCollector::REFUSED_FOLDER);
            return;
        }

        // === Conversation matching ===
        $references = PluginMailanalyzerThreadResolver::getReferences(
            (string) ($head['threadindex'] ?? ''),
            $referencesRaw
        );
        $related = PluginMailanalyzerThreadResolver::findRelatedTicket($references, $mailgateId);

        if ($related !== null) {
            $ticket = new Ticket();
            $ticket->getFromDB($related['tickets_id']);
            $isClosed = (int) $ticket->fields['status'] === CommonITILObject::CLOSED;

            if (!$isClosed) {
                // Add as ITILFollowup on the existing open ticket
                $followup = new ITILFollowup();
                $input               = $parm->input;
                $input['items_id']   = $related['tickets_id'];
                $input['itemtype']   = 'Ticket';
                $input['users_id']   = $parm->input['_users_id_requester'] ?? 0;
                $input['add_reopen'] = 1;
                unset($input['urgency'], $input['entities_id'], $input['_ruleid']);

                $followupId = $followup->add($input);
                if ($followupId) {
                    PluginMailanalyzerAuditLog::append(
                        PluginMailanalyzerStats::ACTION_FOLLOWUP_CREATED,
                        $related['tickets_id'],
                        $mailgateId,
                        $messageId,
                        $from,
                        $subjectRaw,
                        $subjectHash,
                        'reference match → followup'
                    );
                } else {
                    Toolbox::logError("MailAnalyzer: ITILFollowup::add failed on ticket #{$related['tickets_id']}");
                }

                // Persist the new message-id so subsequent replies in this thread also match
                global $DB;
                $DB->insert(PluginMailanalyzerInstaller::TABLE_MESSAGE_ID, [
                    'message_id'        => $messageId,
                    'tickets_id'        => $related['tickets_id'],
                    'mailcollectors_id' => $mailgateId,
                    'subject_hash'      => $subjectHash,
                ]);

                $parm->input = false;
                self::tryDeleteMail($localMailgate, $uid, MailCollector::ACCEPTED_FOLDER);
                return;
            }

            // Existing ticket is closed → create a new ticket linked to it
            PluginMailanalyzerAuditLog::append(
                PluginMailanalyzerStats::ACTION_TICKET_LINKED,
                $related['tickets_id'],
                $mailgateId,
                $messageId,
                $from,
                $subjectRaw,
                $subjectHash,
                'reference match → linked (closed ticket)'
            );
            $parm->input['_link'] = [
                'link'         => Ticket_Ticket::LINK_TO,
                'tickets_id_1' => 0,
                'tickets_id_2' => $related['tickets_id'],
            ];
            // Mark this email so item_add does NOT additionally log a NEW_TICKET
            self::$pendingActionType = PluginMailanalyzerStats::ACTION_TICKET_LINKED;
        }

        // === Smart classification + VIP escalation ===
        self::applyVipBoost($parm, $verdict, $config);
        self::applyClassification($parm, $config, $subjectRaw, $bodyRaw);

        // Persist refs for future emails. tickets_id is filled in item_add.
        PluginMailanalyzerThreadResolver::persistReferences(
            $references,
            $messageId,
            $mailgateId,
            $subjectHash
        );

        // Carry to item_add hook
        self::$pendingReferences  = array_merge($references, $messageId !== '' ? [$messageId] : []);
        self::$pendingMessageId   = $messageId;
        self::$pendingSubjectHash = $subjectHash;
    }

    /**
     * item_add hook on Ticket — finalise: attach the new tickets_id to the
     * placeholder rows we inserted in pre_item_add.
     */
    public static function plugin_item_add_mailanalyzer(Ticket $parm): void
    {
        if (!isset($parm->input['_mailgate'])) {
            return;
        }
        $mailgateId = (int) $parm->input['_mailgate'];
        $head       = $parm->input['_head'] ?? [];

        $references = self::$pendingReferences;
        $messageId  = self::$pendingMessageId;
        if (empty($references)) {
            $references = PluginMailanalyzerThreadResolver::getReferences(
                (string) ($head['threadindex'] ?? ''),
                html_entity_decode((string) ($head['references'] ?? ''))
            );
            $messageId = trim(html_entity_decode((string) ($head['message_id'] ?? '')));
            if ($messageId !== '') {
                $references[] = $messageId;
            }
        }

        $updated = PluginMailanalyzerThreadResolver::attachTicketToReferences(
            $references,
            (int) $parm->fields['id'],
            $mailgateId
        );

        if ($updated && self::$pendingActionType !== PluginMailanalyzerStats::ACTION_TICKET_LINKED) {
            PluginMailanalyzerAuditLog::append(
                PluginMailanalyzerStats::ACTION_NEW_TICKET,
                (int) $parm->fields['id'],
                $mailgateId,
                $messageId,
                (string) ($head['from'] ?? ''),
                (string) ($parm->fields['name'] ?? ''),
                self::$pendingSubjectHash,
                'no prior reference → new ticket'
            );
        }

        self::$pendingReferences  = [];
        self::$pendingMessageId   = '';
        self::$pendingSubjectHash = '';
        self::$pendingActionType  = '';
    }

    /**
     * item_purge hook on Ticket — clean up message_id and attachment-hash tables.
     */
    public static function plugin_item_purge_mailanalyzer(Ticket $item): void
    {
        global $DB;
        $DB->delete(
            PluginMailanalyzerInstaller::TABLE_MESSAGE_ID,
            ['tickets_id' => $item->getID()]
        );
        PluginMailanalyzerAttachmentDedup::plugin_item_purge_ticket($item);
    }

    /**
     * Apply VIP escalation to ticket input (max urgency + optional flag).
     */
    private static function applyVipBoost(Ticket $parm, string $verdict, array $config): void
    {
        if ($verdict !== PluginMailanalyzerDomainFilter::RESULT_VIP) {
            return;
        }
        $current = (int) ($parm->input['urgency'] ?? 3);
        if ($current < 5) {
            $parm->input['urgency'] = 5;
        }
        Toolbox::logInfo("MailAnalyzer: VIP escalation applied to ticket-in-progress");
    }

    /**
     * Apply smart ITIL classification (Incident / Service Request) and
     * urgency boost from configurable keyword dictionaries.
     */
    private static function applyClassification(
        Ticket $parm,
        array $config,
        string $subject,
        string $body
    ): void {
        $classifier = new PluginMailanalyzerClassifier($config);
        if (!$classifier->isEnabled()) {
            return;
        }
        $decision = $classifier->decide($subject, $body);
        if ($decision['type'] !== null && !isset($parm->input['type'])) {
            $parm->input['type'] = $decision['type'];
        }
        if ($decision['urgency'] !== null) {
            $cur = (int) ($parm->input['urgency'] ?? 3);
            if ($cur < $decision['urgency']) {
                $parm->input['urgency'] = $decision['urgency'];
            }
        }
    }

    /**
     * Best-effort move of a processed/refused email out of the inbox.
     */
    private static function tryDeleteMail(PluginMailanalyzerMailCollector $mg, string $uid, string $folder): void
    {
        if ($uid === '') {
            return;
        }
        try {
            $mg->deleteMails($uid, $folder);
        } catch (\Throwable $e) {
            Toolbox::logWarning("MailAnalyzer: deleteMails($uid, $folder) failed - " . $e->getMessage());
        }
    }
}
