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
set('sage/build_command', 'build --clean --flush'); // build --clean for bud, build:production for webpack mix
set('sage/public_dir', 'public'); // public for bud, dist for webpack mix

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
```
## WooCommerce
```php
/** Update WooCommerce tables */
after('deploy:symlink', 'woocommerce:update_database');
```

## WordPress cache
```php
/** Update WooCommerce tables */
after('deploy:symlink', 'wordpress:clear_cache');
```

## Extra commands

### Create bedrock .env file

```bash
dep bedrock:create_env staging
```

### Add repository authentication to remote server


```bash
dep composer:add_remote_repository_authentication
```
