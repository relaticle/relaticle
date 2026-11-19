---
title: MCP server
description: Référence du MCP server de Relaticle, avec configuration OAuth et jeton d'accès personnel, accès aux attributs personnalisés et écritures directes.
order: 2
updated: "2026-11-09"
---

MCP (Model Context Protocol) permet à des assistants IA comme Claude de travailler directement avec les données de votre CRM Relaticle. Plus besoin de copier-coller d'un outil à l'autre : votre assistant IA peut lister des entreprises, créer des tâches, modifier des personnes, et plus encore, au fil d'une simple conversation.

---

## Ce que vous pouvez faire

Avec le MCP server de Relaticle, votre assistant IA peut :

- **Lister et rechercher** des entreprises, personnes, opportunités, tâches et notes
- **Obtenir un enregistrement** avec tous ses détails et ses relations
- **Créer de nouveaux enregistrements** directement depuis une conversation
- **Modifier des enregistrements existants** : renommer une entreprise, réassigner une tâche
- **Supprimer des enregistrements** dont vous n'avez plus besoin
- **Rattacher ou détacher** des tâches et des notes à des entreprises, des personnes et des opportunités
- **Lire les schémas des entités** pour comprendre vos attributs personnalisés
- **Obtenir une vue d'ensemble du CRM** avec le nombre d'enregistrements et l'activité récente

Les écritures faites via MCP s'appliquent immédiatement. La carte d'approbation appartient à l'assistant intégré à Relaticle, et un client MCP s'appuie sur ses propres demandes de confirmation. Des outils d'e-mail existent pour une connexion à laquelle l'accès à l'e-mail a été accordé. Les réunions ne sont pas exposées. Un e-mail envoyé est la seule écriture qui ne s'applique pas immédiatement : il est d'abord retenu, et son utilisateur peut l'annuler, dans Relaticle ou via l'agent qui l'a envoyé.

---

## Se connecter à Relaticle

Les clients compatibles OAuth n'ont besoin que du point d'accès MCP. ChatGPT, Claude et Claude Code ouvrent l'écran de consentement de Relaticle et vous laissent choisir un espace de travail.

Les clients sans prise en charge d'OAuth ont besoin d'un jeton d'accès personnel :

1. Connectez-vous à Relaticle
2. Cliquez sur votre avatar en haut à droite
3. Sélectionnez **Jetons d'accès**
4. Cliquez sur **Créer** et donnez un nom à votre jeton
5. Copiez le jeton : il ne sera plus affiché

Le jeton limite votre accès à l'espace de travail que vous sélectionnez à sa création. Toutes les opérations MCP utilisent les données de cet espace de travail.

---

## Authentification

Le MCP server de Relaticle prend en charge deux méthodes d'authentification :

### OAuth 2.1 (recommandé pour les utilisateurs finaux)

Le point d'accès MCP publie ses métadonnées OAuth à ces adresses :

- `https://mcp.relaticle.com/.well-known/oauth-authorization-server`
- `https://mcp.relaticle.com/.well-known/oauth-protected-resource`

Les clients qui prennent en charge l'enregistrement dynamique de client (RFC 7591), dont Claude.ai, Claude Desktop, Claude Code et les connecteurs personnalisés de ChatGPT, s'enregistrent automatiquement et vous guident dans un parcours de consentement en un clic. PKCE est obligatoire (`S256`). Un client peut envoyer un paramètre `resource` (RFC 8707). Il doit désigner ce MCP server, sinon la requête est refusée avec `invalid_target`.

Au moment du consentement, vous choisissez **un espace de travail** pour le connecteur. Ce choix est définitif pour ce connecteur : pour le pointer vers un autre espace de travail, révoquez-le et connectez-le à nouveau. Les espaces de travail en pause ne peuvent pas être sélectionnés. Abonnez-vous d'abord, sinon le connecteur n'aurait aucune donnée à lire.

L'écran de consentement liste ce que le connecteur pourra faire, et la liste suit votre rôle dans l'espace de travail que vous choisissez. Quand l'e-mail est activé, tous les rôles obtiennent la lecture et la rédaction de brouillons, et les rôles Propriétaire, Administrateur et Membre obtiennent l'envoi. Le client ne peut demander ni plus ni moins. Relaticle affiche cet écran à chaque autorisation d'un connecteur, si bien que le connecteur détient toujours ce que son utilisateur a vu. Chaque appel d'outil vérifie aussi votre rôle à ce moment-là, donc un changement de rôle s'applique aux connecteurs que vous avez ajoutés plus tôt.

Les jetons d'accès durent 30 jours et les jetons d'actualisation 90 jours. Les clients pris en charge les actualisent en silence en arrière-plan.

### Révoquer un connecteur

**Paramètres → Jetons d'accès → Connecteurs IA** liste chaque assistant que vous avez connecté, l'espace de travail auquel chacun est lié, et un bouton **Révoquer**. Révoquer invalide immédiatement les jetons d'accès et d'actualisation du connecteur. Les e-mails qu'il avait mis en file d'attente et qui ne sont pas encore partis ne sont pas envoyés. Ils passent dans la boîte d'envoi sous **Échoués**, où vous pouvez les envoyer vous-même avec **Réessayer**.

### Jetons d'accès personnels (recommandés pour les outils de développement)

Pour Cursor, VS Code, MCP Inspector ou tout client sans prise en charge d'OAuth, créez un jeton d'accès personnel depuis les paramètres de votre compte et transmettez-le sous la forme `Authorization: Bearer YOUR_TOKEN`.

Un jeton obtient l'accès à l'e-mail par ses autorisations : **Lire les e-mails**, **Rédiger des brouillons d'e-mail** et **Envoyer des e-mails**. Un nouveau jeton n'en a aucune tant que vous n'en cochez pas une.

---

## Configuration par client

Le point d'accès du MCP server est `https://mcp.relaticle.com`. ChatGPT, Claude et Claude Code utilisent OAuth. Les autres exemples utilisent un jeton d'accès personnel.

### ChatGPT

1. Ouvrez le [plugin Relaticle](https://chatgpt.com/plugins/plugin_asdk_app_6a92c3af04a0819180ed6652ebe09961) dans le répertoire de plugins de ChatGPT et cliquez sur **Install plugin**.
2. Connectez-vous à Relaticle et choisissez un espace de travail.
3. Commencez un message par **@Relaticle**.

### Claude et Claude Desktop

Claude sur le web et l'application Claude Desktop partagent les mêmes connecteurs.

1. Ouvrez **Customize → Connectors**.
2. Sélectionnez le bouton plus, puis **Add custom connector**.
3. Saisissez `Relaticle` et `https://mcp.relaticle.com`.
4. Cliquez sur **Connect**, connectez-vous à Relaticle et choisissez un espace de travail.

Le fichier `claude_desktop_config.json` de Claude Desktop sert aux serveurs locaux. Ajoutez plutôt Relaticle comme connecteur, comme le décrit le guide d'Anthropic pour [ajouter un connecteur par URL](https://claude.com/docs/connectors/custom/add-unlisted).

### Claude Code

Ajoutez le serveur, puis connectez-vous :

```bash
claude mcp add relaticle --transport http https://mcp.relaticle.com
claude mcp login relaticle
```

`claude mcp login` ouvre l'écran de consentement dans votre navigateur, où vous choisissez un espace de travail. Vous pouvez aussi lancer `/mcp` dans une session. Claude Code stocke lui-même les jetons OAuth, donc sa configuration ne contient que l'URL du serveur.

Pour utiliser plutôt un jeton d'accès personnel, référencez-le par une variable d'environnement :

```bash
claude mcp add relaticle \
  --transport http \
  https://mcp.relaticle.com \
  --header 'Authorization: Bearer ${RELATICLE_TOKEN}'
```

Un jeton saisi dans l'en-tête est enregistré en clair dans `~/.claude.json`. Avec `--scope project`, il va dans `.mcp.json` à la racine de votre dépôt, que Claude Code partage via le contrôle de version.

### Cursor avec un jeton d'accès personnel

Ajoutez ceci à la configuration MCP de Cursor (`.cursor/mcp.json`) :

```json
{
  "mcpServers": {
    "relaticle": {
      "type": "streamable-http",
      "url": "https://mcp.relaticle.com",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN"
      }
    }
  }
}
```

### VS Code avec un jeton d'accès personnel

Ajoutez ceci aux paramètres de VS Code (`.vscode/mcp.json`) :

```json
{
  "servers": {
    "relaticle": {
      "type": "streamable-http",
      "url": "https://mcp.relaticle.com",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN"
      }
    }
  }
}
```

---

## Outils disponibles

Le serveur fournit 50 outils. Ils couvrent le contexte du compte, la découverte entre entités, l'analyse de l'espace de travail, un CRUD complet sur cinq entités du CRM, la gestion des relations, les téléversements de fichiers, la lecture des e-mails synchronisés, la gestion des brouillons d'e-mail, ainsi que l'envoi et la programmation d'e-mails. Une connexion sans accès à l'e-mail voit 39 outils.

### Découverte entre entités

| Outil | Description |
|------|-------------|
| `search` | Recherche dans les entreprises, personnes, opportunités, tâches et notes. Renvoie des URL canoniques pour la citation. |
| `fetch` | Récupère un enregistrement par son URL canonique. À associer à `search` pour l'intégration Company Knowledge de ChatGPT. |

### Compte

| Outil | Description |
|------|-------------|
| `who-ami-tool` | Obtient l'utilisateur authentifié avec son fuseau horaire, son espace de travail actuel, les membres de l'espace de travail et les capacités du jeton |

### Intelligence de l'espace de travail

| Outil | Description |
|------|-------------|
| `get-crm-schema-tool` | Obtient le schéma actif, les attributs personnalisés, les filtres et les relations pour un type d'entité |
| `get-crm-summary-tool` | Obtient le nombre d'enregistrements, les totaux du pipeline par étape et l'état des échéances des tâches dans votre fuseau horaire |
| `aggregate-opportunities-tool` | Regroupe les nombres et les montants d'opportunités par étape ou par entreprise, avec des bornes de date facultatives |
| `list-activity-tool` | Liste les changements récents du CRM avec leurs auteurs, les liens vers les enregistrements et les différences au niveau des champs. Un compte supprimé s'affiche comme `Former member` |
| `list-custom-fields-tool` | Liste les définitions d'attributs personnalisés (custom field) actifs et inactifs, y compris les options de choix |

### Entreprises

| Outil | Description |
|------|-------------|
| `list-companies-tool` | Liste les entreprises avec filtre, tri et pagination facultatifs |
| `get-company-tool` | Obtient une entreprise par ID avec tous ses détails et ses relations |
| `create-company-tool` | Crée une nouvelle entreprise (exige `name`) |
| `update-company-tool` | Modifie une entreprise par ID |
| `delete-company-tool` | Supprime une entreprise par ID (suppression logique) |

### Personnes

| Outil | Description |
|------|-------------|
| `list-people-tool` | Liste les personnes avec filtre, tri et pagination facultatifs |
| `get-people-tool` | Obtient une personne par ID avec tous ses détails et ses relations |
| `create-people-tool` | Crée une nouvelle personne (exige `name`, `company_id` facultatif) |
| `update-people-tool` | Modifie une personne par ID |
| `delete-people-tool` | Supprime une personne par ID (suppression logique) |

### Opportunités

| Outil | Description |
|------|-------------|
| `list-opportunities-tool` | Liste les opportunités avec filtre, tri et pagination facultatifs |
| `get-opportunity-tool` | Obtient une opportunité par ID avec tous ses détails et ses relations |
| `create-opportunity-tool` | Crée une nouvelle opportunité (exige `name`, `company_id` et `contact_id` facultatifs) |
| `update-opportunity-tool` | Modifie une opportunité par ID |
| `delete-opportunity-tool` | Supprime une opportunité par ID (suppression logique) |

### Tâches

| Outil | Description |
|------|-------------|
| `list-tasks-tool` | Liste les tâches avec filtre, tri et pagination facultatifs |
| `get-task-tool` | Obtient une tâche par ID avec tous ses détails et ses relations |
| `create-task-tool` | Crée une nouvelle tâche (exige `title`) |
| `update-task-tool` | Modifie une tâche par ID |
| `delete-task-tool` | Supprime une tâche par ID (suppression logique) |
| `attach-task-to-entities-tool` | Lie une tâche à des entreprises, des personnes, des opportunités, ou l'assigne à des membres. Ajoute sans retirer les liens existants. |
| `detach-task-from-entities-tool` | Délie une tâche d'entreprises, de personnes, d'opportunités, ou retire l'assignation de membres |

### Notes

| Outil | Description |
|------|-------------|
| `list-notes-tool` | Liste les notes avec filtre, tri et pagination facultatifs |
| `get-note-tool` | Obtient une note par ID avec tous ses détails et ses relations |
| `create-note-tool` | Crée une nouvelle note (exige `title`) |
| `update-note-tool` | Modifie une note par ID |
| `delete-note-tool` | Supprime une note par ID (suppression logique) |
| `attach-note-to-entities-tool` | Lie une note à des entreprises, des personnes ou des opportunités. Ajoute sans retirer les liens existants. |
| `detach-note-from-entities-tool` | Délie une note d'entreprises, de personnes ou d'opportunités |

### E-mail

Ces outils n'apparaissent que pour une connexion à laquelle l'accès à l'e-mail a été accordé. Les outils de lecture montrent les e-mails synchronisés que leur utilisateur peut voir, au niveau de partage choisi par le propriétaire de la boîte mail, ainsi que ses propres brouillons et sa boîte d'envoi. Les outils de boîte mail, de brouillon, d'envoi et de boîte d'envoi travaillent sur les boîtes mail et les e-mails de l'utilisateur lui-même.

Les deux outils de lecture exigent l'accès en lecture. L'outil de boîte mail apparaît pour une connexion autorisée à rédiger des brouillons ou à envoyer, et les trois outils de brouillon pour une connexion autorisée à rédiger des brouillons. Les outils d'envoi, d'annulation, de reprogrammation et de nouvel essai exigent l'autorisation d'envoi et un rôle autorisé à envoyer via un assistant. Un envoi programmé ne part jamais avant le délai de retenue. Un e-mail part vers 20 destinataires au plus au total, tous champs À, Cc et Cci confondus. Un utilisateur peut avoir jusqu'à 10 e-mails d'assistant en attente à la fois.

Un e-mail en file d'attente reste lié à la connexion qui l'y a mis. Il n'est pas envoyé si cette connexion est révoquée, supprimée ou expirée à l'heure de son envoi. Il en va de même si le rôle de son utilisateur n'autorise plus l'envoi via un assistant. Il attend alors dans la boîte d'envoi sous **Échoués** avec la raison.

| Outil | Description |
|------|-------------|
| `list-emails-tool` | Liste les e-mails du plus récent au plus ancien, avec des filtres facultatifs de recherche, d'enregistrement lié, de direction, de fil, de statut et de date. L'e-mail d'un collègue apparaît une fois synchronisé ou envoyé, et les propres brouillons et la boîte d'envoi de l'utilisateur apparaissent aussi. L'objet et l'extrait n'apparaissent que là où le niveau de partage le permet. |
| `get-email-tool` | Obtient un e-mail par ID, avec son statut et son heure d'envoi. Le corps et les noms des pièces jointes n'apparaissent qu'avec un accès complet, et un corps long est tronqué. |
| `list-email-accounts-tool` | Liste les boîtes mail que l'utilisateur a connectées dans cet espace de travail, avec l'ID à utiliser pour rédiger un brouillon et si chacune peut envoyer. |
| `create-email-draft-tool` | Enregistre un brouillon dans l'une des boîtes mail de l'utilisateur, en texte brut ou en markdown, avec la signature par défaut sauf si vous la désactivez. Rien n'est envoyé : l'utilisateur relit le brouillon dans Relaticle. |
| `update-email-draft-tool` | Réécrit un brouillon enregistré avec les mêmes arguments que pour le créer. Les fichiers joints restent. |
| `delete-email-draft-tool` | Supprime un brouillon enregistré et ses fichiers joints. |
| `send-email-tool` | Envoie un e-mail depuis l'une des boîtes mail de l'utilisateur, maintenant ou à l'heure choisie. Chaque e-mail attend quelques minutes avant de partir, et son utilisateur peut l'annuler depuis les notifications ou la Boîte d'envoi. |
| `send-email-draft-tool` | Envoie un brouillon enregistré tel quel, y compris les modifications de l'utilisateur, et le retire des Brouillons. Il est retenu comme n'importe quel autre envoi. |
| `cancel-email-tool` | Annule un e-mail en file d'attente avant son départ. |
| `reschedule-email-tool` | Change l'heure de départ d'un e-mail en file d'attente. L'utilisateur reçoit un nouvel avis depuis lequel il peut annuler. |
| `retry-email-tool` | Remet un e-mail en échec en file d'attente, retenu comme un nouvel envoi. Il est alors lié à la connexion qui a réessayé. |

### Fichiers

| Outil | Description |
|------|-------------|
| `upload-file` | Stocke un fichier dans l'espace de travail à partir d'un corps en base64 avec `filename`, ou d'un `upload_id`. Renvoie `suggested_markdown` pour intégrer le fichier dans un champ d'éditeur enrichi comme le corps d'une note, ainsi que `file_id` pour le fichier stocké. Types autorisés : pdf, doc, docx, xlsx, pptx, jpg, jpeg, png, gif, webp, jusqu'à 10 Mo. 60 appels par heure et par espace de travail. |
| `create-upload-url` | Obtient une URL signée valable cinq minutes vers laquelle faire un `PUT` du corps d'un fichier, puis transmettez l'`upload_id` renvoyé à `upload-file` dans l'heure. Un corps non stocké à ce moment-là est supprimé. 60 appels par heure et par espace de travail. |

Les téléversements restent en attente pendant 24 heures. Le fichier est réclamé dès qu'un enregistrement dont le corps d'éditeur enrichi l'intègre est sauvegardé. Les fichiers non réclamés sont purgés.

Les outils de liste des entités acceptent `filter`, `sort`, `include`, `per_page` (15 par défaut, 25 au maximum) et `page`. `sort` est un objet avec `field` et `direction` (`asc` ou `desc`). `include` est une liste de relations au singulier ou de décomptes de relations. Un outil de liste refuse tout autre argument. Pour trouver des enregistrements par des mots dans toutes les entités, utilisez l'outil `search`.

`sort` accepte un champ natif ou un attribut personnalisé qui contient une seule valeur. Un champ qui contient une liste, comme l'e-mail, le téléphone, le lien ou les tags, ne peut pas être trié.

Les réponses de liste incluent `page`, `per_page`, `total`, `has_more` et `next_page`. Les outils de création et de modification acceptent `custom_fields` sous forme de paires clé-valeur. Une écriture stocke un téléphone au format E.164, un domaine sous forme de simple hôte et une date et heure sous forme de son instant UTC. Une date sans heure écrite dans un attribut de type date et heure est stockée comme le début de ce jour dans le fuseau horaire de l'utilisateur du jeton. Une heure sans décalage est toujours lue comme UTC.

### Filtrer les enregistrements

Transmettez `filter` à un outil de liste pour restreindre les enregistrements qu'il renvoie. Un filtre est un objet JSON. L'[API REST](/fr/developers/rest-api) et Rela, l'assistant IA intégré, lisent le même filtre.

Chaque clé d'un filtre est l'une des suivantes :

| Clé | Signification |
|---|---|
| `$and`, `$or`, `$not` | Logique |
| `$eq`, `$in`, `$contains` et les autres opérateurs | Un test sur le champ ou la relation qui les entoure |
| Un champ natif comme `name` ou `created_at` | Une condition sur l'une des colonnes propres à l'enregistrement |
| Une relation comme `company` ou `assignees` | Une condition sur des enregistrements liés |
| `custom_fields` | Des conditions sur vos attributs personnalisés, indexées par code de champ |
| Un sous-champ comme `domain` | Une partie d'une valeur d'e-mail ou de lien |

Une clé qui commence par `$` est un mot-clé. Toute autre clé est un nom. Il n'existe aucune forme abrégée : `{"stage": "Won"}` est une erreur, tout comme un opérateur sans son `$`.

Les champs natifs et les relations se placent au niveau supérieur. Les attributs personnalisés se placent sous `custom_fields`, si bien qu'un attribut personnalisé de code `name` n'entre jamais en collision avec le `name` natif.

#### Champs natifs et relations

| Entité | Noms de filtre |
|---|---|
| Entreprises | Champs natifs `name`, `created_at`, `updated_at`, `creation_source`. Relations d'enregistrements `people`, `opportunities`. Relations de membres `creator`, `accountOwner`. |
| Personnes | Champs natifs `name`, `created_at`, `updated_at`, `creation_source`. Relation d'enregistrement `company`. Relation de membre `creator`. |
| Opportunités | Champs natifs `name`, `created_at`, `updated_at`, `creation_source`, `stale_days`. Relations d'enregistrements `company`, `contact`. Relation de membre `creator`. |
| Tâches | Champs natifs `title`, `created_at`, `updated_at`, `creation_source`, `assigned_to_me`. Relations d'enregistrements `companies`, `people`, `opportunities`. Relations de membres `creator`, `assignees`. |
| Notes | Champs natifs `title`, `created_at`, `updated_at`, `creation_source`. Relations d'enregistrements `companies`, `people`, `opportunities`. Relation de membre `creator`. |

`name` et `title` acceptent les opérateurs de texte. `created_at` et `updated_at` acceptent les opérateurs de date et d'heure. Un opérande de date est `YYYY-MM-DD` ou une date et heure ISO 8601 comme `2026-10-01T09:30:00Z`, et tout autre format est une erreur. Une date et heure avec décalage, comme `2026-10-01T13:00:00+05:00`, est lue comme le même instant en UTC. Une date sans heure couvre toute cette journée dans le fuseau horaire de l'utilisateur du jeton, que l'outil d'identité renvoie, sur ces champs et sur un attribut personnalisé de type date et heure. `creation_source` est un choix unique avec les valeurs `web`, `sample`, `import`, `api`, `mcp`, `chat`, `mailbox` et `system`. `stale_days` retient les opportunités par nombre de jours entiers sans activité, comme dans `{"$gte": 30}`. `assigned_to_me` accepte `{"$eq": true}`.

#### Combiner des conditions

- Les clés d'un même objet se combinent avec AND.
- `$or` accepte une liste d'objets de condition et renvoie les enregistrements qui correspondent à l'un d'eux.
- `$and` accepte aussi une liste. Utilisez-le quand un seul objet exigerait deux clés `$or`.
- `$not` accepte un seul objet de condition. Il renvoie tous les enregistrements que cet objet ne renvoie pas, y compris ceux dont le champ est vide.
- Une liste `$and` ou `$or` vide, un objet `$not` vide et un nœud de relation vide sont des erreurs. Un filtre vide signifie aucun filtre.

#### Filtrer à travers une relation

Un nœud de relation contient la même grammaire de filtre, appliquée aux enregistrements liés. Les noms qu'il contient appartiennent à l'entité liée.

- Les relations d'enregistrements acceptent `$in`, `$not_in` (ID d'enregistrements) et `$is_empty`, ou des conditions sur l'enregistrement lié.
- Les relations de membres acceptent `$in`, `$not_in` (ID de membres) et `$is_empty`. Les noms et les e-mails des membres ne sont pas filtrables. Une personne qui a quitté l'espace de travail ne compte plus comme membre, donc un enregistrement qu'elle a créé correspond à `{"creator": {"$is_empty": true}}`.
- Une relation vers plusieurs enregistrements correspond quand au moins un enregistrement lié correspond. Toutes les conditions d'un même nœud de relation s'appliquent au même enregistrement lié. Pour trouver les enregistrements sans aucun lien, enveloppez le nœud dans `$not`.
- Les relations s'imbriquent sur 2 niveaux au plus, comme `company` puis `people`.

#### Opérateurs par type de champ

Les champs natifs acceptent les opérateurs du type de champ auquel ils correspondent. `$is_empty` accepte `true` ou `false`.

| Type de champ | Opérateurs |
|---|---|
| Choix unique (liste déroulante, boutons radio, boutons à bascule), `creation_source` | `$eq`, `$in`, `$not_in`, `$is_empty` |
| Choix multiple (sélection multiple, liste de cases à cocher), tags, enregistrement | `$has_any`, `$has_none`, `$is_empty` |
| E-mail, téléphone, lien, domaine | `$has_any`, `$has_none`, `$contains`, `$starts_with`, `$ends_with`, `$is_empty` |
| Texte | `$eq`, `$contains`, `$starts_with`, `$ends_with`, `$is_empty` |
| Nombre, devise, date, date et heure | `$eq`, `$gt`, `$gte`, `$lt`, `$lte`, `$is_empty` |
| Case à cocher, interrupteur | `$eq`, `$is_empty` |

Les champs e-mail et lien acceptent aussi un sous-champ `domain` avec `$in` et `$not_in`. Il compare le domaine de chaque valeur, donc `{"domain": {"$in": ["canva.com"]}}` trouve toutes les adresses de `canva.com`. Un domaine est un simple hôte. Un chemin, un utilisateur ou un port est une erreur.

Les champs zone de texte, texte enrichi et couleur n'acceptent aucun filtre. Les champs chiffrés ou inactifs non plus.

#### Comment les valeurs correspondent

- Les valeurs de choix acceptent le libellé de l'option ou son ID. Un libellé inconnu renvoie une erreur qui liste les libellés valides. Un libellé ambigu demande l'ID de l'option.
- Les valeurs d'e-mail et de lien correspondent quelle que soit la casse. Un lien correspond aussi avec ou sans son schéma, donc `acme.com/team` trouve `https://acme.com/team`.
- Les valeurs de domaine correspondent sous forme de simple hôte ou d'URL complète, avec ou sans `www.`, donc `https://www.acme.com/about` trouve `acme.com`.
- Les valeurs de téléphone correspondent quel que soit le format. Un opérande de téléphone exige un indicatif de pays, comme `+1 415 555 0100`. Un opérande sans indicatif renvoie l'erreur `phone_number needs a country code, for example +1 415 555 0100.`
- Les tags correspondent à la valeur stockée exacte.
- Un champ d'enregistrement accepte les ID des enregistrements auxquels il est lié.
- `$contains`, `$starts_with` et `$ends_with` ignorent la casse. Sur un champ qui contient plusieurs valeurs, ils correspondent quand une valeur correspond. Ils comparent l'opérande tel que saisi à la valeur telle que stockée. Un téléphone est stocké sous la forme `+14155550100`, avec un numéro de poste sous la forme `;ext=12` et sans espaces, donc `+1415` correspond et `+1 415` non. Un lien ne garde un schéma que si un schéma a été saisi, donc `https://acme` correspond à `https://acme.com/team` et `acme` non. Un domaine est stocké sous forme de simple hôte, sans schéma ni `www.`.
- `$not_in` et `$has_none` correspondent aussi aux enregistrements dont le champ est vide.
- Le lien d'un champ d'enregistrement vers un enregistrement supprimé compte comme aucun lien.

#### Limites

Un filtre contient 20 conditions au plus, comptées comme chaque opérateur à n'importe quelle profondeur. Il imbrique `$and`, `$or` et `$not` sur 3 niveaux au plus et les relations sur 2 niveaux au plus. Une liste contient 100 valeurs au plus.

#### Exemples

Les opportunités d'au moins 10 000 qui se trouvent à Proposal/Price Quote ou Negotiation/Review. La condition en dehors de `$or` se combine avec lui par AND.

```json
{
  "custom_fields": {"amount": {"$gte": 10000}},
  "$or": [
    {"custom_fields": {"stage": {"$eq": "Proposal/Price Quote"}}},
    {"custom_fields": {"stage": {"$eq": "Negotiation/Review"}}}
  ]
}
```

Les opportunités d'entreprises dont l'interrupteur `icp` est activé. `company` est une relation, donc son nœud contient des conditions sur l'entreprise, y compris sur ses attributs personnalisés.

```json
{"company": {"custom_fields": {"icp": {"$eq": true}}}}
```

Les personnes ayant une adresse e-mail sur un domaine. Cela fonctionne de la même façon pour un champ de lien.

```json
{"custom_fields": {"emails": {"domain": {"$in": ["canva.com"]}}}}
```

Les libellés d'étape et les codes de champ appartiennent à votre espace de travail. Appelez `get-crm-schema-tool` pour lire les vôtres.

#### Lire le vocabulaire de filtre

`get-crm-schema-tool` renvoie `filterable_fields` pour un type d'entité. Il contient trois parties :

- Chaque champ natif et chaque relation par son nom, avec son `type`, ses `operators` et un `example`. Les relations ajoutent l'`entity` liée. `creation_source` ajoute ses `values`. Les relations, les relations de membres, `stale_days` et `assigned_to_me` ajoutent un `operand` qui indique ce qu'ils acceptent.
- `types` associe chaque type d'attribut personnalisé présent dans votre espace de travail à ses `operators`, sa règle `matching`, sa règle `partial_matching` quand il en a une, et tout `sub_fields`. Un type dont vous saisissez librement les valeurs porte aussi un `example`, et un type de choix le porte sur le champ à la place.
- `custom_fields` liste chaque attribut personnalisé filtrable par code, avec son `name` et son `type`. Un champ de choix porte aussi ses `options` et un `example` construit à partir d'elles.

#### Erreurs de filtre

Un filtre invalide renvoie une erreur qui nomme la partie à corriger. Par exemple, un code d'attribut personnalisé inconnu renvoie `"stagee" is not a filterable custom attribute (custom field) on opportunity. Available: amount, close_date, stage.` Un opérateur sans `$` renvoie `Operators start with $. Use $eq.`

Les noms à plat de l'ancien filtre n'existent plus. Dans `filter`, des clés comme `search`, `created_after`, `company_id` et `assignee_ids` renvoient une erreur qui nomme le remplaçant, par exemple `company_id was replaced. Use company (or companies) with $in.` Les mêmes noms envoyés à côté de `filter` renvoient aussi cette erreur.

---

## Ressources de schéma

Le serveur expose cinq ressources de schéma qui décrivent les champs de chaque entité, y compris les attributs personnalisés configurés dans votre espace de travail, ainsi qu'une ressource de synthèse avec le nombre d'enregistrements :

| URI de la ressource | Description |
|---|---|
| `relaticle://schema/company` | Champs et attributs personnalisés des entreprises |
| `relaticle://schema/people` | Champs et attributs personnalisés des personnes |
| `relaticle://schema/opportunity` | Champs et attributs personnalisés des opportunités |
| `relaticle://schema/task` | Champs et attributs personnalisés des tâches |
| `relaticle://schema/note` | Champs et attributs personnalisés des notes |

La prise en charge des ressources varie selon le client MCP. Utilisez `get-crm-schema-tool` avant d'écrire un attribut personnalisé (custom field) quand votre client n'expose pas les ressources automatiquement.

---

## Prompt de vue d'ensemble du CRM

Le serveur inclut un prompt intégré appelé **CRM Overview** qui donne à votre assistant IA un aperçu des données de votre CRM : le nombre d'enregistrements de chaque entité, ainsi que les entreprises et les personnes créées récemment. C'est un excellent point de départ pour toute conversation.

---

## Exemples de prompts

Une fois connecté, essayez ceux-ci dans votre assistant IA :

- « Liste toutes mes entreprises »
- « Crée une nouvelle entreprise nommée Acme Corp »
- « Montre-moi les personnes de l'entreprise X »
- « Crée une tâche pour relancer John la semaine prochaine »
- « Donne-moi une vue d'ensemble de mon CRM »
- « Remplace le nom de l'entreprise X par Y »
- « Supprime la tâche d'ID abc-123 »

---

## Dépannage

### Erreur « Unauthorized » ou 401

Votre jeton d'accès est peut-être expiré ou invalide. Créez-en un nouveau depuis **Paramètres > Jetons d'accès**.

### Aucune donnée renvoyée

Le MCP server limite toutes les données à l'espace de travail associé à votre jeton. Vérifiez que le jeton a été créé pour le bon espace de travail et que cet espace de travail contient des données.

### Connexion refusée

Vérifiez que l'URL MCP est correcte : `https://mcp.relaticle.com`.

### Les attributs personnalisés n'apparaissent pas

Les attributs personnalisés sont propres à chaque espace de travail. Si vous ne les voyez pas, vérifiez qu'ils sont configurés pour votre espace de travail dans **Paramètres > Attributs personnalisés**. Appelez ensuite `get-crm-schema-tool` pour le type d'entité.

### Limitation de débit

Les requêtes d'outils MCP sont limitées à 120 par minute et par utilisateur authentifié. Les points d'accès d'autorisation OAuth sont limités à 20 par minute et par adresse IP.
