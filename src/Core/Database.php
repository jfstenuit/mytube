<?php
namespace App\Core;

use PDO;

class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                'mysql:host=' . Config::get('db.host') . ';dbname=' . Config::get('db.name') . ';charset=utf8mb4',
                Config::get('db.user'),
                Config::get('db.pass')
            );
            self::$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return self::$pdo;
    }
}
