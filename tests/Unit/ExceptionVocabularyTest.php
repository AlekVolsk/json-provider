<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Exception\JsonProviderQueryException;
use AV\JsonProvider\Exception\JsonProviderTableException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorRu;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The contract of the error vocabulary.
 *
 * A situation is named by a case of JsonProviderErrorEn; every locale
 * declares the same case names, and the values passed at a throw site are
 * data only. These tests hold that line structurally — over the whole
 * vocabulary and every throw site in src — instead of sampling a few
 * runtime messages.
 */
final class ExceptionVocabularyTest
{
    /**
     * Latin tokens a Russian template may legitimately contain: keys of the
     * JSON files the user edits, the values the schema accepts verbatim,
     * column types, attribute names and language literals. Reviewed by hand;
     * bounded by the templates, not by runtime data.
     */
    private const array NEUTRAL = [
        'asc', 'backingindex', 'belongsto', 'between', 'bool', 'cascade',
        'columns', 'date', 'datetime', 'datetimeimmutable', 'datetimez',
        'day', 'desc', 'field', 'fields', 'fk', 'float', 'foreignkey',
        'from', 'hasmany', 'hasone', 'id', 'indexes', 'inf',
        'informationschema', 'int', 'json', 'jsonprovidercolumn',
        'jsonproviderrecord', 'like', 'manifest', 'max', 'meta',
        'min', 'mixed', 'month', 'nan', 'noaction', 'null', 'onupdate',
        'php',
        'protected', 'pruneextratables', 'references', 'relations',
        'restrict', 'setnull', 'string', 'tables', 'time', 'timez', 'to',
        'true', 'type', 'unique', 'utc', 'utf', 'year',
    ];

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        TempDir::remove(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);
        $this->db->createTable(TableSchema::create(
            name: 'items',
            columns: ['n' => 'int'],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        JsonProviderException::resetLocale();
        TempDir::remove(self::dbPathRoot());
    }

    /**
     * A case present in one locale only would render as English for half the
     * users — and a locale is written by a translator who never sees the
     * code, so the sets must match exactly.
     */
    #[Test]
    public function bothLocalesDeclareTheSameCases(): void
    {
        $en = array_map(
            static fn (JsonProviderErrorEn $c): string => $c->name,
            JsonProviderErrorEn::cases(),
        );
        $ru = array_map(
            static fn (JsonProviderErrorRu $c): string => $c->name,
            JsonProviderErrorRu::cases(),
        );

        sort($en);
        sort($ru);

        Assert::same(
            array_values(array_diff($en, $ru)),
            [],
            'cases missing from the Russian locale',
        );
        Assert::same(
            array_values(array_diff($ru, $en)),
            [],
            'cases missing from the English locale',
        );
    }

    /**
     * A hole count that differs between locales either drops data silently
     * (fewer holes) or blows up with ArgumentCountError at the worst
     * possible moment (more holes).
     */
    #[Test]
    public function placeholderCountsMatchAcrossLocales(): void
    {
        $russian = [];

        foreach (JsonProviderErrorRu::cases() as $case) {
            $russian[$case->name] = $case->value;
        }

        foreach (JsonProviderErrorEn::cases() as $case) {
            Assert::same(
                substr_count($russian[$case->name] ?? '', '%s'),
                substr_count($case->value, '%s'),
                $case->name . ': placeholder count differs',
            );
        }
    }

    /**
     * A caller who set the Russian locale must read the whole sentence in
     * Russian. The template is rendered with markers instead of data, so
     * whatever Latin is left is part of the wording — either a neutral token
     * or untranslated prose.
     */
    #[Test]
    public function russianTemplatesHoldNoEnglishProse(): void
    {
        foreach (JsonProviderErrorRu::cases() as $case) {
            $holes = substr_count($case->value, '%s');
            $rendered = $holes === 0
                ? $case->value
                : \sprintf($case->value, ...array_fill(0, $holes, '‹›'));

            preg_match_all('/[A-Za-z][A-Za-z_0-9]*/', $rendered, $matches);

            $prose = [];

            foreach ($matches[0] as $word) {
                $normalized = strtolower(str_replace('_', '', $word));

                if (!\in_array($normalized, self::NEUTRAL, true)) {
                    $prose[] = $word;
                }
            }

            Assert::same(
                array_values(array_unique($prose)),
                [],
                $case->name . ': Latin prose left in the Russian template',
            );
        }
    }

    /**
     * The guard of the whole design: a literal at a throw site is prose
     * sneaking back into the code past the locales. Only the case may be
     * named literally; every other argument is data — a variable, a
     * property, a call or a declared constant.
     */
    #[Test]
    public function throwSitesPassNoLiteralsButTheCase(): void
    {
        $offenders = [];

        foreach (self::throwSites() as $site) {
            if ($site['case'] === null && !$site['indirect']) {
                $offenders[] = $site['where']
                    . ': the first argument is not a vocabulary case';

                continue;
            }

            foreach ($site['literals'] as $literal) {
                $offenders[] = $site['where'] . ': literal ' . $literal;
            }
        }

        Assert::same($offenders, []);
    }

    /**
     * A situation belongs to exactly one domain, so a case must always be
     * thrown by the same class — otherwise catching that class would miss
     * half of the situation.
     */
    #[Test]
    public function everyCaseIsThrownByExactlyOneClass(): void
    {
        $classes = [];

        foreach (self::throwSites() as $site) {
            if ($site['case'] === null) {
                continue;
            }

            $classes[$site['case']][$site['class']] = true;
        }

        $split = [];

        foreach ($classes as $case => $used) {
            if (\count($used) > 1) {
                $split[] = $case . ': ' . implode(', ', array_keys($used));
            }
        }

        Assert::same($split, []);
    }

    /**
     * A case nothing throws is dead weight a translator still has to
     * translate.
     */
    #[Test]
    public function everyCaseIsReachable(): void
    {
        $mentioned = self::mentionedCases();
        $unused = [];

        foreach (JsonProviderErrorEn::cases() as $case) {
            if (!isset($mentioned[$case->name])) {
                $unused[] = $case->name;
            }
        }

        Assert::same($unused, []);
    }

    /**
     * The runtime contract: the invariant message stays English for logs
     * while the localized one follows the locale, and the case survives as
     * the handle for programmatic handling.
     */
    #[Test]
    public function messagesSplitByLocaleAtRuntime(): void
    {
        $this->db->setLocale(JsonProviderErrorRu::TableNotFound);

        try {
            $this->db->table('nope')->selectAllByArray();
            Assert::fail('expected a table failure');
        } catch (JsonProviderTableException $e) {
            Assert::same($e->error, JsonProviderErrorEn::TableNotFound);
            Assert::same($e->getErrorKey(), 'TableNotFound');
            Assert::string($e->getMessage())->contains('not found in schema');
            Assert::string($e->getLocalizedMessage())
                ->contains('не найдена в схеме');
        }

        try {
            $this->db->table('items')->limit(-1);
            Assert::fail('expected a query failure');
        } catch (JsonProviderQueryException $e) {
            Assert::same($e->error, JsonProviderErrorEn::InvalidLimit);
            Assert::string($e->getLocalizedMessage())
                ->contains('предел выборки');
        }
    }

    /**
     * A log line must not leak the deployment layout: the trace is cut back
     * to the package-relative part.
     */
    #[Test]
    public function traceCarriesNoHostPaths(): void
    {
        try {
            $this->db->table('nope')->selectAllByArray();
            Assert::fail('expected a table failure');
        } catch (JsonProviderTableException $e) {
            $context = $e->getLogContext();

            $absolute = \dirname(__DIR__, 2) . '/src/';

            Assert::same($context['errorKey'], 'TableNotFound');
            Assert::false(
                str_contains($context['trace'], $absolute),
                'a package frame still holds the absolute path',
            );
            Assert::false(
                str_contains($context['origin'], $absolute),
                'the origin still holds the absolute path',
            );
            Assert::string($context['origin'])->contains('/src/');
        }
    }

    /**
     * Every `new JsonProvider*Exception(...)` in src: the case it names, the
     * class it uses and the scalar literals passed alongside the case.
     *
     * @return list<array{
     *     where: string,
     *     class: string,
     *     case: null|string,
     *     indirect: bool,
     *     literals: list<string>,
     * }>
     */
    private static function throwSites(): array
    {
        $sites = [];
        $root = \dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);

            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string)file_get_contents($file->getPathname());
            $short = str_replace($root . '/', '', $file->getPathname());

            foreach (self::scan($source, $short) as $site) {
                $sites[] = $site;
            }
        }

        return $sites;
    }

    /**
     * @return list<array{
     *     where: string,
     *     class: string,
     *     case: null|string,
     *     indirect: bool,
     *     literals: list<string>,
     * }>
     */
    private static function scan(string $source, string $file): array
    {
        $tokens = token_get_all($source);
        $sites = [];

        foreach ($tokens as $index => $token) {
            if (
                !\is_array($token)
                || $token[0] !== T_STRING
                || preg_match(
                    '/^JsonProvider\w+Exception$/',
                    $token[1],
                ) !== 1
            ) {
                continue;
            }

            $previous = self::previousMeaningful($tokens, $index);

            if ($previous === null || $previous[0] !== T_NEW) {
                continue;
            }

            $site = self::readArguments($tokens, $index);

            if ($site === null) {
                continue;
            }

            $sites[] = [
                'where'    => $file . ':' . $token[2],
                'class'    => $token[1],
                'case'     => $site['case'],
                'indirect' => $site['indirect'],
                'literals' => $site['literals'],
            ];
        }

        return $sites;
    }

    /**
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     *
     * @return null|array{0:int,1:string,2:int}
     */
    private static function previousMeaningful(
        array $tokens,
        int $index,
    ): array | null {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (!\is_array($token)) {
                return null;
            }

            if (
                $token[0] === T_WHITESPACE
                || $token[0] === T_COMMENT
                || $token[0] === T_DOC_COMMENT
            ) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     *
     * @return null|array{
     *     case: null|string,
     *     indirect: bool,
     *     literals: list<string>,
     * }
     */
    private static function readArguments(
        array $tokens,
        int $index,
    ): array | null {
        $count = \count($tokens);
        $start = null;

        for ($i = $index + 1; $i < $count; $i++) {
            if ($tokens[$i] === '(') {
                $start = $i;

                break;
            }

            if (
                !\is_array($tokens[$i])
                || $tokens[$i][0] !== T_WHITESPACE
            ) {
                return null;
            }
        }

        if ($start === null) {
            return null;
        }

        $depth = 0;
        $position = 0;
        $case = null;
        $indirect = false;
        $literals = [];
        $expectCase = true;

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = \is_array($token) ? $token[1] : $token;

            if ($text === '(' || $text === '[') {
                $depth++;

                continue;
            }

            if ($text === ')' || $text === ']') {
                $depth--;

                if ($depth === 0) {
                    break;
                }

                continue;
            }

            if ($text === ',' && $depth === 1) {
                $position++;
                $expectCase = false;

                continue;
            }

            if ($depth !== 1 || !\is_array($token)) {
                continue;
            }

            if ($expectCase && $token[0] === T_VARIABLE) {
                $indirect = true;

                continue;
            }

            if ($expectCase && $token[0] === T_STRING) {
                $case ??= $token[1] === 'JsonProviderErrorEn'
                    ? self::caseName($tokens, $i)
                    : null;

                continue;
            }

            if (
                $position > 0
                && \in_array(
                    $token[0],
                    [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER],
                    true,
                )
            ) {
                $literals[] = $token[1];
            }
        }

        return [
            'case'     => $case,
            'indirect' => $indirect,
            'literals' => $literals,
        ];
    }

    /**
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     */
    private static function caseName(array $tokens, int $index): string | null
    {
        $next = $tokens[$index + 1] ?? null;
        $name = $tokens[$index + 2] ?? null;

        if (
            !\is_array($next)
            || $next[0] !== T_DOUBLE_COLON
            || !\is_array($name)
            || $name[0] !== T_STRING
        ) {
            return null;
        }

        return $name[1];
    }

    /**
     * Every vocabulary case named anywhere in src — including the ones a
     * throw site receives through a variable (a lock timeout picked by mode,
     * a DTO mismatch reason, an index corruption reason).
     *
     * @return array<string,true>
     */
    private static function mentionedCases(): array
    {
        $mentioned = [];
        $root = \dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);

            if (
                !$file->isFile()
                || $file->getExtension() !== 'php'
                || str_contains($file->getPathname(), '/Exception/Locale/')
            ) {
                continue;
            }

            preg_match_all(
                '/JsonProviderErrorEn::(\w+)/',
                (string)file_get_contents($file->getPathname()),
                $matches,
            );

            foreach ($matches[1] as $name) {
                $mentioned[$name] = true;
            }
        }

        return $mentioned;
    }

    private static function dbPathRoot(): string
    {
        return TempDir::root('jp-vocabulary-tests');
    }
}
