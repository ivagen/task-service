<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/behaviors',
        __DIR__ . '/components',
        __DIR__ . '/controllers',
        __DIR__ . '/migrations',
        __DIR__ . '/models',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR2' => true,
        'no_empty_phpdoc' => true,
        'no_unused_imports' => true,
        'single_blank_line_before_namespace' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'length'],
        'blank_line_before_statement' => [
            'statements' => [
                'return',
            ],
        ],
        'align_multiline_comment' => true,
        'trailing_comma_in_multiline' => [
            'after_heredoc' => true,
            'elements' => ['arguments', 'arrays', 'match', 'parameters'],
        ],
        'single_space_after_construct' => [
            'constructs' => [
                'abstract', 'as', 'attribute', 'break', 'case', 'catch', 'class', 'clone', 'comment', 'const',
                'const_import', 'continue', 'do', 'echo', 'else', 'elseif', 'enum', 'extends', 'final', 'finally',
                'for', 'foreach', 'function', 'function_import', 'global', 'goto', 'if', 'implements', 'include',
                'include_once', 'instanceof', 'insteadof', 'interface', 'match', 'named_argument', 'namespace', 'new',
                'open_tag_with_echo', 'php_doc', 'php_open', 'print', 'private', 'protected', 'public', 'readonly',
                'require', 'require_once', 'return', 'static', 'switch', 'throw', 'trait', 'try', 'type_colon', 'use',
                'use_lambda', 'use_trait', 'var', 'while', 'yield', 'yield_from',
            ],
        ],
        'binary_operator_spaces' => [
            'default' => 'single_space',
        ],
        'visibility_required' => [
            'elements' => ['method'],
        ],
    ])
    ->setFinder($finder);
