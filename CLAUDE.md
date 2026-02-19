# AA Plugin WordPress

Plugin WordPress pour connecter un site WP à ArcadiaAgents.

## Guide des tâches

**Suivre `docs/checklist.md`** pour savoir quelles tâches faire et dans quel ordre.
Cocher les cases au fur et à mesure de l'avancement.

## Documentation

- `docs/specs.md` - Spécifications techniques (copie locale)
- `docs/checklist.md` - Liste des tâches à faire
- `docs/checklist-test-site-client.md` - Checklist test manuel site client (ACF Pro)

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

## Build zip

```bash
cd arcadia-agents && composer install --no-dev && cd ..
rm -f arcadia-agents.zip && zip -r arcadia-agents.zip arcadia-agents/ -x "arcadia-agents/tests/*" -x "arcadia-agents/test/*" -x "arcadia-agents/.phpunit*" -x "arcadia-agents/phpunit.xml" -x "arcadia-agents/composer.json" -x "arcadia-agents/composer.lock"
composer install  # restaurer les dev dependencies après
```

## Tests

```bash
# PHPUnit (dans le container)
docker compose exec wordpress bash -c "cd /var/www/html/wp-content/plugins/arcadia-agents && ./vendor/bin/phpunit --testdox"

# Setup JWT pour tests manuels
docker compose exec wordpress bash -c "cd /var/www/html/wp-content/plugins/arcadia-agents/test && php mock-setup.php && php generate-jwt.php private_key.pem 'articles:read'"
```

- **Unit tests** : `arcadia-agents/tests/unit/` (PHPUnit, mocks WP)
- **Integration tests** : `test/integration/` (shell scripts + curl)
- Config integration : `cp test/config.example.sh test/config.sh`
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

## Guiding Principle: Structure Over Brevity

**Oscar's explicit preference:** "More structure is better for me, even if experienced developers might call it over-engineering. I tend to easily forget things, structure forces me to remember."

**Why:**
- **Guardrails**: Structure prevents mistakes (like TypeScript for JavaScript)
- **Navigation**: Always know where to look and where to put things
- **Confidence**: Less doubt = less paralysis = more productivity
- **Learning**: Structure teaches best practices by example

**Accepted trade-offs:**
- More files to create per feature (boilerplate)
- More jumps between files when debugging (indirection)
- Some conceptual duplication (domain + infrastructure versions)
- Heavier refactoring when changing decisions

**This is intentional.** When in doubt, add more structure, not less.