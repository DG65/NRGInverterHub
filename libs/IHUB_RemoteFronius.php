<?php

// ---------------------------------------------------------------------------
// IHUB_RemoteFronius — Fronius GEN24 / Tauro mit Speicher, SunSpec "Basic Storage Control" (Model 124), als Adapter der gemeinsamen
// Fernsteuerung (IHUB_RemoteControl). Quelle: Fronius GEN24 Modbus TCP/RTU 42,0410,2649 (28.09.2026, S. 34-37, 73 f., 89-93) und die
// Projekte evcc, nilseckert/gen24_battery_control (Symo GEN24 10.0, FW 1.41.11-1) und knackerbrot/fronius-modbus-control (FW 1.39.5-1).
// UNGETESTET: an keinem Gerät von uns geprüft (rc_tested = false).
//
// Die Modellfolge wird ab 40002 durchlaufen (die Adressen ändern sich je Firmware und Einstellung int+SF/float); Model 124 hat die Länge 24.
// Lage der Nutzdaten (Index ab dem ersten Datenregister, SunSpec-Offset minus 2):
//   0 WChaMax   3 StorCtl_Mod   10 OutWRte   11 InWRte   12 InOutWRte_WinTms   13 InOutWRte_RvrtTms   14 RmpTms   15 ChaGriSet
//   16 WChaMax_SF   23 InOutWRte_SF
// Die Grenzen sind Prozent von WChaMax mit Skalierung InOutWRte_SF (immer gelesen, ändert sich mit der Firmware):
//   Fenster = [-InWRte, +OutWRte] * WChaMax, negativ = laden, positiv = entladen; StorCtl_Mod Bit 0 = Ladegrenze, Bit 1 = Entladegrenze aktiv.
//   Halten:           In = 0, Out = 0, Mod = 3
//   Laden mit C:      In = +C, Out = -C, Mod = 3   (Doku Beispiel 5)
//   Entladen mit D:   In = -D, Out = +D, Mod = 3   (Doku Beispiel 6)
//   Aus (Automatik):  Mod = 0 und beide auf 100 % (wie evcc, damit andere Anwendungen nichts Fremdes vorfinden)
// Die Fenster, die das Fronius-Dokument bei den Beispielen 5 bis 7 ausdruckt, tragen das falsche Vorzeichen; die Einstellungen sind
// widerspruchsfrei und werden hier verwendet.
//
// Totmann: InOutWRte_RvrtTms (0-28800 s, 0 = ab Werk kein Rückfall). Mit jeder empfangenen Modbus-Nachricht startet der Zeitgeber neu; läuft er
// ab, endet die Betriebsart und das Gerät fällt auf die nächste Priorität zurück (Fronius S. 73). Zwei unabhängige Projekte haben das am Gerät
// beobachtet (Rückfall in den Automatikbetrieb). Offen: ob schon ein LESENDER Zugriff den Zeitgeber neu startet.
// Der Adapter schreibt den Zeitgeber nur, wenn er abweicht; die Grenzen IMMER in einem Block (Lade- und Entladegrenze nie getrennt), zuletzt
// StorCtl_Mod nur bei Abweichung.
//
// Bewusst NICHT geschrieben: ChaGriSet (ein evcc-Beitragender berichtet, dass es die Laderegeln der Weboberfläche außer Kraft setzte) und
// MinRsvPct. Kein Modus 3 (Netzanschlusspunkt): Model 124 kennt keinen Netzsollwert.
// ---------------------------------------------------------------------------
class IHUB_RemoteFronius implements IHUB_RemoteAdapter, IHUB_RemotePreparing
{
    const MODEL_ID = 124;
    const MODEL_LEN = 24;
    const IDX_WCHAMAX = 0;
    const IDX_MOD = 3;
    const IDX_OUT = 10;
    const IDX_IN = 11;
    const IDX_RVRT = 13;
    const IDX_WCHAMAX_SF = 16;
    const IDX_RATE_SF = 23;
    const MOD_BOTH = 3;
    const TIMEOUT_DEFAULT_S = 120;
    const TIMEOUT_MIN_S = 10;
    const TIMEOUT_MAX_S = 28800;
    const CHAIN_START = 40002;
    const CHAIN_MAX = 40;

    public static $gapUs = 300000;           // Anfragen nur nacheinander, im Millisekundentakt wird Fronius langsam (Doku S. 68)
    public static $readbackUs = 600000;
    public static $retryPauseUs = 1500000;

    public function info(): array
    {
        return [
            'vendor' => 'Fronius', 'source' => 'Fronius GEN24 Modbus TCP/RTU 42,0410,2649 (28.09.2026), SunSpec Model 124',
            'tested' => false, 'modes' => [0, 1, 2], 'gridCharge' => false, 'socWindow' => false,
            'failsafeMinSec' => self::TIMEOUT_MIN_S, 'failsafeMaxSec' => self::TIMEOUT_MAX_S, 'failsafeDefaultSec' => self::TIMEOUT_DEFAULT_S,
            'pitfalls' => [
                'untested: built from the Fronius document and from other projects, not checked on one of our devices',
                'the failsafe time InOutWRte_RvrtTms is 0 from the factory (no fallback): it is always set and read back',
                'open: it is unknown whether a read access already restarts the timer; then the failsafe only covers a failure of the whole instance, not of the energy management',
                'the web interface setting "Inverter control via Modbus" must be on; otherwise every write ends with an exception',
                'the charge and discharge limit are always written together in one block, never as separate steps',
                'the printed windows of the Fronius examples 5 to 7 have the wrong sign: the settings of the examples are used',
                'one project reports that on firmware 1.41.11-1 mode 3 (both limits) rejects a negative discharge limit with exception 3: then only hold and off work',
                'an evcc contributor reports that Fronius named the timing of the revert-time commands as the cause of a voltage error, and evcc removed them; other projects use them',
                'ChaGriSet and MinRsvPct are not written (ChaGriSet overrode the charging rules of the web interface in one report)',
                'the inverter may deviate from the limits for safety; Solar.web then shows forced recharge or energy saving mode',
                'control priority (I/O control, dynamic power reduction, Modbus): the commands may not be accepted; the Energy Cost Assistant must not be active',
                'the inverter keeps its own self-consumption controller (it cannot be switched off) and may swing against the limits; the limits are recommendations, not exact setpoints',
                'a report from the OpenEMS community: when changing between charging and discharging the inverter stopped accepting setpoints for a while (unconfirmed by Fronius); do not flip the direction every few seconds',
                'only a few simultaneous Modbus connections: other clients writing the same registers (evcc, scripts) must be switched off',
            ],
        ];
    }

    /** Model 124 in der SunSpec-Kette suchen. null = keine Antwort, 0 = nicht vorhanden, sonst Adresse des ersten Datenregisters. */
    private function locate($mb): ?int
    {
        $addr = self::CHAIN_START;
        for ($i = 0; $i < self::CHAIN_MAX; $i++) {
            $hdr = $mb->readHolding($addr, 2);
            if ($hdr === null || count($hdr) < 2) {
                return $i === 0 ? null : 0;
            }
            $id = $mb->u16($hdr, 0);
            $len = $mb->u16($hdr, 1);
            if ($id === 0xFFFF) {
                return 0;
            }
            if ($id === self::MODEL_ID) {
                return $addr + 2;
            }
            $addr += 2 + $len;
        }
        return 0;
    }

    /** Nutzdaten lesen und auswerten: ['sf' => Skalierung der Grenzen, 'ratedW' => WChaMax in W, 'cfg' => [mod,out,in,rvrt]]. */
    private function readModel($mb, int $base): ?array
    {
        $r = $mb->readHolding($base, self::MODEL_LEN);
        if ($r === null || count($r) < self::MODEL_LEN) {
            return null;
        }
        $r = array_values($r);
        $wsf = $mb->s16($r, self::IDX_WCHAMAX_SF);
        $rsf = $mb->s16($r, self::IDX_RATE_SF);
        if (abs($wsf) > 10 || abs($rsf) > 10) {
            return null;        // Skalierungsfaktor unplausibel (SunSpec: -10..10): das Modell wird nicht benutzt
        }
        return [
            'sf' => $rsf,
            'ratedW' => (int)round($mb->u16($r, self::IDX_WCHAMAX) * pow(10, $wsf)),
            'cfg' => ['mod' => $mb->u16($r, self::IDX_MOD), 'out' => $mb->s16($r, self::IDX_OUT), 'in' => $mb->s16($r, self::IDX_IN), 'rvrt' => $mb->u16($r, self::IDX_RVRT)],
        ];
    }

    public function capability($mb): ?array
    {
        $base = $this->locate($mb);
        if ($base === null) {
            return null;
        }
        if ($base === 0) {
            return ['available' => false, 'version' => 0, 'status' => 0];
        }
        $m = $this->readModel($mb, $base);
        if ($m === null) {
            return null;
        }
        if ($m['ratedW'] <= 0) {
            return ['available' => false, 'version' => 0, 'status' => 0];   // WChaMax 0 = kein Energiespeicher
        }
        return ['available' => true, 'version' => 1, 'status' => $m['cfg']['mod'], 'ratedW' => $m['ratedW'], 'base' => $base, 'sf' => $m['sf'], 'cfg' => $m['cfg']];
    }

    /** Aktuelle Werte und Adresse (nach der letzten Fähigkeit nicht mehr bekannt) frisch lesen; nur Abweichendes wird geschrieben. */
    public function prepare($mb, array $cap): array
    {
        $base = $cap['base'] ?? $this->locate($mb);
        if (!$base) {
            return $cap;
        }
        $m = $this->readModel($mb, $base);
        if ($m === null) {
            return $cap;
        }
        $cap['base'] = $base;
        $cap['sf'] = $m['sf'];
        $cap['cfg'] = $m['cfg'];
        $cap['ratedW'] = $m['ratedW'];      // auch 0: kein Energiespeicher (validate lehnt dann ab)
        return $cap;
    }

    public function capabilityError(?array $cap): string
    {
        if ($cap === null) {
            return IHUB_Text::t('Fronius remote control: the device did not answer the SunSpec model chain.');
        }
        return $cap['available'] ? '' : IHUB_Text::t('Fronius remote control: no SunSpec model 124 with a battery was found (no storage connected, or the model is not offered).');
    }

    public function validate(array $req, ?array $cap): string
    {
        $mode = (int)$req['mode'];
        if (!in_array($mode, [0, 1, 2], true)) {
            return IHUB_Text::t('Fronius remote control: mode %s is not offered (0 off, 1 hold, 2 battery).', $mode);
        }
        if (!isset($cap['base'], $cap['sf'])) {
            return IHUB_Text::t('Fronius remote control: model 124 could not be read, the limits cannot be written.');
        }
        if ((int)($cap['ratedW'] ?? 0) <= 0) {
            return IHUB_Text::t('Fronius remote control: WChaMax is 0, there is no battery to control.');
        }
        return '';
    }

    private function timeout(array $req): int
    {
        $s = (int)($req['failsafeSec'] ?? 0);
        return $s <= 0 ? self::TIMEOUT_DEFAULT_S : max(self::TIMEOUT_MIN_S, min(self::TIMEOUT_MAX_S, $s));
    }

    /** Prozent -> Registerwert mit der Skalierung des Geräts (SF -2: 100 % = 10000), auf ±100 % begrenzt. */
    public static function raw(float $pct, int $sf): int
    {
        $pct = max(-100.0, min(100.0, $pct));
        return (int)round($pct * pow(10, -$sf));
    }

    /** Grenzen [Entladegrenze OutWRte, Ladegrenze InWRte] als Registerwerte; einheitlich + Laden. */
    public static function limits(int $mode, int $watts, int $ratedW, int $sf): array
    {
        if ($mode === 0) {
            return [self::raw(100, $sf), self::raw(100, $sf)];
        }
        if ($mode === 1 || $ratedW <= 0 || $watts === 0) {
            return [0, 0];
        }
        $pct = abs($watts) / $ratedW * 100;
        return $watts > 0 ? [self::raw(-$pct, $sf), self::raw($pct, $sf)] : [self::raw($pct, $sf), self::raw(-$pct, $sf)];
    }

    private static function w16(int $v): int
    {
        return $v & 0xFFFF;
    }

    public function plan(array $req, array $cap, int $last): array
    {
        $mode = (int)$req['mode'];
        $base = (int)$cap['base'];
        $sf = (int)$cap['sf'];
        $have = $cap['cfg'] ?? [];
        [$out, $in] = self::limits($mode, (int)($req['watts'] ?? 0), (int)($cap['ratedW'] ?? 0), $sf);
        $steps = [];
        if ($mode === 0) {
            if (($have['mod'] ?? null) === 0 && ($have['out'] ?? null) === $out && ($have['in'] ?? null) === $in) {
                return ['steps' => []];     // Automatik schon hergestellt
            }
            $steps[] = ['fc' => 16, 'addr' => $base + self::IDX_OUT, 'words' => [self::w16($out), self::w16($in)], 'afterUs' => self::$gapUs];
            if (($have['mod'] ?? null) !== 0) {
                $steps[] = ['fc' => 16, 'addr' => $base + self::IDX_MOD, 'words' => [0], 'afterUs' => 0];
            }
            return ['steps' => $steps];
        }
        $rvrt = $this->timeout($req);
        if (($have['rvrt'] ?? null) !== $rvrt) {
            $steps[] = ['fc' => 16, 'addr' => $base + self::IDX_RVRT, 'words' => [$rvrt], 'afterUs' => self::$gapUs];
        }
        // beide Grenzen in EINEM Block und in jedem Zyklus (hält den Zeitgeber wach)
        $steps[] = ['fc' => 16, 'addr' => $base + self::IDX_OUT, 'words' => [self::w16($out), self::w16($in)], 'afterUs' => ($have['mod'] ?? null) === self::MOD_BOTH ? 0 : self::$gapUs];
        if (($have['mod'] ?? null) !== self::MOD_BOTH) {
            $steps[] = ['fc' => 16, 'addr' => $base + self::IDX_MOD, 'words' => [self::MOD_BOTH], 'afterUs' => 0];
        }
        return ['steps' => $steps];
    }

    public function readback($mb, array $req, array $cap): ?array
    {
        $m = $this->readModel($mb, (int)$cap['base']);
        if ($m === null) {
            return null;
        }
        $c = $m['cfg'];
        return ['regs' => [$c['mod'], $c['out'], $c['in'], $c['rvrt']], 'status' => $c['mod'], 'cfg' => $c];
    }

    public function verify(array $back, array $req, array $cap): string
    {
        $mode = (int)$req['mode'];
        $c = $back['cfg'];
        [$out, $in] = self::limits($mode, (int)($req['watts'] ?? 0), (int)($cap['ratedW'] ?? 0), (int)$cap['sf']);
        $checks = [
            [(int)$cap['base'] + self::IDX_MOD, $c['mod'], $mode === 0 ? 0 : self::MOD_BOTH],
            [(int)$cap['base'] + self::IDX_OUT, $c['out'], $out],
            [(int)$cap['base'] + self::IDX_IN, $c['in'], $in],
        ];
        if ($mode !== 0) {
            $checks[] = [(int)$cap['base'] + self::IDX_RVRT, $c['rvrt'], $this->timeout($req)];
        }
        foreach ($checks as [$reg, $got, $want]) {
            if ($got !== $want) {
                return IHUB_Text::t('device acknowledged, but stored %s instead of %s (register %s)', $got, $want, $reg);
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

    public function isActive(int $status): bool
    {
        return ($status & 3) !== 0;
    }

    public function effectiveFailsafeSec(array $req): int
    {
        return $this->timeout($req);
    }
}
