<?php

$PATH = '.';
$unprocessedPaths = [];

function cmd($cmd) {
    global $PATH;
    echo "Running $cmd in $PATH\n";
    return trim(shell_exec("cd $PATH >/dev/null && $cmd"));
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
    echo "\nRun yarn install in $rootDir/vendor/silverstripe/admin\n\nYou should manually merge-up admin all the way through before running this script. Remember to run yarn build as you go\n\n";
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
}
$json = file_get_contents('repositories.json');
$repositories = json_decode($json, true);

$i = 0;
foreach ($repositories['supportedModules'] as $repository) {
    // packagist e.g. "silverstripe/admin", "dnadesign/silverstripe-elemental"
    $packagist = $repository['packagist'];
    echo "\n\nProcessing $packagist\n\n";

    if ($packagist == 'dnadesign/silverstripe-elemental') {
        echo "Skipping dnadesign/silverstripe-elemental because it has an open pr\n";
        continue;
    }
    if ($packagist == 'silverstripe/campaign-admin') {
        echo "Skipping silverstripe/campaign-admin because it has an open pr\n";
        continue;
    }

    $i++;
    if ($i >= 5) {
        echo "Stopping because i is too high\n";
        break;
    }

    if ($packagist == 'silverstripe/admin') {
        echo "Skipping silverstripe/admin because it should be merged-up manually first\n";
        continue;
    }
    $PATH = $rootDir . '/vendor/' . $packagist;
    if (!file_exists("$PATH/$packagist")) {
        echo "Skipping $packagist because it is not in the expected directory\n";
        $unprocessedPaths[] = (string) $PATH;
        continue;
    }

    $currentBranch = cmd('git rev-parse --abbrev-ref HEAD');
    // work out $targetBranch
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
        $unprocessedPaths[] = (string) $PATH;
        continue;
    }
    $res = cmd("git checkout $targetBranch");
    if (str_contains($res, 'error: pathspec')) {
        echo "$packagist does not have a branch $targetBranch\n";
        $unprocessedPaths[] = (string) $PATH;
        continue;
    }
    $res = cmd("git merge --no-ff --no-commit $currentBranch");
    if (str_contains($res, 'Already up to date.')) {
        echo "$packagist is already up to date\n";
        continue;
    }
    cmd("git reset HEAD composer.json");
    cmd("git reset HEAD package.json");
    cmd("git reset HEAD yarn.lock");
    if (file_exists("$PATH/package.json")) {
        $res = cmd('yarn build');
        if (str_contains($res, 'error')) {
            echo "Error found when running yarn build in $packagist\n";
            $unprocessedPaths[] = (string) $PATH;
            continue;
        }
    }
    $status = cmd("git status");
    $lines = explode("\n", $status);
    $isNotStaged = false;
    $allowed = true;
    $notStagedFiles = [];
    foreach ($lines as $line) {
        if ($line == 'Changes not staged for commit:') {
            $isNotStaged = true;
        }
        if ($isNotStaged && preg_match('#modified: +(.+?)$#', $line, $matches)) {
            $notStagedFiles[] = $matches[1];
        }
        sort($notStagedFiles);
        if (
            (
                count($notStagedFiles) == 1
                && $notStagedFiles[0] != 'package.json')
            || (
                count($notStagedFiles) == 2
                && ($notStagedFiles[0] != 'package.json' || $notStagedFiles[1] != 'yarn.lock')
            )
        ) {
            echo "There are unstaged files in $packagist that should be looked at\n";
            $allowed = false;
        }
    }
    // check if contents of package.json can be automatically merged
    if ($allowed && count($notStagedFiles) && $notStagedFiles[0] == 'package.json') {
        $diff = cmd('git diff package.json');
        $allowedJsonKeysDiff = [
            'lint',
            'lint-sass',
            '@silverstripe/eslint-config',
        ];
        preg_match_all("#\n[\+\-] +\"(.+?)\"#", $diff, $matches);
        $packageJsonAllowed = true;
        foreach ((array) $matches[1] as $jsonKey) {
            if (!in_array($jsonKey, $allowedJsonKeysDiff)) {
                $packageJsonAllowed = false;
                $allowed = false;
            }
        }
        if ($packageJsonAllowed) {
            echo "There is a diff in package.json, though it IS allowed\n";
        } else {
            echo "There is a diff in package.json, though it IS NOT allowed\n";
        }
    }
    // check for merge conflicts in unstaged files (probably shouldn't happen)
    foreach ($notStagedFiles as $file) {
        $c = file_get_contents("$PATH/$file");
        if (str_contains($c, '<<<<<<< HEAD')) {
            echo "Unresolved merge conflict in $PATH/$file\n";
            $allowed = false;
        }
    }
    // check for regular merge conflicts
    $status = cmd('git status');
    if (str_contains($status, 'Unmerged paths:')) {
        echo "Unmerged paths in $packagist require manual attention\n";
        $allowed = false;
    }
    // check for untracked files
    if (str_contains($status, 'Untracked files:')) {
        echo "Untracked files in $packagist that should be looked at\n";
        $allowed = false;
    }
    if (!$allowed) {
        echo "Files in $packagist require manual attention, continuing\n";
        $allowed = false;
        $unprocessedPaths[] = (string) $PATH;
        continue;
    }
    cmd('git add .');
    cmd("git commit -m \"Merge branch '$currentBranch' into $targetBranch\"");
    cmd('git push');
    echo "Sucessfully merged-up $packagist from $currentBranch to $targetBranch\n";
}

echo "Done\n";

if (count($unprocessedPaths)) {
    echo "The current paths requires manual attention:\n";
    foreach ($unprocessedPaths as $path) {
        echo "$path\n";
    }
}
