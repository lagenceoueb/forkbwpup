# Plan de la refonte d'Oueb WP Backup

Ce document complète [`cadrage.md`](cadrage.md). Il décrit la réécriture complète du fork : architecture cible, découpage en lots, décisions prises le 3 octobre 2026.

## Décisions

| Sujet | Décision |
|---|---|
| Nom BackWPup | Retiré du code, des options, des textes, des fichiers et de l'interface. Il reste à trois endroits : la mention légale (`LICENSE`, readme, pied de page), exigée par la GPL v2, et le module d'import, qui doit lire les données de BackWPup. |
| Administration | Toute l'interface passe en React : tableau de bord, sauvegardes, stockage, planification, journal, restauration, réglages. Elle suit les maquettes validées. |
| Données de BackWPup | L'extension détecte les options `backwpup_*` et propose de les importer. Elle ne les modifie ni ne les supprime. |
| Bibliothèques d'Inpsyde | Réécrites dans l'extension. `vendor/inpsyde` disparaît. |
| Construction JavaScript | `@wordpress/scripts`, avec React et `@wordpress/components` fournis par WordPress. |
| Version minimale de WordPress | 6.6 au lieu de 6.4 : le JavaScript construit dépend du module `react-jsx-runtime`, que WordPress fournit depuis la 6.6. |
| Tâches | Une sauvegarde principale, gérée par les écrans des maquettes. Des tâches supplémentaires en mode avancé, où se rangent aussi les tâches importées de BackWPup. |

## Ce que fait le code actuel

La cartographie du 3 octobre 2026 donne l'état de départ :

- 25 700 lignes de PHP hors dépendances, avec 6 400 mentions de BackWPup ;
- toutes les tâches dans une seule option, `backwpup_jobs` ;
- un moteur qui s'enregistre dans un fichier PHP sérialisé et se relance par `wp-cron.php`, sans vrai verrou ;
- une archive écrite à la main pour tar, `ZipArchive` ou PclZip pour zip ;
- une restauration de 12 300 lignes, en jQuery, que l'écran des sauvegardes ne propose même pas ;
- un format de chiffrement avec deux défauts qui corrompent probablement les archives après un redémarrage ;
- aucune route REST, une trentaine d'actions `wp_ajax_*`.

## Architecture cible

### Principes

- PHP 8.1, `declare( strict_types=1 )`, propriétés typées, énumérations, classes en lecture seule quand c'est possible.
- Espace de noms `Oueb\WpBackup`, fichiers nommés selon les WordPress Coding Standards (`src/storage/class-s3-storage.php`), chargés par un autoloader de l'extension.
- PHPCS bloquant sur tout le nouveau code, sans règle exclue.
- Aucune dépendance Composer à l'exécution, sauf phpseclib pour SFTP. Les outils PHP intégrés suffisent pour le reste : `ZipArchive`, mysqli, OpenSSL et Sodium.
- Une seule capacité, `oueb_wp_backup_manage`, donnée aux administrateurs, ou aux super-administrateurs en multisite.

### Données

| Donnée | Stockage |
|---|---|
| Réglages | option `oueb_wp_backup_settings`, validée par un schéma |
| Tâches | option `oueb_wp_backup_jobs` |
| Exécutions et journaux | table `{prefix}oueb_wp_backup_runs`, journal détaillé dans un fichier protégé |
| Liste des sauvegardes distantes | cache par stockage, actualisable depuis l'interface |
| Secrets (mots de passe, clés) | chiffrés en base avec Sodium, clé dérivée de `AUTH_KEY` |

### Moteur

- Exécuteur par étapes : export de la base, collecte des fichiers, archive, chiffrement, envoi, rotation.
- Chaque étape sait reprendre là où elle s'est arrêtée. L'état est enregistré en base, pas dans un fichier PHP.
- Un verrou atomique empêche deux exécutions en parallèle. Le redémarrage passe par une requête vers le site, avec un jeton à usage unique.
- Export de la base avec mysqli, par lots, en SQL.
- Archive zip avec `ZipArchive`, tar.gz avec un écrivain en flux.
- Nouveau format de chiffrement : XChaCha20-Poly1305 en flux (`sodium_crypto_secretstream`). Il est authentifié, reprenable et sans les défauts de l'ancien.

### Stockages

Une interface commune, reprise des correctifs déjà fusionnés : dossier local, S3 (six fournisseurs), SFTP, kDrive, FTP. Chaque stockage sait envoyer avec reprise, lister, télécharger, supprimer, tester la connexion et appliquer la rotation.

### Déclenchement

WP-Cron, adresse de déclenchement avec clé, cron-job.org. La conversion du planning reprend les règles actuelles.

### Restauration

- Choix d'une sauvegarde dans l'historique, ou envoi d'une archive par morceaux.
- Mode maintenance pendant l'opération.
- Sauvegarde préalable de l'état actuel, cochée par défaut.
- Import SQL en flux, puis restauration des fichiers.
- Progression suivie par l'interface, reprise après un redémarrage.

### API REST

Espace `oueb-wp-backup/v1` : réglages, tâches, exécutions, sauvegardes, journaux, stockages (dont le test de connexion), planification, restauration, import BackWPup. Chaque route vérifie la capacité et le nonce REST.

### Interface React

Une seule page d'administration, « Sauvegardes ». Elle contient les sections des maquettes : tableau de bord, sauvegardes, stockage, planification, journal. S'y ajoutent l'assistant de première configuration, la restauration et les réglages avancés. L'interface vise RGAA 4.1 et WCAG 2.2 AA, et reprend la palette du cadrage. Les traductions passent par `@wordpress/i18n` et des fichiers JSON.

### Outils et tests

- PHPUnit avec Brain Monkey pour le PHP, Jest pour le JavaScript, tous deux dans `tools/` et `package.json`.
- CI : syntaxe PHP 8.1 à 8.4, PHPCS bloquant, PHPUnit, Jest, construction du JavaScript, poids de l'archive.

## Lots

Chaque lot fait l'objet d'une pull request. L'ancien code reste en place et fonctionne jusqu'au lot 6, qui bascule l'extension sur le nouveau code et supprime l'ancien.

| Lot | Contenu | Livrable vérifiable |
|---|---|---|
| 1. Socle | Fichier principal, autoloader, réglages, capacité, chiffrement des secrets, squelette REST, construction React, PHPUnit, Jest, CI | Les tests passent en CI, la page React vide s'affiche |
| 2. Moteur | Modèle de tâche, exécuteur et reprise, verrou, journal, export de la base, collecte des fichiers, archive, manifeste | Une sauvegarde complète en dossier local, testée sur un WordPress réel |
| 3. Stockages | Dossier, S3, SFTP, kDrive et FTP réécrits, rotation, liste, téléchargement, test de connexion | Envoi et rotation vers chaque stockage |
| 4. Déclenchement et chiffrement | WP-Cron, adresse de déclenchement, cron-job.org, nouveau format de chiffrement, déchiffrement | Une sauvegarde planifiée et chiffrée, puis déchiffrée |
| 5. Restauration | Historique, envoi d'archive, maintenance, import SQL, fichiers, sauvegarde préalable | Aller-retour sauvegarde puis restauration |
| 6. Interface et bascule | Interface React complète d'après les maquettes, import BackWPup, bascule, suppression de `inc/`, `views/`, `vendor/inpsyde` | L'extension tourne entièrement sur le nouveau code |
| 7. Finitions | WP-CLI, multisite, audit RGAA, traductions, version 0.1.0 | Critères de fin de la version 1.0 du cadrage |

## Points ouverts

- Les archives chiffrées par BackWPup ne se liront plus avec le nouveau format. Il faut les déchiffrer avec BackWPup avant l'import.
- Le nom du dépôt GitHub, `forkbwpup`, contient encore BackWPup. Le cadrage prévoit de le renommer en `oueb-wp-backup`.
