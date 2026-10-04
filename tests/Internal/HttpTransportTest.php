<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Internal;

use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Internal\HttpTransport;
use IdSign\BusinessRegisters\Source;
use IdSign\BusinessRegisters\Tests\Double\CountingHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(HttpTransport::class)]
final class HttpTransportTest extends TestCase
{
    public function testRequestCarriesMethodUrlAndCallerOptionsWithTimeoutAndMaxDuration(): void
    {
        $response = new MockResponse('{}');
        $transport = new HttpTransport(new MockHttpClient($response), Source::Ares, 2.5);

        $transport->exchange(
            'POST',
            'https://example.test/search',
            ['headers' => ['Accept' => 'application/json'], 'body' => '{"a":1}'],
            'a company search',
        );

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://example.test/search', $response->getRequestUrl());
        $options = $response->getRequestOptions();
        self::assertSame(2.5, $options['timeout']);
        self::assertSame(2.5, $options['max_duration']);
        self::assertSame('{"a":1}', $options['body']);
        self::assertIsArray($options['headers']);
        self::assertContains('Accept: application/json', $options['headers']);
    }

    public function testExchangeReturnsStatusAndBodyOfSuccessfulResponse(): void
    {
        $transport = new HttpTransport(
            new MockHttpClient(new MockResponse('{"ok":true}', ['http_code' => 200])),
            Source::Vies,
            10.0,
        );

        self::assertSame(
            [200, '{"ok":true}'],
            $transport->exchange('GET', 'https://example.test/', [], 'VAT id CZ45274649'),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideErrorStatuses(): iterable
    {
        yield 'not found' => [404];
        yield 'server error' => [500];
        yield 'service unavailable' => [503];
        yield 'too many requests' => [429];
    }

    #[DataProvider('provideErrorStatuses')]
    public function testErrorStatusIsReturnedWithItsBodyAndNeverThrown(int $status): void
    {
        $transport = new HttpTransport(
            new MockHttpClient(new MockResponse('error body', ['http_code' => $status])),
            Source::Adis,
            10.0,
        );

        $response = $transport->send('GET', 'https://example.test/', [], 'IČO 45274649');

        self::assertSame([$status, 'error body'], $transport->read($response, 'IČO 45274649'));
    }

    public function testSendDoesNotReadTheResponseUntilReadIsCalled(): void
    {
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();
        $body = (static function () use ($events): \Generator {
            $events->append('body started');

            yield '{"ok":true}';
        })();
        $transport = new HttpTransport(
            new MockHttpClient(new MockResponse($body, ['http_code' => 200])),
            Source::Ares,
            10.0,
        );

        $response = $transport->send('GET', 'https://example.test/', [], 'IČO 45274649');

        self::assertCount(0, $events, 'send() must return before the response is read');

        self::assertSame([200, '{"ok":true}'], $transport->read($response, 'IČO 45274649'));
        self::assertCount(1, $events);
    }

    /**
     * @return iterable<string, array{Source, string}>
     */
    public static function provideSourceLabels(): iterable
    {
        yield 'ares' => [Source::Ares, 'ARES'];
        yield 'adis' => [Source::Adis, 'ADIS'];
        yield 'vies' => [Source::Vies, 'VIES'];
    }

    #[DataProvider('provideSourceLabels')]
    public function testTransportErrorWhileReadingIsServiceUnavailableOfTheGivenSource(Source $source, string $label): void
    {
        $transport = new HttpTransport(
            new MockHttpClient(new MockResponse(info: ['error' => 'host unreachable'])),
            $source,
            10.0,
        );
        $response = $transport->send('GET', 'https://example.test/', [], 'IČO 45274649');

        try {
            $transport->read($response, 'IČO 45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame($source, $e->source);
            self::assertNotNull($e->getPrevious());
            self::assertStringContainsString($label.' request for IČO 45274649 failed', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testTransportErrorThroughExchangeIsServiceUnavailableWithPreviousException(): void
    {
        $transport = new HttpTransport(
            new MockHttpClient(new MockResponse(info: ['error' => 'host unreachable'])),
            Source::Vies,
            10.0,
        );

        try {
            $transport->exchange('POST', 'https://example.test/', [], 'VAT id CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Vies, $e->source);
            self::assertNull($e->errorCode);
            self::assertInstanceOf(\Throwable::class, $e->getPrevious());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testTransportErrorRaisedWhenTheRequestIsIssuedIsServiceUnavailable(): void
    {
        $failure = new TransportException('connection refused');
        $client = new MockHttpClient(static function () use ($failure): never {
            throw $failure;
        });
        $transport = new HttpTransport($client, Source::Adis, 10.0);

        try {
            $transport->send('GET', 'https://example.test/', [], 'IČO 45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Adis, $e->source);
            self::assertSame($failure, $e->getPrevious());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testIdleTimeoutIsServiceUnavailable(): void
    {
        $silent = new MockResponse((static function (): \Generator {
            yield '';
        })(), ['http_code' => 200]);
        $transport = new HttpTransport(new MockHttpClient($silent), Source::Ares, 0.1);

        $this->expectException(ServiceUnavailable::class);

        $transport->exchange('GET', 'https://example.test/', [], 'IČO 45274649');
    }

    // --- sendInWaves ---

    /**
     * A plan is [status, body], with a Throwable as the body yielded as a transport error, or a Throwable thrown when
     * the request is issued; a request without a plan answers 200.
     *
     * @param array<int, array{int, string|\Throwable}|\Throwable> $plans by ordinal of the request
     */
    private static function client(array $plans = []): CountingHttpClient
    {
        return new CountingHttpClient(static function (int $ordinal) use ($plans): array {
            $plan = $plans[$ordinal] ?? [200, 'body '.$ordinal];
            if ($plan instanceof \Throwable) {
                throw $plan;
            }

            return $plan;
        });
    }

    /**
     * @param \ArrayObject<int, ResponseInterface> $issued receives every response a sender returns
     * @param \ArrayObject<int, string>            $log
     *
     * @return list<\Closure(): ResponseInterface>
     */
    private static function senders(HttpTransport $transport, int $count, \ArrayObject $issued, \ArrayObject $log): array
    {
        $senders = [];
        for ($n = 0; $n < $count; ++$n) {
            $senders[] = static function () use ($transport, $n, $issued, $log): ResponseInterface {
                $log->append('send '.$n);
                $response = $transport->send('GET', 'https://example.test/'.$n, [], 'request '.$n);
                $issued->append($response);

                return $response;
            };
        }

        return $senders;
    }

    /**
     * @param \ArrayObject<int, array{int, string}> $consumed
     * @param \ArrayObject<int, string>             $log
     *
     * @return \Closure(int, string): void
     */
    private static function collector(\ArrayObject $consumed, \ArrayObject $log): \Closure
    {
        return static function (int $status, string $body) use ($consumed, $log): void {
            $log->append('consume '.\count($consumed));
            $consumed->append([$status, $body]);
        };
    }

    /**
     * Every response that was issued and never read must have been cancelled (an unread response with an error
     * status makes the HTTP client throw from its destructor, at shutdown at the latest).
     *
     * @param \ArrayObject<int, ResponseInterface> $issued
     * @param list<int>                            $unread ordinals of the responses that were never read
     */
    private static function assertUnreadResponsesWereCanceled(CountingHttpClient $client, \ArrayObject $issued, array $unread): void
    {
        foreach ($unread as $ordinal) {
            self::assertTrue($issued[$ordinal]?->getInfo('canceled'), \sprintf('Response %d must be cancelled', $ordinal));
        }
        self::assertSame(\count($unread), $client->open, 'Every response must be read or cancelled');
    }

    /**
     * @return iterable<string, array{int, int<1, max>, int}> senders, concurrency, expected peak of open requests
     */
    public static function provideWaveShapes(): iterable
    {
        yield 'one at a time' => [7, 1, 1];
        yield 'waves of two' => [8, 2, 2];
        yield 'waves of three with a short last wave' => [7, 3, 3];
        yield 'waves of four' => [5, 4, 4];
        yield 'fewer senders than the concurrency' => [2, 4, 2];
        yield 'exactly one full wave' => [4, 4, 4];
    }

    /**
     * @param int<1, max> $concurrency
     */
    #[DataProvider('provideWaveShapes')]
    public function testNeverMoreRequestsAreOpenThanTheConcurrencyAllows(int $count, int $concurrency, int $expectedPeak): void
    {
        $client = self::client();
        /** @var \ArrayObject<int, ResponseInterface> $issued */
        $issued = new \ArrayObject();
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        /** @var \ArrayObject<int, array{int, string}> $consumed */
        $consumed = new \ArrayObject();
        $transport = new HttpTransport($client, Source::Ares, 10.0);

        $transport->sendInWaves(self::senders($transport, $count, $issued, $log), $concurrency, self::collector($consumed, $log));

        self::assertSame($expectedPeak, $client->maxOpen);
        self::assertSame($count, $client->issued);
        self::assertCount($count, $consumed);
        self::assertSame(0, $client->open);
    }

    public function testResponsesOfEveryStatusReachTheConsumerInSendingOrder(): void
    {
        /** @var \ArrayObject<int, ResponseInterface> $issued */
        $issued = new \ArrayObject();
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        /** @var \ArrayObject<int, array{int, string}> $consumed */
        $consumed = new \ArrayObject();
        $plans = [0 => [200, 'first'], 1 => [404, 'second'], 2 => [500, 'third'], 3 => [200, 'fourth']];
        $transport = new HttpTransport(self::client($plans), Source::Adis, 10.0);

        $transport->sendInWaves(self::senders($transport, 4, $issued, $log), 3, self::collector($consumed, $log));

        self::assertSame([[200, 'first'], [404, 'second'], [500, 'third'], [200, 'fourth']], $consumed->getArrayCopy());
    }

    /**
     * @return iterable<string, array{int, int<1, max>, list<string>}> senders, concurrency, expected order of events
     */
    public static function provideEventOrders(): iterable
    {
        yield 'concurrency one reads each response before the next request' => [3, 1, [
            'send 0', 'consume 0', 'send 1', 'consume 1', 'send 2', 'consume 2',
        ]];
        yield 'concurrency two sends a wave, consumes it, then sends the next' => [5, 2, [
            'send 0', 'send 1', 'consume 0', 'consume 1',
            'send 2', 'send 3', 'consume 2', 'consume 3',
            'send 4', 'consume 4',
        ]];
    }

    /**
     * @param int<1, max>  $concurrency
     * @param list<string> $expected
     */
    #[DataProvider('provideEventOrders')]
    public function testNextWaveIsSentOnlyAfterTheCurrentWaveIsConsumed(int $count, int $concurrency, array $expected): void
    {
        /** @var \ArrayObject<int, ResponseInterface> $issued */
        $issued = new \ArrayObject();
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        /** @var \ArrayObject<int, array{int, string}> $consumed */
        $consumed = new \ArrayObject();
        $transport = new HttpTransport(self::client(), Source::Ares, 10.0);

        $transport->sendInWaves(self::senders($transport, $count, $issued, $log), $concurrency, self::collector($consumed, $log));

        self::assertSame($expected, $log->getArrayCopy());
    }

    public function testNoSendersIssueNoRequestAndConsumeNothing(): void
    {
        $client = self::client();
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        /** @var \ArrayObject<int, array{int, string}> $consumed */
        $consumed = new \ArrayObject();
        $transport = new HttpTransport($client, Source::Ares, 10.0);

        $transport->sendInWaves([], 4, self::collector($consumed, $log));

        self::assertSame(0, $client->issued);
        self::assertCount(0, $consumed);
    }

    /**
     * @return iterable<string, array{int}> ordinal of the response whose consumer call fails
     */
    public static function provideFailurePositionsInAWave(): iterable
    {
        yield 'first of the wave' => [0];
        yield 'middle of the wave' => [1];
        yield 'last of the wave' => [2];
    }

    #[DataProvider('provideFailurePositionsInAWave')]
    public function testExceptionFromTheConsumerCancelsTheUnreadResponsesOfTheWaveAndSendsNoFurtherWave(int $failAt): void
    {
        $client = self::client(array_fill(0, 7, [500, 'unread sibling']));
        /** @var \ArrayObject<int, ResponseInterface> $issued */
        $issued = new \ArrayObject();
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        $transport = new HttpTransport($client, Source::Ares, 10.0);
        $failure = new \RuntimeException('consumer failed');
        $calls = 0;
        $consume = static function (int $status, string $body) use (&$calls, $failAt, $failure): void {
            if ($calls++ === $failAt) {
                throw $failure;
            }
        };

        try {
            $transport->sendInWaves(self::senders($transport, 7, $issued, $log), 3, $consume);
            self::fail('Expected the consumer exception was not thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame($failure, $e);
        }

        self::assertCount(3, $issued, 'No further wave may be sent');
        self::assertSame(3, $client->issued);
        self::assertUnreadResponsesWereCanceled($client, $issued, \array_slice([0, 1, 2], $failAt + 1));
    }

    #[DataProvider('provideFailurePositionsInAWave')]
    public function testTransportErrorWhileReadingCancelsTheUnreadResponsesOfTheWaveAndSendsNoFurtherWave(int $failAt): void
    {
        /** @var \ArrayObject<int, ResponseInterface> $issued */
        $issued = new \ArrayObject();
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        /** @var \ArrayObject<int, array{int, string}> $consumed */
        $consumed = new \ArrayObject();
        $plans = array_fill(0, 7, [500, 'unread sibling']);
        $plans[$failAt] = [200, new TransportException('connection reset')];
        $client = self::client($plans);
        $transport = new HttpTransport($client, Source::Ares, 10.0);

        try {
            $transport->sendInWaves(self::senders($transport, 7, $issued, $log), 3, self::collector($consumed, $log));
            self::fail('Expected ServiceUnavailable was not thrown.');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Ares, $e->source);
            self::assertNotNull($e->getPrevious());
        }

        self::assertCount($failAt, $consumed);
        self::assertCount(3, $issued, 'No further wave may be sent');
        self::assertSame(3, $client->issued);
        self::assertUnreadResponsesWereCanceled($client, $issued, \array_slice([0, 1, 2], $failAt + 1));
    }

    public function testFailureInASecondWaveCancelsOnlyTheUnreadRestOfThatWave(): void
    {
        $client = self::client(array_fill(0, 7, [500, 'unread sibling']));
        /** @var \ArrayObject<int, ResponseInterface> $issued */
        $issued = new \ArrayObject();
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        $transport = new HttpTransport($client, Source::Ares, 10.0);
        $failure = new \RuntimeException('consumer failed');
        $calls = 0;
        $consume = static function (int $status, string $body) use (&$calls, $failure): void {
            if (3 === $calls++) {
                throw $failure;
            }
        };

        try {
            $transport->sendInWaves(self::senders($transport, 7, $issued, $log), 3, $consume);
            self::fail('Expected the consumer exception was not thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame($failure, $e);
        }

        self::assertCount(6, $issued, 'The third wave may not be sent');
        self::assertSame(6, $client->issued);
        self::assertUnreadResponsesWereCanceled($client, $issued, [4, 5]);
    }

    public function testSenderFailureCancelsTheResponsesAlreadyIssuedInTheWaveAndStopsSending(): void
    {
        /** @var \ArrayObject<int, ResponseInterface> $issued */
        $issued = new \ArrayObject();
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        /** @var \ArrayObject<int, array{int, string}> $consumed */
        $consumed = new \ArrayObject();
        $refused = new TransportException('connection refused');
        $client = self::client([0 => [500, 'unread sibling'], 1 => $refused]);
        $transport = new HttpTransport($client, Source::Ares, 10.0);

        try {
            $transport->sendInWaves(self::senders($transport, 5, $issued, $log), 3, self::collector($consumed, $log));
            self::fail('Expected ServiceUnavailable was not thrown.');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Ares, $e->source);
            self::assertSame($refused, $e->getPrevious());
        }

        self::assertCount(1, $issued);
        self::assertSame(2, $client->issued, 'Nothing is sent after the failing request');
        self::assertCount(0, $consumed);
        self::assertUnreadResponsesWereCanceled($client, $issued, [0]);
    }
}
