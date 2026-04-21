<?php

declare(strict_types=1);

namespace {
    require_once __DIR__.'/lib/export-runtime.php';

    use PhpCsFixer\ConfigInterface;
    use PhpCsFixer\Fixer\ConfigurableFixerInterface;
    use PhpCsFixer\Fixer\FixerInterface;
    use PhpCsFixer\RuleSet\RuleSets;
    use function PhpCsFixerDefaults\Scripts\loadDefaultPhpCsFixerConfig;
    use function PhpCsFixerDefaults\Scripts\loadPintConfig;
    use function PhpCsFixerDefaults\Scripts\newFixerFactory;
    use function PhpCsFixerDefaults\Scripts\normalizeOutputValue;
    use function PhpCsFixerDefaults\Scripts\normalizeRules;
    use function PhpCsFixerDefaults\Scripts\preparePintPhar;
    use function PhpCsFixerDefaults\Scripts\readPharInstalledVersion;
    use function PhpCsFixerDefaults\Scripts\readRootComposerLockVersion;
    use function PhpCsFixerDefaults\Scripts\repositoryPath;
    use function PhpCsFixerDefaults\Scripts\repositoryRelativePath;
    use function PhpCsFixerDefaults\Scripts\requirePintAutoloader;
    use function PhpCsFixerDefaults\Scripts\writeJson;

    const DEFAULT_OUTPUT = 'generated/pint-php-cs-fixer-differences.json';
    const DEFAULT_PRESET = 'laravel';
    const PINT_GITHUB_BASE = 'https://github.com/laravel/pint/blob/main/';
    const SCHEMA_VERSION = 4;

    function usage(): never
    {
        $script = basename(__FILE__);

        fwrite(STDERR, <<<TXT
Usage:
  php scripts/{$script} [--output=generated/pint-php-cs-fixer-differences.json] [--preset=laravel] [--rule=array_indentation]

TXT);

        exit(1);
    }

    function parseOptions(array $argv): array
    {
        $options = [
            'output' => DEFAULT_OUTPUT,
            'preset' => DEFAULT_PRESET,
            'rule' => null,
        ];

        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $options['output'] = substr($arg, strlen('--output='));

                continue;
            }

            if (str_starts_with($arg, '--preset=')) {
                $options['preset'] = substr($arg, strlen('--preset='));

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

    function phpCsFixerGithubBase(string $version): string
    {
        if ($version === '' || $version === 'unknown') {
            return 'https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/blob/master/';
        }

        return 'https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/blob/'.rawurlencode($version).'/';
    }

    function loadPintPresetSource(string $pharRoot, string $preset): array
    {
        $relativePath = sprintf('resources/presets/%s.php', $preset);
        $presetPath = sprintf('%s/%s', $pharRoot, $relativePath);

        if (!is_file($presetPath)) {
            throw new RuntimeException(sprintf('Could not find the "%s" Pint preset source inside the installed PHAR.', $preset));
        }

        $lines = file($presetPath);
        if ($lines === false) {
            throw new RuntimeException(sprintf('Could not read the "%s" Pint preset source.', $preset));
        }

        return [
            'relative_path' => $relativePath,
            'lines' => $lines,
        ];
    }

    function compareRules(array $phpCsFixerDefaults, array $pintRules, array $phpCsFixerRuleDefaults): array
    {
        $missingFromPint = array_keys(array_diff_key($phpCsFixerDefaults, $pintRules));
        sort($missingFromPint);

        $different = [];

        foreach (array_intersect(array_keys($phpCsFixerDefaults), array_keys($pintRules)) as $rule) {
            if ($phpCsFixerDefaults[$rule] !== $pintRules[$rule]) {
                $different[$rule] = [
                    'php_cs_fixer_raw_default' => $phpCsFixerDefaults[$rule],
                    'php_cs_fixer_rule_default' => $phpCsFixerRuleDefaults[$rule],
                    'pint' => $pintRules[$rule],
                ];
            }
        }

        ksort($different);

        $pintDiffersFromRuleDefault = [];

        foreach (array_keys(array_diff_key($pintRules, $phpCsFixerDefaults)) as $rule) {
            if (!array_key_exists($rule, $phpCsFixerRuleDefaults)) {
                throw new RuntimeException(sprintf('Could not resolve the PHP-CS-Fixer default rule configuration for "%s".', $rule));
            }

            if ($pintRules[$rule] === $phpCsFixerRuleDefaults[$rule]) {
                continue;
            }

            $pintDiffersFromRuleDefault[$rule] = [
                'php_cs_fixer_rule_default' => $phpCsFixerRuleDefaults[$rule],
                'pint' => $pintRules[$rule],
            ];
        }

        ksort($pintDiffersFromRuleDefault);

        return [
            'missing_from_pint' => $missingFromPint,
            'different_configuration' => $different,
            'pint_differs_from_rule_default' => $pintDiffersFromRuleDefault,
        ];
    }

    function normalizeRuleState(bool $enabled, mixed $value): array
    {
        if (!$enabled) {
            return [
                'enabled' => false,
                'parameters' => null,
            ];
        }

        return [
            'enabled' => true,
            'parameters' => $value === true ? null : normalizeOutputValue($value),
        ];
    }

    function collectFixerLookup(ConfigInterface $config): array
    {
        $lookup = [];

        foreach (newFixerFactory($config)->getFixers() as $fixer) {
            $lookup[$fixer->getName()] = $fixer;
        }

        ksort($lookup);

        return $lookup;
    }

    function normalizeSourcePathParts(string $sourcePath): array
    {
        if (!str_starts_with($sourcePath, 'phar://')) {
            return [
                'vendor_path' => repositoryRelativePath($sourcePath),
                'internal_path' => null,
            ];
        }

        $trimmed = substr($sourcePath, strlen('phar://'));
        $parts = explode('.phar/', $trimmed, 2);

        if (count($parts) !== 2) {
            return [
                'vendor_path' => repositoryRelativePath($sourcePath),
                'internal_path' => null,
            ];
        }

        $internalPath = $parts[1];

        return [
            'vendor_path' => repositoryRelativePath('vendor/laravel/pint/builds/pint').'::'.$internalPath,
            'internal_path' => $internalPath,
        ];
    }

    function buildGithubReference(string $baseUrl, string $path, int $startLine, int $endLine): array
    {
        $url = $baseUrl.$path.'#L'.$startLine;

        if ($endLine > $startLine) {
            $url .= '-L'.$endLine;
        }

        return [
            'url' => $url,
            'path' => $path,
            'start_line' => $startLine,
            'end_line' => $endLine,
        ];
    }

    function stripSingleQuotedStrings(string $line): string
    {
        return (string) preg_replace("/'[^'\\\\]*(?:\\\\.[^'\\\\]*)*'/", "''", $line);
    }

    function findRuleEntryEndLine(array $lines, int $startIndex, string $contextLabel): int
    {
        $depth = 0;
        $started = false;
        $lineCount = count($lines);

        for ($index = $startIndex; $index < $lineCount; ++$index) {
            $line = stripSingleQuotedStrings($lines[$index]);
            $fragment = $line;

            if (!$started) {
                $arrowPosition = strpos($line, '=>');
                if ($arrowPosition === false) {
                    throw new RuntimeException(sprintf('Could not locate rule assignment while parsing %s.', $contextLabel));
                }

                $fragment = substr($line, $arrowPosition + 2);
                $started = true;
            }

            $depth += substr_count($fragment, '[');
            $depth -= substr_count($fragment, ']');

            if ($depth <= 0 && str_ends_with(rtrim($fragment), ',')) {
                return $index;
            }
        }

        throw new RuntimeException(sprintf('Could not determine the end of a rule configuration block while parsing %s.', $contextLabel));
    }

    function collectRuleEntryReferences(array $source, string $githubBase, string $contextLabel): array
    {
        $references = [];
        $lines = $source['lines'];
        $relativePath = $source['relative_path'];
        $lineCount = count($lines);

        for ($index = 0; $index < $lineCount; ++$index) {
            if (!preg_match("/^\\s*'([^']+)'\\s*=>/", $lines[$index], $matches)) {
                continue;
            }

            $rule = $matches[1];
            $endIndex = findRuleEntryEndLine($lines, $index, $contextLabel);

            $references[$rule] = buildGithubReference(
                $githubBase,
                $relativePath,
                $index + 1,
                $endIndex + 1,
            );

            $index = $endIndex;
        }

        ksort($references);

        return $references;
    }

    function collectPintPresetRuleReferences(array $presetSource): array
    {
        return collectRuleEntryReferences($presetSource, PINT_GITHUB_BASE, 'the Pint preset');
    }

    function shouldSkipPhpCsFixerRuleset(string $name): bool
    {
        return str_starts_with($name, '@auto');
    }

    function newPhpCsFixerConfig(bool $riskyAllowed): ConfigInterface
    {
        $config = loadDefaultPhpCsFixerConfig();

        if (method_exists($config, 'setRiskyAllowed')) {
            $config->setRiskyAllowed($riskyAllowed);
        }

        return $config;
    }

    function collectPhpCsFixerRulesetStates(array $targetRules, string $phpCsFixerGithubBase): array
    {
        $statesByRule = [];

        foreach (RuleSets::getSetDefinitions() as $name => $definition) {
            if (shouldSkipPhpCsFixerRuleset($name)) {
                continue;
            }

            $definitionReflection = new ReflectionObject($definition);
            $sourcePath = $definitionReflection->getFileName() ?: 'unknown';
            $pathParts = normalizeSourcePathParts($sourcePath);
            $internalPath = $pathParts['internal_path'];
            $definitionReference = null;
            $directRuleReferences = [];

            if ($internalPath !== null && str_starts_with($internalPath, 'vendor/friendsofphp/php-cs-fixer/')) {
                $repositoryPath = substr($internalPath, strlen('vendor/friendsofphp/php-cs-fixer/'));
                $definitionReference = buildGithubReference(
                    $phpCsFixerGithubBase,
                    $repositoryPath,
                    $definitionReflection->getStartLine(),
                    $definitionReflection->getEndLine(),
                );

                $lines = file($sourcePath);
                if ($lines === false) {
                    throw new RuntimeException(sprintf('Could not read the PHP-CS-Fixer ruleset source for "%s".', $name));
                }

                $directRuleReferences = collectRuleEntryReferences(
                    [
                        'relative_path' => $repositoryPath,
                        'lines' => $lines,
                    ],
                    $phpCsFixerGithubBase,
                    sprintf('the PHP-CS-Fixer ruleset "%s"', $name),
                );
            }

            $effectiveRules = normalizeRules(
                [$name => true],
                newPhpCsFixerConfig($definition->isRisky()),
            );

            foreach ($effectiveRules as $rule => $value) {
                if (!isset($targetRules[$rule])) {
                    continue;
                }

                $declaredDirectly = isset($directRuleReferences[$rule]);

                $statesByRule[$rule][] = [
                    'name' => $definition->getName(),
                    'description' => $definition->getDescription(),
                    'risky' => $definition->isRisky(),
                    'declared_directly' => $declaredDirectly,
                    'state' => normalizeRuleState(true, $value),
                    'references' => [
                        'definition' => $definitionReference,
                        'rule' => $declaredDirectly ? ($directRuleReferences[$rule] ?? null) : null,
                    ],
                ];
            }
        }

        foreach ($statesByRule as &$ruleStates) {
            usort(
                $ruleStates,
                static fn (array $left, array $right): int => [
                    $left['declared_directly'] ? 0 : 1,
                    $left['risky'] ? 1 : 0,
                    $left['name'],
                ] <=> [
                    $right['declared_directly'] ? 0 : 1,
                    $right['risky'] ? 1 : 0,
                    $right['name'],
                ],
            );
        }
        unset($ruleStates);

        ksort($statesByRule);

        return $statesByRule;
    }

    function collectPhpCsFixerRuleDefaultStates(array $targetRules, array $fixerLookup): array
    {
        $states = [];

        foreach (array_keys($targetRules) as $rule) {
            if (!isset($fixerLookup[$rule])) {
                throw new RuntimeException(sprintf('Could not resolve fixer metadata for "%s".', $rule));
            }

            $fixer = $fixerLookup[$rule];
            $normalized = normalizeRules(
                [$rule => true],
                newPhpCsFixerConfig($fixer->isRisky()),
            );

            if (!array_key_exists($rule, $normalized)) {
                throw new RuntimeException(sprintf('Could not resolve the intrinsic PHP-CS-Fixer configuration for "%s".', $rule));
            }

            $states[$rule] = $normalized[$rule];
        }

        ksort($states);

        return $states;
    }

    function buildRuleSetMembership(array $ruleStates): array
    {
        $membership = [
            'direct' => [],
            'inherited' => [],
        ];

        foreach ($ruleStates as $ruleState) {
            if ($ruleState['declared_directly']) {
                $membership['direct'][] = $ruleState['name'];

                continue;
            }

            $membership['inherited'][] = $ruleState['name'];
        }

        return $membership;
    }

    function buildFixerMetadata(FixerInterface $fixer, string $phpCsFixerGithubBase): array
    {
        $reflection = new ReflectionClass($fixer);
        $sourcePath = $reflection->getFileName() ?: 'unknown';
        $pathParts = normalizeSourcePathParts($sourcePath);
        $internalPath = $pathParts['internal_path'];
        $githubReference = null;

        if ($internalPath !== null && str_starts_with($internalPath, 'vendor/friendsofphp/php-cs-fixer/')) {
            $repositoryPath = substr($internalPath, strlen('vendor/friendsofphp/php-cs-fixer/'));
            $githubReference = buildGithubReference(
                $phpCsFixerGithubBase,
                $repositoryPath,
                $reflection->getStartLine(),
                $reflection->getEndLine(),
            );
        }

        return [
            'class' => $fixer::class,
            'configurable' => $fixer instanceof ConfigurableFixerInterface,
            'path' => $pathParts['vendor_path'],
            'github' => $githubReference,
        ];
    }

    function buildDifferences(
        array $comparison,
        array $phpCsFixerRules,
        array $phpCsFixerRuleDefaults,
        array $pintRules,
        array $phpCsFixerRulesetStates,
        array $fixerLookup,
        array $pintPresetRuleReferences,
        string $phpCsFixerGithubBase
    ): array
    {
        $differences = [];

        foreach ($comparison['missing_from_pint'] as $rule) {
            if (!isset($fixerLookup[$rule])) {
                throw new RuntimeException(sprintf('Could not resolve fixer metadata for "%s".', $rule));
            }

            $fixerMetadata = buildFixerMetadata($fixerLookup[$rule], $phpCsFixerGithubBase);
            $ruleStates = $phpCsFixerRulesetStates[$rule] ?? [];

            $differences[] = [
                'rule' => $rule,
                'category' => 'missing_from_pint',
                'comparison' => [
                    'case' => 'php_cs_fixer_default_vs_pint',
                    'php_cs_fixer_side' => 'raw_default',
                ],
                'pint' => normalizeRuleState(false, null),
                'php_cs_fixer' => [
                    'comparison' => normalizeRuleState(true, $phpCsFixerRules[$rule]),
                    'raw_default' => normalizeRuleState(true, $phpCsFixerRules[$rule]),
                    'rule_default' => normalizeRuleState(true, $phpCsFixerRuleDefaults[$rule]),
                ],
                'php_cs_fixer_rule_set_membership' => buildRuleSetMembership($ruleStates),
                'php_cs_fixer_rulesets' => $ruleStates,
                'fixer' => $fixerMetadata,
                'references' => [
                    'php_cs_fixer' => $fixerMetadata['github'],
                    'pint' => $pintPresetRuleReferences[$rule] ?? null,
                ],
            ];
        }

        foreach ($comparison['pint_differs_from_rule_default'] as $rule => $values) {
            if (!isset($fixerLookup[$rule])) {
                throw new RuntimeException(sprintf('Could not resolve fixer metadata for "%s".', $rule));
            }

            $fixerMetadata = buildFixerMetadata($fixerLookup[$rule], $phpCsFixerGithubBase);
            $ruleStates = $phpCsFixerRulesetStates[$rule] ?? [];

            $differences[] = [
                'rule' => $rule,
                'category' => 'pint_differs_from_rule_default',
                'comparison' => [
                    'case' => 'pint_vs_php_cs_fixer_rule_default',
                    'php_cs_fixer_side' => 'rule_default',
                ],
                'pint' => normalizeRuleState(true, $values['pint']),
                'php_cs_fixer' => [
                    'comparison' => normalizeRuleState(true, $values['php_cs_fixer_rule_default']),
                    'raw_default' => normalizeRuleState(false, null),
                    'rule_default' => normalizeRuleState(true, $values['php_cs_fixer_rule_default']),
                ],
                'php_cs_fixer_rule_set_membership' => buildRuleSetMembership($ruleStates),
                'php_cs_fixer_rulesets' => $ruleStates,
                'fixer' => $fixerMetadata,
                'references' => [
                    'php_cs_fixer' => $fixerMetadata['github'],
                    'pint' => $pintPresetRuleReferences[$rule] ?? null,
                ],
            ];
        }

        foreach ($comparison['different_configuration'] as $rule => $values) {
            if (!isset($fixerLookup[$rule])) {
                throw new RuntimeException(sprintf('Could not resolve fixer metadata for "%s".', $rule));
            }

            $fixerMetadata = buildFixerMetadata($fixerLookup[$rule], $phpCsFixerGithubBase);
            $ruleStates = $phpCsFixerRulesetStates[$rule] ?? [];

            $differences[] = [
                'rule' => $rule,
                'category' => 'different_configuration',
                'comparison' => [
                    'case' => 'php_cs_fixer_default_vs_pint',
                    'php_cs_fixer_side' => 'raw_default',
                ],
                'pint' => normalizeRuleState(true, $values['pint']),
                'php_cs_fixer' => [
                    'comparison' => normalizeRuleState(true, $values['php_cs_fixer_raw_default']),
                    'raw_default' => normalizeRuleState(true, $values['php_cs_fixer_raw_default']),
                    'rule_default' => normalizeRuleState(true, $values['php_cs_fixer_rule_default']),
                ],
                'php_cs_fixer_rule_set_membership' => buildRuleSetMembership($ruleStates),
                'php_cs_fixer_rulesets' => $ruleStates,
                'fixer' => $fixerMetadata,
                'references' => [
                    'php_cs_fixer' => $fixerMetadata['github'],
                    'pint' => $pintPresetRuleReferences[$rule] ?? null,
                ],
            ];
        }

        usort(
            $differences,
            static fn (array $left, array $right): int => [$left['rule'], $left['category']] <=> [$right['rule'], $right['category']],
        );

        return $differences;
    }

    function comparisonRuleNames(array $comparison): array
    {
        $ruleNames = array_merge(
            $comparison['missing_from_pint'],
            array_keys($comparison['different_configuration']),
            array_keys($comparison['pint_differs_from_rule_default']),
        );

        $lookup = [];

        foreach ($ruleNames as $rule) {
            $lookup[$rule] = true;
        }

        ksort($lookup);

        return $lookup;
    }

    function filterComparisonByRule(array $comparison, ?string $rule): array
    {
        if ($rule === null) {
            return $comparison;
        }

        return [
            'missing_from_pint' => array_values(
                array_filter(
                    $comparison['missing_from_pint'],
                    static fn (string $candidate): bool => $candidate === $rule,
                ),
            ),
            'different_configuration' => isset($comparison['different_configuration'][$rule])
                ? [$rule => $comparison['different_configuration'][$rule]]
                : [],
            'pint_differs_from_rule_default' => isset($comparison['pint_differs_from_rule_default'][$rule])
                ? [$rule => $comparison['pint_differs_from_rule_default'][$rule]]
                : [],
        ];
    }

    function buildReport(
        string $preset,
        string $pintVersion,
        string $phpCsFixerVersion,
        array $phpCsFixerDefaults,
        array $comparison,
        array $differences,
        string $outputPath
    ): array {
        return [
            'schema_version' => SCHEMA_VERSION,
            'preset' => $preset,
            'output' => repositoryRelativePath($outputPath),
            'package_versions' => [
                'laravel/pint' => $pintVersion,
                'friendsofphp/php-cs-fixer' => $phpCsFixerVersion,
            ],
            'baseline' => [
                'strategy' => 'php_cs_fixer_raw_defaults_then_rule_defaults_for_pint_only_rules',
                'php_cs_fixer_defaults' => $phpCsFixerDefaults,
            ],
            'counts' => [
                'differences' => count($differences),
                'missing_from_pint' => count($comparison['missing_from_pint']),
                'different_configuration' => count($comparison['different_configuration']),
                'pint_differs_from_rule_default' => count($comparison['pint_differs_from_rule_default']),
            ],
            'differences' => $differences,
            'limitations' => [
                'Rules enabled by the raw PHP-CS-Fixer defaults are compared directly against Pint.',
                'Rules enabled only by Pint are compared against the intrinsic PHP-CS-Fixer fixer defaults that would apply if that rule were enabled in isolation.',
                'Each difference also lists non-@auto PHP-CS-Fixer rulesets that contain the rule and the normalized effective rule state within each ruleset.',
                'Automatic @auto* PHP-CS-Fixer rulesets are intentionally omitted from the per-rule ruleset metadata because their effective target depends on environment and project PHP-version detection.',
                'Fixer paths inside the JSON use the installed Pint PHAR path plus the internal PHAR file path, separated by "::".',
            ],
        ];
    }

    function main(array $argv): int
    {
        $options = parseOptions($argv);
        $paths = preparePintPhar();
        $outputPath = repositoryPath($options['output']);

        requirePintAutoloader($paths['phar_root']);

        $pintVersion = readRootComposerLockVersion('laravel/pint');
        $phpCsFixerVersion = readPharInstalledVersion($paths['phar_root'], 'friendsofphp/php-cs-fixer');
        $phpCsFixerGithubBase = phpCsFixerGithubBase($phpCsFixerVersion);

        $phpCsFixerConfig = loadDefaultPhpCsFixerConfig();
        $pintConfig = loadPintConfig($paths['phar_root'], $options['preset']);
        $pintPresetSource = loadPintPresetSource($paths['phar_root'], $options['preset']);
        $pintPresetRuleReferences = collectPintPresetRuleReferences($pintPresetSource);
        $fixerLookup = collectFixerLookup($pintConfig);

        $phpCsFixerDefaults = normalizeRules($phpCsFixerConfig->getRules(), $phpCsFixerConfig);
        $pintRules = normalizeRules($pintConfig->getRules(), $pintConfig);
        $comparisonRuleLookup = [];
        foreach (array_keys($phpCsFixerDefaults + $pintRules) as $rule) {
            $comparisonRuleLookup[$rule] = true;
        }
        $phpCsFixerRuleDefaults = collectPhpCsFixerRuleDefaultStates($comparisonRuleLookup, $fixerLookup);
        $comparison = filterComparisonByRule(compareRules($phpCsFixerDefaults, $pintRules, $phpCsFixerRuleDefaults), $options['rule']);
        $phpCsFixerRulesetStates = collectPhpCsFixerRulesetStates(
            comparisonRuleNames($comparison),
            $phpCsFixerGithubBase,
        );
        $differences = buildDifferences(
            $comparison,
            $phpCsFixerDefaults,
            $phpCsFixerRuleDefaults,
            $pintRules,
            $phpCsFixerRulesetStates,
            $fixerLookup,
            $pintPresetRuleReferences,
            $phpCsFixerGithubBase,
        );

        $report = buildReport(
            $options['preset'],
            $pintVersion,
            $phpCsFixerVersion,
            normalizeOutputValue($phpCsFixerConfig->getRules()),
            $comparison,
            $differences,
            $outputPath,
        );

        writeJson($outputPath, $report);

        fwrite(STDOUT, repositoryRelativePath($outputPath).PHP_EOL);

        return 0;
    }

    exit(main($argv));
}
