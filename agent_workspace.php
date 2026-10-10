<?php
require_once __DIR__.'/includes/session_check.php';
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/eneron_core.php';
eneron_sync_session($pdo);
if (eneron_role()==='importer'){http_response_code(403);exit('Acces interzis.');}
[$scope,$scopeParams]=eneron_scoped_where();
$uid=(int)$_SESSION['user_id'];
$owner=in_array(eneron_role(),['owner','admin'],true);
$statuses=eneron_statuses();
$hasFollowup=eneron_column($pdo,'leads','data_contact_urmator');
if (isset($_GET['contact'])) {
    $id=(int)$_GET['contact'];
    $sql="SELECT l.id,l.nume,l.telefon,l.email,l.judet,l.proiect FROM leads l WHERE l.id=? AND $scope LIMIT 1";
    $q=$pdo->prepare($sql);$q->execute(array_merge([$id],$scopeParams));$l=$q->fetch(PDO::FETCH_ASSOC);
    if(!$l){http_response_code(404);exit('Contact indisponibil');}
    $clean=static function($value){return str_replace(["\\",";",",","\r","\n"],["\\\\","\\;","\\,",'','\\n'],(string)($value??''));};
    $phone=preg_replace('/[^0-9+]/','',(string)$l['telefon']);
    $vcf="BEGIN:VCARD\r\nVERSION:3.0\r\nFN:".$clean($l['nume'])."\r\n";
    if($phone!=='')$vcf.='TEL;TYPE=CELL:'.$phone."\r\n";
    if(!empty($l['email']))$vcf.='EMAIL:'.$clean($l['email'])."\r\n";
    $vcf.='ORG:Eneron' . "\r\nNOTE:".$clean(trim((string)($l['proiect']??'').' | '.(string)($l['judet']??'')))."\r\nEND:VCARD\r\n";
    header('Content-Type: text/vcard; charset=utf-8');header('Content-Disposition: attachment; filename="eneron-contact-'.$id.'.vcf"');
    header('X-Content-Type-Options: nosniff');echo $vcf;exit;
}
$msg='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_lead'])){
    eneron_csrf_check();
    $id=(int)($_POST['lead_id']??0);$status=(string)($_POST['status']??'');
    $note=eneron_limit(trim((string)($_POST['nota_agent']??'')),0,5000);
    $next=trim((string)($_POST['data_contact_urmator']??''));
    if($hasFollowup && $next!=='' && !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/',$next)) {http_response_code(422);exit('Data următoarei contactări este invalidă.');}
    $nextVal=$next===''?null:str_replace('T',' ',$next).':00';
    if(!in_array($status,$statuses,true)){$error='Status invalid.';}else{
        $sql="UPDATE leads l SET l.status=?,l.nota_agent=?".($hasFollowup?",l.data_contact_urmator=?":"")." WHERE l.id=? AND $scope";
        try{$q=$pdo->prepare($sql);$values=[$status,$note];if($hasFollowup)$values[]=$nextVal;$values[]=$id;$q->execute(array_merge($values,$scopeParams));
        if(eneron_table($pdo,'lead_activities')){
            $check=$pdo->prepare("SELECT l.id FROM leads l WHERE l.id=? AND $scope LIMIT 1");$check->execute(array_merge([$id],$scopeParams));
            if($check->fetchColumn()) $pdo->prepare('INSERT INTO lead_activities(lead_id,user_id,tip,descriere) VALUES (?,?,?,?)')->execute([$id,$uid,'status','Actualizat status: '.$status]);
        }
        header('Location: agent_workspace.php?updated=1');exit;
        }catch(Throwable $e){$error='Nu s-a putut actualiza lead-ul.';}
    }
}
$search=trim((string)($_GET['q']??''));$filter=(string)($_GET['status']??'');
$where="WHERE $scope AND l.agent_id IS NOT NULL";
$params=$scopeParams;
if(!$owner && eneron_role()==='team'){$where='WHERE l.agent_id=?';$params=[$uid];}
if($search!==''){$where.=' AND (l.nume LIKE ? OR l.telefon LIKE ? OR l.judet LIKE ?)';$like='%'.str_replace(['%','_'],['\\%','\\_'],$search).'%';array_push($params,$like,$like,$like);}
if($filter!=='' && in_array($filter,$statuses,true)){$where.=' AND l.status=?';$params[]=$filter;}
$sql="SELECT l.id,l.nume,l.telefon,l.email,l.judet,l.oras_localitate,l.proiect,l.status,l.nota_agent,l.sursa,l.created_at,".($hasFollowup?'l.data_contact_urmator':'NULL AS data_contact_urmator').",u.nume agent_nume FROM leads l LEFT JOIN users u ON u.id=l.agent_id $where ORDER BY CASE WHEN l.status='URGENT' THEN 0 WHEN l.status='NEW LEAD' THEN 1 WHEN l.status='FOLLOW UP' THEN 2 ELSE 3 END, l.id DESC LIMIT 90";
$q=$pdo->prepare($sql);$q->execute($params);$leads=$q->fetchAll(PDO::FETCH_ASSOC);
include __DIR__ . '/meniu.php';
?>
<section class="en-page-heading en-agent-head">
 <div class="en-agent-title"><div class="en-eyebrow">Eneron / Activitate comercială</div><h1>Spațiul meu de lucru</h1><p>Contactează clienții, notează discuțiile și planifică următoarea acțiune. Toate modificările rămân asociate lead-ului.</p></div>
 <div class="en-agent-tools"><a href="master_leads.php" class="en-action en-action-outline">Fișier Master</a><?php if(eneron_is_management()): ?><a href="agents.php" class="en-action en-action-outline">Administrare agenți</a><?php endif; ?></div>
</section>
<?php if(isset($_GET['updated'])):?><div class="en-alert success">Lead-ul a fost actualizat.</div><?php endif;?>
<?php if($error):?><div class="en-alert danger"><?=eneron_h($error)?></div><?php endif;?>
<form method="get" class="glass-card en-agent-filter">
 <label>Caută client, telefon sau județ<input name="q" value="<?=eneron_h($search)?>" placeholder="Caută în lead-urile alocate..."></label>
 <label>Status<select name="status"><option value="">Toate statusurile</option><?php foreach($statuses as $s):?><option value="<?=eneron_h($s)?>" <?=$filter===$s?'selected':''?>><?=eneron_h($s)?></option><?php endforeach;?></select></label>
 <button class="en-action" type="submit">Aplică filtrele</button><span class="en-agent-result-count"><?=count($leads)?> rezultate (maximum 90)</span>
</form>
<div class="en-lead-cards en-agent-grid">
<?php foreach($leads as $l):
 $phone=preg_replace('/[^0-9+]/','',(string)$l['telefon']);
 $wa=ltrim($phone,'+');if(str_starts_with($wa,'0'))$wa='40'.substr($wa,1);
 $source=trim((string)($l['sursa']??''));$judet=trim((string)($l['judet']??''));$project=trim((string)($l['proiect']??''));
?>
<article class="en-lead-card en-agent-card">
 <header class="en-agent-card-header"><div><span class="en-agent-status"><?=eneron_h($l['status']?:'Fără status')?></span><h3><?=eneron_h($l['nume']?:'Client fără nume')?></h3><div class="en-agent-facts"><?php foreach(array_filter([$source,$judet,$project],static fn($x)=>$x!=='') as $fact): ?><span><?=eneron_h($fact)?></span><?php endforeach; ?></div></div><span class="en-agent-id">#<?=(int)$l['id']?> · <?=eneron_h(date('d.m.Y',strtotime($l['created_at'])))?></span></header>
 <div class="en-agent-actions">
 <?php if($phone):?><a class="en-action en-action-outline" href="tel:<?=eneron_h($phone)?>">Sună</a><a class="en-action en-action-outline" href="https://wa.me/<?=eneron_h(preg_replace('/\D/','',$wa))?>" target="_blank" rel="noopener noreferrer">WhatsApp</a><?php endif;?>
 <a class="en-action en-action-outline" href="?contact=<?=(int)$l['id']?>">Salvează în agendă (.vcf)</a>
 </div>
 <form method="post" class="en-agent-form">
 <input type="hidden" name="csrf_token" value="<?=eneron_h(eneron_csrf())?>"><input type="hidden" name="update_lead" value="1"><input type="hidden" name="lead_id" value="<?=(int)$l['id']?>">
 <label>Status<select name="status"><?php foreach($statuses as $s):?><option value="<?=eneron_h($s)?>" <?=$l['status']===$s?'selected':''?>><?=eneron_h($s)?></option><?php endforeach;?></select></label>
 <label>Notă agent<textarea name="nota_agent" rows="3" maxlength="5000" placeholder="Ultima discuție, obiecții, pașii următori..."><?=eneron_h($l['nota_agent'])?></textarea></label>
 <?php if($hasFollowup):?><label>Următoarea contactare<input type="datetime-local" name="data_contact_urmator" value="<?=eneron_h(!empty($l['data_contact_urmator'])?date('Y-m-d\TH:i',strtotime($l['data_contact_urmator'])):'')?>"></label><?php endif;?>
 <div class="en-agent-form-bottom"><button class="en-action" type="submit">Salvează modificările</button><span class="en-agent-owner"><?=eneron_h($l['agent_nume']??'')?></span></div>
 </form>
</article>
<?php endforeach;?>
<?php if(!$leads):?><div class="glass-card en-empty">Nu există lead-uri pentru filtrele selectate.</div><?php endif;?>
</div>
<?php include __DIR__ . '/footer.php'; ?>
