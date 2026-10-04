# Oueb WP Backup

Extension WordPress de sauvegarde et de restauration, éditée par [L'agence Oueb](https://lagenceoueb.tech). Elle s'adresse aux administrateurs de site qui veulent gérer leurs sauvegardes seuls. Les archives partent chez des hébergeurs européens ou suisses, choisis sur des critères de souveraineté et d'énergie renouvelable, ou sur un serveur que vous administrez.

L'extension est en développement (version 0.0.1). N'installez pas cette version sur un site en production.

## Origine et licence

Oueb WP Backup est un fork de [BackWPup](https://wordpress.org/plugins/backwpup/) 4.1.7, développé par Inpsyde puis WP Media. Il est distribué comme lui sous licence GPL v2 ou ultérieure (voir [`LICENSE`](LICENSE)). Le code d'origine reste la propriété de ses auteurs. Les modifications faites depuis le fork sont signées L'agence Oueb.

BackWPup est une marque de ses détenteurs. Oueb WP Backup n'est pas affilié à BackWPup, et ses éditeurs ne le soutiennent pas.

## Différences avec BackWPup

Le fork garde le moteur de BackWPup : sauvegarde de la base et des fichiers, restauration depuis l'administration, multisite, commandes WP-CLI, maintenance de la base. Il change le reste :

- les supports de stockage hors d'Europe, le code de la version Pro et les appels à des services externes sont retirés ;
- un client S3 écrit pour l'extension remplace le SDK AWS ;
- les destinations SFTP et Infomaniak kDrive sont ajoutées ;
- les tâches peuvent être déclenchées par cron-job.org ;
- l'archive d'installation pèse moins de 2 Mo, et la CI refuse toute modification qui dépasse ce budget.

Les décisions du projet, les critères de choix des fournisseurs et le découpage en lots sont dans [`docs/cadrage.md`](docs/cadrage.md). Ce fichier fait référence : une décision qui le contredit passe d'abord par sa mise à jour.

## Supports de stockage

### Fournisseurs compatibles S3

Un fournisseur entre dans la liste s'il a son siège et ses centres de données dans l'Union européenne ou en Suisse, s'il n'a pas de maison mère hors d'Europe et si une source publique prouve que son électricité est renouvelable. La vérification date du 3 octobre 2026. Ses sources sont dans [`inc/oueb-providers.php`](inc/oueb-providers.php).

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
- **FTP**, repris de BackWPup.
- **Dossier sur le serveur du site.** Une copie sur le même serveur que le site disparaît avec lui : gardez ce support en complément d'un stockage externe.

## Déclenchement des sauvegardes

Trois modes au choix, tâche par tâche :

- **WP-Cron**, le planificateur de WordPress. Il ne tourne que lorsque le site reçoit des visites.
- **URL de déclenchement**, à appeler depuis le service de votre choix. Elle contient une clé à garder secrète.
- **cron-job.org**, service gratuit, au code ouvert, hébergé en Allemagne. L'extension crée et met à jour la tâche distante avec votre clé d'API.

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

N'activez pas Oueb WP Backup sur un site où BackWPup est actif (voir les limites connues).

## Développement

L'extension est en cours de réécriture, lot par lot : le plan est dans [`docs/refonte.md`](docs/refonte.md). Le nouveau code vit dans `includes/` (PHP) et `client/` (React). L'ancien code, dans `inc/`, `src/` et `views/`, fonctionne jusqu'à la bascule du lot 6.

La nouvelle interface ne s'affiche que si `wp-config.php` contient :

```php
define( 'OUEB_WP_BACKUP_NEXT', true );
```

Avec cette constante, le tableau de bord lance aussi les sauvegardes du nouveau moteur. Elles restent sur le serveur, dans `wp-content/uploads/oueb-wp-backup-<jeton>/`, en attendant les stockages du lot 3. Le jeton aléatoire rend le nom du dossier imprévisible. Apache et IIS appliquent les fichiers `.htaccess` et `web.config` que l'extension y dépose. Nginx les ignore : bloquez ce dossier dans la configuration du site.

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
| WordPress Coding Standards, nouveau code (`includes/`, `tests/php/`) | oui |
| WordPress Coding Standards, ancien code | non, jusqu'à la bascule |
| Interface React : style, tests, construction | oui |
| Poids de l'archive | oui |

Le fichier [`.gitattributes`](.gitattributes) liste ce qui reste hors de l'archive distribuée : outils, documentation, CI, fichiers `.po`.

## Limites connues

- Le code hérité ne respecte pas encore les WordPress Coding Standards. La CI comptait 36 373 erreurs et 1 443 avertissements PHPCS au 3 octobre 2026.
- Les options gardent les noms de BackWPup (`backwpup_*`). Désinstaller Oueb WP Backup effacerait les réglages d'un BackWPup présent sur le même site.
- Les textes ajoutés par le fork utilisent le domaine de traduction `oueb-wp-backup`, que l'extension ne charge pas encore. Ces textes s'affichent en anglais.
- Le réglage de la clé de chiffrement et le déchiffrement au téléchargement faisaient partie de BackWPup Pro. Ils restent à réécrire.
- Le cadrage ne prévoit pas de support FTP, mais il est encore dans le code.
- Les tâches BackWPup qui envoient vers Dropbox, Amazon S3, Google Cloud Storage, Azure, Rackspace, SugarSync ou par e-mail ne fonctionnent plus dans le fork.

## Feuille de route

Le travail avance par lots, sans échéance :

1. **Sobriété** : outillage qualité, retrait du code inutile, client S3 léger. Terminé.
2. **Supports** : fournisseurs vérifiés, SFTP, kDrive, cron-job.org, écran de choix du stockage. En cours.
3. **Accessibilité** : nouvelle interface conforme RGAA 4.1 et WCAG 2.2 AA, d'après les maquettes validées.

La version 1.0 sera prête quand PHPCS passera sans erreur, quand les écrans n'auront plus de non-conformité RGAA bloquante, et quand une sauvegarde suivie d'une restauration aura réussi sur chaque support.
