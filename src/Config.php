<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI
Copyright (C) 2011-2025 by Raynet SAS a company of A.Raymond Network.
-------------------------------------------------------------------------
LICENSE: GPLv2+
--------------------------------------------------------------------------
 */

namespace GlpiPlugin\Mailanalyzer;

use CommonGLPI;
use Config as GlpiConfig;
use Glpi\Application\View\TemplateRenderer;
use Plugin;
use Session;

/**
 * Plugin configuration — tab added on Setup > General.
 *
 * The form posts to front/save_config.php (not ajax/), since GLPI 11 only
 * verifies the $_POST['_glpi_csrf_token'] for requests under front/ and
 * report/. The CSRF token is rendered by the Twig template via csrf_token().
 */
class Config extends GlpiConfig
{
    public static function getTypeName($nb = 0): string
    {
        return __('Mail Analyzer', 'mailanalyzer');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof GlpiConfig) {
            return self::createTabEntry(__('Mail Analyzer', 'mailanalyzer'));
        }
        return '';
    }

    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ): bool {
        if ($item instanceof GlpiConfig) {
            self::showConfigForm($item);
        }
        return true;
    }

    public static function showConfigForm(GlpiConfig $item): void
    {
        $config = GlpiConfig::getConfigurationValues('plugin:mailanalyzer');

        if (!isset($config['use_threadindex'])) {
            GlpiConfig::setConfigurationValues('plugin:mailanalyzer', ['use_threadindex' => 0]);
            $config['use_threadindex'] = 0;
        }

        TemplateRenderer::getInstance()->display('@mailanalyzer/pages/config.html.twig', [
            'config_url'      => Plugin::getWebDir('mailanalyzer') . '/front/save_config.php',
            'use_threadindex' => (int) $config['use_threadindex'],
            'can_update'      => Session::haveRight('config', UPDATE),
        ]);
    }
}
