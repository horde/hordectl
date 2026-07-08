# Installing Horde 6 with hordectl

This walkthrough takes an empty Ubuntu 24.04 host to a running Horde 6
install driven by `hordectl` from end to end. Every step is scripted;
no clicking through admin dialogs, no manual `conf.php` edits, no
hidden state.

The narrative flow is platform-agnostic. At each step where a decision
matters (web server, database, PHP variant), the paragraph names the
pivot and points at a small recipe section further down. Skip
straight to the pivot recipe if you already know what you want.

Contents:

- [Prerequisites](#prerequisites)
- [1. Install hordectl](#1-install-hordectl)
- [2. Provision the target host](#2-provision-the-target-host)
- [3. Prepare the database](#3-prepare-the-database)
- [4. Fetch and unpack Horde](#4-fetch-and-unpack-horde)
- [5. Configure the web server](#5-configure-the-web-server)
- [6. Activate Horde](#6-activate-horde)
- [7. Wire the database, session and authentication](#7-wire-the-database-session-and-authentication)
- [8. Enable the admin API](#8-enable-the-admin-api)
- [9. Create the first user](#9-create-the-first-user)
- [10. Verify the install](#10-verify-the-install)
- [Pivots](#pivots)
  - [Web server: Apache](#web-server-apache)
  - [Web server: nginx](#web-server-nginx)
  - [Web server: htaccess fallback](#web-server-htaccess-fallback)
  - [Database: MySQL / MariaDB](#database-mysql--mariadb)
  - [Database: PostgreSQL (coming later)](#database-postgresql-coming-later)

---

## Prerequisites

- Ubuntu 24.04 with a working `sudo`. Other distros work; see the
  Prerequisites banners inside each generated file for the target
  directories.
- PHP 8.4 with `cli`, `fpm`, `mysql`, `xml`, `mbstring`, `curl`,
  `gd`, `intl`, `bcmath`, `zip` extensions.
- Composer 2.
- A web server. This guide covers Apache 2.4 and nginx.
- A database. This guide covers MySQL 8; MariaDB works with the
  same driver. PostgreSQL support arrives in a future revision.

## 1. Install hordectl

```
composer global require horde/hordectl:dev-FRAMEWORK_6_0
```

hordectl is a stand-alone CLI. It does not need to live inside the
Horde install; a global composer install works. Test:

```
hordectl version
```

## 2. Provision the target host

Everything past this point runs as `sudo`, because Horde needs to
write into `/var/www/`, `/etc/apache2/`, `/etc/nginx/` etc. Adjust
paths if you deploy elsewhere; the flags below accept absolute paths.

## 3. Prepare the database

Pivot: **[MySQL/MariaDB](#database-mysql--mariadb)** is followed today.
**[PostgreSQL](#database-postgresql-coming-later)** comes later.

Regardless of engine, the outcome of this step is:

- A dedicated database named e.g. `horde`.
- A dedicated user with only the privileges Horde needs on that
  database.
- Connection credentials on hand: host, port, user, password,
  database name, charset.

Do NOT reuse credentials from other services. hordectl never
guesses; you supply them via `hordectl configure database`.

## 4. Fetch and unpack Horde

```
sudo hordectl install --install-dir=/var/www/horde --stability=dev
```

`hordectl install` downloads the latest horde/bundle tarball from
GitHub, unpacks it under `--install-dir`, runs `composer install`
inside, and creates a hordectl **target** pointing at the install.
Every subsequent hordectl command runs against that target unless
overridden with `--target=<name>`.

After this step:

- `/var/www/horde/composer.json` is the bundle root manifest.
- `/var/www/horde/vendor/horde/*` holds every Horde package.
- `/var/www/horde/web/` is the public web root (contains `horde/`,
  `imp/`, static asset directories).
- `/var/www/horde/var/` holds mutable state (config, cache, logs).

Set ownership so the web server can read everything and write into
`var/`:

```
sudo chown -R www-data:www-data /var/www/horde
sudo chmod -R 775 /var/www/horde/var
```

## 5. Configure the web server

Pivot: **[Apache](#web-server-apache)**, **[nginx](#web-server-nginx)**,
or **[htaccess fallback](#web-server-htaccess-fallback)** for shared
hosting where you don't own the vhost.

Regardless of flavor, the outcome is:

- A web server that serves the horde app at `/horde/` and every
  other Horde app at `/<app>/`.
- Non-public directories under each package (`lib/`, `config/`,
  `templates/`, `locale/`, `bin/`, `scripts/`, `data/`,
  `migration/`) forbidden from the outside.
- The `HTTP_AUTHORIZATION` header propagated to PHP so Bearer-token
  auth for the admin API works.
- php-fpm reachable from the web server.

The `hordectl webserver-config` subcommand emits these files for
any of the three flavors from the target's registry.

## 6. Activate Horde

```
sudo hordectl activate
```

`activate` copies `conf.php.dist` templates into `var/config/horde/`.
It does not seed any secrets, so a bare `activate` leaves session
encryption unconfigured. Fix that in the next step.

```
sudo chown -R www-data:www-data /var/www/horde/var
```

## 7. Wire the database, session and authentication

Three commands, one after another. Each writes to
`var/config/horde/conf.php` and creates a `.bak` beside the file:

```
sudo hordectl configure database \
    --type=mysql --host=localhost --port=3306 \
    --username=horde --password=<db-password> \
    --database=horde --charset=utf8mb4

sudo hordectl configure session \
    --type=builtin --cookie-domain='' \
    --ensure-session-secret

sudo hordectl configure auth --driver=sql --encryption=ssha
```

Notes:

- `--ensure-session-secret` autogenerates a strong 88-character
  base64 secret and injects it into `$conf['secret_key']`.
  Session HKDF derivation refuses to run without one; skipping
  this flag will lock you out with a fatal at the first request.
- `--driver=sql` picks the built-in SQL authenticator. Every
  authenticated user lives in `horde_users` in your database.

After `configure auth`, run the migrations. This creates the tables
Horde and every installed application needs:

```
sudo -u www-data /var/www/horde/vendor/bin/horde-db-migrate up
```

## 8. Enable the admin API

hordectl talks to Horde through a REST admin API. The API is off
until you generate a secret:

```
sudo hordectl target update <target-name> --endpoint=http://localhost/horde
sudo hordectl secret generate
```

`secret generate` writes a cryptographically random 128-character
Bearer token to `$conf['admin_api']['admin_secret']` AND to the
hordectl target config. Subsequent hordectl commands use the
Bearer automatically.

The `--endpoint` value is where hordectl reaches Horde. The
"display root" convention is that this URL includes the `/horde`
path, i.e. it points directly at the horde app, not at the site
root. This matches how `hordectl target show` displays it.

## 9. Create the first user

```
sudo hordectl create user --username=administrator --password=<admin-password>
```

Password from an interactive prompt is fine too; drop `--password`
and hordectl asks.

## 10. Verify the install

Two health checks. The first uses the admin API; the second is a
raw browser request:

```
sudo hordectl test all
```

Reports per-subsystem status (Database, Cache, Session, Logger,
Auth, JWT). Anything ERROR or WARNING points at a specific
subsystem to investigate. `test all` returns exit-code 0 on all-OK.

```
curl -f http://localhost/horde/
```

Should return a login page or the Horde portal. HTTP 200. Anything
else means the web server didn't come up cleanly; check its error
log.

At this point Horde runs and hordectl can drive it further:

- `hordectl query registry` dumps the compiled registry.
- `hordectl query apps` lists installed applications.
- `hordectl query user administrator` exports a user.
- `hordectl webserver-config apache-vhost` regenerates the vhost
  from the current registry (drop-in-ready).
- `hordectl translation update --locale=de --package=imp` updates
  translations.

---

## Pivots

### Web server: Apache

Generate a fresh Apache vhost from the target's registry:

```
sudo hordectl webserver-config apache-vhost \
    --default-url=http://<hostname> \
    --root-bundle-path=/var/www/horde \
    --output-dir=/var/www/horde/var/webserver/apache-vhost \
    --force
```

The output layout:

```
sites/<host>.conf                     drop-in-ready vhost file
horde-includes/apps/<app>.conf        per-app <Directory> snippets
horde-includes/tls/<host>.conf        operator-owned TLS knobs
horde-includes/local/<host>.conf      operator-owned override snippet
README.md                             per-run install instructions
```

`sites/*.conf` files go into `/etc/apache2/sites-available/`
(Debian/Ubuntu), `/etc/apache2/vhosts.d/` (SUSE), or
`/etc/httpd/conf.d/` (RHEL/Fedora). The `horde-includes/` tree
copies under Apache ServerRoot alongside your other Apache
directories (`mods-available/`, `conf-available/`, etc.).

Enable required modules:

```
sudo a2enmod rewrite proxy_fcgi setenvif
```

Enable the site and reload Apache:

```
sudo cp -r /var/www/horde/var/webserver/apache-vhost/horde-includes /etc/apache2/
sudo cp /var/www/horde/var/webserver/apache-vhost/sites/*.conf \
    /etc/apache2/sites-available/
sudo a2ensite <host>
sudo apachectl configtest
sudo systemctl reload apache2
```

TLS knobs live in `horde-includes/tls/<host>.conf`. hordectl writes
a sketch snippet the first time and never overwrites it on
subsequent regenerations. Fill in your certificate paths, cipher
suite tuning, HSTS header etc. before enabling https. See the
Prerequisites banner inside the tls file.

For per-vhost setups where an app owns its own hostname, add
`--app-webroots='<id>|https://<hostname>/,...'` to the generator
command. The emitter detects the root-anchored app and points
`DocumentRoot` at that app's fileroot.

### Web server: nginx

Same shape as the Apache flow, different flavor:

```
sudo hordectl webserver-config nginx \
    --default-url=http://<hostname> \
    --root-bundle-path=/var/www/horde \
    --output-dir=/var/www/horde/var/webserver/nginx \
    --force
```

Install:

```
sudo cp -r /var/www/horde/var/webserver/nginx/horde-includes /etc/nginx/
sudo cp /var/www/horde/var/webserver/nginx/sites/*.conf \
    /etc/nginx/sites-available/
sudo ln -s /etc/nginx/sites-available/<host>.conf \
    /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

nginx-specific notes:

- The generator emits `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`
  in every site block so Bearer-token auth for the admin API works.
  Without this parameter nginx strips the header before forwarding
  to php-fpm and every admin API call would return 401.
- The php-fpm socket path defaults to
  `/run/php/php<major>.<minor>-fpm.sock`, inheriting from
  hordectl's own PHP. Override with `--php-handler=` for
  non-Ubuntu socket paths or a TCP endpoint:
  `--php-handler=tcp:127.0.0.1:9000`.
- Operator customization goes into
  `horde-includes/local.d/<host>/*.conf`. hordectl never touches
  that directory.

### Web server: htaccess fallback

For shared hosting where you don't own the vhost:

```
sudo hordectl webserver-config htaccess \
    --output-dir=/var/www/horde/var/webserver/htaccess
```

Copies of `.htaccess` files land under each app's fileroot. Prerequisites:

- Apache 2.4+ with `mod_rewrite` and `mod_authz_core`.
- `AllowOverride FileInfo AuthConfig` (or `All`) at the parent
  `<Directory>`. htaccess is silently ignored when `AllowOverride None`.
- `Options +FollowSymLinks` (or `+SymLinksIfOwnerMatch`) if the
  docroot uses symlinks.

Limitations:

- Cannot follow registry entries whose webroot is on a different
  host. Use apache-vhost or nginx for per-vhost setups.
- Slower than vhost-based setups. Apache stats every parent
  directory of every request looking for `.htaccess`.

### Database: MySQL / MariaDB

Provision the database and a dedicated user before running
`hordectl install`:

```
sudo mysql -u root <<'EOF'
CREATE DATABASE horde CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'horde'@'localhost' IDENTIFIED BY '<db-password>';
GRANT ALL PRIVILEGES ON horde.* TO 'horde'@'localhost';
FLUSH PRIVILEGES;
EOF
```

Notes:

- Use `utf8mb4` unconditionally. Not `utf8` (which is really
  utf8mb3 on MySQL and truncates 4-byte codepoints, breaking
  emoji and some CJK characters).
- MariaDB uses the same driver. Substitute `mariadb` for `mysql`
  in the package name and systemd unit; `hordectl configure
  database --type=mysql` still applies.

### Database: PostgreSQL (coming later)

Not yet supported. When PostgreSQL support lands, this section will
cover role creation, `pg_hba.conf` setup, and the
`hordectl configure database --type=pgsql` flags. The rest of the
walkthrough won't change.
