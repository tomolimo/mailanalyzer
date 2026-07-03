<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI
Copyright (C) 2011-2026 by Raynet SAS a company of A.Raymond Network.
-------------------------------------------------------------------------
LICENSE: GPLv2+
--------------------------------------------------------------------------
 */

namespace GlpiPlugin\Mailanalyzer;

use CommonDBTM;
use GLPIKey;
use Laminas\Mail\Storage\Message;
use MailCollector;
use Toolbox;

/**
 * Wrapper around GLPI's MailCollector to add Thread-Index support.
 *
 * Uses glpi_mailcollectors table (same as MailCollector) but exposes
 * only the functionality this plugin needs, avoiding direct inheritance
 * from MailCollector which may conflict with GLPI 11 internals.
 *
 * GLPI 11 note:
 * - Toolbox::parseMailServerConnectString() is still available in 11.x.
 * - Toolbox::getMailServerStorageInstance() is still available in 11.x.
 * - GLPIKey::decrypt() is the correct way to decrypt stored passwords.
 */
class MailCollectorWrapper extends CommonDBTM
{
    /** @var \Laminas\Mail\Storage\AbstractStorage|null */
    private $storage = null;

    /** @var int UID cursor (set to -1 before connect) */
    public int $uid = -1;

    /**
     * Share the same DB table as MailCollector.
     */
    public static function getTable($classname = null): string
    {
        return MailCollector::getTable();
    }

    /**
     * Connect to the mail server described in $this->fields['host'].
     *
     * @throws \Throwable on connection failure
     */
    public function connect(): void
    {
        $config = Toolbox::parseMailServerConnectString($this->fields['host']);

        $params = [
            'host'     => $config['address'],
            'user'     => $this->fields['login'],
            'password' => (new GLPIKey())->decrypt($this->fields['passwd']),
            'port'     => $config['port'],
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
                throw new \RuntimeException(
                    sprintf(__('Unsupported mail server type: %s.', 'mailanalyzer'), $config['type'])
                );
            }
            $this->storage = $storage;

            // Reset error counter on successful connection
            if ($this->fields['errors'] > 0) {
                $this->update(['id' => $this->getID(), 'errors' => 0]);
            }
        } catch (\Throwable $e) {
            $this->update(['id' => $this->getID(), 'errors' => ($this->fields['errors'] + 1)]);
            throw $e;
        }
    }

    /**
     * Retrieve the Thread-Index header value from a Laminas message.
     *
     * The Thread-Index header is a Microsoft-specific base64-encoded blob.
     * We extract bytes 6–21 (16 bytes) and hex-encode them to get a stable
     * string key for threading.
     *
     * @param Message $message
     * @return string|null Hex string, or null if header is absent
     */
    public function getThreadIndex(Message $message): ?string
    {
        if (!isset($message->threadindex)) {
            return null;
        }

        $header = $message->getHeader('threadindex');
        if ($header === null) {
            return null;
        }

        return bin2hex(substr(base64_decode($header->getFieldValue()), 6, 16));
    }

    /**
     * Retrieve a Laminas Message object by UID.
     *
     * @param mixed $uid  Mail UID as stored in GLPI's ticket input
     * @return Message
     */
    public function getMessage($uid): Message
    {
        return $this->storage->getMessage(
            $this->storage->getNumberByUniqueId($uid)
        );
    }

    /**
     * Move or delete a mail from the mailbox.
     *
     * Mirrors MailCollector::deleteMails() but operates on our storage handle.
     *
     * @param mixed  $uid    Mail UID
     * @param string $folder Folder constant from MailCollector (ACCEPTED_FOLDER / REFUSED_FOLDER)
     *                       Pass '' to delete without moving.
     */
    public function deleteMails($uid, string $folder = ''): bool
    {
        // POP3 has no folders — always delete
        if (strstr($this->fields['host'], '/pop')) {
            $folder = '';
        }

        if (!empty($folder) && isset($this->fields[$folder]) && !empty($this->fields[$folder])) {
            $name = mb_convert_encoding($this->fields[$folder], 'UTF7-IMAP', 'UTF-8');
            try {
                $this->storage->moveMessage(
                    $this->storage->getNumberByUniqueId($uid),
                    $name
                );
                return true;
            } catch (\Throwable $e) {
                trigger_error(
                    sprintf(
                        __('Invalid configuration for %1$s folder in receiver %2$s', 'mailanalyzer'),
                        $folder,
                        $this->getName()
                    )
                );
                // Fall through to delete
            }
        }

        $this->storage->removeMessage($this->storage->getNumberByUniqueId($uid));
        return true;
    }
}
