<?php
namespace Deployer;

require_once 'contrib/slack.php';
require_once 'functions.php';

// require all files in recipes
foreach (glob(__DIR__ . '/recipes/*.php') as $filename) {
    require_once $filename;
}

/** Config */
set('keep_releases', 2);
set('slack_success_text', 'Deploy to *{{target}}* successful. Visit {{url}}/wp/wp-admin.');
set('web_root', 'web');
set('sage/public_dir', 'public');
set('bin/wp_cli', 'wp');
set('db_prefix', 'wp_');

/** Shared files */
add('shared_files', [
    '.env',
    'auth.json',
    get('web_root') . '/.htaccess',
    get('web_root') . '/.htpasswd',
    get('web_root') . '/.user.ini',
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
