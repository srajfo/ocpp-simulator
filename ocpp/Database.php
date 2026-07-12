<?php

namespace SolutionForest\OcppPhp\Ocpp\v16;

use PDO;
use PDOException;

class Database {

    private $pdo;
    private $dsn;
    private $user;
    private $pass;

    public function __construct() {
        $this->dsn  = "mysql:host=localhost;dbname=ocpp_simulator;charset=utf8mb4";
        $this->user = "srajf";
        $this->pass = "Passw0rd";

        $this->connect();
    }

    /**
     * Initial connection
     */
    private function connect() {
        try {
            $this->pdo = new PDO(
                $this->dsn,
                $this->user,
                $this->pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_PERSISTENT         => false
                ]
            );
        } catch (PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Reconnect when MySQL drops the connection
     */
    private function reconnect() {
        try {
            $this->connect();
        } catch (PDOException $e) {
            throw new \Exception("Reconnect failed: " . $e->getMessage());
        }
    }

    /**
     * Safe query with auto-reconnect
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;

        } catch (PDOException $e) {

            // MySQL server has gone away
            if ($e->getCode() == 2006 || $e->getCode() == 2013) {
                $this->reconnect();

                // retry once
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }

            throw $e;
        }
    }
}
