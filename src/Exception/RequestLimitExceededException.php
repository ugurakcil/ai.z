<?php

declare(strict_types=1);

namespace DigitalExchange\AiMailReply\Exception;

use Exception;

class RequestLimitExceededException extends Exception
{
    // Özel istisna sınıfı, günlük istek limiti aşıldığında fırlatılır
}