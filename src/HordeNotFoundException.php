<?php
declare(strict_types=1);
namespace Horde\Hordectl;
use Exception;

class HordeNotFoundException extends Exception
{
    public function __construct(string $message = "No Horde installation found", int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}