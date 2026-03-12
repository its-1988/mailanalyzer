<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI
Copyright (C) 2011-2025 by Raynet SAS a company of A.Raymond Network.

https://www.araymond.com/
-------------------------------------------------------------------------

LICENSE

This file is part of MailAnalyzer plugin for GLPI.

This file is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This plugin is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this plugin. If not, see <http://www.gnu.org/licenses/>.
--------------------------------------------------------------------------
*/

use Laminas\Mail\Storage\Message;

/**
 * Extended MailCollector for the MailAnalyzer plugin.
 * Provides direct access to mail storage for reading headers
 * (Thread-Index, References) and managing emails.
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
     * Connect to the mail server using the collector configuration.
     *
     * @return void
     * @throws \Exception If connection fails
     */
    public function connect(): void
    {
        $config = Toolbox::parseMailServerConnectString($this->fields['host']);

        $params = [
            'host'      => $config['address'],
            'user'      => $this->fields['login'],
            'password'  => (new GLPIKey())->decrypt($this->fields['passwd']),
            'port'      => $config['port']
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
            if ($this->fields['errors'] > 0) {
                $this->update([
                    'id'     => $this->getID(),
                    'errors' => 0
                ]);
            }
        } catch (\Throwable $e) {
            $this->update([
                'id'     => $this->getID(),
                'errors' => ($this->fields['errors'] + 1)
            ]);
            Toolbox::logError("MailAnalyzer: Mail server connection failed - " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract Thread-Index header from an email message.
     * The Thread-Index is a Microsoft Exchange-specific header used
     * to track email conversation threads.
     *
     * @param Message $message The email message to extract from
     * @return string|null Hex-encoded Thread-Index or null if not present
     */
    public function getThreadIndex(Message $message): ?string
    {
        try {
            if (isset($message->threadindex)) {
                if ($val = $message->getHeader('threadindex')) {
                    return bin2hex(substr(base64_decode($val->getFieldValue()), 6, 16));
                }
            }
        } catch (\Throwable $e) {
            Toolbox::logWarning("MailAnalyzer: Failed to extract Thread-Index - " . $e->getMessage());
        }
        return null;
    }

    /**
     * Get a specific message from the mail storage by its unique ID.
     *
     * @param string $uid Unique ID of the message
     * @return Message The email message
     * @throws \Throwable If the message cannot be retrieved
     */
    public function getMessage(string $uid): Message
    {
        try {
            return $this->storage->getMessage($this->storage->getNumberByUniqueId($uid));
        } catch (\Throwable $e) {
            Toolbox::logError("MailAnalyzer: Unable to get message UID: $uid - " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete or move a mail from the mailbox.
     *
     * @param string $uid    Mail UID
     * @param string $folder Folder to move to (delete if empty)
     * @return bool True on success
     */
    public function deleteMails(string $uid, string $folder = ''): bool
    {
        // Disable move support, POP protocol only has the INBOX folder
        if (strstr($this->fields['host'], "/pop")) {
            $folder = '';
        }

        if (!empty($folder) && isset($this->fields[$folder]) && !empty($this->fields[$folder])) {
            $name = mb_convert_encoding($this->fields[$folder], "UTF7-IMAP", "UTF-8");
            try {
                $this->storage->moveMessage($this->storage->getNumberByUniqueId($uid), $name);
                return true;
            } catch (\Throwable $e) {
                // raise an error and fallback to delete
                Toolbox::logWarning(
                    sprintf(
                        "MailAnalyzer: Invalid configuration for %s folder in receiver %s - falling back to delete",
                        $folder,
                        $this->getName()
                    )
                );
            }
        }
        $this->storage->removeMessage($this->storage->getNumberByUniqueId($uid));
        return true;
    }
}
