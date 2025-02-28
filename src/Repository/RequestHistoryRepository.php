<?php

declare(strict_types=1);

namespace DigitalExchange\AiMailReply\Repository;

use DigitalExchange\AiMailReply\Exception\RequestLimitExceededException;

class RequestHistoryRepository
{
    private string $historyFile;
    private int $dailyLimit;
    private array $requestHistory = [];

    public function __construct(string $historyFile, int $dailyLimit)
    {
        $this->historyFile = $historyFile;
        $this->dailyLimit = $dailyLimit;
        $this->loadRequestHistory();
    }

    /**
     * Load request history from JSON file
     */
    private function loadRequestHistory(): void
    {
        if (file_exists($this->historyFile)) {
            $content = file_get_contents($this->historyFile);
            $this->requestHistory = json_decode($content, true) ?? [];
        } else {
            // Create directory if it doesn't exist
            $directory = dirname($this->historyFile);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            
            $this->requestHistory = [];
            $this->saveRequestHistory();
        }
    }

    /**
     * Save request history to JSON file
     */
    private function saveRequestHistory(): void
    {
        file_put_contents($this->historyFile, json_encode($this->requestHistory, JSON_PRETTY_PRINT));
    }

    /**
     * Check if email has exceeded daily request limit
     * 
     * @param string $email Email address to check
     * @return bool True if limit is exceeded, false otherwise
     */
    public function hasExceededLimit(string $email): bool
    {
        $this->cleanupOldRequests();
        
        $count = $this->getRequestCount($email);
        return $count >= $this->dailyLimit;
    }

    /**
     * Get request count for an email in the last 24 hours
     * 
     * @param string $email Email address to check
     * @return int Number of requests
     */
    public function getRequestCount(string $email): int
    {
        if (!isset($this->requestHistory[$email])) {
            return 0;
        }
        
        $count = 0;
        $oneDayAgo = time() - 86400; // 24 hours in seconds
        
        foreach ($this->requestHistory[$email] as $timestamp) {
            if ($timestamp >= $oneDayAgo) {
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Record a new request for an email
     * 
     * @param string $email Email address
     * @throws RequestLimitExceededException If daily limit is exceeded
     */
    public function recordRequest(string $email): void
    {
        if ($this->hasExceededLimit($email)) {
            throw new RequestLimitExceededException(
                "Daily request limit of {$this->dailyLimit} exceeded for {$email}"
            );
        }
        
        if (!isset($this->requestHistory[$email])) {
            $this->requestHistory[$email] = [];
        }
        
        $this->requestHistory[$email][] = time();
        $this->saveRequestHistory();
    }

    /**
     * Remove requests older than 24 hours
     */
    private function cleanupOldRequests(): void
    {
        $oneDayAgo = time() - 86400; // 24 hours in seconds
        $modified = false;
        
        foreach ($this->requestHistory as $email => $timestamps) {
            $newTimestamps = array_filter($timestamps, function ($timestamp) use ($oneDayAgo) {
                return $timestamp >= $oneDayAgo;
            });
            
            if (count($newTimestamps) !== count($timestamps)) {
                $this->requestHistory[$email] = array_values($newTimestamps);
                $modified = true;
            }
            
            // Remove empty entries
            if (empty($this->requestHistory[$email])) {
                unset($this->requestHistory[$email]);
                $modified = true;
            }
        }
        
        if ($modified) {
            $this->saveRequestHistory();
        }
    }
}