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
 * GLPI 11: redirect to the Mail Analyzer tab on Setup > General.
 * This file only exists to satisfy the Hooks::CONFIG_PAGE hook target.
 */
Session::setActiveTab('Config', 'GlpiPlugin\Mailanalyzer\Config$1');
Html::redirect(Toolbox::getItemTypeFormURL('Config'));
