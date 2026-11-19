---
title: Mit Rela ein Unternehmen recherchieren
description: Lassen Sie Rela im Web nach einem Unternehmen oder einer Person suchen, die noch nicht in Ihrem Arbeitsbereich ist, und bestimmen Sie, wann das erlaubt ist.
order: 6
updated: "2026-11-04"
related: [help/ai-assistant/approve-what-the-assistant-changes, help/ai-assistant/ai-credits-and-limits, help/ai-assistant/choose-an-ai-model]
---

Rela, der integrierte KI-Assistent, antwortet zuerst aus Ihrem Arbeitsbereich.
Braucht eine Frage etwas, das Ihre Einträge nicht enthalten, etwa was ein
Unternehmen verkauft oder wer es gegründet hat, kann Rela das Web durchsuchen
und die gefundenen Seiten lesen.

## So läuft eine Recherche-Anfrage ab

Fragen Sie in einfachen Worten, zum Beispiel „Recherchieren Sie Filaforms und sagen Sie
mir, ob sie zu uns passen könnten“. Braucht Rela das Web, fragt es vor der
Suche nach. Eine Karte über dem Nachrichtenfeld zeigt, wonach es suchen
möchte und warum, mit drei Schaltflächen:

- **Zulassen** erlaubt Rela die Websuche nur für diese Anfrage. Ihre nächste Nachricht fragt erneut.
- **Immer zulassen** tut dasselbe und fragt ab dann nicht mehr.
- **Ablehnen** hält Rela bei den Daten Ihres Arbeitsbereichs. Es antwortet aus Ihren Einträgen und sagt, dass es das Web nicht genutzt hat.

Während Rela arbeitet, zeigt der Chat, wonach es sucht und welche Seite es
gerade liest. Die Antwort endet mit einer Zeile **Quellen**: ein Link für
jede Seite, die Rela zitiert hat. Jeder Link öffnet sich in einem neuen Tab.

Rela schreibt nie von sich aus in Ihre Einträge, was es gefunden hat. Schlägt
es vor, eine Notiz hinzuzufügen oder ein Attribut auszufüllen, genehmigen Sie
diese Änderung wie jede andere. Siehe
[Änderungen des Assistenten genehmigen](/de/help/ai-assistant/approve-what-the-assistant-changes).

## Festlegen, wann Rela suchen darf

Öffnen Sie über Ihr Avatar-Menü **Einstellungen**, dann **Rela**, und wählen
Sie unter **Websuche** eine Option:

| Auswahl | Was passiert |
|---------|--------------|
| Immer fragen | Rela fragt vor jeder Recherche-Anfrage. Das ist die Voreinstellung. |
| Immer zulassen | Rela sucht, ohne zu fragen. |
| Nicht zulassen | Rela durchsucht das Web nie und antwortet nur aus Ihrem Arbeitsbereich. |

Die Wahl gilt nur für Sie. Andere Mitglieder wählen selbst.

## Die Websuche für den ganzen Arbeitsbereich ausschalten

Der Inhaber des Arbeitsbereichs kann die Websuche für alle Mitglieder
ausschalten, unter **Einstellungen des Arbeitsbereichs** (klicken Sie in der
Seitenleiste auf den Namen Ihres Arbeitsbereichs), im Abschnitt **Rela**.
Solange sie aus ist, bittet Rela nie um eine Suche, und die Einstellung jedes
Mitglieds zeigt, dass der Inhaber sie ausgeschaltet hat.

## Was Ihren Arbeitsbereich verlässt

Rela schreibt die Suchanfragen selbst, und sie gehen an die Websuche des
KI-Anbieters: Anthropic für Claude-Modelle, OpenAI für GPT-Modelle. Eine
Anfrage kann ein Unternehmen oder eine Person aus Ihrer Anfrage nennen. Die
Seiten, die Rela liest, ruft derselbe Anbieter ab, der ohnehin jede Nachricht
verarbeitet, die Sie Rela senden.

Seiten im Web sind keine Anweisungen. Rela behandelt, was es liest, als
Information, und jede Änderung, die es vorschlägt, wartet weiter auf Ihre
Genehmigung.

## Was eine Suche kostet

In Relaticle Cloud verbraucht jede Websuche 2 Credits zusätzlich zu den
normalen Kosten der Nachricht. Das Lesen einer Seite zählt wie jedes andere
Tool. Siehe [KI-Credits und Ratenlimits](/de/help/ai-assistant/ai-credits-and-limits).

## Welche Modelle suchen können

Nur Claude- und GPT-Modelle können das Web durchsuchen. Rela bittet nie um
eine Suche, solange ein anderes Modell gewählt ist, etwa Gemini oder ein
selbst gehostetes Modell (Ollama oder ein OpenAI-kompatibler Endpunkt). Wählen
Sie in der Modellauswahl ein Claude- oder GPT-Modell, um ein Unternehmen zu
recherchieren. Siehe [KI-Modell auswählen](/de/help/ai-assistant/choose-an-ai-model).
