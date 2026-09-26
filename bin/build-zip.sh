#!/usr/bin/env bash
# Builds the installable plugin ZIP (with production dependencies, without
# development files). Usage: bin/build-zip.sh [output directory]
# COMPOSER_BIN="php8.2 /usr/local/bin/composer" selects a specific PHP.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-$root/dist}"
version="$(sed -n "s/^define('AASPF_VERSION', '\(.*\)');/\1/p" "$root/podcast-forge.php")"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

rsync -a --exclude-from="$root/.distignore" --exclude=/vendor --exclude=/dist "$root/" "$work/podcast-forge/"
cp "$root/composer.json" "$root/composer.lock" "$work/podcast-forge/"
(cd "$work/podcast-forge" && ${COMPOSER_BIN:-composer} install --no-dev --optimize-autoloader --no-interaction --quiet && rm composer.lock)

mkdir -p "$out"
(cd "$work" && zip -qr "$out/podcast-forge-$version.zip" podcast-forge)
echo "$out/podcast-forge-$version.zip"
