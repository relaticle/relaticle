---
title: Importer vos données existantes
description: Les étapes de l'import CSV dans Relaticle pour associer les colonnes et vérifier les enregistrements, avec 10 000 lignes et 10 Mo au maximum.
order: 4
updated: "2026-08-12"
related: [help/import/prepare-your-csv, help/import/update-existing-records, help/getting-started/use-custom-fields]
---

L'import fait entrer dans Relaticle des entreprises, des personnes ou des
opportunités existantes depuis un tableur, au lieu de les saisir une par une.
Téléversez un CSV, associez ses colonnes aux champs de Relaticle, vérifiez les
valeurs, puis validez. Relaticle vous indique exactement combien
d'enregistrements seront créés ou mis à jour avant que quoi que ce soit change.

1. Cliquez sur **Entreprises**, **Personnes** ou **Opportunités** dans la barre
   latérale.
2. Cliquez sur **Importer / Exporter**, puis sur **Importer des entreprises** (le libellé
   correspond à la liste où vous êtes).
3. Cliquez sur **Choisir un fichier CSV**, ou déposez-le sur la zone de
   téléversement, dans la limite de 10 000 lignes et 10 Mo.
4. Cliquez sur **Continuer**.

## Associer vos colonnes

Relaticle associe automatiquement les colonnes de votre fichier aux champs de
Relaticle quand les noms sont assez proches.

![L'étape « Associer », avec les colonnes du fichier Name, Domain et LinkedIn associées automatiquement aux champs Nom, Domaines et LinkedIn](/help-assets/getting-started/import-your-existing-data-1.png)

5. Vérifiez chaque champ **Associer à** et corrigez ceux qui ont été mal associés.
6. Cliquez sur **Continuer**.

## Vérifier et corriger les valeurs

Relaticle valide chaque valeur unique de chaque colonne et les regroupe sous
**Tous**, **À vérifier**, **Modifiés** et **Ignorés**. Modifiez une valeur
directement pour la corriger, ou ignorez une ligne que vous ne voulez pas
importer.

7. Cliquez sur **Continuer** quand les valeurs associées vous conviennent.
8. Vérifiez le nombre d'enregistrements qui seront créés et mis à jour, cliquez
   sur **Démarrer l'import**, puis confirmez.

![Le résumé « Import terminé » indiquant 2 créés, 0 mis à jour et 0 en échec](/help-assets/getting-started/import-your-existing-data-2.png)

Chaque import passé figure sur la page **Historique des imports**, sous le nom
de votre espace de travail, avec le nombre de lignes créées, mises à jour,
ignorées ou en échec.

## Si une colonne ne s'associe pas automatiquement, associez-la à la main

Ouvrez la liste déroulante sous **Associer à** pour cette colonne et choisissez
vous-même le bon champ, ou cliquez sur **Supprimer l'association** pour laisser
complètement cette colonne hors de l'import.

Pour les règles de mise en forme des fichiers, la correspondance et la mise à
jour des enregistrements existants, et les corrections des valeurs signalées
par l'assistant d'import, consultez les articles
[Importer des données](/fr/help/import).
