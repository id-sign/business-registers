<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Exception;

/**
 * The input is invalid or was rejected by the source; repeating the call will not help.
 */
final class InvalidInput extends \InvalidArgumentException implements ExceptionInterface
{
    /**
     * @param ?string $errorCode error code of the source, if it sent one (e.g. ARES `subKod`, VIES `error`);
     *                           ` (error code X)` is appended to the message only when it is a code token
     *                           (`[A-Za-z0-9_.:-]`, 1–64 chars); it is always kept raw here
     */
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        ?\Throwable $previous = null,
    ) {
        // Only a code-like token goes into the message, so free text from a response never reaches logs.
        $isToken = null !== $errorCode && 1 === preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $errorCode);
        parent::__construct($isToken ? \sprintf('%s (error code %s)', $message, $errorCode) : $message, 0, $previous);
    }
}
