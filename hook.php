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
 * Schema version tracked in glpi_configs['plugin:mailanalyzer']['dbversion'].
 * Bump this whenever the table structure changes, so plugin_mailanalyzer_install()
 * only re-runs the (potentially expensive) ALTER TABLE steps once per schema change,
 * instead of on every plugin activation/update.
 */
const PLUGIN_MAILANALYZER_DB_VERSION = '1.1.0';

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
                `message_id`        VARCHAR(512) COLLATE {$collation} NOT NULL DEFAULT '0',
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

        \Config::setConfigurationValues('plugin:mailanalyzer', [
            'dbversion' => PLUGIN_MAILANALYZER_DB_VERSION,
        ]);
    } elseif (\Config::getConfigurationValue('plugin:mailanalyzer', 'dbversion') !== PLUGIN_MAILANALYZER_DB_VERSION) {
        // ── Upgrade path ──────────────────────────────────────────────────────
        // Only re-run these (potentially expensive) schema fixes when the stored
        // dbversion is behind, instead of on every install()/update() call.

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

        // 4 & 5. Force UNSIGNED on tickets_id and mailcollectors_id.
        //
        // Migration::changeField() with type 'integer' generates a signed INT(11),
        // ignoring the 'unsigned' => true option. To force UNSIGNED correctly we
        // run a direct ALTER TABLE via doQueryOrDie(), the same method GLPI uses
        // internally for its own unsigned_keys migrations.
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

        // 6. Widen message_id from VARCHAR(255) to VARCHAR(512) for older installs
        // (some mail servers generate Message-ID / Thread-Index values longer than 255 chars).
        $fields = $DB->listFields($table, false);
        if (isset($fields['message_id']) && stripos($fields['message_id']['Type'], 'varchar(255)') !== false) {
            $DB->doQueryOrDie(
                "ALTER TABLE `{$table}` MODIFY `message_id` VARCHAR(512) NOT NULL DEFAULT '0'",
                "Cannot widen message_id in {$table}"
            );
        }

        $migration->executeMigration();

        \Config::setConfigurationValues('plugin:mailanalyzer', [
            'dbversion' => PLUGIN_MAILANALYZER_DB_VERSION,
        ]);
    }

    // Initialize default configuration only if it doesn't already exist, so an
    // upgrade never resets a value the admin has already saved.
    if (\Config::getConfigurationValue('plugin:mailanalyzer', 'use_threadindex') === null) {
        \Config::setConfigurationValues('plugin:mailanalyzer', [
            'use_threadindex' => 0,
        ]);
    }

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
