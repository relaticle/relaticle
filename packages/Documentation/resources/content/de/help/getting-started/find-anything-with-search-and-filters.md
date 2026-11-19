---
title: Mit Suche und Filtern alles finden
description: So durchsuchen Sie Relaticle-Einträge und filtern Listen mit Unternehmen, Personen, Verkaufschancen, Aufgaben und Notizen.
order: 7
updated: "2026-11-13"
related: [help/getting-started/use-custom-fields, help/getting-started/import-your-existing-data]
---

Drücken Sie **⌘K** oder klicken Sie auf das Suchfeld in der Seitenleiste, um
alle Einträge auf einmal zu durchsuchen. Mit der Filterzeile über jeder Liste
grenzen Sie ein, was Sie bereits vor sich haben.

1. Klicken Sie oben in der Seitenleiste auf das Suchfeld oder drücken Sie
   **⌘K**.
2. Geben Sie einen beliebigen Teil eines Namens ein. Die Ergebnisse erscheinen
   nach Eintragstyp gruppiert: **Unternehmen**, **Verkaufschancen** und weitere.
3. Klicken Sie auf ein Ergebnis, um es zu öffnen.

![Das Dropdown der globalen Suche mit Ergebnissen für „Acme“, gruppiert unter Unternehmen und Verkaufschancen](/help-assets/getting-started/find-anything-with-search-and-filters-1.png)

## Einen Eintrag in einer Liste finden

Die Listen (Unternehmen, Personen, Verkaufschancen, Aufgaben, Notizen) haben
kein Suchfeld. Um einen Eintrag in einer Liste nach Namen zu finden, fügen Sie
einen Filter auf das Feld für Name oder Titel hinzu und wählen die Bedingung
**enthält**. Die Filterzeile über der Tabelle zeigt ihn an, und Sie können ihn
dort wieder entfernen.

## Eine Liste filtern

1. Klicken Sie in der Zeile über der Tabelle auf **Filter**. Sobald ein Filter
   gesetzt ist, erscheint stattdessen **+**.
2. Wählen Sie ein Feld. Tippen Sie, um die Liste der Felder einzugrenzen.
3. Wählen Sie eine Bedingung, zum Beispiel **enthält**, **ist eines von** oder
   **in den letzten**.
4. Geben Sie einen Wert ein oder wählen Sie einen aus. Die Liste aktualisiert
   sich, sobald der Filter vollständig ist.

![Die Filterzeile über der Liste der Verkaufschancen mit drei Chips: Phase ist eines von Verhandlung/Prüfung, Abschlussdatum in den nächsten 30 Tagen und Betrag größer als 10.000,00 $](/help-assets/getting-started/find-anything-with-search-and-filters-2.png)

Jeder Filter erscheint als Chip in der Zeile. Klicken Sie auf sein Feld, seine
Bedingung oder seinen Wert, um ihn zu ändern. Wenn Sie ein anderes Feld wählen,
bleibt der Chip erhalten, seine Bedingung und sein Wert werden aber geleert. Um
ihn zu entfernen, klicken Sie auf dem Chip auf **⋮** und dann auf **Filter
entfernen**. Ein Eintrag muss zu jedem Chip passen, damit er in der Liste
bleibt. Wenn die Filter alle Einträge ausblenden, klicken Sie auf **Filter
zurücksetzen**.

Die Zeile bleibt einzeilig. Scrollen Sie sie seitlich, wenn sie mehr Chips
enthält, als hineinpassen.

## Datumsangaben relativ halten

Wählen Sie **in den letzten**, **in den nächsten** oder **liegt**, um nach einem
Zeitraum zu filtern, etwa den letzten 30 Tagen oder diesem Monat. Eine
gespeicherte Ansicht hält den Zeitraum relativ, sodass er mit dem Kalender
wandert. **Heute** funktioniert genauso.

Ein Feld, das Mitglieder enthält, zeigt Sie zuerst an, mit Ihrem Namen gefolgt
von **(ich)**.

## Gelöschte Einträge anzeigen

**Gelöschte Einträge** steht am Ende der Feldliste. Sein Chip zeigt gelöschte
Einträge zusammen mit den übrigen (**eingeschlossen**) oder allein (**nur**).
Klicken Sie auf den zweiten Teil des Chips, um umzuschalten. Sie sehen ihn nur,
wenn Sie gelöschte Einträge sehen dürfen.

## Eine Liste sortieren und gruppieren

Klicken Sie auf **Sortieren** und suchen Sie die Spalte, nach der die Liste
geordnet werden soll. Klicken Sie auf **Sortierung hinzufügen**, um Gleichstände
mit einer weiteren Spalte aufzulösen, und ziehen Sie eine Zeile an ihrem Griff,
um zu ändern, welche Sortierung zuerst gilt. Jede Zeile wechselt zwischen
aufsteigend und absteigend, und ihr X entfernt sie. Die Schaltfläche zeigt
**Sortiert nach** und die erste Spalte, dazu eine Anzahl, wenn weitere folgen.

Bei Aufgaben gruppiert **Gruppieren** die Liste nach Status oder Priorität.

Eine gespeicherte Ansicht gehört nur Ihnen und speichert sich selbst. Ändern Sie
bei geöffneter Ansicht einen Filter, eine Sortierung, eine Gruppierung, eine
Spalte oder einen Spaltennamen, übernimmt die Ansicht die Änderung. Um aus dem
aktuellen Bildschirminhalt eine weitere Ansicht zu beginnen, öffnen Sie die
Ansichtsauswahl und wählen **Neue Ansicht erstellen**.

Die Auswahl führt Ihre Ansichten in einer Reihenfolge auf. Die eingebaute
Tabelle ist eine der Zeilen, etwa **Alle Unternehmen**. Verkaufschancen bringen
zusätzlich **Pipeline** mit, Aufgaben **Board** und Notizen **Karten**. Die
Suche oben grenzt die Liste nach Namen ein.

Ziehen Sie eine Zeile, um sie zu verschieben. Auf einem Touchscreen genügt dafür
langes Drücken. Das Menü jeder Zeile bietet außerdem **Nach oben** und **Nach
unten**, die sich per Tastatur bedienen lassen. Während Sie in der Suche tippen,
sind Ziehen und Verschieben ausgeschaltet.

Die oberste Ansicht ist die, mit der sich eine Seite öffnet, wenn Sie sie ohne
Link aufrufen, etwa über die Seitenleiste. Steht ein Board oder die Kartenansicht
ganz oben, öffnet sich die Seite damit. Ein Link, der etwas Bestimmtes benennt,
etwa einen Filter, einen Eintrag oder **Alle Unternehmen**, öffnet das, worauf er
verweist.

## Wenn Sie ein Feld in der Filterliste nicht finden

Jedes benutzerdefinierte Attribut lässt sich filtern, außer Attribute vom Typ
Mehrzeiliger Text, Rich-Text-Editor und Farbwähler. Verschlüsselte Attribute
lassen sich ebenfalls nicht filtern, ebenso wenig ein ausgeschaltetes Attribut.
Ein Attribut braucht **In der Liste sichtbar** nicht, um gefiltert zu werden.
Alle Attributeinstellungen finden Sie unter
[Benutzerdefinierte Attribute verwenden](/de/help/getting-started/use-custom-fields).

## Über die API, den MCP-Server oder Rela filtern

Die Filterzeile ist ein Weg, eine Liste einzugrenzen. Die REST-API, der MCP-Server und Rela, der integrierte KI-Assistent, verwenden stattdessen ein `filter`-Objekt. Es filtert nach nativen Feldern und benutzerdefinierten Attributen, verknüpft Bedingungen mit AND, OR und NOT und folgt Verknüpfungen zwischen Einträgen. Die vollständige Grammatik steht im [MCP-Leitfaden](/de/developers/mcp).
