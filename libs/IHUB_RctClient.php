<?php

// ---------------------------------------------------------------------------
// IHUB_RctClient / IHUB_RctPowerDriver — RCT Power Storage DC (nur LESEN, Stufe 1)
// Eigenes TCP-Protokoll der RCT Power GmbH (Port 8899), kein Modbus. Protokoll und
// Objekt-IDs sind aus der öffentlichen Beschreibung nachgebaut (Belege: python-rctclient
// docs/protocol_overview.rst und registry.py, evcc meter/rct.go); es wurde KEIN Code
// aus diesen Projekten übernommen (GPL-3.0 bzw. LGPL-2.1, dieses Modul steht unter
// PolyForm Noncommercial). Es gibt hier bewusst weder Schreibbefehle noch Schreib-OIDs.
// ---------------------------------------------------------------------------

class IHUB_RctClient
{
    const START = 0x2B;   // '+'
    const ESC   = 0x2D;   // '-'
    const CMD_READ          = 0x01;
    const CMD_RESPONSE      = 0x05;
    const CMD_LONG_RESPONSE = 0x06;

    private $host;
    private $port;
    private $timeout;
    private $sock = null;
    private $buf  = '';
    public $requests  = 0;
    public $responses = 0;
    public $crcErrors = 0;

    public function __construct(string $host, int $port, float $timeout = 3.0)
    {
        $this->host    = $host;
        $this->port    = $port;
        $this->timeout = $timeout;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->sock) {
            @fclose($this->sock);
        }
        $this->sock = null;
        $this->buf  = '';
    }

    // CRC16, CCITT-Polynom 0x1021, Startwert 0xFFFF; bei ungerader Länge wird vorher ein
    // Nullbyte angehängt (so beschrieben in der rctclient-Doku, am dortigen Beispiel
    // 0104959930bf -> 0d65 geprüft).
    public static function crc16(string $data): int
    {
        if (strlen($data) % 2 === 1) {
            $data .= "\x00";
        }
        $crc = 0xFFFF;
        $n   = strlen($data);
        for ($i = 0; $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? ((($crc << 1) ^ 0x1021) & 0xFFFF) : (($crc << 1) & 0xFFFF);
            }
        }
        return $crc;
    }

    // Leseanfrage für ein Objekt: '+' | Befehl 0x01 | Länge 4 | OID | CRC; hinter dem Startzeichen
    // wird jedes '+' und '-' mit einem vorangestellten '-' maskiert.
    public static function buildReadFrame(int $oid): string
    {
        $body = chr(self::CMD_READ) . chr(4) . pack('N', $oid);
        $body .= pack('n', self::crc16($body));
        $out = chr(self::START);
        $n   = strlen($body);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($body[$i]);
            if ($c === self::START || $c === self::ESC) {
                $out .= chr(self::ESC);
            }
            $out .= $body[$i];
        }
        return $out;
    }

    // Nächstes entmaskiertes Byte ab $i. -1 = Daten reichen nicht, -2 = unmaskiertes Startzeichen
    // mitten im Rahmen (der Rahmen ist abgebrochen, ab hier beginnt ein neuer).
    private static function nextByte(string $buf, int &$i, int $n): int
    {
        if ($i >= $n) {
            return -1;
        }
        $c = ord($buf[$i]);
        if ($c === self::ESC) {
            if ($i + 1 >= $n) {
                return -1;
            }
            $i += 2;
            return ord($buf[$i - 1]);
        }
        if ($c === self::START) {
            return -2;
        }
        $i++;
        return $c;
    }

    // Zerlegt den Anfang von $buf. status: 'none' (kein Startzeichen, alles verwerfbar),
    // 'incomplete', 'crc', 'unsupported' oder 'ok'. 'consumed' = so viele Bytes dürfen weg.
    public static function parseFrame(string $buf): array
    {
        $n = strlen($buf);
        $s = 0;
        while ($s < $n && ord($buf[$s]) !== self::START) {
            $s++;
        }
        if ($s >= $n) {
            return ['status' => 'none', 'consumed' => $n];
        }
        $i = $s + 1;
        $cmd = self::nextByte($buf, $i, $n);
        if ($cmd === -1) {
            return ['status' => 'incomplete', 'consumed' => $s];
        }
        if ($cmd === -2) {
            return ['status' => 'unsupported', 'consumed' => $i];
        }
        if ($cmd !== self::CMD_RESPONSE && $cmd !== self::CMD_LONG_RESPONSE) {
            return ['status' => 'unsupported', 'consumed' => $s + 1];
        }
        $raw = chr($cmd);
        $len = 0;
        $lenBytes = ($cmd === self::CMD_LONG_RESPONSE) ? 2 : 1;
        for ($k = 0; $k < $lenBytes; $k++) {
            $b = self::nextByte($buf, $i, $n);
            if ($b === -1) {
                return ['status' => 'incomplete', 'consumed' => $s];
            }
            if ($b === -2) {
                return ['status' => 'unsupported', 'consumed' => $i];
            }
            $raw .= chr($b);
            $len = ($len << 8) | $b;
        }
        if ($len < 4 || $len > 4096) {
            return ['status' => 'unsupported', 'consumed' => $s + 1];
        }
        $data = '';
        for ($k = 0; $k < $len; $k++) {
            $b = self::nextByte($buf, $i, $n);
            if ($b === -1) {
                return ['status' => 'incomplete', 'consumed' => $s];
            }
            if ($b === -2) {
                return ['status' => 'unsupported', 'consumed' => $i];
            }
            $data .= chr($b);
        }
        $crcIn = 0;
        for ($k = 0; $k < 2; $k++) {
            $b = self::nextByte($buf, $i, $n);
            if ($b === -1) {
                return ['status' => 'incomplete', 'consumed' => $s];
            }
            if ($b === -2) {
                return ['status' => 'unsupported', 'consumed' => $i];
            }
            $crcIn = ($crcIn << 8) | $b;
        }
        if (self::crc16($raw . $data) !== $crcIn) {
            return ['status' => 'crc', 'consumed' => $i];
        }
        return [
            'status'   => 'ok',
            'cmd'      => $cmd,
            'oid'      => unpack('N', substr($data, 0, 4))[1],
            'payload'  => substr($data, 4),
            'consumed' => $i,
        ];
    }

    private function connect(): bool
    {
        if ($this->sock) {
            return true;
        }
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$sock) {
            return false;
        }
        stream_set_timeout($sock, 1);
        $this->sock = $sock;
        $this->buf  = '';
        return true;
    }

    // Liest ein Objekt. Rückgabe: Nutzdaten (kann leer sein) oder null bei Zeitüberschreitung,
    // Verbindungsfehler oder Prüfsummenfehler. Das Protokoll kennt keine Fehlerantwort, ein
    // unverstandener Befehl bleibt einfach unbeantwortet.
    public function read(int $oid): ?string
    {
        // Antwortet das Gerät auf die ersten beiden Anfragen gar nicht, ist es nicht erreichbar:
        // den Zyklus nicht noch viermal auf das Zeitlimit warten lassen.
        if ($this->requests >= 2 && $this->responses === 0) {
            return null;
        }
        if (!$this->connect()) {
            return null;
        }
        $this->requests++;
        $frame = self::buildReadFrame($oid);
        if (@fwrite($this->sock, $frame) !== strlen($frame)) {
            $this->close();
            return null;
        }
        $deadline = microtime(true) + $this->timeout;
        while (microtime(true) < $deadline) {
            $r = self::parseFrame($this->buf);
            if ($r['status'] === 'ok') {
                $this->buf = substr($this->buf, $r['consumed']);
                if ($r['oid'] === $oid) {
                    $this->responses++;
                    return $r['payload'];
                }
                continue;   // Antwort auf etwas anderes (z. B. verspätet), weiterlesen
            }
            if ($r['status'] === 'crc') {
                $this->crcErrors++;
                $this->buf = substr($this->buf, $r['consumed']);
                continue;
            }
            if ($r['status'] === 'unsupported') {
                $this->buf = substr($this->buf, max(1, $r['consumed']));
                continue;
            }
            if ($r['status'] === 'none') {
                $this->buf = '';   // kein Startzeichen im Puffer: alles verwerfen und weiterlesen
            }
            // leer oder unvollständig: mehr Daten holen
            $chunk = @fread($this->sock, 512);
            if ($chunk === false || ($chunk === '' && feof($this->sock))) {
                $this->close();
                return null;
            }
            $this->buf .= $chunk;
        }
        // Zeitüberschreitung: die Verbindung kann einen späten Rest enthalten, also neu aufbauen.
        $this->close();
        return null;
    }

    public function readFloat(int $oid): ?float
    {
        $p = $this->read($oid);
        if ($p === null || strlen($p) !== 4) {
            return null;
        }
        $v = unpack('G', $p)[1];
        return is_finite($v) ? (float)$v : null;
    }

    public function readInt32(int $oid): ?int
    {
        $p = $this->read($oid);
        if ($p === null || strlen($p) !== 4) {
            return null;
        }
        $v = unpack('N', $p)[1];
        return ($v >= 0x80000000) ? $v - 0x100000000 : $v;
    }

    public function readString(int $oid): ?string
    {
        $p = $this->read($oid);
        if ($p === null || $p === '') {
            return null;
        }
        $p = explode("\x00", $p)[0];
        $p = preg_replace('/[^\x20-\x7E]/', '', $p);
        return ($p === '' || $p === null) ? null : $p;
    }
}
