<?php

namespace SolutionForest\OcppPhp\Ocpp\v16;

class TransactionManager {

    public function __construct($db = null) {
        // DB više nije potreban
    }

    /**
     * CSMS sada radi sav upis u bazu.
     * Ovdje samo vracamo podatke ako ih CSMS želi koristiti.
     */
    public function startTransaction($transactionId, $chargepointId, $connectorId, $timestamp) {
        return [
            "transactionId" => $transactionId,
            "chargepointId" => $chargepointId,
            "connectorId"   => $connectorId,
            "timestamp"     => $timestamp
        ];
    }

    public function stopTransaction($transactionId, $timestamp, $energy) {
        return [
            "transactionId" => $transactionId,
            "timestamp"     => $timestamp,
            "energy"        => $energy
        ];
    }
}
