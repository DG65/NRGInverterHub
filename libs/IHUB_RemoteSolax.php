<?php

// ---------------------------------------------------------------------------
// IHUB_RemoteSolax — SolaX Hybrid Gen4 und neuer, "VPP mode control (new interface)" 0x00A0-0x00A7 (SolaX ESS Inverter MODBUS Protocol
// V001.02, Abschnitt 3.5.8, Modus 8 "PV&BAT Individual Setting - Duration Mode") als Adapter der gemeinsamen Fernsteuerung.
// UNGETESTET: an keinem Gerät geprüft (rc_tested = false).
//
// WIDERSPRUCH Dokument gegen Community-Code, offen benannt, nicht geraten (Entscheidung Dietmar 19.11.2026: nach dem Community-Code bauen):
//  - Dokument (V001.02, S. 70 f., an der PDF-Seite gelesen): 0xA0 Modus, 0xA1 ExecMode, 0xA2/A3 PvW (U32), 0xA4/A5 BatW (S32), 0xA6 WaitTimeout,
//    0xA7 NextMotion. Es gibt in Modus 8 KEIN Register für die Dauer (ExecDuration): WaitTimeout beginnt laut Beschreibung erst nach Ablauf der
//    Dauer oder bei Erreichen eines Ziels, beides hat Modus 8 nicht. Nach dem Dokument hätte der Modus also KEINEN Totmann.
//  - homeassistant-solax-modbus (plugin_solax.py): 0xA6 = Dauer (bei Modus 8), 0xA7 = Timeout. Dort gibt es einen Totmann.
// Wir schreiben nach dem Community-Code: 0xA6 = Dauer in s (= Totmann), 0xA7 = 0xA0. Der Wert 0xA0 (160) ist in BEIDEN Lesarten gültig: als
// Timeout 160 s (Community) und als NextMotion „zurück in den Arbeitsmodus des Nutzers“ (Dokument). Das Gerät lässt sich so nicht über das
// Rücklesen unterscheiden (es liefert genau das Geschriebene); ob nach der Dauer wirklich ausgestiegen wird, zeigt nur ein Test am Gerät.
// Totmann im Gerät laut Community-Lesart = Dauer + 160 s (rc_failsafe_eff_sec).
//
//  - 32-Bit-Werte: niederwertiges Wort zuerst (Doku: LSB-Register zuerst). Vorzeichen BatW: + = Entladen, − = Laden (Doku). Einheitlich + = Laden,
//    deshalb BatW = −einheitlich.
//  - PV-Grenze (PvW): wir setzen 100000 W = unbegrenzt (das Gerät begrenzt selbst). Der Wert wird nicht verglichen (das Gerät kann ihn auf die
//    Nennleistung kürzen).
//  - Modus 0 (aus): 0xA0 := 0 und zusätzlich 13 Nullen ab 0x7C (so verlässt die Community-Umsetzung Modus 8/9; beides ist harmlos).
//  - Fähigkeit: FC03 0x01A0-0x01A7 lesbar (sonst Ausnahme, z. B. Gen2/Gen3 oder ältere Firmware); der Rohstatus ist der gelesene VPP-Modus (0 = kein VPP).
//
// Bewusst NICHT angeboten:
//  - Modus 3 (Netzanschlusspunkt): kein Modus der Schnittstelle regelt den Netzanschlusspunkt.
//  - Netzladen und SOC-Fenster: Modus 9 kennt nur ein Ziel-SOC, kein Fenster.
// Schreiben: ein FC16-Block 0xA0-0xA7 (keine Teilzugriffe). FC06 nicht verwenden. Kein ★ (EEPROM) an diesen Registern, laufend nachschreiben ist
// damit unkritisch (laut Hersteller sind EEPROM-Register mit ★ markiert, die Fernsteuerregister nicht).
// ---------------------------------------------------------------------------
class IHUB_RemoteSolax implements IHUB_RemoteAdapter
{
    const REG_MODE = 0x00A0;            // Schreiben; Rücklesen 0x01A0 (Differenz 0x100)
    const REG_READ = 0x01A0;
    const REG_OLD = 0x007C;             // alte Schnittstelle, 13 Register
    const VPP_BATTERY = 8;
    const EXEC_INTERRUPT = 1;
    const NEXT_USER_MODE = 0xA0;        // 160: NextMotion „Arbeitsmodus des Nutzers“ bzw. Timeout 160 s
    const PV_UNLIMITED_W = 100000;
    const DURATION_DEFAULT_S = 60;
    const DURATION_MIN_S = 10;
    const DURATION_MAX_S = 60000;
    const POWER_MAX_W = 50000;

    public static $gapUs = 500000;
    public static $readbackUs = 400000;
    public static $retryPauseUs = 1500000;

    public function info(): array
    {
        return [
            'vendor' => 'SolaX', 'source' => 'SolaX ESS Inverter MODBUS Protocol V001.02, section 3.5.8, VPP mode 8, registers 0xA0-0xA7',
            'tested' => false, 'modes' => [0, 1, 2], 'gridCharge' => false, 'socWindow' => false,
            'failsafeMinSec' => self::DURATION_MIN_S, 'failsafeMaxSec' => self::DURATION_MAX_S, 'failsafeDefaultSec' => self::DURATION_DEFAULT_S,
            'pitfalls' => [
                'untested: not checked on any device',
                'the document has no duration register in mode 8 (so no failsafe by the document); built like the community code: 0xA6 = duration, 0xA7 = timeout',
                'the written values are valid in both readings, but the read-back cannot tell them apart',
                'battery sign in the document: + discharging, - charging (opposite to the uniform sign)',
                'no grid connection point mode and no SOC window',
                'PV limit is set to 100000 W (unlimited) and not compared',
                'only Gen4 and newer (Gen2/Gen3 have no VPP control)',
            ],
        ];
    }

    public function capability($mb): ?array
    {
        $r = $mb->readHolding(self::REG_READ, 8);
        if ($r === null || count($r) < 8) {
            return null;
        }
        return ['available' => true, 'version' => 1, 'status' => $mb->u16($r, 0)];
    }

    public function capabilityError(?array $cap): string
    {
        return $cap === null ? IHUB_Text::t('SolaX remote control: the device did not answer the VPP registers 0x01A0-0x01A7 (only Gen4 and newer offer it).') : '';
    }

    public function validate(array $req, ?array $cap): string
    {
        $mode = (int)$req['mode'];
        if (!in_array($mode, [0, 1, 2], true)) {
            return IHUB_Text::t('SolaX remote control: mode %s is not offered (0 off, 1 hold, 2 battery).', $mode);
        }
        return '';
    }

    private function duration(array $req): int
    {
        $s = (int)($req['failsafeSec'] ?? 0);
        return $s <= 0 ? self::DURATION_DEFAULT_S : max(self::DURATION_MIN_S, min(self::DURATION_MAX_S, $s));
    }

    /** S32 in zwei Wörtern, niederwertiges Wort zuerst. */
    public static function encodeS32(int $v): array
    {
        $u = $v < 0 ? $v + 4294967296 : $v;
        return [$u & 0xFFFF, ($u >> 16) & 0xFFFF];
    }

    public static function decodeS32(int $lo, int $hi): int
    {
        $u = (($hi & 0xFFFF) << 16) | ($lo & 0xFFFF);
        return $u > 2147483647 ? $u - 4294967296 : $u;
    }

    /** Akkuleistung fürs Gerät: + = Entladen, − = Laden; einheitlich + = Laden, also gedreht. Halten = 0. */
    public static function devicePower(int $mode, int $watts): int
    {
        if ($mode !== 2) {
            return 0;
        }
        return -max(-self::POWER_MAX_W, min(self::POWER_MAX_W, $watts));
    }

    public function plan(array $req, array $cap, int $last): array
    {
        $mode = (int)$req['mode'];
        if ($mode === 0) {
            return ['steps' => [
                ['fc' => 16, 'addr' => self::REG_MODE, 'words' => [0], 'afterUs' => self::$gapUs],
                ['fc' => 16, 'addr' => self::REG_OLD, 'words' => array_fill(0, 13, 0), 'afterUs' => 0],
            ]];
        }
        $pv = self::encodeS32(self::PV_UNLIMITED_W);
        $bat = self::encodeS32(self::devicePower($mode, (int)($req['watts'] ?? 0)));
        return ['steps' => [
            ['fc' => 16, 'addr' => self::REG_MODE, 'words' => [self::VPP_BATTERY, self::EXEC_INTERRUPT, $pv[0], $pv[1], $bat[0], $bat[1], $this->duration($req), self::NEXT_USER_MODE], 'afterUs' => 0],
        ]];
    }

    public function readback($mb, array $req, array $cap): ?array
    {
        $r = $mb->readHolding(self::REG_READ, 8);
        if ($r === null || count($r) < 8) {
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
        $bat = self::encodeS32(self::devicePower($mode, (int)($req['watts'] ?? 0)));
        $checks = [
            [self::REG_MODE, $r[0], self::VPP_BATTERY],
            [self::REG_MODE + 1, $r[1], self::EXEC_INTERRUPT],
            [self::REG_MODE + 4, $r[4], $bat[0]],
            [self::REG_MODE + 5, $r[5], $bat[1]],
            [self::REG_MODE + 6, $r[6], $this->duration($req)],
            [self::REG_MODE + 7, $r[7], self::NEXT_USER_MODE],
        ];
        foreach ($checks as [$reg, $got, $want]) {
            if ($got !== $want) {
                return IHUB_Text::t($msg, $got, $want, $reg);
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
        return $status === self::VPP_BATTERY;
    }

    /** Totmann im Gerät laut Community-Lesart: Dauer + Timeout 160 s. */
    public function effectiveFailsafeSec(array $req): int
    {
        return $this->duration($req) + self::NEXT_USER_MODE;
    }
}
