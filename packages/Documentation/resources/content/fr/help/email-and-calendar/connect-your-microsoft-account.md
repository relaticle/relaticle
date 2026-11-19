---
title: Connecter votre boîte mail Microsoft
description: Reliez une boîte Outlook ou Microsoft 365 pour synchroniser ses e-mails et son agenda dans Relaticle, et voyez les accès demandés à Microsoft.
order: 2
updated: "2026-11-08"
related: [help/email-and-calendar/connect-your-google-account, help/email-and-calendar/read-and-reply-to-email, help/email-and-calendar/choose-who-sees-your-email, help/email-and-calendar/see-meetings-and-link-them-to-records]
---

Connecter une boîte mail Microsoft amène une boîte Outlook ou Microsoft 365 et son
agenda dans Relaticle. Les e-mails et les réunions arrivent sur les enregistrements
concernés, et vous pouvez envoyer depuis Relaticle avec cette adresse.

## La connecter

1. Ouvrez le menu de l'espace de travail en haut de la barre latérale et choisissez
   **Paramètres de l'espace de travail**.
2. Ouvrez l'onglet **E-mail et agenda**. Il s'ouvre sur **Boîtes mail**.
3. Cliquez sur **Connecter une boîte mail Microsoft**.
4. Connectez-vous chez Microsoft et acceptez les accès demandés.
5. Microsoft vous renvoie vers Relaticle, et la boîte mail apparaît sous
   **Boîtes mail connectées**.

La page **E-mails**, l'onglet **E-mails** d'un enregistrement et le panneau
**Réunions** de la page **Accueil** affichent le même bouton tant que vous n'avez connecté
aucune boîte mail.

![L'onglet Boîtes mail sous E-mail et agenda, avec le bouton Connecter une boîte mail Microsoft à côté de Connecter une boîte mail Google, sous les boîtes mail connectées](/help-assets/email-and-calendar/connect-your-google-account-1.png)

Sur une installation auto-hébergée, le bouton apparaît une fois le client Microsoft
configuré. Le [Guide d'auto-hébergement](/fr/developers/self-hosting) liste les
paramètres.

## Ce que Relaticle demande à Microsoft

- **Lire votre courrier**, pour que les messages puissent apparaître sur vos
  enregistrements.
- **Envoyer du courrier en votre nom**, pour que ce que vous écrivez dans Relaticle
  parte de votre adresse.
- **Lire et mettre à jour votre agenda**, pour que les réunions apparaissent et que
  votre réponse arrive dans votre agenda.
- **Lire votre profil**, pour que Relaticle sache quelle adresse il a connectée.
- **Conserver cet accès**, pour que la synchronisation continue pendant que vous
  êtes déconnecté de Microsoft.

Relaticle ne modifie, ne déplace ni ne supprime jamais les messages de votre boîte
mail, et il n'importe jamais vos brouillons. Dans votre agenda, il modifie
uniquement votre propre réponse à une invitation.

## Quels dossiers sont synchronisés

Relaticle synchronise tous les dossiers de courrier, y compris les dossiers dans
votre boîte de réception et ceux où une règle déplace le courrier. Il exclut
**Brouillons**, **Courrier indésirable**, **Éléments supprimés**, **Boîte d'envoi**,
**Courrier pêle-mêle** et **Historique des conversations**, ainsi que tout dossier qu'ils
contiennent.

## Ce qui se passe après la connexion

La boîte mail appartient à l'espace de travail où vous l'avez connectée. Relaticle
importe votre boîte mail et votre agenda en arrière-plan, et la boîte mail affiche
**Synchronisation** avec un pourcentage jusqu'à ce qu'elle indique **Synchronisé**.

Ce que votre espace de travail voit de chaque e-mail dépend du niveau de partage de
la boîte mail.
[Choisissez qui voit vos e-mails](/fr/help/email-and-calendar/choose-who-sees-your-email)
avant de connecter une boîte mail que vous jugez sensible.

Une boîte mail Microsoft a le même menu qu'une boîte mail Google. Consultez
[Gérer une boîte mail connectée](/fr/help/email-and-calendar/connect-your-google-account#gérer-une-boîte-mail-connectée)
pour ses paramètres, **Définir par défaut** et **Déconnecter la boîte mail**.

## Si Microsoft demande l'approbation d'un administrateur

Dans certaines organisations, seul un administrateur peut approuver une nouvelle
application. Demandez à votre administrateur Microsoft 365 d'approuver Relaticle,
puis connectez à nouveau la boîte mail.

## Si la boîte mail affiche Reconnexion nécessaire

Microsoft n'accepte plus l'accès enregistré. Ouvrez le menu de la boîte mail et
cliquez sur **Reconnecter**, puis connectez-vous et acceptez à nouveau les accès.

## Après la déconnexion

**Déconnecter la boîte mail** arrête la synchronisation et supprime les jetons
d'accès que Relaticle détient. Microsoft liste toujours Relaticle parmi les
applications que vous avez approuvées. Retirez-le dans les paramètres de votre
compte Microsoft pour mettre fin à cette approbation.
