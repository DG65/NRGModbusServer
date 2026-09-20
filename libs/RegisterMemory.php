<?php

declare(strict_types=1);

/**
 * Speicherzellen: beschreibbare Registerzeilen OHNE zugeordnete Variable.
 *
 * Ein Modbus-Server ist im Kern ein Speicher: Was ein Client in ein Register
 * schreibt, liefert der Server beim nächsten Lesen wieder zurück. Ist einer
 * Zeile keine Variable zugeordnet, aber Schreiben erlaubt, arbeitet sie
 * deshalb als Speicherzelle - der geschriebene Wert wird gemerkt (dauerhaft,
 * auch nach Neustart), der 'Festwert' der Zeile ist nur der Startwert.
 *
 * Reiner Rechenkern ohne IPS-Abhängigkeit, CLI-testbar (tests/codec_test.php).
 * Gespeichert wird der Registerwert so, wie er über Modbus übertragen wird
 * (bereits mit dem Faktor der Zeile skaliert) - eine Umrechnung ist nicht nötig.
 */
class MBSLVRegisterMemory
{
    /** IPS-Objekt-IDs beginnen bei 10000; alles darunter gilt als "keine Variable" */
    public const MIN_VARIABLE_ID = 10000;

    /**
     * Ist die (normalisierte) Registerzeile eine Speicherzelle?
     * RPC-Zeilen (Ident) gehören nie dazu, sie haben ihre eigene Logik.
     */
    public static function isMemoryCell(array $row): bool
    {
        if (isset($row['Ident'])) {
            return false;
        }
        if (empty($row['Writable'])) {
            return false;
        }
        return (int) ($row['VariableID'] ?? 0) < self::MIN_VARIABLE_ID;
    }

    /** @return array<string,float> Adresse => Registerwert */
    public static function decode(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $memory = [];
        foreach ($data as $address => $value) {
            if (is_numeric($value) && is_finite((float) $value)) {
                $memory[(string) $address] = (float) $value;
            }
        }
        return $memory;
    }

    public static function encode(array $memory): string
    {
        return json_encode($memory === [] ? new stdClass() : $memory);
    }

    /** Gemerkter Wert der Adresse, sonst der Startwert (Festwert der Zeile) */
    public static function get(array $memory, int $address, float $default): float
    {
        return $memory[(string) $address] ?? $default;
    }

    /** Neuer Speicherstand mit dem Wert; nicht endliche Werte (NaN/INF) werden nicht übernommen */
    public static function with(array $memory, int $address, float $value): array
    {
        if (!is_finite($value)) {
            return $memory;
        }
        $memory[(string) $address] = $value;
        return $memory;
    }

    /**
     * Wirft Einträge weg, deren Adresse keine Speicherzelle mehr ist (Zeile
     * gelöscht oder auf eine Variable umgestellt).
     *
     * @param int[] $addresses Adressen der aktuellen Speicherzellen
     */
    public static function prune(array $memory, array $addresses): array
    {
        return array_intersect_key($memory, array_flip(array_map('strval', $addresses)));
    }
}
