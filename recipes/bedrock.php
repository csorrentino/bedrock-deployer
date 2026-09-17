<?php
namespace Deployer;

// Derived from the host alias so each target reads its own file. A single
// shared value would mean `dep env:push staging` uploading production
// credentials by mistake.
if (!has('local_env_file')) {
    set('local_env_file', function () {
        return '.env.' . currentHost()->getAlias();
    });
}

/** Install sage composer dependencies */
desc('Runs composer install on remote server');
task('bedrock:vendors', function () {
    run('cd {{release_path}} && {{bin/composer}} install {{composer_options}}');
});


desc('Makes sure, .env file for Bedrock is available');
task('bedrock:create_env', function () {
    $alias = currentHost()->getAlias();

    // Check if url is set
    $url = rtrim(get('url'), '/');
    if (!$url || $url == '') {
        throw new \RuntimeException("url is not set for host {$alias}");
    }

    $deployPath = get('deploy_path');
    if (!test("[ -f {$deployPath}/shared/.env ]")) {
        // Keys that require a salt token
        $salt_keys = [
            'AUTH_KEY',
            'SECURE_AUTH_KEY',
            'LOGGED_IN_KEY',
            'NONCE_KEY',
            'AUTH_SALT',
            'SECURE_AUTH_SALT',
            'LOGGED_IN_SALT',
            'NONCE_SALT',
        ];

        writeln('<comment>Generating .env file</comment>');

        // Ask for credentials
        $dbName = ask($alias . ' DB_NAME');
        $dbUser = ask($alias . ' DB_USER');
        $dbPass = askHiddenResponse($alias . ' DB_PASSWORD');
        $dbHost = ask($alias . ' DB_HOST', $dbName . '.db.webhosting.be');
        $wpEnv  = askChoice($alias . ' WP_ENV', [
            'development' => 'development',
            'staging' => 'staging',
            'production' => 'production',
        ], 'production');

        $salts = [];
        foreach ($salt_keys as $key) {
            $salts[$key] = generate_salt();
        }

        $content = bedrock_env_content([
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_pass' => $dbPass,
            'db_host' => $dbHost,
            'wp_env'  => $wpEnv,
            'url'     => $url,
            'salts'   => $salts,
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'env');
        try {
            file_put_contents($tmp, $content);

            run("mkdir -p {$deployPath}/shared");
            upload($tmp, "{$deployPath}/shared/.env");
        } finally {
            unlink($tmp);
        }

        run("chmod 600 {$deployPath}/shared/.env");

        $localEnvFile = get('local_env_file');
        if (askConfirmation('Also save a local copy to ' . $localEnvFile . '?', false)) {
            if (file_exists($localEnvFile)) {
                warning("{$localEnvFile} already exists locally — not overwriting.");
            } else {
                file_put_contents($localEnvFile, $content);
                // Same protection as the remote copy: this file holds the DB
                // password and every salt.
                chmod($localEnvFile, 0600);
                info("Saved local copy to {$localEnvFile} (mode 600)");
            }
        }
    } else {
        writeln('<comment>.env file already exists</comment>');
    }
});

desc('Upload .env.<host alias> to shared/.env');
task('env:push', function () {
    $alias = currentHost()->getAlias();
    $local = get('local_env_file');

    if (!file_exists($local)) {
        throw new \RuntimeException("{$local} not found. Create it from .env.example first.");
    }

    $envPath = '{{deploy_path}}/shared/.env';

    if (test("[ -f {$envPath} ]")) {
        if (!input()->isInteractive()) {
            throw new \RuntimeException(
                "shared/.env already exists on {$alias} — refusing to overwrite non-interactively."
            );
        }

        if (!askConfirmation("shared/.env already exists on {$alias}. Overwrite it?", false)) {
            info('Left the existing .env alone.');

            return;
        }

        // Cheap insurance against overwriting the only copy of the WordPress salts.
        run("cp {$envPath} {$envPath}.bak-" . date('Ymd-His'));
    }

    run('mkdir -p {{deploy_path}}/shared');
    upload($local, $envPath);
    run("chmod 600 {$envPath}");

    info("Uploaded {$local} -> {$envPath} (mode 600)");
});

desc('Upload auth.json to remote');
task('bedrock:upload_auth_json', function () {
    $authJsonPath = 'auth.json';

    if (file_exists($authJsonPath)) {
        upload($authJsonPath, '{{release_path}}/auth.json');
    }
});

if (!function_exists(__NAMESPACE__ . '\\bedrock_env_escape')) {
    /**
     * Escape a value for embedding inside single quotes in a dotenv file.
     * vlucas/phpdotenv v5 supports \' for a literal quote and \\ for a
     * literal backslash inside single-quoted values.
     */
    function bedrock_env_escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}

if (!function_exists(__NAMESPACE__ . '\\bedrock_env_content')) {
    /**
     * Build the contents of a Bedrock .env file from the answers gathered in
     * bedrock:create_env. Extracted into its own function so it can be unit
     * tested without a Deployer context.
     *
     * Expected keys in $values: db_name, db_user, db_pass, db_host, wp_env, url,
     * salts (an associative array of SALT_KEY => value).
     */
    function bedrock_env_content(array $values): string
    {
        $dbName = bedrock_env_escape($values['db_name']);
        $dbUser = bedrock_env_escape($values['db_user']);
        $dbPass = bedrock_env_escape($values['db_pass']);
        $dbHost = bedrock_env_escape($values['db_host']);
        $wpEnv  = bedrock_env_escape($values['wp_env']);
        $url    = bedrock_env_escape($values['url']);

        ob_start();

        echo <<<EOL
        DB_NAME='{$dbName}'
        DB_USER='{$dbUser}'
        DB_PASSWORD='{$dbPass}'

        # Optionally, you can use a data source name (DSN)
        # When using a DSN, you can remove the DB_NAME, DB_USER, DB_PASSWORD, and DB_HOST variables
        # DATABASE_URL='mysql://root:root@database_host:database_port/local'

        # Optional database variables
        DB_HOST='{$dbHost}'
        # DB_PREFIX='wp_'

        WP_ENV='{$wpEnv}'
        WP_HOME='{$url}'
        WP_SITEURL='{$url}/wp'

        # Specify optional debug.log path
        # WP_DEBUG_LOG='/path/to/debug.log'

        # Generate your keys here: https://roots.io/salts.html
        EOL;

        echo PHP_EOL;

        foreach ($values['salts'] as $key => $salt) {
            echo $key . "='" . bedrock_env_escape($salt) . "'" . PHP_EOL;
        }

        return ob_get_clean();
    }
}

if (!function_exists(__NAMESPACE__ . '\\generate_salt')) {
    function generate_salt()
    {
        // No single quote or backslash in this charset: values built from it
        // are safe to embed directly inside single-quoted .env lines.
        $chars              = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*()-_[]{}'
            . '<>~+=,.;:/?|';
        $char_option_length = strlen($chars) - 1;

        $password = '';
        for ($i = 0; $i < 64; $i ++) {
            $password .= substr($chars, random_int(0, $char_option_length), 1);
        }

        return $password;
    }
}
