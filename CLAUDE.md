# NRGModbusServer — Hinweise für die Arbeit an diesem Repository

## Rolle im NRG-Stack

**Export-Endpunkt**: macht IPS zum Modbus-TCP-Server (Slave), damit externe
Clients/Master (EMS-/SCADA-/Leitsysteme, Direktvermarkter) IPS-Variablen lesen und
schreiben können. Kein `*_GetFunctions`-Vertrag — die Kompatibilitätsgröße
ist die Registertabelle. Die blue'Log-RPC-Emulation ist der
**Direktvermarktungs-Andockpunkt** des Verbunds; künftig Quelle für
`EMS_GetSpecialEvents` (`source: 'marketer'`).

## Technische Eckpunkte

- Hängt unter einer Server-Socket-Instanz; mehrere gleichzeitige Clients pro
  Port, eigener Empfangspuffer je Verbindung (TCP-Fragmentierung behandelt).
- FC 03/04/06/16; uint16/int16/uint32/int32/float32/float64; Word-Order
  ABCD/CDAB umschaltbar. Coils/Discrete Inputs (FC 01/02/05/15) bewusst nicht.
- Vorlagen: Meteocontrol blue'Log RPC (Steuer-Register 5000/5002–5006/5008
  verwaltet das Modul INTERN, nicht in der Registertabelle), SunSpec WR
  dreiphasig (Model 1+113), SunSpec Zähler dreiphasig (Model 1+213).
- Schreiben je Registerzeile (Spalte "Schreiben"): 0 = nur lesen, 1 =
  `RequestAction` wenn die Zielvariable eine Aktion hat (sonst `SetValue`),
  2 = immer `SetValue` (Aktion bewusst umgehen - nötig für Variablen fremder
  Instanzen, z. B. ModBus-Device-Register beim Ersatz eines alten Servers/Slaves).
- Optionale Timeout-Absicherung (Property `RegisterTimeouts`, unabhängig vom
  RPC-Profil): Register X ohne Schreibzugriff seit Dauer Y (fest oder aus
  einem Quell-Register gelesen) -> Rückfallwert. Reiner Rechenkern in
  `libs/TimeoutGuard.php` (`MBSLVTimeoutGuard`, CLI-testbar). Zeitmessung
  nutzt die bestehende `RegisterActivity`-Attribut-Zeitstempel je Adresse -
  WICHTIG: der interne Rückfall-Schreibvorgang läuft über
  `applyValueToTarget()`, NICHT über `writeRegisterValue()`, damit er nicht
  seine eigene Aktivität verbucht und sich dadurch selbst neu bewaffnet
  (sonst käme der Rückfall nie zur Ruhe). Geprüft im bestehenden 60-s-`Watch()`-
  Timer, kein eigener Timer.
- Instanzstatus (`UpdateHealth()`): bewusst geschlossener Server Socket
  (`Open`=false, z. B. vorbereitete Instanz vor dem Cutover) -> `IS_INACTIVE`
  (104), NICHT der eigene Fehlercode `STATUS_NO_SOCKET` (201). Grund: ein
  system-weiter Integrity-Check zählt jeden Status ≠ 102 als Fehler, egal ob
  beabsichtigt - ein daran hängendes Watchdog-Skript hat deshalb am
  13.09.2026 live die produktiven Direktvermarktungs-Sockets einer
  Referenzanlage fälschlich durchgestartet (SUITE.md 9d). 201 bleibt reserviert für den
  echten Fehlerfall (`Open`=true, aber Socket erreicht keinen aktiven Status).

- Speicherzellen (`libs/RegisterMemory.php`, `MBSLVRegisterMemory`): beschreibbare
  Zeile OHNE Variable (`VariableID` < 10000, Schreiben 1/2, kein `Ident`) merkt den
  geschriebenen Registerwert im Attribut `RegisterMemory` (Adresse => Wert, wie
  übertragen, mit Faktor), Festwert = Startwert. Grund: Variablen von
  ModBus-Devices sind schreibgeschützt, `SetValue` von außen scheitert (Referenzanlage
  20.09.2026) - das ModBus-Device liest den Wert stattdessen als Client vom eigenen
  Server zurück. `applyValueToTarget()` und `currentRegisterValue()` kennen den Fall,
  `ApplyChanges()` räumt Einträge gelöschter Zeilen ab. NIE "Ja - Aktion" auf
  Variablen eines ModBus-Devices setzen, das denselben Server abfragt: das schreibt
  per FC16 zurück in den eigenen Server (Endlosschleife).

- Formular (`GetConfigurationForm()`): Reihenfolge nach Verbund-Konvention - Zweck
  (`PurposeIntro`), Neu (`NewsBanner`, `NEWS_VERSION` nur bei wichtigen Änderungen
  anheben), Doku (`DocPanel` in form.json, Version aus der Bibliothek), Fachpanels,
  Lizenz (`LicenseHint`, ganz unten, nicht wegklickbar). Ausblenden wird über alle
  Instanzen geteilt (`PropagateDismiss`/`AdoptDismissState`). Link-Buttons immer
  `'onClick' => "echo '<URL>';", 'link' => true`. Der Feedback-Hinweis (`ForumHint`, Pflicht)
  zeigt auf den Forum-Thread (`FEEDBACK_URL`, Kurzform mit Themen-ID).
- Jeder `ReadProperty…()`/`ReadAttribute…()`-Aufruf trägt einen Typ-Cast (SUITE.md 9c).
- `CreateRowVariables()` liest bewusst NICHT ihren `$RowsJson`-Parameter, sondern immer
  `ReadPropertyString('Registers')`. Grund (Live-Fund Solarpark 22.09.2026): Der Browser-Stand
  eines `List`-Feldes mit `loadValuesFromConfiguration: false` (unsere Registertabelle, wegen
  der injizierten Anzeige-Spalten Wert/Empfangen/Abgefragt) kommt bei einem `onClick` nicht
  zuverlässig als echte Zeilen an - Symcon lieferte teils nur die interne
  "Zeile hinzufügen"-Vorlage (ein Objekt, Adresse 0, leerer Name). NIE wieder einen an ein
  solches Feld gebundenes `$Feldname` aus `onClick` als Dateninhalt vertrauen, nur die
  gespeicherte Property gilt als verlässliche Quelle.

## Keine sichtbaren Hilfsordner im Wurzelverzeichnis

Der Symcon Module Store behandelt jeden sichtbaren Top-Level-Ordner als Modul und verlangt eine
`module.json` (real passiert bei Tibber). Deshalb heißt der Testordner `.tests` mit führendem Punkt.
`libs` bleibt (offizieller Ordner der Symcon-Modulstruktur). Genau EIN Alias je Modul
(`"NRG-Stack Modbus TCP Server"`), Verbundregel seit 20.09.2026.

## Tests

Der Protokollkern `libs/ModbusServer.php` ist IPS-frei und CLI-testbar:
`php .tests/codec_test.php`. Bei jeder Änderung am Codec laufen lassen. Das Modul selbst
läuft mit Stub-Umgebung in `php .tests/module_test.php` (Formular, Speicherzellen).
Manueller Gegentest: `modpoll` (Beispiele im README).

## Branch-Modell

Arbeitsbranch `ems-integration` (verbundweit identisch), Merge nach
`beta`/`main` erst nach Bewährung. Nutzersichtbares deutsch.

## Verbund-Manifest SUITE.md — Bezugsquelle (geändert 31.08.2026)

SUITE.md liegt seit 31.08.2026 NICHT mehr in einem GitHub-Repo (die
Modul-Repos sind öffentlich, SUITE.md enthält das komplette Architektur-/
Debugging-Know-how des Verbunds — Dietmars Entscheidung). Primärquelle ist
ausschließlich die lokale Datei `/Users/dietmar/Nextcloud/Claude/SUITE.md`
auf Dietmars Maschine, versioniert in einem eigenen lokalen Git-Repo ohne
Remote. Frühere Kopien dieses Dokuments wurden zusätzlich aus der Historie
aller Modul-Repos entfernt (`git filter-repo` + Force-Push). Kein
Fallback-Link mehr — ohne lokalen Zugriff auf Dietmars Maschine ist SUITE.md
nicht einsehbar.
