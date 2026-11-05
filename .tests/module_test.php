<?php

declare(strict_types=1);

/**
 * Modul-Test mit Stub-Umgebung (ohne IPS): php .tests/module_test.php
 *
 * Ersetzt IPSModule und die benötigten IPS_*-Funktionen durch kleine Attrappen
 * und lässt das echte module.php laufen: Formularaufbau (Reihenfolge der Panels
 * nach Verbund-Konvention, Doku-Version, Lizenz-Panel), geteiltes Ausblenden der
 * Hinweise über Geschwister-Instanzen sowie die Speicherzellen (Schreiben, Lesen,
 * Aufräumen in ApplyChanges). Prüft NICHT das Verhalten in echtem Symcon.
 */
define('IS_ACTIVE',102); define('IS_INACTIVE',104);
define('VARIABLETYPE_BOOLEAN',0); define('VARIABLETYPE_INTEGER',1); define('VARIABLETYPE_FLOAT',2); define('VARIABLETYPE_STRING',3);
// PHP-Warnungen werden aufgezeichnet und am Ende geprüft (nicht als Ausnahme geworfen: Symcon wirft auch keine, sondern druckt sie VOR das
// Formular-JSON, das Formular wird dadurch unlesbar - ein try/catch im Modul würde sie im Test verstecken)
$GLOBALS['warnings'] = [];
set_error_handler(function ($no, $msg, $file, $line) { $GLOBALS['warnings'][] = $msg . ' (Zeile ' . $line . ')'; return true; });
$GLOBALS['props'] = ['RPCEnabled'=>false,'Registers'=>json_encode([
  ['Name'=>'a','Area'=>0,'Address'=>5000,'DataType'=>'float32','VariableID'=>0,'Factor'=>1,'Fixed'=>100,'Writable'=>2],
  ['Name'=>'b','Area'=>0,'Address'=>100,'DataType'=>'int32','VariableID'=>0,'Factor'=>1,'Fixed'=>0,'Writable'=>0]]),
  'RegisterTimeouts'=>'[]','UnitID'=>1,'CheckUnitID'=>true,'SwapWords'=>false,'UnmappedRead'=>0,'CommTimeout'=>0];
$GLOBALS['attrs'] = ['PurposeIntroGone'=>false,'ForumHintGone'=>false,'SeenNews'=>'','RegisterActivity'=>'{}','TimeoutApplied'=>'{}','RegisterMemory'=>'{"5000":42.5}','ScratchValues'=>'{}'];
$GLOBALS['updates'] = [];
class IPSModule {
  public $InstanceID = 12345;
  public function __construct() {}
  public function Create() {} public function ApplyChanges() {} public function Destroy() {}
  public function RequireParent($g) {}
  public function RegisterPropertyInteger($n,$v){ $GLOBALS['props'][$n] ??= $v; } public function RegisterPropertyBoolean($n,$v){ $GLOBALS['props'][$n] ??= $v; }
  public function RegisterPropertyString($n,$v){ $GLOBALS['props'][$n] ??= $v; } public function RegisterPropertyFloat($n,$v){ $GLOBALS['props'][$n] ??= $v; }
  public function RegisterAttributeString($n,$v){ $GLOBALS['attrs'][$n] ??= $v; } public function RegisterAttributeBoolean($n,$v){ $GLOBALS['attrs'][$n] ??= $v; } public function RegisterAttributeFloat($n,$v){ $GLOBALS['attrs'][$n] ??= $v; }
  public function ReadPropertyString($n){ return $GLOBALS['props'][$n]; } public function ReadPropertyBoolean($n){ return $GLOBALS['props'][$n]; }
  public function ReadPropertyInteger($n){ return $GLOBALS['props'][$n]; } public function ReadPropertyFloat($n){ return $GLOBALS['props'][$n]; }
  public function ReadAttributeString($n){ return $GLOBALS['attrs'][$n]; } public function ReadAttributeBoolean($n){ return $GLOBALS['attrs'][$n]; } public function ReadAttributeFloat($n){ return $GLOBALS['attrs'][$n]; }
  public function WriteAttributeString($n,$v){ $GLOBALS['attrs'][$n]=$v; } public function WriteAttributeBoolean($n,$v){ $GLOBALS['attrs'][$n]=$v; }
  public function RegisterTimer($n,$i,$s){} public function SetTimerInterval($n,$i){} public function RegisterMessage($a,$b){}
  public function UpdateFormField($f,$k,$v){ $GLOBALS['updates'][] = [$f,$k,$v]; }
  public function Translate($text){ $GLOBALS['translateCalls']++; return $GLOBALS['locale'][$text] ?? $text; }
  public function SendDebug($a,$b,$c){} public function GetValue($i){ return 0; } public function SetValue($i,$v){}
  public function GetIDForIdent($i){ return 0; } public function RegisterVariableInteger($i,$n,$p='',$pos=0){ return 1; } public function RegisterVariableFloat($i,$n,$p='',$pos=0){ return 1; } public function RegisterVariableBoolean($i,$n,$p='',$pos=0){ return 1; } public function MaintainVariable(){} public function SetStatus($s){} public function GetStatus(){ return 102; }
}
function IPS_GetInstance($id){ return ['ConnectionID'=>0,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>'{3F519A7D-1ABC-417D-BC08-8CCEDE0BEEE8}','ModuleName'=>'ModbusTCPServer']]; }
function IPS_GetModule($g){ return ['ModuleID'=>$g,'LibraryID'=>'{1C9B79B6-35D6-4381-907C-ADAE1FEAF307}']; }
function IPS_GetLibrary($id){ if ($id !== '{1C9B79B6-35D6-4381-907C-ADAE1FEAF307}') { trigger_error($id . ' is not a valid GUID', E_USER_WARNING); return false; } return ['Version'=>'1.11.0']; }
function IPS_GetInstanceListByModuleID($g){ return [12345, 22222]; }
function IPS_VariableExists($i){ return false; } function IPS_GetName($i){ return 'Test'; } function IPS_GetObject($i){ return ['ParentID'=>0]; }
function IPS_GetProperty($i,$n){ return 0; } function IPS_VariableProfileExists($n){ return true; }
$sibState = ['purposeIntroGone'=>true,'forumHintGone'=>true,'seenNews'=>'1.13']; $GLOBALS['adopted']=[];
function MBSLV_AdoptDismissState($id,$w,$v){ $GLOBALS['adopted'][]=[$id,$w,$v]; }
function MBSLV_GetDismissState($id){ global $sibState; return $sibState; }
// Symcon-Sprache: Deutsch (locale.json) - Quelltexte sind englisch, die vorhandenen Prüfungen unten erwarten die deutsche Fassung
$GLOBALS['locale'] = json_decode(file_get_contents(__DIR__ . '/../ModbusTCPServer/locale.json'), true)['translations']['de'];
$GLOBALS['translateCalls'] = 0;
require __DIR__ . '/../ModbusTCPServer/module.php';
$m = new ModbusTCPServer();
$fail = 0; function t($n,$ok){ global $fail; echo ($ok?'OK   ':'FAIL ').$n."\n"; if(!$ok)$fail++; }
$m->Create();
$form = json_decode($m->GetConfigurationForm(), true);
t('Formular ist gültiges JSON', is_array($form) && isset($form['elements']));
$caps = array_map(fn($e)=>($e['name'] ?? '') ?: ($e['caption'] ?? $e['type']), $form['elements']);
echo '   Reihenfolge: ' . implode(' | ', array_map(fn($c)=>mb_substr($c,0,28), $caps)) . "\n";
t('1. Panel = Zweck', ($form['elements'][0]['name'] ?? '') === 'PurposeIntroPanel');
t('2. Panel = Neu in Version', ($form['elements'][1]['name'] ?? '') === 'NewsPanel' && str_contains($form['elements'][1]['caption'], '1.13'));
t('3. Panel = Dokumentation & Hilfe, eingeklappt', ($form['elements'][2]['name'] ?? '') === 'DocPanel' && $form['elements'][2]['expanded'] === false);
t('Doku-Panel nennt Version', str_contains($form['elements'][2]['items'][0]['caption'], '1.11.0'));
$last = end($form['elements']);
$forum = $form['elements'][count($form['elements']) - 2];
t('Letztes Panel = Über dieses Modul, eingeklappt, ohne name', str_contains($last['caption'], 'Über dieses Modul') && $last['expanded'] === false && !isset($last['name']));
t('Lizenz-Link-Buttons: onClick echo + link=true, Ziel LICENSE auf beta', $last['items'][2]['link'] === true && str_contains($last['items'][2]['onClick'], 'echo') && str_contains($last['items'][2]['onClick'], 'NRGModbusServer/blob/beta/LICENSE'));
t('Vorletztes Panel = Feedback (Forum-Hinweis), aufgeklappt', ($forum['name'] ?? '') === 'ForumHintPanel' && $forum['expanded'] === true && str_contains($forum['caption'], 'Feedback'));
t('Feedback-Link-Button: onClick echo + link=true, Ziel Forum-Thread', $forum['items'][1]['link'] === true && str_contains($forum['items'][1]['onClick'], "echo 'https://community.symcon.de/t/144448'"));
t('Statuszeile PortInfo weiterhin vorhanden', in_array('PortInfo', $caps, true));
$reg = null; foreach ($form['elements'] as $e) if (($e['name'] ?? '')==='Registers') $reg=$e;
t('Speicherzelle zeigt gemerkten Wert 42.5 in Spalte Wert', str_contains($reg['values'][0]['CurrentValue'], '42'));
t('Zeile ohne Speicher zeigt Festwert-Anzeige', isset($reg['values'][1]['CurrentValue']));
// Ausblenden teilen
$m->AckPurposeIntro();
t('AckPurposeIntro setzt Attribut', $GLOBALS['attrs']['PurposeIntroGone'] === true);
t('AckPurposeIntro reicht an Geschwister weiter (nicht an sich selbst)', $GLOBALS['adopted'] === [[22222,'PurposeIntro','']]);
$GLOBALS['adopted'] = [];
$m->AckForumHint();
t('AckForumHint setzt Attribut und reicht an Geschwister weiter', $GLOBALS['attrs']['ForumHintGone'] === true && $GLOBALS['adopted'] === [[22222,'ForumHint','']]);
$m->AckNews();
t('AckNews speichert Version', $GLOBALS['attrs']['SeenNews'] === '1.13');
$form2 = json_decode($m->GetConfigurationForm(), true);
t('Nach Bestätigen fehlen Zweck-, News- und Feedback-Panel', ($form2['elements'][0]['name'] ?? '') === 'DocPanel' && !in_array('ForumHintPanel', array_map(fn($e) => $e['name'] ?? '', $form2['elements']), true));
// Neue Instanz übernimmt Stand vom Geschwister
$GLOBALS['attrs']['PurposeIntroGone']=false; $GLOBALS['attrs']['ForumHintGone']=false; $GLOBALS['attrs']['SeenNews']='';
$ref = new ReflectionMethod($m,'AdoptDismissFromSibling');  $ref->invoke($m);
t('Neue Instanz übernimmt Ausblende-Stand vom Geschwister', $GLOBALS['attrs']['PurposeIntroGone']===true && $GLOBALS['attrs']['ForumHintGone']===true && $GLOBALS['attrs']['SeenNews']==='1.13');
$st = $m->GetDismissState();
t('GetDismissState liefert Array', $st === ['purposeIntroGone'=>true,'forumHintGone'=>true,'seenNews'=>'1.13']);
// Speicherzelle: schreiben/lesen über das Modul
$ref = new ReflectionMethod($m,'applyValueToTarget'); 
$row = ['Address'=>5000,'VariableID'=>0,'Writable'=>2,'Factor'=>1.0,'Fixed'=>100.0];
$ref->invoke($m,$row,60.0);
$cur = new ReflectionMethod($m,'currentRegisterValue'); 
t('Speicherzelle: geschriebener Wert 60 wird zurückgeliefert', abs($cur->invoke($m,$row)-60.0)<1e-9);
// ApplyChanges: prune
$GLOBALS['attrs']['RegisterMemory'] = '{"5000":60,"9999":1}';
$m->ApplyChanges();
t('ApplyChanges räumt Speicher gelöschter Zeilen ab', json_decode($GLOBALS['attrs']['RegisterMemory'],true) === [5000=>60]);
// Anzeige der Spalte "Wert": locale-unabhängig, keine hängenden Kommas
$fmt = new ReflectionMethod($m, 'formatCurrentValue');
$before = setlocale(LC_ALL, '0');
foreach ([[100.0,'100'],[5.0,'5'],[0.0,'0'],[199155.0,'199155'],[40.5,'40,5'],[-3.25,'-3,25'],[0.00004,'0']] as [$in,$out]) {
    t('Wertanzeige ' . $in . ' -> "' . $out . '"', $fmt->invoke($m, $in) === $out);
}
if (setlocale(LC_ALL, 'de_DE.UTF-8', 'de_DE', 'German_Germany')) {
    t('Wertanzeige unter deutscher Locale unverändert "100"', $fmt->invoke($m, 100.0) === '100');
    setlocale(LC_ALL, $before);
}
// CreateRowVariables: Live-Fund Solarpark 22.09.2026 - beim Klick auf den Button lieferte
// Symcon nicht zuverlässig die echten Browser-Zeilen als Parameter, sondern u. a. nur die
// interne "Zeile hinzufügen"-Vorlage (ein einzelnes Objekt mit Adresse 0, leerem Namen);
// die alte Fassung legte dadurch eine Fantasie-Variable "Register 0" an und überschrieb die
// ANZEIGE im offenen Formular mit dieser einen Zeile - die echten Zeilen wirkten verschwunden
// (tatsächlich nur die Anzeige, die gespeicherte Konfiguration blieb unangetastet). Die
// Methode liest jetzt AUSSCHLIESSLICH die gespeicherte Konfiguration, der Parameter wird
// bewusst ignoriert - hier mit genau so einer Fantasie-Zeile als Parameter simuliert.
$GLOBALS['props']['Registers'] = json_encode([
    ['Name' => 'a', 'Area' => 0, 'Address' => 100, 'DataType' => 'uint16', 'VariableID' => 0, 'Factor' => 1, 'Fixed' => 0.0, 'Writable' => 0],
    ['Name' => 'b', 'Area' => 0, 'Address' => 200, 'DataType' => 'float32', 'VariableID' => 0, 'Factor' => 1, 'Fixed' => 5.0, 'Writable' => 0],
]);
$bogusBrowserRow = json_encode(['Name' => '', 'Area' => 0, 'Address' => 0, 'DataType' => 'uint16', 'VariableID' => 0, 'Factor' => 1, 'Fixed' => 0.0, 'Writable' => 0]);
$resultTwoRows = $m->CreateRowVariables($bogusBrowserRow);
t('CreateRowVariables: legt 1 an, überspringt 1 mit Festwert - unabhängig vom (ignorierten) Parameter', str_contains($resultTwoRows, '1 Datenpunkt(e) angelegt') && str_contains($resultTwoRows, '1 Zeile(n) mit Festwert'));
$lastUpdate = end($GLOBALS['updates']);
$refreshedRows = json_decode($lastUpdate[2], true);
t('Formular-Refresh zeigt die echten Zeilen, nicht die Fantasie-Zeile aus dem Parameter', $lastUpdate[0] === 'Registers' && $lastUpdate[1] === 'values' && count($refreshedRows) === 2 && $refreshedRows[0]['Address'] === 100);
t('Formular-Refresh reichert Wert/Empfangen/Abgefragt wieder an', array_key_exists('CurrentValue', $refreshedRows[0]));

// Gespeicherte Konfiguration mit genau einer Zeile - läuft weiterhin durch.
$GLOBALS['props']['Registers'] = json_encode([
    ['Name' => 'c', 'Area' => 0, 'Address' => 300, 'DataType' => 'uint16', 'VariableID' => 0, 'Factor' => 1, 'Fixed' => 0.0, 'Writable' => 0],
]);
t('CreateRowVariables: gespeicherte Konfiguration mit einer Zeile legt 1 an', str_contains($m->CreateRowVariables(''), '1 Datenpunkt(e) angelegt'));

// Absicherung: eine von Hand beschädigte Property (Objekt statt Array) stürzt nicht ab.
$GLOBALS['props']['Registers'] = json_encode(['Name' => 'd', 'Area' => 0, 'Address' => 400, 'DataType' => 'uint16', 'VariableID' => 0, 'Factor' => 1, 'Fixed' => 0.0, 'Writable' => 0]);
t('CreateRowVariables: beschädigte Property (Objekt statt Array) stürzt nicht ab', str_contains($m->CreateRowVariables(''), '1 Datenpunkt(e) angelegt'));

// ---------------------------------------------------------------------------------------------
// Zweisprachigkeit DE/EN (SUITE.md Konventionen Punkt 9)
// ---------------------------------------------------------------------------------------------
$deMap = json_decode(file_get_contents(__DIR__ . '/../ModbusTCPServer/locale.json'), true)['translations']['de'];
$moduleSrc = file_get_contents(__DIR__ . '/../ModbusTCPServer/module.php');
$formSrc = json_decode(file_get_contents(__DIR__ . '/../ModbusTCPServer/form.json'), true);

// Texte, die in beiden Sprachen bewusst gleich lauten (Eigennamen, Fachkürzel, Symbole)
$sameInBoth = ['Name', 'Holding', 'Input', 'Variable', 'Status', ' ', '–', 'uint16', 'int16', 'uint32', 'int32', 'float32', 'float64'];

// 1. form.json: jeder Anzeigetext ist ein englischer Quelltext mit deutscher Fassung in locale.json
$walk = function ($node) use (&$walk, &$formTexts) {
    foreach ($node as $k => $v) {
        if (is_string($k) && in_array($k, ['caption', 'confirm', 'label'], true) && is_string($v) && $v !== '') {
            $formTexts[] = $v;
        } elseif (is_array($v)) {
            $walk($v);
        }
    }
};
$formTexts = [];
$walk($formSrc);
$missing = array_values(array_filter(array_unique($formTexts), fn ($x) => !isset($deMap[$x]) && !in_array($x, $sameInBoth, true)));
t('form.json: jeder Anzeigetext steht in locale.json' . ($missing ? ' - fehlt: ' . implode(' | ', array_map(fn ($x) => mb_substr($x, 0, 40), $missing)) : ''), $missing === []);
$germanInForm = array_values(array_filter(array_unique($formTexts), fn ($x) => preg_match('/[äöüÄÖÜß]/u', $x) === 1));
t('form.json: kein deutscher Text mehr im Quelltext (keine Umlaute)' . ($germanInForm ? ' - ' . mb_substr($germanInForm[0], 0, 40) : ''), $germanInForm === []);

// 2. module.php: jedes $this->t('...')-Literal und jede dynamische Panel-Beschriftung steht in locale.json
preg_match_all('/\$this->t\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/u', $moduleSrc, $mt);
preg_match_all('/\'caption\'\s*=>\s*\'((?:[^\'\\\\]|\\\\.)*)\'/u', $moduleSrc, $mc);
$codeTexts = array_map(fn ($x) => str_replace(["\\'", '\\\\'], ["'", '\\'], $x), array_merge($mt[1], $mc[1]));
$missing = array_values(array_filter(array_unique($codeTexts), fn ($x) => $x !== '' && !isset($deMap[$x]) && !in_array($x, $sameInBoth, true)));
t('module.php: jeder übersetzbare Text steht in locale.json' . ($missing ? ' - fehlt: ' . implode(' | ', array_map(fn ($x) => mb_substr($x, 0, 50), $missing)) : ''), $missing === []);

// 3. kein deutscher Text mehr im Code (Zeichenketten-Literale, ohne Kommentare)
$germanInCode = [];
foreach (token_get_all($moduleSrc) as $tok) {
    if (is_array($tok) && in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && preg_match('/[äöüÄÖÜß]/u', $tok[1])) {
        $germanInCode[] = $tok[2] . ': ' . mb_substr($tok[1], 0, 50);
    }
}
t('module.php: kein deutscher Text mehr in Zeichenketten' . ($germanInCode ? ' - ' . $germanInCode[0] : ''), $germanInCode === []);

// 4. locale.json: Platzhalter je Eintrag in Englisch und Deutsch gleich, deutsche Fassung nicht leer
$ph = fn ($x) => preg_match_all('/%(?:\d+\$)?[-+ 0]?\d*(?:\.\d+)?[sdfF]/', str_replace('%%', '', $x));
$badPh = array_keys(array_filter($deMap, fn ($de, $en) => $ph($en) !== $ph($de) || trim($de) === '', ARRAY_FILTER_USE_BOTH));
t('locale.json: Platzhalter gleich und keine leeren Texte' . ($badPh ? ' - ' . mb_substr((string) $badPh[0], 0, 50) : ''), $badPh === []);
t('locale.json: kein "IP-Symcon" mehr in deutschen Texten', array_filter($deMap, fn ($de) => str_contains($de, 'IP-Symcon')) === []);

// 5. Formular in Englisch erzeugen: keine deutschen Reste, gültiges JSON, Texte werden unverändert (englisch) ausgeliefert
$memo = new ReflectionProperty(ModbusTCPServer::class, 'translations');
$memo->setValue(null, []);
$GLOBALS['locale'] = []; // Symcon-Sprache Englisch: Quelltext bleibt
$GLOBALS['props']['Registers'] = json_encode([['Name' => 'x', 'Area' => 0, 'Address' => 1, 'DataType' => 'uint16', 'VariableID' => 0, 'Factor' => 1, 'Fixed' => 0.0, 'Writable' => 1]]);
$GLOBALS['attrs']['PurposeIntroGone'] = false; $GLOBALS['attrs']['ForumHintGone'] = false; $GLOBALS['attrs']['SeenNews'] = '';
$en = json_decode($m->GetConfigurationForm(), true);
$enTexts = [];
$collect = function ($node) use (&$collect, &$enTexts) {
    foreach ($node as $k => $v) {
        if (is_string($k) && in_array($k, ['caption', 'confirm', 'label'], true) && is_string($v)) { $enTexts[] = $v; }
        elseif (is_array($v)) { $collect($v); }
    }
};
$collect($en);
$collect($en['actions'] ?? []);
t('Formular englisch: gültiges JSON mit Elementen', is_array($en) && !empty($en['elements']));
$germanEn = array_values(array_filter($enTexts, fn ($x) => preg_match('/[äöüÄÖÜß]|\b(Dokumentation|Registertabelle|Schreiben|Nein|Verbindung|Zeitüberwachung)\b/u', $x)));
t('Formular englisch: keine deutschen Reste' . ($germanEn ? ' - ' . mb_substr($germanEn[0], 0, 60) : ''), $germanEn === []);
t('Formular englisch: Kopf- und Panel-Texte englisch', str_contains(json_encode($en, JSON_UNESCAPED_UNICODE), 'What is this module for?') && str_contains(json_encode($en, JSON_UNESCAPED_UNICODE), 'About this module') && str_contains(json_encode($en, JSON_UNESCAPED_UNICODE), 'Register table'));
fwrite(STDERR, "   (englisches Formular: " . count(array_unique($enTexts)) . " verschiedene Texte)\n");

// 6. Dezimalzeichen sprachabhängig (Registerwert-Anzeige)
$fmt = new ReflectionMethod($m, 'formatCurrentValue');
t('Wertanzeige englisch: Punkt als Dezimalzeichen (40.5)', $fmt->invoke($m, 40.5) === '40.5');
$GLOBALS['locale'] = $deMap; $memo->setValue(null, []);
t('Wertanzeige deutsch: Komma als Dezimalzeichen (40,5)', $fmt->invoke($m, 40.5) === '40,5');

// 7. Stolperstein 24: Übersetzungen werden je Prozess gemerkt - der zweite Formularaufbau fragt Symcon nicht erneut
$m->GetConfigurationForm();
$before = $GLOBALS['translateCalls'];
$m->GetConfigurationForm();
$m->GetConfigurationForm();
t('Translate() wird je Text höchstens einmal je Prozess aufgerufen (zweiter/dritter Formularaufbau: 0 neue Aufrufe)', $GLOBALS['translateCalls'] === $before);

// 8. Meldungen zweisprachig: Vorlage laden in beiden Sprachen
$GLOBALS['locale'] = []; $memo->setValue(null, []);
t('Meldung englisch: SunSpec-Vorlage', str_contains($m->LoadTemplate('sunspec113'), 'SunSpec template loaded'));
$GLOBALS['locale'] = $deMap; $memo->setValue(null, []);
t('Meldung deutsch: SunSpec-Vorlage', str_contains($m->LoadTemplate('sunspec113'), 'SunSpec-Vorlage geladen'));

t('Keine PHP-Warnungen/Notices beim Formularaufbau und Rechnen' . ($GLOBALS['warnings'] ? ': ' . implode(' | ', array_unique($GLOBALS['warnings'])) : ''), $GLOBALS['warnings'] === []);
echo $fail===0 ? "\nAlle Formular-Tests bestanden.\n" : "\n$fail FEHLER\n"; exit($fail?1:0);
