---
title: Ajouter, modifier et archiver des attributs
description: Créez des attributs sur la page Attributs personnalisés, réordonnez-les, retirez-les sans risque et comprenez les attributs système.
order: 2
updated: "2026-11-10"
related: [help/custom-fields/field-types-you-can-add, help/custom-fields/control-where-a-field-appears, help/getting-started/use-custom-fields]
---

Les attributs se gèrent sur la page **Attributs personnalisés** : cliquez sur le
nom de votre espace de travail en haut de la barre latérale et choisissez
**Attributs personnalisés**. Seuls le propriétaire de l'espace de travail et les
administrateurs voient cette page. Tous les autres utilisent les attributs, et
les propriétaires et administrateurs les façonnent.

La page affiche un onglet par type d'enregistrement (Entreprises, Personnes,
Opportunités, Tâches, Notes) avec un badge de compteur, et les attributs du type
sélectionné dans un tableau que vous pouvez filtrer avec **Rechercher des
attributs...**.

![La page Attributs personnalisés sur l'onglet Opportunités, montrant Amount, Close Date et Stage avec des badges Système, plus un attribut personnalisé Deal Source](/help-assets/custom-fields/add-and-manage-fields-1.png)

## Ajouter un attribut

1. Choisissez l'onglet du type d'enregistrement.
2. Cliquez sur **Ajouter un attribut**.
3. Choisissez le **Type**. Il est définitif, alors consultez d'abord
   [Types d'attributs disponibles](/fr/help/custom-fields/field-types-you-can-add).
4. Donnez-lui un nom (les noms doivent être uniques par type d'enregistrement).
5. Pour les types à choix, ajoutez les **Options**.
6. Réglez les interrupteurs de **Paramètres** (où l'attribut apparaît, s'il est
   chiffré) et enregistrez.

L'attribut apparaît aussitôt dans le formulaire de l'enregistrement, sur la page
de l'enregistrement et (si vous l'avez activé) dans la vue en liste, pour tout
le monde dans l'espace de travail. Rela et l'import CSV peuvent aussi l'utiliser
immédiatement, sans configuration supplémentaire.

## Modifier et réordonner

Cliquez sur la ligne d'un attribut pour le renommer, changer ses options ou
ajuster ses paramètres, tout sauf le type. Faites glisser la poignée au début de
chaque ligne pour réordonner les attributs. Les formulaires et les pages
d'enregistrement suivent l'ordre que vous définissez.

## Archiver plutôt que supprimer

**Désactiver** déplace un attribut dans le groupe repliable **Archivé** en bas du
tableau. Il disparaît des formulaires et des listes, mais toutes les valeurs
enregistrées sont conservées. **Activer** rétablit aussitôt l'attribut et ses
données. C'est la façon sûre de retirer un attribut dont vous pourriez avoir de
nouveau besoin, et de masquer un attribut à Rela.

**Supprimer** n'est disponible que sur un attribut déjà archivé, et il est
destructeur : l'attribut, ses options et **toutes les valeurs enregistrées sur
vos enregistrements** sont supprimés définitivement. Relaticle vous demande
d'abord de confirmer.

## Attributs système

Les attributs portant un badge **Système** sont ceux sur lesquels Relaticle
lui-même s'appuie : les e-mails d'une personne, les domaines d'une entreprise,
le montant, la date de clôture et l'étape d'une opportunité, le statut et la
priorité d'une tâche, le corps d'une note. Vous ne pouvez ni les renommer, ni
changer leur type, ni les archiver, ni les supprimer, mais vous pouvez toujours
modifier leurs paramètres et, pour les listes déroulantes parmi eux, leurs
options :
[renommez vos étapes de pipeline](/fr/help/custom-fields/edit-the-options-in-a-select-field)
quand vous le voulez.
