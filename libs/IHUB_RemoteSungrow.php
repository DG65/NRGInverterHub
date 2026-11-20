<?php

// ---------------------------------------------------------------------------
// IHUB_RemoteSungrow — Sungrow Hybrid (SH-RS / SH-RT / SH-T, Modbus TCP) mit "externem EMS-Modus" (Modus 3) als Adapter der gemeinsamen
// Fernsteuerung (IHUB_RemoteControl). Quelle: Sungrow Hybrid Protocol V1.1.11 (Abschnitt 3.5, Heartbeat), evcc, mkaiser/Sungrow-SHx, OpenEMS,
// SunGather; Beleg-Auswertung in Hersteller-Doku/Recherche-Sungrow-Fernsteuerung-Runde3.md. UNGETESTET (rc_tested = false).
//
// Register (Dokumentnummern; Holding FC 03/06/16, Status und Gerätedaten FC 04), je 16 Bit:
//   13050 EMS-Modus: 0 Eigenverbrauch, 2 Zwang, 3 externes EMS, 4 VPP      13051 Befehl: 0xAA (170) laden, 0xBB (187) entladen, 0xCC (204) stopp
//   13052 Leistung in W (Praxis laut Projekten; das Dokument ist uneinheitlich: W oder % der BDC-Nennleistung)
//   13080 Heartbeat in s (1..1000, Vorgabe 20): im Modus 3 muss er innerhalb der Zeit neu geschrieben werden, sonst fällt das Gerät in den
//         Eigenverbrauch zurück (ältere Dokumentfassung V1.0.20: "PCS will stop running")
//   Lesen: 5000 Gerätetypcode (FC 04), 5628 BDC-Nennleistung in 0,1 kW (FC 04), 13000 Laufstatus (FC 04: 0x4000 externes EMS, 0x0800 Zwang)
// ADRESSVERSATZ (Widerspruch, offen): Das Sungrow-Dokument verlangt Adresse = Nummer − 1, alle Projekte halten sich daran; der bestehende
// InverterHub-Treiber liest die Dokumentnummern direkt (laut Changelog an einem SH 6.0RT bestätigt). Der Adapter probiert deshalb den
// Gerätetypcode bei 5000 und bei 4999 und nimmt den Treffer (Liste der Typcodes aus dem Dokument, Anhang 1).
//
// Modus 1 (Halten) = Befehl 0xCC im Modus 3 mit laufendem Heartbeat: hat also den Totmann. Modus 2 (Akku ±W) = 0xAA/0xBB mit Betrag.
// Modus 0 = 13050 := 0 (Eigenverbrauch), Befehl 0xCC, Leistung 0. Kein Modus 3 (Netzanschlusspunkt): das Gerät hat keinen Netzsollwert.
// Flash: 13051, 13052 und 13080 gelten als flüchtig, 13050 wahrscheinlich nicht (ein Projekt berichtet, der Modus überlebe das Abschalten):
// 13050 wird deshalb nur bei Abweichung geschrieben, im Zyklus nur 13051/13052 und 13080.
// NICHT geschrieben: 33047/33048 (der evcc-Haltweg blieb dort hängen), 13058/13059 (SOC-Grenzen), 13074 (Einspeisegrenze), 13100.
// ---------------------------------------------------------------------------
class IHUB_RemoteSungrow implements IHUB_RemoteAdapter, IHUB_RemotePreparing
{
    const R_TYPE = 5000;
    const R_RATED = 5628;
    const R_STATUS = 13000;
    const R_MODE = 13050;
    const R_CMD = 13051;
    const R_POWER = 13052;
    const R_HB = 13080;
    const MODE_EXTERNAL = 3;
    const CMD_CHARGE = 0xAA;
    const CMD_DISCHARGE = 0xBB;
    const CMD_STOP = 0xCC;
    const TIMEOUT_DEFAULT_S = 60;
    const TIMEOUT_MIN_S = 10;
    const TIMEOUT_MAX_S = 1000;

    public static $gapUs = 1000000;          // Sungrow: mindestens 1000 ms zwischen zwei Anfragen
    public static $readbackUs = 1500000;
    public static $retryPauseUs = 2000000;

    /** Gerätetypcodes aus dem Anhang des Sungrow-Dokuments (SH-RS, SH-RT, SH-RT V112, SH-T). */
    public static function isType(int $v): bool
    {
        return ($v >= 0xD0D && $v <= 0xD1B) || $v === 0xE03 || ($v >= 0xE0C && $v <= 0xE13) || ($v >= 0xE20 && $v <= 0xE28);
    }

    public function info(): array
    {
        return [
            'vendor' => 'Sungrow', 'source' => 'Sungrow Hybrid Protocol V1.1.11, external EMS mode 13050 = 3 with heartbeat 13080',
            'tested' => false, 'modes' => [0, 1, 2], 'gridCharge' => false, 'socWindow' => false,
            'failsafeMinSec' => self::TIMEOUT_MIN_S, 'failsafeMaxSec' => self::TIMEOUT_MAX_S, 'failsafeDefaultSec' => self::TIMEOUT_DEFAULT_S,
            'pitfalls' => [
                'untested: built from the Sungrow document and from other projects, not checked on one of our devices',
                'the failsafe is the heartbeat 13080 (1 to 1000 s, 20 s from the factory) in the external EMS mode 13050 = 3: without a new write the inverter falls back to self-consumption',
                'older document versions (V1.0.20) say the inverter stops running instead of falling back: older firmware may stop',
                'open: it is unknown whether reading restarts the heartbeat timer',
                'the forced mode (13050 = 2) has NO failsafe and is not used',
                'the register addresses differ by one between the document (number minus one) and the existing driver: the adapter finds the offset by the device type code at 5000 or 4999',
                'the power 13052 is taken in W as other projects report; the document also names a percentage of the battery converter rating for some models',
                '13050 is probably stored permanently and is only written when it differs; 13051, 13052 and 13080 are written in every cycle',
                'the same numbers 13050 to 13053 read as alarm values with function 04: read back only with function 03',
                'at least 1000 ms between two requests; the WiNet-S dongle is slow and allows one connection, write to the master only',
                'without allowed grid import the inverter may fight itself while charging; iSolarCloud schedules or VPP can overwrite 13050',
                'not offered: mode 3 (grid connection point), the maximum charge and discharge registers 33047/33048 as a hold, the SOC limits 13058/13059, the feed-in limit 13074',
            ],
        ];
    }

    /** Versatz der Wire-Adresse zur Dokumentnummer: 0 oder −1; null = kein bekannter Gerätetypcode (kein SH-Hybrid). */
    private function offset($mb): ?int
    {
        $r = $mb->readInput(self::R_TYPE - 1, 2);
        if ($r === null || count($r) < 2) {
            return null;
        }
        if (self::isType((int)$mb->u16($r, 1))) {
            return 0;
        }
        return self::isType((int)$mb->u16($r, 0)) ? -1 : null;
    }

    private function readInput16($mb, int $off, int $reg): ?int
    {
        $r = $mb->readInput($reg + $off, 1);
        return ($r === null || count($r) < 1) ? null : (int)$mb->u16($r, 0);
    }

    public function capability($mb): ?array
    {
        $r = $mb->readInput(self::R_TYPE - 1, 2);
        if ($r === null) {
            return null;
        }
        $off = $this->offset($mb);
        if ($off === null) {
            return ['available' => false, 'version' => 0, 'status' => 0];
        }
        $h = $mb->readHolding(self::R_MODE + $off, 1);
        if ($h === null) {
            return ['available' => false, 'version' => 0, 'status' => 0];     // String-Wechselrichter ohne EMS-Register
        }
        $cap = ['available' => true, 'version' => 1, 'status' => (int)$this->readInput16($mb, $off, self::R_STATUS), 'off' => $off];
        $rated = $this->readInput16($mb, $off, self::R_RATED);
        if ($rated !== null && $rated > 0 && $rated < 0xFFFF) {
            $cap['ratedW'] = $rated * 100;
        }
        return $cap;
    }

    public function prepare($mb, array $cap): array
    {
        $off = $this->offset($mb);
        if ($off === null) {
            unset($cap['off'], $cap['cfg']);
            return $cap;
        }
        $cap['off'] = $off;
        $rated = $this->readInput16($mb, $off, self::R_RATED);
        if ($rated !== null && $rated > 0 && $rated < 0xFFFF) {
            $cap['ratedW'] = $rated * 100;
        }
        $b = $mb->readHolding(self::R_MODE + $off, 3);
        $h = $mb->readHolding(self::R_HB + $off, 1);
        if ($b === null || count($b) < 3 || $h === null) {
            unset($cap['cfg']);
            return $cap;
        }
        $cap['cfg'] = ['mode' => (int)$mb->u16($b, 0), 'cmd' => (int)$mb->u16($b, 1), 'power' => (int)$mb->u16($b, 2), 'hb' => (int)$mb->u16($h, 0)];
        $cap['status'] = (int)$this->readInput16($mb, $off, self::R_STATUS);
        return $cap;
    }

    public function capabilityError(?array $cap): string
    {
        if ($cap === null) {
            return IHUB_Text::t('Sungrow remote control: the device did not answer the device type code (registers 4999 and 5000).');
        }
        return $cap['available'] ? '' : IHUB_Text::t('Sungrow remote control: this is no Sungrow hybrid inverter with EMS registers (device type code unknown, or register 13050 is not offered).');
    }

    public function validate(array $req, ?array $cap): string
    {
        $mode = (int)$req['mode'];
        if (!in_array($mode, [0, 1, 2], true)) {
            return IHUB_Text::t('Sungrow remote control: mode %s is not offered (0 off, 1 hold, 2 battery).', $mode);
        }
        if (!isset($cap['off'])) {
            return IHUB_Text::t('Sungrow remote control: the register offset could not be determined (device type code at 4999 or 5000 not found).');
        }
        if ($mode === 2 && (int)($req['watts'] ?? 0) !== 0 && (int)($cap['ratedW'] ?? 0) <= 0) {
            return IHUB_Text::t('Sungrow remote control: the rated power (register 5628) is unknown, the power cannot be limited.');
        }
        return '';
    }

    private function timeout(array $req): int
    {
        $s = (int)($req['failsafeSec'] ?? 0);
        return $s <= 0 ? self::TIMEOUT_DEFAULT_S : max(self::TIMEOUT_MIN_S, min(self::TIMEOUT_MAX_S, $s));
    }

    /** [Befehl, Leistung in W] je Modus; einheitlich + Laden. */
    public static function command(int $mode, int $watts, int $ratedW): array
    {
        if ($mode !== 2 || $watts === 0) {
            return [self::CMD_STOP, 0];
        }
        $p = abs($watts);
        if ($ratedW > 0) {
            $p = min($p, $ratedW);
        }
        return [$watts > 0 ? self::CMD_CHARGE : self::CMD_DISCHARGE, min($p, 0xFFFF)];
    }

    public function plan(array $req, array $cap, int $last): array
    {
        $mode = (int)$req['mode'];
        $off = (int)$cap['off'];
        $cfg = $cap['cfg'] ?? null;
        $h = $cfg ?? [];
        $steps = [];
        if ($mode === 0) {
            if (($h['mode'] ?? null) === 0 && ($h['cmd'] ?? null) === self::CMD_STOP && ($h['power'] ?? null) === 0) {
                return ['steps' => []];
            }
            return ['steps' => [['fc' => 16, 'addr' => self::R_MODE + $off, 'words' => [0, self::CMD_STOP, 0], 'afterUs' => 0]]];
        }
        [$cmd, $p] = self::command($mode, (int)($req['watts'] ?? 0), (int)($cap['ratedW'] ?? 0));
        if ($cfg === null ? $last === 0 : ($h['mode'] !== self::MODE_EXTERNAL)) {
            $steps[] = ['fc' => 16, 'addr' => self::R_MODE + $off, 'words' => [self::MODE_EXTERNAL], 'afterUs' => self::$gapUs];
        }
        $steps[] = ['fc' => 16, 'addr' => self::R_CMD + $off, 'words' => [$cmd, $p], 'afterUs' => self::$gapUs];
        $steps[] = ['fc' => 16, 'addr' => self::R_HB + $off, 'words' => [$this->timeout($req)], 'afterUs' => 0];
        return ['steps' => $steps];
    }

    public function readback($mb, array $req, array $cap): ?array
    {
        $c = $this->prepare($mb, ['available' => true])['cfg'] ?? null;
        if ($c === null) {
            return null;
        }
        $off = $this->offset($mb);
        $st = $off === null ? 0 : (int)$this->readInput16($mb, $off, self::R_STATUS);
        return ['regs' => [$c['mode'], $c['cmd'], $c['power'], $c['hb']], 'status' => $st, 'cfg' => $c];
    }

    public function verify(array $back, array $req, array $cap): string
    {
        $c = $back['cfg'];
        $mode = (int)$req['mode'];
        $diff = fn($got, $want, $reg) => IHUB_Text::t('device acknowledged, but stored %s instead of %s (register %s)', $got, $want, $reg);
        if ($mode === 0) {
            foreach ([[self::R_MODE, $c['mode'], 0], [self::R_CMD, $c['cmd'], self::CMD_STOP], [self::R_POWER, $c['power'], 0]] as [$reg, $got, $want]) {
                if ($got !== $want) {
                    return $diff($got, $want, $reg);
                }
            }
            return '';
        }
        [$cmd, $p] = self::command($mode, (int)($req['watts'] ?? 0), (int)($cap['ratedW'] ?? 0));
        foreach ([[self::R_MODE, $c['mode'], self::MODE_EXTERNAL], [self::R_CMD, $c['cmd'], $cmd], [self::R_POWER, $c['power'], $p], [self::R_HB, $c['hb'], $this->timeout($req)]] as [$reg, $got, $want]) {
            if ($got !== $want) {
                return $diff($got, $want, $reg);
            }
        }
        return '';
    }

    public function readbackWaitUs(): int
    {
        return self::$readbackUs;
    }

    public function retryPauseUs(): int
    {
        return self::$retryPauseUs;
    }

    /** Laufstatus 13000: Bit 0x4000 = externes EMS, 0x0800 = Zwangsbetrieb. */
    public function isActive(int $status): bool
    {
        return ($status & 0x4000) !== 0 || ($status & 0x0800) !== 0;
    }

    public function effectiveFailsafeSec(array $req): int
    {
        return $this->timeout($req);
    }
}
