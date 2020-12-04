<?php

/**
 * Summary of plugin_mailanalyzer_install
 * @return boolean
 */
function plugin_mailanalyzer_install() {
   global $DB;

   if (!$DB->tableExists("glpi_plugin_mailanalyzer_message_id")) {
         $query = "CREATE TABLE `glpi_plugin_mailanalyzer_message_id` (
			   `id` INT(10) NOT NULL AUTO_INCREMENT,
			   `message_id` VARCHAR(255) NOT NULL DEFAULT '0',
			   `ticket_id` INT(10) NOT NULL DEFAULT '0',
			   PRIMARY KEY (`id`),
			   UNIQUE INDEX `message_id` (`message_id`),
			   INDEX `ticket_id` (`ticket_id`)
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
      }
      catch (Throwable $e) {
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
    * Summary of plugin_pre_item_add_mailanalyzer_followup
    * @param mixed $parm
    */
   public static function plugin_pre_item_add_mailanalyzer_followup($parm) {
       global $DB;

       if (isset($parm->input['_mailgate'])
          && !isset($parm->input['_from_plugin_pre_item_add_mailanalyzer'])) {
         // change requester if needed
         $locUser = new User();
         $str = self::getTextFromHtml($parm->input['content']);
         $users_id = self::getUserOnBehalfOf($str);
         if ($users_id !== false) {
            if ($locUser->getFromDB($users_id)) {
               // set users_id
               $parm->input['users_id'] = $users_id;
            }
         }
      }
   }


   /**
    * Summary of getTextFromHtml
    * gets bare text content from HTML
    * deltes HTML entities, but <br>
    * @param mixed $str HTML input
    * @return string bare text
    */
   public static function getTextFromHtml($str) {
      $ret = Toolbox::unclean_html_cross_side_scripting_deep($str);
      $ret = preg_replace("/<(p|br|div)( [^>]*)?".">/i", "\n", $ret);
      $ret = preg_replace("/(&nbsp;| |\xC2\xA0)+/", " ", $ret);
      $ret = strip_tags($ret);
      $ret = html_entity_decode(html_entity_decode($ret, ENT_QUOTES));
      return $ret;
   }

   /**
    * Summary of getUserOnBehalfOf
    * search for ##From if it exists, then try to find users_id from DB
    * @param string $str
    * @return boolean|integer
    */
   public static function getUserOnBehalfOf($str) {
      global $DB;

      // search for ##From if it exists, then try to find real requester from DB
      $str = str_replace(['\n', '\r\n'], "\n", $str); // to be sure that \n (end of line) will not be confused with a \ in firstname

      $ptnUserFullName = '/##From\s*:\s*(["\']?(?\'last\'[\w.\-\\\\\' ]+)[, ]\s*(?\'first\'[\w+.\-\\\\\' ]+))?.*?(?\'email\'[\w_.+\-]+@[\w\-]+\.[\w\-.]+)?\W*$/imu';

      if (preg_match_all($ptnUserFullName, $str, $matches, PREG_SET_ORDER) > 0) {
         // we found at least one ##From:
         // then we try to get its user id from DB
         // if an email has been found, then we try it
         // else we try with name and firstname in this order
         $matches = $matches[0];
         if (isset($matches['email'])) {
            $where2 = ['glpi_useremails.email' => $matches['email']];
         } else {
            $where2 = ['AND' => ['glpi_users.realname'         => $DB->escape(trim( $matches['last'] )),
                                 'glpi_users.firstname'        => $DB->escape(trim( $matches['first'] )),
                                 'glpi_useremails.is_default'  => 1
                                 ]];
         }
         $where2['AND']['glpi_users.is_active'] = 1;
         $where2['AND']['glpi_users.is_deleted'] = 0;
         $res = $DB->request([
            'SELECT'    => 'glpi_users.id',
            'FROM'      => 'glpi_users',
            'RIGHT JOIN'=> ['glpi_useremails' => ['FKEY' => ['glpi_useremails' => 'users_id', 'glpi_users' => 'id']]],
            'WHERE'     => $where2,
            'LIMIT'     => 1
            ]);

         if ($row = $res->next()) {
            return (integer)$row['id'];
         }
      }

      return false;

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


         // change requester if needed
         // search for ##From if it exists, then try to find real requester from DB
         $locUser = new User();
         if (isset($parm->input['itemtype']) && $parm->input['itemtype'] == 'Ticket') {
            $ticketId = (isset($parm->input['items_id']) ? $parm->input['items_id'] : $parm->fields['items_id'] );
            $locTicket = new Ticket;
            if ($locTicket->getFromDB( $ticketId ) && isset( $parm->input['content'] )) {
               self::addWatchers( $locTicket, $parm->input['content'] );
            }
         }
         $str = self::getTextFromHtml($parm->input['content']);
         $users_id = self::getUserOnBehalfOf($str);
         if ($users_id !== false) {
            if ($locUser->getFromDB($users_id)) {
               // set user_id and user_entity only if 'post-only' profile is found and unique
               $entity = Profile_User::getEntitiesForProfileByUser($users_id, 1 ); // 1 if the post-only or self-service profile
               if (count( $entity ) == 1) {
                  $parm->input['users_id_recipient'] = $parm->input['_users_id_requester'];
                  $parm->input['_users_id_requester'] = $users_id;
                  $parm->input['entities_id'] = current( $entity );
               }
            }
         }


         // Analyze emails to establish conversation
         $references = [];
         $is_local_mailgate = false;
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
            $is_local_mailgate = true;
         }

         // try to get Thread-Index from email header
         $messageId = $parm->input['_message']->messageid;
         $uid = $parm->input['_uid'];
         // we must check if this email has not been received yet!
         // test if 'message-id' is in the DB
         $res = $DB->request(
            'glpi_plugin_mailanalyzer_message_id',
            [
            'AND' =>
               [
               'ticket_id' => ['!=', 0],
               'message_id' => $messageId
               ]
            ]
         );

         if ($row = $res->next()) {
            // email already received
            // must prevent ticket creation
            $parm->input = [];

            // as Ticket creation is cancelled, then email is not deleted from mailbox
            // then we need to set deletion flag to true to this email from mailbox folder
            $local_mailgate->deleteMails($uid, MailCollector::REFUSED_FOLDER); // NOK Folder

            // // close mailgate only if localy open
            //if ($is_local_mailgate) {
            //   // close session and delete emails marked for deletion during this session only!
            //   unset($local_mailgate);
            //}

            return;
         }

         // search for 'Thread-Index'
         $references = [];
         if (isset($parm->input['_message']->threadindex)) {
            // exemple of thread-index : ac5rwreerb4gv3pcr8gdflszrsqhoa==
            // explanations to decode this property: http://msdn.microsoft.com/en-us/library/ee202481%28v=exchg.80%29.aspx
            $references[] = bin2hex(substr(imap_base64($parm->input['_message']->threadindex), 6, 16 ));
         }

         // this ticket has been created via an email receiver.
         // we have to check if references can be found in DB.
         if (isset($parm->input['_message']->references)) {
            // we may have a forwarded email that looks like reply-to
            if (preg_match_all('/<.*?>/', $parm->input['_message']->references, $matches)) {
               $references = array_merge($references, $matches[0]);
            }
         }

         if (count($references) > 0) {
            foreach ($references as $ref) {
               $messages_id[] = $ref;
            }

            $res = $DB->request(
               'glpi_plugin_mailanalyzer_message_id', 
               ['AND' =>
                  [
                  'ticket_id' => ['!=',0],
                  'message_id' => $messages_id
                  ],
                  'ORDER' => 'ticket_id DESC'
               ]
            );
            if ($row = $res->next()) {
               // TicketFollowup creation only if ticket status is not solved or closed
               $locTicket = new Ticket();
               $locTicket->getFromDB((integer)$row['ticket_id']);
               if ($locTicket->fields['status'] !=  CommonITILObject::SOLVED && $locTicket->fields['status'] != CommonITILObject::CLOSED) {
                  $ticketfollowup = new ITILFollowup();
                  $input = $parm->input;
                  $input['items_id'] = $row['ticket_id'];
                  $input['users_id'] = $parm->input['_users_id_requester'];
                  $input['add_reopen'] = 1;
                  $input['itemtype'] = 'Ticket';

                  unset($input['urgency']);
                  unset($input['entities_id']);
                  unset($input['_ruleid']);

                  // to prevent a new analyze in self::plugin_pre_item_add_mailanalyzer_followup
                  $input['_from_plugin_pre_item_add_mailanalyzer'] = 1;

                  $ticketfollowup->add($input);

                  // add message id to DB in case of another email will use it
                  $DB->insert(
                     'glpi_plugin_mailanalyzer_message_id',
                     [
                        'message_id' => $messageId,
                        'ticket_id'  => $input['items_id']
                     ]
                  );

                  // prevent Ticket creation. Unfortunately it will return an error to receiver when started manually from web page
                  $parm->input = []; // empty array...

                  // as Ticket creation is cancelled, then email is not deleted from mailbox
                  // then we need to set deletion flag to true to this email from mailbox folder
                  $local_mailgate->deleteMails($uid, MailCollector::ACCEPTED_FOLDER); // OK folder

                  //// close mailgate only if localy open
                  //if ($is_local_mailgate) {
                  //   // close session and delete emails marked for deletion during this session only!
                  //   unset($local_mailgate);
                  //}

                  return;
               } else {
                  // ticket creation, but linked to the closed one...
                  $parm->input['_link'] = ['link' => '1', 'tickets_id_1' => '0', 'tickets_id_2' => $row['ticket_id']];
               }
            }
         }

         // can't find ref into DB, then this is a new ticket, in this case insert refs and message_id into DB
         $references[] = $messageId;

         // this is a new ticket
         // then add references and message_id to DB
         foreach ($references as $ref) {
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
      $messages_id = [];
      if (isset($parm->input['_mailgate'])) {
         // this ticket have been created via email receiver.
         // update the ticket ID for the message_id only for newly created tickets (ticket_id == 0)

         // add watchers if ##CC
         self::addWatchers($parm, $parm->fields['content']);

         $messages_id[] = $parm->input['_message']->messageid;

         // is 'Thread-Index present?'
         if (isset($parm->input['_message']->threadindex)) {
            // exemple of thread-index : Ac5rWReeRb4gv3pCR8GDflsZrsqhoA==
            // explanations to decode this property: http://msdn.microsoft.com/en-us/library/ee202481%28v=exchg.80%29.aspx
            $messages_id[] = bin2hex(substr(imap_base64($parm->input['_message']->threadindex), 6, 16 ));
         }

         // Are some references in the email?
         if (isset($parm->input['_message']->references)) {
            // we may have a forwarded email that looks like reply-to
            $references = [];
            if (preg_match_all('/<.*?>/', $parm->input['_message']->references, $matches)) {
               $references =  $matches[0];
            }
            foreach ($references as $ref) {
               $messages_id[] = $ref;
            }
         }

         $DB->update(
            'glpi_plugin_mailanalyzer_message_id',
            [
               'ticket_id' => $parm->fields['id']
            ],
            [
               'WHERE' =>
                  [
                     'AND' =>
                        [
                           'ticket_id'  => 0,
                           'message_id' => $messages_id
                        ]
                  ]
            ]
         );
      }
   }


   /**
    * Summary of addWatchers
    * @param Ticket $parm    a Ticket
    * @param string $content content that will be analyzed
    * @return void
    */
   public static function addWatchers($parm, $content) {
      // to be sure
      if ($parm->getType() == 'Ticket') {
         $content = str_replace(['\n', '\r\n'], "\n", $content);
         $content = self::getTextFromHtml($content);
         $ptnUserFullName = '/##CC\s*:\s*(["\']?(?\'last\'[\w.\-\\\\\' ]+)[, ]\s*(?\'first\'[\w+.\-\\\\\' ]+))?.*?(?\'email\'[\w_.+\-]+@[\w\-]+\.[\w\-.]+)?\W*$/imu';
         if (preg_match_all($ptnUserFullName, $content, $matches, PREG_PATTERN_ORDER) > 0) {
            // we found at least one ##CC matching user name convention: "Name, Firstname"
            for ($i=0; $i<count($matches[1]); $i++) {
               // then try to get its user id from DB
               //$locUser = self::getFromDBbyCompleteName( trim($matches[1][$i]).' '.trim($matches[2][$i]));
               $locUser = self::getFromDBbyCompleteName( trim($matches['last'][$i]).' '.trim($matches['first'][$i]));
               if ($locUser) {
                  // add user in watcher list
                  if (!$parm->isUser( CommonITILActor::OBSERVER, $locUser->getID())) {
                     // then we need to add this user as it is not yet in the observer list
                     $locTicketUser = new Ticket_User;
                     $locTicketUser->add( [ 'tickets_id' => $parm->getId(), 'users_id' => $locUser->getID(), 'type' => CommonITILActor::OBSERVER, 'use_notification' => 1] );
                     $parm->getFromDB($parm->getId());
                  }
               }
            }
         }

         $locGroup = new Group;
         $ptnGroupName = "/##CC\s*:\s*([_a-z0-9-\\\\* ]+)/i";
         if (preg_match_all($ptnGroupName, $content, $matches, PREG_PATTERN_ORDER) > 0) {
            // we found at least one ##CC matching group name convention:
            for ($i=0; $i<count($matches[1]); $i++) {
               // then try to get its group id from DB
               //if ($locGroup->getFromDBWithName( trim($matches[1][$i]))) {
               if ($locGroup->getFromDBByCrit( ['name' => trim($matches[1][$i])])) {
                  // add group in watcher list
                  if (!$parm->isGroup( CommonITILActor::OBSERVER, $locGroup->getID())) {
                     // then we need to add this group as it is not yet in the observer list
                     $locGroup_Ticket = new Group_Ticket;
                     $locGroup_Ticket->add( [ 'tickets_id' => $parm->getId(), 'groups_id' => $locGroup->getID(), 'type' => CommonITILActor::OBSERVER] );
                     $parm->getFromDB($parm->getId());
                  }
               }
            }
         }

      }
   }


   /**
    * Summary of getFromDBbyCompleteName
    * Retrieve an item from the database using its Lastname Firstname
    * @param string $completename : family name + first name of the user ('lastname firstname')
    * @return boolean|User a user if succeed else false
    **/
   public static function getFromDBbyCompleteName($completename) {
      global $DB;
      $user = new User();
      $res = $DB->request(
                     $user->getTable(),
                     [
                     'AND' => [
                        'is_active' => 1,
                        'is_deleted' => 0,
                        'RAW' => [
                           "CONCAT(realname, ' ', firstname)" => ['LIKE', addslashes($completename)]
                        ]
                     ]
                     ]);
      if ($res) {
         if ($res->numrows() != 1) {
            return false;
         }
         $user->fields = $res->next();
         if (is_array($user->fields) && count($user->fields)) {
            return $user;
         }
      }
      return false;
   }
}

