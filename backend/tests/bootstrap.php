<?php

namespace Dealer\Wallet\Config {
    class Database
    {
        private static ?\MockDatabase $instance = null;

        public static function getConnection(): \MockDatabase
        {
            if (self::$instance === null) {
                self::$instance = \MockDatabase::getInstance();
            }
            return self::$instance;
        }

        public static function resetInstance(): void
        {
            self::$instance = null;
        }
    }
}

namespace {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/MockDatabase.php';

    \Dealer\Wallet\Config\Database::resetInstance();
    $db = \Dealer\Wallet\Config\Database::getConnection();
    $db->clearAll();

    date_default_timezone_set('Asia/Shanghai');
}
