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
    private const NEWS_VERSION = '1.13';
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


    /** @var array<string,string> je Prozess gemerkte Übersetzungen (Translate() liest bei jedem Aufruf die locale.json, SUITE.md Stolperstein 24) */
    private static array $translations = [];

    /**
     * Text übersetzen. Quelltexte stehen englisch im Code und in der form.json, die deutsche
     * Fassung in locale.json. Mit Argumenten wie sprintf (Platzhalter %s/%d, ein Prozentzeichen als %%).
     */
    private function t(string $text, ...$args): string
    {
        if (!isset(self::$translations[$text])) {
            self::$translations[$text] = (string) $this->Translate($text);
        }
        $translated = self::$translations[$text];
        if ($args === []) {
            return $translated;
        }
        $result = @vsprintf($translated, array_map(fn ($a) => is_scalar($a) || $a === null ? (string) $a : (string) json_encode($a), $args));
        return $result === false ? $translated : $result;
    }

    /** Übersetzt in einem Formular alle Anzeigetexte (caption, confirm, label); Kommandos und Namen bleiben unverändert. */
    private function translateForm($node)
    {
        if (!is_array($node)) {
            return $node;
        }
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, ['caption', 'confirm', 'label'], true) && is_string($value)) {
                $node[$key] = $value === '' ? $value : $this->t($value);
            } elseif (is_array($value)) {
                $node[$key] = $this->translateForm($value);
            }
        }
        return $node;
    }

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
        $this->registerVarOnce('int', 'LastRequest', $this->t('Last Modbus request'), '~UnixTimestamp', 5);

        if ((bool) $this->ReadPropertyBoolean('RPCEnabled')) {
            // nur bei echter Neuanlage registrieren (SUITE.md-Stolperstein 3)
            $this->registerVarOnce('float', 'Setpoint', $this->t('Direct marketing setpoint'), 'MBSLV.Percent', 10);
            $this->registerVarOnce('bool', 'SetpointValid', $this->t('Direct marketing setpoint valid'), '~Switch', 20);
            $this->registerVarOnce('int', 'ValidUntil', $this->t('Direct marketing setpoint valid until'), '~UnixTimestamp', 30);
            $this->registerVarOnce('float', 'Effective', $this->t('Effective direct marketing setpoint'), 'MBSLV.Percent', 40);
            $this->registerVarOnce('float', 'ValidTime', $this->t('Validity period'), 'MBSLV.Minutes', 50);
            $this->registerVarOnce('int', 'LastWrite', $this->t('Last direct marketing setpoint'), '~UnixTimestamp', 60);

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
            'caption' => '👋  What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'This module turns Symcon into a Modbus TCP server: external devices and systems (Modbus clients, e.g. a control system, an energy management system or a direct marketer) can read and write freely chosen Symcon variables through it.'],
                ['type' => 'Label', 'caption' => 'The benefit: values from Symcon can be passed on to systems that only speak Modbus, and setpoints can be received from them - or a known Modbus device (e.g. a meter or data logger) can be emulated for a remote side that expects one.'],
                ['type' => 'Label', 'caption' => 'Would you like to read values from Modbus devices instead? For that, the NRG-Stack offers InverterHub (inverters), MeterHub (meters) and ChargerHub (wallboxes).'],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'MBSLV_AckPurposeIntro($id);'],
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
            'caption' => $this->t('🆕  New in version %s', self::NEWS_VERSION),
            'items' => [
                ['type' => 'Label', 'caption' => '• 🌐 Bilingual: the module is now fully available in German and English (form, messages, variable names, templates). The language follows the Symcon setting.'],
                ['type' => 'Label', 'caption' => '• ℹ️ Variables and register names that already exist keep their previous (German) names; new instances use the language set in Symcon.'],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'MBSLV_AckNews($id);'],
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
            'caption' => '💬  Feedback in the Symcon forum',
            'items' => [
                ['type' => 'Label', 'caption' => 'Feedback, questions and bug reports about this module are explicitly welcome - preferably in the thread in the Symcon forum.'],
                ['type' => 'Button', 'caption' => 'To the forum thread', 'onClick' => "echo '" . self::FEEDBACK_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'MBSLV_AckForumHint($id);'],
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
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of real enthusiasm for my own setup — and quite a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'You like the module and would like to leave something anyway? I am happy about a small donation — completely voluntary, nothing required in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
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
            $this->SendDebug($this->t('Connection'), $this->t('%s:%d connected', $ip, $port), 0);
            return '';
        }
        if ($type === 2) { // Verbindung beendet
            $this->SetBuffer($key, '');
            $this->SendDebug($this->t('Connection'), $this->t('%s:%d disconnected', $ip, $port), 0);
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
        $this->SendDebug('RPC', $this->t('Setpoint expired - falling back to %.1f %%', $fallback), 0);
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
                array_unshift($element['items'], ['type' => 'Label', 'caption' => $version !== '' ? $this->t('ModbusServer %s - version of this documentation.', $version) : $this->t('ModbusServer - version of this documentation.')]);
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
        return json_encode($this->translateForm($form));
    }

    /**
     * Gespeicherte Registerzeilen mit normalisierter Spalte "Writable" (0/1/2)
     * sowie den zuletzt beobachteten Zugriffszeiten je Register (Spalten
     * "Empfangen"/"Abgefragt") - rein zur Anzeige, nicht Teil der gespeicherten
     * Konfiguration (buildServer() liest nur die bekannten Registerfelder).
     */
    /**
     * @param array|null $rows Vorgefertigte Zeilen (z. B. mit gerade erst vergebenen
     *                         VariableIDs, siehe CreateRowVariables()) statt der
     *                         gespeicherten Konfiguration - für die Live-Aktualisierung
     *                         des offenen Formulars, bevor "Übernehmen" gespeichert hat.
     */
    private function registersForForm(?array $rows = null): array
    {
        if ($rows === null) {
            $rows = json_decode((string) $this->ReadPropertyString('Registers'), true);
        }
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
                $rule['Status'] = $this->t('⛔ Address not in the register table');
            } elseif (self::normalizeWriteMode($targetRow['Writable'] ?? 0) === self::WRITE_NONE) {
                $rule['Status'] = $this->t('⛔ Register is set to "read only"');
            } else {
                $lastWrite = (int) ($activity[$key]['w'] ?? 0);
                if ($lastWrite <= 0) {
                    $rule['Status'] = $this->t('– never written');
                } elseif (isset($applied[$key])) {
                    $rule['Status'] = $this->t('⚠️ in fallback (last written %s)', date('H:i:s', $lastWrite));
                } else {
                    $rule['Status'] = $this->t('✅ current (last written %s)', date('H:i:s', $lastWrite));
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
            return $this->t('ℹ️ No Server Socket connected.');
        }
        $port = (int) IPS_GetProperty($parent, 'Port');
        if (!IPS_GetProperty($parent, 'Open')) {
            return $this->t('ℹ️ Server Socket is closed (port %d) - not active yet.', $port);
        }
        if (IPS_GetInstance($parent)['InstanceStatus'] !== IS_ACTIVE) {
            return $this->t('❌ Server Socket not active (port %d).', $port);
        }
        $lastRequestID = (int) @$this->GetIDForIdent('LastRequest');
        $last = $lastRequestID > 0 ? (int) GetValue($lastRequestID) : 0;
        $base = $last === 0
            ? $this->t('⚠️ Reachable on port %d (no request received yet).', $port)
            : $this->t('✅ Reachable on port %d (last request at %s).', $port, date('H:i:s', $last));

        $fallbackCount = $this->activeTimeoutFallbackCount();
        if ($fallbackCount > 0) {
            $base .= $this->t(' ⚠️ %d register(s) at the timeout fallback value.', $fallbackCount);
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
                    return $this->t('✅ Template loaded (unit ID 10, word order CDAB set, RPC interface enabled - settings in the panel that has appeared). Please assign the actual-value variables (inverter power, grid power, P_AV) and save with \'Apply changes\'.');
                }
                return $this->t('✅ Template loaded (unit ID 10, word order CDAB set, RPC interface enabled - settings in the panel that has appeared). After \'Apply changes\', load the template again so that the setpoint registers (4/8/104/108) are linked automatically to the variable \'Effective direct marketing setpoint\'. Please assign the actual-value variables manually.');

            case 'sunspec113':
                $this->UpdateFormField('Registers', 'values', json_encode($this->templateRowsSunSpec113()));
                $this->UpdateFormField('SwapWords', 'value', false);
                $this->UpdateFormField('CheckUnitID', 'value', true);
                return $this->t('✅ SunSpec template loaded (word order ABCD set): Common Model 1 + inverter Model 113 (three-phase, float32) from base register 40000. Please assign measurement variables, adjust the unit ID to the remote side (usually 1 or 126) and save with \'Apply changes\'. Note: the text fields of the Common Model (manufacturer/model/serial number) return 0 - the module does not support strings.');

            case 'sunspec213':
                $this->UpdateFormField('Registers', 'values', json_encode($this->templateRowsSunSpec213()));
                $this->UpdateFormField('SwapWords', 'value', false);
                $this->UpdateFormField('CheckUnitID', 'value', true);
                return $this->t('✅ SunSpec template loaded (word order ABCD set): Common Model 1 + meter Model 213 (three-phase, float32) from base register 40000 - this lets Symcon appear as a SunSpec grid meter to wallbox/EMS systems (evcc, openWB etc.). Most important assignments: W (active power, sign: export positive), TotWhImp/TotWhExp (energy counters). Unassigned detail points return 0. Adjust the unit ID to the remote side and save with \'Apply changes\'.');

            default:
                return $this->t('⛔ Unknown template: %s', $Template);
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
        // $RowsJson wird bewusst NICHT ausgewertet (Live-Fund Solarpark 22.09.2026): Der
        // Browser-Stand des Registertabelle-Feldes kommt beim Klick auf diesen Button nicht
        // zuverlässig als die echten Zeilen an - bei einer geöffneten Instanz lieferte Symcon
        // hier nur die interne "Zeile hinzufügen"-Vorlage (Adresse 0, leerer Name), wodurch der
        // Button eine Fantasie-Variable "Register 0" anlegte und die ANZEIGE im Formular mit
        // dieser einen Zeile überschrieb - die zuvor sichtbaren echten Zeilen waren scheinbar
        // weg (tatsächlich nur die Anzeige, die gespeicherte Konfiguration blieb unangetastet,
        // solange nicht zusätzlich "Übernehmen" geklickt wurde). Verlässliche Quelle ist
        // ausschließlich die gespeicherte Konfiguration; eine gerade erst hinzugefügte, noch
        // nicht übernommene Zeile bekommt ihren Datenpunkt deshalb erst nach "Übernehmen".
        $rows = json_decode($this->ReadPropertyString('Registers'), true);
        if (!is_array($rows) || $rows === []) {
            return $this->t('⚠️ The register table is empty - first create rows, apply, then click this button again.');
        }
        // Absicherung: eine gespeicherte Konfiguration ist immer ein echtes Array, das ist nur
        // Sicherheitsnetz gegen eine von Hand beschädigte Property.
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

        // registersForForm() statt der rohen $rows: reichert Wert/Empfangen/Abgefragt wieder an,
        // sonst verschwänden diese Anzeige-Spalten nach dem Klick aus dem offenen Formular.
        $this->UpdateFormField('Registers', 'values', json_encode($this->registersForForm($rows)));

        $icon = ($created + $reused) > 0 ? '✅' : '⚠️';
        $message = $this->t('%s %d data point(s) created, %d reused and entered into the table.', $icon, $created, $reused);
        if ($skipped > 0) {
            $message .= $this->t(' %d row(s) with a fixed value were skipped.', $skipped);
        }
        $message .= ' ' . $this->t("Save with 'Apply changes'. The data points belong to the instance and can be filled from any source via event/script.");
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
            return $this->t('⚠️ No valid ports given. Examples: \'502-505\' or \'502,503,1502\'.');
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
            $message[] = $this->t('Created: port %s.', implode(', ', $created));
        }
        if ($skipped !== []) {
            $message[] = $this->t('Skipped (already in use): port %s.', implode(', ', $skipped));
        }
        $message[] = $this->t('Note: the last SAVED configuration of this instance was copied.');
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
                $this->SendDebug('Timeout', $this->t('Register %d: no matching row in the register table', $address), 0);
                continue;
            }
            $fallback = (float) ($rule['Fallback'] ?? 0);
            $this->SendDebug('Timeout', $this->t('Register %d: %.0f s without write access - setting fallback value %.3f', $address, $seconds, $fallback), 0);
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
        $decimal = $this->t('.');
        $text = rtrim(rtrim(number_format(round($value, 4), 4, $decimal, ''), '0'), $decimal);
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
            $rows[] = $this->templateRow($base + 0, $type, $this->t('PPC_P_AC_INV - sum of inverter active power') . " (W$suffix)");
            $rows[] = $this->templateRow($base + 2, $type, $this->t('PPC_P_AC - actual active power at the grid analyzer') . " (W$suffix)");
            $rows[] = $this->templateRow($base + 4, $type, $this->t('PPC_P_SET_REL - currently valid setpoint') . " (%$suffix)", $effective);
            $rows[] = $this->templateRow($base + 6, $type, $this->t('PPC_P_SET_GRIDOP_REL - setpoint grid operator') . " (%$suffix)", 0, 100.0);
            $rows[] = $this->templateRow($base + 8, $type, $this->t('PPC_P_SET_RPC_REL - setpoint direct marketer') . " (%$suffix)", $effective);
            $rows[] = $this->templateRow($base + 10, $type, $this->t('PPC_P_AC_GRIDOP_MAX - max. power grid operator') . " (W$suffix)");
            $rows[] = $this->templateRow($base + 12, $type, $this->t('PPC_P_AC_RPC_MAX - max. power third party') . " (W$suffix)");
            $rows[] = $this->templateRow($base + 14, $type, $this->t('PPC_P_SET_MODUS - control mode') . " (5 = RPC$suffix)", 0, 5.0);
        }
        $rows[] = $this->templateRow(4000, 'float32', $this->t('PPC_P_AV - agreed connection active power (W)'));
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
            $this->templateRow(40000, 'uint32', $this->t('SunSpec identifier "SunS"'), 0, 1400204883.0),
            $this->templateRow(40002, 'uint16', 'Common Model - ID', 0, 1.0),
            $this->templateRow(40003, 'uint16', $this->t('Common Model - length'), 0, 66.0),
            $this->templateRow(40070, 'uint16', $this->t('Model 113 - ID (inverter three-phase, float)'), 0, 113.0),
            $this->templateRow(40071, 'uint16', $this->t('Model 113 - length'), 0, 60.0)
        ];
        $points = [
            [40072, 'A - AC current total (A)'],
            [40074, 'AphA - AC current L1 (A)'],
            [40076, 'AphB - AC current L2 (A)'],
            [40078, 'AphC - AC current L3 (A)'],
            [40080, 'PPVphAB - voltage L1-L2 (V)'],
            [40082, 'PPVphBC - voltage L2-L3 (V)'],
            [40084, 'PPVphCA - voltage L3-L1 (V)'],
            [40086, 'PhVphA - voltage L1-N (V)'],
            [40088, 'PhVphB - voltage L2-N (V)'],
            [40090, 'PhVphC - voltage L3-N (V)'],
            [40092, 'W - AC active power (W)'],
            [40094, 'Hz - grid frequency (Hz)'],
            [40096, 'VA - apparent power (VA)'],
            [40098, 'VAr - reactive power (var)'],
            [40100, 'PF - power factor'],
            [40102, 'WH - total energy yield (Wh)'],
            [40104, 'DCA - DC current (A)'],
            [40106, 'DCV - DC voltage (V)'],
            [40108, 'DCW - DC power (W)'],
            [40110, 'TmpCab - cabinet temperature (°C)'],
            [40112, 'TmpSnk - heat sink temperature (°C)'],
            [40114, 'TmpTrns - transformer temperature (°C)'],
            [40116, 'TmpOt - other temperature (°C)']
        ];
        foreach ($points as [$addr, $name]) {
            $rows[] = $this->templateRow($addr, 'float32', $this->t($name));
        }
        $rows[] = $this->templateRow(40118, 'uint16', $this->t('St - operating state (4 = MPPT/feed-in)'), 0, 4.0);
        $rows[] = $this->templateRow(40119, 'uint16', $this->t('StVnd - operating state, vendor-specific'), 0, 0.0);
        foreach ([[40120, 'Evt1'], [40122, 'Evt2'], [40124, 'EvtVnd1'], [40126, 'EvtVnd2'], [40128, 'EvtVnd3'], [40130, 'EvtVnd4']] as [$addr, $name]) {
            $rows[] = $this->templateRow($addr, 'uint32', $this->t('%s - event bits', $name), 0, 0.0);
        }
        $rows[] = $this->templateRow(40132, 'uint16', $this->t('End model - ID (0xFFFF)'), 0, 65535.0);
        $rows[] = $this->templateRow(40133, 'uint16', $this->t('End model - length'), 0, 0.0);
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
            $this->templateRow(40000, 'uint32', $this->t('SunSpec identifier "SunS"'), 0, 1400204883.0),
            $this->templateRow(40002, 'uint16', 'Common Model - ID', 0, 1.0),
            $this->templateRow(40003, 'uint16', $this->t('Common Model - length'), 0, 66.0),
            $this->templateRow(40070, 'uint16', $this->t('Model 213 - ID (meter three-phase, float)'), 0, 213.0),
            $this->templateRow(40071, 'uint16', $this->t('Model 213 - length'), 0, 124.0)
        ];
        $points = [
            [40072, 'A - current total (A)'],
            [40074, 'AphA - current L1 (A)'],
            [40076, 'AphB - current L2 (A)'],
            [40078, 'AphC - current L3 (A)'],
            [40080, 'PhV - voltage L-N average (V)'],
            [40082, 'PhVphA - voltage L1-N (V)'],
            [40084, 'PhVphB - voltage L2-N (V)'],
            [40086, 'PhVphC - voltage L3-N (V)'],
            [40088, 'PPV - voltage L-L average (V)'],
            [40090, 'PPVphAB - voltage L1-L2 (V)'],
            [40092, 'PPVphBC - voltage L2-L3 (V)'],
            [40094, 'PPVphCA - voltage L3-L1 (V)'],
            [40096, 'Hz - grid frequency (Hz)'],
            [40098, 'W - active power total (W, export positive)'],
            [40100, 'WphA - active power L1 (W)'],
            [40102, 'WphB - active power L2 (W)'],
            [40104, 'WphC - active power L3 (W)'],
            [40106, 'VA - apparent power total (VA)'],
            [40108, 'VAphA - apparent power L1 (VA)'],
            [40110, 'VAphB - apparent power L2 (VA)'],
            [40112, 'VAphC - apparent power L3 (VA)'],
            [40114, 'VAR - reactive power total (var)'],
            [40116, 'VARphA - reactive power L1 (var)'],
            [40118, 'VARphB - reactive power L2 (var)'],
            [40120, 'VARphC - reactive power L3 (var)'],
            [40122, 'PF - power factor total'],
            [40124, 'PFphA - power factor L1'],
            [40126, 'PFphB - power factor L2'],
            [40128, 'PFphC - power factor L3'],
            [40130, 'TotWhExp - energy export total (Wh)'],
            [40132, 'TotWhExpPhA - energy export L1 (Wh)'],
            [40134, 'TotWhExpPhB - energy export L2 (Wh)'],
            [40136, 'TotWhExpPhC - energy export L3 (Wh)'],
            [40138, 'TotWhImp - energy import total (Wh)'],
            [40140, 'TotWhImpPhA - energy import L1 (Wh)'],
            [40142, 'TotWhImpPhB - energy import L2 (Wh)'],
            [40144, 'TotWhImpPhC - energy import L3 (Wh)'],
            [40146, 'TotVAhExp - apparent energy export (VAh)'],
            [40148, 'TotVAhExpPhA - apparent energy export L1 (VAh)'],
            [40150, 'TotVAhExpPhB - apparent energy export L2 (VAh)'],
            [40152, 'TotVAhExpPhC - apparent energy export L3 (VAh)'],
            [40154, 'TotVAhImp - apparent energy import (VAh)'],
            [40156, 'TotVAhImpPhA - apparent energy import L1 (VAh)'],
            [40158, 'TotVAhImpPhB - apparent energy import L2 (VAh)'],
            [40160, 'TotVAhImpPhC - apparent energy import L3 (VAh)'],
            [40162, 'TotVArhImpQ1 - reactive energy import Q1 (varh)'],
            [40164, 'TotVArhImpQ1phA - reactive energy import Q1 L1 (varh)'],
            [40166, 'TotVArhImpQ1phB - reactive energy import Q1 L2 (varh)'],
            [40168, 'TotVArhImpQ1phC - reactive energy import Q1 L3 (varh)'],
            [40170, 'TotVArhImpQ2 - reactive energy import Q2 (varh)'],
            [40172, 'TotVArhImpQ2phA - reactive energy import Q2 L1 (varh)'],
            [40174, 'TotVArhImpQ2phB - reactive energy import Q2 L2 (varh)'],
            [40176, 'TotVArhImpQ2phC - reactive energy import Q2 L3 (varh)'],
            [40178, 'TotVArhExpQ3 - reactive energy export Q3 (varh)'],
            [40180, 'TotVArhExpQ3phA - reactive energy export Q3 L1 (varh)'],
            [40182, 'TotVArhExpQ3phB - reactive energy export Q3 L2 (varh)'],
            [40184, 'TotVArhExpQ3phC - reactive energy export Q3 L3 (varh)'],
            [40186, 'TotVArhExpQ4 - reactive energy export Q4 (varh)'],
            [40188, 'TotVArhExpQ4phA - reactive energy export Q4 L1 (varh)'],
            [40190, 'TotVArhExpQ4phB - reactive energy export Q4 L2 (varh)'],
            [40192, 'TotVArhExpQ4phC - reactive energy export Q4 L3 (varh)']
        ];
        foreach ($points as [$addr, $name]) {
            $rows[] = $this->templateRow($addr, 'float32', $this->t($name));
        }
        $rows[] = $this->templateRow(40194, 'uint32', $this->t('Evt - event bits'), 0, 0.0);
        $rows[] = $this->templateRow(40196, 'uint16', $this->t('End model - ID (0xFFFF)'), 0, 65535.0);
        $rows[] = $this->templateRow(40197, 'uint16', $this->t('End model - length'), 0, 0.0);
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
            $this->SendDebug($this->t('Write'), $this->t('Register %d -> memory cell = %s', $row['Address'], json_encode($value)), 0);
            return;
        }
        $variable = $row['VariableID'];
        if ($variable < 10000 || !IPS_VariableExists($variable)) {
            $this->SendDebug($this->t('Write'), $this->t('Register %d: no target variable assigned', $row['Address']), 0);
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
        $this->SendDebug($this->t('Write'), $this->t('Register %d -> variable #%d = %s (%s)', $row['Address'], $variable, json_encode($target), $direct ? $this->t('SetValue, direct') : ($hasAction ? 'RequestAction' : 'SetValue')), 0);
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
                $this->SendDebug('RPC', $this->t('Setpoint %.3f %% received', $value), 0);
                $this->SetValue('Setpoint', $value);
                $this->SetValue('SetpointValid', true);
                $this->SetValue('LastWrite', $now);
                $this->armExpiry($now);
                $this->SetValue('Effective', $value);
                $this->runForwardScript('setpoint', $value);
                break;

            case 'RPC_VALIDTIME':
                $value = max(1.0, min(255.0, $value));
                $this->SendDebug('RPC', $this->t('Validity period %.1f min received', $value), 0);
                $this->SetValue('ValidTime', $value);
                break;

            case 'RPC_WATCHDOG':
                $this->WriteAttributeFloat('WatchdogValue', $value);
                if ($this->GetValue('SetpointValid')) {
                    $this->SendDebug('RPC', $this->t('Watchdog received - validity period restarted'), 0);
                    $this->SetValue('LastWrite', $now);
                    $this->armExpiry($now);
                    $this->runForwardScript('watchdog', (float) $this->GetValue('Setpoint'));
                } else {
                    // lt. Datenblatt: Watchdog nach Ablauf hält den Sollwert NICHT am Leben
                    $this->SendDebug('RPC', $this->t('Watchdog received, but setpoint already expired - ignored'), 0);
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
