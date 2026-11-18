<?php
// NRGModbus 0.2.0 quelle:eb736be015ea | Kopie, nicht ändern (Quelle: NRGModbus)
// Lädt die Bibliothek in der richtigen Reihenfolge. Die Module binden nur diese Datei ein:
//   require_once __DIR__ . '/../libs/NRGModbus/autoload.php';
require_once __DIR__ . '/TransportState.php';
require_once __DIR__ . '/ModbusTransport.php';
require_once __DIR__ . '/ModbusDecode.php';
require_once __DIR__ . '/TcpTransport.php';
require_once __DIR__ . '/GatewayTransport.php';
require_once __DIR__ . '/ModbusClient.php';
