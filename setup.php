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

define("PLUGIN_MAILANALYZER_VERSION", "4.1.0");
// Minimal GLPI version, inclusive
define('PLUGIN_MAILANALYZER_MIN_GLPI', '11.0.0');
// Maximum GLPI version, exclusive
define('PLUGIN_MAILANALYZER_MAX_GLPI', '11.1');

/**
 * Init the hooks of the plugin.
 * Registers all event hooks, classes, and configuration pages.
 *
 * @return void
 */
function plugin_init_mailanalyzer(): void
{
   global $PLUGIN_HOOKS;

   Plugin::registerClass('PluginMailAnalyzer');
   Plugin::registerClass('PluginMailanalyzerStats');

   $PLUGIN_HOOKS['csrf_compliant']['mailanalyzer'] = true;

   $PLUGIN_HOOKS['pre_item_add']['mailanalyzer'] = [
      'Ticket' => ['PluginMailAnalyzer', 'plugin_pre_item_add_mailanalyzer'],
   ];

   $PLUGIN_HOOKS['item_add']['mailanalyzer'] = [
      'Ticket' => ['PluginMailAnalyzer', 'plugin_item_add_mailanalyzer']
   ];

   $PLUGIN_HOOKS['item_purge']['mailanalyzer'] = [
      'Ticket' => ['PluginMailAnalyzer', 'plugin_item_purge_mailanalyzer']
   ];

   if (Session::haveRightsOr("config", [READ, UPDATE])) {
      Plugin::registerClass('PluginMailanalyzerConfig', ['addtabon' => 'Config']);
      $PLUGIN_HOOKS['config_page']['mailanalyzer'] = 'front/config.form.php';
   }
}


/**
 * Get the name and the version of the plugin.
 *
 * @return array Plugin metadata
 */
function plugin_version_mailanalyzer(): array
{
   return [
      'name' => __('Mail Analyzer'),
      'version' => PLUGIN_MAILANALYZER_VERSION,
      'author' => 'Olivier Moron',
      'license' => 'GPLv2+',
      'homepage' => 'https://github.com/tomolimo/mailanalyzer',
      'requirements' => [
         'glpi' => [
            'min' => PLUGIN_MAILANALYZER_MIN_GLPI,
            'max' => PLUGIN_MAILANALYZER_MAX_GLPI
         ]
      ]
   ];
}


/**
 * Check prerequisites before install.
 *
 * @return bool
 */
function plugin_mailanalyzer_check_prerequisites(): bool
{
   if (
      version_compare(GLPI_VERSION, PLUGIN_MAILANALYZER_MIN_GLPI, 'lt')
      || version_compare(GLPI_VERSION, PLUGIN_MAILANALYZER_MAX_GLPI, 'ge')
   ) {
      echo "This plugin requires GLPI >= " . PLUGIN_MAILANALYZER_MIN_GLPI . " and < " . PLUGIN_MAILANALYZER_MAX_GLPI;
      return false;
   }
   return true;
}


/**
 * Check plugin configuration.
 *
 * @return bool
 */
function plugin_mailanalyzer_check_config(): bool
{
   return true;
}
