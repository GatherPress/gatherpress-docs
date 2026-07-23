#!/usr/bin/env bash
#
# Build an installable plugin zip in dist/, named after the version in the
# plugin header. Packages the committed state (git archive), so dev-only
# files marked export-ignore in .gitattributes never ship.

set -euo pipefail
cd "$(dirname "$0")/.."

version=$(awk -F': *' '/^ \* Version:/ { print $2; exit }' gatherpress-docs.php | tr -d '[:space:]')

if [[ -z "$version" ]]; then
	echo "Could not read the version from gatherpress-docs.php" >&2
	exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
	echo "Warning: uncommitted changes will NOT be included (packaging HEAD)." >&2
fi

mkdir -p dist
zip="dist/gatherpress-docs-${version}.zip"
rm -f "$zip"

git archive --format=zip --prefix=gatherpress-docs/ --output="$zip" HEAD

echo "Built ${zip}"
unzip -l "$zip"
