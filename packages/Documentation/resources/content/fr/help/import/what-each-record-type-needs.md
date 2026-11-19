---
title: Ce qu'exige chaque type d'enregistrement
description: Colonnes, relations et exemples de CSV pour les entreprises, personnes, opportunités, tâches et notes.
order: 2
updated: "2026-08-13"
related: [help/import/prepare-your-csv, help/import/update-existing-records]
---

Chaque type d'enregistrement s'importe séparément, un fichier par type. Cette
page liste ce que chaque type accepte : sa colonne obligatoire, les relations
que vous pouvez lier pendant l'association, et un exemple de CSV fonctionnel.

## Entreprises

- `name` (obligatoire)
- `account_owner_email` : l'e-mail d'un membre, pour désigner le responsable
- Les attributs personnalisés avec le préfixe `custom_fields_`

```
name,account_owner_email,custom_fields_industry,custom_fields_domains
Acme Corporation,owner@yourcompany.com,Technology,acme.com
```

Les entreprises correspondent aux enregistrements existants par **domaine** ou
par **ID d'enregistrement**. Consultez
[Mettre à jour des enregistrements par import](/fr/help/import/update-existing-records).

## Personnes

- `name` (obligatoire)
- Les attributs personnalisés avec le préfixe `custom_fields_`
- Relation **Entreprise** : liez chaque personne à une entreprise par ID
  d'enregistrement, domaine ou nom

```
name,company,custom_fields_emails,custom_fields_title
John Doe,acme.com,john@acme.com,CEO
Jane Smith,acme.com,jane@acme.com,CTO
```

Associez la colonne `company` à la relation Entreprise et choisissez
**Domaine** sous **Faire correspondre par**, pour que `acme.com` se lie à l'entreprise
existante. Choisir **Nom** sous **Faire correspondre par** crée toujours une nouvelle
entreprise.

## Opportunités

- `name` (obligatoire)
- Les attributs personnalisés (montant, étape, date de clôture, etc.)
- Relation **Entreprise** : par ID d'enregistrement, domaine ou nom
- Relation **Interlocuteur** : par ID d'enregistrement, e-mail, téléphone ou nom

```
name,company,contact,custom_fields_amount,custom_fields_stage
Q1 Enterprise Deal,acme.com,john@acme.com,50000,Proposal
```

Associez `company` à Entreprise → Domaine et `contact` à Interlocuteur →
E-mail pour la liaison la plus fiable.

## Tâches

- `title` (obligatoire)
- Relations **Entreprises**, **Personnes**, **Opportunités** : liez chaque tâche
  à un ou plusieurs enregistrements
- Relation **Assignés** : assignez à des membres par e-mail

```
title,assignee,company,custom_fields_due_date,custom_fields_priority
Follow up with client,assignee@yourcompany.com,acme.com,2024-03-15,High
```

Associez la colonne `assignee` à Assignés → E-mail pour assigner par adresse
e-mail.

## Notes

- `title` (obligatoire)
- Relations **Entreprises**, **Personnes**, **Opportunités**

```
title,company
Meeting Notes,acme.com
```

Les notes sont toujours créées comme nouveaux enregistrements. Un import ne met
jamais à jour une note existante, même si vous incluez une colonne `id`.
