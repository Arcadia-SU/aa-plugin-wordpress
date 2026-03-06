# Sync - Context & Status Check

Tu es le point d'entrée quand l'utilisateur revient travailler sur le projet AA Plugin WordPress.
Ton objectif : lui donner une vue complète de l'état du projet pour qu'il puisse reprendre efficacement, même après une longue absence.

## Dossier maître des specs

**Chemin :** `/Users/oscarsatre/Documents/ArcadiaAgents/docs/tasks_backlog/agent-seo/plugin-wp-specs/`

Cette session lit directement les fichiers maîtres (pas de copie locale).

| Fichier | Contenu |
|---------|---------|
| `README.md` | Hub : purpose, protocole, liens |
| `backlog.md` | File d'attente actionnelle |
| `api-contract.md` | Endpoints, params, réponses |
| `auth.md` | JWT RS256, handshake, scopes |
| `content-model.md` | JSON schema blocs, mapping ACF, multi-builder |
| `code-review.md` | Audit code v2.0.1 (33 issues) |
| `decisions.md` | Historique des décisions validées |
| `dev-guide.md` | Guide dev, repo, CI/CD, publication WP.org |

## Étapes à exécuter

### Étape 1 : Intégrer le backlog (protocole pull)

Lis le fichier backlog :
```
/Users/oscarsatre/Documents/ArcadiaAgents/docs/tasks_backlog/agent-seo/plugin-wp-specs/backlog.md
```

- **Si vide** (pas de "Pending Items" ou section vide) : signale "backlog vide, plugin à jour".
- **Si items présents** :
  1. Affiche les items trouvés à l'utilisateur
  2. Intègre chaque item actionnable dans `docs/checklist.md` (dans la phase appropriée, ou dans une nouvelle phase si nécessaire)
  3. Vide le backlog en réécrivant le fichier avec le template vide :
     ```markdown
     # Plugin WordPress — Backlog

     > **Protocol:** AA session writes here. Plugin session reads, moves to local tracking, then clears.
     > **Empty backlog = plugin is up to date.**

     ---

     ## Pending Items

     _No pending items._
     ```
  4. Signale à l'utilisateur les items intégrés et où ils ont été placés dans la checklist

### Étape 2 : État du repo (en parallèle)

Lance ces vérifications en parallèle :

1. **Git status** : branche courante, fichiers modifiés/non suivis, état par rapport au remote
2. **Derniers commits** : `git log --oneline -10` pour voir l'activité récente
3. **Travail en cours non commité** : `git diff` + `git diff --cached` pour comprendre ce qui était en cours. Interprète les changements : "tu avais commencé à travailler sur X" plutôt que juste lister des fichiers.
4. **Checklist** : lis `docs/checklist.md` et résume quelles phases sont terminées, laquelle est en cours, et ce qui reste

### Étape 3 : Décisions récentes

Lis les dernières entrées du decisions log :
```
/Users/oscarsatre/Documents/ArcadiaAgents/docs/tasks_backlog/agent-seo/plugin-wp-specs/decisions.md
```

Pour rappeler à l'utilisateur les décisions récentes et leur contexte.

### Étape 4 : Synthèse

Présente un résumé structuré :

```
## État du projet

### Backlog
- [Items intégrés ou "à jour"]

### Progression
- Phases terminées : ...
- Phase en cours : ...
- Prochaine phase : ...

### Travail en cours
- [Interprétation des changements non commités]

### Next steps (par priorité)
1. ...
2. ...
3. ...
```
