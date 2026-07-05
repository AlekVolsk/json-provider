<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Immutable DTO fixture covering every mapping shape:
 *  - scalars (id, title),
 *  - a required and a nullable backed enum (status / priority),
 *  - required, nullable, and bare-date DateTimeImmutable (happensAt / endsAt /
 *    onDate),
 *  - a numeric part (year),
 *  - camelCase → snake_case name conversion (happensAt → happens_at).
 */
#[JsonProviderRecord('dto_events')]
final class EventDto
{
    public function __construct(
        public int $id,
        public string $title,
        public EventStatus $status,
        public EventStatus | null $priority,
        public \DateTimeImmutable $happensAt,
        public \DateTimeImmutable | null $endsAt,
        public \DateTimeImmutable $onDate,
        public int $year,
    ) {}
}
