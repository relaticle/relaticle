---
title: Corriger les erreurs d'import
description: Que faire quand une étape de téléversement, d'association ou de validation échoue, et comment les dates ambiguës sont traitées.
order: 4
updated: "2026-08-13"
related: [help/import/prepare-your-csv, help/import/update-existing-records]
---

La plupart des problèmes d'import sont de quatre types : le fichier ne se
téléverse pas, une colonne ne s'associe pas, des valeurs échouent à la
validation, ou l'import lui-même se comporte mal. Repérez le symptôme
ci-dessous ; chaque solution est à votre portée.

## Problèmes de téléversement

| Problème | Solution |
|---------|----------|
| Fichier trop volumineux | Découpez-le en fichiers de moins de 10 000 lignes chacun |
| Format non valide | Réenregistrez-le en « CSV UTF-8 » depuis Excel |
| Le téléversement échoue | Vérifiez votre connexion, puis essayez un fichier plus petit |

## Problèmes d'association

| Problème | Solution |
|---------|----------|
| Une colonne ne s'est pas associée automatiquement | Choisissez vous-même le champ dans la liste déroulante **Associer à** |
| Un attribut personnalisé manque dans la liste déroulante | Vérifiez le code de l'attribut dans **Paramètres → Attributs personnalisés** |
| Un champ obligatoire s'affiche en rouge | Associez-lui l'une de vos colonnes CSV |

## Problèmes de validation

| Problème | Solution |
|---------|----------|
| E-mail non valide | Corrigez le format : `user@domain.com` |
| Date non valide | Utilisez le format ISO, européen ou américain de façon cohérente |
| ID d'enregistrement non valide | Réexportez pour obtenir des ID à jour |
| Option de liste déroulante inconnue | Utilisez le libellé exact de l'option dans les réglages de l'attribut |

À l'étape de vérification, vous pouvez aussi corriger une valeur une seule fois (**Corriger cette valeur**)
pour la corriger dans toutes les lignes qui la contiennent, ou l'ignorer (**Ignorer cette valeur**). Les lignes sont quand même importées, avec ce seul champ laissé vide.

## Dates ambiguës

L'assistant lit trois formats de date et détecte celui qu'utilise une colonne :

| Format | Modèle | Exemple |
|--------|---------|---------|
| ISO | YYYY-MM-DD | `2024-05-15` |
| Européen | DD/MM/YYYY | `15/05/2024` |
| Américain | MM/DD/YYYY | `05/15/2024` |

Quand la valeur du jour dépasse 12, le format est sans ambiguïté : `31/01/2024`
ne peut être qu'européen. Quand les deux positions valent 12 ou moins
(`01/02/2024` : le 2 janvier ou le 1er février ?), l'assistant affiche un
avertissement et une liste déroulante de format sur cette colonne : choisissez
**Européen** ou **Américain** pour indiquer la bonne lecture. Les dates ISO ne
sont jamais ambiguës, ce qui en fait le format le plus sûr à exporter depuis
d'autres outils.

## Problèmes d'import

| Problème | Solution |
|---------|----------|
| Bloqué sur « Traitement en cours… » | Les gros imports s'exécutent en arrière-plan ; revenez dans quelques minutes |
| Certaines lignes ont échoué | Téléchargez les lignes en échec, corrigez-les, réimportez uniquement celles-ci |
| Doublons inattendus | Associez une colonne unique (e-mail, domaine ou ID) ; voir [Mettre à jour des enregistrements existants](/fr/help/import/update-existing-records) |

## Bon à savoir

- **Il n'y a pas d'annulation.** Testez avec un échantillon de 5 à 10 lignes
  avant le fichier complet.
- **Un import en échec conserve ses lignes réussies.** Corrigez et réimportez
  uniquement les lignes en échec téléchargées.
- **Un seul type d'enregistrement par fichier.** Importer des personnes peut
  quand même créer automatiquement leurs entreprises si vous faites correspondre
  la relation Entreprise par Nom.
- **Chaque exécution est journalisée.** La page **Historique des imports** sous
  le nom de votre espace de travail affiche le nombre de lignes créées, mises à
  jour, ignorées et en échec pour chaque import.
