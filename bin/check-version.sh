#!/usr/bin/env bash
# Vérifie que la version donnée est celle de l'en-tête de l'extension, de
# Plugin::VERSION et du Stable tag du readme. Sans argument, vérifie que les
# trois concordent.
set -euo pipefail
cd "$(dirname "$0")/.."

header=$(sed -n 's/^ \* Version: *//p' oueb-wp-backup.php)
constant=$(sed -n "s/.*const VERSION = '\(.*\)';/\1/p" includes/class-plugin.php)
stable=$(sed -n 's/^Stable tag: *//p' readme.txt)
wanted="${1:-$header}"

status=0
for pair in "en-tête|$header" "Plugin::VERSION|$constant" "readme.txt|$stable"; do
	if [ "${pair#*|}" != "$wanted" ]; then
		echo "::error::Version ${pair#*|} dans ${pair%%|*}, attendue : $wanted."
		status=1
	fi
done

[ "$status" -eq 0 ] && echo "Version $wanted partout."
exit "$status"
