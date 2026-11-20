<?php

// ---------------------------------------------------------------------------
// IHUB_RemoteVictron — Victron GX (Cerbo/Venus OS) mit ESS, Netzsollwert-Override "Weg B" (Register 2716/2717, Unit 100) als Adapter der
// gemeinsamen Fernsteuerung (IHUB_RemoteControl). Modi 0 (aus), 1 (halten), 2 (Akku ±W) und 3 (Netzanschlusspunkt ±W); 1 und 2 sind eine Näherung über einen Regelkreis. Beleg-Auswertung:
// Hersteller-Doku/Recherche-Victron-Fernsteuerung-Runde3.md (Victron Register-Liste 3.81, ESS-Wiki, Community, evcc-Issue 35471). UNGETESTET.
//
//   2716/2717 int32 W (hohes Wort zuerst), `hub4 /Overrides/Setpoint`, flüchtig ("volatile, in memory"), seit Venus OS 3.50: Netzsollwert-Override,
//             + = Netzbezug, − = Einspeisung. Das GX regelt den Netzzähler auf diesen Wert, die Batterie deckt den Rest im Rahmen ihrer Grenzen.
//   2902 U16  ESS-Modus (Unit 100, Settings): 1 mit Phasenkompensation, 2 ohne, 3 externe Steuerung (dann gilt der Override nicht, Weg A)
//   5400 U16  Dynamic ESS aktiv (der Override wird dann verworfen)
// Einheitlich + Einspeisung (Modus 3): Gerät = −Leistung. 0 W ist ein ECHTER Override (Netzbezug 0 W), kein Freigeben: Modbus kennt kein "null"
// (Runde 4, Quellcode dbus_modbustcp: ein ungültiger D-Bus-Wert wird als 0 geliefert, Schreiben setzt immer eine Zahl). Lesen von 2716 kann
// "kein Override" und "Override 0" deshalb nicht unterscheiden.
// Modus 0: Es gibt per Modbus keine Freigabe. Der Adapter schreibt den Wert des Netzsollwerts 2700 (Spiegelung, einmalig und nur wenn der Override davon
// abweicht), damit das System sofort wie ohne EMS regelt; danach schweigt er, und hub4control nimmt den Override nach seinem Zeitgeber zurück.
//
// Totmann (belegt in Runde 4): hub4control hat einen eigenen, ABSICHTLICHEN Zeitgeber für die Overrides ("hub4control has its own timeout built
// in", Victron, Commit 879719a; "5 minute timeout is intentional", Victron 11.08.2026, home-assistant/core#178109); nach einem Neustart sind alle
// Overrides weg. Gemessen: etwa 300 s (evcc-Issue 35471, Register 2716) und etwa 180 s (SEUSS, Venus 3.80); die Dauer ist undokumentiert und die
// Messungen widersprechen sich. Rückfall ist Victrons eigene Regelung (Netzsollwert 2700), NICHT Passthru. Victrons eigene Schreiber (venus-dynamicess)
// frischen alle 5 s auf, auch mit unverändertem Wert; ob ein Neuschreiben mit gleichem Wert den Zeitgeber per Modbus neu startet, ist unbelegt.
// rc_failsafe_eff_sec meldet deshalb 180 s (die kleinere Messung); das EMS schreibt in jedem Zyklus (alle 5 bis 10 s, höchstens 30 s).
//
// Modus 1 (Halten) und 2 (Akku ±W) gibt es per Modbus nicht als Register (die Overrides ForceCharge, MaxChargePower, MaxDischargePower, FeedInExcess, mit denen
// Victrons Dynamic ESS das macht, haben kein Modbus-Register). Der Adapter bildet sie deshalb über einen Regelkreis auf den Netzsollwert 2716 ab, UNGETESTET
// und nur als Näherung: Zielleistung des Akkus T (+ = Laden, Halten = 0). Bilanz am Netzzähler: Bezug = Last + Akkuladeleistung − PV. Daraus
//   N_vor = T + Last − PV_gesamt            (Vorsteuerung, begrenzt, kein Aufschaukeln; PV_gesamt = AC-PV 808..816 + DC-PV 850; Last 817..819)
//   N_rueck = Netz + (T − P_Akku)           (Rückführung über das gemessene Netz 820..822 und die Akkuleistung 842)
//   N = N_rueck, begrenzt auf N_vor ± BAND  (Anti-Windup: folgt der Akku nicht, etwa bei Mindest-SOC, BMS-Grenze oder Sustain, bleibt N in der Nähe von N_vor)
// Verluste (Wandler, Akku) und der Sollwert-Einschwingvorgang (30 bis 60 s) sind nicht modelliert; die Akkuleistung folgt nur im Rahmen der Grenzen.
//
// NICHT gebaut (Gründe im Changelog): Weg A (ESS-Modus 3, VE.Bus-Register 37/38/39, offizieller Totmann 60 s, aber Rückfall in PASSTHRU = Akku
// ungenutzt, braucht eine VE.Bus-Unit-ID als neue Eigenschaft und das Umstellen des ESS-Modus im Flash) und Weg C (Multi RS, widersprüchliche
// Forenberichte). Ein Modus "Halten" oder "Akku ±W" gibt es bei Victron per Modbus nicht: die Overrides ForceCharge, MaxChargePower, MaxDischargePower
// und FeedInExcess, mit denen Victrons Dynamic ESS selbst Halten, Laden und Entladen macht, haben KEIN Modbus-Register (nur D-Bus und MQTT, Registerliste Rev 55).
// ---------------------------------------------------------------------------
class IHUB_RemoteVictron implements IHUB_RemoteAdapter, IHUB_RemotePreparing
{
    const REG_OVERRIDE = 2716;
    const REG_GRID_SETPOINT = 2700;     // Netzsollwert des Nutzers (int16, Settings/Flash): wird nie geschrieben, nur gelesen
    const REG_HUB4_MODE = 2902;
    const REG_DESS_ACTIVE = 5400;
    const UNIT = 100;
    const POWER_LIMIT_W = 40000;
    const FAILSAFE_S = 180;             // gemessen 180 bis 300 s, undokumentiert
    const BAND_W = 800;                 // Anti-Windup: Rückführung höchstens so weit von der Vorsteuerung weg
    const REG_FLOW = 808;               // 808..826: PV (808..816), Verbrauch (817..819), Netz (820..822)
    const REG_BATTERY_POWER = 842;      // int16, + = Laden
    const REG_PV_DC = 850;              // uint16

    public static $readbackUs = 1500000;
    public static $retryPauseUs = 2000000;

    public function info(): array
    {
        return [
            'vendor' => 'Victron', 'source' => 'Victron Modbus-TCP register list 3.81, ESS wiki, hub4 setpoint override 2716 (Venus OS 3.50 or newer)',
            'tested' => false, 'modes' => [0, 1, 2, 3], 'gridCharge' => false, 'socWindow' => false,
            'failsafeMinSec' => 0, 'failsafeMaxSec' => 0, 'failsafeDefaultSec' => 0,
            'pitfalls' => [
                'untested: built from the Victron register list, the Victron source code and community reports, not checked on one of our devices',
                'the override has a deliberate timer in hub4control (Victron: intentional, after a restart all overrides are gone); measured about 180 to 300 s, the duration is undocumented, rc_failsafe_eff_sec reports 180; afterwards Victron own control (grid setpoint 2700) applies, not passthru',
                'it is unknown whether writing the same value again restarts the timer over Modbus; Victron own writers refresh every 5 s: the energy management must write every 5 to 10 s',
                'writing 0 does NOT release the override: over Modbus 0 is a real override of 0 W grid import and "no override" reads as 0 too',
                'mode 0 therefore writes the user grid setpoint 2700 once (mirroring) and then stays silent; hub4control then drops the override after its timer',
                'Dynamic ESS (modes 1 and 4, register 5400) overwrites all overrides every 5 s: nothing is written then',
                'it needs ESS mode 1 or 2 (register 2902) and a grid meter or "run without grid meter"; with external control (mode 3) the override does not apply',
                'on a newly set up GX, Modbus TCP is read-only by default (Venus OS 3.90 beta resets it to read-only at every switch-on): without write permission every write ends with an exception',
                'hold (mode 1) and battery power (mode 2) have no Modbus register: they are an approximation by a control loop on 2716 (feed-forward from load and PV, feedback from grid and battery power, limited to a band of 800 W around the feed-forward), untested, losses and the settling time of 30 to 60 s are not modelled',
                'the control loop needs the measured values 808 to 822, 842 and 850; the battery only follows within its limits (minimum SOC, BMS, Sustain): then the setpoint stays near the feed-forward value instead of running away',
                'ForceCharge, MaxChargePower, MaxDischargePower and FeedInExcess (used by Victron Dynamic ESS) have no Modbus register, only D-Bus and MQTT; an MQTT access would give a proven hold and battery power',
                'not built: the ESS mode 3 path (registers 37, 38, 39 of the VE.Bus service) with its failsafe (60 s in the wiki, about 10 s in a 2026 report) and fall back to passthru (battery unused), needs the ESS mode in flash and a VE.Bus unit ID; and the Multi RS path',
                'the unit of the system and settings service is 100; the battery limits of a managed battery (BMS, DVCC) always apply; settling takes 30 to 60 s, the ramp is limited to 400 W/s',
                'the setpoint is a total over all phases; ESS with phase compensation (2902 = 1) works on the sum; the power is rounded to 10 W; Sustain (battery nearly empty) overrides every setpoint',
            ],
        ];
    }

    /** int32 (hohes Wort zuerst) in zwei Worte. */
    public static function words(int $v): array
    {
        $u = $v < 0 ? $v + 4294967296 : $v;
        return [($u >> 16) & 0xFFFF, $u & 0xFFFF];
    }

    private function unit($mb, callable $fn)
    {
        $keep = $mb->unitId;
        $mb->unitId = self::UNIT;
        try {
            return $fn();
        } finally {
            $mb->unitId = $keep;
        }
    }

    private function readS32($mb, int $reg): ?int
    {
        $r = $mb->readHolding($reg, 2);
        return ($r === null || count($r) < 2) ? null : (int)$mb->s32($r, 0);
    }

    private function readU16($mb, int $reg): ?int
    {
        $r = $mb->readHolding($reg, 1);
        return ($r === null || count($r) < 1) ? null : (int)$mb->u16($r, 0);
    }

    public function capability($mb): ?array
    {
        return $this->unit($mb, function () use ($mb) {
            $ov = $this->readS32($mb, self::REG_OVERRIDE);
            if ($ov === null) {
                return null;
            }
            return $this->extras($mb, ['available' => true, 'version' => 1, 'status' => $ov, 'override' => $ov]);
        });
    }

    private function extras($mb, array $cap): array
    {
        $dess = $this->readU16($mb, self::REG_DESS_ACTIVE);       // fehlt bei älteren Versionen: dann unbekannt
        $hub4 = $this->readU16($mb, self::REG_HUB4_MODE);          // fehlt bei manchen GX (kein Settings-Dienst)
        unset($cap['dess'], $cap['hub4']);
        if ($dess !== null) {
            $cap['dess'] = $dess;
        }
        if ($hub4 !== null) {
            $cap['hub4'] = $hub4;
        }
        return $cap;
    }

    public function prepare($mb, array $cap): array
    {
        return $this->unit($mb, function () use ($mb, $cap) {
            $ov = $this->readS32($mb, self::REG_OVERRIDE);
            if ($ov !== null) {
                $cap['override'] = $ov;
            } else {
                unset($cap['override']);
            }
            $fl = $mb->readHolding(self::REG_FLOW, 15);          // PV 808..816, Verbrauch 817..819, Netz 820..822
            $bp = $mb->readHolding(self::REG_BATTERY_POWER, 1);
            $pd = $mb->readHolding(self::REG_PV_DC, 1);
            unset($cap['flow']);
            if ($fl !== null && count($fl) >= 15 && $bp !== null && $pd !== null) {
                $pvAc = 0;
                for ($o = 0; $o <= 8; $o++) {
                    $pvAc += (int)$mb->u16($fl, $o);
                }
                $cap['flow'] = [
                    'pv' => $pvAc + (int)$mb->u16($pd, 0), 'load' => (int)$mb->u16($fl, 9) + (int)$mb->u16($fl, 10) + (int)$mb->u16($fl, 11),
                    'grid' => (int)$mb->s16($fl, 12) + (int)$mb->s16($fl, 13) + (int)$mb->s16($fl, 14), 'bat' => (int)$mb->s16($bp, 0),
                ];
            }
            $gs = $mb->readHolding(self::REG_GRID_SETPOINT, 1);
            unset($cap['grid']);
            if ($gs !== null && count($gs) >= 1) {
                $cap['grid'] = (int)$mb->s16($gs, 0);
            }
            return $this->extras($mb, $cap);
        });
    }

    public function capabilityError(?array $cap): string
    {
        return $cap === null ? IHUB_Text::t('Victron remote control: the device did not answer register 2716 (setpoint override). It needs Venus OS 3.50 or newer and the system service on unit 100.') : '';
    }

    public function validate(array $req, ?array $cap): string
    {
        $mode = (int)$req['mode'];
        if (!in_array($mode, [0, 1, 2, 3], true)) {
            return IHUB_Text::t('Victron remote control: mode %s is not offered (0 off, 1 hold, 2 battery, 3 grid connection point).', $mode);
        }
        if (in_array($mode, [1, 2], true) && !isset($cap['flow'])) {
            return IHUB_Text::t('Victron remote control: the measured values for the control loop (registers 808 to 822, 842 and 850) could not be read, hold and battery power are not possible.');
        }
        if ($mode !== 0 && (int)($cap['dess'] ?? 0) === 1) {
            return IHUB_Text::t('Victron remote control: Dynamic ESS is active (register 5400), it discards the setpoint override. Switch Dynamic ESS off.');
        }
        if ($mode !== 0 && isset($cap['hub4']) && (int)$cap['hub4'] === 3) {
            return IHUB_Text::t('Victron remote control: the ESS mode is external control (register 2902 = 3): the setpoint override does not apply there. Select ESS mode 1 or 2.');
        }
        return '';
    }

    /** Override fürs Gerät (Modus 3): einheitlich + Einspeisung, Gerät + = Bezug; auf 10 W gerundet. 0 W ist ein echter Override (kein Freigeben). */
    public static function devicePower(int $mode, int $watts): int
    {
        if ($mode !== 3) {
            return 0;
        }
        return self::clampRound(-$watts);
    }

    private static function clampRound(float $w): int
    {
        return (int)(round(max(-self::POWER_LIMIT_W, min(self::POWER_LIMIT_W, $w)) / 10) * 10);
    }

    /**
     * Regelkreis für Halten und Akku ±W: Zielleistung des Akkus T (+ = Laden) auf den Netzsollwert (+ = Bezug).
     * Vorsteuerung aus der Bilanz, Rückführung über Netz und Akkuleistung, begrenzt auf die Vorsteuerung ± BAND_W (Anti-Windup).
     */
    public static function loopSetpoint(int $target, array $flow): int
    {
        $ff = $target + $flow['load'] - $flow['pv'];
        $fb = $flow['grid'] + ($target - $flow['bat']);
        return self::clampRound(max($ff - self::BAND_W, min($ff + self::BAND_W, $fb)));
    }

    /** Sollwert von 2716 für diese Anfrage (Modus 1 und 2 über den Regelkreis, Modus 3 direkt). */
    private function wanted(array $req, array $cap): int
    {
        $mode = (int)$req['mode'];
        if ($mode === 3) {
            return self::devicePower(3, (int)($req['watts'] ?? 0));
        }
        return self::loopSetpoint($mode === 2 ? (int)($req['watts'] ?? 0) : 0, $cap['flow']);
    }

    public function plan(array $req, array $cap, int $last): array
    {
        $mode = (int)$req['mode'];
        if ($mode === 0) {
            // keine Freigabe per Modbus: den Netzsollwert 2700 spiegeln, einmalig, dann schweigen
            if (!isset($cap['grid']) || ($cap['override'] ?? null) === $cap['grid']) {
                return ['steps' => []];
            }
            return ['steps' => [['fc' => 16, 'addr' => self::REG_OVERRIDE, 'words' => self::words((int)$cap['grid']), 'afterUs' => 0]]];
        }
        return ['steps' => [['fc' => 16, 'addr' => self::REG_OVERRIDE, 'words' => self::words($this->wanted($req, $cap)), 'afterUs' => 0]]];
    }

    public function readback($mb, array $req, array $cap): ?array
    {
        return $this->unit($mb, function () use ($mb) {
            $ov = $this->readS32($mb, self::REG_OVERRIDE);
            return $ov === null ? null : ['regs' => [$ov], 'status' => $ov, 'override' => $ov];
        });
    }

    public function verify(array $back, array $req, array $cap): string
    {
        $mode = (int)$req['mode'];
        if ($mode === 0 && !isset($cap['grid'])) {
            return '';
        }
        $want = $mode === 0 ? (int)$cap['grid'] : $this->wanted($req, $cap);
        if (abs($back['override'] - $want) > 1) {
            return IHUB_Text::t('device acknowledged, but stored %s instead of %s (register %s)', $back['override'], $want, self::REG_OVERRIDE);
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

    /** Ein Override (≠ 0) ist gesetzt; 0 ist per Modbus nicht von "kein Override" zu unterscheiden. */
    public function isActive(int $status): bool
    {
        return $status !== 0;
    }

    /** Zeitgeber von hub4control (absichtlich, undokumentierte Dauer): 180 s sind die kleinere Messung; Modus 0 hat keinen. */
    public function effectiveFailsafeSec(array $req): int
    {
        return (int)$req['mode'] !== 0 ? self::FAILSAFE_S : 0;
    }
}
