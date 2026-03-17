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

use Glpi\Plugin\Hooks;
use GlpiPlugin\Mailanalyzer\Config;
use GlpiPlugin\Mailanalyzer\MailAnalyzer;

define('PLUGIN_MAILANALYZER_VERSION', '4.0.0');

// Minimal GLPI version, inclusive
define('PLUGIN_MAILANALYZER_MIN_GLPI', '11.0.0');
// Maximum GLPI version, exclusive
define('PLUGIN_MAILANALYZER_MAX_GLPI', '12.0.0');

/**
 * Init the hooks of the plugin.
 */
function plugin_init_mailanalyzer(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['mailanalyzer'] = true;

    if (!Plugin::isPluginActive('mailanalyzer')) {
        return;
    }

    // Register hooks for Ticket lifecycle
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['mailanalyzer'] = [
        'Ticket' => [MailAnalyzer::class, 'preItemAdd'],
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['mailanalyzer'] = [
        'Ticket' => [MailAnalyzer::class, 'itemAdd'],
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['mailanalyzer'] = [
        'Ticket' => [MailAnalyzer::class, 'itemPurge'],
    ];

    // Config tab on Setup > General
    if (Session::haveRightsOr('config', [READ, UPDATE])) {
        Plugin::registerClass(Config::class, ['addtabon' => \Config::class]);
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['mailanalyzer'] = 'front/config.form.php';
    }
}


/**
 * Get the name and the version of the plugin.
 */
function plugin_version_mailanalyzer(): array
{
    return [
        'name'         => __('Mail Analyzer', 'mailanalyzer'),
        'version'      => PLUGIN_MAILANALYZER_VERSION,
        'author'       => 'Olivier Moron',
        'license'      => 'GPLv2+',
        'homepage'     => 'https://github.com/tomolimo/mailanalyzer',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MAILANALYZER_MIN_GLPI,
                'max' => PLUGIN_MAILANALYZER_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.1',
            ],
        ],
    ];
}


/**
 * Check prerequisites before install.
 */
function plugin_mailanalyzer_check_prerequisites(): bool
{
    return true;
}


/**
 * Check config.
 */
function plugin_mailanalyzer_check_config(): bool
{
    return true;
}
