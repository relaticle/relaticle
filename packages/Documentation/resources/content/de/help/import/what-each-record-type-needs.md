---
title: Was jeder Eintragstyp braucht
description: Spalten, Beziehungen und Beispiel-CSVs für Unternehmen, Personen, Verkaufschancen, Aufgaben und Notizen.
order: 2
updated: "2026-08-13"
related: [help/import/prepare-your-csv, help/import/update-existing-records]
---

Jeder Eintragstyp wird einzeln importiert, eine Datei pro Typ. Diese Seite
nennt, was jeder Typ akzeptiert: seine erforderliche Spalte, die Beziehungen, die
Sie beim Zuordnen verknüpfen können, und eine funktionierende Beispiel-CSV.

## Unternehmen

- `name` (erforderlich)
- `account_owner_email`: die E-Mail-Adresse eines Mitglieds, um die
  Verantwortung zuzuweisen
- Benutzerdefinierte Attribute mit dem Präfix `custom_fields_`

```
name,account_owner_email,custom_fields_industry,custom_fields_domains
Acme Corporation,owner@yourcompany.com,Technology,acme.com
```

Unternehmen werden über **Domain** oder **Eintrags-ID** mit bestehenden Einträgen
abgeglichen. Siehe
[Bestehende Einträge per Import aktualisieren](/de/help/import/update-existing-records).

## Personen

- `name` (erforderlich)
- Benutzerdefinierte Attribute mit dem Präfix `custom_fields_`
- Beziehung **Unternehmen**: Jede Person über Eintrags-ID, Domain oder Name mit
  einem Unternehmen verknüpfen

```
name,company,custom_fields_emails,custom_fields_title
John Doe,acme.com,john@acme.com,CEO
Jane Smith,acme.com,jane@acme.com,CTO
```

Ordnen Sie die Spalte `company` der Beziehung Unternehmen zu und wählen Sie
**Abgleichen nach** mit **Domain**, damit `acme.com` mit dem bestehenden Unternehmen verknüpft
wird. Wählen Sie stattdessen **Name** bei **Abgleichen nach**, wird immer ein neues Unternehmen
angelegt.

## Verkaufschancen

- `name` (erforderlich)
- Benutzerdefinierte Attribute (Betrag, Phase, Abschlussdatum und so weiter)
- Beziehung **Unternehmen**: über Eintrags-ID, Domain oder Name
- Beziehung **Ansprechpartner**: über Eintrags-ID, E-Mail, Telefon oder Name

```
name,company,contact,custom_fields_amount,custom_fields_stage
Q1 Enterprise Deal,acme.com,john@acme.com,50000,Proposal
```

Ordnen Sie `company` Unternehmen → Domain und `contact` Ansprechpartner →
E-Mail zu, um die zuverlässigste Verknüpfung zu erhalten.

## Aufgaben

- `title` (erforderlich)
- Beziehungen **Unternehmen**, **Personen**, **Verkaufschancen**: mit jeweils
  einem oder mehreren Einträgen verknüpfen
- Beziehung **Zuständige**: Mitgliedern per E-Mail-Adresse zuweisen

```
title,assignee,company,custom_fields_due_date,custom_fields_priority
Follow up with client,assignee@yourcompany.com,acme.com,2024-03-15,High
```

Ordnen Sie die Spalte `assignee` Zuständige → E-Mail zu, um per E-Mail-Adresse
zuzuweisen.

## Notizen

- `title` (erforderlich)
- Beziehungen **Unternehmen**, **Personen**, **Verkaufschancen**

```
title,company
Meeting Notes,acme.com
```

Notizen werden immer als neue Einträge angelegt. Ein Import aktualisiert nie eine
bestehende Notiz, auch wenn Sie eine Spalte `id` aufnehmen.
