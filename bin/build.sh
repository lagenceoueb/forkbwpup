#!/usr/bin/env bash
# Construit build/oueb-wp-backup.zip depuis le dernier commit et compare son
# poids au budget du projet. Les exclusions sont dans .gitattributes.
#
# L'interface React est construite ici puis ajoutée à l'archive : dist/ n'est
# pas versionné. Les dépendances npm doivent être installées (npm ci).
set -euo pipefail
cd "$(dirname "$0")/.."

slug="oueb-wp-backup"
budget_kb="${OUEB_BUDGET_KB:-2048}"

npm run build --silent

rm -rf build
mkdir -p "build/stage/$slug"
git archive --format=zip -9 --prefix="$slug/" -o "build/$slug.zip" HEAD
cp -R dist "build/stage/$slug/dist"
(cd build/stage && zip -qr -9 "../$slug.zip" "$slug/dist")
rm -rf build/stage

size_kb=$(( $(stat -c %s "build/$slug.zip") / 1024 ))
echo "Archive : ${size_kb} Ko, budget : ${budget_kb} Ko."

if [ "$size_kb" -gt "$budget_kb" ]; then
	echo "::error::L'archive dépasse le budget de ${budget_kb} Ko."
	exit 1
fi
