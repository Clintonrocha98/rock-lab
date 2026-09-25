@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp

# Tinker Snippets — No Bootstrap Ceremony

A tinker-executed file runs inside the **full booted Laravel environment** — tinker
loads everything. Write the snippet against that assumption.

## Rules

- **No bootstrap ceremony.** Do NOT `require` the autoloader, build the kernel, or boot
  the app. The file uses models and tables directly — straight `User::query()...` /
  `DB::table(...)`.
- **App namespaces are already resolved.** Reference `RockLab\...` / `App\...` classes without
  any `use`-wiring scaffolding.
- **`laravel/prompts` is available** — use it to structure interactive prompts.
- **`laravel/console` helpers are available** too.
- **String formatting: always `sprintf()`.** Never build a string with `.` concatenation
  or `"{$var}"` interpolation. `sprintf` keeps the template readable and the arguments
  ordered.
- **Output goes through `echo` or a `Laravel\Prompts` function.** The global `info()`
  helper writes to the log, not to the console.

## Running

@verbatim
<code-snippet name="Run a tinker snippet" lang="bash">
# Non-interactive (agent, background, pipe) — runs the file and exits:
php artisan tinker --execute="require 'path/to/script.php';"

# Interactive — runs the file, then opens the REPL:
php artisan tinker path/to/script.php
</code-snippet>
@endverbatim

The interactive form includes the file and then waits for input in the PsySH shell.
Never use it in the background or behind a pipe: it never exits, and `| tail` prints
nothing.

## The pattern

@verbatim
<code-snippet name="Tinker snippet — no scaffolding" lang="php">
// No autoload require, no kernel, no bootstrap. Models and DB used directly.
$stale = User::query()
    ->whereNull('email_verified_at')
    ->where('created_at', '<', now()->subDays(30))
    ->count();

echo sprintf("Stale unverified users: %d\n", $stale);
</code-snippet>
@endverbatim
