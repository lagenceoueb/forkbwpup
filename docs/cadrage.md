# Cadrage du projet Oueb WP Backup

Document de référence du projet, validé le 3 octobre 2026. Toute décision qui le contredit passe d'abord par une mise à jour de ce fichier.

## Objectif

Oueb WP Backup est une extension WordPress de sauvegarde et de restauration, éditée par L'agence Oueb. Elle permet à un administrateur de site, sans compétence technique, de sauvegarder son site, de suivre l'état de ses sauvegardes et de restaurer une version précédente sans aide extérieure.

L'extension est un fork de BackWPup 4.1.7 (Inpsyde puis WP Media), distribué sous licence GPL v2 ou ultérieure. Cette origine est mentionnée dans le readme, l'en-tête du plugin et le pied de chaque écran d'administration.

## Principes

- **Sobriété.** Archive d'installation de moins de 2 Mo. Aucune police téléchargée, aucune image décorative, des scripts chargés uniquement sur les pages de l'extension.
- **Accessibilité.** Conformité RGAA 4.1 et WCAG 2.2 niveau AA sur tous les écrans de l'extension.
- **Stockage européen.** Seuls des fournisseurs européens ou suisses, éthiques et écoresponsables, sont proposés.
- **Autonomie.** Interface pensée pour un administrateur de site client : assistant de première configuration, textes d'aide, messages d'erreur qui disent quoi faire.
- **Qualité du code.** WordPress Coding Standards appliqués en entier, sans exclusion de règle PHPCS.

## Identité

| Élément | Valeur |
|---|---|
| Nom | Oueb WP Backup |
| Slug et text domain | `oueb-wp-backup` |
| Préfixe du code | `oueb_` (fonctions, options, hooks), `Oueb_` (classes) |
| Auteur | L'agence Oueb, https://lagenceoueb.tech |
| Signature des commits | `L'agence Oueb <jm@lagenceoueb.tech>` |

Palette, reprise de la console Oueb Monitor :

| Rôle | Couleur | Contraste sur blanc | Usage |
|---|---|---|---|
| Gris du logo | `#3f3e3e` | 10,7:1 | Texte principal |
| Sarcelle foncé | `#1b6e72` | 6,0:1 | Liens, boutons, texte en couleur |
| Sarcelle | `#26989d` | 3,5:1 | Bordures, icônes, survol, jamais du texte |
| Ambre | `#fdbd2b` | 1,7:1 | Fonds d'accent, texte `#3f3e3e` par-dessus |

## Supports de stockage

### Critères éliminatoires

Un fournisseur entre dans la liste s'il remplit les trois conditions :

1. siège social et centres de données dans l'Union européenne ou en Suisse ;
2. aucune maison mère hors d'Europe (exposition au Cloud Act américain) ;
3. électricité d'origine renouvelable, prouvée par une source publique.

### Critères affichés

Chaque fiche fournisseur indique, avec sa source :

- le PUE (Power Usage Effectiveness) publié ;
- la réutilisation de la chaleur ou le refroidissement sans climatisation ;
- les certifications et labels environnementaux (ISO 14001, Code de conduite européen sur les centres de données) ;
- les qualifications de sécurité (SecNumCloud, ISO 27001).

### Candidats à vérifier

Tous proposent un stockage compatible S3 : Scaleway, OVHcloud, Clever Cloud (Cellar), Outscale (France) ; Hetzner, IONOS (Allemagne) ; Infomaniak, Exoscale (Suisse). Un candidat qui ne prouve pas son électricité renouvelable sort de la liste.

### Autres supports

- Serveur SFTP administré par le client.
- Dossier sur le serveur du site, présenté comme une copie complémentaire seulement.

### Supports retirés

AWS S3, Google Cloud Storage, Microsoft Azure, Dropbox, DigitalOcean, DreamHost, Rackspace, SugarSync, envoi par e-mail.

## Périmètre fonctionnel de la version 1.0

Conservé :

- sauvegarde de la base de données et des fichiers ;
- restauration depuis l'administration ;
- multisite, géré depuis l'administration du réseau ;
- commandes WP-CLI ;
- maintenance de la base : vérification, réparation, optimisation ;
- chiffrement des archives ;
- déclenchement externe : URL de déclenchement et intégration de cron-job.org (gratuit, code ouvert, hébergé en Allemagne).

Retiré : envoi par e-mail, export XML de WordPress, liste des extensions installées, EasyCron, code de la version Pro.

Plus tard : service de cron propre à l'agence, branché sur l'URL de déclenchement.

Le réglage de la clé de chiffrement et le déchiffrement au téléchargement faisaient partie de BackWPup Pro, absent du fork. Ils sont à réécrire pour tenir l'engagement sur le chiffrement.

## Migration depuis BackWPup

Quand l'extension détecte une installation de BackWPup, une notice propose « Importer mes tâches et réglages » ou « Ignorer ». L'import est facultatif et ne modifie ni ne supprime les données de BackWPup. Les deux extensions ne peuvent pas être actives en même temps.

## Distribution et mises à jour

- Prérequis : WordPress 6.4 et PHP 8.1, déclarés dans l'en-tête du plugin. WordPress refuse l'activation sur un serveur qui ne les remplit pas.
- Utilisateurs : les clients de l'agence, dans leur administration.
- Distribution : releases GitHub, puis wordpress.org.
- Mises à jour : en-tête `Update URI` et filtre `update_plugins_github.com`. Le plugin lit la dernière release, télécharge le zip construit par la CI (dossier `oueb-wp-backup/`), garde la réponse en cache 12 heures. La classe vit dans son propre fichier pour être retirée de la version wordpress.org.
- Contraintes wordpress.org : pas de « BackWPup » dans le nom, pas de module de mise à jour maison, aucun appel à un service externe sans accord explicite de l'administrateur.

## Interface

Maquettes validées : https://claude.ai/artifact/2vzez61heo6EDwMKyERgSU

- Tableau de bord : état du site en une phrase, bouton « Sauvegarder maintenant », trois cartes quoi, où, quand, sauvegardes récentes.
- Assistant de première configuration en quatre étapes : contenu, stockage, fréquence, vérification.
- Planification : fréquences proposées avec leur cas d'usage, nombre de copies gardées avec l'espace estimé, choix du déclencheur.
- Restauration : récapitulatif, ce qui sera perdu, sauvegarde préalable de l'état actuel cochée par défaut, confirmation explicite.
- Encart de l'agence sur le tableau de bord de l'extension : présentation, coordonnées, lien vers le site, bouton pour le masquer.

## Critères de fin de la version 1.0

- PHPCS avec le jeu `WordPress` passe sans erreur ni règle exclue.
- Aucune non-conformité RGAA bloquante sur les écrans de l'extension.
- Archive d'installation de moins de 2 Mo.
- Sauvegarde complète puis restauration réussies sur chaque support retenu.

## Lots

Pas d'échéance. Ordre de travail :

1. **Sobriété.** Outillage qualité et CI, retrait du code mort et des supports écartés, nettoyage des dépendances et des assets, client S3 léger à la place du SDK AWS.
2. **Supports.** Vérification des fournisseurs, fiches et sources, SFTP, écran de choix, cron-job.org.
3. **RGAA.** Nouvelle interface d'après les maquettes, audit, corrections, grille de conformité.

Le rebranding (nom, text domain, préfixes, migration des réglages) accompagne les lots 1 et 3. Il doit renommer les options : le fork utilise encore les noms de BackWPup (`backwpup_*`), et sa désinstallation effacerait les réglages d'un BackWPup installé sur le même site.

## Points ouverts

- Archive complète de BackWPup 4.1.7 : le dépôt n'en contient qu'une partie (dépendances `vendor/` et fichiers de `src/` manquants).
- Coordonnées de l'agence et phrase de présentation pour l'encart.
- Logo en SVG pour l'icône du menu d'administration.
- Renommage du dépôt GitHub en `oueb-wp-backup`, avant la première release.
