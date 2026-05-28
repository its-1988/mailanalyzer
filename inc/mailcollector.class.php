<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — Extended MailCollector.
Copyright (C) 2011-2026 by Raynet SAS a company of A.Raymond Network.
GPLv2+
--------------------------------------------------------------------------
*/

use Laminas\Mail\Storage\Message;

/**
 * Thin wrapper around the IMAP/POP3 storage to:
 *  - open a dedicated connection (so we can read raw headers safely),
 *  - extract the Microsoft Exchange Thread-Index,
 *  - move/delete a single message by its UID.
 *
 * Re-uses the standard `glpi_mailcollectors` table — no schema of its own.
 */
class PluginMailanalyzerMailCollector extends CommonDBTM
{
    private $storage;
    public int $uid = -1;

    public static function getTable($classname = null): string
    {
        return MailCollector::getTable();
    }

    /**
     * Connect to the mail server using stored collector credentials.
     *
     * @throws \Exception on unsupported server type / IMAP failure
     */
    public function connect(): void
    {
        $config = Toolbox::parseMailServerConnectString($this->fields['host']);

        $params = [
            'host'     => $config['address'],
            'user'     => $this->fields['login'],
            'password' => (new GLPIKey())->decrypt($this->fields['passwd']),
            'port'     => $config['port'],
        ];
        if ($config['ssl']) {
            $params['ssl'] = 'SSL';
        }
        if ($config['tls']) {
            $params['ssl'] = 'TLS';
        }
        if (!empty($config['mailbox'])) {
            $params['folder'] = mb_convert_encoding($config['mailbox'], 'UTF7-IMAP', 'UTF-8');
        }
        if ($config['validate-cert'] === false) {
            $params['novalidatecert'] = true;
        }

        try {
            $storage = Toolbox::getMailServerStorageInstance($config['type'], $params);
            if ($storage === null) {
                throw new \Exception(sprintf(__('Unsupported mail server type:%s.'), $config['type']));
            }
            $this->storage = $storage;
            if ((int) ($this->fields['errors'] ?? 0) > 0) {
                $this->update(['id' => $this->getID(), 'errors' => 0]);
            }
        } catch (\Throwable $e) {
            $this->update([
                'id'     => $this->getID(),
                'errors' => (int) ($this->fields['errors'] ?? 0) + 1,
            ]);
            Toolbox::logError('MailAnalyzer: mail server connect failed - ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract Microsoft Exchange Thread-Index from a message header.
     * Returns the 16-byte conversation prefix as hex (string), or null.
     */
    public function getThreadIndex(Message $message): ?string
    {
        try {
            if (!isset($message->threadindex)) {
                return null;
            }
            $h = $message->getHeader('threadindex');
            if (!$h) {
                return null;
            }
            $decoded = base64_decode((string) $h->getFieldValue(), true);
            if ($decoded === false || strlen($decoded) < 22) {
                return null;
            }
            return bin2hex(substr($decoded, 6, 16));
        } catch (\Throwable $e) {
            Toolbox::logWarning('MailAnalyzer: Thread-Index extract failed - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch a message by its server-side unique ID.
     *
     * @throws \Throwable on lookup failure
     */
    public function getMessage(string $uid): Message
    {
        try {
            return $this->storage->getMessage(
                $this->storage->getNumberByUniqueId($uid)
            );
        } catch (\Throwable $e) {
            Toolbox::logError("MailAnalyzer: getMessage UID=$uid failed - " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Move (or, for POP / on failure, delete) a single mail by UID.
     */
    public function deleteMails(string $uid, string $folder = ''): bool
    {
        // POP only has INBOX — no folder support
        if (str_contains((string) $this->fields['host'], '/pop')) {
            $folder = '';
        }

        if ($folder !== '' && !empty($this->fields[$folder])) {
            $name = mb_convert_encoding($this->fields[$folder], 'UTF7-IMAP', 'UTF-8');
            try {
                $this->storage->moveMessage(
                    $this->storage->getNumberByUniqueId($uid),
                    $name
                );
                return true;
            } catch (\Throwable $e) {
                Toolbox::logWarning(sprintf(
                    'MailAnalyzer: invalid %s folder on %s — falling back to delete',
                    $folder,
                    $this->getName()
                ));
            }
        }

        $this->storage->removeMessage($this->storage->getNumberByUniqueId($uid));
        return true;
    }
}
