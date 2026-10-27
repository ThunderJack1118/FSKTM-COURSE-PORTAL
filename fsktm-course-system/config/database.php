<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'fsktm_courses');

class Database {
    private $conn;

    public function __construct() {
        $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if ($this->conn->connect_error) {
            error_log('Database connection failed: ' . $this->conn->connect_error);
            die('Database service is currently unavailable. Please try again later.');
        }

        if (!$this->conn->set_charset('utf8mb4')) {
            error_log('Failed to set database charset: ' . $this->conn->error);
        }
    }

    public function getConnection() {
        return $this->conn;
    }

    public function closeConnection() {
        if ($this->conn instanceof mysqli) {
            $this->conn->close();
        }
    }
}
?>
