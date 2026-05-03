# Plan: Extend bedrock-deployer for Roots Radicle

## Context

**bedrock-deployer** currently supports Bedrock + Sage projects. The goal is to extend it to also support **Roots Radicle** projects — the newer full-stack WordPress framework in the Roots ecosystem.

**deep-swan** is the first Radicle project that needs to use this deployer. It's been analyzed and all differences are accounted for below.

---

## How Radicle Differs from Bedrock + Sage

Understanding the differences drives every decision in this plan.

### Directory Layout

| Concern | Bedrock + Sage | Radicle (deep-swan) |
|---|---|---|
| Document root | `web/` | `public/` |
| WordPress core | `web/wp/` | `public/wp/` |
| wp-content equiv | `web/app/` | `public/content/` |
| Uploads | `web/app/uploads/` | `public/content/uploads/` |
| Themes | `web/app/themes/{name}/` | `public/content/themes/radicle/` |
| Build output | `web/app/themes/{name}/public/` | `public/build/` |
| Object cache | `web/app/object-cache.php` | `public/content/object-cache.php` |
| Storage / logs | _(not present)_ | `storage/logs/` |
| Fonts | `web/app/fonts/` | `public/content/fonts/` |

### Build System

| Concern | Bedrock + Sage | Radicle |
|---|---|---|
| Build tool | Bud or Webpack Mix (inside theme dir) | Vite (at project root) |
| Build command | `npm run build --clean --flush` | `npm run build` |
| Output location | `{theme_path}/public/` or `{theme_path}/dist/` | `public/build/` |
| What gets uploaded | Theme's `public/` or `dist/` dir | `public/build/` dir (project root) |
| Composer install for theme | Yes (Sage has its own composer.json) | No (single root composer.json) |

### Post-deploy Steps

| Concern | Bedrock + Sage | Radicle |
|---|---|---|
| Acorn optimize | Optional | Required (`wp acorn optimize`) |
| Icons cache | Not applicable | Required (`wp acorn icons:cache`) |
| Google fonts | Optional | Optional (same `acorn:fetch_google_fonts`) |

### What Does NOT Change

- `bedrock:vendors` — same (`composer install` at release root)
- `bedrock:create_env` — same (Radicle is still Bedrock-based)
- `bedrock:upload_auth_json` — same
- `wordpress:check_installation` — same
- `wordpress:clear_cache` — same
- `woocommerce:update_database` — same
- `composer:add_remote_repository_authentication` — same
- `runcloud-hub:*` — same
- `cleanup:unused_themes` — works as-is because it uses `{web_root}/wp/wp-content/themes/twenty*`; setting `web_root=public` makes it `public/wp/wp-content/themes/twenty*` which is correct for Radicle

---

## Implementation Plan

### 1. New file: `recipes/radicle.php`

This is the main addition. It mirrors `sage.php` but for Radicle's Vite-based, project-root build pipeline.

**File: `recipes/radicle.php`**

```php
<?php
namespace Deployer;

/** Default config values for Radicle projects */
set('radicle/build_output_path', 'public/build');

/** Build Radicle assets locally */
desc('Compiles Radicle assets locally for production');
task('radicle:build', function () {
    runLocally('npm ci && npm run build');
});

/** Upload built assets to remote */
desc('Uploads compiled Radicle build to remote server');
task('radicle:upload_build', function () {
    upload('{{radicle/build_output_path}}/', '{{release_path}}/{{radicle/build_output_path}}/');
});

/** Composite: build + upload */
desc('Compiles Radicle assets and uploads them to remote server');
task('radicle:compile_and_upload_build', [
    'radicle:build',
    'radicle:upload_build',
]);
```

**Why a `set()` default inside the recipe?**  
Same pattern as how Deployer's own recipes work. The project's `deploy.php` can override it if the build output ever changes.

**Why `upload('{{radicle/build_output_path}}/', ...)`?**  
The trailing slash on the source path tells rsync to upload the *contents* of `public/build/`, not the `build/` directory itself — mirroring how `sage:upload_assets` works.

---

### 2. Update `recipes/acorn.php` — Add `acorn:icons_cache`

Radicle uses [blade-icons](https://github.com/blade-ui-kit/blade-icons) and requires its icon cache to be rebuilt on every deploy. Add this alongside `acorn:optimize`.

**Current `recipes/acorn.php`:**

```php
<?php
namespace Deployer;

desc('Fetch google fonts');
task('acorn:fetch_google_fonts', function () {
    // ...
});

desc('Run Acorn Commands');
task('acorn:optimize', function () {
    within('{{release_path}}', function () {
        run('{{bin/wp_cli}} acorn optimize');
    });
});
```

**Updated `recipes/acorn.php`** — add one task at the bottom:

```php
/** Cache blade-icons (used by Radicle) */
desc('Cache Acorn blade icons');
task('acorn:icons_cache', function () {
    within('{{release_path}}', function () {
        run('{{bin/wp_cli}} acorn icons:cache');
    });
});
```

No changes needed to the existing tasks. The new task is simply additive.

---

### 3. No Changes Needed: `recipes/cleanup.php`

The current implementation:

```php
task('cleanup:unused_themes', function () {
    $webRoot = get('web_root');
    run("rm -rf {$webRoot}/wp/wp-content/themes/twenty*");
});
```

This resolves to `public/wp/wp-content/themes/twenty*` when `web_root` is `public` — which is exactly where WordPress core bundles its default themes in a Radicle project. No modification needed.

---

### 4. Update `README.md` — Add Radicle Section

Add a **Radicle** section to the README with a complete example `deploy.php`. Place it after the existing main example and before the WooCommerce section.

The example `deploy.php` for a Radicle project (e.g., deep-swan):

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
require 'vendor/csorrentino/bedrock-deployer/recipes/wordpress.php';

/** Config */
set('keep_releases', 2);
set('web_root', 'public');              // Radicle uses public/ not web/
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

/** Upload Composer auth before installing vendors */
before('deploy:vendors', 'bedrock:upload_auth_json');

/** Build Radicle assets locally and upload */
after('deploy:update_code', 'radicle:compile_and_upload_build');

/** Run Acorn optimizations after symlink swap */
after('deploy:symlink', 'acorn:optimize');
after('deploy:symlink', 'acorn:icons_cache');

/** Clear WordPress cache */
after('deploy:symlink', 'wordpress:clear_cache');

/** Remove unused themes */
after('deploy:cleanup', 'cleanup:unused_themes');

/** Unlock on failure */
after('deploy:failed', 'deploy:unlock');

/** Deploy task */
desc('Deploys your project');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'deploy:publish',
]);
```

**Key differences vs Bedrock + Sage deploy.php:**

| Setting | Bedrock + Sage | Radicle |
|---|---|---|
| `web_root` | `web` | `public` |
| Recipes included | `sage.php` | `radicle.php` |
| Sage vars (`sage/*`) | Required | Not used |
| Shared theme assets | `web/app/themes/{name}/public/` | Not shared (built per deploy) |
| Object cache path | `web/app/object-cache.php` | `public/content/object-cache.php` |
| Uploads path | `web/app/uploads` | `public/content/uploads` |
| Storage/logs | Not shared | `storage/logs` |
| `sage:vendors` hook | After `deploy:vendors` | Not used |
| `sage:compile_and_upload_assets` | After `deploy:update_code` | `radicle:compile_and_upload_build` |
| `acorn:icons_cache` | Not used | After `deploy:symlink` |

---

## Files Changed Summary

| File | Action | Reason |
|---|---|---|
| `recipes/radicle.php` | **Create** | New recipe for Radicle's Vite build pipeline |
| `recipes/acorn.php` | **Update** | Add `acorn:icons_cache` task (blade-icons) |
| `recipes/cleanup.php` | No change | Already works with `web_root=public` |
| `README.md` | **Update** | Add Radicle usage section and example deploy.php |

---

## Deploy Lifecycle for Radicle

For reference, the full deploy sequence when using the Radicle recipe:

```
deploy:prepare
  └─ bedrock:upload_auth_json        (before deploy:vendors)
deploy:vendors                        (composer install at project root)
deploy:update_code
  └─ radicle:compile_and_upload_build
       ├─ radicle:build               (npm ci && npm run build, local)
       └─ radicle:upload_build        (rsync public/build/ to remote)
deploy:publish
deploy:symlink
  ├─ acorn:optimize                   (wp acorn optimize)
  ├─ acorn:icons_cache                (wp acorn icons:cache)
  └─ wordpress:clear_cache            (wp cache flush)
deploy:cleanup
  └─ cleanup:unused_themes            (rm public/wp/wp-content/themes/twenty*)
deploy:failed
  └─ deploy:unlock
```

---

## Optional Additions (Not Required for deep-swan)

### `acorn:fetch_google_fonts` hook

If the project uses Acorn's Google Fonts integration, add:

```php
after('deploy:symlink', 'acorn:fetch_google_fonts');
```

### RunCloud

If hosted on RunCloud:

```php
require 'vendor/csorrentino/bedrock-deployer/recipes/runcloud-hub.php';

after('deploy:symlink', 'runcloud-hub:purgeall');
after('deploy:symlink', 'runcloud-hub:update-dropin');
```

### WooCommerce

If the Radicle project uses WooCommerce:

```php
require 'vendor/csorrentino/bedrock-deployer/recipes/woocommerce.php';

after('deploy:symlink', 'woocommerce:update_database');
```

---

## Todo

### Phase 1 — Recipe: `recipes/radicle.php`

- [x] Create `recipes/radicle.php`
- [x] Add default config `set('radicle/build_output_path', 'public/build')`
- [x] Add `radicle:build` task — runs `npm ci && npm run build` locally via `runLocally()`
- [x] Add `radicle:upload_build` task — uploads `{{radicle/build_output_path}}/` to `{{release_path}}/{{radicle/build_output_path}}/` with trailing slash on source so rsync copies contents not the directory itself
- [x] Add `radicle:compile_and_upload_build` composite task chaining `radicle:build` and `radicle:upload_build`

### Phase 2 — Update `recipes/acorn.php`

- [x] Add `acorn:icons_cache` task at the bottom of `recipes/acorn.php` — runs `{{bin/wp_cli}} acorn icons:cache` inside `{{release_path}}`

### Phase 3 — Update `README.md`

- [x] Add a `## Radicle` section after the existing example `deploy.php` block and before the `## WooCommerce` section
- [x] Include a complete example Radicle `deploy.php` in the new section showing: correct `web_root`, Radicle-specific shared files and dirs, `radicle.php` recipe instead of `sage.php`, and all post-deploy hook wiring (`radicle:compile_and_upload_build`, `acorn:optimize`, `acorn:icons_cache`, `wordpress:clear_cache`, `cleanup:unused_themes`)
- [x] Add a brief note in the Radicle section explaining that `acorn:fetch_google_fonts`, RunCloud, and WooCommerce tasks are optional add-ons (same as for Bedrock + Sage)

### Phase 4 — Wire up `deep-swan`

- [ ] Add `csorrentino/bedrock-deployer` as a dev dependency in deep-swan's `composer.json` (VCS repo entry + `composer require`)
- [ ] Create `deploy.php` at the root of deep-swan using the Radicle example as the base
- [ ] Fill in real values: `repository`, `hostname`, `remote_user`, `deploy_path`, and `url` for each host
- [ ] Verify `storage/logs/` is in `shared_dirs`
- [ ] Verify `public/build/` is NOT in `shared_dirs` or `shared_files`
- [ ] Do a dry-run deploy to staging: `dep deploy staging --dry-run` and check the task sequence matches the expected lifecycle

---

## Notes on deep-swan Specifics

**Node version**: deep-swan requires Node 22. The deploying machine must have Node 22 available. The `npm ci && npm run build` runs locally so this is a local machine concern, not a remote one.

**`storage/logs/`**: Radicle uses Laravel-style file logging to `storage/logs/`. This directory must be shared so logs persist across releases. If it doesn't exist yet on the remote, Deployer will create it on first deploy.

**`public/build/` is not shared**: Unlike uploads or .env, the build output is generated fresh on each deploy and uploaded to the current release. It is intentionally NOT in `shared_dirs` — each release gets its own build, and the Vite manifest hash changes every build.

**Single `composer install`**: Unlike Bedrock + Sage (which runs `composer install` at the project root AND again inside the theme directory), Radicle only needs one `composer install` at the project root. The `sage:vendors` hook is not needed and should not be included.

**`web_root` in env generation**: `bedrock:create_env` uses `get('url')` for WP_HOME and derives WP_SITEURL as `{url}/wp`. This is correct for Radicle. The task does not reference `web_root`, so it works without modification.
