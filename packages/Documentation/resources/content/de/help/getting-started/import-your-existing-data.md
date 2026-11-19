---
title: Bestehende Daten importieren
description: "Schritte für den CSV-Import in Relaticle: Spalten zuordnen, Einträge prüfen, Dateilimits von 10.000 Zeilen und 10 MB."
order: 4
updated: "2026-08-12"
related: [help/import/prepare-your-csv, help/import/update-existing-records, help/getting-started/use-custom-fields]
---

Der Import übernimmt bestehende Unternehmen, Personen oder Verkaufschancen aus
einer Tabelle, statt sie einzeln einzutippen. Laden Sie eine CSV-Datei hoch,
ordnen Sie ihre Spalten den Relaticle-Feldern zu, prüfen Sie die Werte und
bestätigen Sie. Relaticle zeigt Ihnen genau, wie viele Einträge erstellt oder
aktualisiert werden, bevor sich etwas ändert.

1. Klicken Sie in der Seitenleiste auf **Unternehmen**, **Personen** oder
   **Verkaufschancen**.
2. Klicken Sie auf **Import / Export** und dann auf **Unternehmen importieren**
   (die Beschriftung passt zur Liste, in der Sie sich befinden).
3. Klicken Sie auf **CSV-Datei auswählen** oder legen Sie die Datei auf dem
   Upload-Bereich ab, mit bis zu 10.000 Zeilen und 10 MB.
4. Klicken Sie auf **Weiter**.

## Spalten zuordnen

Relaticle ordnet die Spalten Ihrer Datei den Relaticle-Feldern automatisch zu,
wenn die Namen nah genug beieinanderliegen.

![Der Schritt „Zuordnen“, in dem die Werte der Dateispalten Name, Domain und LinkedIn automatisch den Feldern Name, Domains und LinkedIn zugeordnet sind](/help-assets/getting-started/import-your-existing-data-1.png)

5. Prüfen Sie jedes Feld unter **Zuordnen zu** und korrigieren Sie falsche
   Zuordnungen.
6. Klicken Sie auf **Weiter**.

## Werte prüfen und korrigieren

Relaticle validiert jeden einzelnen Wert in jeder Spalte und gruppiert sie unter
**Alle**, **Prüfung erforderlich**, **Geändert** und **Übersprungen**. Bearbeiten
Sie einen Wert direkt, um ihn zu korrigieren, oder überspringen Sie eine Zeile,
die Sie nicht importieren möchten.

7. Klicken Sie auf **Weiter**, sobald Sie mit den zugeordneten Werten zufrieden
   sind.
8. Prüfen Sie die Anzahl der Einträge, die erstellt und aktualisiert werden,
   klicken Sie auf **Import starten** und bestätigen Sie.

![Die Zusammenfassung „Import abgeschlossen“ mit 2 erstellt, 0 aktualisiert und 0 fehlgeschlagen](/help-assets/getting-started/import-your-existing-data-2.png)

Jeder frühere Import steht auf der Seite **Importverlauf** unter dem Namen Ihres
Arbeitsbereichs, einschließlich der Zahl der erstellten, aktualisierten,
übersprungenen oder fehlgeschlagenen Zeilen.

## Wenn eine Spalte nicht automatisch zugeordnet wird, ordnen Sie sie von Hand zu

Öffnen Sie das Dropdown unter **Zuordnen zu** für diese Spalte und wählen Sie das
passende Feld selbst aus. Oder klicken Sie auf **Zuordnung entfernen**, um die Spalte
ganz vom Import auszuschließen.

Regeln zur Dateiformatierung, zum Abgleichen und Aktualisieren bestehender
Einträge und Lösungen für Werte, die der Import-Assistent markiert, finden Sie in den
Artikeln unter [Daten importieren](/de/help/import).
