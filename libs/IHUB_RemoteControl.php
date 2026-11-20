<?php

// ---------------------------------------------------------------------------
// IHUB_RemoteControl — gemeinsame Fernsteuerung für Hybrid-Wechselrichter (seit 0.92.0). Eine Schicht für alle Hersteller:
// Variablen und Idents (ctl_rc_*, rc_*), Merken der Vorgaben, Schreiben in der Reihenfolge des Adapters mit Abständen,
// Rücklesen, ein zweiter Gesamtversuch, Warnung mit Grund (zählt in ctl_write_fail_*), Fähigkeit und Kennzeichnung.
// Die Hersteller liefern nur einen IHUB_RemoteAdapter (Register, Skalierung, Vorzeichen).
//
// Regeln (gelten für jeden Adapter):
//  - Die Fähigkeit wird vor jedem Schreiben geprüft; fehlt sie, wird NICHTS geschrieben (Warnung).
//  - Vorgaben außer dem Modus werden nur gemerkt; erst das Schreiben von ctl_rc_mode sendet alles. Der Modus wird vor Ablauf des
//    Totmanns neu geschrieben (das EMS in jedem Zyklus). Der InverterHub hält selbst nichts, der Totmann bleibt beim Gerät.
//  - Was ein Adapter nicht kann (Netzladen, SOC-Fenster, Modi), wird bei Anfrage abgelehnt statt still ignoriert.
//  - Einheitliches Vorzeichen: Modus 2 + Laden / − Entladen, Modus 3 + Einspeisung / − Bezug.
// ---------------------------------------------------------------------------
class IHUB_RemoteControl
{
    const MODE_OFF = 0;
    const MODE_HOLD = 1;
    const MODE_BATTERY = 2;
    const MODE_GRID = 3;
    const POWER_MAX_W = 40000;

    /** Nur Prüfstände: keine Wartezeiten. */
    public static $noWait = false;

    public static function wait(int $us): void
    {
        if (!self::$noWait && $us > 0) {
            usleep($us);
        }
    }

    /** Variablen der Fernsteuerung für die Steuerungsgruppe eines Treibers. */
    public static function vars(): array
    {
        return [
            ['ctl_rc_mode', 'Remote control: mode (writing sends everything)', 'I', 'IHUB.RcMode', false, 'control', 'Mode 0 off, 1 hold battery, 2 battery ±W, 3 grid connection point ±W. Set the other values first, the mode last; write again before the failsafe time runs out'],
            ['ctl_rc_w', 'Remote control: power (W)', 'I', 'IHUB.RcWatt', false, 'control', 'Mode 2: + charging / − discharging; mode 3: + feed-in / − import. Only stored until the mode is written'],
            ['ctl_rc_grid_charge', 'Remote control: grid charging', 'I', 'IHUB.RcGridCharge', false, 'control', '0 unchanged, 1 allowed, 2 not allowed (only where the device supports it)'],
            ['ctl_rc_soc_min', 'Remote control: SOC lower limit (%)', 'I', '~Intensity.100', false, 'control', 'Only where the device supports an SOC window'],
            ['ctl_rc_soc_max', 'Remote control: SOC upper limit (%, 0 = 100)', 'I', '~Intensity.100', false, 'control', 'Only where the device supports an SOC window'],
            ['ctl_rc_failsafe_sec', 'Remote control: failsafe time (s, 0 = default)', 'I', 'IHUB.RcSeconds', false, 'control', 'Without a new command the device leaves the remote control after this time'],
            ['rc_available', 'Remote control: device offers it', 'B', '~Switch', false, 'device', 'Capability read from the device'],
            ['rc_version', 'Remote control: register version', 'I', '', false, 'device', '0 without remote control'],
            ['rc_status', 'Remote control: device status (raw)', 'I', '', false, 'device', 'Raw status of the device, meaning per manufacturer'],
            ['rc_active', 'Remote control: running', 'B', '~Switch', false, 'device', 'Judged by the adapter from the raw status'],
            ['rc_vendor', 'Remote control: manufacturer adapter', 'S', '', false, 'device', 'Manufacturer and document'],
            ['rc_tested', 'Remote control: confirmed on a device', 'B', '~Switch', false, 'device', 'false = built from the manufacturer document only, not yet confirmed on a device'],
            ['rc_modes', 'Remote control: supported modes', 'S', '', false, 'device', 'For example 0,1,2,3'],
            ['rc_failsafe_eff_sec', 'Remote control: failsafe time in the device (s)', 'I', '', false, 'device', 'After limiting and rounding'],
            ['rc_failsafe_decl_sec', 'Remote control: failsafe time the adapter declares (s)', 'I', '', false, 'device', 'Contract field failsafeSec: 0 = no failsafe known or none in the device, then no write release for the energy manager'],
            ['rc_rated_w', 'Remote control: rated power (W)', 'I', '', false, 'device', '0 = unknown'],
        ];
    }

    public static function profiles(): array
    {
        return [
            'IHUB.RcWatt'    => [VARIABLETYPE_INTEGER, ' W', -self::POWER_MAX_W, self::POWER_MAX_W, 10, 0],
            'IHUB.RcSeconds' => [VARIABLETYPE_INTEGER, ' s', 0, 65535, 1, 0],
        ];
    }

    public static function enumProfiles(): array
    {
        return [
            'IHUB.RcMode' => [0 => ['Off', 0x7A8A99], 1 => ['Hold battery', 0xE08A2B], 2 => ['Battery', 0x3BA55D], 3 => ['Grid connection point', 0x3BA55D]],
            'IHUB.RcGridCharge' => [0 => ['Unchanged', 0x7A8A99], 1 => ['Allowed', 0x3BA55D], 2 => ['Not allowed', 0xE08A2B]],
        ];
    }

    /** Anfrage aus den gemerkten Variablen bauen. */
    public static function requestFromHub($hub, int $mode): array
    {
        return [
            'mode'        => $mode,
            'watts'       => $hub->GetVarInt('ctl_rc_w'),
            'gridCharge'  => $hub->GetVarInt('ctl_rc_grid_charge'),
            'socMin'      => $hub->GetVarInt('ctl_rc_soc_min'),
            'socMax'      => $hub->GetVarInt('ctl_rc_soc_max'),
            'failsafeSec' => $hub->GetVarInt('ctl_rc_failsafe_sec'),
            'ext'         => [],
        ];
    }

    /** Fähigkeit aus dem letzten langsamen Zyklus (Version > 0), sonst null (dann wird beim Schreiben nachgefragt). */
    public static function knownCapability($hub): ?array
    {
        $ver = $hub->GetVarInt('rc_version');
        if ($ver <= 0) {
            return null;
        }
        $cap = ['available' => true, 'version' => $ver, 'status' => $hub->GetVarInt('rc_status')];
        $rated = $hub->GetVarInt('rc_rated_w');
        if ($rated > 0) {
            $cap['ratedW'] = $rated;
        }
        return $cap;
    }

    /**
     * Fernsteuerung anwenden. true = das Gerät hat die Vorgaben. $err bekommt sonst den Grund, $back das Rückgelesene.
     * Schreibt nichts, wenn die Fähigkeit fehlt oder die Anfrage ungültig ist.
     */
    public static function apply(IHUB_RemoteAdapter $a, $mb, array $req, ?array $cap, int $last, &$err, &$back = null): bool
    {
        $err = '';
        if ($cap === null) {
            $cap = $a->capability($mb);
        }
        $bad = $a->capabilityError($cap);
        if ($bad !== '') {
            $err = $bad;
            return false;
        }
        if ($a instanceof IHUB_RemotePreparing) {
            $cap = $a->prepare($mb, $cap);
        }
        $bad = $a->validate($req, $cap);
        if ($bad !== '') {
            $err = $bad;
            return false;
        }
        $plan = $a->plan($req, $cap, $last);
        $steps = $plan['steps'];
        if ($steps === []) {
            $back = null;
            return true;
        }
        for ($try = 0; $try < 2; $try++) {
            if ($try > 0) {
                self::wait($a->retryPauseUs());
            }
            $err = '';
            foreach ($steps as $s) {
                if (isset($s['mqtt'])) {   // Schritt über MQTT statt Modbus (Victron-Overrides)
                    if (!$a->publish($s['mqtt'])) {
                        $err = IHUB_Text::t('write failed');
                        continue 2;
                    }
                    self::wait((int)$s['afterUs']);
                    continue;
                }
                $ok = ((int)$s['fc'] === 6) ? $mb->writeSingle($s['addr'], $s['words'][0]) : $mb->writeMultiple($s['addr'], $s['words']);
                if (!$ok) {
                    $err = isset($mb->lastError) && $mb->lastError !== '' ? $mb->lastError : IHUB_Text::t('write failed');
                    continue 2;
                }
                self::wait((int)$s['afterUs']);
            }
            self::wait($a->readbackWaitUs());
            $back = $a->readback($mb, $req, $cap);
            if ($back === null) {
                $err = IHUB_Text::t('read-back without answer');
                continue;
            }
            $diff = $a->verify($back, $req, $cap);
            if ($diff !== '') {
                $err = $diff;
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * Steuerpunkt ctl_rc_* behandeln. true = der Ident gehörte der Fernsteuerung (auch bei Ablehnung), false = nicht zuständig.
     */
    public static function control(IHUB_RemoteAdapter $a, $mb, $hub, string $ident, $value): bool
    {
        $info = $a->info();
        switch ($ident) {
            case 'ctl_rc_w':
                $hub->SetVarInt('ctl_rc_w', (int)(round(max(-self::POWER_MAX_W, min(self::POWER_MAX_W, (float)$value)) / 10) * 10));
                return true;
            case 'ctl_rc_grid_charge':
                $v = (int)$value;
                if ($v < 0 || $v > 2 || ($v !== 0 && !$info['gridCharge'])) {
                    $hub->WarnUser($v < 0 || $v > 2
                        ? IHUB_Text::t('Remote control: %s is invalid for %s (0 unchanged, 1, 2).', $v, $ident)
                        : IHUB_Text::t('Remote control: %s does not support grid charging control.', $info['vendor']));
                    return true;
                }
                $hub->SetVarInt($ident, $v);
                return true;
            case 'ctl_rc_soc_min':
            case 'ctl_rc_soc_max':
                $v = max(0, min(100, (int)$value));
                $window = ($ident === 'ctl_rc_soc_min') ? $v > 0 : ($v > 0 && $v < 100);
                if ($window && !$info['socWindow']) {
                    $hub->WarnUser(IHUB_Text::t('Remote control: %s does not support an SOC window.', $info['vendor']));
                    return true;
                }
                $hub->SetVarInt($ident, $v);
                return true;
            case 'ctl_rc_failsafe_sec':
                $hub->SetVarInt($ident, max(0, min(65535, (int)$value)));
                return true;
            case 'ctl_rc_mode':
                $mode = (int)$value;
                if (!in_array($mode, $info['modes'], true)) {
                    $hub->WarnUser(IHUB_Text::t('Remote control: mode %s is not supported by %s.', $mode, $info['vendor']));
                    return true;
                }
                $req = self::requestFromHub($hub, $mode);
                $status = null;
                $cap = self::knownCapability($hub);
                if (!self::apply($a, $mb, $req, $cap, $hub->GetVarInt('ctl_rc_mode'), $err, $back)) {
                    $hub->WarnUser(IHUB_Text::t('Remote control could not be applied: %s', $err));
                    return true;
                }
                $hub->SetVarBool('rc_available', true);
                $hub->SetVarInt('ctl_rc_mode', $mode);
                $hub->SetVarInt('rc_failsafe_eff_sec', $mode === self::MODE_OFF ? 0 : $a->effectiveFailsafeSec($req));
                if ($back !== null) {
                    $hub->SetVarInt('rc_status', (int)$back['status']);
                    $hub->SetVarBool('rc_active', $a->isActive((int)$back['status']));
                }
                return true;
        }
        return false;
    }

    /** Langsamer Zyklus: Fähigkeit und Kennzeichnung in die Variablen. $cap = schon gelesene Fähigkeit oder null (dann wird gelesen). */
    public static function refresh(IHUB_RemoteAdapter $a, $mb, $hub, ?array $cap = null, bool $read = true): void
    {
        if ($cap === null && $read) {
            $cap = $a->capability($mb);
        }
        if ($cap === null) {
            return;
        }
        $info = $a->info();
        $on = !empty($cap['available']);
        $hub->SetVarBool('rc_available', $on);
        $hub->SetVarInt('rc_version', $on ? (int)$cap['version'] : 0);
        $hub->SetVarInt('rc_status', $on ? (int)$cap['status'] : 0);
        $hub->SetVarBool('rc_active', $on && $a->isActive((int)$cap['status']));
        $hub->SetVarStr('rc_vendor', $info['vendor'] . ' - ' . $info['source']);
        $hub->SetVarBool('rc_tested', (bool)$info['tested']);
        $hub->SetVarStr('rc_modes', implode(',', $info['modes']));
        // Vertragsfeld failsafeSec (1.10): Totmann, den der Adapter beim Schreiben im Gerät einstellt; 0 = keiner bekannt.
        $decl = 0;
        if ($on) {
            $modes = array_values(array_filter(array_map('intval', $info['modes']), fn($m) => $m > 0));
            $decl = $modes === [] ? 0 : max(0, $a->effectiveFailsafeSec(self::requestFromHub($hub, $modes[0])));
        }
        $hub->SetVarInt('rc_failsafe_decl_sec', $decl);
        $hub->SetVarInt('rc_rated_w', $on ? (int)($cap['ratedW'] ?? 0) : 0);
    }
}
