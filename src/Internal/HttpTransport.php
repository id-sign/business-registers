<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Internal;

use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Source;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends requests of one source and reads their status and body; the status mapping stays with each client.
 *
 * @internal
 */
final readonly class HttpTransport
{
    /**
     * @param float $timeout seconds, applied as both the idle timeout and the maximum duration
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private Source $source,
        private float $timeout,
    ) {
    }

    /**
     * Issues the request without reading the response.
     *
     * @param array<string, mixed> $options
     * @param string               $subject what the request is for, used in the exception message
     *
     * @throws ServiceUnavailable
     */
    public function send(string $method, string $url, #[\SensitiveParameter] array $options, string $subject): ResponseInterface
    {
        $options['timeout'] = $this->timeout;
        $options['max_duration'] = $this->timeout;

        try {
            return $this->httpClient->request($method, $url, $options);
        } catch (TransportExceptionInterface $e) {
            throw $this->unavailable($e, $subject);
        }
    }

    /**
     * Reads the status and body of any status; only a transport error throws.
     *
     * @return array{int, string} HTTP status and body
     *
     * @throws ServiceUnavailable
     */
    public function read(ResponseInterface $response, string $subject): array
    {
        try {
            return [$response->getStatusCode(), $response->getContent(false)];
        } catch (TransportExceptionInterface $e) {
            throw $this->unavailable($e, $subject);
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{int, string} HTTP status and body
     *
     * @throws ServiceUnavailable
     */
    public function exchange(string $method, string $url, #[\SensitiveParameter] array $options, string $subject): array
    {
        return $this->read($this->send($method, $url, $options, $subject), $subject);
    }

    /**
     * Sends at most `$maxConcurrency` requests at a time and consumes their answers in sending order; the next wave
     * is sent only after the current one is consumed. On the first failure of a sender, a read or the consumer, the
     * unread responses of the wave are cancelled, no further wave is sent and the failure is rethrown.
     *
     * @param list<\Closure(): ResponseInterface> $senders        each issues one request through send()
     * @param int<1, max>                         $maxConcurrency
     * @param \Closure(int, string): void         $consume        receives the status and body of every response
     *
     * @throws ServiceUnavailable
     */
    public function sendInWaves(array $senders, int $maxConcurrency, \Closure $consume): void
    {
        foreach (array_chunk($senders, $maxConcurrency) as $wave) {
            $unread = [];
            try {
                foreach ($wave as $sender) {
                    $unread[] = $sender();
                }
                foreach ($unread as $index => $response) {
                    unset($unread[$index]);
                    [$status, $body] = $this->read($response, 'a batch');
                    $consume($status, $body);
                }
            } catch (\Throwable $e) {
                // an unread response with an error status would otherwise throw from its destructor
                foreach ($unread as $response) {
                    $response->cancel();
                }

                throw $e;
            }
        }
    }

    /**
     * @return int<1, max>
     *
     * @throws \InvalidArgumentException when the value is outside 1 … `$max`
     */
    public static function concurrency(int $value, int $max): int
    {
        if ($value < 1 || $value > $max) {
            throw new \InvalidArgumentException(\sprintf('maxConcurrency must be between 1 and %d, %d given', $max, $value));
        }

        return $value;
    }

    private function unavailable(TransportExceptionInterface $e, string $subject): ServiceUnavailable
    {
        return new ServiceUnavailable(\sprintf('%s request for %s failed: %s', strtoupper($this->source->value), $subject, $e->getMessage()), $this->source, previous: $e, connectionFailed: true);
    }
}
