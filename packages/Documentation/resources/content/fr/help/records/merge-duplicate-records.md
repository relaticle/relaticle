---
title: Fusionner des enregistrements en double
description: Combinez deux entreprises ou personnes en double en un enregistrement. Celui de droite l'emporte. La fusion est irréversible.
order: 7
updated: "2026-11-20"
related: [help/records/company-records, help/records/people-records, help/records/restore-deleted-records]
---

Les doublons apparaissent le plus souvent après un import ou une synchronisation de
boîte mail. En fusionnant deux entreprises, ou deux personnes, vous obtenez un seul
enregistrement qui garde tout ce qui était lié à l'un ou à l'autre. Les membres et les
administrateurs peuvent fusionner. Les lecteurs ne le peuvent pas.

## Lancer une fusion

- **Depuis l'alerte de doublons.** La page d'un enregistrement qui a des doublons
  probables affiche une section avec leur nombre. Dépliez-la et cliquez sur
  **Fusionner** à côté de celui que vous voulez. La boîte de dialogue s'ouvre avec cet
  enregistrement déjà choisi.
- **Depuis le menu.** Ouvrez l'enregistrement, cliquez sur le menu **⋯** et choisissez
  **Fusionner la fiche**.

## Dans la boîte de dialogue

1. Recherchez l'autre enregistrement par nom, domaine ou e-mail, puis choisissez-le.
2. Vérifiez les deux cartes. **Inverser les fiches** change celui qui l'emporte.
3. Cliquez sur **Fusionner les fiches**.

L'enregistrement de droite l'emporte partout où les deux ont une valeur. Les vides sont
comblés par l'autre. Les domaines, les e-mails et les valeurs de sélection multiple sont
combinés, ceux du gagnant en premier.

## Ce que vous obtenez

La fusion crée un nouvel enregistrement. Les deux originaux sont retirés, et tout ce qui
était lié à l'un ou à l'autre passe au nouveau : notes, tâches, e-mails, réunions,
personnes et opportunités, ainsi que l'historique d'activité. L'historique montre une
seule création et une entrée indiquant qui a fusionné les enregistrements.

Une liste qui dépasse sa limite garde toutes ses valeurs. La fusion relève la limite de
l'espace de travail pour cet attribut personnalisé jusqu'au nombre combiné, dans la
limite de 20. Chaque enregistrement de ce type peut ensuite contenir autant de valeurs.

## Anciens liens

Le lien d'un enregistrement fusionné affiche **Cette fiche a été fusionnée** et un
bouton **Aller à la fiche** qui mène au nouvel enregistrement.

## Fusionner depuis l'API, MCP et Rela

Vous pouvez fusionner sans la boîte de dialogue. L'API REST propose un point d'accès de
fusion pour les entreprises et un pour les personnes, le serveur MCP offre un outil pour
chacune, et Rela, l'assistant IA intégré, propose des fusions que vous approuvez. La
même règle que dans la boîte de dialogue s'applique.

## Bon à savoir

- Une fusion est irréversible. Un enregistrement fusionné n'est pas dans la corbeille et
  ne peut pas être [restauré](/fr/help/records/restore-deleted-records).
- Une fusion porte sur deux enregistrements. Trois doublons demandent deux fusions.
- Seules les entreprises et les personnes peuvent être fusionnées.
