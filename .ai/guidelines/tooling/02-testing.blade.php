@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp

# Testing — Project Conventions

Project-specific testing conventions that go beyond the framework defaults (Pest,
factories). Each `##` below is one convention; add new ones here rather than spawning
single-rule guideline files.

## The suite runs against Postgres

`.env.testing` (untracked, copied from `.env.testing.example` by `composer setup`) points
the suite at the Postgres from `docker-compose.yml`. Start it with `make env-up` before
running tests. Sqlite is never used: a migration that only passes on sqlite is a bug.

- **Worker databases are per-process.** `--parallel` provisions `<DB_DATABASE>_test_N`
  for each worker, so no test may assume a fixed database name, and none may reach across
  to another worker's data.
- **One path per run.** paratest accepts a single `<path>` argument. `pest --parallel
  app-modules/a/tests app-modules/b/tests` dies with `Too many arguments`. Run one module
  at a time, or scope with `--group` / `--filter`.

## Groups: `unit` and `feature`

`tests/Pest.php` binds `tests/Unit` and `app-modules/*/tests/Unit` to the `unit` group
with **no** `RefreshDatabase`, and `tests/Feature` and `app-modules/*/tests/Feature` to
the `feature` group with `LazilyRefreshDatabase`.

- **Never put a DB-touching test in `tests/Unit/`.** Anything using a factory, `DB::`, or
  `assertDatabase*` is a `feature` test.
- Run a group alone with `{{ $assist->composerCommand('test:unit') }}` /
  `{{ $assist->composerCommand('test:feature') }}`, or `make test-unit` / `make test-feature`.

## Module test autoloading — register every module's `Tests\` namespace at the root

**Priority: HIGH.** When a module gains a **shared, namespaced test class** (a base
`TestCase`, a trait, a fake/fixture under `tests/Support/`, a dataset), the module's
`RockLab\<Module>\Tests\` namespace MUST be declared in the **root** `composer.json`
`autoload-dev.psr-4` **in the same change** — not only in the module's own
`composer.json`.

### Why (the non-obvious part)

Composer **never loads the `autoload-dev` of a dependency**, and `internachi/modular`
installs each module as a path-repo dependency. So a module's own `autoload-dev`
(`RockLab\<Module>\Tests\` → `tests/`) is **inert** in the aggregate app build — it only
resolves when the module is treated as its own root package (isolated tooling, IDE).

Tests still *generally* run without the root entry because **PHPUnit `require`s each
`*Test.php` by path** (via the `app-modules/*/tests/{Unit,Feature}` globs in
`phpunit.xml`) and most tests extend the root `Tests\TestCase`. The gap is any class
PHPUnit's suffix scan never includes — anything under `tests/Support/`, a base
`TestCase`, a trait, a dataset. Those resolve **only** through PSR-4, so a sibling test
that `use`s one **fatals with "class not found"** unless the namespace is registered at
the root.

This is not automated: `modules:sync` only touches `phpunit.xml` (and here runs with
`--no-phpunit`), and upstream declined to automate it (`InterNACHI/modular#105` — closed,
"not a fan"). It is a **manual convention**.

### Keep BOTH declarations — they serve different scopes

Do **not** "deduplicate" by deleting the module's `autoload-dev`. The two coexist by
design:

| Declaration | Scope it serves |
|-------------|-----------------|
| **Module** `autoload-dev` (`tests/`) | Isolated per-module tooling + IDE resolution; keeps test code out of the prod autoloader so `composer dump-autoload --no-dev` strips it. It is the `make:module` scaffold default — deleting it just re-drifts on the next module. |
| **Root** `autoload-dev` (`app-modules/<slug>/tests/`) | Resolves `RockLab\<Module>\Tests\...` when the **aggregate** suite runs from the repo root. |

### The pattern

@verbatim
<code-snippet name="Module composer.json — scaffold default, keep it" lang="json">
{
    "autoload-dev": {
        "psr-4": {
            "RockLab\\Billing\\Tests\\": "tests/"
        }
    }
}
</code-snippet>

<code-snippet name="Root composer.json — mirror EVERY module here" lang="json">
{
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/",
            "RockLab\\Billing\\Tests\\": "app-modules/billing/tests/"
        }
    }
}
</code-snippet>
@endverbatim

After editing either file, run `{{ $assist->composerCommand('dump-autoload') }}`.

### When scaffolding a NEW module

`php artisan make:module <slug>` adds the module's own `autoload-dev` for you. In the
**same change** you MUST add the mirror line to the root `composer.json`:

- `"RockLab\\<PascalCase>\\Tests\\": "app-modules/<slug>/tests/"`

## Tests never depend on a Vite build

**Priority: HIGH.** `tests/TestCase.php` calls `withoutVite()` in `setUp()`, so every
`@vite` and `Vite::withEntryPoints()` renders as an empty string, and CI runs no
`bun run build`. Nothing in the suite may reverse that:

- Never call `withVite()` in a test.
- Never `skip()` a test on `public/build/manifest.json` — a test that skips on CI is a
  test that does not run where the gate is.
- Never assert an asset by rendering a page and looking for its tag.

Assert the **delivery** of an asset from the source instead: the provider registers the
entry, `vite.config.js` compiles it, `app.js` imports it.
