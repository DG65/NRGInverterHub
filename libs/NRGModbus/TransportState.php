<?php
// NRGModbus 0.2.0 quelle:eb736be015ea | Kopie, nicht ändern (Quelle: NRGModbus)
namespace IHUB\NRGModbus;

/** Zustand der letzten Operation eines Transports. Wird vor jeder Operation zurückgesetzt. */
final class TransportState
{
    /** Grund des letzten Fehlschlags: 'connect' | 'write' | 'eof' | 'timeout' | 'frame' | 'busy' | 'no_response' | 'no_parent' | '' (kein Fehler, auch bei einer Modbus-Exception). */
    public $error = '';
    /** Fehlernummer und -text von fsockopen() beim letzten Verbindungsfehler. */
    public $errno = 0;
    public $errstr = '';
    /** Exception-Code der letzten Modbus-Exception-Antwort, sonst 0. */
    public $exceptionCode = 0;
    /** Letzte gesendete und empfangene Rohframes (Hex) für die Fehlersuche. */
    public $requestHex = '';
    public $responseHex = '';
    /** Anzahl aufgebauter Verbindungen (Prüfstand, Diagnose). */
    public $connects = 0;
}
