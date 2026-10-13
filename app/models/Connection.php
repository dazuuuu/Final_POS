<?php
// app/models/Connection.php
namespace Models;

use PDO;
use PDOException;

class Connection {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        // Same single config file + connection as the rest of the app.
        require_once __DIR__ . '/../helpers/PathConfig.php';
        require_once __DIR__ . '/../helpers/Database.php';
        $this->connection = \Database::pdo();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
}
?>