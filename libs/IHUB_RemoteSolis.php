<?php

// ---------------------------------------------------------------------------
// IHUB_RemoteSolis — Solis "Remote Dispatch" (Smart Control V1.0, Ginlong, 15.03.2025) als Adapter der gemeinsamen Fernsteuerung.
// Register, Blöcke und Rücklesen liegen in IHUB_SolisDispatch (seit 0.91.0, am Gerät bei Paul am 19.11.2026 geprüft: Modi 0 bis 3);
// dieser Adapter reicht sie an die Basis. Das Vorzeichen der Solis-Doku ist die einheitliche Konvention (Modus 2 + Laden,
// Modus 3 + Einspeisung), es wird nichts umgerechnet. Der Altmodus 4 (AC-Netzanschluss) bleibt dem alten Weg ctl_dispatch_mode vorbehalten.
// ---------------------------------------------------------------------------
class IHUB_RemoteSolis implements IHUB_RemoteAdapter
{
    public function info(): array
    {
        return [
            'vendor' => 'Solis', 'source' => 'Solis Smart Control V1.0 (Ginlong, 15.03.2025), registers 44100-44110',
            'tested' => true, 'modes' => [0, 1, 2, 3], 'gridCharge' => true, 'socWindow' => true,
            'failsafeMinSec' => 60, 'failsafeMaxSec' => 86400, 'failsafeDefaultSec' => 300,
            'pitfalls' => ['34504 reports status 1 in mode 3 although the mode works; only 0 means not running', 'registers are not stored in the device, the failsafe time ends the dispatch', 'never write 0xFFFF into the limits'],
        ];
    }

    public function capability($mb): ?array
    {
        return IHUB_SolisDispatch::probe($mb);
    }

    public function capabilityError(?array $cap): string
    {
        if ($cap === null) {
            return IHUB_Text::t('Solis dispatch: the device did not answer the capability query (register 34502).');
        }
        if (!$cap['available']) {
            return IHUB_Text::t('Solis dispatch: this device does not offer remote dispatch (register 34502 is not 0xAA55).');
        }
        return '';
    }

    /** Anfrage in die Form von IHUB_SolisDispatch (Minuten, Zusätze). */
    private function legacy(array $req): array
    {
        $sec = (int)($req['failsafeSec'] ?? 0);
        return [
            'mode' => (int)$req['mode'], 'watts' => (int)($req['watts'] ?? 0), 'gridCharge' => (int)($req['gridCharge'] ?? 0),
            'pvShutdown' => (int)($req['ext']['pvShutdown'] ?? 0), 'socMin' => (int)($req['socMin'] ?? 0), 'socMax' => (int)($req['socMax'] ?? 0),
            'failsafeMin' => $sec > 0 ? (int)ceil($sec / 60) : 0,
            'importLimitW' => (int)($req['ext']['importLimitW'] ?? 0), 'exportLimitW' => (int)($req['ext']['exportLimitW'] ?? 0),
        ];
    }

    public function validate(array $req, ?array $cap): string
    {
        return IHUB_SolisDispatch::validate($this->legacy($req));
    }

    public function plan(array $req, array $cap, int $last): array
    {
        $p = $this->legacy($req);
        if ($p['mode'] === IHUB_SolisDispatch::MODE_OFF) {
            return ['steps' => [['fc' => 16, 'addr' => IHUB_SolisDispatch::REG_SYSTEM, 'words' => [0], 'afterUs' => 0]]];
        }
        return ['steps' => [
            ['fc' => 16, 'addr' => IHUB_SolisDispatch::REG_SYSTEM, 'words' => IHUB_SolisDispatch::systemBlock($p), 'afterUs' => IHUB_SolisDispatch::$spacingUs],
            ['fc' => 16, 'addr' => IHUB_SolisDispatch::REG_REALTIME, 'words' => IHUB_SolisDispatch::realtimeBlock($p), 'afterUs' => 0],
        ]];
    }

    public function readback($mb, array $req, array $cap): ?array
    {
        return IHUB_SolisDispatch::readBack($mb, (int)$req['mode'] === IHUB_SolisDispatch::MODE_OFF ? 1 : 11);
    }

    public function verify(array $back, array $req, array $cap): string
    {
        $p = $this->legacy($req);
        if ($p['mode'] === IHUB_SolisDispatch::MODE_OFF) {
            return $back['regs'][0] !== 0 ? IHUB_Text::t('device acknowledged, but stored %s instead of %s', $back['regs'][0], 0) : '';
        }
        $diff = IHUB_SolisDispatch::compare($back['regs'], IHUB_SolisDispatch::systemBlock($p), IHUB_SolisDispatch::realtimeBlock($p));
        if ($diff !== '') {
            return $diff;
        }
        if ($back['status'] === 0) {
            return IHUB_Text::t('Solis dispatch: the registers hold the values, but the device does not report dispatch as running (register 34504 = 0).');
        }
        return '';
    }

    public function readbackWaitUs(): int
    {
        return IHUB_SolisDispatch::$readbackUs;
    }

    public function retryPauseUs(): int
    {
        return IHUB_SolisDispatch::$retryPauseUs;
    }

    public function isActive(int $status): bool
    {
        return $status !== 0;
    }

    public function effectiveFailsafeSec(array $req): int
    {
        return IHUB_SolisDispatch::failsafe($this->legacy($req)) * 60;
    }
}
