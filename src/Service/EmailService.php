<?php

declare(strict_types=1);

namespace DigitalExchange\AiMailReply\Service;

use DigitalExchange\AiMailReply\Config\AppConfig;
use DigitalExchange\AiMailReply\Domain\Email;
use DigitalExchange\AiMailReply\Domain\AiResponse;
use DigitalExchange\AiMailReply\Exception\InvalidEmailDomainException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Monolog\Logger;
use Parsedown;

class EmailService
{
    private AppConfig $config;
    private Logger $logger;
    private array $allowedDomains;
    private bool $includeThreadEmails;
    private array $blockedSenders;
    private Parsedown $parsedown;

    public function __construct(AppConfig $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->allowedDomains = $config->getAllowedDomains();
        $this->blockedSenders = $config->getBlockedSenders();
        $this->includeThreadEmails = $config->includeThreadEmails();
        $this->parsedown = new Parsedown();
    }

    /**
     * Get unseen emails from IMAP server
     * 
     * @return array Array of Email objects
     */
    public function getUnseenEmails(): array
    {
        $imapConfig = $this->config->getImapConfig();
        $mailbox = $this->getImapMailbox($imapConfig);
        
        $emails = [];
        
        try {
            // Connect to the mailbox
            if (!imap_open($mailbox, $imapConfig['username'], $imapConfig['password'])) {
                $this->logger->error('IMAP connection failed: ' . imap_last_error());
                return [];
            }
            
            // Search for unseen messages
            $unseenEmails = imap_search(imap_open($mailbox, $imapConfig['username'], $imapConfig['password']), 'UNSEEN');
            
            if (!$unseenEmails) {
                $this->logger->info('No unseen emails found');
                imap_close(imap_open($mailbox, $imapConfig['username'], $imapConfig['password']));
                return [];
            }
            
            foreach ($unseenEmails as $emailId) {
                $email = $this->fetchEmail($emailId, imap_open($mailbox, $imapConfig['username'], $imapConfig['password']));
                
                if ($email) {
                    // Validate sender domain and check if sender is blocked
                    $senderEmail = $email->getFrom();
                    if (!$this->isAllowedDomain($senderEmail)) {
                        $this->logger->warning('Email from unauthorized domain: ' . $senderEmail);
                        // Delete email instead of marking as read
                        $this->deleteEmail($emailId, imap_open($mailbox, $imapConfig['username'], $imapConfig['password']));
                        continue;
                    } else if (in_array($senderEmail, $this->blockedSenders)) {
                        $this->logger->warning('Email from blocked sender: ' . $senderEmail);
                        // Delete email instead of marking as read
                        $this->deleteEmail($emailId, imap_open($mailbox, $imapConfig['username'], $imapConfig['password']));
                        continue;
                    }
                    
                    $emails[] = $email;
                }
            }
            
            imap_close(imap_open($mailbox, $imapConfig['username'], $imapConfig['password']));
        } catch (\Exception $e) {
            $this->logger->error('Error fetching emails: ' . $e->getMessage());
        }
        
        return $emails;
    }

    /**
     * Fetch email details from IMAP
     * 
     * @param int $emailId Email ID
     * @param resource $imapStream IMAP stream
     * @return Email|null Email object or null on error
     */
    private function fetchEmail(int $emailId, $imapStream): ?Email
    {
        try {
            $overview = imap_fetch_overview($imapStream, (string)$emailId, 0);
            $structure = imap_fetchstructure($imapStream, $emailId);
            
            if (empty($overview)) {
                return null;
            }
            
            $overview = $overview[0];
            
            // Get headers
            $headers = imap_fetchheader($imapStream, $emailId);
            
            // Parse headers
            $headerObj = imap_rfc822_parse_headers($headers);
            
            // Get message ID
            $messageId = $overview->message_id ?? '';
            
            // Get subject
            $subject = $this->decodeSubject($overview->subject ?? '');
            
            // Get body
            $body = $this->getEmailBody($imapStream, $emailId, $structure);
            $htmlBody = $this->getEmailHtmlBody($imapStream, $emailId, $structure);
            
            // Get sender
            $from = $headerObj->from[0]->mailbox . '@' . $headerObj->from[0]->host;
            $fromName = $headerObj->from[0]->personal ?? $from;
            
            // Get recipients
            $to = $this->parseAddresses($headerObj->to ?? []);
            $cc = $this->parseAddresses($headerObj->cc ?? []);
            $replyTo = $this->parseAddresses($headerObj->reply_to ?? []);
            
            // Get references
            $inReplyTo = $headerObj->in_reply_to ?? null;
            $references = $headerObj->references ?? null;
            
            // Create Email object
            $email = new Email(
                $messageId,
                $subject,
                $body,
                $htmlBody,
                $from,
                $fromName,
                $to,
                $cc,
                $replyTo,
                $inReplyTo,
                $references
            );
            
            // Get thread emails if this is a reply
            if ($references) {
                $threadEmails = $this->getThreadEmails($imapStream, $references);
                $email->setThreadEmails($threadEmails);
            }
            
            // Extract custom prompt and special directives
            $this->extractCustomPromptAndDirectives($email);
            
            return $email;
        } catch (\Exception $e) {
            $this->logger->error('Error fetching email details: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get email body
     * 
     * @param resource $imapStream IMAP stream
     * @param int $emailId Email ID
     * @param object $structure Email structure
     * @return string Email body
     */
    private function getEmailBody($imapStream, $emailId, $structure): string
    {
        $body = '';
        
        if ($structure->type == 0) { // Plain text
            $body = imap_fetchbody($imapStream, $emailId, "1");
        } elseif ($structure->type == 1) { // Multipart
            // Try to get plain text part
            for ($i = 0; $i < count($structure->parts); $i++) {
                $part = $structure->parts[$i];
                if ($part->type == 0) {
                    $body = imap_fetchbody($imapStream, $emailId, (string)($i + 1));
                    break;
                }
            }
        }
        
        // Decode body
        if ($structure->encoding == 3) { // Base64
            $body = base64_decode($body);
        } elseif ($structure->encoding == 4) { // Quoted-printable
            $body = quoted_printable_decode($body);
        } else {
            // Diğer kodlamalar için de quoted-printable decode deneyelim
            $body = quoted_printable_decode($body);
        }
        
        return $body;
    }

    /**
     * Get email HTML body
     * 
     * @param resource $imapStream IMAP stream
     * @param int $emailId Email ID
     * @param object $structure Email structure
     * @return string Email HTML body
     */
    private function getEmailHtmlBody($imapStream, $emailId, $structure): string
    {
        $htmlBody = '';
        
        if ($structure->type == 1) { // Multipart
            // Try to get HTML part
            for ($i = 0; $i < count($structure->parts); $i++) {
                $part = $structure->parts[$i];
                if ($part->type == 0 && $part->subtype == 'HTML') {
                    $htmlBody = imap_fetchbody($imapStream, $emailId, (string)($i + 1));
                    break;
                }
            }
        }
        
        // Decode body
        if ($structure->encoding == 3) { // Base64
            $htmlBody = base64_decode($htmlBody);
        } elseif ($structure->encoding == 4) { // Quoted-printable
            $htmlBody = quoted_printable_decode($htmlBody);
        } else {
            // Diğer kodlamalar için de quoted-printable decode deneyelim
            $htmlBody = quoted_printable_decode($htmlBody);
        }
        
        return $htmlBody;
    }

    /**
     * Parse email addresses
     * 
     * @param array $addresses Array of address objects
     * @return array Array of email addresses
     */
    private function parseAddresses(array $addresses): array
    {
        $result = [];
        
        foreach ($addresses as $address) {
            $email = $address->mailbox . '@' . $address->host;
            $result[] = $email;
        }
        
        return $result;
    }

    /**
     * Decode email subject
     * 
     * @param string $subject Encoded subject
     * @return string Decoded subject
     */
    private function decodeSubject(string $subject): string
    {
        $elements = imap_mime_header_decode($subject);
        $subject = '';
        
        foreach ($elements as $element) {
            $subject .= $element->text;
        }
        
        return $subject;
    }

    /**
     * Get thread emails
     * 
     * @param resource $imapStream IMAP stream
     * @param string $references Message references
     * @return array Array of email bodies
     */
    private function getThreadEmails($imapStream, string $references): array
    {
        $threadEmails = [];
        $messageIds = explode(' ', $references);
        
        foreach ($messageIds as $messageId) {
            $messageId = trim($messageId);
            if (empty($messageId)) {
                continue;
            }
            
            // Get all messages and filter by Message-ID
            $search = [];
            $messageCount = imap_num_msg($imapStream);
            
            for ($i = 1; $i <= $messageCount; $i++) {
                $headers = imap_headerinfo($imapStream, $i);
                if (!$headers) {
                    continue;
                }
                if (isset($headers->message_id) && trim($headers->message_id) === $messageId) {
                    $search[] = $i;
                }
            }
            
            if ($search && count($search) > 0) {
                $emailId = $search[0];
                $structure = imap_fetchstructure($imapStream, $emailId);
                $body = $this->getEmailBody($imapStream, $emailId, $structure);
                
                if (!empty($body)) {
                    $threadEmails[] = $body;
                }
            }
        }
        
        return $threadEmails;
    }

    /**
     * Extract custom prompt and special directives from email body
     * 
     * @param Email $email Email object
     */
    private function extractCustomPromptAndDirectives(Email $email): void
    {
        $body = $email->getBody();
        $customPrompt = null;
        $specialDirectives = [];
        
        // Extract custom prompt (the first line of the latest email)
        $lines = explode("\n", $body);
        if (!empty($lines)) {
            $customPrompt = trim($lines[0]);
        }
        
        // Extract special directives
        // Example: "Cevabı sadece example@example.com'a gönder"
        // if (preg_match('/cevab[ıi]\s+sadece\s+([^\s,;]+@[^\s,;]+)\'[ae]\s+gönder/i', $body, $matches)) {
        //     $specialDirectives['send_to_only'] = trim($matches[1]);
        // }
        
        // Example: "Şunu da ekle: user@example.com"
        // if (preg_match('/şunu\s+da\s+ekle:\s+([^\s,;]+@[^\s,;]+)/i', $body, $matches)) {
        //     $specialDirectives['add_recipient'] = trim($matches[1]);
        // }
        
        $email->setCustomPrompt($customPrompt);
        $email->setSpecialDirectives($specialDirectives);
    }

    /**
     * Send email reply
     * 
     * @param Email $originalEmail Original email
     * @param AiResponse $aiResponse AI response
     * @return bool True on success, false on failure
     */
    public function sendReply(Email $originalEmail, AiResponse $aiResponse): bool
    {
        $emailConfig = $this->config->getEmailConfig();
        
        $mail = new PHPMailer(true);
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = $emailConfig['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $emailConfig['username'];
            $mail->Password = $emailConfig['password'];
            $mail->SMTPSecure = $emailConfig['encryption'];
            $mail->Port = $emailConfig['port'];
            $mail->CharSet = 'UTF-8';
            
            // Sender
            $mail->setFrom($emailConfig['username'], $emailConfig['from_name']);
            
            // Recipients
            $recipients = $this->determineRecipients($originalEmail, $aiResponse);
            
            foreach ($recipients['to'] as $to) {
                if ($this->isValidEmail($to)) {
                    $mail->addAddress($to);
                }
            }
            
            foreach ($recipients['cc'] as $cc) {
                if ($this->isValidEmail($cc)) {
                    $mail->addCC($cc);
                }
            }
            
            // Add Reply-To header
            $mail->addReplyTo($emailConfig['username'], $emailConfig['from_name']);
            
            // Add In-Reply-To and References headers for threading
            if ($originalEmail->getMessageId()) {
                $mail->addCustomHeader('In-Reply-To', $originalEmail->getMessageId());
                
                $references = $originalEmail->getReferences() ?? '';
                if (!empty($references)) {
                    $references .= ' ';
                }
                $references .= $originalEmail->getMessageId();
                
                $mail->addCustomHeader('References', $references);
            }
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Re: ' . $originalEmail->getSubject();
            $mail->Body = $this->formatHtmlReply($aiResponse->getContent(), $originalEmail);
            $mail->AltBody = $this->formatTextReply($aiResponse->getContent(), $originalEmail);
            
            // Log email content before sending
            $this->logger->info('Sending email with content:', [
                'to' => $recipients['to'],
                'cc' => $recipients['cc'],
                'subject' => $mail->Subject,
                'ai_response_content' => $aiResponse->getContent(),
                'html_body' => $mail->Body,
                'text_body' => $mail->AltBody
            ]);
            
            $mail->send();
            $this->logger->info('Email sent successfully.', [
                'to' => $recipients['to'],
                'cc' => $recipients['cc'],
                'subject' => $mail->Subject
            ]);
            
            return true;
        } catch (PHPMailerException $e) {
            $this->logger->error('Error sending email: ' . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Determine recipients for reply
     * 
     * @param Email $originalEmail Original email
     * @param AiResponse $aiResponse AI response
     * @return array Array with 'to' and 'cc' keys
     */
    private function determineRecipients(Email $originalEmail, AiResponse $aiResponse): array
    {
        $to = [];
        $cc = [];
        
        // Check if AI response has special instructions for recipients
        if ($this->config->allowAiRecipients() && $aiResponse->hasSpecialInstruction('override_recipients') && 
            !empty($aiResponse->getRecipients())) {
            // Use only the recipients specified in AI response if allowed
            $to = $this->validateEmails($aiResponse->getRecipients());
            $cc = $this->validateEmails($aiResponse->getCcRecipients());
        } else {
            // Default: Reply to all original recipients
            // Add original sender to recipients
            $to[] = $originalEmail->getFrom();
            
            // Add original TO recipients except our own email
            foreach ($originalEmail->getTo() as $recipient) {
                if ($recipient !== $this->config->getEmailConfig()['username']) {
                    $to[] = $recipient;
                }
            }
            
            // Add original CC recipients
            $cc = $originalEmail->getCc();
            
            // Add any additional recipients from AI response
            if ($this->config->allowAiRecipients() && !empty($aiResponse->getRecipients())) {
                $to = array_merge($to, $this->validateEmails($aiResponse->getRecipients()));
            }
            
            // Add any additional CC recipients from AI response
            if ($this->config->allowAiRecipients() && !empty($aiResponse->getCcRecipients())) {
                $cc = array_merge($cc, $this->validateEmails($aiResponse->getCcRecipients()));
            }
        }
        
        // Check for special directives in the original email
        if ($originalEmail->hasSpecialDirective('send_to_only')) {
            $singleRecipient = $originalEmail->getSpecialDirective('send_to_only');
            if ($this->isValidEmail($singleRecipient)) {
                $to = [$singleRecipient];
                $cc = [];
            }
        }
        
        if ($originalEmail->hasSpecialDirective('add_recipient')) {
            $additionalRecipient = $originalEmail->getSpecialDirective('add_recipient');
            if ($this->isValidEmail($additionalRecipient)) {
                $cc[] = $additionalRecipient;
            }
        }
        
        // Remove duplicates
        $to = array_unique($to);
        $cc = array_unique($cc);
        
        // Remove any CC recipients that are already in TO
        $cc = array_diff($cc, $to);
        
        return [
            'to' => $to,
            'cc' => $cc
        ];
    }

    /**
     * Validate email addresses against allowed domains
     * 
     * @param array $emails Array of email addresses
     * @return array Array of valid email addresses
     */
    private function validateEmails(array $emails): array
    {
        $validEmails = [];
        
        foreach ($emails as $email) {
            if ($this->isValidEmail($email)) {
                $validEmails[] = $email;
            }
        }
        
        return $validEmails;
    }

    /**
     * Check if email is valid and from allowed domain
     * 
     * @param string $email Email address
     * @return bool True if valid, false otherwise
     */
    private function isValidEmail(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        // Always allow emails from allowed domains
        if ($this->isAllowedDomain($email)) {
            return true;
        }
        
        // For external emails, only allow if they were in the original recipients
        return true;
    }

    /**
     * Check if email is from allowed domain
     * 
     * @param string $email Email address
     * @return bool True if allowed, false otherwise
     */
    private function isAllowedDomain(string $email): bool
    {
        $domain = substr(strrchr($email, "@"), 1);
        
        foreach ($this->allowedDomains as $allowedDomain) {
            if ($domain === $allowedDomain) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Format HTML reply
     * 
     * @param string $content AI response content
     * @param Email $originalEmail Original email
     * @return string Formatted HTML reply
     */
    private function formatHtmlReply(string $content, Email $originalEmail): string
    {
        // Markdown'ı HTML'e dönüştür
        $htmlContent = $this->parsedown->text($content);
        
        $html = '<div style="font-family: Arial, sans-serif; margin-bottom: 20px;">';
        $html .= $htmlContent; // Markdown'dan dönüştürülen HTML içeriği
        $html .= '</div>';
        
        if ($this->includeThreadEmails) {
            $html .= '<div style="border-top: 1px solid #ccc; margin-top: 20px; padding-top: 10px; color: #777;">';
            $html .= '<p><strong>From:</strong> ' . htmlspecialchars($originalEmail->getFromName()) . ' &lt;' . htmlspecialchars($originalEmail->getFrom()) . '&gt;<br>';
            $html .= '<strong>Sent:</strong> ' . date('Y-m-d H:i:s') . '<br>';
            $html .= '<strong>To:</strong> ' . htmlspecialchars(implode(', ', $originalEmail->getTo())) . '<br>';
            
            if (!empty($originalEmail->getCc())) {
                $html .= '<strong>Cc:</strong> ' . htmlspecialchars(implode(', ', $originalEmail->getCc())) . '<br>';
            }
            
            $html .= '<strong>Subject:</strong> ' . htmlspecialchars($originalEmail->getSubject()) . '</p>';
            
            if (!empty($originalEmail->getHtmlBody())) {
                $html .= '<div style="margin-top: 20px; padding: 10px; border-left: 4px solid #ccc;">';
                $html .= $originalEmail->getHtmlBody();
                $html .= '</div>';
            } else {
                $html .= '<div style="margin-top: 20px; padding: 10px; border-left: 4px solid #ccc; white-space: pre-wrap;">';
                $html .= htmlspecialchars($originalEmail->getBody());
                $html .= '</div>';
            }
            
            $html .= '</div>';
        }
        
        return $html;
    }

    /**
     * Format text reply
     * 
     * @param string $content AI response content
     * @param Email $originalEmail Original email
     * @return string Formatted text reply
     */
    private function formatTextReply(string $content, Email $originalEmail): string
    {
        // HTML etiketlerini kaldır
        $text = strip_tags($content) . "\n\n";
        
        if ($this->includeThreadEmails) {
            $text .= "-----Original Message-----\n";
            $text .= "From: " . $originalEmail->getFromName() . " <" . $originalEmail->getFrom() . ">\n";
            $text .= "Sent: " . date('Y-m-d H:i:s') . "\n";
            $text .= "To: " . implode(', ', $originalEmail->getTo()) . "\n";
            
            if (!empty($originalEmail->getCc())) {
                $text .= "Cc: " . implode(', ', $originalEmail->getCc()) . "\n";
            }
            
            $text .= "Subject: " . $originalEmail->getSubject() . "\n\n";
            $text .= $originalEmail->getBody();
        }
        return $text;
    }

    /**
     * Get IMAP mailbox string
     * 
     * @param array $config IMAP configuration
     * @return string IMAP mailbox string
     */
    private function getImapMailbox(array $config): string
    {
        return '{' . $config['host'] . ':' . $config['port'] . '/imap/' . $config['encryption'] . '}INBOX';
    }

    /**
     * Send error notification
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $message Error message
     * @return bool True on success, false on failure
     */
    public function sendErrorNotification(string $to, string $subject, string $message): bool
    {
        $emailConfig = $this->config->getEmailConfig();
        
        $mail = new PHPMailer(true);
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = $emailConfig['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $emailConfig['username'];
            $mail->Password = $emailConfig['password'];
            $mail->SMTPSecure = $emailConfig['encryption'];
            $mail->Port = $emailConfig['port'];
            $mail->CharSet = 'UTF-8';
            
            // Sender
            $mail->setFrom($emailConfig['username'], $emailConfig['from_name']);
            
            // Recipient
            $mail->addAddress($to);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = '<div style="font-family: Arial, sans-serif;">' . nl2br(htmlspecialchars($message)) . '</div>';
            $mail->AltBody = $message;
            
            $mail->send();
            $this->logger->info('Error notification sent', ['to' => $to, 'subject' => $subject]);
            
            return true;
        } catch (PHPMailerException $e) {
            $this->logger->error('Error sending notification: ' . $mail->ErrorInfo);
            return false;
        }
    }
    
    /**
     * Mark email as read
     * 
     * @param string $messageId Message ID
     * @return bool True on success, false on failure
     */
    public function markEmailAsRead(string $messageId): bool
    {
        $imapConfig = $this->config->getImapConfig();
        $mailbox = $this->getImapMailbox($imapConfig);
        
        try {
            // Connect to the mailbox
            $imapStream = imap_open($mailbox, $imapConfig['username'], $imapConfig['password']);
            
            if (!$imapStream) {
                $this->logger->error('IMAP connection failed: ' . imap_last_error());
                return false;
            }
            
            // Get all messages and filter by Message-ID
            $search = [];
            $messageCount = imap_num_msg($imapStream);
            
            for ($i = 1; $i <= $messageCount; $i++) {
                $headers = imap_headerinfo($imapStream, $i);
                if (!$headers) {
                    continue;
                }
                if (isset($headers->message_id) && trim($headers->message_id) === $messageId) {
                    $search[] = $i;
                }
            }
            
            if ($search && count($search) > 0) {
                imap_setflag_full($imapStream, (string)$search[0], "\\Seen");
                $this->logger->info('Email marked as read', ['message_id' => $messageId]);
            }
            
            imap_close($imapStream);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error marking email as read: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete email
     * 
     * @param int $emailId Email ID
     * @param resource $imapStream IMAP stream
     * @return bool True on success, false on failure
     */
    private function deleteEmail(int $emailId, $imapStream): bool
    {
        try {
            // Mark email for deletion
            imap_delete($imapStream, (string)$emailId);
            
            // Expunge deleted messages
            imap_expunge($imapStream);
            
            $this->logger->info('Email deleted', ['email_id' => $emailId]);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error deleting email: ' . $e->getMessage());
            return false;
        }
    }
}