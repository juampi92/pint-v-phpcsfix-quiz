# Pint vs PHP-CS-Fixer Quiz

`pint-v-phpcsfix-quiz` is a blind test for your linting preferences.
It compares Laravel Pint with PHP-CS-Fixer one visible rule at a time, focused
on the formatter rules that actually change output.

The repository includes two committed artifacts:

- `generated/pint-php-cs-fixer-differences.json`
- `generated/pint-php-cs-fixer-quiz.json`

The quiz app is built with Vite and Svelte, and GitHub Pages deployment is
handled by `.github/workflows/deploy-pages.yml`.

## Local Setup

```bash
composer install
npm ci
```

`composer install` restores the locked Pint version and refreshes the
difference export through Composer hooks. `npm ci` installs the app
dependencies.

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
npm run generate:quiz-data
```

That regenerates the committed JSON files consumed by the quiz UI.

## Deploy

GitHub Pages uses the workflow in `.github/workflows/deploy-pages.yml`. The
workflow installs Node dependencies, builds the Vite app, and publishes `dist/`
with the official Pages actions.

## Notes

- The app is configured to work from a GitHub Pages subpath.
- Only rules that produce a visible standalone difference are included in the
  quiz.
