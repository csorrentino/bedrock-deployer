# Research Report: bedrock-deployer

## Overview

**Package**: `csorrentino/bedrock-deployer`  
**Author**: Chris Sorrentino (`chris@stayfresh.design`)  
**Type**: Composer library  
**Purpose**: A PHP library that provides reusable Deployer recipes for automating the deployment of [Bedrock](https://roots.io/bedrock/) WordPress sites with [Sage](https://roots.io/sage/) themes.

This is a personal/studio-internal tooling package — not published to Packagist, installed via VCS (GitHub). It wraps Deployer v7 with opinionated, pre-built tasks tailored to the Roots.io modern WordPress stack.

---

## Tech Stack

| Layer | Technology | Version |
|---|---|---|
| Language | PHP | ^8.0 |
| Deployment framework | [Deployer](https://deployer.org/) | ^7.0 (locked to 7.0.2) |
| Environment config | [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) | ^5.4 |
| Code style (dev) | PHP_CodeSniffer | ^3.7 (PSR2 standard) |

The library itself has no runtime logic outside of Deployer task definitions — it is entirely a set of recipe files to be `require`'d in a project's `deploy.php`.

---

## Directory Structure

```
vivid-moth/
├── recipes/                   # All task definitions (8 recipe files)
│   ├── acorn.php              # Laravel Acorn WP CLI commands
│   ├── bedrock.php            # Core Bedrock setup (env, vendors, auth)
│   ├── cleanup.php            # Post-deploy cleanup (unused themes)
│   ├── composer.php           # Remote composer repo authentication
│   ├── runcloud-hub.php       # RunCloud hosting cache management
│   ├── sage.php               # Sage theme build & asset upload
│   ├── wordpress.php          # WP CLI utilities (check install, clear cache)
│   └── woocommerce.php        # WooCommerce DB update task
├── .gitignore                 # Ignores vendor/
├── README.md                  # Installation & usage docs with example deploy.php
├── composer.json              # Package metadata, deps, test script
└── composer.lock              # Locked deps (Deployer 7.0.2)
```

**Total recipe code**: ~250 lines across 8 files. This is a small, focused library.

---

## Recipe Reference

### `bedrock.php` — Core Bedrock Tasks

**`bedrock:vendors`**  
Runs `composer install` on the remote server inside `{{release_path}}`. This is a remote-side PHP dependency install.

**`bedrock:create_env`**  
Interactive task to generate a Bedrock `.env` file on the remote server for first-time setup. It:
- Checks if `{{url}}` is configured (exits if empty)
- Skips if `.env` already exists in `{{deploy_path}}/shared/`
- Interactively prompts for: `DB_NAME`, `DB_USER`, `DB_PASSWORD` (hidden), `DB_HOST` (defaults to `{dbName}.db.webhosting.be`), `WP_ENV` (choice: development/staging/production)
- Generates 8 cryptographically-secure WordPress salt keys using `random_int()` — 64 characters each from a ~90-character alphabet
- Sets `WP_HOME` and `WP_SITEURL` (`{url}/wp`) automatically from the configured host URL
- Writes everything to `{deploy_path}/shared/.env`

Run it once per environment: `dep bedrock:create_env staging`

**`bedrock:upload_auth_json`**  
If a local `auth.json` file exists (Composer private package credentials), uploads it to `{{release_path}}/auth.json` before `deploy:vendors` runs. Silently skips if the file doesn't exist.

**`generate_salt()` (helper function)**  
Standalone PHP function in the `Deployer` namespace. Uses `random_int()` (CSPRNG) to build 64-character random strings. Character set: `a-z A-Z 0-9 !@#%^&*()-_[]{}<>~+=,.;:/?|` (88 chars, avoids shell-unsafe characters like `$`, `` ` ``, `"`, `'`, `\`).

---

### `sage.php` — Theme Build & Asset Pipeline

**`sage:vendors`**  
Installs Composer dependencies for the Sage theme on the remote server: `composer install` inside `{{release_path}}/{{sage/theme_path}}`.

**`sage:compile`**  
Runs locally (on the deploying machine): `npm ci && npm run {{sage/build_command}}` inside `{{sage/theme_path}}`. This compiles the theme assets before upload.

**`sage:upload_assets`**  
Uploads the compiled `{{sage/public_dir}}` directory to the remote `{{release_path}}/{{sage/theme_path}}`. Does **not** delete existing assets on the destination (additive upload).

**`sage:compile_and_upload_assets`**  
Composite task combining `sage:compile` + `sage:upload_assets` in sequence.

**Key configuration variables:**
- `sage/theme_path` — relative path to theme (e.g., `web/app/themes/themename`)
- `sage/build_command` — `build --clean --flush` for Bud, `build:production` for Webpack Mix
- `sage/public_dir` — `public` for Bud, `dist` for Webpack Mix

---

### `acorn.php` — Laravel Acorn Integration

[Acorn](https://roots.io/acorn/) is a Laravel-based framework that runs inside WordPress for Sage themes.

**`acorn:fetch_google_fonts`**  
Runs `wp acorn google-fonts:fetch` to download Google Fonts locally (for GDPR/performance). Guarded by two checks:
1. `test('wp cli has-command acorn')` — bails with a comment if WP CLI doesn't have the `acorn` command registered
2. try/catch wraps `run()` so a failed fetch doesn't break the deploy

**`acorn:optimize`**  
Runs `wp acorn optimize` inside `{{release_path}}` — equivalent to Laravel's `php artisan optimize`, caches config/routes for Acorn-based functionality.

---

### `wordpress.php` — WP CLI Utilities

**`wordpress:check_installation`**  
Verifies WordPress is installed (`wp core is-installed --skip-plugins --skip-themes`) then runs `wp core update-db` to run any pending database migrations. Use after first deploy or upgrades.

**`wordpress:clear_cache`**  
Runs `wp cache flush` on the remote. Should be hooked `after('deploy:symlink', ...)` so the symlink is already pointing at the new release when cache is flushed.

---

### `woocommerce.php` — WooCommerce

**`woocommerce:update_database`**  
Runs `wp wc update` to migrate WooCommerce database tables after plugin updates. Hook it `after('deploy:symlink', ...)`.

---

### `cleanup.php` — Theme Cleanup

**`cleanup:unused_themes`**  
Deletes default WordPress themes with `rm -rf {web_root}/wp/wp-content/themes/twenty*`. Removes all `twenty*` themes (TwentyTwenty, TwentyTwentyOne, etc.) to keep deployments clean. Runs after `deploy:cleanup`.

Note: This task uses `get('web_root')` but runs a shell command directly with `run()` rather than `within()` — the path is relative to the current working directory on the remote, which may be context-dependent. In practice it works because Deployer sets the working directory to `{{release_path}}` during task execution.

---

### `composer.php` — Remote Repository Auth

**`composer:add_remote_repository_authentication`** (`.oncePerNode()`)  
Interactive first-time setup for private Composer repositories:
1. Reads local Composer repository list with `runLocally('composer config repositories')`
2. Filters out public repos (`wpackagist.org`, `repo.packagist.org`)
3. For each remaining `composer`-type private repo, prompts for username and password (hidden)
4. Configures global auth on the remote server: `composer config -a -g http-basic.{host} {user} {pass}`

The `.oncePerNode()` decorator ensures this only runs once per remote host even in multi-host deployments.

Run manually: `dep composer:add_remote_repository_authentication`

---

### `runcloud-hub.php` — RunCloud Hosting Integration

[RunCloud Hub](https://runcloud.io/) is a WordPress plugin for RunCloud-managed servers that provides Redis/FastCGI caching.

**`runcloud-hub:purgeall`**  
Runs `wp runcloud-hub purgeall` to clear all server caches.

**`runcloud-hub:update-dropin`**  
Runs `wp runcloud-hub update-dropin` to update the `object-cache.php` dropin after deploys.

---

## Deployment Configuration Variables

All Deployer configuration values used across recipes:

| Variable | Description | Example |
|---|---|---|
| `release_path` | Current release directory (Deployer built-in) | `/data/sites/.../releases/42` |
| `deploy_path` | Base deployment directory | `/data/sites/web/example/app/main` |
| `bin/composer` | Composer binary path | `composer` |
| `bin/wp_cli` | WP CLI binary path | `wp` |
| `web_root` | Web root directory name | `web` |
| `db_prefix` | WordPress table prefix | `wp_` |
| `stage` | Deployment stage | `production`, `staging` |
| `url` | Site URL (used in .env generation) | `https://example.com` |
| `sage/theme_path` | Path to Sage theme | `web/app/themes/themename` |
| `sage/build_command` | NPM build command | `build --clean --flush` |
| `sage/public_dir` | Compiled assets directory | `public` |
| `basic_auth_user` | HTTP Basic Auth user (staging) | from env |
| `basic_auth_pass` | HTTP Basic Auth password (staging) | from env |

---

## Deployment Lifecycle

The intended deploy task ordering (from the example `deploy.php`):

```
deploy:prepare
  └─ bedrock:upload_auth_json   (before deploy:vendors)
deploy:vendors
  └─ sage:vendors               (after deploy:vendors)
deploy:update_code
  └─ sage:compile_and_upload_assets  (after deploy:update_code)
deploy:publish
deploy:symlink
  ├─ wordpress:clear_cache      (after deploy:symlink — optional)
  └─ woocommerce:update_database (after deploy:symlink — optional)
deploy:cleanup
  └─ cleanup:unused_themes      (after deploy:cleanup)
deploy:failed
  └─ deploy:unlock              (after deploy:failed)
```

Assets are compiled **locally** before symlink swap, so there's no build toolchain required on the remote server.

---

## Shared Files & Directories

Deployer keeps these files/directories persistent across releases (symlinked into each release):

**Shared files** (one copy, symlinked):
- `.env` — Bedrock environment configuration
- `auth.json` — Composer private package credentials
- `web/.htaccess` — WordPress URL rewrites
- `web/app/object-cache.php` — Object cache dropin
- `web/app/wp-cache-config.php` — Cache configuration

**Shared directories** (one copy, symlinked):
- `web/app/uploads/` — WordPress media uploads
- `web/app/fonts/` — Locally-fetched fonts (from Acorn Google Fonts)
- `web/app/ewww/` — EWWW image optimization cache

---

## Hosting Context

The example configuration targets **webhosting.be** (a Belgian shared hosting provider), using:
- SSH hostname pattern: `ssh###.webhosting.be`
- Deployment path pattern: `/data/sites/web/{username}/app/{branch}`
- Default DB host: `{db_name}.db.webhosting.be`
- `keep_releases: 2` — only 2 releases kept on disk

RunCloud support is also first-class, with dedicated tasks for its caching layer.

---

## Code Quality & Patterns

**PSR2 compliance**: All recipe files pass `phpcs --standard=PSR2 --exclude=PSR1.Files.SideEffects recipes`. The side-effects exclusion is necessary because Deployer recipe files call `task()`, `desc()`, etc. at the top level — this is how Deployer's DSL works.

**Security notes**:
- `generate_salt()` uses `random_int()` (CSPRNG) — correct
- Salt character set deliberately excludes `$`, `` ` ``, `"`, `'`, `\` to avoid shell quoting issues when echoed into the `.env` file
- Passwords prompted with `askHiddenResponse()` — not echoed to terminal
- The `bedrock:create_env` task uses string interpolation to build a shell `echo` command containing passwords — this is a potential shell injection vector if passwords contain special characters, though the hidden-response collection and controlled environment mitigates risk in practice

**Deployer patterns used**:
- `run()` — executes command on remote server
- `runLocally()` — executes command on the deploying machine
- `upload()` — transfers files/directories to remote via rsync/scp
- `within()` — sets working directory for a block of commands
- `test()` — runs a shell test expression, returns bool
- `ask()` / `askHiddenResponse()` / `askChoice()` — interactive prompts
- `.oncePerNode()` — runs task only once per unique remote host

---

## Installation & Usage Summary

1. Add the GitHub VCS repo to your project's `composer.json` repositories
2. `composer require csorrentino/bedrock-deployer --dev`
3. Create `deploy.php` at project root, `require` the needed recipes
4. Configure hosts, shared files, and task hooks
5. Run `dep deploy staging` or `dep deploy production`

For first-time environment setup:
```bash
dep bedrock:create_env staging
dep composer:add_remote_repository_authentication
```

---

## Summary

`bedrock-deployer` is a thin, focused Deployer recipe library. Its value is in reducing boilerplate for anyone deploying Bedrock + Sage stacks — instead of writing all these tasks from scratch per project, you `require` this package and wire up the hooks. The library is opinionated toward a specific hosting setup (webhosting.be, RunCloud) but the core tasks (`bedrock:vendors`, `sage:compile_and_upload_assets`, `wordpress:clear_cache`, etc.) are generic enough to work on any SSH-accessible host.

The codebase is deliberately minimal — no abstractions, no framework beyond Deployer itself, no tests beyond code style. It's internal tooling that trades generality for simplicity.
