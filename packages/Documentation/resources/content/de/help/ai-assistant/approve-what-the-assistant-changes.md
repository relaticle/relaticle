---
title: Änderungen des Assistenten genehmigen
description: Jede Erstellung, Änderung oder Löschung, die die KI vorschlägt, wartet auf Ihre Prüfung. Ohne Ihre Genehmigung wird nichts gespeichert.
order: 2
updated: "2026-11-06"
related: [help/ai-assistant/ask-questions-about-your-data, help/getting-started/use-custom-fields, help/email-and-calendar/use-your-email-with-an-ai-assistant]
---

Wenn Sie den Assistenten bitten, etwas zu ändern („Erstellen Sie eine
Folgeaufgabe für Acme“, „Verschieben Sie diese Verkaufschance nach Verhandlung“,
„Löschen Sie diese drei Notizen“), schreibt er nie direkt in Ihr CRM. Er entwirft
die Änderung als Vorschlagskarte an der Stelle des Nachrichtenfelds und
wartet. Genehmigen oder verwerfen Sie sie, um weiter zu chatten.

![Eine Vorschlagskarte für „Create task Send updated proposal to Figma“ mit Titel, verknüpftem Unternehmen und verknüpfter Verkaufschance, Status, Fälligkeitsdatum und Priorität sowie den Schaltflächen Verwerfen und Genehmigen](/help-assets/ai-assistant/approve-what-the-assistant-changes-1.png)

## Die Vorschlagskarte

Die Karte listet jede Änderung als eine Zeile auf, mit jedem Wert, den sie
setzt. Bei einer Aktualisierung steht der alte Wert durchgestrichen neben dem
neuen. Von dort aus können Sie Folgendes tun:

- **Genehmigen.** Die Schaltfläche zählt, was sie schreibt: **Genehmigen**,
  bei mehreren Änderungen **3 Änderungen genehmigen**. Eine Löschung zeigt
  in Rot **2 Aufgaben löschen**. `⌘↵` genehmigt per Tastatur, außer bei
  Löschungen, E-Mails, Einladungen und Überschreibungen, die immer einen
  Klick brauchen.
- **Zuerst ein Attribut bearbeiten.** Klicken Sie auf den Stift neben einem
  Wert, korrigieren Sie ihn und klicken Sie dann auf **Speichern**.
  Genehmigen Sie, wenn die Karte stimmt.
- **Etwas weglassen.** Fahren Sie mit der Maus über ein Attribut, eine Zeile
  oder einen Eintrag und klicken Sie auf das **×**. Es bleibt durchgestrichen
  auf der Karte, mit **Behalten**, um es zurückzuholen. Entschieden ist erst
  etwas, wenn Sie **Genehmigen** drücken.
- **Verwerfen.** Es wird nichts geschrieben.

Der Assistent kann jedes Attribut setzen, das Sie im Formular des Eintrags
selbst setzen könnten, auch die benutzerdefinierten Attribute Ihres
Arbeitsbereichs. Jedes erscheint zur Prüfung auf der Karte.

## Wenn jemand den Eintrag zwischenzeitlich geändert hat

Ändert jemand ein Attribut, nachdem der Assistent dessen Änderung
vorgeschlagen hat, nennt die Karte, wer es geändert hat, auf welchen Wert und
wann. Das Genehmigen braucht dann einen Klick, nie die Tastenkombination. Bei
einer einzelnen Aktualisierung heißt die Schaltfläche **Überschreiben**.
Lassen Sie dieses Attribut weg, um den Wert der anderen Person zu behalten (in
einem Stapel oder Plan lassen Sie den ganzen Eintrag weg), oder verwerfen Sie
den Vorschlag. Eine Änderung an einem Attribut, das der Vorschlag nicht
berührt, ändert nichts.

Trifft die Änderung ein, während die Karte offen ist, zeigt Ihr erster Klick
die Änderung an, statt zu genehmigen. Klicken Sie erneut, sobald Sie sie
gelesen haben.

## Stapel und Pläne

Eine Anfrage kann bis zu 25 Einträge vorschlagen. „Fügen Sie diese fünf Personen
aus meinen Meeting-Notizen hinzu“ kommt als eine Karte, die alle fünf
auflistet. Lassen Sie die weg, die Sie nicht möchten, und genehmigen Sie den
Rest. Löschungen funktionieren genauso.

Eine Anfrage, die mehrere verknüpfte Änderungen braucht, etwa ein neues
Unternehmen mit einer Person und einer Aufgabe, kommt als ein Plan mit bis zu
5 verknüpften Schritten. Ein Eintrag, den der Plan erstellt und mit dem eine
andere Änderung verknüpft ist, trägt die Markierung **neu**. Lassen Sie diesen
Eintrag weg, entfallen auch die Änderungen, die mit ihm verknüpft sind. Die
Karte sagt Ihnen, welche, und bietet **Behalten** an, um sie
zurückzuholen. Den Plan genehmigen Sie einmal.

## Nach Ihrer Entscheidung

Die Karte verschwindet, und die Unterhaltung behält einen Beleg: **3
Änderungen angewendet** mit einer Zeile pro Eintrag, jede mit Link zum
Eintrag. Alles, was Sie weggelassen haben, erscheint durchgestrichen. Ein
verworfener Vorschlag hinterlässt eine graue Zeile. Beim jüngsten steht
**Erneut vorschlagen** zur Wahl.

## E-Mail

Eine E-Mail wird einzeln genehmigt. Ihre Karte zeigt alle Empfänger und die
ganze Nachricht, und ihre Schaltfläche heißt **E-Mail senden**. Ein Plan
listet die E-Mail als „Danach E-Mail an …“ auf und sendet sie nie mit dem
Rest: Sobald Sie den Plan genehmigen, bekommt die E-Mail die Karte für sich
allein und wartet auf ihr eigenes **E-Mail senden**.
Siehe [Ihre E-Mails mit einem KI-Assistenten nutzen](/de/help/email-and-calendar/use-your-email-with-an-ai-assistant).

## Einladungen

Wer jemanden in Ihren Arbeitsbereich einlädt, verschickt eine E-Mail an diese
Person. Deshalb hat auch eine Einladung eine eigene Schaltfläche: **Einladung
senden**. Das Genehmigen eines Plans sendet sie nie.

## Vorschläge warten nicht ewig

- Solange ein Vorschlag wartet, nimmt die Karte den Platz des Nachrichtenfelds
  ein. Genehmigen oder verwerfen Sie ihn, um weiter zu chatten.
- Ein unberührter Vorschlag läuft nach 24 Stunden ab, und seine Zeile lautet
  **abgelaufen**. Ist es der jüngste, liefert **Erneut vorschlagen** eine
  frische Karte.
- Eine Antwort lässt sich nicht neu generieren, solange ihr Vorschlag aussteht.
  Entscheiden Sie zuerst über die Karte.

## Wer was vorschlagen kann

Der Assistent handelt mit Ihren Berechtigungen. Benutzerdefinierte Attribute
im Chat zu erstellen und zu bearbeiten ist Sache des Inhabers und der
Administratoren, genau wie bei der Seite **Benutzerdefinierte Attribute**
selbst. Für alle anderen erklärt der Assistent die Sache und verlinkt dorthin.
