<?php

namespace {

    class MockStatement
    {
        private string $sql;
        private MockDatabase $db;
        private array $params = [];
        private int $fetchStyle = \PDO::FETCH_ASSOC;

        public function __construct(string $sql, MockDatabase $db)
        {
            $this->sql = $sql;
            $this->db = $db;
        }

        public function execute(array $params = []): bool
        {
            $this->params = $params;
            return true;
        }

        public function bindValue($parameter, $value, $type = \PDO::PARAM_STR): bool
        {
            $this->params[$parameter] = $value;
            return true;
        }

        public function fetch(int $mode = null, int $orientation = null, int $limit = null): false|array|\stdClass
        {
            $results = $this->fetchAll();
            return $results[0] ?? false;
        }

        public function fetchAll(int $mode = null): array
        {
            return array_map(function ($row) {
            }, []);
        }

        public function fetchColumn(int $column = 0): mixed
        {
            $row = $this->fetch();
            if (!$row) {
                return false;
            }
            $values = array_values($row);
            return $values[$column] ?? false;
        }

        public function rowCount(): int
        {
            return 0;
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
            $this->lastSql = $sql;
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
            $this->lastSql = $sql;
            return new MockStatement($sql, $this);
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
                        $row[$key] = $value;
                    }
                    $count++;
                }
            }
            return $count;
        }

        public function executeSelect(string $table, array $where = [], ?string $orderBy = null, $limit = null): array
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
                usort($result, function ($a, $b) use ($orderBy) {
                    return $b[$orderBy] <=> $a[$orderBy];
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

    class_alias('MockDatabase', 'MockDatabase');
}
