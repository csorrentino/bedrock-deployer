<?php
namespace Deployer;

/*
|--------------------------------------------------------------------------
| RunCloud Hub
|--------------------------------------------------------------------------
| These tasks run after deploy:symlink, once the new release is already
| live. A hard failure there leaves a deployed site with a stale page cache
| and a red deploy, so a missing plugin skips with a warning instead: the
| wp-cli command only exists while the RunCloud Hub plugin is active, and it
| is deactivated often enough (staging clones, local dumps) to be worth
| tolerating. A command that exists and then fails still fails the deploy.
*/

if (! function_exists(__NAMESPACE__ . '\\runcloud_hub_guard')) {
    /** Whether `wp runcloud-hub` can be run, warning (and returning false) if not */
    function runcloud_hub_guard(string $task): bool
    {
        return within(
            '{{release_path}}',
            function () use ($task) {
                if (! test('{{bin/wp_cli}} cli has-command runcloud-hub')) {
                    writeln("<comment>Skipped {$task}: wp runcloud-hub is not a registered command " .
                        '(is the RunCloud Hub plugin active?)</comment>');
                    return false;
                }

                return true;
            }
        );
    }
}

desc('Purge all caches');
task('runcloud-hub:purgeall', function () {
    within(
        '{{release_path}}',
        function () {
            if (! runcloud_hub_guard('runcloud-hub:purgeall')) {
                return;
            }

            run('{{bin/wp_cli}} runcloud-hub purgeall');
        }
    );
});

desc('Update dropin');
task('runcloud-hub:update-dropin', function () {
    within(
        '{{release_path}}',
        function () {
            if (! runcloud_hub_guard('runcloud-hub:update-dropin')) {
                return;
            }

            run('{{bin/wp_cli}} runcloud-hub update-dropin');
        }
    );
});
