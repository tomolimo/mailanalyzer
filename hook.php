<?php

if (!function_exists('arTableExists')) {
   function arTableExists($table) {
      global $DB;
      if (method_exists( $DB, 'tableExists')) {
         return $DB->tableExists($table);
      } else {
         return TableExists($table);
      }
   }
}

if (!function_exists('arFieldExists')) {
   function arFieldExists($table, $field, $usecache = true) {
      global $DB;
      if (method_exists( $DB, 'fieldExists')) {
         return $DB->fieldExists($table, $field, $usecache);
      } else {
         return FieldExists($table, $field, $usecache);
      }
   }
}


function plugin_mailanalyzer_install() {
	global $DB ;

	if (!arTableExists("glpi_plugin_mailanalyzer_message_id")) {
		$query = "CREATE TABLE `glpi_plugin_mailanalyzer_message_id` (
				`id` INT(10) NOT NULL AUTO_INCREMENT,
				`message_id` VARCHAR(255) NOT NULL DEFAULT '0',
				`ticket_id` INT(10) NOT NULL DEFAULT '0',
				PRIMARY KEY (`id`),
				UNIQUE INDEX `message_id` (`message_id`),
				INDEX `ticket_id` (`ticket_id`)
			)
			COLLATE='utf8_general_ci'
			ENGINE=MyISAM;
			";

		$DB->query($query) or die("error creating glpi_plugin_mailanalyzer_message_id " . $DB->error());
	}



	return true;
}

function plugin_mailanalyzer_uninstall() {
	global $DB;
	// nothing to uninstall
	// do not delete table

	return true;
}


// function plugin_mailanalyzer_Update(){


// 	return true;
// }


class PluginMailAnalyzer {


	/**
    *		Search for current email in order to get its additional properties from header.
    *		Will search for:
    *			X-...
    *			Auto-Submitted...
    *			Received...
    *			Thread-...
    *			Message-ID
    * @param stream $marubox is a connected MailCollector
    * @param int $mid is the msg num in the mailbox linked to the stream $marubox
    * @return array of strings: extended header properties
    *
    */
	static function getAdditionnalHeaders($marubox, $mid) {

		$head   = array();
		$header = explode("\n", imap_fetchheader($marubox, $mid));

		if (is_array($header) && count($header)) {
			foreach ($header as $line) {
				if (preg_match("/^([^: ]*):\\s*/i", $line)
                        || preg_match("/^\\s(.*)/i", $line ) ) {
					// separate name and value

					if (preg_match("/^([^: ]*): (.*)/i", $line, $arg) ) {

                  $key = Toolbox::strtolower($arg[1]);

						if (!isset($head[$key])) {
							$head[$key] = '';
						} elseif( $head[$key] != '' )  {
							$head[$key] .= "\n";
						}

						$head[$key] .= trim($arg[2]);

               } elseif( preg_match("/^\\s(.*)/i", $line, $arg) && !empty($key) ) {
                  if (!isset($head[$key])) {
							$head[$key] = '';
						} elseif( $head[$key] != '' ) {
							$head[$key] .= "\n";
						}

						$head[$key] .= trim($arg[1]);

					} elseif ( preg_match("/^([^:]*):/i", $line, $arg) ) {
                  $key = Toolbox::strtolower($arg[1]);
                  $head[$key] = '';
               }
				}
			}
		}
		return $head;
	}

	/**
    *		Search for current email in order to get its msg num, that will be stored in $mailgate->{$mailgate->pluginmailanalyzer_mid_field}.
    *		The only way to find the right email in the current mailbox is to look for "message-id" property
    *
    * @param MailCollector $mailgate is a connected MailCollector
    * @param string $message_id is the "Message-ID" property of the messasge: most of the time looks like: <080DF555E8A78147A053578EC592E8395F25E3@arexch34.ar.ray.group>
    * @return array of strings: extended header properties, and property 'mid' will be the msg num found in mailbox
    *
    */
	static function getHeaderAndMsgNum($mailgate, $message_id){

		for($locMsgNum = 1; $locMsgNum <= $mailgate->getTotalMails(); $locMsgNum++) {
			$fetchheader = PluginMailAnalyzer::getAdditionnalHeaders($mailgate->marubox, $locMsgNum) ;
			if( array_key_exists( 'message-id', $fetchheader) && $fetchheader['message-id'] == $message_id ) {
            $mailgate->{$mailgate->pluginmailanalyzer_uid_field} = imap_uid($mailgate->marubox, $locMsgNum);
				return $fetchheader ; // message is found, then stop search, and $mailgate->{$mailgate->pluginmailanalyzer_mid_field} is the msg uid in the mailbox
         }
		}

		return array(); // returns an empty array if not found, in this case, $mailgate->{$mailgate->pluginmailanalyzer_mid_field} is not changed
	}

	/**
    * Create default mailgate
    * @param int $mailgate_id is the id of the mail collector in GLPI DB
    * @return MailCollector
    *
    */
	static function openMailgate( $mailgate_id ){

		$mailgate = new MailCollector() ;
		$mailgate->getFromDB($mailgate_id) ;
      self::setUIDField($mailgate);
		$mailgate->{$mailgate->pluginmailanalyzer_uid_field} = -1 ;
		$mailgate->connect() ;

		return $mailgate ;
	}


   /**
    * Retrieve a user from the database using its lastName + FirstName
    *
    * @param $name login of the user
    *
    * @return true if succeed else false
    **/
   function getFromDBbyName($name) {
      global $DB;

      $query = "SELECT *
                FROM `".$this->getTable()."`
                WHERE `name` = '$name'";

      if ($result = $DB->query($query)) {
         if ($DB->numrows($result) != 1) {
            return false;
         }
         $this->fields = $DB->fetch_assoc($result);
         if (is_array($this->fields) && count($this->fields)) {
            return true;
         }
      }
      return false;
   }


   /**
    */

	public static function plugin_pre_item_add_mailanalyzer_followup($parm) {
		global $DB ;

      if( array_key_exists('_head', $parm->input) ) {
         // try to change requester
         // search for ##From if it exists, then try to find real requester from DB
         $locUser = new User();

         $ptnUserFullName = "/##From: ([_a-z0-9-\\\\' ]+), ([_.a-z0-9- ]+)/i";

         $str = self::getTextFromHtml($parm->input['content']) ;

         if( preg_match_all($ptnUserFullName, $str, $matches, PREG_PATTERN_ORDER) > 0 ){
            // we found at least one ##From:
            // then try to get its user id from DB
            $query = "select glpi_users.* from glpi_users
                    right outer join glpi_useremails on glpi_useremails.users_id = glpi_users.id
                    where glpi_users.realname LIKE '".trim( $matches[1][0] )."'
                          and glpi_users.firstname like '".trim( $matches[2][0] )."'
                          and glpi_useremails.is_default = 1
                          and glpi_users.is_active = 1
                          and glpi_users.is_deleted = 0;" ;
            $res = $DB->query($query) ;
            if( $DB->numrows($res) == 1 ) { // we need to be sure there is only one user with same name and same firstname
               $row = $DB->fetch_array($res);
               if( $locUser->getFromDB( $row['id'] ) ) {
                  // set users_id
                  $parm->input['users_id'] = $locUser->getID() ;
                  //$parm->input['_users_id_requester'] = $locUser->getID() ;
               }
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
   public static function getTextFromHtml( $str ) {
      $ret = Toolbox::unclean_html_cross_side_scripting_deep($str);
      $ret = preg_replace('@<\s*br\s*/*>@', "\n", $ret);
      return strip_tags( $ret );
   }

	/**
    */

	public static function plugin_pre_item_add_mailanalyzer($parm) {
		global $DB, $GLOBALS ;

		if( array_key_exists('_head', $parm->input) ) {
			// this ticket have been created via email receiver.

			$references = array() ;

         // try to change requester
         // search for ##From if it exists, then try to find real requester from DB
         $locUser = new User();

         $ptnUserFullName = "/##From: ([_a-z0-9-\\\\' ]+), ([_.a-z0-9- ]+)/i";

         $str = self::getTextFromHtml($parm->input['content']) ;

         if( preg_match_all($ptnUserFullName, $str, $matches, PREG_PATTERN_ORDER) > 0 ){
            // we found at least one ##From:
            // then try to get its user id from DB
            $query = "select glpi_users.* from glpi_users
                    right outer join glpi_useremails on glpi_useremails.users_id = glpi_users.id
                    where glpi_users.realname LIKE '".trim( $matches[1][0] )."'
                          and glpi_users.firstname like '".trim( $matches[2][0] )."'
                          and glpi_useremails.is_default = 1
                          and glpi_users.is_active = 1
                          and glpi_users.is_deleted = 0;" ;
            //$query = "select glpi_users.* from glpi_users where realname LIKE '".$matches[1][0]."' and firstname like '".$matches[2][0]."' and is_active = 1 and is_deleted = 0;" ;
            $res = $DB->query($query) ;
            if( $DB->numrows($res) == 1 ) { // we need to be sure there is only one user with same name and same firstname
               $row = $DB->fetch_array($res);
               if( $locUser->getFromDB( $row['id'] ) ) {
                  // set user_id and user_entity only if 'post-only' profile is found and unique
                  $entity = Profile_User::getEntitiesForProfileByUser( $locUser->getID(), 1 ) ;
                  if( count( $entity ) == 1 ) {
                     $parm->input['users_id_recipient'] = $parm->input['_users_id_requester'] ;
                     $parm->input['_users_id_requester'] = $locUser->getID() ;
                     //$entity = array_keys( $entity ) ;
                     $parm->input['entities_id'] = current( $entity ) ; //[0] ;
                  }
               }
            }
         }
         //            print_r($matches);

			if( array_key_exists('mailgate', $GLOBALS) ){
				// mailgate has been open by web page call, then use it
				$mailgate = $GLOBALS['mailgate'] ;
            self::setUIDField($mailgate);
			}
			else{
				// mailgate is not open. Called by cron
				// then locally create a mailgate
				$mailgate = PluginMailAnalyzer::openMailgate($parm->input['_mailgate']) ;
			}

			// we must check if this email has not been received yet!
			// test if 'message-id' is in the DB
			$query = "SELECT * FROM glpi_plugin_mailanalyzer_message_id WHERE ticket_id <> 0 AND ( message_id = '".$parm->input['_head']['message_id']."' );" ;
			$res = $DB->query($query) ;
			if( $DB->numrows($res) > 0){
				// email already received
				// must prevent ticket creation
				$parm->input = array( ) ;

				// as Ticket creation is cancelled, then email is not deleted from mailbox
				// then we need to set deletion flag to true to this email from mailbox folder
				$mailgate->deleteMails( $mailgate->{$mailgate->pluginmailanalyzer_uid_field}, MailCollector::REFUSED_FOLDER ) ; // NOK Folder

				// close mailgate only if localy open
				if( !array_key_exists('mailgate', $GLOBALS) )
					$mailgate->close_mailbox() ; // close session and delete emails marked for deletion during this session only!

				return ;
			}


			// try to get Thread-Index from email header
			$fetchheader = PluginMailAnalyzer::getHeaderAndMsgNum($mailgate, $parm->input['_head']['message_id']) ;

			// search for 'Thread-Index'
			if( array_key_exists( 'thread-index', $fetchheader) ){
				// exemple of thread-index : Ac5rWReeRb4gv3pCR8GDflsZrsqhoA==
				// explanations to decode this property: http://msdn.microsoft.com/en-us/library/ee202481%28v=exchg.80%29.aspx
				$references[] = bin2hex(substr(imap_base64($fetchheader['thread-index']), 6, 16 )) ;
			}

         // 			$thread_topic="";
         // 			if( array_key_exists( 'thread-topic', $fetchheader) ){
         // 				// initial topic
         // 				$thread_topic = imap_utf8($fetchheader['thread-topic']) ;
         // 			}


			// this ticket has been created via an email receiver.
			// we have to check if references can be found in DB.
			if( array_key_exists('references', $parm->input['_head']) ) {
				// we may have a forwarded email that looks like reply-to
            if(preg_match_all('/<.*?>/', $parm->input['_head']['references'], $matches)){
               $references = array_merge($references,  $matches[0]) ;
            }
			}

			if( count( $references ) > 0) {

				$query = "";
				foreach( $references as $ref ){
					if($query <> "" )
						$query .= " OR" ;
					$query .= " (message_id = '".$ref."')" ;
				}

				$query = "SELECT * FROM glpi_plugin_mailanalyzer_message_id WHERE ticket_id <> 0 AND ( ".$query." ) ORDER BY ticket_id DESC;" ;
				$res = $DB->query($query) ;
				if( $DB->numrows($res) > 0){
               $row = $DB->fetch_array($res);
					// TicketFollowup creation only if ticket status is not solved or closed
               //					echo $row['ticket_id'] ;
               $locTicket = new Ticket() ;
               $locTicket->getFromDB( $row['ticket_id'] ) ;
               if( $locTicket->fields['status'] !=  CommonITILObject::SOLVED && $locTicket->fields['status'] != CommonITILObject::CLOSED) {
                  $ticketfollowup = new TicketFollowup() ;
                  $input = $parm->input ;
                  $input['tickets_id'] = $row['ticket_id'] ;
                  $input['users_id'] = $parm->input['_users_id_requester'] ;
                  $input['add_reopen'] = 1 ;

                  unset( $input['urgency'] ) ;
                  unset( $input['itemtype'] ) ;
                  unset( $input['entities_id'] ) ;
                  unset( $input['_ruleid'] ) ;

                  $ticketfollowup->add($input) ;

                  // add message id to DB in case of another email will use it
                  $query = "INSERT INTO glpi_plugin_mailanalyzer_message_id (message_id, ticket_id) VALUES ('".$input['_head']['message_id']."', ".$input['tickets_id'].");";
                  $DB->query($query) ;

                  // prevent Ticket creation. Unfortunately it will return an error to receiver when started manually from web page
                  $parm->input = array() ; // empty array...

                  // as Ticket creation is cancelled, then email is not deleted from mailbox
                  // then we need to set deletion flag to true to this email from mailbox folder
                  $mailgate->deleteMails($mailgate->{$mailgate->pluginmailanalyzer_uid_field}, MailCollector::ACCEPTED_FOLDER) ; // OK folder

                  // close mailgate only if localy open
                  if( !array_key_exists('mailgate', $GLOBALS) )
                     $mailgate->close_mailbox() ; // close session and delete emails marked for deletion during this session only!

                  return ;
               } else {
                  // ticket creation, but linked to the closed one...
                  $parm->input['_link'] = array('link' => '1', 'tickets_id_1' => '0', 'tickets_id_2' => $row['ticket_id']) ;
               }
				}
			}

			// can't find ref into DB, then this is a new ticket, in this case insert refs and message_id into DB
			$references[] = $parm->input['_head']['message_id'] ;

			// this is a new ticket
			// then add references and message_id to DB
			foreach($references as $ref){
				$query = "INSERT IGNORE INTO glpi_plugin_mailanalyzer_message_id (message_id, ticket_id) VALUES ('".$ref."', 0);";
				$DB->query($query) ;
			}

		}

	}


	public static function plugin_item_add_mailanalyzer($parm) {
		global $DB ;

		if( array_key_exists('_head', $parm->input) ) {
			// this ticket have been created via email receiver.
			// update the ticket ID for the message_id only for newly created tickets (ticket_id == 0)

			$query = " (message_id = '". $parm->input['_head']['message_id']."')" ;

			$fetchheader = array() ;

			if( array_key_exists('mailgate', $GLOBALS) ){
				// mailgate has been open by web page call, then use it
				$mailgate = $GLOBALS['mailgate'] ;
            self::setUIDField($mailgate);
			}
			else{
				$mailgate = PluginMailAnalyzer::openMailgate($parm->input['_mailgate']) ;
			}

			// try to get Thread-Index from email header
			$fetchheader = PluginMailAnalyzer::getHeaderAndMsgNum($mailgate, $parm->input['_head']['message_id']) ;

			// search for 'Thread-Index: '
			if( array_key_exists( 'thread-index', $fetchheader) ){
				// exemple of thread-index : Ac5rWReeRb4gv3pCR8GDflsZrsqhoA==
				// explanations to decode this property: http://msdn.microsoft.com/en-us/library/ee202481%28v=exchg.80%29.aspx
				$thread_index = bin2hex(substr(imap_base64($fetchheader['thread-index']), 6, 16 )) ;
				$query .= " OR (message_id = '".$thread_index."')" ;
			}

			// search for references
			if( array_key_exists('references', $parm->input['_head']) ) {
				// we may have a forwarded email that looks like reply-to
            $references = array();
            if(preg_match_all('/<.*?>/', $parm->input['_head']['references'], $matches)){
               $references =  $matches[0] ;
            }
				foreach( $references as $ref ){
					$query .= " OR (message_id = '".$ref."')" ;
				}
			}

			$query = "UPDATE glpi_plugin_mailanalyzer_message_id SET ticket_id = ". $parm->fields['id']." WHERE ticket_id = 0 AND ( ".$query.") ;" ;
			$DB->query($query) ;

			// close mailgate only if localy open
			if( !array_key_exists('mailgate', $GLOBALS) )
				$mailgate->close_mailbox() ;

		}
	}

   static function setUIDField($mailgate) {
      if (isset($mailgate->uid)) {
         $mailgate->pluginmailanalyzer_uid_field = 'uid';
      } else {
         $mailgate->pluginmailanalyzer_uid_field = 'mid';
      }
      if (preg_match('@/pop(/|})@i', $mailgate->fields['host'])) {
         $mailgate->fields['refused'] = '';
         $mailgate->fields['accepted'] = '';
      }
   }
}

