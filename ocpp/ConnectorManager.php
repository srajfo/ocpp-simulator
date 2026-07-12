<?php

namespace SolutionForest\OcppPhp\Ocpp\v16;

class ConnectorManager {

    public function __construct($db = null) {
        // DB više nije potreban
    }

    /**
     * CSMS sada radi sav posao.
     * Ova metoda ostaje samo kao placeholder ako je poziva CSMS.
     */
    public function updateConnectorStatus($chargepointId, $connectorId, $status, $errorCode, $timestamp) {
        return [
            "chargepointId" => $chargepointId,
            "connectorId"   => $connectorId,
            "status"        => $status,
            "errorCode"     => $errorCode,
            "timestamp"     => $timestamp
        ];
    }
}
