<?php

// ---------------------------------------------------------------------------
// InverterHub — Hauptmodul, lädt den Treiber laut Manufacturer-Property
// ---------------------------------------------------------------------------

class IHUB_Text
{
    /** @var callable|null */
    private static $uebersetzer = null;
    /** @var array<string,string> */
    private static $gemerkt = [];

    public static function setzen(?callable $translate): void
    {
        self::$uebersetzer = $translate === null ? null : function (string $text) use ($translate): string {
            if (!isset(self::$gemerkt[$text])) {
                try {
                    self::$gemerkt[$text] = (string)$translate($text);
                } catch (\Throwable $e) {
                    return $text;
                }
            }
            return self::$gemerkt[$text];
        };
    }

    // Translate() liest locale.json bei jedem Aufruf (SUITE.md Stolperstein 24): je Text einmal je Prozess.
    public static function t(string $text, ...$args): string
    {
        $out = self::$uebersetzer !== null ? (self::$uebersetzer)($text) : $text;
        if ($args === []) {
            return $out;
        }
        $f = @vsprintf($out, array_map(function ($a) {
            return is_scalar($a) || $a === null ? (string)$a : (string)json_encode($a);
        }, $args));
        return $f === false ? $out : $f;
    }
}
