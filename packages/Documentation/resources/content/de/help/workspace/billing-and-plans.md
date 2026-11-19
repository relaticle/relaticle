---
title: Abrechnung und Tarife
description: Relaticle Cloud wird pro Arbeitsbereich abgerechnet, nie pro Platz. Testphase, Abonnement, Credits und Pausieren erklärt.
order: 3
updated: "2026-11-08"
related: [help/ai-assistant/ai-credits-and-limits, help/workspace/rename-or-delete-your-workspace]
---

Relaticle Cloud wird **pro Arbeitsbereich abgerechnet, nie pro Platz**. Sie können also
Ihr ganzes Team einladen, ohne dass sich der Preis ändert. Die Seite **Abrechnung**
finden Sie unter dem Namen Ihres Arbeitsbereichs in der Seitenleiste. Aktionen zum
Abonnement liegen dort beim Inhaber des Arbeitsbereichs, andere Mitglieder sehen, wen
sie fragen können.

## Was Cloud Pro kostet

Cloud Pro ist der Einstiegstarif: unbegrenzt viele Mitglieder und Einträge, 2.000
KI-Credits im Monat, Premium-KI-Modelle, bis zu 10 verbundene Postfächer, die
REST-API und der MCP-Server sowie Support per E-Mail. Er kostet **$19/Monat pro
Arbeitsbereich bei jährlicher Abrechnung** ($228 im Jahr) oder **$24/Monat bei
monatlicher Abrechnung**. Für größere Einführungen beginnt die Enterprise-Umsetzung
bei $20.000 im Jahr. Die [Preisseite](/de/pricing) nennt die Details.

## Was Cloud Scale kostet

Cloud Scale ist für Teams, die den ganzen Tag mit Relaticle arbeiten. Es enthält alles
aus Cloud Pro, erhöht das Kontingent auf 5.000 KI-Credits im Monat, erlaubt bis zu 50
verbundene Postfächer, verdreifacht den Durchsatz von REST-API und MCP-Server und
bringt vorrangigen Support mit. Es kostet **$49/Monat pro Arbeitsbereich bei jährlicher
Abrechnung** ($588 im Jahr) oder **$59/Monat bei monatlicher Abrechnung**. Zwischen Pro
und Scale wechseln Sie auf der Seite Abrechnung. Stripe rechnet die Änderung anteilig
ab.

## Die Testphase

Ihr erster Cloud-Arbeitsbereich startet **automatisch eine 14-tägige Testphase für
Pro, ohne Karte**. Ein später hinzugefügter Arbeitsbereich startet nur dann eine
eigene Testphase, wenn Sie in einem anderen Arbeitsbereich ein Abonnement haben. Andernfalls
öffnet er sich auf der Tarifauswahl. Die Testphase umfasst alles aus Cloud Pro, mit
300 KI-Credits und einem verbundenen Postfach. Premium-KI-Modelle werden frei, sobald
der Arbeitsbereich einen eigenen Eintrag enthält. Schließen Sie während der Testphase
ein Abonnement ab, gilt das volle Kontingent des Tarifs sofort.
Wenn die Testphase endet, schließen Sie auf der Seite Abrechnung ein Abonnement ab, um den
Cloud-Zugriff zu behalten. Ohne Abonnement wird der Arbeitsbereich pausiert. Brauchen
Sie mehr Zeit, kann der Inhaber die Testphase einmalig um 7 Tage verlängern, auf der
Seite Abrechnung. KI-Credits werden während der Verlängerung nicht aufgefüllt.
Relaticle Cloud hat keinen kostenlosen Tarif.

Einen pausierten Arbeitsbereich aus der Testphase bewahren wir 60 Tage nach der
letzten Anmeldung auf. Meldet sich in dieser Zeit niemand an und wählt niemand einen
Tarif, löschen wir ihn samt seinen Einträgen. Zuvor schreiben wir dem Inhaber zweimal
per E-Mail.

## Das Abonnement verwalten

**Abonnement verwalten** öffnet das Abrechnungsportal. Dort aktualisieren Sie die
Zahlungsmethode, laden Rechnungen herunter und wechseln zwischen monatlicher und
jährlicher Abrechnung. Bei einer Kündigung behalten Sie Ihren Tarif bis zum Ende des
bezahlten Zeitraums. Bei Enterprise bezahlen Sie im Portal Rechnungen und
aktualisieren die Zahlungsmethode. Tarifänderungen laufen über uns.

## Wenn das Abonnement ausläuft

Der Arbeitsbereich wird **pausiert**. Die App, die API, der MCP-Server und Rela sind
nicht mehr verfügbar, aber **Ihre Einträge sind sicher** und bleiben gespeichert. Wenn Sie erneut
abonnieren, ist alles genau wie zuvor wiederhergestellt. Ein pausierter Arbeitsbereich
kann vom Inhaber weiterhin gelöscht werden, und die Löschfrist von 30 Tagen gilt wie
gewohnt.

## KI-Credits

Das monatliche Credit-Kontingent des Tarifs wird mit jedem Abrechnungszeitraum
zurückgesetzt. Bezahlte Arbeitsbereiche können auf der Seite Abrechnung einmalige
**Credit-Pakete** kaufen. Gekaufte Credits werden erst nach dem monatlichen Kontingent
verbraucht und verfallen nie. Dieselbe Seite zeigt, wie viele Credits der
Arbeitsbereich in diesem Zeitraum verbraucht hat. Was Credits sind und was Nachrichten
kosten, erklärt
[KI-Credits und Ratenlimits](/de/help/ai-assistant/ai-credits-and-limits).

## Stattdessen selbst hosten

Relaticle ist Open Source (AGPL-3.0). Selbst gehostete Installationen sind für immer
kostenlos, ohne Abonnement und ohne Pausieren des Arbeitsbereichs, und die KI läuft
mit Ihren eigenen Anbieter-Schlüsseln. Der Assistent misst dort keine Credits. Es gilt
nur ein Sendelimit pro Minute, das standardmäßig bei 60 liegt und das der Betreiber
ändern kann. Lesen Sie den [Self-Hosting-Leitfaden](/de/developers/self-hosting).
