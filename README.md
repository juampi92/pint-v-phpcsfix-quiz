<p align="center">
  <img src="art/logo.png" alt="Pint vs PHP-CS-Fixer Quiz logo" width="220">
</p>

<h1 align="center">Pint vs PHP-CS-Fixer Quiz</h1>

<p align="center">
  Blind test your linting preferences.
  <br>
  <a href="https://barreto.jp/pint-v-phpcsfix-quiz/">See it live</a>
</p>

`pint-v-phpcsfix-quiz` is a blind comparison between Laravel Pint and PHP-CS-Fixer.
It keeps the scope narrow on purpose: only rules that produce a visible output
difference are included.

## Generating the Comparison

The comparison is built in two passes:

1. Start from the PHP-CS-Fixer default ruleset and compare each enabled rule
   against the Laravel Pint preset.
2. Keep the rule when Pint does not enable it, or when Pint enables it with a
   different configuration.
3. Then look at the remaining Pint rules that were not covered by the
   PHP-CS-Fixer default ruleset and compare them against the fixer's own default
   rule configuration.
4. Keep those too when Pint's configuration is different.

That leaves only the meaningful cases: rules where the two tools disagree by
default, either because one side does not enable the rule or because the
configuration differs.

## Generating the Code Snippets

For each retained rule, the exporter starts from the fixer's official code
samples and runs the same input through both tools. When the built-in samples do
not show a clean difference, the project can provide a small override sample.
Only rules that produce a visible side-by-side output difference make it into
the quiz.
Almost all code examples were tweaked to get a diff that really reflects the difference.

## Local Setup

```bash
composer install
npm ci
```

## Run Locally

```bash
npm run dev
```

Build the production bundle with:

```bash
npm run build
```

## Refresh The Data

When Pint changes or you update the comparison logic:

```bash
composer update laravel/pint
php scripts/export-differences.php
php scripts/export-quiz-data.php
```

## Deploy

GitHub Pages uses [`.github/workflows/deploy-pages.yml`](.github/workflows/deploy-pages.yml)
to build the Vite app and publish `dist/`.
