# Grille RGAA 4.1

Audit des écrans d'Oueb WP Backup, version 0.1.0, le 5 octobre 2026. Le cadrage fixe l'objectif : aucune non-conformité bloquante sur les écrans de l'extension.

## Périmètre

L'interface tient dans une page d'administration, `admin.php?page=oueb-wp-backup`, et dans ses écrans : tableau de bord, sauvegardes, stockage (liste et formulaire), planification, restauration (choix, envoi d'une archive, confirmation, progression), journal, réglages, contenu, sauvegardes supplémentaires, assistant de configuration. En multisite, la même page vit dans l'administration du réseau.

Le reste de l'administration (menu de WordPress, barre d'outils, pied de page) relève de WordPress et sort du périmètre.

## Méthode

| Contrôle | Outil | Résultat |
|---|---|---|
| Règles automatiques WCAG 2.2 A et AA, et bonnes pratiques | axe-core 4.13, sur 13 états d'écran, à 1 280 px et à 320 px | 0 violation |
| Parcours au clavier, focus visible | Tabulation sur chaque écran, style du focus relevé à chaque arrêt | 230 arrêts, tous visibles |
| Titre de page | Titre lu sur chaque écran | Un titre par écran |
| Reflow | Largeur de 320 px, défilement horizontal mesuré | Aucun, hors tableaux de données |
| Espacement du texte | Feuille WCAG 1.4.12 injectée, texte coupé recherché | Rien de coupé |
| Lecteur d'écran | Non fait : NVDA et VoiceOver restent à passer | À faire |

Les scripts d'audit tournent sous Playwright contre un WordPress 7.1 en français.

## Corrections faites pendant l'audit

- L'application portait ses propres régions `header`, `main` et `footer`, en double de celles de l'administration. Elles sont devenues des `div`.
- À 320 px, les textes masqués des colonnes d'action sortaient de la zone de défilement des tableaux : la zone est désormais positionnée.
- Un bouton au libellé long et le champ de fichier débordaient à 320 px.
- Chaque écran donne son nom au titre de la page, et le focus va sur le titre de l'écran après une navigation interne.
- La section « Restaurer » du menu s'appelle « Restauration », pour ne pas se confondre avec le bouton.
- Le tableau des tables en défaut, sans élément focalisable, est une zone atteignable au clavier.

## Grille

C : conforme. NC : non conforme. NA : non applicable. NT : non testé.

### 1. Images

| Critère | Statut | Note |
|---|---|---|
| 1.1 à 1.3 Alternative des images porteuses d'information | NA | Aucune image porteuse d'information. |
| 1.2 Images de décoration ignorées | C | Le bouclier du tableau de bord porte `aria-hidden="true"` et `focusable="false"`. |
| 1.4 à 1.9 Captcha, images texte, légendes | NA | Aucun. |

### 2. Cadres

| Critère | Statut | Note |
|---|---|---|
| 2.1, 2.2 Titre des cadres | NA | La fenêtre des détails de mise à jour appartient à WordPress. |

### 3. Couleurs

| Critère | Statut | Note |
|---|---|---|
| 3.1 Information pas donnée par la couleur seule | C | Les états des sauvegardes portent un libellé : Réussie, Échec, Arrêtée. |
| 3.2 Contraste du texte | C | Vérifié par axe sur chaque écran. |
| 3.3 Contraste des composants d'interface | C | Bordures et focus des composants de WordPress, couleur d'accent `#1b6e72` sur blanc. |

### 4. Multimédia

| Critère | Statut | Note |
|---|---|---|
| 4.1 à 4.13 | NA | Aucun média temporel. |

### 5. Tableaux

| Critère | Statut | Note |
|---|---|---|
| 5.1 Résumé des tableaux complexes | NA | Tableaux simples. |
| 5.4 Titre des tableaux de données | C | Chaque tableau a une `caption`, visible ou réservée aux lecteurs d'écran. |
| 5.6, 5.7 En-têtes de lignes et de colonnes | C | `th scope="col"`, et `th scope="row"` pour le nom de la table en maintenance. |
| 5.8 Tableaux de mise en forme | NA | Aucun. |

### 6. Liens

| Critère | Statut | Note |
|---|---|---|
| 6.1 Lien explicite | C | Les liens répétés d'une ligne à l'autre (« Télécharger », « Journal ») portent un nom complet avec la date de la sauvegarde. |
| 6.2 Lien avec un intitulé | C | Vérifié par axe. |

### 7. Scripts

| Critère | Statut | Note |
|---|---|---|
| 7.1 Composants compatibles avec les technologies d'assistance | C | Composants de WordPress (`@wordpress/components`) : boutons, cases, boutons radio, fenêtres modales. |
| 7.2 Alternative aux scripts | C | Sans JavaScript, la page dit que l'interface en a besoin. |
| 7.3 Contrôlable au clavier | C | Parcours complet sur chaque écran. |
| 7.4 Changement de contexte annoncé | C | Une navigation interne déplace le focus sur le titre de l'écran. |
| 7.5 Messages de statut | C | Résultats et progression annoncés par `speak()` et les notices de WordPress. |

### 8. Éléments obligatoires

| Critère | Statut | Note |
|---|---|---|
| 8.1 à 8.4 Doctype, code valide, langue | C | Gérés par WordPress (`lang="fr-FR"`). |
| 8.5, 8.6 Titre de page pertinent | C | « Restauration ‹ Sauvegardes ‹ Mon site ». |
| 8.7, 8.8 Changements de langue | NA | Les termes anglais restants (bucket, cron) sont des termes techniques admis. |
| 8.9 Balises pour la présentation | C | Vérifié à la relecture du code. |
| 8.10 Sens de lecture | NA | Pas de texte de droite à gauche dans les traductions livrées. |

### 9. Structuration

| Critère | Statut | Note |
|---|---|---|
| 9.1 Titres | C | Un `h1` par écran, puis un `h2` par carte, `h3` à l'intérieur. Ordre vérifié par axe. |
| 9.2 Structure du document | C | Régions de l'administration de WordPress ; le menu des sections est un `nav` nommé. |
| 9.3 Listes | C | Étapes de l'assistant, actions de maintenance, rapport d'import en `ul` ou `ol`. |
| 9.4 Citations | NA | Aucune. |

### 10. Présentation

| Critère | Statut | Note |
|---|---|---|
| 10.1 Présentation par les styles | C | |
| 10.2 Contenu visible sans styles | C | |
| 10.3 Ordre de lecture sans styles | C | L'ordre du code suit l'ordre visuel. |
| 10.4 Agrandissement à 200 % | C | Couvert par le reflow à 320 px. |
| 10.5 Couleurs de texte et de fond déclarées ensemble | C | |
| 10.6 Liens visibles | C | Liens soulignés. |
| 10.7 Focus visible | C | Contrôlé à chaque arrêt de tabulation. |
| 10.8 Contenus cachés ignorés | C | |
| 10.9, 10.10 Information pas donnée par la forme ou la position seule | C | |
| 10.11 Reflow à 320 px | C | Seuls les tableaux de données défilent, dans leur zone, ce que permet la WCAG 1.4.10. |
| 10.12 Espacement du texte | C | Aucun texte coupé avec la feuille de test. |
| 10.13 Contenus additionnels au survol ou au focus | NA | Pas d'infobulle. |
| 10.14 Contenus additionnels visibles au clavier | NA | |

### 11. Formulaires

| Critère | Statut | Note |
|---|---|---|
| 11.1, 11.2 Étiquettes | C | Chaque champ a un `label` lié. |
| 11.3 Étiquettes cohérentes | C | Mêmes libellés d'un écran à l'autre. |
| 11.4 Étiquette accolée au champ | C | |
| 11.5, 11.6 Regroupements | C | Contenu de la sauvegarde, stockages d'une tâche, fréquence et groupes de réglages en `fieldset` avec `legend`. |
| 11.7 Légendes pertinentes | C | |
| 11.8 Listes de choix | C | Listes `select` natives, chacune avec son étiquette. |
| 11.9 Intitulés des boutons | C | Verbe et objet : « Enregistrer le contenu », « Supprimer le stockage ». |
| 11.10 Contrôle de saisie | C | Champ obligatoire signalé, message d'erreur lié par `aria-describedby`, champ marqué `aria-invalid`. |
| 11.11 Suggestions de correction | C | Les messages disent quoi saisir : « Saisissez un port entre 1 et 65535. » |
| 11.12 Données à conséquence | C | Restauration : récapitulatif, case de consentement, sauvegarde préalable proposée. Suppressions confirmées. |
| 11.13 Finalité des champs | NA | Les identifiants demandés appartiennent aux services de stockage, pas à la personne. Les mots de passe portent `autocomplete="new-password"`. |

### 12. Navigation

| Critère | Statut | Note |
|---|---|---|
| 12.1 Deux systèmes de navigation | C | Menu de l'administration et menu des sections. |
| 12.2 Menus cohérents | C | Même menu des sections sur chaque écran. |
| 12.3 à 12.5 Plan du site, moteur de recherche | NA | Sans objet pour une page d'administration. |
| 12.6 Régions identifiables | C | Régions de WordPress, menu des sections nommé. |
| 12.7 Lien d'évitement | C | Fourni par WordPress (« Aller au contenu principal »). |
| 12.8 Ordre de tabulation | C | |
| 12.9 Pas de piège au clavier | C | La fenêtre de confirmation se ferme avec Échap et rend le focus. |
| 12.10 Raccourcis clavier | NA | Aucun raccourci d'une seule touche. |
| 12.11 Contenus additionnels atteignables | NA | |

### 13. Consultation

| Critère | Statut | Note |
|---|---|---|
| 13.1 Limite de temps | C | Le suivi d'une sauvegarde rafraîchit la progression sans recharger la page ni déplacer le focus. |
| 13.2 Nouvelle fenêtre | C | Les sources des fournisseurs ouvrent un onglet et le disent : « (nouvel onglet) ». |
| 13.3 à 13.6 Documents en téléchargement | NA | Archives et clés ne sont pas des documents bureautiques. |
| 13.7 Changements brusques de luminosité | NA | |
| 13.8 Contenus en mouvement | C | Barre de progression sans animation clignotante. |
| 13.9 Orientation | C | Aucune orientation imposée. |
| 13.10 Gestes complexes | NA | |
| 13.11 Actions déclenchées au pointeur | C | Les actions partent au relâchement, composants de WordPress. |
| 13.12 Mouvement de l'appareil | NA | |

## Reste à faire

- Passer chaque écran avec NVDA sous Firefox et VoiceOver sous Safari, en particulier la progression d'une restauration.
- Refaire l'audit à chaque version qui change l'interface : les scripts sont décrits dans la section Méthode.
