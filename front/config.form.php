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
 * GLPI 11: redirigir al tab Mail Analyzer en Setup > General.
 * El POST lo maneja el core (front/config.form.php del core).
 * Este archivo solo existe para el hook config_page.
 */
Session::setActiveTab('Config', 'GlpiPlugin\Mailanalyzer\Config$1');
Html::redirect(Toolbox::getItemTypeFormURL('Config'));
