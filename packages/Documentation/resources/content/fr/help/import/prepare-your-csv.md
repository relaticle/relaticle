---
title: Préparer votre fichier CSV
description: Les exigences de fichier, le nommage des colonnes et les règles de format qui font réussir un import du premier coup.
order: 1
updated: "2026-08-13"
related: [help/import/what-each-record-type-needs, help/import/fix-import-errors, help/getting-started/import-your-existing-data]
---

Un CSV s'importe sans problème quand il est encodé en UTF-8, a des en-têtes
dans la première ligne et met en forme chaque colonne de façon cohérente. Cette
page couvre les règles ; le
[pas-à-pas](/fr/help/getting-started/import-your-existing-data) couvre les
clics.

## Exigences du fichier

| Exigence | Valeur |
|-------------|-------|
| Format | CSV (valeurs séparées par des virgules) |
| Encodage | UTF-8 |
| Nombre maximal de lignes | 10 000 par fichier |
| Taille maximale | 10 Mo |
| En-têtes | Obligatoires dans la première ligne |

Dans Excel, utilisez **Enregistrer sous → CSV UTF-8 (délimité par des
virgules)**. L'encodage CSV par défaut d'Excel abîme les caractères accentués.

## Colonnes obligatoires

Une seule colonne est obligatoire par type d'enregistrement :

| Type d'enregistrement | Colonne obligatoire |
|-------------|-----------------|
| Entreprises | Name |
| Personnes | Name |
| Opportunités | Name |
| Tâches | Title |
| Notes | Title |

Toutes les autres colonnes sont facultatives ; une cellule vide laisse
simplement le champ vide.

## Colonnes d'attributs personnalisés

Nommez les colonnes d'attributs personnalisés avec le préfixe `custom_fields_`
suivi du code de l'attribut :

```
custom_fields_industry
custom_fields_emails
custom_fields_website
```

Trouvez le code de chaque attribut dans **Paramètres → Attributs
personnalisés**, sous la colonne **Code**. L'assistant associe aussi
automatiquement les noms proches : une colonne nommée « Industry » arrive
généralement sur l'attribut Industry sans le préfixe. Le préfixe rend la
correspondance exacte.

## Mettre en forme les valeurs selon le type d'attribut

| Type d'attribut | Format | Exemple |
|------|--------|---------|
| Texte | Texte brut | `Technology` |
| Nombre | Chiffres uniquement, sans symboles | `50000` |
| Date | YYYY-MM-DD, DD/MM/YYYY ou MM/DD/YYYY | `2024-03-15` |
| E-mail | Une ou plusieurs adresses valides, séparées par des virgules | `john@acme.com,jane@acme.com` |
| Liste déroulante | Le libellé exact de l'option | `Enterprise` |
| Sélection multiple | Libellés d'options séparés par des virgules | `CRM,Analytics` |
| Booléen | true/false, yes/no ou 1/0 | `true` |

## À faire

- Incluez des en-têtes dans la première ligne.
- Mettez entre guillemets les valeurs qui contiennent des virgules :
  `"Last, First"`.
- Gardez un format cohérent dans chaque colonne, surtout pour les dates.
- Testez avec un petit échantillon (5 à 10 lignes) avant d'importer le fichier
  complet.

## À éviter

- Utiliser l'encodage par défaut d'Excel ; choisissez « CSV UTF-8 ».
- Mélanger les formats de date dans une même colonne (`2024-03-15` et
  `03/15/2024`).
- Inclure des symboles monétaires dans les nombres ; écrivez `50000`, pas
  `$50,000`.
- Laisser des lignes vides à la fin du fichier.

## Obtenir un modèle prêt à l'emploi

Exportez quelques enregistrements existants depuis n'importe quelle liste. Le
fichier exporté inclut la colonne `id` et chaque attribut personnalisé utilisé
par votre espace de travail, déjà nommés correctement. Supprimez les lignes,
gardez les en-têtes et saisissez vos données.
