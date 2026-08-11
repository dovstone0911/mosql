### `README.md`

```markdown
# MoSQL

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.0-blue.svg)](https://php.net/)
[![Doctrine DBAL](https://img.shields.io/badge/doctrine--dbal-%5E3.0-brightgreen.svg)](https://www.doctrine-project.org/projects/dbal.html)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Packagist](https://img.shields.io/badge/packagist-dovstone%2Fmosql-orange.svg)](https://packagist.org/packages/dovstone/mosql)

---

**MoSQL** est une base de données NoSQL légère qui utilise MySQL, PostgreSQL ou SQLite comme backend. Elle combine la simplicité des bases NoSQL avec la puissance des bases relationnelles.

---

## 📖 Table des matières

1. [Installation](#installation)
2. [Configuration](#configuration)
3. [Premiers pas](#premiers-pas)
4. [Les identifiants : ID vs UID](#les-identifiants-id-vs-uid)
5. [INSERT - Ajouter des documents](#insert---ajouter-des-documents)
6. [SELECT - Rechercher des documents](#select---rechercher-des-documents)
7. [WHERE - Conditions simples](#where---conditions-simples)
8. [WHERE - Conditions avancées](#where---conditions-avancees)
9. [WHERE - Conditions imbriquées (AND/OR)](#where---conditions-imbriquees-andor)
10. [SELECT - Projection des champs](#select---projection-des-champs)
11. [ORDER BY - Trier les résultats](#order-by---trier-les-resultats)
12. [LIMIT - Limiter les résultats](#limit---limiter-les-resultats)
13. [JOIN - Les jointures](#join---les-jointures)
14. [UPDATE - Mettre à jour des documents](#update---mettre-a-jour-des-documents)
15. [DELETE - Supprimer des documents](#delete---supprimer-des-documents)
16. [Les méthodes FIND](#les-methodes-find)
17. [Les méthodes magiques](#les-methodes-magiques)
18. [Le cache](#le-cache)
19. [La pagination](#la-pagination)
20. [Le schéma dynamique](#le-schema-dynamique)
21. [Les utilitaires](#les-utilitaires)
22. [La gestion des exceptions](#la-gestion-des-exceptions)
23. [Exemple complet - Blog](#exemple-complet---blog)
24. [Exemple complet - E-commerce](#exemple-complet---e-commerce)
25. [FAQ](#faq)

---

## Installation

```bash
composer require dovstone/mosql
```

### Prérequis

- PHP 8.0 ou supérieur
- Extension PDO pour votre base de données (pdo_mysql, pdo_pgsql ou pdo_sqlite)
- Doctrine DBAL sera installé automatiquement

---

## Configuration

### MySQL

```php
$config = [
    'driver' => 'pdo_mysql',
    'host' => 'localhost',
    'port' => 3306,
    'dbname' => 'ma_base',
    'user' => 'root',
    'password' => 'secret',
    'charset' => 'utf8mb4',
];
```

### PostgreSQL

```php
$config = [
    'driver' => 'pdo_pgsql',
    'host' => 'localhost',
    'port' => 5432,
    'dbname' => 'ma_base',
    'user' => 'postgres',
    'password' => 'secret',
];
```

### SQLite

```php
// Fichier
$config = [
    'driver' => 'pdo_sqlite',
    'path' => '/tmp/ma_base.db',
];

// En mémoire (pour les tests)
$config = [
    'driver' => 'pdo_sqlite',
    'memory' => true,
];
```

### Options disponibles

```php
$options = [
    'uid_length' => 8,              // Longueur de l'UID (8, 9 ou 10)
    'table_prefix' => 'app_',       // Préfixe des tables (ex: app_users)
    'auto_create_schema' => true,   // Création automatique du schéma
    'cache_enabled' => false,       // Activer le cache
    'cache_ttl' => 3600,            // Durée de vie du cache (secondes)
];
```

---

## Premiers pas

```php
<?php

use Dovstone\MoSQL\MoSQL;

// 1. Configuration
$config = [
    'driver' => 'pdo_mysql',
    'host' => 'localhost',
    'dbname' => 'monapp',
    'user' => 'root',
    'password' => '',
];

// 2. Création d'une collection (table)
$users = new MoSQL('users', $config);

// 3. Insertion d'un document
$uid = $users->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'age' => 28,
    'address' => [
        'city' => 'Paris',
        'zip' => '75001',
        'country' => 'France'
    ],
    'preferences' => [
        'theme' => 'dark',
        'notifications' => true
    ]
]);

echo "UID généré : {$uid}\n";

// 4. Recherche par UID
$user = $users->find($uid);
echo "Utilisateur : {$user['name']}\n";

// 5. Recherche avec conditions
$results = $users
    ->where('age', '>', 18)
    ->where('address.city', '=', 'Paris')
    ->orderBy('name', 'ASC')
    ->find();

foreach ($results as $user) {
    echo "{$user['name']} - {$user['email']}\n";
}
```

---

## Les identifiants : ID vs UID

MoSQL utilise deux identifiants distincts :

| Type | Nom | Type | Utilisation |
|------|-----|------|-------------|
| **Interne** | `id` | `INT AUTO_INCREMENT` | Jointures, clés étrangères, index |
| **Publique** | `uid` | `VARCHAR(8-10)` | API, URLs, références publiques |

### Pourquoi cette double identification ?

```php
// ✅ L'UID est exposé dans l'API
$user = $users->find('A7kR9qW2');

// ❌ L'ID n'est jamais exposé
// $user = $users->findById(1); // Usage interne uniquement

// ✅ Les jointures utilisent l'ID (plus rapide)
$users->leftJoin('orders', 'uid', '=', 'user_uid');
// → LEFT JOIN orders ON users.id = orders.user_id
```

### Conversion automatique UID → ID

```php
// Vous utilisez des UIDs dans vos requêtes
$orders->where('user_uid', '=', 'A7kR9qW2');
// → WHERE user_id = 1 (converti automatiquement)

// La jointure utilise aussi des UIDs
$users->leftJoin('orders', 'uid', '=', 'user_uid');
// → LEFT JOIN orders ON users.id = orders.user_id
```

### Méthodes de conversion

```php
// Trouver l'ID à partir d'un UID
$id = $users->findIdByUid('A7kR9qW2'); // 1

// Trouver l'UID à partir d'un ID
$uid = $users->findUidById(1); // 'A7kR9qW2'

// Trouver plusieurs IDs à partir d'UIDs
$ids = $users->findIdsByUids(['A7kR9qW2', 'B8sT4vX3']);
// ['A7kR9qW2' => 1, 'B8sT4vX3' => 2]

// Trouver plusieurs UIDs à partir d'IDs
$uids = $users->findUidsByIds([1, 2]);
// [1 => 'A7kR9qW2', 2 => 'B8sT4vX3']
```

---

## INSERT - Ajouter des documents

### Insertion simple (UID généré automatiquement)

```php
$uid = $users->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'age' => 28,
]);

// UID généré : "A7kR9qW2"
```

### Insertion avec UID personnalisé

```php
$uid = $users->insert([
    'uid' => 'CUSTOM123',
    'name' => 'Bob',
    'email' => 'bob@example.com',
    'age' => 32,
]);
```

### Insertion avec données imbriquées

```php
$uid = $users->insert([
    'name' => 'Charlie',
    'email' => 'charlie@example.com',
    'address' => [
        'city' => 'Paris',
        'zip' => '75001',
        'country' => 'France'
    ],
    'preferences' => [
        'theme' => 'dark',
        'notifications' => true,
        'language' => 'fr'
    ],
    'tags' => ['developer', 'php', 'nosql']
]);

// address, preferences et tags sont stockés en JSON
```

### Insertion multiple

```php
$uids = $users->insertMany([
    ['name' => 'David', 'age' => 25],
    ['name' => 'Eve', 'age' => 30],
    ['name' => 'Frank', 'age' => 28],
]);

// Retourne un tableau des UIDs générés
// ['B8sT4vX3', 'C9uM5wY4', 'D0vN6zZ5']
```

### Insertion avec tous les types de données

```php
$uid = $users->insert([
    'name' => 'Grace',
    'email' => 'grace@example.com',
    'age' => 28,
    'salary' => 3500.50,
    'is_active' => true,
    'birthday' => '1995-06-15',
    'profile' => [
        'bio' => 'Développeuse PHP',
        'avatar' => 'https://example.com/avatar.jpg'
    ],
    'score' => 42,
    'tags' => ['php', 'symfony', 'nosql']
]);

// Les types sont automatiquement détectés :
// name → VARCHAR
// age → INT
// salary → FLOAT
// is_active → BOOLEAN
// birthday → DATETIME
// profile → JSON
```

---

## SELECT - Rechercher des documents

### Trouver tous les documents

```php
$allUsers = $users->findAll();
// Tous les utilisateurs
```

### Trouver par UID

```php
$user = $users->find('A7kR9qW2');
// Un utilisateur ou null
```

### Trouver par ID (interne)

```php
$user = $users->findById(1);
// Un utilisateur ou null
```

### Trouver le premier ou le dernier

```php
$firstUser = $users->first();
$lastUser = $users->last();
```

### Recherche avec conditions

```php
$users = $users->where('age', '>', 18)->find();
// Tous les utilisateurs de plus de 18 ans

$user = $users->where('email', '=', 'alice@example.com')->findOne();
// Un seul utilisateur
```

### Recherche avec plusieurs conditions

```php
$users = $users
    ->where('age', '>', 18)
    ->where('status', '=', 'active')
    ->find();
```

### Recherche avec tri et limite

```php
$users = $users
    ->where('age', '>', 18)
    ->orderBy('name', 'ASC')
    ->limit(10)
    ->find();
```

---

## WHERE - Conditions simples

### Égalité (=)

```php
// Âge exactement 18
$users->where('age', '=', 18)->find();

// Nom exactement "Alice"
$users->where('name', '=', 'Alice')->find();

// Email exactement "alice@example.com"
$users->where('email', '=', 'alice@example.com')->find();
```

### Différent (!= ou <>)

```php
// Âge différent de 18
$users->where('age', '!=', 18)->find();
$users->where('age', '<>', 18)->find();

// Statut différent de "banned"
$users->where('status', '!=', 'banned')->find();
```

### Supérieur (>)

```php
// Âge supérieur à 18
$users->where('age', '>', 18)->find();

// Salaire supérieur à 3000
$users->where('salary', '>', 3000)->find();

// Score supérieur à 50
$users->where('score', '>', 50)->find();
```

### Supérieur ou égal (>=)

```php
// Âge au moins 18 ans
$users->where('age', '>=', 18)->find();

// Prix au moins 100
$products->where('price', '>=', 100)->find();
```

### Inférieur (<)

```php
// Âge inférieur à 18
$users->where('age', '<', 18)->find();

// Stock inférieur à 10
$products->where('stock', '<', 10)->find();
```

### Inférieur ou égal (<=)

```php
// Âge maximum 18 ans
$users->where('age', '<=', 18)->find();

// Prix maximum 50
$products->where('price', '<=', 50)->find();
```

### LIKE (recherche partielle)

```php
// Commence par "John"
$users->whereLike('name', 'John%')->find();

// Contient "son"
$users->whereLike('name', '%son%')->find();

// Termine par "son"
$users->whereLike('name', '%son')->find();

// Avec OR
$users
    ->whereLike('name', '%John%')
    ->orWhereLike('name', '%Jane%')
    ->find();
```

### IN (dans une liste)

```php
// Statut dans la liste
$users->whereIn('status', ['active', 'pending', 'new'])->find();

// IDs dans la liste
$users->whereIn('id', [1, 2, 3, 4, 5])->find();

// UIDs dans la liste
$users->whereIn('uid', ['A7...', 'B8...', 'C9...'])->find();

// Rôles dans la liste
$users->whereIn('role', ['admin', 'moderator'])->find();
```

### NOT IN (pas dans une liste)

```php
// Statut pas dans la liste
$users->whereNotIn('status', ['banned', 'deleted'])->find();

// Rôles exclus
$users->whereNotIn('role', ['guest', 'visitor'])->find();

// IDs exclus
$users->whereNotIn('id', [1, 2, 3])->find();
```

### BETWEEN (entre deux valeurs)

```php
// Âge entre 18 et 65
$users->whereBetween('age', 18, 65)->find();

// Prix entre 50 et 100
$products->whereBetween('price', 50, 100)->find();

// Date entre deux dates
$orders->whereBetween('created_at', '2024-01-01', '2024-12-31')->find();
```

### IS NULL / IS NOT NULL

```php
// Email n'est pas renseigné
$users->whereNull('email')->find();

// Email est renseigné
$users->whereNotNull('email')->find();

// Date de suppression non définie
$users->whereNull('deleted_at')->find();

// Date de suppression définie
$users->whereNotNull('deleted_at')->find();
```

---

## WHERE - Conditions avancées

### OR (OU)

```php
// Statut "active" OU "pending"
$users
    ->where('status', '=', 'active')
    ->orWhere('status', '=', 'pending')
    ->find();

// Âge < 18 OU statut "student"
$users
    ->where('age', '<', 18)
    ->orWhere('status', '=', 'student')
    ->find();
```

### Combinaison AND / OR simple

```php
// Âge > 18 ET (statut "active" OU "pending")
$users
    ->where('age', '>', 18)
    ->andWhere(function($q) {
        $q->where('status', '=', 'active')
          ->orWhere('status', '=', 'pending');
    })
    ->find();

// SQL: WHERE age > 18 AND (status = 'active' OR status = 'pending')
```

### Combinaison AND / OR complexe

```php
// (status = 'active' OR status = 'pending') 
// AND (age >= 18 OR role = 'admin')
$users
    ->andWhere(function($q) {
        $q->where('status', '=', 'active')
          ->orWhere('status', '=', 'pending');
    })
    ->andWhere(function($q) {
        $q->where('age', '>=', 18)
          ->orWhere('role', '=', 'admin');
    })
    ->find();

/* SQL:
WHERE (status = 'active' OR status = 'pending')
  AND (age >= 18 OR role = 'admin')
*/
```

### Conditions imbriquées profondes

```php
$results = $users
    ->where('deleted_at', '=', null)
    ->andWhere(function($q) {
        $q->where('role', '=', 'admin')
          ->orWhere(function($sub) {
              $sub->where('role', '=', 'moderator')
                  ->where('permissions', 'LIKE', '%delete%');
          });
    })
    ->orWhere(function($q) {
        $q->where('role', '=', 'user')
          ->where('verified', '=', true)
          ->whereBetween('age', 18, 65);
    })
    ->find();

/* SQL:
WHERE deleted_at IS NULL 
  AND (
    role = 'admin' 
    OR (role = 'moderator' AND permissions LIKE '%delete%')
  ) 
  OR (
    role = 'user' 
    AND verified = 1 
    AND age BETWEEN 18 AND 65
)
*/
```

### OR extérieur

```php
// (age > 18 AND status = 'active') OR (role = 'admin')
$users
    ->andWhere(function($q) {
        $q->where('age', '>', 18)
          ->where('status', '=', 'active');
    })
    ->orWhere(function($q) {
        $q->where('role', '=', 'admin');
    })
    ->find();

/* SQL:
WHERE (age > 18 AND status = 'active')
  OR (role = 'admin')
*/
```

### Champs imbriqués (dot notation)

```php
// Recherche dans address.city
$users->where('address.city', '=', 'Paris')->find();

// Recherche dans preferences.theme
$users->where('preferences.theme', '=', 'dark')->find();

// Recherche dans profile.bio avec LIKE
$users->where('profile.bio', 'LIKE', '%développeur%')->find();

// Combiné avec d'autres conditions
$users
    ->where('address.city', '=', 'Paris')
    ->where('preferences.notifications', '=', true)
    ->find();

// Avec OR
$users
    ->where('address.city', '=', 'Paris')
    ->orWhere('address.city', '=', 'Lyon')
    ->find();
```

### Conditions avec plusieurs valeurs

```php
// WHERE (age = 18 OR age = 20 OR age = 25)
$users->whereIn('age', [18, 20, 25])->find();

// WHERE (status != 'banned' AND status != 'deleted')
$users->whereNotIn('status', ['banned', 'deleted'])->find();

// WHERE (age BETWEEN 18 AND 30)
$users->whereBetween('age', 18, 30)->find();
```

---

## SELECT - Projection des champs

### Sélection de champs spécifiques

```php
// Seulement le nom et l'email
$users = $users
    ->select(['name', 'email'])
    ->find();

// Résultat : [['name' => 'Alice', 'email' => '...'], ...]

// Avec conditions
$users = $users
    ->where('age', '>', 18)
    ->select(['name', 'email', 'age'])
    ->find();
```

### Sélection avec alias

```php
// Dans les jointures
$results = $users
    ->leftJoin('orders', 'uid', '=', 'user_uid')
    ->select([
        'users.name as user_name',
        'users.email',
        'orders.total as order_total'
    ])
    ->find();
```

### Exclure des champs

```php
// Sélectionner tout sauf un champ
// (pas nativement supporté, utiliser select avec tous les champs sauf un)
$users = $users
    ->select(['id', 'uid', 'name', 'email', 'age'])
    ->find();
```

### Projeter des champs imbriqués

```php
// Avec les champs JSON, on peut sélectionner le champ entier
$users = $users
    ->select(['name', 'address', 'preferences'])
    ->find();

// Ou sélectionner via les opérateurs JSON
// (à faire via SQL brut si besoin)
```

---

## ORDER BY - Trier les résultats

### Tri ascendant (A-Z, 0-9)

```php
// Par nom (A-Z)
$users->orderBy('name', 'ASC')->find();

// Par âge (croissant)
$users->orderBy('age', 'ASC')->find();

// Par date de création (plus ancien en premier)
$users->orderBy('created_at', 'ASC')->find();
```

### Tri descendant (Z-A, 9-0)

```php
// Par nom (Z-A)
$users->orderBy('name', 'DESC')->find();

// Par âge (décroissant)
$users->orderBy('age', 'DESC')->find();

// Par date de création (plus récent en premier)
$users->orderBy('created_at', 'DESC')->find();
```

### Tris multiples

```php
// Par âge descendant, puis par nom ascendant
$users
    ->orderBy('age', 'DESC')
    ->orderBy('name', 'ASC')
    ->find();

// Par statut, puis date de création
$users
    ->orderBy('status', 'ASC')
    ->orderBy('created_at', 'DESC')
    ->find();
```

### Tri avec conditions

```php
$users
    ->where('age', '>', 18)
    ->orderBy('age', 'DESC')
    ->orderBy('name', 'ASC')
    ->find();
```

### Tri sur champs JSON

```php
// Tri sur un champ dans un objet JSON
$users->orderBy('address.city', 'ASC')->find();
$users->orderBy('preferences.theme', 'DESC')->find();
```

---

## LIMIT - Limiter les résultats

### Limite simple

```php
// Les 10 premiers
$users->limit(10)->find();

// Les 50 premiers
$users->limit(50)->find();
```

### Limite avec offset

```php
// 10 éléments à partir du 20ème
$users->limit(10, 20)->find();

// 10 par page, page 3 (offset = 20)
$page = 3;
$perPage = 10;
$users->limit($perPage, ($page - 1) * $perPage)->find();
```

### Offset seul

```php
// 10 à partir de la position 20
$users
    ->limit(10)
    ->offset(20)
    ->find();
```

### Limite avec tri

```php
// Les 10 utilisateurs les plus âgés
$users
    ->orderBy('age', 'DESC')
    ->limit(10)
    ->find();
```

### Pagination avec limit/offset

```php
// Page 1 : 10 premiers
$users->limit(10)->find();

// Page 2 : 10 suivants
$users->limit(10, 10)->find();

// Page 3 : 10 suivants
$users->limit(10, 20)->find();
```

---

## JOIN - Les jointures

### INNER JOIN

```php
// Utilisateurs avec leurs commandes
$results = $users
    ->join('orders', 'uid', '=', 'user_uid')
    ->select(['users.name', 'orders.total'])
    ->find();

// SQL: INNER JOIN orders ON users.id = orders.user_id
```

### LEFT JOIN

```php
// Tous les utilisateurs et leurs commandes (éventuellement vides)
$results = $users
    ->leftJoin('orders', 'uid', '=', 'user_uid')
    ->select(['users.name', 'orders.total'])
    ->find();

// SQL: LEFT JOIN orders ON users.id = orders.user_id
```

### RIGHT JOIN

```php
// Toutes les commandes et leurs utilisateurs
$results = $users
    ->rightJoin('orders', 'uid', '=', 'user_uid')
    ->select(['users.name', 'orders.total'])
    ->find();

// SQL: RIGHT JOIN orders ON users.id = orders.user_id
```

### Jointures multiples

```php
// Utilisateurs + commandes + produits
$results = $users
    ->leftJoin('orders', 'uid', '=', 'user_uid')
    ->leftJoin('products', 'orders.product_uid', '=', 'uid')
    ->select([
        'users.name as user',
        'orders.total',
        'products.name as product',
        'orders.created_at'
    ])
    ->where('orders.total', '>', 100)
    ->orderBy('orders.created_at', 'DESC')
    ->find();

/* SQL:
SELECT users.name as user, orders.total, products.name as product, orders.created_at
FROM users
LEFT JOIN orders ON users.id = orders.user_id
LEFT JOIN products ON orders.product_uid = products.id
WHERE orders.total > 100
ORDER BY orders.created_at DESC
*/
```

### Jointure avec conditions

```php
// Utilisateurs actifs avec commandes récentes
$results = $users
    ->leftJoin('orders', 'uid', '=', 'user_uid')
    ->where('users.status', '=', 'active')
    ->where('orders.created_at', '>', '2024-01-01')
    ->select(['users.name', 'orders.total'])
    ->find();
```

### Jointure avec alias

```php
// Auto-jointure (ex: utilisateurs et leur manager)
$results = $users
    ->leftJoin('users', 'manager_id', '=', 'id')
    ->select([
        'users.name as employee',
        'users_manager.name as manager'
    ])
    ->find();
```

---

## UPDATE - Mettre à jour des documents

### Mise à jour avec conditions

```php
// Mettre à jour les utilisateurs de moins de 18 ans
$users
    ->where('age', '<', 18)
    ->update(['status' => 'minor']);

// Mettre à jour avec plusieurs champs
$users
    ->where('email', '=', 'alice@example.com')
    ->update([
        'age' => 29,
        'status' => 'active',
        'updated_at' => date('Y-m-d H:i:s')
    ]);
```

### Mise à jour par UID

```php
// Mettre à jour un utilisateur par son UID
$users->updateByUid('A7kR9qW2', [
    'name' => 'Alice Martin',
    'age' => 29,
    'status' => 'active'
]);
```

### Mise à jour par ID (interne)

```php
// Mettre à jour par ID
$users->updateById(1, ['status' => 'active']);
```

### Mise à jour avec conditions complexes

```php
// Mettre à jour les utilisateurs avec conditions imbriquées
$users
    ->where('age', '>', 18)
    ->andWhere(function($q) {
        $q->where('status', '=', 'pending')
          ->orWhere('status', '=', 'new');
    })
    ->update(['status' => 'active']);
```

### UPSERT (Insert or Update)

```php
// Si l'UID existe → update, sinon → insert
$uid = $users->upsert([
    'uid' => 'A7kR9qW2',
    'name' => 'Alice Martin',
    'email' => 'alice.martin@example.com',
    'age' => 29,
    'status' => 'active'
]);

// Si l'UID n'existe pas → insert
$uid = $users->upsert([
    'name' => 'Bob',
    'email' => 'bob@example.com',
    'age' => 25
]);
// UID généré automatiquement
```

---

## DELETE - Supprimer des documents

### Suppression avec conditions

```php
// Supprimer les utilisateurs de moins de 18 ans
$users->where('age', '<', 18)->delete();

// Supprimer avec plusieurs conditions
$users
    ->where('status', '=', 'banned')
    ->where('age', '>', 65)
    ->delete();
```

### Suppression par UID

```php
// Supprimer un utilisateur par son UID
$users->deleteByUid('A7kR9qW2');
```

### Suppression par ID (interne)

```php
// Supprimer par ID
$users->deleteById(1);
```

### Suppression avec conditions complexes

```php
// Supprimer avec conditions imbriquées
$users
    ->where('status', '=', 'banned')
    ->orWhere('status', '=', 'deleted')
    ->delete();

// Supprimer avec AND/OR
$users
    ->where('age', '<', 18)
    ->andWhere(function($q) {
        $q->where('status', '=', 'pending')
          ->orWhere('status', '=', 'new');
    })
    ->delete();
```

### Supprimer tous les documents

```php
// ⚠️ Supprime tous les utilisateurs
$users->where('id', '>', 0)->delete();

// Ou utiliser truncate pour vider la table
$users->truncate();
```

---

## Les méthodes FIND

### FIND de base

```php
// Trouver par UID (API publique)
$user = $users->find('A7kR9qW2');
// Retourne le document ou null

// Trouver par UID (avec exception)
$user = $users->findOrFail('A7kR9qW2');
// Retourne le document ou lance DocumentNotFoundException

// Trouver par ID (interne)
$user = $users->findById(1);
// Retourne le document ou null

// Trouver par ID (avec exception)
$user = $users->findByIdOrFail(1);
// Retourne le document ou lance DocumentNotFoundException
```

### FIND multiples

```php
// Tous les documents
$allUsers = $users->findAll();

// Premier document (le plus ancien)
$firstUser = $users->first();

// Dernier document (le plus récent)
$lastUser = $users->last();
```

### FIND par critères (style Symfony)

```php
// Critères simples
$users = $users->findBy(['age' => 18, 'status' => 'active']);

// Avec tri
$users = $users->findBy(
    ['age' => 18],
    ['name' => 'ASC', 'created_at' => 'DESC']
);

// Avec limite et offset
$users = $users->findBy(
    ['status' => 'active'],
    ['created_at' => 'DESC'],
    10,   // limit
    20    // offset
);

// Un seul résultat
$user = $users->findOneBy(['email' => 'alice@example.com']);
```

### FIND avec opérateurs dans les critères

```php
// findBy avec opérateurs (via tableau)
$users = $users->findBy([
    'age' => ['>', 18],
    'status' => ['=', 'active']
]);

// Un seul résultat avec opérateurs
$user = $users->findOneBy([
    'email' => ['=', 'alice@example.com'],
    'verified' => ['=', true]
]);
```

---

## Les méthodes magiques

### findBy{Field}

```php
// findByAge(18) → WHERE age = 18
$users = $users->findByAge(18);

// findByEmail('alice@example.com') → WHERE email = 'alice@example.com'
$user = $users->findByEmail('alice@example.com');

// findByStatus('active') → WHERE status = 'active'
$users = $users->findByStatus('active');

// findByRole('admin') → WHERE role = 'admin'
$admins = $users->findByRole('admin');
```

### findBy{Field}{Operator}

```php
// Opérateurs supportés :
// GreaterThan (>)
// LessThan (<)
// GreaterThanOrEqual (>=)
// LessThanOrEqual (<=)
// Like (LIKE)
// NotLike (NOT LIKE)
// In (IN)
// NotIn (NOT IN)

// AGE
$users = $users->findByAgeGreaterThan(18);
$users = $users->findByAgeLessThan(30);
$users = $users->findByAgeGreaterThanOrEqual(18);
$users = $users->findByAgeLessThanOrEqual(65);

// NAME
$users = $users->findByNameLike('%John%');
$users = $users->findByNameNotLike('%test%');

// STATUS
$users = $users->findByStatusIn(['active', 'pending']);
$users = $users->findByStatusNotIn(['banned', 'deleted']);

// PRICE
$products->findByPriceGreaterThan(100);
$products->findByPriceLessThan(50);
$products->findByPriceBetween(50, 100);
```

### findOneBy{Field}

```php
// findOneByEmail('alice@example.com')
$user = $users->findOneByEmail('alice@example.com');

// findOneByAge(18)
$user = $users->findOneByAge(18);
```

### findOneBy{Field}{Operator}

```php
// Un seul utilisateur
$user = $users->findOneByAgeGreaterThan(18);
$user = $users->findOneByEmail('alice@example.com');
$user = $users->findOneByNameLike('%John%');
```

### Avec arguments supplémentaires

```php
// findBy avec tri
$users = $users->findByAgeGreaterThan(18, ['name' => 'ASC']);

// findBy avec tri et limite
$users = $users->findByAgeGreaterThan(18, ['name' => 'ASC'], 10);

// findOneBy avec tri (ignoré car un seul résultat)
$user = $users->findOneByAgeGreaterThan(18, ['name' => 'ASC']);
```

---

## Le cache

### Activation du cache

```php
// Au constructeur
$users = new MoSQL('users', $config, [
    'cache_enabled' => true,
    'cache_ttl' => 3600, // 1 heure
]);

// Pendant l'utilisation
$users->cache(true, 7200); // 2 heures
```

### Cache par requête

```php
// Cache pendant 5 minutes
$results = $users
    ->