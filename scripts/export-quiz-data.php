<?php

declare(strict_types=1);

namespace {
    require_once __DIR__.'/lib/export-runtime.php';

    use PhpCsFixer\Fixer\FixerInterface;
    use PhpCsFixer\FixerDefinition\CodeSampleInterface;
    use PhpCsFixer\FixerDefinition\FileSpecificCodeSampleInterface;
    use function PhpCsFixerDefaults\Scripts\isListArray;
    use function PhpCsFixerDefaults\Scripts\loadDefaultPhpCsFixerConfig;
    use function PhpCsFixerDefaults\Scripts\loadJsonFile;
    use function PhpCsFixerDefaults\Scripts\loadPintConfig;
    use function PhpCsFixerDefaults\Scripts\normalizeOutputValue;
    use function PhpCsFixerDefaults\Scripts\preparePintPhar;
    use function PhpCsFixerDefaults\Scripts\renderRuleOutput;
    use function PhpCsFixerDefaults\Scripts\repositoryPath;
    use function PhpCsFixerDefaults\Scripts\repositoryRelativePath;
    use function PhpCsFixerDefaults\Scripts\requirePintAutoloader;
    use function PhpCsFixerDefaults\Scripts\writeJson;

    const DEFAULT_DIFFERENCES = 'generated/pint-php-cs-fixer-differences.json';
    const DEFAULT_OVERRIDES = 'config/quiz-sample-overrides.json';
    const DEFAULT_OUTPUT = 'generated/pint-php-cs-fixer-quiz.json';
    const QUIZ_SCHEMA_VERSION = 3;

    function usage(): never
    {
        $script = basename(__FILE__);

        fwrite(STDERR, <<<TXT
Usage:
  php scripts/{$script} [--input=generated/pint-php-cs-fixer-differences.json] [--overrides=config/quiz-sample-overrides.json] [--output=generated/pint-php-cs-fixer-quiz.json] [--rule=array_indentation]

TXT);

        exit(1);
    }

    function parseOptions(array $argv): array
    {
        $options = [
            'input' => DEFAULT_DIFFERENCES,
            'overrides' => DEFAULT_OVERRIDES,
            'output' => DEFAULT_OUTPUT,
            'rule' => null,
        ];

        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '--input=')) {
                $options['input'] = substr($arg, strlen('--input='));

                continue;
            }

            if (str_starts_with($arg, '--overrides=')) {
                $options['overrides'] = substr($arg, strlen('--overrides='));

                continue;
            }

            if (str_starts_with($arg, '--output=')) {
                $options['output'] = substr($arg, strlen('--output='));

                continue;
            }

            if (str_starts_with($arg, '--rule=')) {
                $rule = substr($arg, strlen('--rule='));

                if (!is_string($rule) || $rule === '') {
                    usage();
                }

                $options['rule'] = $rule;

                continue;
            }

            usage();
        }

        return $options;
    }

    function valueContains(mixed $container, mixed $needle): bool
    {
        if ($needle === null) {
            return $container === null;
        }

        if (!is_array($needle)) {
            return $container === $needle;
        }

        if (!is_array($container)) {
            return false;
        }

        if (isListArray($needle)) {
            if (!isListArray($container)) {
                return false;
            }

            foreach ($needle as $needleItem) {
                $found = false;

                foreach ($container as $containerItem) {
                    if (valueContains($containerItem, $needleItem)) {
                        $found = true;

                        break;
                    }
                }

                if (!$found) {
                    return false;
                }
            }

            return true;
        }

        foreach ($needle as $key => $value) {
            if (!array_key_exists($key, $container) || !valueContains($container[$key], $value)) {
                return false;
            }
        }

        return true;
    }

    function sampleMatchesState(mixed $sampleConfiguration, ?array $stateParameters): array
    {
        $normalizedParameters = normalizeOutputValue($stateParameters);
        $normalizedSample = normalizeOutputValue($sampleConfiguration);

        return [
            'exact' => $normalizedSample === $normalizedParameters,
            'subset' => $normalizedSample !== null && valueContains($normalizedParameters, $normalizedSample),
        ];
    }

    function phpCsFixerComparisonState(array $difference): array
    {
        $state = $difference['php_cs_fixer']['comparison'] ?? $difference['php_cs_fixer'] ?? null;

        if (!is_array($state) || !array_key_exists('enabled', $state) || !array_key_exists('parameters', $state)) {
            throw new RuntimeException(sprintf('Difference entry for "%s" does not expose a PHP-CS-Fixer comparison state.', $difference['rule'] ?? 'unknown'));
        }

        return $state;
    }

    function loadOverrides(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = loadJsonFile($path);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Expected "%s" to decode to an object.', $path));
        }

        ksort($decoded);

        return $decoded;
    }

    function candidateFromCodeSample(string $rule, CodeSampleInterface $sample, int $index): array
    {
        $relativePath = 'samples/'.$rule.'.php';

        if ($sample instanceof FileSpecificCodeSampleInterface) {
            $samplePath = $sample->getSplFileInfo()->getPathname();
            $relativePath = 'samples/'.basename($samplePath);
        }

        return [
            'origin' => 'code_sample',
            'code' => $sample->getCode(),
            'file_path' => $relativePath,
            'sample_index' => $index,
            'sample_configuration' => normalizeOutputValue($sample->getConfiguration()),
            'reason' => null,
        ];
    }

    function candidateFromOverride(string $rule, array $override): array
    {
        if (!isset($override['code']) || !is_string($override['code'])) {
            throw new RuntimeException(sprintf('Override for "%s" must define a string "code" field.', $rule));
        }

        $relativePath = $override['file_path'] ?? 'samples/'.$rule.'.php';

        if (!is_string($relativePath) || $relativePath === '') {
            throw new RuntimeException(sprintf('Override for "%s" must define a non-empty string "file_path".', $rule));
        }

        return [
            'origin' => 'override',
            'code' => $override['code'],
            'file_path' => $relativePath,
            'sample_index' => null,
            'sample_configuration' => null,
            'reason' => $override['reason'] ?? null,
        ];
    }

    function stableSideForPint(string $rule): string
    {
        return hexdec(substr(hash('sha256', $rule), 0, 2)) % 2 === 0 ? 'left' : 'right';
    }

    function sampleScore(array $candidate, array $difference, string $pintOutput, string $phpOutput): int
    {
        $phpCsFixerState = phpCsFixerComparisonState($difference);
        $score = 0;
        $sourceCode = $candidate['code'];
        $pintChanged = $pintOutput !== $sourceCode;
        $phpChanged = $phpOutput !== $sourceCode;
        $outputsDiffer = $pintOutput !== $phpOutput;

        if ($candidate['origin'] === 'override') {
            $score += 1_000;
        }

        if ($outputsDiffer) {
            $score += 100;
        }

        if ($pintChanged) {
            $score += 10;
        }

        if ($phpChanged) {
            $score += 10;
        }

        if ($pintChanged && $phpChanged) {
            $score += 5;
        }

        $sampleConfiguration = $candidate['sample_configuration'];
        $pintMatch = sampleMatchesState($sampleConfiguration, $difference['pint']['parameters']);
        $phpMatch = sampleMatchesState($sampleConfiguration, $phpCsFixerState['parameters']);

        if ($pintMatch['exact']) {
            $score += 30;
        } elseif ($pintMatch['subset']) {
            $score += 15;
        }

        if ($phpMatch['exact']) {
            $score += 30;
        } elseif ($phpMatch['subset']) {
            $score += 15;
        }

        if (($pintMatch['exact'] || $pintMatch['subset']) xor ($phpMatch['exact'] || $phpMatch['subset'])) {
            $score += 20;
        }

        if ($sampleConfiguration === null && ($difference['pint']['parameters'] === null || $phpCsFixerState['parameters'] === null)) {
            $score += 5;
        }

        return $score;
    }

    function compareCandidateMetadata(array $candidate, array $difference, string $pintOutput, string $phpOutput): array
    {
        $sourceCode = $candidate['code'];

        return [
            'origin' => $candidate['origin'],
            'file_path' => $candidate['file_path'],
            'sample_index' => $candidate['sample_index'],
            'sample_configuration' => $candidate['sample_configuration'],
            'reason' => $candidate['reason'],
            'score' => sampleScore($candidate, $difference, $pintOutput, $phpOutput),
            'outputs_differ' => $pintOutput !== $phpOutput,
            'pint_changed' => $pintOutput !== $sourceCode,
            'php_cs_fixer_changed' => $phpOutput !== $sourceCode,
        ];
    }

    function buildQuestion(array $difference, array $candidate, array $metadata, string $pintOutput, string $phpOutput, array $fixerMetadata): array
    {
        $phpCsFixerState = phpCsFixerComparisonState($difference);

        return [
            'rule' => $difference['rule'],
            'category' => $difference['category'],
            'comparison' => $difference['comparison'] ?? [
                'case' => 'php_cs_fixer_default_vs_pint',
                'php_cs_fixer_side' => 'raw_default',
            ],
            'presentation' => [
                'pint_side' => stableSideForPint($difference['rule']),
            ],
            'fixer' => $fixerMetadata,
            'source' => [
                'code' => $candidate['code'],
                'file_path' => $candidate['file_path'],
                'origin' => $candidate['origin'],
                'sample_index' => $candidate['sample_index'],
                'sample_configuration' => $candidate['sample_configuration'],
                'selection_reason' => $candidate['reason'],
            ],
            'pint' => [
                'enabled' => $difference['pint']['enabled'],
                'parameters' => $difference['pint']['parameters'],
                'output' => $pintOutput,
                'changed' => $metadata['pint_changed'],
            ],
            'php_cs_fixer' => [
                'enabled' => $phpCsFixerState['enabled'],
                'parameters' => $phpCsFixerState['parameters'],
                'output' => $phpOutput,
                'changed' => $metadata['php_cs_fixer_changed'],
            ],
            'selection' => [
                'score' => $metadata['score'],
                'outputs_differ' => $metadata['outputs_differ'],
            ],
        ];
    }

    function buildFixerMetadata(FixerInterface $fixer, array $difference): array
    {
        $definition = $fixer->getDefinition();

        return [
            'class' => $difference['fixer']['class'],
            'configurable' => $difference['fixer']['configurable'],
            'path' => $difference['fixer']['path'],
            'summary' => $definition->getSummary(),
            'description' => $definition->getDescription(),
            'is_risky' => $fixer->isRisky(),
            'risky_description' => $definition->getRiskyDescription(),
        ];
    }

    function writeCliWarning(string $rule, string $message, array $errors = []): void
    {
        fwrite(STDERR, sprintf("[warn] %s: %s\n", $rule, $message));

        foreach ($errors as $error) {
            fwrite(
                STDERR,
                sprintf(
                    "  - origin: %s\n    file: %s\n    error: %s\n    code:\n%s\n",
                    $error['origin'],
                    $error['file_path'],
                    $error['message'],
                    preg_replace('/^/m', '      ', rtrim($error['code'])),
                ),
            );
        }
    }

    function filterDifferencesByRule(array $differences, ?string $rule): array
    {
        if ($rule === null) {
            return $differences;
        }

        return array_values(
            array_filter(
                $differences,
                static fn (array $difference): bool => ($difference['rule'] ?? null) === $rule,
            ),
        );
    }

    function main(array $argv): void
    {
        $options = parseOptions($argv);
        $inputPath = repositoryPath($options['input']);
        $overridesPath = repositoryPath($options['overrides']);
        $outputPath = repositoryPath($options['output']);

        $phar = preparePintPhar();
        requirePintAutoloader($phar['phar_root']);

        $differencesDocument = loadJsonFile($inputPath);
        if (!isset($differencesDocument['differences']) || !is_array($differencesDocument['differences'])) {
            throw new RuntimeException(sprintf('Expected "%s" to contain a differences array.', $inputPath));
        }

        $differences = filterDifferencesByRule($differencesDocument['differences'], $options['rule']);
        $overrides = loadOverrides($overridesPath);
        $preset = is_string($differencesDocument['preset'] ?? null) ? $differencesDocument['preset'] : 'laravel';
        $pintConfig = loadPintConfig($phar['phar_root'], $preset);
        $phpCsFixerConfig = loadDefaultPhpCsFixerConfig();
        $questions = [];
        $skipped = [];

        foreach ($differences as $difference) {
            $rule = $difference['rule'];

            if (($overrides[$rule]['skip'] ?? false) === true) {
                $skipped[] = [
                    'rule' => $rule,
                    'category' => $difference['category'],
                    'reason' => $overrides[$rule]['reason'] ?? 'Skipped by override.',
                ];

                continue;
            }

            $fixer = new $difference['fixer']['class']();
            $fixerMetadata = buildFixerMetadata($fixer, $difference);
            $candidates = [];

            if (array_key_exists($rule, $overrides) && !($overrides[$rule]['skip'] ?? false)) {
                $candidates[] = candidateFromOverride($rule, $overrides[$rule]);
            } else {
                foreach ($fixer->getDefinition()->getCodeSamples() as $index => $sample) {
                    $candidates[] = candidateFromCodeSample($rule, $sample, $index);
                }
            }

            $bestQuestion = null;
            $bestScore = null;
            $errors = [];

            foreach ($candidates as $candidate) {
                try {
                    $pintOutput = renderRuleOutput($rule, $difference['pint'], $candidate['code'], $candidate['file_path'], $pintConfig);
                    $phpCsFixerOutput = renderRuleOutput($rule, phpCsFixerComparisonState($difference), $candidate['code'], $candidate['file_path'], $phpCsFixerConfig);
                } catch (\Throwable $exception) {
                    $errors[] = [
                        'origin' => $candidate['origin'],
                        'file_path' => $candidate['file_path'],
                        'code' => $candidate['code'],
                        'message' => $exception->getMessage(),
                    ];

                    continue;
                }

                $metadata = compareCandidateMetadata($candidate, $difference, $pintOutput, $phpCsFixerOutput);

                if ($bestScore === null || $metadata['score'] > $bestScore) {
                    $bestScore = $metadata['score'];
                    $bestQuestion = buildQuestion($difference, $candidate, $metadata, $pintOutput, $phpCsFixerOutput, $fixerMetadata);
                }
            }

            if ($bestQuestion !== null && $bestQuestion['selection']['outputs_differ']) {
                $questions[] = $bestQuestion;

                continue;
            }

            $reason = $bestQuestion === null
                ? 'No sample could be rendered for this rule on the local PHP runtime.'
                : 'No evaluated sample produced different Pint and PHP-CS-Fixer outputs for this rule.';

            if ($errors !== []) {
                $reason .= ' Sample errors: '.implode(
                    ' | ',
                    array_values(array_unique(array_map(static fn (array $error): string => $error['message'], $errors))),
                );
                writeCliWarning($rule, $reason, $errors);
            }

            $skipped[] = [
                'rule' => $rule,
                'category' => $difference['category'],
                'reason' => $reason,
            ];
        }

        usort(
            $questions,
            static fn (array $left, array $right): int => [$left['category'], $left['rule']] <=> [$right['category'], $right['rule']],
        );

        usort(
            $skipped,
            static fn (array $left, array $right): int => [$left['category'], $left['rule']] <=> [$right['category'], $right['rule']],
        );

        $counts = [
            'differences_total' => count($differences),
            'quiz_questions' => count($questions),
            'skipped' => count($skipped),
            'missing_from_pint' => count(array_filter($questions, static fn (array $question): bool => $question['category'] === 'missing_from_pint')),
            'different_configuration' => count(array_filter($questions, static fn (array $question): bool => $question['category'] === 'different_configuration')),
            'pint_differs_from_rule_default' => count(array_filter($questions, static fn (array $question): bool => $question['category'] === 'pint_differs_from_rule_default')),
        ];

        $output = [
            'schema_version' => QUIZ_SCHEMA_VERSION,
            'generated_from' => repositoryRelativePath($inputPath),
            'overrides_path' => repositoryRelativePath($overridesPath),
            'package_versions' => $differencesDocument['package_versions'],
            'counts' => $counts,
            'questions' => $questions,
            'skipped' => $skipped,
        ];

        writeJson($outputPath, $output);
    }

    main($argv);
}
