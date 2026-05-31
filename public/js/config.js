/*
 * -------------------------------------------------------------------------
 * MailAnalyzer plugin for GLPI — config form submit (AJAX).
 * GPLv2+
 * -------------------------------------------------------------------------
 *
 * The settings form lives inside an AJAX-loaded Config tab. A CSRF token
 * rendered in that context is not reliably valid at submit time (→ "CSRF check
 * failed"), so we submit the form via jQuery AJAX: GLPI then attaches the page
 * CSRF token in the X-Glpi-Csrf-Token header (js/common.js ajaxSend), which the
 * kernel validates. The handler is delegated from document because the form is
 * injected after page load.
 */

/* global $ */

(function () {
    "use strict";

    if (typeof $ === "undefined") {
        return;
    }

    $(document).on("submit", "form.mailanalyzer-config", function (e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $form.find('button[type="submit"]').prop("disabled", true);

        $.ajax({
            url: $form.attr("action"),
            method: "POST",
            data: $form.serialize(),
            dataType: "json"
        }).done(function () {
            // Reload so the saved values + the GLPI "saved" toast are shown.
            window.location.reload();
        }).fail(function (xhr) {
            $btn.prop("disabled", false);
            if (window.console) {
                window.console.error("[mailanalyzer] settings save failed — HTTP "
                    + (xhr && xhr.status));
            }
            window.alert("Mail Analyzer: settings could not be saved (HTTP "
                + (xhr && xhr.status) + ").");
        });
    });
})();
