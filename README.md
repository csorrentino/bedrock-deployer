# Bedrock Deployer

## Installation

```
// add git repo to composer repositories
"repositories": [
  // ...
  {
    "type": "vcs",
    "url": "https://github.com/csorrentino/bedrock-deployer"
  }
],

composer require csorrentino/bedrock-deployer --dev
```

Tip: Add an alias to your shell config (eg. `.bashrc`,`.zshrc`)

`alias dep='vendor/bin/dep'`

This allows you to use `dep` instead of the full path for deployments

## Deploy
```
dep deploy (hostname eg. staging)
```

## Example deploy.php file

```php
<?php
namespace Deployer;

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

require 'vendor/csorrentino/bedrock-deployer/recipes/acorn.php';
require 'vendor/csorrentino/bedrock-deployer/recipes/bedrock.php';
require 'vendor/csorrentino/bedrock-deployer/recipes/cleanup.php';
require 'vendor/csorrentino/bedrock-deployer/recipes/composer.php';
require 'vendor/csorrentino/bedrock-deployer/recipes/sage.php';
require 'vendor/csorrentino/bedrock-deployer/recipes/verify.php';
require 'vendor/csorrentino/bedrock-deployer/recipes/wordpress.php';
require 'vendor/csorrentino/bedrock-deployer/recipes/woocommerce.php';

/** Config */
set('keep_releases', 2);
set('web_root', 'web');
set('bin/wp_cli', 'wp');
set('db_prefix', 'wp_');
set('application', ''); // default blank
set('repository', 'git@bitbucket.org:username/repo.git');
set('sage/theme_path', get('web_root') . '/app/themes/themename');
// sage/bundler, sage/package_manager, sage/install_command, sage/build_command,
// and sage/public_dir are auto-detected — see "Sage 10 (bud) vs Sage 11 (Vite)" below.

/** Shared files */
add('shared_files', [
    '.env',
    'auth.json',
    get('web_root') . '/.htaccess',
    get('web_root') . '/app/object-cache.php',
    get('web_root') . '/app/wp-cache-config.php',
]);

/** Shared directories */
add('shared_dirs', [
    get('web_root') . '/app/ewww',
    get('web_root') . '/app/fonts',
    get('web_root') . '/app/uploads',
]);

/** Writable directories */
add('writable_dirs', []);

/** Hosts */
host('production')
    ->set('hostname', 'ssh###.webhosting.be')
    ->set('url', '')
    ->set('remote_user', 'examplebe')
    ->set('branch', 'main')
    ->set('deploy_path', '/data/sites/web/examplebe/app/main');

host('staging')
    ->set('hostname', 'ssh###.webhosting.be')
    ->set('url', '')
    ->set('basic_auth_user', $_SERVER['BASIC_AUTH_USER'] ?? '')
    ->set('basic_auth_pass', $_SERVER['BASIC_AUTH_PASS'] ?? '')
    ->set('remote_user', 'examplebe')
    ->set('branch', 'staging')
    ->set('deploy_path', '/data/sites/web/examplebe/app/staging');

/** Install theme dependencies */
after('deploy:vendors', 'sage:vendors');

/** Push theme assets */
after('deploy:update_code', 'sage:compile_and_upload_assets');

/** Remove unused themes */
after('deploy:cleanup', 'cleanup:unused_themes');

/** Unlock deploy */
after('deploy:failed', 'deploy:unlock');

/** Copy auth.json */
before('deploy:vendors', 'bedrock:upload_auth_json');

/** Deploy */
desc('Deploys your project');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'deploy:publish',
]);

```

## Sage 10 (bud) vs Sage 11 (Vite)

`sage.php` auto-detects the theme's bundler and package manager, so most projects need no extra config beyond `sage/theme_path`:

| Setting | Auto-detected from | Values |
|---|---|---|
| `sage/theme_path` | — (required) | e.g. `web/app/themes/themename` |
| `sage/bundler` | `vite.config.*` in the theme → `vite`, else `bud` | `vite`, `bud` |
| `sage/package_manager` | lockfile in the theme: `package-lock.json` → `npm`, `pnpm-lock.yaml` → `pnpm`, `yarn.lock` → `yarn`; none → `npm` | `npm`, `pnpm`, `yarn` |
| `sage/install_command` | `sage/package_manager` | `npm ci`, `pnpm install --frozen-lockfile`, `yarn install --frozen-lockfile` (or `--immutable` when `.yarnrc.yml` exists) |
| `sage/build_command` | `sage/bundler` | vite: `build`; bud: `build --clean` |
| `sage/public_dir` | `sage/bundler` | vite: `public/build`; bud: `public` |

**Sage 10 (bud)** — matches the auto-detected default, set explicitly only if you want to be sure:

```php
set('sage/build_command', 'build --clean');
set('sage/public_dir', 'public');
```

**Sage 11 (Vite)** — also auto-detected from `vite.config.*`, or set explicitly:

```php
set('sage/build_command', 'build');
set('sage/public_dir', 'public/build');
```

Notes:

- An explicit `set()` always wins over auto-detection, so existing configs behave exactly as before.
- `sage:upload_assets` only adds files to the remote; it never deletes previously uploaded assets.
- The Vite dev server's `hot` file is never uploaded.
- A Vite deploy fails if `public/build/manifest.json` is missing locally — run `sage:compile` first.

## Acorn

`acorn.php` provides post-deploy tasks for [Roots Acorn](https://roots.io/acorn/): `acorn:optimize`, `acorn:view_cache`, `acorn:icons_cache`, `acorn:optimize_clear`, and `acorn:fetch_google_fonts`. They work against both Acorn 3 and Acorn 5. If Acorn isn't registered as a wp-cli command, or the specific command isn't available, the task is skipped with a warning rather than failing the deploy; if a registered command fails when run, the deploy fails.

```php
after('deploy:symlink', 'acorn:optimize');
after('acorn:optimize', 'acorn:view_cache');
after('acorn:view_cache', 'acorn:icons_cache');
```

## Radicle

For [Roots Radicle](https://roots.io/radicle/) projects, use `radicle.php` instead of `sage.php`. The key differences are `web_root=public`, Radicle-specific shared paths, and post-deploy Acorn tasks.

```php
<?php
namespace Deployer;

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

require 'vendor/csorrentino/bedrock-deployer/recipes/acorn.php';
require 'vendor/csorrentino/bedrock-deployer/recipes/bedrock.php';
require 'vendor/csorrentino/bedrock-deployer/recipes/cleanup.php';
require 'vendor/csorrentino/bedrock-deployer/recipes/composer.php';
require 'vendor/csorrentino/bedrock-deployer/recipes/radicle.php';
require 'vendor/csorrentino/bedrock-deployer/recipes/verify.php';
require 'vendor/csorrentino/bedrock-deployer/recipes/wordpress.php';

/** Config */
set('keep_releases', 2);
set('web_root', 'public');
set('bin/wp_cli', 'wp');
set('repository', 'git@github.com:username/repo.git');

/** Shared files */
add('shared_files', [
    '.env',
    'auth.json',
    'public/.htaccess',
    'public/content/object-cache.php',
    'public/content/wp-cache-config.php',
]);

/** Shared directories */
add('shared_dirs', [
    'public/content/uploads',
    'public/content/fonts',
    'storage/logs',
]);

/** Writable directories */
add('writable_dirs', []);

/** Hosts */
host('production')
    ->set('hostname', 'ssh###.webhosting.be')
    ->set('url', 'https://example.com')
    ->set('remote_user', 'username')
    ->set('branch', 'main')
    ->set('deploy_path', '/data/sites/web/example/app/main');

host('staging')
    ->set('hostname', 'ssh###.webhosting.be')
    ->set('url', 'https://staging.example.com')
    ->set('basic_auth_user', $_SERVER['BASIC_AUTH_USER'] ?? '')
    ->set('basic_auth_pass', $_SERVER['BASIC_AUTH_PASS'] ?? '')
    ->set('remote_user', 'username')
    ->set('branch', 'staging')
    ->set('deploy_path', '/data/sites/web/example/app/staging');

/** Copy auth.json */
before('deploy:vendors', 'bedrock:upload_auth_json');

/** Build Radicle assets locally and upload */
after('deploy:update_code', 'radicle:compile_and_upload_build');

/** Run Acorn optimizations */
after('deploy:symlink', 'acorn:optimize');
after('deploy:symlink', 'acorn:icons_cache');

/** Clear WordPress cache */
after('deploy:symlink', 'wordpress:clear_cache');

/** Remove unused themes */
after('deploy:cleanup', 'cleanup:unused_themes');

/** Unlock deploy */
after('deploy:failed', 'deploy:unlock');

/** Deploy */
desc('Deploys your project');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'deploy:publish',
]);
```

Optional add-ons work the same as for Bedrock + Sage: `acorn:fetch_google_fonts`, `runcloud-hub:*`, and `woocommerce:update_database` can all be included if needed.

## WooCommerce
```php
/** Update WooCommerce tables */
after('deploy:symlink', 'woocommerce:update_database');
```

## WordPress cache
```php
/** Clear Wordpress Cache */
after('deploy:symlink', 'wordpress:clear_cache');
```

## Extra commands

### Create bedrock .env file

```bash
dep bedrock:create_env staging
```

`bedrock:create_env` prompts for DB credentials and WP_ENV per host alias (e.g. separate prompts for `staging` and `production`), builds the `.env` file locally, and uploads it to `shared/.env` with mode `600` — the file itself is never round-tripped through a remote shell command. `local_env_file` defaults to `.env.<host-alias>` (e.g. `.env.staging`, `.env.production`), so each target reads its own file and `dep env:push staging` can never upload production credentials by mistake. Set `local_env_file` explicitly on a host to override the default. After generating the file, you're asked whether to also save a local copy to `local_env_file` for later reuse with `env:push`; an existing local file at that path is never overwritten.

### Push a local .env file to the remote

Build `.env.<host-alias>` locally (e.g. from `.env.example`, or saved by `bedrock:create_env` above), then upload it with `env:push`:

```bash
dep env:push staging
```

This uploads the local file to `shared/.env` with mode `600`. If a `shared/.env` already exists on the remote and the run is interactive, you'll be asked to confirm the overwrite, and the existing file is backed up first (`shared/.env.bak-<timestamp>`); in a non-interactive run (e.g. CI), `env:push` refuses to overwrite and fails instead.

### Verify server prerequisites before deploying

```bash
dep verify:git staging
dep verify:php staging
dep verify:vendors staging
```

`verify:git` checks that the branch being deployed matches local HEAD (Deployer clones the remote, not your working copy, so unpushed commits are silently skipped otherwise). `verify:php` checks the server's CLI PHP version against `php/min_version` (inclusive) / `php/max_version` (exclusive) if set, always requires `mysqli` (WordPress talks to the database through it directly, so it's never a Composer requirement), and otherwise checks required extensions derived from `composer.lock` — merging in the theme's own `composer.lock` too when `sage/theme_path` is set. `verify:vendors` confirms `vendor/autoload.php` (and, when `sage/theme_path` is set, the theme's own `vendor/autoload.php`) actually exists after vendors are installed. Suggested wiring:

```php
before('deploy', 'verify:git');
after('deploy:setup', 'verify:php');

// Sage sites: after theme deps install, not just Bedrock's
after('sage:vendors', 'verify:vendors');
// Non-Sage sites:
after('deploy:vendors', 'verify:vendors');
```

### Check Composer credentials before installing vendors

```bash
dep verify:composer_auth staging
```

`verify:composer_auth` scans the local `composer.json` (repositories) and `composer.lock` — plus the theme's own when `sage/theme_path` is set — for private VCS and non-public Composer sources, then checks the server for credentials in `{{release_or_current_path}}/auth.json`, `{{deploy_path}}/shared/auth.json`, and the global Composer config. It reports only credential key names, never token values, and fails before `deploy:vendors` when a private source has no matching credentials. Suggested wiring:

```php
before('deploy:vendors', 'verify:composer_auth');
```

### Add repository authentication to remote server

```bash
dep composer:add_remote_repository_authentication
```
