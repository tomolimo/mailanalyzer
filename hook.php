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
 * Install hook — compatible with GLPI 11.
 *
 * Pattern for GLPI 11:
 *  - Fresh install  → $DB->doQueryOrDie() with CREATE TABLE (only way to create
 *                     a table that doesn't exist yet; Migration::addField() does
 *                     ALTER TABLE ADD COLUMN which requires the table to exist).
 *  - Upgrade path   → Migration::changeField() / addField() / addKey() for schema
 *                     changes on an already existing table.
 *
 * All integer FK columns MUST be UNSIGNED to avoid the deprecation warning
 * "Usage of signed integers in primary or foreign keys is discouraged".
 */
function plugin_mailanalyzer_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_MAILANALYZER_VERSION);
    $table     = 'glpi_plugin_mailanalyzer_message_id';

    if (!$DB->tableExists($table)) {
        // ── Fresh install ─────────────────────────────────────────────────────
        // doQueryOrDie() is the correct method in GLPI 11 for DDL statements
        // that cannot be expressed through the Migration column-by-column API
        // (which only handles ALTER TABLE on existing tables).
        $charset   = \DBConnection::getDefaultCharset();
        $collation = \DBConnection::getDefaultCollation();

        $DB->doQueryOrDie(
            "CREATE TABLE `{$table}` (
                `id`                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                `message_id`        VARCHAR(255) COLLATE {$collation} NOT NULL DEFAULT '0',
                `tickets_id`        INT UNSIGNED     NOT NULL DEFAULT '0',
                `mailcollectors_id` INT UNSIGNED     NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                UNIQUE KEY `message_id` (`message_id`, `mailcollectors_id`),
                KEY `tickets_id` (`tickets_id`)
            ) ENGINE=InnoDB
              DEFAULT CHARSET={$charset}
              COLLATE={$collation};",
            "Cannot create {$table}"
        );
    } else {
        // ── Upgrade path ──────────────────────────────────────────────────────

        // 1. Rename legacy mailgate_id → mailcollectors_id
        if ($DB->fieldExists($table, 'mailgate_id') && !$DB->fieldExists($table, 'mailcollectors_id')) {
            $migration->changeField($table, 'mailgate_id', 'mailcollectors_id', 'integer', [
                'value'    => 0,
                'unsigned' => true,
            ]);
            $migration->dropKey($table, 'message_id');
            $migration->addKey($table, ['message_id', 'mailcollectors_id'], 'message_id', 'UNIQUE');
        }

        // 2. Add mailcollectors_id if still completely absent
        if (!$DB->fieldExists($table, 'mailcollectors_id')) {
            $migration->addField($table, 'mailcollectors_id', 'integer', [
                'value'    => 0,
                'unsigned' => true,
                'after'    => 'message_id',
            ]);
            $migration->dropKey($table, 'message_id');
            $migration->addKey($table, ['message_id', 'mailcollectors_id'], 'message_id', 'UNIQUE');
        }

        // 3. Rename ticket_id → tickets_id (very old schema)
        if ($DB->fieldExists($table, 'ticket_id') && !$DB->fieldExists($table, 'tickets_id')) {
            $migration->changeField($table, 'ticket_id', 'tickets_id', 'integer', [
                'value'    => 0,
                'unsigned' => true,
            ]);
        }

        // 4 & 5. Forzar UNSIGNED en tickets_id y mailcollectors_id.
        //
        // Migration::changeField() con tipo 'integer' genera INT(11) firmado
        // ignorando la opción 'unsigned' => true. Para forzar UNSIGNED correctamente
        // usamos ALTER TABLE directo con doQueryOrDie(), que es el mismo método
        // que usa GLPI internamente en sus propias migraciones de unsigned_keys.
        $alterCols = [];
        if ($DB->fieldExists($table, 'tickets_id')) {
            $alterCols[] = "MODIFY `tickets_id` INT UNSIGNED NOT NULL DEFAULT '0'";
        }
        if ($DB->fieldExists($table, 'mailcollectors_id')) {
            $alterCols[] = "MODIFY `mailcollectors_id` INT UNSIGNED NOT NULL DEFAULT '0'";
        }
        if (!empty($alterCols)) {
            $DB->doQueryOrDie(
                "ALTER TABLE `{$table}` " . implode(', ', $alterCols),
                "Cannot fix unsigned columns in {$table}"
            );
        }

        $migration->executeMigration();
    }

    // Inicializar configuración por defecto si no existe aún
    // Config::setConfigurationValues hace INSERT si no existe, UPDATE si ya existe
    \Config::setConfigurationValues('plugin:mailanalyzer', [
        'use_threadindex' => 0,
    ]);

    return true;
}


/**
 * Uninstall hook.
 *
 * Per the original plugin design: the tracking table is intentionally
 * NOT dropped on uninstall so that historical threading data is preserved
 * if the plugin is re-installed later.
 */
function plugin_mailanalyzer_uninstall(): bool
{
    // Intentionally left empty — table preserved on uninstall.
    return true;
}
