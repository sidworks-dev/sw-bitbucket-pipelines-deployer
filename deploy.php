<?php

namespace Deployer;

use Deployer\Exception\ConfigurationException;

require 'recipe/shopware.php';

set('application', 'APPLICATION_NAME_HERE');

set('repository', 'git@bitbucket.org:username/repo.git');

host('staging')
    ->set('branch', 'development') // Git Branch
    ->set('remote_user', 'SSH_USERNAME') // SSH username
    ->set('hostname', 'staging.example.com') // SSH hostname
    ->set('port', '22') // SSH Port name
    ->set('deploy_path', '/var/websites/staging') // Parent directory for "current" and "releases"
    ->set('forward_agent', false)
    ->set('keep_releases', 3);

host('production')
    ->set('branch', 'master')
    ->set('remote_user', 'SSH_USERNAME') // SSH username
    ->set('hostname', 'staging.example.com') // SSH hostname
    ->set('port', '22') // SSH Port name
    ->set('deploy_path', '/var/websites/production') // Parent directory for "current" and "releases"
    ->set('forward_agent', false)
    ->set('keep_releases', 3);

set('bin/console', '{{bin/php}} {{release_or_current_path}}/bin/console');

set('release_name', date('d-m-Y-His'));

// These files are shared among all releases.
set('shared_files', [
    '.env',
    'public/.htaccess',
    'public/.user.ini',
]);

// These directories are shared among all releases.
set('shared_dirs', [
    'config/jwt',
    'files',
    'var/log',
    'public/media',
    'public/thumbnail',
    'public/sitemap',
]);

// These directories are made writable (the definition of "writable" requires attention).
// Please note that the files in `config/jwt/*` receive special attention in the `sw:writable:jwt` task.
set('writable_dirs', [
    'config/jwt',
    'custom/plugins',
    'files',
    'public/bundles',
    'public/css',
    'public/fonts',
    'public/js',
    'public/media',
    'public/sitemap',
    'public/theme',
    'public/thumbnail',
    'var',
]);

desc('Builds your project');
task('build-sw6', [
    'deploy:prepare',
    'sw:touch_install_lock',
    'deploy:vendors',
    'sw:build',
    'sw:writable:jwt'
]);

desc('Deploys your project');
task('deploy-sw6', [
    'sw:deploy',
    'deploy:clear_paths',
    'sw:cache:warmup',
    'deploy:publish',
]);

desc('Fully Deploys your project');
task('full-deploy-sw6', [
    'deploy:unlock',
    'build-sw6',
    'deploy-sw6',
]);

// Remove this so we can skip it
Deployer::get()->tasks->remove('sw-build-without-db');
task('sw-build-without-db:get-remote-config', static function () {
});
task('sw-build-without-db', function () {
});

task('sw:build', static function () {
    if (test('[ -f {{release_path}}/bin/build.sh ]')) {
        run('cd {{release_path}} && ./bin/build.sh');
    } else {
        run('cd {{release_path}} && ./bin/build-js.sh');
    }
});

// Remove http cache warmup
task('sw:cache:warmup', static function () {
    run('cd {{release_path}} && {{bin/console}} cache:warmup');
});

task('sw:touch_install_lock', static function () {
    run('cd {{release_path}} && touch install.lock');
});

task('sw:health_checks', static function () {
    run('cd {{release_path}} && bin/console system:check --context=pre_rollout');
});

// Bring back the original code from common.php
desc('Updates code');
task('deploy:update_code', function () {
    $git = get('bin/git');
    $repository = get('repository');
    $target = get('target');

    if (empty($repository)) {
        throw new ConfigurationException("Missing 'repository' configuration.");
    }

    $targetWithDir = $target;
    if (!empty(get('sub_directory'))) {
        $targetWithDir .= ':{{sub_directory}}';
    }

    $bare = parse('{{deploy_path}}/.dep/repo');
    $env = [
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_SSH_COMMAND' => get('git_ssh_command'),
    ];

    start:
    // Clone the repository to a bare repo.
    run("[ -d $bare ] || mkdir -p $bare");
    run("[ -f $bare/HEAD ] || $git clone --mirror $repository $bare 2>&1", ['env' => $env]);

    cd($bare);

    // If remote url changed, drop `.dep/repo` and reinstall.
    if (run("$git config --get remote.origin.url") !== $repository) {
        cd('{{deploy_path}}');
        run("rm -rf $bare");
        goto start;
    }

    run("$git remote update 2>&1", ['env' => $env]);

    // Copy to release_path.
    if (get('update_code_strategy') === 'archive') {
        run("$git archive $targetWithDir | tar -x -f - -C {{release_path}} 2>&1");
    } elseif (get('update_code_strategy') === 'clone') {
        cd('{{release_path}}');
        run("$git clone -l $bare .");
        run("$git remote set-url origin $repository", ['env' => $env]);
        run("$git checkout --force $target");
    } else {
        throw new ConfigurationException(parse("Unknown `update_code_strategy` option: {{update_code_strategy}}."));
    }

    // Save git revision in REVISION file.
    $rev = escapeshellarg(run("$git rev-list $target -1"));
    run("echo $rev > {{release_path}}/REVISION");
});
