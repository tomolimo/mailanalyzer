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

use CommonDBTM;
use Config;
use ITILFollowup;
use MailCollector;
use Ticket;
use CommonITILObject;

/**
 * Main plugin class — handles Ticket hooks for email threading.
 *
 * GLPI 11 notes:
 * - No raw $DB->query(). All DB access uses $DB->request() / insert() / update() / delete().
 * - No Toolbox::addslashes_deep(): GLPI 11 removed auto-sanitization; data is raw.
 * - Hook callbacks are now public static methods referenced via ::class constant in setup.php.
 * - openMailgate() returns MailCollectorWrapper (our PSR-4 class).
 */
class MailAnalyzer
{
    /**
     * Open a connection to a mail collector.
     *
     * @param int $mailcollectors_id
     * @return MailCollectorWrapper
     */
    private static function openMailgate(int $mailcollectors_id): MailCollectorWrapper
    {
        $mailgate = new MailCollectorWrapper();
        $mailgate->getFromDB($mailcollectors_id);
        $mailgate->uid = -1;
        $mailgate->connect();

        return $mailgate;
    }


    /**
     * Hook: pre_item_add on Ticket.
     *
     * Intercepts tickets created via the mail collector and either:
     *  - Blocks creation (duplicate message-id already in DB), or
     *  - Converts it into a followup on an existing ticket (email threading), or
     *  - Allows creation and pre-registers references.
     *
     * @param Ticket $item
     */
    public static function preItemAdd(Ticket $item): void
    {
        global $DB, $mailgate;

        $mailgateId = $item->input['_mailgate'] ?? false;
        if (!$mailgateId) {
            return;
        }

        // Config: should we use Thread-Index header?
        $config          = Config::getConfigurationValues('plugin:mailanalyzer');
        $use_threadindex = isset($config['use_threadindex']) && $config['use_threadindex'];

        // Resolve which mail connection to use
        if (isset($mailgate)) {
            $local_mailgate = $mailgate;
            if ($use_threadindex) {
                // Need a fresh connection to fetch Thread-Index
                $local_mailgate = self::openMailgate($mailgateId);
            }
        } else {
            // Called from cron — open our own connection
            $local_mailgate = self::openMailgate($mailgateId);
            if ($local_mailgate === false) {
                $item->input = false;
                return;
            }
        }

        // Optionally extract Thread-Index header
        if ($use_threadindex) {
            $local_message = $local_mailgate->getMessage($item->input['_uid']);
            $threadindex   = $local_mailgate->getThreadIndex($local_message);
            if ($threadindex) {
                $item->input['_head']['threadindex'] = $threadindex;
            }
        }

        // GLPI 11: html_entity_decode() is still fine here; data is raw but may come
        // from the mail headers which can be HTML-encoded.
        $messageId = html_entity_decode($item->input['_head']['message_id']);
        $uid       = $item->input['_uid'];

        // --- 1. Check for duplicate message-id ---
        $res = $DB->request([
            'FROM'  => 'glpi_plugin_mailanalyzer_message_id',
            'WHERE' => [
                'AND' => [
                    ['tickets_id'        => ['!=', 0]],
                    ['message_id'        => $messageId],
                    ['mailcollectors_id' => $mailgateId],
                ],
            ],
        ]);

        if ($res->current()) {
            // Already imported — refuse and move to NOK folder
            $item->input = false;
            $local_mailgate->deleteMails($uid, MailCollector::REFUSED_FOLDER);
            return;
        }

        // --- 2. Check references / Thread-Index for threading ---
        $messages_id = self::getMailReferences(
            $item->input['_head']['threadindex'] ?? '',
            html_entity_decode($item->input['_head']['references'] ?? '')
        );

        if (count($messages_id) > 0) {
            $res = $DB->request([
                'FROM'  => 'glpi_plugin_mailanalyzer_message_id',
                'WHERE' => [
                    'AND' => [
                        ['tickets_id'        => ['!=', 0]],
                        ['message_id'        => $messages_id],
                        ['mailcollectors_id' => $mailgateId],
                    ],
                ],
                'ORDER' => ['tickets_id DESC'],
            ]);

            if ($row = $res->current()) {
                $locTicket = new Ticket();
                $locTicket->getFromDB((int) $row['tickets_id']);

                if ($locTicket->fields['status'] !== CommonITILObject::CLOSED) {
                    // Convert to a followup on the existing ticket
                    $ticketfollowup = new ITILFollowup();
                    $input = $item->input;
                    $input['items_id']   = $row['tickets_id'];
                    $input['users_id']   = $item->input['_users_id_requester'];
                    $input['add_reopen'] = 1;
                    $input['itemtype']   = 'Ticket';

                    unset($input['urgency'], $input['entities_id'], $input['_ruleid']);

                    $ticketfollowup->add($input);

                    // Track this message-id too
                    $DB->insert('glpi_plugin_mailanalyzer_message_id', [
                        'message_id'        => $messageId,
                        'tickets_id'        => $input['items_id'],
                        'mailcollectors_id' => $mailgateId,
                    ]);

                    // Cancel ticket creation and move mail to OK folder
                    $item->input = false;
                    $local_mailgate->deleteMails($uid, MailCollector::ACCEPTED_FOLDER);
                    return;
                }

                // Ticket is closed → allow new ticket but link it
                $item->input['_link'] = [
                    'link'         => '1',
                    'tickets_id_1' => '0',
                    'tickets_id_2' => $row['tickets_id'],
                ];
            }
        }

        // --- 3. New ticket — pre-register all references and message_id ---
        $messages_id[] = $messageId;

        foreach ($messages_id as $ref) {
            $existing = $DB->request([
                'FROM'  => 'glpi_plugin_mailanalyzer_message_id',
                'WHERE' => [
                    'message_id'        => $ref,
                    'mailcollectors_id' => $mailgateId,
                ],
            ]);

            if (count($existing) === 0) {
                $DB->insert('glpi_plugin_mailanalyzer_message_id', [
                    'message_id'        => $ref,
                    'mailcollectors_id' => $mailgateId,
                ]);
            }
        }
    }


    /**
     * Hook: item_add on Ticket.
     *
     * After the ticket is created, update the tickets_id for all pre-registered
     * references (tickets_id == 0).
     *
     * @param Ticket $item
     */
    public static function itemAdd(Ticket $item): void
    {
        global $DB;

        if (!isset($item->input['_mailgate'])) {
            return;
        }

        $messages_id = self::getMailReferences(
            $item->input['_head']['threadindex'] ?? '',
            html_entity_decode($item->input['_head']['references'] ?? '')
        );
        $messages_id[] = html_entity_decode($item->input['_head']['message_id']);

        $DB->update(
            'glpi_plugin_mailanalyzer_message_id',
            ['tickets_id' => $item->fields['id']],
            [
                'WHERE' => [
                    'AND' => [
                        'tickets_id'  => 0,
                        'message_id'  => $messages_id,
                    ],
                ],
            ]
        );
    }


    /**
     * Hook: item_purge on Ticket.
     *
     * Remove all message-id records linked to the purged ticket.
     *
     * @param Ticket $item
     */
    public static function itemPurge(Ticket $item): void
    {
        global $DB;

        $DB->delete('glpi_plugin_mailanalyzer_message_id', [
            'tickets_id' => $item->getID(),
        ]);
    }


    /**
     * Build the list of known message references from Thread-Index and References headers.
     *
     * @param string $threadindex Base64-encoded Thread-Index header value (may be empty)
     * @param string $references  RFC 2822 References header value (may be empty)
     * @return string[]
     */
    private static function getMailReferences(string $threadindex, string $references): array
    {
        $messages_id = [];

        if (!empty($threadindex)) {
            $messages_id[] = $threadindex;
        }

        if (!empty($references)) {
            if (preg_match_all('/<.*?>/', $references, $matches)) {
                $messages_id = array_merge($messages_id, $matches[0]);
            }
        }

        // Filter out empty angle-bracket tokens
        return array_filter($messages_id, static fn($val) => $val !== trim('', '< >'));
    }
}
