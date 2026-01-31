# AA Plugin WordPress

Plugin WordPress pour connecter un site WP à ArcadiaAgents.

## Guide des tâches

**Suivre `checklist.md`** pour savoir quelles tâches faire et dans quel ordre.
Cocher les cases au fur et à mesure de l'avancement.

## Documentation

- `docs/specs.md` - Spécifications techniques complètes
- `checklist.md` - Liste des tâches à faire (source de vérité)

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
