<?php

namespace App\Support\WorkOs;

use WorkOS\Client;
use WorkOS\Exception\GenericException;
use WorkOS\RequestClient\RequestClientInterface;

/**
 * WorkOS SDK HTTP client that forces IPv4 and short timeouts.
 *
 * Docker bridges on the production host often have broken IPv6 egress. WorkOS
 * DNS returns A+AAAA, and the stock curl client hangs for ~60s on IPv6, which
 * stalls every authenticated page behind ValidateSessionWithWorkOS.
 */
class Ipv4CurlRequestClient implements RequestClientInterface
{
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
     * @param  array<int, mixed>  $opts
     * @return array{0: string, 1: array<string, string>, 2: int}
     */
    private function execute(array $opts): array
    {
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

        if ($result === false) {
            $errno = curl_errno($curl);
            $msg = curl_error($curl);
            curl_close($curl);

            throw new GenericException($msg, ['curlErrno' => $errno]);
        }

        $statusCode = (int) curl_getinfo($curl, \CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [$result, $responseHeaders, $statusCode];
    }
}
