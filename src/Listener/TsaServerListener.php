<?php

declare(strict_types=1);

namespace LibreSign\Behat\TsaExtension\Listener;

use Behat\Testwork\EventDispatcher\Event\AfterSuiteTested;
use Behat\Testwork\EventDispatcher\Event\BeforeSuiteTested;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class TsaServerListener implements EventSubscriberInterface
{
    private string $pid = '0';

    private string $workDir = '';

    private string $routerPath = '';

    private int $resolvedPort = 0;

    public function __construct(
        private readonly bool $enabled,
        private readonly string $host,
        private readonly int $port,
        private readonly string $path,
        private readonly string $policyOid,
        private readonly string $envVar,
        private readonly bool $verbose,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}|string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            BeforeSuiteTested::BEFORE => ['beforeSuite', 10],
            AfterSuiteTested::AFTER => ['afterSuite', -10],
        ];
    }

    public function beforeSuite(BeforeSuiteTested $event): void
    {
        if (!$this->enabled || $this->isRunning()) {
            return;
        }

        $this->resolvedPort = $this->port > 0 ? $this->port : $this->findOpenPort($this->host);
        $this->workDir = $this->makeWorkDir();
        $this->prepareTsaArtifacts();
        $this->routerPath = $this->writeRouterScript();
        $this->startServer();

        $tsaUrl = sprintf('http://%s:%d%s', $this->host, $this->resolvedPort, $this->normalizePath($this->path));
        putenv(sprintf('%s=%s', $this->envVar, $tsaUrl));

        if ($this->verbose) {
            fwrite(STDOUT, sprintf("[tsa-extension] %s=%s\n", $this->envVar, $tsaUrl));
        }
    }

    public function afterSuite(AfterSuiteTested $event): void
    {
        $this->stop();
    }

    private function startServer(): void
    {
        $cmd = sprintf(
            'php -S %s:%d -t %s %s > /dev/null 2>&1 & echo $!',
            escapeshellarg($this->host),
            $this->resolvedPort,
            escapeshellarg($this->workDir),
            escapeshellarg($this->routerPath)
        );

        $this->pid = trim((string) shell_exec($cmd));
        if ($this->pid === '' || !ctype_digit($this->pid)) {
            throw new RuntimeException('Failed to start local TSA server process.');
        }

        $attempts = 30;
        while ($attempts > 0) {
            usleep(100000);
            $socket = @fsockopen($this->host, $this->resolvedPort);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            $attempts--;
        }

        $this->stop();
        throw new RuntimeException('Local TSA server did not become ready in time.');
    }

    private function stop(): void
    {
        if ($this->isRunning()) {
            exec('kill ' . $this->pid);
        }

        $this->pid = '0';

        if ($this->workDir !== '' && is_dir($this->workDir)) {
            $this->deleteTree($this->workDir);
        }

        $this->workDir = '';
        $this->routerPath = '';

        putenv($this->envVar);
    }

    private function isRunning(): bool
    {
        if ($this->pid === '' || $this->pid === '0') {
            return false;
        }

        exec(sprintf('ps %d', (int) $this->pid), $result);

        return count($result) > 1;
    }

    private function makeWorkDir(): string
    {
        $dir = sys_get_temp_dir() . '/libresign-tsa-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create temporary directory for local TSA server.');
        }

        return $dir;
    }

    private function prepareTsaArtifacts(): void
    {
        $tsaDir = $this->workDir . '/tsa';
        $this->mkdirOrFail($tsaDir . '/private');
        $this->mkdirOrFail($tsaDir . '/certs');
        $this->mkdirOrFail($tsaDir . '/newcerts');

        file_put_contents($tsaDir . '/index.txt', '');
        file_put_contents($tsaDir . '/serial', "1000\n");

        $this->runOrFail(sprintf('openssl genrsa -out %s 2048', escapeshellarg($tsaDir . '/ca.key')));
        $this->runOrFail(sprintf(
            'openssl req -x509 -new -nodes -key %s -sha256 -days 365 -subj %s -out %s',
            escapeshellarg($tsaDir . '/ca.key'),
            escapeshellarg('/CN=LibreSign Local TSA CA'),
            escapeshellarg($tsaDir . '/ca.crt')
        ));

        $this->runOrFail(sprintf('openssl genrsa -out %s 2048', escapeshellarg($tsaDir . '/private/tsa.key')));
        $this->runOrFail(sprintf(
            'openssl req -new -key %s -subj %s -out %s',
            escapeshellarg($tsaDir . '/private/tsa.key'),
            escapeshellarg('/CN=LibreSign Local TSA'),
            escapeshellarg($tsaDir . '/tsa.csr')
        ));

        $extConfig = implode("\n", [
            'basicConstraints=CA:FALSE',
            'keyUsage = digitalSignature, nonRepudiation',
            'extendedKeyUsage = critical,timeStamping',
            'subjectKeyIdentifier=hash',
            'authorityKeyIdentifier=keyid,issuer',
            '',
        ]);
        file_put_contents($tsaDir . '/ext.cnf', $extConfig);

        $this->runOrFail(sprintf(
            'openssl x509 -req -in %s -CA %s -CAkey %s -CAcreateserial -out %s -days 365 -sha256 -extfile %s',
            escapeshellarg($tsaDir . '/tsa.csr'),
            escapeshellarg($tsaDir . '/ca.crt'),
            escapeshellarg($tsaDir . '/ca.key'),
            escapeshellarg($tsaDir . '/tsa.crt'),
            escapeshellarg($tsaDir . '/ext.cnf')
        ));

        $tsaConfig = implode("\n", [
            '[tsa]',
            'default_tsa = tsa_config1',
            '',
            '[tsa_config1]',
            'dir = ' . $tsaDir,
            'serial = $dir/serial',
            'database = $dir/index.txt',
            'crypto_device = builtin',
            'signer_cert = $dir/tsa.crt',
            'certs = $dir/ca.crt',
            'signer_key = $dir/private/tsa.key',
            'signer_digest = sha256',
            'default_policy = ' . $this->policyOid,
            'other_policies = ' . $this->policyOid,
            'digests = sha1,sha256,sha384,sha512',
            'accuracy = secs:1',
            'ordering = yes',
            'tsa_name = yes',
            'ess_cert_id_chain = no',
            'ess_cert_id_alg = sha256',
            '',
        ]);
        file_put_contents($tsaDir . '/openssl-tsa.cnf', $tsaConfig);
    }

    private function writeRouterScript(): string
    {
        $router = $this->workDir . '/router.php';
        $path = $this->normalizePath($this->path);
        $script = <<<'PHP'
<?php
$targetPath = '__TSA_PATH__';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== $targetPath) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'not found';
    return;
}

$input = file_get_contents('php://input');
if ($input === false || $input === '') {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'empty timestamp query';
    return;
}

$tmpDir = sys_get_temp_dir();
$queryFile = tempnam($tmpDir, 'tsa-query-');
$replyFile = tempnam($tmpDir, 'tsa-reply-');
if ($queryFile === false || $replyFile === false) {
    http_response_code(500);
    echo 'temporary file error';
    return;
}

file_put_contents($queryFile, $input);

$command = sprintf(
    'openssl ts -reply -queryfile %s -config %s -out %s 2>&1',
    escapeshellarg($queryFile),
    escapeshellarg('__TSA_CONFIG__'),
    escapeshellarg($replyFile)
);

exec($command, $output, $code);
if ($code !== 0) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "tsa reply generation failed\n" . implode("\n", $output);
    @unlink($queryFile);
    @unlink($replyFile);
    return;
}

$reply = file_get_contents($replyFile);
if ($reply === false || $reply === '') {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'empty timestamp response';
    @unlink($queryFile);
    @unlink($replyFile);
    return;
}

header('Content-Type: application/timestamp-reply');
header('Content-Length: ' . strlen($reply));
echo $reply;

@unlink($queryFile);
@unlink($replyFile);
PHP;

        $script = str_replace(
            ['__TSA_PATH__', '__TSA_CONFIG__'],
            [$path, $this->workDir . '/tsa/openssl-tsa.cnf'],
            $script
        );

        file_put_contents($router, $script);

        return $router;
    }

    private function runOrFail(string $command): void
    {
        exec($command . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException(
                sprintf("Command failed (%d): %s\n%s", $exitCode, $command, implode("\n", $output))
            );
        }
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/tsr';
        }

        return str_starts_with($path, '/') ? $path : '/' . $path;
    }

    private function mkdirOrFail(string $dir): void
    {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create directory: ' . $dir);
        }
    }

    private function findOpenPort(string $host): int
    {
        $server = @stream_socket_server('tcp://' . $host . ':0', $errno, $errstr);
        if ($server === false) {
            throw new RuntimeException('Unable to allocate local port for TSA server: ' . $errstr);
        }

        $name = stream_socket_get_name($server, false);
        fclose($server);

        if (!is_string($name) || !str_contains($name, ':')) {
            throw new RuntimeException('Unable to detect allocated local port for TSA server.');
        }

        $parts = explode(':', $name);
        $port = (int) end($parts);

        if ($port <= 0) {
            throw new RuntimeException('Invalid allocated port for TSA server.');
        }

        return $port;
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
