# Contrat — table des écritures (plugin 0.13.0, Phase 55 / CH03)

> **Statut : figé le 2026-11-09** (périmètre décidé par Oscar le 2026-11-09). Toute
> modification passe par ce fichier **et** par `includes/class-write-table.php`, dans le même
> commit : un test vérifie que les deux disent la même chose.
>
> Destinataires : le serveur Arcadia (double partagé du plugin dans ses tests), et le code du
> plugin lui-même — chaque chemin d'écriture lit la table, aucun ne décide seul.

## 1. Règle générale

Une écriture **appliquée** change le site tout de suite. Une écriture **gardée** ne change rien :
elle devient une *action en attente*, qu'un humain valide ou refuse dans l'admin WordPress. Une
écriture **refusée** ne change rien et ne crée rien (4xx).

Le brouillon forcé (`aa_force_draft`) est le seul réglage qui fait garder. Le clic d'un membre
dans Arcadia ne le contourne pas : le plugin ne sait pas, et n'a pas à savoir, qui a cliqué.

Toute réponse se calcule sur **le résultat observé** après l'appel WordPress (statut relu,
objet relu), jamais sur la demande.

## 2. La table

« Live » = statut `publish` ou `future` (un article programmé sortira seul). Les autres statuts
(`draft`, `pending`, `private`) ne sont pas vus par les visiteurs.

### 2.1 Articles et pages (`/contents`, alias `/articles`)

| Action | État de l'article | Brouillon forcé **désactivé** | Brouillon forcé **activé** |
|---|---|---|---|
| Créer (`POST`) | — | appliquer, avec le `status` demandé (défaut `draft`) | appliquer **en `draft`** ; `force_draft_applied: true` si un autre statut était demandé |
| Mettre à jour (`PUT`), sans `status` | live | appliquer, statut inchangé | **garder** (révision) |
| | non live | appliquer, statut inchangé | appliquer, **statut inchangé** (C61 : plus de passage forcé en `draft`) |
| Mettre à jour, `status` = statut actuel | live | appliquer | **garder** (révision) — le `status` est un « rien à changer », plus de 422 (C03) |
| | non live | appliquer | appliquer |
| Mettre à jour, `status` ≠ statut actuel | live | appliquer | **refuser** `422 status_not_supported_for_revision` |
| | non live, vers `publish` | appliquer | appliquer **en `draft`**, `force_draft_applied: true` |
| | non live, vers `draft`/`pending`/`private` | appliquer | appliquer |
| Image mise en avant (`PUT …/featured-image`) | live | appliquer | **garder** (action `content.featured_image`) |
| | non live | appliquer | appliquer |
| Supprimer (`DELETE`) | pas à la corbeille | **corbeille**, quel que soit le type (C63) | **garder** (action `content.delete`) |
| | déjà à la corbeille, sans `force` | rien à faire : `200`, `trashed: true`, `already_trashed: true` | idem |
| | déjà à la corbeille, `force: true` | suppression définitive | **garder** (action `content.delete`, `force: true`) |
| Toute écriture | une action `content.delete` est en attente | — | **refuser** `409 pending_action_exists` (lien vers l'attente) |

Une mise à jour à la corbeille (statut `trash`) est refusée : `409 post_trashed`, dans les deux cas.

### 2.2 Catégories et étiquettes (`/categories`, `/tags`)

| Action | Brouillon forcé désactivé | Brouillon forcé activé |
|---|---|---|
| Créer | appliquer | appliquer — une catégorie vide ne se voit pas, et les brouillons en ont besoin (décision Oscar) |
| Renommer / modifier | appliquer | **garder** (action `term.update`) |
| Supprimer | appliquer | **garder** (action `term.delete`) |

La création implicite d'un terme par une écriture d'article (`categories`/`tags` par nom) suit la
même règle que « Créer » : appliquée.

### 2.3 Médias (`/media`)

| Action | Brouillon forcé désactivé | Brouillon forcé activé |
|---|---|---|
| Téléverser | appliquer | appliquer — un fichier non utilisé ne se voit pas |
| Modifier (titre, texte alternatif, légende) | appliquer | **garder** (action `media.update`) |
| Supprimer | appliquer | **garder** (action `media.delete`) |

### 2.4 Redirections (`/redirects`)

| Action | Brouillon forcé désactivé | Brouillon forcé activé |
|---|---|---|
| Créer | appliquer | **garder** (action `redirect.create`) |
| Supprimer | appliquer | **garder** (action `redirect.delete`) |

### 2.5 Hors table (jamais gardées)

`PUT /field-schema` (calibration, ne touche pas le site visible), `POST /disconnect`,
`POST …/revisions/{id}/reject` et `POST /pending-actions/{id}/withdraw` (retirer sa propre
proposition ne change pas le site).

## 3. Réponse « gardé »

Même forme pour toutes les actions gardées. Code **`202 Accepted`**, sauf la révision d'article
(`content.update`), qui garde son `201` et ses champs actuels pour la compatibilité, et reçoit en
plus les champs communs.

```json
{
  "held": true,
  "pending_action_id": 812,
  "action": "content.delete",
  "object": { "kind": "content", "type": "article", "id": 115286 },
  "base_version": "2026-11-09T10:42:17",
  "review_url": "https://site.example/wp-admin/admin.php?page=arcadia-pending&action_id=812",
  "message": "Held for approval in WordPress. Nothing changed on the site."
}
```

- `held` : seul champ à tester pour savoir si l'écriture est appliquée. Il est **absent** d'une
  écriture appliquée (pas `false`), comme aujourd'hui pour `force_draft_applied`.
- `action` : une des valeurs de la table (`content.update`, `content.featured_image`,
  `content.delete`, `term.update`, `term.delete`, `media.update`, `media.delete`,
  `redirect.create`, `redirect.delete`).
- `object.kind` : `content` | `term` | `media` | `redirect`. `object.type` : type de publication,
  taxonomie (`category`, `post_tag`) ou `attachment` ; `redirect` pour une redirection. Pour
  `redirect.create`, `object.id` vaut `null`.
- `base_version` : la version de l'objet sur laquelle la proposition a été calculée —
  `post_modified_gmt` pour un article ou un média ; pour un terme ou une redirection, un hash de
  ses champs. `null` pour `redirect.create`.
- `content.update` garde aussi `revision_created`, `revision_id` (= `pending_action_id`),
  `revision_version`, `preview_url`, `original_post_status`.
- Une nouvelle proposition de la **même action sur le même objet** remplace la précédente,
  qui passe en `superseded` (comportement actuel des révisions, étendu à toutes les actions).

## 4. Vie d'une action en attente

| Statut | Sens | Qui le pose |
|---|---|---|
| `pending` | en attente de validation | le plugin, à la création |
| `approved` | validée et appliquée | l'humain, dans WordPress |
| `rejected` | refusée | l'humain, dans WordPress (avec une note facultative) |
| `stale` | l'objet a changé depuis la proposition : **l'approbation est refusée** (C04) | le plugin, au moment où on tente de valider, ou quand l'objet est supprimé |
| `superseded` | remplacée par une proposition plus récente | le plugin |
| `withdrawn` | retirée par Arcadia | Arcadia, par `POST /pending-actions/{id}/withdraw` |

Règle C04 : à la validation (écran unitaire **et** validation groupée), le plugin compare
`base_version` à la version actuelle de l'objet. Si elles diffèrent, rien n'est appliqué, l'action
passe en `stale`, et l'écran dit pourquoi. Il n'y a pas de « valider quand même » dans WordPress :
c'est Arcadia qui repropose sur la nouvelle version.

Suppression appliquée d'un article : ses actions en attente passent en `stale`.

### Lecture par Arcadia

- `GET /pending-actions?status=<statut>&object_kind=<kind>&object_id=<id>` (filtres facultatifs) :
  liste, la plus récente d'abord. Chaque entrée : les champs de la réponse « gardé » +
  `status`, `created_gmt`, `decided_gmt`, `decided_by` (identifiant WordPress), `decision_notes`.
- Les routes actuelles `…/{id}/revisions` restent, et ne listent que `content.update`.
- `POST /pending-actions/{id}/withdraw` : `200` → `withdrawn` ; `409 action_not_pending` si
  l'action n'est plus en attente.
- Scopes : lecture avec le scope de lecture de l'objet, retrait avec son scope d'écriture.

## 5. Ce que `/site-info` annonce

`/site-info` publie la table elle-même, pour que le serveur ne la devine pas :

```json
"held_actions": ["content.update", "content.featured_image", "content.delete",
                 "term.update", "term.delete", "media.update", "media.delete",
                 "redirect.create", "redirect.delete"]
```

Liste vide quand le brouillon forcé est désactivé. Ce champ s'ajoute à `force_draft` et
`pending_revisions` (inchangés). Le serveur doit dire AVANT le clic qu'une action sera gardée
(décision C16) : il lit cette liste.

## 6. Codes d'erreur nouveaux ou changés

| Code | HTTP | Quand |
|---|---|---|
| `status_not_supported_for_revision` | 422 | mise à jour d'un article live, brouillon forcé actif, `status` **différent** du statut actuel (plus levé pour un statut égal) |
| `pending_action_exists` | 409 | écriture sur un article qui a une suppression en attente ; `data.pending_action_id` |
| `post_trashed` | 409 | mise à jour d'un article à la corbeille |
| `action_not_pending` | 409 | retrait d'une action qui n'est plus `pending` |
| `pending_action_not_found` | 404 | identifiant inconnu |

## 7. Ce que le double partagé du serveur doit reproduire

Une case de la table = un comportement du double. Le double ne doit pas produire de réponse que
la table ne prévoit pas (exemple réel : `revision_created` pour un `status: draft` sur un article
live, que le plugin refuse en 422 depuis la 0.4.1).
