#!/usr/bin/env bash
# Builds an installable ai-sales-rep.zip (with vendor/) into ../dist/.
set -euo pipefail
cd "$(dirname "$0")"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
find vendor -name .git -type d -prune -exec rm -rf {} +
find vendor -type d \( -name tests -o -name Tests -o -name examples -o -name docs -o -name .github \) -prune -exec rm -rf {} +
mkdir -p ../dist
rm -f ../dist/ai-sales-rep.zip
cd ..
zip -qr dist/ai-sales-rep.zip ai-sales-rep -x 'ai-sales-rep/build.sh' 'ai-sales-rep/composer.lock' 'ai-sales-rep/.gitignore'
echo "dist/ai-sales-rep.zip"
