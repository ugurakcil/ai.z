<?php

declare(strict_types=1);

namespace DigitalExchange\AiMailReply\Domain;

class AiResponse
{
    private string $content;
    private array $recipients = [];
    private array $ccRecipients = [];
    private array $specialInstructions = [];

    public function __construct(
        string $content,
        array $recipients = [],
        array $ccRecipients = [],
        array $specialInstructions = []
    ) {
        $this->content = $content;
        $this->recipients = $recipients;
        $this->ccRecipients = $ccRecipients;
        $this->specialInstructions = $specialInstructions;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function getCcRecipients(): array
    {
        return $this->ccRecipients;
    }

    public function getSpecialInstructions(): array
    {
        return $this->specialInstructions;
    }

    public function hasSpecialInstruction(string $key): bool
    {
        return isset($this->specialInstructions[$key]);
    }

    public function getSpecialInstruction(string $key): mixed
    {
        return $this->specialInstructions[$key] ?? null;
    }

    /**
     * Parse AI response to extract special instructions
     * 
     * @param string $aiResponseText The raw AI response text
     * @return self
     */
    public static function fromAiResponseText(string $aiResponseText): self
    {
        // Default values
        $content = $aiResponseText;
        $recipients = [];
        $ccRecipients = [];
        $specialInstructions = [];

        // Extract JSON instructions if present
        if (preg_match('/```json\s*(.*?)\s*```/s', $aiResponseText, $matches)) {
            $jsonData = json_decode($matches[1], true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                // Remove the JSON block from content
                $content = str_replace($matches[0], '', $aiResponseText);
                
                // Extract recipients if present
                if (isset($jsonData['recipients']) && is_array($jsonData['recipients'])) {
                    $recipients = $jsonData['recipients'];
                }
                
                // Extract CC recipients if present
                if (isset($jsonData['cc']) && is_array($jsonData['cc'])) {
                    $ccRecipients = $jsonData['cc'];
                }
                
                // Extract any other special instructions
                foreach ($jsonData as $key => $value) {
                    if (!in_array($key, ['recipients', 'cc'])) {
                        $specialInstructions[$key] = $value;
                    }
                }
            }
        } else {
            // Try to extract instructions using regex patterns
            // Example: "Cevabı sadece example@example.com'a gönder"
            if (preg_match('/cevab[ıi]\s+sadece\s+([^\s,;]+@[^\s,;]+)\'[ae]\s+gönder/i', $aiResponseText, $matches)) {
                $recipients = [trim($matches[1])];
                $ccRecipients = [];
                $specialInstructions['override_recipients'] = true;
            }
            
            // Example: "Şunu da ekle: user@example.com"
            if (preg_match('/şunu\s+da\s+ekle:\s+([^\s,;]+@[^\s,;]+)/i', $aiResponseText, $matches)) {
                $ccRecipients[] = trim($matches[1]);
            }
        }

        return new self(trim($content), $recipients, $ccRecipients, $specialInstructions);
    }
}