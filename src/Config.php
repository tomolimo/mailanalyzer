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
use Dropdown;
use Html;
use Session;
use Toolbox;

/**
 * Plugin configuration — tab added on Setup > General.
 *
 * Estrategia final GLPI 11:
 * - El form hace POST al MISMO endpoint que lo renderiza: ajax/common.tabs.php
 *   no — eso tampoco funciona.
 * - Solución: form con action al front del plugin + AJAX submit via JS para
 *   evitar el reload de página, usando el token CSRF generado por Html::closeForm().
 *
 * Estrategia real final:
 * - Usar el hook nativo de GLPI correctamente: el form apunta al core
 *   front/config.form.php, Html::closeForm() agrega el CSRF token,
 *   y config_class llama configUpdate() en el plugin.
 * - El problema anterior NO era el mecanismo — era que configUpdate() no
 *   estaba siendo llamado porque en GLPI 11 src/Config.php cambió el flujo.
 * - Verificar el flujo real leyendo src/Config.php de GLPI 11.
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

    /**
     * Llamado por Config::prepareInputForUpdate() cuando config_class está en el POST.
     * Normaliza los valores antes de que sean guardados.
     */
    public static function configUpdate(array $input): array
    {
        $input['use_threadindex'] = isset($input['use_threadindex']) ? (int)$input['use_threadindex'] : 0;
        return $input;
    }

    public static function showConfigForm(GlpiConfig $item): void
    {
        $config = GlpiConfig::getConfigurationValues('plugin:mailanalyzer');

        if (!isset($config['use_threadindex'])) {
            GlpiConfig::setConfigurationValues('plugin:mailanalyzer', ['use_threadindex' => 0]);
            $config['use_threadindex'] = 0;
        }

        $use_threadindex = (int) $config['use_threadindex'];
        $canedit         = Session::haveRight('config', UPDATE);

        // El form apunta al ajax handler del plugin que guarda directamente
        // usando el mismo contexto de sesión que el tab (mismo dominio, mismo request)
        $form_action = \Plugin::getWebDir('mailanalyzer') . '/front/save_config.php';

        echo '<form name="mailanalyzerconfig" method="post"'
            . ' action="' . htmlspecialchars($form_action) . '"'
            . ' data-track-changes="true">';

        echo '<div class="card mb-4">';
        echo '<div class="card-header">';
        echo '<h3 class="card-title">';
        echo '<i class="ti ti-mail-search me-2"></i>';
        echo htmlspecialchars(__('Mail Analyzer setup', 'mailanalyzer'));
        echo '</h3>';
        echo '</div>';

        echo '<div class="card-body">';
        echo '<div class="row mb-3 align-items-center">';

        echo '<label class="col-sm-4 col-form-label fw-bold">';
        echo htmlspecialchars(__('Use of Thread index', 'mailanalyzer'));
        echo '</label>';

        echo '<div class="col-sm-8">';
        Dropdown::showYesNo('use_threadindex', $use_threadindex, -1, [
            'disabled' => !$canedit,
        ]);
        echo '<div class="form-text text-muted mt-1">';
        echo htmlspecialchars(
            __('When enabled, the Microsoft Thread-Index header is used in addition to References to link emails to existing tickets.', 'mailanalyzer')
        );
        echo '</div>';
        echo '</div>';

        echo '</div>';
        echo '</div>';

        if ($canedit) {
            echo '<div class="card-footer d-flex">';
            echo '<button type="submit" name="update" class="btn btn-primary ms-auto">';
            echo '<i class="ti ti-device-floppy me-1"></i> ';
            echo htmlspecialchars(_sx('button', 'Save'));
            echo '</button>';
            echo '</div>';
        }

        echo '</div>';

        // Html::closeForm() cierra </form> y agrega _glpi_csrf_token automáticamente
        Html::closeForm();
    }
}
