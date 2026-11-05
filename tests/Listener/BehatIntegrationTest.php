<?php

declare(strict_types=1);

namespace LibreSign\Behat\TsaExtension\Tests\Listener;

use PHPUnit\Framework\TestCase;

final class BehatIntegrationTest extends TestCase
{
    public function testBehatFourStartsRespondsAndCleansUp(): void
    {
        $before = $this->temporaryDirectories();
        [$exitCode, $output] = $this->runBehat();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('1 scenario (1 passed)', $output);
        self::assertMatchesRegularExpression('~LIBRESIGN_TSA_URL=http://127\.0\.0\.1:[0-9]+/tsr~', $output);
        preg_match('~LIBRESIGN_TSA_URL=http://127\.0\.0\.1:([0-9]+)/tsr~', $output, $matches);
        $socket = @fsockopen('127.0.0.1', (int) $matches[1], $errorCode, $error, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
        }
        self::assertFalse($socket, 'The TSA process must stop after the suite');
        self::assertSame($before, $this->temporaryDirectories(), 'TSA temporary directories must be removed after the suite');
    }

    public function testStartupFailureIsDiagnosableAndCleansUp(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $address = stream_socket_get_name($socket, false);
        $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
        $before = $this->temporaryDirectories();

        try {
            [$exitCode, $output] = $this->runBehat(['TSA_TEST_PORT' => (string) $port]);
        } finally {
            fclose($socket);
        }

        self::assertNotSame(0, $exitCode, $output);
        self::assertStringContainsString('Local TSA server did not become ready', $output);
        self::assertSame($before, $this->temporaryDirectories(), 'Startup failure must remove temporary files');
    }

    /** @return array{int, string} */
    private function runBehat(array $env = []): array
    {
        $command = sprintf(
            '%s %s %s --config %s --format progress --fail-on-behat-deprecations 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 2) . '/vendor/bin/behat'),
            escapeshellarg(dirname(__DIR__) . '/Fixtures/tsa.feature'),
            escapeshellarg(dirname(__DIR__) . '/Fixtures/behat.php')
        );
        foreach ($env as $name => $value) {
            $command = $name . '=' . escapeshellarg($value) . ' ' . $command;
        }

        exec($command, $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    /** @return list<string> */
    private function temporaryDirectories(): array
    {
        $directories = glob(sys_get_temp_dir() . '/libresign-tsa-*', GLOB_ONLYDIR);
        sort($directories);

        return $directories;
    }
}
