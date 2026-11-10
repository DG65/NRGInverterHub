<?php
/**
 * Kostal PLENTICORE HTTP API v1 -> NRGInverterHub Modbus-register-compatible,
 * READ-ONLY transport. No control APIs are called, Modbus TCP is not opened.
 *
 * The register map covers every registered variable of IHUB_KostalDriver
 * (beta branch, inspected 2026-11-08). Absent API values remain unavailable;
 * they MUST NOT silently become zero, and are reported as missing.
 */
class IHUB_KostalHttpClient
{
    /** @var callable|null liefert die gespeicherte Sitzungskennung */
    private $loadSession;
    /** @var callable|null speichert die Sitzungskennung (Attribut der Instanz) */
    private $saveSession;
    private string $host;
    private string $username;
    private string $password;
    private bool $pv3Battery;
    private string $session = '';
    private bool $loaded = false;
    private array $raw = [];
    private array $registers = [];
    private array $availability = [];
    private array $errors = [];
    public bool $floatWordSwap = false;
    public $unitId = 71;
    public int $requests = 0;
    public int $responses = 0;

    // Each key is the starting Modbus register. The ident matches the
    // existing Kostal driver's variable, preserving downstream contracts.
    // The API names are processdata IDs on PLENTICORE HTTP API v1.
    private const REGISTERS = [
        100 => ['pv_total', 'devices:local', ['Dc_P'], 'f32', 'W'],
        106 => ['home_from_bat', 'devices:local', ['HomeBat_P'], 'f32', 'W'],
        108 => ['home_from_grid', 'devices:local', ['HomeGrid_P'], 'f32', 'W'],
        116 => ['home_from_pv', 'devices:local', ['HomePv_P'], 'f32', 'W'],
        118 => ['home_total', 'scb:statistic:EnergyFlow', ['Statistic:EnergyHome:Total'], 'f32', 'Wh'],
        120 => ['riso', 'devices:local', ['Iso_R'], 'f32', 'Ohm'],
        152 => ['grid_freq', 'devices:local:ac', ['Frequency'], 'f32', 'Hz'],
        154 => ['grid_l1_curr', 'devices:local:ac', ['L1_I'], 'f32', 'A'],
        156 => ['grid_l1_pwr', 'devices:local:ac', ['L1_P'], 'f32', 'W'],
        158 => ['grid_l1_volt', 'devices:local:ac', ['L1_U'], 'f32', 'V'],
        160 => ['grid_l2_curr', 'devices:local:ac', ['L2_I'], 'f32', 'A'],
        162 => ['grid_l2_pwr', 'devices:local:ac', ['L2_P'], 'f32', 'W'],
        164 => ['grid_l2_volt', 'devices:local:ac', ['L2_U'], 'f32', 'V'],
        166 => ['grid_l3_curr', 'devices:local:ac', ['L3_I'], 'f32', 'A'],
        168 => ['grid_l3_pwr', 'devices:local:ac', ['L3_P'], 'f32', 'W'],
        170 => ['grid_l3_volt', 'devices:local:ac', ['L3_U'], 'f32', 'V'],
        172 => ['ac_power', 'devices:local:ac', ['P'], 'f32', 'W'],
        200 => ['bat_curr', 'devices:local:battery', ['I'], 'f32', 'A'],
        210 => ['bat_soc', 'devices:local:battery', ['SoC'], 'f32', '%'],
        214 => ['bat_temp', 'devices:local:battery', ['T', 'Temperature', 'BatTemp'], 'f32', 'C'],
        216 => ['bat_volt', 'devices:local:battery', ['U'], 'f32', 'V'],
        224 => ['meter_l1_pwr', 'devices:local:powermeter', ['L1_P'], 'f32', 'W'],
        234 => ['meter_l2_pwr', 'devices:local:powermeter', ['L2_P'], 'f32', 'W'],
        244 => ['meter_l3_pwr', 'devices:local:powermeter', ['L3_P'], 'f32', 'W'],
        252 => ['meter_total', 'devices:local:powermeter', ['P'], 'f32', 'W'],
        258 => ['pv1_curr', 'devices:local:pv1', ['I'], 'f32', 'A'],
        260 => ['pv1_power', 'devices:local:pv1', ['P'], 'f32', 'W'],
        266 => ['pv1_volt', 'devices:local:pv1', ['U'], 'f32', 'V'],
        268 => ['pv2_curr', 'devices:local:pv2', ['I'], 'f32', 'A'],
        270 => ['pv2_power', 'devices:local:pv2', ['P'], 'f32', 'W'],
        276 => ['pv2_volt', 'devices:local:pv2', ['U'], 'f32', 'V'],
        278 => ['pv3_curr', 'devices:local:pv3', ['I'], 'f32', 'A'],
        280 => ['pv3_power', 'devices:local:pv3', ['P'], 'f32', 'W'],
        286 => ['pv3_volt', 'devices:local:pv3', ['U'], 'f32', 'V'],
        320 => ['e_total', 'scb:statistic:EnergyFlow', ['Statistic:Yield:Total'], 'f32', 'Wh'],
        322 => ['e_day', 'scb:statistic:EnergyFlow', ['Statistic:Yield:Day'], 'f32', 'Wh'],
        324 => ['e_year', 'scb:statistic:EnergyFlow', ['Statistic:Yield:Year'], 'f32', 'Wh'],
        326 => ['e_month', 'scb:statistic:EnergyFlow', ['Statistic:Yield:Month'], 'f32', 'Wh'],
        582 => ['bat_power', 'devices:local:battery', ['P'], 's16', 'W'],
        768 => ['dev_name', 'info/version', ['product_name', 'model', 'hostname'], 'str', ''],
    ];

    public function __construct(string $host, string $username, string $password, bool $pv3Battery = false, ?callable $loadSession = null, ?callable $saveSession = null)
    {
        if (!preg_match('/^[a-z0-9][a-z0-9.:-]*$/i', $host) || strpos($host, '..') !== false) {
            throw new InvalidArgumentException('Invalid Kostal HTTP host. Supply only IP or DNS name.');
        }
        if ($password === '') {
            throw new InvalidArgumentException('Kostal HTTP API password is missing.');
        }
        $this->loadSession = $loadSession;
        $this->saveSession = $saveSession;
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->pv3Battery = $pv3Battery;
        if ($loadSession !== null) {
            $this->session = (string)$loadSession();
        }
    }

    public function beginBatch(): void { $this->ensureLoaded(); }
    public function endBatch(): void {}
    // HTTP values are native numbers, serialized here in canonical IEEE-754 ABCD.
    // Ignore the Modbus hardware's CDAB setting: applying it would corrupt every float.
    public function setFloatWordSwap(bool $swap): void { $this->floatWordSwap = false; }
    public function writeSingle($register, $value): bool { throw new LogicException('Kostal HTTP transport is read-only.'); }
    public function writeMultiple($register, $values): bool { throw new LogicException('Kostal HTTP transport is read-only.'); }
    public function readInput($reg, $count): ?array { return null; }

    /** In HTTP mode there is no supported battery-temperature processdatum. */
    private function isSuppressed(string $ident): bool
    {
        if ($ident === 'bat_temp') return true;
        return $this->pv3Battery && in_array($ident, ['pv3_curr', 'pv3_power', 'pv3_volt'], true);
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) return;
        $this->loadSnapshot();
        foreach (self::REGISTERS as $register => $meta) {
            [$ident, $module, $keys, $type, $targetUnit] = $meta;
            if ($this->isSuppressed($ident)) continue;
            $record = null;
            $matchedKey = '';
            foreach ($keys as $key) {
                if (isset($this->raw[$module][$key])) {
                    $record = $this->raw[$module][$key];
                    $matchedKey = (string)$key;
                    break;
                }
            }
            if ($record === null) {
                $this->availability[$ident] = false;
                $this->errors[$ident] = $module . '/' . implode('|', $keys) . ' not available';
                continue;
            }
            try {
                $value = $record['value'];
                if ($type === 'str') {
                    $data = str_pad(substr((string)$value, 0, 32), 32, "\x00");
                    $words = array_values(unpack('n*', $data));
                } else {
                    $unit = (string)($record['unit'] ?? '');
                    // EnergyFlow's Statistic:EnergyHome / Statistic:Yield API keys
                    // are documented in Wh. Some Kostal firmwares omit 'unit'.
                    if ($targetUnit === 'Wh' && trim($unit) === ''
                        && $module === 'scb:statistic:EnergyFlow'
                        && preg_match('/^Statistic:(?:Yield|EnergyHome):(?:Day|Month|Year|Total)$/', $matchedKey)) {
                        $unit = 'Wh';
                    }
                    $v = $this->normalizeValue($value, $unit, $targetUnit);
                    if ($type === 's16') {
                        if ($v < -32768 || $v > 32767) {
                            throw new RuntimeException('outside signed 16-bit range');
                        }
                        $words = [((int)round($v)) & 0xffff];
                    } else {
                        $words = array_values(unpack('n*', pack('G', $v)));
                    }
                }
                foreach ($words as $offset => $word) {
                    $this->registers[$register + $offset] = $word;
                }
                $this->availability[$ident] = true;
            } catch (Throwable $e) {
                $this->availability[$ident] = false;
                $this->errors[$ident] = $e->getMessage();
            }
        }
        $this->loaded = true;
    }

    protected function loadSnapshot(): void
    {
        $index = $this->authorized('GET', 'processdata');
        if (!is_array($index)) throw new RuntimeException('Invalid processdata index');
        $modules = [];
        foreach ($index as $item) {
            if (isset($item['moduleid'])) $modules[(string)$item['moduleid']] = true;
        }
        $needed = [];
        foreach (self::REGISTERS as $meta) {
            // PV3 is omitted from API requests entirely when it is the battery port.
            // Battery module still loads for SOC/current/voltage/power, but never T.
            if ($this->isSuppressed($meta[0])) continue;
            if ($meta[1] !== 'info/version') $needed[$meta[1]] = true;
        }
        foreach (array_keys($needed) as $module) {
            if (!isset($modules[$module])) {
                $this->errors['module:' . $module] = 'module not exposed by inverter';
                continue;
            }
            try {
                $list = $this->authorized('GET', 'processdata/' . $module);
                foreach ((array)$list as $row) {
                    if (($row['moduleid'] ?? '') !== $module) continue;
                    foreach ($row['processdata'] ?? [] as $datum) {
                        if (!is_array($datum) || !isset($datum['id']) || !array_key_exists('value', $datum)) continue;
                        $this->raw[$module][(string)$datum['id']] = $datum;
                    }
                }
            } catch (Throwable $e) {
                $this->errors['module:' . $module] = $e->getMessage();
            }
        }
        try {
            $info = $this->authorized('GET', 'info/version');
            foreach (['product_name', 'model', 'hostname'] as $key) {
                if (isset($info[$key]) && trim((string)$info[$key]) !== '') {
                    $this->raw['info/version'][$key] = ['value' => (string)$info[$key]];
                }
            }
        } catch (Throwable $e) {
            $this->errors['module:info/version'] = $e->getMessage();
        }
    }

    private function normalizeValue($value, string $unit, string $wanted): float
    {
        if (!is_numeric($value) || !is_finite((float)$value)) throw new RuntimeException('Not a finite number');
        $x = (float)$value;
        $unit = trim($unit);
        // The Kostal API may express W/Wh in kilo-units. Do not guess
        // energy units: an absent unit on an energy counter is a hard error.
        if ($wanted === 'Wh') {
            if ($unit === 'Wh') return $x;
            if ($unit === 'kWh') return $x * 1000.0;
            if ($unit === 'MWh') return $x * 1000000.0;
            throw new RuntimeException('Energy unit missing or unsupported: ' . $unit);
        }
        if ($wanted === 'W' && $unit === 'kW') return $x * 1000.0;
        if ($wanted === 'Ohm' && in_array($unit, ['kOhm', 'kΩ'], true)) return $x * 1000.0;
        if ($wanted === 'C' && in_array($unit, ['°C', 'C'], true)) return $x;
        if ($unit === '' || $unit === $wanted || ($wanted === 'Ohm' && $unit === 'Ω') || ($wanted === 'C' && $unit === '°C')) return $x;
        throw new RuntimeException('Unexpected unit ' . $unit . '; expected ' . $wanted);
    }

    /**
     * Liest einen Registerbereich aus dem Schnappschuss. Ist KEIN abgebildeter Punkt des Bereichs verfügbar, kommt null
     * (der Treiber überspringt den Block). Fehlt nur ein Teil, trägt die Lücke die Marke "nicht belegt" (Float32-
     * Extremwert, die der Hub überall verwirft), nie eine Null: ein fehlender Wert wird nicht zum gültigen Messwert,
     * zieht aber auch nicht die übrigen Werte desselben Blocks mit.
     */
    public function readHolding($start, $count): ?array
    {
        $this->ensureLoaded();
        $end = $start + $count - 1;
        $mapped = 0;
        $missing = [];
        foreach (self::REGISTERS as $register => $meta) {
            $len = $meta[3] === 's16' ? 1 : ($meta[3] === 'str' ? 16 : 2);
            if ($register <= $end && $register + $len - 1 >= $start) {
                $mapped++;
                if (!($this->availability[$meta[0]] ?? false)) {
                    for ($i = 0; $i < $len; $i++) {
                        $missing[$register + $i] = $meta[3] === 'f32' ? ($i === 0 ? 0xFF7F : 0xFFFF) : 0;
                    }
                    $missing[-$register] = true;   // Merker: dieser Punkt fehlt
                }
            }
        }
        if ($mapped > 0 && count(array_filter(array_keys($missing), static fn($k) => $k < 0)) === $mapped) {
            return null;   // nichts davon verfügbar
        }
        $words = [];
        for ($reg = $start; $reg <= $end; $reg++) {
            $words[] = $missing[$reg] ?? ($this->registers[$reg] ?? 0);   // Lücken zwischen Punkten liest der Treiber nicht
        }
        return $words;
    }

    public function u16($regs, $offset): int { return (int)($regs[$offset] ?? 0) & 0xffff; }
    public function s16($regs, $offset): int
    {
        $u = $this->u16($regs, $offset);
        return $u > 32767 ? $u - 65536 : $u;
    }
    public function u32($regs, $offset): int { return ($this->u16($regs, $offset) << 16) | $this->u16($regs, $offset + 1); }
    public function s32($regs, $offset): int
    {
        $v = $this->u32($regs, $offset);
        return $v > 2147483647 ? $v - 4294967296 : $v;
    }
    public function readFloat32($regs, $offset): float
    {
        $a = $this->u16($regs, $offset); $b = $this->u16($regs, $offset + 1);
        if ($this->floatWordSwap) [$a, $b] = [$b, $a];
        return (float)(unpack('G', pack('nn', $a, $b))[1]);
    }
    public function readStr($regs, $offset, int $regCount): string
    {
        $out = '';
        for ($i = 0; $i < $regCount; $i++) $out .= pack('n', $this->u16($regs, $offset + $i));
        return rtrim($out, "\x00 ");
    }

    /** Returns only missing variables enabled in the Kostal driver configuration. */
    public function getMissingFor($driver, $hub): array
    {
        $this->ensureLoaded();
        $wanted = [];
        foreach ($driver->getBaseVars() as $spec) $wanted[] = $spec[0];
        foreach ($driver->getOptionalGroups() as $property => $group) {
            if (!$hub->GetPropBool($property)) continue;
            foreach ($group['vars'] as $spec) $wanted[] = $spec[0];
        }
        // Two driver variables are calculated, not read from the API.
        $derived = ['connected' => true, 'bat_status' => true];
        $missing = [];
        foreach (array_unique($wanted) as $ident) {
            if ($this->isSuppressed($ident)) continue;
            if (isset($derived[$ident])) {
                if ($ident === 'bat_status' && !($this->availability['bat_power'] ?? false)) $missing[$ident] = 'battery power missing';
                continue;
            }
            if (!($this->availability[$ident] ?? false)) $missing[$ident] = $this->errors[$ident] ?? 'no HTTP mapping';
        }
        return $missing;
    }

    public function rawRegisterIdents(): array
    {
        return array_values(array_map(static fn($entry) => $entry[0], self::REGISTERS));
    }

    private function authorized(string $method, string $path, $payload = null)
    {
        if ($this->session !== '') {
            [$status, $result] = $this->request($method, $path, $payload, $this->session);
            if ($status >= 200 && $status < 300) return $result;
            if (!in_array($status, [401, 403], true)) throw new RuntimeException('Kostal HTTP ' . $status . ' for ' . $path);
            $this->session = '';
            $this->saveSession();
        }
        $this->login();
        [$status, $result] = $this->request($method, $path, $payload, $this->session);
        if ($status < 200 || $status >= 300) throw new RuntimeException('Kostal HTTP ' . $status . ' for ' . $path);
        return $result;
    }

    private function login(): void
    {
        $nonce = base64_encode(random_bytes(12));
        [$status, $start] = $this->request('POST', 'auth/start', ['username' => $this->username, 'nonce' => $nonce]);
        if ($status !== 200 || !isset($start['nonce'], $start['salt'], $start['rounds'], $start['transactionId'])) throw new RuntimeException('Kostal auth/start failed');
        $salt = base64_decode($start['salt'], true);
        if ($salt === false || (int)$start['rounds'] <= 0) throw new RuntimeException('Kostal auth parameters invalid');
        $salted = hash_pbkdf2('sha256', $this->password, $salt, (int)$start['rounds'], 32, true);
        $clientKey = hash_hmac('sha256', 'Client Key', $salted, true);
        $stored = hash('sha256', $clientKey, true);
        $message = 'n=' . $this->username . ',r=' . $nonce . ',r=' . $start['nonce'] . ',s=' . $start['salt'] . ',i=' . $start['rounds'] . ',c=biws,r=' . $start['nonce'];
        $signature = hash_hmac('sha256', $message, $stored, true);
        $proof = $clientKey ^ $signature;
        [$status, $finish] = $this->request('POST', 'auth/finish', ['transactionId' => $start['transactionId'], 'proof' => base64_encode($proof)]);
        if ($status !== 200 || !isset($finish['signature'], $finish['token'])) throw new RuntimeException('Kostal auth/finish failed');
        $serverKey = hash_hmac('sha256', 'Server Key', $salted, true);
        $expected = base64_encode(hash_hmac('sha256', $message, $serverKey, true));
        if (!hash_equals($expected, $finish['signature'])) throw new RuntimeException('Kostal server signature mismatch');
        $protocolKey = hash_hmac('sha256', 'Session Key' . $message . $clientKey, $stored, true);
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($finish['token'], 'aes-256-gcm', $protocolKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('Kostal session encryption failed');
        [$status, $session] = $this->request('POST', 'auth/create_session', [
            'transactionId' => $start['transactionId'],
            'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'payload' => base64_encode($cipher)
        ]);
        if ($status !== 200 || empty($session['sessionId'])) throw new RuntimeException('Kostal create_session failed');
        $this->session = $session['sessionId'];
        $this->saveSession();
    }

    private function saveSession(): void
    {
        if ($this->saveSession !== null) {
            ($this->saveSession)($this->session);
        }
    }

    /** @return array{int,mixed} */
    protected function request(string $method, string $path, $payload = null, string $session = ''): array
    {
        $headers = ["Accept: application/json", "Content-Type: application/json"];
        if ($session !== '') $headers[] = 'Authorization: Session ' . $session;
        $options = ['http' => [
            'method' => $method, 'timeout' => 4, 'ignore_errors' => true,
            'header' => implode("\r\n", $headers) . "\r\n"
        ]];
        if ($payload !== null) $options['http']['content'] = json_encode($payload, JSON_THROW_ON_ERROR);
        $url = 'http://' . $this->host . '/api/v1/' . ltrim($path, '/');
        $context = stream_context_create($options);
        $this->requests++;
        $body = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        foreach ($responseHeaders as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) $status = (int)$m[1];
        }
        if ($body === false) throw new RuntimeException('HTTP connection failed: ' . $path);
        $this->responses++;
        if ($body === '') return [$status, null];
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return [$status, $data];
    }
}
