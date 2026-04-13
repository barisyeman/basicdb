<?php

declare(strict_types=1);

/**
 * BasicDB - Lightweight PDO Query Builder
 *
 * Original authors (preserved with gratitude):
 *   @author  Tayfun Erbilen <tayfunerbilen@gmail.com> http://www.erbilen.net
 *   @author  Midori Koçak   <mtkocak@gmail.com>      http://www.mtkocak.com
 *   @date    13 April 2014
 *   @update  20 March 2019
 *
 * 2026 modernization & security hardening:
 *   @maintainer Barış Yeman
 *   @update     2026
 *   - PHP 8.1+, strict types, typed properties
 *   - PSR-4 namespace: Erbilen\Database
 *   - Full prepared statements with identifier whitelist
 *   - Transaction helper, safer error modes, modern API
 */

namespace Erbilen\Database;

use Erbilen\Database\Exceptions\QueryException;
use Erbilen\Database\Exceptions\ValidationException;
use Closure;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

class BasicDB extends PDO
{
    public const ERROR_THROW  = 'throw';
    public const ERROR_SILENT = 'silent';
    public const ERROR_DEBUG  = 'debug';

    private string $dbName;
    private string $type = '';
    private string $sql = '';
    private string $unionSql = '';
    private ?string $tableName = null;

    /** @var array<int, array<string, mixed>> */
    private array $where = [];
    /** @var array<int, array<string, mixed>> */
    private array $having = [];
    private bool $grouped = false;
    private int $groupId = 0;

    /** @var array<int, string> */
    private array $join = [];
    private ?string $orderBy = null;
    private bool $random = false;
    private ?string $groupBy = null;
    private ?string $limit = null;

    private int $page = 1;
    private int $totalRecord = 0;
    public int $pageCount = 0;
    private int $paginationLimit = 10;
    private string $html = '';

    /** @var array<string, mixed> */
    private array $bindParams = [];
    private int $paramCounter = 0;

    /** @var array<string, true> */
    private array $allowedTables = [];
    /** @var array<string, array<string, true>> */
    private array $allowedColumns = [];
    private bool $whitelistEnabled = true;

    public bool $debug = false;
    public string $errorMode = self::ERROR_THROW;
    public int $fetchMode = PDO::FETCH_OBJ;

    public string $paginationItem =
        '<li class="page-item [pagination-active]"><a class="page-link" href="[url]">[text]</a></li>';

    /** @var array<int, string> */
    public array $reference = ['NOW()', 'CURRENT_TIMESTAMP', 'NULL'];

    public function __construct(
        string $host,
        string $dbname,
        string $username,
        string $password,
        string $charset = 'utf8mb4',
        ?string $cacheDir = null,
        int $cacheTtl = 3600,
    ) {
        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $dbname, $charset);
            parent::__construct($dsn, $username, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
            ]);
            $this->dbName = $dbname;
            $this->loadSchema($cacheDir, $cacheTtl);
        } catch (PDOException $e) {
            $this->handleError($e);
        }
    }

    // ---------------------------------------------------------------------
    // Schema / whitelist
    // ---------------------------------------------------------------------

    public function disableWhitelist(): self
    {
        $this->whitelistEnabled = false;
        return $this;
    }

    public function enableWhitelist(): self
    {
        $this->whitelistEnabled = true;
        return $this;
    }

    public function reloadSchema(?string $cacheDir = null, int $cacheTtl = 3600): void
    {
        $this->loadSchema($cacheDir, $cacheTtl, true);
    }

    private function loadSchema(?string $cacheDir, int $cacheTtl, bool $force = false): void
    {
        $cacheDir ??= sys_get_temp_dir();
        $cacheFile = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR
            . 'basicdb_schema_' . md5($this->dbName) . '.php';

        if (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            $cached = @include $cacheFile;
            if (is_array($cached) && isset($cached['tables'], $cached['columns'])) {
                $this->allowedTables  = $cached['tables'];
                $this->allowedColumns = $cached['columns'];
                return;
            }
        }

        try {
            $tables = $this->query('SHOW TABLES')?->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $this->allowedTables = array_flip($tables);
            foreach ($tables as $table) {
                $cols = $this->query("SHOW COLUMNS FROM `{$table}`")?->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $this->allowedColumns[$table] = array_flip($cols);
            }
            $payload = "<?php return " . var_export([
                'tables'  => $this->allowedTables,
                'columns' => $this->allowedColumns,
            ], true) . ';';
            @file_put_contents($cacheFile, $payload, LOCK_EX);
            @chmod($cacheFile, 0600);
        } catch (PDOException) {
            $this->allowedTables = [];
            $this->allowedColumns = [];
        }
    }

    // ---------------------------------------------------------------------
    // Identifier validation
    // ---------------------------------------------------------------------

    private function validateTableName(string $tableName): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
            throw new ValidationException("Invalid table name format: {$tableName}");
        }
        if ($this->whitelistEnabled && !empty($this->allowedTables)
            && !isset($this->allowedTables[$tableName])) {
            throw new ValidationException("Unknown table: {$tableName}");
        }
        return '`' . $tableName . '`';
    }

    private function validateColumnName(string $columnName, ?string $tableName = null): string
    {
        $columnName = trim($columnName);

        if ($columnName === '*') {
            return '*';
        }

        // Aggregate or SQL function: COUNT(*), SUM(col), DISTINCT col ...
        if (preg_match('/^(COUNT|SUM|AVG|MAX|MIN|DISTINCT)\s*\(/i', $columnName)) {
            if (preg_match('/\b(UNION|INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|EXEC|EXECUTE|INTO|LOAD_FILE|OUTFILE|INFORMATION_SCHEMA)\b/i', $columnName)) {
                throw new ValidationException("Disallowed SQL keyword in expression: {$columnName}");
            }
            return $columnName;
        }

        // Comma-separated list
        if (str_contains($columnName, ',')) {
            $parts = array_map(fn($c) => $this->validateColumnName($c, $tableName), explode(',', $columnName));
            return implode(', ', $parts);
        }

        // "col AS alias"
        if (preg_match('/^(.+)\s+AS\s+(.+)$/i', $columnName, $m)) {
            $alias = trim($m[2]);
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
                throw new ValidationException("Invalid alias: {$alias}");
            }
            return $this->validateSingleColumn(trim($m[1]), $tableName) . ' AS `' . $alias . '`';
        }

        return $this->validateSingleColumn($columnName, $tableName);
    }

    private function validateSingleColumn(string $columnName, ?string $tableName = null): string
    {
        if (str_contains($columnName, '.')) {
            [$table, $col] = explode('.', $columnName, 2);
            $table = trim($table);
            $col   = trim($col);
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
                throw new ValidationException("Invalid table prefix: {$table}");
            }
            if ($col === '*') {
                return "`{$table}`.*";
            }
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                throw new ValidationException("Invalid column: {$col}");
            }
            return "`{$table}`.`{$col}`";
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $columnName)) {
            throw new ValidationException("Invalid column format: {$columnName}");
        }
        return '`' . $columnName . '`';
    }

    private function createSafeParam(): string
    {
        return ':p' . (++$this->paramCounter);
    }

    // ---------------------------------------------------------------------
    // Builder: SELECT
    // ---------------------------------------------------------------------

    public function from(string $tableName): self
    {
        $this->resetState();
        $safe = $this->validateTableName($tableName);
        $this->sql = 'SELECT * FROM ' . $safe;
        $this->tableName = $tableName;
        return $this;
    }

    public function select(string $columns): self
    {
        $safe = $this->validateColumnName($columns, $this->tableName);
        $this->sql = preg_replace('/^SELECT \*/', 'SELECT ' . $safe, $this->sql, 1) ?? $this->sql;
        return $this;
    }

    public function union(): self
    {
        $this->type = 'union';
        $this->unionSql = $this->sql;
        return $this;
    }

    public function group(Closure $fn): self
    {
        static $gid = 0;
        $this->grouped = true;
        $fn($this);
        $this->groupId = ++$gid;
        $this->grouped = false;
        return $this;
    }

    public function where(string $column, mixed $value = '', string $mark = '=', string $logical = '&&'): self
    {
        $this->where[] = [
            'column'   => $this->validateColumnName($column, $this->tableName),
            'value'    => $value,
            'mark'     => $mark,
            'logical'  => $logical,
            'grouped'  => $this->grouped,
            'group_id' => $this->groupId,
        ];
        return $this;
    }

    public function or_where(string $column, mixed $value, string $mark = '='): self
    {
        return $this->where($column, $value, $mark, '||');
    }

    public function having(string $column, mixed $value = '', string $mark = '=', string $logical = '&&'): self
    {
        $this->having[] = [
            'column'   => $this->validateColumnName($column, $this->tableName),
            'value'    => $value,
            'mark'     => $mark,
            'logical'  => $logical,
            'grouped'  => $this->grouped,
            'group_id' => $this->groupId,
        ];
        return $this;
    }

    public function or_having(string $column, mixed $value, string $mark = '='): self
    {
        return $this->having($column, $value, $mark, '||');
    }

    public function whereNull(string $column): self
    {
        return $this->where($column, null, 'IS NULL');
    }

    public function whereNotNull(string $column): self
    {
        return $this->where($column, null, 'IS NOT NULL');
    }

    public function whereRaw(string $expression, array $bindings = []): self
    {
        if (preg_match('/\b(UNION|INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|EXEC|EXECUTE|INTO|LOAD_FILE|OUTFILE)\b/i', $expression)) {
            throw new ValidationException("Disallowed SQL in whereRaw");
        }
        $this->where[] = [
            'column'   => '__RAW__',
            'value'    => $expression,
            'mark'     => 'RAW',
            'logical'  => '&&',
            'grouped'  => $this->grouped,
            'group_id' => $this->groupId,
            'bindings' => $bindings,
        ];
        return $this;
    }

    public function join(string $targetTable, string $leftCol, string $op, string $rightCol, string $joinType = 'INNER'): self
    {
        $safeTable = $this->validateTableName($targetTable);
        $safeLeft  = $this->validateSingleColumn($leftCol);
        $safeRight = $this->validateSingleColumn($rightCol);
        if (!in_array($op, ['=', '!=', '<', '>', '<=', '>='], true)) {
            throw new ValidationException("Invalid join operator: {$op}");
        }
        $type = strtoupper($joinType);
        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT', 'CROSS'], true)) {
            $type = 'INNER';
        }
        $this->join[] = " {$type} JOIN {$safeTable} ON {$safeLeft} {$op} {$safeRight}";
        return $this;
    }

    public function leftJoin(string $targetTable, string $leftCol, string $op, string $rightCol): self
    {
        return $this->join($targetTable, $leftCol, $op, $rightCol, 'LEFT');
    }

    public function rightJoin(string $targetTable, string $leftCol, string $op, string $rightCol): self
    {
        return $this->join($targetTable, $leftCol, $op, $rightCol, 'RIGHT');
    }

    public function orderBy(string $columnName, string $sort = 'ASC'): self
    {
        $safe = $this->validateColumnName($columnName, $this->tableName);
        $sort = strtoupper($sort) === 'DESC' ? 'DESC' : 'ASC';
        $this->orderBy = ' ORDER BY ' . $safe . ' ' . $sort;
        return $this;
    }

    public function random(): self
    {
        $this->random = true;
        return $this;
    }

    public function groupBy(string $columnName): self
    {
        $this->groupBy = ' GROUP BY ' . $this->validateColumnName($columnName, $this->tableName);
        return $this;
    }

    public function limit(int $start, int $limit): self
    {
        $start = max(0, $start);
        $limit = max(1, min($limit, 1000));
        $this->limit = ' LIMIT ' . $start . ',' . $limit;
        return $this;
    }

    // ---------------------------------------------------------------------
    // Specialized WHERE helpers
    // ---------------------------------------------------------------------

    public function between(string $column, array $values): self     { return $this->where($column, $values, 'BETWEEN'); }
    public function notBetween(string $column, array $values): self  { return $this->where($column, $values, 'NOT BETWEEN'); }
    public function in(string $column, array|string $value): self    { return $this->where($column, $value, 'IN'); }
    public function notIn(string $column, array|string $value): self { return $this->where($column, $value, 'NOT IN'); }
    public function like(string $column, string $value): self        { return $this->where($column, $value, 'LIKE'); }
    public function notLike(string $column, string $value): self     { return $this->where($column, $value, 'NOT LIKE'); }
    public function findInSet(string $column, string $value): self   { return $this->where($column, $value, 'FIND_IN_SET'); }
    public function findInSetReverse(string $c, string $v): self     { return $this->where($c, $v, 'FIND_IN_SET_REVERSE'); }
    public function soundex(string $column, string $value): self     { return $this->where($column, $value, 'SOUNDEX'); }

    // ---------------------------------------------------------------------
    // Execution
    // ---------------------------------------------------------------------

    public function all(): array
    {
        try {
            return $this->generateQuery()->fetchAll($this->fetchMode);
        } catch (PDOException $e) {
            $this->handleError($e);
            return [];
        }
    }

    public function first(): mixed
    {
        try {
            return $this->generateQuery()->fetch($this->fetchMode);
        } catch (PDOException $e) {
            $this->handleError($e);
            return false;
        }
    }

    public function find(int|string $id, string $pk = 'id'): mixed
    {
        return $this->where($pk, $id)->first();
    }

    public function count(): int
    {
        $originalSql    = $this->sql;
        $originalWhere  = $this->where;
        $originalHaving = $this->having;
        $originalJoin   = $this->join;
        $originalGroup  = $this->groupBy;

        $this->sql = preg_replace('/^SELECT .*? FROM/', 'SELECT COUNT(*) AS total FROM', $this->sql, 1) ?? $this->sql;
        $this->orderBy = null;
        $this->limit = null;

        try {
            $row = $this->generateQuery()->fetch(PDO::FETCH_OBJ);
            return (int) ($row->total ?? 0);
        } catch (PDOException $e) {
            $this->handleError($e);
            return 0;
        } finally {
            $this->sql     = $originalSql;
            $this->where   = $originalWhere;
            $this->having  = $originalHaving;
            $this->join    = $originalJoin;
            $this->groupBy = $originalGroup;
        }
    }

    public function total(): int
    {
        return $this->count();
    }

    public function generateQuery(): PDOStatement
    {
        $this->bindParams = [];
        $this->paramCounter = 0;

        if ($this->join) {
            $this->sql .= implode(' ', $this->join);
            $this->join = [];
        }
        $this->buildWhere('where');
        if ($this->groupBy) {
            $this->sql .= $this->groupBy;
            $this->groupBy = null;
        }
        $this->buildWhere('having');
        if ($this->random) {
            $this->sql .= ' ORDER BY RAND()';
            $this->random = false;
        } elseif ($this->orderBy) {
            $this->sql .= $this->orderBy;
            $this->orderBy = null;
        }
        if ($this->limit) {
            $this->sql .= $this->limit;
            $this->limit = null;
        }
        if ($this->type === 'union') {
            $this->sql = $this->unionSql . ' UNION ALL ' . $this->sql;
        }
        if ($this->debug) {
            echo $this->getSqlString();
        }
        $this->type = '';

        $stmt = $this->prepare($this->sql);
        foreach ($this->bindParams as $p => $v) {
            $stmt->bindValue($p, $v, $this->pdoType($v));
        }
        $stmt->execute();
        return $stmt;
    }

    private function pdoType(mixed $v): int
    {
        return match (true) {
            is_int($v)   => PDO::PARAM_INT,
            is_bool($v)  => PDO::PARAM_BOOL,
            is_null($v)  => PDO::PARAM_NULL,
            default      => PDO::PARAM_STR,
        };
    }

    // ---------------------------------------------------------------------
    // WHERE/HAVING builder
    // ---------------------------------------------------------------------

    /** @param 'where'|'having' $type */
    private function buildWhere(string $type): void
    {
        $arrs = $this->{$type};
        if (!is_array($arrs) || count($arrs) === 0) {
            return;
        }

        $clause = ' ' . ($type === 'having' ? 'HAVING' : 'WHERE') . ' ';

        foreach ($arrs as $key => $item) {
            $prev = $arrs[$key - 1] ?? null;
            $next = $arrs[$key + 1] ?? null;

            $openGroup = $item['grouped'] === true &&
                ($prev === null || $prev['grouped'] !== true || $prev['group_id'] !== $item['group_id']);
            if ($openGroup) {
                $clause .= ($prev && $prev['grouped'] === true ? ' ' . $item['logical'] : '') . ' (';
            }

            $piece = $this->buildSafeWhereClause($item);

            if ($key === 0) {
                $clause .= $piece . ($item['grouped'] === false && isset($next['grouped']) ? ' ' . $item['logical'] : '');
            } else {
                $clause .= ' ' . $item['logical'] . ' ' . $piece;
            }

            $closeGroup = $item['grouped'] === true &&
                ($next === null || $next['grouped'] !== true || $next['group_id'] !== $item['group_id']);
            if ($closeGroup) {
                $clause .= ' )';
            }
        }

        $clause = rtrim($clause, '|&');
        $clause = preg_replace('/\(\s+(\|\||&&)/', '(', $clause) ?? $clause;
        $clause = preg_replace('/(\|\||&&)\s+\)/', ')', $clause) ?? $clause;

        $this->sql      .= $clause;
        $this->unionSql .= $clause;
        $this->{$type}   = [];
    }

    private function buildSafeWhereClause(array $item): string
    {
        $column = $item['column'];
        $value  = $item['value'];
        $mark   = strtoupper($item['mark']);

        switch ($mark) {
            case 'IS NULL':
            case 'IS NOT NULL':
                return $column . ' ' . $mark;

            case 'RAW':
                $expr = $value;
                foreach ($item['bindings'] ?? [] as $bv) {
                    $p = $this->createSafeParam();
                    $this->bindParams[$p] = $bv;
                    $expr = preg_replace('/\?/', $p, $expr, 1) ?? $expr;
                }
                return '(' . $expr . ')';

            case 'LIKE':
            case 'NOT LIKE':
                $p = $this->createSafeParam();
                $this->bindParams[$p] = '%' . $value . '%';
                return "{$column} {$mark} {$p}";

            case 'BETWEEN':
            case 'NOT BETWEEN':
                if (!is_array($value) || count($value) !== 2) {
                    throw new ValidationException('BETWEEN requires exactly 2 values');
                }
                $p1 = $this->createSafeParam();
                $p2 = $this->createSafeParam();
                $this->bindParams[$p1] = $value[0];
                $this->bindParams[$p2] = $value[1];
                return "{$column} {$mark} {$p1} AND {$p2}";

            case 'IN':
            case 'NOT IN':
                $values = is_array($value) ? $value : [$value];
                if (count($values) === 0) {
                    return $mark === 'IN' ? '1 = 0' : '1 = 1';
                }
                $params = [];
                foreach ($values as $v) {
                    $p = $this->createSafeParam();
                    $this->bindParams[$p] = $v;
                    $params[] = $p;
                }
                return "{$column} {$mark} (" . implode(', ', $params) . ')';

            case 'FIND_IN_SET':
                $p = $this->createSafeParam();
                $this->bindParams[$p] = $value;
                return "FIND_IN_SET({$p}, {$column})";

            case 'FIND_IN_SET_REVERSE':
                $p = $this->createSafeParam();
                $this->bindParams[$p] = $value;
                return "FIND_IN_SET({$column}, {$p})";

            case 'SOUNDEX':
                $p = $this->createSafeParam();
                $this->bindParams[$p] = $value;
                return "SOUNDEX({$column}) LIKE CONCAT('%', TRIM(TRAILING '0' FROM SOUNDEX({$p})), '%')";

            default:
                if (!in_array($mark, ['=', '!=', '<>', '<', '>', '<=', '>='], true)) {
                    throw new ValidationException("Invalid comparison operator: {$mark}");
                }
                if (is_string($value) && in_array(trim($value), $this->reference, true)) {
                    return "{$column} {$mark} {$value}";
                }
                $p = $this->createSafeParam();
                $this->bindParams[$p] = $value;
                return "{$column} {$mark} {$p}";
        }
    }

    // ---------------------------------------------------------------------
    // INSERT / UPDATE / DELETE
    // ---------------------------------------------------------------------

    public function insert(string $tableName): self
    {
        $this->resetState();
        $this->sql = 'INSERT INTO ' . $this->validateTableName($tableName);
        $this->tableName = $tableName;
        return $this;
    }

    public function update(string $tableName): self
    {
        $this->resetState();
        $this->sql = 'UPDATE ' . $this->validateTableName($tableName);
        $this->tableName = $tableName;
        return $this;
    }

    public function delete(string $tableName): self
    {
        $this->resetState();
        $this->sql = 'DELETE FROM ' . $this->validateTableName($tableName);
        $this->tableName = $tableName;
        return $this;
    }

    /**
     * @param array<string, mixed>|string $data
     */
    public function set(array|string $data, mixed $value = null): bool
    {
        try {
            $this->bindParams = [];
            $this->paramCounter = 0;
            $setParts = [];
            $execute  = [];

            if (is_string($data)) {
                $safeCol = $this->validateColumnName($data, $this->tableName);
                [$sqlFrag, $extra] = $this->buildSetExpression($safeCol, $value);
                $setParts[] = $sqlFrag;
                $execute    = $extra;
            } else {
                foreach ($data as $column => $val) {
                    $safeCol = $this->validateColumnName((string) $column, $this->tableName);
                    [$sqlFrag, $extra] = $this->buildSetExpression($safeCol, $val);
                    $setParts[] = $sqlFrag;
                    $execute   += $extra;
                }
            }

            $this->sql .= ' SET ' . implode(', ', $setParts);
            $this->buildWhere('where');
            $this->buildWhere('having');

            $stmt = $this->prepare($this->sql);
            foreach (array_merge($execute, $this->bindParams) as $p => $v) {
                $stmt->bindValue($p, $v, $this->pdoType($v));
            }
            $ok = $stmt->execute();
            $this->resetState();
            return $ok;
        } catch (PDOException $e) {
            $this->handleError($e);
            return false;
        }
    }

    /**
     * @return array{0:string, 1:array<string, mixed>}
     */
    private function buildSetExpression(string $safeCol, mixed $val): array
    {
        // Self-increment: "+5" or "-3"
        if (is_string($val) && preg_match('/^([+\-])\s*(\d+)$/', $val, $m)) {
            return ["{$safeCol} = {$safeCol} {$m[1]} " . (int) $m[2], []];
        }

        // Reference literal (NOW(), NULL, CURRENT_TIMESTAMP)
        if (is_string($val) && in_array(trim($val), $this->reference, true)) {
            return ["{$safeCol} = {$val}", []];
        }

        $p = $this->createSafeParam();
        return ["{$safeCol} = {$p}", [$p => $val]];
    }

    public function done(): int|bool
    {
        try {
            $this->bindParams = [];
            $this->paramCounter = 0;
            $this->buildWhere('where');
            $this->buildWhere('having');

            $stmt = $this->prepare($this->sql);
            foreach ($this->bindParams as $p => $v) {
                $stmt->bindValue($p, $v, $this->pdoType($v));
            }
            $ok = $stmt->execute();
            $rows = $stmt->rowCount();
            $this->resetState();
            return $ok ? $rows : false;
        } catch (PDOException $e) {
            $this->handleError($e);
            return false;
        }
    }

    public function lastId(): int
    {
        return (int) $this->lastInsertId();
    }

    // ---------------------------------------------------------------------
    // Transactions
    // ---------------------------------------------------------------------

    /**
     * Run the callback inside a transaction. Commits on success,
     * rolls back on any Throwable and rethrows.
     */
    public function transaction(Closure $fn): mixed
    {
        $this->beginTransaction();
        try {
            $result = $fn($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->inTransaction()) {
                $this->rollBack();
            }
            throw $e;
        }
    }

    // ---------------------------------------------------------------------
    // Pagination
    // ---------------------------------------------------------------------

    /**
     * @param int|string|null $page  int = page number, string = $_GET key (BC), null = 1
     * @return array{start:int, limit:int}
     */
    public function pagination(int $totalRecord, int $limit, int|string|null $page = null): array
    {
        $this->paginationLimit = max(1, $limit);
        $this->totalRecord = max(0, $totalRecord);
        $this->page = match (true) {
            is_int($page)    => max(1, $page),
            is_string($page) => isset($_GET[$page]) && is_numeric($_GET[$page]) ? max(1, (int) $_GET[$page]) : 1,
            default          => 1,
        };
        $this->pageCount = (int) ceil($this->totalRecord / $this->paginationLimit);
        $start = ($this->page - 1) * $this->paginationLimit;
        return ['start' => $start, 'limit' => $this->paginationLimit];
    }

    public function showPagination(string $url, string $class = 'active'): ?string
    {
        if ($this->totalRecord <= $this->paginationLimit) {
            return null;
        }
        $this->html = '';
        for ($i = $this->page - 5; $i <= $this->page + 5; $i++) {
            if ($i > 0 && $i <= $this->pageCount) {
                $this->html .= str_replace(
                    ['[pagination-active]', '[text]', '[url]'],
                    [$i === $this->page ? $class : '', (string) $i, str_replace('[page]', (string) $i, $url)],
                    $this->paginationItem
                );
            }
        }
        return $this->html;
    }

    public function nextPage(): int
    {
        return $this->page + 1 <= $this->pageCount ? $this->page + 1 : $this->pageCount;
    }

    public function prevPage(): int
    {
        return $this->page - 1 > 0 ? $this->page - 1 : 1;
    }

    // ---------------------------------------------------------------------
    // Utility
    // ---------------------------------------------------------------------

    public function getSqlString(): string
    {
        $temp = $this->sql;
        $this->buildWhere('where');
        $this->buildWhere('having');
        $out = $this->renderErrorHtml($this->sql, static::class . ' SQL');
        $this->sql = $temp;
        return $out;
    }

    public function truncate(string $tableName): PDOStatement|false
    {
        $safe = $this->validateTableName($tableName);
        return $this->query("TRUNCATE TABLE {$safe}");
    }

    /**
     * @param array<int, string> $dbs
     */
    public function truncateAll(array $dbs = []): void
    {
        if (count($dbs) === 0) {
            $dbs[] = $this->dbName;
        }
        foreach ($dbs as $db) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $db)) {
                throw new ValidationException("Invalid database name: {$db}");
            }
        }

        $placeholders = [];
        $binds = [];
        foreach ($dbs as $i => $db) {
            $k = ":db{$i}";
            $placeholders[] = $k;
            $binds[$k] = $db;
        }

        $sql = 'SELECT CONCAT("TRUNCATE TABLE `", table_schema, "`.`", TABLE_NAME, "`;") AS query, '
             . 'TABLE_NAME AS tableName, table_schema AS schemaName '
             . 'FROM INFORMATION_SCHEMA.TABLES WHERE table_schema IN (' . implode(',', $placeholders) . ')';
        $stmt = $this->prepare($sql);
        $stmt->execute($binds);
        $rows = $stmt->fetchAll(PDO::FETCH_OBJ);

        $this->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($rows as $row) {
            $this->setAutoIncrement($row->tableName);
            $this->exec($row->query);
        }
        $this->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function setAutoIncrement(string $tableName, int $ai = 1): PDOStatement|false
    {
        $safe = $this->validateTableName($tableName);
        $ai = max(1, $ai);
        return $this->query("ALTER TABLE {$safe} AUTO_INCREMENT = {$ai}");
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function resetState(): void
    {
        $this->sql = '';
        $this->unionSql = '';
        $this->type = '';
        $this->where = [];
        $this->having = [];
        $this->join = [];
        $this->orderBy = null;
        $this->groupBy = null;
        $this->limit = null;
        $this->random = false;
        $this->bindParams = [];
        $this->paramCounter = 0;
        $this->grouped = false;
        $this->groupId = 0;
    }

    private function handleError(PDOException $e): void
    {
        $mode = $this->debug ? self::ERROR_DEBUG : $this->errorMode;

        switch ($mode) {
            case self::ERROR_DEBUG:
                echo $this->renderErrorHtml($e->getMessage());
                return;
            case self::ERROR_SILENT:
                error_log('[BasicDB] ' . $e->getMessage());
                return;
            case self::ERROR_THROW:
            default:
                error_log('[BasicDB] ' . $e->getMessage());
                throw new QueryException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    private function renderErrorHtml(string $msg, ?string $title = null): string
    {
        $t = $title ?? static::class . ' Error';
        $safe = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<div class="db-error-msg-content">
    <div class="db-error-title">{$t}</div>
    <div class="db-error-msg">{$safe}</div>
</div>
<style>
.db-error-msg-content{padding:15px;border-left:5px solid #c00;background:#f8f8f8;margin-bottom:10px}
.db-error-title{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;font-size:16px;font-weight:500}
.db-error-msg{margin-top:15px;font-size:14px;font-family:Consolas,Monaco,Menlo,"Courier New",monospace;color:#c00}
</style>
HTML;
    }

    public function __call(string $name, array $args): never
    {
        throw new \BadMethodCallException(
            sprintf('Method %s::%s() does not exist.', static::class, $name)
        );
    }
}
