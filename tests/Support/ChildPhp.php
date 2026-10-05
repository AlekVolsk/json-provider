<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

/**
 * A PHP child process running a code snippet, for tests that need a second
 * process: a concurrent writer, a lock holder, a reader.
 */
final class ChildPhp
{
    /**
     * Starts `php -r $code -- ...$args` with stdin, stdout and stderr piped.
     *
     * @param list<string> $args
     *
     * @return array{proc: resource, pipes: array<int,resource>}
     */
    public static function spawn(string $code, array $args): array
    {
        $cmd = array_merge([PHP_BINARY, '-r', $code, '--'], $args);
        $pipes = [];
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        \assert(\is_resource($proc));

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    /**
     * Starts the child and waits until it prints its "ready" line.
     *
     * @param list<string> $args
     *
     * @return array{proc: resource, pipes: array<int,resource>}
     */
    public static function spawnReady(string $code, array $args): array
    {
        $child = self::spawn($code, $args);
        $ready = fgets($child['pipes'][1]);
        \assert(\is_string($ready) && trim($ready) === 'ready');

        return $child;
    }

    /**
     * Closes stdin, reads stdout to the end and waits for the exit.
     *
     * @param array{proc: resource, pipes: array<int,resource>} $child
     */
    public static function drain(array $child): string
    {
        fclose($child['pipes'][0]);
        $stdout = stream_get_contents($child['pipes'][1]);
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        proc_close($child['proc']);

        return $stdout === false ? '' : $stdout;
    }
}
