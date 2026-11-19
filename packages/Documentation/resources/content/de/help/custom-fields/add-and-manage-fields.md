---
title: Attribute hinzufügen, bearbeiten, archivieren
description: Erstellen Sie Attribute auf der Seite Benutzerdefinierte Attribute, ordnen Sie sie neu, legen Sie sie sicher still und verstehen Sie Systemattribute.
order: 2
updated: "2026-11-10"
related: [help/custom-fields/field-types-you-can-add, help/custom-fields/control-where-a-field-appears, help/getting-started/use-custom-fields]
---

Attribute verwalten Sie auf der Seite **Benutzerdefinierte Attribute**:
Klicken Sie oben in der Seitenleiste auf den Namen Ihres Arbeitsbereichs und
wählen Sie **Benutzerdefinierte Attribute**. Nur der Inhaber des
Arbeitsbereichs und die Administratoren sehen diese Seite. Alle anderen nutzen
die Attribute, Inhaber und Administratoren gestalten sie.

Die Seite zeigt einen Tab pro Eintragstyp (Unternehmen, Personen,
Verkaufschancen, Aufgaben, Notizen) mit einem Zähler und die Attribute des
gewählten Typs in einer Tabelle, die Sie mit **Attribute suchen ...**
durchsuchen können.

![Die Seite Benutzerdefinierte Attribute im Tab Verkaufschancen mit Betrag, Abschlussdatum und Phase mit System-Abzeichen sowie einem eigenen Attribut Deal Source](/help-assets/custom-fields/add-and-manage-fields-1.png)

## Ein Attribut hinzufügen

1. Wählen Sie den Tab des Eintragstyps.
2. Klicken Sie auf **Attribut hinzufügen**.
3. Wählen Sie den **Typ**. Er ist dauerhaft, prüfen Sie also zuerst
   [Attributtypen, die Sie hinzufügen können](/de/help/custom-fields/field-types-you-can-add).
4. Geben Sie dem Attribut einen Namen (Namen müssen pro Eintragstyp eindeutig
   sein).
5. Fügen Sie bei Auswahltypen die **Optionen** hinzu.
6. Passen Sie die Schalter unter **Einstellungen** an (wo das Attribut
   erscheint, ob es verschlüsselt ist) und speichern Sie.

Das Attribut erscheint sofort für alle im Arbeitsbereich im Formular des
Eintrags, auf der Seite des Eintrags und, falls Sie es aktiviert haben, in der
Listenansicht. Rela und der CSV-Import können es ebenfalls sofort nutzen, ohne
weitere Einrichtung.

## Bearbeiten und neu ordnen

Klicken Sie auf die Zeile eines Attributs, um es umzubenennen, seine Optionen
zu ändern oder seine Einstellungen anzupassen, also alles außer dem Typ.
Ziehen Sie den Griff am Anfang jeder Zeile, um Attribute neu zu ordnen.
Formulare und Seiten von Einträgen folgen der Reihenfolge, die Sie festlegen.

## Archivieren statt löschen

**Deaktivieren** verschiebt ein Attribut in die einklappbare Gruppe
**Archiviert** am Ende der Tabelle. Es verschwindet aus Formularen und Listen,
aber jeder gespeicherte Wert bleibt erhalten. **Aktivieren** bringt das
Attribut samt seinen Daten sofort zurück. So legen Sie ein Attribut sicher
still, das Sie vielleicht wieder brauchen, und so blenden Sie ein Attribut vor
Rela aus.

**Löschen** ist nur bei einem Attribut möglich, das bereits archiviert ist,
und es ist endgültig: Das Attribut, seine Optionen und **alle in Ihren
Einträgen gespeicherten Werte** werden dauerhaft entfernt. Relaticle bittet
Sie vorher um Bestätigung.

## Systemattribute

Attribute mit einem Abzeichen **System** sind die, auf die sich Relaticle
selbst stützt: die E-Mail-Adressen einer Person, die Domains eines
Unternehmens, Betrag, Abschlussdatum und Phase einer Verkaufschance, Status und
Priorität einer Aufgabe, der Inhalt einer Notiz. Sie können sie nicht
umbenennen, nicht ihren Typ ändern, nicht archivieren und nicht löschen. Ihre
Einstellungen können Sie aber weiter bearbeiten, und bei den Auswahl-Attributen
darunter auch deren Optionen:
[Benennen Sie Ihre Pipeline-Phasen](/de/help/custom-fields/edit-the-options-in-a-select-field)
jederzeit um.
