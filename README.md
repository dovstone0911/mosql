# README.md complet avec Table des Matières pour MoSQL

```markdown
# MoSQL - ORM Légère avec Support JSON, UID et Cache

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg?style=flat&logo=php)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Doctrine DBAL](https://img.shields.io/badge/Doctrine-DBAL-FF6B00.svg?style=flat&logo=doctrine)](https://www.doctrine-project.org/projects/dbal.html)

**MoSQL** est une bibliothèque ORM légère inspirée de MongoDB avec une syntaxe fluide, supportant les champs JSON, la génération automatique d'UID, le cache intégré et des requêtes avancées. Elle utilise **Doctrine DBAL** comme couche d'abstraction base de données.

---

## 📚 Table des Matières

- [📦 Installation](#-installation)
- [🚀 Démarrage Rapide](#-démarrage-rapide)
  - [Configuration](#configuration)
  - [CRUD de Base](#crud-de-base)
  - [Requêtes Simples](#requêtes-simples)
- [📚 Fonctionnalités Complètes](#-fonctionnalités-complètes)
  - [1. Conditions de Base](#1-conditions-de-base)
  - [2. Conditions JSON](#2-conditions-json)
  - [3. Conditions de Date/Temps](#3-conditions-de-datetemps)
  - [4. Conditions Avancées](#4-conditions-avancées)
  - [5. Projection et Tri](#5-projection-et-tri)
  - [6. Jointures](#6-jointures)
  - [7. Agrégations](#7-agrégations)
  - [8. Transactions](#8-transactions)
  - [9. Cache](#9-cache)
  - [10. Méthodes Utilitaires](#10-méthodes-utilitaires)
- [🔥 Méthodes Magiques](#-méthodes-magiques)
  - [findBy*](#findby)
  - [findOneBy*](#findoneby)
- [🎯 Apply Criteria (Multi-Formats)](#-apply-criteria-multi-formats)
- [🗄️ Cache des Requêtes SQL](#️-cache-des-requêtes-sql)
- [🏗️ Architecture](#️-architecture)
- [📊 Performance](#-performance)
- [🔧 Requirements](#-requirements)
- [📖 Exemples Complets](#-exemples-complets)
  - [Exemple 1: Système d'authentification](#exemple-1-système-dauthentification)
  - [Exemple 2: Rapports et Statistiques](#exemple-2-rapports-et-statistiques)
  - [Exemple 3: API REST](#exemple-3-api-rest)
- [🤝 Contribution](#-contribution)
- [📄 License](#-license)
- [🙏 Remerciements](#-remerciements)
- [📞 Support](#-support)
- [⭐️ Show Your Support](#️-show-your-support)

---

## 📦 Installation

```bash
composer require dovstone/mosql
```

---

## 🚀 Démarrage Rapide

### Configuration

```php
use Dovstone\MoSQL\MoSQL;

$dbParams = [
    'driver' => 'pdo_mysql',
    'host' => 'localhost',
    'dbname' => 'my_database',
    'user' => 'root',
    'password' => ''
];

// Création d'une collection
$users = new MoSQL('users', $dbParams);

// Options avancées
$users = new MoSQL('users', $dbParams, [
    'uid_length' => 10,          // Longueur de l'UID (défaut: 8)
    'table_prefix' => 'app_',    // Préfixe des tables
    'auto_create_schema' => true, // Création auto de la table
    'cache_enabled' => true,     // Activer le cache
    'cache_ttl' => 3600          // Durée du cache en secondes
]);
```

### CRUD de Base

```php
// CREATE - Insertion
$uid = $users->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'age' => 28,
    'roles' => ['admin', 'editor']
]);

// READ - Lecture
$user = $users->find($uid);
$allUsers = $users->findAll();

// UPDATE - Mise à jour
$users->where('uid', '=', $uid)->update(['age' => 29]);

// DELETE - Suppression
$users->where('uid', '=', $uid)->delete();
```

### Requêtes Simples

```php
// Conditions basiques
$activeUsers = $users
    ->where('status', '=', 'active')
    ->where('age', '>', 18)
    ->orderBy('name', 'ASC')
    ->limit(10)
    ->find();

// Recherche par email
$user = $users->findOneByEmail('alice@example.com');

// Magic methods
$adults = $users->findByAgeGreaterThan(18);
$admins = $users->findByRoleIn(['admin', 'super_admin']);
```

---

## 📚 Fonctionnalités Complètes

### 1. Conditions de Base

```php
// Égalité
$users->where('status', '=', 'active');

// Comparaison
$users->where('age', '>', 18);
$users->where('age', '>=', 18);

// LIKE
$users->whereLike('name', '%John%');

// IN
$users->whereIn('status', ['active', 'pending', 'draft']);

// BETWEEN
$users->whereBetween('age', 18, 65);

// NULL
$users->whereNull('deleted_at');
$users->whereNotNull('email');

// Conditions OR
$users->where('status', '=', 'active')
      ->orWhere('status', '=', 'pending');

// Groupes de conditions
$users->where('status', '=', 'active')
      ->andWhere(function($q) {
          $q->where('age', '>', 18)
            ->orWhere('role', '=', 'admin');
      });
```

### 2. Conditions JSON

```php
// JSON_CONTAINS (champ de type JSON)
$users->whereJsonContains('roles', '"admin"');
$users->whereJsonNotContains('roles', '"banned"');

// OR JSON_CONTAINS
$users->whereJsonContains('roles', '"admin"')
      ->orWhereJsonContains('roles', '"editor"');

// ANY (au moins une valeur)
$users->whereJsonContainsAny('roles', ['admin', 'editor', 'contributor']);

// ALL (toutes les valeurs)
$users->whereJsonContainsAll('roles', ['admin', 'editor']);

// NOT ANY (au moins une absente)
$users->whereJsonNotContainsAny('roles', ['admin', 'editor']);

// NOT ALL (toutes absentes)
$users->whereJsonNotContainsAll('roles', ['admin', 'editor']);

// JSON Path (champ imbriqué)
$users->where('user.profile.age', '>', 18);
```

### 3. Conditions de Date/Temps

```php
// Date
$users->whereDate('created_at', '=', '2024-01-01');
$users->whereDate('created_at', '>', '2024-01-01');

// Mois
$users->whereMonth('created_at', '=', 1); // Janvier

// Année
$users->whereYear('created_at', '=', 2024);

// Jour
$users->whereDay('created_at', '=', 15);

// Heure
$users->whereHour('created_at', '>', 12);

// Minute
$users->whereMinute('created_at', '=', 30);
```

### 4. Conditions Avancées

```php
// SQL Brut
$users->whereRaw('YEAR(created_at) = ?', [2024]);

// Sous-requête
$users->whereSub('id', 'IN', function($q) {
    $q->select(['user_id'])
      ->from('orders')
      ->where('status', '=', 'paid');
});

// Soft Delete (soft delete intégré)
$users->onlyTrashed()->find();  // Uniquement supprimés
$users->withTrashed()->find();  // Inclut les supprimés
```

### 5. Projection et Tri

```php
// Sélection des champs
$users->select(['name', 'email'])->find();

// Tri simple
$users->orderBy('name', 'ASC');

// Tri multiple
$users->orderBy(['name' => 'ASC', 'created_at' => 'DESC']);

// Tri personnalisé (FIELD)
$users->orderByField('uid', ['A7kR9qW2', 'B8sT4vX3']);

// GROUP BY & HAVING
$users->groupBy('status')
      ->having('COUNT(*)', '>', 10)
      ->select(['status', 'COUNT(*) as total'])
      ->find();

// Pagination
$page = $users->where('status', '=', 'active')->paginate(15, 2);
```

### 6. Jointures

```php
// INNER JOIN
$users->join('orders', 'uid', '=', 'user_uid')->find();

// LEFT JOIN
$users->leftJoin('orders', 'uid', '=', 'user_uid')->find();

// Jointure complexe
$users->joinComplex(
    'orders',
    'users.id = orders.user_id AND orders.status = :status',
    'inner',
    ['status' => 'paid']
);
```

### 7. Agrégations

```php
$total = $users->sum('age', ['status' => 'active']);
$avg = $users->avg('age', ['status' => 'active']);
$min = $users->min('age');
$max = $users->max('age');
$count = $users->countBy(['status' => 'active']);
```

### 8. Transactions

```php
// Manuel
$users->beginTransaction();
try {
    $uid = $users->insert(['name' => 'Test']);
    $users->where('uid', '=', $uid)->update(['status' => 'active']);
    $users->commit();
} catch (\Exception $e) {
    $users->rollBack();
}

// Automatique
$users->transaction(function($db) {
    $uid = $db->insert(['name' => 'Test']);
    $db->where('uid', '=', $uid)->update(['status' => 'active']);
    return $uid;
});
```

### 9. Cache

```php
// Activer le cache
$users->cache(true, 3600)->find();

// Invalider
$users->invalidate('A7kR9qW2');
$users->invalidateAll();

// Toutes les requêtes sont automatiquement mises en cache dans logs/sql/
```

### 10. Méthodes Utilitaires

```php
// UID ↔ ID
$id = $users->findIdByUid('A7kR9qW2');
$uid = $users->findUidById(1);

// Vérifications
$exists = $users->exists(['email' => 'alice@example.com']);
$missing = $users->missing(['email' => 'bob@example.com']);

// Récupération d'une colonne
$names = $users->pluck('name');
$namesByUid = $users->pluck('name', 'uid');

// Paires clé-valeur
$pairs = $users->pairs('uid', 'name');

// Valeurs distinctes
$statuses = $users->distinct('status');

// Conversion
$array = $users->findAll();
$json = $users->toJson();

// Debug
$users->where('status', '=', 'active')->dd();

// Requête brute
$results = $users->rawQuery('SELECT * FROM users WHERE age > ?', [18]);

// Informations de requête
$data = $users->where('status', '=', 'active')->getData();
// Retourne: conditions, projection, order_by, sql, params, etc.
```

---

## 🔥 Méthodes Magiques

### findBy*

```php
// findByAge(18) → WHERE age = 18
$users->findByAge(18);

// findByAgeGreaterThan(18) → WHERE age > 18
$users->findByAgeGreaterThan(18);

// findByAgeLessThan(18) → WHERE age < 18
$users->findByAgeLessThan(18);

// findByAgeGreaterThanOrEqual(18) → WHERE age >= 18
$users->findByAgeGreaterThanOrEqual(18);

// findByAgeLessThanOrEqual(18) → WHERE age <= 18
$users->findByAgeLessThanOrEqual(18);

// findByNameLike('%John%') → WHERE name LIKE '%John%'
$users->findByNameLike('%John%');

// findByStatusIn(['active', 'pending']) → WHERE status IN (...)
$users->findByStatusIn(['active', 'pending']);

// Avec tri et limite
$users->findByAge(18, ['name' => 'ASC'], 10);
```

### findOneBy*

```php
// findOneByEmail('alice@example.com')
$user = $users->findOneByEmail('alice@example.com');

// findOneByAgeGreaterThan(18)
$user = $users->findOneByAgeGreaterThan(18);
```

---

## 🎯 Apply Criteria (Multi-Formats)

```php
// Format 1: [['field', 'operator', 'value']]
$users->applyCriteria([
    ['status', '=', 'active'],
    ['age', '>', 18],
    ['role', 'IN', ['admin', 'editor']]
]);

// Format 2: ['field' => ['operator', 'value']]
$users->applyCriteria([
    'status' => ['=', 'active'],
    'age' => ['>', 18],
    'role' => ['IN', ['admin', 'editor']]
]);

// Format 3: ['field' => 'value']
$users->applyCriteria([
    'status' => 'active',
    'age' => 18
]);

// Format 4: Callback
$users->applyCriteria(function($q) {
    $q->where('status', '=', 'active')
      ->orWhere('status', '=', 'pending');
});
```

---

## 🗄️ Cache des Requêtes SQL

Toutes les requêtes sont automatiquement sauvegardées dans `logs/sql/` au format JSON :

```json
{
    "collection": "users",
    "conditions": [...],
    "projection": ["*"],
    "order_by": [],
    "limit": 10,
    "offset": 0,
    "sql": "SELECT * FROM users WHERE status = :p_status_... LIMIT 10",
    "params": {"p_status_...": "active"},
    "execution_time_ms": 12.34,
    "executed_at": "2024-01-01 12:00:00"
}
```

---

## 🏗️ Architecture

```
Dovstone\MoSQL
├── MoSQL.php              # Point d'entrée principal
├── Query/
│   └── QueryBuilder.php   # Constructeur de requêtes
├── Schema/
│   └── SchemaManager.php  # Gestion du schéma
├── Document/
│   └── DocumentManager.php # Gestion des documents
├── Cache/
│   ├── ArrayCache.php
│   ├── CacheManager.php
│   └── SqlFileCacheManager.php
├── Uid/
│   └── UidGenerator.php   # Générateur d'UID
└── Exception/
    ├── DatabaseException.php
    ├── DocumentNotFoundException.php
    ├── DuplicateException.php
    └── InvalidArgumentException.php
```

---

## 📊 Performance

- **Connexion partagée (Singleton)** : Une seule connexion pour toutes les collections
- **Cache intégré** : Cache des résultats en mémoire et sur disque
- **Optimisation JSON** : Support natif des colonnes JSON
- **UID générés** : Évitent les collisions et les auto-incréments

---

## 🔧 Requirements

- PHP 7.4 ou supérieur
- Doctrine DBAL 3.0 ou supérieur
- Extension PDO (MySQL, PostgreSQL, SQLite, etc.)

---

## 📖 Exemples Complets

### Exemple 1: Système d'authentification

```php
class UserAuth
{
    private MoSQL $users;

    public function __construct()
    {
        $this->users = new MoSQL('users', DB_PARAMS);
    }

    public function register(array $data): string
    {
        if ($this->users->exists(['email' => $data['email']])) {
            throw new \Exception('Email already exists');
        }

        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        $data['roles'] = ['user'];
        $data['status'] = 'pending';
        
        return $this->users->insert($data);
    }

    public function login(string $email, string $password): ?array
    {
        $user = $this->users->findOneBy(['email' => $email]);
        
        if (!$user || !password_verify($password, $user['password'])) {
            return null;
        }

        $this->users->where('uid', '=', $user['uid'])
                    ->update(['last_login' => date('Y-m-d H:i:s')]);

        return $user;
    }

    public function isAdmin(string $uid): bool
    {
        $user = $this->users->find($uid);
        return $user && in_array('admin', $user['roles'] ?? []);
    }
}
```

### Exemple 2: Rapports et Statistiques

```php
class ReportGenerator
{
    private MoSQL $orders;
    private MoSQL $users;

    public function __construct()
    {
        $this->orders = new MoSQL('orders', DB_PARAMS);
        $this->users = new MoSQL('users', DB_PARAMS);
    }

    public function getMonthlyStats(int $year): array
    {
        $stats = [];
        
        for ($month = 1; $month <= 12; $month++) {
            $stats[$month] = [
                'total_orders' => $this->orders->whereYear('created_at', '=', $year)
                                               ->whereMonth('created_at', '=', $month)
                                               ->count(),
                'total_revenue' => $this->orders->whereYear('created_at', '=', $year)
                                                ->whereMonth('created_at', '=', $month)
                                                ->sum('total'),
                'new_users' => $this->users->whereYear('created_at', '=', $year)
                                           ->whereMonth('created_at', '=', $month)
                                           ->count()
            ];
        }
        
        return $stats;
    }

    public function getTopUsers(int $limit = 10): array
    {
        return $this->orders->select(['user_id', 'COUNT(*) as order_count', 'SUM(total) as total_spent'])
                            ->groupBy('user_id')
                            ->having('order_count', '>', 5)
                            ->orderBy('total_spent', 'DESC')
                            ->limit($limit)
                            ->find();
    }

    public function getActiveUsers(): array
    {
        return $this->users->where('status', '=', 'active')
                           ->whereJsonContains('roles', '"user"')
                           ->whereDate('last_login', '>', date('Y-m-d', strtotime('-30 days')))
                           ->find();
    }
}
```

### Exemple 3: API REST

```php
class UserController
{
    private MoSQL $users;

    public function __construct()
    {
        $this->users = new MoSQL('users', DB_PARAMS);
    }

    public function index(): array
    {
        $page = (int)($_GET['page'] ?? 1);
        $limit = (int)($_GET['limit'] ?? 15);
        $search = $_GET['search'] ?? null;

        $query = $this->users->select(['uid', 'name', 'email', 'status', 'created_at']);

        $query->when($search, function($q) use ($search) {
            $q->whereLike('name', '%' . $search . '%')
              ->orWhereLike('email', '%' . $search . '%');
        });

        return $query->paginate($limit, $page);
    }

    public function show(string $uid): ?array
    {
        $user = $this->users->find($uid);
        if (!$user) {
            throw new \Exception('User not found', 404);
        }
        return $user;
    }

    public function store(array $data): array
    {
        $validator = $this->validate($data);
        if ($validator->fails()) {
            throw new \Exception('Validation failed', 422);
        }

        $uid = $this->users->insert($data);
        return ['uid' => $uid];
    }

    public function update(string $uid, array $data): bool
    {
        $affected = $this->users->where('uid', '=', $uid)->update($data);
        if ($affected === 0) {
            throw new \Exception('User not found', 404);
        }
        return true;
    }

    public function destroy(string $uid): bool
    {
        $deleted = $this->users->where('uid', '=', $uid)->delete();
        if ($deleted === 0) {
            throw new \Exception('User not found', 404);
        }
        return true;
    }
}
```

---

## 🤝 Contribution

1. Fork le projet
2. Crée ta branche (`git checkout -b feature/amazing-feature`)
3. Commit tes changements (`git commit -m 'Add amazing feature'`)
4. Push (`git push origin feature/amazing-feature`)
5. Ouvre une Pull Request

---

## 📄 License

MIT License - voir le fichier [LICENSE](LICENSE) pour plus de détails.

---

## 🙏 Remerciements

- [Doctrine DBAL](https://www.doctrine-project.org/projects/dbal.html) - Base de données abstraite
- [PHP](https://php.net) - Language de programmation

---

## 📞 Support

- **Documentation** : [GitHub Wiki](https://github.com/dovstone/mosql/wiki)
- **Issues** : [GitHub Issues](https://github.com/dovstone/mosql/issues)
- **Email** : support@dovstone.com

---

## ⭐️ Show Your Support

Si vous appréciez ce projet, n'hésitez pas à :

- Mettre une ⭐️ sur GitHub
- Partager autour de vous
- Contribuer au développement

---

*Made with ❤️ by Dovstone*
```

---

## 📋 Résumé de la Table des Matières

| Section | Contenu |
|---------|---------|
| **Installation** | Installation via Composer |
| **Démarrage Rapide** | Configuration, CRUD, Requêtes |
| **Conditions de Base** | WHERE, OR, AND, IN, LIKE, BETWEEN, NULL |
| **Conditions JSON** | JSON_CONTAINS, ANY, ALL, Path |
| **Conditions Date/Temps** | DATE, MONTH, YEAR, DAY, HOUR, MINUTE |
| **Conditions Avancées** | Raw SQL, Subqueries, Soft Delete |
| **Projection et Tri** | SELECT, ORDER BY, GROUP BY, HAVING, Pagination |
| **Jointures** | JOIN, LEFT JOIN, Complex Join |
| **Agrégations** | SUM, AVG, MIN, MAX, COUNT |
| **Transactions** | Manuel et Automatique |
| **Cache** | Activation, Invalidation |
| **Méthodes Utilitaires** | UID↔ID, Exists, Pluck, Pairs, Distinct |
| **Méthodes Magiques** | findBy*, findOneBy* |
| **Apply Criteria** | 4 formats de critères |
| **Cache des Requêtes** | Logs SQL au format JSON |
| **Architecture** | Structure des classes |
| **Exemples Complets** | Auth, Reports, API REST |
| **Contribution** | Comment contribuer |
| **Support** | Où trouver de l'aide |