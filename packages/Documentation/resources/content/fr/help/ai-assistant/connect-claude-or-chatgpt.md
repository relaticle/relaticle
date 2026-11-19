---
title: Connecter Claude ou ChatGPT à Relaticle
description: Configurez un connecteur IA vers Relaticle avec OAuth. Ce guide explique les accès et pourquoi les clients MCP écrivent directement dans les enregistrements.
order: 5
updated: "2026-10-05"
related: [docs/guides/mcp, help/ai-assistant/ask-questions-about-your-data, help/ai-assistant/ai-credits-and-limits]
---

Les assistants que vous utilisez déjà, Claude et ChatGPT, peuvent travailler
directement avec vos données Relaticle. Vous installez le plugin Relaticle dans
ChatGPT, ou vous ajoutez Relaticle comme **connecteur** dans Claude, vous
approuvez l'accès une fois, puis vous pouvez interroger votre assistant sur
votre pipeline, ou lui faire créer et modifier des enregistrements, depuis son
propre chat.

Pas de code, pas de clés d'API : toute la configuration tient dans un écran de
consentement.

## Le connecter

1. Ajoutez Relaticle à votre assistant :
   - **ChatGPT :** ouvrez le
     [plugin Relaticle](https://chatgpt.com/plugins/plugin_asdk_app_6a92c3af04a0819180ed6652ebe09961)
     dans le répertoire de plugins de ChatGPT et cliquez sur **Install plugin**.
   - **Claude :** dans **Settings → Connectors**, ajoutez un connecteur
     personnalisé avec l'URL `https://mcp.relaticle.com`.
2. L'assistant vous envoie vers Relaticle pour vous connecter, si ce n'est pas
   déjà fait.
3. Approuvez l'accès et **choisissez l'espace de travail** que le connecteur
   peut utiliser.
4. C'est tout. Demandez quelque chose à votre assistant : « qu'y a-t-il dans
   mon pipeline ce mois-ci ? » Dans ChatGPT, commencez le message par
   **@Relaticle**.

Le connecteur agit comme **vous**, dans **cet unique espace de travail** : il
peut faire ce que vous y faites, et rien d'autre. Pour orienter un assistant
vers un autre espace de travail, révoquez le connecteur et reconnectez-le.

## Ce que votre assistant peut faire

Rechercher et lire des entreprises, personnes, opportunités, tâches et notes,
les créer, les modifier et les supprimer, rattacher des tâches et des notes à
des enregistrements, et lire les attributs personnalisés de votre espace de
travail pour que ses réponses reposent sur votre vrai schéma.

Un point à connaître : les changements faits via un connecteur sont appliqués
directement. La carte de relecture que vous obtenez dans le chat intégré de
Relaticle n'existe pas ici, fiez-vous donc aux demandes de confirmation de votre
assistant avant qu'il agisse. L'activité d'un connecteur ne consomme jamais les
[crédits d'IA](/fr/help/ai-assistant/ai-credits-and-limits) de votre espace de
travail, qui ne servent qu'à Rela, l'assistant intégré.

## Voir et révoquer les connexions

Ouvrez **Paramètres → Jetons d'accès** depuis le menu de votre avatar. La
section **Connecteurs IA** liste chaque assistant connecté à vos espaces de
travail, l'espace de travail auquel chacun est lié et le nombre de jetons
actifs qu'il détient. **Révoquer** coupe l'accès d'un assistant immédiatement et
arrête tout e-mail qu'il avait mis en file d'attente et qui n'est pas encore
parti. Vous pouvez toujours le reconnecter plus tard.

![La liste des connecteurs IA montrant Claude et ChatGPT liés à un espace de travail, avec le nombre de jetons actifs et un bouton de révocation par ligne](/help-assets/ai-assistant/connect-claude-or-chatgpt-1.png)

## Bon à savoir

- Fonctionne avec un espace de travail Relaticle Cloud actif. Un espace de
  travail en pause ne peut pas être sélectionné sur l'écran de consentement tant
  que son abonnement n'est pas réactivé.
- Claude et ChatGPT sont aujourd'hui les assistants dotés d'un parcours de
  connecteur intégré, mais le serveur parle le standard ouvert MCP : tout autre
  outil compatible MCP peut donc utiliser la même URL avec un jeton d'accès
  personnel, comme les développeurs connectent Cursor (voir le
  [guide du MCP server](/fr/developers/mcp)).
- Les installations auto-hébergées incluent le même serveur de connecteurs sur
  votre propre domaine. Le [guide du MCP server](/fr/developers/mcp) couvre la
  configuration.
- L'accès se renouvelle tout seul en arrière-plan. Vous ne revoyez l'écran de
  consentement que si vous révoquez puis reconnectez.
