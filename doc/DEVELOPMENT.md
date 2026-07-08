# Developer notes

## Global scopes

For backward compat with h5ish code, hordectl itself must not rely on
globals. When interacting with Horde code, hordectl or code run by
hordectl may globalize / reset `$GLOBALS['registry']`,
`$GLOBALS['conf']`, and `$GLOBALS['injector']` for the duration of the
call.

Any admin-API request served through `rampage.php` runs the modern
PSR-15 middleware stack. That stack does NOT populate the legacy
`$GLOBALS['registry']`. Legacy `lib/` code reached from a modern
request must either be updated to accept an injected dependency or
gracefully degrade when the global is absent.

## Injectors

hordectl tracks two separate injector scopes:

- The **hordectl Dependencies injector** holds bindings that make the
  CLI work (config manager, target resolver, output helper, admin
  API client, service factories).

- The **Horde injector** is the injector the target Horde install
  hands out during a local (filesystem-mode) command. When a
  hordectl subcommand runs code from the target's `vendor/horde/*`
  and that code expects `$GLOBALS['injector']`, hordectl publishes
  the Horde injector into that global for the duration of the call
  and restores whatever was there before on return.

## Services vs command glue

`src/Command/*` files are argv parsers and pretty-printers. They
resolve options, call one or more services, and render the outcome.
No business logic, no file I/O beyond `--force`/skip semantics.

`src/Service/*` files hold the actual behavior. They accept plain
values (paths, options, DTOs), return typed results, and never touch
argv. Every service is unit-testable without a CLI harness.

New subcommands should extend this split: put the doing in a service,
the plumbing in the command.

## Adding a top-level command

1. Create `src/Command/<Name>.php` implementing `Module` and
   `ModuleUsage`. Extend `HasModulesTrait` if it dispatches to
   sub-flavors (e.g. `webserver-config` → apache-vhost / nginx / htaccess).
2. Override `getTitle()` and `getPositionalArgs()` to return the
   canonical spelling. Both should match; the framework lower-cases
   the class name by default which is fine for single-word commands
   and wrong for multi-word ones.
3. For sub-flavors, put each in `src/Command/<Name>/<Flavor>.php`,
   returning its own single-string `getPositionalArgs()`.
4. Register any non-Module files in the exclusion list passed to
   `_initModules()` so they don't get treated as flavors.

## Adding a service

Services live under `src/Service/<Namespace>/`. Prefer:

- One class per responsibility (builder / emitter / writer /
  report). Small classes are easier to substitute in tests.
- Constructor injection. Any collaborator that could vary between
  tests goes through the constructor.
- Immutable value classes for outcomes. See
  `Service/WebserverConfig/MapBuildResult.php` for the shape.

## Testing conventions

- `test/unit/` mirrors `src/`. One test class per source class.
- Use PHPUnit attributes (`#[CoversClass(...)]`).
- Do not include `phpunit/phpunit` in `require-dev`; it's installed
  globally on developer machines.
