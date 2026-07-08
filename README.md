# hordectl

Deploy scenarios for end to end tests from yaml files
Patch desired configurations into horde backends without touching unrelated content

For a full walkthrough (Ubuntu 24.04, MySQL, Apache or nginx, PHP 8.4, from
empty host to logged-in user), see [doc/INSTALL_HORDE.md](doc/INSTALL_HORDE.md).
Developer notes: [doc/DEVELOPMENT.md](doc/DEVELOPMENT.md).

## Install into your Horde 6 deployment

cd /var/www/horde-dev
composer require horde/hordectl

## Basic Usage

### Show available horde apps

7root@cdcead205371:/var/www/horde-dev# ./vendor/bin/hordectl help
Help
Found Horde at: /var/www/horde-dev/web/horde
Found Application:        horde (active)
Found Application:          imp (inactive)
Found Application:         ingo (inactive)
Found Application:          sam (inactive)
Found Application:    kronolith (inactive)
Found Application:        turba (inactive)
Found Application:          nag (inactive)
Found Application:        mnemo (inactive)
Found Application:        trean (inactive)
Found Application:        ansel (inactive)
Found Application:       wicked (inactive)
Found Application:        chora (inactive)
Found Application:        whups (inactive)
Found Application:        luxor (inactive)
Found Application:        klutz (inactive)
Found Application:        jonah (inactive)
Found Application:       hermes (inactive)
Found Application:        sesha (inactive)
Found Application:        kolab (inactive)
Found Application:       gollem (inactive)
Found Application:       passwd (inactive)
Found Application:        agora (inactive)
Found Application:      ulaform (inactive)
Found Application:        vilma (inactive)
Found Application:      content (inactive)
Found Application:  timeobjects (inactive)


### Inject a user or change his password (AKA:  Help! I have locked myself out of horde)

If your horde authentication backend allows setting passwords through horde ...

- SQL Authentication
- Some types of LDAP/AD authentication with password change option
- Some types of IMAP setup

You can change the password for an existing user or inject a new user into horde.
This is also useful for automated deployment.
You can configure horde to use the SQL backend, migrate up the database schema from zero and inject an admin user.

```
    hordectl patch user fritz mysecretpassword
```

Remember, this only works if the auth backend supports it.

### Export resources to a yaml file

Exported resources are as backend independent as possible. Some backends may limit Horde's ability to expose every user's resources globally.
Data format is similar to the internal representation but may deviate where it's appropriate. For example, groups do not export backend keys. When a permission query has per-group permissions, the group will be referenced by display name.

    hordectl query user > user.yml
    hordectl query group > group.yml
    hordectl query permission > permission.yml

These will create individual files for resources. When exporting, order is not important.
A syntax for filtering queries or combining resource types into one file is still missing.

### Import definitions from a yaml file

    hordectl import -f user.yml
    hordectl import -f group.yml
    hordectl import -f permission.yml

Order might be significant. Permissions won't accept group permissions for groups which are not present in the system yet.
Some backends may be readonly and will not allow adding/changing some resources.

See doc dir for detailed explanations of possible input formats and their semantics

## Translations

`hordectl translation` (alias `i18n`) manages gettext-based translations across every Horde package discovered under a target's installation. It replaces the legacy `horde-translation` script with a modern PSR-4 implementation and covers the full workflow: extract, merge, compile, init, cleanup, compendium, update-help, make-help and commit. Two additional read-only subcommands, `diff` and `check`, compare committed and freshly-regenerated .pot / .po files after normalization so reviewers and CI can tell material changes apart from creation-date churn. Discovery keys off `.horde.yml` `type:`, so applications and libraries are picked up automatically without hardcoded package lists. See [doc/TRANSLATION.md](doc/TRANSLATION.md) for details.

## Intended uses

If you need a verbatim backup, you might be better off with a snapshot of the database and vfs.

- Deploy CI/CD scenario content without dependency on DB type or format
- Reproduce edge cases to demonstrate bugs
- Inject users/groups/perms into an existing or new installation
- Demo scenarios
- Migrate between backend types

## Inspirations

- yaml and similar formats from config management tools, infrastructure as code, kubectl, helm

## Will it dump existing content to yaml?

Yes, it does. For any objects defined. Maybe within some limitations. I don't know. It will evolve as I need it.

## Will it be a complete backup/restore solution?

Likely not. See horde/backup for a different take on dumping/restoring application content.

## Development notes

### Builtin commands

help    TODO give help on commands in general or on a specific command and its switches and sub commands
query   output yaml format representations of backend data for which an exporter is either builtin or provided by the app
import  generate backend data from [potentially incomplete ] yaml repesentations and builder defaults if builtin or provided by the app
patch   manipulate resources with one-shot commands.
app     TODO run app specific commands implemented in your app.

