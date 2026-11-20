---
title: Doppelte Einträge zusammenführen
description: Führen Sie zwei doppelte Unternehmen oder Personen zu einem Eintrag zusammen. Der rechte gewinnt. Das Zusammenführen ist endgültig.
order: 7
updated: "2026-11-20"
related: [help/records/company-records, help/records/people-records, help/records/restore-deleted-records]
---

Doppelte Einträge entstehen meist nach einem Import oder einer Postfach-Synchronisierung.
Wenn Sie zwei Unternehmen oder zwei Personen zusammenführen, behalten Sie einen
Eintrag, an dem alles hängt, was mit einem der beiden verknüpft war. Mitglieder und
Administratoren können zusammenführen. Betrachter nicht.

## Zusammenführen starten

- **Über den Abschnitt „Mögliche Duplikate“.** Eine Eintragsseite mit möglichen
  Duplikaten zeigt einen Abschnitt mit deren Anzahl. Klappen Sie ihn auf und klicken
  Sie neben dem gewünschten Eintrag auf **Zusammenführen**. Der Dialog öffnet sich mit
  diesem Eintrag bereits ausgewählt.
- **Über das Menü.** Öffnen Sie den Eintrag, klicken Sie auf das Menü **⋯** und wählen
  Sie **Eintrag zusammenführen**.

## Im Dialog

1. Suchen Sie den anderen Eintrag nach Name, Domain oder E-Mail und wählen Sie ihn aus.
2. Prüfen Sie die beiden Karten. Mit der Schaltfläche **⇄** zwischen ihnen ändern Sie, welcher gewinnt.
3. Klicken Sie auf **Einträge zusammenführen**.

Der Eintrag rechts gewinnt überall dort, wo beide einen Wert haben. Lücken werden aus
dem anderen Eintrag aufgefüllt. Domains, E-Mails und jedes Attribut, das eine Liste
enthält, werden kombiniert, die des Gewinners zuerst. Dazu zählen Mehrfachauswahl,
Checkbox-Liste, Tags und Attribute mit aktivierter Mehrfachauswahl („allow multiple“).

## Was Sie erhalten

Beim Zusammenführen entsteht ein neuer Eintrag. Beide Originale werden entfernt.
Alles, was mit einem von ihnen verknüpft war, wandert zum neuen Eintrag: Notizen,
Aufgaben, E-Mails, Meetings, Personen und Verkaufschancen sowie der Aktivitätsverlauf.
Der Verlauf zeigt eine Erstellung und einen Eintrag, der sagt, wer die Einträge
zusammengeführt hat.

Überschreitet eine kombinierte Liste das Limit des Feldes, steigt das Limit auf die
kombinierte Anzahl. Das gilt für den ganzen Arbeitsbereich, jeder Eintrag dieses Typs
kann dann so viele Werte aufnehmen. Eine Zusammenführung, deren Liste mehr als 20 Werte
hätte, wird abgelehnt. Der Dialog bittet Sie, zuerst einige Werte zu entfernen.

## Alte Links

Der Link eines zusammengeführten Eintrags zeigt **Dieser Eintrag wurde
zusammengeführt** und die Schaltfläche **Zum Eintrag**, die zum neuen Eintrag führt.

## Zusammenführen per API, MCP und Rela

Sie können auch ohne den Dialog zusammenführen. Die REST-API hat einen
Zusammenführen-Endpunkt für Unternehmen und für Personen, der MCP-Server bietet je ein
Tool dafür, und Rela, der integrierte KI-Assistent, schlägt Zusammenführungen zur
Freigabe vor. Es gilt dieselbe Regel wie im Dialog.

## Gut zu wissen

- Das Zusammenführen lässt sich nicht rückgängig machen. Ein zusammengeführter Eintrag
  liegt nicht im Papierkorb und kann nicht
  [wiederhergestellt](/de/help/records/restore-deleted-records) werden.
- Es werden immer zwei Einträge zusammengeführt. Bei drei Duplikaten sind es zwei Durchgänge.
- Nur Unternehmen und Personen lassen sich zusammenführen.
