<?php
/**
 * Gemeinsame Hilfe fuer die Referenzlaeufe der Treiber (18.11.2026, EMS-Auftrag Code-Bestandsaufnahme Schritt 1).
 *
 * Ein Referenzlauf haelt das HEUTIGE Verhalten eines Treibers fest, nicht das gewuenschte:
 *   - feste Registerantworten (RefMb) werden eingespeist,
 *   - jede geschriebene Variable (RefHub) und jeder Schreibzugriff aufs Geraet wird in der Reihenfolge aufgezeichnet,
 *   - das Ergebnis wird mit der Referenzdatei .tools/ref/<name>.json verglichen (typsicher: Zahlen als Float
 *     auf 6 Stellen gerundet, damit 60.0 und 60 nicht als Abweichung gelten).
 * Neu schreiben der Referenz nur bewusst: UPDATE_REF=1 php .tools/test-ref-<name>.php
 *
 * Die Registerwerte der Fixtures sind an den im Treiber belegten Adressen gesetzt. Ankerwerte stammen aus
 * Messungen oder Treiberkommentaren (z. B. BMS-Zaehler 8669,7/8429,7 kWh vom 07.11.2026); die uebrigen Register
 * tragen ein Muster, das je Adresse einen eigenen Wert liefert, damit jede Verschiebung von Offset, Skalierung
 * oder Vorzeichen eine Abweichung erzeugt. Das ist KEINE Aussage, dass die Werte am Geraet so vorkommen.
 */

if (!class_exists('IPSModule')) {
    class IPSModule
    {
        public $InstanceID = 0;
        public $buf = [];
        public $log = [];
        public function __construct($id = 0) { $this->InstanceID = $id; }
        public function GetBuffer($k) { return $this->buf[$k] ?? ''; }
        public function SetBuffer($k, $v) { $this->buf[$k] = $v; }
        public function LogMessage($m, $l) { $this->log[] = [$m, $l]; }
        public function SendDebug($a, $b, $c) { }
    }
}
foreach (['VARIABLETYPE_BOOLEAN' => 0, 'VARIABLETYPE_INTEGER' => 1, 'VARIABLETYPE_FLOAT' => 2, 'VARIABLETYPE_STRING' => 3, 'KR_READY' => 10103, 'KL_WARNING' => 2] as $c => $v) {
    if (!defined($c)) { define($c, $v); }
}
if (!function_exists('IPS_Sleep')) { function IPS_Sleep($ms) { } }
if (!function_exists('IPS_SemaphoreEnter')) { function IPS_SemaphoreEnter($n, $ms) { return true; } }
if (!function_exists('IPS_SemaphoreLeave')) { function IPS_SemaphoreLeave($n) { return true; } }

require_once dirname(__DIR__, 2) . '/InverterHub/module.php';

/** Simulierter Modbus-Client: feste Registerantworten, Schreibzugriffe werden aufgezeichnet. */
class RefMb extends IHUB_ModbusTcpClient
{
    /** @var array<int,array<int,int>> [Funktionscode 3|4][Adresse] => Wert */
    public $regs = [3 => [], 4 => []];
    /** @var array<int,int[]|int> Schreibzugriffe in Reihenfolge: ['single'|'multi', Adresse, Wert(e)] */
    public $writes = [];
    /** @var int[] Adressen, deren Schreiben scheitern soll */
    public $failWrite = [];
    /** @var int[] Startadressen, die beim Lesen keine Antwort liefern (null) */
    public $noRead = [];
    /** Anzahl Lesezugriffe je Startadresse (zur Kontrolle, dass ein Block wirklich gelesen wurde) */
    public $reads = [];
    /** Optional: Register je Unit-ID [unit][fc][addr]. Ist es gesetzt, gilt nur die Tabelle der aktuellen Unit-ID. */
    public $regsByUnit = null;
    /** Automatik: jede nicht gesetzte Adresse liefert ein Muster ('pos', 'neg', 'ffff', 'zero'); null = aus. */
    public $auto = null;
    /** true: keine Antwort auf irgendeinen Lesezugriff */
    public $silent = false;

    public function __construct($unit = 1) { parent::__construct('127.0.0.1', 502, $unit); }
    public function beginBatch() { }
    public function endBatch() { }

    private function fetch(int $fc, $start, $count)
    {
        $this->reads[] = [$fc, (int)$start, (int)$count, (int)$this->unitId];
        if ($this->silent || in_array((int)$start, $this->noRead, true)) { return null; }
        $any = false; $out = [];
        $tab = $this->regsByUnit !== null ? ($this->regsByUnit[$this->unitId][$fc] ?? []) : $this->regs[$fc];
        for ($i = 0; $i < $count; $i++) {
            $a = $start + $i;
            if (array_key_exists($a, $tab)) { $any = true; $out[] = $tab[$a] & 0xFFFF; }
            elseif ($this->auto !== null) {
                $any = true;
                $p = ($a * 37 + 13) % 3900;
                switch ($this->auto) {
                    case 'neg':  $out[] = 0xF000 | ($p + 100); break;
                    case 'ffff': $out[] = 0xFFFF; break;
                    case 'zero': $out[] = 0; break;
                    default:     $out[] = 100 + $p;
                }
            }
            else { $out[] = 0; }
        }
        return $any ? $out : null;
    }
    public function readHolding($start, $count) { return $this->fetch(3, $start, $count); }
    public function readInput($start, $count) { return $this->fetch(4, $start, $count); }

    public function writeSingle($reg, $value)
    {
        $this->lastError = '';
        if (in_array((int)$reg, $this->failWrite, true)) { $this->lastError = 'no connection (simuliert)'; $this->writes[] = ['single-failed', (int)$reg, (int)$value]; return false; }
        $this->writes[] = ['single', (int)$reg, (int)$value];
        $this->regs[3][(int)$reg] = (int)$value & 0xFFFF;
        return true;
    }
    public function writeMultiple($start, $values)
    {
        $this->lastError = '';
        if (in_array((int)$start, $this->failWrite, true)) { $this->lastError = 'no connection (simuliert)'; $this->writes[] = ['multi-failed', (int)$start, array_map('intval', $values)]; return false; }
        $this->writes[] = ['multi', (int)$start, array_map('intval', $values)];
        foreach (array_values($values) as $i => $v) { $this->regs[3][(int)$start + $i] = (int)$v & 0xFFFF; }
        return true;
    }

    /** Setzt Register einer bestimmten Unit-ID (schaltet auf die Tabelle je Unit-ID um). */
    public function setU(int $unit, int $fc, int $addr, array $vals)
    {
        if ($this->regsByUnit === null) { $this->regsByUnit = []; }
        foreach (array_values($vals) as $i => $v) { $this->regsByUnit[$unit][$fc][$addr + $i] = (int)$v & 0xFFFF; }
    }
    /** IEEE-754-Float32 als zwei Register, hohes Wort zuerst (ABCD). */
    public static function f32(float $f): array
    {
        $b = pack('G', $f); $u = unpack('n2', $b); return [$u[1], $u[2]];
    }
    /** Setzt Register ab Adresse (Funktionscode 3 = Holding, 4 = Input). */
    public function set(int $fc, int $addr, array $vals)
    {
        foreach (array_values($vals) as $i => $v) { $this->regs[$fc][$addr + $i] = (int)$v & 0xFFFF; }
    }
    /** Fuellt einen Adressbereich mit dem Muster, nur wo noch nichts gesetzt ist. */
    public function pattern(int $fc, int $addr, int $count, int $seed = 0)
    {
        for ($i = 0; $i < $count; $i++) {
            $a = $addr + $i;
            if (!array_key_exists($a, $this->regs[$fc])) { $this->regs[$fc][$a] = 100 + (($a * 37 + $seed * 11 + 13) % 3900); }
        }
    }
    /** Zwei Register fuer einen 32-Bit-Wert, hohes Wort zuerst (GoodWe/Solis/Sungrow). */
    public static function w32(int $v): array
    {
        $v &= 0xFFFFFFFF; return [($v >> 16) & 0xFFFF, $v & 0xFFFF];
    }
}

/** Aufzeichnende Hauptinstanz: speichert jede gesetzte Variable und jede Warnung. */
class RefHub
{
    /** @var array<string,array{0:string,1:mixed}> */
    public $vars = [];
    public $warn = [];
    public $props = [];
    public $defaultProp = true;
    /** @var array<string,mixed> Startwerte, die GetVarInt/GetVarFloat vor dem Lauf liefern */
    public $seed = [];
    private $real;

    public function __construct(array $props = [], bool $defaultProp = true)
    {
        $this->props = $props;
        $this->defaultProp = $defaultProp;
        $stub = new class extends InverterHub {
            public $attr = [];
            public function __construct() { }
            public function ReadAttributeBoolean($n) { return $this->attr[$n] ?? false; }
            public function WriteAttributeBoolean($n, $v) { $this->attr[$n] = $v; }
        };
        $this->real = $stub;
    }
    public function SetVarFloat(string $i, float $v) { $this->vars[$i] = ['F', $v]; }
    public function SetVarInt(string $i, int $v) { $this->vars[$i] = ['I', $v]; }
    public function SetVarBool(string $i, bool $v) { $this->vars[$i] = ['B', $v]; }
    public function SetVarStr(string $i, string $v) { $this->vars[$i] = ['S', $v]; }
    public function GetPropInt(string $n) { return (int)($this->props[$n] ?? 0); }
    public function GetPropStr(string $n) { return (string)($this->props[$n] ?? ''); }
    public $stamps = [];
    public function RcStamp(string $k) { return (int)($this->stamps[$k] ?? 0); }
    public function SetRcStamp(string $k, int $t) { $this->stamps[$k] = $t; }
    public $published = [];
    public $publishOk = true;
    public function VictronMqttPublish(string $t, string $p) { $this->published[] = [$t, $p]; return $this->publishOk; }
    public function GetPropBool(string $n) { return array_key_exists($n, $this->props) ? (bool)$this->props[$n] : $this->defaultProp; }
    public function GetVarInt(string $i) { return isset($this->vars[$i]) ? (int)$this->vars[$i][1] : (int)($this->seed[$i] ?? 0); }
    public function GetVarFloat(string $i) { return isset($this->vars[$i]) ? (float)$this->vars[$i][1] : (float)($this->seed[$i] ?? 0.0); }
    public function WarnUser(string $m) { $this->warn[] = $m; }
    public function BlockLooksUnset(array $regs): bool { return $this->real->BlockLooksUnset($regs); }
    public function GetMeterUnitId() { return (int)($this->props['_meterUnit'] ?? 1); }
    public function GetKostalWordSwap() { return (bool)($this->props['_kostalSwap'] ?? false); }
    public function GetVictronMpptUnitIds() { return $this->props['_victronMppt'] ?? []; }
    public function GetVictronGridUnitId() { return (int)($this->props['_victronGrid'] ?? 0); }
    public function GroupEnabled($n) { return $this->GetPropBool($n); }
    public function YieldCorrected(int $unitId, int $index, int $raw): float { return $raw / 10.0; }   // VictronYieldFix aus (Vorgabe)
    public function FlushYieldState() { }
}

/** Normalisiert Werte typsicher fuer den Vergleich. */
function ref_norm($v)
{
    if (is_array($v)) { $o = []; foreach ($v as $k => $x) { $o[$k] = ref_norm($x); } return $o; }
    if (is_float($v) && is_nan($v)) { return 'NaN'; }
    if (is_float($v) && is_infinite($v)) { return $v > 0 ? 'INF' : '-INF'; }
    if (is_float($v) || is_int($v)) { return round((float)$v, 6); }
    if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) { return 'hex:' . bin2hex($v); }   // Rohbytes aus Textregistern
    return $v;
}

/** Ergebnis eines Laufs als einfaches Array aufbereiten. */
function ref_result(RefHub $hub, RefMb $mb, array $extra = []): array
{
    $vars = [];
    foreach ($hub->vars as $ident => $tv) { $vars[$ident] = [$tv[0], $tv[1]]; }
    ksort($vars);
    return ['vars' => $vars, 'writes' => $mb->writes, 'warnings' => count($hub->warn)] + $extra;
}

$GLOBALS['REF_FAILS'] = 0;
$GLOBALS['REF_COUNT'] = 0;
function ref_ok($cond, string $msg)
{
    $GLOBALS['REF_COUNT']++;
    if ($cond) { echo "  ok   $msg\n"; } else { $GLOBALS['REF_FAILS']++; echo "  FAIL $msg\n"; }
}

/**
 * Vergleicht mit der Referenzdatei. Gibt die Zahl der Abweichungen aus und zaehlt jede Variable als eine Pruefung.
 */
function ref_check(string $file, string $label, array $result)
{
    $path = __DIR__ . '/' . $file . '.json';
    $all = is_file($path) ? json_decode(file_get_contents($path), true) : [];
    $norm = ref_norm($result);
    if (getenv('UPDATE_REF') === '1') {
        $all[$label] = $norm;
        ksort($all);
        $json = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { echo "  FEHLER: Referenz nicht als JSON darstellbar (" . json_last_error_msg() . ") bei $label\n"; exit(2); }
        file_put_contents($path, $json . "\n");
        echo "  ref  $label geschrieben (" . count($result['vars'] ?? []) . " Variablen, " . count($result['writes'] ?? []) . " Schreibzugriffe)\n";
        return;
    }
    if (!isset($all[$label])) { ref_ok(false, "$label: keine Referenz vorhanden (UPDATE_REF=1 zum Anlegen)"); return; }
    $ref = ref_norm($all[$label]);   // beide Seiten gleich normalisieren (JSON liefert 60.0 als 60)
    $diff = [];
    $keys = array_unique(array_merge(array_keys($ref['vars'] ?? []), array_keys($norm['vars'] ?? [])));
    foreach ($keys as $k) {
        if (($ref['vars'][$k] ?? null) !== ($norm['vars'][$k] ?? null)) {
            $diff[] = $k . ': Referenz ' . json_encode($ref['vars'][$k] ?? null) . ' / jetzt ' . json_encode($norm['vars'][$k] ?? null);
        }
    }
    foreach (array_diff(array_keys($norm), ['vars']) as $k) {
        if (($ref[$k] ?? null) !== ($norm[$k] ?? null)) { $diff[] = "$k: Referenz " . json_encode($ref[$k] ?? null) . ' / jetzt ' . json_encode($norm[$k] ?? null); }
    }
    $n = count($keys);
    ref_ok($diff === [], "$label: $n Variablen und " . count($norm['writes'] ?? []) . ' Schreibzugriffe wie in der Referenz' . ($diff ? ' - ' . implode(' | ', array_slice($diff, 0, 6)) . (count($diff) > 6 ? ' ... (+' . (count($diff) - 6) . ')' : '') : ''));
}

function ref_finish(string $name)
{
    if (getenv('UPDATE_REF') === '1') { echo "\nReferenz geschrieben.\n"; exit(0); }
    echo $GLOBALS['REF_FAILS'] ? "\n{$GLOBALS['REF_FAILS']} FEHLER ({$GLOBALS['REF_COUNT']} Pruefungen, $name)\n" : "\nALLE PRUEFUNGEN BESTANDEN ({$GLOBALS['REF_COUNT']} Pruefungen, $name)\n";
    exit($GLOBALS['REF_FAILS'] ? 1 : 0);
}
