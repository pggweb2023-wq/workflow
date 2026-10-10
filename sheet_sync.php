<?php
/** Configurare sincronizare Google Sheets / Meta. Owner-only. */
require_once __DIR__.'/includes/session_check.php';
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/eneron_core.php';
require_once __DIR__.'/includes/eneron_sheet_sync.php';
eneron_sync_session($pdo);
if(!in_array(eneron_role(),['owner','admin'],true)){http_response_code(403);exit('Acces permis numai administratorului.');}
if(!en_sheet_table_exists($pdo)){http_response_code(503);exit('Lipsește schema de sincronizare. Rulează MIGRARE_SINCRONIZARE_SHEETS.sql în phpMyAdmin.');}
$error='';$success='';$preview=null;$sheets=[];$cfg=null;$sourceList=[];$map=[];
$defaults=['source_name'=>'Meta Ads – LEADS CENTRAL','spreadsheet_id'=>'','sheet_name'=>'LEADS CENTRAL','auth_mode'=>'service_account','header_row'=>1,'initial_mode'=>1,'auto_assign'=>0,'max_per_run'=>200,'enabled'=>1];
$sourceList=$pdo->query('SELECT * FROM eneron_sheet_sources ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$byId=[];foreach($sourceList as $source)$byId[(int)$source['id']]=$source;
$id=(int)($_POST['id']??$_GET['edit']??0);
$cfg=$byId[$id]??$defaults;
$map=json_decode((string)($cfg['mapping_json']??'{}'),true)?:[];
if($_SERVER['REQUEST_METHOD']==='POST'){
 eneron_csrf_check();
 $action=(string)($_POST['action']??'');
 try{
  if($action==='toggle'){
   if(!$id||!isset($byId[$id]))throw new RuntimeException('Sursa nu există.');
   $pdo->prepare('UPDATE eneron_sheet_sources SET enabled=1-enabled WHERE id=?')->execute([$id]);
   header('Location: sheet_sync.php?msg=Stare actualizată');exit;
  }
  if($action==='sync_now'){
   if(!$id||!isset($byId[$id]))throw new RuntimeException('Sursa nu există.');
   $r=en_sheet_run($pdo,$byId[$id]);
   $success='Sincronizare încheiată: '.$r['new'].' lead-uri importate, '.$r['duplicates'].' duplicate, '.$r['baseline'].' rânduri istorice marcate, '.$r['invalid'].' invalide, '.$r['errors'].' erori.';
   if($r['notes'])$error=implode(' ',array_unique($r['notes']));
  }
  if(in_array($action,['inspect','save'],true)){
   $cfg=['source_name'=>mb_substr_fallback(trim((string)($_POST['source_name']??'')),100),
    'spreadsheet_id'=>en_sheet_id((string)($_POST['spreadsheet_id']??'')),
    'sheet_name'=>trim((string)($_POST['sheet_name']??'')),
    'auth_mode'=>(string)($_POST['auth_mode']??'service_account'),
    'header_row'=>max(1,min(50,(int)($_POST['header_row']??1))),
    'initial_mode'=>isset($_POST['initial_mode'])?1:0,'auto_assign'=>isset($_POST['auto_assign'])?1:0,
    'max_per_run'=>max(10,min(500,(int)($_POST['max_per_run']??200))),
    'enabled'=>isset($_POST['enabled'])?1:0];
   if($cfg['source_name']===''||$cfg['spreadsheet_id']===''||$cfg['sheet_name']==='')throw new RuntimeException('Completează numele sursei, linkul și foaia.');
   if(!in_array($cfg['auth_mode'],['public','service_account'],true))throw new RuntimeException('Acces Google invalid.');
   $preview=en_sheet_fetch($cfg);$headers=$preview['headers'];
   $sheets=($cfg['auth_mode']==='service_account')?en_sheet_list($cfg['spreadsheet_id'],$cfg['auth_mode']):[];
   if($sheets&&!in_array($cfg['sheet_name'],$sheets,true))throw new RuntimeException('Această foaie nu există în document. Selectează un nume din listă.');
   $newMap=en_sheet_defaults($headers);
   if(isset($_POST['mapping'])&&is_array($_POST['mapping'])){
    $newMap=[];foreach(en_sheet_fields() as $field=>$_label){
     $value=(string)($_POST['mapping'][$field]??'');
     if($value!==''&&in_array($value,$headers,true))$newMap[$field]=$value;
    }
   }elseif($id&&isset($byId[$id]))$newMap=json_decode((string)$byId[$id]['mapping_json'],true)?:$newMap;
   $map=$newMap;
   if($action==='save'){
    if(!en_sheet_can_import($map,$headers))throw new RuntimeException('Trebuie asociate cel puțin coloanele Nume și Telefon.');
    foreach($map as $field=>$value)if(!isset(en_sheet_fields()[$field])||!in_array($value,$headers,true))throw new RuntimeException('Mapare invalidă.');
    $mappingJson=json_encode($map,JSON_UNESCAPED_UNICODE);
    if($id){
     // Changes to source identity or key fields require a separate profile, avoiding silent skipped rows.
     $old=$byId[$id]??null;
     if(!$old)throw new RuntimeException('Profilul nu există.');
     if($old['spreadsheet_id']!==$cfg['spreadsheet_id']||$old['sheet_name']!==$cfg['sheet_name']) throw new RuntimeException('Pentru alt document sau altă foaie creează un profil nou. Poți păstra mai multe foi active.');
     $sql='UPDATE eneron_sheet_sources SET source_name=?,auth_mode=?,header_row=?,mapping_json=?,auto_assign=?,max_per_run=?,enabled=? WHERE id=?';
     $pdo->prepare($sql)->execute([$cfg['source_name'],$cfg['auth_mode'],$cfg['header_row'],$mappingJson,$cfg['auto_assign'],$cfg['max_per_run'],$cfg['enabled'],$id]);
    }else{
     $sql='INSERT INTO eneron_sheet_sources(source_name,spreadsheet_id,sheet_name,auth_mode,header_row,mapping_json,initial_mode,auto_assign,max_per_run,enabled) VALUES (?,?,?,?,?,?,?,?,?,?)';
     $pdo->prepare($sql)->execute([$cfg['source_name'],$cfg['spreadsheet_id'],$cfg['sheet_name'],$cfg['auth_mode'],$cfg['header_row'],$mappingJson,$cfg['initial_mode'],$cfg['auto_assign'],$cfg['max_per_run'],$cfg['enabled']]);
     $id=(int)$pdo->lastInsertId();
    }
    header('Location: sheet_sync.php?edit='.$id.'&msg=Maparea a fost salvată');exit;
   }
   $success='Foaia a fost citită. Verifică maparea coloanelor și salvează.';
  }
 }catch(Throwable $e){$error=$e->getMessage();}
}
if($_SERVER['REQUEST_METHOD']!=='POST' && $id && isset($byId[$id])){
 $cfg=$byId[$id];$map=json_decode((string)$cfg['mapping_json'],true)?:[];
 try{$preview=en_sheet_fetch($cfg);if($cfg['auth_mode']==='service_account')$sheets=en_sheet_list($cfg['spreadsheet_id'],$cfg['auth_mode']);}catch(Throwable $e){$error=$e->getMessage();}
}
if(isset($_GET['msg']))$success=mb_substr_fallback((string)$_GET['msg'],200);
$runs=$pdo->query('SELECT r.*,s.source_name FROM eneron_sheet_runs r JOIN eneron_sheet_sources s ON s.id=r.source_id ORDER BY r.id DESC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC);
include __DIR__.'/meniu.php';
?>
<style>
.ens-page{max-width:1480px;margin:auto;color:#f1f5ef}.ens-hero{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;flex-wrap:wrap;margin:0 0 22px}.ens-hero h1{margin:8px 0;font-size:clamp(26px,3vw,38px);letter-spacing:-1px}.ens-hero p{color:#a4aca1;max-width:750px}.ens-tag{font-size:11px;letter-spacing:1.5px;color:#93c751;font-weight:800;text-transform:uppercase}.ens-grid{display:grid;grid-template-columns:minmax(270px,.9fr) minmax(0,1.6fr);gap:18px}.ens-card{border:1px solid #30372f;background:#141914;border-radius:18px;padding:22px;box-shadow:0 16px 30px #0005;min-width:0}.ens-card h2{font-size:19px;margin:0 0 15px}.ens-source{border:1px solid #30372f;background:#0e120e;padding:16px;border-radius:12px;margin-bottom:12px;display:grid;gap:7px}.ens-source strong{font-size:15px}.ens-sub{color:#a3aca0;font-size:12px}.ens-controls{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.ens-btn{border:1px solid #3c4539;border-radius:11px;background:#242c22;color:#e9f2e6;font-weight:750;padding:11px 15px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;text-decoration:none}.ens-btn.primary{border-color:#84bb36;background:#84bb36;color:#10170c}.ens-btn:hover{filter:brightness(1.14)}.ens-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.ens-label{display:grid;gap:7px;font-size:12px;color:#c1cebc;font-weight:700}.ens-label input,.ens-label select{width:100%;min-height:44px;border-radius:11px;background:#090d09;color:#eff6eb;border:1px solid #344033;padding:10px;font:inherit}.ens-label input:focus,.ens-label select:focus{outline:2px solid #88ba3e}.ens-check{display:flex;align-items:center;gap:10px;color:#d2dccf;font-size:13px}.ens-check input{width:17px;height:17px;accent-color:#8bbf40}.ens-map{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.ens-notice{background:#142112;color:#d7efd0;border:1px solid #3b6231;padding:13px 16px;border-radius:12px;margin-bottom:14px}.ens-error{background:#271815;color:#ffcebe;border:1px solid #814536;padding:13px 16px;border-radius:12px;margin-bottom:14px}.ens-table-wrap{overflow-x:auto}.ens-table{width:100%;border-collapse:collapse;font-size:12px}.ens-table th,.ens-table td{border-bottom:1px solid #30372f;padding:12px 9px;text-align:left}.ens-table th{color:#9ab48c}.ens-table td{color:#dce7d6}.ens-muted{color:#9aa999;font-size:12px;line-height:1.55}.ens-note{padding:15px;background:#0d160d;border:1px solid #273527;border-radius:12px;margin:18px 0}.ens-long{overflow-wrap:anywhere}.ens-stack{display:grid;gap:18px}@media(max-width:1000px){.ens-grid{grid-template-columns:1fr}}@media(max-width:650px){.ens-card{padding:16px}.ens-fields,.ens-map{grid-template-columns:1fr}.ens-controls>*{flex:1}.ens-hero{align-items:start}}
</style>
<main class="ens-page">
<div class="ens-hero"><div><span class="ens-tag">Surse lead / Google Sheets</span><h1>Sincronizare lead-uri Meta</h1><p>Alegi documentul, foaia de lucru și coloanele. Importul poate rula automat prin Cron Jobs, fără să fie deschis CRM-ul.</p></div><a class="ens-btn" href="master_leads.php">Înapoi la Fișier Master</a></div>
<?php if($success):?><div class="ens-notice"><?=en_sheet_escape($success)?></div><?php endif;?>
<?php if($error):?><div class="ens-error"><?=en_sheet_escape($error)?></div><?php endif;?>
<div class="ens-grid"><div class="ens-stack"><section class="ens-card"><h2>Foi configurate</h2>
<?php if(!$sourceList):?><p class="ens-muted">Nu există încă surse. Începe cu „LEADS CENTRAL” din documentul Eneron.</p><?php endif;?>
<?php foreach($sourceList as $s):?><article class="ens-source"><strong><?=en_sheet_escape($s['source_name'])?></strong><span class="ens-sub"><?=en_sheet_escape($s['sheet_name'])?> · <?= $s['enabled']?'Activă':'Oprită' ?> · <?= $s['first_run_done']?'Inițializată':'Prima rulare neefectuată' ?></span>
<?php if($s['last_run_at']):?><span class="ens-sub">Ultima verificare: <?=en_sheet_escape($s['last_run_at'])?> · importate <?= (int)$s['last_imported_count'] ?></span><?php endif;?>
<?php if($s['last_error']):?><span class="ens-sub" style="color:#e7a79b"><?=en_sheet_escape($s['last_error'])?></span><?php endif;?>
<div class="ens-controls"><a class="ens-btn" href="?edit=<?=(int)$s['id']?>">Mapare</a><form method="post"><input type="hidden" name="csrf_token" value="<?=en_sheet_escape(eneron_csrf())?>"><input type="hidden" name="id" value="<?=(int)$s['id']?>"><button class="ens-btn" name="action" value="sync_now" type="submit">Verifică acum</button></form><form method="post"><input type="hidden" name="csrf_token" value="<?=en_sheet_escape(eneron_csrf())?>"><input type="hidden" name="id" value="<?=(int)$s['id']?>"><button class="ens-btn" name="action" value="toggle" type="submit"><?=$s['enabled']?'Pauză':'Activează'?></button></form></div></article><?php endforeach;?>
<a class="ens-btn primary" href="sheet_sync.php">Adaugă o foaie nouă</a></section>
<section class="ens-card"><h2>Acces sigur la Google</h2><p class="ens-muted">Recomandat: cont de serviciu Google, cu acces doar de citire. Încarcă fișierul JSON în afara domeniului public, la calea indicată mai jos, și partajează documentul cu adresa <code>client_email</code> din JSON.</p><p class="ens-note ens-long"><code><?=en_sheet_escape(dirname(__DIR__).'/.eneron-google-service-account.json')?></code></p><p class="ens-muted">Modul „CSV public” funcționează numai pentru documente accesibile fără autentificare și expune lead-urile persoanelor care dețin linkul. Nu îl recomand pentru date personale.</p></section></div>
<div class="ens-stack"><section class="ens-card"><h2><?=$id?'Editează maparea sursei':'Conectează o nouă foaie'?></h2>
<form method="post" class="ens-stack">
<input type="hidden" name="csrf_token" value="<?=en_sheet_escape(eneron_csrf())?>"><input type="hidden" name="id" value="<?=$id?>">
<div class="ens-fields"><label class="ens-label">Denumire profil<input name="source_name" maxlength="100" required value="<?=en_sheet_escape($cfg['source_name'])?>"></label><label class="ens-label">Link Google Sheets / ID document<input name="spreadsheet_id" required value="<?=en_sheet_escape($cfg['spreadsheet_id'])?>" placeholder="https://docs.google.com/spreadsheets/d/.../edit" <?=$id?'readonly':''?>></label>
<label class="ens-label">Foaia de lucru<input name="sheet_name" list="ens-sheet-list" required value="<?=en_sheet_escape($cfg['sheet_name'])?>" <?=$id?'readonly':''?>><datalist id="ens-sheet-list"><?php foreach(array_unique(array_merge($sheets,['LEADS CENTRAL','Eneron #2 V2','BRUTE','CAMPANIE BF FILTR','Eneron #2','Eneron #2 V2-Industrial','Eneron #22 V2-Industrial - Reco','Eneron #22 V2 - Recovered'])) as $n):?><option value="<?=en_sheet_escape($n)?>"></option><?php endforeach;?></datalist></label>
<label class="ens-label">Acces la Google<select name="auth_mode"><option value="service_account" <?=$cfg['auth_mode']==='service_account'?'selected':''?>>Privat / Service account (recomandat)</option><option value="public" <?=$cfg['auth_mode']==='public'?'selected':''?>>CSV public (document partajat public)</option></select></label>
<label class="ens-label">Rândul cu antete<input type="number" min="1" max="50" name="header_row" value="<?=(int)$cfg['header_row']?>"></label><label class="ens-label">Limită lead-uri noi / rulare<input type="number" min="10" max="500" name="max_per_run" value="<?=(int)$cfg['max_per_run']?>"></label></div>
<div class="ens-stack">
<?php if(!$id):?><label class="ens-check"><input type="checkbox" name="initial_mode" value="1" <?=($cfg['initial_mode']??1)?'checked':''?>>La prima rulare marchează istoricul ca deja văzut (nu importa mii de lead-uri vechi)</label><?php else:?><span class="ens-muted">Modul primei rulări nu se poate schimba după crearea profilului. Pentru import istoric, creează profil separat și decide explicit.</span><?php endif;?>
<label class="ens-check"><input type="checkbox" name="auto_assign" value="1" <?=($cfg['auto_assign']??0)?'checked':''?>>Distribuie automat lead-urile noi după criteriile Eneron</label>
<label class="ens-check"><input type="checkbox" name="enabled" value="1" <?=($cfg['enabled']??1)?'checked':''?>>Sincronizare periodică activă</label></div>
<div class="ens-controls"><button class="ens-btn" name="action" value="inspect" type="submit">1. Verifică foaia și coloanele</button></div>
<?php if($preview):?><div class="ens-note"><strong>Antete detectate: <?=count($preview['headers'])?> · rânduri: <?=$preview['total']?></strong><p class="ens-muted">Folosește selectoarele de mai jos pentru maparea fiecărui câmp. Când Meta schimbă formularul, revii aici și le reajustezi.</p></div>
<div class="ens-map">
<?php foreach(en_sheet_fields() as $field=>$label):?><label class="ens-label"><?=en_sheet_escape($label)?><select name="mapping[<?=en_sheet_escape($field)?>]"><option value="">Nu importa acest câmp</option><?php foreach($preview['headers'] as $index=>$header):if($header==='')continue;?><option value="<?=en_sheet_escape($header)?>" <?=(isset($map[$field])&&(string)$map[$field]===(string)$header)?'selected':''?>><?=en_sheet_escape(mb_substr_fallback($header,100))?></option><?php endforeach;?></select></label><?php endforeach;?></div>
<div class="ens-controls"><button class="ens-btn primary" name="action" value="save" type="submit">2. Salvează maparea și profilul</button></div><?php else:?><p class="ens-muted">Mai întâi apasă „Verifică foaia” pentru a încărca lista reală a coloanelor.</p><?php endif;?>
</form></section></div></div>
<section class="ens-card" style="margin-top:18px"><h2>Ultimele verificări</h2><div class="ens-table-wrap"><table class="ens-table"><thead><tr><th>Data</th><th>Foaie</th><th>Citite</th><th>Importate</th><th>Duplicate</th><th>Istoric marcat</th><th>Invalide</th><th>Erori</th></tr></thead><tbody><?php foreach($runs as $r):?><tr><td><?=en_sheet_escape($r['created_at'])?></td><td><?=en_sheet_escape($r['source_name'])?></td><td><?=$r['read_count']?></td><td><?=$r['imported_count']?></td><td><?=$r['duplicate_count']?></td><td><?=$r['baseline_count']?></td><td><?=$r['invalid_count']?></td><td><?=$r['error_count']?></td></tr><?php endforeach;?><?php if(!$runs):?><tr><td colspan="8">Încă nu a rulat nicio sincronizare.</td></tr><?php endif;?></tbody></table></div></section>
</main>
<?php include __DIR__.'/footer.php'; ?>
