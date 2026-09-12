<?php

declare(strict_types=1);

/**
 * MBSLVTimeoutGuard
 *
 * Reine Dauer-/Ablauf-Arithmetik für die optionale Timeout-Absicherung im
 * generischen Registertabellen-Modus, ohne IPS-Abhängigkeiten (CLI-testbar
 * wie MBSLVModbusServer, siehe tests/).
 *
 * Zweck: Für ein schreibbares Register lässt sich konfigurieren, dass es nach
 * einer bestimmten Dauer ohne neuen Schreibzugriff automatisch auf einen
 * Rückfallwert gesetzt wird - analog dem Meteocontrol-blue'Log-RPC-Muster
 * (Sollwert-Register + separates Gültigkeitsdauer-Register), aber generisch
 * für beliebige Register nutzbar, nicht auf die RPC-Vorlage beschränkt.
 */
class MBSLVTimeoutGuard
{
    public const UNIT_SECONDS = 's';
    public const UNIT_MINUTES = 'min';

    /**
     * Ermittelt die aktuell gültige Timeout-Dauer in Sekunden.
     *
     * @param float      $duration    feste Dauer dieser Regel (gilt, wenn kein Quellwert vorliegt)
     * @param float|null $sourceValue aktueller Wert des optionalen Quell-Registers (null = keins konfiguriert)
     * @param string     $unit        Einheit von $duration bzw. $sourceValue (UNIT_SECONDS|UNIT_MINUTES)
     */
    public static function effectiveSeconds(float $duration, ?float $sourceValue, string $unit): float
    {
        $value = $sourceValue ?? $duration;
        return $unit === self::UNIT_MINUTES ? $value * 60.0 : $value;
    }

    /**
     * true, wenn seit $lastWrite mindestens $seconds vergangen sind.
     * Vor dem ERSTEN Schreibzugriff ($lastWrite <= 0) oder bei deaktivierter
     * Regel ($seconds <= 0) gilt nie als abgelaufen - ein Neustart löst so
     * keinen sofortigen Rückfall aus, und eine Dauer von 0 schaltet die
     * Regel wirkungslos.
     */
    public static function isExpired(int $now, int $lastWrite, float $seconds): bool
    {
        if ($lastWrite <= 0 || $seconds <= 0.0) {
            return false;
        }
        return ($now - $lastWrite) >= $seconds;
    }
}
