<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI
Copyright (C) 2011-2026 by Raynet SAS a company of A.Raymond Network.
-------------------------------------------------------------------------
LICENSE: GPLv2+
--------------------------------------------------------------------------
 */

/**
 * Endpoint under front/ that saves the plugin configuration.
 *
 * GLPI 11 CSRF rules:
 * - front/ and report/ → GLPI automatically verifies $_POST['_glpi_csrf_token']
 *   BEFORE this file is executed (in the GLPI Firewall). The Twig template
 *   renders that token via csrf_token(), so the form is compatible.
 *
 * - ajax/ → GLPI looks for the 'X-Glpi-Csrf-Token' HTTP header instead
 *   (not $_POST) → incompatible with a regular form POST.
 *
 * That's why this endpoint lives in front/ and not in ajax/.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['update'])) {
    Html::redirect(Toolbox::getItemTypeFormURL('Config'));
    return;
}

// CSRF was already validated by the GLPI Firewall before reaching here.
// Only the user's rights need to be checked.
if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
    return;
}

// Save configuration
$use_threadindex = isset($_POST['use_threadindex']) ? (int) $_POST['use_threadindex'] : 0;

Config::setConfigurationValues('plugin:mailanalyzer', [
    'use_threadindex' => $use_threadindex,
]);

Session::addMessageAfterRedirect(
    __('Configuration updated', 'mailanalyzer'),
    true,
    INFO
);

Html::redirect(
    Toolbox::getItemTypeFormURL('Config')
    . '?forcetab='
    . urlencode('GlpiPlugin\Mailanalyzer\Config$1')
);
