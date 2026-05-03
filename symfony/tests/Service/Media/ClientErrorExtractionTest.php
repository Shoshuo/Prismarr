<?php

namespace App\Tests\Service\Media;

use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Covers extractApiErrorMessage() — the helper each Media client uses to turn
 * an upstream error response into a human-readable message exposed via
 * getLastError(). Tested on RadarrClient (the canonical implementation —
 * Sonarr/Prowlarr/Jellyseerr/QBittorrent share the exact same logic).
 *
 * The method is private; we invoke it via reflection rather than weakening
 * the production API.
 */
#[AllowMockObjectsWithoutExpectations]
class ClientErrorExtractionTest extends TestCase
{
    private RadarrClient $client;
    private \ReflectionMethod $extract;

    protected function setUp(): void
    {
        $this->client = new RadarrClient(
            $this->createMock(ServiceInstanceProvider::class),
            $this->createMock(LoggerInterface::class),
            new ServiceHealthCache(new ArrayAdapter()),
        );

        $ref = new \ReflectionClass($this->client);
        $this->extract = $ref->getMethod('extractApiErrorMessage');
        $this->extract->setAccessible(true);
    }

    private function call(string $body, int $code = 400, string $curlError = ''): string
    {
        return (string) $this->extract->invoke($this->client, $body, $code, $curlError);
    }

    public function testExtractsErrorMessageKeyFromJsonObject(): void
    {
        $body = json_encode(['errorMessage' => 'QualityProfile [1] is in use.']);

        $this->assertSame('QualityProfile [1] is in use.', $this->call($body, 400));
    }

    public function testExtractsErrorKeyFromJsonObject(): void
    {
        $body = json_encode(['error' => 'Unauthorized']);

        $this->assertSame('Unauthorized', $this->call($body, 401));
    }

    public function testExtractsMessageKeyFromJsonObject(): void
    {
        $body = json_encode(['message' => 'Resource not found']);

        $this->assertSame('Resource not found', $this->call($body, 404));
    }

    public function testExtractsDetailKeyFromJsonObject(): void
    {
        $body = json_encode(['detail' => 'Conflict on tag id 42']);

        $this->assertSame('Conflict on tag id 42', $this->call($body, 409));
    }

    public function testJoinsValidationArrayMessages(): void
    {
        $body = json_encode([
            ['propertyName' => 'name',  'errorMessage' => 'Field A required'],
            ['propertyName' => 'value', 'errorMessage' => 'Field B required'],
        ]);

        $msg = $this->call($body, 400);

        $this->assertStringContainsString('Field A required', $msg);
        $this->assertStringContainsString('Field B required', $msg);
        $this->assertStringContainsString(';', $msg, 'Multiple validation errors should be joined with a separator');
    }

    public function testReturnsShortRawBodyWhenNotJson(): void
    {
        $body = 'Server is rebooting, try again later';

        $this->assertSame('Server is rebooting, try again later', $this->call($body, 503));
    }

    public function testFallsBackToCurlErrorWhenBodyEmpty(): void
    {
        $this->assertSame(
            'Connection refused',
            $this->call('', 0, 'Connection refused')
        );
    }

    public function testFallsBackToHttpCodeWhenNoBodyAndNoCurlError(): void
    {
        $this->assertSame('HTTP 500', $this->call('', 500, ''));
    }

    public function testIgnoresBodyOver200CharsAndFallsBackToHttpCode(): void
    {
        $body = str_repeat('A', 250);

        // Long non-JSON body shouldn't be returned verbatim — falls through.
        $this->assertSame('HTTP 502', $this->call($body, 502));
    }
}
