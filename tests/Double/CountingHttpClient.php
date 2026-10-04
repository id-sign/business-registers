<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Double;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * MockHttpClient that counts the requests it issues and how many of them are open at once.
 *
 * A response body is a generator and a generator runs when its response is first read, so "open" is the number of
 * requests issued and not read yet, and "maxOpen" is its peak. A cancelled request stays open, because its generator
 * body never runs. A request whose answer callback throws is issued but never open.
 */
final class CountingHttpClient extends MockHttpClient
{
    /** Requests attempted. */
    public int $issued = 0;

    /** Requests issued and not read yet. */
    public int $open = 0;

    /** Peak of $open. */
    public int $maxOpen = 0;

    /**
     * $answer receives the ordinal of the request, the method, the URL and the options, and returns the status and
     * the body (a Throwable body is yielded as a transport error). It may throw when the request is issued.
     *
     * @param \Closure(int, string, string, array<string, mixed>): array{int, string|\Throwable} $answer
     */
    public function __construct(private readonly \Closure $answer)
    {
        parent::__construct($this->respond(...));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function respond(string $method, string $url, array $options): MockResponse
    {
        $ordinal = $this->issued;
        ++$this->issued;

        [$status, $body] = ($this->answer)($ordinal, $method, $url, $options);

        ++$this->open;
        $this->maxOpen = max($this->maxOpen, $this->open);
        $content = (function () use ($body): \Generator {
            --$this->open;

            yield $body;
        })();

        return new MockResponse($content, ['http_code' => $status]);
    }
}
