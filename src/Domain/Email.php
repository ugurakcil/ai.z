<?php

declare(strict_types=1);

namespace DigitalExchange\AiMailReply\Domain;

class Email
{
    private string $messageId;
    private string $subject;
    private string $body;
    private string $htmlBody;
    private string $from;
    private string $fromName;
    private array $to = [];
    private array $cc = [];
    private array $replyTo = [];
    private ?string $inReplyTo = null;
    private ?string $references = null;
    private array $threadEmails = [];
    private ?string $customPrompt = null;
    private array $specialDirectives = [];

    public function __construct(
        string $messageId,
        string $subject,
        string $body,
        string $htmlBody,
        string $from,
        string $fromName,
        array $to,
        array $cc = [],
        array $replyTo = [],
        ?string $inReplyTo = null,
        ?string $references = null
    ) {
        $this->messageId = $messageId;
        $this->subject = $subject;
        $this->body = $body;
        $this->htmlBody = $htmlBody;
        $this->from = $from;
        $this->fromName = $fromName;
        $this->to = $to;
        $this->cc = $cc;
        $this->replyTo = $replyTo;
        $this->inReplyTo = $inReplyTo;
        $this->references = $references;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getHtmlBody(): string
    {
        return $this->htmlBody;
    }

    public function getFrom(): string
    {
        return $this->from;
    }

    public function getFromName(): string
    {
        return $this->fromName;
    }

    public function getTo(): array
    {
        return $this->to;
    }

    public function getCc(): array
    {
        return $this->cc;
    }

    public function getReplyTo(): array
    {
        return $this->replyTo;
    }

    public function getInReplyTo(): ?string
    {
        return $this->inReplyTo;
    }

    public function getReferences(): ?string
    {
        return $this->references;
    }

    public function setThreadEmails(array $threadEmails): void
    {
        $this->threadEmails = $threadEmails;
    }

    public function getThreadEmails(): array
    {
        return $this->threadEmails;
    }

    public function setCustomPrompt(?string $customPrompt): void
    {
        $this->customPrompt = $customPrompt;
    }

    public function getCustomPrompt(): ?string
    {
        return $this->customPrompt;
    }

    public function setSpecialDirectives(array $specialDirectives): void
    {
        $this->specialDirectives = $specialDirectives;
    }

    public function getSpecialDirectives(): array
    {
        return $this->specialDirectives;
    }

    public function hasSpecialDirective(string $directive): bool
    {
        return isset($this->specialDirectives[$directive]);
    }

    public function getSpecialDirective(string $directive): mixed
    {
        return $this->specialDirectives[$directive] ?? null;
    }

    public function getAllRecipients(): array
    {
        return array_merge($this->to, $this->cc);
    }
}