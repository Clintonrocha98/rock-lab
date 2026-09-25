@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp

# Module Architecture

This monorepo uses `internachi/modular`. Each module lives under `app-modules/{kebab-case}/` with namespace `RockLab\{PascalCase}\` and composer name `rock-lab/{kebab-case}` (`config/app-modules.php`).

## Module types

| Type              | Prefix / Names                         | Contains                                      |
| ----------------- | -------------------------------------- | --------------------------------------------- |
| **Domain**        | `billing`, `identity`…                 | Business logic: Models, Actions, DTOs, Enums  |
| **Integration**   | `integration-*`                        | External APIs: Transport, OAuth, ETL, Console |
| **Presentation**  | `portal-*`                             | UI: Livewire, Blade, CSS                      |

Presentation modules own UI concerns only. Domain logic belongs in domain modules.

## Canonical structure

```
app-modules/{module}/
├── composer.json
├── phpstan.neon
├── phpstan.ignore.neon
├── config/{module}.php                       (optional)
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── lang/{en,pt_BR}/                          (optional)
├── routes/{topic}-routes.php                 (optional, auto-discovered)
├── resources/views/                          (optional, presentation only)
├── src/
│   ├── {ModuleName}ServiceProvider.php       <- always at src/ root, never in Providers/
│   ├── Actions/
│   ├── Models/
│   ├── DTOs/
│   ├── Enums/
│   ├── Exceptions/
│   ├── Concerns/
│   ├── Contracts/
│   └── ...
└── tests/
    ├── Feature/
    └── Unit/
```

`php artisan make:module <slug>` scaffolds this from `stubs/app-modules/`.

## Sub-namespace strategies

**Flat layers** — simple modules:
`src/Actions/`, `src/Models/`, `src/DTOs/`

**Sub-domain grouping** — complex modules:
`src/{SubDomain}/Actions/`, `src/{SubDomain}/Models/`

## ServiceProvider

Always at `src/{ModuleName}ServiceProvider.php`. Minimal pattern:

@verbatim
<code-snippet name="Module ServiceProvider" lang="php">
namespace RockLab\{ModuleName};

class {ModuleName}ServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
</code-snippet>
@endverbatim

Add `mergeConfigFrom()`, `loadTranslationsFrom()`, `Event::listen()`, `Relation::morphMap()` as needed. Check a sibling module's ServiceProvider for the full pattern.

## Module composer.json

@verbatim
<code-snippet name="Module composer.json" lang="json">
{
    "name": "rock-lab/{module-slug}",
    "autoload": {
        "psr-4": {
            "RockLab\\{ModuleName}\\": "src/",
            "RockLab\\{ModuleName}\\Database\\Factories\\": "database/factories/",
            "RockLab\\{ModuleName}\\Database\\Seeders\\": "database/seeders/"
        }
    }
}
</code-snippet>
@endverbatim

## Version constraints — mandatory `^1.0.0` style

Every intra-repo `rock-lab/*` module dependency (in the root `composer.json` and in any
module's `composer.json`) MUST be declared with the caret style `^1.0.0`. Never use
loose constraints like `>=1`, `*`, `dev-main`, or a truncated `^1.0`.

@verbatim
<code-snippet name="rock-lab/* module constraints" lang="json">
{
    "require": {
        // GOOD — caret with full three-part version:
        "rock-lab/identity": "^1.0.0",

        // BAD — loose or truncated constraints:
        "rock-lab/identity": ">=1",
        "rock-lab/billing": "^1.0",
        "rock-lab/kyc": "*"
    }
}
</code-snippet>
@endverbatim

## Dependency rules

- **Domain** modules never import from Presentation or Integration.
- **Integration** modules may depend on Domain.
- **Presentation** imports from Domain and Integration, never the reverse.

## Registering a new module

In the same change that scaffolds `app-modules/<slug>/`:

1. Add `"RockLab\\<PascalCase>\\Tests\\": "app-modules/<slug>/tests/"` to the root
   `composer.json` `autoload-dev.psr-4` (see the testing guideline for why).
2. Run `{{ $assist->composerCommand('dump-autoload') }}`.
