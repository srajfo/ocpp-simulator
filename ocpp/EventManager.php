<?php

namespace SolutionForest\OcppPhp\Ocpp\v16;

class EventManager {

    public function __construct($db = null) {
        // DB više nije potreban
    }

    /**
     * CSMS radi sav upis u bazu.
     * Ovdje samo vracamo pripremljeni event.
     */
    public function logEvent($type, $action, $payload, $chargepointId) {
        return [
            "type"          => $type,
            "action"        => $action,
            "payload"       => $payload,
            "chargepointId" => $chargepointId,
            "timestamp"     => date("c")
        ];
    }
}
