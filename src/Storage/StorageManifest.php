<?php

declare(strict_types=1);

namespace AV\JsonProvider\Storage;

use AV\JsonProvider\Exception\JsonProviderIoException;
use AV\JsonProvider\Exception\JsonProviderServiceException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;

/**
 * The storage format of a database: its generation and feature flags,
 * kept in dbPath/.jdp/format.json.
 *
 * Generation 1 is everything the 1.0.x engines write. It carries no
 * manifest at all — its mark is the absence of one, since those engines
 * predate it. Every later generation writes the manifest.
 *
 * The directory name starts with a dot on purpose: the 1.0.x integrity
 * validator and repair skip dot-entries of the database root (as they skip
 * .locks), so an engine that knows nothing of the manifest neither reports
 * it as an orphan nor deletes it. Everything a generation stores must stay
 * readable and writable by the 1.0.x engines for the same reason; the flags
 * exist so that engines from 1.1 on can tell a format they cannot handle
 * and refuse it instead of misreading it:
 *
 *  - compat: an engine that does not know the feature may ignore it;
 *  - roCompat: such an engine may read the database but must not write;
 *  - incompat: such an engine must not open the database at all.
 *
 * Unknown top-level keys are ignored, so a later generation may add fields
 * that older readers skip.
 */
final class StorageManifest
{
    /** The generation this engine writes and fully understands. */
    public const int GENERATION = 2;

    public const string DIR = '.jdp';
    public const string FILE = 'format.json';

    private const int LEGACY_GENERATION = 1;
    private const string KEY_GENERATION = 'generation';
    private const string KEY_COMPAT = 'compat';
    private const string KEY_RO_COMPAT = 'roCompat';
    private const string KEY_INCOMPAT = 'incompat';

    private const array KNOWN_COMPAT = [];
    private const array KNOWN_RO_COMPAT = [];
    private const array KNOWN_INCOMPAT = [];

    /**
     * @param list<string> $compat
     * @param list<string> $roCompat
     * @param list<string> $incompat
     */
    private function __construct(
        public readonly int $generation,
        public readonly array $compat = [],
        public readonly array $roCompat = [],
        public readonly array $incompat = [],
    ) {
    }

    /**
     * The manifest of a database written by a 1.0.x engine.
     */
    public static function legacy(): self
    {
        return new self(self::LEGACY_GENERATION);
    }

    /**
     * The manifest this engine writes for a database it creates.
     */
    public static function current(): self
    {
        return new self(self::GENERATION);
    }

    /**
     * Reads the manifest of the database at $dbPath; a database without
     * one is generation 1. A manifest that exists but cannot be trusted
     * (not JSON, not an object, a generation below 2, a flag list that is
     * present but not a list of non-empty strings) raises
     * STORAGE_MANIFEST_CORRUPT: guessing a generation could hide a feature
     * this engine must not ignore. An absent flag list is an empty one.
     */
    public static function read(string $dbPath): self
    {
        $path = self::path($dbPath);

        if (!is_file($path)) {
            return self::legacy();
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new JsonProviderIoException(
                JsonProviderErrorEn::FileNotReadable,
                $path,
            );
        }

        $data = json_decode($raw, true);

        if (!\is_array($data) || array_is_list($data)) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::StorageManifestCorrupt,
                $path,
            );
        }

        $generation = $data[self::KEY_GENERATION] ?? null;

        if (
            !\is_int($generation)
            || $generation <= self::LEGACY_GENERATION
        ) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::StorageManifestCorrupt,
                $path,
            );
        }

        return new self(
            $generation,
            self::flags($data, self::KEY_COMPAT, $path),
            self::flags($data, self::KEY_RO_COMPAT, $path),
            self::flags($data, self::KEY_INCOMPAT, $path),
        );
    }

    /**
     * Writes the manifest atomically, creating its directory when needed.
     */
    public function write(string $dbPath): void
    {
        $dir = $dbPath . '/' . self::DIR;

        if (!is_dir($dir) && !mkdir($dir, 0755) && !is_dir($dir)) {
            throw new JsonProviderIoException(
                JsonProviderErrorEn::FileNotWritable,
                $dir,
            );
        }

        $json = json_encode([
            self::KEY_GENERATION => $this->generation,
            self::KEY_COMPAT     => $this->compat,
            self::KEY_RO_COMPAT  => $this->roCompat,
            self::KEY_INCOMPAT   => $this->incompat,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        AtomicFileWriter::write(self::path($dbPath), $json . "\n");
    }

    public function isLegacy(): bool
    {
        return $this->generation === self::LEGACY_GENERATION;
    }

    /**
     * Throws when this engine must not open the database at all: a newer
     * generation, or an incompat feature it does not know.
     */
    public function assertOpenable(string $dbPath): void
    {
        if ($this->generation > self::GENERATION) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::StorageGenerationUnsupported,
                (string)$this->generation,
                (string)self::GENERATION,
            );
        }

        $unknown = array_values(
            array_diff($this->incompat, self::KNOWN_INCOMPAT),
        );

        if ($unknown !== []) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::StorageFeatureUnsupported,
                self::describe($unknown, $dbPath),
            );
        }
    }

    /**
     * The roCompat features this engine does not know: when any is set,
     * the database may be read but not written.
     *
     * @return list<string>
     */
    public function unknownRoCompat(): array
    {
        return array_values(
            array_diff($this->roCompat, self::KNOWN_RO_COMPAT),
        );
    }

    /**
     * The compat features this engine does not know; ignoring them is
     * safe by the definition of the class.
     *
     * @return list<string>
     */
    public function unknownCompat(): array
    {
        return array_values(array_diff($this->compat, self::KNOWN_COMPAT));
    }

    public static function path(string $dbPath): string
    {
        return $dbPath . '/' . self::DIR . '/' . self::FILE;
    }

    /**
     * Feature names joined for a message, followed by the database path.
     *
     * @param list<string> $features
     */
    public static function describe(array $features, string $dbPath): string
    {
        return implode(', ', $features) . ' (' . $dbPath . ')';
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function flags(
        array $data,
        string $key,
        string $path,
    ): array {
        if (!\array_key_exists($key, $data)) {
            return [];
        }

        $raw = $data[$key];

        if (!\is_array($raw) || !array_is_list($raw)) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::StorageManifestCorrupt,
                $path,
            );
        }

        $flags = [];

        foreach ($raw as $flag) {
            if (!\is_string($flag) || $flag === '') {
                throw new JsonProviderServiceException(
                    JsonProviderErrorEn::StorageManifestCorrupt,
                    $path,
                );
            }

            $flags[] = $flag;
        }

        return $flags;
    }
}
