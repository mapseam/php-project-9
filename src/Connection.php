<?php

namespace App;

use Dotenv\Dotenv;

final class Connection
{
    private static ?Connection $conn = null;

    public function connect()
    {
        $dotenv = Dotenv::createImmutable('../');
        $dotenv->safeLoad();
        $dbURL = $_ENV['DATABASE_URL'];

        if ($dbURL) {
            $databaseUrl = parse_url($dbURL);
        }
        if (isset($databaseUrl['scheme'])) {
            $params['host'] = $databaseUrl['host'];
            $params['port'] = isset($databaseUrl['port']) ? $databaseUrl['port'] : 5432;
            $params['dbname'] = isset($databaseUrl['path']) ? ltrim($databaseUrl['path'], '/') : null;
            $params['user'] = isset($databaseUrl['user']) ? $databaseUrl['user'] : null;
            $params['password'] = isset($databaseUrl['pass']) ? $databaseUrl['pass'] : null;
        } else {
            $params = parse_ini_file('database.ini');
        }
        if ($params === false) {
            throw new \Exception("Error reading database configuration file");
        }

        $conStr = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $params['host'],
            $params['port'],
            $params['dbname'],
            $params['user'],
            $params['password']
        );

        $pdo = new \PDO($conStr);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public static function get()
    {
        if (null === static::$conn) {
            static::$conn = new self();
        }

        return static::$conn;
    }

    protected function __construct()
    {
    }
}
