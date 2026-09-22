# Changelog

## 1.12.4 (2026-09-22)

- Fix (Live-Fund Solarpark, Folgefehler von 1.12.3): "Datenpunkte für unzugeordnete Register anlegen" lieferte nach dem ersten Fix zwar keinen Fatal Error mehr, aber ein falsches Ergebnis - Symcon übergibt den Browser-Stand der Registertabelle beim Klick auf diesen Button nicht zuverlässig, teils nur die interne "Zeile hinzufügen"-Vorlage (ein einzelnes Objekt mit Adresse 0, leerem Namen). Dadurch legte das Modul eine Fantasie-Variable "Register 0" an und überschrieb die ANZEIGE im offenen Formular mit dieser einen Zeile - die echten Zeilen wirkten verschwunden (tatsächlich nur die Anzeige betroffen, die gespeicherte Konfiguration blieb unangetastet, solange nicht zusätzlich "Änderungen übernehmen" geklickt wurde). Die Methode liest jetzt ausschließlich die gespeicherte Konfiguration, der übergebene Parameter wird bewusst ignoriert. Eine gerade erst hinzugefügte, noch nicht übernommene Zeile bekommt ihren Datenpunkt deshalb erst nach dem Speichern - im Formular und der README ergänzt. Regressionstests umgebaut (Gegenprobe: schlagen gegen 1.12.3 fehl, laufen gegen diese Version durch)

## 1.12.3 (2026-09-22)

- Fix (Live-Fund Solarpark, Fatal Error beim Klick auf "Datenpunkte für unzugeordnete Register anlegen"): Enthielt die Registertabelle genau EINE Zeile, lieferte Symcon `$Registers` als einzelnes Zeilen-Objekt statt als Array mit einem Element - `json_encode()` daraus ergab `{...}` statt `[{...}]`. Die Methode `CreateRowVariables()` lief dadurch über die FELDER der einen Zeile statt über die Zeile selbst und stürzte mit "Cannot access offset of type string on string" ab. Erkennung per `array_is_list()`, zusätzliche Absicherung gegen weitere Anlieferungs-Eigenheiten. Neue Regressionstests (Gegenprobe: schlagen gegen den alten Code fehl, laufen gegen den neuen durch)

## 1.12.2 (2026-09-21)

- Der Lizenz-Link im Formular ("Über dieses Modul") zeigt auf den Branch `beta` statt `main`: `beta` ist der Branch, den der Store-Beta-Kanal ausliefert und der die aktuelle PolyForm-Lizenz trägt; `main` wird erst mit dem späteren Wechsel nach `main` wieder gepflegt

## 1.12.1 (2026-09-21)

- Der Feedback-Hinweis zeigt jetzt auf den Modul-Thread im Symcon-Forum (Kurzadresse mit Themen-ID, bleibt auch bei einer Titeländerung gültig) statt auf die GitHub-Issues; Titel "💬 Feedback im Symcon-Forum", Button "Zum Forums-Thread"

## 1.12.0 (2026-09-21)

- Feedback-Hinweis nach der Verbund-Konvention (SUITE.md "Einheitliche Formular-Optik", Punkt 4, Pflicht für jedes Modul): Panel "💬 Feedback" nach den Fachpanels und vor "Über dieses Modul", einmalig wegklickbar (Ausblenden gilt für alle Instanzen, neue Instanzen übernehmen den Stand; neue öffentliche Funktion `MBSLV_AckForumHint`, neues Attribut `ForumHintGone`). Weil es noch keinen Thread im Symcon-Forum gibt, zeigt der Link-Button auf die GitHub-Issues des Repos (wie bei OCPPHub vor seinem Thread); sobald es einen Thread gibt, wird die Adresse umgestellt. "Neu in Version"-Panel entsprechend auf 1.12 mit eigenem Eintrag

## 1.11.2 (2026-09-21)

- Fix (Live-Fund, Konfigurationsformular öffnete nicht): Die Versionsangabe im Doku-Panel (seit 1.11.0) las die Bibliotheks-ID aus einem Feld, das `IPS_GetInstance()` nicht liefert; die dabei entstehenden PHP-Warnungen standen vor dem Formular-JSON und machten das Formular unlesbar ("Konnte Konfigurationsform nicht laden"). Die ID wird jetzt über `IPS_GetModule()` geholt und abgesichert. Der Stub-Test bildet das Symcon-Verhalten nun genauer nach und bricht bei jeder PHP-Warnung ab
- Fix: Die Spalte "Wert" der Registertabelle zeigte unter deutscher Locale "100," statt "100" (sprintf folgt der Locale); jetzt locale-unabhängig

## 1.11.1 (2026-09-21)

- Verbund-Konventionen nachgezogen (Abgleich mit EMS): genau EIN Alias je Modul ("NRG-Stack Modbus TCP Server") statt sieben - jeder Alias erscheint in "Instanz hinzufügen" als eigener Eintrag und wirkte wie ein Duplikat. Die Suchbegriffe "Slave" entfallen damit (Preis der Regel); GUID, Klassenname und bestehende Instanzen sind nicht betroffen. Testordner von `tests` nach `.tests` (der Store-Scanner behandelt jeden sichtbaren Top-Level-Ordner als Modul und verlangt eine module.json). library.json: Kompatibilität 9.0 (auf IP-Symcon 9.0 getestet, wie bei allen Verbund-Modulen) und ein echtes Datum statt 0

## 1.11.0 (2026-09-21)

- Formular nach der verbundweiten Konvention (SUITE.md "Einheitliche Formular-Optik"): "👋 Wozu dieses Modul?" ganz oben (einmalig wegklickbar), "🆕 Neu in Version X.Y" (aufgeklappt, pro Version wegklickbar), "📖 Dokumentation & Hilfe" (eingeklappt, mit Versionsangabe, vorher "📖 Doku"), die Verbindungszeile folgt darunter, ganz unten "🧡 Über dieses Modul" (Lizenz, Spenden-Link; nicht wegklickbar). Das Wegklicken von "Wozu"/"Neu" gilt für alle Instanzen dieses Moduls und wird von neu angelegten Instanzen übernommen (neue öffentliche Funktionen `MBSLV_AckPurposeIntro`, `MBSLV_AckNews`, `MBSLV_AdoptDismissState`, `MBSLV_GetDismissState`; neue Attribute `PurposeIntroGone`, `SeenNews`). Ein Forum-Hinweis fehlt noch, weil es keinen Modul-Thread gibt
- Verbund-Regel 9c: `ReadProperty…()`/`ReadAttribute…()` werden nie mehr ungecastet an typisierte Funktionen oder `json_decode()` weitergereicht (während des Neuladens einer Instanz liefert das SDK `false`, mit strict_types ein TypeError)
- Neu: `tests/module_test.php` lässt das echte module.php in einer Stub-Umgebung ohne IPS laufen (Formularaufbau, geteiltes Ausblenden, Speicherzellen inkl. Aufräumen), zusätzlich in der CI
- README: Zeile "Teil des NRG-Stack"

## 1.10.1 (2026-09-21)

- Store-Vorbereitung (Verbund-Checkliste, Neuinstallations-Simulation): Der Button "Instanzen anlegen" (Weitere Schnittstellen) fragt jetzt vor dem Anlegen ausdrücklich nach und nennt, dass Instanzen samt Server Socket angelegt und die Ports sofort geöffnet werden; die eigene Instanz bleibt dabei unverändert (nur deren gespeicherte Konfiguration wird kopiert, geschrieben wird ausschließlich auf die neuen Instanzen). Bezüge auf eine konkrete Anlage aus Code-Kommentaren, Changelog, Entwicklerhinweisen und Testnamen entfernt (neutral als "produktive Anlage" bzw. "Referenzanlage" formuliert). Keine Änderung am Verhalten der Register- oder Protokolllogik

## 1.10.0 (2026-09-21)

- Neu: Speicherzellen - eine beschreibbare Registerzeile OHNE zugeordnete Variable merkt sich den vom Master geschriebenen Wert (dauerhaft, auch nach Neustart) und liefert ihn beim Lesen zurück, wie ein klassischer Modbus-Server (z. B. ModRSsim2). Der Festwert ist der Startwert bis zum ersten Schreibzugriff, gemerkt wird der Registerwert wie übertragen (bereits mit Faktor). Behebt den Fall, dass ein Direktvermarkter Sollwerte schreibt, die ein ModBus-Device zurückliest: dessen Variablen sind schreibgeschützt, `SetValue` von außen scheiterte dort mit "Variable is marked as read-only" (Live-Befund an einer produktiven Anlage 20.09.2026, der Schreibmodus "Ja - direkt" war für Variablen von ModBus-Devices nie bestätigt). Timeout-Absicherung funktioniert mit Speicherzellen (Rückfallwert wird in die Zelle geschrieben, Quell-Register darf eine Speicherzelle sein). Verhaltensänderung: beschreibbare Zeilen ohne Variable verwarfen den Wert bisher stillschweigend, sie merken ihn jetzt. Reiner Rechenkern in libs/RegisterMemory.php, CLI-getestet (18 neue Tests, u. a. Schreiben per FC16 und Zurücklesen per FC03)
- Doku: Formular-Panel, README und Anleitung "Bestehenden Modbus-Server ersetzen" beschreiben die Speicherzelle und ihren Unterschied zu "Ja - direkt"; Spalte "Festwert" heißt jetzt "Festwert / Startwert"

## 1.9.0 (2026-09-20)

- Umbenennung Slave -> Server, passend zur Begriffswahl der Modbus-Spezifikation (Client/Server): Bibliothek "NRG-Stack ModbusServer", Modul und PHP-Klasse "ModbusTCPServer" (Ordner ModbusTCPServer), Repo github.com/DG65/NRGModbusServer (die alte URL leitet GitHub weiter). Modul-GUID, Präfix MBSLV_, Idents, Eigenschaften und Variablen unverändert - bestehende Instanzen bleiben zugeordnet, Skripte mit MBSLV_-Aufrufen laufen weiter. Die bisherigen Namen "Modbus TCP Slave", "ModbusTCPSlave" und "NRGModbusTCPSlave" bleiben als Suchbegriffe erhalten. Neu angelegte Server Sockets heißen "Server Socket (Modbus TCP Server Port ...)"
- Update-Hinweis: Wegen des geänderten Klassennamens nach dem Update einmal in der Modulverwaltung prüfen, dass die Instanzen wieder den Status des Sockets zeigen; bei Bedarf das Modul einzeln löschen und neu hinzufügen (GUID gleich, Instanzen bleiben)

## 1.8.3 (2026-09-20)

- Doku: Begriffe vereinheitlicht - das Modul heißt in Beschreibung, README, Formular-Doku und Code-Kommentar jetzt "Modbus-TCP-Server (Slave)", die Gegenstelle "Client (Master)". Neuer Abschnitt "Begriffe" im README erklärt, dass beides dasselbe meint (Modbus-Spezifikation: Client/Server, ältere Datenblätter und Geräte: Master/Slave). Technischer Modulname, Klassenname, Präfix und Suchbegriffe unverändert

## 1.8.2 (2026-09-13)

- Fix (Verbund-Erkenntnis SUITE.md 9d nach Live-Vorfall an einer produktiven Anlage): ein bewusst geschlossener Server Socket (Open=aus, z. B. eine vorbereitete, noch nicht in Betrieb genommene Instanz) zeigt jetzt "Inaktiv" (IS_INACTIVE) statt des eigenen Fehlerstatus 201 - vermeidet, dass ein system-weiter Integrity-Check das als Störung zählt und daran hängende Watchdog-Skripte unnötig auslöst. Status 201 bleibt reserviert für den echten Fehlerfall (Socket soll laufen, tut es aber nicht, z. B. Port belegt). Verbindungszeile und Doku entsprechend angepasst

## 1.8.1 (2026-09-13)

- Verbund-Regel 9b (echte Umlaute statt ue/ae/oe/ss): zwei Code-Kommentare korrigiert (nicht nutzersichtbar, reine Konsistenz). Datumsformat-Teil der Regel (TT.MM.JJJJ) geprüft und gegenstandslos - das Modul zeigt nirgends ein Datum, nur reine Uhrzeiten

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

- Neu: Formular-Aktion "Weitere Schnittstellen anlegen" - erzeugt pro angegebenem Port (z. B. "501-505") eine Kopie der Instanz samt Server Socket; bereits belegte Ports werden übersprungen. Damit lassen sich Mehrfach-Anbindungen (z. B. mehrere Gegenstellen auf Ports 501-505) aus einer fertig konfigurierten Instanz heraus aufbauen

## 1.0.2 (2026-07-12)

- Port des Server Sockets nicht mehr durch das Modul erzwungen (GetConfigurationForParent entfernt) - der Port ist jetzt am Socket frei einstellbar, z. B. für mehrere Slave-Instanzen auf unterschiedlichen Ports

## 1.0.1 (2026-07-12)

- Texte und Standardwerte neutralisiert: das Modul präsentiert sich als generischer Modbus-TCP-Slave, die blue'Log-RPC-Emulation ist eine optionale Vorlage (Defaults jetzt Unit-ID 1 und Word-Order ABCD; die RPC-Vorlage setzt weiterhin Unit-ID 10 und CDAB)

## 1.0.0 (2026-07-12)

- Erstversion: generisches Modbus-TCP-Slave-Modul (FC 03/04/06/16, uint16/int16/uint32/int32/float32/float64, umschaltbare Word-Reihenfolge, frei konfigurierbare Registertabelle mit Variablen-Mapping, Faktor und Festwerten)
- Meteocontrol blue'Log XM/XC RPC-Emulation für die Direktvermarktung: Register 5000/5002–5005/5006/5008 mit Gültigkeits- und Watchdog-Logik, Rückfall-Sollwert, Registervorlage für die Istwert-Register per Formular-Button
- IPS-freier, CLI-testbarer Protokollkern (tests/codec_test.php)
