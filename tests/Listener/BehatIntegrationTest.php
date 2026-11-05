<?php

declare(strict_types=1);

namespace LibreSign\Behat\TsaExtension\Tests\Listener;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class BehatIntegrationTest extends TestCase
{
    private string $temporaryRoot = '';

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/behat-tsa-test-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporaryRoot, 0700), 'Could not create isolated test directory');
    }

    protected function tearDown(): void
    {
        if ($this->temporaryRoot === '' || !is_dir($this->temporaryRoot)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->temporaryRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($this->temporaryRoot);
    }

    public function testBehatFourStartsRespondsAndCleansUp(): void
    {
        $before = $this->temporaryEntries();
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
        self::assertSame($before, $this->temporaryEntries(), 'TSA temporary files must be removed after the suite');
    }

    public function testCustomQuotedPathCanServeTimestampRequest(): void
    {
        [$exitCode, $output] = $this->runBehat(['TSA_TEST_PATH' => "/quoted'path"]);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('1 scenario (1 passed)', $output);
    }

    public function testStartupFailureIsDiagnosableAndCleansUp(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $address = stream_socket_get_name($socket, false);
        $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
        $before = $this->temporaryEntries();

        try {
            [$exitCode, $output] = $this->runBehat(['TSA_TEST_PORT' => (string) $port]);
        } finally {
            fclose($socket);
        }

        self::assertNotSame(0, $exitCode, $output);
        self::assertStringContainsString('Local TSA server did not become ready', $output);
        self::assertSame($before, $this->temporaryEntries(), 'Startup failure must remove temporary files');
    }

    public function testOpenSslBootstrapFailureCleansUp(): void
    {
        [$exitCode, $output] = $this->runBehat(['PATH' => '/missing-tsa-executable']);

        self::assertNotSame(0, $exitCode, $output);
        self::assertStringContainsString('Command failed', $output);
        self::assertSame([], $this->temporaryEntries(), 'Bootstrap errors must clean temporary files');
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
        $env = ['TMPDIR' => $this->temporaryRoot] + $env;
        foreach ($env as $name => $value) {
            $command = $name . '=' . escapeshellarg($value) . ' ' . $command;
        }

        exec($command, $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    /** @return list<string> */
    private function temporaryEntries(): array
    {
        $entries = [];
        foreach (['libresign-tsa-*', 'tsa-query-*', 'tsa-reply-*', 'tsa-fixture-*'] as $pattern) {
            array_push($entries, ...(glob($this->temporaryRoot . '/' . $pattern) ?: []));
        }
        sort($entries);

        return $entries;
    }
}
