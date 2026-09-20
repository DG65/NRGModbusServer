<?php

declare(strict_types=1);

/**
 * CLI-Test für den Modbus-Server-Kern (ohne IPS): php .tests/codec_test.php
 * Simuliert Anfragen eines Direktvermarkters gegen die blue'Log-RPC-Registerbelegung.
 */

require_once __DIR__ . '/../libs/ModbusServer.php';
require_once __DIR__ . '/../libs/TimeoutGuard.php';
require_once __DIR__ . '/../libs/RegisterMemory.php';

$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "OK   $name\n";
    } else {
        $failures++;
        echo "FAIL $name" . ($detail !== '' ? " - $detail" : '') . "\n";
    }
}

function mbap(int $tid, int $unit, string $pdu): string
{
    return pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($unit) . $pdu;
}

function hexdump(string $s): string
{
    return implode(' ', str_split(bin2hex($s), 4));
}

// --- Testaufbau: RPC-ähnliche Registertabelle -------------------------------

$values = [
    'pAcInv' => 12345.0,   // Register 0 float32 / 100 int32
    'setpointRead' => 60.0 // Register 8 float32
];
$written = [];

$rows = [
    ['Area' => 0, 'Address' => 0, 'DataType' => 'float32', 'Key' => 'pAcInv', 'Writable' => false],
    ['Area' => 0, 'Address' => 8, 'DataType' => 'float32', 'Key' => 'setpointRead', 'Writable' => false],
    ['Area' => 0, 'Address' => 14, 'DataType' => 'float32', 'Key' => 'modus', 'Writable' => false],
    ['Area' => 0, 'Address' => 100, 'DataType' => 'int32', 'Key' => 'pAcInv', 'Writable' => false],
    ['Area' => 0, 'Address' => 5000, 'DataType' => 'float32', 'Key' => 'wSetpoint', 'Writable' => true],
    ['Area' => 0, 'Address' => 5006, 'DataType' => 'float32', 'Key' => 'wValidTime', 'Writable' => true],
    ['Area' => 0, 'Address' => 5008, 'DataType' => 'float32', 'Key' => 'wWatchdog', 'Writable' => true],
    ['Area' => 0, 'Address' => 200, 'DataType' => 'uint16', 'Key' => 'wSingle', 'Writable' => true],
    ['Area' => 0, 'Address' => 210, 'DataType' => 'int16', 'Key' => 'negative', 'Writable' => false],
    ['Area' => 1, 'Address' => 0, 'DataType' => 'uint16', 'Key' => 'inputOnly', 'Writable' => false]
];

$reader = function (array $row) use (&$values): float {
    switch ($row['Key']) {
        case 'modus': return 5.0;
        case 'negative': return -123.0;
        case 'inputOnly': return 777.0;
        default: return $values[$row['Key']] ?? 0.0;
    }
};
$writer = function (array $row, float $value) use (&$written): void {
    $written[$row['Key']] = $value;
};

$server = new MBSLVModbusServer($rows, true, 10, true, MBSLVModbusServer::UNMAPPED_ZERO, $reader, $writer);

// --- Word-Kodierung ----------------------------------------------------------

// Datenblatt: 0xAABBCCDD wird als 0xCCDDAABB übertragen (Low-Word zuerst)
$words = MBSLVModbusServer::valueToWords(30.0, 'float32', true);
// pack('G', 30.0) = 0x41F00000 -> Words [0x41F0, 0x0000] -> geswappt [0x0000, 0x41F0]
check('float32 Word-Swap (30.0)', $words === [0x0000, 0x41F0], json_encode($words));
check('float32 Roundtrip', MBSLVModbusServer::wordsToValue($words, 'float32', true) === 30.0);
check('int32 negativ Roundtrip', MBSLVModbusServer::wordsToValue(MBSLVModbusServer::valueToWords(-5000.0, 'int32', true), 'int32', true) === -5000.0);
check('uint16 Roundtrip', MBSLVModbusServer::wordsToValue(MBSLVModbusServer::valueToWords(65535.0, 'uint16', false), 'uint16', false) === 65535.0);
check('float64 Roundtrip', MBSLVModbusServer::wordsToValue(MBSLVModbusServer::valueToWords(1234.5678, 'float64', true), 'float64', true) === 1234.5678);

// --- FC3: Istwerte lesen (Register 0, 2 Words) -------------------------------

$resp = $server->process(mbap(1, 10, chr(3) . pack('nn', 0, 2)));
$expectWords = MBSLVModbusServer::valueToWords(12345.0, 'float32', true);
$expect = mbap(1, 10, chr(3) . chr(4) . pack('n*', ...$expectWords));
check('FC3 Read float32', $resp === $expect, hexdump((string) $resp));

// --- FC3: Blockleseanfrage über gemappte und unbelegte Register (0..15) ------

$resp = $server->process(mbap(2, 10, chr(3) . pack('nn', 0, 16)));
check('FC3 Blocklesen 16 Register', $resp !== null && strlen($resp) === 9 + 32 && ord($resp[7]) === 3);
$payload = array_values(unpack('n*', substr((string) $resp, 9)));
check('FC3 unbelegte Register = 0', $payload[4] === 0 && $payload[5] === 0, json_encode(array_slice($payload, 0, 8)));
$modusWords = array_slice($payload, 14, 2);
check('FC3 Register 14 im Block (Modus 5)', MBSLVModbusServer::wordsToValue($modusWords, 'float32', true) === 5.0);

// --- FC4: Input-Register getrennt vom Holding-Bereich -------------------------

$resp = $server->process(mbap(3, 10, chr(4) . pack('nn', 0, 1)));
check('FC4 Input-Register', $resp === mbap(3, 10, chr(4) . chr(2) . pack('n', 777)));

// --- FC16: Sollwertvorgabe schreiben (modpoll-Beispiel: 30 % auf Register 5000)

$resp = $server->process(mbap(4, 10, chr(16) . pack('nnC', 5000, 2, 4) . pack('n*', ...MBSLVModbusServer::valueToWords(30.0, 'float32', true))));
check('FC16 Antwort', $resp === mbap(4, 10, chr(16) . pack('nn', 5000, 2)), hexdump((string) $resp));
check('FC16 Sollwert 30% angekommen', ($written['wSetpoint'] ?? null) === 30.0, json_encode($written));

// --- FC16: Sammelschreiben Sollwert + Reserve + Gültigkeitsdauer (5000..5007) --

$written = [];
$block = array_merge(
    MBSLVModbusServer::valueToWords(45.5, 'float32', true),
    [0, 0, 0, 0], // Reserve 5002-5005 (hier unbelegt)
    MBSLVModbusServer::valueToWords(15.0, 'float32', true)
);
$resp = $server->process(mbap(5, 10, chr(16) . pack('nnC', 5000, 8, 16) . pack('n*', ...$block)));
check('FC16 Sammelschreiben Antwort', $resp === mbap(5, 10, chr(16) . pack('nn', 5000, 8)));
check('FC16 Sammelschreiben Werte', ($written['wSetpoint'] ?? null) === 45.5 && ($written['wValidTime'] ?? null) === 15.0, json_encode($written));

// --- FC6: Einzelregister schreiben --------------------------------------------

$written = [];
$resp = $server->process(mbap(6, 10, chr(6) . pack('nn', 200, 4711)));
check('FC6 Antwort (Echo)', $resp === mbap(6, 10, chr(6) . pack('nn', 200, 4711)));
check('FC6 Wert angekommen', ($written['wSingle'] ?? null) === 4711.0);

// --- Schreiben auf nicht schreibbares Register wird ignoriert ------------------

$written = [];
$server->process(mbap(7, 10, chr(16) . pack('nnC', 0, 2, 4) . pack('n*', 0, 0)));
check('Nicht schreibbares Register ignoriert', $written === []);

// --- Falsche Unit-ID -> Exception 0x0B ----------------------------------------

$resp = $server->process(mbap(8, 99, chr(3) . pack('nn', 0, 2)));
check('Falsche Unit-ID -> Exception 0x0B', $resp === mbap(8, 99, chr(3 | 0x80) . chr(0x0B)), hexdump((string) $resp));

// --- Unbekannter Function Code -> Exception 0x01 -------------------------------

$resp = $server->process(mbap(9, 10, chr(0x2B) . 'xx'));
check('FC 0x2B -> Exception 0x01', $resp === mbap(9, 10, chr(0x2B | 0x80) . chr(0x01)));

// --- Exception-Modus für unbelegte Register ------------------------------------

$strict = new MBSLVModbusServer($rows, true, 10, true, MBSLVModbusServer::UNMAPPED_EXCEPTION, $reader, $writer);
$resp = $strict->process(mbap(10, 10, chr(3) . pack('nn', 9000, 1)));
check('Unbelegt strikt -> Exception 0x02', $resp === mbap(10, 10, chr(3 | 0x80) . chr(0x02)));

// --- Fragmentierung: Frame in zwei TCP-Segmenten --------------------------------

$frame = mbap(11, 10, chr(3) . pack('nn', 0, 2));
[$frames, $rest] = MBSLVModbusServer::extractFrames(substr($frame, 0, 5));
check('Fragment: unvollständig gepuffert', $frames === [] && $rest === substr($frame, 0, 5));
[$frames, $rest] = MBSLVModbusServer::extractFrames($rest . substr($frame, 5));
check('Fragment: Frame komplettiert', count($frames) === 1 && $frames[0] === $frame && $rest === '');

// --- Zwei Frames in einem Segment ----------------------------------------------

$two = $frame . mbap(12, 10, chr(3) . pack('nn', 8, 2));
[$frames, $rest] = MBSLVModbusServer::extractFrames($two);
check('Zwei Frames in einem Paket', count($frames) === 2 && $rest === '');

// --- Datenmüll wird verworfen ---------------------------------------------------

[$frames, $rest] = MBSLVModbusServer::extractFrames("GET / HTTP/1.1\r\n");
check('Datenmüll verworfen', $frames === [] && $rest === '');

// --- MBSLVTimeoutGuard: Timeout-Absicherung generischer Register ---------------

check('Timeout: fest, Sekunden, noch nicht abgelaufen', MBSLVTimeoutGuard::isExpired(1000, 950, MBSLVTimeoutGuard::effectiveSeconds(300, null, 's')) === false);
check('Timeout: fest, Sekunden, genau abgelaufen (>=)', MBSLVTimeoutGuard::isExpired(1300, 1000, MBSLVTimeoutGuard::effectiveSeconds(300, null, 's')) === true);
check('Timeout: fest, Minuten-Einheit wird umgerechnet', MBSLVTimeoutGuard::effectiveSeconds(5, null, 'min') === 300.0);
check('Timeout: Quell-Register überschreibt feste Dauer', MBSLVTimeoutGuard::effectiveSeconds(300, 5.0, 'min') === 300.0);
check('Timeout: Quell-Register in Sekunden', MBSLVTimeoutGuard::effectiveSeconds(9999, 42.0, 's') === 42.0);
check('Timeout: nie geschrieben (lastWrite<=0) -> nie abgelaufen', MBSLVTimeoutGuard::isExpired(999999, 0, 1.0) === false);
check('Timeout: Dauer 0 deaktiviert die Regel', MBSLVTimeoutGuard::isExpired(999999, 1, 0.0) === false);
check('Timeout: Direktvermarktungs-Beispiel (5006=5 min, 10s-Zyklus, nach 4 min noch gültig)', MBSLVTimeoutGuard::isExpired(1000 + 240, 1000, MBSLVTimeoutGuard::effectiveSeconds(0, 5.0, 'min')) === false);
check('Timeout: Direktvermarktungs-Beispiel (nach 6 min abgelaufen)', MBSLVTimeoutGuard::isExpired(1000 + 360, 1000, MBSLVTimeoutGuard::effectiveSeconds(0, 5.0, 'min')) === true);

// --- MBSLVRegisterMemory: Speicherzellen (beschreibbar, ohne Variable) ---------

$cell = ['Address' => 5000, 'VariableID' => 0, 'Writable' => 2, 'Fixed' => 100.0];
check('Speicherzelle: beschreibbar + ohne Variable', MBSLVRegisterMemory::isMemoryCell($cell) === true);
check('Speicherzelle: Modus "Ja - Aktion" zählt ebenso', MBSLVRegisterMemory::isMemoryCell(['VariableID' => 0, 'Writable' => 1]) === true);
check('Speicherzelle: nur lesen ist keine', MBSLVRegisterMemory::isMemoryCell(['VariableID' => 0, 'Writable' => 0]) === false);
check('Speicherzelle: mit Variable ist keine', MBSLVRegisterMemory::isMemoryCell(['VariableID' => 12345, 'Writable' => 2]) === false);
check('Speicherzelle: RPC-Zeile (Ident) ist keine', MBSLVRegisterMemory::isMemoryCell(['Ident' => 'RPC_SETPOINT', 'Writable' => true]) === false);
check('Speicherzelle: ungültige Variable-ID unter 10000 gilt als "keine Variable"', MBSLVRegisterMemory::isMemoryCell(['VariableID' => 5, 'Writable' => 1]) === true);

$mem = MBSLVRegisterMemory::decode('{}');
check('Speicherzelle: leer -> Startwert (Festwert)', MBSLVRegisterMemory::get($mem, 5000, 100.0) === 100.0);
$mem = MBSLVRegisterMemory::with($mem, 5000, 40.5);
check('Speicherzelle: geschriebener Wert kommt zurück', MBSLVRegisterMemory::get($mem, 5000, 100.0) === 40.5);
check('Speicherzelle: andere Adresse bleibt beim Startwert', MBSLVRegisterMemory::get($mem, 5006, 5.0) === 5.0);
$roundTrip = MBSLVRegisterMemory::decode(MBSLVRegisterMemory::encode($mem));
check('Speicherzelle: übersteht Speichern/Laden (JSON)', MBSLVRegisterMemory::get($roundTrip, 5000, 0.0) === 40.5);
check('Speicherzelle: leerer Speicher wird als {} gespeichert', MBSLVRegisterMemory::encode([]) === '{}');
check('Speicherzelle: NaN wird nicht übernommen', MBSLVRegisterMemory::with($mem, 5000, NAN) === $mem);
check('Speicherzelle: INF wird nicht übernommen', MBSLVRegisterMemory::with($mem, 5000, INF) === $mem);
check('Speicherzelle: kaputtes JSON -> leerer Speicher', MBSLVRegisterMemory::decode('kaputt') === []);
$two = MBSLVRegisterMemory::with(MBSLVRegisterMemory::with([], 5000, 1.0), 5006, 2.0);
$pruned = MBSLVRegisterMemory::prune($two, [5006]);
check('Speicherzelle: prune behält nur noch vorhandene Zellen', array_keys($pruned) == [5006] && $pruned[5006] === 2.0);
check('Speicherzelle: prune ohne Zellen leert den Speicher', MBSLVRegisterMemory::prune($two, []) === []);

// Zusammenspiel mit dem Protokollkern: Master schreibt FC16, dann liest er FC03 zurück
$stored = [];
$server = new MBSLVModbusServer(
    [['Area' => MBSLVModbusServer::AREA_HOLDING, 'Address' => 5000, 'DataType' => 'float32', 'VariableID' => 0, 'Factor' => 1.0, 'Fixed' => 100.0, 'Writable' => 2]],
    false, 1, false, MBSLVModbusServer::UNMAPPED_ZERO,
    function (array $row) use (&$stored): float {
        return MBSLVRegisterMemory::get($stored, (int) $row['Address'], (float) $row['Fixed']);
    },
    function (array $row, float $value) use (&$stored): void {
        if (MBSLVRegisterMemory::isMemoryCell($row)) {
            $stored = MBSLVRegisterMemory::with($stored, (int) $row['Address'], $value);
        }
    },
    function (string $topic, string $message): void {
    }
);
$read = mbap(20, 1, chr(3) . pack('nn', 5000, 2));
$respFloat = function (string $resp): float {
    return unpack('G', substr($resp, 9, 4))[1];
};
check('Speicherzelle E2E: vor dem ersten Schreiben liefert sie den Startwert', abs($respFloat($server->process($read)) - 100.0) < 1e-6);
$server->process(mbap(21, 1, chr(16) . pack('nnC', 5000, 2, 4) . pack('G', 37.5)));
check('Speicherzelle E2E: nach FC16 liefert FC03 den geschriebenen Wert', abs($respFloat($server->process($read)) - 37.5) < 1e-6);

echo $failures === 0 ? "\nAlle Tests bestanden.\n" : "\n$failures Test(s) fehlgeschlagen!\n";
exit($failures === 0 ? 0 : 1);
