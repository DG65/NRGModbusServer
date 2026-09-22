<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ModbusServer.php';
require_once __DIR__ . '/../libs/TimeoutGuard.php';
require_once __DIR__ . '/../libs/RegisterMemory.php';

/**
 * ModbusTCPServer (NRG-Stack; früher "ModbusTCPSlave", als Alias weiter auffindbar)
 *
 * Macht IP-Symcon zum Modbus-TCP-Server (Slave): externe Modbus-Clients (Master) können
 * IPS-Variablen über eine frei konfigurierbare Registertabelle lesen und
 * schreiben (FC 03/04/06/16, uint16 bis float64, umschaltbare Word-Reihenfolge).
 * Als I/O dient der IPS Server Socket (wird als Parent automatisch angelegt).
 * Typische Einsätze: IPS-Daten für EMS, SCADA, Wallboxen, Logger oder Regler
 * bereitstellen bzw. Sollwerte von solchen Systemen entgegennehmen.
 *
 * Als optionales Profil ist die Remote-Power-Control-(RPC-)Schnittstelle des
 * Meteocontrol blue'Log XM/XC enthalten (Direktvermarktung): Sollwertvorgabe
 * (Register 5000), Gültigkeitsdauer (5006) und Watchdog (5008) inklusive
 * Ablauf-Logik; Registervorlage per Formular-Button. Protokoll-Referenz:
 * Datenblatt "Remote Power Control (RPC) blue'Log XM/XC", Stand 05-2020.
 * Weitere Vorlagen (z. B. SunSpec-Ausschnitte) sind nach demselben Muster
 * ergänzbar.
 */
class ModbusTCPServer extends IPSModule
{
    // eigene Modul-GUID (siehe module.json)
    private const MODULE_GUID = '{3F519A7D-1ABC-417D-BC08-8CCEDE0BEEE8}';
    // IPS Server Socket (I/O), wird als Parent benötigt
    private const SERVER_SOCKET_MODULE = '{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}';
    // Datenpaket "Erweitert (Socket)": Empfang vom Server Socket
    // Formular-Konvention (SUITE.md "Einheitliche Formular-Optik"): News-Panel je Version
    // einmalig bestätigbar, Lizenz-/Spendenhinweis fest verdrahtet
    private const NEWS_VERSION = '1.12';
    private const LICENSE_URL = 'https://github.com/DG65/NRGModbusServer/blob/beta/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';
    // Modul-Thread im Symcon-Forum (Kurzform mit Themen-ID, Discourse leitet auf die aktuelle Adresse um -
    // bleibt gültig, auch wenn der Titel des Threads geändert wird)
    private const FEEDBACK_URL = 'https://community.symcon.de/t/144448';

    private const RX_DATA_ID = '{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}';
    // Datenpaket "Erweitert (Socket)": gerichtetes Senden an einen Client
    private const TX_DATA_ID = '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}';

    // Instanzstatus (sichtbar ohne Log-Zugriff, siehe form.json "status")
    private const STATUS_NO_SOCKET = 201;  // Server Socket fehlt oder soll laufen (Open=true), tut es aber nicht - NICHT für absichtlich geschlossen (dafür IS_INACTIVE, siehe UpdateHealth())
    private const STATUS_NO_TRAFFIC = 202; // Kommunikationsüberwachung ausgelöst

    // Schreibmodus je Registerzeile (Spalte "Writable"; ältere Konfigurationen
    // speichern dort noch true/false, das wird auf 1/0 normalisiert)
    private const WRITE_NONE = 0;   // nur lesen
    private const WRITE_ACTION = 1; // RequestAction, falls die Variable eine Aktion hat, sonst SetValue
    private const WRITE_DIRECT = 2; // immer SetValue, Aktion der Variable bewusst nicht auslösen

    // innerhalb EINER ReceiveData()-Verarbeitung gesammelte Registerzugriffe,
    // am Ende gebuendelt in die RegisterActivity-Attribut-Variable geschrieben
    // (ein Schreibzugriff je Empfangs-Batch statt je einzelnem Register)
    private array $pendingActivity = [];

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RequireParent(self::SERVER_SOCKET_MODULE);

        $this->RegisterPropertyInteger('UnitID', 1);
        $this->RegisterPropertyBoolean('CheckUnitID', true);
        // Minuten ohne Modbus-Anfrage bis Fehlerstatus (0 = Überwachung aus)
        $this->RegisterPropertyInteger('CommTimeout', 0);
        $this->RegisterPropertyBoolean('SwapWords', false);
        $this->RegisterPropertyInteger('UnmappedRead', MBSLVModbusServer::UNMAPPED_ZERO);
        $this->RegisterPropertyString('Registers', '[]');
        // Optionale Timeout-Absicherung für schreibbare Register im generischen
        // Modus (unabhängig vom RPC-Profil, das seine eigene Watchdog-/Ablauf-
        // Logik hat) - siehe checkRegisterTimeouts() und form.json-Panel.
        $this->RegisterPropertyString('RegisterTimeouts', '[]');

        // Meteocontrol RPC / Direktvermarktung
        $this->RegisterPropertyBoolean('RPCEnabled', false);
        $this->RegisterPropertyFloat('RPCFallback', 100.0);
        $this->RegisterPropertyFloat('RPCDefaultValidTime', 10.0);
        $this->RegisterPropertyInteger('RPCForwardScript', 0);

        // Zuletzt geschriebener Watchdog-Wert (Register 5008) und die laut
        // Datenblatt ab FW 16.0.4 les-/schreibbaren Reserve-Register 5002-5005.
        // Register 5002 heißt auf realer blue'Log-Hardware PPC_P_SET_RPC_ABS
        // (absoluter Watt-Sollwert, Geschwister von 5000/REL) - im öffentlichen
        // Datenblatt nur als "reserviert" ohne Namen geführt. Bestätigt an einer
        // echten Anlage (Feldeinsatz, 01.09.2026): dort
        // schreibt die Park-Steuerung AUSSCHLIESSLICH auf ABS, REL bleibt
        // unangetastet - keine Priorisierungslogik zwischen beiden beobachtet.
        // Wir speichern 5002 hier bewusst nur als Passthrough (kein Effekt auf
        // Setpoint/Effective) - eine Verknüpfung mit ABS wäre erst sinnvoll,
        // wenn geklärt ist, ob ein externer Direktvermarkter auch ABS statt
        // REL in UNSERE Slave-Emulation schreiben könnte (offen, siehe Memory).
        $this->RegisterAttributeFloat('WatchdogValue', 0.0);
        $this->RegisterAttributeString('ScratchValues', '{}');
        // gemerkte Werte der Speicherzellen (beschreibbare Zeilen ohne Variable), Adresse => Registerwert
        $this->RegisterAttributeString('RegisterMemory', '{}');
        // Formular-Hinweise (Ausblenden wird über alle Instanzen dieses Moduls geteilt)
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeBoolean('ForumHintGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        // Letzte Zugriffszeit je Registeradresse (r=gelesen/abgefragt,
        // w=geschrieben/empfangen) - Sichtbarkeit "was geht wirklich" in der
        // Registertabelle, unabhaengig von der einen globalen LastRequest-Variable
        $this->RegisterAttributeString('RegisterActivity', '{}');
        // Adressen, für die aktuell (wegen Timeout) der Rückfallwert gilt -
        // verhindert, dass derselbe Rückfallwert bei jedem Watch()-Tick erneut
        // geschrieben wird; wird beim nächsten echten Schreibzugriff gelöscht.
        $this->RegisterAttributeString('TimeoutApplied', '{}');

        $this->RegisterTimer('Expire', 0, 'MBSLV_CheckExpire($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Watch', 0, 'MBSLV_Watch($_IPS[\'TARGET\']);');
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->ensureProfiles();

        // Sichtbare Kommunikationsanzeige, unabhängig vom RPC-Profil
        $this->registerVarOnce('int', 'LastRequest', 'Letzte Modbus-Anfrage', '~UnixTimestamp', 5);

        if ((bool) $this->ReadPropertyBoolean('RPCEnabled')) {
            // nur bei echter Neuanlage registrieren (SUITE.md-Stolperstein 3)
            $this->registerVarOnce('float', 'Setpoint', 'DV-Sollwertvorgabe', 'MBSLV.Percent', 10);
            $this->registerVarOnce('bool', 'SetpointValid', 'DV-Vorgabe gültig', '~Switch', 20);
            $this->registerVarOnce('int', 'ValidUntil', 'DV-Vorgabe gültig bis', '~UnixTimestamp', 30);
            $this->registerVarOnce('float', 'Effective', 'Wirksamer DV-Sollwert', 'MBSLV.Percent', 40);
            $this->registerVarOnce('float', 'ValidTime', 'Gültigkeitsdauer', 'MBSLV.Minutes', 50);
            $this->registerVarOnce('int', 'LastWrite', 'Letzte DV-Vorgabe', '~UnixTimestamp', 60);

            if ($this->GetValue('ValidTime') < 1) {
                $this->SetValue('ValidTime', (float) $this->ReadPropertyFloat('RPCDefaultValidTime'));
            }

            // Zustand nach Neustart/Übernehmen wiederherstellen
            if ($this->GetValue('SetpointValid')) {
                $remaining = $this->GetValue('ValidUntil') - time();
                if ($remaining <= 0) {
                    $this->CheckExpire();
                } else {
                    $this->SetTimerInterval('Expire', $remaining * 1000);
                }
            } else {
                $this->SetValue('Effective', (float) $this->ReadPropertyFloat('RPCFallback'));
                $this->SetTimerInterval('Expire', 0);
            }
        } else {
            $this->SetTimerInterval('Expire', 0);
        }

        $this->pruneRegisterMemory();
        $this->AdoptDismissFromSibling();

        $this->SetTimerInterval('Watch', 60000);
        $this->UpdateHealth();
    }

    /** Verwirft gemerkte Werte von Adressen, die keine Speicherzelle mehr sind (Zeile gelöscht oder auf Variable umgestellt) */
    private function pruneRegisterMemory(): void
    {
        $rows = json_decode((string) $this->ReadPropertyString('Registers'), true);
        $addresses = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $normalized = self::normalizeGenericRow($row);
            if (MBSLVRegisterMemory::isMemoryCell($normalized)) {
                $addresses[] = $normalized['Address'];
            }
        }
        $current = (string) $this->ReadAttributeString('RegisterMemory');
        $pruned = MBSLVRegisterMemory::encode(MBSLVRegisterMemory::prune(MBSLVRegisterMemory::decode($current), $addresses));
        if ($pruned !== $current) {
            $this->WriteAttributeString('RegisterMemory', $pruned);
        }
    }

    /** "Wozu dieses Modul?" - ganz oben, einmalig wegklickbar (SUITE.md Formular-Konvention, Punkt 0) */
    private function PurposeIntro(): ?array
    {
        if ((bool) $this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Dieses Modul macht IP-Symcon zum Modbus-TCP-Server: Externe Geräte und Systeme (Modbus-Clients, z. B. ein Leitsystem, ein Energiemanagement oder ein Direktvermarkter) können darüber frei gewählte Symcon-Variablen lesen und beschreiben.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen: Werte aus Symcon lassen sich an Systeme weitergeben, die nur Modbus sprechen, und Sollwerte von dort entgegennehmen - oder ein bekanntes Modbus-Gerät (z. B. einen Zähler oder Datenlogger) nachbilden, das eine Gegenstelle erwartet.'],
                ['type' => 'Label', 'caption' => 'Möchten Sie stattdessen Werte von Modbus-Geräten einlesen? Dafür gibt es im NRG-Stack InverterHub (Wechselrichter), MeterHub (Zähler) und ChargerHub (Wallboxen).'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'MBSLV_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
        $this->PropagateDismiss('PurposeIntro');
    }

    /** "Neu in Version X.Y" - aufgeklappt, pro Version einmalig bestätigbar (Punkt 1) */
    private function NewsBanner(): ?array
    {
        if ((string) $this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  Neu in Version ' . self::NEWS_VERSION,
            'items' => [
                ['type' => 'Label', 'caption' => '• 🆕 Speicherzellen: Eine beschreibbare Registerzeile ohne Variable merkt sich den vom Client geschriebenen Wert und liefert ihn beim Lesen zurück - wie ein klassischer Modbus-Server (z. B. ModRSsim2). Der Festwert ist der Startwert. Praktisch für Sollwerte, die ein anderes Gerät, etwa ein ModBus-Device in derselben Installation, von diesem Server zurückliest; dessen Variablen sind schreibgeschützt und lassen sich nicht direkt beschreiben.'],
                ['type' => 'Label', 'caption' => '• 🔧 Neuer Name: „Modbus-TCP-Server" statt „Slave", passend zur Modbus-Spezifikation (Client/Server). Bestehende Instanzen bleiben unverändert zugeordnet, nur beim Anlegen einer neuen Instanz heißt der Eintrag „NRG-Stack Modbus TCP Server".'],
                ['type' => 'Label', 'caption' => '• 🔧 „Instanzen anlegen" (weitere Schnittstellen) fragt jetzt vorher nach und nennt, dass die Ports sofort geöffnet werden.'],
                ['type' => 'Label', 'caption' => '• 💬 Neuer Feedback-Hinweis unten im Formular: Rückmeldungen, Fragen und Fehlermeldungen sind willkommen, am liebsten im Thread im Symcon-Forum.'],
                ['type' => 'Label', 'caption' => '• 🔗 Bei mehreren Instanzen: „Wozu dieses Modul?", „Was ist neu?" und der Feedback-Hinweis müssen nur einmal weggeklickt werden - ein Klick gilt für alle Instanzen dieses Moduls.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'MBSLV_AckNews($id);'],
            ],
        ];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
        $this->PropagateDismiss('News', self::NEWS_VERSION);
    }

    /** Feedback-/Forum-Hinweis - nach den Fachpanels, einmalig wegklickbar (Punkt 4; Pflicht laut Verbund-Konvention) */
    private function ForumHint(): ?array
    {
        if ((bool) $this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback im Symcon-Forum',
            'items' => [
                ['type' => 'Label', 'caption' => 'Rückmeldungen, Fragen und Fehlermeldungen zu diesem Modul sind ausdrücklich willkommen - am liebsten im Thread im Symcon-Forum.'],
                ['type' => 'Button', 'caption' => 'Zum Forums-Thread', 'onClick' => "echo '" . self::FEEDBACK_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'MBSLV_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
        $this->PropagateDismiss('ForumHint');
    }

    /**
     * Ausblenden der Hinweise über alle Instanzen dieses Moduls teilen (SUITE.md
     * "Ausblenden über mehrere Instanzen desselben Moduls teilen"): sonst müsste
     * derselbe Hinweis bei jeder Schnittstelle einzeln weggeklickt werden. Ruft
     * bei den Geschwistern nur den reinen Übernahme-Schritt auf, der selbst nie
     * weiterreicht - dadurch kein Hin und Her.
     */
    private function PropagateDismiss(string $what, string $value = ''): void
    {
        foreach (IPS_GetInstanceListByModuleID(self::MODULE_GUID) as $sibling) {
            if ($sibling === $this->InstanceID) {
                continue;
            }
            try {
                MBSLV_AdoptDismissState($sibling, $what, $value);
            } catch (\Throwable $e) {
                // eine Instanz mitten im Neuladen darf das Ausblenden nicht mitreißen
            }
        }
    }

    /** Reiner Übernahme-Schritt für eine Geschwister-Instanz, siehe PropagateDismiss() */
    public function AdoptDismissState(string $what, string $value): void
    {
        switch ($what) {
            case 'PurposeIntro':
                $this->WriteAttributeBoolean('PurposeIntroGone', true);
                $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
                break;
            case 'ForumHint':
                $this->WriteAttributeBoolean('ForumHintGone', true);
                $this->UpdateFormField('ForumHintPanel', 'visible', false);
                break;
            case 'News':
                $this->WriteAttributeString('SeenNews', $value);
                $this->UpdateFormField('NewsPanel', 'visible', false);
                break;
        }
    }

    /** Ausblende-Stand dieser Instanz, damit neu angelegte Instanzen ihn übernehmen können */
    public function GetDismissState(): array
    {
        return [
            'purposeIntroGone' => (bool) $this->ReadAttributeBoolean('PurposeIntroGone'),
            'forumHintGone'    => (bool) $this->ReadAttributeBoolean('ForumHintGone'),
            'seenNews'         => (string) $this->ReadAttributeString('SeenNews')
        ];
    }

    /**
     * Gegenrichtung: eine neue Instanz übernimmt beim ersten ApplyChanges() den
     * Stand einer bestehenden, statt bereits bestätigte Hinweise erneut zu zeigen.
     * Zieht nur vor, überschreibt nie einen weiter fortgeschrittenen eigenen Stand.
     */
    private function AdoptDismissFromSibling(): void
    {
        if ((bool) $this->ReadAttributeBoolean('PurposeIntroGone') && (bool) $this->ReadAttributeBoolean('ForumHintGone') && (string) $this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return;
        }
        foreach (IPS_GetInstanceListByModuleID(self::MODULE_GUID) as $sibling) {
            if ($sibling === $this->InstanceID) {
                continue;
            }
            try {
                $state = MBSLV_GetDismissState($sibling);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_array($state)) {
                continue;
            }
            if (!(bool) $this->ReadAttributeBoolean('PurposeIntroGone') && !empty($state['purposeIntroGone'])) {
                $this->WriteAttributeBoolean('PurposeIntroGone', true);
            }
            if (!(bool) $this->ReadAttributeBoolean('ForumHintGone') && !empty($state['forumHintGone'])) {
                $this->WriteAttributeBoolean('ForumHintGone', true);
            }
            if ((string) $this->ReadAttributeString('SeenNews') !== self::NEWS_VERSION && ($state['seenNews'] ?? '') === self::NEWS_VERSION) {
                $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
            }
            break;
        }
    }

    /**
     * Lizenz-/Unterstützungshinweis - Wortlaut verbundweit identisch (SUITE.md
     * "Einheitliche Formular-Optik", Punkt 5). Bewusst NICHT wegklickbar,
     * eingeklappt, ganz unten. Link-Buttons: die URL ist die Echo-Ausgabe des
     * onClick-Skripts, 'link' nur ein true/false-Schalter.
     */
    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /** Versionsstand der Bibliothek für das Doku-Panel (dauerhaft sichtbar, ohne von Hand mitgepflegt zu werden) */
    private function libraryVersion(): string
    {
        // LibraryID steht am Modul (IPS_GetModule), NICHT in IPS_GetInstance()['ModuleInfo'] -
        // eine PHP-Warnung im Formular-Rückgabewert macht das ganze Formular unlesbar
        $libraryID = (string) (IPS_GetModule(self::MODULE_GUID)['LibraryID'] ?? '');
        if ($libraryID === '') {
            return '';
        }
        return (string) (IPS_GetLibrary($libraryID)['Version'] ?? '');
    }

    /**
     * Empfängt Rohdaten vom Server Socket (Datenpaket "Erweitert (Socket)"),
     * setzt daraus vollständige Modbus-TCP-Frames zusammen und beantwortet sie
     * gerichtet an den jeweiligen Client.
     */
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        $ip = (string) ($data->ClientIP ?? '');
        $port = (int) ($data->ClientPort ?? 0);
        $type = (int) ($data->Type ?? 0);
        $key = 'RX_' . $ip . '_' . $port;

        if ($type === 1) { // Verbindung hergestellt
            $this->SetBuffer($key, '');
            $this->SendDebug('Verbindung', sprintf('%s:%d verbunden', $ip, $port), 0);
            return '';
        }
        if ($type === 2) { // Verbindung beendet
            $this->SetBuffer($key, '');
            $this->SendDebug('Verbindung', sprintf('%s:%d getrennt', $ip, $port), 0);
            return '';
        }

        $raw = $this->decodeBuffer((string) ($data->Buffer ?? ''));
        $this->SendDebug('RX ' . $ip . ':' . $port, $raw, 1);

        $buffer = $this->GetBuffer($key) . $raw;
        [$frames, $rest] = MBSLVModbusServer::extractFrames($buffer);
        // Schutz gegen Nicht-Modbus-Datenmüll auf dem Port
        $this->SetBuffer($key, strlen($rest) > 2048 ? '' : $rest);

        if ($frames === []) {
            return '';
        }

        $this->noteRequest();
        $server = $this->buildServer();
        foreach ($frames as $frame) {
            $response = $server->process($frame);
            if ($response === null) {
                continue;
            }
            $this->SendDebug('TX ' . $ip . ':' . $port, $response, 1);
            $this->SendDataToParent(json_encode([
                'DataID'     => self::TX_DATA_ID,
                'Buffer'     => $this->encodeBuffer($response),
                'ClientIP'   => $ip,
                'ClientPort' => $port,
                'Type'       => 0
            ]));
        }
        $this->flushRegisterActivity();
        return '';
    }

    /**
     * Timer-Callback (minütlich): aktualisiert die Statusampel der Instanz.
     */
    public function Watch(): void
    {
        $this->UpdateHealth();
        $this->checkRegisterTimeouts();
    }

    /**
     * Letzte Zugriffszeitpunkte je Registeradresse als JSON, z. B. für eigene
     * Skripte/EMS-Integration: {"5000":{"w":1735900000,"r":1735899990}} -
     * "w" = zuletzt von einem Master GESCHRIEBEN (Wert kam rein), "r" = zuletzt
     * von einem Master GELESEN (Wert wurde abgefragt). Dieselben Zeiten stehen
     * auch als Spalten "Empfangen"/"Abgefragt" in der Registertabelle im Formular.
     */
    public function GetRegisterActivity(): string
    {
        return (string) $this->ReadAttributeString('RegisterActivity');
    }

    /**
     * Timer-Callback: prüft den Ablauf der DV-Sollwertvorgabe und schaltet nach
     * Ablauf der Gültigkeitsdauer auf den Rückfall-Sollwert.
     */
    public function CheckExpire(): void
    {
        $this->SetTimerInterval('Expire', 0);
        if (!(bool) $this->ReadPropertyBoolean('RPCEnabled') || !$this->GetValue('SetpointValid')) {
            return;
        }
        $remaining = $this->GetValue('ValidUntil') - time();
        if ($remaining > 0) {
            $this->SetTimerInterval('Expire', $remaining * 1000);
            return;
        }
        $fallback = (float) $this->ReadPropertyFloat('RPCFallback');
        $this->SetValue('SetpointValid', false);
        $this->SetValue('Effective', $fallback);
        $this->SendDebug('RPC', sprintf('Sollwertvorgabe abgelaufen - Rückfall auf %.1f %%', $fallback), 0);
        $this->runForwardScript('expired', $fallback);
    }

    /**
     * Dynamisches Formular: RPC-Einstellungen nur zeigen, wenn die
     * RPC-Schnittstelle aktiviert ist (Live-Umschaltung via UIToggleRPC).
     */
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $enabled = (bool) $this->ReadPropertyBoolean('RPCEnabled');
        // Das komplette RPC-Panel erscheint erst, wenn die RPC-Schnittstelle
        // (über die Vorlage im Popup) aktiviert wurde
        $this->setFormVisibility($form['elements'], array_merge(['RPCPanel'], self::RPC_FORM_FIELDS), $enabled);
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'DocPanel') {
                $version = $this->libraryVersion();
                array_unshift($element['items'], ['type' => 'Label', 'caption' => 'ModbusServer' . ($version !== '' ? ' ' . $version : '') . ' - Stand dieser Anleitung.']);
            }
            if (($element['name'] ?? '') === 'PortInfo') {
                $element['caption'] = $this->portInfoCaption();
            }
            // Registertabelle mit normalisiertem Schreibmodus anzeigen (ältere
            // Konfigurationen speichern true/false statt 0/1/2); die Liste bleibt
            // an die Property gebunden, gespeichert wird weiterhin regulär
            if (($element['name'] ?? '') === 'Registers') {
                $element['loadValuesFromConfiguration'] = false;
                $element['values'] = $this->registersForForm();
            }
            if (($element['name'] ?? '') === 'RegisterTimeouts') {
                $element['loadValuesFromConfiguration'] = false;
                $element['values'] = $this->timeoutsForForm();
            }
        }
        unset($element);
        // Reihenfolge nach Verbund-Konvention: Zweck, Neu, Doku, Fachpanels, Lizenz (ganz unten)
        $form['elements'] = array_values(array_filter(array_merge(
            [$this->PurposeIntro(), $this->NewsBanner()],
            $form['elements'],
            [$this->ForumHint(), $this->LicenseHint()]
        )));
        return json_encode($form);
    }

    /**
     * Gespeicherte Registerzeilen mit normalisierter Spalte "Writable" (0/1/2)
     * sowie den zuletzt beobachteten Zugriffszeiten je Register (Spalten
     * "Empfangen"/"Abgefragt") - rein zur Anzeige, nicht Teil der gespeicherten
     * Konfiguration (buildServer() liest nur die bekannten Registerfelder).
     */
    private function registersForForm(): array
    {
        $rows = json_decode((string) $this->ReadPropertyString('Registers'), true);
        if (!is_array($rows)) {
            return [];
        }
        $activity = json_decode((string) $this->ReadAttributeString('RegisterActivity'), true);
        if (!is_array($activity)) {
            $activity = [];
        }
        foreach ($rows as &$row) {
            $row['Writable'] = self::normalizeWriteMode($row['Writable'] ?? 0);
            $row = array_merge($row, $this->registerActivityLabels($activity, (int) ($row['Address'] ?? 0)));
            $factor = (float) ($row['Factor'] ?? 1.0);
            $row['CurrentValue'] = $this->formatCurrentValue($this->currentRegisterValue([
                'Address'    => (int) ($row['Address'] ?? 0),
                'Writable'   => $row['Writable'],
                'VariableID' => (int) ($row['VariableID'] ?? 0),
                'Factor'     => $factor == 0.0 ? 1.0 : $factor,
                'Fixed'      => (float) ($row['Fixed'] ?? 0.0)
            ]));
        }
        unset($row);
        return $rows;
    }

    /**
     * Gespeicherte Timeout-Regeln mit angehängter Live-Status-Spalte (rein zur
     * Anzeige, nicht Teil der gespeicherten Konfiguration) - zeigt, ob das
     * Register aktuell frisch beschrieben ist, im Rückfall steckt oder noch
     * nie beschrieben wurde.
     */
    private function timeoutsForForm(): array
    {
        $rules = json_decode((string) $this->ReadPropertyString('RegisterTimeouts'), true);
        if (!is_array($rules)) {
            return [];
        }
        $registers = json_decode((string) $this->ReadPropertyString('Registers'), true);
        if (!is_array($registers)) {
            $registers = [];
        }
        $activity = json_decode((string) $this->ReadAttributeString('RegisterActivity'), true);
        if (!is_array($activity)) {
            $activity = [];
        }
        $applied = json_decode((string) $this->ReadAttributeString('TimeoutApplied'), true);
        if (!is_array($applied)) {
            $applied = [];
        }
        foreach ($rules as &$rule) {
            $address = (int) ($rule['Address'] ?? 0);
            $key = (string) $address;
            $targetRow = self::findRegisterRow($registers, $address);
            if ($targetRow === null) {
                $rule['Status'] = '⛔ Adresse nicht in der Registertabelle';
            } elseif (self::normalizeWriteMode($targetRow['Writable'] ?? 0) === self::WRITE_NONE) {
                $rule['Status'] = '⛔ Register ist auf "nur lesen" gestellt';
            } else {
                $lastWrite = (int) ($activity[$key]['w'] ?? 0);
                if ($lastWrite <= 0) {
                    $rule['Status'] = '– noch nie beschrieben';
                } elseif (isset($applied[$key])) {
                    $rule['Status'] = '⚠️ im Rückfall (zuletzt beschrieben ' . date('H:i:s', $lastWrite) . ')';
                } else {
                    $rule['Status'] = '✅ aktuell (zuletzt beschrieben ' . date('H:i:s', $lastWrite) . ')';
                }
            }
        }
        unset($rule);
        return $rules;
    }

    private static function normalizeWriteMode($value): int
    {
        if (is_bool($value)) {
            return $value ? self::WRITE_ACTION : self::WRITE_NONE;
        }
        return max(self::WRITE_NONE, min(self::WRITE_DIRECT, (int) $value));
    }

    /** Rohe (gespeicherte) Registerzeile in die von buildServer()/den Readern/Writern erwartete Form bringen */
    private static function normalizeGenericRow(array $row): array
    {
        $factor = (float) ($row['Factor'] ?? 1.0);
        return [
            'Area'       => (int) ($row['Area'] ?? MBSLVModbusServer::AREA_HOLDING),
            'Address'    => (int) ($row['Address'] ?? 0),
            'DataType'   => (string) ($row['DataType'] ?? 'uint16'),
            'VariableID' => (int) ($row['VariableID'] ?? 0),
            'Factor'     => $factor == 0.0 ? 1.0 : $factor,
            'Fixed'      => (float) ($row['Fixed'] ?? 0.0),
            'Writable'   => self::normalizeWriteMode($row['Writable'] ?? 0)
        ];
    }

    /** Sucht in der rohen (gespeicherten) Registertabelle die HOLDING-Zeile mit der gegebenen Adresse */
    private static function findRegisterRow(array $rows, int $address): ?array
    {
        foreach ($rows as $row) {
            $area = (int) ($row['Area'] ?? MBSLVModbusServer::AREA_HOLDING);
            if ($area === MBSLVModbusServer::AREA_HOLDING && (int) ($row['Address'] ?? -1) === $address) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Immer sichtbare Verbindungs-Kopfzeile: eine Zeile, Icon + Kernaussage +
     * Zeitstempel (Stil analog zur verbundweiten Discovery-Kopfzeile-Konvention,
     * SUITE.md "Einheitliche Verbund-Status-Kopfzeile") - Details/Anleitung
     * stehen im Doku-Panel, nicht hier.
     */
    private function portInfoCaption(): string
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parent === 0) {
            return 'ℹ️ Kein Server Socket verbunden.';
        }
        $port = (int) IPS_GetProperty($parent, 'Port');
        if (!IPS_GetProperty($parent, 'Open')) {
            return sprintf('ℹ️ Server Socket ist geschlossen (Port %d) - noch nicht aktiv.', $port);
        }
        if (IPS_GetInstance($parent)['InstanceStatus'] !== IS_ACTIVE) {
            return sprintf('❌ Server Socket nicht aktiv (Port %d).', $port);
        }
        $lastRequestID = (int) @$this->GetIDForIdent('LastRequest');
        $last = $lastRequestID > 0 ? (int) GetValue($lastRequestID) : 0;
        $base = $last === 0
            ? sprintf('⚠️ Erreichbar auf Port %d (noch keine Anfrage empfangen).', $port)
            : sprintf('✅ Erreichbar auf Port %d (zuletzt %s Uhr).', $port, date('H:i:s', $last));

        $fallbackCount = $this->activeTimeoutFallbackCount();
        if ($fallbackCount > 0) {
            $base .= sprintf(' ⚠️ %d Register auf Timeout-Rückfallwert.', $fallbackCount);
        }
        return $base;
    }

    /** Anzahl der Register, für die aktuell (Timeout-Absicherung) der Rückfallwert gilt */
    private function activeTimeoutFallbackCount(): int
    {
        $applied = json_decode((string) $this->ReadAttributeString('TimeoutApplied'), true);
        return is_array($applied) ? count($applied) : 0;
    }

    private const RPC_FORM_FIELDS = ['RPCHintInternal', 'RPCSettingsRow', 'RPCForwardScript', 'RPCHintScript'];

    /** onChange-Handler der RPC-Checkbox: blendet die RPC-Felder live ein/aus */
    public function UIToggleRPC(bool $Value): void
    {
        foreach (self::RPC_FORM_FIELDS as $field) {
            $this->UpdateFormField($field, 'visible', $Value);
        }
    }

    /**
     * Popup-Button: lädt eine Registervorlage in die offene Konfiguration
     * (nur UpdateFormField - gespeichert wird erst durch den Nutzer über
     * "Änderungen übernehmen"). Gibt einen Ergebnistext zurück (✅/⚠️/⛔-Präfix);
     * der onClick-Handler in form.json lautet bewusst "echo MBSLV_LoadTemplate(...)",
     * damit die Rückmeldung sofort als Popup sichtbar wird (SUITE.md-Konvention
     * "Sichtbare Rückmeldung bei jeder Aktion").
     *
     * @param string $Template 'rpc' | 'sunspec113' | 'sunspec213'
     */
    public function LoadTemplate(string $Template): string
    {
        switch ($Template) {
            case 'rpc':
                $effective = (bool) $this->ReadPropertyBoolean('RPCEnabled') ? (int) @$this->GetIDForIdent('Effective') : 0;
                $this->UpdateFormField('Registers', 'values', json_encode($this->templateRowsRPC($effective)));
                $this->UpdateFormField('UnitID', 'value', 10);
                $this->UpdateFormField('CheckUnitID', 'value', true);
                $this->UpdateFormField('SwapWords', 'value', true);
                $this->UpdateFormField('RPCEnabled', 'value', true);
                $this->UpdateFormField('RPCPanel', 'visible', true);
                $this->UpdateFormField('RPCPanel', 'expanded', true);
                $this->UIToggleRPC(true);
                if ($effective > 0) {
                    return "✅ Vorlage geladen (Unit-ID 10, Word-Order CDAB gesetzt, RPC-Schnittstelle aktiviert - Einstellungen siehe eingeblendetes Panel). Bitte die Istwert-Variablen (WR-Leistung, Netzleistung, P_AV) zuordnen und mit 'Änderungen übernehmen' speichern.";
                }
                return "✅ Vorlage geladen (Unit-ID 10, Word-Order CDAB gesetzt, RPC-Schnittstelle aktiviert - Einstellungen siehe eingeblendetes Panel). Nach 'Änderungen übernehmen' die Vorlage erneut laden, damit die Sollwert-Register (4/8/104/108) automatisch mit der Variable 'Wirksamer DV-Sollwert' verknüpft werden. Istwert-Variablen bitte manuell zuordnen.";

            case 'sunspec113':
                $this->UpdateFormField('Registers', 'values', json_encode($this->templateRowsSunSpec113()));
                $this->UpdateFormField('SwapWords', 'value', false);
                $this->UpdateFormField('CheckUnitID', 'value', true);
                return "✅ SunSpec-Vorlage geladen (Word-Order ABCD gesetzt): Common Model 1 + Wechselrichter Model 113 (dreiphasig, float32) ab Basisregister 40000. Bitte Messwert-Variablen zuordnen, Unit-ID an die Gegenstelle anpassen (üblich 1 oder 126) und mit 'Änderungen übernehmen' speichern. Hinweis: Die Textfelder des Common Models (Hersteller/Modell/Seriennummer) liefern 0 - Strings unterstützt das Modul nicht.";

            case 'sunspec213':
                $this->UpdateFormField('Registers', 'values', json_encode($this->templateRowsSunSpec213()));
                $this->UpdateFormField('SwapWords', 'value', false);
                $this->UpdateFormField('CheckUnitID', 'value', true);
                return "✅ SunSpec-Vorlage geladen (Word-Order ABCD gesetzt): Common Model 1 + Zähler Model 213 (dreiphasig, float32) ab Basisregister 40000 - damit kann IPS z. B. gegenüber Wallbox-/EMS-Systemen (evcc, openWB u. a.) als SunSpec-Netzzähler auftreten. Wichtigste Zuordnungen: W (Wirkleistung, Vorzeichen: Export positiv), TotWhImp/TotWhExp (Energiezähler). Nicht zugeordnete Detailpunkte liefern 0. Unit-ID an die Gegenstelle anpassen und mit 'Änderungen übernehmen' speichern.";

            default:
                return '⛔ Unbekannte Vorlage: ' . $Template;
        }
    }

    /** Abwärtskompatibler Alias (Button bis v1.1.0) - echot selbst, falls ein noch
     *  gecachtes altes Formular ihn ohne führendes echo aufruft. */
    public function LoadRPCProfile(): void
    {
        echo $this->LoadTemplate('rpc');
    }

    /**
     * Formular-Button: legt für alle Zeilen der (offenen) Registertabelle ohne
     * zugeordnete Variable einen Datenpunkt unter der Instanz an und trägt ihn
     * in die Tabelle ein. Zeilen mit Festwert ungleich 0 (Header/Konstanten,
     * z. B. SunSpec-Modell-IDs) bleiben unangetastet. Wiederholtes Ausführen
     * ist unschädlich - vorhandene Datenpunkte werden wiederverwendet.
     * Rückgabe als Ergebnistext (✅/⚠️-Präfix), form.json ruft "echo MBSLV_..." auf.
     */
    public function CreateRowVariables(string $RowsJson): string
    {
        $rows = json_decode($RowsJson, true);
        if (is_string($rows)) { // doppelt kodiert angeliefert
            $rows = json_decode($rows, true);
        }
        if (!is_array($rows) || $rows === []) {
            return '⚠️ Die Registertabelle ist leer - zuerst Zeilen anlegen oder eine Vorlage laden.';
        }
        // Bei genau einer Zeile liefert Symcon $Registers als einzelnes Zeilen-Objekt statt
        // als Array mit einem Element - json_encode() daraus ergibt "{...}" statt "[{...}]",
        // ohne dieses Abfangen läuft die Schleife unten über die FELDER der einen Zeile statt
        // über die Zeile selbst (Live-Fund Solarpark 22.09.2026, Fatal Error in dieser Methode).
        if (!array_is_list($rows)) {
            $rows = [$rows];
        }

        $created = 0;
        $reused = 0;
        $skipped = 0;
        foreach ($rows as &$row) {
            if (!is_array($row)) { // zusätzliche Absicherung gegen weitere Anlieferungs-Eigenheiten
                continue;
            }
            $variable = (int) ($row['VariableID'] ?? 0);
            if ($variable >= 10000 && IPS_VariableExists($variable)) {
                continue; // bereits zugeordnet
            }
            if ((float) ($row['Fixed'] ?? 0.0) != 0.0) {
                $skipped++;
                continue;
            }
            $address = (int) ($row['Address'] ?? 0);
            $ident = sprintf('Reg_%d_%d', (int) ($row['Area'] ?? 0), $address);
            $name = trim((string) ($row['Name'] ?? ''));
            if ($name === '') {
                $name = 'Register ' . $address;
            }
            $existed = (int) @$this->GetIDForIdent($ident) > 0;
            $isFloat = in_array((string) ($row['DataType'] ?? 'uint16'), ['float32', 'float64'], true);
            $id = $this->registerVarOnce($isFloat ? 'float' : 'int', $ident, $name, '', 1000 + $address);
            $row['VariableID'] = $id;
            $existed ? $reused++ : $created++;
        }
        unset($row);

        $this->UpdateFormField('Registers', 'values', json_encode($rows));

        $icon = ($created + $reused) > 0 ? '✅' : '⚠️';
        $message = sprintf('%s %d Datenpunkt(e) angelegt, %d wiederverwendet und in die Tabelle eingetragen.', $icon, $created, $reused);
        if ($skipped > 0) {
            $message .= sprintf(' %d Zeile(n) mit Festwert wurden übersprungen.', $skipped);
        }
        $message .= " Mit 'Änderungen übernehmen' speichern. Die Datenpunkte gehören zur Instanz und können per Ereignis/Skript aus beliebigen Quellen befüllt werden.";
        return $message;
    }

    /**
     * Formular-Button: legt für jeden angegebenen Port eine Kopie dieser
     * Instanz samt Server Socket an (z. B. "502-505" oder "502,503").
     * Ports, auf denen bereits eine ModbusTCPServer-Instanz lauscht (inklusive
     * dieser), werden übersprungen. Die neuen Instanzen sind vollständige
     * Kopien der GESPEICHERTEN Konfiguration dieser Instanz.
     * Rückgabe als Ergebnistext (✅/⚠️-Präfix), form.json ruft "echo MBSLV_..." auf.
     */
    public function CreateSiblings(string $Ports): string
    {
        $ports = [];
        foreach (explode(',', $Ports) as $part) {
            $part = trim($part);
            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                for ($p = (int) $m[1]; $p <= (int) $m[2] && count($ports) < 50; $p++) {
                    $ports[] = $p;
                }
            } elseif ($part !== '' && preg_match('/^\d+$/', $part)) {
                $ports[] = (int) $part;
            }
        }
        $ports = array_values(array_unique(array_filter($ports, fn ($p) => $p > 0 && $p <= 65535)));
        if ($ports === []) {
            return "⚠️ Keine gültigen Ports angegeben. Beispiele: '502-505' oder '502,503,1502'.";
        }

        // bereits belegte Ports aller ModbusTCPServer-Instanzen ermitteln
        $usedPorts = [];
        foreach (IPS_GetInstanceListByModuleID(self::MODULE_GUID) as $instanceID) {
            $socket = IPS_GetInstance($instanceID)['ConnectionID'];
            if ($socket > 0) {
                $usedPorts[(int) IPS_GetProperty($socket, 'Port')] = true;
            }
        }

        // Die eigene Instanz bleibt unverändert: gelesen wird nur ihre gespeicherte
        // Konfiguration, geschrieben wird ausschließlich auf die NEU angelegten
        // Instanzen und deren Server Socket. Der Formular-Button fragt vorher
        // ausdrücklich nach (confirm), es passiert also nichts ungefragt.
        $config = json_decode(IPS_GetConfiguration($this->InstanceID), true);
        $baseName = preg_replace('/ \(Port \d+\)$/', '', IPS_GetName($this->InstanceID));
        $location = IPS_GetObject($this->InstanceID)['ParentID'];

        $created = [];
        $skipped = [];
        foreach ($ports as $port) {
            if (isset($usedPorts[$port])) {
                $skipped[] = $port;
                continue;
            }

            $instance = IPS_CreateInstance(self::MODULE_GUID);
            IPS_SetName($instance, $baseName . ' (Port ' . $port . ')');
            IPS_SetParent($instance, $location);
            foreach ($config as $property => $value) {
                IPS_SetProperty($instance, $property, $value);
            }
            IPS_ApplyChanges($instance);

            // RequireParent legt den Server Socket normalerweise automatisch an
            $socket = IPS_GetInstance($instance)['ConnectionID'];
            if ($socket === 0) {
                $socket = IPS_CreateInstance(self::SERVER_SOCKET_MODULE);
                IPS_ConnectInstance($instance, $socket);
            }
            IPS_SetName($socket, 'Server Socket (Modbus TCP Server Port ' . $port . ')');
            IPS_SetProperty($socket, 'Port', $port);
            IPS_SetProperty($socket, 'Open', true);
            @IPS_ApplyChanges($socket);

            $usedPorts[$port] = true;
            $created[] = $port;
        }

        $icon = $created !== [] ? '✅' : '⚠️';
        $message = [];
        if ($created !== []) {
            $message[] = 'Angelegt: Port ' . implode(', ', $created) . '.';
        }
        if ($skipped !== []) {
            $message[] = 'Übersprungen (bereits belegt): Port ' . implode(', ', $skipped) . '.';
        }
        $message[] = 'Hinweis: Kopiert wurde die zuletzt GESPEICHERTE Konfiguration dieser Instanz.';
        return $icon . ' ' . implode("\n", $message);
    }

    // ---------------------------------------------------------------------
    // interner Teil
    // ---------------------------------------------------------------------

    /** Registerzugriff für die spätere Sammel-Persistierung vormerken */
    private function noteRegisterActivity(int $address, string $kind): void
    {
        $this->pendingActivity[$address][$kind] = time();
    }

    /**
     * Sammelt die in einem ReceiveData()-Aufruf vorgemerkten Registerzugriffe
     * in EINEM Schreibzugriff auf die Attribut-Variable (statt je Register).
     */
    private function flushRegisterActivity(): void
    {
        if ($this->pendingActivity === []) {
            return;
        }
        $activity = json_decode((string) $this->ReadAttributeString('RegisterActivity'), true);
        if (!is_array($activity)) {
            $activity = [];
        }
        foreach ($this->pendingActivity as $address => $kinds) {
            $entry = $activity[(string) $address] ?? [];
            foreach ($kinds as $kind => $ts) {
                $entry[$kind] = $ts;
            }
            $activity[(string) $address] = $entry;
        }
        $this->WriteAttributeString('RegisterActivity', json_encode($activity));
        $this->pendingActivity = [];
    }

    /**
     * Optionale Timeout-Absicherung für schreibbare Register im generischen
     * Modus: Wurde ein konfiguriertes Register seit der (festen oder aus einem
     * Quell-Register gelesenen) Dauer nicht mehr beschrieben, wird der
     * konfigurierte Rückfallwert gesetzt. Läuft unabhängig vom RPC-Profil
     * (das hat mit armExpiry()/CheckExpire() seine eigene, andersartige
     * Ablauf-Logik inkl. separater "wirksamer Sollwert"-Variable) - bewusst
     * NICHT vereinheitlicht, weil die generische Registerzeile keine solche
     * getrennte Ist/Soll-Variable kennt, sondern direkt in die Zielvariable
     * zurückfällt. Wird minütlich über Watch() aufgerufen.
     */
    private function checkRegisterTimeouts(): void
    {
        $rules = json_decode((string) $this->ReadPropertyString('RegisterTimeouts'), true);
        if (!is_array($rules) || $rules === []) {
            return;
        }
        $registers = json_decode((string) $this->ReadPropertyString('Registers'), true);
        if (!is_array($registers)) {
            $registers = [];
        }
        $activity = json_decode((string) $this->ReadAttributeString('RegisterActivity'), true);
        if (!is_array($activity)) {
            $activity = [];
        }
        $applied = json_decode((string) $this->ReadAttributeString('TimeoutApplied'), true);
        if (!is_array($applied)) {
            $applied = [];
        }
        $now = time();
        $changed = false;

        foreach ($rules as $rule) {
            $address = (int) ($rule['Address'] ?? 0);
            if ($address <= 0) {
                continue;
            }
            $key = (string) $address;
            $lastWrite = (int) ($activity[$key]['w'] ?? 0);
            if ($lastWrite <= 0) {
                continue; // noch nie beschrieben - Timer beginnt erst mit dem ersten echten Schreibzugriff
            }

            $sourceAddress = (int) ($rule['SourceAddress'] ?? 0);
            $sourceValue = null;
            if ($sourceAddress > 0) {
                $sourceRow = self::findRegisterRow($registers, $sourceAddress);
                if ($sourceRow !== null) {
                    // reine Anzeige-/Auswertungslesart - zaehlt nicht als "von einem Master abgefragt"
                    $sourceValue = $this->currentRegisterValue(self::normalizeGenericRow($sourceRow));
                }
            }
            $seconds = MBSLVTimeoutGuard::effectiveSeconds(
                (float) ($rule['Duration'] ?? 0),
                $sourceValue,
                (string) ($rule['Unit'] ?? MBSLVTimeoutGuard::UNIT_SECONDS)
            );

            if (!MBSLVTimeoutGuard::isExpired($now, $lastWrite, $seconds)) {
                if (isset($applied[$key])) {
                    unset($applied[$key]);
                    $changed = true;
                }
                continue;
            }
            if (isset($applied[$key])) {
                continue; // Rückfallwert steht schon, nicht bei jedem Tick erneut schreiben
            }

            $targetRow = self::findRegisterRow($registers, $address);
            if ($targetRow === null) {
                $this->SendDebug('Timeout', sprintf('Register %d: keine passende Zeile in der Registertabelle', $address), 0);
                continue;
            }
            $fallback = (float) ($rule['Fallback'] ?? 0);
            $this->SendDebug('Timeout', sprintf('Register %d: %.0f s ohne Schreibzugriff - setze Rückfallwert %.3f', $address, $seconds, $fallback), 0);
            $this->applyValueToTarget(self::normalizeGenericRow($targetRow), $fallback);
            $applied[$key] = true;
            $changed = true;
        }

        if ($changed) {
            $this->WriteAttributeString('TimeoutApplied', json_encode($applied));
        }
    }

    /** Läuft aktuell ein Timeout-Rückfall für diese Adresse, wird er hier beendet */
    private function clearTimeoutFallback(int $address): void
    {
        $applied = json_decode((string) $this->ReadAttributeString('TimeoutApplied'), true);
        if (!is_array($applied) || !isset($applied[(string) $address])) {
            return;
        }
        unset($applied[(string) $address]);
        $this->WriteAttributeString('TimeoutApplied', json_encode($applied));
    }

    /** Formatierte Zugriffszeiten (HH:MM:SS bzw. "–") einer Registeradresse für die Formularanzeige */
    private function registerActivityLabels(array $activity, int $address): array
    {
        $entry = $activity[(string) $address] ?? [];
        $format = fn ($ts): string => $ts > 0 ? date('H:i:s', (int) $ts) : '–';
        return [
            'LastWritten' => $format($entry['w'] ?? 0),
            'LastRead'    => $format($entry['r'] ?? 0)
        ];
    }

    /** Kompakte Anzeige eines Registerwerts (Formular-Spalte "Wert") */
    private function formatCurrentValue(float $value): string
    {
        // number_format statt sprintf: sprintf folgt der Locale (deutsch: Komma), dann bliebe "100," übrig
        $text = rtrim(rtrim(number_format(round($value, 4), 4, ',', ''), '0'), ',');
        return $text === '' || $text === '-' ? '0' : $text;
    }

    /** Eingehende gültige Modbus-Frames als Lebenszeichen verbuchen */
    private function noteRequest(): void
    {
        $now = time();
        // Schreiblast begrenzen: bei 1-s-Polling nicht jede Anfrage persistieren
        if ($now - (int) $this->GetValue('LastRequest') >= 5) {
            $this->SetValue('LastRequest', $now);
        }
        $this->setStatusIfChanged(IS_ACTIVE);
    }

    /**
     * Statusampel: Socket-Zustand und Kommunikationsüberwachung in den
     * Instanzstatus spiegeln, damit Störungen ohne Log-Zugriff sichtbar sind.
     */
    private function UpdateHealth(): void
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parent === 0) {
            $this->setStatusIfChanged(self::STATUS_NO_SOCKET);
            return;
        }
        if (!IPS_GetProperty($parent, 'Open')) {
            // Socket ist ABSICHTLICH geschlossen (z. B. vorbereitete Instanz vor
            // dem eigentlichen Cutover) - das ist kein Fehler, sondern ein
            // normaler Zwischenzustand. IS_INACTIVE statt eines eigenen Fehler-
            // codes (>200), damit ein system-weiter Integrity-Check das nicht als
            // Störung zählt und daran hängende Watchdog-Skripte nicht unnötig
            // auslöst (Live-Vorfall an einer produktiven Anlage 13.09.2026, SUITE.md-Punkt 9d).
            $this->setStatusIfChanged(IS_INACTIVE);
            return;
        }
        if (IPS_GetInstance($parent)['InstanceStatus'] !== IS_ACTIVE) {
            // Socket SOLLTE laufen (Open=true), erreicht aber keinen aktiven
            // Zustand - das ist ein echtes Problem (z. B. Port belegt).
            $this->setStatusIfChanged(self::STATUS_NO_SOCKET);
            return;
        }
        $timeout = (int) $this->ReadPropertyInteger('CommTimeout');
        if ($timeout > 0) {
            $last = (int) $this->GetValue('LastRequest');
            if (time() - $last > $timeout * 60) {
                $this->setStatusIfChanged(self::STATUS_NO_TRAFFIC);
                return;
            }
        }
        $this->setStatusIfChanged(IS_ACTIVE);
    }

    private function setStatusIfChanged(int $status): void
    {
        if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] !== $status) {
            $this->SetStatus($status);
        }
    }

    /**
     * Variable nur bei echter Neuanlage registrieren (SUITE.md-Stolperstein 3:
     * RegisterVariableXXX nicht bedingungslos für bestehende Variablen aufrufen).
     */
    private function registerVarOnce(string $type, string $ident, string $name, string $profile, int $position): int
    {
        $id = (int) @$this->GetIDForIdent($ident);
        if ($id > 0) {
            return $id;
        }
        switch ($type) {
            case 'bool':
                return $this->RegisterVariableBoolean($ident, $name, $profile, $position);
            case 'int':
                return $this->RegisterVariableInteger($ident, $name, $profile, $position);
            default:
                return $this->RegisterVariableFloat($ident, $name, $profile, $position);
        }
    }

    private function setFormVisibility(array &$items, array $names, bool $visible): void
    {
        foreach ($items as &$item) {
            if (isset($item['name']) && in_array($item['name'], $names, true)) {
                $item['visible'] = $visible;
            }
            if (isset($item['items'])) {
                $this->setFormVisibility($item['items'], $names, $visible);
            }
        }
    }

    private function templateRow(int $addr, string $type, string $name, int $variable = 0, float $fixed = 0.0): array
    {
        return ['Name' => $name, 'Area' => 0, 'Address' => $addr, 'DataType' => $type,
            'VariableID' => $variable, 'Factor' => 1.0, 'Fixed' => $fixed, 'Writable' => self::WRITE_NONE];
    }

    /** Meteocontrol blue'Log RPC: Istwert-Register (Datenblatt 05-2020) */
    private function templateRowsRPC(int $effective): array
    {
        $rows = [];
        foreach ([['float32', 0], ['int32', 100]] as [$type, $base]) {
            $suffix = $base === 0 ? '' : ', int32';
            $rows[] = $this->templateRow($base + 0, $type, "PPC_P_AC_INV - Summe WR-Wirkleistung (W$suffix)");
            $rows[] = $this->templateRow($base + 2, $type, "PPC_P_AC - Ist-Wirkleistung Netzanalysator (W$suffix)");
            $rows[] = $this->templateRow($base + 4, $type, "PPC_P_SET_REL - aktuell gültiger Sollwert (%$suffix)", $effective);
            $rows[] = $this->templateRow($base + 6, $type, "PPC_P_SET_GRIDOP_REL - Sollwert Netzbetreiber (%$suffix)", 0, 100.0);
            $rows[] = $this->templateRow($base + 8, $type, "PPC_P_SET_RPC_REL - Sollwert Direktvermarkter (%$suffix)", $effective);
            $rows[] = $this->templateRow($base + 10, $type, "PPC_P_AC_GRIDOP_MAX - max. Leistung Netzbetreiber (W$suffix)");
            $rows[] = $this->templateRow($base + 12, $type, "PPC_P_AC_RPC_MAX - max. Leistung Dritte (W$suffix)");
            $rows[] = $this->templateRow($base + 14, $type, "PPC_P_SET_MODUS - Regelmodus (5 = RPC$suffix)", 0, 5.0);
        }
        $rows[] = $this->templateRow(4000, 'float32', 'PPC_P_AV - vereinbarte Anschlusswirkleistung (W)');
        return $rows;
    }

    /**
     * SunSpec: Common Model 1 + Wechselrichter Model 113 (dreiphasig, float32)
     * ab Basisregister 40000, Word-Order ABCD. Die float-Modelle (111-113)
     * kommen ohne Skalierungsfaktoren aus.
     */
    private function templateRowsSunSpec113(): array
    {
        $rows = [
            $this->templateRow(40000, 'uint32', 'SunSpec-Kennung "SunS"', 0, 1400204883.0),
            $this->templateRow(40002, 'uint16', 'Common Model - ID', 0, 1.0),
            $this->templateRow(40003, 'uint16', 'Common Model - Länge', 0, 66.0),
            $this->templateRow(40070, 'uint16', 'Model 113 - ID (WR dreiphasig, float)', 0, 113.0),
            $this->templateRow(40071, 'uint16', 'Model 113 - Länge', 0, 60.0)
        ];
        $points = [
            [40072, 'A - AC-Strom gesamt (A)'],
            [40074, 'AphA - AC-Strom L1 (A)'],
            [40076, 'AphB - AC-Strom L2 (A)'],
            [40078, 'AphC - AC-Strom L3 (A)'],
            [40080, 'PPVphAB - Spannung L1-L2 (V)'],
            [40082, 'PPVphBC - Spannung L2-L3 (V)'],
            [40084, 'PPVphCA - Spannung L3-L1 (V)'],
            [40086, 'PhVphA - Spannung L1-N (V)'],
            [40088, 'PhVphB - Spannung L2-N (V)'],
            [40090, 'PhVphC - Spannung L3-N (V)'],
            [40092, 'W - AC-Wirkleistung (W)'],
            [40094, 'Hz - Netzfrequenz (Hz)'],
            [40096, 'VA - Scheinleistung (VA)'],
            [40098, 'VAr - Blindleistung (var)'],
            [40100, 'PF - Leistungsfaktor'],
            [40102, 'WH - Energieertrag gesamt (Wh)'],
            [40104, 'DCA - DC-Strom (A)'],
            [40106, 'DCV - DC-Spannung (V)'],
            [40108, 'DCW - DC-Leistung (W)'],
            [40110, 'TmpCab - Temperatur Gehäuse (°C)'],
            [40112, 'TmpSnk - Temperatur Kühlkörper (°C)'],
            [40114, 'TmpTrns - Temperatur Trafo (°C)'],
            [40116, 'TmpOt - Temperatur sonstige (°C)']
        ];
        foreach ($points as [$addr, $name]) {
            $rows[] = $this->templateRow($addr, 'float32', $name);
        }
        $rows[] = $this->templateRow(40118, 'uint16', 'St - Betriebszustand (4 = MPPT/Einspeisung)', 0, 4.0);
        $rows[] = $this->templateRow(40119, 'uint16', 'StVnd - Betriebszustand herstellerspezifisch', 0, 0.0);
        foreach ([[40120, 'Evt1'], [40122, 'Evt2'], [40124, 'EvtVnd1'], [40126, 'EvtVnd2'], [40128, 'EvtVnd3'], [40130, 'EvtVnd4']] as [$addr, $name]) {
            $rows[] = $this->templateRow($addr, 'uint32', $name . ' - Ereignisbits', 0, 0.0);
        }
        $rows[] = $this->templateRow(40132, 'uint16', 'Endmodell - ID (0xFFFF)', 0, 65535.0);
        $rows[] = $this->templateRow(40133, 'uint16', 'Endmodell - Länge', 0, 0.0);
        return $rows;
    }

    /**
     * SunSpec: Common Model 1 + Zähler Model 213 (dreiphasig Wye, float32)
     * ab Basisregister 40000, Word-Order ABCD. Registerlayout generiert aus
     * der offiziellen Modelldefinition (github.com/sunspec/models, model_213.json).
     */
    private function templateRowsSunSpec213(): array
    {
        $rows = [
            $this->templateRow(40000, 'uint32', 'SunSpec-Kennung "SunS"', 0, 1400204883.0),
            $this->templateRow(40002, 'uint16', 'Common Model - ID', 0, 1.0),
            $this->templateRow(40003, 'uint16', 'Common Model - Länge', 0, 66.0),
            $this->templateRow(40070, 'uint16', 'Model 213 - ID (Zähler dreiphasig, float)', 0, 213.0),
            $this->templateRow(40071, 'uint16', 'Model 213 - Länge', 0, 124.0)
        ];
        $points = [
            [40072, 'A - Strom gesamt (A)'],
            [40074, 'AphA - Strom L1 (A)'],
            [40076, 'AphB - Strom L2 (A)'],
            [40078, 'AphC - Strom L3 (A)'],
            [40080, 'PhV - Spannung L-N Mittel (V)'],
            [40082, 'PhVphA - Spannung L1-N (V)'],
            [40084, 'PhVphB - Spannung L2-N (V)'],
            [40086, 'PhVphC - Spannung L3-N (V)'],
            [40088, 'PPV - Spannung L-L Mittel (V)'],
            [40090, 'PPVphAB - Spannung L1-L2 (V)'],
            [40092, 'PPVphBC - Spannung L2-L3 (V)'],
            [40094, 'PPVphCA - Spannung L3-L1 (V)'],
            [40096, 'Hz - Netzfrequenz (Hz)'],
            [40098, 'W - Wirkleistung gesamt (W, Export positiv)'],
            [40100, 'WphA - Wirkleistung L1 (W)'],
            [40102, 'WphB - Wirkleistung L2 (W)'],
            [40104, 'WphC - Wirkleistung L3 (W)'],
            [40106, 'VA - Scheinleistung gesamt (VA)'],
            [40108, 'VAphA - Scheinleistung L1 (VA)'],
            [40110, 'VAphB - Scheinleistung L2 (VA)'],
            [40112, 'VAphC - Scheinleistung L3 (VA)'],
            [40114, 'VAR - Blindleistung gesamt (var)'],
            [40116, 'VARphA - Blindleistung L1 (var)'],
            [40118, 'VARphB - Blindleistung L2 (var)'],
            [40120, 'VARphC - Blindleistung L3 (var)'],
            [40122, 'PF - Leistungsfaktor gesamt'],
            [40124, 'PFphA - Leistungsfaktor L1'],
            [40126, 'PFphB - Leistungsfaktor L2'],
            [40128, 'PFphC - Leistungsfaktor L3'],
            [40130, 'TotWhExp - Energie Export gesamt (Wh)'],
            [40132, 'TotWhExpPhA - Energie Export L1 (Wh)'],
            [40134, 'TotWhExpPhB - Energie Export L2 (Wh)'],
            [40136, 'TotWhExpPhC - Energie Export L3 (Wh)'],
            [40138, 'TotWhImp - Energie Import gesamt (Wh)'],
            [40140, 'TotWhImpPhA - Energie Import L1 (Wh)'],
            [40142, 'TotWhImpPhB - Energie Import L2 (Wh)'],
            [40144, 'TotWhImpPhC - Energie Import L3 (Wh)'],
            [40146, 'TotVAhExp - Scheinenergie Export (VAh)'],
            [40148, 'TotVAhExpPhA - Scheinenergie Export L1 (VAh)'],
            [40150, 'TotVAhExpPhB - Scheinenergie Export L2 (VAh)'],
            [40152, 'TotVAhExpPhC - Scheinenergie Export L3 (VAh)'],
            [40154, 'TotVAhImp - Scheinenergie Import (VAh)'],
            [40156, 'TotVAhImpPhA - Scheinenergie Import L1 (VAh)'],
            [40158, 'TotVAhImpPhB - Scheinenergie Import L2 (VAh)'],
            [40160, 'TotVAhImpPhC - Scheinenergie Import L3 (VAh)'],
            [40162, 'TotVArhImpQ1 - Blindenergie Import Q1 (varh)'],
            [40164, 'TotVArhImpQ1phA - Blindenergie Import Q1 L1 (varh)'],
            [40166, 'TotVArhImpQ1phB - Blindenergie Import Q1 L2 (varh)'],
            [40168, 'TotVArhImpQ1phC - Blindenergie Import Q1 L3 (varh)'],
            [40170, 'TotVArhImpQ2 - Blindenergie Import Q2 (varh)'],
            [40172, 'TotVArhImpQ2phA - Blindenergie Import Q2 L1 (varh)'],
            [40174, 'TotVArhImpQ2phB - Blindenergie Import Q2 L2 (varh)'],
            [40176, 'TotVArhImpQ2phC - Blindenergie Import Q2 L3 (varh)'],
            [40178, 'TotVArhExpQ3 - Blindenergie Export Q3 (varh)'],
            [40180, 'TotVArhExpQ3phA - Blindenergie Export Q3 L1 (varh)'],
            [40182, 'TotVArhExpQ3phB - Blindenergie Export Q3 L2 (varh)'],
            [40184, 'TotVArhExpQ3phC - Blindenergie Export Q3 L3 (varh)'],
            [40186, 'TotVArhExpQ4 - Blindenergie Export Q4 (varh)'],
            [40188, 'TotVArhExpQ4phA - Blindenergie Export Q4 L1 (varh)'],
            [40190, 'TotVArhExpQ4phB - Blindenergie Export Q4 L2 (varh)'],
            [40192, 'TotVArhExpQ4phC - Blindenergie Export Q4 L3 (varh)']
        ];
        foreach ($points as [$addr, $name]) {
            $rows[] = $this->templateRow($addr, 'float32', $name);
        }
        $rows[] = $this->templateRow(40194, 'uint32', 'Evt - Ereignisbits', 0, 0.0);
        $rows[] = $this->templateRow(40196, 'uint16', 'Endmodell - ID (0xFFFF)', 0, 65535.0);
        $rows[] = $this->templateRow(40197, 'uint16', 'Endmodell - Länge', 0, 0.0);
        return $rows;
    }

    private function buildServer(): MBSLVModbusServer
    {
        $rows = json_decode((string) $this->ReadPropertyString('Registers'), true);
        if (!is_array($rows)) {
            $rows = [];
        }
        $normalized = array_map([self::class, 'normalizeGenericRow'], $rows);

        if ((bool) $this->ReadPropertyBoolean('RPCEnabled')) {
            foreach ([
                'RPC_SETPOINT'  => 5000,
                'RPC_SCRATCH0'  => 5002,
                'RPC_SCRATCH1'  => 5004,
                'RPC_VALIDTIME' => 5006,
                'RPC_WATCHDOG'  => 5008
            ] as $ident => $address) {
                $normalized[] = [
                    'Area'     => MBSLVModbusServer::AREA_HOLDING,
                    'Address'  => $address,
                    'DataType' => 'float32',
                    'Factor'   => 1.0,
                    'Writable' => true,
                    'Ident'    => $ident
                ];
            }
        }

        return new MBSLVModbusServer(
            $normalized,
            (bool) $this->ReadPropertyBoolean('SwapWords'),
            (int) $this->ReadPropertyInteger('UnitID'),
            (bool) $this->ReadPropertyBoolean('CheckUnitID'),
            (int) $this->ReadPropertyInteger('UnmappedRead'),
            fn (array $row): float => $this->readRegisterValue($row),
            function (array $row, float $value): void {
                $this->writeRegisterValue($row, $value);
            },
            function (string $topic, string $message): void {
                $this->SendDebug($topic, $message, 0);
            }
        );
    }

    private function readRegisterValue(array $row): float
    {
        $this->noteRegisterActivity((int) ($row['Address'] ?? 0), 'r');
        return $this->currentRegisterValue($row);
    }

    /**
     * Aktueller Wert eines Registers OHNE Zugriffszeit zu vermerken - für die
     * reine Anzeige (Formular-Spalte "Wert"), damit das Betrachten des
     * Formulars nicht selbst als "von einem Master abgefragt" gezählt wird.
     */
    private function currentRegisterValue(array $row): float
    {
        if (isset($row['Ident'])) {
            switch ($row['Ident']) {
                case 'RPC_SETPOINT':
                    return (float) $this->GetValue('Setpoint');
                case 'RPC_VALIDTIME':
                    return (float) $this->GetValue('ValidTime');
                case 'RPC_WATCHDOG':
                    return (float) $this->ReadAttributeFloat('WatchdogValue');
                default:
                    $scratch = json_decode((string) $this->ReadAttributeString('ScratchValues'), true);
                    return (float) ($scratch[$row['Ident']] ?? 0.0);
            }
        }

        $variable = $row['VariableID'];
        if ($variable >= 10000 && IPS_VariableExists($variable)) {
            $value = GetValue($variable);
            if (is_bool($value)) {
                $value = $value ? 1.0 : 0.0;
            }
            return (float) $value * $row['Factor'];
        }
        if (MBSLVRegisterMemory::isMemoryCell($row)) {
            $memory = MBSLVRegisterMemory::decode((string) $this->ReadAttributeString('RegisterMemory'));
            return MBSLVRegisterMemory::get($memory, (int) ($row['Address'] ?? 0), (float) $row['Fixed']);
        }
        return $row['Fixed'];
    }

    private function writeRegisterValue(array $row, float $value): void
    {
        $this->noteRegisterActivity((int) ($row['Address'] ?? 0), 'w');
        if (isset($row['Ident'])) {
            $this->rpcWrite($row['Ident'], $value);
            return;
        }
        // ein echter Master-Schreibzugriff beendet einen laufenden Timeout-Rückfall
        $this->clearTimeoutFallback((int) ($row['Address'] ?? 0));
        $this->applyValueToTarget($row, $value);
    }

    /**
     * Schreibt einen Wert in die Zielvariable einer generischen Registerzeile
     * (Ident-Zeilen der RPC-Vorlage laufen NICHT hier durch, siehe rpcWrite()).
     * Bewusst getrennt von writeRegisterValue(): wird auch vom internen
     * Timeout-Rückfall (checkRegisterTimeouts()) genutzt, OHNE das als
     * "von einem Master geschrieben" in RegisterActivity zu vermerken - sonst
     * würde der Rückfall selbst seinen eigenen Timeout immer wieder neu
     * bewaffnen und nie zur Ruhe kommen.
     */
    private function applyValueToTarget(array $row, float $value): void
    {
        // Speicherzelle (beschreibbar, ohne Variable): Wert merken, beim Lesen kommt er zurück
        if (MBSLVRegisterMemory::isMemoryCell($row)) {
            $memory = MBSLVRegisterMemory::with(MBSLVRegisterMemory::decode((string) $this->ReadAttributeString('RegisterMemory')), (int) $row['Address'], $value);
            $this->WriteAttributeString('RegisterMemory', MBSLVRegisterMemory::encode($memory));
            $this->SendDebug('Schreiben', sprintf('Register %d -> Speicherzelle = %s', $row['Address'], json_encode($value)), 0);
            return;
        }
        $variable = $row['VariableID'];
        if ($variable < 10000 || !IPS_VariableExists($variable)) {
            $this->SendDebug('Schreiben', sprintf('Register %d: keine Zielvariable zugeordnet', $row['Address']), 0);
            return;
        }
        $scaled = $value / $row['Factor'];
        $info = IPS_GetVariable($variable);
        switch ($info['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $target = $scaled >= 0.5;
                break;
            case VARIABLETYPE_INTEGER:
                $target = (int) round($scaled);
                break;
            case VARIABLETYPE_STRING:
                $target = (string) $scaled;
                break;
            default:
                $target = $scaled;
        }
        // Schreibmodus "direkt": Aktion der Variable bewusst umgehen (z. B. Register
        // eines ModBus-Device, dessen Aktion sonst in ein anderes Gerät schreiben würde)
        $direct = ((int) ($row['Writable'] ?? 0)) === self::WRITE_DIRECT;
        $hasAction = !$direct && (($info['VariableCustomAction'] > 0) || ($info['VariableAction'] > 0));
        $this->SendDebug('Schreiben', sprintf('Register %d -> Variable #%d = %s (%s)', $row['Address'], $variable, json_encode($target), $direct ? 'SetValue, direkt' : ($hasAction ? 'RequestAction' : 'SetValue')), 0);
        if ($hasAction) {
            @RequestAction($variable, $target);
        } else {
            SetValue($variable, $target);
        }
    }

    private function rpcWrite(string $ident, float $value): void
    {
        $now = time();
        switch ($ident) {
            case 'RPC_SETPOINT':
                $value = max(0.0, min(125.0, $value));
                $this->SendDebug('RPC', sprintf('Sollwertvorgabe %.3f %% empfangen', $value), 0);
                $this->SetValue('Setpoint', $value);
                $this->SetValue('SetpointValid', true);
                $this->SetValue('LastWrite', $now);
                $this->armExpiry($now);
                $this->SetValue('Effective', $value);
                $this->runForwardScript('setpoint', $value);
                break;

            case 'RPC_VALIDTIME':
                $value = max(1.0, min(255.0, $value));
                $this->SendDebug('RPC', sprintf('Gültigkeitsdauer %.1f min empfangen', $value), 0);
                $this->SetValue('ValidTime', $value);
                break;

            case 'RPC_WATCHDOG':
                $this->WriteAttributeFloat('WatchdogValue', $value);
                if ($this->GetValue('SetpointValid')) {
                    $this->SendDebug('RPC', 'Watchdog empfangen - Gültigkeitsdauer neu gestartet', 0);
                    $this->SetValue('LastWrite', $now);
                    $this->armExpiry($now);
                    $this->runForwardScript('watchdog', (float) $this->GetValue('Setpoint'));
                } else {
                    // lt. Datenblatt: Watchdog nach Ablauf hält den Sollwert NICHT am Leben
                    $this->SendDebug('RPC', 'Watchdog empfangen, aber Vorgabe bereits abgelaufen - ignoriert', 0);
                }
                break;

            default: // RPC_SCRATCH0 / RPC_SCRATCH1 (Reserve-Register 5002-5005)
                $scratch = json_decode((string) $this->ReadAttributeString('ScratchValues'), true);
                if (!is_array($scratch)) {
                    $scratch = [];
                }
                $scratch[$ident] = $value;
                $this->WriteAttributeString('ScratchValues', json_encode($scratch));
        }
    }

    private function armExpiry(int $now): void
    {
        $minutes = (float) $this->GetValue('ValidTime');
        if ($minutes < 1) {
            $minutes = (float) $this->ReadPropertyFloat('RPCDefaultValidTime');
        }
        $until = $now + (int) round($minutes * 60);
        $this->SetValue('ValidUntil', $until);
        $this->SetTimerInterval('Expire', ($until - $now) * 1000);
    }

    private function runForwardScript(string $action, float $setpoint): void
    {
        $script = (int) $this->ReadPropertyInteger('RPCForwardScript');
        if ($script < 10000 || !IPS_ScriptExists($script)) {
            return;
        }
        IPS_RunScriptEx($script, [
            'Action'     => $action, // setpoint | watchdog | expired
            'Setpoint'   => $setpoint,
            'Valid'      => $this->GetValue('SetpointValid'),
            'InstanceID' => $this->InstanceID
        ]);
    }

    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('MBSLV.Percent')) {
            IPS_CreateVariableProfile('MBSLV.Percent', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileValues('MBSLV.Percent', 0, 125, 0);
            IPS_SetVariableProfileDigits('MBSLV.Percent', 1);
            IPS_SetVariableProfileText('MBSLV.Percent', '', ' %');
        }
        if (!IPS_VariableProfileExists('MBSLV.Minutes')) {
            IPS_CreateVariableProfile('MBSLV.Minutes', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileValues('MBSLV.Minutes', 1, 255, 0);
            IPS_SetVariableProfileDigits('MBSLV.Minutes', 0);
            IPS_SetVariableProfileText('MBSLV.Minutes', '', ' min');
        }
    }

    /** Binärdaten für den JSON-Datenaustausch kodieren (Latin-1 -> UTF-8) */
    private function encodeBuffer(string $data): string
    {
        return mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
    }

    /** Binärdaten aus dem JSON-Datenaustausch dekodieren (UTF-8 -> Latin-1) */
    private function decodeBuffer(string $data): string
    {
        return mb_convert_encoding($data, 'ISO-8859-1', 'UTF-8');
    }
}
