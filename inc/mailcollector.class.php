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
-*/

use Laminas\Mail\Storage\Message;

class PluginMailanalyzerMailCollector extends CommonDBTM
{
    private $storage;
    public $uid = -1;

    public static function getTable($classname = null) {
        return MailCollector::getTable();
    }

    public function connect()
    {
        $config = Toolbox::parseMailServerConnectString($this->fields['host']);

        $params = [
            'host'      => $config['address'],
            'user'      => $this->fields['login'],
            'password'  => (new GLPIKey())->decrypt($this->fields['passwd']),
            'port'      => $config['port']
        ];

        if ($config['ssl']) {
            $params['ssl'] = 'SSL';
        }

        if ($config['tls']) {
            $params['ssl'] = 'TLS';
        }

        if (!empty($config['mailbox'])) {
            $params['folder'] = mb_convert_encoding($config['mailbox'], 'UTF7-IMAP', 'UTF-8');
        }

        if ($config['validate-cert'] === false) {
            $params['novalidatecert'] = true;
        }

        try {
            $storage = Toolbox::getMailServerStorageInstance($config['type'], $params);
            if ($storage === null) {
                throw new \Exception(sprintf(__('Unsupported mail server type:%s.'), $config['type']));
            }
            $this->storage = $storage;
            if ($this->fields['errors'] > 0) {
                $this->update([
                    'id'     => $this->getID(),
                    'errors' => 0
                ]);
            }
        } catch (\Throwable $e) {
            $this->update([
                'id'     => $this->getID(),
                'errors' => ($this->fields['errors'] + 1)
            ]);
           // Any errors will cause an Exception.
            throw $e;
        }
    }

    /**
     * Summary of getThreadIndex
     * @param Message $message 
     * @return string|null
     */
    public function getThreadIndex(Message $message) {
        if (isset($message->threadindex)) {
            if ($val = $message->getHeader('threadindex')) {
                return bin2hex(substr(base64_decode($val->getFieldValue()), 6, 16 ));
            }
        }
        return null;
    }

    /**
     * Summary of getMessage
     * @param mixed $uid 
     * @return Message
     */
    public function getMessage($uid) : Message {
        return $this->storage->getMessage($this->storage->getNumberByUniqueId($uid));
    }

        /**
     * Delete mail from that mail box
     *
     * @param string $uid    mail UID
     * @param string $folder Folder to move (delete if empty) (default '')
     *
     * @return boolean
     **/
    public function deleteMails($uid, $folder = '')
    {

       // Disable move support, POP protocol only has the INBOX folder
        if (strstr($this->fields['host'], "/pop")) {
            $folder = '';
        }

        if (!empty($folder) && isset($this->fields[$folder]) && !empty($this->fields[$folder])) {
            $name = mb_convert_encoding($this->fields[$folder], "UTF7-IMAP", "UTF-8");
            try {
                $this->storage->moveMessage($this->storage->getNumberByUniqueId($uid), $name);
                return true;
            } catch (\Throwable $e) {
               // raise an error and fallback to delete
                trigger_error(
                    sprintf(
                    //TRANS: %1$s is the name of the folder, %2$s is the name of the receiver
                        __('Invalid configuration for %1$s folder in receiver %2$s'),
                        $folder,
                        $this->getName()
                    )
                );
            }
        }
        $this->storage->removeMessage($this->storage->getNumberByUniqueId($uid));
        return true;
    }

}

