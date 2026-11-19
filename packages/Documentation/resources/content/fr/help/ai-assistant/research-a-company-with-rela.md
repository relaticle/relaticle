---
title: Rechercher une entreprise avec Rela
description: Laissez Rela chercher sur le web une entreprise ou une personne absente de votre espace de travail, et choisissez quand il peut le faire.
order: 6
updated: "2026-11-04"
related: [help/ai-assistant/approve-what-the-assistant-changes, help/ai-assistant/ai-credits-and-limits, help/ai-assistant/choose-an-ai-model]
---

Rela, l'assistant IA intégré, répond d'abord à partir de votre espace de
travail. Quand une question demande quelque chose que vos enregistrements ne
contiennent pas, comme ce qu'une entreprise vend ou qui l'a fondée, Rela peut
chercher sur le web et lire les pages qu'il trouve.

## Comment fonctionne une demande de recherche

Demandez en mots simples, par exemple « Recherche Filaforms et dis-moi s'ils
pourraient nous convenir ». Quand Rela a besoin du web, il demande avant de
chercher. Une carte au-dessus de la zone de message montre ce qu'il veut
chercher et pourquoi, avec trois boutons :

- **Autoriser** laisse Rela chercher sur le web pour cette seule demande. Rela vous redemandera à votre prochain message.
- **Toujours autoriser** fait la même chose et cesse de demander à partir de là.
- **Refuser** garde Rela sur les données de votre espace de travail. Il répond à partir de vos enregistrements et précise qu'il n'a pas utilisé le web.

Pendant que Rela travaille, le chat montre ce qu'il cherche et quelle page il
lit. La réponse se termine par une ligne **Sources** : un lien pour chaque page
que Rela a citée, qui s'ouvre dans un nouvel onglet.

Rela n'écrit jamais de lui-même ce qu'il a trouvé dans vos enregistrements.
Quand il suggère d'ajouter une note ou de renseigner un champ, vous approuvez ce
changement comme n'importe quel autre. Voir
[Approuver les changements de l'assistant](/fr/help/ai-assistant/approve-what-the-assistant-changes).

## Choisir quand Rela peut chercher

Ouvrez **Paramètres** depuis le menu de votre avatar, puis **Rela**, et sous **Recherche web** choisissez une option :

| Choix | Ce qui se passe |
|--------|--------------|
| Toujours demander | Rela demande votre accord avant chaque recherche. C'est le choix par défaut. |
| Toujours autoriser | Rela cherche sans demander. |
| Ne pas autoriser | Rela ne cherche jamais sur le web et répond uniquement à partir de votre espace de travail. |

Ce choix n'appartient qu'à vous. Vos collègues choisissent le leur.

## Désactiver la recherche web pour tout l'espace de travail

Le propriétaire de l'espace de travail peut désactiver la recherche web pour
tous les membres dans **Paramètres de l'espace de travail** (cliquez sur le nom de votre
espace de travail dans la barre latérale), dans la section **Rela**. Tant
qu'elle est désactivée, Rela ne demande jamais à chercher, et le réglage de
chaque membre indique que le propriétaire l'a désactivée.

## Ce qui quitte votre espace de travail

Rela rédige lui-même les requêtes de recherche, et elles partent vers la
recherche web du fournisseur d'IA : Anthropic pour les modèles Claude, OpenAI
pour les modèles GPT. Une requête peut nommer une entreprise ou une personne
issue de votre demande. Les pages que Rela lit sont récupérées par le même
fournisseur, qui traite déjà chaque message que vous envoyez à Rela.

Les pages du web ne sont pas des instructions. Rela traite ce qu'il lit comme de
l'information, et tout changement qu'il suggère attend toujours votre
approbation.

## Ce que coûte une recherche

Sur Relaticle Cloud, chaque recherche web consomme 2 crédits en plus du coût
normal du message. Lire une page compte comme n'importe quel autre outil. Voir
[Crédits d'IA et limites de débit](/fr/help/ai-assistant/ai-credits-and-limits).

## Quels modèles peuvent chercher

Seuls les modèles Claude et GPT peuvent chercher sur le web. Rela ne demande
jamais à chercher tant qu'un autre modèle est sélectionné, comme Gemini ou un
modèle auto-hébergé (Ollama ou un point d'accès compatible OpenAI). Choisissez
un modèle Claude ou GPT dans le sélecteur de modèle pour rechercher une
entreprise. Voir [Choisir un modèle d'IA](/fr/help/ai-assistant/choose-an-ai-model).
