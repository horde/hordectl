# Translation

`hordectl translation` (alias `hordectl i18n`) manages gettext-based translations
across every Horde package discovered under a target's installation. It replaces
the legacy `horde-translation` script with a modern PSR-4 implementation.

The tool operates against a local target with filesystem access. Remote targets
are refused: translation work touches source trees and .po files, which are
never accessible via the REST API. Run `hordectl target current` to see which
target you're operating against.

## Concepts

- **Package.** Any directory under `<install>/vendor/horde/` with a `.horde.yml`
  whose `type:` field is `application`, `horde-library` or `library`. Other
  types (composer-plugin, horde-theme, extension, component, project) are not
  translatable and are silently skipped during discovery.
- **Domain.** The gettext text domain used at runtime, discovered from any
  existing `locale/<domain>.pot` file. Falls back to `.horde.yml` id when no
  pot yet exists. This is why `horde/Exception` writes to `Horde_Exception.po`
  even though its id is `Exception`.
- **Source mode.** `--source=composer` (default) scans `<install>/vendor/horde/`.
  `--source=repos` scans `<install>` itself as a directory of package checkouts.
  Use `repos` when running against a laid-out development tree rather than a
  composer install.

## Subcommands

### binaries

Reports which gettext tools the translation subcommand can find on PATH,
including their resolved paths and versions. Diagnostic-only. Missing tools
appear with `(missing)` in the Path column and the command emits distro-specific
install hints (Debian/Ubuntu, Fedora/RHEL, openSUSE, Alpine, macOS/brew).

    hordectl translation binaries
    hordectl translation binaries --strict    # exit non-zero when anything is missing

Every write subcommand preflights its own tool dependencies at startup, so
"extract failed because xgettext is missing" always surfaces before any file is
touched. The preflight error message includes the same distro-specific install
hints as this subcommand.

### extract

Runs `xgettext` against a package's source tree and writes `locale/<domain>.pot`.
Source paths are recorded relative to the package root so the resulting pot is
portable across installs. Symbolic links are followed, so packages installed
as composer symlinks to a working tree are traversed correctly.

    hordectl translation extract --package=imp
    hordectl translation extract --package=Exception --dry-run

### merge

Runs `msgmerge --update` to fold a fresh .pot into an existing per-locale .po.
Preserves the translator's work. New msgids appear as untranslated msgstrs.
When a per-locale compendium exists at `<install>/vendor/horde/horde/locale/compendium.<locale>.po`,
it's passed as `--compendium` automatically.

    hordectl translation merge --locale=de --package=imp

**Never runs cleanup implicitly.** Untranslated entries stay in the .po until
an explicit `translation cleanup` is invoked. This is a deliberate behavior
change from the legacy tool, which stripped untranslated msgids on every merge
and surprised translators mid-workflow.

### compile

Runs `msgfmt` to compile a per-locale .po into a .mo, and prints a stats table
at the end (translated / fuzzy / untranslated counts).

    hordectl translation compile --locale=de --package=Core

### init

Bootstraps a new locale for a package via `msginit`. Refuses to overwrite an
existing .po unless `--force` is passed.

    hordectl translation init --locale=pt_BR --package=turba

### cleanup

Runs `msgattrib --translated --no-obsolete` to prune untranslated msgids and
obsolete entries. Standalone by design: run it deliberately when you want a
lean .po, and never as a side effect of another subcommand.

    hordectl translation cleanup --locale=de --package=Core
    hordectl translation cleanup --locale=de --package=Core --keep-untranslated

### compendium

Builds a per-locale compendium file at `<install>/vendor/horde/horde/locale/compendium.<locale>.po`
by concatenating every package's `<locale>/LC_MESSAGES/*.po` with `msgcat --use-first`,
then stripping untranslated and obsolete entries via `msgattrib`.

    hordectl translation compendium --locale=de

The **locale suffix** in the filename is required. The legacy tool wrote a
single `compendium.po` with no locale, meaning German entries poisoned French
merges and vice versa. Fixed here.

### update

Convenience wrapper: runs `extract` then `merge` for the requested locale. Does
NOT run compile or cleanup.

    hordectl translation update --locale=de --package=imp

### update-help

Applications only. Merges each application's English `help.xml` into every
locale's `help.xml` via DOM. Localized entries are annotated with a `state`
attribute (`new` / `changed` / `uptodate` / `unknown`) plus an md5 fingerprint
so translators can find the work.

    hordectl translation update-help --package=imp
    hordectl translation update-help --package=imp --locale=de

### make-help

Applications only. Marks reviewed `help.xml` entries as up-to-date. Once a
translator has consumed the English-source comments left by `update-help`, this
command refreshes each entry's `md5=` attribute and drops the source comments.

    hordectl translation make-help --package=imp --locale=de

### commit

Stages translation artifacts and writes one Conventional Commits headline per
package. Only runs against `--source=repos` (i.e. a working tree). Never
pushes, never opens a PR, never crosses package boundaries.

    hordectl translation commit --locale=de --package=imp
    hordectl translation commit --locale=de --package=imp --new

Default staged paths:

- `locale/<domain>.pot`
- `locale/<locale>/LC_MESSAGES/<domain>.po`
- `locale/<locale>/LC_MESSAGES/<domain>.mo`
- `locale/<locale>/help.xml` (applications only)

With `--new`: also stages `CREDITS`, `CHANGES`, `locale/<locale>/nls.php`.

The commit headline defaults to `chore(i18n): update <locale> translation for
<package>` and can be overridden with `--message=`. Bodies are never
generated: one line per commit.

### diff

Compares two revisions (or two paths) of a package's translation files after
normalization. Reviewers and CI use this to answer "did this change touch any
real translation content, or is it only creation-date churn?"

    hordectl translation diff HEAD~1 HEAD --package=imp --pot-only
    hordectl translation diff HEAD WORKTREE --package=imp --locale=de
    hordectl translation diff --left=old.po --right=new.po

Special ref `WORKTREE` (also `WORK`, `.`) reads the current working tree.
Empty diff means no material change.

### check

Runs `xgettext` and `msgmerge` into a temp workspace, then diffs the fresh
output against the committed files after normalization. Nothing on disk is
mutated. CI runs this as a pre-merge gate.

    hordectl translation check --package=imp
    hordectl translation check --package=Exception --with-locales
    hordectl translation check --package=imp -v            # print unified diff on drift
    hordectl translation check --package=imp --ignore-refs # ignore #: line-number moves

Reports a status per file: `up to date`, `DRIFT`, `committed missing`,
`workspace error`, `extract failed` or `merge failed`.

## Normalization pipeline (diff / check)

Both `diff` and `check` compare after canonicalization:

1. `msgcat --sort-output --no-wrap`. Stable msgid order, no line wraps.
2. Strip volatile header fields: `POT-Creation-Date`, `PO-Revision-Date`,
   `Last-Translator`, `Language-Team`.
3. Optionally strip `#:` reference lines when `--ignore-refs` is passed.

Two axes of materiality:

- Without `--ignore-refs`, a line-number shift is material. Source moved,
  translators may want a fresh extract to keep refs current.
- With `--ignore-refs`, only msgid / msgstr content is material.

Both axes are legitimate CI questions.

## Typical workflows

**Regenerate translations for a package after code changes:**

    hordectl translation extract --package=imp
    hordectl translation merge --locale=de --package=imp
    hordectl translation compile --locale=de --package=imp

**Bootstrap a new locale:**

    hordectl translation extract --package=imp
    hordectl translation init --locale=pt_BR --package=imp
    # ... translate the .po in an editor ...
    hordectl translation compile --locale=pt_BR --package=imp

**CI drift gate:**

    hordectl translation check --package=imp --ignore-refs
    # exit 0 = up to date, table shows DRIFT rows otherwise

**Commit a translator's work:**

    hordectl translation cleanup --locale=de --package=imp
    hordectl translation compile --locale=de --package=imp
    hordectl translation commit --locale=de --package=imp

## Behavioral differences from horde-translation

- **Every gettext invocation is exit-code-checked.** The legacy tool ignored
  most exit codes. `hordectl translation` surfaces failures with the failed
  command in the error message.
- **Compendium files are locale-suffixed.** No more cross-language leakage.
- **`merge` never strips untranslated entries.** Use `cleanup` explicitly.
- **No auto-cleanup after merge, ever.**
- **Applications and libraries are discovered from `.horde.yml` type.** No
  hardcoded package lists.
- **`compile` (not `make`).** Communicates the actual operation.
- **Terminology.** "Package" replaces "module" throughout. `--module` remains
  as an alias for `--package` where the legacy tool used it.
