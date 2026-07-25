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
        '@PSR12' => true,
        '@PSR12:risky' => true,
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        'no_trailing_whitespace_in_string' => false,
        'no_unreachable_default_argument_value' => false,
        'no_unset_on_property' => false,
        'no_useless_sprintf' => false,
        'final_internal_class' => false,
        'ordered_class_elements' => true,
        'yoda_style' => false,
        'no_superfluous_phpdoc_tags' => true,
        'single_line_empty_body' => false,
        'phpdoc_to_comment' => true,
        'operator_linebreak' => true,
        'fully_qualified_strict_types' => true,
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['const', 'class', 'function'],
        ],
        'increment_style' => ['style' => 'post'],
        'nullable_type_declaration' => ['syntax' => 'union'],
        'align_multiline_comment' => ['comment_type' => 'phpdocs_like'],
        'cast_spaces' => ['space' => 'none'],
        'types_spaces' => ['space' => 'single'],
        'concat_space' => ['spacing' => 'one'],
        'blank_line_before_statement' => [
            'statements' => [
                'case', 'default', 'declare', 'do', 'for', 'foreach',
                'if', 'return', 'switch', 'try', 'while', 'phpdoc',
            ],
        ],
        'multiline_whitespace_before_semicolons' => [
            'strategy' => 'no_multi_line',
        ],
        'binary_operator_spaces' => [
            'operators' => [
                '=' => 'single_space',
                '=>' => 'align_single_space_minimal',
            ],
        ],
        'phpdoc_line_span' => [
            'const' => 'single',
            'property' => 'single',
            'method' => 'multi',
        ],
    ])
    ->setFinder($finder);
