<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI
Copyright (C) 2011-2026 by Raynet SAS a company of A.Raymond Network.

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

define("PLUGIN_MAILANALYZER_VERSION", "5.0.0");
// Minimal GLPI version, inclusive
define('PLUGIN_MAILANALYZER_MIN_GLPI', '11.0.0');
// Maximum GLPI version, exclusive
define('PLUGIN_MAILANALYZER_MAX_GLPI', '11.1');

/**
 * Init hooks of the plugin.
 */
function plugin_init_mailanalyzer(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['mailanalyzer'] = true;

    // Lifecycle hooks on Ticket + Document_Item
    $PLUGIN_HOOKS['pre_item_add']['mailanalyzer'] = [
        'Ticket'        => ['PluginMailAnalyzer', 'plugin_pre_item_add_mailanalyzer'],
        'Document_Item' => ['PluginMailanalyzerAttachmentDedup', 'plugin_pre_item_add'],
    ];
    $PLUGIN_HOOKS['item_add']['mailanalyzer'] = [
        'Ticket' => ['PluginMailAnalyzer', 'plugin_item_add_mailanalyzer'],
    ];
    $PLUGIN_HOOKS['item_purge']['mailanalyzer'] = [
        'Ticket' => ['PluginMailAnalyzer', 'plugin_item_purge_mailanalyzer'],
    ];

    // Native cron task
    $PLUGIN_HOOKS['cron']['mailanalyzer'] = ['PluginMailanalyzerCrontask'];

    // Console (bin/console) commands
    $PLUGIN_HOOKS['console_command']['mailanalyzer'] = [
        'PluginMailanalyzerCleanupCommand',
    ];

    // NotificationEvent label used when raising "too many duplicates" alert
    $PLUGIN_HOOKS['item_action']['MailCollector']['mailanalyzer_duplicate_alert']
        = __('High volume of blocked duplicates', 'mailanalyzer');

    // Configuration tab on the standard Config item
    if (Session::haveRightsOr('config', [READ, UPDATE])) {
        Plugin::registerClass('PluginMailanalyzerConfig', ['addtabon' => 'Config']);
        $PLUGIN_HOOKS['config_page']['mailanalyzer'] = 'front/config.form.php';
    }

    // Native GLPI Search registration for the audit log + message-id table
    Plugin::registerClass('PluginMailanalyzerStats');
    Plugin::registerClass('PluginMailanalyzerMessageId');
}

/**
 * Top-level menu entry exposing the audit log + message-id search pages.
 * Returns an array consumed by GLPI's menu builder.
 */
function plugin_mailanalyzer_getMenuContent(): array|false
{
    if (!Session::haveRight('config', READ)) {
        return false;
    }
    return [
        'title' => __('Mail Analyzer', 'mailanalyzer'),
        'page'  => Plugin::getWebDir('mailanalyzer') . '/front/auditlog.php',
        'icon'  => 'ti ti-mail',
        'links' => [
            'search' => Plugin::getWebDir('mailanalyzer') . '/front/auditlog.php',
            'config' => Plugin::getWebDir('mailanalyzer') . '/front/config.form.php',
        ],
        'options' => [
            'audit' => [
                'title' => PluginMailanalyzerStats::getTypeName(2),
                'page'  => Plugin::getWebDir('mailanalyzer') . '/front/auditlog.php',
                'icon'  => 'fas fa-history',
            ],
            'messageid' => [
                'title' => PluginMailanalyzerMessageId::getTypeName(2),
                'page'  => Plugin::getWebDir('mailanalyzer') . '/front/messageid.php',
                'icon'  => 'fas fa-fingerprint',
            ],
        ],
    ];
}

/**
 * Plugin metadata.
 */
function plugin_version_mailanalyzer(): array
{
    return [
        'name'         => __('Mail Analyzer', 'mailanalyzer'),
        'version'      => PLUGIN_MAILANALYZER_VERSION,
        'author'       => 'Olivier Moron, Kadosh',
        'license'      => 'GPLv2+',
        'homepage'     => 'https://github.com/tomolimo/mailanalyzer',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MAILANALYZER_MIN_GLPI,
                'max' => PLUGIN_MAILANALYZER_MAX_GLPI,
            ],
        ],
    ];
}

/**
 * Prerequisites check (called before install).
 */
function plugin_mailanalyzer_check_prerequisites(): bool
{
    if (
        version_compare(GLPI_VERSION, PLUGIN_MAILANALYZER_MIN_GLPI, 'lt')
        || version_compare(GLPI_VERSION, PLUGIN_MAILANALYZER_MAX_GLPI, 'ge')
    ) {
        echo sprintf(
            'This plugin requires GLPI >= %s and < %s',
            PLUGIN_MAILANALYZER_MIN_GLPI,
            PLUGIN_MAILANALYZER_MAX_GLPI
        );
        return false;
    }
    return true;
}

/**
 * Config validation hook (no-op).
 */
function plugin_mailanalyzer_check_config(): bool
{
    return true;
}
