<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 spy collecting every record — asserts on degradation logging.
 */
final class SpyLogger extends AbstractLogger
{
    /** @var array<int,array{level:string,message:string}> */
    public array $records = [];

    /**
     * @param mixed[] $context
     */
    public function log(
        mixed $level,
        string | \Stringable $message,
        array $context = [],
    ): void {
        $this->records[] = [
            'level'   => \is_scalar($level) ? (string)$level : 'unknown',
            'message' => (string)$message,
        ];
    }
}
