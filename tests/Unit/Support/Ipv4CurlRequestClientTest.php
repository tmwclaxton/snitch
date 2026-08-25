<?php

namespace Tests\Unit\Support;

use App\Support\WorkOs\Ipv4CurlRequestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Ipv4CurlRequestClientTest extends TestCase
{
    #[DataProvider('transientFailures')]
    public function test_detects_transient_curl_failures(int $errno, string $message): void
    {
        $this->assertTrue(Ipv4CurlRequestClient::isTransientCurlFailure($errno, $message));
    }

    #[DataProvider('nonTransientFailures')]
    public function test_rejects_non_transient_curl_failures(int $errno, string $message): void
    {
        $this->assertFalse(Ipv4CurlRequestClient::isTransientCurlFailure($errno, $message));
    }

    public function test_client_retries_transient_failures(): void
    {
        $clientPath = file_get_contents(base_path('app/Support/WorkOs/Ipv4CurlRequestClient.php'));

        $this->assertNotFalse($clientPath);
        $this->assertStringContainsString('MAX_ATTEMPTS = 3', $clientPath);
        $this->assertStringContainsString('isTransientCurlFailure', $clientPath);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function transientFailures(): array
    {
        return [
            'resolve timeout errno' => [\CURLE_OPERATION_TIMEDOUT, 'Resolving timed out after 3000 milliseconds'],
            'could not resolve host' => [\CURLE_COULDNT_RESOLVE_HOST, 'Could not resolve host: api.workos.com'],
            'connect timeout message' => [0, 'Failed to connect to api.workos.com port 443 after 3001 ms: Timeout was reached'],
            'couldnt connect errno' => [\CURLE_COULDNT_CONNECT, 'Failed to connect'],
        ];
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function nonTransientFailures(): array
    {
        return [
            'ssl error' => [\CURLE_SSL_CONNECT_ERROR, 'SSL connect error'],
            'http empty' => [0, 'Empty reply from server'],
        ];
    }
}
