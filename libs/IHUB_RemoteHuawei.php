<?php

// ---------------------------------------------------------------------------
// IHUB_RemoteHuawei — Huawei SUN2000 mit LUNA2000 (Modbus TCP) über das "Zwangsladen/-entladen" (forcible charge/discharge) als Adapter der
// gemeinsamen Fernsteuerung (IHUB_RemoteControl). Quelle: Huawei Modbus Interface Definitions (MA08, SPC166 Issue 08), evcc huawei-sun2000,
// wlcrs/huawei_solar, ioBroker, Homey, openHAB/Symcon-Forum; Beleg-Auswertung in Hersteller-Doku/Recherche-Huawei-Fernsteuerung-Runde3.md.
// UNGETESTET: an keinem Gerät von uns geprüft (rc_tested = false).
//
// Register (FC 0x10, 32 Bit nur so, hohes Wort zuerst; Leistungen vorzeichenlos, die Richtung steht in 47100):
//   47100 U16  Zwangsmodus 0 Stopp, 1 Laden, 2 Entladen        47246 U16 Modus 0 = nach Dauer         47083 U16 Dauer in MINUTEN (0..1440, nicht gespeichert)
//   47247 U32  Zwangsladeleistung W      47249 U32 Zwangsentladeleistung W
//   47075 U32  max. Ladeleistung W       47077 U32 max. Entladeleistung W   (Schreiben über 37046/37048 wird abgelehnt)
//   47087 U16  Netzladen erlauben        47242 U32 Netzladeleistung W       47244 U32 max. Netzladeleistung W (>= 47242)
//   Lesen: 47000 Speichermodell (0 = keiner), 37000 Zustand (0 offline), 37006 Arbeitsmodus (1 = Zwangsmodus läuft), 37025 Restzeit (min),
//   37046/37048 maximale Lade-/Entladeleistung des Systems.
// Einheitlich + Laden: Modus 2 mit W > 0 schreibt Laden (47100 = 1, 47247), W < 0 Entladen (47100 = 2, 47249).
//
// Totmann: 47083 (Minuten, gerundet nach oben). Läuft die Dauer ab, stoppt der Zwangsmodus von selbst und das Gerät läuft im Arbeitsmodus
// weiter. evcc setzt 47083 = 2 min und schreibt danach alle 30 s nur 47100. Dieser Adapter schreibt 47083 und 47100 in jedem Zyklus, alles
// andere nur bei Abweichung (Speicherart der Register ist nicht angegeben). Offen: ob erneutes Schreiben von 47100 den Rest-Zähler neu startet.
// HALTEN (Modus 1) hat KEINEN Totmann: es sperrt 47075 und 47077 auf 0 dauerhaft, rc_failsafe_eff_sec ist dann 0. Modus 0 hebt die Sperre auf,
// aber nur, wenn sie 0 ist (eigene Werte des Nutzers bleiben).
// Kein Modus 3: Huawei hat keinen Netzpunkt-Sollwert. Der Drittanbieter-Weg (47321/47589) ist ohne Beleg und wird nicht gebaut.
// ---------------------------------------------------------------------------
class IHUB_RemoteHuawei implements IHUB_RemoteAdapter, IHUB_RemotePreparing
{
    const R_MODEL = 47000;
    const R_STATE = 37000;
    const R_WORKMODE = 37006;
    const R_MAX = 37046;            // U32 Laden, 37048 Entladen
    const R_CMD = 47100;
    const R_DUR = 47083;
    const R_LIMIT_C = 47075;
    const R_LIMIT_D = 47077;
    const R_GRID_EN = 47087;
    const R_GRID_P = 47242;
    const R_GRID_MAX = 47244;
    const R_FMODE = 47246;
    const R_FP_C = 47247;
    const R_FP_D = 47249;
    const MIN_MIN = 1;
    const MIN_MAX = 1440;
    const DEFAULT_S = 120;
    const TOLERANCE_W = 1;

    public static $gapUs = 300000;
    public static $dirPauseUs = 1500000;     // vor einem Richtungswechsel erst stoppen und warten
    public static $readbackUs = 1500000;     // der SDongle ist langsam
    public static $retryPauseUs = 2000000;

    public function info(): array
    {
        return [
            'vendor' => 'Huawei', 'source' => 'Huawei SUN2000 Modbus Interface Definitions (MA08, SPC166), forcible charge/discharge 47100',
            'tested' => false, 'modes' => [0, 1, 2], 'gridCharge' => true, 'socWindow' => false,
            'failsafeMinSec' => 60, 'failsafeMaxSec' => 86400, 'failsafeDefaultSec' => self::DEFAULT_S,
            'pitfalls' => [
                'untested: built from the Huawei document and from other projects, not checked on one of our devices',
                'the failsafe is the forced duration 47083 in whole MINUTES (rounded up); after it ends the inverter runs in its working mode again',
                'open: it is unknown whether writing 47100 again restarts the remaining time; reading does not extend it as far as known',
                'hold (mode 1) has NO failsafe: it locks the limits 47075 and 47077 at 0 permanently, a failure of the energy management leaves the lock in place',
                'mode 0 only releases the limits when they are 0, own values of the user stay',
                '32-bit registers are written with function 0x10 only (function 0x06 ends with an illegal address)',
                'writes above the system maximum 37046/37048 are rejected: the power is limited to it',
                'a forced charge only uses PV unless grid charging (47087) is allowed; the grid charge power 47242 is then limited',
                'before reversing the direction the forced mode is stopped (47100 = 0) and the adapter waits',
                'the connection through the SDongle needs Modbus TCP enabled with the installer access, allows one client and is slow; the direct inverter port needs a vendor login this module does not do (exception 0x80)',
                'the working mode 37006 only shows that the forced mode runs; it can start late (one report: up to 30 minutes), so it is no hard check',
                'no mode 3 (grid connection point): Huawei has no grid setpoint; the third-party dispatch registers 47321 and 47589 are not used (no evidence, no timeout)',
                'the minimum and maximum SOC registers (47081, 47082) are persistent settings and are not written',
            ],
        ];
    }

    private function u32w(int $v): array
    {
        $v = max(0, min(4294967295, $v));
        return [($v >> 16) & 0xFFFF, $v & 0xFFFF];
    }

    private function readU16($mb, int $reg): ?int
    {
        $r = $mb->readHolding($reg, 1);
        return ($r === null || count($r) < 1) ? null : (int)$mb->u16($r, 0);
    }

    private function readU32($mb, int $reg): ?int
    {
        $r = $mb->readHolding($reg, 2);
        return ($r === null || count($r) < 2) ? null : (int)$mb->u32($r, 0);
    }

    public function capability($mb): ?array
    {
        $model = $this->readU16($mb, self::R_MODEL);
        if ($model === null) {
            return null;
        }
        if ($model === 0) {
            return ['available' => false, 'version' => 0, 'status' => 0];
        }
        $state = $this->readU16($mb, self::R_STATE);
        $maxC = $this->readU32($mb, self::R_MAX);
        $maxD = $this->readU32($mb, self::R_MAX + 2);
        $wm = $this->readU16($mb, self::R_WORKMODE);
        if (($state === 0) || (($maxC ?? 0) <= 0 && ($maxD ?? 0) <= 0)) {
            return ['available' => false, 'version' => 0, 'status' => (int)$wm];
        }
        return ['available' => true, 'version' => 1, 'status' => (int)$wm, 'ratedW' => max((int)$maxC, (int)$maxD), 'maxC' => (int)$maxC, 'maxD' => (int)$maxD];
    }

    /** Aktuelle Werte der Steuerregister lesen: nur Abweichendes wird geschrieben. Ohne Antwort fehlt 'cfg', dann schreibt der Plan alles. */
    public function prepare($mb, array $cap): array
    {
        $model = $this->readU16($mb, self::R_MODEL);        // die gemerkte Fähigkeit kann veraltet sein (Batterie getrennt)
        if ($model !== null) {
            $cap['model'] = $model;
        }
        $maxC = $this->readU32($mb, self::R_MAX);
        $maxD = $this->readU32($mb, self::R_MAX + 2);
        if ($maxC !== null && $maxD !== null) {
            $cap['maxC'] = $maxC;
            $cap['maxD'] = $maxD;
            if (max($maxC, $maxD) > 0) {
                $cap['ratedW'] = max($maxC, $maxD);
            }
        }
        $lim = $mb->readHolding(self::R_LIMIT_C, 4);
        $dur = $this->readU16($mb, self::R_DUR);
        $grid = $this->readU16($mb, self::R_GRID_EN);
        $cmd = $this->readU16($mb, self::R_CMD);
        $blk = $mb->readHolding(self::R_GRID_P, 9);       // 47242..47250: Netzladeleistung, Netz-Maximum, Modus, Lade-, Entladeleistung
        if ($lim === null || count($lim) < 4 || $dur === null || $grid === null || $cmd === null || $blk === null || count($blk) < 9) {
            unset($cap['cfg']);
            return $cap;
        }
        $cap['cfg'] = [
            'limC' => (int)$mb->u32($lim, 0), 'limD' => (int)$mb->u32($lim, 2), 'dur' => $dur, 'gridEn' => $grid, 'cmd' => $cmd,
            'gridP' => (int)$mb->u32($blk, 0), 'gridMax' => (int)$mb->u32($blk, 2), 'fmode' => (int)$mb->u16($blk, 4),
            'fpC' => (int)$mb->u32($blk, 5), 'fpD' => (int)$mb->u32($blk, 7),
        ];
        return $cap;
    }

    public function capabilityError(?array $cap): string
    {
        if ($cap === null) {
            return IHUB_Text::t('Huawei remote control: the device did not answer register 47000.');
        }
        return $cap['available'] ? '' : IHUB_Text::t('Huawei remote control: no battery was found (register 47000 is 0, the battery is offline, or the system maximum power 37046/37048 is 0).');
    }

    /** Modus 2 mit 0 W ist Halten. */
    private function effMode(array $req): int
    {
        $m = (int)$req['mode'];
        return ($m === 2 && (int)($req['watts'] ?? 0) === 0) ? 1 : $m;
    }

    public function validate(array $req, ?array $cap): string
    {
        $mode = (int)$req['mode'];
        if (!in_array($mode, [0, 1, 2], true)) {
            return IHUB_Text::t('Huawei remote control: mode %s is not offered (0 off, 1 hold, 2 battery).', $mode);
        }
        if (isset($cap['model']) && (int)$cap['model'] === 0) {
            return $this->capabilityError(['available' => false]);
        }
        if ((int)($cap['ratedW'] ?? 0) <= 0) {
            return IHUB_Text::t('Huawei remote control: the system maximum power (registers 37046 and 37048) is unknown.');
        }
        return '';
    }

    private function minutes(array $req): int
    {
        $s = (int)($req['failsafeSec'] ?? 0);
        $s = $s <= 0 ? self::DEFAULT_S : $s;
        return max(self::MIN_MIN, min(self::MIN_MAX, (int)ceil($s / 60)));
    }

    /** Leistung begrenzt auf das Systemmaximum der Richtung. */
    public static function power(int $watts, int $maxC, int $maxD): int
    {
        return $watts > 0 ? ($maxC > 0 ? min($watts, $maxC) : $watts) : ($maxD > 0 ? min(-$watts, $maxD) : -$watts);
    }

    public function plan(array $req, array $cap, int $last): array
    {
        $mode = $this->effMode($req);
        $cfg = $cap['cfg'] ?? null;
        $h = $cfg ?? [];
        $maxC = (int)($cap['maxC'] ?? 0);
        $maxD = (int)($cap['maxD'] ?? 0);
        $steps = [];
        $w16 = fn(int $addr, array $words, int $after = 0) => ['fc' => 16, 'addr' => $addr, 'words' => $words, 'afterUs' => $after];
        $stop = function () use (&$steps, $h, $w16) {
            if (($h['cmd'] ?? null) !== 0) {
                $steps[] = $w16(self::R_CMD, [0], self::$gapUs);
            }
        };
        if ($mode === 0) {
            $stop();
            foreach ([self::R_FP_C => 'fpC', self::R_FP_D => 'fpD'] as $reg => $k) {
                if (($h[$k] ?? null) !== 0) {
                    $steps[] = $w16($reg, $this->u32w(0), self::$gapUs);
                }
            }
            if (($h['dur'] ?? null) !== 0) {
                $steps[] = $w16(self::R_DUR, [0], self::$gapUs);
            }
            if (isset($h['limC']) && $h['limC'] === 0 && $maxC > 0) {
                $steps[] = $w16(self::R_LIMIT_C, $this->u32w($maxC), self::$gapUs);
            }
            if (isset($h['limD']) && $h['limD'] === 0 && $maxD > 0) {
                $steps[] = $w16(self::R_LIMIT_D, $this->u32w($maxD), self::$gapUs);
            }
            return ['steps' => $steps];
        }
        if ($mode === 1) {
            $stop();
            if (($h['limC'] ?? null) !== 0) {
                $steps[] = $w16(self::R_LIMIT_C, $this->u32w(0), self::$gapUs);
            }
            if (($h['limD'] ?? null) !== 0) {
                $steps[] = $w16(self::R_LIMIT_D, $this->u32w(0), self::$gapUs);
            }
            return ['steps' => $steps];
        }
        // Modus 2: Laden (W > 0) oder Entladen (W < 0)
        $watts = (int)$req['watts'];
        $charge = $watts > 0;
        $p = self::power($watts, $maxC, $maxD);
        $cmd = $charge ? 1 : 2;
        if (isset($h['cmd']) && $h['cmd'] !== 0 && $h['cmd'] !== $cmd) {
            $steps[] = $w16(self::R_CMD, [0], self::$dirPauseUs);     // Richtungswechsel: erst stoppen
        }
        $limReg = $charge ? self::R_LIMIT_C : self::R_LIMIT_D;
        $limHave = $charge ? ($h['limC'] ?? null) : ($h['limD'] ?? null);
        $limMax = $charge ? $maxC : $maxD;
        if ($limMax > 0 && ($limHave === null || $limHave < $p)) {
            $steps[] = $w16($limReg, $this->u32w($limMax), self::$gapUs);
        }
        $gc = (int)($req['gridCharge'] ?? 0);
        if ($charge && $gc === 1) {
            if (($h['gridEn'] ?? null) !== 1) {
                $steps[] = $w16(self::R_GRID_EN, [1], self::$gapUs);
            }
            if (($h['gridMax'] ?? null) === null || $h['gridMax'] < $p) {
                $steps[] = $w16(self::R_GRID_MAX, $this->u32w($p), self::$gapUs);
            }
            if (($h['gridP'] ?? null) !== $p) {
                $steps[] = $w16(self::R_GRID_P, $this->u32w($p), self::$gapUs);
            }
        } elseif ($gc === 2 && ($h['gridEn'] ?? null) !== 0) {
            $steps[] = $w16(self::R_GRID_EN, [0], self::$gapUs);
        }
        if (($h[$charge ? 'fpC' : 'fpD'] ?? null) !== $p) {
            $steps[] = $w16($charge ? self::R_FP_C : self::R_FP_D, $this->u32w($p), self::$gapUs);
        }
        if (($h['fmode'] ?? null) !== 0) {
            $steps[] = $w16(self::R_FMODE, [0], self::$gapUs);
        }
        $steps[] = $w16(self::R_DUR, [$this->minutes($req)], self::$gapUs);     // jeder Zyklus
        $steps[] = $w16(self::R_CMD, [$cmd], 0);                                // jeder Zyklus, zuletzt
        return ['steps' => $steps];
    }

    public function readback($mb, array $req, array $cap): ?array
    {
        $c = $this->prepare($mb, ['available' => true])['cfg'] ?? null;
        if ($c === null) {
            return null;
        }
        $wm = $this->readU16($mb, self::R_WORKMODE);
        return ['regs' => [$c['cmd'], $c['fpC'], $c['fpD'], $c['dur'], $c['limC'], $c['limD']], 'status' => (int)$wm, 'cfg' => $c];
    }

    public function verify(array $back, array $req, array $cap): string
    {
        $c = $back['cfg'];
        $mode = $this->effMode($req);
        $maxC = (int)($cap['maxC'] ?? 0);
        $maxD = (int)($cap['maxD'] ?? 0);
        $diff = fn($got, $want, $reg) => IHUB_Text::t('device acknowledged, but stored %s instead of %s (register %s)', $got, $want, $reg);
        if ($mode === 0) {
            return $c['cmd'] !== 0 ? $diff($c['cmd'], 0, self::R_CMD) : '';
        }
        if ($mode === 1) {
            foreach ([[self::R_CMD, $c['cmd']], [self::R_LIMIT_C, $c['limC']], [self::R_LIMIT_D, $c['limD']]] as [$reg, $got]) {
                if ($got !== 0) {
                    return $diff($got, 0, $reg);
                }
            }
            return '';
        }
        $watts = (int)$req['watts'];
        $charge = $watts > 0;
        $p = self::power($watts, $maxC, $maxD);
        $checks = [
            [self::R_CMD, $c['cmd'], $charge ? 1 : 2],
            [$charge ? self::R_FP_C : self::R_FP_D, $charge ? $c['fpC'] : $c['fpD'], $p],
            [self::R_FMODE, $c['fmode'], 0],
            [self::R_DUR, $c['dur'], $this->minutes($req)],
        ];
        if ((int)($req['gridCharge'] ?? 0) === 1 && $charge) {
            $checks[] = [self::R_GRID_EN, $c['gridEn'], 1];
        }
        foreach ($checks as [$reg, $got, $want]) {
            if ($got !== $want) {
                return $diff($got, $want, $reg);
            }
        }
        $lim = $charge ? $c['limC'] : $c['limD'];
        return $lim < $p ? $diff($lim, $p, $charge ? self::R_LIMIT_C : self::R_LIMIT_D) : '';
    }

    public function readbackWaitUs(): int
    {
        return self::$readbackUs;
    }

    public function retryPauseUs(): int
    {
        return self::$retryPauseUs;
    }

    /** 37006 = 1: der Zwangsmodus läuft. */
    public function isActive(int $status): bool
    {
        return $status === 1;
    }

    /** Halten hat keinen Totmann (0), sonst die Dauer in Minuten. */
    public function effectiveFailsafeSec(array $req): int
    {
        return $this->effMode($req) === 2 ? $this->minutes($req) * 60 : 0;
    }
}
