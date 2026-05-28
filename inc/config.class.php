<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — Configuration tab.
Copyright (C) 2011-2026 by Raynet SAS a company of A.Raymond Network.
GPLv2+
--------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;

/**
 * Renders the plugin settings tab on the standard Config item.
 * Persists into the `plugin:mailanalyzer` config context via the native
 * Config form pipeline — submission is handled by GLPI core.
 */
class PluginMailanalyzerConfig extends CommonDBTM
{
    public static function getTypeName($nb = 0): string
    {
        return __('Mail Analyzer setup', 'mailanalyzer');
    }

    public function getName($with_comment = 0): string
    {
        return __('Mail Analyzer', 'mailanalyzer');
    }

    public static function getIcon(): string
    {
        return 'ti ti-mail';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item->getType() === 'Config') {
            return "<i class='ti ti-mail me-2'></i>" . __('Mail Analyzer', 'mailanalyzer');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item->getType() === 'Config') {
            self::showConfigForm();
        }
        return true;
    }

    /**
     * Render the full config screen: form + health-check + dashboard.
     */
    public static function showConfigForm(): bool
    {
        $config = Config::getConfigurationValues('plugin:mailanalyzer');
        $config = array_merge(self::defaults(), $config);

        TemplateRenderer::getInstance()->display('@mailanalyzer/config.html.twig', [
            'config'           => $config,
            'form_action'      => Toolbox::getItemTypeFormURL('Config'),
            'csrf_token_value' => Session::getNewCSRFToken(),
            'incident_type_id' => Ticket::INCIDENT_TYPE,
            'request_type_id'  => Ticket::DEMAND_TYPE,
            'default_type_choices' => [
                0                       => __('Do not change', 'mailanalyzer'),
                Ticket::INCIDENT_TYPE   => __('Incident', 'mailanalyzer'),
                Ticket::DEMAND_TYPE     => __('Service Request', 'mailanalyzer'),
            ],
            'yesno_choices' => [
                0 => __('No'),
                1 => __('Yes'),
            ],
        ]);

        self::showHealthCheck();

        $period = $_SESSION['plugin_mailanalyzer_stats_period'] ?? '30days';
        PluginMailanalyzerStats::showDashboard($period);

        return false;
    }

    private static function showHealthCheck(): void
    {
        PluginMailanalyzerStats::showHealthCheck();
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        return [
            'use_threadindex'                 => 0,
            'whitelist_domains'               => '',
            'blacklist_domains'               => '',
            'vip_senders'                     => '',
            'enable_smart_classification'     => 0,
            'incident_keywords'               => '',
            'request_keywords'                => '',
            'default_request_type'            => 0,
            'auto_priority_keywords'          => '',
            'enable_subject_hash_dedup'       => 0,
            'subject_hash_window_minutes'     => 5,
            'duplicate_alert_threshold'       => 20,
            'duplicate_alert_window_seconds'  => 3600,
            'enable_auth_validation'          => 0,
            'reject_on_spf_fail'              => 0,
            'reject_on_dkim_fail'             => 0,
            'reject_on_dmarc_fail'            => 0,
            'enable_attachment_dedup'         => 0,
        ];
    }
}
