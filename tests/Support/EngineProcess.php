<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

use Testo\Assert;

/**
 * One child PHP process running LegacyRunner against a database, on either
 * the current engine or the published v1.0.0 one.
 *
 * Two versions of the library share one namespace, so they cannot live in
 * one process; the child is where the legacy engine runs. For the legacy
 * side the child prepends an autoloader that maps the library namespace to
 * the extracted v1.0.0 sources (see `make compat-legacy`). It is registered
 * after Composer's loader, which prepends itself, and is prepended in turn,
 * so the legacy classes win while test helpers and third-party packages
 * still come from the project's vendor tree.
 *
 * start() returns at once, so two processes can run side by side; wait()
 * collects the output. run() is the blocking shortcut.
 */
final class EngineProcess
{
    public const string LEGACY_VERSION = 'v1.0.0';

    private const string CHILD = <<<'PHP'
        [, $legacySrc, $autoload, $dbDir, $ops] = $argv;
        require $autoload;
        if ($legacySrc !== '') {
            spl_autoload_register(
                static function (string $class) use ($legacySrc): void {
                    $prefix = 'AV\\JsonProvider\\';
                    if (
                        !str_starts_with($class, $prefix)
                        || str_starts_with($class, $prefix . 'Tests\\')
                    ) {
                        return;
                    }
                    $file = $legacySrc . '/' . str_replace(
                        '\\',
                        '/',
                        substr($class, strlen($prefix)),
                    ) . '.php';
                    if (is_file($file)) {
                        require $file;
                    }
                },
                true,
                true,
            );
        }
        exit(\AV\JsonProvider\Tests\Support\LegacyRunner::main($dbDir, $ops));
        PHP;

    /** @var resource */
    private $process;

    /** @var array<int,resource> */
    private array $pipes;

    /**
     * @param list<array<string,mixed>> $ops
     */
    private function __construct(bool $legacy, string $dbDir, array $ops)
    {
        $legacySrc = '';

        if ($legacy) {
            $legacySrc = self::legacySourceDir();
            Assert::true(
                is_file($legacySrc . '/JsonDataProvider.php'),
                'legacy sources missing — run `make compat-legacy`',
            );
        }

        $cmd = [
            PHP_BINARY,
            '-r',
            self::CHILD,
            '--',
            $legacySrc,
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            $dbDir,
            json_encode($ops, JSON_THROW_ON_ERROR),
        ];

        $process = proc_open($cmd, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        Assert::true(\is_resource($process), 'cannot start a child process');

        $this->process = $process;
        $this->pipes = $pipes;
    }

    public static function legacySourceDir(): string
    {
        return \dirname(__DIR__, 2) . '/build-dev/compat/'
            . self::LEGACY_VERSION . '/src';
    }

    /**
     * @param list<array<string,mixed>> $ops
     */
    public static function start(
        bool $legacy,
        string $dbDir,
        array $ops,
    ): self {
        return new self($legacy, $dbDir, $ops);
    }

    /**
     * Runs the operations on the legacy engine and returns their results.
     *
     * @param list<array<string,mixed>> $ops
     *
     * @return list<mixed>
     */
    public static function legacy(string $dbDir, array $ops): array
    {
        return self::start(true, $dbDir, $ops)->wait();
    }

    /**
     * Runs the operations on the current engine in a fresh process — a
     * fresh provider initialization, as on every PHP-FPM request.
     *
     * @param list<array<string,mixed>> $ops
     *
     * @return list<mixed>
     */
    public static function current(string $dbDir, array $ops): array
    {
        return self::start(false, $dbDir, $ops)->wait();
    }

    /**
     * @return list<mixed>
     */
    public function wait(): array
    {
        $stdout = (string)stream_get_contents($this->pipes[1]);
        $stderr = (string)stream_get_contents($this->pipes[2]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        $exit = proc_close($this->process);

        Assert::same($exit, 0, 'child failed: ' . $stderr . $stdout);

        $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        Assert::true(
            \is_array($decoded) && \is_array($decoded['results'] ?? null),
            'child printed no results: ' . $stdout,
        );

        return array_values($decoded['results']);
    }
}
