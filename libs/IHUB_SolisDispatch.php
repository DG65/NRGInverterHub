<?php

// ---------------------------------------------------------------------------
// IHUB_SolisDispatch — Solis "Remote Dispatch" (Smart Control V1.0, Ginlong, 15.03.2025): Steuerung des Hybrid-
// Wechselrichters über die Register 44100-44110. Eigene Klasse, damit Aufbau der Register, Schreibreihenfolge und
// Rücklesen an einer Stelle liegen und ohne Gerät prüfbar sind (.tools/test-solis-dispatch.php); der Solis-Treiber
// reicht nur die Vorgaben durch.
//
// Fähigkeit: Input-Register 34502 = 0xAA55 (Register 44100-44199 gültig), 34503 = Version (0x0001 = V01),
// 34504 = Status (0 aus, 1 Standard/Standby, 2 Echtzeit, 3 Zeitplan).
//
// Schreiben in getrennten Befehlsblöcken (FC16, höchstens 50 Register je Befehl), Reihenfolge SYSTEM vor ECHTZEIT:
//   System   44100 Hauptschalter (0 aus, 1 an), 44101 Totmann in Minuten (1-1440), 44102 Grenzschalter (Bit0 Bezug,
//            Bit1 Einspeisung), 44103 Bezugsgrenze und 44104 Einspeisegrenze in 100 W.
//   Echtzeit 44105 Modus (1 Standby, 2 Akku +/-W, 3 Netzanschlusspunkt +/-W, 4 AC-Netzanschluss +/-W),
//            44106/44107 Leistung S32 in 10 W (Doku-Vorzeichen: Modus 2 + Laden / − Entladen; Modus 3 und 4 − Bezug /
//            + Einspeisung), 44108 Funktionsschalter (Bit0-1 PV-Abschaltung 1 aus / 2 an, Bit4-5 Netzladen 1 erlaubt /
//            2 nicht erlaubt, 0 = unverändert), 44109/44110 SOC-Bereich in %.
// Zwischen zwei Schreibbefehlen mindestens 700 ms (Doku). Nichts wird im Gerät gespeichert (RAM), der Totmann liegt
// beim Gerät: ohne neuen Befehl auf 44100-44199 verlässt es den Dispatch nach der Zeit aus 44101 und kehrt zur eigenen
// Logik zurück (bei Pauls Solis am 19.11.2026 gemessen, 44101 stand danach wieder auf 5).
// Der InverterHub hält selbst nichts: wer den Dispatch halten will, schreibt ctl_dispatch_mode vor Ablauf des Totmanns neu.
// 0xFFFF wird NIE geschrieben: der Systemblock mit 0xFFFF in 44103/44104 wurde am Gerät nicht übernommen (19.11.2026).
// ---------------------------------------------------------------------------
class IHUB_SolisDispatch
{
    const REG_AVAILABLE = 34502;
    const REG_VERSION   = 34503;
    const REG_STATUS    = 34504;
    const REG_SYSTEM    = 44100;
    const REG_REALTIME  = 44105;
    const AVAILABLE_MAGIC = 0xAA55;

    const MODE_OFF = 0;
    const MODE_STANDBY = 1;
    const MODE_BATTERY = 2;
    const MODE_GRID_POINT = 3;
    const MODE_AC_PORT = 4;

    const POWER_MAX_W = 40000;       // Obergrenze der Vorgabe, das Gerät begrenzt selbst (ungültiger Wert: Gerät behält den alten)
    const FAILSAFE_DEFAULT_MIN = 5;  // Vorgabe des Geräts (Register 44101)
    const FAILSAFE_MAX_MIN = 1440;

    /** Mindestabstand zwischen zwei Schreibbefehlen in Mikrosekunden (Doku: > 700 ms). Nur der Prüfstand verkürzt ihn. */
    public static $spacingUs = 750000;
    /** Wartezeit vor dem Rücklesen (Doku: Leseabstand > 300 ms). */
    public static $readbackUs = 400000;
    /** Pause vor dem zweiten Gesamtversuch (Datenlogger mit nur einer Verbindung). */
    public static $retryPauseUs = 1500000;

    /** Gültigkeitsprüfung der Vorgaben; liefert den Fehlertext oder '' */
    public static function validate(array $p): string
    {
        $mode = (int)($p['mode'] ?? 0);
        if ($mode < self::MODE_OFF || $mode > self::MODE_AC_PORT) {
            return IHUB_Text::t('Solis dispatch: mode %s is invalid (0 off, 1 standby, 2 battery, 3 grid connection point, 4 AC grid port).', $mode);
        }
        $min = (int)($p['socMin'] ?? 0);
        $max = self::socMax($p);
        if ($min < 0 || $min > 100 || $max < 1 || $max > 100 || $min > $max) {
            return IHUB_Text::t('Solis dispatch: SOC range %s to %s is invalid (0 to 100, lower limit not above upper limit).', $min, $max);
        }
        return '';
    }

    /** Obere SOC-Grenze; 0 (Variable nie gesetzt) gilt als 100. */
    public static function socMax(array $p): int
    {
        $max = (int)($p['socMax'] ?? 0);
        return $max === 0 ? 100 : $max;
    }

    public static function failsafe(array $p): int
    {
        $f = (int)($p['failsafeMin'] ?? 0);
        return $f <= 0 ? self::FAILSAFE_DEFAULT_MIN : min(self::FAILSAFE_MAX_MIN, $f);
    }

    /** Vorgabe in W auf das Register: S32 in 10 W, vorzeichenrichtig als zwei 16-Bit-Wörter (hoch zuerst). */
    public static function encodePower(int $watts): array
    {
        $w = max(-self::POWER_MAX_W, min(self::POWER_MAX_W, $watts));
        $v = (int)round($w / 10);
        $u = $v < 0 ? $v + 4294967296 : $v;
        return [($u >> 16) & 0xFFFF, $u & 0xFFFF];
    }

    public static function decodePower(int $hi, int $lo): int
    {
        $u = (($hi & 0xFFFF) << 16) | ($lo & 0xFFFF);
        return ($u > 2147483647 ? $u - 4294967296 : $u) * 10;
    }

    /** 44100-44104. Grenzen: 0 = aus (Schalter aus, Wert 0), sonst auf 100 W gerundet. Nie 0xFFFF. */
    public static function systemBlock(array $p): array
    {
        $imp = max(0, min(self::POWER_MAX_W, (int)($p['importLimitW'] ?? 0)));
        $exp = max(0, min(self::POWER_MAX_W, (int)($p['exportLimitW'] ?? 0)));
        $bits = ($imp > 0 ? 1 : 0) | ($exp > 0 ? 2 : 0);
        return [1, self::failsafe($p), $bits, (int)round($imp / 100), (int)round($exp / 100)];
    }

    /** 44105-44110. */
    public static function realtimeBlock(array $p): array
    {
        $mode = (int)$p['mode'];
        $w = ($mode === self::MODE_STANDBY) ? 0 : (int)($p['watts'] ?? 0);
        [$hi, $lo] = self::encodePower($w);
        $pv = max(0, min(2, (int)($p['pvShutdown'] ?? 0)));
        $gc = max(0, min(2, (int)($p['gridCharge'] ?? 0)));
        return [$mode, $hi, $lo, $pv | ($gc << 4), (int)($p['socMin'] ?? 0), self::socMax($p)];
    }

    /**
     * Fähigkeit lesen. null = keine Antwort; sonst ['available' => bool, 'version' => int, 'status' => int].
     * Ein Gerät ohne Dispatch liefert 34502 ungleich 0xAA55 (oder eine Ausnahme: dann null).
     */
    public static function probe($mb): ?array
    {
        $r = $mb->readInput(self::REG_AVAILABLE, 3);
        if ($r === null || !isset($r[0])) {
            return null;
        }
        return ['available' => $mb->u16($r, 0) === self::AVAILABLE_MAGIC, 'version' => $mb->u16($r, 1), 'status' => $mb->u16($r, 2)];
    }

    /**
     * Vorgaben ans Gerät bringen und zurücklesen. true = das Gerät hat sie. $err bekommt sonst den Grund.
     * $known: Ergebnis von probe(), wenn der Aufrufer die Fähigkeit schon kennt (spart einen Lesezugriff).
     * $status bekommt den gelesenen Wert von 34504.
     */
    public static function apply($mb, array $p, ?array $known, &$err, &$status = null): bool
    {
        $err = '';
        $bad = self::validate($p);
        if ($bad !== '') {
            $err = $bad;
            return false;
        }
        if ($known === null) {
            $known = self::probe($mb);
        }
        if ($known === null) {
            $err = IHUB_Text::t('Solis dispatch: the device did not answer the capability query (register 34502).');
            return false;
        }
        if (!$known['available']) {
            $err = IHUB_Text::t('Solis dispatch: this device does not offer remote dispatch (register 34502 is not 0xAA55).');
            return false;
        }
        $mode = (int)$p['mode'];
        for ($try = 0; $try < 2; $try++) {
            if ($try > 0) {
                usleep(self::$retryPauseUs);
            }
            $err = '';
            if ($mode === self::MODE_OFF) {
                if (!$mb->writeMultiple(self::REG_SYSTEM, [0])) {
                    $err = self::writeError($mb);
                    continue;
                }
                usleep(self::$readbackUs);
                $back = self::readBack($mb, 1);
                if ($back === null) {
                    $err = IHUB_Text::t('read-back without answer');
                    continue;
                }
                $status = $back['status'];
                if ($back['regs'][0] !== 0) {
                    $err = IHUB_Text::t('device acknowledged, but stored %s instead of %s', $back['regs'][0], 0);
                    continue;
                }
                return true;
            }
            $system = self::systemBlock($p);
            $real = self::realtimeBlock($p);
            if (!$mb->writeMultiple(self::REG_SYSTEM, $system)) {
                $err = self::writeError($mb);
                continue;
            }
            usleep(self::$spacingUs);
            if (!$mb->writeMultiple(self::REG_REALTIME, $real)) {
                $err = self::writeError($mb);
                continue;
            }
            usleep(self::$readbackUs);
            $back = self::readBack($mb, 11);
            if ($back === null) {
                $err = IHUB_Text::t('read-back without answer');
                continue;
            }
            $status = $back['status'];
            $diff = self::compare($back['regs'], $system, $real);
            if ($diff !== '') {
                $err = $diff;
                continue;
            }
            if ($back['status'] === 0) {
                $err = IHUB_Text::t('Solis dispatch: the registers hold the values, but the device does not report dispatch as running (register 34504 = 0).');
                continue;
            }
            return true;
        }
        return false;
    }

    private static function writeError($mb): string
    {
        $e = isset($mb->lastError) && $mb->lastError !== '' ? $mb->lastError : IHUB_Text::t('write failed');
        return $e;
    }

    /** Liest 44100.. ($count Register) und 34504 über eine gemeinsame Verbindung. */
    private static function readBack($mb, int $count): ?array
    {
        $mb->beginBatch();
        try {
            $r = $mb->readHolding(self::REG_SYSTEM, $count);
            $s = $mb->readInput(self::REG_STATUS, 1);
        } finally {
            $mb->endBatch();
        }
        if ($r === null || count($r) < $count) {
            return null;
        }
        return ['regs' => array_values($r), 'status' => $s === null ? 0 : $mb->u16($s, 0)];
    }

    /** Vergleicht die zurückgelesenen Register mit dem Gesendeten; '' = gleich, sonst Text der ersten Abweichung. */
    private static function compare(array $back, array $system, array $real): string
    {
        foreach ($system as $i => $v) {
            if (($back[$i] ?? null) !== $v) {
                return IHUB_Text::t('device acknowledged, but stored %s instead of %s (register %s)', $back[$i] ?? '?', $v, self::REG_SYSTEM + $i);
            }
        }
        foreach ($real as $i => $v) {
            $reg = self::REG_REALTIME + $i;
            $got = $back[$reg - self::REG_SYSTEM] ?? null;
            if ($i === 3) {
                // Funktionsschalter: 0 = unverändert; das Gerät behält dann den früheren gültigen Wert. Nur gesetzte Bitpaare prüfen.
                if ((($v & 0x03) !== 0 && ($got & 0x03) !== ($v & 0x03)) || (($v & 0x30) !== 0 && ($got & 0x30) !== ($v & 0x30))) {
                    return IHUB_Text::t('device acknowledged, but stored %s instead of %s (register %s)', $got ?? '?', $v, $reg);
                }
                continue;
            }
            if ($got !== $v) {
                return IHUB_Text::t('device acknowledged, but stored %s instead of %s (register %s)', $got ?? '?', $v, $reg);
            }
        }
        return '';
    }
}
