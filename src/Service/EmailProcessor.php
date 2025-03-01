<?php

declare(strict_types=1);

namespace DigitalExchange\AiMailReply\Service;

use DigitalExchange\AiMailReply\Config\AppConfig;
use DigitalExchange\AiMailReply\Repository\RequestHistoryRepository;
use DigitalExchange\AiMailReply\Exception\RequestLimitExceededException;
use DigitalExchange\AiMailReply\Exception\InvalidEmailDomainException;
use DigitalExchange\AiMailReply\Domain\Email;
use Monolog\Logger;

class EmailProcessor
{
    private AppConfig $config;
    private Logger $logger;
    private EmailService $emailService;
    private OpenAiService $openAiService;
    private RequestHistoryRepository $requestHistoryRepository;

    public function __construct(AppConfig $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->emailService = new EmailService($config, $logger);
        $this->openAiService = new OpenAiService($config, $logger);
        $this->requestHistoryRepository = new RequestHistoryRepository(
            $config->getRequestHistoryFile(),
            $config->getDailyRequestLimit()
        );
    }

    /**
     * Process unseen emails
     */
    public function processUnseenEmails(): void
    {
        $this->logger->info('Starting to process unseen emails');
        
        // Get unseen emails
        $emails = $this->emailService->getUnseenEmails();
        
        if (empty($emails)) {
            $this->logger->info('No unseen emails to process');
            return;
        }
        
        $this->logger->info('Found ' . count($emails) . ' unseen emails');
        
        foreach ($emails as $email) {
            usleep(200000); // 0.2 seconds delay between each email processing
            // Açılan e-postaları okundu olarak işaretle
            $marked = $this->markEmailAsRead($email);

            if (!$marked) {
                $this->logger->warning('Failed to mark email as read', [
                    'from' => $email->getFrom(),
                    'subject' => $email->getSubject()
                ]);

                sleep(1);
                $this->markEmailAsRead($email); // try again // TODO: add Retry mechanism (max 3) and stop
            }

            try {
                // 1. Alıcı sayısı kontrolü
                if (count($email->getAllRecipients()) > $this->config->getMaxRecipients()) {
                    $this->logger->warning('Too many recipients', [
                        'from' => $email->getFrom(),
                        'subject' => $email->getSubject(),
                        'recipient_count' => count($email->getAllRecipients())
                    ]);
                    
                    // E-posta sil ve atla
                    $this->emailService->deleteEmailByMessageId($email->getMessageId());
                    continue;
                }
                
                // 2. Engellenen alıcılar kontrolü
                if ($this->hasBlockedRecipients($email)) {
                    $this->logger->info('Email has blocked recipients', [
                        'from' => $email->getFrom(),
                        'subject' => $email->getSubject()
                    ]);
                    
                    // E-postayı sil ve atla
                    $this->emailService->deleteEmailByMessageId($email->getMessageId());
                    continue;
                }
                
                // 3. Engellenen gönderen kontrolü
                if ($this->isBlockedSender($email->getFrom())) {
                    $this->emailService->deleteEmailByMessageId($email->getMessageId());
                    continue;
                }
                
                // 4. CC kontrolü - Eğer IGNORE_CC_EMAILS aktifse ve e-posta CC ile geldiyse
                if ($this->config->ignoreCcEmails() && $this->isEmailInCcOnly($email)) {
                    $this->logger->info('Email received as CC only, ignoring as per configuration', [
                        'from' => $email->getFrom(),
                        'subject' => $email->getSubject()
                    ]);
                    
                    // E-posta sil ve atla
                    $this->emailService->deleteEmailByMessageId($email->getMessageId());
                    continue;
                }
                
                // 5. İzin verilen e-posta adresleri kontrolü
                if (!$this->isAllowedReplyEmail($email->getFrom())) {
                    $this->logger->info('Email from unauthorized sender for reply', [
                        'from' => $email->getFrom(),
                        'subject' => $email->getSubject()
                    ]);
                    
                    // E-postayı sil ve atla
                    $this->emailService->deleteEmailByMessageId($email->getMessageId());
                    continue;
                }
                
                // Check if sender domain is allowed
                if (!$this->isAllowedDomain($email->getFrom())) {
                    $this->logger->warning('Email from unauthorized domain', [
                        'from' => $email->getFrom()
                    ]);
                    
                    $this->emailService->deleteEmailByMessageId($email->getMessageId());
                    continue;
                }
                
                // E-posta içeriğini temizle
                $cleanBody = $this->openAiService->cleanEmailContent($email->getBody());
                

                $this->logger->info('Processing email', [
                    'from' => $email->getFrom(),
                    'subject' => $email->getSubject(),
                    'body' => $cleanBody,
                    'thread_emails_count' => count($email->getThreadEmails()),
                    //'body_length' => strlen($email->getBody()),
                    //'body_first_100_chars' => substr($email->getBody(), 0, 100),
                    //'html_body_length' => strlen($email->getHtmlBody()),
                    //'html_body_first_100_chars' => substr($email->getHtmlBody(), 0, 100)
                ]);
                
                // Check request limit
                $this->requestHistoryRepository->recordRequest($email->getFrom());
                
                // Generate AI response
                $aiResponse = $this->openAiService->generateResponse($email);
                
                // Send reply
                $success = $this->emailService->sendReply($email, $aiResponse);
                
                if ($success) {
                    $this->logger->info('Email processed successfully', [
                        'from' => $email->getFrom(),
                        'subject' => $email->getSubject(),
                        'ai_response_content' => $aiResponse->getContent()
                    ]);
                    
                    // E-posta cevaplanınca sil
                    $this->emailService->deleteEmailByMessageId($email->getMessageId());
                    $this->logger->info('Email marked as read and deleted after processing', [
                        'message_id' => $email->getMessageId()
                    ]);
                } else {
                    $this->logger->error('Failed to send reply', [
                        'from' => $email->getFrom(),
                        'subject' => $email->getSubject()
                    ]);
                    
                    // Send error notification to sender
                    $this->sendErrorNotification(
                        $email->getFrom(),
                        "E-posta yanıtı gönderilemedi",
                        "Merhaba,\n\nE-postanıza yanıt gönderilirken bir hata oluştu. Lütfen daha sonra tekrar deneyin veya sistem yöneticisiyle iletişime geçin.\n\nSaygılarımızla,\nAi.Z"
                    );
                }
            } catch (RequestLimitExceededException $e) {
                $this->logger->warning('Request limit exceeded', [
                    'from' => $email->getFrom(),
                    'message' => $e->getMessage()
                ]);
                
                // Send limit notification to sender
                $this->sendErrorNotification(
                    $email->getFrom(),
                    "Günlük istek limitine ulaşıldı",
                    "Merhaba,\n\nGünlük istek limitine ulaştınız. Lütfen 24 saat sonra tekrar deneyin.\n\nSaygılarımızla,\nAi.Z"
                );
            } catch (InvalidEmailDomainException $e) {
                $this->logger->warning('Invalid email domain', [
                    'from' => $email->getFrom(),
                    'message' => $e->getMessage()
                ]);
                
                // No notification for unauthorized domains
            } catch (\Exception $e) {
                $this->logger->error('Error processing email', [
                    'from' => $email->getFrom() ?? 'unknown',
                    'subject' => $email->getSubject() ?? 'unknown',
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Send error notification to sender if email is available
                if ($email && $email->getFrom()) {
                    $this->sendErrorNotification(
                        $email->getFrom(),
                        "E-posta işlenirken hata oluştu",
                        "Merhaba,\n\nE-postanız işlenirken bir hata oluştu. Lütfen daha sonra tekrar deneyin veya sistem yöneticisiyle iletişime geçin.\n\nHata: " . $e->getMessage() . "\n\nSaygılarımızla,\nAi.Z"
                    );
                }
            }
        }
        
        $this->logger->info('Finished processing emails');
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
        
        foreach ($this->config->getAllowedDomains() as $allowedDomain) {
            if ($domain === $allowedDomain) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if email has blocked recipients
     * 
     * @param Email $email Email object
     * @return bool True if has blocked recipients, false otherwise
     */
    private function hasBlockedRecipients(Email $email): bool
    {
        $blockedRecipients = $this->config->getBlockedRecipients();
        
        if (empty($blockedRecipients)) {
            return false;
        }
        
        $recipients = $email->getAllRecipients();
        
        foreach ($recipients as $recipient) {
            foreach ($blockedRecipients as $blockedRecipient) {
                if ($recipient === $blockedRecipient) {
                    $this->logger->info('Blocked recipient found', [
                        'recipient' => $recipient
                    ]);
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if sender is allowed for reply
     * 
     * @param string $sender Sender email
     * @return bool True if allowed, false otherwise
     */
    private function isAllowedReplyEmail(string $sender): bool
    {
        $allowedReplyEmails = $this->config->getAllowedReplyEmails();
        
        // Eğer izin verilen e-posta adresleri tanımlanmamışsa, herkese yanıt ver
        if (empty($allowedReplyEmails) || $allowedReplyEmails === false) {
            return true;
        }
        
        // İzin verilen e-posta adreslerinden biri mi kontrol et
        foreach ($allowedReplyEmails as $allowedEmail) {
            if (strtolower(trim($sender)) === strtolower(trim($allowedEmail))) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if sender is blocked
     * 
     * @param string $sender Sender email
     * @return bool True if blocked, false otherwise
     */
    private function isBlockedSender(string $sender): bool
    {
        $blockedSenders = $this->config->getBlockedSenders();
        
        if (empty($blockedSenders)) {
            return false;
        }
        
        foreach ($blockedSenders as $blockedSender) {
            if ($sender === $blockedSender) {
                $this->logger->info('Blocked sender', [
                    'sender' => $sender
                ]);
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if email is only in CC field and not in TO field
     * 
     * @param Email $email Email object
     * @return bool True if email is only in CC, false otherwise
     */
    private function isEmailInCcOnly(Email $email): bool
    {
        $imapConfig = $this->config->getImapConfig();
        $aiEmailAddress = $imapConfig['username'];
        
        // TO alanında AI e-posta adresi var mı kontrol et
        $toRecipients = $email->getTo();
        foreach ($toRecipients as $recipient) {
            if (strtolower($recipient) === strtolower($aiEmailAddress)) {
                // TO alanında AI e-posta adresi varsa, CC değil
                return false;
            }
        }
        
        // TO alanında yoksa ve CC alanında varsa, sadece CC'de demektir
        $ccRecipients = $email->getCc();
        return in_array(strtolower($aiEmailAddress), array_map('strtolower', $ccRecipients));
    }
    
    /**
     * Mark email as read
     * 
     * @param Email $email Email object 
     * @return bool True if marked as read, false otherwise
     */
    private function markEmailAsRead(Email $email): bool
    {
        return $this->emailService->markEmailAsRead($email->getMessageId());
    }

    /**
     * Send error notification
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $message Error message
     */
    private function sendErrorNotification(string $to, string $subject, string $message): void
    {
        try {
            $this->emailService->sendErrorNotification($to, $subject, $message);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send error notification', [
                'to' => $to,
                'subject' => $subject,
                'message' => $e->getMessage()
            ]);
        }
    }
}