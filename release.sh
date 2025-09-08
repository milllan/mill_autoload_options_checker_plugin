#!/bin/bash

# Release script for Autoloaded Options Optimizer Plugin
# Usage: ./release.sh [patch|minor|major|custom_version]
#
# This script automates the release process:
# 1. Updates version in plugin file
# 2. Commits version change
# 3. Waits for changelog update
# 4. Creates and pushes git tag
# 5. Triggers automated GitHub release
#
# Requirements:
# - Git repository with remote configured
# - No uncommitted changes
# - GitHub Actions workflows set up

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if we're in a git repository
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    print_error "Not in a git repository"
    exit 1
fi

# Check if there are uncommitted changes
if ! git diff-index --quiet HEAD --; then
    print_error "You have uncommitted changes. Please commit or stash them first."
    exit 1
fi

# Get current version from the plugin file
CURRENT_VERSION=$(grep "Version:" autoloaded_options_checker.php | head -1 | sed 's/.*Version:\s*//')
print_status "Current version: $CURRENT_VERSION"

# Determine new version
if [ $# -eq 0 ]; then
    # If no argument provided, try to get it from the plugin file
    NEW_VERSION=$CURRENT_VERSION
    print_warning "No version bump specified. Using current version: $NEW_VERSION"
elif [ "$1" = "patch" ]; then
    # Increment patch version (e.g., 4.1.3 -> 4.1.4)
    NEW_VERSION=$(echo $CURRENT_VERSION | awk -F. '{$NF = $NF + 1;} 1' | sed 's/ /./g')
elif [ "$1" = "minor" ]; then
    # Increment minor version (e.g., 4.1.3 -> 4.2.0)
    NEW_VERSION=$(echo $CURRENT_VERSION | awk -F. '{$2 = $2 + 1; $3 = 0;} 1' | sed 's/ /./g')
elif [ "$1" = "major" ]; then
    # Increment major version (e.g., 4.1.3 -> 5.0.0)
    NEW_VERSION=$(echo $CURRENT_VERSION | awk -F. '{$1 = $1 + 1; $2 = 0; $3 = 0;} 1' | sed 's/ /./g')
else
    NEW_VERSION=$1
    print_warning "Using custom version: $NEW_VERSION"
fi

print_status "New version will be: $NEW_VERSION"

# Confirm with user
read -p "Do you want to create a release for version $NEW_VERSION? (y/N): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    print_status "Release cancelled."
    exit 0
fi

# Update version in plugin file
print_status "Updating version in autoloaded_options_checker.php..."
sed -i "s/Version:.*$CURRENT_VERSION/Version:           $NEW_VERSION/" autoloaded_options_checker.php
sed -i "s/AO_PLUGIN_VERSION', '$CURRENT_VERSION'/AO_PLUGIN_VERSION', '$NEW_VERSION'/" autoloaded_options_checker.php

# Commit the version change
print_status "Committing version change..."
git add autoloaded_options_checker.php
git commit -m "Bump version to $NEW_VERSION"

# Wait a moment for the changelog workflow to complete
print_status "Waiting for changelog to be updated..."
sleep 5

# Create and push tag
TAG="v$NEW_VERSION"
print_status "Creating tag $TAG..."
git tag -a "$TAG" -m "Release $NEW_VERSION"

print_status "Pushing changes and tag to GitHub..."
git push origin main
git push origin "$TAG"

print_status "Release $NEW_VERSION created successfully!"
print_status "GitHub Actions will automatically create the GitHub release."
print_status "Check the Actions tab in your repository to monitor the release creation."