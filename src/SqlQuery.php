<?php

namespace App;

class SqlQuery
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private function queryType(string $sql): string
    {
        $result = mb_strtoupper(explode(' ', $sql, 2)[0]);
        return $result;
    }

    private function query(string $sql, array $params = []): \PDOStatement|false
    {
        $stmt = $this->pdo->prepare($sql);
        if ($stmt instanceof \PDOStatement) {
            if (! empty($params)) {
                foreach ($params as $key => $value) {
                    $stmt->bindValue(":$key", $value);
                }
            }
            $stmt->execute();
        }
        return $stmt;
    }

    public function insert(string $sql, array $params = []): string|false
    {
        $result = false;
        if ($this->queryType($sql) != 'INSERT') {
            return $result;
        }

        $stmt = $this->query($sql, $params);
        if ($stmt instanceof \PDOStatement) {
            $result = $this->pdo->lastInsertId();
        }
        return $result;
    }

    public function select(string $sql, array $params = []): array
    {
        $result = [];
        if ($this->queryType($sql) != 'SELECT') {
            return $result;
        }

        $stmt = $this->query($sql, $params);
        if ($stmt instanceof \PDOStatement) {
            $result = $stmt->fetchAll($this->pdo::FETCH_ASSOC);
        }
        return $result;
    }
}
