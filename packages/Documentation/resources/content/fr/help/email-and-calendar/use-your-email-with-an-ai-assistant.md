---
title: Utiliser vos e-mails avec un assistant IA
description: Laissez Rela, Claude ou ChatGPT lire les e-mails que vous voyez, enregistrer des brouillons et envoyer pour vous. Vous approuvez ou annulez chaque envoi.
order: 9
updated: "2026-11-08"
related: [help/email-and-calendar/choose-who-sees-your-email, help/ai-assistant/connect-claude-or-chatgpt, help/ai-assistant/approve-what-the-assistant-changes, help/workspace/manage-members-and-roles]
---

Rela, l'assistant IA intégré, peut travailler avec vos e-mails synchronisés. Un
assistant connecté comme Claude ou ChatGPT le peut aussi. Chacun obtient ce que
votre rôle dans l'espace de travail permet, et aucun ne voit plus que vous dans
Relaticle.

## Interroger Rela sur vos e-mails

Rela n'a besoin d'aucune configuration au-delà d'une boîte mail connectée.
Interrogez-le dans le chat : « qu'a dit Lena sur les tarifs du T4 ? », « rédige une
réponse qui confirme jeudi » ou « envoie à Lena la nouvelle liste de prix ».

- **Lecture.** Rela lit un e-mail au niveau de partage qu'il a pour vous. Le tableau
  sous [Ce que l'assistant peut lire](#ce-que-lassistant-peut-lire) s'applique aussi
  à Rela.
- **Brouillons.** Rela propose un brouillon sur une carte. Cliquez sur **Enregistrer
  le brouillon** : il va dans vos **Brouillons**, où vous le relisez avant
  d'envoyer.
- **Envoi.** Rela propose l'e-mail sur une carte qui montre la boîte mail, chaque
  destinataire, l'objet, le message et votre signature. Rien ne part avant que vous
  cliquiez sur **Envoyer l'e-mail**.

Après votre clic sur **Envoyer l'e-mail**, un bouton **Annuler** reste affiché
pendant cinq secondes. Ensuite l'e-mail part de votre propre boîte mail.

Chaque e-mail a sa propre carte et son propre clic sur **Envoyer l'e-mail**. Quand
Rela propose un e-mail avec d'autres modifications, approuver le plan enregistre les
autres modifications, puis chaque e-mail passe sur sa propre carte et attend votre
accord.

Les rôles Propriétaire, Administrateur et Membre peuvent envoyer via Rela. Un Lecteur
peut lire et enregistrer des brouillons.

## Connecter Claude ou ChatGPT

Un connecteur ajouté avant l'arrivée de l'accès aux e-mails n'en a aucun tant que
vous ne le reconnectez pas.

### Donner l'accès aux e-mails à un connecteur

1. Si l'assistant est déjà connecté, ouvrez **Jetons d'accès** depuis le menu de
   votre avatar, trouvez-le sous **Connecteurs IA** et cliquez sur **Révoquer**.
2. Ajoutez à nouveau Relaticle à l'assistant. Les étapes sont dans
   [Connecter Claude ou ChatGPT à Relaticle](/fr/help/ai-assistant/connect-claude-or-chatgpt).
3. Sur l'écran de consentement de Relaticle, choisissez l'espace de travail.
4. Lisez la liste de ce que l'assistant pourra faire, puis cliquez sur
   **Autoriser**.

La liste suit votre rôle dans l'espace de travail que vous avez choisi.

| Sur la liste | Ce que l'assistant peut faire | Qui l'obtient |
|---|---|---|
| **Lire les e-mails que vous pouvez voir** | Lire les e-mails que vous pouvez déjà voir dans cet espace de travail. | Tous les rôles |
| **Enregistrer des brouillons d'e-mail** | Écrire un brouillon dans vos Brouillons. Rien n'est envoyé. | Tous les rôles |
| **Envoyer des e-mails en votre nom** | Envoyer depuis une boîte mail que vous avez connectée, après un délai de cinq minutes. | Propriétaire, Administrateur, Membre |

Un connecteur obtient tout ce qui figure sur la liste. Pour donner moins à un
assistant, utilisez un jeton d'accès.

### L'activer pour un jeton d'accès

Ouvrez **Jetons d'accès** depuis le menu de votre avatar. Cochez **Lire les
e-mails**, **Rédiger des brouillons d'e-mail** ou **Envoyer des e-mails** quand vous
créez un jeton. Pour un jeton existant, cliquez sur son icône **Autorisations**,
modifiez les cases et enregistrez.

## Ce que l'assistant peut lire

L'assistant lit un e-mail au niveau de partage que cet e-mail a pour vous. Les noms
des niveaux sont ceux de
[Choisir qui voit vos e-mails](/fr/help/email-and-calendar/choose-who-sees-your-email).

| Niveau de partage | Ce que l'assistant obtient |
|---|---|
| Vos propres e-mails | Tout, y compris les destinataires en Cci. |
| **Accès complet** | L'expéditeur, les destinataires À et Cc, l'objet, le texte du message et les noms des pièces jointes. |
| **Objet et participants** | L'expéditeur, les destinataires À, l'objet et l'heure. |
| **Participants uniquement** | L'expéditeur, les destinataires À et l'heure. |
| **Privé** | Rien. L'e-mail n'apparaît pas. |

Un message très long est tronqué, et l'assistant est prévenu qu'il l'a été. Il ne
reçoit jamais le contenu d'une pièce jointe.

Seuls les e-mails délivrés apparaissent. Les brouillons et les e-mails en attente
d'envoi n'apparaissent pas. Les conversations internes d'un membre restent masquées.
Si vous demandez les e-mails d'une personne ou d'une entreprise protégée ou
bloquée, l'assistant n'obtient rien.

Quand un e-mail en montre moins que ce dont l'assistant a besoin, vous pouvez
[demander plus d'accès au membre](/fr/help/email-and-calendar/request-access-to-an-email).

## Brouillons et envoi

L'assistant écrit en texte brut ou en markdown, et Relaticle le transforme en e-mail.
L'e-mail ne contient ni image ni pièce jointe. La signature par défaut de votre
boîte mail est ajoutée, sauf si l'assistant l'omet. Un brouillon va dans vos
**Brouillons**, et vous le relisez avant d'envoyer.

Un e-mail envoyé par un assistant connecté attend cinq minutes avant de partir. Il
part d'une boîte mail que vous avez connectée vous-même, jamais de celle d'un
membre. Rela n'a pas de délai de cinq minutes, car vous approuvez d'abord chaque
e-mail sur sa carte.

1. Vous recevez une notification, par exemple « Claude a mis un e-mail en file
   d'attente ». Elle indique l'objet et les destinataires.
2. Cliquez sur **Annuler l'envoi** dans cette notification pour l'arrêter.
3. Vous pouvez aussi ouvrir la page **E-mails**, puis **Boîte d'envoi**, et annuler
   l'e-mail depuis là.

Après cinq minutes, l'e-mail part et ne peut plus être annulé. Il apparaît ensuite
dans vos e-mails synchronisés comme n'importe quel autre.

Les rôles Propriétaire, Administrateur et Membre peuvent envoyer via un assistant.
Un Lecteur ne le peut pas. Si votre rôle devient Lecteur avant le départ d'un
e-mail en file d'attente, il n'est pas envoyé. Consultez
[Gérer les membres et les rôles](/fr/help/workspace/manage-members-and-roles).

## Le désactiver

Pour un connecteur, cliquez sur **Révoquer** sous **Connecteurs IA**. Les e-mails
qu'il avait mis en file d'attente et qui ne sont pas partis ne sont pas envoyés. Ils
attendent dans la **Boîte d'envoi**, sous **Échoués**, où vous pouvez en envoyer un
vous-même avec **Réessayer**. Un connecteur n'a pas de réglage propre aux e-mails :
un assistant qui doit gérer vos enregistrements sans vos e-mails a donc besoin d'un
jeton d'accès dont les cases e-mail sont vides. Pour un jeton d'accès, cliquez sur
son icône **Autorisations** et décochez les cases e-mail.

## Avant d'accorder l'envoi

Un assistant agit sur ce qu'il lit, et le texte d'un e-mail vient d'expéditeurs
extérieurs. N'accordez l'envoi qu'à un assistant de confiance, et annulez tout ce
que vous n'avez pas demandé.
