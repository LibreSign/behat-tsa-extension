<?php

declare(strict_types=1);

namespace LibreSign\Behat\TsaExtension\Listener;

use Behat\Testwork\EventDispatcher\Event\AfterSuiteTested;
use Behat\Testwork\EventDispatcher\Event\BeforeSuiteTested;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class TsaServerListener implements EventSubscriberInterface
{
    /** @var resource|null */
    private $serverProcess = null;

    private ?string $previousEnvValue = null;

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

        $this->previousEnvValue = getenv($this->envVar) === false ? null : getenv($this->envVar);

        $started = false;
        try {
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

            $started = true;
        } finally {
            if (!$started) {
                $this->stop();
            }
        }
    }

    public function afterSuite(AfterSuiteTested $event): void
    {
        if ($this->workDir !== '' || is_resource($this->serverProcess)) {
            $this->stop();
        }
    }

    private function startServer(): void
    {
        $log = $this->workDir . '/server.log';
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];

        $process = proc_open(
            [PHP_BINARY, '-S', $this->host . ':' . $this->resolvedPort, '-t', $this->workDir, $this->routerPath],
            $descriptors,
            $pipes,
            $this->workDir
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start local TSA server process.');
        }

        $this->serverProcess = $process;

        for ($attempt = 0; $attempt < 30; $attempt++) {
            usleep(100000);
            if (!$this->isRunning()) {
                break;
            }

            // A listening socket alone does not prove that our TSA router is ready.
            if ($this->isRouterReady()) {
                return;
            }
        }

        $details = is_file($log) ? trim((string) file_get_contents($log)) : '';
        throw new RuntimeException('Local TSA server did not become ready: ' . $details);
    }

    private function isRouterReady(): bool
    {
        $socket = @fsockopen($this->host, $this->resolvedPort, $errno, $error, 0.2);
        if (!is_resource($socket)) {
            return false;
        }

        try {
            stream_set_timeout($socket, 0, 200000);
            $request = sprintf(
                "POST %s HTTP/1.0\r\nHost: %s\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
                $this->normalizePath($this->path),
                $this->host
            );
            if (fwrite($socket, $request) !== strlen($request)) {
                return false;
            }

            $response = stream_get_contents($socket);

            return is_string($response)
                && preg_match('~^HTTP/1\.[01] 400\b~', $response) === 1
                && str_ends_with($response, 'empty timestamp query');
        } finally {
            fclose($socket);
        }
    }

    private function stop(): void
    {
        if (is_resource($this->serverProcess)) {
            if ($this->isRunning()) {
                proc_terminate($this->serverProcess);
            }
            proc_close($this->serverProcess);
        }

        $this->serverProcess = null;

        if ($this->workDir !== '' && is_dir($this->workDir)) {
            $this->deleteTree($this->workDir);
        }

        $this->workDir = '';
        $this->routerPath = '';
        $this->resolvedPort = 0;

        if ($this->previousEnvValue === null) {
            putenv($this->envVar);
        } else {
            putenv($this->envVar . '=' . $this->previousEnvValue);
        }

        $this->previousEnvValue = null;
    }

    private function isRunning(): bool
    {
        return is_resource($this->serverProcess) && proc_get_status($this->serverProcess)['running'];
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
$targetPath = __TSA_PATH__;
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
    escapeshellarg(__TSA_CONFIG__),
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
            [var_export($path, true), var_export($this->workDir . '/tsa/openssl-tsa.cnf', true)],
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
            if (is_dir($path) && !is_link($path)) {
                $this->deleteTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
