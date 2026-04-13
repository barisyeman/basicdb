All notable changes to this project will be documented in this file.
We follow the [Semantic Versioning 2.0.0](http://semver.org/) format.


## 0.1.0 - 2014-04-13

### Added
-BasicDB Class is created

### Deprecated
- Nothing.

### Removed
- Nothing.

### Fixed
- Nothing.

## 0.2.0 - 2015-07-02

### Added
- Crudable and Add, Edit, Index, Update, Delete, View interfaces, adapters and methods
- English Comments
- Unit Tests
- Code Coverage
- Mess Detector
- Composer Depedencies

### Deprecated
- Usage without namespaces

### Removed
- Turkish comments

### Fixed
- Examples

## 0.3.0 - 2018-08-23

### Added
- Debug
- Soundex method
- _call() magic method
- Grouping for where clause

## 1.0.0 - 2026-04-14

Modernization & security hardening fork maintained by Barış Yeman.
All credit for the original design remains with the original authors.

### Added
- Full prepared-statement binding for LIKE / IN / BETWEEN / FIND_IN_SET / SOUNDEX and SET clauses
- Identifier whitelist validated against the live schema (cached on disk, chmod 0600)
- `QueryException` and `ValidationException` classes for structured error handling
- Configurable error modes: `throw` (default), `silent` (log-only), `debug` (HTML)
- `transaction(Closure $fn)` helper with automatic rollback on any `Throwable`
- `whereNull()`, `whereNotNull()`, `whereRaw()` helpers
- `find($id, $pk = 'id')` and `count()` convenience methods
- Typed `pagination($total, $limit, int|string|null $page)` — no more implicit `$_GET` coupling
- `disableWhitelist()` / `enableWhitelist()` / `reloadSchema()` runtime toggles
- `utf8mb4` default charset with proper collation

### Changed
- Minimum PHP version bumped to **8.1** (was 5.3)
- `declare(strict_types=1)`, typed properties, union types throughout
- `join()` signature is now structured: `join($table, $leftCol, $op, $rightCol)` — no more raw SQL strings
- `set()` operator detection limited to `+N` / `-N` format (no more false positives on e-mails, etc.)
- `__call()` now throws `BadMethodCallException` instead of calling `die()`
- Error output no longer forced to HTML — respects error mode
- Builder state is reset after each `all()` / `first()` / `done()` / `set()` execution
- `PDO::ATTR_EMULATE_PREPARES` disabled for real server-side prepares
- `CrudableAdapter::$basicDB` changed from `private` to `protected` (bug fix)
- `CrudableAdapter::read()` now uses `from()->first()` (was calling non-existent `->run()`)

### Fixed
- `truncate()` no longer passes `$dbName` through table-name validator
- `truncateAll()` uses parameterised `INFORMATION_SCHEMA` query instead of broken `from()` path
- `select()` no longer relies on fragile `str_replace(' * ', …)`
- Parameter counter resets between queries to prevent unbounded growth
- Schema cache file permissions locked down to `0600`

### Security
- All user-supplied values go through prepared statements; identifiers go through strict whitelist + regex
- `whereRaw()` blocks dangerous keywords (UNION, INSERT, UPDATE, DELETE, DROP, …)
- No SQL string concatenation anywhere in the query path

