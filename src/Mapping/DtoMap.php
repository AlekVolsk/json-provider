<?php

declare(strict_types=1);

namespace AV\JsonProvider\Mapping;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\Mapping\Attribute\JsonProviderColumn;
use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Validation\ColumnTypeInfo;
use AV\JsonProvider\Validation\TemporalKind;

/**
 * A DTO class compiled against a table schema.
 *
 * Built once per class at registration: reads the `#[JsonProviderRecord]`
 * binding, walks the promoted constructor, and validates every parameter
 * against the matching schema column (name, PHP type ↔ column type,
 * nullability, enum backing). Any mismatch fails fast with a clear
 * StorageException, so a DTO can never silently disagree with its table.
 *
 * Only the DTO's constructor parameters are mapped; columns the DTO omits keep
 * the array-core rules (missing nullable → null, missing non-nullable → error
 * on insert).
 */
final class DtoMap
{
    /**
     * @param class-string                          $class
     * @param array<int,FieldDescriptor>            $fields
     * @param \Closure(object): array<string,mixed> $reader reads the DTO's
     */
    public function __construct(
        public readonly string $class,
        public readonly string $table,
        public readonly array $fields,
        public readonly \Closure $reader,
    ) {}

    /**
     * Compiles and validates a DTO class against a table schema.
     *
     * @param class-string $class
     *
     * @throws StorageException on any DTO/schema mismatch
     */
    public static function compile(string $class, TableSchema $schema): self
    {
        $reflection = new \ReflectionClass($class);
        $table = self::readTable($reflection, $schema->name);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            throw StorageException::dtoSchemaMismatch(
                $class,
                $schema->name,
                'a mapped DTO must declare a constructor',
            );
        }

        $fields = [];

        foreach ($constructor->getParameters() as $parameter) {
            $fields[] = self::compileField($class, $schema, $parameter);
        }

        return new self($class, $table, $fields, self::buildReader($class));
    }

    /**
     * Reads the table a DTO is bound to via #[JsonProviderRecord], without
     * compiling. The provider needs it to resolve the schema before compile().
     *
     * @param class-string $class
     *
     * @throws StorageException if the attribute is absent
     */
    public static function tableName(string $class): string
    {
        $attributes = (new \ReflectionClass($class))
            ->getAttributes(JsonProviderRecord::class);

        if ($attributes === []) {
            throw StorageException::dtoSchemaMismatch(
                $class,
                '(unbound)',
                'missing #[JsonProviderRecord] attribute',
            );
        }

        return $attributes[0]->newInstance()->table;
    }

    /**
     * A property reader bound to the DTO's class scope: get_object_vars()
     * called from inside the class sees promoted constructor properties of
     * every visibility (public, protected, private). Inherited private
     * properties of a PARENT class stay invisible — a mapped property must be
     * declared on the DTO itself.
     *
     * @param class-string $class
     *
     * @return \Closure(object): array<string,mixed>
     */
    private static function buildReader(string $class): \Closure
    {
        /** @var \Closure(object): array<string,mixed> $reader */
        $reader = \Closure::bind(
            static fn (object $o): array => get_object_vars($o),
            null,
            $class,
        );

        return $reader;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     */
    private static function readTable(
        \ReflectionClass $reflection,
        string $expected,
    ): string {
        $attributes = $reflection->getAttributes(JsonProviderRecord::class);

        if ($attributes === []) {
            throw StorageException::dtoSchemaMismatch(
                $reflection->getName(),
                $expected,
                'missing #[JsonProviderRecord] attribute',
            );
        }

        $table = $attributes[0]->newInstance()->table;

        if ($table !== $expected) {
            throw StorageException::dtoSchemaMismatch(
                $reflection->getName(),
                $expected,
                'bound to table "' . $table . '"',
            );
        }

        return $table;
    }

    private static function compileField(
        string $class,
        TableSchema $schema,
        \ReflectionParameter $parameter,
    ): FieldDescriptor {
        $property = $parameter->getName();
        $type = $parameter->getType();

        if ($type === null) {
            throw self::mismatch(
                $class,
                $schema,
                $property,
                'property has no type declaration; a mapped property must '
                    . 'be typed',
            );
        }

        if ($type instanceof \ReflectionUnionType) {
            throw self::mismatch(
                $class,
                $schema,
                $property,
                'union types are not supported',
            );
        }

        if ($type instanceof \ReflectionIntersectionType) {
            throw self::mismatch(
                $class,
                $schema,
                $property,
                'intersection types are not supported',
            );
        }

        if (!$type instanceof \ReflectionNamedType) {
            throw self::mismatch(
                $class,
                $schema,
                $property,
                'unsupported type declaration',
            );
        }

        if ($type->getName() === 'mixed') {
            throw self::mismatch(
                $class,
                $schema,
                $property,
                "'mixed' is not supported; declare a concrete type",
            );
        }

        self::assertReadable($class, $schema, $parameter);

        $derived = $parameter->getAttributes(JsonProviderColumn::class) === [];
        $column = self::resolveColumn($parameter);

        if (!isset($schema->columns[$column])) {
            throw self::mismatch(
                $class,
                $schema,
                $property,
                $derived
                    ? 'no column "' . $column . '" (derived from property "'
                        . $property . '"); add #[JsonProviderColumn("...")] '
                        . 'to override the name'
                    : 'no column "' . $column . '" in the table',
            );
        }

        $info = ColumnTypeInfo::parse($schema->columns[$column]);
        self::assertNullability($class, $schema, $property, $type, $info);

        return self::describe(
            $class,
            $schema,
            $property,
            $column,
            $type,
            $info,
        );
    }

    private static function describe(
        string $class,
        TableSchema $schema,
        string $property,
        string $column,
        \ReflectionNamedType $type,
        ColumnTypeInfo $info,
    ): FieldDescriptor {
        $phpType = $type->getName();
        $nullable = $type->allowsNull();

        if ($phpType === \DateTimeImmutable::class) {
            $kind = TemporalKind::tryFromBase($info->base);

            if ($kind === null) {
                throw self::mismatch(
                    $class,
                    $schema,
                    $property,
                    'DateTimeImmutable maps only to a temporal column, '
                        . 'got "' . $info->base . '"',
                );
            }

            return new FieldDescriptor(
                $property,
                $column,
                $nullable,
                temporalKind: $kind,
            );
        }

        if (
            enum_exists($phpType)
            && is_subclass_of($phpType, \BackedEnum::class)
        ) {
            self::assertEnumBacking(
                $class,
                $schema,
                $property,
                $phpType,
                $info
            );

            return new FieldDescriptor(
                $property,
                $column,
                $nullable,
                enumClass: $phpType,
            );
        }

        return self::describeScalar(
            $class,
            $schema,
            $property,
            $column,
            $phpType,
            $nullable,
            $info,
        );
    }

    private static function describeScalar(
        string $class,
        TableSchema $schema,
        string $property,
        string $column,
        string $phpType,
        bool $nullable,
        ColumnTypeInfo $info,
    ): FieldDescriptor {
        $ok = match ($phpType) {
            'string' => $info->base === ColumnTypes::STRING,
            'bool'   => $info->base === ColumnTypes::BOOL,
            'float'  => $info->base === ColumnTypes::FLOAT,
            'int'    => \in_array($info->base, [
                ColumnTypes::INT,
                ColumnTypes::YEAR,
                ColumnTypes::MONTH,
                ColumnTypes::DAY,
            ], true),
            default => false,
        };

        if (!$ok) {
            throw self::mismatch(
                $class,
                $schema,
                $property,
                'PHP type "' . $phpType . '" is not compatible with column '
                    . 'type "' . $info->base . '"',
            );
        }

        return new FieldDescriptor(
            $property,
            $column,
            $nullable,
            floatColumn: $phpType === 'float',
        );
    }

    /**
     * @param class-string<\BackedEnum> $enumClass
     */
    private static function assertEnumBacking(
        string $class,
        TableSchema $schema,
        string $property,
        string $enumClass,
        ColumnTypeInfo $info,
    ): void {
        $backing = (new \ReflectionEnum($enumClass))->getBackingType();
        $backingName = $backing instanceof \ReflectionNamedType
            ? $backing->getName()
            : null;

        $expected = match ($info->base) {
            ColumnTypes::INT    => 'int',
            ColumnTypes::STRING => 'string',
            default             => null,
        };

        if ($expected === null || $backingName !== $expected) {
            throw self::mismatch(
                $class,
                $schema,
                $property,
                'enum ' . $enumClass . ' (backed by '
                    . ($backingName ?? 'nothing') . ') is not compatible with '
                    . 'column type "' . $info->base . '"',
            );
        }
    }

    /**
     * Rejects a promoted property the extract-time reader cannot see. The
     * reader (buildReader) is bound to the mapped class scope, so
     * get_object_vars omits a property declared `private` on a PARENT class;
     * extracting it would yield a silent null — data loss on a nullable
     * column, a misleading NULL_NOT_ALLOWED on a non-nullable one. Fail loudly
     * at compile time so a table can never register a DTO that would truncate
     * its own rows. A private property declared on the mapped class itself, or
     * an inherited protected/public one, stays readable and is accepted.
     */
    private static function assertReadable(
        string $class,
        TableSchema $schema,
        \ReflectionParameter $parameter,
    ): void {
        if (!$parameter->isPromoted()) {
            return;
        }

        $declaringClass = $parameter->getDeclaringClass();

        if ($declaringClass === null || $declaringClass->getName() === $class) {
            return;
        }

        if (!$declaringClass->getProperty($parameter->getName())->isPrivate()) {
            return;
        }

        throw self::mismatch(
            $class,
            $schema,
            $parameter->getName(),
            'a private property inherited from ' . $declaringClass->getName()
                . ' is not readable by the mapper; declare it on the DTO class '
                . 'itself or make it protected',
        );
    }

    private static function assertNullability(
        string $class,
        TableSchema $schema,
        string $property,
        \ReflectionNamedType $type,
        ColumnTypeInfo $info,
    ): void {
        if ($type->allowsNull() !== $info->nullable) {
            throw self::mismatch(
                $class,
                $schema,
                $property,
                'nullability differs from the column ('
                    . ($info->nullable ? 'column is nullable' : 'column is not '
                        . 'nullable') . ')',
            );
        }
    }

    private static function resolveColumn(
        \ReflectionParameter $parameter,
    ): string {
        $attributes = $parameter->getAttributes(JsonProviderColumn::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->name;
        }

        return NameStrategy::columnFor($parameter->getName());
    }

    private static function mismatch(
        string $class,
        TableSchema $schema,
        string $property,
        string $reason,
    ): StorageException {
        return StorageException::dtoSchemaMismatch(
            $class,
            $schema->name,
            'property "' . $property . '": ' . $reason,
        );
    }
}
