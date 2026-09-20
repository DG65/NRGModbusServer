# NRG-Stack ModbusServer

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-1.11.0-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-7.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGModbusServer/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGModbusServer/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

IP-Symcon-Bibliothek aus dem NRG-Stack, die IPS zum **Modbus-TCP-Server (Slave)** macht. Externe Modbus-Clients
(Master) lesen und schreiben IPS-Variablen über eine frei konfigurierbare Registertabelle.

**Teil des NRG-Stack** — dem Energie-Modulverbund von DG65.

**Begriffe:** Die Modbus-Spezifikation spricht bei Modbus TCP von *Client* und *Server*, ältere
Datenblätter, Geräte und Werkzeuge (z. B. ModRSsim2) sagen *Master* und *Slave*. Beides meint dasselbe:
Dieses Modul ist der **Server (Slave)**, die abfragende Gegenstelle der **Client (Master)**. Im Text und
in der Oberfläche kommen beide Schreibweisen vor, damit man sie mit der Gerätedokumentation abgleichen kann.

IP-Symcon selbst bietet von Haus aus nur Modbus-**Client**-Funktionalität (Master) – dieses Modul
ergänzt die Gegenrichtung. Typische Einsätze:

- IPS-Messwerte für ein übergeordnetes EMS, SCADA- oder Leitsystem bereitstellen
- Sollwerte von externen Reglern, Wallboxen oder Energiemanagern entgegennehmen
- Geräte emulieren, deren Registerbelegung bekannt ist (z. B. Zähler oder Datenlogger),
  um Systeme anzubinden, die einen bestimmten Modbus-Teilnehmer erwarten
- Direktvermarktungs-Schnittstellen (siehe Vorlage unten)

## Modul: ModbusTCPServer

### Funktionsweise

Das Modul hängt als Gerät unter einer **Server-Socket**-Instanz (wird beim Anlegen automatisch
erstellt; den Port dort frei einstellen, üblich ist 502). Eingehende Modbus-TCP-Anfragen werden
pro Client gepuffert (TCP-Fragmentierung wird korrekt behandelt), gegen die Registertabelle
beantwortet und gerichtet an den anfragenden Client zurückgesendet. **Mehrere gleichzeitige
Clients auf demselben Port sind möglich** – jede Verbindung erhält einen eigenen Empfangspuffer.

Sollen mehrere getrennte Schnittstellen bedient werden (z. B. verschiedene Ports oder
unterschiedliche Registerbelegungen je Gegenstelle), wird je Schnittstelle eine eigene
Instanz mit eigenem Server Socket angelegt – ein I/O pro Port entspricht der IPS-Architektur.
Die Formular-Aktion **„Weitere Schnittstellen anlegen"** nimmt dabei die Arbeit ab: Eine
Instanz fertig konfigurieren, Portliste eintragen (z. B. „501-505") – für jeden noch freien
Port entsteht eine Kopie samt Server Socket.

**Unterstützte Function Codes:**

| FC | Funktion |
|----|----------|
| 03 | Read Holding Registers |
| 04 | Read Input Registers |
| 06 | Write Single Register |
| 16 | Write Multiple Registers |

**Datentypen:** uint16, int16, uint32, int32, float32, float64.
Die Word-Reihenfolge bei Mehrwort-Typen ist umschaltbar (ABCD/CDAB).

**Registertabelle:** Pro Zeile Adresse (0-basiert), Bereich (Holding/Input), Datentyp,
IPS-Variable, aktueller Wert (nur Anzeige, siehe unten), Skalierungsfaktor, optionaler Festwert
(wenn keine Variable zugeordnet ist; bei einer Speicherzelle der Startwert) und Schreibmodus. Dieselbe Variable darf mehrfach gemappt
werden (z. B. float32- und int32-Darstellung parallel).

**Schreibmodus (Spalte „Schreiben"):**

| Modus | Verhalten |
|-------|-----------|
| Nein | Master darf nur lesen; Schreibversuche werden quittiert, aber verworfen |
| Ja – Aktion | `RequestAction`, falls die Variable eine Aktion besitzt (z. B. um einen Aktor zu schalten), sonst `SetValue` |
| Ja – direkt | immer `SetValue`; die Aktion der Variable wird bewusst nicht ausgelöst – nötig für Variablen fremder Instanzen (z. B. ModBus-Device-Register), deren Aktion sonst in ein anderes Gerät schreiben würde |

**Speicherzellen (Zeile ohne Variable, Schreiben „Ja"):** Ein Modbus-Server ist im Kern ein
Speicher – was der Client in ein Register schreibt, bekommt er beim nächsten Lesen zurück. Genau so
arbeitet eine beschreibbare Zeile, der keine Variable zugeordnet ist: Das Modul merkt sich den
geschriebenen Wert (dauerhaft, auch nach einem Neustart) und liefert ihn beim Lesen wieder aus. Der
Festwert der Zeile ist nur der **Startwert** bis zum ersten Schreibzugriff, „Aktion" und „direkt"
verhalten sich hier gleich. Der gemerkte Wert steht in der Spalte „Wert"; wird die Zeile gelöscht oder
einer Variable zugeordnet, verfällt er. Die Timeout-Absicherung funktioniert auch mit Speicherzellen
(der Rückfallwert wird in die Zelle geschrieben, und eine Speicherzelle darf als Quell-Register für
die Dauer dienen).

Typischer Einsatz: Ein Direktvermarkter schreibt einen Sollwert, und ein zweites Gerät – etwa ein
ModBus-Device in derselben IPS-Installation – liest ihn von diesem Server zurück und legt ihn in
seiner eigenen Variable ab. Hier wäre „Ja – direkt" auf die Variable des ModBus-Device wirkungslos,
denn deren Variablen sind schreibgeschützt (`SetValue` von außen scheitert mit „Variable is marked as
read-only"). Mit einer Speicherzelle übernimmt das ModBus-Device den Wert per Abfrage – genauso wie
früher von einem Simulator wie ModRSsim2, alle nachgelagerten Ereignisse und Skripte laufen
unverändert weiter.

**Zugriffszeiten je Register:** Die Registertabelle zeigt zusätzlich zwei Spalten „Empfangen"
(zuletzt von einem Master GESCHRIEBEN) und „Abgefragt" (zuletzt von einem Master GELESEN) –
Uhrzeit oder „–", wenn seit dem letzten Neustart noch kein Zugriff stattfand. Damit ist pro
Register sichtbar, ob und wann tatsächlich Datenverkehr ankommt, statt nur die eine globale
Variable „Letzte Modbus-Anfrage" zu haben. Für eigene Skripte liefert `MBSLV_GetRegisterActivity`
dieselben Zeiten als JSON (`{"5000":{"w":1735900000,"r":1735899990}}`). Die Spalte „Wert" zeigt
zusätzlich den aktuellen Registerinhalt (skaliert, wie über Modbus übertragen). Alle drei Spalten
aktualisieren sich beim (erneuten) Öffnen der Instanzkonfiguration - ein offenes Formular
automatisch nachzuziehen ist bewusst nicht umgesetzt, weil die Registertabelle ein speicherbares
Listenfeld ist und `UpdateFormField` darauf die Konsole zu "Änderungen übernehmen" auffordert,
obwohl nichts geändert wurde. Lehnt das Modul einen Schreibversuch ab (z. B. weil ein Register
nicht beschreibbar ist), steht der abgelehnte Wert im Debug-Log.

### Bestehenden Modbus-Server (Slave) ersetzen (Simulator, SPS)

Soll dieses Modul einen vorhandenen Modbus-Server ablösen (z. B. einen Simulator wie ModRSsim2, über den
ein Direktvermarkter oder Leitsystem bislang angebunden war):

1. **Registertabelle 1:1 nachbilden** – Adressen, Datentypen, Word-Order und Unit-ID vom alten
   Server übernehmen. Ist die Unit-ID des Clients nicht sicher bekannt, „Anfragen an fremde
   Unit-IDs ablehnen" zunächst ausschalten (das Debug-Fenster zeigt die tatsächlich verwendete).
2. **Dieselben IPS-Variablen verknüpfen** wie bisher, damit vorhandene Skripte und Ereignisse
   unverändert weiterlaufen. Gehören die Variablen einer anderen Instanz (typisch: einem
   ModBus-Device, das bisher den alten Server bedient hat), Schreibmodus **„Ja – direkt"** wählen. Ist die bisherige Variable schreibgeschützt, weil ein ModBus-Device
   den Wert vom Server zurückliest, die Variable leer lassen (→ Speicherzelle, siehe oben).
3. **Umschalten:** alten Server auf diesem Port stoppen, dann den Server Socket dieser Instanz
   auf demselben Port öffnen. Läuft IPS auf einem anderen Rechner als der alte Server, muss der
   Client (bzw. dessen VPN/NAT) auf die IPS-Adresse umgestellt werden.
4. **Kontrolle:** Verbindungszeile oben im Formular („zuletzt … Uhr" läuft im Polltakt des
   Clients weiter) und Debug-Fenster der Instanz. Rückweg jederzeit: Server Socket schließen,
   alten Server starten.

**Unbelegte Register:** Fragt ein Master eine Adresse ohne Tabellenzeile an, liefert das Modul
wahlweise 0 (tolerant, Standard – sinnvoll, wenn Master ganze Blöcke lesen) oder eine
Modbus-Exception „Illegal Data Address" (strikt).

**Statusampel:** Die Variable „Letzte Modbus-Anfrage" zeigt das letzte Lebenszeichen des
Masters. Ist der Server Socket bewusst geschlossen (z. B. eine vorbereitete, noch nicht in
Betrieb genommene Instanz), zeigt die Instanz „Inaktiv" – kein Fehler. Soll der Socket
eigentlich laufen, erreicht aber keinen aktiven Zustand (z. B. Port belegt), oder – bei
aktivierter Kommunikationsüberwachung (Minuten, 0 = aus) – wenn kein Master mehr pollt, meldet
die Instanz sichtbar (ohne Log-Zugriff) einen echten Fehlerstatus. Für die Direktvermarktung
empfohlen (z. B. 5 min).

### Vorlagen

Vorbereitete Registerbelegungen lassen sich über den Popup-Button „Vorlage laden…" in die
Tabelle übernehmen (inklusive passender Word-Order); gespeichert wird erst mit „Änderungen
übernehmen". Aktuell enthalten:

- **Meteocontrol blue'Log RPC** (Direktvermarktung, siehe unten)
- **SunSpec Wechselrichter dreiphasig** (Common Model 1 + Model 113, float32, Basis 40000) –
  emuliert einen SunSpec-konformen WR für Logger/Parkregler; die float-Modelle kommen ohne
  Skalierungsfaktoren aus. Die Textfelder des Common Models (Hersteller/Seriennummer) liefern 0,
  Strings unterstützt das Modul nicht.
- **SunSpec Zähler dreiphasig** (Common Model 1 + Model 213, float32, Basis 40000) – IPS tritt
  gegenüber Wallbox-/EMS-Systemen (z. B. evcc, openWB) als SunSpec-Netzzähler auf; wichtigste
  Zuordnungen sind W (Wirkleistung, Export positiv) und TotWhImp/TotWhExp. Layout generiert aus
  der offiziellen SunSpec-Modelldefinition.

Weitere Vorlagen sind nach demselben Muster ergänzbar.

**Datenpunkte anlegen:** Das Modul legt von sich aus keine Variablen für Tabellenzeilen an –
die Tabelle verweist normalerweise auf bestehende Variablen. Der Button **„Datenpunkte für
unzugeordnete Register anlegen"** erzeugt bei Bedarf für alle Zeilen ohne Variable einen
Datenpunkt unter der Instanz und trägt ihn in die Tabelle ein (Zeilen mit Festwert bleiben
unangetastet). Diese Datenpunkte können dann per Ereignis/Skript aus beliebigen Quellen
befüllt werden – praktisch für Emulationen wie die SunSpec-Vorlage.

### Timeout-Absicherung (optionale Sicherung für schreibbare Register)

Ohne die RPC-Vorlage kennt die Registertabelle von sich aus **kein Ablaufdatum** für einen
geschriebenen Wert – ein einmal gesetzter Sollwert bleibt bei Verbindungsverlust des Masters
unbegrenzt stehen. Das kann ein echtes Sicherheitsproblem sein, z. B. wenn ein Direktvermarkter
eine Abregelung setzt und dann die Verbindung verliert: ohne Absicherung bliebe die Anlage
dauerhaft abgeregelt statt nach der vereinbarten Gültigkeitsdauer automatisch wieder freizugeben.

Im Panel **„⏱ Timeout-Absicherung"** lässt sich das pro Register nachrüsten (unabhängig davon,
ob die RPC-Vorlage überhaupt verwendet wird):

| Spalte | Bedeutung |
|---|---|
| Ziel-Adresse | Register aus der Haupttabelle, das überwacht werden soll (muss dort schreibbar sein) |
| Dauer + Einheit | feste Gültigkeitsdauer (Sekunden oder Minuten) |
| Quell-Register | optional: Adresse eines anderen Registers, dessen **aktueller Wert** die Dauer liefert, statt der festen „Dauer" – für Master, die ihre Gültigkeitsdauer selbst mitschreiben |
| Rückfallwert | wird gesetzt, sobald die Ziel-Adresse länger als die Dauer nicht mehr beschrieben wurde |
| Status | Live-Anzeige: noch nie beschrieben / aktuell / im Rückfall / Konfigurationsfehler |

**Beispiel Meteocontrol blue'Log:** Next Kraftwerke (oder ein anderer Direktvermarkter) schreibt
den Sollwert in Register 5000 und die Gültigkeitsdauer in Register 5006 (in Minuten). Eine
Zeitüberwachungs-Zeile mit Ziel-Adresse 5000, Quell-Register 5006, Einheit Minuten und
Rückfallwert 100 (= keine Abregelung) reproduziert damit das blue'Log-eigene Sicherheitsverhalten
– auch ganz ohne die RPC-Vorlage, also z. B. wenn Register 5000 nach der Migration eines
bestehenden Servers auf eine bereits vorhandene, wiederverwendete Variable zeigt (Schreibmodus
„Ja - direkt", siehe oben).

Die Zeit läuft erst ab dem **ersten echten Schreibzugriff** – eine nie beschriebene Zeile fällt
nie automatisch zurück, ein Neustart der Instanz löst also keinen ungewollten Rückfall aus.
Geprüft wird einmal pro Minute (derselbe Takt wie die Statusampel); ein aktiver Rückfall
erscheint zusätzlich als Zähler in der Verbindungs-Kopfzeile oben im Formular.

#### Meteocontrol blue'Log RPC (Direktvermarktung)

Emuliert die **Remote-Power-Control-(RPC-)Schnittstelle** des Meteocontrol blue'Log XM/XC
(Datenblatt Stand 05-2020: Unit-ID 10, Port 502, FC 03/16, Word-Order Low vor High):

- **Register 5000** (float32, RW): Wirkleistungs-Sollwertvorgabe 0–125 %
- **Register 5002–5005** (float32, RW): Reserve (wird gespeichert und zurückgeliefert)
- **Register 5006** (float32, RW): Gültigkeitsdauer der Vorgabe in Minuten (1–255, Default 10)
- **Register 5008** (float32, RW): Watchdog – verlängert eine laufende Vorgabe
- **Istwert-Register** 0–14 (float32), 100–114 (int32) und 4000 (P_AV) über die Registertabelle

Aktiviert wird die RPC-Schnittstelle über die Vorlage im Popup „Vorlage laden…" – erst dann
erscheint auch das Einstellungs-Panel (Rückfall-Sollwert, Gültigkeitsdauer, Ereignis-Skript).
Die Steuer-Register 5000/5002–5005/5006/5008 verwaltet das Modul **intern** – sie erscheinen
nicht in der Registertabelle. Die Vorlage lädt die zugehörigen Istwert-Register.

**Ablauf-Logik gemäß Datenblatt:** Eine geschriebene Sollwertvorgabe gilt für die
Gültigkeitsdauer; jede weitere Vorgabe oder ein Watchdog-Schreiben startet den Ablauf-Timer
neu. Ein Watchdog **nach** Ablauf hält die Vorgabe nicht am Leben – sie muss neu gesetzt
werden. Nach Ablauf fällt der wirksame Sollwert auf den konfigurierbaren
**Rückfall-Sollwert** (Standard 100 %) zurück.

**Variablen bei aktivierter RPC-Schnittstelle:**

| Variable | Bedeutung |
|----------|-----------|
| DV-Sollwertvorgabe | zuletzt vom Master geschriebener Wert (Register 5000) |
| DV-Vorgabe gültig | true solange die Gültigkeitsdauer läuft |
| DV-Vorgabe gültig bis | Zeitstempel des Ablaufs |
| **Wirksamer DV-Sollwert** | Vorgabe solange gültig, sonst Rückfall-Sollwert – hier EMS/Weiterleitung anbinden |
| Gültigkeitsdauer | aktueller Wert von Register 5006 |
| Letzte DV-Vorgabe | Zeitstempel des letzten Schreibens (Sollwert oder Watchdog) |

**Weiterleitung an einen echten blue'Log** (IPS als Zwischenschicht): Ereignis auf
„Wirksamer DV-Sollwert" legen oder das optionale Ereignis-Skript konfigurieren
(`$_IPS['Action']` = `setpoint` | `watchdog` | `expired`, `$_IPS['Setpoint']`, `$_IPS['Valid']`)
und den Wert über eine IPS-ModBus-Master-Instanz in Register 5000 des blue'Log schreiben.

### Einrichtung

1. Modul über die Modulverwaltung installieren (GitHub-URL)
2. Instanz „Modbus TCP Server" anlegen (unter dem alten Namen „Modbus TCP Slave" ebenfalls
   auffindbar) – der Server Socket wird automatisch erstellt
3. Port am Server Socket einstellen (z. B. 502) und Socket aktivieren
4. Registertabelle füllen (manuell oder per Vorlage), Variablen zuordnen, übernehmen

### Test von der Kommandozeile

```
# Holding-Register lesen (hier: Register 5000 als float32, Unit 10, 0-basiert)
modpoll -m tcp -t4:float -r 5000 -a 10 -0 -1 <IPS-IP>

# Wert schreiben (hier: 30 auf Register 5000)
modpoll -m tcp -t4:float -r 5000 -a 10 -0 -1 <IPS-IP> 30
```

### Grenzen

- Coils/Discrete Inputs (FC 01/02/05/15) sind bewusst nicht implementiert
- PHP-Module sind nicht auf Millisekunden-Latenz optimiert; für übliche
  Poll-Intervalle (≥ 500 ms) unkritisch
- Port 502 erfordert je nach System Root-Rechte (alternativ z. B. 1502 verwenden)

## Tests

Der Protokollkern (`libs/ModbusServer.php`) ist IPS-frei und mit der PHP-CLI testbar, ebenso das Modul selbst
(Formularaufbau, Speicherzellen) über eine kleine Stub-Umgebung ohne IPS:

```
php tests/codec_test.php
php tests/module_test.php
```
