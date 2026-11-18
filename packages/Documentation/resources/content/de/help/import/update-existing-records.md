---
title: Bestehende Einträge per Import aktualisieren
description: Gleichen Sie Zeilen über ID, E-Mail, Telefon oder Domain ab, um Einträge direkt zu aktualisieren, statt Duplikate anzulegen.
order: 3
updated: "2026-08-13"
related: [help/import/what-each-record-type-needs, help/import/fix-import-errors]
---

Wenn eine CSV-Spalte einen Eintrag eindeutig identifiziert (eine ID, eine
E-Mail-Adresse, eine Domain), ordnet der Import-Assistent Zeilen Ihren
bestehenden Daten zu und aktualisiert diese Einträge, statt sie zu duplizieren.
Sie wählen die Abgleichsmethode beim Zuordnen der Spalten, und die Vorschau
zeigt genau, was erstellt und was aktualisiert wird, bevor sich etwas ändert.

## Abgleichsmethoden

| Methode | Verhalten | Verfügbar für |
|--------|----------|---------------|
| **Eintrags-ID** | Aktualisiert genau diesen Eintrag. Die Zeile wird übersprungen, wenn die ID nicht gefunden wird | Alle Eintragstypen |
| **Domain** | Findet das bestehende Unternehmen über die Domain oder legt ein neues an | Unternehmen |
| **E-Mail** | Findet die bestehende Person über die E-Mail-Adresse oder legt eine neue an | Personen |
| **Telefon** | Findet die bestehende Person über die Telefonnummer oder legt eine neue an | Personen |
| **Name** | Legt immer einen neuen Eintrag an, da Namen nicht eindeutig sind | Unternehmen, Personen |

Der Vorschauschritt kennzeichnet dann jede Zeile:

- Einem bestehenden Eintrag zugeordnet → **Aktualisieren**
- Kein Treffer oder über den Namen abgeglichen → **Neu erstellen**
- Eine Eintrags-ID, die nicht existiert → **Überspringen**

Wenn Sie keine abgleichbare Spalte zuordnen, warnt der Assistent, dass jede Zeile
als neu angelegt wird. Sie können zurückgehen und eine zuordnen oder trotzdem
fortfahren.

## Wie sich Aktualisierungen verhalten

- **Leere Zellen werden ignoriert.** Eine leere CSV-Zelle bewahrt den bestehenden
  Wert. Nehmen Sie nur die Spalten auf, die Sie ändern möchten.
- **Felder mit mehreren Werten werden zusammengeführt.** E-Mail-Adressen,
  Telefonnummern, Tags und Werte aus Mehrfachauswahl werden zu dem hinzugefügt,
  was bereits im Eintrag steht, und ersetzen es nicht.
- **Duplikate innerhalb der Datei werden zusammengefasst.** Führen zwei Zeilen zum
  selben Eintrag (etwa dieselbe E-Mail-Adresse zweimal), aktualisiert die zweite
  Zeile das Ergebnis der ersten, statt ein Duplikat anzulegen.

## Über die Eintrags-ID aktualisieren

Für gezielte Aktualisierungen beginnen Sie mit einem Export:

1. **Exportieren** Sie die Einträge, die Sie ändern möchten. Die Datei enthält
   eine Spalte `id`.
2. **Bearbeiten** Sie die CSV-Datei und lassen Sie die Spalte `id` unverändert.
3. **Importieren Sie erneut.** Zeilen mit gültiger ID aktualisieren genau diese
   Einträge.

```
id,name,custom_fields_industry
01KCCFMZ52QWZSQZWVG0AP704V,Acme Corporation,Software
01KCCFN1A8XVQR4ZFWB3KC5M7P,TechStart Inc,Hardware
```

## Erstellen und aktualisieren in einer Datei

Zeilen mit ID aktualisieren, Zeilen mit leerer ID erstellen:

```
id,name
01KCCFMZ52QWZSQZWVG0AP704V,Update This Company
,New Company (blank ID = create new)
```
