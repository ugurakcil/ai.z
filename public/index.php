<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use DigitalExchange\AiMailReply\Config\AppConfig;
use DigitalExchange\AiMailReply\Service\EmailProcessor;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Configure logger
$logLevel = isset($_ENV['DEBUG']) && filter_var($_ENV['DEBUG'], FILTER_VALIDATE_BOOLEAN) ? Logger::INFO : Logger::ERROR;
$logger = new Logger('ai-mail-reply');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/app.log', $logLevel));

// Initialize application config
$config = new AppConfig();

// Function to output to terminal only if debug is enabled
$output = function($message) use ($config) { if ($config->isDebugEnabled()) { echo $message . "\n"; } };

try {
    // Initialize application config
    $config = new AppConfig();
    
    // Initialize email processor
    $emailProcessor = new EmailProcessor($config, $logger);
    
    // Process emails
    $emailProcessor->processUnseenEmails();
    
    // Log success message
    if ($config->isDebugEnabled()) {
        $logger->info('Email processing completed successfully');
    }
    $output("Email processing completed successfully");
} catch (Throwable $e) {
    $logger->error('Error processing emails: ' . $e->getMessage(), [
        'exception' => $e
    ]);
    echo "Error processing emails: " . $e->getMessage() . "\n"; // Always output errors
}