<?php
namespace Deployer;

/** Install sage composer dependencies */
desc('Runs composer install on remote server');
task('sage:vendors', function () {
    run('cd {{release_path}}/{{sage/theme_path}} && {{bin/composer}} install {{composer_options}}');
});

/** Detects the bundler used by the theme (vite or bud) */
set('sage/bundler', function () {
    $themePath = parse('{{sage/theme_path}}');
    $viteConfigs = ['vite.config.js', 'vite.config.ts', 'vite.config.mjs', 'vite.config.mts', 'vite.config.cjs'];
    foreach ($viteConfigs as $file) {
        if (file_exists($themePath . '/' . $file)) {
            return 'vite';
        }
    }
    return 'bud';
});

/** Detects the package manager used by the theme from its lockfile */
set('sage/package_manager', function () {
    $themePath = parse('{{sage/theme_path}}');
    if (file_exists($themePath . '/package-lock.json')) {
        return 'npm';
    }
    if (file_exists($themePath . '/pnpm-lock.yaml')) {
        return 'pnpm';
    }
    if (file_exists($themePath . '/yarn.lock')) {
        return 'yarn';
    }
    return 'npm';
});

/** Command used to install npm dependencies locally */
set('sage/install_command', function () {
    switch (get('sage/package_manager')) {
        case 'pnpm':
            return 'pnpm install --frozen-lockfile';
        case 'yarn':
            $themePath = parse('{{sage/theme_path}}');
            return file_exists($themePath . '/.yarnrc.yml')
                ? 'yarn install --immutable'
                : 'yarn install --frozen-lockfile';
        default:
            return 'npm ci';
    }
});

/** Command passed to the package manager's "run" script */
set('sage/build_command', function () {
    return get('sage/bundler') === 'vite' ? 'build' : 'build --clean';
});

/** Directory (relative to the theme) containing compiled assets */
set('sage/public_dir', function () {
    return get('sage/bundler') === 'vite' ? 'public/build' : 'public';
});

/** Build & copy sage assets */
desc('Compiles the theme locally for production');
task('sage:compile', function () {
    runLocally(
        'cd {{sage/theme_path}} && {{sage/install_command}} && {{sage/package_manager}} run {{sage/build_command}}'
    );
});

desc('Updates remote assets with local assets, but without deleting previous assets on destination');
task('sage:upload_assets', function () {
    $localPublicDir = parse('{{sage/theme_path}}/{{sage/public_dir}}');

    if (get('sage/bundler') === 'vite') {
        if (!file_exists($localPublicDir . '/manifest.json')) {
            throw new \RuntimeException(
                "Vite manifest.json not found in {$localPublicDir}. Run sage:compile before uploading assets."
            );
        }
        $themePath = parse('{{sage/theme_path}}');
        foreach ([$themePath . '/public/hot', $localPublicDir . '/hot'] as $hotFile) {
            if (file_exists($hotFile)) {
                warning("Local Vite dev server 'hot' file found at {$hotFile}; it will not be uploaded.");
            }
        }
    }

    run('mkdir -p {{release_path}}/{{sage/theme_path}}/{{sage/public_dir}}');
    upload($localPublicDir . '/', '{{release_path}}/{{sage/theme_path}}/{{sage/public_dir}}/', [
        'options' => ['--exclude=hot'],
    ]);
});

desc('Builds assets and uploads them to remote server');
task('sage:compile_and_upload_assets', [
    'sage:compile',
    'sage:upload_assets',
]);
