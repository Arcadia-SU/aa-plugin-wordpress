# Rituel de déploiement

Le seul chemin autorisé vers un site client. Chaque étape existe parce qu'un des
garde-fous automatiques s'arrête là où elle commence : le build prouve que le zip
survit aux upgrades, mais seul ce rituel couvre ce que le build ne voit pas
(ACF Pro, le PHP réel du client, le contenu réel).

**Principe : preprod est notre canari.** Comme les canaux beta de Yoast ou les
rollouts progressifs d'ACF — aucune version n'atteint la prod sans avoir
encaissé la preprod d'abord.

## 0. Pré-requis (avant de toucher un site)

- [ ] `./build.sh` **vert de bout en bout** (17 checks, dont l'upgrade-path
      test depuis N-1 **et** depuis la plus vieille version déployée)
- [ ] L'entrée de changelog décrit bien ce que les sites vont recevoir
- [ ] Le zip déployé est **celui de `dist/`** (`dist/arcadia-agents-<version>.zip`),
      jamais un zip rebuildé à la main

## 1. Canari — preprod-iselection.vertuelle.com

- [ ] Uploader le zip (admin WP → Extensions → Ajouter → Téléverser →
      « Remplacer l'actuelle par la version téléversée »)
- [ ] `/wp-json/arcadia/v1/health` répond `ok` sur la **nouvelle version**
      (navigateur — la preprod est derrière un basic auth)
- [ ] Passe ACF rapide : `docs/checklist-test-site-client.md`
- [ ] **Trempage 24 h.** Pas de prod le même jour, sauf hotfix d'un site cassé
      (auquel cas le rollback est déjà pire que le risque).

## 2. Production — www.iselection.com puis www.trottinette-tout-terrain.fr

- [ ] J+1 : re-vérifier `/health` preprod (toujours `ok`, toujours la bonne version)
- [ ] Uploader le zip sur chaque site, **un site à la fois**
- [ ] Immédiatement après chaque upload :
      `curl -s https://<site>/wp-json/arcadia/v1/health`

## 3. Après coup (le jour même)

- [ ] Mettre à jour `test/upgrade/deployed-versions.conf` (hôte + version) —
      c'est ce qui dit au prochain build quels sauts d'upgrade tester
- [ ] Annoncer la release dans `backlog-for-backend.md` (protocole inter-repo)
- [ ] J+1 : `curl -s https://<site>/wp-json/arcadia/v1/health` sur les sites prod

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
