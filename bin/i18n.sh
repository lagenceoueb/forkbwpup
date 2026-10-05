#!/usr/bin/env bash
# Met à jour les traductions : modèle .pot, fichiers .po, puis .mo pour le PHP
# et .json pour l'interface React.
#
# Demande WP-CLI (commande wp, ou WP_CLI="php wp-cli.phar"). Traduisez ensuite
# les chaînes nouvelles des .po, avec Poedit par exemple, et relancez le script.
set -euo pipefail
cd "$(dirname "$0")/.."

wp="${WP_CLI:-wp}"
domain="oueb-wp-backup"

$wp i18n make-pot . "languages/$domain.pot" \
	--slug="$domain" --domain="$domain" \
	--include="oueb-wp-backup.php,includes,client,uninstall.php" \
	--exclude="client/**/test" \
	--headers='{"Report-Msgid-Bugs-To":"https://github.com/lagenceoueb/forkbwpup/issues"}' \
	--skip-audit

for po in languages/*.po; do
	$wp i18n update-po "languages/$domain.pot" "$po"
done

$wp i18n make-mo languages

# Les chaînes de client/ finissent toutes dans dist/index.js, le seul script
# chargé : WordPress cherche les traductions sous le nom de ce fichier.
map=$(mktemp)
trap 'rm -f "$map"' EXIT
find client -name '*.js' -not -path '*/test/*' | sort \
	| awk 'BEGIN { printf "{" } { printf "%s\"%s\":\"dist/index.js\"", (NR > 1 ? "," : ""), $0 } END { print "}" }' > "$map"
$wp i18n make-json languages --no-purge --use-map="$map"

grep -c '^msgid ' "languages/$domain.pot" | xargs printf 'Chaînes : %s\n'
for po in languages/*.po; do
	# Un msgstr vide suivi d'une ligne vide : traduction absente. L'en-tête, suivi de ses lignes, ne compte pas.
	untranslated=$(awk '/^msgstr(\[[0-9]+\])? ""$/ { getline n; if (n == "" || n ~ /^msgstr/) c++ } END { print c + 0 }' "$po")
	echo "$po : $untranslated traduction(s) manquante(s)."
done
