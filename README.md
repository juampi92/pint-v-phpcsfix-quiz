# Pint vs PHP-CS-Fixer Defaults

This repository exports a deterministic JSON snapshot of the rule differences between:

- Laravel Pint's bundled `laravel` preset
- PHP-CS-Fixer's default `Config`
- plus the named PHP-CS-Fixer rulesets that can be inferred from the installed Pint preset

The result is written to:

- `generated/pint-php-cs-fixer-differences.json`

The repository also contains a static quiz site. Its committed intermediate
artifact is written to:

- `generated/pint-php-cs-fixer-quiz.json`

## Install

```bash
composer update
npm install
```

This repository depends on `laravel/pint` directly. The bundled PHP-CS-Fixer version is read from Pint itself, so the comparison always uses the PHP-CS-Fixer build that ships with the installed Pint version.

The frontend is a Vite + Svelte static site. It builds from the committed quiz
artifact, so GitHub Pages deployment does not need Composer or the PHAR parsing
step.

## Run

```bash
composer export-differences
```

or:

```bash
php scripts/export-differences.php
```

Optional flags:

```bash
php scripts/export-differences.php \
  --output=generated/pint-php-cs-fixer-differences.json \
  --preset=laravel
```

`composer install` and `composer update` also regenerate the JSON automatically via Composer hooks.

To generate the quiz data used by the website:

```bash
npm run generate:quiz-data
```

To run the site locally:

```bash
npm run dev
```

To build the static site:

```bash
npm run build
```

## What The Script Does

The exporter is deterministic. It does not write timestamps into the JSON and it sorts output consistently.

At a high level it:

1. Loads the installed Pint PHAR from `vendor/laravel/pint/builds/pint`.
2. Reads Pint's bundled `friendsofphp/php-cs-fixer` version from the PHAR metadata.
3. Loads Pint's `laravel` preset from inside the PHAR.
4. Starts from PHP-CS-Fixer's default `Config`.
5. Infers which named PHP-CS-Fixer rulesets Pint effectively adds.
6. Augments the PHP-CS-Fixer baseline with those inferred rulesets.
7. Compares the resulting rule maps and records every rule that is:
   - enabled only in the augmented PHP-CS-Fixer baseline
   - enabled only in Pint
   - enabled in both, but configured differently
8. Resolves the actual fixer class for each rule and records its path as `vendor/laravel/pint/builds/pint::...`, where the part after `::` is the internal file path inside the Pint PHAR.
9. Writes the final JSON artifact to `generated/pint-php-cs-fixer-differences.json`.

## JSON Schema

The JSON Schema lives at:

- `schema/pint-php-cs-fixer-differences.schema.json`

Top-level fields:

- `schema_version`: schema version for the exported file.
- `preset`: Pint preset used for the export.
- `output`: relative path to the generated JSON file.
- `package_versions`: installed Pint version and the PHP-CS-Fixer version bundled inside Pint.
- `baseline`: how the PHP-CS-Fixer side was built.
- `counts`: difference counts by category.
- `differences`: one entry per rule difference.
- `limitations`: known constraints of the method.

Each `differences[]` item contains:

- `rule`: the PHP-CS-Fixer rule name.
- `category`: one of `only_php_cs_fixer`, `only_pint`, or `different_configuration`.
- `pint`: whether Pint enables the rule and, if enabled, the effective parameters.
- `php_cs_fixer`: the same shape for the augmented PHP-CS-Fixer baseline.
- `fixer.class`: the resolved fixer FQCN.
- `fixer.configurable`: whether the fixer is configurable.
- `fixer.path`: a vendor-based Pint PHAR path in the form `vendor/laravel/pint/builds/pint::vendor/friendsofphp/php-cs-fixer/...`.
- `fixer.github`: the GitHub file-and-lines reference for the actual PHP-CS-Fixer fixer class.
- `references.php_cs_fixer`: the same GitHub reference, exposed at the difference level for direct lookup.
- `references.pint`: the GitHub file-and-lines reference for the Pint preset entry that configures the rule, or `null` when Pint does not configure that rule explicitly.

Example shape:

```json
{
  "rule": "blank_line_before_statement",
  "category": "different_configuration",
  "pint": {
    "enabled": true,
    "parameters": {
      "statements": ["continue", "return"]
    }
  },
  "php_cs_fixer": {
    "enabled": true,
    "parameters": {
      "statements": ["break", "continue", "declare", "return", "throw", "try"]
    }
  },
  "fixer": {
    "class": "PhpCsFixer\\Fixer\\Whitespace\\BlankLineBeforeStatementFixer",
    "configurable": true,
    "path": "vendor/laravel/pint/builds/pint::vendor/friendsofphp/php-cs-fixer/src/Fixer/Whitespace/BlankLineBeforeStatementFixer.php",
    "github": {
      "url": "https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/blob/master/src/Fixer/Whitespace/BlankLineBeforeStatementFixer.php#L39-L285",
      "path": "src/Fixer/Whitespace/BlankLineBeforeStatementFixer.php",
      "start_line": 39,
      "end_line": 285
    }
  },
  "references": {
    "php_cs_fixer": {
      "url": "https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/blob/master/src/Fixer/Whitespace/BlankLineBeforeStatementFixer.php#L39-L285",
      "path": "src/Fixer/Whitespace/BlankLineBeforeStatementFixer.php",
      "start_line": 39,
      "end_line": 285
    },
    "pint": {
      "url": "https://github.com/laravel/pint/blob/main/resources/presets/laravel.php#L13-L20",
      "path": "resources/presets/laravel.php",
      "start_line": 13,
      "end_line": 20
    }
  }
}
```

## Quiz Data

The website uses a second deterministic artifact:

- `generated/pint-php-cs-fixer-quiz.json`

This file is built from `differences[]` plus each fixer's
`getDefinition()->getCodeSamples()`.

At a high level the quiz exporter:

1. Loads `generated/pint-php-cs-fixer-differences.json`.
2. Instantiates the resolved fixer class for each differing rule.
3. Reads the fixer's bundled code samples from the Pint PHAR.
4. Chooses the most relevant sample that produces distinct Pint and PHP-CS-Fixer outputs.
5. Falls back to curated overrides in `config/quiz-sample-overrides.json` when the bundled samples do not expose the difference clearly enough.
6. Writes the original snippet, both formatter outputs, and the hidden left/right answer mapping used by the quiz UI.
7. Skips any rule whose current parameters do not yield a distinguishable standalone output.

Right now that means:

- `108` quiz questions
- `1` skipped rule: `class_definition`

`class_definition` is skipped because the current configuration delta is masked
by `single_line: true` on both sides, so the output is effectively identical in
a standalone example.

The quiz artifact is intentionally committed so it can be reviewed, adjusted,
and used directly by the static site build.

## Website

The static site is meant to answer a practical question: which formatter keeps
producing the output you actually want?

Each question shows:

- the original PHP snippet
- two possible linted outputs
- inline diff highlighting against the original
- visible whitespace markers so spacing-only changes remain obvious

When the user picks the output they prefer, the site reveals whether that choice
matches Laravel Pint or PHP-CS-Fixer and keeps a running score. That score is
persisted in browser storage so it can be reused later when the repository adds
a configuration generator.

GitHub Pages deployment lives in:

- `.github/workflows/deploy-pages.yml`

That workflow builds the Vite site from the committed quiz artifact and deploys
the generated `dist/` directory with the official GitHub Pages actions.

## Updating For A New Pint Release

When Pint updates, run:

```bash
composer update laravel/pint
```

The Composer `post-update-cmd` hook reruns the exporter automatically, so the JSON updates to the new Pint build.

## Limitation

This approach only compares what the installed Pint preset actually exposes. If PHP-CS-Fixer adds new rules and Pint has not been updated to adopt or reference them yet, this export will not automatically treat those unseen rules as Pint differences.
