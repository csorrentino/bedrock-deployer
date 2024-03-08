<?php
namespace Deployer;

/** Fetch google fonts */
desc('Fetch google fonts');
task('acorn:fetch_google_fonts', function () {
    within(
        '{{release_path}}',
        function () {
            if (! test('wp cli has-command acorn')) {
                writeln('<comment>Aborted: Unable to fetch google fonts, wp acorn is not a registered command</comment>'); // phpcs:ignore:Generic.Files.LineLength
                return;
            }

            try {
                run('wp acorn google-fonts:fetch');
            } catch (\Exception $e) {
                writeln('<comment>Unable to fetch google fonts</comment>');
                writeln('<comment>Output: '.$e->getMessage() . '</comment>');
            }
        }
    );
});

/** Run Acorn Commands */
desc('Run Acorn Commands');
task('acorn:optimize', function () {
    within(
        '{{release_path}}',
        function () {
            run('{{bin/wp_cli}} acorn optimize');
        }
    );
});
