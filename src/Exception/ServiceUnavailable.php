<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Exception;

use IdSign\BusinessRegisters\Source;

/**
 * The source could not answer (transport error, timeout, outage, overload); try again later.
 *
 * It never means a negative business answer such as "not a VAT payer".
 */
final class ServiceUnavailable extends \RuntimeException implements ExceptionInterface
{
    /**
     * @param ?string $errorCode        error code of the source, if it sent one; ` (error code X)` is appended to the
     *                                  message only when it is a code token (`[A-Za-z0-9_.:-]`, 1–64 chars); it is
     *                                  always kept raw here
     * @param bool    $connectionFailed the source was not reached: the request failed in transport (a timeout, a refused,
     *                                  blocked or broken connection), or was not sent after repeated transport failures;
     *                                  false when the source answered, even with an error status or code
     */
    public function __construct(
        string $message,
        public readonly Source $source,
        public readonly ?string $errorCode = null,
        ?\Throwable $previous = null,
        public readonly bool $connectionFailed = false,
    ) {
        // Only a code-like token goes into the message, so free text from a response never reaches logs.
        $isToken = null !== $errorCode && 1 === preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $errorCode);
        parent::__construct($isToken ? \sprintf('%s (error code %s)', $message, $errorCode) : $message, 0, $previous);
    }
}
