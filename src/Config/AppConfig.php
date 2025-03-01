<?php

declare(strict_types=1);

namespace DigitalExchange\AiMailReply\Config;

class AppConfig
{
    private array $emailConfig;
    private array $imapConfig;
    private array $openaiConfig;
    private array $appConfig;

    public function __construct()
    {
        $this->loadEmailConfig();
        $this->loadImapConfig();
        $this->loadOpenaiConfig();
        $this->loadAppConfig();
    }

    private function loadEmailConfig(): void
    {
        $this->emailConfig = [
            'host' => $_ENV['EMAIL_HOST'],
            'port' => (int)$_ENV['EMAIL_PORT'],
            'username' => $_ENV['EMAIL_USERNAME'],
            'password' => $_ENV['EMAIL_PASSWORD'],
            'encryption' => $_ENV['EMAIL_ENCRYPTION'],
            'from_name' => $_ENV['EMAIL_FROM_NAME'],
        ];
    }

    private function loadImapConfig(): void
    {
        $this->imapConfig = [
            'host' => $_ENV['IMAP_HOST'],
            'port' => (int)$_ENV['IMAP_PORT'],
            'username' => $_ENV['IMAP_USERNAME'],
            'password' => $_ENV['IMAP_PASSWORD'],
            'encryption' => $_ENV['IMAP_ENCRYPTION'],
        ];
    }

    private function loadOpenaiConfig(): void
    {
        $this->openaiConfig = [
            'api_key' => $_ENV['OPENAI_API_KEY'],
            'model' => $_ENV['OPENAI_MODEL'],
        ];
    }

    private function loadAppConfig(): void
    {
        $this->appConfig = [
            'allowed_domains' => explode(',', $_ENV['ALLOWED_DOMAINS']),
            'blocked_recipients' => isset($_ENV['BLOCKED_RECIPIENTS']) ? explode(',', $_ENV['BLOCKED_RECIPIENTS']) : [],
            'blocked_senders' => isset($_ENV['BLOCKED_SENDERS']) ? explode(',', $_ENV['BLOCKED_SENDERS']) : [],
            'max_recipients' => isset($_ENV['MAX_RECIPIENTS']) ? (int)$_ENV['MAX_RECIPIENTS'] : 10,
            'daily_request_limit' => (int)$_ENV['DAILY_REQUEST_LIMIT'],
            'request_history_file' => $_ENV['REQUEST_HISTORY_FILE'],
            'default_prompt' => $_ENV['DEFAULT_PROMPT'],
            'allow_ai_recipients' => isset($_ENV['ALLOW_AI_RECIPIENTS']) ? filter_var($_ENV['ALLOW_AI_RECIPIENTS'], FILTER_VALIDATE_BOOLEAN) : false,
            'debug' => isset($_ENV['DEBUG']) ? filter_var($_ENV['DEBUG'], FILTER_VALIDATE_BOOLEAN) : true,
            'include_thread_emails' => isset($_ENV['INCLUDE_THREAD_EMAILS']) ? filter_var($_ENV['INCLUDE_THREAD_EMAILS'], FILTER_VALIDATE_BOOLEAN) : false,
            'ignore_cc_emails' => isset($_ENV['IGNORE_CC_EMAILS']) ? filter_var($_ENV['IGNORE_CC_EMAILS'], FILTER_VALIDATE_BOOLEAN) : false,
        ];
    }

    public function getEmailConfig(): array
    {
        return $this->emailConfig;
    }

    public function getImapConfig(): array
    {
        return $this->imapConfig;
    }

    public function getOpenaiConfig(): array
    {
        return $this->openaiConfig;
    }

    public function getAppConfig(): array
    {
        return $this->appConfig;
    }

    public function getAllowedDomains(): array
    {
        return $this->appConfig['allowed_domains'];
    }

    public function getBlockedRecipients(): array
    {
        return $this->appConfig['blocked_recipients'];
    }

    public function getBlockedSenders(): array
    {
        return $this->appConfig['blocked_senders'];
    }

    public function getMaxRecipients(): int
    {
        return $this->appConfig['max_recipients'];
    }

    public function getDailyRequestLimit(): int
    {
        return $this->appConfig['daily_request_limit'];
    }

    public function getRequestHistoryFile(): string
    {
        return $this->appConfig['request_history_file'];
    }

    public function getDefaultPrompt(): string
    {
        return $this->appConfig['default_prompt'];
    }

    public function includeThreadEmails(): bool
    {
        return $this->appConfig['include_thread_emails'];
    }

    public function allowAiRecipients(): bool
    {
        return $this->appConfig['allow_ai_recipients'];
    }

    public function isDebugEnabled(): bool
    {
        return $this->appConfig['debug'];
    }
    
    public function ignoreCcEmails(): bool
    {
        return $this->appConfig['ignore_cc_emails'];
    }
}