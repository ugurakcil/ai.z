<?php

declare(strict_types=1);

namespace DigitalExchange\AiMailReply\Exception;

use Exception;

class InvalidEmailDomainException extends Exception
{
    // İzin verilmeyen e-posta domainlerinden gelen istekler için fırlatılır
}