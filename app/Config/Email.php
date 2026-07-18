<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Email extends BaseConfig
{
    public string $fromEmail  = 'no-reply@bsu.edu.ph'; // This will be overwritten by your Controller
    public string $fromName   = 'BSU GSO Portal';
    public string $recipients = '';

    public string $userAgent = 'CodeIgniter';
    public string $protocol = 'smtp';
    public string $mailPath = '/usr/sbin/sendmail';
    public string $SMTPHost = 'sandbox.smtp.mailtrap.io';
    public string $SMTPUser = ''; // Leave empty, .env handles this
    public string $SMTPPass = ''; // Leave empty, .env handles this
    public int $SMTPPort = 2525;
    public int $SMTPTimeout = 15;
    public bool $SMTPKeepAlive = false;
    public string $SMTPCrypto = '';
    public bool $wordWrap = true;
    public int $wrapChars = 76;
    public string $mailType = 'html';
    public string $charset = 'utf-8';
    public bool $validate = false;
    public int $priority = 3;

    // Critical for SMTP delivery
    public string $CRLF = "\r\n";
    public string $newline = "\r\n";

    public bool $BCCBatchMode = false;
    public int $BCCBatchSize = 200;
    public bool $DSN = false;
}