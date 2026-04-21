# Pint vs PHP-CS-Fixer Defaults

This repository exports a deterministic JSON snapshot of the formatter rules
that meaningfully differ between:

- Laravel Pint's bundled `laravel` preset
- PHP-CS-Fixer's raw default `Config` rule map
- PHP-CS-Fixer's intrinsic fixer defaults for rules that are not enabled by the
  raw defaults

The main artifact is:

- `generated/pint-php-cs-fixer-differences.json`

The repository also contains a static quiz site built from:

- `generated/pint-php-cs-fixer-quiz.json`

## Motivation

The comparison in this repository is intentionally asymmetric, because the goal
is not "list every rule Pint enables that raw PHP-CS-Fixer leaves off." The
goal is "show the rules where choosing Pint or choosing PHP-CS-Fixer actually
changes the starting behavior you get."

That produces two comparison scenarios:

1. Start from the raw PHP-CS-Fixer defaults.
   For every rule enabled there, compare it against Pint.
   If Pint does not enable that rule, or Pint enables it with a different
   configuration, keep it.
   If Pint enables it with the same effective configuration, drop it.
2. Then look at the remaining Pint-only rules.
   For each of those rules, compare Pint against the fixer's own default
   configuration as if that rule were enabled in isolation.
   If Pint matches that intrinsic fixer default, drop it.
   If Pint changes that default, keep it.

This means the export excludes a large class of rules that are "enabled only in
Pint" from a raw-default perspective but do not actually change the underlying
PHP-CS-Fixer fixer behavior.

The JSON also keeps PHP-CS-Fixer ruleset provenance for every exported rule, so
consumers can later show which named rulesets contain that rule and whether the
rule is declared there directly or inherited.

## Install

```bash
composer update
npm install
```

This repository depends on `laravel/pint` directly. The PHP-CS-Fixer version is
read from the installed Pint PHAR, so the comparison always uses the exact
PHP-CS-Fixer build that ships with the installed Pint version.

## Run

Generate the differences export:

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
  --preset=laravel \
  --rule=array_indentation
```

`composer install` and `composer update` also regenerate the differences export
via Composer hooks.

Generate the quiz data used by the website:

```bash
npm run generate:quiz-data
```

or:

```bash
php scripts/export-quiz-data.php --rule=array_indentation
```

Run the site locally:

```bash
npm run dev
```

Build the static site:

```bash
npm run build
```

## What The Exporter Does

At a high level the exporter:

1. Loads the installed Pint PHAR from `vendor/laravel/pint/builds/pint`.
2. Reads Pint's bundled `friendsofphp/php-cs-fixer` version from the PHAR
   metadata.
3. Loads Pint's `laravel` preset from inside the PHAR.
4. Builds the raw PHP-CS-Fixer default rule map from `new Config()`.
5. Resolves the intrinsic PHP-CS-Fixer default configuration for each fixer by
   enabling that rule in isolation.
6. Records rules in exactly three categories:
   - `missing_from_pint`
   - `different_configuration`
   - `pint_differs_from_rule_default`
7. Collects the non-`@auto` PHP-CS-Fixer rulesets that contain each exported
   rule and stores both a compact membership summary and the full effective
   ruleset state.
8. Resolves the fixer implementation class and GitHub references for the rule.
9. Writes the final JSON artifact to
   `generated/pint-php-cs-fixer-differences.json`.

The exporter is deterministic. It does not write timestamps into the JSON and
it sorts output consistently.

## JSON Schema

The JSON Schema lives at:

- `schema/pint-php-cs-fixer-differences.schema.json`

Top-level fields:

- `schema_version`
- `preset`
- `output`
- `package_versions`
- `baseline`
- `counts`
- `differences`
- `limitations`

Important fields inside each `differences[]` item:

- `rule`: the PHP-CS-Fixer rule name
- `category`: one of `missing_from_pint`, `different_configuration`, or
  `pint_differs_from_rule_default`
- `comparison`: which PHP-CS-Fixer baseline is being compared to Pint for this
  rule
- `pint`: Pint's effective enabled state and normalized parameters
- `php_cs_fixer.comparison`: the exact PHP-CS-Fixer state used for this rule's
  comparison
- `php_cs_fixer.raw_default`: the raw default PHP-CS-Fixer state
- `php_cs_fixer.rule_default`: the intrinsic fixer default when the rule is
  enabled in isolation
- `php_cs_fixer_rule_set_membership`: compact ruleset names split into
  `direct` and `inherited`
- `php_cs_fixer_rulesets`: full non-`@auto` ruleset provenance, including
  effective rule state and source references
- `fixer`: resolved fixer metadata
- `references.pint`: GitHub file-and-lines reference for the Pint preset entry,
  when Pint configures the rule explicitly
- `references.php_cs_fixer`: GitHub reference for the PHP-CS-Fixer fixer class

`php_cs_fixer_rulesets` is provenance, not the comparison baseline. The actual
comparison baseline for each exported rule is always spelled out in
`comparison.php_cs_fixer_side`.

## Quiz Data

The website uses a second deterministic artifact:

- `generated/pint-php-cs-fixer-quiz.json`

This file is built from `differences[]` plus each fixer's
`getDefinition()->getCodeSamples()`.

At a high level the quiz exporter:

1. Loads `generated/pint-php-cs-fixer-differences.json`.
2. Instantiates the resolved fixer class for each exported rule.
3. Starts from the fixer's bundled code samples, unless
   `config/quiz-sample-overrides.json` replaces the starting snippet for that
   rule.
4. Applies only the target rule to the selected starting snippet using Pint's
   rule state.
5. Applies only the same target rule to the same starting snippet using
   `php_cs_fixer.comparison`, so the PHP-CS-Fixer side always matches the
   intended comparison scenario for that rule.
6. Chooses the most relevant evaluated sample that produces distinct Pint and
   PHP-CS-Fixer outputs.
7. Writes the original snippet, both executed formatter outputs, and the hidden
   left/right answer mapping used by the quiz UI.
8. Skips any rule whose current parameters do not yield a distinguishable
   standalone output.

The quiz artifact is intentionally committed so it can be reviewed, adjusted,
and used directly by the static site build.

## Website

The static site is meant to answer a practical question: which formatter keeps
producing the output you actually want?

Each question shows:

- the original PHP snippet
- two possible formatter outputs
- inline diff highlighting against the original
- visible whitespace markers so spacing-only changes remain obvious

When the user picks the output they prefer, the site reveals whether that
choice matches Laravel Pint or PHP-CS-Fixer and keeps a running score. That
score is persisted in browser storage so it can be reused later.

GitHub Pages deployment lives in:

- `.github/workflows/deploy-pages.yml`

That workflow builds the Vite site from the committed quiz artifact and deploys
the generated `dist/` directory with the official GitHub Pages actions.

## Updating For A New Pint Release

When Pint updates, run:

```bash
composer update laravel/pint
```

The Composer `post-update-cmd` hook reruns the exporter automatically, so the
differences JSON updates to the new Pint build.

## Limitations

- Automatic `@auto*` PHP-CS-Fixer rulesets are intentionally omitted from the
  per-rule ruleset metadata because their effective target depends on runtime
  and project PHP-version detection.
- Fixer paths inside the JSON use the installed Pint PHAR path plus the
  internal PHAR file path, separated by `::`.
- The quiz only includes rules whose selected standalone sample produces a
  visible output difference.
