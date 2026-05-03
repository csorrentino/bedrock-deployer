<?php
namespace Deployer;

set('radicle/build_output_path', 'public/build');

desc('Compiles Radicle assets locally for production');
task('radicle:build', function () {
    runLocally('npm ci && npm run build');
});

desc('Uploads compiled Radicle build to remote server');
task('radicle:upload_build', function () {
    upload('{{radicle/build_output_path}}/', '{{release_path}}/{{radicle/build_output_path}}/');
});

desc('Compiles Radicle assets and uploads them to remote server');
task('radicle:compile_and_upload_build', [
    'radicle:build',
    'radicle:upload_build',
]);
