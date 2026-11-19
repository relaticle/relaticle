---
title: CSV-Datei vorbereiten
description: Dateianforderungen, Spaltenbenennung und Formatierungsregeln, damit ein Import auf Anhieb gelingt.
order: 1
updated: "2026-08-13"
related: [help/import/what-each-record-type-needs, help/import/fix-import-errors, help/getting-started/import-your-existing-data]
---

Eine CSV-Datei lässt sich sauber importieren, wenn sie UTF-8-codiert ist, in der
ersten Zeile Spaltenüberschriften hat und jede Spalte einheitlich formatiert
ist. Diese Seite behandelt die Regeln. Die
[Schritt-für-Schritt-Anleitung](/de/help/getting-started/import-your-existing-data)
behandelt die Klicks.

## Dateianforderungen

| Anforderung | Wert |
|-------------|-------|
| Format | CSV (durch Kommas getrennte Werte) |
| Codierung | UTF-8 |
| Maximale Zeilenzahl | 10.000 pro Datei |
| Maximale Größe | 10 MB |
| Überschriften | In der ersten Zeile erforderlich |

Verwenden Sie in Excel **Speichern unter → CSV UTF-8 (durch Trennzeichen
getrennt)**. Die Standardcodierung von Excel für CSV zerstört Umlaute und andere
Sonderzeichen.

## Erforderliche Spalten

Pro Eintragstyp ist nur eine Spalte erforderlich:

| Eintragstyp | Erforderliche Spalte |
|-------------|-----------------|
| Unternehmen | Name |
| Personen | Name |
| Verkaufschancen | Name |
| Aufgaben | Titel |
| Notizen | Titel |

Alle anderen Spalten sind optional. Eine leere Zelle lässt das Feld einfach leer.

## Spalten für benutzerdefinierte Attribute

Benennen Sie Spalten für benutzerdefinierte Attribute mit dem Präfix
`custom_fields_`, gefolgt vom Code des Attributs:

```
custom_fields_industry
custom_fields_emails
custom_fields_website
```

Den Code jedes Attributs finden Sie unter **Einstellungen → Benutzerdefinierte
Attribute** in der Spalte **Code**. Der Import-Assistent ordnet auch ähnliche Namen
automatisch zu, sodass eine Spalte namens „Industry“ meist ohne Präfix beim Attribut
Industry landet. Das Präfix macht die Zuordnung exakt.

## Werte nach Feldtyp formatieren

| Feldtyp | Format | Beispiel |
|------|--------|---------|
| Text | Reiner Text | `Technology` |
| Zahl | Nur Ziffern, keine Symbole | `50000` |
| Datum | JJJJ-MM-TT, TT/MM/JJJJ oder MM/TT/JJJJ | `2024-03-15` |
| E-Mail | Gültige E-Mail-Adresse(n), durch Kommas getrennt | `john@acme.com,jane@acme.com` |
| Auswahl | Die exakte Optionsbezeichnung | `Enterprise` |
| Mehrfachauswahl | Optionsbezeichnungen, durch Kommas getrennt | `CRM,Analytics` |
| Boolescher Wert | true/false, yes/no oder 1/0 | `true` |

## Empfohlen

- Fügen Sie in der ersten Zeile Überschriften ein.
- Setzen Sie Werte, die Kommas enthalten, in Anführungszeichen: `"Last, First"`.
- Halten Sie innerhalb jeder Spalte ein einheitliches Format ein, besonders bei
  Datumsangaben.
- Testen Sie mit einer kleinen Stichprobe (5 bis 10 Zeilen), bevor Sie die ganze
  Datei importieren.

## Zu vermeiden

- Die Standardcodierung von Excel verwenden. Wählen Sie „CSV UTF-8“.
- Datumsformate in derselben Spalte mischen (`2024-03-15` und `03/15/2024`).
- Währungssymbole in Zahlen aufnehmen. Schreiben Sie `50000`, nicht `$50,000`.
- Leere Zeilen am Ende der Datei lassen.

## Eine fertige Vorlage erhalten

Exportieren Sie einige bestehende Einträge aus einer beliebigen Liste. Die
exportierte Datei enthält die Spalte `id` und jedes benutzerdefinierte Attribut,
das Ihr Arbeitsbereich verwendet, bereits richtig benannt. Löschen Sie die
Zeilen, behalten Sie die Überschriften und tragen Sie Ihre Daten ein.
