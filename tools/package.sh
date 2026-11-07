#!/usr/bin/env bash
# Builds the release package for the PKP plugin gallery: a .tar.gz that
# contains a single directory submissionCaptcha/, and prints its MD5.
# Packs the committed state (HEAD); files marked export-ignore in
# .gitattributes (tests, tools) are left out.
#
# Usage: tools/package.sh [output directory, default: build]
set -euo pipefail

cd "$(dirname "$0")/.."

if [ -n "$(git status --porcelain)" ]; then
	echo "Working tree has uncommitted changes; commit them first." >&2
	exit 1
fi

version=$(sed -n 's:.*<release>\(.*\)</release>.*:\1:p' version.xml)
out=${1:-build}
file="$out/submissionCaptcha-$version.tar.gz"

mkdir -p "$out"
git archive --format=tar.gz --prefix=submissionCaptcha/ -o "$file" HEAD

if command -v md5sum >/dev/null; then
	md5=$(md5sum "$file" | cut -d' ' -f1)
else
	md5=$(md5 -q "$file")
fi

echo "package: $file"
echo "md5:     $md5"
