# Textes 0.13.0 à valider par Oscar (Phase 55 / CH03)

Tous les textes nouveaux ou changés lus par une personne : l'admin WordPress (l'écran, le réglage),
ou Arcadia (les messages d'erreur de l'API, que le serveur et l'agent relaient). Ils sont en anglais
dans le code, comme tout le plugin. La traduction française passe par le fichier de langue
`arcadia-agents` (`languages/`). Pour chaque texte : où il apparaît, ce qu'il y avait avant, ce qu'il y a maintenant, et pourquoi.

## 1. Réglage « Approve changes » (`admin/settings.php`) — validé par Oscar le 2026-11-12

Ancien nom : « The agent saves as draft only ». L'ancien texte promettait plus que ce que faisait
le plugin (C62). Le nouveau a deux niveaux : une phrase qui dit l'état actuel, puis le détail replié.

| Emplacement | Anglais | Français |
|---|---|---|
| Interrupteur | Approve changes | Valider les changements |
| Texte, activé | Arcadia prepares, you approve. Nothing changes on your site without your approval. | Arcadia prépare, vous validez. Rien ne change sur votre site sans votre accord. |
| Texte, désactivé | Arcadia publishes and edits directly. | Arcadia publie et modifie directement. |
| Détail replié | What waits for your approval | Ce qui attend votre accord |

Seul le texte de l'état actuel est visible. Il change dès qu'on bascule l'interrupteur, avant l'enregistrement.
Le détail est une liste à icônes (les mêmes que la page) :

- **+** New articles: saved as drafts.
- **crayon** Edits to live articles: approved in the article editor.
- **crayon** Changes to categories, tags, media and redirects: approved on the Awaiting approval page.
- **poubelle** Deletions: approved on the Awaiting approval page.
- **coche grise** Without approval: creating a category, adding an image. Visitors see neither until it is used.

**Badge d'état** (tableau de bord, haut des réglages) : « Agent: Change approval » / « Agent: Direct
publishing ». Français : « Agent : Validation des changements » / « Agent : Publication directe ».
Avant : « Brouillon uniquement ». Infobulle, activé : « Arcadia’s changes wait for your approval. »

**Permission `articles:write`** (écran de connexion) : « While “Approve changes” is on, nothing goes
live: new content stays a draft and edits to published content wait for your approval. »

## 2. Nouvel écran (`admin/pending.php`, menu Arcadia Agents) — validé par Oscar le 2026-11-12

| Emplacement | Anglais | Français |
|---|---|---|
| Menu | Awaiting approval | À valider |
| Titre | Changes awaiting approval | Changements à valider |
| Intro, ligne 1 | Arcadia proposes these changes. Nothing changes on your site until you approve. | |
| Intro, ligne 2 | Edits to an article’s text are approved in the article editor. | |
| File vide | Nothing is waiting for your approval. | Rien n’attend votre validation. |
| Colonnes | Action · Item · Date | Action · Élément · Date |
| Décisions | Recent decisions · Approved · Rejected · Out of date · Withdrawn by Arcadia | … Périmée · Retirée par Arcadia |
| Confirmation | Apply this change to your site? | |
| Refus groupé | Reason (optional) | Raison (facultatif) |
| Bilan groupé | %s change(s) applied · %s change(s) rejected | %s changement(s) appliqué(s) · rejeté(s) |
| Bilan, échecs | %s failed (lien vers la première ligne en échec) | %s en échec |
| Bilan, périmés | %s is / are no longer valid (gris, sans lien ; les lignes quittent la liste) | %s n’est / ne sont plus valable(s) |
| Ligne seule périmée | No longer valid: the item changed on your site. Removed from the list. (2,5 s, puis la ligne part) | |

**Chaque ligne** (`Arcadia_Pending_Actions::summary()`) : une icône pour l'action (poubelle rouge =
supprimer, crayon violet = modifier, + vert = créer ; le mot reste au survol et pour les lecteurs
d'écran), puis le type d'élément, son nom, et l'effet :

| Action | Effet affiché |
|---|---|
| content.delete, media.delete (corbeille active) | Moves to the trash · Va à la corbeille |
| content.delete déjà en corbeille, media.delete, term.delete | Permanent · Définitif (rouge) |
| term.update | New name: “%s” · ou la liste des champs changés |
| media.update | la liste des champs changés |
| redirect.create | source → cible |

Si l'élément n'existe plus, la ligne garde son nom (copie prise quand la proposition arrive).

## 3. Messages d'erreur de l'API (`Arcadia_Write_Table::refusal()`)

Le serveur Arcadia les relaie au membre et à l'agent. Ils disent ce qui s'est passé et quoi faire ensuite.

| Code | HTTP | Avant | Après |
|---|---|---|---|
| `status_not_supported_for_revision` | 422 | This edit is held for approval, and an approval cannot change the post status — send 'status' only on a write that applies directly. | This article is live and the site holds edits for approval: an approval cannot change the article's status. Send the edit without 'status', or with the article's current status. |
| `post_trashed` | 409 | — (nouveau) | This article is in the trash. Restore it in WordPress before editing it. |
| `pending_action_exists` | 409 | — (nouveau) | A deletion of this article is waiting for approval in WordPress. Nothing was changed. Withdraw the deletion, or wait for the decision. |
| `pending_action_stale` | 409 | — (nouveau) | This proposal is out of date: the item changed on the site after Arcadia made it. Nothing was applied. Arcadia has been told. |
| `action_not_pending` | 409 | — (nouveau) | This proposal is no longer waiting for approval. |
| `pending_action_not_found` | 404 | — (nouveau) | No proposal with this ID. |
| `invalid_ids` | 400 | — (nouveau) | ids must be a comma-separated list of 1 to %s positive IDs. (FR : ids doit être une liste de 1 à %s identifiants positifs, séparés par des virgules.) |

`pending_action_stale` est aussi le message que voit la personne qui valide dans WordPress : sur la
ligne de l'écran, dans la validation groupée et dans l'éditeur d'article. Avant : « Arcadia will propose again from the current version ». Changé le 2026-11-09 :
le serveur retire la proposition périmée et prévient l'agent, mais une nouvelle proposition n'est
pas garantie (une réoptimisation attend le « oui » du client, la mise à jour d'un membre n'est pas
reproposée). Français : « Cette proposition n’est plus valable : l’élément a été modifié sur le
site après la proposition d’Arcadia. Rien n’a été appliqué. Arcadia en est prévenu. »

## 4. Champ `message` de la réponse « gardé » (202)

> Held for approval in WordPress. Nothing changed on the site.

Il n'est pas traduit : c'est un champ de l'API, comme l'ancien `Revision stored. Live post unchanged.`,
qui reste dans la réponse 201 des révisions.

## 5. Traduction française (`languages/arcadia-agents-fr_FR.po`)

La couverture reste à 100 % (309 chaînes). Sur l'écran, le mot est « changement » (et non plus
« modification »), comme dans le nom du réglage.
