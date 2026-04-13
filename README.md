# BasicDB — 2026 Modernized Fork

> **Credits**: This project was originally created by **[Tayfun Erbilen](http://www.erbilen.net/)** and **[Midori Koçak](http://www.mtkocak.com/)**. All credit for the original design, API and adapter pattern goes to them — this fork exists only to modernize and harden the library while preserving their work and approach intact. The original README, credits and history below are kept untouched out of respect for the authors.

## About this fork (maintained by [Barış Yeman](https://github.com/barisyeman))

**Goal:** Keep BasicDB a **lightweight, single-file-style ORM/query builder** — *no* heavy Eloquent-like abstractions, *no* migrations, *no* relations. Just a safe, modern PDO helper that is small enough to read in one sitting.

**Approach:** Preserve the original public API and philosophy. Refactor internals so the library is safe to use in production on modern PHP.

### What changed in the 2026 modernization

- **PHP 8.1+**, `declare(strict_types=1)`, typed properties, union types, `match`, `readonly`-friendly.
- **PSR-4 namespace** `Erbilen\Database` — fully namespaced, autoloaded via Composer.
- **Full prepared statements** everywhere — `LIKE`, `IN`, `BETWEEN`, `FIND_IN_SET`, `SOUNDEX` and `SET` clauses all bind values; no string concatenation of user input.
- **Identifier whitelist** — table and column names are validated against the live schema at connect time (cached, configurable). Invalid identifiers throw a `ValidationException` instead of reaching the database.
- **Error modes** — `throw` (default, throws `QueryException`), `silent` (logs), `debug` (HTML). No more forced HTML output that breaks JSON APIs.
- **Transactions** — `$db->transaction(fn($db) => …)` helper with automatic rollback on any `Throwable`.
- **New helpers** — `whereNull`, `whereNotNull`, `whereRaw`, `find($id)`, `count()`, typed `pagination()` (no more implicit `$_GET` coupling).
- **Bug fixes** — safer `set()` operator detection (`+5`/`-3` only), reset of builder state after execute, `truncate()`/`truncateAll()` fixed, JOIN accepts structured args (`join($table, $a, '=', $b)`), `utf8mb4` by default.
- **Security** — `__call` now throws `BadMethodCallException` instead of `die()`, schema cache file is chmod `0600`, raw SQL in `whereRaw` blocks dangerous keywords.

### Quick start

```php
require 'vendor/autoload.php';

use Erbilen\Database\BasicDB;

$db = new BasicDB('localhost', 'mydb', 'user', 'pass');
// or: $db = new Erbilen\Database\BasicDB('localhost', 'mydb', 'user', 'pass');

// SELECT
$posts = $db->from('posts')
            ->where('status', 'published')
            ->orderBy('created_at', 'DESC')
            ->limit(0, 20)
            ->all();

// INSERT
$db->insert('users')->set(['name' => 'Barış', 'email' => 'x@y.com']);
$id = $db->lastId();

// UPDATE with self-increment
$db->update('products')->where('id', 5)->set(['stock' => '-1']);

// Transaction
$db->transaction(function (BasicDB $db) {
    $db->insert('orders')->set(['user_id' => 1, 'total' => 99.90]);
    $db->update('stock')->where('sku', 'ABC')->set(['qty' => '-1']);
});
```

### Requirements

- PHP **8.1** or newer
- `ext-pdo`, `ext-pdo_mysql`
- MySQL / MariaDB (other drivers are not targeted — the library stays MySQL-focused on purpose)

---

# Original README (preserved)

# BasicDB Class for PHP

**Description**: BasicDB is a class to teach and use PDO and OOP, using abstract classes, interfaces and adapters. It simplifies also PDO usage.

Includes:
 - BasicDB Class to simplify PDO
 - Crudable Adapter
 - Add, Edit, Index, View, Delete Adapter methods

Other things to include:

  - **Technology stack**: PHP, Composer, PHPUnit should exits
  - **Things to do**: Complete Doccomments.
  - **Status**:  Very very Alpha [CHANGELOG](CHANGELOG.md).
  - **Links to production or demo instances**
  -  No demo.

## Installation

1. Clone repository
2. Composer Install
3. (Optional) ant build

## Configuration

For testing purposes you should create a test database and add credentials to test files.

## Usage

See examples doler.

## How to test the software

build/phpunit

## Known issues

No issues yet

## Getting help

mtkocak@mtkocak.net

Feel free to mail mtkocak@gmail.com 

## Getting involved

You can fork this package.
----

## Open source licensing info
1. [TERMS](TERMS.md)
2. [LICENSE](LICENSE)
----

## Credits and references

I already gave credits above but, you can check;

1. [Tayfun Erbilen](http://www.erbilen.net/)
2. [Midori Kocak](http://www.mtkocak.com/)
3. [Open Source Project Template](https://github.com/cfpb/open-source-project-template)
