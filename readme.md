# Oueb WP Backup

Extension WordPress de sauvegarde et de restauration, éditée par [L'agence Oueb](https://lagenceoueb.tech). Elle s'adresse aux administrateurs de site qui veulent gérer leurs sauvegardes seuls. Les archives partent chez des hébergeurs européens ou suisses, choisis sur des critères de souveraineté et d'énergie renouvelable, ou sur un serveur que vous administrez.

L'extension est en développement (version 0.1.0). N'installez pas cette version sur un site en production.

## Origine et licence

Oueb WP Backup est un fork de [BackWPup](https://wordpress.org/plugins/backwpup/) 4.1.7, développé par Inpsyde puis WP Media. Il est distribué comme lui sous licence GPL v2 ou ultérieure (voir [`LICENSE`](LICENSE)). Le code d'origine reste la propriété de ses auteurs. Les modifications faites depuis le fork sont signées L'agence Oueb.

BackWPup est une marque de ses détenteurs. Oueb WP Backup n'est pas affilié à BackWPup, et ses éditeurs ne le soutiennent pas.

## Différences avec BackWPup

Le fork est parti du code de BackWPup, puis l'a entièrement réécrit (voir [`docs/refonte.md`](docs/refonte.md)) :

- un moteur par étapes, qui reprend après une coupure, avec un verrou contre les exécutions en double ;
- des stockages européens seulement, un client S3 écrit pour l'extension, SFTP et Infomaniak kDrive ;
- un chiffrement XChaCha20-Poly1305 des archives, avec un outil de déchiffrement hors de WordPress ;
- une restauration depuis l'administration, précédée d'une sauvegarde de l'état actuel ;
- une interface React, avec un assistant de première configuration ;
- une seule dépendance embarquée, phpseclib, pour SFTP ;
- une archive d'installation de moins de 1 Mo, pour un budget de 2 Mo que la CI fait respecter.

Les décisions du projet, les critères de choix des fournisseurs et le découpage en lots sont dans [`docs/cadrage.md`](docs/cadrage.md). Ce fichier fait référence : une décision qui le contredit passe d'abord par sa mise à jour.

## Supports de stockage

### Fournisseurs compatibles S3

Un fournisseur entre dans la liste s'il a son siège et ses centres de données dans l'Union européenne ou en Suisse, s'il n'a pas de maison mère hors d'Europe et si une source publique prouve que son électricité est renouvelable. La vérification date du 3 octobre 2026. Ses sources sont dans [`includes/storage/class-providers.php`](includes/storage/class-providers.php).

| Fournisseur | Pays | Régions proposées |
|---|---|---|
| Scaleway | France | Paris, Amsterdam, Varsovie, Milan |
| OVHcloud | France | Gravelines, Roubaix, Strasbourg, Paris, Milan, Francfort, Varsovie |
| 3DS Outscale | France | Paris |
| Hetzner | Allemagne | Falkenstein, Nuremberg, Helsinki |
| IONOS | Allemagne | Francfort, Berlin, Logroño |
| Infomaniak | Suisse | Public Cloud 1 et 2 |

La région SecNumCloud d'Outscale et Swiss Backup d'Infomaniak se configurent avec un endpoint personnalisé.

Certains sites de fournisseurs étaient inaccessibles pendant la vérification. Une partie des faits vient donc d'extraits de recherche, avec l'adresse de la page officielle. L'agence relira chaque source avant la première version publique.

### Autres supports

- **Infomaniak kDrive**, par WebDAV. Il faut un mot de passe d'application kDrive.
- **SFTP**, sur un serveur que vous administrez. L'extension enregistre l'empreinte de la clé du serveur à la première connexion, puis refuse tout serveur dont la clé a changé.
- **Dossier sur le serveur du site.** Une copie sur le même serveur que le site disparaît avec lui : gardez ce support en complément d'un stockage externe.

## Déclenchement des sauvegardes

Trois modes au choix, tâche par tâche :

- **WP-Cron**, le planificateur de WordPress. Il ne tourne que lorsque le site reçoit des visites.
- **URL de déclenchement**, à appeler depuis le service de votre choix. Elle contient une clé à garder secrète.
- **cron-job.org**, service gratuit, au code ouvert, hébergé en Allemagne. L'extension crée et met à jour la tâche distante avec votre clé d'API.

La section Planification propose des fréquences avec leur cas d'usage (deux fois par jour, chaque jour, chaque semaine, chaque mois) ou une expression cron à cinq champs, dans le fuseau du site. Une sauvegarde ne part pas plus de quatre fois par heure. Le lien de déclenchement accepte GET et POST ; une mauvaise clé reçoit une erreur 403.

## Chiffrement

Dans la nouvelle version, une option chiffre l'archive sur le serveur, avant tout envoi : les fournisseurs de stockage ne peuvent pas la lire. Le format est XChaCha20-Poly1305 en flux (libsodium) ; chaque bloc de 1 Mio est authentifié, et une archive modifiée, tronquée ou rallongée est refusée. Le chiffrement reprend après une coupure, comme les autres étapes.

Les clés se gèrent dans les réglages. La plus récente chiffre les nouvelles archives, les anciennes restent pour déchiffrer les anciennes archives. **Sans la clé, une archive chiffrée est perdue, même pour l'agence** : téléchargez chaque clé et gardez-la hors du site, par exemple dans un gestionnaire de mots de passe.

Depuis l'écran des sauvegardes, une archive chiffrée se télécharge telle quelle ou déchiffrée. Si le site a disparu, l'outil livré avec l'extension la déchiffre sans WordPress, avec PHP et Sodium :

```sh
php oueb-decrypt.php site_main_2026-10-04_030000.zip.enc cle.txt
```

## Restauration

La nouvelle version restaure une sauvegarde depuis la section « Restaurer ». L'archive vient de la liste des sauvegardes, d'un stockage (utile après la réinstallation d'un site) ou de l'ordinateur ; un envoi coupé reprend où il s'est arrêté. Les archives zip, tar.gz et tar se lisent, chiffrées ou non.

La restauration suit cet ordre :

1. L'archive est récupérée, déchiffrée, puis vérifiée d'après son manifeste. Un refus à ce stade laisse le site intact.
2. Le site actuel est sauvegardé dans le dossier local, si la case est cochée (elle l'est par défaut). Cette sauvegarde apparaît dans la liste et se restaure comme les autres.
3. Le site passe en maintenance. L'administrateur qui restaure garde l'accès pour suivre la progression.
4. La base est importée instruction par instruction, puis les fichiers sont remis en place.

Chaque étape reprend après une coupure. Quelques éléments ne changent jamais :

- les réglages d'Oueb WP Backup (stockages, clés, tâches) et l'historique des sauvegardes ;
- l'adresse du site (`siteurl`, `home`) ;
- la session de l'administrateur qui restaure, si son compte existe dans la sauvegarde ;
- `wp-config.php`, l'extension elle-même et son dossier de travail.

Les fichiers ajoutés depuis la sauvegarde restent en place.

## Prérequis

- WordPress 6.6 ou plus récent
- PHP 8.1 ou plus récent
- L'extension PHP cURL pour kDrive

WordPress refuse l'activation sur un serveur qui ne remplit pas les deux premières conditions.

## Installation

Aucune version publiée n'existe pour l'instant. Construisez l'archive depuis les sources :

```sh
git clone https://github.com/lagenceoueb/forkbwpup.git
cd forkbwpup
bin/build.sh
```

Le script produit `build/oueb-wp-backup.zip` à partir du dernier commit. Dans WordPress, ouvrez **Extensions > Ajouter une extension > Téléverser une extension** et envoyez ce fichier.

Au premier affichage, un assistant règle le contenu, le stockage et la fréquence, puis lance une première sauvegarde.

## Venir de BackWPup

Si BackWPup est installé, ou l'a été, le tableau de bord propose d'importer ses tâches et ses réglages. L'import ne modifie ni ne supprime les données de BackWPup :

- les tâches deviennent des sauvegardes supplémentaires, réglables dans **Réglages > Mode avancé** ;
- les stockages S3, SFTP, kDrive et dossier sont recréés, avec leurs mots de passe ;
- le FTP et les services retirés (Dropbox, Amazon S3, Google Drive…) sont signalés dans le rapport, tâche par tâche.

Désactivez ensuite BackWPup : sinon, les deux extensions font les mêmes sauvegardes. Les archives chiffrées par BackWPup Pro ne se lisent pas avec le nouveau format : déchiffrez-les avec BackWPup avant de le désactiver.

## Ligne de commande

La commande `wp oueb-backup` reprend les actions de l'administration. Une sauvegarde ou une restauration lancée en ligne de commande avance dans ce processus et affiche son journal ; elle n'attend pas WP-Cron.

```sh
wp oueb-backup backup                    # tâche principale
wp oueb-backup backup job-1a2b3c         # autre tâche, voir wp oueb-backup jobs
wp oueb-backup list --kind=backup
wp oueb-backup log 42
wp oueb-backup restore 42 --database     # base seule, après confirmation
wp oueb-backup restore --file=/home/site/sauvegarde.zip --yes
wp oueb-backup import                    # tâches de BackWPup
wp oueb-backup db check                  # aussi : tables, repair, optimize
```

Sans `--user`, la commande agit au nom du premier administrateur qui peut gérer les sauvegardes. `wp help oueb-backup <commande>` détaille les options.

## Multisite

En multisite, l'extension s'active sur le réseau et se règle depuis l'administration du réseau, par les super-administrateurs. Une sauvegarde couvre tout le réseau : toutes les tables et les fichiers de tous les sites.

La base d'un réseau se restaure sur ce même réseau, à la même adresse. Les réglages de l'extension et son activation sur le réseau restent ceux d'avant la restauration. Les fichiers seuls se restaurent partout.

## Développement

Le code PHP vit dans `includes/`, l'interface React dans `client/`. Le plan de la réécriture et le découpage en lots sont dans [`docs/refonte.md`](docs/refonte.md).

Les archives locales sont rangées dans `wp-content/uploads/oueb-wp-backup-<jeton>/archives/`. Un envoi interrompu reprend au dernier morceau confirmé, sauf vers kDrive : WebDAV n'envoie pas par morceaux, et l'envoi recommence. Après chaque sauvegarde, la rotation garde le nombre d'archives choisi dans chaque stockage, sans toucher aux archives des autres sites.

Le jeton aléatoire rend le nom du dossier local imprévisible. Apache et IIS appliquent les fichiers `.htaccess` et `web.config` que l'extension y dépose. Nginx les ignore : bloquez ce dossier dans la configuration du site.

```nginx
location ~ ^/wp-content/uploads/oueb-wp-backup- {
    deny all;
}
```

Le moteur avance par passages courts et se relance lui-même par une requête vers le site. Si l'hébergeur bloque ces requêtes, une tâche WP-Cron reprend la sauvegarde toutes les deux minutes.

Les outils PHP ont leur propre `composer.json` dans `tools/`, séparé des dépendances embarquées dans `vendor/`. Ceux de l'interface sont dans `package.json`.

```sh
composer --working-dir=tools install
npm ci

bin/lint.sh                  # syntaxe de chaque fichier PHP
tools/vendor/bin/phpcs       # WordPress Coding Standards
tools/vendor/bin/phpunit     # tests PHP
npm run lint:js              # style du JavaScript
npm run lint:css             # style des feuilles de style
npm test                     # tests JavaScript
npm run build                # interface construite dans dist/
bin/build.sh                 # archive build/oueb-wp-backup.zip et contrôle du poids
```

Les tests PHP tournent avec PHPUnit 9.6, comme ceux de WordPress : PHPUnit 10 exige des noms de fichiers incompatibles avec les WordPress Coding Standards.

Le budget de l'archive est de 2 048 Ko. La variable `OUEB_BUDGET_KB` permet de le changer pour un essai local.

La CI ([`.github/workflows/qualite.yml`](.github/workflows/qualite.yml)) tourne sur chaque pull request et sur chaque push sur `main` :

| Contrôle | Bloquant |
|---|---|
| Syntaxe PHP 8.1, 8.3 et 8.4 | oui |
| Tests PHP 8.1 et 8.4 | oui |
| WordPress Coding Standards, sur tout le dépôt | oui |
| Interface React : style, tests, construction | oui |
| Poids de l'archive | oui |

Le fichier [`.gitattributes`](.gitattributes) liste ce qui reste hors de l'archive distribuée : outils, documentation, CI, fichiers `.po`.

### Publier une version

1. Changez la version dans l'en-tête de `oueb-wp-backup.php`, dans `Plugin::VERSION` et dans le `Stable tag` de `readme.txt`. `bin/check-version.sh` vérifie qu'elles concordent.
2. Complétez le changelog de `readme.txt`.
3. Poussez une étiquette `vX.Y.Z` sur le commit à publier.

Le workflow [`release.yml`](.github/workflows/release.yml) construit l'archive et crée la release GitHub avec `oueb-wp-backup.zip`. Les sites qui ont l'extension voient la mise à jour dans l'administration : l'en-tête `Update URI` désigne GitHub, et `includes/update/class-github-updater.php` lit la dernière release toutes les 12 heures.

Pour wordpress.org, retirez ce fichier et l'en-tête `Update URI` : le répertoire refuse les modules de mise à jour propres à une extension.

## Limites connues

- L'interface est en anglais : la traduction française arrive au lot 7.
- La maintenance de la base vérifie, répare et optimise les tables du site. InnoDB, le moteur par défaut, ne se répare pas de cette façon : la réparation le signale sans erreur.
- Les archives chiffrées par BackWPup Pro utilisent l'ancien format, que la nouvelle version ne lit pas.
- Le moteur de la nouvelle version ne sait pas encore traverser une protection par mot de passe HTTP du site (authentification Basic) : sa relance par le site et le lien de déclenchement seraient refusés.
- Le FTP n'est plus proposé : il transmet le mot de passe en clair. Utilisez SFTP.
- La désinstallation efface les réglages et les clés de chiffrement, mais laisse les archives du dossier local. Téléchargez les clés avant de désinstaller.
- La restauration ne remplace pas les adresses dans le contenu. Une sauvegarde venue d'un autre domaine garde ses liens vers l'ancien.
- La restauration refuse une base dont le préfixe des tables diffère de celui du site. Elle refuse aussi la base d'un réseau sur un site seul, celle d'un site seul sur un réseau, et celle d'un réseau sur un réseau d'une autre adresse.
- Les tables d'un site créé après la sauvegarde restent dans la base après la restauration du réseau : le site disparaît de la liste, ses tables non.
- La restauration ne lit que les archives d'Oueb WP Backup, qui portent un manifeste. Une archive de BackWPup se restaure à la main.

## Feuille de route

Le travail avance par lots, sans échéance (détail dans [`docs/refonte.md`](docs/refonte.md)) :

1. Socle, moteur, stockages, déclenchement et chiffrement, restauration : terminés.
2. Interface complète, import de BackWPup et bascule : terminés.
3. WP-CLI, multisite, maintenance de la base, audit RGAA, traductions et version 0.1.0 publiée : lot 7, en cours.

La version 1.0 sera prête quand PHPCS passera sans erreur, quand les écrans n'auront plus de non-conformité RGAA bloquante, et quand une sauvegarde suivie d'une restauration aura réussi sur chaque support.
