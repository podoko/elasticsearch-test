# Architecture technique — elasticsearch-test

## Problème à résoudre

Elasticsearch n'a ni transaction ni rollback. On ne peut pas enrouler un test dans une transaction annulée après coup, contrairement à ce que fait `dama/doctrine-test-bundle` avec Doctrine. Pourtant on veut le même résultat : chaque test démarre dans un état connu, sans pollution par les tests précédents, sans `sleep()`, et de façon transparente pour le code applicatif.

Deux contraintes propres à ES s'ajoutent :

1. **Pas de rollback natif** — on revient à l'état initial par copie d'index.
2. **Near-real-time** — un document indexé n'est pas visible immédiatement dans une recherche (sans `_refresh` explicite). La lib ne masque pas ce comportement.

---

## Stratégie retenue : Clone paresseux depuis l'index source

L'utilisateur est responsable de créer et peupler ses index avant la suite de tests (via `fos:elastica:populate`, fixtures Symfony, ou tout autre moyen). Le bundle part du principe que ces index préexistent avec le bon mapping et les fixtures souhaitées.

Lors de la **première opération d'écriture** dans un test, l'API `_clone` d'Elasticsearch copie l'index source (`posts`) vers un index de travail (`posts_<token>`). Les tests qui ne font que lire n'ont aucun coût — ils interrogent directement l'index source.

```
posts   (source préexistante, write-blocked pendant le clone)
  │
  ├── _clone ──▶  posts_1   (worker 1, test A — sur première écriture)
  ├── _clone ──▶  posts_1   (worker 1, test B — sur première écriture)
  └── _clone ──▶  posts_2   (worker 2, test C — sur première écriture)
```

Le `_clone` ES repose sur des **hardlinks de segments Lucene** : la copie est quasi-instantanée quelle que soit la taille de l'index. La suppression du clone en fin de test est également rapide (pas de flush disque).

### Write-block requis par `_clone`

L'API `_clone` exige `index.blocks.write: true` sur la source. Le bundle pose ce verrou automatiquement dans `CloneResetStrategy::prepare()`, avant chaque clone. Le verrou est idempotent (plusieurs workers peuvent le poser sans conflit) et ne bloque que les écritures sur documents — les lectures et les changements de settings restent possibles.

Le verrou est retiré par :
- `TestSuiteFinishedSubscriber` en fin de suite propre (hors ParaTest)
- `register_shutdown_function()` en cas de `dd()` ou `exit()` (hors ParaTest)
- Le prochain lancement du script de setup, qui DELETE l'index (le DELETE bypasse le write-block)

### Cycle de vie d'un index géré

| Étape | Opération ES | Déclencheur |
|---|---|---|
| Avant les tests | `DELETE posts` + `PUT posts` + fixtures + `_refresh` | Script de setup lancé par l'utilisateur |
| Première écriture du test | write-block `posts` + `POST posts/_clone/posts_1` | `LazyCloneIndex::ensureCopy()` → `StaticState::copy()` |
| Lectures dans le test | requêtes sur `posts_1` (si cloné) ou `posts` (si pas encore cloné) | `LazyCloneIndex::getName()` |
| Après chaque test | `DELETE posts_1` | `TestFinishedSubscriber` → `StaticState::rollbackTest()` |
| Fin de suite (non-ParaTest) | retire write-block sur `posts` | `TestSuiteFinishedSubscriber` |

---

## Flux d'initialisation

La lib repose sur deux phases d'initialisation distinctes qui se synchronisent via `StaticState`.

### Phase 1 — Extension PHPUnit (avant tout test)

`ElasticsearchTestExtension::bootstrap()` est appelé par PHPUnit avant le premier test, bien avant tout boot de kernel Symfony. Elle :

1. Positionne `$bootstrapped = true` — la sentinelle qui active le suffixage dans `RefreshForcingClient`.
2. Enregistre les subscribers PHPUnit : `TestPreparedSubscriber` (no-op), `TestFinishedSubscriber`, `TestSuiteFinishedSubscriber`.

À ce stade, `StaticState` n'est pas encore initialisé. Les subscribers vérifient `StaticState::isInitialized()` avant d'agir.

### Phase 2 — Boot du kernel Symfony

Quand le kernel Symfony démarre (au premier accès à `static::getContainer()` dans un test), `PodokoElasticsearchTestBundle::boot()` force l'instanciation eager de `elasticsearch_test.static_state_initializer`. Le constructeur de `StaticStateInitializer` :

1. Appelle `StaticState::initialize()` avec le client admin, la liste des index gérés, la stratégie de reset et l'URL ES.
2. Hors ParaTest : appelle `StaticState::unlockSourceIndexes()` (crash recovery SIGKILL) et enregistre un `register_shutdown_function` pour le même appel.

`StaticState` est alors prêt. Les prochains appels à `LazyCloneIndex::ensureCopy()` déclencheront les clones selon les besoins.

### `LazyCloneIndex` — proxy stateless

`LazyCloneIndex` étend `FOS\ElasticaBundle\Elastica\Index` pour la compatibilité de type avec les services FOSElastica. Son comportement :

- `getName()` retourne `$logicalName` (ex. `posts`) si aucun clone n'existe pour cet index, ou `posts_<token>` après le premier clone.
- Les méthodes d'écriture (`addDocument`, `deleteById`, `updateByQuery`, …) appellent `ensureCopy()` avant de déléguer au parent. `ensureCopy()` appelle `StaticState::copy()` qui appelle `CloneResetStrategy::prepare()`.
- Délibérément stateless : l'état de copie est centralisé dans `StaticState::$copiedIndexes` et partagé entre toutes les instances représentant le même index logique.

---

## Isolation PHPUnit-only — la sentinelle `$bootstrapped`

Le `FosClientDecoratorPass` remplace le client FOSElastica dans le conteneur Symfony. Ce conteneur est utilisé non seulement par les tests, mais aussi par les commandes Symfony et les requêtes HTTP en `APP_ENV=test`. Sans garde-fou, une commande `fos:elastica:populate` en env de test écrirait dans `posts_1` au lieu de `posts`.

La sentinelle `ElasticsearchTestExtension::isBootstrapped()` résout ce problème. Elle retourne `true` uniquement si l'extension PHPUnit a été bootstrappée dans le processus courant. `RefreshForcingClient::getIndex()` vérifie ce flag avant de suffixer :

```php
public function getIndex(string $name): BaseIndex
{
    if ($this->suffixIndexes && ElasticsearchTestExtension::isBootstrapped()) {
        return parent::getIndex($name . '_' . TestToken::get());
    }
    return parent::getIndex($name);
}
```

Une commande Symfony lancée en dehors de PHPUnit ne déclenche jamais le bootstrap → `isBootstrapped()` retourne `false` → `getIndex('posts')` retourne `posts`, comportement normal.

---

## Support ParaTest

ParaTest fork plusieurs processus workers. Chaque worker a son propre espace mémoire, donc son propre `$bootstrapped` et son propre token.

Le token worker est résolu par `TestToken::get()` dans l'ordre de priorité suivant :
1. Variable d'env `TEST_TOKEN` (entier court, injectée par ParaTest).
2. Variable d'env `UNIQUE_TEST_TOKEN` (UUID, disponible depuis paratest 6.x).
3. Valeur par défaut `'1'` (mode mono-processus PHPUnit).

Le token est sanitizé (seuls les caractères alphanumériques et tirets sont conservés) pour être valide dans un nom d'index Elasticsearch.

### Write-block et ParaTest

Plusieurs workers peuvent poser `index.blocks.write: true` sur la même source simultanément — c'est idempotent. Chaque worker clone vers son propre `posts_<token>` — pas de collision.

En revanche, **le unlock ne s'effectue pas depuis les workers ParaTest**. `StaticStateInitializer` saute l'appel `unlockSourceIndexes()` et ne pose pas de `register_shutdown_function` quand `PARATEST=1` est présent. La raison : si Worker A se termine et unlock `posts` pendant que Worker B est entre son write-block et son `_clone`, le clone échoue. La source reste write-blocked jusqu'au prochain lancement du script de setup (qui DELETE l'index).

### Pourquoi le suffixage est au runtime et non dans un CompilerPass

Le conteneur Symfony compilé est **mis en cache sur disque et partagé entre tous les workers**. Un CompilerPass s'exécute à la compilation, une seule fois, dans un processus arbitraire. Si on y figeait le token, tous les workers utiliseraient le token du premier processus à compiler → collisions.

En déléguant le suffixage à `RefreshForcingClient::getIndex()`, on résout le token au **moment de l'appel**, dans chaque processus worker, avec sa propre valeur de `TEST_TOKEN`.

### Isolation des tests unitaires de la lib

Les tests unitaires (`TestTokenTest`, `StaticStateTest`) manipulent `TEST_TOKEN` et `UNIQUE_TEST_TOKEN` via `putenv()`. Sous ParaTest, ces variables sont positionnées par le runner pour identifier chaque worker. Les tests unitaires **sauvegardent et restaurent** ces variables dans `setUp()`/`tearDown()` pour ne pas polluer les tests suivants dans le même processus worker.

---

## FosClientDecoratorPass en détail

Le pass effectue trois opérations à la compilation du conteneur :

1. **Fail-fast sur `use_alias: true`** — lit la config FOSElastica depuis `fos_elastica.config_source.container` (argument 0) et lève une `RuntimeException` si un index géré utilise des alias. Le suffixage de `getIndex()` bypasse la logique d'alias de FOSElastica, ce qui produirait des erreurs silencieuses.

2. **Remplacement de classe** — pour chaque service taggué `fos_elastica.client`, change `setClass()` en `RefreshForcingClient` et ajoute un appel `setSuffixIndexes(true)`.

3. **Auto-découverte des index** — si `managed_indexes` est vide dans la config du bundle, lit les services taggués `fos_elastica.index` pour construire la liste automatiquement.

---

## Pistes non implémentées

### TruncateResetStrategy

`delete_by_query { match_all }` sur le clone. Utile pour les index qui démarrent vides dans les tests (pas besoin de clone).

### RecreateResetStrategy

Suppression + recréation de l'index de travail à chaque test. Plus lent que le clone, mais ne nécessite pas que la source existe au préalable.

### SnapshotResetStrategy

Restauration d'un snapshot Elasticsearch natif. Adapté à un reset de suite complet (pas par test). Exige `path.repo` configuré sur le nœud ES, ce qui est impossible sur les clusters managés (Elastic Cloud, AWS OpenSearch).

### Support des alias FOSElastica (`use_alias: true`)

Le suffixage via `getIndex()` bypass la couche d'alias de FOSElastica. Pour supporter `use_alias`, il faudrait intercepter les appels à un niveau plus bas (au niveau des requêtes HTTP vers ES) ou recréer les alias sur les index clonés.

### Suites de tests distinctes pour index vide vs. index peuplé

Les tests fonctionnels actuels utilisent un index source peuplé (3 fixtures). Une suite distincte — ou des index distincts — permettrait de tester l'isolation sur un index source vide (cas où les tests partent d'une ardoise blanche).
