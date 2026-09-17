<?php
namespace Deployer;

/*
|--------------------------------------------------------------------------
| Unpushed work
|--------------------------------------------------------------------------
| Deployer clones {{repository}} — the remote — not the working copy. Local
| commits that have not been pushed are simply absent from the release, and
| nothing says so: the deploy succeeds, and release_name is often built from
| local HEAD, so the release even looks named after a commit it does not
| contain.
*/

/** Check the branch being deployed matches local HEAD */
desc('Check the branch being deployed matches local HEAD');
task('verify:git', function () {
    $branch = get('branch');
    $repository = get('repository');

    $local = trim(runLocally('git rev-parse HEAD'));
    $localBranch = trim(runLocally('git rev-parse --abbrev-ref HEAD'));

    $remoteRef = trim(runLocally("git ls-remote {$repository} refs/heads/{$branch} 2>&1 || true"));

    if (! preg_match('/^([0-9a-f]{40})\s/', $remoteRef, $matches)) {
        warning("Could not read {$branch} from the remote — skipping the check.");
        warning('Deployer will still clone the remote, not your working copy.');
        return;
    }

    $remote = $matches[1];

    if ($remote === $local) {
        info("Deploying {$branch} at ".substr($local, 0, 7).', matching local HEAD.');
        return;
    }

    $ahead = trim(runLocally("git rev-list --count {$remote}..HEAD 2>/dev/null || echo 0"));

    $message = "The deploy would ship {$branch} at ".substr($remote, 0, 7)
        .', but local HEAD is '.substr($local, 0, 7)." ({$localBranch}).";

    if ((int) $ahead > 0) {
        $log = trim(runLocally("git log --oneline {$remote}..HEAD 2>/dev/null | head -10 || true"));

        throw new \RuntimeException(
            $message."\n{$ahead} local commit(s) would NOT be deployed:\n{$log}\n\n"
            .'Push first:  git push origin '.$branch
        );
    }

    warning($message);
    warning('Local HEAD is not ahead, so this may be deliberate — continuing.');
});

/*
|--------------------------------------------------------------------------
| PHP pre-flight
|--------------------------------------------------------------------------
| Composer resolves against whichever PHP invokes it, so a version mismatch
| between the server's CLI PHP and its web-facing PHP produces a vendor/
| tree built for the wrong runtime — a failure that only surfaces later, at
| request time, as an opaque error.
|
| Required extensions are derived from composer.lock rather than hardcoded,
| so this stays accurate as dependencies change. Bedrock has no default
| minimum/ceiling of its own the way a framework recipe would, so those come
| from php/min_version (inclusive) / php/max_version (exclusive) if set, and
| are skipped otherwise.
*/

/**
 * Collect required and provided (polyfilled) ext-* names across one or more
 * composer.lock files.
 *
 * @param string[] $lockPaths
 * @return array{required: array<string, bool>, provided: array<string, bool>}
 */
if (! function_exists(__NAMESPACE__.'\\verify_collect_extensions')) {
    function verify_collect_extensions(array $lockPaths)
    {
        $provided = [];
        $required = [];

        foreach ($lockPaths as $lockPath) {
            if (! file_exists($lockPath)) {
                continue;
            }

            $lock = json_decode(file_get_contents($lockPath), true);

            foreach ($lock['packages'] ?? [] as $package) {
                foreach (array_keys($package['provide'] ?? []) as $name) {
                    if (strpos($name, 'ext-') === 0) {
                        $provided[substr($name, 4)] = true;
                    }
                }

                foreach (array_keys($package['require'] ?? []) as $name) {
                    if (strpos($name, 'ext-') === 0) {
                        $required[substr($name, 4)] = true;
                    }
                }
            }
        }

        return ['required' => $required, 'provided' => $provided];
    }
}

/** Check the server PHP meets requirements */
desc('Check the server PHP meets requirements');
task('verify:php', function () {
    $minimum = has('php/min_version') && get('php/min_version') !== '' ? get('php/min_version') : null;
    $below = has('php/max_version') && get('php/max_version') !== '' ? get('php/max_version') : null;

    $binPhp = parse('{{bin/php}}');

    if (strpos($binPhp, '/') !== false) {
        if (! test("[ -x {$binPhp} ]")) {
            throw new \RuntimeException("{$binPhp} is not executable on the server.");
        }
    } else {
        if (! test("command -v {$binPhp}")) {
            throw new \RuntimeException("{$binPhp} was not found on the server's PATH.");
        }
    }

    // Parsed from -v rather than -r, because -r does not exist on the CGI
    // SAPI and fails with a usage dump that buries the actual problem.
    $banner = run('{{bin/php}} -v 2>&1 || true');

    if (! preg_match('/^PHP (\d+\.\d+\.\d+)\S*\s+\(([^)]+)\)/m', $banner, $matches)) {
        throw new \RuntimeException(
            parse('Could not read a version from {{bin/php}} -v. Got:')."\n".$banner
        );
    }

    [, $version, $sapi] = $matches;

    if ($sapi !== 'cli') {
        throw new \RuntimeException(
            parse("{{bin/php}} is the '{$sapi}' SAPI, not CLI.")."\n"
            .'Composer and WP-CLI need the CLI binary; a CGI one often prints HTTP '
            ."headers and has no -r flag.\n"
            .'Set bin/php to the CLI binary in hosts.yaml.'
        );
    }

    if ($minimum !== null && version_compare($version, $minimum, '<')) {
        throw new \RuntimeException(
            "Server PHP is {$version}; this project needs >= {$minimum}."
            .' Set php/min_version / point bin/php at a build in range.'
        );
    }

    if ($below !== null && version_compare($version, $below, '>=')) {
        throw new \RuntimeException(
            "Server PHP is {$version}; this project needs < {$below}."
            .' Set php/max_version / point bin/php at a build in range.'
        );
    }

    info("PHP {$version} ({$sapi}) — in range.");

    // Composer must run through the same binary, or it resolves platform
    // requirements against a different version than the one serving requests.
    $composerVersion = trim(run('{{bin/composer}} --version 2>&1 || true'));

    // Matched against a real version string, not merely the word "composer".
    // A shell wrapper handed to PHP echoes its own source and exits 0, and
    // that source often mentions composer.phar — enough to pass a substring
    // check while nothing is actually executable.
    if (! preg_match('/Composer(?:\s+version)?\s+(\d+)\.(\d+)\.\S+/i', $composerVersion, $composerMatch)) {
        throw new \RuntimeException(
            parse('{{bin/composer}} did not report a version. Got:')."\n".$composerVersion."\n"
            .'If that looks like shell script source, bin/composer points at a wrapper '
            .'rather than the phar.'
        );
    }

    info("Composer {$composerMatch[1]}.{$composerMatch[2]} via the pinned PHP.");

    $lockPaths = [getcwd().'/composer.lock'];

    $themePath = get('sage/theme_path', null);

    if ($themePath !== null && $themePath !== '') {
        $lockPaths[] = getcwd().'/'.$themePath.'/composer.lock';
    }

    // A missing lock only costs us the composer-derived extensions; mysqli is
    // checked either way, since that is the one WordPress cannot run without.
    if (! file_exists($lockPaths[0])) {
        warning('composer.lock not found locally — checking mysqli only.');
    }

    $extensions = verify_collect_extensions($lockPaths);
    $provided = $extensions['provided'];
    $required = $extensions['required'];

    // Lowercased because `php -m` reports some modules in mixed case — PDO,
    // SPL, Phar, SimpleXML, Reflection — while Composer's ext-* names are
    // always lowercase. Composer normalises the same way.
    $loaded = array_flip(array_map(
        function ($line) {
            return strtolower(trim($line));
        },
        explode("\n", run('{{bin/php}} -m'))
    ));

    $missing = [];
    $polyfilled = [];

    foreach (array_keys($required) as $extension) {
        if (isset($loaded[$extension])) {
            continue;
        }

        isset($provided[$extension])
            ? $polyfilled[] = $extension
            : $missing[] = $extension;
    }

    // No package declares ext-mysqli — WordPress talks to the database
    // through it directly rather than through a PDO driver, so it never
    // shows up as a Composer requirement and needs checking explicitly.
    if (! isset($loaded['mysqli'])) {
        $missing[] = 'mysqli';
    }

    if ($polyfilled !== []) {
        info('Satisfied by polyfill: '.implode(', ', $polyfilled));
    }

    if ($missing !== []) {
        throw new \RuntimeException(
            'Missing PHP extensions on the server: '.implode(', ', $missing)."\n"
            .'Enable them in your hosting control panel, or pick a build that has them.'
        );
    }

    info('All required extensions present.');
});

/*
|--------------------------------------------------------------------------
| Vendor sanity check
|--------------------------------------------------------------------------
| Composer can exit 0 without having installed anything — a malformed command
| line is enough. deploy:vendors then reports success and the first symptom
| is an unrelated-looking fatal in the next task:
|
|   Failed opening required '.../vendor/autoload.php'
|
| Checking the one file everything else depends on turns that into an error
| that names the actual problem. Also checks the theme's own vendor tree
| when sage/theme_path is set and the theme ships its own composer.json —
| Sage themes install their Composer dependencies separately from Bedrock's.
*/

/** Confirm Composer actually installed something */
desc('Confirm Composer actually installed something');
task('verify:vendors', function () {
    $autoload = '{{release_or_current_path}}/vendor/autoload.php';

    if (! test("[ -f {$autoload} ]")) {
        throw new \RuntimeException(
            "Composer reported success but {$autoload} does not exist.\n"
            .'Run the install by hand to see what it did: '
            .parse('cd {{release_or_current_path}} && {{bin/composer}} install -v')
        );
    }

    info('vendor/autoload.php present.');

    $themePath = get('sage/theme_path', null);

    if ($themePath === null || $themePath === '') {
        return;
    }

    $themeRoot = '{{release_or_current_path}}/'.$themePath;
    $themeComposerJson = $themeRoot.'/composer.json';

    if (! test("[ -f {$themeComposerJson} ]")) {
        return;
    }

    $themeAutoload = $themeRoot.'/vendor/autoload.php';

    if (! test("[ -f {$themeAutoload} ]")) {
        throw new \RuntimeException(
            "Theme has a composer.json but {$themeAutoload} does not exist.\n"
            .'Run the install by hand to see what it did: '
            .parse("cd {$themeRoot} && {{bin/composer}} install -v")
        );
    }

    info('Theme vendor/autoload.php present.');
});

/*
|--------------------------------------------------------------------------
| Composer credential pre-flight
|--------------------------------------------------------------------------
| Composer installs fail mid-run, not before, when a private repository has
| no credentials — and on a shared IP even public GitHub gets rate-limited.
| Both are cheaper to catch here, before deploy:vendors writes a half-built
| vendor/ tree. Sources are read from the local project being deployed, so
| the check reflects what this release will actually try to fetch.
*/

/**
 * Quote a remote path for the shell without killing a leading ~.
 *
 * deploy_path is often written as ~/webapps/site, and escapeshellarg() would
 * quote the tilde along with the rest, leaving the shell looking for a
 * directory literally named "~". Quoting everything after the tilde keeps the
 * expansion while still protecting spaces and metacharacters in the path.
 */
if (! function_exists(__NAMESPACE__.'\\verify_quote_remote_path')) {
    function verify_quote_remote_path(string $path): string
    {
        if ($path === '~') {
            return '~';
        }

        if (strpos($path, '~/') === 0) {
            return '~/'.escapeshellarg(substr($path, 2));
        }

        return escapeshellarg($path);
    }
}

/**
 * Find Composer sources that need credentials.
 *
 * @param string[] $paths Directory paths that each contain a composer.json
 *                        and/or composer.lock.
 * @return string[]
 */
if (! function_exists(__NAMESPACE__.'\\verify_private_sources')) {
    function verify_private_sources(array $paths): array
    {
        $publicLockHosts = [
            'github.com',
            'gitlab.com',
            'bitbucket.org',
            'packagist.org',
            'repo.packagist.org',
            'wpackagist.org',
            'api.github.com',
            'codeload.github.com',
            'raw.githubusercontent.com',
            'objects.githubusercontent.com',
            'api.bitbucket.org',
            'packages.wpackagist.org',
        ];

        $private = [];

        foreach ($paths as $path) {
            $composerJson = $path.'/composer.json';
            $composerLock = $path.'/composer.lock';

            if (file_exists($composerJson)) {
                $json = json_decode((string) file_get_contents($composerJson), true);

                if (is_array($json)) {
                    $repositories = $json['repositories'] ?? [];

                    if (is_array($repositories)) {
                        foreach ($repositories as $repository) {
                            if (! is_array($repository)) {
                                continue;
                            }

                            $type = strtolower((string) ($repository['type'] ?? ''));
                            $url = (string) ($repository['url'] ?? '');

                            if ($url === '') {
                                continue;
                            }

                            if (in_array($type, ['vcs', 'git', 'github', 'gitlab', 'bitbucket'], true)) {
                                $private[$type.' repository '.$url] = true;

                                continue;
                            }

                            if ($type !== 'composer') {
                                continue;
                            }

                            $host = parse_url($url, PHP_URL_HOST);

                            if (! is_string($host) || $host === '') {
                                $host = $url;
                            }

                            if ($host !== 'repo.packagist.org' && $host !== 'wpackagist.org') {
                                $private['composer repository '.$url] = true;
                            }
                        }
                    }
                }
            }

            if (file_exists($composerLock)) {
                $lock = json_decode((string) file_get_contents($composerLock), true);

                if (is_array($lock)) {
                    // Deploys install with --no-dev, so packages-dev is ignored.
                    // Only source URLs are checked: a private dist host always
                    // comes from a composer-type repository that composer.json
                    // already flags above.
                    foreach ($lock['packages'] ?? [] as $package) {
                        if (! is_array($package)) {
                            continue;
                        }

                        $url = (string) ($package['source']['url'] ?? '');

                        if ($url === '') {
                            continue;
                        }

                        $host = parse_url($url, PHP_URL_HOST);

                        if (! is_string($host) || $host === '') {
                            $host = $url;
                        }

                        if (in_array($host, $publicLockHosts, true) || str_ends_with($host, '.wordpress.org')) {
                            continue;
                        }

                        $private['packages from '.$host] = true;
                    }
                }
            }
        }

        return array_keys($private);
    }
}

/** Check the server has credentials Composer needs */
desc('Check the server has credentials Composer needs');
task('verify:composer_auth', function () {
    $composerJson = getcwd().'/composer.json';

    if (! file_exists($composerJson)) {
        warning('composer.json not found locally — skipping the Composer auth check.');

        return;
    }

    $paths = [getcwd()];

    $themePath = get('sage/theme_path', null);

    if ($themePath !== null && $themePath !== '') {
        $paths[] = getcwd().'/'.$themePath;
    }

    $privateSources = verify_private_sources($paths);

    $releaseAuth = parse('{{release_or_current_path}}/auth.json');
    $sharedAuth = parse('{{deploy_path}}/shared/auth.json');
    $binComposer = parse('{{bin/composer}}');

    $credentialLocation = null;
    $credentialKeys = [];

    $keyPattern = '"github-oauth"\|"http-basic"\|"bearer"\|"gitlab-token"';

    $authLocations = [
        'release auth.json' => $releaseAuth,
        'shared auth.json' => $sharedAuth,
    ];

    foreach ($authLocations as $label => $authPath) {
        $quotedPath = verify_quote_remote_path($authPath);

        if (! test("[ -s {$quotedPath} ]")) {
            continue;
        }

        $keys = trim(run(
            "grep -o '{$keyPattern}' {$quotedPath} 2>/dev/null | sort -u || true"
        ));

        if ($keys === '') {
            continue;
        }

        $credentialLocation = $label;
        $credentialKeys = array_values(array_unique(array_filter(array_map(
            function ($line) {
                return str_replace('"', '', trim($line));
            },
            explode("\n", $keys)
        ))));

        break;
    }

    if ($credentialLocation === null) {
        $globalKeys = trim(run(
            $binComposer.' config --global --list 2>/dev/null'
            ." | grep -oE '\\[(github-oauth|http-basic|bearer|gitlab-token)[^]]*\\]'"
            ." | tr -d '[]' | sort -u || true"
        ));

        if ($globalKeys !== '') {
            $credentialLocation = 'global Composer config';
            $credentialKeys = array_values(array_unique(array_filter(array_map(
                function ($line) {
                    return trim($line);
                },
                explode("\n", $globalKeys)
            ))));
        }
    }

    if ($credentialLocation !== null) {
        info('Composer credentials found in '.$credentialLocation.': '.implode(', ', $credentialKeys));

        return;
    }

    if ($privateSources !== []) {
        throw new \RuntimeException(
            "Composer needs credentials for private sources, but none were found on the server.\n"
            .'Private sources:'."\n"
            .'- '.implode("\n- ", $privateSources)."\n\n"
            .'Checked:'."\n"
            .'1. '.$releaseAuth."\n"
            .'2. '.$sharedAuth."\n"
            .'3. '.$binComposer.' config --global --list'."\n\n"
            .'Add credentials with one of:'."\n"
            .'- bedrock:upload_auth_json'."\n"
            .'- composer:add_remote_repository_authentication'."\n"
            .'- composer config -g github-oauth.github.com <token>'
        );
    }

    warning('No private sources or Composer credentials found. On a shared IP, '
        .'public GitHub installs may still hit API rate limits — add a token if that happens.');
});
