<?php
namespace Horde\Hordectl;

/**
 * Exception thrown when Horde bootstrap fails
 */
class HordeBootstrapException extends \Exception
{
    private ?\Throwable $originalException = null;

    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->originalException = $previous;
    }

    public function getOriginalException(): ?\Throwable
    {
        return $this->originalException;
    }
}
