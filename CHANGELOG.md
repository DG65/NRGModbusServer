# Changelog

## 1.8.0 (2026-09-12)

- Neu: optionale Timeout-Absicherung für schreibbare Register im generischen Modus (Panel "⏱ Timeout-Absicherung") - unabhängig vom RPC-Profil. Für einzelne Adressen konfigurierbar: feste Dauer ODER Dauer aus einem Quell-Register (z. B. Meteocontrol blue'Log Register 5006, vom Master selbst mitgeschrieben), Einheit Sekunden/Minuten, Rückfallwert. Zeit läuft erst ab dem ersten echten Schreibzugriff, geprüft im bestehenden 60-s-Takt, Live-Statusspalte je Regel, Rückfallzähler in der Verbindungs-Kopfzeile. Reiner Rechenkern in libs/TimeoutGuard.php, CLI-testbar wie der Protokollkern

- Neu: Registertabelle zeigt eine Spalte "Wert" mit dem aktuellen Registerinhalt (skaliert, wie über Modbus übertragen) - reine Anzeige, aktualisiert sich wie "Empfangen"/"Abgefragt" beim Öffnen des Formulars, löst dafür keine eigene Zugriffszeit aus
- Verbesserung: Lehnt das Modul einen Schreibversuch ab (z. B. nicht beschreibbares Register), steht der abgelehnte Wert jetzt mit im Debug-Log statt nur "Wert verworfen"

## 1.7.3 (2026-09-04)

- Fix: Live-Aktualisierung aus 1.7.2 wieder entfernt - `UpdateFormField` auf die Registertabelle (ein speicherbares Listenfeld) hat ein offenes Formular bei jedem Modbus-Zugriff als "geändert" markiert und ständig "Änderungen übernehmen" verlangt, obwohl der Nutzer nichts geändert hatte. Auf einer Live-Instanz mit häufigem Verkehr praktisch unbenutzbar. "Empfangen"/"Abgefragt" aktualisieren sich wie in 1.7.1 beim (erneuten) Öffnen des Formulars, kein automatisches Live-Update

## 1.7.2 (2026-09-04)

- Verbesserung: Ein geöffnetes Konfigurationsformular aktualisiert die Spalten "Empfangen"/"Abgefragt" sowie die Verbindungs-Kopfzeile jetzt live bei jedem eingehenden Modbus-Zugriff, statt nur beim manuellen Neuladen der Seite

## 1.7.1 (2026-09-04)

- Neu: Registertabelle zeigt je Zeile zwei zusätzliche Spalten "Empfangen" (zuletzt von einem Master geschrieben) und "Abgefragt" (zuletzt von einem Master gelesen) - macht sichtbar, ob und wann tatsächlich Datenverkehr auf einem konkreten Register ankommt, statt nur die eine globale Variable "Letzte Modbus-Anfrage" zu haben. Neue öffentliche Funktion `MBSLV_GetRegisterActivity()` liefert dieselben Zeiten als JSON für eigene Skripte. Zugriffe eines Empfangs-Batches werden gesammelt und in einem Schreibzugriff persistiert (keine zusätzliche Last je Einzelregister)

## 1.7.0 (2026-09-03)

- Neu: dreistufiger Schreibmodus je Registerzeile (Spalte "Schreiben": Nein / Ja - Aktion / Ja - direkt). "Direkt" schreibt immer per SetValue und umgeht die Aktion der Variable - nötig, wenn die verknüpfte Variable einer anderen Instanz gehört (typisch: Register eines ModBus-Device beim Ersatz eines bisherigen Slaves), deren Aktion sonst versuchen würde, den Wert in ein fremdes Gerät zu schreiben. Bestehende Konfigurationen (true/false) werden automatisch als Ja-Aktion/Nein gelesen
- Doku: Anleitung "Bestehenden Modbus-Slave ersetzen" (Simulator wie ModRSsim2, SPS) im Formular und README - Registertabelle 1:1 nachbilden, gleiche Variablen verknüpfen, Port übernehmen, Rückweg

## 1.6.8 (2026-09-01)

- Dokumentation (keine Verhaltensänderung): Register 5002 als PPC_P_SET_RPC_ABS (absoluter Watt-Sollwert, Geschwister von 5000/REL) benannt und mit einem an einer echten Anlage bestätigten Befund versehen - dort wird ausschließlich ABS geschrieben, REL bleibt unangetastet. Unser Modul behandelt 5002 weiterhin nur als Passthrough, bis geklärt ist, ob ein Direktvermarkter auch ABS statt REL in unsere Slave-Emulation schreiben könnte

## 1.6.7 (2026-08-20)

- Neu: Button "🔄 Übernehmen erzwingen (ohne Formularänderung)" - ruft direkt IPS_ApplyChanges() auf, praktisch nach jedem Modul-Update, um neue Variablen/Zeitgeber ohne Formularänderung nachzuziehen (Vorschlag aus dem Verbund, EMS-Modul)

## 1.6.6 (2026-08-20)

- Verbund-Audit "Sichtbare Rückmeldung bei jeder Aktion" (SUITE.md, verbindlich): die drei Formular-Buttons (Vorlage laden, Datenpunkte anlegen, weitere Schnittstellen anlegen) geben jetzt einen Ergebnistext mit ✅/⚠️/⛔-Präfix per `return` zurück; die onClick-Handler rufen explizit `echo Prefix_Methode(...)` auf statt sich auf internes Echo zu verlassen - macht die Rückmeldung robust und die Methoden zusätzlich per Skript testbar (Rückgabewert statt reinem Seiteneffekt)

## 1.6.5 (2026-08-20)

- Verbindungs-Kopfzeile im Formular auf die verbundweite Status-Kopfzeilen-Konvention umgestellt: eine Zeile, Icon + Kernaussage + Zeitstempel statt Fließtext (✅ erreichbar mit Zeitpunkt der letzten Modbus-Anfrage, ⚠️ erreichbar aber noch keine Anfrage, ❌ Server Socket inaktiv, ℹ️ kein Socket verbunden). Erklärungstext zum Port bleibt im Doku-Panel, wo er bereits stand

## 1.6.4 (2026-08-19)

- Check-Style-CI nachgereicht (.github/workflows/check-style.yml: php -l über alle Dateien plus Protokollkern-Tests bei jedem Push/PR) und Check-Style-Badge in README ergänzt - Workflow-Scope-Blocker war zwischenzeitlich behoben

## 1.6.3 (2026-08-19)

- Verbund-Konvention README-Badges umgesetzt (Symcon/Version/Lizenz/PayPal unter der Überschrift; Modul-Version-Badge wird bei jedem Versions-Bump mitgepflegt). Der Check-Style-CI-Badge folgt zusammen mit dem Workflow, sobald das GitHub-Token mit workflow-Scope hinterlegt ist - gemäß Konvention kein Badge ohne echten Workflow

## 1.6.2 (2026-07-29)

- Verbund-Namenskonvention: sichtbarer Bibliotheksname jetzt "NRG-Stack ModbusSlave", Instanzsuche findet zusätzlich "NRG-Stack Modbus TCP Slave" (nur Anzeigenamen - GUID, PHP-Klassenname und Idents unverändert, bestehende Instanzen und Git-Updates bleiben unberührt)

## 1.6.1 (2026-07-27)

- Usability (Verbund-Audit): Immer sichtbare Verbindungszeile oben im Formular ("Erreichbar auf Port X" bzw. konkrete Anleitung, wenn der Server Socket nicht aktiv ist) - der Port lag bisher unauffindbar nur im eingeklappten Doku-Panel
- RPC-Panel stellt jetzt klar, dass KEIN echtes blue'Log-Gerät benötigt wird (IPS übernimmt dessen Rolle; funktioniert mit jedem Direktvermarkter, der die blue'Log-RPC-Schnittstelle unterstützt)
- Sichtbar dokumentiert, welche Register-Zuordnungen manuell erfolgen (Istwerte) und welche automatisch (Sollwert-Register über den Vorlage-Button) - stand bisher nur in einer flüchtigen Meldung nach dem Button-Klick

## 1.6.0 (2026-07-27)

- Neue Vorlage: SunSpec Zähler dreiphasig (Common Model 1 + Model 213, float32) - IPS kann damit gegenüber Wallbox-/EMS-Systemen (z. B. evcc, openWB) als SunSpec-Netzzähler auftreten. Registerlayout generiert aus der offiziellen SunSpec-Modelldefinition (github.com/sunspec/models)

## 1.5.0 (2026-07-27)

- Statusampel (Verbund-Zielbild "Zuverlässigkeit ohne KI-Krücke"): Instanzstatus spiegelt jetzt sichtbar den Betriebszustand - Fehlerstatus 201 bei inaktivem Server Socket, Fehlerstatus 202 wenn innerhalb der konfigurierbaren Kommunikationsüberwachung (Minuten, 0 = aus) keine Modbus-Anfrage eintraf ("Master pollt nicht mehr")
- Neue Variable "Letzte Modbus-Anfrage" (Zeitstempel, max. alle 5 s aktualisiert) als Lebenszeichen ohne Log-Zugriff

## 1.4.1 (2026-07-26)

- Härtung gemäß Verbund-Erkenntnis (SUITE.md-Stolperstein 3): RegisterVariableXXX wird nur noch bei echter Neuanlage aufgerufen (Guard über GetIDForIdent), sowohl in ApplyChanges (RPC-Variablen) als auch im Datenpunkte-Button

## 1.4.0 (2026-07-25)

- Verbund-Umbenennung mit NRG-Präfix: Bibliothek heißt jetzt "NRGModbusSlave", Repo-URL auf github.com/DG65/NRGModbusSlave aktualisiert. Der Modulname in module.json bleibt "ModbusTCPSlave" (= PHP-Klassenname, von IPS per Reflection gesucht - Umbenennung würde das Modul zerschossen); "NRGModbusTCPSlave" ist als Alias suchbar. GUID, Prefix (MBSLV) und Variablen-Idents unverändert

## 1.3.0 (2026-07-12)

- Neu: Button "Datenpunkte für unzugeordnete Register anlegen" - legt für alle Tabellenzeilen ohne zugeordnete Variable einen Datenpunkt unter der Instanz an (float für float32/64, sonst integer) und trägt ihn direkt in die Tabelle ein. Zeilen mit Festwert (Header/Konstanten, z. B. SunSpec-Modell-IDs) bleiben unangetastet; wiederholtes Ausführen verwendet vorhandene Datenpunkte wieder

## 1.2.1 (2026-07-12)

- RPC-Aktivierung ins Vorlagen-Popup integriert: Die RPC-Vorlage aktiviert die Schnittstelle und blendet das Einstellungs-Panel "Meteocontrol RPC / Direktvermarktung" ein - solange RPC nicht aktiviert ist, wird das Panel gar nicht angezeigt
- Kopfzeile wieder einzeilig, nutzt jetzt die volle Formularbreite mit größeren Abständen zwischen den Feldern

## 1.2.0 (2026-07-12)

- Neu: SunSpec-Vorlage (Common Model 1 + Wechselrichter Model 113, float32, Basis 40000)
- Vorlagen jetzt über Popup-Button "Vorlage laden..." wählbar (setzt auch die passende Word-Order)
- Dynamisches Formular: RPC-Einstellungen werden nur bei aktivierter RPC-Schnittstelle angezeigt; Hinweis ergänzt, dass die Steuer-Register 5000/5006/5008 intern verwaltet werden und nicht in der Tabelle erscheinen
- Formular aufgelockert (zwei Zeilen statt einer) und Option "Antwort beim Lesen unbelegter Register" verständlicher beschriftet und in der Doku erklärt

## 1.1.0 (2026-07-12)

- Neu: Formular-Aktion "Weitere Schnittstellen anlegen" - erzeugt pro angegebenem Port (z. B. "501-505") eine Kopie der Instanz samt Server Socket; bereits belegte Ports werden übersprungen. Damit lassen sich Mehrfach-Anbindungen (z. B. Solarpark mit fünf Gegenstellen auf Ports 501-505) aus einer fertig konfigurierten Instanz heraus aufbauen

## 1.0.2 (2026-07-12)

- Port des Server Sockets nicht mehr durch das Modul erzwungen (GetConfigurationForParent entfernt) - der Port ist jetzt am Socket frei einstellbar, z. B. für mehrere Slave-Instanzen auf unterschiedlichen Ports

## 1.0.1 (2026-07-12)

- Texte und Standardwerte neutralisiert: das Modul präsentiert sich als generischer Modbus-TCP-Slave, die blue'Log-RPC-Emulation ist eine optionale Vorlage (Defaults jetzt Unit-ID 1 und Word-Order ABCD; die RPC-Vorlage setzt weiterhin Unit-ID 10 und CDAB)

## 1.0.0 (2026-07-12)

- Erstversion: generisches Modbus-TCP-Slave-Modul (FC 03/04/06/16, uint16/int16/uint32/int32/float32/float64, umschaltbare Word-Reihenfolge, frei konfigurierbare Registertabelle mit Variablen-Mapping, Faktor und Festwerten)
- Meteocontrol blue'Log XM/XC RPC-Emulation für die Direktvermarktung: Register 5000/5002–5005/5006/5008 mit Gültigkeits- und Watchdog-Logik, Rückfall-Sollwert, Registervorlage für die Istwert-Register per Formular-Button
- IPS-freier, CLI-testbarer Protokollkern (tests/codec_test.php)
