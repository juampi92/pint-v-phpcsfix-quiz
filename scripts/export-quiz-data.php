<?php

declare(strict_types=1);

namespace {
    use PhpCsFixer\Config;
    use PhpCsFixer\ConfigInterface;
    use PhpCsFixer\Fixer\ConfigurableFixerInterface;
    use PhpCsFixer\Fixer\FixerInterface;
    use PhpCsFixer\FixerFactory;
    use PhpCsFixer\FixerDefinition\CodeSampleInterface;
    use PhpCsFixer\FixerDefinition\FileSpecificCodeSampleInterface;
    use PhpCsFixer\RuleSet\RuleSet;
    use PhpCsFixer\Tokenizer\Tokens;

    const DEFAULT_DIFFERENCES = 'generated/pint-php-cs-fixer-differences.json';
    const DEFAULT_OVERRIDES = 'config/quiz-sample-overrides.json';
    const DEFAULT_OUTPUT = 'generated/pint-php-cs-fixer-quiz.json';
    const QUIZ_SCHEMA_VERSION = 1;

    function usage(): never
    {
        $script = basename(__FILE__);

        fwrite(STDERR, <<<TXT
Usage:
  php scripts/{$script} [--input=generated/pint-php-cs-fixer-differences.json] [--overrides=config/quiz-sample-overrides.json] [--output=generated/pint-php-cs-fixer-quiz.json]

TXT);

        exit(1);
    }

    function parseOptions(array $argv): array
    {
        $options = [
            'input' => DEFAULT_DIFFERENCES,
            'overrides' => DEFAULT_OVERRIDES,
            'output' => DEFAULT_OUTPUT,
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

            usage();
        }

        return $options;
    }

    function repositoryRoot(): string
    {
        return dirname(__DIR__);
    }

    function repositoryPath(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        return repositoryRoot().'/'.$path;
    }

    function repositoryRelativePath(string $absolutePath): string
    {
        $root = str_replace('\\', '/', repositoryRoot());
        $path = str_replace('\\', '/', $absolutePath);

        if (str_starts_with($path, $root.'/')) {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }

    function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $path));
        }
    }

    function preparePintPhar(): array
    {
        $sourcePath = repositoryPath('vendor/laravel/pint/builds/pint');

        if (!is_file($sourcePath)) {
            throw new RuntimeException('Could not find the Pint binary under vendor/laravel/pint/builds/pint. Run composer install first.');
        }

        $targetPath = sys_get_temp_dir().'/php-cs-fixer-defaults-pint.phar';
        $copyRequired = !is_file($targetPath);

        if (!$copyRequired) {
            $copyRequired = hash_file('sha256', $sourcePath) !== hash_file('sha256', $targetPath);
        }

        if ($copyRequired && !copy($sourcePath, $targetPath)) {
            throw new RuntimeException(sprintf('Could not copy Pint PHAR from "%s" to "%s".', $sourcePath, $targetPath));
        }

        return [
            'binary_path' => $sourcePath,
            'phar_path' => $targetPath,
            'phar_root' => 'phar://'.$targetPath,
        ];
    }

    function requirePintAutoloader(string $pharRoot): void
    {
        require_once $pharRoot.'/vendor/autoload.php';
    }

    function isListArray(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    function normalizeOutputValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (isListArray($value)) {
            return array_map(static fn (mixed $item): mixed => normalizeOutputValue($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = normalizeOutputValue($item);
        }

        return $value;
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

    function newFixerFactory(ConfigInterface $config): FixerFactory
    {
        $factory = new FixerFactory();
        $factory->registerBuiltInFixers();

        if (class_exists(App\Fixers\TypeAnnotationsOnlyFixer::class)) {
            $factory->registerCustomFixers([
                new App\Fixers\TypeAnnotationsOnlyFixer(),
            ]);
        }

        $factory->setWhitespacesConfig(
            new PhpCsFixer\WhitespacesFixerConfig($config->getIndent(), $config->getLineEnding()),
        );

        return $factory;
    }

    function loadJsonFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Could not find required file "%s".', $path));
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
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

    function findFixerForRule(FixerFactory $factory, string $rule): FixerInterface
    {
        foreach ($factory->getFixers() as $fixer) {
            if ($fixer->getName() === $rule) {
                return $fixer;
            }
        }

        throw new RuntimeException(sprintf('Could not resolve fixer instance for "%s".', $rule));
    }

    function temporarySampleRoot(): string
    {
        $path = sys_get_temp_dir().'/php-cs-fixer-defaults-quiz-samples';
        ensureDirectory($path);

        return $path;
    }

    function writeTemporarySample(string $relativePath, string $code): string
    {
        $hash = substr(hash('sha256', $relativePath."\0".$code), 0, 12);
        $normalizedRelativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $targetPath = temporarySampleRoot().'/'.$hash.'/'.$normalizedRelativePath;
        ensureDirectory(dirname($targetPath));

        if (file_put_contents($targetPath, $code) === false) {
            throw new RuntimeException(sprintf('Could not write temporary sample "%s".', $targetPath));
        }

        return $targetPath;
    }

    function ruleConfigForFactory(array $state): mixed
    {
        if (!$state['enabled']) {
            return false;
        }

        return $state['parameters'] ?? true;
    }

    function renderRuleOutput(string $rule, array $state, string $code, string $relativePath, ConfigInterface $config): string
    {
        if (!$state['enabled']) {
            return $code;
        }

        $absolutePath = writeTemporarySample($relativePath, $code);
        $factory = newFixerFactory($config);
        $factory->useRuleSet(new RuleSet([$rule => ruleConfigForFactory($state)]));
        $fixer = findFixerForRule($factory, $rule);
        $tokens = Tokens::fromCode($code);
        $file = new SplFileInfo($absolutePath);

        if ($fixer->supports($file) && $fixer->isCandidate($tokens)) {
            $fixer->fix($file, $tokens);
        }

        return $tokens->generateCode();
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
        $phpMatch = sampleMatchesState($sampleConfiguration, $difference['php_cs_fixer']['parameters']);

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

        if ($sampleConfiguration === null && ($difference['pint']['parameters'] === null || $difference['php_cs_fixer']['parameters'] === null)) {
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
        return [
            'rule' => $difference['rule'],
            'category' => $difference['category'],
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
                'enabled' => $difference['php_cs_fixer']['enabled'],
                'parameters' => $difference['php_cs_fixer']['parameters'],
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

        $overrides = loadOverrides($overridesPath);
        $config = new Config();
        $questions = [];
        $skipped = [];

        foreach ($differencesDocument['differences'] as $difference) {
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
                    $pintOutput = renderRuleOutput($rule, $difference['pint'], $candidate['code'], $candidate['file_path'], $config);
                    $phpCsFixerOutput = renderRuleOutput($rule, $difference['php_cs_fixer'], $candidate['code'], $candidate['file_path'], $config);
                } catch (\Throwable $exception) {
                    $errors[] = $exception->getMessage();

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
                $reason .= ' Sample errors: '.implode(' | ', array_unique($errors));
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
            'differences_total' => count($differencesDocument['differences']),
            'quiz_questions' => count($questions),
            'skipped' => count($skipped),
            'only_php_cs_fixer' => count(array_filter($questions, static fn (array $question): bool => $question['category'] === 'only_php_cs_fixer')),
            'only_pint' => count(array_filter($questions, static fn (array $question): bool => $question['category'] === 'only_pint')),
            'different_configuration' => count(array_filter($questions, static fn (array $question): bool => $question['category'] === 'different_configuration')),
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

        ensureDirectory(dirname($outputPath));

        $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        file_put_contents($outputPath, $json);
    }

    main($argv);
}
