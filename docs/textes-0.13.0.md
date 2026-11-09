# Textes 0.13.0 à valider par Oscar (Phase 55 / CH03)

Tous les textes nouveaux ou changés lus par une personne : l'admin WordPress (l'écran, le réglage),
ou Arcadia (les messages d'erreur de l'API, que le serveur et l'agent relaient). Ils sont en anglais
dans le code, comme tout le plugin. La traduction française passe par le fichier de langue
`arcadia-agents` (`languages/`). Pour chaque texte : où il apparaît, ce qu'il y avait avant, ce qu'il y a maintenant, et pourquoi.

## 1. Réglage « The agent saves as draft only » (`admin/settings.php`)

**Avant :**
> When enabled, the agent can never change your live site on its own. New articles are saved as
> drafts, and edits to already-published articles are held as proposals you approve from the article
> editor — your live article is never taken offline. When disabled, the agent publishes new articles
> and applies edits to live articles directly, with no review step.

**Après :**
> When enabled, nothing Arcadia does changes what visitors see until you approve it. New articles are
> saved as drafts. Edits to published or scheduled articles, and their featured image, wait for your
> approval in the article editor. Deletions, category and tag renames and deletions, media changes and
> redirects wait on the “Changes awaiting approval” page. A change is refused if the item was modified
> on your site after Arcadia proposed it. Creating a category or uploading an image is not held:
> neither is visible on its own. When disabled, Arcadia applies all of these directly, with no review
> step. In both cases a deletion goes to the trash.

**Pourquoi :** l'ancien texte promettait plus que ce que faisait le plugin (C62). Le nouveau décrit
exactement la table des écritures, avec aussi ce qu'elle ne garde pas. Le texte est long. Pour le
raccourcir, enlever « Creating a category… on its own » ferait perdre l'exception qui surprend le plus.

## 2. Nouvel écran (`admin/pending.php`, menu Arcadia Agents)

| Emplacement | Texte |
|---|---|
| Menu (sous-menu) | Awaiting approval |
| Titre de la page | Changes awaiting approval |
| Intro | The setting “The agent saves as draft only” is on: Arcadia’s deletions, category and tag changes, media changes, redirects and featured-image changes on live articles wait here. Nothing changes on your site until you approve. Edits to article text are reviewed in the article editor. |
| File vide | Nothing is waiting for your approval. |
| Colonnes | Change · Date · Actions |
| Décisions | Recent decisions · Approved · Rejected · Out of date · Withdrawn by Arcadia · No decisions yet. |
| Confirmation | Apply this change to your site? |
| Sélection | %s change selected · %s changes selected · Select all changes |
| Groupé | Apply %s changes to your site? · Reject this change? · Reject %s changes? · Reason, applied to every selected change (optional): |
| Échec partiel | %1$s processed, %2$s failed — the failed changes are still listed, with the reason. |
| AJAX | Change approved and applied. · Change rejected. · Missing change ID. · You are not allowed to approve this change. · You are not allowed to reject this change. |

**Description de chaque ligne** (`Arcadia_Pending_Actions::describe()`, construite à l'affichage) :

| Action | Texte |
|---|---|
| content.delete | Move “%s” to the trash · Permanently delete “%s” (already in the trash) |
| content.featured_image | Change the featured image of “%s” |
| term.update | Rename %1$s “%2$s” to “%3$s” · Edit %1$s “%2$s” (%1$s = the category / the tag) |
| term.delete | Delete %1$s “%2$s” |
| media.update | Edit the title, caption or alternative text of the media “%s” |
| media.delete | Delete the media “%s” — it disappears from every page that shows it |
| redirect.create | Redirect visitors from %1$s to %2$s |
| redirect.delete | Remove the redirect from %s |

**Pourquoi :** chaque ligne dit ce que la validation va faire sur le site. Pour un média supprimé,
elle dit aussi la conséquence qu'on ne voit pas : l'image disparaît de toutes les pages qui l'affichent.

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

Les 43 nouvelles chaînes sont traduites, et la couverture reste à 100 % (268 chaînes). Voici celles
qu'un client lira le plus souvent :

| Anglais | Français |
|---|---|
| Awaiting approval (menu) | À valider |
| Changes awaiting approval | Modifications à valider |
| Nothing is waiting for your approval. | Rien n’attend votre validation. |
| Apply this change to your site? | Appliquer cette modification à votre site ? |
| Out of date / Withdrawn by Arcadia | Périmée / Retirée par Arcadia |
| Move “%s” to the trash | Mettre « %s » à la corbeille |
| Rename %1$s “%2$s” to “%3$s” | Renommer %1$s « %2$s » en « %3$s » (la catégorie / l’étiquette) |
| Delete the media “%s” — it disappears from every page that shows it | Supprimer le média « %s » — il disparaît de toutes les pages qui l’affichent |
| Redirect visitors from %1$s to %2$s | Rediriger les visiteurs de %1$s vers %2$s |
| This proposal is out of date: … | Cette proposition est périmée : l’élément a été modifié sur le site après la proposition d’Arcadia. Rien n’a été appliqué. Arcadia refera une proposition à partir de la version actuelle. |

**Réglage, version française :**
> Activé, rien de ce que fait Arcadia ne change ce que voient les visiteurs avant votre validation. Les
> nouveaux articles sont enregistrés en brouillon. Les modifications d’articles publiés ou programmés,
> et de leur image mise en avant, attendent votre validation dans l’éditeur d’article. Les
> suppressions, les renommages et suppressions de catégories et d’étiquettes, les changements de
> médias et les redirections attendent sur la page « Modifications à valider ». Une modification est
> refusée si l’élément a été modifié sur votre site après la proposition d’Arcadia. Créer une
> catégorie ou téléverser une image n’est pas gardé : ni l’une ni l’autre ne se voit seule.
> Désactivé, Arcadia applique tout cela directement, sans relecture. Dans les deux cas, une
> suppression va à la corbeille.
