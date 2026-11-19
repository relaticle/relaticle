---
title: Importfehler beheben
description: Was Sie tun, wenn ein Upload, eine Zuordnung oder eine Prüfung fehlschlägt, und wie mehrdeutige Datumsangaben behandelt werden.
order: 4
updated: "2026-08-13"
related: [help/import/prepare-your-csv, help/import/update-existing-records]
---

Die meisten Importprobleme gehören zu einer von vier Arten: Die Datei lässt sich
nicht hochladen, eine Spalte lässt sich nicht zuordnen, Werte bestehen die
Prüfung nicht, oder der Import selbst verhält sich unerwartet. Suchen Sie unten
das Symptom heraus. Jede Lösung können Sie selbst umsetzen.

## Upload-Probleme

| Problem | Lösung |
|---------|----------|
| Datei zu groß | In Dateien mit jeweils weniger als 10.000 Zeilen aufteilen |
| Ungültiges Format | In Excel erneut als „CSV UTF-8“ speichern |
| Upload schlägt fehl | Verbindung prüfen, dann eine kleinere Datei versuchen |

## Zuordnungsprobleme

| Problem | Lösung |
|---------|----------|
| Eine Spalte wurde nicht automatisch zugeordnet | Das Feld selbst im Dropdown **Zuordnen zu** auswählen |
| Ein benutzerdefiniertes Attribut fehlt im Dropdown | Den Code des Attributs unter **Einstellungen → Benutzerdefinierte Attribute** prüfen |
| Ein Pflichtfeld erscheint rot | Eine Ihrer CSV-Spalten diesem Feld zuordnen |

## Prüfungsprobleme

| Problem | Lösung |
|---------|----------|
| Ungültige E-Mail-Adresse | Das Format korrigieren: `user@domain.com` |
| Ungültiges Datum | ISO-, europäisches oder amerikanisches Format durchgehend einheitlich verwenden |
| Ungültige Eintrags-ID | Neu exportieren, um aktuelle IDs zu erhalten |
| Unbekannte Auswahloption | Exakt die Optionsbezeichnung aus den Einstellungen des Attributs verwenden |

Im Prüfschritt können Sie einen Wert auch einmal korrigieren (**Diesen Wert korrigieren**), sodass
die Korrektur in jeder Zeile gilt, die ihn enthält, oder einen Wert mit **Diesen Wert überspringen**
auslassen. Die Zeilen werden trotzdem importiert, nur dieses eine Feld bleibt
leer.

## Mehrdeutige Datumsangaben

Der Import-Assistent liest drei Datumsformate und erkennt, welches eine Spalte
verwendet:

| Format | Muster | Beispiel |
|--------|---------|---------|
| ISO | JJJJ-MM-TT | `2024-05-15` |
| Europäisch | TT/MM/JJJJ | `15/05/2024` |
| Amerikanisch | MM/TT/JJJJ | `05/15/2024` |

Ist ein Tageswert größer als 12, ist das Format eindeutig, sodass `31/01/2024`
nur europäisch sein kann. Sind beide Positionen 12 oder kleiner (`01/02/2024`,
2. Januar oder 1. Februar?), zeigt der Assistent an dieser Spalte eine Warnung
und ein Dropdown für das Format: Wählen Sie **Europäisch** oder **Amerikanisch**,
um anzugeben, welche Lesart stimmt. ISO-Datumsangaben sind nie mehrdeutig,
weshalb sie das sicherste Format für den Export aus anderen Werkzeugen sind.

## Importprobleme

| Problem | Lösung |
|---------|----------|
| Hängt bei „Wird verarbeitet…“ | Große Importe laufen im Hintergrund. Schauen Sie in einigen Minuten noch einmal nach |
| Einige Zeilen sind fehlgeschlagen | Die fehlgeschlagenen Zeilen herunterladen, korrigieren und nur diese erneut importieren |
| Unerwartete Duplikate | Eine eindeutige Spalte zuordnen (E-Mail, Domain oder ID). Siehe [Bestehende Einträge per Import aktualisieren](/de/help/import/update-existing-records) |

## Gut zu wissen

- **Ein Import lässt sich nicht rückgängig machen.** Testen Sie mit einer Stichprobe von 5 bis 10
  Zeilen, bevor Sie die ganze Datei importieren.
- **Ein fehlgeschlagener Import behält seine erfolgreichen Zeilen.** Korrigieren
  Sie nur die heruntergeladenen fehlgeschlagenen Zeilen und importieren Sie diese
  erneut.
- **Ein Eintragstyp pro Datei.** Beim Import von Personen können deren
  Unternehmen trotzdem automatisch angelegt werden, wenn Sie die Beziehung
  Unternehmen über den Namen abgleichen.
- **Jeder Lauf wird protokolliert.** Die Seite **Importverlauf** unter dem Namen
  Ihres Arbeitsbereichs zeigt für jeden Import die Anzahl der erstellten,
  aktualisierten, übersprungenen und fehlgeschlagenen Zeilen.
