<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Account\RegistrationChallenge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class RegistrationChallengeTest extends TestCase
{
    public function testValidRegistrationTokenChecksTheProviderWithBoundedUntracedRequest(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $url);
            parse_str($options['body'], $body);
            self::assertSame(['secret' => 'private-key', 'response' => 'challenge-token', 'remoteip' => '192.0.2.1'], $body);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(5.0, $options['max_duration']);
            self::assertFalse($options['extra']['trace_content']);

            return new MockResponse('{"success":true,"hostname":"example.com","action":"register"}');
        });

        self::assertTrue($this->challenge($http)->verify('challenge-token', '192.0.2.1'));
        self::assertSame(1, $http->getRequestsCount());
    }

    #[DataProvider('invalidTokens')]
    public function testMissingMalformedAndOversizedTokensNeverReachTheProvider(mixed $token): void
    {
        $http = new MockHttpClient();
        self::assertFalse($this->challenge($http)->verify($token, null));
        self::assertSame(0, $http->getRequestsCount());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidTokens(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'array' => [['token']];
        yield 'boolean' => [true];
        yield 'oversized' => [str_repeat('a', 2049)];
    }

    #[DataProvider('rejectedResponses')]
    public function testProviderResponsesFailClosed(string $body, int $status = 200): void
    {
        $http = new MockHttpClient(new MockResponse($body, ['http_code' => $status]));
        self::assertFalse($this->challenge($http)->verify('challenge-token', null));
    }

    /** @return iterable<string, array{0: string, 1?: int}> */
    public static function rejectedResponses(): iterable
    {
        yield 'rejected' => ['{"success":false}'];
        yield 'replayed' => ['{"success":false,"error-codes":["timeout-or-duplicate"]}'];
        yield 'string success' => ['{"success":"true","hostname":"example.com","action":"register"}'];
        yield 'wrong host' => ['{"success":true,"hostname":"attacker.example","action":"register"}'];
        yield 'wrong action' => ['{"success":true,"hostname":"example.com","action":"login"}'];
        yield 'missing metadata' => ['{"success":true}'];
        yield 'invalid JSON' => ['not-json'];
        yield 'invalid shape' => ['[]'];
        yield 'server failure' => ['{"success":true,"hostname":"example.com","action":"register"}', 503];
        yield 'redirect' => ['', 302];
    }

    public function testTransportErrorsRejectTheSubmission(): void
    {
        $http = new MockHttpClient(static fn () => throw new TransportException('unavailable'));
        self::assertFalse($this->challenge($http)->verify('challenge-token', null));
    }

    public function testAValidatedTokenIsCheckedAgainAndCannotReuseACachedSuccess(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{"success":true,"hostname":"example.com","action":"register"}'),
            new MockResponse('{"success":false,"error-codes":["timeout-or-duplicate"]}'),
        ]);
        $challenge = $this->challenge($http);
        self::assertTrue($challenge->verify('same-token', null));
        self::assertFalse($challenge->verify('same-token', null));
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testProductionRejectsMissingConfigurationAndPublishedDummyKeys(): void
    {
        $http = new MockHttpClient();
        foreach ([
            [null, 'secret', 'example.com'],
            ['public', null, 'example.com'],
            ['public', 'secret', null],
            ['1x00000000000000000000AA', 'secret', 'example.com'],
            ['public', '1x0000000000000000000000000000000AA', 'example.com'],
        ] as [$site, $secret, $hostname]) {
            $challenge = new RegistrationChallenge($http, true, $site, $secret, $hostname, 'prod');
            self::assertFalse($challenge->verify('challenge-token', null));
            self::assertNull($challenge->getSiteKey());
        }
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testExplicitlyDisabledChallengeKeepsLocalAndOfflineDevelopmentAvailable(): void
    {
        $http = new MockHttpClient();
        $challenge = new RegistrationChallenge($http, false, null, null, null, 'dev');
        self::assertFalse($challenge->isEnabled());
        self::assertNull($challenge->getSiteKey());
        self::assertTrue($challenge->verify(null, null));
        self::assertSame(0, $http->getRequestsCount());
    }

    private function challenge(MockHttpClient $http): RegistrationChallenge
    {
        return new RegistrationChallenge($http, true, 'public-key', 'private-key', 'example.com', 'prod');
    }
}
