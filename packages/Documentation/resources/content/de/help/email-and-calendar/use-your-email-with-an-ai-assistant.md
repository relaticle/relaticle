---
title: E-Mails mit einem KI-Assistenten nutzen
description: Lassen Sie Rela, Claude oder ChatGPT E-Mails lesen, die Sie sehen können, Entwürfe speichern und für Sie senden. Sie genehmigen oder brechen jeden Versand ab.
order: 9
updated: "2026-11-08"
related: [help/email-and-calendar/choose-who-sees-your-email, help/ai-assistant/connect-claude-or-chatgpt, help/ai-assistant/approve-what-the-assistant-changes, help/workspace/manage-members-and-roles]
---

Rela, der integrierte KI-Assistent, kann mit Ihren synchronisierten E-Mails arbeiten.
Das gilt auch für einen verbundenen Assistenten wie Claude oder ChatGPT. Jeder erhält,
was Ihre Rolle im Arbeitsbereich erlaubt, und keiner sieht in Relaticle mehr, als Sie
selbst sehen.

## Rela zu Ihren E-Mails fragen

Rela braucht außer einem verbundenen Postfach keine Einrichtung. Fragen Sie im Chat:
„Was hat Lena zu den Q4-Preisen gesagt?“, „Entwerfen Sie eine Antwort, die den
Donnerstag bestätigt“ oder „Schicken Sie Lena die neue Preisliste per E-Mail“.

- **Lesen.** Rela liest E-Mails in der Freigabestufe, die für Sie gilt. Die Tabelle
  unter [Was der Assistent lesen kann](#was-der-assistent-lesen-kann) gilt auch für
  Rela.
- **Entwürfe.** Rela schlägt einen Entwurf auf einer Karte vor. Klicken Sie auf
  **Entwurf speichern**, und er landet in Ihren **Entwürfen**, wo Sie ihn vor dem
  Senden prüfen.
- **Senden.** Rela schlägt die E-Mail auf einer Karte vor, die das Postfach, jeden
  Empfänger, den Betreff, die Nachricht und Ihre Signatur zeigt. Nichts geht raus,
  bevor Sie auf **E-Mail senden** klicken.

Nach einem Klick auf **E-Mail senden** bleibt fünf Sekunden lang eine Schaltfläche
**Rückgängig** auf dem Bildschirm. Dann geht die E-Mail aus Ihrem eigenen Postfach
raus.

Jede E-Mail bekommt eine eigene Karte und einen eigenen Klick auf **E-Mail senden**.
Schlägt Rela eine E-Mail zusammen mit anderen Änderungen vor, speichert das
Genehmigen des Plans die anderen Änderungen. Jede E-Mail bekommt danach ihre eigene
Karte und wartet auf Sie.

Die Rollen Inhaber, Administrator und Mitglied können über Rela senden. Ein Betrachter
kann lesen und Entwürfe speichern.

## Claude oder ChatGPT verbinden

Ein Konnektor, den Sie vor der Einführung des E-Mail-Zugriffs hinzugefügt haben, hat
keinen, bis Sie ihn erneut verbinden.

### Einem Konnektor E-Mail-Zugriff geben

1. Ist der Assistent bereits verbunden, öffnen Sie **Zugriffstoken** im Menü Ihres
   Avatars, suchen Sie ihn unter **KI-Konnektoren** und klicken Sie auf
   **Widerrufen**.
2. Fügen Sie Relaticle dem Assistenten erneut hinzu. Die Schritte stehen unter
   [Claude oder ChatGPT mit Relaticle verbinden](/de/help/ai-assistant/connect-claude-or-chatgpt).
3. Wählen Sie auf dem Zustimmungsbildschirm von Relaticle den Arbeitsbereich.
4. Lesen Sie die Liste der Dinge, die der Assistent tun darf, und klicken Sie auf
   **Autorisieren**.

Die Liste richtet sich nach Ihrer Rolle im gewählten Arbeitsbereich.

| Auf der Liste | Was der Assistent tun kann | Wer es erhält |
|---|---|---|
| **E-Mails lesen, die Sie in diesem Arbeitsbereich sehen können** | E-Mails lesen, die Sie in diesem Arbeitsbereich bereits sehen. | Jede Rolle |
| **E-Mail-Entwürfe speichern, die Sie prüfen können** | Einen Entwurf in Ihre Entwürfe schreiben. Es wird nichts gesendet. | Jede Rolle |
| **E-Mails in Ihrem Namen senden, nach einer Wartezeit, die Sie abbrechen können** | Von einem Postfach senden, das Sie verbunden haben, nach einer Wartezeit von fünf Minuten. | Inhaber, Administrator, Mitglied |

Ein Konnektor erhält alles, was auf der Liste steht. Um einem Assistenten weniger zu
geben, verwenden Sie ein Zugriffstoken.

### Für ein Zugriffstoken aktivieren

Öffnen Sie **Zugriffstoken** im Menü Ihres Avatars. Aktivieren Sie beim Erstellen
eines Tokens **E-Mails lesen**, **E-Mails entwerfen** oder **E-Mails senden**. Bei
einem vorhandenen Token klicken Sie auf das Symbol **Berechtigungen**, ändern die
Kästchen und speichern.

## Was der Assistent lesen kann

Der Assistent liest eine E-Mail in der Freigabestufe, die diese E-Mail für Sie hat.
Die Namen der Stufen stehen unter
[Festlegen, wer Ihre E-Mails sieht](/de/help/email-and-calendar/choose-who-sees-your-email).

| Freigabestufe | Was der Assistent erhält |
|---|---|
| Ihre eigenen E-Mails | Alles, einschließlich BCC-Empfängern. |
| **Vollzugriff** | Den Absender, die Empfänger in An und CC, den Betreff, den Nachrichtentext und die Namen der Anhänge. |
| **Betreffzeile und Teilnehmer** | Den Absender, die Empfänger in An, den Betreff und den Zeitpunkt. |
| **Nur Teilnehmer** | Den Absender, die Empfänger in An und den Zeitpunkt. |
| **Privat** | Nichts. Die E-Mail erscheint nicht. |

Eine sehr lange Nachricht wird gekürzt, und der Assistent erfährt davon. Den Inhalt
eines Anhangs erhält er nie.

Es erscheinen nur zugestellte E-Mails. Entwürfe und E-Mails, die auf den Versand
warten, erscheinen nicht. Interne Unterhaltungen anderer Mitglieder bleiben
ausgeblendet. Fragt der Assistent nach E-Mails einer geschützten oder gesperrten
Person oder eines solchen Unternehmens, bekommt er nichts zurück.

Zeigt eine E-Mail weniger, als der Assistent braucht, können Sie
[das Mitglied um mehr Zugriff bitten](/de/help/email-and-calendar/request-access-to-an-email).

## Entwürfe und Senden

Der Assistent schreibt Klartext oder Markdown, und Relaticle macht daraus die E-Mail.
Die E-Mail enthält keine Bilder und keine Anhänge. Die Standardsignatur Ihres
Postfachs wird hinzugefügt, es sei denn, der Assistent lässt sie weg. Ein Entwurf
landet in Ihren **Entwürfen**, und Sie prüfen ihn vor dem Senden.

Eine E-Mail, die ein verbundener Assistent sendet, wartet fünf Minuten, bevor sie
rausgeht. Sie wird aus einem Postfach gesendet, das Sie selbst verbunden haben, nie
aus dem eines anderen Mitglieds. Bei Rela gibt es keine Wartezeit von fünf Minuten,
weil Sie jede E-Mail vorher auf ihrer Karte genehmigen.

1. Sie erhalten eine Benachrichtigung wie „Claude hat eine E-Mail eingeplant“. Sie
   nennt den Betreff und die Empfänger.
2. Klicken Sie in dieser Benachrichtigung auf **Senden abbrechen**, um den Versand zu
   stoppen.
3. Sie können auch die Seite **E-Mails** und dort **Postausgang** öffnen und die
   E-Mail dort abbrechen.

Nach fünf Minuten geht die E-Mail raus und lässt sich nicht mehr abbrechen. Danach
erscheint sie wie jede andere in Ihren synchronisierten E-Mails.

Die Rollen Inhaber, Administrator und Mitglied können über einen Assistenten senden.
Ein Betrachter nicht. Wechselt Ihre Rolle zu Betrachter, bevor eine eingeplante
E-Mail rausgeht, wird sie nicht gesendet. Lesen Sie dazu
[Mitglieder und Rollen verwalten](/de/help/workspace/manage-members-and-roles).

## Ausschalten

Bei einem Konnektor klicken Sie unter **KI-Konnektoren** auf **Widerrufen**. E-Mails,
die er eingeplant hat und die noch nicht rausgegangen sind, werden nicht gesendet.
Sie warten im **Postausgang** unter **Fehlgeschlagen**, wo Sie eine selbst mit
**Erneut versuchen** senden können. Ein Konnektor hat keine eigene Einstellung nur für
E-Mails. Ein Assistent, der Ihre Einträge behalten soll, aber nicht Ihre E-Mails,
braucht deshalb ein Zugriffstoken mit leeren E-Mail-Kästchen. Bei einem Zugriffstoken
klicken Sie auf das Symbol **Berechtigungen** und deaktivieren die E-Mail-Kästchen.

## Bevor Sie das Senden erlauben

Ein Assistent handelt nach dem, was er liest, und E-Mail-Texte stammen von externen
Absendern. Erlauben Sie das Senden nur einem Assistenten, dem Sie vertrauen, und
brechen Sie alles ab, worum Sie nicht gebeten haben.
