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

namespace PhpCsFixerDefaults\Scripts {
    use PhpCsFixer\Config;
    use PhpCsFixer\ConfigInterface;
    use PhpCsFixer\Fixer\ConfigurableFixerInterface;
    use PhpCsFixer\Fixer\FixerInterface;
    use PhpCsFixer\FixerFactory;
    use PhpCsFixer\RuleSet\RuleSet;
    use PhpCsFixer\Tokenizer\Tokens;
    use PhpCsFixer\WhitespacesFixerConfig;
    use ReflectionObject;
    use RuntimeException;
    use SplFileInfo;

    function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
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

    function loadDefaultPhpCsFixerConfig(): ConfigInterface
    {
        return new Config();
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

    function newFixerFactory(ConfigInterface $config): FixerFactory
    {
        $factory = new FixerFactory();
        $factory->registerBuiltInFixers();

        if (class_exists(\App\Fixers\TypeAnnotationsOnlyFixer::class)) {
            $factory->registerCustomFixers([
                new \App\Fixers\TypeAnnotationsOnlyFixer(),
            ]);
        }

        $factory->setWhitespacesConfig(
            new WhitespacesFixerConfig($config->getIndent(), $config->getLineEnding()),
        );

        return $factory;
    }

    function normalizeRules(array $ruleMap, ConfigInterface $config): array
    {
        $factory = newFixerFactory($config);
        $factory->useRuleSet(new RuleSet($ruleMap));

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

    function loadJsonFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Could not find required file "%s".', $path));
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
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

    function writeJson(string $path, array $data): void
    {
        ensureDirectory(dirname($path));

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        if (is_file($path) && file_get_contents($path) === $json) {
            return;
        }

        file_put_contents($path, $json);
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

    function renderRuleOutput(
        string $rule,
        array $state,
        string $code,
        string $relativePath,
        ConfigInterface $config
    ): string {
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
}
