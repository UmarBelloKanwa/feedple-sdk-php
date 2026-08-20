#!/usr/bin/env bash

set -e

# Ensure this script is being run from the SDK repository root.
if [ ! -f "./composer.json" ] || [ ! -d "./.git" ]; then
    echo "Error: Run this script from the Feedple SDK repository root."
    exit 1
fi

echo -e "\033[32mFeedple PHP SDK Release\033[0m"

# Determine the next patch version from the latest vMAJOR.MINOR.PATCH tag.
LATEST_TAG=$(git tag --list "v*.*.*" --sort=-v:refname | head -n 1)

if [[ $LATEST_TAG =~ ^v([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
    MAJOR="${BASH_REMATCH[1]}"
    MINOR="${BASH_REMATCH[2]}"
    PATCH="${BASH_REMATCH[3]}"
    DEFAULT_VERSION="${MAJOR}.${MINOR}.$((PATCH + 1))"
else
    DEFAULT_VERSION="1.0.0"
fi

read -p "Release version [$DEFAULT_VERSION]: " VERSION_INPUT
VERSION="${VERSION_INPUT:-$DEFAULT_VERSION}"
VERSION=$(echo "$VERSION" | xargs)

if [[ ! $VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Error: Invalid version '$VERSION'. Use MAJOR.MINOR.PATCH, for example 1.0.1."
    exit 1
fi

TAG="v$VERSION"

# Refuse to overwrite an existing release tag.
if git rev-parse "$TAG" >/dev/null 2>&1; then
    echo "Error: Tag $TAG already exists. Choose a new version."
    exit 1
fi

# Show current working tree.
echo ""
echo -e "\033[36m>>> git status --short\033[0m"
git status --short

read -p "Continue release $TAG? [y/N]: " CONFIRM
if [[ ! $CONFIRM =~ ^[Yy](es)?$ ]]; then
    echo "Release cancelled."
    exit 0
fi

# Validate composer.json
echo ""
echo -e "\033[36m>>> composer validate\033[0m"
composer validate

echo -e "\033[36m>>> composer update\033[0m"
composer update

echo -e "\033[36m>>> composer validate\033[0m"
composer validate

# Commit release changes if any.
git add composer.json composer.lock

if git diff --cached --quiet; then
    echo "No Composer changes staged. Creating the release from the current commit."
else
    echo -e "\033[36m>>> git commit -m \"Release SDK $VERSION\"\033[0m"
    git commit -m "Release SDK $VERSION"
fi

# Push release commit and tag.
echo -e "\033[36m>>> git push origin main\033[0m"
git push origin main || git push origin HEAD

echo -e "\033[36m>>> git tag $TAG\033[0m"
git tag "$TAG"

echo -e "\033[36m>>> git push origin $TAG\033[0m"
git push origin "$TAG"

echo ""
echo -e "\033[32mRelease $TAG completed successfully.\033[0m"
echo ""
echo "Next step in your application:"
echo "  composer require feedple/feedple-sdk:^$VERSION"
echo "or:"
echo "  composer update feedple/feedple-sdk"
