<?php

declare(strict_types=1);

namespace App\Factories {
    function resolve(string $class): object
    {
        return new class
        {
            public function rules(): array
            {
                return [];
            }

            public function finder(): array
            {
                return [];
            }
        };
    }

    function abort(int $code, string $message): never
    {
        throw new \RuntimeException($message, $code);
    }
}

namespace {
    use PhpCsFixer\Config;
    use PhpCsFixer\ConfigInterface;
    use PhpCsFixer\Fixer\ConfigurableFixerInterface;
    use PhpCsFixer\Fixer\FixerInterface;
    use PhpCsFixer\FixerFactory;
    use PhpCsFixer\RuleSet\RuleSet;
    use PhpCsFixer\RuleSet\RuleSets;
    use PhpCsFixer\WhitespacesFixerConfig;

    const DEFAULT_OUTPUT = 'generated/pint-php-cs-fixer-differences.json';
    const DEFAULT_PRESET = 'laravel';
    const PHP_CS_FIXER_GITHUB_BASE = 'https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/blob/master/';
    const PINT_GITHUB_BASE = 'https://github.com/laravel/pint/blob/main/';
    const SCHEMA_VERSION = 1;

    function usage(): never
    {
        $script = basename(__FILE__);

        fwrite(STDERR, <<<TXT
Usage:
  php scripts/{$script} [--output=generated/pint-php-cs-fixer-differences.json] [--preset=laravel]

TXT);

        exit(1);
    }

    function parseOptions(array $argv): array
    {
        $options = [
            'output' => DEFAULT_OUTPUT,
            'preset' => DEFAULT_PRESET,
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
        return repositoryRoot().'/'.$path;
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

    function newFixerFactory(): FixerFactory
    {
        $factory = new FixerFactory();
        $factory->registerBuiltInFixers();

        if (class_exists(App\Fixers\TypeAnnotationsOnlyFixer::class)) {
            $factory->registerCustomFixers([
                new App\Fixers\TypeAnnotationsOnlyFixer(),
            ]);
        }

        return $factory;
    }

    function normalizeRules(array $ruleMap, ConfigInterface $config): array
    {
        $factory = newFixerFactory();
        $factory
            ->useRuleSet(new RuleSet($ruleMap))
            ->setWhitespacesConfig(new WhitespacesFixerConfig($config->getIndent(), $config->getLineEnding()));

        $normalized = [];

        foreach ($factory->getFixers() as $fixer) {
            if ($fixer instanceof ConfigurableFixerInterface) {
                $reflection = new ReflectionObject($fixer);

                while ($reflection && !$reflection->hasProperty('configuration')) {
                    $reflection = $reflection->getParentClass();
                }

                if (!$reflection) {
                    throw new RuntimeException(sprintf('Could not inspect configuration for fixer "%s".', $fixer->getName()));
                }

                $property = $reflection->getProperty('configuration');
                $property->setAccessible(true);

                $value = $property->getValue($fixer);
                $normalized[$fixer->getName()] = $value === null ? true : normalizeOutputValue($value);
            } else {
                $normalized[$fixer->getName()] = true;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    function loadPintConfig(string $pharRoot, string $preset): ConfigInterface
    {
        $presetPath = sprintf('%s/resources/presets/%s.php', $pharRoot, $preset);

        if (!is_file($presetPath)) {
            throw new RuntimeException(sprintf('Could not find the "%s" Pint preset inside the installed PHAR.', $preset));
        }

        $config = require $presetPath;

        if (!$config instanceof ConfigInterface) {
            throw new RuntimeException(sprintf('The "%s" Pint preset did not return a PhpCsFixer\\ConfigInterface instance.', $preset));
        }

        return $config;
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

    function shouldSkipNamedRuleset(string $name): bool
    {
        return str_starts_with($name, '@auto');
    }

    function namedRulesetPreference(string $name): array
    {
        return [
            str_contains($name, 'x') ? 1 : 0,
            $name,
        ];
    }

    function loadNamedRulesets(): array
    {
        $sets = [];

        foreach (RuleSets::getSetDefinitionNames() as $name) {
            if (shouldSkipNamedRuleset($name)) {
                continue;
            }

            $rawRules = (new RuleSet([$name => true]))->getRules();

            $sets[$name] = [
                'name' => $name,
                'rule_names' => array_keys($rawRules),
                'size' => count($rawRules),
            ];
        }

        ksort($sets);

        return $sets;
    }

    function inferRulesetCover(array $pintRuleNames, array $namedSets): array
    {
        $memberships = [];

        foreach ($pintRuleNames as $rule) {
            $memberships[$rule] = [];
        }

        foreach ($namedSets as $name => $set) {
            foreach ($set['rule_names'] as $rule) {
                if (array_key_exists($rule, $memberships)) {
                    $memberships[$rule][] = $name;
                }
            }
        }

        $ignoredRules = [];
        $setBackedRules = [];

        foreach ($memberships as $rule => $ruleSets) {
            if ($ruleSets === []) {
                $ignoredRules[] = $rule;

                continue;
            }

            $setBackedRules[$rule] = true;
        }

        sort($ignoredRules);

        $remaining = $setBackedRules;
        $chosen = [];

        while ($remaining !== []) {
            $bestName = null;
            $bestCoverage = [];
            $bestSize = null;

            foreach ($namedSets as $name => $set) {
                $coverage = array_values(array_intersect($set['rule_names'], array_keys($remaining)));

                if ($coverage === []) {
                    continue;
                }

                if (
                    $bestName === null
                    || count($coverage) > count($bestCoverage)
                    || (
                        count($coverage) === count($bestCoverage)
                        && (
                            $set['size'] < $bestSize
                            || (
                                $set['size'] === $bestSize
                                && namedRulesetPreference($name) < namedRulesetPreference($bestName)
                            )
                        )
                    )
                ) {
                    $bestName = $name;
                    $bestCoverage = $coverage;
                    $bestSize = $set['size'];
                }
            }

            if ($bestName === null) {
                break;
            }

            sort($bestCoverage);

            $chosen[$bestName] = [
                'name' => $bestName,
                'covered_rules' => $bestCoverage,
                'size' => $namedSets[$bestName]['size'],
            ];

            foreach ($bestCoverage as $rule) {
                unset($remaining[$rule]);
            }
        }

        return [
            'chosen_sets' => array_values($chosen),
            'ignored_rules' => $ignoredRules,
        ];
    }

    function compareRules(array $left, array $right): array
    {
        $onlyLeft = array_keys(array_diff_key($left, $right));
        $onlyRight = array_keys(array_diff_key($right, $left));
        sort($onlyLeft);
        sort($onlyRight);

        $different = [];

        foreach (array_intersect(array_keys($left), array_keys($right)) as $rule) {
            if ($left[$rule] !== $right[$rule]) {
                $different[$rule] = [
                    'php_cs_fixer' => $left[$rule],
                    'pint' => $right[$rule],
                ];
            }
        }

        ksort($different);

        return [
            'only_php_cs_fixer' => $onlyLeft,
            'only_pint' => $onlyRight,
            'different_configuration' => $different,
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

    function readRootComposerLockVersion(string $packageName): string
    {
        $lockPath = repositoryPath('composer.lock');

        if (!is_file($lockPath)) {
            return 'unknown';
        }

        $lock = json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR);

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $package) {
                if (($package['name'] ?? null) === $packageName) {
                    return $package['version'] ?? 'unknown';
                }
            }
        }

        return 'unknown';
    }

    function readPharInstalledVersion(string $pharRoot, string $packageName): string
    {
        $installedPath = $pharRoot.'/vendor/composer/installed.json';
        $installed = json_decode((string) file_get_contents($installedPath), true, 512, JSON_THROW_ON_ERROR);

        foreach ($installed['packages'] ?? [] as $package) {
            if (($package['name'] ?? null) === $packageName) {
                return $package['version'] ?? 'unknown';
            }
        }

        return 'unknown';
    }

    function collectFixerLookup(): array
    {
        $lookup = [];

        foreach (newFixerFactory()->getFixers() as $fixer) {
            $lookup[$fixer->getName()] = $fixer;
        }

        ksort($lookup);

        return $lookup;
    }

    function repositoryRelativePath(string $absolutePath): string
    {
        $root = repositoryRoot();
        $normalized = str_replace('\\', '/', $absolutePath);
        $normalizedRoot = str_replace('\\', '/', $root);

        if (str_starts_with($normalized, $normalizedRoot.'/')) {
            return substr($normalized, strlen($normalizedRoot) + 1);
        }

        return $normalized;
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

    function findPintRuleEndLine(array $lines, int $startIndex): int
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
                    throw new RuntimeException('Could not locate rule assignment while parsing the Pint preset.');
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

        throw new RuntimeException('Could not determine the end of a Pint rule configuration block.');
    }

    function collectPintPresetRuleReferences(array $presetSource): array
    {
        $references = [];
        $lines = $presetSource['lines'];
        $relativePath = $presetSource['relative_path'];
        $lineCount = count($lines);

        for ($index = 0; $index < $lineCount; ++$index) {
            if (!preg_match("/^\\s*'([^']+)'\\s*=>/", $lines[$index], $matches)) {
                continue;
            }

            $rule = $matches[1];
            $endIndex = findPintRuleEndLine($lines, $index);

            $references[$rule] = buildGithubReference(
                PINT_GITHUB_BASE,
                $relativePath,
                $index + 1,
                $endIndex + 1,
            );

            $index = $endIndex;
        }

        ksort($references);

        return $references;
    }

    function pharVendorPath(string $sourcePath): string
    {
        return normalizeSourcePathParts($sourcePath)['vendor_path'];
    }

    function buildFixerMetadata(string $rule, FixerInterface $fixer): array
    {
        $reflection = new ReflectionClass($fixer);
        $sourcePath = $reflection->getFileName() ?: 'unknown';
        $pathParts = normalizeSourcePathParts($sourcePath);
        $internalPath = $pathParts['internal_path'];
        $githubReference = null;

        if ($internalPath !== null && str_starts_with($internalPath, 'vendor/friendsofphp/php-cs-fixer/')) {
            $repositoryPath = substr($internalPath, strlen('vendor/friendsofphp/php-cs-fixer/'));
            $githubReference = buildGithubReference(
                PHP_CS_FIXER_GITHUB_BASE,
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
        array $pintRules,
        array $fixerLookup,
        array $pintPresetRuleReferences
    ): array
    {
        $differences = [];

        foreach ($comparison['only_php_cs_fixer'] as $rule) {
            if (!isset($fixerLookup[$rule])) {
                throw new RuntimeException(sprintf('Could not resolve fixer metadata for "%s".', $rule));
            }

            $fixerMetadata = buildFixerMetadata($rule, $fixerLookup[$rule]);

            $differences[] = [
                'rule' => $rule,
                'category' => 'only_php_cs_fixer',
                'pint' => normalizeRuleState(false, null),
                'php_cs_fixer' => normalizeRuleState(true, $phpCsFixerRules[$rule]),
                'fixer' => $fixerMetadata,
                'references' => [
                    'php_cs_fixer' => $fixerMetadata['github'],
                    'pint' => $pintPresetRuleReferences[$rule] ?? null,
                ],
            ];
        }

        foreach ($comparison['only_pint'] as $rule) {
            if (!isset($fixerLookup[$rule])) {
                throw new RuntimeException(sprintf('Could not resolve fixer metadata for "%s".', $rule));
            }

            $fixerMetadata = buildFixerMetadata($rule, $fixerLookup[$rule]);

            $differences[] = [
                'rule' => $rule,
                'category' => 'only_pint',
                'pint' => normalizeRuleState(true, $pintRules[$rule]),
                'php_cs_fixer' => normalizeRuleState(false, null),
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

            $fixerMetadata = buildFixerMetadata($rule, $fixerLookup[$rule]);

            $differences[] = [
                'rule' => $rule,
                'category' => 'different_configuration',
                'pint' => normalizeRuleState(true, $values['pint']),
                'php_cs_fixer' => normalizeRuleState(true, $values['php_cs_fixer']),
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

    function buildReport(
        string $preset,
        string $pintVersion,
        string $phpCsFixerVersion,
        array $rulesetCover,
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
                'strategy' => 'default_php_cs_fixer_plus_inferred_pint_named_rulesets',
                'inferred_named_rulesets' => $rulesetCover['chosen_sets'],
                'ignored_pint_rules_outside_named_rulesets' => $rulesetCover['ignored_rules'],
            ],
            'counts' => [
                'differences' => count($differences),
                'only_php_cs_fixer' => count($comparison['only_php_cs_fixer']),
                'only_pint' => count($comparison['only_pint']),
                'different_configuration' => count($comparison['different_configuration']),
            ],
            'differences' => $differences,
            'limitations' => [
                'The comparison only knows about the named PHP-CS-Fixer rulesets that can be inferred from the installed Pint preset.',
                'If PHP-CS-Fixer adds new rules and Pint has not adopted them yet, this export will not treat those unseen rules as Pint differences automatically.',
                'Fixer paths inside the JSON use the installed Pint PHAR path plus the internal PHAR file path, separated by "::".',
            ],
        ];
    }

    function writeJson(string $path, array $data): void
    {
        ensureDirectory(dirname($path));

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

        if ($json === false) {
            throw new RuntimeException('Could not encode the differences report as JSON.');
        }

        if (is_file($path) && file_get_contents($path) === $json) {
            return;
        }

        file_put_contents($path, $json);
    }

    function main(array $argv): int
    {
        $options = parseOptions($argv);
        $paths = preparePintPhar();
        $outputPath = repositoryPath($options['output']);

        requirePintAutoloader($paths['phar_root']);

        $pintVersion = readRootComposerLockVersion('laravel/pint');
        $phpCsFixerVersion = readPharInstalledVersion($paths['phar_root'], 'friendsofphp/php-cs-fixer');

        $phpCsFixerConfig = new Config();
        $pintConfig = loadPintConfig($paths['phar_root'], $options['preset']);
        $pintPresetSource = loadPintPresetSource($paths['phar_root'], $options['preset']);
        $pintPresetRuleReferences = collectPintPresetRuleReferences($pintPresetSource);

        $namedSets = loadNamedRulesets();
        $pintRuleNames = array_keys((new RuleSet($pintConfig->getRules()))->getRules());
        $rulesetCover = inferRulesetCover($pintRuleNames, $namedSets);

        $augmentedPhpCsFixerRuleMap = $phpCsFixerConfig->getRules();
        foreach ($rulesetCover['chosen_sets'] as $set) {
            $augmentedPhpCsFixerRuleMap[$set['name']] = true;
        }

        $augmentedPhpCsFixerRules = normalizeRules($augmentedPhpCsFixerRuleMap, $phpCsFixerConfig);
        $pintRules = normalizeRules($pintConfig->getRules(), $pintConfig);

        foreach ($rulesetCover['ignored_rules'] as $rule) {
            unset($pintRules[$rule]);
        }

        $comparison = compareRules($augmentedPhpCsFixerRules, $pintRules);
        $fixerLookup = collectFixerLookup();
        $differences = buildDifferences(
            $comparison,
            $augmentedPhpCsFixerRules,
            $pintRules,
            $fixerLookup,
            $pintPresetRuleReferences,
        );

        $report = buildReport(
            $options['preset'],
            $pintVersion,
            $phpCsFixerVersion,
            $rulesetCover,
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
