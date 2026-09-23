<?php

declare(strict_types=1);

namespace AV\JsonProvider\Mapping;

use AV\JsonProvider\Exception\JsonProviderMappingException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Validation\TemporalCodec;
use AV\JsonProvider\Validation\TemporalKindEnum;
use AV\JsonProvider\Validation\TemporalParseException;

/**
 * Converts between DTOs and the array records the provider core speaks.
 *
 * The mapper works entirely in the array API's "local" space: hydrate consumes
 * rows already decoded to the current timezone, extract produces local strings
 * that flow back through the ordinary validating/UTC-encoding write path. All
 * timezone conversion stays in the core — the mapper only bridges local strings
 * to DateTimeImmutable and scalars to BackedEnum cases. Compiled descriptors
 * drive it, so no reflection happens per row.
 */
final class DtoMapper
{
    public function __construct(
        private readonly TemporalCodec $codec = new TemporalCodec(),
    ) {
    }

    /**
     * Builds a DTO from a decoded row.
     *
     * @param array<string,null|scalar> $row
     */
    public function hydrate(DtoMap $map, array $row): object
    {
        $args = [];

        foreach ($map->fields as $field) {
            $args[$field->property] = $this->hydrateValue(
                $map,
                $field,
                $row[$field->column] ?? null,
            );
        }

        $class = $map->class;

        return new $class(...$args);
    }

    /**
     * Flattens a DTO into a local-form row for the write pipeline. A mapped
     * property the object does not carry is handled per $missing.
     *
     * @return array<string,null|scalar>
     */
    public function extract(
        DtoMap $map,
        object $dto,
        MissingPropertyModeEnum $missing = MissingPropertyModeEnum::WriteNull,
    ): array {
        $values = ($map->reader)($dto);
        $row = [];

        foreach ($map->fields as $field) {
            if (
                $missing === MissingPropertyModeEnum::KeepStored
                && !\array_key_exists($field->property, $values)
            ) {
                continue;
            }

            $row[$field->column] = $this->extractValue(
                $map,
                $field,
                $values[$field->property] ?? null,
            );
        }

        return $row;
    }

    private function hydrateValue(
        DtoMap $map,
        FieldDescriptor $field,
        bool | float | int | string | null $raw,
    ): \BackedEnum | bool | \DateTimeImmutable | float | int | string | null {
        if ($raw === null) {
            if ($field->nullable) {
                return null;
            }

            throw new JsonProviderMappingException(
                JsonProviderErrorEn::HydrateNullNonNullable,
                $map->table,
                $field->column,
            );
        }

        if ($field->temporalKind !== null) {
            return $this->hydrateTemporal(
                $map,
                $field->column,
                $field->temporalKind,
                $raw,
            );
        }

        if ($field->enumClass !== null) {
            return $this->hydrateEnum(
                $map,
                $field->column,
                $field->enumClass,
                $raw,
            );
        }

        if ($field->floatColumn && \is_int($raw)) {
            return (float)$raw;
        }

        return $raw;
    }

    private function hydrateTemporal(
        DtoMap $map,
        string $column,
        TemporalKindEnum $kind,
        bool | float | int | string $raw,
    ): \DateTimeImmutable {
        if (!\is_string($raw)) {
            throw new JsonProviderMappingException(
                JsonProviderErrorEn::HydrateNotTemporal,
                $map->table,
                $column,
                get_debug_type($raw),
            );
        }

        try {
            return $this->codec->localStringToDateTime($kind, $raw);
        } catch (TemporalParseException) {
            throw new JsonProviderMappingException(
                JsonProviderErrorEn::HydrateBadTemporal,
                $map->table,
                $column,
                $raw,
                $kind->value,
            );
        }
    }

    /**
     * @param class-string<\BackedEnum> $enumClass
     */
    private function hydrateEnum(
        DtoMap $map,
        string $column,
        string $enumClass,
        bool | float | int | string $raw,
    ): \BackedEnum {
        if (!\is_int($raw) && !\is_string($raw)) {
            throw new JsonProviderMappingException(
                JsonProviderErrorEn::InvalidEnumValue,
                $map->table,
                $column,
                get_debug_type($raw),
                $enumClass,
            );
        }

        $case = $enumClass::tryFrom($raw);

        if ($case === null) {
            throw new JsonProviderMappingException(
                JsonProviderErrorEn::InvalidEnumValue,
                $map->table,
                $column,
                (string)$raw,
                $enumClass,
            );
        }

        return $case;
    }

    private function extractValue(
        DtoMap $map,
        FieldDescriptor $field,
        mixed $value,
    ): bool | float | int | string | null {
        if ($value === null) {
            return null;
        }

        if (
            $field->temporalKind !== null
            && $value instanceof \DateTimeImmutable
        ) {
            return $this->codec->dateTimeToLocalString(
                $field->temporalKind,
                $value,
            );
        }

        if ($field->enumClass !== null && $value instanceof \BackedEnum) {
            return $value->value;
        }

        if (
            $field->temporalKind === null
            && $field->enumClass === null
            && \is_scalar($value)
        ) {
            return $value;
        }

        throw new JsonProviderMappingException(
            JsonProviderErrorEn::DtoPropertyTypeMismatch,
            $map->class,
            $map->table,
            $field->property,
        );
    }
}
