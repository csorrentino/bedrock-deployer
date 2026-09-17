<?php
namespace Deployer;

if (! function_exists(__NAMESPACE__ . '\\acorn_available')) {
    /** Whether Acorn is registered as a wp-cli command, within {{release_path}} */
    function acorn_available(): bool
    {
        return within(
            '{{release_path}}',
            function () {
                return test('{{bin/wp_cli}} cli has-command acorn');
            }
        );
    }
}

if (! function_exists(__NAMESPACE__ . '\\acorn_has_command')) {
    /**
     * Whether the given Acorn command is available, within {{release_path}}.
     * Command availability differs between Acorn 3 and Acorn 5, so this checks
     * `acorn list --raw` output (e.g. 'optimize   Cache framework bootstrap files')
     * rather than assuming a fixed command set.
     */
    function acorn_has_command(string $command): bool
    {
        return within(
            '{{release_path}}',
            function () use ($command) {
                $pattern = '^' . preg_quote($command, '/') . '([[:space:]]|$)';

                return test('{{bin/wp_cli}} acorn list --raw | grep -Eq ' . escapeshellarg($pattern));
            }
        );
    }
}

if (! function_exists(__NAMESPACE__ . '\\acorn_guard')) {
    /**
     * Whether $command can be run, warning (and returning false) if not.
     * Distinguishes "Acorn isn't available at all" from "this command isn't registered",
     * so callers get a message that actually explains what to fix.
     */
    function acorn_guard(string $task, string $command): bool
    {
        return within(
            '{{release_path}}',
            function () use ($task, $command) {
                if (! acorn_available()) {
                    writeln("<comment>Skipped {$task}: Acorn is not available</comment>");
                    return false;
                }

                if (! acorn_has_command($command)) {
                    writeln("<comment>Skipped {$task}: acorn {$command} is not a registered command</comment>");
                    return false;
                }

                return true;
            }
        );
    }
}

/** Fetch google fonts */
desc('Fetch google fonts');
task('acorn:fetch_google_fonts', function () {
    within(
        '{{release_path}}',
        function () {
            if (! acorn_guard('acorn:fetch_google_fonts', 'google-fonts:fetch')) {
                return;
            }

            run('{{bin/wp_cli}} acorn google-fonts:fetch');
        }
    );
});

/** Run Acorn Commands */
desc('Run Acorn Commands');
task('acorn:optimize', function () {
    within(
        '{{release_path}}',
        function () {
            if (! acorn_guard('acorn:optimize', 'optimize')) {
                return;
            }

            run('{{bin/wp_cli}} acorn optimize');
        }
    );
});

desc('Clear Acorn optimizations');
task('acorn:optimize_clear', function () {
    within(
        '{{release_path}}',
        function () {
            if (! acorn_guard('acorn:optimize_clear', 'optimize:clear')) {
                return;
            }

            run('{{bin/wp_cli}} acorn optimize:clear');
        }
    );
});

desc('Cache Acorn blade icons');
task('acorn:icons_cache', function () {
    within(
        '{{release_path}}',
        function () {
            if (! acorn_guard('acorn:icons_cache', 'icons:cache')) {
                return;
            }

            run('{{bin/wp_cli}} acorn icons:cache');
        }
    );
});

desc('Cache Acorn views');
task('acorn:view_cache', function () {
    within(
        '{{release_path}}',
        function () {
            if (! acorn_guard('acorn:view_cache', 'view:cache')) {
                return;
            }

            run('{{bin/wp_cli}} acorn view:cache');
        }
    );
});
