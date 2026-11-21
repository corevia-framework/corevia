# V4.0.1 publication commands - approval required

Preparation does not authorize any command below that commits, pushes, merges,
creates a tag, publishes a release or submits/updates Packagist. Run the relevant
stage only after explicit approval and review the preparation report first.
GitHub commands below require authenticated Git and GitHub CLI (`gh`); `gh` is
not currently installed in the preparation environment.

## Review, commit and publish the candidate branch

From the repository in PowerShell, first review the exact staged changes. Generated
local authentication files are application-owned and must remain untracked. Do not
use `git add .` or `git add -A`.

If this Windows checkout reports dubious Git ownership, scope its existing trust
exception to this terminal session (no global Git configuration change):

```powershell
$coreviaGitConfigIndex = if ($env:GIT_CONFIG_COUNT) { [int]$env:GIT_CONFIG_COUNT } else { 0 }
Set-Item "Env:GIT_CONFIG_KEY_$coreviaGitConfigIndex" 'safe.directory'
Set-Item "Env:GIT_CONFIG_VALUE_$coreviaGitConfigIndex" ((Get-Location).Path.Replace('\', '/'))
$env:GIT_CONFIG_COUNT = [string]($coreviaGitConfigIndex + 1)
```

```powershell
git switch release/v4.0.1
git diff --cached --check
git diff --cached --stat
git diff --cached
git status --short
git commit -m "fix(packaging): prepare Corevia 4.0.1 Composer project distribution"
git push --set-upstream origin release/v4.0.1
gh pr create --repo corevia-framework/corevia --base main --head release/v4.0.1 --title "Corevia 4.0.1: Composer / Packagist compatibility" --body-file docs/v4/release-4.0.1.md
gh pr checks release/v4.0.1 --repo corevia-framework/corevia --watch
```

Before committing, update the `4.0.1` changelog heading from `Unreleased` to the
actual approved release date, stage that exact file, and rerun the archive test.
Use the existing repository-scoped Git ownership exception where this Windows
checkout needs it: `git -c safe.directory='J:/New folder/corevia' ...`.

## Merge and release after candidate CI passes and approval is granted

```powershell
gh pr merge release/v4.0.1 --repo corevia-framework/corevia --merge
git switch main
git pull --ff-only origin main
$releaseCommit = git rev-parse HEAD
gh run list --repo corevia-framework/corevia --branch main --commit $releaseCommit --workflow ci.yml
```

Wait for the listed main-commit workflow to finish successfully; inspect its jobs
and confirm the PHP matrix and packaging installation checks passed. Do not tag a
different commit or substitute historical V4.0.0 CI evidence. Then:

```powershell
git tag -a v4.0.1 -m "Corevia 4.0.1 - Composer / Packagist compatibility"
git push origin v4.0.1
gh release create v4.0.1 --repo corevia-framework/corevia --verify-tag --title "Corevia 4.0.1" --notes-file docs/v4/release-4.0.1.md
```

GitHub automatically provides the tag's source ZIP/tarball with Git export rules.
These commands do not upload or replace the separate runtime/application assets.
If those optional assets are also needed, build and independently validate them
using `tools/v4-package.py` and `tools/v4-publication.py` before approving upload.

## Packagist registration and indexing - separate approval

Supply `PACKAGIST_USERNAME` and `PACKAGIST_API_TOKEN` privately in the process
environment. Never paste the token into chat, tracked files or a command literal.
The initial creation endpoint requires the account's main token and permission
to publish under the `corevia-framework` vendor.

```powershell
if (-not $env:PACKAGIST_USERNAME -or -not $env:PACKAGIST_API_TOKEN) { throw 'Set private Packagist environment credentials first.' }
$packagistHeaders = @{ Authorization = "Bearer $($env:PACKAGIST_USERNAME):$($env:PACKAGIST_API_TOKEN)" }
$packagistBody = @{ repository = 'https://github.com/corevia-framework/corevia' } | ConvertTo-Json -Compress
```

For initial registration only, after confirming the name is not already registered:

```powershell
Invoke-RestMethod -Method Post -Uri 'https://packagist.org/api/create-package' -Headers $packagistHeaders -ContentType 'application/json' -Body $packagistBody
```

For an existing registration, or to request indexing after creation:

```powershell
Invoke-RestMethod -Method Post -Uri 'https://packagist.org/api/update-package' -Headers $packagistHeaders -ContentType 'application/json' -Body $packagistBody
```

Configure the GitHub/Packagist update integration in the package settings for future
tags. Follow the [official API documentation](https://packagist.org/apidoc#authentication)
and [version/tag guidance](https://packagist.org/about#managing-package-versions).
No hard-coded `version` field is needed in the repository's Composer manifest.

## Verify public availability

Run from a new temporary directory after Packagist reports successful indexing;
do not run inside this checkout or overwrite an existing application.

```powershell
composer show corevia-framework/corevia 4.0.1 --all
composer create-project --prefer-dist corevia-framework/corevia corevia-401-public-smoke "4.0.1"
cd corevia-401-public-smoke
composer validate --strict
composer check-platform-reqs
php corevia about
php corevia doctor
php corevia route:list
php corevia serve
```

Open http://127.0.0.1:8081, stop the development server, then verify the requested
compatible version constraint separately in another new directory:

```powershell
composer create-project --prefer-dist corevia-framework/corevia corevia-4-public-smoke "^4.0"
```

The `^4.0` constraint may choose a later compatible V4 release in the future; use
`4.0.1` when checking this exact release.
