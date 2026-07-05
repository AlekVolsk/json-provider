<?php

// phpcs:ignoreFile

use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = PhpCsFixer\Finder::create()
    ->ignoreVCS(true)
    ->ignoreDotFiles(true)
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/build-dev/php-cs-fixer-cache.json')
    ->setRules([
        '@PSR12'            => true, // PSR-12: базовый стиль оформления
        '@PSR12:risky'      => true, // PSR-12, risky-набор
        '@PhpCsFixer'       => true, // доп. строгий стиль PhpCsFixer
        '@PhpCsFixer:risky' => true, // PhpCsFixer, risky-набор

        'no_trailing_whitespace_in_string'      => false, // не чистить хвостовые пробелы внутри строк
        'no_unreachable_default_argument_value' => false, // не переупорядочивать дефолтные аргументы
        'no_unset_on_property'                  => false, // разрешить unset() свойства (не менять на = null)
        'no_useless_sprintf'                    => false, // не убирать sprintf() с одним аргументом
        'final_internal_class'                  => false, // не помечать @internal-классы как final

        // Стилевые правила пресетов, отключённые осознанно
        'ordered_class_elements'     => true, // не переупорядочивать члены класса
        'yoda_style'                 => false, // не навязывать йода-условия
        'no_superfluous_phpdoc_tags' => true, // не удалять «избыточные» @param/@return/@var
        'phpdoc_to_comment'          => true, // не превращать docblock не на месте в // -комментарий
        'operator_linebreak'         => true, // не навязывать позицию оператора при переносе

        // Импорты: держим FQCN, const-группа первой
        'fully_qualified_strict_types' => true, // типы держим полными (import_symbols=false, без импорта)
        'ordered_imports'              => [ // сортировка use: сначала const-группа, внутри — по алфавиту
            'sort_algorithm' => 'alpha',
            'imports_order'  => ['const', 'class', 'function'],
        ],

        // Настройка поведения правил пресетов
        'increment_style'             => ['style' => 'post'], // постфиксные ++/-- ($i++, а не ++$i)
        'nullable_type_declaration'   => ['syntax' => 'union'], // nullable как int|null, а не ?int
        'align_multiline_comment'     => ['comment_type' => 'phpdocs_like'], // выравнивать * в многострочных docblock
        'cast_spaces'                 => ['space' => 'none'], // без пробела в приведении типа: (int)$x
        'types_spaces'                => ['space' => 'single'], // пробелы вокруг | в union-типах
        'concat_space'                => ['spacing' => 'one'], // пробелы вокруг . конкатенации
        'blank_line_before_statement' => [ // пустая строка перед перечисленными конструкциями
            'statements' => [
                'case', 'default', 'declare', 'do', 'for', 'foreach',
                'if', 'return', 'switch', 'try', 'while', 'phpdoc',
            ],
        ],
        'multiline_whitespace_before_semicolons' => [ // ; не выносить на отдельную строку
            'strategy' => 'no_multi_line',
        ],
        'binary_operator_spaces' => [ // пробелы вокруг бинарных операторов; => выравнивать по минимуму
            'operators' => [
                '='  => 'single_space',
                '=>' => 'align_single_space_minimal',
            ],
        ],

        'phpdoc_line_span' => [ // Многострочность doc-блоков: const/property — однострочный, method — многострочный
            'const'    => 'single',
            'property' => 'single',
            'method'   => 'multi',
        ],
    ])
    ->setFinder($finder);
