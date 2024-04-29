<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI
Copyright (C) 2011-2024 by Raynet SAS a company of A.Raymond Network.

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
/**
 * Summary of plugin_mailanalyzer_install
 * @return boolean
 */
function plugin_mailanalyzer_install() {
   global $DB;

   if (!$DB->tableExists("glpi_plugin_mailanalyzer_message_id")) {
         $query = "CREATE TABLE `glpi_plugin_mailanalyzer_message_id` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `message_id` VARCHAR(255) NOT NULL DEFAULT '0',
            `tickets_id` INT(11) NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            UNIQUE INDEX `message_id` (`message_id`),
            INDEX `tickets_id` (`tickets_id`)
         )
         COLLATE='utf8_general_ci'
         ENGINE=innoDB;
         ";

         $DB->query($query) or die("error creating glpi_plugin_mailanalyzer_message_id " . $DB->error());
   } else {
      if (count($DB->listTables('glpi_plugin_mailanalyzer_message_id', ['engine' => 'MyIsam'])) > 0) {
         $query = "ALTER TABLE glpi_plugin_mailanalyzer_message_id ENGINE = InnoDB";
         $DB->query($query) or die("error updating ENGINE in glpi_plugin_mailanalyzer_message_id " . $DB->error());
      }
   }

   if (!$DB->fieldExists('glpi_plugin_mailanalyzer_message_id', 'tickets_id')) {
      // then we must change the name and the length of id and ticket_id to 11
      $query = "ALTER TABLE `glpi_plugin_mailanalyzer_message_id`
                  CHANGE COLUMN `id` `id` INT(11) NOT NULL AUTO_INCREMENT FIRST,
                  CHANGE COLUMN `ticket_id` `tickets_id` INT(11) NOT NULL DEFAULT '0' AFTER `message_id`,
                  DROP INDEX `ticket_id`,
                  ADD INDEX `ticket_id` (`tickets_id`);";
      $DB->query($query) or die('Cannot alter glpi_plugin_mailanalyzer_message_id table! ' .  $DB->error());
   }

   return true;
}


/**
 * Summary of plugin_mailanalyzer_uninstall
 * @return boolean
 */
function plugin_mailanalyzer_uninstall() {

   // nothing to uninstall
   // do not delete table

   return true;
}


/**
 * Summary of PluginMailAnalyzer
 */
class PluginMailAnalyzer {

   /**
    * Create default mailgate
    * @param int $mailgate_id is the id of the mail collector in GLPI DB
    * @return bool|MailCollector
    *
   */
   static function openMailgate($mailgate_id) {

      $mailgate = new MailCollector();
      $mailgate->getFromDB($mailgate_id);

      $mailgate->uid          = -1;
      //Connect to the Mail Box
      try {
         $mailgate->connect();
      } catch (Throwable $e) {
         Toolbox::logError(
            'An error occured trying to connect to collector.',
            $e->getMessage(),
            "\n",
            $e->getTraceAsString()
         );
         Session::addMessageAfterRedirect(
            __('An error occured trying to connect to collector.') . "<br/>" . $e->getMessage(),
            false,
            ERROR
         );
         return false;
      }

      return $mailgate;
   }


   /**
   * Summary of plugin_pre_item_add_mailanalyzer
   * @param mixed $parm
   * @return void
   */
   public static function plugin_pre_item_add_mailanalyzer($parm) {
      global $DB, $mailgate;

      if (isset($parm->input['_mailgate'])) {
         // this ticket have been created via email receiver.
         // and we have the Laminas\Mail\Storage\Message object in the _message key
         // Analyze emails to establish conversation

         if (isset($mailgate)) {
            // mailgate has been open by web page call, then use it
            $local_mailgate = $mailgate;
         } else {
            // mailgate is not open. Called by cron
            // then locally create a mailgate
            $local_mailgate = PluginMailAnalyzer::openMailgate($parm->input['_mailgate']);
            if ($local_mailgate === false) {
               // can't connect to the mail server, then cancel ticket creation
               $parm->input = []; // empty array...
               return;
            }
         }

         // we must check if this email has not been received yet!
         // test if 'message-id' is in the DB
         $messageId = $parm->input['_message']->messageid;
         $uid = $parm->input['_uid'];
         $res = $DB->request(
            'glpi_plugin_mailanalyzer_message_id',
            [
            'AND' =>
               [
               'tickets_id'  => ['!=', 0],
               'message_id' => $messageId
               ]
            ]
         );

         if ($row = $res->next()) {
            // email already received
            // must prevent ticket creation
            $parm->input = false; //[ ];

            // as Ticket creation is cancelled, then email is not deleted from mailbox
            // then we need to set deletion flag to true to this email from mailbox folder
            $local_mailgate->deleteMails($uid, MailCollector::REFUSED_FOLDER); // NOK Folder

            return;
         }

         // search for 'Thread-Index' and 'References'
         $messages_id = self::getMailReferences($parm->input['_message']);

         if (count($messages_id) > 0) {
            $res = $DB->request(
               'glpi_plugin_mailanalyzer_message_id',
               ['AND' =>
                  [
                  'tickets_id'  => ['!=',0],
                  'message_id' => $messages_id
                  ],
                  'ORDER' => 'tickets_id DESC'
               ]
            );
            if ($row = $res->next()) {
               // TicketFollowup creation only if ticket status is not closed
               $locTicket = new Ticket();
               $locTicket->getFromDB((integer)$row['tickets_id']);
               if ($locTicket->fields['status'] != CommonITILObject::CLOSED) {
                  $ticketfollowup = new ITILFollowup();
                  $input = $parm->input;
                  $input['items_id'] = $row['tickets_id'];
                  $input['users_id'] = $parm->input['_users_id_requester'];
                  $input['add_reopen'] = 1;
                  $input['itemtype'] = 'Ticket';

                  unset($input['urgency']);
                  unset($input['entities_id']);
                  unset($input['_ruleid']);

                  $ticketfollowup->add($input);

                  // add message id to DB in case of another email will use it
                  $DB->insert(
                     'glpi_plugin_mailanalyzer_message_id',
                     [
                        'message_id' => $messageId,
                        'tickets_id'  => $input['items_id']
                     ]
                  );

                  // prevent Ticket creation. Unfortunately it will return an error to receiver when started manually from web page
                  $parm->input = false; // []; // empty array...

                  // as Ticket creation is cancelled, then email is not deleted from mailbox
                  // then we need to set deletion flag to true to this email from mailbox folder
                  $local_mailgate->deleteMails($uid, MailCollector::ACCEPTED_FOLDER); // OK folder

                  return;

               } else {
                  // ticket creation, but linked to the closed one...
                  $parm->input['_link'] = ['link' => '1', 'tickets_id_1' => '0', 'tickets_id_2' => $row['tickets_id']];
               }
            }
         }

         // can't find ref into DB, then this is a new ticket, in this case insert refs and message_id into DB
         $messages_id[] = $messageId;

         // this is a new ticket
         // then add references and message_id to DB
         foreach ($messages_id as $ref) {
            $res = $DB->request('glpi_plugin_mailanalyzer_message_id', ['message_id' => $ref]);
            if (count($res) <= 0) {
               $DB->insert('glpi_plugin_mailanalyzer_message_id', ['message_id' => $ref]);
            }
         }
      }
   }


    /**
     * Summary of plugin_item_add_mailanalyzer
     * @param mixed $parm
     */
   public static function plugin_item_add_mailanalyzer($parm) {
      global $DB;
      if (isset($parm->input['_mailgate'])) {
         // this ticket have been created via email receiver.
         // update the ticket ID for the message_id only for newly created tickets (tickets_id == 0)

         // Are 'Thread-Index' or 'Refrences' present?
         $messages_id = self::getMailReferences($parm->input['_message']);
         $messages_id[] = $parm->input['_message']->messageid;

         $DB->update(
            'glpi_plugin_mailanalyzer_message_id',
            [
               'tickets_id' => $parm->fields['id']
            ],
            [
               'WHERE' =>
                  [
                     'AND' =>
                        [
                           'tickets_id'  => 0,
                           'message_id' => $messages_id
                        ]
                  ]
            ]
         );
      }
   }


   /**
    * Summary of getMailReferences
    * @param Laminas\Mail\Storage\Message $message
    * @return array
    */
   private static function getMailReferences(Laminas\Mail\Storage\Message $message) {

      $messages_id = []; // by default

      $config = Config::getConfigurationValues('plugin:mailanalyzer');

      // search for 'Thread-Index'
      if (isset($message->threadindex) && isset($config['use_threadindex']) && $config['use_threadindex']) {
         // exemple of thread-index : ac5rwreerb4gv3pcr8gdflszrsqhoa==
         // explanations to decode this property: http://msdn.microsoft.com/en-us/library/ee202481%28v=exchg.80%29.aspx
         $messages_id[] = bin2hex(substr(base64_decode($message->threadindex), 6, 16 ));
      }

      // search for 'References'
      if (isset($message->references)) {
         // we may have a forwarded email that looks like reply-to
         if (preg_match_all('/<.*?>/', $message->references, $matches)) {
            $messages_id = array_merge($messages_id, $matches[0]);
         }
      }

      // clean $messages_id array
      return array_filter($messages_id, function($val) {return $val != trim('', '< >');});
   }


   /**
    * Summary of plugin_item_purge_mailanalyzer
    * @param mixed $item
    */
   static function plugin_item_purge_mailanalyzer($item) {
      Global $DB;
      // the ticket is purged, then we are going to purge the matching rows in glpi_plugin_mailanalyzer_message_id table
      // DELETE FROM glpi_plugin
      $DB->delete('glpi_plugin_mailanalyzer_message_id', ['tickets_id' => $item->getID()]);
   }
}

