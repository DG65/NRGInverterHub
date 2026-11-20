<?php

// ---------------------------------------------------------------------------
// IHUB_RemoteKostal — Kostal PLENTICORE (plus / BI / G3, Modbus TCP Port 1502, Unit-ID 71) mit "externem Batteriemanagement über Modbus"
// als Adapter der gemeinsamen Fernsteuerung (IHUB_RemoteControl). Quelle: KOSTAL MODBUS/SunSpec Interface Description Rev. 2.7 (20.11.2025),
// Kapitel 3.4 (S. 24 f.), und die Projekte evcc (kostal-plenticore-gen2), OpenEMS, mbuhansen/kostal_plenticore_battery_control,
// TCzerny/ha-modbus-manager (Issue 145, PLENTICORE G3 M, FW 3.07.03.27599, gemessen). UNGETESTET: an keinem Gerät von uns geprüft.
//
// Sollwert: Register 1034 "Battery charge power (DC) setpoint, absolute", Float32 in W, FC16; negativ = Laden, positiv = Entladen.
// Einheitlich + Laden: Gerät = −Leistung. Halten = 0 W. Gewählt wird nur der DC-Sollwert (nicht der AC-Sollwert 1026 der BI/G3).
//
// Totmann: Das Gerät hat EINEN gemeinsamen Zeitgeber für die Register 1024-1044 (gemessen: jeder Schreibzugriff startet ihn neu, Lesen nicht).
// Seine Dauer wird vom Installateur in der Weboberfläche eingestellt (gemessen: 30 s) und ist über Modbus weder les- noch setzbar. Läuft er ab,
// kehrt der Wechselrichter in seine eigene Regelung zurück. Deshalb: rc_failsafe_eff_sec bleibt 0 (= unbekannt), der Adapter muss in jedem
// Zyklus schreiben (das EMS höchstens alle halbe Zeitgeberdauer, gemessen waren 15 bis 25 s bei 30 s). Modus 0 hört einfach auf zu schreiben.
//
// Bewusst NICHT benutzt:
//  - Register 1280-1288 ("Battery limitation", nur G3 ab SW 03.05, offizieller Totmann mit Rückfallgrenzen und Zeit 30-10800 s): sie begrenzen
//    nur die Leistung. Wäre die Rückfallgrenze 0, bliebe der Speicher nach einem Ausfall dauerhaft gesperrt; wäre sie offen, schützte sie nichts.
//  - Minimum-SOC 1042: ein Wert über dem aktuellen SOC hat den Akku getrennt und in einem Bericht eine Batteriestörung ausgelöst.
//  - 1038/1040 (Lade-/Entladegrenzen): sie sind im Gerät die wirksamen Grenzen (BMS), kein sicherer Haltemechanismus.
// Freischaltung: Register 1080 muss 2 sein ("externes Batteriemanagement über MODBUS", im Webinterface mit dem Installateurcode). Bei 0 oder 1
// ignoriert der Wechselrichter jeden Schreibzugriff STILL, deshalb prüft die Fähigkeit 1080 vor jedem Schreiben.
// ---------------------------------------------------------------------------
class IHUB_RemoteKostal implements IHUB_RemoteAdapter, IHUB_RemotePreparing
{
    const REG_SETPOINT = 1034;      // Float32 W, RW
    const REG_MAX_CHARGE = 1076;    // Float32 W, RO: Ladegrenze laut Akku
    const REG_MAX_DISCHARGE = 1078; // Float32 W, RO
    const REG_MODE = 1080;          // U8, RO: 0 keins, 1 digitale I/O, 2 Modbus
    const MODE_MODBUS = 2;
    const TOLERANCE_W = 1.0;

    public static $gapUs = 500000;
    public static $readbackUs = 500000;
    public static $retryPauseUs = 1500000;

    public function info(): array
    {
        return [
            'vendor' => 'Kostal', 'source' => 'KOSTAL MODBUS/SunSpec Interface Description Rev. 2.7 (20.11.2025), external battery management 1034',
            'tested' => false, 'modes' => [0, 1, 2], 'gridCharge' => false, 'socWindow' => false,
            'failsafeMinSec' => 0, 'failsafeMaxSec' => 0, 'failsafeDefaultSec' => 0,
            'pitfalls' => [
                'untested: built from the Kostal document and from other projects, not checked on one of our devices',
                'the failsafe time is set by the installer in the inverter web interface and can be neither set nor read over Modbus; rc_failsafe_eff_sec stays 0 (unknown)',
                'one shared timer for the registers 1024 to 1044: every write restarts it, reads do not; write at least every half of the configured time (a project measured 30 s with writes every 15 to 25 s)',
                'after the timeout the inverter returns to its own control; the setpoint registers keep their last value and cannot show it; only 1038 and 1040 read the limit in effect',
                'the inverter ignores every write silently unless register 1080 is 2 (external battery management via Modbus, enabled in the web interface with the installer code)',
                'rc_active only says that external battery management via Modbus is enabled, not that the setpoint is being followed',
                'mode 0 only stops writing: the inverter takes over after its web interface timeout',
                'only one Modbus client may control the inverter (Kostal Smart Energy Meter, evcc, other energy management)',
                'the registers 1280 to 1288 (battery limitation with fallback, G3 from software 03.05) are not used: their fallback would leave the battery permanently limited or protect nothing',
                'the minimum SOC (1042) is not written: a value above the current SOC disconnected the battery in one report and caused a battery fault',
                'a hold (0 W) still lets the inverter own consumption (about 170 to 260 W) come from the battery',
                'in Denmark and in Austria (gradient case) the DC registers 1028 to 1036 cannot be written permanently (Kostal note 7)',
                'only the DC setpoint 1034 is used, not the AC setpoint 1026 of PLENTICORE BI and G3',
                'the float word order follows the byte order selected on the instance',
            ],
        ];
    }

    private function readFloat($mb, int $reg): ?float
    {
        $r = $mb->readHolding($reg, 2);
        if ($r === null || count($r) < 2) {
            return null;
        }
        $v = $mb->readFloat32($r, 0);
        return (is_float($v) || is_int($v)) && is_finite((float)$v) && abs($v) < 3.0e38 ? (float)$v : null;
    }

    private function readMode($mb): ?int
    {
        $r = $mb->readHolding(self::REG_MODE, 1);
        return ($r === null || count($r) < 1) ? null : (int)$mb->u16($r, 0);
    }

    public function capability($mb): ?array
    {
        $mode = $this->readMode($mb);
        if ($mode === null) {
            return null;
        }
        if ($mode !== self::MODE_MODBUS) {
            return ['available' => false, 'version' => 0, 'status' => $mode, 'mode' => $mode];
        }
        return $this->limits($mb, ['available' => true, 'version' => 1, 'status' => $mode, 'mode' => $mode]);
    }

    private function limits($mb, array $cap): array
    {
        $c = $this->readFloat($mb, self::REG_MAX_CHARGE);
        $d = $this->readFloat($mb, self::REG_MAX_DISCHARGE);
        unset($cap['maxCharge'], $cap['maxDischarge']);
        if ($c !== null && $c > 0) {
            $cap['maxCharge'] = $c;
        }
        if ($d !== null && $d > 0) {
            $cap['maxDischarge'] = $d;
        }
        $rated = max($cap['maxCharge'] ?? 0, $cap['maxDischarge'] ?? 0);
        if ($rated > 0) {
            $cap['ratedW'] = (int)round($rated);
        }
        return $cap;
    }

    /** Wortreihenfolge des Clients (die Instanz stellt sie ein) und die Akkugrenzen frisch holen. */
    public function prepare($mb, array $cap): array
    {
        $cap['swap'] = (bool)($mb->floatWordSwap ?? false);
        $mode = $this->readMode($mb);        // vor jedem Schreiben frisch: bei 0 oder 1 ignoriert der Wechselrichter jeden Schreibzugriff still
        if ($mode !== null) {
            $cap['mode'] = $mode;
        }
        return $this->limits($mb, $cap);
    }

    public function capabilityError(?array $cap): string
    {
        if ($cap === null) {
            return IHUB_Text::t('Kostal remote control: the device did not answer register 1080.');
        }
        return $cap['available'] ? '' : IHUB_Text::t('Kostal remote control: external battery management via Modbus is not enabled (register 1080 is %s, it must be 2). Enable it in the inverter web interface with the installer code; otherwise the inverter ignores every write.', (int)($cap['mode'] ?? 0));
    }

    public function validate(array $req, ?array $cap): string
    {
        $mode = (int)$req['mode'];
        if (!in_array($mode, [0, 1, 2], true)) {
            return IHUB_Text::t('Kostal remote control: mode %s is not offered (0 off, 1 hold, 2 battery).', $mode);
        }
        if ($mode !== 0 && isset($cap['mode']) && (int)$cap['mode'] !== self::MODE_MODBUS) {
            return $this->capabilityError(['available' => false, 'mode' => (int)$cap['mode']]);
        }
        return '';
    }

    /** Sollwert fürs Gerät in W: einheitlich + Laden, Gerät negativ = laden; auf die Akkugrenzen begrenzt, soweit sie lesbar sind. */
    public static function devicePower(int $mode, int $watts, ?float $maxCharge, ?float $maxDischarge): float
    {
        if ($mode !== 2) {
            return 0.0;
        }
        $w = (float)-$watts;
        if ($w < 0 && $maxCharge !== null) {
            $w = max($w, -$maxCharge);
        }
        if ($w > 0 && $maxDischarge !== null) {
            $w = min($w, $maxDischarge);
        }
        return (float)round($w);
    }

    public function plan(array $req, array $cap, int $last): array
    {
        $mode = (int)$req['mode'];
        if ($mode === 0) {
            return ['steps' => []];     // nur aufhören zu schreiben: das Gerät übernimmt nach dem Zeitgeber der Weboberfläche
        }
        $w = self::devicePower($mode, (int)($req['watts'] ?? 0), $cap['maxCharge'] ?? null, $cap['maxDischarge'] ?? null);
        return ['steps' => [['fc' => 16, 'addr' => self::REG_SETPOINT, 'words' => \IHUB\NRGModbus\ModbusDecode::packFloat32($w, (bool)($cap['swap'] ?? false)), 'afterUs' => 0]]];
    }

    public function readback($mb, array $req, array $cap): ?array
    {
        $mode = $this->readMode($mb);
        $sp = $this->readFloat($mb, self::REG_SETPOINT);
        if ($mode === null || $sp === null) {
            return null;
        }
        return ['regs' => [$mode, (int)round($sp * 10)], 'status' => $mode, 'sp' => $sp];
    }

    public function verify(array $back, array $req, array $cap): string
    {
        $mode = (int)$req['mode'];
        if ($mode === 0) {
            return '';
        }
        if ($back['status'] !== self::MODE_MODBUS) {
            return IHUB_Text::t('Kostal remote control: register 1080 is %s, external battery management via Modbus is no longer active.', $back['status']);
        }
        $want = self::devicePower($mode, (int)($req['watts'] ?? 0), $cap['maxCharge'] ?? null, $cap['maxDischarge'] ?? null);
        if (abs($back['sp'] - $want) > self::TOLERANCE_W) {
            return IHUB_Text::t('device acknowledged, but stored %s instead of %s (register %s)', (int)round($back['sp']), (int)round($want), self::REG_SETPOINT);
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

    public function isActive(int $status): bool
    {
        return $status === self::MODE_MODBUS;
    }

    /** Die Zeit stellt der Installateur in der Weboberfläche ein und sie ist über Modbus nicht lesbar: 0 = unbekannt. */
    public function effectiveFailsafeSec(array $req): int
    {
        return 0;
    }
}
