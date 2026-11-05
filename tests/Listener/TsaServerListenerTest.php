<?php

declare(strict_types=1);

namespace LibreSign\Behat\TsaExtension\Tests\Listener;

use Behat\Testwork\EventDispatcher\Event\AfterSuiteTested;
use Behat\Testwork\EventDispatcher\Event\BeforeSuiteTested;
use LibreSign\Behat\TsaExtension\Listener\TsaServerListener;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TsaServerListenerTest extends TestCase
{
    private string $workDir = '';

    protected function tearDown(): void
    {
        if ($this->workDir !== '' && is_dir($this->workDir)) {
            $this->deleteTree($this->workDir);
        }

        $this->workDir = '';
    }

    public function testSubscribedEvents(): void
    {
        $events = TsaServerListener::getSubscribedEvents();

        self::assertArrayHasKey(BeforeSuiteTested::BEFORE, $events);
        self::assertArrayHasKey(AfterSuiteTested::AFTER, $events);
    }

    public function testPrepareTsaArtifactsWritesCompatibleOpenSslConfig(): void
    {
        $listener = new TsaServerListener(true, '127.0.0.1', 0, '/tsr', '1.2.3.4.1', 'LIBRESIGN_TSA_URL', false);
        $reflection = new ReflectionClass($listener);

        $this->workDir = sys_get_temp_dir() . '/behat-tsa-extension-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0700, true);

        $workDirProperty = $reflection->getProperty('workDir');
        $workDirProperty->setValue($listener, $this->workDir);

        $prepareMethod = $reflection->getMethod('prepareTsaArtifacts');
        $prepareMethod->invoke($listener);

        $config = file_get_contents($this->workDir . '/tsa/openssl-tsa.cnf');

        self::assertIsString($config);
        self::assertStringNotContainsString('openssl_conf = openssl_init', $config);
        self::assertStringContainsString('[tsa]', $config);
        self::assertStringContainsString('signer_digest = sha256', $config);
        self::assertStringContainsString('ess_cert_id_alg = sha256', $config);
        self::assertStringContainsString('digests = sha1,sha256,sha384,sha512', $config);
    }

    public function testSuiteLifecycleRestoresExistingEnvironmentVariable(): void
    {
        $envVar = 'LIBRESIGN_TSA_RESTORE_TEST';
        $previous = getenv($envVar);
        $expected = 'http://original.example/tsa';
        putenv($envVar . '=' . $expected);

        $listener = new TsaServerListener(true, '127.0.0.1', 0, '/tsr', '1.2.3.4.1', $envVar, false);
        $before = (new ReflectionClass(BeforeSuiteTested::class))->newInstanceWithoutConstructor();
        $after = (new ReflectionClass(AfterSuiteTested::class))->newInstanceWithoutConstructor();

        try {
            $listener->beforeSuite($before);
            self::assertStringStartsWith('http://127.0.0.1:', (string) getenv($envVar));

            $listener->afterSuite($after);
            self::assertSame($expected, getenv($envVar));
        } finally {
            $listener->afterSuite($after);
            if ($previous === false) {
                putenv($envVar);
            } else {
                putenv($envVar . '=' . $previous);
            }
        }
    }

    private function deleteTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->deleteTree($path . '/' . $entry);
        }

        @rmdir($path);
    }
}
