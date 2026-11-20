<?php
/**
 * Mutationsproben für die gemeinsame Fernsteuerung (Basis und Adapter Solis, FoxESS, Deye, SolaX, SMA): jede Mutation baut einen Fehler in eine Kopie des Moduls (InverterHub/, libs/,
 * .tools/) ein; .tools/test-remote-control.php muss anschlagen.   php .tools/mutate-remote-control.php   (0 = alle erkannt)
 */
$root = dirname(__DIR__);
$B = 'libs/IHUB_RemoteControl.php'; $F = 'libs/IHUB_RemoteFoxEss.php'; $Y = 'libs/IHUB_RemoteDeye.php'; $X1 = 'libs/IHUB_RemoteSolax.php'; $S = 'libs/IHUB_RemoteSolis.php'; $SM = 'libs/IHUB_RemoteSma.php'; $FR = 'libs/IHUB_RemoteFronius.php'; $KO = 'libs/IHUB_RemoteKostal.php'; $HW = 'libs/IHUB_RemoteHuawei.php'; $HD = 'libs/IHUB_HuaweiDriver.php'; $SG = 'libs/IHUB_RemoteSungrow.php'; $SGD = 'libs/IHUB_SungrowDriver.php'; $VI = 'libs/IHUB_RemoteVictron.php'; $VD = 'libs/IHUB_VictronDriver.php'; $KD = 'libs/IHUB_KostalDriver.php'; $FD = 'libs/IHUB_FroniusDriver.php'; $SD = 'libs/IHUB_SmaDriver.php'; $M = 'InverterHub/module.php';
$X = [
    // Basis
    [$B, [["if (\$steps === []) {", 'if (false) {']], 'leere Schrittliste wird nicht als erfüllt behandelt'],
    [$B, [['$bad = $a->capabilityError($cap);', '$bad = "";']], 'Fähigkeit wird nicht geprüft'],
    [$B, [['$bad = $a->validate($req, $cap);', '$bad = "";']], 'Anfrage wird nicht geprüft'],
    [$B, [['for ($try = 0; $try < 2; $try++) {', 'for ($try = 0; $try < 1; $try++) {']], 'kein zweiter Gesamtversuch'],
    [$B, [['continue 2;', 'continue;']], 'nach einem Schreibfehler geht die Reihenfolge weiter'],
    [$B, [['$diff = $a->verify($back, $req, $cap);', '$diff = "";']], 'Rücklesen wird nicht verglichen'],
    [$B, [['max(-self::POWER_MAX_W, min(self::POWER_MAX_W, (float)$value))', '(float)$value']], 'Leistung nicht begrenzt'],
    [$B, [["(\$v !== 0 && !\$info['gridCharge'])", 'false']], 'Netzladen wird bei Geräten ohne Netzladen nicht abgelehnt'],
    [$B, [["if (\$window && !\$info['socWindow']) {", 'if (false) {']], 'SOC-Fenster wird bei Geräten ohne Fenster nicht abgelehnt'],
    [$B, [["\$hub->GetVarInt('ctl_rc_mode'), \$err, \$back", '0, $err, $back']], 'letzter Modus wird dem Adapter nicht übergeben'],
    [$B, [["\$hub->SetVarInt('ctl_rc_mode', \$mode);", '']], 'Modus wird nach Erfolg nicht gemerkt'],
    [$B, [['if ($ver <= 0) {', 'if (true) {']], 'Fähigkeit wird nie aus dem Zyklus übernommen'],
    [$B, [["\$hub->SetVarBool('rc_available', \$on);", "\$hub->SetVarBool('rc_available', true);"]], 'rc_available immer true'],
    [$B, [["\$hub->SetVarBool('rc_tested', (bool)\$info['tested']);", "\$hub->SetVarBool('rc_tested', true);"]], 'rc_tested immer true'],
    [$B, [["\$hub->SetVarStr('rc_modes', implode(',', \$info['modes']));", '']], 'rc_modes fehlt'],
    // FoxESS
    [$F, [['return 1 | (($mode === 3 ? 2 : 1) << 2);', 'return 1 | (($mode === 3 ? 1 : 2) << 2);']], 'FoxESS: Ziele Akku und Netz vertauscht'],
    [$F, [['return $mode === 2 ? -$w : $w;', 'return $w;']], 'FoxESS: Vorzeichen für Modus 2 nicht gedreht'],
    [$F, [["['fc' => 6, 'addr' => self::REG_TIMEOUT, 'words' => [\$this->timeout(\$req)], 'afterUs' => self::\$gapUs],", '']], 'FoxESS: Totmann wird nicht gesetzt'],
    [$F, [['const TIMEOUT_DEFAULT_S = 60;', 'const TIMEOUT_DEFAULT_S = 600;']], 'FoxESS: Totmann-Vorgabe geändert'],
    [$F, [['$last === 0 ? [] :', 'false ? [] :']], 'FoxESS: Fernsteuerung wird auch ausgeschaltet, wenn wir sie nicht eingeschaltet haben'],
    [$F, [["if (\$back['status'] <= 0) {", 'if (false) {']], 'FoxESS: Restzeit 0 gilt nicht als Fehlschlag'],
    [$F, [['return [($u >> 16) & 0xFFFF, $u & 0xFFFF];', 'return [$u & 0xFFFF, ($u >> 16) & 0xFFFF];']], 'FoxESS: I32 niederwertiges Wort zuerst'],
    [$F, [["if (!in_array(\$mode, [0, 2, 3], true)) {", 'if (false) {']], 'FoxESS: Modus 1 wird nicht abgelehnt'],
    [$F, [["['fc' => 6, 'addr' => self::REG_CONTROL, 'words' => [self::controlWord(\$mode)]", "['fc' => 16, 'addr' => self::REG_CONTROL, 'words' => [self::controlWord(\$mode)]"]], 'FoxESS: Enable mit FC16 statt FC06'],
    // Deye
    [$Y, [['$v = (int)round(-$watts / $ratedW * 1000);', '$v = (int)round(-$watts / $ratedW * 100);']], 'Deye: falsche Skalierung (nicht 0,1 %)'],
    [$Y, [['$v = (int)round(-$watts / $ratedW * 1000);', '$v = (int)round($watts / $ratedW * 1000);']], 'Deye: Vorzeichen nicht gedreht'],
    [$Y, [["['fc' => 16, 'addr' => self::REG_MODE, 'words' => [1], 'afterUs' => 0],", '']], 'Deye: Fernmodus wird nicht eingeschaltet'],
    [$Y, [['max(self::WATCHDOG_MIN_S, min(self::WATCHDOG_MAX_S, $s))', '$s']], 'Deye: Watchdog nicht begrenzt'],
    [$Y, [['const WATCHDOG_DEFAULT_S = 60;', 'const WATCHDOG_DEFAULT_S = 600;']], 'Deye: Watchdog-Vorgabe geändert'],
    [$Y, [['if ($mode !== 2 || $ratedW <= 0) {', 'if ($ratedW <= 0) {']], 'Deye: Halten schreibt die gemerkte Leistung'],
    [$Y, [["if (\$mode === 2 && (int)(\$cap['ratedW'] ?? 0) <= 0) {", 'if (false) {']], 'Deye: unbekannte Nennleistung wird nicht abgelehnt'],
    [$Y, [["['fc' => 16, 'addr' => self::REG_WATCHDOG,", "['fc' => 6, 'addr' => self::REG_WATCHDOG,"]], 'Deye: FC06 statt FC16'],
    [$Y, [['if ($got !== $want) {', 'if (false) {']], 'Deye: Rücklesen vergleicht nichts'],
    [$Y, [["if (!in_array(\$mode, [0, 1, 2], true)) {", 'if (false) {']], 'Deye: Modus 3 wird nicht abgelehnt'],
    // SolaX
    [$X1, [['return -max(-self::POWER_MAX_W, min(self::POWER_MAX_W, $watts));', 'return max(-self::POWER_MAX_W, min(self::POWER_MAX_W, $watts));']], 'SolaX: Vorzeichen der Akkuleistung nicht gedreht'],
    [$X1, [['return [$u & 0xFFFF, ($u >> 16) & 0xFFFF];', 'return [($u >> 16) & 0xFFFF, $u & 0xFFFF];']], 'SolaX: S32 hochwertiges Wort zuerst'],
    [$X1, [["if (\$mode !== 2) {\n            return 0;", "if (false) {\n            return 0;"]], 'SolaX: Halten schreibt die gemerkte Leistung'],
    [$X1, [['max(self::DURATION_MIN_S, min(self::DURATION_MAX_S, $s))', '$s']], 'SolaX: Dauer nicht begrenzt'],
    [$X1, [['const NEXT_USER_MODE = 0xA0;', 'const NEXT_USER_MODE = 0xA1;']], 'SolaX: 0xA7 / Timeout geändert'],
    [$X1, [["['fc' => 16, 'addr' => self::REG_OLD, 'words' => array_fill(0, 13, 0), 'afterUs' => 0],", '']], 'SolaX: Modus 0 schreibt die alte Schnittstelle nicht'],
    [$X1, [['if ($got !== $want) {', 'if (false) {']], 'SolaX: Rücklesen vergleicht nichts'],
    [$X1, [["if (!in_array(\$mode, [0, 1, 2], true)) {", 'if (false) {']], 'SolaX: Modus 3 wird nicht abgelehnt'],
    [$X1, [['const PV_UNLIMITED_W = 100000;', 'const PV_UNLIMITED_W = 1000;']], 'SolaX: PV-Grenze zu klein (drosselt die PV)'],
    [$X1, [['return $this->duration($req);', 'return $this->duration($req) + 1;']], 'SolaX: Totmann im Gerät falsch gemeldet'],
    // Huawei
    [$HW, [['(int)ceil($s / 60)', '(int)floor($s / 60)']], 'Huawei: Dauer wird abgerundet'],
    [$HW, [['$cmd = $charge ? 1 : 2;', '$cmd = $charge ? 2 : 1;']], 'Huawei: Richtung vertauscht'],
    [$HW, [['$w16($charge ? self::R_FP_C : self::R_FP_D, $this->u32w($p), self::$gapUs)', '$w16($charge ? self::R_FP_D : self::R_FP_C, $this->u32w($p), self::$gapUs)']], 'Huawei: Lade- und Entladeleistungsregister vertauscht'],
    [$HW, [["if ((\$h['limC'] ?? null) !== 0) {", 'if (false) {']], 'Huawei: Halten sperrt die Ladegrenze nicht'],
    [$HW, [["if (isset(\$h['limC']) && \$h['limC'] === 0 && \$maxC > 0) {", 'if (false) {']], 'Huawei: Modus 0 hebt die Hold-Sperre nicht auf'],
    [$HW, [["if (isset(\$h['cmd']) && \$h['cmd'] !== 0 && \$h['cmd'] !== \$cmd) {", 'if (false) {']], 'Huawei: Richtungswechsel ohne vorheriges Stoppen'],
    [$HW, [['min($watts, $maxC)', '$watts']], 'Huawei: Ladeleistung nicht auf das Systemmaximum begrenzt'],
    [$HW, [['max(self::MIN_MIN, min(self::MIN_MAX, (int)ceil($s / 60)))', '(int)ceil($s / 60)']], 'Huawei: Dauer nicht begrenzt'],
    [$HW, [["foreach (\$checks as [\$reg, \$got, \$want]) {\n            if (\$got !== \$want) {", "foreach (\$checks as [\$reg, \$got, \$want]) {\n            if (false) {"]], 'Huawei: Rücklesen vergleicht nichts'],
    [$HW, [["if (isset(\$cap['model']) && (int)\$cap['model'] === 0) {", 'if (false) {']], 'Huawei: fehlende Batterie (47000 = 0) wird nicht abgelehnt'],
    [$HW, [['if ($charge && $gc === 1) {', 'if (false) {']], 'Huawei: Netzladen wird nie freigegeben'],
    [$HW, [['return $this->effMode($req) === 2 ? $this->minutes($req) * 60 : 0;', 'return $this->minutes($req) * 60;']], 'Huawei: Halten meldet einen Totmann'],
    [$HW, [["if (!in_array(\$mode, [0, 1, 2], true)) {", 'if (false) {']], 'Huawei: Modus 3 wird nicht abgelehnt'],
    [$HD, [["'default' => false, 'vars' => IHUB_RemoteControl::vars()", "'default' => true, 'vars' => IHUB_RemoteControl::vars()"]], 'Huawei-Treiber: Gruppe Steuerung startet an'],
    // Sungrow
    [$SG, [["\$steps[] = ['fc' => 16, 'addr' => self::R_HB + \$off, 'words' => [\$this->timeout(\$req)], 'afterUs' => 0];", '']], 'Sungrow: Heartbeat wird nie geschrieben'],
    [$SG, [["if (\$cfg === null ? \$last === 0 : (\$h['mode'] !== self::MODE_EXTERNAL)) {", 'if (true) {']], 'Sungrow: 13050 (Flash) wird in jedem Zyklus geschrieben'],
    [$SG, [['return self::isType((int)$mb->u16($r, 0)) ? -1 : null;', 'return self::isType((int)$mb->u16($r, 0)) ? 0 : null;']], 'Sungrow: Adressversatz −1 wird nicht erkannt'],
    [$SG, [['return [$watts > 0 ? self::CMD_CHARGE : self::CMD_DISCHARGE, min($p, 0xFFFF)];', 'return [$watts > 0 ? self::CMD_DISCHARGE : self::CMD_CHARGE, min($p, 0xFFFF)];']], 'Sungrow: Lade- und Entladebefehl vertauscht'],
    [$SG, [['$p = min($p, $ratedW);', '$p = $p;']], 'Sungrow: Leistung nicht auf die BDC-Nennleistung begrenzt'],
    [$SG, [['max(self::TIMEOUT_MIN_S, min(self::TIMEOUT_MAX_S, $s))', '$s']], 'Sungrow: Heartbeat nicht begrenzt'],
    [$SG, [["[\$cmd, \$p] = self::command(\$mode, (int)(\$req['watts'] ?? 0), (int)(\$cap['ratedW'] ?? 0));\n        foreach ([[self::R_MODE, \$c['mode'], self::MODE_EXTERNAL], [self::R_CMD, \$c['cmd'], \$cmd], [self::R_POWER, \$c['power'], \$p], [self::R_HB, \$c['hb'], \$this->timeout(\$req)]] as [\$reg, \$got, \$want]) {\n            if (\$got !== \$want) {", "[\$cmd, \$p] = self::command(\$mode, (int)(\$req['watts'] ?? 0), (int)(\$cap['ratedW'] ?? 0));\n        foreach ([[self::R_MODE, \$c['mode'], self::MODE_EXTERNAL], [self::R_CMD, \$c['cmd'], \$cmd], [self::R_POWER, \$c['power'], \$p], [self::R_HB, \$c['hb'], \$this->timeout(\$req)]] as [\$reg, \$got, \$want]) {\n            if (false) {"]], 'Sungrow: Rücklesen vergleicht nichts'],
    [$SG, [['|| $v === 0xE03 ||', '|| $v === 0xE04 ||']], 'Sungrow: Gerätetypcode 0xE03 (SH10RT) nicht erkannt'],
    [$SG, [["'words' => [0, self::CMD_STOP, 0]", "'words' => [0, self::CMD_CHARGE, 0]"]], 'Sungrow: Modus 0 lässt den Lade-Befehl stehen'],
    [$SG, [["if (!in_array(\$mode, [0, 1, 2], true)) {", 'if (false) {']], 'Sungrow: Modus 3 wird nicht abgelehnt'],
    [$SG, [["(int)(\$cap['ratedW'] ?? 0) <= 0", 'false']], 'Sungrow: unbekannte Nennleistung wird nicht abgelehnt'],
    [$SG, [['return ($status & 0x4000) !== 0 || ($status & 0x0800) !== 0;', 'return true;']], 'Sungrow: immer aktiv gemeldet'],
    [$SGD, [["'default' => false, 'vars' => IHUB_RemoteControl::vars()", "'default' => true, 'vars' => IHUB_RemoteControl::vars()"]], 'Sungrow-Treiber: Gruppe Fernsteuerung startet an'],
    // Victron
    [$VI, [['return self::clampRound(-$watts);', 'return self::clampRound($watts);']], 'Victron: Vorzeichen des Overrides nicht gedreht'],
    [$VI, [["if (\$mode !== 0 && (int)(\$cap['dess'] ?? 0) === 1) {", 'if (false) {']], 'Victron: Dynamic ESS wird nicht abgelehnt'],
    [$VI, [["if (\$mode !== 0 && isset(\$cap['hub4']) && (int)\$cap['hub4'] === 3) {", 'if (false) {']], 'Victron: ESS-Modus 3 (externe Steuerung) wird nicht abgelehnt'],
    [$VI, [["if (!isset(\$cap['grid']) || (\$cap['override'] ?? null) === \$cap['grid']) {", 'if (false) {']], 'Victron: Modus 0 schreibt immer, auch ohne lesbaren Netzsollwert'],
    [$VI, [["'words' => self::words((int)\$cap['grid'])", "'words' => self::words(0)"]], 'Victron: Modus 0 schreibt 0 statt den Netzsollwert zu spiegeln'],
    [$VI, [["return (int)\$req['mode'] !== 0 ? self::FAILSAFE_S : 0;", 'return 0;']], 'Victron: Totmann nicht gemeldet'],
    [$VI, [["if (abs(\$back['override'] - \$want) > 1) {", 'if (false) {']], 'Victron: Rücklesen vergleicht nichts'],
    [$VI, [["if (!in_array(\$mode, [0, 1, 2, 3], true)) {", 'if (false) {']], 'Victron: ungültiger Modus wird nicht abgelehnt'],
    [$VI, [['return $status !== 0;', 'return true;']], 'Victron: immer aktiv gemeldet'],
    [$VI, [['/ 10) * 10);', '/ 1) * 1);']], 'Victron: keine Rundung auf 10 W'],
    [$VI, [["\$ff = \$target + \$flow['load'] - \$flow['pv'];", "\$ff = \$target - \$flow['load'] + \$flow['pv'];"]], 'Victron: Vorsteuerung mit falschem Vorzeichen'],
    [$VI, [["\$fb = \$flow['grid'] + (\$target - \$flow['bat']);", "\$fb = \$flow['grid'] + (\$flow['bat'] - \$target);"]], 'Victron: Rückführung mit falschem Vorzeichen'],
    [$VI, [["return self::clampRound(max(\$ff - self::BAND_W, min(\$ff + self::BAND_W, \$fb)));", "return self::clampRound(\$fb);"]], 'Victron: kein Anti-Windup (Rückführung unbegrenzt)'],
    [$VI, [["if (in_array(\$mode, [1, 2], true) && !isset(\$cap['flow'])) {", 'if (false) {']], 'Victron: Halten ohne Messwerte wird nicht abgelehnt'],
    [$VI, [["'pv' => \$pvAc + (int)\$mb->u16(\$pd, 0),", "'pv' => \$pvAc,"]], 'Victron: DC-gekoppelte PV (850) bleibt unberücksichtigt'],
    [$VD, [["'default' => false, 'vars' => IHUB_RemoteControl::vars()", "'default' => true, 'vars' => IHUB_RemoteControl::vars()"]], 'Victron-Treiber: Gruppe Fernsteuerung startet an'],
    [$VD, [["            \$mb->unitId = self::UNIT_SYSTEM;\n            try {", "            try {"]], 'Victron-Treiber: Steuerbefehl nicht auf Unit 100'],
    // Kostal
    [$KO, [['$w = (float)-$watts;', '$w = (float)$watts;']], 'Kostal: Vorzeichen des Sollwerts nicht gedreht'],
    [$KO, [['$w = max($w, -$maxCharge);', '$w = $w;']], 'Kostal: Ladeleistung nicht auf die Akkugrenze begrenzt'],
    [$KO, [['$w = min($w, $maxDischarge);', '$w = $w;']], 'Kostal: Entladeleistung nicht auf die Akkugrenze begrenzt'],
    [$KO, [["if (\$mode !== 2) {\n            return 0.0;", "if (false) {\n            return 0.0;"]], 'Kostal: Halten schreibt die gemerkte Leistung'],
    [$KO, [["return ['steps' => []];     // nur aufhören", "return ['steps' => [['fc' => 16, 'addr' => self::REG_SETPOINT, 'words' => [0, 0], 'afterUs' => 0]]];     // nur aufhören"]], 'Kostal: Modus 0 schreibt einen Sollwert (hält das Gerät unter externer Regelung)'],
    [$KO, [['(bool)($cap[\'swap\'] ?? false)', 'false']], 'Kostal: Wortreihenfolge der Instanz ignoriert'],
    [$KO, [["if (\$mode !== 0 && isset(\$cap['mode']) && (int)\$cap['mode'] !== self::MODE_MODBUS) {", 'if (false) {']], 'Kostal: 1080 wird vor dem Schreiben nicht geprüft'],
    [$KO, [["if (\$mode !== self::MODE_MODBUS) {\n            return ['available' => false", "if (false) {\n            return ['available' => false"]], 'Kostal: Fähigkeit ignoriert 1080'],
    [$KO, [['if ($back[\'status\'] !== self::MODE_MODBUS) {', 'if (false) {']], 'Kostal: verlorenes externes Management wird nicht erkannt'],
    [$KO, [['if (abs($back[\'sp\'] - $want) > self::TOLERANCE_W) {', 'if (false) {']], 'Kostal: Rücklesen des Sollwerts vergleicht nichts'],
    [$KO, [['const REG_SETPOINT = 1034;', 'const REG_SETPOINT = 1026;']], 'Kostal: falsches Sollwertregister'],
    [$KO, [['return $status === self::MODE_MODBUS;', 'return true;']], 'Kostal: immer aktiv gemeldet'],
    [$KO, [["if (!in_array(\$mode, [0, 1, 2], true)) {", 'if (false) {']], 'Kostal: Modus 3 wird nicht abgelehnt'],
    [$KD, [["'default' => false, 'vars' => IHUB_RemoteControl::vars()", "'default' => true, 'vars' => IHUB_RemoteControl::vars()"]], 'Kostal-Treiber: Gruppe Steuerung startet an'],
    [$KD, [["            \$mb->setFloatWordSwap(\$hub->GetKostalWordSwap());\n            IHUB_RemoteControl::control", "            IHUB_RemoteControl::control"]], 'Kostal-Treiber: Wortreihenfolge wird beim Schreiben nicht gesetzt'],
    // Fronius
    [$FR, [['return $watts > 0 ? [self::raw(-$pct, $sf), self::raw($pct, $sf)] : [self::raw($pct, $sf), self::raw(-$pct, $sf)];', 'return $watts > 0 ? [self::raw($pct, $sf), self::raw(-$pct, $sf)] : [self::raw(-$pct, $sf), self::raw($pct, $sf)];']], 'Fronius: Vorzeichen der Grenzen vertauscht'],
    [$FR, [['const MOD_BOTH = 3;', 'const MOD_BOTH = 2;']], 'Fronius: nur die Entladegrenze aktiv statt beider'],
    [$FR, [["if ((\$have['rvrt'] ?? null) !== \$rvrt) {", 'if (false) {']], 'Fronius: Rückfallzeit (Totmann) wird nie gesetzt'],
    [$FR, [["if ((\$have['rvrt'] ?? null) !== \$rvrt) {", 'if (true) {']], 'Fronius: Rückfallzeit wird in jedem Zyklus geschrieben'],
    [$FR, [['max(self::TIMEOUT_MIN_S, min(self::TIMEOUT_MAX_S, $s))', '$s']], 'Fronius: Rückfallzeit nicht begrenzt'],
    [$FR, [['return (int)round($pct * pow(10, -$sf));', 'return (int)round($pct * 100);']], 'Fronius: Skalierung der Grenzen ignoriert'],
    [$FR, [['* pow(10, $wsf))', '* 1)']], 'Fronius: Skalierung von WChaMax ignoriert'],
    [$FR, [["if ((\$have['mod'] ?? null) !== 0) {", 'if (false) {']], 'Fronius: Modus 0 schaltet StorCtl_Mod nicht aus'],
    [$FR, [["if (!in_array(\$mode, [0, 1, 2], true)) {", 'if (false) {']], 'Fronius: Modus 3 wird nicht abgelehnt'],
    [$FR, [["if (\$got !== \$want) {\n                return IHUB_Text::t('device acknowledged", "if (false) {\n                return IHUB_Text::t('device acknowledged"]], 'Fronius: Rücklesen vergleicht nichts'],
    [$FR, [["if ((int)(\$cap['ratedW'] ?? 0) <= 0) {", 'if (false) {']], 'Fronius: fehlender Speicher wird nicht abgelehnt'],
    [$FR, [['if (abs($wsf) > 10 || abs($rsf) > 10) {', 'if (false) {']], 'Fronius: unplausibler Skalierungsfaktor wird benutzt'],
    [$FR, [['return ($status & 3) !== 0;', 'return true;']], 'Fronius: immer aktiv gemeldet'],
    [$FD, [["'default' => false, 'vars' => IHUB_RemoteControl::vars()", "'default' => true, 'vars' => IHUB_RemoteControl::vars()"]], 'Fronius-Treiber: Gruppe Steuerung startet an'],
    // SMA
    [$SM, [['const FALLBACK_APPLY = 2507;', 'const FALLBACK_APPLY = 2506;']], 'SMA: Ausfallverhalten bleibt auf "Werte halten" (kein Totmann)'],
    [$SM, [['return $mode === 2 ? -$watts : 0;', 'return $mode === 2 ? $watts : 0;']], 'SMA STP SE: Vorzeichen des Sollwerts nicht gedreht'],
    [$SM, [['(int)round(-$watts / $ratedW * self::PCT_FULL)', '(int)round($watts / $ratedW * self::PCT_FULL)']], 'SMA: Vorzeichen des Prozentfensters nicht gedreht'],
    [$SM, [['return $this->family === self::FAMILY_STPSX ? 44449 : 44039;', 'return $this->family === self::FAMILY_STPSX ? 44039 : 44449;']], 'SMA: Fensterregister der Geräteklassen vertauscht'],
    [$SM, [["'words' => array_merge(self::words(\$p), self::words(\$p)), 'afterUs' => 0]", "'words' => self::words(\$p), 'afterUs' => 0]"]], 'SMA: nur eine Fenstergrenze geschrieben'],
    [$SM, [["if ((\$have['fallback'] ?? null) !== \$want['fallback'] || (\$have['timeout'] ?? null) !== \$want['timeout']) {", 'if (true) {']], 'SMA: 41193/41195 (Flash) werden bei jedem Zyklus geschrieben'],
    [$SM, [['if ($cfg === null && $last > 0) {', 'if (false) {']], 'SMA: unbekannte Konfiguration wird bei laufender Fernsteuerung erneut geschrieben'],
    [$SM, [['if ($this->family !== self::FAMILY_SI) {', 'if (true) {']], 'SMA Sunny Island: 40210 (Grid Guard) wird geschrieben'],
    [$SM, [["(\$cfg === null || \$cfg['self'] !== self::SELF_ON)", 'false']], 'SMA Sunny Island: Modus 0 schaltet den Eigenverbrauch nicht wieder ein'],
    [$SM, [['max(self::TIMEOUT_MIN_S, min(self::TIMEOUT_MAX_S, $s))', '$s']], 'SMA: Timeout nicht begrenzt'],
    [$SM, [["\$cap['cfg']['mode'] !== self::MODE_EXTERNAL", 'false']], 'SMA Sunny Island: fehlende Betriebsart 1079 wird nicht gemeldet'],
    [$SM, [["(int)(\$cap['ratedW'] ?? 0) <= 0", 'false']], 'SMA: unbekannte Nennleistung wird nicht abgelehnt'],
    [$SM, [["if (\$got !== \$want) {\n                return \$this->diff", "if (false) {\n                return \$this->diff"]], 'SMA: Rücklesen der Konfiguration vergleicht nichts'],
    [$SM, [['[0, self::SP_INACTIVE], \'afterUs\' => 0]]];', '[0, self::SP_ACTIVE], \'afterUs\' => 0]]];']], 'SMA STP SE: Modus 0 schreibt nicht 803'],
    [$SM, [["[self::REG_SELF, \$c['self'] ?? 0, self::SELF_OFF]", "[self::REG_SELF, self::SELF_OFF, self::SELF_OFF]"]], 'SMA Sunny Island: 40075 wird nicht geprüft'],
    [$SM, [["array_merge(self::words(\$w), [0, self::SP_ACTIVE])", "array_merge(self::words(\$w), [0, self::SP_INACTIVE])"]], 'SMA STP SE: Sollwert wird mit 803 statt 802 geschrieben'],
    [$SM, [["if (\$this->window()) {\n            \$want['ramp']", "if (false) {\n            \$want['ramp']"]], 'SMA: Rampenbegrenzung 44029 wird nicht ausgeschaltet'],
    [$SM, [['[0 => self::FAMILY_SE, 1 => self::FAMILY_SI, 2 => self::FAMILY_STPSX]', '[0 => self::FAMILY_SE, 1 => self::FAMILY_STPSX, 2 => self::FAMILY_SI]']], 'SMA: SmaControlType auf die falsche Geräteklasse'],
    [$B, [['$cap = $a->prepare($mb, $cap);', '']], 'Basis: prepare() wird nicht aufgerufen'],
    [$SD, [['$mb->unitId = $keep;' . "\n" . '        }', '$mb->unitId = $use;' . "\n" . '        }']], 'SMA-Treiber: Unit-ID wird nach der Steuerung nicht zurückgesetzt'],
    [$SD, [["'default' => false, 'vars' => IHUB_RemoteControl::vars()", "'default' => true, 'vars' => IHUB_RemoteControl::vars()"]], 'SMA-Treiber: Gruppe Steuerung startet an'],
    // Solis-Adapter
    [$S, [["IHUB_SolisDispatch::failsafe(\$this->legacy(\$req)) * 60", "IHUB_SolisDispatch::failsafe(\$this->legacy(\$req))"]], 'Solis: Totmann in Minuten statt Sekunden gemeldet'],
    [$S, [["(int)ceil(\$sec / 60)", "(int)floor(\$sec / 60)"]], 'Solis: Totmann wird abgerundet'],
    [$M, [["'modeID' => \$find('ctl_rc_mode')", "'modeID' => 0"]], 'Vertrag: modeID fehlt'],
    [$M, [["if (\$find('ctl_rc_mode') > 0) {", 'if (true) {']], 'Vertrag: remoteControl auch ohne Variablen gefüllt'],
    [$M, [["\$group['default'] ?? true", 'true']], 'Gruppe mit default false startet an'],
];

// die letzte Probe in $S mit Platzhaltertext 'x' entfällt, wenn der Suchtext nicht existiert
$tmp = sys_get_temp_dir() . '/ihmutd' . getmypid(); mkdir($tmp);
$jobs = [];
foreach ($X as $i => [$file, $pairs, $desc]) {
    $d = "$tmp/m$i"; mkdir($d);
    foreach (['InverterHub', 'InverterHubDiscovery', 'libs', '.tools'] as $dir) { if (is_dir("$root/$dir")) { exec('cp -R ' . escapeshellarg("$root/$dir") . ' ' . escapeshellarg("$d/$dir")); } }
    $t = file_get_contents("$d/$file"); $ok = true;
    foreach ($pairs as [$a, $b]) {
        $p = strpos($t, $a);
        if ($p === false) { $ok = false; break; }
        $t = substr($t, 0, $p) . $b . substr($t, $p + strlen($a));
    }
    if (!$ok) { $jobs[$i] = ['err' => 'Suchtext nicht gefunden']; continue; }
    file_put_contents("$d/$file", $t);
    $jobs[$i] = ['dir' => $d, 'out' => ''];
}
$queue = array_keys(array_filter($jobs, fn($j) => isset($j['dir']))); $run = [];
while ($queue || $run) {
    while ($queue && count($run) < 6) {
        $i = array_shift($queue);
        $pr = proc_open('php ' . escapeshellarg($jobs[$i]['dir'] . '/.tools/test-remote-control.php') . ' 2>&1', [1 => ['pipe', 'w']], $pp);
        stream_set_blocking($pp[1], false); $run[$i] = [$pr, $pp[1]];
    }
    foreach ($run as $i => [$pr, $pipe]) {
        $jobs[$i]['out'] .= (string)stream_get_contents($pipe);
        $st = proc_get_status($pr);
        if (!$st['running']) { $jobs[$i]['out'] .= (string)stream_get_contents($pipe); $jobs[$i]['rc'] = $st['exitcode']; fclose($pipe); proc_close($pr); unset($run[$i]); }
    }
    usleep(100000);
}
$bad = 0;
foreach ($X as $i => [$file, , $desc]) {
    $j = $jobs[$i];
    if (isset($j['err'])) { $bad++; echo "  FEHLER     [$file] $desc: {$j['err']}\n"; continue; }
    $failed = [];
    foreach (explode("\n", $j['out']) as $l) { if (str_starts_with($l, '  FAIL ')) { $failed[] = trim(substr($l, 7)); } }
    if ($j['rc'] !== 0) { echo sprintf("  erkannt    [%s] %s  -> %s\n", basename($file), $desc, $failed ? mb_substr($failed[0], 0, 70) : 'Abbruch im Lauf'); }
    else { $bad++; echo "  UNGEFANGEN [$file] $desc\n"; }
}
exec('rm -rf ' . escapeshellarg($tmp));
echo "\n" . count($X) . " Mutationen, " . (count($X) - $bad) . " erkannt, $bad ungefangen\n";
exit($bad === 0 ? 0 : 1);
