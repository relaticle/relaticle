---
title: Steuern, wo ein Attribut erscheint
description: Die Einstellungen eines Attributs bestimmen seinen Platz in Listen und auf Seiten von Einträgen sowie wie es filtert und sortiert.
order: 3
updated: "2026-11-10"
related: [help/custom-fields/add-and-manage-fields, help/getting-started/find-anything-with-search-and-filters]
---

Jedes Attribut hat einen Abschnitt **Einstellungen**, der steuert, wo es
erscheint und wie es sich verhält. Öffnen Sie das Attribut auf der Seite
**Benutzerdefinierte Attribute**, um sie zu ändern. Änderungen gelten sofort
für den ganzen Arbeitsbereich.

![Der Abschnitt Einstellungen im Formular Attribut bearbeiten mit Schaltern für In der Liste sichtbar, In der Detailansicht sichtbar, Ein- und ausblendbar, Verschlüsselt und Farboptionen aktivieren](/help-assets/custom-fields/control-where-a-field-appears-1.png)

## Sichtbarkeit

| Einstellung | Was sie bewirkt |
|-------------|-----------------|
| In der Liste sichtbar | Fügt das Attribut als Spalte auf der Listenseite des Eintragstyps hinzu |
| In der Detailansicht sichtbar | Zeigt das Attribut auf der Detailseite des Eintrags |
| Ein- und ausblendbar, standardmäßig ausgeblendet | Hält die Listenspalte verfügbar, aber ausgeblendet, bis jemand sie über **Ansichtseinstellungen** unter **Attribut zur Ansicht hinzufügen** hinzufügt |

Ein Attribut, das an beiden Stellen aus ist, gibt es weiterhin im Formular zum
Erstellen und Bearbeiten. Nutzen Sie das für Daten, die Sie erfassen, aber im
Alltag nicht sehen müssen.

## Filter und Sortierung

- **Filter** brauchen keine Einstellung. Sie können eine Liste in der
  Filterzeile darüber nach den meisten Attributen filtern. Attribute vom Typ
  Mehrzeiliger Text, Rich-Text-Editor und Farbwähler haben keinen Filter, ein
  verschlüsseltes Attribut ebenso wenig. Siehe
  [Alles finden mit Suche und Filtern](/de/help/getting-started/find-anything-with-search-and-filters).
- **Suche** braucht keine Einstellung. Rela und der MCP-Server gleichen die
  Werte von Attributen der Typen Text, Mehrzeiliger Text, E-Mail,
  Telefonnummer, Link, Domain und Farbwähler ab. Sie überspringen Attribute
  der Typen Auswahl, Eintrag, Rich-Text-Editor, Zahl, Datum und Währung. Die
  Suche in der Seitenleiste gleicht Namen und Titel ab.
- **Sortieren** funktioniert bei Text-, Zahlen-, Währungs- und Datumsspalten.
  Spalten mit mehreren Werten und Kontakttyp-Spalten (E-Mail-Adressen,
  Telefonnummern, Links) lassen sich nicht sortieren.

## Datenform

| Einstellung | Was sie bewirkt |
|-------------|-----------------|
| Mehrere Werte zulassen | Lässt einen Eintrag mehrere Werte halten (mit einer Obergrenze, die Sie festlegen, bis zu 20) |
| Eindeutig pro Eintragstyp | Keine zwei Einträge dieses Typs können einen Wert teilen. So verhindern Domains und E-Mail-Adressen Duplikate |
| Verschlüsselt | Speichert Werte verschlüsselt. Verschlüsselte Attribute lassen sich nicht sortieren |
| Farboptionen aktivieren | Für Attribute vom Typ Auswahl und Tag-Eingabe. Gibt jeder Option eine Farbe, die als Abzeichen in Listen und auf dem Board erscheint |
