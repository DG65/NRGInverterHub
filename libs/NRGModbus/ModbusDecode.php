<?php
// NRGModbus 0.2.0 quelle:eb736be015ea | Kopie, nicht ändern (Quelle: NRGModbus)
namespace IHUB\NRGModbus;

/**
 * Register dekodieren und kodieren. Rein, ohne Zustand, ohne IP-Symcon.
 *
 * Fehlendes Register: 0 (wie bei allen vier Modulen heute). Ausnahme: mit $nullOnInvalid liefert die Funktion
 * dann null. Die Ungültig-Marke ist nur je Aufruf zuschaltbar, Standard ist aus (Abstimmung 18.11.2026, Punkt 2):
 *   u16 0xFFFF, s16 0x8000, u32 0xFFFFFFFF, s32 0x80000000, Fließkomma NaN.
 * INF und NaN werden ohne $nullOnInvalid unverändert durchgereicht (Punkt 7).
 */
final class ModbusDecode
{
    public const INVALID_U16 = 0xFFFF;
    public const INVALID_S16 = 0x8000;      // als Rohwert, vor der Vorzeichenwandlung
    public const INVALID_U32 = 0xFFFFFFFF;
    public const INVALID_S32 = 0x80000000;  // als Rohwert

    /** @param array $regs Register 0-indiziert */
    public static function has(array $regs, int $offset, int $count = 1): bool
    {
        for ($i = 0; $i < $count; $i++) {
            if (!isset($regs[$offset + $i])) {
                return false;
            }
        }
        return true;
    }

    public static function u16($regs, $offset, bool $nullOnInvalid = false)
    {
        if (!isset($regs[$offset])) {
            return $nullOnInvalid ? null : 0;
        }
        $v = $regs[$offset] & 0xFFFF;
        return ($nullOnInvalid && $v === self::INVALID_U16) ? null : $v;
    }

    public static function s16($regs, $offset, bool $nullOnInvalid = false)
    {
        if (!isset($regs[$offset])) {
            return $nullOnInvalid ? null : 0;
        }
        $v = $regs[$offset] & 0xFFFF;
        if ($nullOnInvalid && $v === self::INVALID_S16) {
            return null;
        }
        return $v > 32767 ? $v - 65536 : $v;
    }

    /** 32 Bit; $swap = true: niederwertiges Wort zuerst (CDAB). */
    public static function u32($regs, $offset, bool $swap = false, bool $nullOnInvalid = false)
    {
        if ($nullOnInvalid && !self::has($regs, $offset, 2)) {
            return null;
        }
        $a = self::u16($regs, $offset);
        $b = self::u16($regs, $offset + 1);
        $v = $swap ? (($b << 16) | $a) : (($a << 16) | $b);
        return ($nullOnInvalid && $v === self::INVALID_U32) ? null : $v;
    }

    public static function s32($regs, $offset, bool $swap = false, bool $nullOnInvalid = false)
    {
        $v = self::u32($regs, $offset, $swap, false);
        if ($nullOnInvalid && (!self::has($regs, $offset, 2) || $v === self::INVALID_S32)) {
            return null;
        }
        return $v > 2147483647 ? $v - 4294967296 : $v;
    }

    /** IEEE-754 binary32 über 2 Register. Big endian (ABCD); $swap = true: CDAB. */
    public static function float32($regs, $offset, bool $swap = false, bool $nullOnInvalid = false)
    {
        if ($nullOnInvalid && !self::has($regs, $offset, 2)) {
            return null;
        }
        $w0 = self::u16($regs, $offset);
        $w1 = self::u16($regs, $offset + 1);
        if ($swap) {
            [$w0, $w1] = [$w1, $w0];
        }
        $val = unpack('G', pack('nn', $w0, $w1));
        $f = (float)($val[1] ?? 0.0);
        return ($nullOnInvalid && is_nan($f)) ? null : $f;
    }

    /** IEEE-754 binary64 über 4 Register. $swap = true: die vier Wörter in umgekehrter Reihenfolge. */
    public static function float64($regs, $offset, bool $swap = false, bool $nullOnInvalid = false)
    {
        if ($nullOnInvalid && !self::has($regs, $offset, 4)) {
            return null;
        }
        $w = [self::u16($regs, $offset), self::u16($regs, $offset + 1), self::u16($regs, $offset + 2), self::u16($regs, $offset + 3)];
        if ($swap) {
            $w = array_reverse($w);
        }
        $val = unpack('E', pack('nnnn', $w[0], $w[1], $w[2], $w[3]));
        $f = (float)($val[1] ?? 0.0);
        return ($nullOnInvalid && is_nan($f)) ? null : $f;
    }

    /** Zeichenkette aus $regCount Registern (je zwei Zeichen, High-Byte zuerst); rechts ohne NUL und Leerzeichen. */
    public static function str($regs, $offset, int $regCount): string
    {
        $s = '';
        for ($i = 0; $i < $regCount; $i++) {
            $r = self::u16($regs, $offset + $i);
            $s .= chr(($r >> 8) & 0xFF) . chr($r & 0xFF);
        }
        return rtrim($s, "\x00 ");
    }

    // ---- Kodieren (Gegenstücke zum Schreiben) ----

    public static function packU32(int $value, bool $swap = false): array
    {
        $hi = ($value >> 16) & 0xFFFF;
        $lo = $value & 0xFFFF;
        return $swap ? [$lo, $hi] : [$hi, $lo];
    }

    /** INF und NaN bleiben unverändert (go-e schaltet mit INF das Energielimit ab). */
    public static function packFloat32(float $value, bool $swap = false): array
    {
        $u = unpack('n2', pack('G', $value));
        return $swap ? [$u[2], $u[1]] : [$u[1], $u[2]];
    }

    public static function packFloat64(float $value, bool $swap = false): array
    {
        $u = array_values(unpack('n4', pack('E', $value)));
        return $swap ? array_reverse($u) : $u;
    }
}
