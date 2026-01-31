# AA Plugin WordPress

Plugin WordPress pour connecter un site WP à ArcadiaAgents.

## Guide des tâches

**Suivre `checklist.md`** pour savoir quelles tâches faire et dans quel ordre.
Cocher les cases au fur et à mesure de l'avancement.

## Documentation

- `docs/specs.md` - Spécifications techniques (copie locale)
- `checklist.md` - Liste des tâches à faire

### Source de vérité des specs

**Fichier maître :** `/Users/oscarsatre/Documents/ArcadiaAgents/docs/tasks_backlog/agent-seo/next/plugin-wp-specs.md`

**Après toute modification des specs :**
```bash
cp /Users/oscarsatre/Documents/ArcadiaAgents/docs/tasks_backlog/agent-seo/next/plugin-wp-specs.md docs/specs.md
```

Cela permet aux sessions Claude Code sans accès au repo ArcadiaAgents d'avoir le contexte complet.

## Conventions

- Préfixe : `arcadia_*` pour fonctions, classes, options WP
- WordPress Coding Standards (PHPCS)
- Licence : GPL v2+
- PHP 8.0+

## Architecture

```
arcadia-agents/
├── arcadia-agents.php       # Point d'entrée + hooks
├── includes/
│   ├── class-auth.php       # Validation JWT RS256
│   ├── class-api.php        # Endpoints REST
│   └── class-blocks.php     # Génération blocs Gutenberg/ACF
└── admin/
    └── settings.php         # Page admin WP
```

## Dev local

```bash
./start.sh   # Lance WordPress + MySQL + PHPMyAdmin
./stop.sh    # Arrête les containers
```

- WordPress : http://localhost:8080
- PHPMyAdmin : http://localhost:8081
- Health check : http://localhost:8080/wp-json/arcadia/v1/health

## Dépendances PHP

Pour la validation JWT, utiliser `firebase/php-jwt` :
```bash
composer require firebase/php-jwt
```
## Contexte

### Projet

Plugin WordPress pour connecter un site WP à ArcadiaAgents. Permet à l'agent SEO de publier/modifier du contenu automatiquement.

### Architecture

```
ArcadiaAgents (orchestrateur)
    │
    │ REST API + JWT
    ▼
Plugin WordPress (exécuteur)
    │
    ▼
Site WordPress du client
```

### Documents liés (repo ArcadiaAgents)

**PRD Agent SEO :** `/Users/oscarsatre/Documents/ArcadiaAgents/docs/tasks_backlog/agent-seo/prd.md`
- Lecture seule, sous responsabilité du repo ArcadiaAgents

### Principes clés

- **Agent orchestre, plugin exécute** - Le plugin est passif, il expose une API REST
- **JSON sémantique** - L'agent envoie du JSON structuré, le plugin le mappe vers les blocs WP
- **Multi-builders** - Adaptateurs pour ACF Blocks (MVP) + Gutenberg natif (MVP)
- **Sécurité JWT** - ArcadiaAgents signe, le plugin vérifie avec la public key