#!/usr/bin/env bash
# Construit build/oueb-wp-backup.zip depuis le dernier commit et compare son
# poids au budget du projet. Les exclusions sont dans .gitattributes.
set -euo pipefail
cd "$(dirname "$0")/.."

slug="oueb-wp-backup"
budget_kb="${OUEB_BUDGET_KB:-2048}"

rm -rf build
mkdir -p build
git archive --format=zip -9 --prefix="$slug/" -o "build/$slug.zip" HEAD

size_kb=$(( $(stat -c %s "build/$slug.zip") / 1024 ))
echo "Archive : ${size_kb} Ko, budget : ${budget_kb} Ko."

if [ "$size_kb" -gt "$budget_kb" ]; then
	echo "::error::L'archive dépasse le budget de ${budget_kb} Ko."
	exit 1
fi
