<?php
namespace Deployer;

/** Check Wordpress Installation - clear cache */
desc('Check WordPress Installation');
task('wordpress:check_installation', function () {
    within(
        '{{release_path}}',
        function () {
            run('{{bin/wp_cli}} core is-installed --skip-plugins --skip-themes');
            run('{{bin/wp_cli}} core update-db');
        }
    );
});


/** Clear cache */
desc('Clear WordPress cache');
task('wordpress:clear_cache', function () {
    within(
        '{{release_path}}',
        function () {
            run('{{bin/wp_cli}} cache flush');
        }
    );
});

/** Set admin email address */
desc('Set administration email address');
task('wordpress:set_admin_email', function () {
    runWpQuery('wordpress/admin-email');
});
