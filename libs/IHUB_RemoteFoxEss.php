<?php

// ---------------------------------------------------------------------------
// IHUB_RemoteFoxEss — FoxESS "Remote Control" 46001-46007 (H3-Pro, H3-Smart; FoxESS Modbus Protocol V1.05.03.00, 15.01.2025,
// Tabelle 3-8, Nr. 271-275) als Adapter der gemeinsamen Fernsteuerung. UNGETESTET: nur nach dem Herstellerdokument gebaut, an keinem
// Gerät geprüft (rc_tested = false).
//
//   46001 Bitfeld: Bit0 Fernsteuerung an, Bit1 Vorzeichen (0 = Erzeugersystem: + Erzeugung/Entladung/Einspeisung, 1 = Verbrauchersystem),
//         Bits 3:2 Ziel (00 AC, 01 Akku, 10 Netz (Zähler), 11 AC (Netz zuerst))
//   46002 Totmann in s (U16), 46003/46004 Wirkleistung I32 in W (hoch zuerst), 46007 Restzeit des Totmanns in s (nur lesbar)
// Wir schreiben Bit1 = 0 (Erzeugersystem). Einheitliches Vorzeichen -> Gerät: Modus 2 (Ziel Akku) = −Leistung (Laden = Verbrauch),
// Modus 3 (Ziel Netz) = +Leistung (Einspeisung = Erzeugung). Die Zuordnung für Akku und Netz ist aus der Bitdefinition abgeleitet, am
// Gerät NICHT belegt (die verbreiteten Umsetzungen schreiben nur das Ziel AC).
//
// Bewusst NICHT angeboten:
//  - Modus 1 (halten): Fernleistung 0 W drosselt bei H3-Pro/H3-Smart die PV (evcc, an echter Hardware bestätigt); der Weg „Max. Entladestrom 0“
//    (46608) hat keinen Totmann, eine hängengebliebene Sperre wäre das Fehlerbild, das die Fernsteuerung vermeiden soll.
//  - Netzladen und SOC-Fenster: Max-SOC wird beim Fernladen nicht beachtet (foxess_modbus), die Grenzregister 46609/46610 sind in der Doku
//    ohne Aussage zur Speicherung. Das EMS überwacht den SOC selbst.
//  - Fernsteuerung ausschalten (Modus 0) nur, wenn wir sie selbst eingeschaltet haben (die Strategien der FoxESS-App nutzen dasselbe Register).
// Schreiben wie in foxess_modbus: Timeout und Enable einzeln mit FC06, die Leistung als FC16-Block. Reihenfolge: Timeout, Enable, Leistung.
// ---------------------------------------------------------------------------
class IHUB_RemoteFoxEss implements IHUB_RemoteAdapter
{
    const REG_CONTROL = 46001;
    const REG_TIMEOUT = 46002;
    const REG_POWER   = 46003;
    const REG_COUNTDOWN = 46007;
    const TIMEOUT_DEFAULT_S = 60;       // wie evcc
    const TIMEOUT_MAX_S = 65535;
    const POWER_MAX_W = 50000;

    /** Wartezeit zwischen zwei Schreibbefehlen (die Doku nennt keinen Mindestabstand; vorsichtig 0,5 s). Nur der Prüfstand ändert sie. */
    public static $gapUs = 500000;
    public static $readbackUs = 400000;
    public static $retryPauseUs = 1500000;

    public function info(): array
    {
        return [
            'vendor' => 'FoxESS', 'source' => 'FoxESS Modbus Protocol V1.05.03.00 (15.01.2025), table 3-8, registers 46001-46007',
            'tested' => false, 'modes' => [0, 2, 3], 'gridCharge' => false, 'socWindow' => false,
            'failsafeMinSec' => 1, 'failsafeMaxSec' => self::TIMEOUT_MAX_S, 'failsafeDefaultSec' => self::TIMEOUT_DEFAULT_S,
            'pitfalls' => [
                'untested: built from the manufacturer document only',
                'sign for target battery and grid derived from the bit definition, not confirmed',
                'no hold mode: remote power 0 W curtails PV on H3-Pro/H3-Smart',
                'max SOC is not respected while remote charging',
                'the strategies of the FoxESS app use the same register: remote control is only switched off if we switched it on',
                'only H3-Pro and H3-Smart (register family 46000); older families are not supported',
            ],
        ];
    }

    public function capability($mb): ?array
    {
        $r = $mb->readHolding(self::REG_CONTROL, 7);   // 46001-46007
        if ($r === null || count($r) < 7) {
            return null;
        }
        $on = ($mb->u16($r, 0) & 1) === 1;
        $countdown = $mb->u16($r, 6);
        return ['available' => true, 'version' => 1, 'status' => $on ? $countdown : 0];
    }

    public function capabilityError(?array $cap): string
    {
        return $cap === null ? IHUB_Text::t('FoxESS remote control: the device did not answer register 46001 (only H3-Pro and H3-Smart offer it).') : '';
    }

    public function validate(array $req, ?array $cap): string
    {
        $mode = (int)$req['mode'];
        if (!in_array($mode, [0, 2, 3], true)) {
            return IHUB_Text::t('FoxESS remote control: mode %s is not offered (0 off, 2 battery, 3 grid connection point).', $mode);
        }
        return '';
    }

    /** Totmann in s, auf den Bereich begrenzt. */
    private function timeout(array $req): int
    {
        $s = (int)($req['failsafeSec'] ?? 0);
        return $s <= 0 ? self::TIMEOUT_DEFAULT_S : min(self::TIMEOUT_MAX_S, $s);
    }

    /** I32 in zwei Wörtern, hoch zuerst. */
    public static function encodeI32(int $v): array
    {
        $u = $v < 0 ? $v + 4294967296 : $v;
        return [($u >> 16) & 0xFFFF, $u & 0xFFFF];
    }

    public static function decodeI32(int $hi, int $lo): int
    {
        $u = (($hi & 0xFFFF) << 16) | ($lo & 0xFFFF);
        return $u > 2147483647 ? $u - 4294967296 : $u;
    }

    /** Wert von 46001: Bit0 an, Bit1 = 0 (Erzeugersystem), Bits 3:2 Ziel (Modus 2 Akku = 01, Modus 3 Netz = 10). */
    public static function controlWord(int $mode): int
    {
        return 1 | (($mode === 3 ? 2 : 1) << 2);
    }

    /** Leistung für das Gerät: Modus 2 − einheitlich (Laden = Verbrauch), Modus 3 + einheitlich (Einspeisung = Erzeugung). */
    public static function devicePower(int $mode, int $watts): int
    {
        $w = max(-self::POWER_MAX_W, min(self::POWER_MAX_W, $watts));
        return $mode === 2 ? -$w : $w;
    }

    public function plan(array $req, array $cap, int $last): array
    {
        $mode = (int)$req['mode'];
        if ($mode === 0) {
            // Nur ausschalten, was wir selbst eingeschaltet haben.
            return ['steps' => $last === 0 ? [] : [['fc' => 6, 'addr' => self::REG_CONTROL, 'words' => [0], 'afterUs' => 0]]];
        }
        $p = self::encodeI32(self::devicePower($mode, (int)($req['watts'] ?? 0)));
        return ['steps' => [
            ['fc' => 6, 'addr' => self::REG_TIMEOUT, 'words' => [$this->timeout($req)], 'afterUs' => self::$gapUs],
            ['fc' => 6, 'addr' => self::REG_CONTROL, 'words' => [self::controlWord($mode)], 'afterUs' => self::$gapUs],
            ['fc' => 16, 'addr' => self::REG_POWER, 'words' => $p, 'afterUs' => 0],
        ]];
    }

    public function readback($mb, array $req, array $cap): ?array
    {
        $r = $mb->readHolding(self::REG_CONTROL, 7);
        if ($r === null || count($r) < 7) {
            return null;
        }
        $r = array_values($r);
        $on = ($r[0] & 1) === 1;
        return ['regs' => $r, 'status' => $on ? $r[6] : 0];
    }

    public function verify(array $back, array $req, array $cap): string
    {
        $r = $back['regs'];
        $mode = (int)$req['mode'];
        if ($mode === 0) {
            return ($r[0] & 1) !== 0 ? IHUB_Text::t('device acknowledged, but stored %s instead of %s (register %s)', $r[0], 0, self::REG_CONTROL) : '';
        }
        $want = self::controlWord($mode);
        if (($r[0] & 0x000F) !== $want) {
            return IHUB_Text::t('device acknowledged, but stored %s instead of %s (register %s)', $r[0], $want, self::REG_CONTROL);
        }
        if ($r[1] !== $this->timeout($req)) {
            return IHUB_Text::t('device acknowledged, but stored %s instead of %s (register %s)', $r[1], $this->timeout($req), self::REG_TIMEOUT);
        }
        $want = self::encodeI32(self::devicePower($mode, (int)($req['watts'] ?? 0)));
        if ($r[2] !== $want[0] || $r[3] !== $want[1]) {
            return IHUB_Text::t('device acknowledged, but stored %s instead of %s (register %s)', self::decodeI32($r[2], $r[3]), self::decodeI32($want[0], $want[1]), self::REG_POWER);
        }
        if ($back['status'] <= 0) {
            return IHUB_Text::t('FoxESS remote control: the registers hold the values, but the failsafe countdown (register 46007) is 0.');
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
        return $status > 0;
    }

    public function effectiveFailsafeSec(array $req): int
    {
        return $this->timeout($req);
    }
}
