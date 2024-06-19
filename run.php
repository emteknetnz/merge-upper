<?php

$PATH = '.';

function cmd($cmd) {
    global $PATH;
    echo "Running $cmd in $PATH\n";
    return shell_exec("cd $PATH >/dev/null && $cmd");
}

$rootDir = __DIR__;
for ($i = 0; $i < 5; $i++) {
    if (!file_exists("$rootDir/.env")) {
        $rootDir = dirname($rootDir);
    } else {
        echo "Root dir is $rootDir\n";
        break;
    }
}

if (count($argv) < 2) {
    echo "Usage: php run.php <expectedAdminBranch> <isBeta>\n";
    die;
}

$expectedAdminBranch = $argv[1];
if (!is_numeric($expectedAdminBranch)) {
    echo "Expected admin branch must be a standard branch name\n";
    die;
}

$isBeta = $argv[2];
if (!in_array($isBeta, ['true', 'false'])) {
    echo "isBeta must be true or false\n";
    die;
}
$isBeta = $isBeta == 'true';

if (!file_exists("$rootDir/vendor/silverstripe/admin/node_modules")) {
    echo "\nRun yarn install in $rootDir/vendor/silverstripe/admin\n\n! AND MAKE SURE it's the version you are merging up into\n\nYou should manually merge-up admin all the way through first\n\n";
    die;
}

$actualAdminBranch = cmd("cd $rootDir/vendor/silverstripe/admin && git rev-parse --abbrev-ref HEAD");
if ($expectedAdminBranch != $actualAdminBranch) {
    echo "Expected admin version is $expectedAdminBranch but actual is $actualAdminBranch\n";
    die;
}

if (!file_exists('repositories.json')) {
    $c = file_get_contents('https://raw.githubusercontent.com/silverstripe/supported-modules/main/repositories.json');
    file_put_contents('repositories.json', $c);
    echo "Run composer update\n";
    die;
}
$json = file_get_contents('repositories.json');
$repositories = json_decode($json, true);

foreach ($repositories['supportedModules'] as $repository) {
    // packagist e.g. "silverstripe/admin", "dnadesign/silverstripe-elemental"
    $packagist = $repository['packagist'];
    $PATH = $rootDir . '/vendor/' . $packagist;
    $currentBranch = cmd('git rev-parse --abbrev-ref HEAD');
    if (preg_match('#^[0-9]$#', $currentBranch, $matches)) {
        $targetBranch = $currentBranch + 1;
        cmd('git fetch');
        $res = cmd('git branch -r');
        preg_match_all("#origin/([0-9]+)\n#", $res, $matches);
        $branches = $matches[1];
        preg_match_all("#origin/([0-9]+\.[0-9]+)\n#", $res, $matches);
        $branches = (array) array_merge($branches, $matches[1]);
        usort($branches, fn($a, $b) => version_compare($a, $b));
        $branches = array_reverse($branches);
        $j = $isBeta ? 1 : 0;
        foreach ($branches as $branch) {
            if (!preg_match("#^$targetBranch\.[0-9]+$#", $branch)) {
                continue;
            }
            if ($j > 0) {
                $j--;
                continue;
            }
            $targetBranch = $branch;
        }
    } elseif (preg_match('#^([0-9])\.[0-9]$#', $currentBranch, $matches)) {
        $targetBranch = $matches[1];
    } else {
        echo "$packagist branch $currentBranch is not a version branch\n";
        die;
    }
    die;
}
