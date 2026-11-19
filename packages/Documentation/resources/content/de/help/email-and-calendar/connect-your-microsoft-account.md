---
title: Ihr Microsoft-Postfach verbinden
description: Verknüpfen Sie ein Outlook- oder Microsoft-365-Postfach für die Synchronisierung von E-Mails und Kalender und sehen Sie, welchen Zugriff Relaticle anfragt.
order: 2
updated: "2026-11-08"
related: [help/email-and-calendar/connect-your-google-account, help/email-and-calendar/read-and-reply-to-email, help/email-and-calendar/choose-who-sees-your-email, help/email-and-calendar/see-meetings-and-link-them-to-records]
---

Wenn Sie ein Microsoft-Postfach verbinden, kommen ein Outlook- oder
Microsoft-365-Postfach und sein Kalender in Relaticle an. E-Mails und Meetings landen
an den Einträgen, um die es darin geht, und Sie können aus Relaticle mit dieser
Adresse senden.

## Postfach verbinden

1. Öffnen Sie oben in der Seitenleiste das Menü des Arbeitsbereichs und wählen Sie
   **Einstellungen des Arbeitsbereichs**.
2. Öffnen Sie den Tab **E-Mail und Kalender**. Er öffnet sich auf **Postfächer**.
3. Klicken Sie auf **Mit Microsoft verbinden**.
4. Melden Sie sich bei Microsoft an und bestätigen Sie die aufgelisteten
   Zugriffsrechte.
5. Microsoft leitet Sie zurück zu Relaticle, und das Postfach erscheint unter
   **Verbundene Postfächer**.

Die Seite **E-Mails**, der Tab **E-Mails** eines Eintrags und das Panel **Meetings**
auf der Seite **Start** zeigen dieselbe Schaltfläche, bis Sie ein Postfach verbinden.

![Der Tab Postfächer unter E-Mail und Kalender mit der Schaltfläche Mit Microsoft verbinden neben Mit Google verbinden unter den verbundenen Postfächern](/help-assets/email-and-calendar/connect-your-google-account-1.png)

Bei einer selbst gehosteten Installation erscheint die Schaltfläche, sobald der
Microsoft-Client konfiguriert ist. Die Einstellungen finden Sie im
[Self-Hosting-Leitfaden](/de/developers/self-hosting).

## Was Relaticle bei Microsoft anfragt

- **E-Mails lesen**, damit Nachrichten an Ihren Einträgen erscheinen können.
- **E-Mails in Ihrem Namen senden**, damit alles, was Sie in Relaticle schreiben, von
  Ihrer Adresse aus verschickt wird.
- **Kalender lesen und aktualisieren**, damit Meetings erscheinen und Ihre Zu- oder
  Absage in Ihrem Kalender ankommt.
- **Ihr Profil lesen**, damit Relaticle weiß, welche Adresse verbunden wurde.
- **Diesen Zugriff behalten**, damit die Synchronisierung weiterläuft, während Sie bei
  Microsoft abgemeldet sind.

Relaticle ändert, verschiebt oder löscht niemals Nachrichten in Ihrem Postfach und
importiert nie Ihre Entwürfe. In Ihrem Kalender ändert es nur Ihre eigene Antwort auf
eine Einladung.

## Welche Ordner synchronisiert werden

Relaticle synchronisiert jeden E-Mail-Ordner, auch Ordner im Posteingang und Ordner,
in die eine Regel E-Mails verschiebt. Nicht berücksichtigt werden **Entwürfe**,
**Junk-E-Mail**, **Gelöschte Elemente**, **Postausgang**, **Clutter** und
**Unterhaltungsverlauf**, einschließlich aller Ordner darin.

## Was nach dem Verbinden passiert

Das Postfach gehört zu dem Arbeitsbereich, in dem Sie es verbunden haben. Relaticle
importiert Ihr Postfach und Ihren Kalender im Hintergrund, und das Postfach zeigt
**Synchronisierung läuft** mit einem Prozentwert, bis dort **Synchron** steht.

Wie viel von jeder E-Mail Ihr Arbeitsbereich sieht, hängt von der Freigabestufe des
Postfachs ab. [Legen Sie fest, wer Ihre E-Mails sieht](/de/help/email-and-calendar/choose-who-sees-your-email),
bevor Sie ein Postfach verbinden, das Sie für sensibel halten.

Ein Microsoft-Postfach hat dasselbe Menü wie ein Google-Postfach. Unter
[Ein verbundenes Postfach verwalten](/de/help/email-and-calendar/connect-your-google-account#ein-verbundenes-postfach-verwalten)
finden Sie die Einstellungen, **Als Standard festlegen** und **Postfach trennen**.

## Wenn Microsoft die Zustimmung eines Administrators verlangt

In manchen Organisationen darf nur ein Administrator eine neue App freigeben. Bitten
Sie Ihren Microsoft-365-Administrator, Relaticle freizugeben, und verbinden Sie das
Postfach dann erneut.

## Wenn das Postfach „Neu verbinden erforderlich“ zeigt

Microsoft akzeptiert den gespeicherten Zugriff nicht mehr. Öffnen Sie das Menü des
Postfachs, klicken Sie auf **Neu verbinden**, melden Sie sich an und bestätigen Sie
den Zugriff erneut.

## Nach dem Trennen

**Postfach trennen** beendet die Synchronisierung und löscht die Zugriffstoken, die
Relaticle speichert. Microsoft führt Relaticle weiterhin unter den Apps auf, die Sie
freigegeben haben. Entfernen Sie die App in Ihren Microsoft-Kontoeinstellungen, um
diese Freigabe zu beenden.
