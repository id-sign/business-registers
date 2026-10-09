<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Internal;

use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Source;

/**
 * Stops sequential requests to one source after a number of connection failures in a row, so an unreachable source
 * costs that many timeouts instead of one per request. Any other outcome (an answer, an error status or code, an
 * invalid response) resets the count. One guard serves one bulk call.
 *
 * @internal
 */
final class ConnectionGuard
{
    private int $failuresInARow = 0;

    private ?ServiceUnavailable $lastFailure = null;

    /**
     * @param int<1, max> $limit connection failures in a row after which no further request is sent
     */
    public function __construct(
        private readonly Source $source,
        private readonly int $limit,
    ) {
    }

    /**
     * Runs $request unless the limit was reached; then throws a ServiceUnavailable naming $subject, flagged as a
     * connection failure, with the last failure as the previous exception.
     *
     * @template T
     *
     * @param \Closure(): T $request
     * @param string        $subject what the request is for, used in the exception message
     *
     * @return T
     *
     * @throws ServiceUnavailable when not sent, and whatever $request throws
     * @throws InvalidInput
     * @throws InvalidResponse
     */
    public function call(\Closure $request, string $subject): mixed
    {
        if ($this->failuresInARow >= $this->limit) {
            throw new ServiceUnavailable(\sprintf('%s request for %s was not sent after %d connection failures in a row', strtoupper($this->source->value), $subject, $this->limit), $this->source, previous: $this->lastFailure, connectionFailed: true);
        }

        try {
            $result = $request();
        } catch (ServiceUnavailable $e) {
            $this->failuresInARow = $e->connectionFailed ? $this->failuresInARow + 1 : 0;
            $this->lastFailure = $e;

            throw $e;
        } catch (\Throwable $e) {
            $this->failuresInARow = 0;

            throw $e;
        }

        $this->failuresInARow = 0;

        return $result;
    }
}
