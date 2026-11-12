# Rituel de déploiement

Le seul chemin autorisé vers un site client. Chaque étape existe parce qu'un des
garde-fous automatiques s'arrête là où elle commence : le build prouve que le zip
survit aux upgrades, mais seul ce rituel couvre ce que le build ne voit pas
(ACF Pro, le PHP réel du client, le contenu réel).

**Principe : la préprod teste le site du client, le build teste le temps.**
La préprod a le PHP, le thème et ACF d'un vrai client : on y fait les tests
fonctionnels de la version. Elle n'a pas d'usage réel, donc attendre dessus ne
prouve rien de plus. Ce qu'une journée d'attente vérifiait (tâches quotidiennes,
mémoires temporaires de 24 h) est forcé par le build (#15, phase « un jour plus
tard »). Jusqu'au 2026-11-12, le rituel imposait 24 h de trempage sur la préprod ;
il est retiré parce qu'il ne testait que ces chemins, et qu'il laissait la prod
en retard (0.12.0 validée le 2026-11-08, prods toujours en 0.11.0 le 2026-11-12).

## 0. Pré-requis (avant de toucher un site)

- [ ] `./build.sh` **vert de bout en bout**, dont l'upgrade-path test depuis N-1
      **et** depuis la plus vieille version déployée, avec sa phase « un jour plus
      tard » (tâches cron lancées, mémoires temporaires expirées)
- [ ] L'entrée de changelog décrit bien ce que les sites vont recevoir
- [ ] Le zip déployé est **celui de `dist/`** (`dist/arcadia-agents-<version>.zip`),
      jamais un zip rebuildé à la main

## 1. Préprod — preprod-iselection.vertuelle.com

- [ ] Uploader le zip (admin WP → Extensions → Ajouter → Téléverser →
      « Remplacer l'actuelle par la version téléversée »)
- [ ] Mettre la version dans `test/upgrade/deployed-versions.conf`, puis
      `./check-sites.sh preprod-iselection.vertuelle.com` : `/health` sur la **nouvelle
      version**, connexion `arcadia-check` OK, blocs ACF enregistrés (accès :
      `~/Developer/secrets/wp-sites/<hôte>.env` sur le mini)
- [ ] **Tests fonctionnels de la version** sur la préprod (ce qu'elle change, de
      bout en bout : demandes d'Arcadia, écrans, effet sur le site), puis
      supprimer les éléments de test
- [ ] Passe ACF rapide : `docs/checklist-test-site-client.md`

## 2. Production — le jour même

- [ ] Uploader le zip sur chaque site, **un site à la fois** : d'abord
      www.iselection.com (celui qui écrit le plus avec Arcadia), puis les autres
- [ ] Immédiatement après chaque upload : mettre la conf à jour, puis
      `./check-sites.sh <site>`
- [ ] Un problème sur le premier site : rollback (plus bas) avant de toucher les autres

`./check-sites.sh` signale toute prod en retard sur la préprod, à chaque relevé.

## 3. Après coup (le jour même)

- [ ] Mettre à jour `test/upgrade/deployed-versions.conf` (hôte + version + env) —
      c'est ce qui dit au prochain build quels sauts d'upgrade tester
- [ ] `./publish-release.sh` — publie la **release GitHub** `vX.Y.Z` avec le zip
      de `dist/` (asset `arcadia-agents.zip`, notes = entrée de changelog).
      C'est la « dernière version » que l'app propose au téléchargement
      (`…/releases/latest/download/arcadia-agents.zip`) : le script refuse tant
      qu'un hôte `prod` de `deployed-versions.conf` ne répond pas la version sur
      `/health`, donc **après** la conf, jamais avant la prod
- [ ] Annoncer la release dans `backlog-for-backend.md` (protocole inter-repo)
- [ ] Le lendemain : `./check-sites.sh` (tous les sites ; un ✗ = la conf ou un site a dérivé)

## Rollback

`dist/` contient chaque zip released. En cas de comportement anormal :

1. Reprendre `dist/arcadia-agents-<version-précédente>.zip`
2. L'uploader via le même écran « Remplacer l'actuelle » — WordPress accepte le
   downgrade de fichiers sans toucher aux données
3. Vérifier `/health` (ancienne version), puis remettre
   `deployed-versions.conf` à jour

Si le zip d'une vieille version manque dans `dist/` (machine changée, purge) :
le rebuilder depuis le tag/commit git correspondant — worktree sur le tag,
`composer install --no-dev` (via le conteneur dev), puis `zip -r` avec les
mêmes exclusions que build.sh. C'est exactement ainsi que
`arcadia-agents-0.2.1.zip` et `arcadia-agents-0.3.0.zip` ont été reconstruits
le 2026-08-09.
