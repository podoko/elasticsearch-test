# Architecture — elasticsearch-dama

Document de réflexion vivant. À mettre à jour au fil des itérations.

---

## Problème à résoudre

Elasticsearch n'a ni transaction ni rollback. Pourtant on veut le même confort que
`dama/doctrine-test-bundle` : chaque test démarre dans un état connu, sans pollution
par les tests précédents, sans `sleep()`, et de façon transparente pour le code applicatif.

Deux contraintes structurantes propres à ES :

1. **Pas de rollback** → on revient à l'état initial par copie physique (clone).
2. **Near-real-time** → un document indexé n'est pas visible immédiatement dans une recherche
   (sauf si on force un `_refresh`). C'est à la charge du code applicatif ou du test de gérer ça.

---

## Stratégies évaluées

### A — Snapshot / Restore natif

Restaure un snapshot ES complet (données + mappings + settings) avant chaque test.

| | |
|---|---|
| ✅ | Restauration la plus fidèle |
| ❌ | Exige `path.repo` configuré côté nœud ES (impossible sur clusters managés) |
| ❌ | Ferme/supprime l'index pendant la restauration → inutilisable par test |
| ❌ | Prend plusieurs secondes par test |

**Verdict :** réservé à un reset de suite complet, pas par test.

### B1 — Clone depuis un seed figé ✅ **(retenue)**

Un index `<name>_seed` contient mappings + fixtures, bloqué en écriture.
Avant chaque test : `_clone` du seed vers `<name>_<token>` (hardlink de segments, quasi
instantané). Après chaque test : suppression du clone.

| | |
|---|---|
| ✅ | Rapide (hardlink) |
| ✅ | Fixtures préservées gratuitement — une fixture modifiée/supprimée par le test est annulée |
| ✅ | Isolation totale |
| ✅ | Compatible ParaTest (un clone par worker) |
| ⚠️ | Deux appels API par test (clone + delete) → surcoût de cluster state |

### B2 — Reindex par test

Copie via `_reindex` (doc par doc).

| | |
|---|---|
| ❌ | Beaucoup plus lent que `_clone` |

### C — Truncate (delete_by_query) + reseed

Vide l'index entre chaque test et recharge les fixtures.

| | |
|---|---|
| ✅ | Simple à coder |
| ❌ | Lent si fixtures volumineuses |
| ❌ | Ne préserve pas les fixtures : un test qui supprime une fixture casse les suivants si on ne reseed pas |

### D — Track & revert (analogue DAMA pur)

Décore le client pour journaliser chaque écriture et rejouer l'inverse au teardown.

| | |
|---|---|
| ✅ | Léger, pas de recréation d'index |
| ❌ | Impossible d'annuler un mapping créé dynamiquement |
| ❌ | Fragile sur les bulk, upserts, scripts |
| ❌ | Collisions en parallèle |

---

## Architecture retenue

### Vue d'ensemble

```
phpunit.xml
  └─ ElasticsearchDamaExtension (bootstrap)
       ├─ set bootstrapped = true  ← sentinelle "on est sous PHPUnit"
       └─ enregistre les subscribers :
            TestRunnerStartedSubscriber → StaticState::ensureSeedExists()
            TestPreparedSubscriber      → StaticState::beginTest()   (clone)
            TestFinishedSubscriber      → StaticState::rollbackTest() (delete)

Symfony kernel (APP_ENV=test)
  └─ PodokoElasticsearchDamaBundle
       ├─ FosClientDecoratorPass
       │    └─ remplace Elastica\Client par RefreshForcingClient
       │         + setSuffixIndexes(true)
       └─ StaticStateInitializer (service)
            └─ StaticState::initialize(adminClient, indexes, strategy, url)

RefreshForcingClient::getIndex('posts')
  ├─ si bootstrapped && suffixIndexes → parent::getIndex('posts_1')
  └─ sinon                            → parent::getIndex('posts')
```

### Modèle d'index

| Nom | Usage | Droits |
|---|---|---|
| `posts_seed` | Source de vérité partagée entre workers | write-blocked |
| `posts_1` | Index de travail du worker 1 (ParaTest) | read/write |
| `posts_2` | Index de travail du worker 2 (ParaTest) | read/write |

### Isolation PHPUnit-only

**Problème clé :** le conteneur Symfony compilé est mis en cache et partagé entre
tous les workers ParaTest. Un CompilerPass ne peut donc pas résoudre le token worker
(qui est une variable d'env différente par processus) — le résultat serait figé avec
la valeur du premier worker à compiler.

**Solution :** le suffixage est délégué à `RefreshForcingClient::getIndex()`, appelé
au **runtime** dans chaque processus worker. Le conteneur ne contient que l'instruction
"appeler `getIndex('posts')`" ; c'est l'override qui ajoute `_1`, `_2`, etc. selon
le `TEST_TOKEN` du processus courant.

De plus, le gate `ElasticsearchDamaExtension::isBootstrapped()` garantit que le
suffixage ne s'active que sous PHPUnit. Une commande Symfony ou une requête HTTP
en `APP_ENV=test` ne déclenche pas le bootstrap de l'extension PHPUnit → comportement
normal, sans redirection vers les index de test.

### Refresh

Le refresh après écriture est à la charge du code applicatif ou du test.
La lib ne force rien. Seule exception : `CloneResetStrategy::seed()` appelle
`_refresh` explicitement après le peuplement du seed (pour que les fixtures soient
visibles dans le clone).

---

## Pistes pour les prochaines itérations

### Tests fonctionnels

À créer dans `tests/Functional/` : mini kernel Symfony + FOSElastica sur un index
`posts`. Vérifier :
- isolation entre tests (document créé dans testA absent dans testB)
- fixtures préservées (fixture modifiée dans testA intacte dans testB)
- parallélisme ParaTest (4 workers, pas de collision)

### StaticStateInitializer — déclenchement

Actuellement le service n'est instancié que si quelque chose le demande au conteneur.
Il faut s'assurer qu'il est bien instancié avant le premier test. Options :
- Tag `kernel.event_listener` sur un event qui se déclenche tôt (ex: `kernel.request`)
  — mais ne couvre pas les commandes console sans requête HTTP.
- Tag `container.preload` (Symfony 4.4+) — force l'instanciation au boot.
- Appel explicite depuis `TestRunnerStartedSubscriber` via une factory statique.

La troisième option est la plus robuste car elle ne dépend pas du cycle de vie Symfony.

### Stratégies alternatives (non implémentées)

- `TruncateResetStrategy` : `delete_by_query match_all` + reseed via callback.
  Utile pour les index sans fixtures (tests qui partent d'un état vide).
- `RecreateResetStrategy` : suppression + recréation + `fos:elastica:populate`.
  Le plus simple à implémenter, le plus lent à l'exécution.
- `SnapshotResetStrategy` : reset complet de suite (pas par test).

### Gestion des mappings dans le seed

Actuellement `CloneResetStrategy` crée le seed sans mapping (ES l'infère).
Il faudra récupérer les mappings FOSElastica (via `fos_elastica.config_source.container`)
pour les injecter à la création du seed, afin d'avoir le bon mapping dès le départ.

### Support des alias FOSElastica (use_alias: true)

Si un index FOSElastica est configuré avec `use_alias: true`, FOSElastica écrit dans
un alias plutôt qu'un index direct. Notre approche de suffixage via `getIndex()` bypass
cette logique. À investiguer.
