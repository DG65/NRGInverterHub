<?php

// ---------------------------------------------------------------------------
// IHUB_RemoteDeye — Deye dreiphasige Hybrid-Wechselrichter, "Remote mode" 1100-1121 (Deye MODBUS-RTU 3P V105.4, 13.08.2024, S. 41 f.)
// als Adapter der gemeinsamen Fernsteuerung. UNGETESTET: nur nach dem Herstellerdokument gebaut, an keinem Gerät geprüft (rc_tested = false).
//
//   1100 Fernmodus (0 aus, 1/2/3 an; V105.1 kennt nur 0/1; ein Gerät ohne die Funktion liefert 0x0500)
//   1101 Watchdog in s [10, 18000], 0xFFFF = AUS (Werkseinstellung!): wird immer gesetzt und zurückgelesen
//   1104 Regelziel (0 AC-seitig, 1 akkuseitig, 2 netzseitig), 1105 Akku-Regelung (2 = Leistung)
//   1109 Akkuleistung in 0,1 % der Nennleistung [−1200, +1200] (NICHT in W; Nennleistung aus Register 20/21, 0,1 W)
// Nur FC03 und FC16 (FC06 ist nicht freigegeben): alles mit FC16.
//
// Vorzeichen: Die Doku nennt es nicht. Wir nehmen die einzige Quelle (PR #978 von ha-solarman, "doc-derived, not hardware-verified"):
// 1109 negativ = Akku laden, positiv = Entladen. Einheitlich + = Laden: Gerät = −Leistung. NICHT am Gerät geprüft.
//
// Bewusst NICHT angeboten:
//  - Modus 3 (Netzanschlusspunkt): die Doku nennt 1104 = 2 (netzseitig), aber nicht, welches Register den Netzsollwert trägt.
//  - Netzladen und SOC-Fenster: 1110 ist ein Ziel-SOC bei Akku-Regelung 3/5, kein Fenster.
// Die Doku widerspricht sich bei 1104: Bereich [0,1], beschrieben sind drei Werte. Schreibreihenfolge (unbelegt): Watchdog, Regelziel
// und -art, Leistung, zuletzt der Fernmodus; das Rücklesen prüft, ob das Gerät die Werte angenommen hat.
// Einphasige Geräte (SG01/03/05LP1): kein Beleg für 1100 ff., Fähigkeit wird über Register 1100 erkannt (0x0500 oder Ausnahme = nicht verfügbar).
// ---------------------------------------------------------------------------
class IHUB_RemoteDeye implements IHUB_RemoteAdapter
{
    const REG_MODE = 1100;
    const REG_WATCHDOG = 1101;
    const REG_TARGET = 1104;
    const REG_BATTERY = 1105;
    const REG_POWER = 1109;
    const REG_RATED_LO = 20;
    const NOT_AVAILABLE = 0x0500;
    const WATCHDOG_DEFAULT_S = 60;
    const WATCHDOG_MIN_S = 10;
    const WATCHDOG_MAX_S = 18000;
    const POWER_LIMIT = 1200;     // 0,1 % Pn

    public static $gapUs = 500000;
    public static $readbackUs = 400000;
    public static $retryPauseUs = 1500000;

    public function info(): array
    {
        return [
            'vendor' => 'Deye', 'source' => 'Deye MODBUS-RTU 3P V105.4 (13.08.2024), registers 1100-1121',
            'tested' => false, 'modes' => [0, 1, 2], 'gridCharge' => false, 'socWindow' => false,
            'failsafeMinSec' => self::WATCHDOG_MIN_S, 'failsafeMaxSec' => self::WATCHDOG_MAX_S, 'failsafeDefaultSec' => self::WATCHDOG_DEFAULT_S,
            'pitfalls' => [
                'untested: built from the manufacturer document only',
                'the watchdog (1101) is off from the factory (0xFFFF): it is always set and read back',
                'setpoints are in 0.1 % of the rated power, not in W',
                'sign of register 1109 is not in the document: negative = charging taken from one unverified source',
                'no grid connection point mode: the document does not name the grid setpoint register',
                'document inconsistent for register 1104 (range 0..1, three values described)',
                'only FC03 and FC16 are released',
                'single-phase devices have no evidence for registers 1100 ff.',
            ],
        ];
    }

    public function capability($mb): ?array
    {
        $r = $mb->readHolding(self::REG_MODE, 1);
        if ($r === null) {
            return null;
        }
        $v = $mb->u16($r, 0);
        if ($v === self::NOT_AVAILABLE || $v > 3) {
            return ['available' => false, 'version' => 0, 'status' => 0];
        }
        $rated = 0;
        $p = $mb->readHolding(self::REG_RATED_LO, 2);   // Nennleistung, 0,1 W, niederwertiges Wort zuerst
        if ($p !== null) {
            $rated = (int)round((($mb->u16($p, 1) << 16) | $mb->u16($p, 0)) / 10);
        }
        return ['available' => true, 'version' => 1, 'status' => $v, 'ratedW' => $rated];
    }

    public function capabilityError(?array $cap): string
    {
        if ($cap === null) {
            return IHUB_Text::t('Deye remote control: the device did not answer register 1100.');
        }
        return $cap['available'] ? '' : IHUB_Text::t('Deye remote control: this device does not offer remote mode (register 1100 reads 0x0500 or is not valid).');
    }

    public function validate(array $req, ?array $cap): string
    {
        $mode = (int)$req['mode'];
        if (!in_array($mode, [0, 1, 2], true)) {
            return IHUB_Text::t('Deye remote control: mode %s is not offered (0 off, 1 hold, 2 battery).', $mode);
        }
        if ($mode === 2 && (int)($cap['ratedW'] ?? 0) <= 0) {
            return IHUB_Text::t('Deye remote control: the rated power (registers 20 and 21) is unknown, the power cannot be converted to 0.1 % of it.');
        }
        return '';
    }

    private function watchdog(array $req): int
    {
        $s = (int)($req['failsafeSec'] ?? 0);
        return $s <= 0 ? self::WATCHDOG_DEFAULT_S : max(self::WATCHDOG_MIN_S, min(self::WATCHDOG_MAX_S, $s));
    }

    /** Leistung für 1109: 0,1 % der Nennleistung, einheitlich + Laden -> Gerät negativ, als 16 Bit (Zweierkomplement). */
    public static function devicePower(int $mode, int $watts, int $ratedW): int
    {
        if ($mode !== 2 || $ratedW <= 0) {
            return 0;
        }
        $v = (int)round(-$watts / $ratedW * 1000);
        $v = max(-self::POWER_LIMIT, min(self::POWER_LIMIT, $v));
        return $v & 0xFFFF;
    }

    public function plan(array $req, array $cap, int $last): array
    {
        $mode = (int)$req['mode'];
        if ($mode === 0) {
            return ['steps' => [['fc' => 16, 'addr' => self::REG_MODE, 'words' => [0], 'afterUs' => 0]]];
        }
        $p = self::devicePower($mode, (int)($req['watts'] ?? 0), (int)($cap['ratedW'] ?? 0));
        return ['steps' => [
            ['fc' => 16, 'addr' => self::REG_WATCHDOG, 'words' => [$this->watchdog($req)], 'afterUs' => self::$gapUs],
            ['fc' => 16, 'addr' => self::REG_TARGET, 'words' => [1, 2], 'afterUs' => self::$gapUs],
            ['fc' => 16, 'addr' => self::REG_POWER, 'words' => [$p], 'afterUs' => self::$gapUs],
            ['fc' => 16, 'addr' => self::REG_MODE, 'words' => [1], 'afterUs' => 0],
        ]];
    }

    public function readback($mb, array $req, array $cap): ?array
    {
        $r = $mb->readHolding(self::REG_MODE, 11);   // 1100-1110
        if ($r === null || count($r) < 11) {
            return null;
        }
        $r = array_values($r);
        return ['regs' => $r, 'status' => $r[0]];
    }

    public function verify(array $back, array $req, array $cap): string
    {
        $r = $back['regs'];
        $mode = (int)$req['mode'];
        $msg = 'device acknowledged, but stored %s instead of %s (register %s)';
        if ($mode === 0) {
            return $r[0] !== 0 ? IHUB_Text::t($msg, $r[0], 0, self::REG_MODE) : '';
        }
        $checks = [
            [self::REG_WATCHDOG, $r[1], $this->watchdog($req)],
            [self::REG_TARGET, $r[4], 1],
            [self::REG_BATTERY, $r[5], 2],
            [self::REG_POWER, $r[9], self::devicePower($mode, (int)($req['watts'] ?? 0), (int)($cap['ratedW'] ?? 0))],
        ];
        foreach ($checks as [$reg, $got, $want]) {
            if ($got !== $want) {
                return IHUB_Text::t($msg, $got, $want, $reg);
            }
        }
        if ($r[0] < 1 || $r[0] > 3) {
            return IHUB_Text::t($msg, $r[0], 1, self::REG_MODE);
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
        return $status >= 1 && $status <= 3;
    }

    public function effectiveFailsafeSec(array $req): int
    {
        return $this->watchdog($req);
    }
}
