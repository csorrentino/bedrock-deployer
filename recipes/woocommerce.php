<?php
namespace Deployer;

desc('Update database');
task('woocommerce:update_database', function () {
    within(
        '{{release_path}}',
        function () {
            run('{{bin/wp_cli}} wc update');
        }
    );
});
