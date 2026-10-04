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
 * (s:Body/r:Response/r:statusSubjektu[2]/@dic).
 */
final class InvalidResponse extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        string $message,
        public readonly Source $source,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
