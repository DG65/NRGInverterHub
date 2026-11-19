<?php

// ---------------------------------------------------------------------------
// IHUB_RemoteSma — SMA-Speicher als Adapter der gemeinsamen Fernsteuerung (IHUB_RemoteControl), drei Geräteklassen mit drei Registerwegen.
// UNGETESTET: nur nach den SMA-Dokumenten und Forenberichten gebaut, an keinem Gerät geprüft (rc_tested = false).
//
//   'se'    Sunny Tripower Smart Energy (STP SE) und Sunny Boy Storage: Sollwert 40149 (S32, W) + 40151 (802 aktiv / 803 inaktiv).
//           Offiziell dokumentiert ist 40149/40151 nur als Wirkleistungsvorgabe im SB/STP-Profil (TI-en-14) und beim Sunny Island (SI-BA);
//           dass es bei STP SE den AKKU steuert, belegen nur OpenEMS (Code) und Forenberichte. Vorzeichen laut Foren: negativ = laden.
//   'si'    Sunny Island 4.4M/6.0H/8.0H im Parallelbetrieb (SI-Modbus-BA-en-12 S. 15-18, 23 f.): Fenster 44039 (max) / 44041 (min),
//           S32 mit zwei Nachkommastellen, in % der Nennleistung; positiv = Entladen. 40210 = 1079 verlangt den Grid-Guard-Code
//           und wird deshalb NIE geschrieben, nur geprüft.
//   'stpsx' Sunny Tripower Storage X / Sunny Island X (SMAModbus-STPS-X-SI-X-TI-en-11 S. 9, 13 f., 16): Fenster 44449 (max) / 44451 (min),
//           beide zusammen in einem FC16-Telegramm, sonst wie 'si'. Die Rückfallwerte FlbWMaxNom/FlbWMinNom (= 0) nennt die Doku nur als
//           Parametername ohne Adresse: sie bleiben Sache des Installateurs in der Geräteoberfläche.
//
// Totmann (alle Klassen): 41195 Timeout (s) und 41193 Verhalten bei Ausfall (2507 = Rückfallwerte verwenden). Werkseinstellung ist 2506 =
// Werte HALTEN: ohne 41193 = 2507 gibt es KEINEN Totmann. Beides sind RW-Parameter (Flash!): SMA warnt, zyklisches Ändern zerstört den
// Speicher. Deshalb liest die Fernsteuerung sie vor dem Schreiben (prepare) und schreibt nur, was abweicht. Die Sollwerte selbst sind
// WO (flüchtig) und lesen sich als NaN zurück: sie lassen sich NICHT rücklesen, geprüft wird die Konfiguration.
//
// Vorzeichen einheitlich: Modus 2 + Laden / − Entladen. Gerät 'se': 40149 = −Leistung (negativ = laden). 'si'/'stpsx': Prozent von der
// Nennleistung (33019, S32, negativ), Entladen positiv, also Gerät = −Leistung / Nennleistung; Höchst- und Mindestwert werden gleich gesetzt.
// Modus 0: 'se' schreibt 40151 = 803; 'si'/'stpsx' öffnen das Fenster auf −100 % bis +100 %, 'si' schaltet zusätzlich die Eigenverbrauchs-
// erhöhung 40075 wieder ein (sonst lädt der Sunny Island nach dem Timeout den Akku voll, SI-BA S. 18).
// ---------------------------------------------------------------------------
class IHUB_RemoteSma implements IHUB_RemoteAdapter, IHUB_RemotePreparing
{
    const FAMILY_SE = 'se';
    const FAMILY_SI = 'si';
    const FAMILY_STPSX = 'stpsx';

    const REG_SETPOINT = 40149;     // se: S32 W, WO
    const REG_CONTROL = 40151;      // se: U32 802/803, WO
    const REG_MODE = 40210;         // U32, 1079 = externer Sollwert
    const REG_FALLBACK = 41193;     // U32, 2506 halten / 2507 Rückfallwerte
    const REG_TIMEOUT = 41195;      // U32 s
    const REG_RAMP = 44029;         // U32, 303 aus
    const REG_SELF = 40075;         // si: U32, 1129 Eigenverbrauchserhöhung ein / 1130 aus
    const REG_NOMINAL = 33019;      // S32 W (negativ), Nennleistung der Aufnahme
    const MODE_EXTERNAL = 1079;
    const FALLBACK_APPLY = 2507;
    const RAMP_OFF = 303;
    const SELF_ON = 1129;
    const SELF_OFF = 1130;
    const SP_ACTIVE = 802;
    const SP_INACTIVE = 803;
    const NAN_U32 = 0xFFFFFFFF;
    const NAN_S32 = 0x80000000;
    const TIMEOUT_DEFAULT_S = 60;
    const TIMEOUT_MIN_S = 5;
    const TIMEOUT_MAX_S = 1800;
    const PCT_FULL = 10000;         // 100 % mit zwei Nachkommastellen

    public static $gapUs = 600000;          // SMA: höchstens ein Sollwert alle 500 ms (SI-BA S. 17)
    public static $readbackUs = 1200000;    // etwa 1 s Reaktionszeit je Parameter (ennexOS-TI)
    public static $retryPauseUs = 1500000;

    private $family;

    public function __construct(string $family = self::FAMILY_SE)
    {
        $this->family = in_array($family, [self::FAMILY_SE, self::FAMILY_SI, self::FAMILY_STPSX], true) ? $family : self::FAMILY_SE;
    }

    /** SmaControlType der Instanz (0 STP SE/SBS, 1 Sunny Island, 2 STPS X / SI X) auf die Geräteklasse. */
    public static function familyOf(int $type): string
    {
        return [0 => self::FAMILY_SE, 1 => self::FAMILY_SI, 2 => self::FAMILY_STPSX][$type] ?? self::FAMILY_SE;
    }

    private function window(): bool
    {
        return $this->family !== self::FAMILY_SE;
    }

    private function winMax(): int
    {
        return $this->family === self::FAMILY_STPSX ? 44449 : 44039;
    }

    public function info(): array
    {
        $names = [self::FAMILY_SE => 'SMA STP SE', self::FAMILY_SI => 'SMA Sunny Island', self::FAMILY_STPSX => 'SMA STPS X'];
        $sources = [
            self::FAMILY_SE => 'SMA Modbus-Profil SB/STP TI-en-14 (40149/40151), OpenEMS and forum reports (battery use not in an SMA document)',
            self::FAMILY_SI => 'SMA SI-Modbus-BA-en-12 (parallel grid operation, registers 44039/44041)',
            self::FAMILY_STPSX => 'SMA SMAModbus-STPS-X-SI-X-TI-en-11 (registers 44449/44451)',
        ];
        $pit = [
            'untested: built from SMA documents and forum reports only',
            'the failsafe is OFF from the factory (41193 = 2506 keep values): it is set to 2507 and read back, otherwise there is no failsafe',
            'registers 41193, 41195, 44029 and 40075 are stored in flash: they are read first and only written when they differ',
            'the setpoint registers are write-only and read back as NaN: only the configuration is verified, not the setpoint itself',
            'send a new setpoint at most every 500 ms',
        ];
        if ($this->family === self::FAMILY_SE) {
            $pit[] = 'battery control through 40149/40151 on the STP SE is documented only by OpenEMS and forum users; the sign (negative = charging) is from forum reports';
            $pit[] = 'forum reports: the timeout only acts on 40151, a firmware update reset it to 30 minutes, and ending the setpoint can cause a short charging peak';
            $pit[] = 'the behaviour of the battery after the timeout (fallback values) is not documented for the STP SE';
            $pit[] = 'a Sunny Home Manager 2.0 or predictive charging in the Sunny Portal may overwrite the settings';
        } elseif ($this->family === self::FAMILY_SI) {
            $pit[] = 'register 40210 = 1079 needs the Grid Guard code: it is only checked, never written. Set it in the device user interface';
            $pit[] = 'the percentages refer to the rated power of the inverter (register 33019): without it only hold and off are possible';
            $pit[] = 'without new setpoints the Sunny Island fully charges the battery after the timeout, unless 0 % is sent or self-consumption is switched on again';
            $pit[] = 'watch the discharge limit (register 31009): the battery may not be able to discharge at the current state of charge';
        } else {
            $pit[] = 'the energy management of the System Manager must be switched off; SMA gives no warranty for battery or inverter damage then';
            $pit[] = 'the fallback values FlbWMaxNom and FlbWMinNom (= 0) have no register address in the document: set them in the device user interface';
            $pit[] = 'both limits 44449 and 44451 are written together in one telegram';
        }
        return [
            'vendor' => $names[$this->family], 'source' => $sources[$this->family],
            'tested' => false, 'modes' => [0, 1, 2], 'gridCharge' => false, 'socWindow' => false,
            'failsafeMinSec' => self::TIMEOUT_MIN_S, 'failsafeMaxSec' => self::TIMEOUT_MAX_S, 'failsafeDefaultSec' => self::TIMEOUT_DEFAULT_S,
            'pitfalls' => $pit,
        ];
    }

    private function readU32($mb, int $reg): ?int
    {
        $r = $mb->readInput($reg, 2);
        if ($r === null || count($r) < 2) {
            return null;
        }
        return (int)$mb->u32($r, 0);
    }

    public function capability($mb): ?array
    {
        $mode = $this->readU32($mb, self::REG_MODE);
        if ($mode === null) {
            return null;
        }
        if ($mode === self::NAN_U32) {
            return ['available' => false, 'version' => 0, 'status' => 0];
        }
        $cap = ['available' => true, 'version' => 1, 'status' => $mode === self::MODE_EXTERNAL ? 1 : 0];
        if ($this->window()) {
            $r = $mb->readInput(self::REG_NOMINAL, 2);
            if ($r !== null && count($r) >= 2) {
                $w = (int)$mb->s32($r, 0);
                if ($w !== -2147483648 && $w !== 0) {
                    $cap['ratedW'] = abs($w);
                }
            }
        }
        return $cap;
    }

    /** Konfiguration lesen, damit nur Abweichendes geschrieben wird (Flash). Ohne Antwort fehlt 'cfg', dann schreibt der Plan alles. */
    public function prepare($mb, array $cap): array
    {
        $mode = $this->readU32($mb, self::REG_MODE);
        $fb = $mb->readInput(self::REG_FALLBACK, 4);       // 41193-41196: Ausfallverhalten und Timeout
        $ramp = $this->readU32($mb, self::REG_RAMP);
        if ($mode === null || $fb === null || count($fb) < 4 || $ramp === null) {
            return $cap;
        }
        $cfg = ['mode' => $mode, 'fallback' => (int)$mb->u32($fb, 0), 'timeout' => (int)$mb->u32($fb, 2), 'ramp' => $ramp];
        if ($this->family === self::FAMILY_SI) {
            $self = $this->readU32($mb, self::REG_SELF);
            if ($self === null) {
                return $cap;
            }
            $cfg['self'] = $self;
        }
        $cap['cfg'] = $cfg;
        return $cap;
    }

    public function capabilityError(?array $cap): string
    {
        if ($cap === null) {
            return IHUB_Text::t('SMA remote control: the device did not answer register 40210.');
        }
        return $cap['available'] ? '' : IHUB_Text::t('SMA remote control: this device does not offer the external setpoint (register 40210 is not valid).');
    }

    public function validate(array $req, ?array $cap): string
    {
        $mode = (int)$req['mode'];
        if (!in_array($mode, [0, 1, 2], true)) {
            return IHUB_Text::t('SMA remote control: mode %s is not offered (0 off, 1 hold, 2 battery).', $mode);
        }
        if ($mode !== 0 && $this->family === self::FAMILY_SI && isset($cap['cfg']) && $cap['cfg']['mode'] !== self::MODE_EXTERNAL) {
            return IHUB_Text::t('SMA remote control: register 40210 is %s, it must be 1079 (external setpoint). The Sunny Island needs the Grid Guard code for that: set it in the device user interface.', $cap['cfg']['mode']);
        }
        if ($mode === 2 && $this->window() && (int)($cap['ratedW'] ?? 0) <= 0) {
            return IHUB_Text::t('SMA remote control: the rated power (register 33019) is unknown, the power cannot be converted to a percentage.');
        }
        return '';
    }

    private function timeout(array $req): int
    {
        $s = (int)($req['failsafeSec'] ?? 0);
        return $s <= 0 ? self::TIMEOUT_DEFAULT_S : max(self::TIMEOUT_MIN_S, min(self::TIMEOUT_MAX_S, $s));
    }

    /** Zwei Worte (hohes zuerst) für einen 32-Bit-Wert mit Vorzeichen. */
    public static function words(int $v): array
    {
        $u = $v < 0 ? $v + 4294967296 : $v;
        return [($u >> 16) & 0xFFFF, $u & 0xFFFF];
    }

    /** Leistung fürs Gerät 'se': einheitlich + Laden, Gerät negativ = laden. */
    public static function deviceWatts(int $mode, int $watts): int
    {
        return $mode === 2 ? -$watts : 0;
    }

    /** Prozent für das Fenster ('si'/'stpsx'): zwei Nachkommastellen, Entladen positiv, auf ±100 % begrenzt. */
    public static function devicePercent(int $mode, int $watts, int $ratedW): int
    {
        if ($mode !== 2 || $ratedW <= 0) {
            return 0;
        }
        return max(-self::PCT_FULL, min(self::PCT_FULL, (int)round(-$watts / $ratedW * self::PCT_FULL)));
    }

    /** Gewünschte Konfiguration je Modus (nur die Register, die dieser Adapter schreiben darf). */
    private function wantedConfig(array $req): array
    {
        $want = ['fallback' => self::FALLBACK_APPLY, 'timeout' => $this->timeout($req)];
        if ($this->family !== self::FAMILY_SI) {
            $want['mode'] = self::MODE_EXTERNAL;       // beim Sunny Island nur geprüft (Grid Guard)
        }
        if ($this->window()) {
            $want['ramp'] = self::RAMP_OFF;
        }
        if ($this->family === self::FAMILY_SI) {
            $want['self'] = self::SELF_OFF;
        }
        return $want;
    }

    public function plan(array $req, array $cap, int $last): array
    {
        $mode = (int)$req['mode'];
        $cfg = $cap['cfg'] ?? null;
        $steps = [];
        if ($mode === 0) {
            if ($this->family === self::FAMILY_SE) {
                return ['steps' => [['fc' => 16, 'addr' => self::REG_CONTROL, 'words' => [0, self::SP_INACTIVE], 'afterUs' => 0]]];
            }
            $steps[] = ['fc' => 16, 'addr' => $this->winMax(), 'words' => array_merge(self::words(self::PCT_FULL), self::words(-self::PCT_FULL)), 'afterUs' => self::$gapUs];
            if ($this->family === self::FAMILY_SI && ($cfg === null || $cfg['self'] !== self::SELF_ON)) {
                $steps[] = ['fc' => 16, 'addr' => self::REG_SELF, 'words' => [0, self::SELF_ON], 'afterUs' => 0];
            }
            return ['steps' => $steps];
        }
        $want = $this->wantedConfig($req);
        $have = $cfg ?? [];
        if ($cfg === null && $last > 0) {
            $have = $want;           // Konfiguration unbekannt, aber schon in dieser Sitzung gesetzt: nicht erneut ins Flash schreiben
        }
        if (isset($want['mode']) && ($have['mode'] ?? null) !== $want['mode']) {
            $steps[] = ['fc' => 16, 'addr' => self::REG_MODE, 'words' => [0, $want['mode']], 'afterUs' => self::$gapUs];
        }
        if (($have['fallback'] ?? null) !== $want['fallback'] || ($have['timeout'] ?? null) !== $want['timeout']) {
            $steps[] = ['fc' => 16, 'addr' => self::REG_FALLBACK, 'words' => [0, $want['fallback'], 0, $want['timeout']], 'afterUs' => self::$gapUs];
        }
        if (isset($want['ramp']) && ($have['ramp'] ?? null) !== $want['ramp']) {
            $steps[] = ['fc' => 16, 'addr' => self::REG_RAMP, 'words' => [0, $want['ramp']], 'afterUs' => self::$gapUs];
        }
        if (isset($want['self']) && ($have['self'] ?? null) !== $want['self']) {
            $steps[] = ['fc' => 16, 'addr' => self::REG_SELF, 'words' => [0, $want['self']], 'afterUs' => self::$gapUs];
        }
        if ($this->family === self::FAMILY_SE) {
            $w = self::deviceWatts($mode, (int)($req['watts'] ?? 0));
            $steps[] = ['fc' => 16, 'addr' => self::REG_SETPOINT, 'words' => array_merge(self::words($w), [0, self::SP_ACTIVE]), 'afterUs' => 0];
        } else {
            $p = self::devicePercent($mode, (int)($req['watts'] ?? 0), (int)($cap['ratedW'] ?? 0));
            $steps[] = ['fc' => 16, 'addr' => $this->winMax(), 'words' => array_merge(self::words($p), self::words($p)), 'afterUs' => 0];
        }
        return ['steps' => $steps];
    }

    public function readback($mb, array $req, array $cap): ?array
    {
        $c = $this->prepare($mb, ['available' => true])['cfg'] ?? null;
        if ($c === null) {
            return null;
        }
        $regs = [$c['mode'], $c['fallback'], $c['timeout'], $c['ramp'], $c['self'] ?? 0];
        $ok = $this->configOk($c, $req);
        return ['regs' => $regs, 'status' => $ok ? 1 : 0, 'cfg' => $c];
    }

    /** Steht die Konfiguration so im Gerät, wie sie für den Modus nötig ist? */
    private function configOk(array $c, array $req): bool
    {
        return $this->configDiff($c, $req) === '';
    }

    private function configDiff(array $c, array $req): string
    {
        $mode = (int)$req['mode'];
        if ($mode === 0) {
            return ($this->family === self::FAMILY_SI && ($c['self'] ?? 0) !== self::SELF_ON) ? $this->diff($c['self'] ?? 0, self::SELF_ON, self::REG_SELF) : '';
        }
        $checks = [
            [self::REG_MODE, $c['mode'], self::MODE_EXTERNAL],
            [self::REG_FALLBACK, $c['fallback'], self::FALLBACK_APPLY],
            [self::REG_TIMEOUT, $c['timeout'], $this->timeout($req)],
        ];
        if ($this->window()) {
            $checks[] = [self::REG_RAMP, $c['ramp'], self::RAMP_OFF];
        }
        if ($this->family === self::FAMILY_SI) {
            $checks[] = [self::REG_SELF, $c['self'] ?? 0, self::SELF_OFF];
        }
        foreach ($checks as [$reg, $got, $want]) {
            if ($got !== $want) {
                return $this->diff($got, $want, $reg);
            }
        }
        return '';
    }

    private function diff(int $got, int $want, int $reg): string
    {
        return IHUB_Text::t('device acknowledged, but stored %s instead of %s (register %s)', $got, $want, $reg);
    }

    public function verify(array $back, array $req, array $cap): string
    {
        return $this->configDiff($back['cfg'], $req);
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
        return $status === 1;
    }

    public function effectiveFailsafeSec(array $req): int
    {
        return $this->timeout($req);
    }
}
