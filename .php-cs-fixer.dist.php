<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;
use Symfony\Component\Finder\Finder;

return (new Config('default'))
    ->setCacheFile('.php-cs-fixer.cache')
    ->setFinder(
        Finder::create()
            ->files()
            ->name('/\.php$/')
            ->exclude('vendor')
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
    )
    ->setFormat('txt')
    ->setHideProgress(false)
    ->setIndent('    ')
    ->setLineEnding("\n")
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setPhpExecutable(null)
    ->setRiskyAllowed(false)
    ->setRules([
        'binary_operator_spaces' => [
            'default' => 'at_least_single_space',
            'operators' => [],
        ],
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'blank_line_between_import_groups' => true,
        'blank_lines_before_namespace' => [
            'min_line_breaks' => 2,
            'max_line_breaks' => 2,
        ],
        'braces_position' => [
            'allow_single_line_anonymous_functions' => false,
            'allow_single_line_empty_anonymous_classes' => true,
            'anonymous_classes_opening_brace' => 'same_line',
            'anonymous_functions_opening_brace' => 'same_line',
            'classes_opening_brace' => 'next_line_unless_newline_at_signature_end',
            'control_structures_opening_brace' => 'same_line',
            'functions_opening_brace' => 'next_line_unless_newline_at_signature_end',
        ],
        'class_definition' => [
            'inline_constructor_arguments' => false,
            'multi_line_extends_each_single_line' => false,
            'single_item_single_line' => false,
            'single_line' => false,
            'space_before_parenthesis' => true,
        ],
        'compact_nullable_type_declaration' => true,
        'constant_case' => [
            'case' => 'lower',
        ],
        'control_structure_braces' => true,
        'control_structure_continuation_position' => [
            'position' => 'same_line',
        ],
        'declare_equal_normalize' => [
            'space' => 'none',
        ],
        'elseif' => true,
        'encoding' => true,
        'full_opening_tag' => true,
        'function_declaration' => [
            'closure_fn_spacing' => 'one',
            'closure_function_spacing' => 'one',
            'trailing_comma_single_line' => false,
        ],
        'indentation_type' => true,
        'line_ending' => true,
        'lowercase_cast' => true,
        'lowercase_keywords' => true,
        'lowercase_static_reference' => true,
        'method_argument_space' => [
            'after_heredoc' => false,
            'attribute_placement' => 'ignore',
            'keep_multiple_spaces_after_comma' => false,
            'on_multiline' => 'ensure_fully_multiline',
        ],
        'modifier_keywords' => [
            'elements' => [
                'const',
                'method',
                'property',
            ],
        ],
        'new_with_parentheses' => [
            'anonymous_class' => true,
            'named_class' => true,
        ],
        'no_blank_lines_after_class_opening' => true,
        'no_break_comment' => [
            'comment_text' => 'no break',
        ],
        'no_closing_tag' => true,
        'no_extra_blank_lines' => [
            'tokens' => [
                'use',
            ],
        ],
        'no_leading_import_slash' => true,
        'no_multiple_statements_per_line' => true,
        'no_space_around_double_colon' => true,
        'no_spaces_after_function_name' => true,
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,
        'no_whitespace_in_blank_line' => true,
        'ordered_class_elements' => [
            'case_sensitive' => false,
            'order' => [
                'use_trait',
            ],
            'sort_algorithm' => 'none',
        ],
        'ordered_imports' => [
            'case_sensitive' => false,
            'imports_order' => [
                'class',
                'function',
                'const',
            ],
            'sort_algorithm' => 'none',
        ],
        'return_type_declaration' => [
            'space_before' => 'none',
        ],
        'short_scalar_cast' => true,
        'single_blank_line_at_eof' => true,
        'single_class_element_per_statement' => [
            'elements' => [
                'property',
            ],
        ],
        'single_import_per_statement' => [
            'group_to_single_imports' => false,
        ],
        'single_line_after_imports' => true,
        'single_space_around_construct' => [
            'constructs_contain_a_single_space' => [
                'yield_from',
            ],
            'constructs_followed_by_a_single_space' => [
                'abstract',
                'as',
                'case',
                'catch',
                'class',
                'const_import',
                'do',
                'else',
                'elseif',
                'final',
                'finally',
                'for',
                'foreach',
                'function',
                'function_import',
                'if',
                'insteadof',
                'interface',
                'namespace',
                'new',
                'private',
                'protected',
                'public',
                'static',
                'switch',
                'trait',
                'try',
                'use',
                'use_lambda',
                'while',
            ],
            'constructs_preceded_by_a_single_space' => [
                'as',
                'else',
                'elseif',
                'use_lambda',
            ],
        ],
        'single_trait_insert_per_statement' => true,
        'spaces_inside_parentheses' => [
            'space' => 'none',
        ],
        'statement_indentation' => [
            'stick_comment_to_next_continuous_control_statement' => false,
        ],
        'switch_case_semicolon_to_colon' => true,
        'switch_case_space' => true,
        'ternary_operator_spaces' => true,
        'unary_operator_spaces' => [
            'only_dec_inc' => true,
        ],
    ])
    ->setUsingCache(true)
    ->setUnsupportedPhpVersionAllowed(
        false !== getenv('PHP_CS_FIXER_IGNORE_ENV')
            ? filter_var(getenv('PHP_CS_FIXER_IGNORE_ENV'), FILTER_VALIDATE_BOOL)
            : false
    );
