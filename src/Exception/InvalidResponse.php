<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Exception;

use IdSign\BusinessRegisters\Source;

/**
 * The source answered with something the library cannot read: unparsable content,
 * a missing mandatory value or a value outside a closed set.
 *
 * The message names the key path and the expected type, never a value from the response,
 * for example "ARES: missing obchodniJmeno". The path style follows the source format:
 * JSON paths (ARES, VIES) are jq-style with 0-based list indices (zaznamy[0].sidlo.psc),
 * XML paths (ADIS) are XPath-style with 1-based positions and "@" for attributes
 * (s:Body/r:Response/r:statusSubjektu[2]/@dic). When the source sent an error code the library
 * cannot accept, the message may end with " (error code X)".
 */
final class InvalidResponse extends \RuntimeException implements ExceptionInterface
{
    /**
     * @param ?string $errorCode error code of the source, if it sent one; ` (error code X)` is appended to the message
     *                           only when it is a code token (`[A-Za-z0-9_.:-]`, 1–64 chars); it is always kept raw here
     */
    public function __construct(
        string $message,
        public readonly Source $source,
        public readonly ?string $errorCode = null,
        ?\Throwable $previous = null,
    ) {
        // Only a code-like token goes into the message, so free text from a response never reaches logs.
        $isToken = null !== $errorCode && 1 === preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $errorCode);
        parent::__construct($isToken ? \sprintf('%s (error code %s)', $message, $errorCode) : $message, 0, $previous);
    }
}
