<?php

$rootDir = __DIR__;
for ($i = 0; $i < 5; $i++) {
    if (!file_exists("$rootDir/.env")) {
        $rootDir = dirname($rootDir);
    } else {
        echo "Root dir is $rootDir\n";
        break;
    }
}

if (!file_exists("$rootDir/vendor/silverstripe/admin/node_modules")) {
    echo "\nRun yarn install in $rootDir/vendor/silverstripe/admin\n\n! AND MAKE SURE it's the version you are merging up into\n\nYou should manually merge-up admin all the way through first\n\n";
    die;
}

if (!file_exists('repositories.json')) {
    $c = file_get_contents('https://raw.githubusercontent.com/silverstripe/supported-modules/main/;repositories.json');
    file_put_contents('repositories.json', $c);
    echo "Run composer update\n";
    die;
}
$json = file_get_contents('repositories.json');
$repositories = json_decode($json, true);

foreach ($repositories['supportedModules'] as $repository) {
    // packagist e.g. "silverstripe/admin", "dnadesign/silverstripe-elemental"
    $subpath = $repository['packagist'];
    $path = $rootDir . '/vendor/' . $subpath;
    echo "$path\n";
}
