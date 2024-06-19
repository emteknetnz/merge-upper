# merge-upper

Tool to automate doing mass merge-ups on silverstripe/recipe-kitchen-sink

**Paused work because changes in style lint was causing new yarn build errors which I was then pushing - automating anything with changes in package.json is just a bad idea**

## Usage

`php run.php <expectedAdminBranch> <isBeta>`

e.g. `php run.php 2 false`

`$expectedAdminBranch` is there to the correct version of `silversripe/admin` is being used

`$isBeta` determines whether to target the latest minor branch on the next major, or second to last minor branch on the next major, when the current branch is a major/next-minor branch e.g. `5`, `6`

Do one merge-up e.g. 5.2 to 2 at a time for the entire sink, then reinstall to do the next step e.g. 5 to 6. The idea is to ensure that you have the correct version of `silverstripe/admin` the entire time.

Manually merge-up silverstripe/admin first, running `yarn build` along the way. The ensure you've checked out the target brach for when you automatically merge-up all the other modules.
