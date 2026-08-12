#!/usr/bin/env pwsh

$ErrorActionPreference = "Stop"

function Run-Step {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Command,
        [Parameter(Mandatory = $false)]
        [string[]]$Arguments = @()
    )

    Write-Host ""
    Write-Host ">>> $Command $($Arguments -join ' ')" -ForegroundColor Cyan
    & $Command @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed: $Command $($Arguments -join ' ')"
    }
}

# Ensure this script is being run from the SDK repository root.
if (-not (Test-Path "./composer.json") -or -not (Test-Path "./.git")) {
    throw "Run this script from the Feedple SDK repository root."
}

Write-Host "Feedple PHP SDK Release" -ForegroundColor Green

# Determine the next patch version from the latest vMAJOR.MINOR.PATCH tag.
$tags = git tag --list "v*.*.*" --sort=-v:refname
if ($LASTEXITCODE -ne 0) {
    throw "Unable to read Git tags."
}

if ($tags.Count -gt 0) {
    $latestTag = $tags[0]
    if ($latestTag -match '^v(\d+)\.(\d+)\.(\d+)$') {
        $major = [int]$Matches[1]
        $minor = [int]$Matches[2]
        $patch = [int]$Matches[3] + 1
        $defaultVersion = "$major.$minor.$patch"
    }
    else {
        $defaultVersion = "1.0.0"
    }
}
else {
    $latestTag = $null
    $defaultVersion = "1.0.0"
}

$versionInput = Read-Host "Release version [$defaultVersion]"
$version = if ([string]::IsNullOrWhiteSpace($versionInput)) { $defaultVersion } else { $versionInput.Trim() }

if ($version -notmatch '^\d+\.\d+\.\d+$') {
    throw "Invalid version '$version'. Use MAJOR.MINOR.PATCH, for example 1.0.1."
}

$tag = "v$version"

# Refuse to overwrite an existing release tag.
$existingTag = git tag --list $tag
if ($existingTag -eq $tag) {
    throw "Tag $tag already exists. Choose a new version."
}

# Show current working tree.
Run-Step "git" @("status", "--short")

$confirm = Read-Host "Continue release $tag? [y/N]"
if ($confirm -notmatch '^(y|yes)$') {
    Write-Host "Release cancelled."
    exit 0
}

# Validate composer.json and synchronize composer.lock.
Run-Step "composer" @("validate")
Run-Step "composer" @("update")

# Re-validate after composer update.
Run-Step "composer" @("validate")

# Commit all release changes.
Run-Step "git" @("add", "composer.json", "composer.lock")

$staged = git diff --cached --name-only
if ([string]::IsNullOrWhiteSpace(($staged -join ""))) {
    Write-Host "No Composer changes were staged. Creating the release from the current commit."
}
else {
    Run-Step "git" @("commit", "-m", "Release SDK $version")
}

# Push the release commit.
Run-Step "git" @("push", "origin", "main")

# Create and push the version tag.
Run-Step "git" @("tag", $tag)
Run-Step "git" @("push", "origin", $tag)

Write-Host ""
Write-Host "Release $tag completed successfully." -ForegroundColor Green
Write-Host ""
Write-Host "Next step in your Laravel application:"
Write-Host '  composer require feedple/feedple-sdk:^' + $version
Write-Host "or, if composer.json already contains the desired constraint:"
Write-Host "  composer update feedple/feedple-sdk"