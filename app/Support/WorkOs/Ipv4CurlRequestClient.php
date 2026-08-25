<?php

namespace App\Support\WorkOs;

use WorkOS\Client;
use WorkOS\Exception\GenericException;
use WorkOS\RequestClient\RequestClientInterface;

/**
 * WorkOS SDK HTTP client that forces IPv4, short timeouts, and brief retries.
 *
 * Docker bridges on the production host often have broken IPv6 egress. WorkOS
 * DNS returns A+AAAA, and the stock curl client hangs for ~60s on IPv6, which
 * stalls every authenticated page behind ValidateSessionWithWorkOS.
 *
 * Transient DNS / connect blips still happen on IPv4; retry a couple of times
 * before surfacing GenericException so session refresh survives brief flaps.
 */
class Ipv4CurlRequestClient implements RequestClientInterface
{
    private const MAX_ATTEMPTS = 3;

    /**
     * @param  array<int, string>|null  $headers
     * @param  array<string, mixed>|null  $params
     * @return array{0: string, 1: array<string, string>, 2: int}
     */
    public function request($method, $url, ?array $headers = null, ?array $params = null)
    {
        $headers ??= [];
        $opts = [
            \CURLOPT_RETURNTRANSFER => 1,
            \CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4,
            \CURLOPT_CONNECTTIMEOUT => 3,
            \CURLOPT_TIMEOUT => 15,
        ];

        switch ($method) {
            case Client::METHOD_GET:
                if (! empty($params)) {
                    $url .= '?'.http_build_query($params);
                }
                break;
            case Client::METHOD_POST:
                $headers[] = 'Content-Type: application/json';
                $opts[\CURLOPT_POST] = 1;
                if (! empty($params)) {
                    $opts[\CURLOPT_POSTFIELDS] = json_encode($params);
                }
                break;
            case Client::METHOD_DELETE:
                $opts[\CURLOPT_CUSTOMREQUEST] = 'DELETE';
                break;
            case Client::METHOD_PUT:
                $headers[] = 'Content-Type: application/json';
                $opts[\CURLOPT_CUSTOMREQUEST] = 'PUT';
                $opts[\CURLOPT_POST] = 1;
                if (! empty($params)) {
                    $opts[\CURLOPT_POSTFIELDS] = json_encode($params);
                }
                break;
            case Client::METHOD_PATCH:
                $headers[] = 'Content-Type: application/json';
                $opts[\CURLOPT_CUSTOMREQUEST] = 'PATCH';
                $opts[\CURLOPT_POST] = 1;
                if (! empty($params)) {
                    $opts[\CURLOPT_POSTFIELDS] = json_encode($params);
                }
                break;
        }

        $opts[\CURLOPT_HTTPHEADER] = $headers;
        $opts[\CURLOPT_URL] = $url;

        return $this->execute($opts);
    }

    /**
     * Curl transport failures that are worth a short retry (DNS / connect / timeout).
     */
    public static function isTransientCurlFailure(int $errno, string $message): bool
    {
        if (in_array($errno, [\CURLE_COULDNT_RESOLVE_HOST, \CURLE_COULDNT_CONNECT, \CURLE_OPERATION_TIMEDOUT], true)) {
            return true;
        }

        $haystack = strtolower($message);

        return str_contains($haystack, 'timed out')
            || str_contains($haystack, 'timeout')
            || str_contains($haystack, 'could not resolve')
            || str_contains($haystack, 'failed to connect')
            || str_contains($haystack, 'resolving timed out');
    }

    /**
     * @param  array<int, mixed>  $opts
     * @return array{0: string, 1: array<string, string>, 2: int}
     */
    private function execute(array $opts): array
    {
        $lastErrno = 0;
        $lastMessage = '';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $curl = curl_init();
            $responseHeaders = [];

            $opts[\CURLOPT_HEADERFUNCTION] = function ($curl, string $headerLine) use (&$responseHeaders): int {
                if (! str_contains($headerLine, ':')) {
                    return strlen($headerLine);
                }

                [$key, $value] = explode(':', trim($headerLine), 2);
                $responseHeaders[trim($key)] = trim($value);

                return strlen($headerLine);
            };

            curl_setopt_array($curl, $opts);
            $result = curl_exec($curl);

            if ($result !== false) {
                $statusCode = (int) curl_getinfo($curl, \CURLINFO_RESPONSE_CODE);
                curl_close($curl);

                return [$result, $responseHeaders, $statusCode];
            }

            $lastErrno = curl_errno($curl);
            $lastMessage = curl_error($curl);
            curl_close($curl);

            if ($attempt >= self::MAX_ATTEMPTS || ! self::isTransientCurlFailure($lastErrno, $lastMessage)) {
                break;
            }

            usleep(100_000 * $attempt);
        }

        throw new GenericException($lastMessage, ['curlErrno' => $lastErrno]);
    }
}
