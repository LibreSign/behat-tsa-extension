<?php

declare(strict_types=1);

namespace LibreSign\Behat\TsaExtension\Tests\Fixtures;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use RuntimeException;

final class FeatureContext implements Context
{
    #[Given('the local TSA answers a timestamp request')]
    public function assertTimestampResponse(): void
    {
        $endpoint = getenv('LIBRESIGN_TSA_URL');
        $expectedPath = getenv('TSA_TEST_PATH') ?: '/tsr';
        $url = is_string($endpoint) ? parse_url($endpoint) : false;
        if (!is_array($url)
            || ($url['scheme'] ?? '') !== 'http'
            || ($url['host'] ?? '') !== '127.0.0.1'
            || ($url['port'] ?? 0) <= 0
            || ($url['path'] ?? '') !== $expectedPath
        ) {
            throw new RuntimeException('Dynamic TSA endpoint was not published: ' . var_export($endpoint, true));
        }

        $payload = tempnam(sys_get_temp_dir(), 'tsa-fixture-');
        $query = tempnam(sys_get_temp_dir(), 'tsa-fixture-');
        $reply = tempnam(sys_get_temp_dir(), 'tsa-fixture-');
        if ($payload === false || $query === false || $reply === false) {
            throw new RuntimeException('Could not create temporary timestamp fixtures');
        }

        try {
            file_put_contents($payload, 'LibreSign RFC 3161 smoke test');
            exec(sprintf('openssl ts -query -data %s -sha256 -cert -out %s 2>&1', escapeshellarg($payload), escapeshellarg($query)), $output, $status);
            if ($status !== 0) {
                throw new RuntimeException('OpenSSL query failed: ' . implode("\n", $output));
            }

            $request = file_get_contents($query);
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/timestamp-query',
                'content' => $request,
                'timeout' => 6,
                'ignore_errors' => true,
            ]]);
            $response = file_get_contents($endpoint, false, $context);
            $headers = $http_response_header ?? [];
            if ($response === false || !str_contains($headers[0] ?? '', '200')) {
                throw new RuntimeException('TSA HTTP request failed: ' . implode(' ', $headers));
            }

            if (!in_array('Content-Type: application/timestamp-reply', $headers, true)) {
                throw new RuntimeException('Unexpected TSA content type: ' . implode(' ', $headers));
            }

            file_put_contents($reply, $response);
            $output = [];
            exec(sprintf('openssl ts -reply -in %s -text 2>&1', escapeshellarg($reply)), $output, $status);
            if ($status !== 0 || !str_contains(implode("\n", $output), 'Status: Granted.')) {
                throw new RuntimeException('Invalid RFC 3161 response: ' . implode("\n", $output));
            }
        } finally {
            @unlink($payload);
            @unlink($query);
            @unlink($reply);
        }
    }
}
