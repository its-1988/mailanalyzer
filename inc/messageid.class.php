<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — Searchable view of the message_id table.
GPLv2+
--------------------------------------------------------------------------
 */

/**
 * Thin CommonDBTM wrapper around glpi_plugin_mailanalyzer_message_id so
 * the table appears in GLPI's native Search Engine. Read-only — the table
 * is populated exclusively by the analyzer flow.
 */
class PluginMailanalyzerMessageId extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTable($classname = null): string
    {
        return PluginMailanalyzerInstaller::TABLE_MESSAGE_ID;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Message-ID record', 'Message-ID records', $nb, 'mailanalyzer');
    }

    public static function getIcon(): string
    {
        return 'fas fa-fingerprint';
    }

    /**
     * Search options for native GLPI search.
     */
    public function rawSearchOptions(): array
    {
        $tab = [];
        $tab[] = [
            'id'   => 'common',
            'name' => __('Message-ID record', 'mailanalyzer'),
        ];
        $tab[] = [
            'id'            => 1,
            'table'         => self::getTable(),
            'field'         => 'message_id',
            'name'          => __('Message ID'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 2,
            'table'         => self::getTable(),
            'field'         => 'tickets_id',
            'name'          => __('Ticket'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 3,
            'table'         => self::getTable(),
            'field'         => 'mailcollectors_id',
            'name'          => __('Mail Collector'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 4,
            'table'         => self::getTable(),
            'field'         => 'subject_hash',
            'name'          => __('Subject hash', 'mailanalyzer'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 5,
            'table'         => self::getTable(),
            'field'         => 'date_created',
            'name'          => __('Date'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        return $tab;
    }

    public static function getMenuName()
    {
        return __('Message-IDs', 'mailanalyzer');
    }

    public static function canView(): bool
    {
        return Session::haveRight('config', READ);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }
}
