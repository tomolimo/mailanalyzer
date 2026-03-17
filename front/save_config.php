<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI
Copyright (C) 2011-2025 by Raynet SAS a company of A.Raymond Network.
-------------------------------------------------------------------------
LICENSE: GPLv2+
--------------------------------------------------------------------------
 */

/**
 * Endpoint en front/ para guardar la configuración del plugin.
 *
 * GLPI 11 CSRF rules:
 * - front/ y report/ → GLPI verifica $_POST['_glpi_csrf_token'] automáticamente
 *   ANTES de que este archivo se ejecute (en inc/includes.php / Firewall).
 *   Html::closeForm() inyecta ese token en el form → compatible.
 *
 * - ajax/ → GLPI busca el header HTTP 'X-Glpi-Csrf-Token' (no $_POST)
 *   → INCOMPATIBLE con form POST normal.
 *
 * Por eso el endpoint está aquí en front/ y no en ajax/.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['update'])) {
    Html::redirect(Toolbox::getItemTypeFormURL('Config'));
    return;
}

// El CSRF ya fue validado por el Firewall de GLPI antes de llegar aquí.
// Solo verificar permisos.
if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
    return;
}

// Guardar configuración
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
