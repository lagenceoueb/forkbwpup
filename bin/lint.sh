#!/usr/bin/env bash
# Vérifie la syntaxe de chaque fichier PHP de l'extension, dépendances comprises.
set -euo pipefail
cd "$(dirname "$0")/.."

status=0
while IFS= read -r -d '' file; do
	if ! output=$(php -l "$file" 2>&1); then
		echo "$output"
		status=1
	fi
done < <(find . -name '*.php' -not -path './tools/*' -not -path './build/*' -not -path './.git/*' -print0)

if [ "$status" -eq 0 ]; then
	echo "Syntaxe correcte."
fi
exit "$status"
