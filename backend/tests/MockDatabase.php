<?php

namespace {

    class MockStatement
    {
        private string $sql;
        private MockDatabase $db;
        private array $params = [];
        private array $resultSet = [];
        private int $cursor = 0;
        private int $affectedRows = 0;
        private int $fetchStyle = \PDO::FETCH_ASSOC;

        public function __construct(string $sql, MockDatabase $db)
        {
            $this->sql = $sql;
            $this->db = $db;
        }

        public function execute(array $params = []): bool
        {
            if (!empty($params)) {
                $this->params = array_merge($this->params, $params);
            }
            $this->db->lastSql = $this->sql;
            $this->db->lastParams = $this->params;
            $this->db->executeParsedSql($this->sql, $this->params, $this);
            return true;
        }

        public function bindValue($parameter, $value, $type = \PDO::PARAM_STR): bool
        {
            $this->params[$parameter] = $value;
            return true;
        }

        public function setResultSet(array $rows): void
        {
            $this->resultSet = $rows;
            $this->cursor = 0;
        }

        public function setAffectedRows(int $count): void
        {
            $this->affectedRows = $count;
        }

        public function fetch(int $mode = null, int $orientation = null, int $limit = null): false|array|\stdClass
        {
            if ($this->cursor >= count($this->resultSet)) {
                return false;
            }
            $row = $this->resultSet[$this->cursor];
            $this->cursor++;
            return $row;
        }

        public function fetchAll(int $mode = null): array
        {
            $all = array_slice($this->resultSet, $this->cursor);
            $this->cursor = count($this->resultSet);
            return $all;
        }

        public function fetchColumn(int $column = 0): mixed
        {
            $row = $this->fetch();
            if ($row === false) {
                return false;
            }
            $values = array_values($row);
            return $values[$column] ?? false;
        }

        public function rowCount(): int
        {
            return $this->affectedRows;
        }

        public function setFetchMode(int $mode): bool
        {
            $this->fetchStyle = $mode;
            return true;
        }
    }

    class MockDatabase
    {
        private array $tables = [];
        private array $autoIncrement = [];
        private bool $inTransaction = false;
        private array $savepoints = [];
        private static ?self $instance = null;
        public ?int $lastInsertId = null;
        public string $lastSql = '';
        public array $lastParams = [];

        public static function getInstance(): self
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public static function getConnection(): self
        {
            return self::getInstance();
        }

        public function prepare(string $sql): MockStatement
        {
            return new MockStatement($sql, $this);
        }

        public static function __callStatic($name, $arguments)
        {
            $instance = self::getInstance();
            return $instance->$name(...$arguments);
        }

        public function beginTransaction(): bool
        {
            if ($this->inTransaction) {
                $this->savepoints[] = $this->deepCloneTables();
            } else {
                $this->inTransaction = true;
                $this->savepoints = [$this->deepCloneTables()];
            }
            return true;
        }

        public function commit(): bool
        {
            if (!$this->inTransaction) {
                return false;
            }
            $this->savepoints = [];
            $this->inTransaction = false;
            return true;
        }

        public function rollBack(): bool
        {
            if (!$this->inTransaction) {
                return false;
            }
            if (!empty($this->savepoints)) {
                $this->tables = end($this->savepoints);
                array_pop($this->savepoints);
            }
            if (empty($this->savepoints)) {
                $this->inTransaction = false;
            }
            return true;
        }

        private function deepCloneTables(): array
        {
            $clone = [];
            foreach ($this->tables as $table => $rows) {
                $clone[$table] = array_map(fn($r) => $r, $rows);
            }
            return $clone;
        }

        public function inTransaction(): bool
        {
            return $this->inTransaction;
        }

        public function query(string $sql): MockStatement
        {
            $stmt = new MockStatement($sql, $this);
            $stmt->execute();
            return $stmt;
        }

        public function executeParsedSql(string $sql, array $params, MockStatement $stmt): void
        {
            $sql = trim($sql);
            $sql = $this->substituteParams($sql, $params);

            if (stripos($sql, 'INSERT INTO') === 0) {
                $this->executeInsertSql($sql, $stmt);
            } elseif (stripos($sql, 'UPDATE') === 0) {
                $this->executeUpdateSql($sql, $stmt);
            } elseif (stripos($sql, 'SELECT') === 0) {
                $this->executeSelectSql($sql, $stmt);
            } elseif (stripos($sql, 'SELECT COUNT') !== false || stripos($sql, 'COUNT(*)') !== false) {
                $this->executeCountSql($sql, $stmt);
            }
        }

        private function substituteParams(string $sql, array $params): string
        {
            foreach ($params as $key => $value) {
                $key = ltrim($key, ':');
                if ($value === null) {
                    $sql = preg_replace('/:' . preg_quote($key, '/') . '\b/', 'NULL', $sql);
                } elseif (is_numeric($value)) {
                    $sql = preg_replace('/:' . preg_quote($key, '/') . '\b/', (string)$value, $sql);
                } else {
                    $sql = preg_replace('/:' . preg_quote($key, '/') . '\b/', "'" . addslashes((string)$value) . "'", $sql);
                }
            }
            return $sql;
        }

        private function executeInsertSql(string $sql, MockStatement $stmt): void
        {
            if (!preg_match('/INSERT INTO\s+(\w+)\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/i', $sql, $m)) {
                return;
            }
            $table = trim($m[1]);
            $cols = array_map('trim', explode(',', $m[2]));
            $valsRaw = $this->splitValues($m[3]);
            $data = [];
            foreach ($cols as $i => $col) {
                $val = trim($valsRaw[$i] ?? '');
                $data[$col] = $this->parseLiteralValue($val);
            }
            $id = $this->executeInsert($table, $data);
            $stmt->setAffectedRows(1);
        }

        private function splitValues(string $valuesStr): array
        {
            $result = [];
            $current = '';
            $inQuote = false;
            $len = strlen($valuesStr);
            for ($i = 0; $i < $len; $i++) {
                $ch = $valuesStr[$i];
                if ($ch === "'") {
                    $inQuote = !$inQuote;
                    $current .= $ch;
                } elseif ($ch === ',' && !$inQuote) {
                    $result[] = $current;
                    $current = '';
                } else {
                    $current .= $ch;
                }
            }
            if ($current !== '') {
                $result[] = $current;
            }
            return $result;
        }

        private function parseLiteralValue(string $val)
        {
            $val = trim($val);
            if ($val === 'NULL' || $val === '') {
                return null;
            }
            if (preg_match("/^'(.*)'$/s", $val, $m)) {
                return stripslashes($m[1]);
            }
            if (is_numeric($val)) {
                if (strpos($val, '.') !== false) {
                    return (float)$val;
                }
                return (int)$val;
            }
            return $val;
        }

        private function executeUpdateSql(string $sql, MockStatement $stmt): void
        {
            if (!preg_match('/UPDATE\s+(\w+)\s+SET\s+(.+?)(?:\s+WHERE\s+(.+))?$/is', $sql, $m)) {
                return;
            }
            $table = trim($m[1]);
            $setClause = trim($m[2]);
            $whereClause = $m[3] ?? '';

            $data = [];
            foreach (explode(',', $setClause) as $part) {
                $part = trim($part);
                if (preg_match('/(\w+)\s*=\s*(.+)/s', $part, $pm)) {
                    $col = trim($pm[1]);
                    $val = trim($pm[2]);
                    if (preg_match('/^(\w+)\s*\+\s*(\d+)$/i', $val, $vm)) {
                        $data[$col] = 'INC:' . $vm[2];
                    } else {
                        $data[$col] = $this->parseLiteralValue($val);
                    }
                }
            }

            $where = $this->parseWhereClause($whereClause);
            $affected = $this->executeUpdate($table, $data, $where);
            $stmt->setAffectedRows($affected);
        }

        private function executeSelectSql(string $sql, MockStatement $stmt): void
        {
            $sql = preg_replace('/\s+FOR\s+UPDATE\s*$/i', '', $sql);

            if (!preg_match('/SELECT\s+(.+?)\s+FROM\s+(\w+)(?:\s+WHERE\s+(.+?))?(?:\s+ORDER\s+BY\s+(.+?))?(?:\s+LIMIT\s+(.+?))?$/is', $sql, $m)) {
                $stmt->setResultSet([]);
                return;
            }

            $table = trim($m[2]);
            $whereClause = $m[3] ?? '';
            $orderByClause = $m[4] ?? '';
            $limitClause = $m[5] ?? '';

            $where = $this->parseWhereClause($whereClause);

            $orderBy = null;
            $orderDir = 'ASC';
            if ($orderByClause) {
                $parts = preg_split('/\s+/', trim($orderByClause), 2, PREG_SPLIT_NO_EMPTY);
                $orderBy = $parts[0];
                if (isset($parts[1]) && strtoupper($parts[1]) === 'DESC') {
                    $orderDir = 'DESC';
                }
            }

            $limit = null;
            if ($limitClause) {
                $parts = array_map('trim', explode(',', $limitClause));
                $limit = (int)end($parts);
            }

            $rows = $this->executeSelect($table, $where, $orderBy, $orderDir, $limit);
            $stmt->setResultSet($rows);
            $stmt->setAffectedRows(count($rows));
        }

        private function executeCountSql(string $sql, MockStatement $stmt): void
        {
            $sql = preg_replace('/\s+FOR\s+UPDATE\s*$/i', '', $sql);

            if (!preg_match('/SELECT\s+COUNT\(\*\)\s+FROM\s+(\w+)(?:\s+WHERE\s+(.+))?$/is', $sql, $m)) {
                $stmt->setResultSet([[0 => 0]]);
                return;
            }
            $table = trim($m[1]);
            $whereClause = $m[2] ?? '';
            $where = $this->parseWhereClause($whereClause);
            $count = $this->executeCount($table, $where);
            $stmt->setResultSet([[0 => $count, 'COUNT(*)' => $count]]);
            $stmt->setAffectedRows(1);
        }

        private function parseWhereClause(string $clause): array
        {
            $where = [];
            if (!$clause) {
                return $where;
            }
            $parts = preg_split('/\s+AND\s+/i', $clause);
            foreach ($parts as $part) {
                $part = trim($part);
                if (preg_match('/(\w+)\s*=\s*(.+)/s', $part, $m)) {
                    $col = trim($m[1]);
                    $val = trim($m[2]);
                    $where[$col] = $this->parseLiteralValue($val);
                }
            }
            return $where;
        }

        public function executeInsert(string $table, array $data): int
        {
            if (!isset($this->tables[$table])) {
                $this->tables[$table] = [];
                $this->autoIncrement[$table] = 0;
            }
            $this->autoIncrement[$table]++;
            $id = $this->autoIncrement[$table];
            $row = array_merge(['id' => $id], $data);
            $this->tables[$table][] = $row;
            $this->lastInsertId = $id;
            return $id;
        }

        public function executeUpdate(string $table, array $data, array $where): int
        {
            if (!isset($this->tables[$table])) {
                return 0;
            }
            $count = 0;
            foreach ($this->tables[$table] as &$row) {
                $match = true;
                foreach ($where as $key => $value) {
                    if (!isset($row[$key]) || (string)$row[$key] !== (string)$value) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    foreach ($data as $key => $value) {
                        if (is_string($value) && str_starts_with($value, 'INC:')) {
                            $inc = (int)substr($value, 4);
                            $row[$key] = (int)($row[$key] ?? 0) + $inc;
                        } else {
                            $row[$key] = $value;
                        }
                    }
                    $count++;
                }
            }
            return $count;
        }

        public function executeSelect(string $table, array $where = [], ?string $orderBy = null, string $orderDir = 'ASC', $limit = null): array
        {
            if (!isset($this->tables[$table])) {
                return [];
            }
            $result = [];
            foreach ($this->tables[$table] as $row) {
                $match = true;
                foreach ($where as $key => $value) {
                    if (!isset($row[$key]) || (string)$row[$key] !== (string)$value) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    $result[] = $row;
                }
            }
            if ($orderBy) {
                usort($result, function ($a, $b) use ($orderBy, $orderDir) {
                    if ($orderDir === 'DESC') {
                        return $b[$orderBy] <=> $a[$orderBy];
                    }
                    return $a[$orderBy] <=> $b[$orderBy];
                });
            }
            if ($limit !== null) {
                $result = array_slice($result, 0, $limit);
            }
            return $result;
        }

        public function executeCount(string $table, array $where = []): int
        {
            return count($this->executeSelect($table, $where));
        }

        public function lastInsertId(?string $name = null): string
        {
            return (string)$this->lastInsertId;
        }

        public function clearAll(): void
        {
            $this->tables = [];
            $this->autoIncrement = [];
            $this->inTransaction = false;
            $this->savepoints = [];
            $this->lastInsertId = null;
            $this->lastSql = '';
            $this->lastParams = [];
        }

        public function quote($value, $type = \PDO::PARAM_STR): string
        {
            if (is_string($value)) {
                return "'" . addslashes($value) . "'";
            }
            return (string)$value;
        }

        public function errorCode(): ?string
        {
            return null;
        }

        public function errorInfo(): array
        {
            return ['00000', null, null];
        }

        public function setAttribute(int $attribute, mixed $value): bool
        {
            return true;
        }

        public function getAttribute(int $attribute): mixed
        {
            return null;
        }
    }
}
