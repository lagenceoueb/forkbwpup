# Oueb WP Backup

Extension WordPress de sauvegarde et de restauration pour les administrateurs de site qui veulent gérer leurs sauvegardes seuls. Les sauvegardes partent uniquement chez des hébergeurs européens ou suisses, sélectionnés sur des critères éthiques et environnementaux.

Projet en cours de développement par [L'agence Oueb](https://lagenceoueb.tech). Ne pas installer sur un site en production pour l'instant.

## Origine

Oueb WP Backup est un fork de [BackWPup](https://wordpress.org/plugins/backwpup/) 4.1.7, développé par Inpsyde puis WP Media, et distribué comme lui sous licence GPL v2 ou ultérieure (voir `LICENSE`). Le code d'origine reste la propriété de ses auteurs ; les modifications apportées depuis le fork sont signées L'agence Oueb.

BackWPup est une marque de ses détenteurs. Oueb WP Backup n'est ni affilié à BackWPup ni soutenu par ses éditeurs.

## Ce qui change par rapport à BackWPup

- Stockage limité à des fournisseurs européens ou suisses, compatibles S3, plus SFTP et dossier local.
- Archive d'installation visée : moins de 2 Mo.
- Interface refaite pour l'autonomie des administrateurs, conforme RGAA 4.1 et WCAG 2.2 AA.
- Code aux WordPress Coding Standards.

Le détail des décisions est dans [`docs/cadrage.md`](docs/cadrage.md).

## Prérequis

- WordPress 6.4 ou plus récent
- PHP 8.1 ou plus récent

## Développement

Les outils de développement ont leur propre `composer.json` dans `tools/`, pour ne pas toucher aux dépendances embarquées dans `vendor/`.

```sh
composer --working-dir=tools install
bin/lint.sh                  # syntaxe de chaque fichier PHP
tools/vendor/bin/phpcs       # WordPress Coding Standards
bin/build.sh                 # archive build/oueb-wp-backup.zip et contrôle du poids
```

## Limites connues

- Le dépôt ne contient qu'une partie de BackWPup 4.1.7 : des dépendances de `vendor/` et des fichiers de `src/` manquent, et l'extension ne démarre pas en l'état.
- Les supports Dropbox, Azure, Rackspace, SugarSync et l'envoi par e-mail ont été retirés. Les tâches BackWPup qui les utilisent ne fonctionneront pas après import.
