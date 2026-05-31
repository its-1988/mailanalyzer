<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — AJAX endpoint that persists the settings.
GPLv2+
--------------------------------------------------------------------------

Why a dedicated AJAX endpoint instead of posting to the core Config form:
the settings form is rendered inside an AJAX-loaded Config tab, and a CSRF
token generated in that context is not reliably valid when the form is later
submitted (→ "CSRF check failed"). Submitting via jQuery AJAX instead lets
GLPI attach the *page* CSRF token in the `X-Glpi-Csrf-Token` header (see
js/common.js `ajaxSend`), which the kernel validates with `preserve_token`.
 */

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

// Authenticated admins only. CSRF is validated by the kernel via the
// X-Glpi-Csrf-Token header (jQuery adds it automatically).
Session::checkRight('config', UPDATE);

PluginMailanalyzerConfig::saveFromPost($_POST);

Session::addMessageAfterRedirect(
    __('Mail Analyzer settings have been saved.', 'mailanalyzer'),
    false,
    INFO
);

$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($is_ajax) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => true]);
} else {
    // Graceful fallback if JS did not intercept the submit.
    Html::back();
}
