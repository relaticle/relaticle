---
title: Mettre à jour des enregistrements par import
description: Faites correspondre les lignes par ID, e-mail, téléphone ou domaine pour mettre à jour les enregistrements au lieu de créer des doublons.
order: 3
updated: "2026-08-13"
related: [help/import/what-each-record-type-needs, help/import/fix-import-errors]
---

Quand une colonne du CSV identifie un enregistrement de façon unique (un ID, un
e-mail, un domaine), l'assistant d'import fait correspondre les lignes à vos
données existantes et met à jour ces enregistrements au lieu de les dupliquer.
Vous choisissez la méthode de correspondance pendant l'association des
colonnes, et l'aperçu montre exactement ce qui sera créé et ce qui sera mis à
jour avant que quoi que ce soit change.

## Méthodes de correspondance

| Méthode | Comportement | Disponible pour |
|--------|----------|---------------|
| **ID de l'enregistrement** | Met à jour cet enregistrement précis ; ignore la ligne si l'ID est introuvable | Tous les types d'enregistrements |
| **Domaine** | Trouve l'entreprise existante par son domaine, ou en crée une nouvelle | Entreprises |
| **E-mail** | Trouve la personne existante par son e-mail, ou en crée une nouvelle | Personnes |
| **Téléphone** | Trouve la personne existante par son téléphone, ou en crée une nouvelle | Personnes |
| **Nom** | Crée toujours un nouvel enregistrement ; les noms ne sont pas uniques | Entreprises, Personnes |

L'étape d'aperçu étiquette ensuite chaque ligne :

- Correspond à un enregistrement existant → **Mettre à jour**
- Aucune correspondance, ou correspondance par Nom → **Créer un nouveau**
- Un ID d'enregistrement qui n'existe pas → **Ignorer**

Si vous n'associez aucune colonne de correspondance, l'assistant vous avertit
que chaque ligne sera créée comme nouvelle. Vous pouvez revenir en arrière pour
en associer une, ou continuer quand même.

## Comment se comportent les mises à jour

- **Les cellules vides sont ignorées.** Une cellule CSV vide préserve la valeur
  existante. N'incluez que les colonnes que vous voulez modifier.
- **Les champs à valeurs multiples fusionnent.** Les e-mails, numéros de
  téléphone, tags et valeurs de sélection multiple sont ajoutés à ce qui figure
  déjà sur l'enregistrement, sans le remplacer.
- **Les doublons au sein du fichier fusionnent.** Si deux lignes aboutissent au
  même enregistrement (par exemple le même e-mail deux fois), la seconde ligne
  met à jour le résultat de la première au lieu de créer un doublon.

## Mettre à jour par ID d'enregistrement

Pour des mises à jour précises, partez d'un export :

1. **Exportez** les enregistrements que vous voulez modifier. Le fichier inclut
   une colonne `id`.
2. **Modifiez** le CSV en gardant la colonne `id` intacte.
3. **Réimportez**. Les lignes avec un ID valide mettent à jour ces
   enregistrements précis.

```
id,name,custom_fields_industry
01KCCFMZ52QWZSQZWVG0AP704V,Acme Corporation,Software
01KCCFN1A8XVQR4ZFWB3KC5M7P,TechStart Inc,Hardware
```

## Créer et mettre à jour dans un même fichier

Les lignes avec un ID mettent à jour ; les lignes avec un ID vide créent :

```
id,name
01KCCFMZ52QWZSQZWVG0AP704V,Update This Company
,New Company (blank ID = create new)
```
