<?php

declare(strict_types=1);

/**
 * Modul-Test mit Stub-Umgebung (ohne IPS): php tests/module_test.php
 *
 * Ersetzt IPSModule und die benötigten IPS_*-Funktionen durch kleine Attrappen
 * und lässt das echte module.php laufen: Formularaufbau (Reihenfolge der Panels
 * nach Verbund-Konvention, Doku-Version, Lizenz-Panel), geteiltes Ausblenden der
 * Hinweise über Geschwister-Instanzen sowie die Speicherzellen (Schreiben, Lesen,
 * Aufräumen in ApplyChanges). Prüft NICHT das Verhalten in echtem Symcon.
 */
define('IS_ACTIVE',102); define('IS_INACTIVE',104);
define('VARIABLETYPE_BOOLEAN',0); define('VARIABLETYPE_INTEGER',1); define('VARIABLETYPE_FLOAT',2); define('VARIABLETYPE_STRING',3);
$GLOBALS['props'] = ['RPCEnabled'=>false,'Registers'=>json_encode([
  ['Name'=>'a','Area'=>0,'Address'=>5000,'DataType'=>'float32','VariableID'=>0,'Factor'=>1,'Fixed'=>100,'Writable'=>2],
  ['Name'=>'b','Area'=>0,'Address'=>100,'DataType'=>'int32','VariableID'=>0,'Factor'=>1,'Fixed'=>0,'Writable'=>0]]),
  'RegisterTimeouts'=>'[]','UnitID'=>1,'CheckUnitID'=>true,'SwapWords'=>false,'UnmappedRead'=>0,'CommTimeout'=>0];
$GLOBALS['attrs'] = ['PurposeIntroGone'=>false,'SeenNews'=>'','RegisterActivity'=>'{}','TimeoutApplied'=>'{}','RegisterMemory'=>'{"5000":42.5}','ScratchValues'=>'{}'];
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
  public function SendDebug($a,$b,$c){} public function GetValue($i){ return 0; } public function SetValue($i,$v){}
  public function GetIDForIdent($i){ return 0; } public function RegisterVariableInteger($i,$n,$p='',$pos=0){ return 1; } public function RegisterVariableFloat($i,$n,$p='',$pos=0){ return 1; } public function RegisterVariableBoolean($i,$n,$p='',$pos=0){ return 1; } public function MaintainVariable(){} public function SetStatus($s){} public function GetStatus(){ return 102; }
}
function IPS_GetInstance($id){ return ['ConnectionID'=>0,'InstanceStatus'=>102,'ModuleInfo'=>['LibraryID'=>'{LIB}']]; }
function IPS_GetLibrary($id){ return ['Version'=>'1.11.0']; }
function IPS_GetInstanceListByModuleID($g){ return [12345, 22222]; }
function IPS_VariableExists($i){ return false; } function IPS_GetName($i){ return 'Test'; } function IPS_GetObject($i){ return ['ParentID'=>0]; }
function IPS_GetProperty($i,$n){ return 0; } function IPS_VariableProfileExists($n){ return true; }
$sibState = ['purposeIntroGone'=>true,'seenNews'=>'1.11']; $GLOBALS['adopted']=[];
function MBSLV_AdoptDismissState($id,$w,$v){ $GLOBALS['adopted'][]=[$id,$w,$v]; }
function MBSLV_GetDismissState($id){ global $sibState; return $sibState; }
require __DIR__ . '/../ModbusTCPServer/module.php';
$m = new ModbusTCPServer();
$fail = 0; function t($n,$ok){ global $fail; echo ($ok?'OK   ':'FAIL ').$n."\n"; if(!$ok)$fail++; }
$m->Create();
$form = json_decode($m->GetConfigurationForm(), true);
t('Formular ist gültiges JSON', is_array($form) && isset($form['elements']));
$caps = array_map(fn($e)=>($e['name'] ?? '') ?: ($e['caption'] ?? $e['type']), $form['elements']);
echo '   Reihenfolge: ' . implode(' | ', array_map(fn($c)=>mb_substr($c,0,28), $caps)) . "\n";
t('1. Panel = Zweck', ($form['elements'][0]['name'] ?? '') === 'PurposeIntroPanel');
t('2. Panel = Neu in Version', ($form['elements'][1]['name'] ?? '') === 'NewsPanel' && str_contains($form['elements'][1]['caption'], '1.11'));
t('3. Panel = Dokumentation & Hilfe, eingeklappt', ($form['elements'][2]['name'] ?? '') === 'DocPanel' && $form['elements'][2]['expanded'] === false);
t('Doku-Panel nennt Version', str_contains($form['elements'][2]['items'][0]['caption'], '1.11.0'));
$last = end($form['elements']);
t('Letztes Panel = Über dieses Modul, eingeklappt, ohne name', str_contains($last['caption'], 'Über dieses Modul') && $last['expanded'] === false && !isset($last['name']));
t('Lizenz-Link-Buttons: onClick echo + link=true', $last['items'][2]['link'] === true && str_contains($last['items'][2]['onClick'], 'echo') && str_contains($last['items'][2]['onClick'], 'NRGModbusServer'));
t('Statuszeile PortInfo weiterhin vorhanden', in_array('PortInfo', $caps, true));
$reg = null; foreach ($form['elements'] as $e) if (($e['name'] ?? '')==='Registers') $reg=$e;
t('Speicherzelle zeigt gemerkten Wert 42.5 in Spalte Wert', str_contains($reg['values'][0]['CurrentValue'], '42'));
t('Zeile ohne Speicher zeigt Festwert-Anzeige', isset($reg['values'][1]['CurrentValue']));
// Ausblenden teilen
$m->AckPurposeIntro();
t('AckPurposeIntro setzt Attribut', $GLOBALS['attrs']['PurposeIntroGone'] === true);
t('AckPurposeIntro reicht an Geschwister weiter (nicht an sich selbst)', $GLOBALS['adopted'] === [[22222,'PurposeIntro','']]);
$m->AckNews();
t('AckNews speichert Version', $GLOBALS['attrs']['SeenNews'] === '1.11');
$form2 = json_decode($m->GetConfigurationForm(), true);
t('Nach Bestätigen fehlen Zweck- und News-Panel', ($form2['elements'][0]['name'] ?? '') === 'DocPanel');
// Neue Instanz übernimmt Stand vom Geschwister
$GLOBALS['attrs']['PurposeIntroGone']=false; $GLOBALS['attrs']['SeenNews']='';
$ref = new ReflectionMethod($m,'AdoptDismissFromSibling');  $ref->invoke($m);
t('Neue Instanz übernimmt Ausblende-Stand vom Geschwister', $GLOBALS['attrs']['PurposeIntroGone']===true && $GLOBALS['attrs']['SeenNews']==='1.11');
$st = $m->GetDismissState();
t('GetDismissState liefert Array', $st === ['purposeIntroGone'=>true,'seenNews'=>'1.11']);
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
echo $fail===0 ? "\nAlle Formular-Tests bestanden.\n" : "\n$fail FEHLER\n"; exit($fail?1:0);
