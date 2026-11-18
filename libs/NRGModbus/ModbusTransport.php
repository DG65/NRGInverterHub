<?php
// NRGModbus 0.2.0 quelle:eb736be015ea | Kopie, nicht ändern (Quelle: NRGModbus)
namespace IHUB\NRGModbus;

/**
 * Ein Übertragungsweg (direkt per Socket oder über die Symcon-Modbus-Gateway-Instanz).
 * Lesen liefert bei jedem Fehler null, nie eine Ausnahme, nie false, nie [] (Abstimmung 18.11.2026, Punkt 1).
 * Schreiben liefert bool; den Grund liefert state().
 */
interface ModbusTransport
{
    // Öffentliches Feld $unitId (int): beide Transporte führen es, ModbusClient::setUnitId() setzt es.

    /** @param int $fc 3 (Holding) oder 4 (Input) @return array|null Register 0-indiziert */
    public function read(int $fc, int $address, int $count): ?array;

    /** @param int $fc 6 (ein Register) oder 16 (mehrere) @param int[] $words */
    public function write(int $fc, int $address, array $words): bool;

    /** Eine Verbindung für viele Aufrufe (Batch). Schlägt das Öffnen fehl, bleibt der Einzelverbindungs-Betrieb (kein Fehler). */
    public function beginBatch(): void;

    public function endBatch(): void;

    /** Schließt eine gehaltene Verbindung (Batch und Zyklus). Gefahrlos mehrfach aufrufbar. */
    public function close(): void;

    /** @return TransportState Zustand der letzten Operation */
    public function state(): TransportState;
}
