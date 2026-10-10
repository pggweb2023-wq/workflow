<?php
require_once __DIR__.'/includes/session_check.php';
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/eneron_core.php';
eneron_sync_session($pdo);
if (eneron_role() === 'importer') { header('Location: master_leads.php?tab=partners'); exit; }
$uid=(int)($_SESSION['user_id']??0);
$owner=in_array(eneron_role(),['owner','admin'],true);
$manager=eneron_is_management();
$canTarget=eneron_column($pdo,'users','target_lunar');
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_target'])) {
    eneron_csrf_check();
    if (!$owner || !$canTarget) {http_response_code(403);exit('Acces interzis');}
    $target=filter_var($_POST['target']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
    if ($target===false) {http_response_code(422);exit('Target invalid');}
    $pdo->prepare('UPDATE users SET target_lunar=? WHERE id=?')->execute([$target,$uid]);exit('ok');
}
$range=(int)($_GET['range']??0);
if (!in_array($range,[0,7,30,90],true)) $range=30;
[$scope,$scopeParams]=eneron_scoped_where();
$where="WHERE $scope".($range>0?" AND l.created_at >= DATE_SUB(NOW(), INTERVAL $range DAY)":"");
$getOne=static function(string $sql,array $params=[])use($pdo){$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();};
$getRows=static function(string $sql,array $params=[])use($pdo){$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);};
$allWhere="WHERE $scope";
$tot=(int)$getOne("SELECT COUNT(*) FROM leads l $where",$scopeParams);
$allTotal=(int)$getOne("SELECT COUNT(*) FROM leads l $allWhere",$scopeParams);
$newLeads=(int)$getOne("SELECT COUNT(*) FROM leads l $where AND (l.status='NEW LEAD' OR l.status IS NULL OR l.status='')",$scopeParams);
$won=(int)$getOne("SELECT COUNT(*) FROM leads l $where AND l.status IN ('CONTRACT','DE INSTALAT')",$scopeParams);
$active=(int)$getOne("SELECT COUNT(*) FROM leads l $where AND l.status IN ('LEAD','URGENT','LUCRU','OFERTAT','FOLLOW UP','SE GANDESTE','INTERESAT','NEGOCIERE','PIPELINE','VREA SA FACA')",$scopeParams);
$ofertat=(int)$getOne("SELECT COUNT(*) FROM leads l $where AND l.status='OFERTAT'",$scopeParams);
$contract=(int)$getOne("SELECT COUNT(*) FROM leads l $where AND l.status='CONTRACT'",$scopeParams);
$installed=(int)$getOne("SELECT COUNT(*) FROM leads l $where AND l.status='DE INSTALAT'",$scopeParams);
$closed=(int)$getOne("SELECT COUNT(*) FROM leads l $where AND l.status='CLOSED'",$scopeParams);
$unassigned=$manager?(int)$getOne("SELECT COUNT(*) FROM leads l $allWhere AND l.agent_id IS NULL AND COALESCE(l.status,'') NOT IN ('CONTRACT','DE INSTALAT','CLOSED','ALT INSTALATOR','LASAT')",$scopeParams):0;
$incomplete=(int)$getOne("SELECT COUNT(*) FROM leads l $allWhere AND ((l.telefon IS NULL OR l.telefon='') OR (l.judet IS NULL OR l.judet='') OR (l.sursa IS NULL OR l.sursa=''))",$scopeParams);
$conversion=$tot>0?round(100*$won/$tot,1):0;
$sourceStats=$getRows("SELECT COALESCE(NULLIF(l.sursa,''),'Necunoscută') label, COUNT(*) qty FROM leads l $where GROUP BY l.sursa ORDER BY qty DESC LIMIT 6",$scopeParams);
$stats=$getRows("SELECT COALESCE(NULLIF(l.status,''),'Fără status') label, COUNT(*) qty FROM leads l $where GROUP BY l.status ORDER BY qty DESC LIMIT 8",$scopeParams);
$recent=$getRows("SELECT l.id,l.nume,l.judet,l.sursa,l.telefon,l.status,l.created_at,u.nume agent_nume FROM leads l LEFT JOIN users u ON u.id=l.agent_id WHERE $scope ORDER BY l.id DESC LIMIT 8",$scopeParams);
$rank=$getRows("SELECT u.nume,COUNT(l.id) total,SUM(CASE WHEN l.status IN ('CONTRACT','DE INSTALAT') THEN 1 ELSE 0 END) contracte FROM leads l JOIN users u ON u.id=l.agent_id $where GROUP BY u.id,u.nume ORDER BY contracte DESC,total DESC LIMIT 6",$scopeParams);

# User visibility for team/management
$userWhere = '1=0';
$userParams = [];
if (eneron_role()==='team') { $userWhere='id=?'; $userParams=[$uid]; }
elseif (eneron_role()==='manager') { $userWhere='(id=? OR manager_id=?)'; $userParams=[$uid,$uid]; }
elseif ($owner) { $userWhere="LOWER(rol) IN ('owner','admin','manager','team')"; }
$activeAgents = (int)$getOne("SELECT COUNT(*) FROM users WHERE $userWhere AND status_activ=1", $userParams);
$capacityTotal = (int)$getOne("SELECT COALESCE(SUM(capacitate_maxima),0) FROM users WHERE $userWhere AND status_activ=1", $userParams);
$capacityUsed = (int)$getOne("SELECT COALESCE(SUM(leads_primite_azi),0) FROM users WHERE $userWhere AND status_activ=1", $userParams);

$followupsDue = 0;
if (eneron_column($pdo,'leads','data_contact_urmator')) {
    $followupsDue = (int)$getOne("SELECT COUNT(*) FROM leads l $allWhere AND l.data_contact_urmator IS NOT NULL AND l.data_contact_urmator <= NOW() AND COALESCE(l.status,'') NOT IN ('CONTRACT','DE INSTALAT','CLOSED','ALT INSTALATOR','LASAT')", $scopeParams);
}
$activitiesToday = eneron_table($pdo,'lead_activities') ? (int)$getOne("SELECT COUNT(*) FROM lead_activities a JOIN leads l ON l.id=a.lead_id WHERE $scope AND DATE(a.created_at)=CURDATE()",$scopeParams) : 0;
$rulesCount = eneron_table($pdo,'agent_distribution_rules') ? (int)$getOne("SELECT COUNT(*) FROM agent_distribution_rules") : 0;
$partnerTotal = eneron_table($pdo,'partner_leads') && $owner ? (int)$getOne("SELECT COUNT(*) FROM partner_leads") : 0;
$distinctSources = (int)$getOne("SELECT COUNT(DISTINCT NULLIF(l.sursa,'')) FROM leads l $allWhere", $scopeParams);

$requiredLeadFields=['putere_kw','program','tip_client','adresa','urgenta_proiect','sursa_campanie','lead_external_id','data_contact_urmator'];
$leadInstalled=0; foreach($requiredLeadFields as $f){ if (eneron_column($pdo,'leads',$f)) $leadInstalled++; }
$requiredPartnerFields=['lead_external_id','data_contact_urmator'];
$partnerInstalled=0; if (eneron_table($pdo,'partner_leads')) { foreach($requiredPartnerFields as $f){ if (eneron_column($pdo,'partner_leads',$f)) $partnerInstalled++; } }
$requiredTables=['agent_distribution_rules','lead_distribution_log','lead_activities'];
$tableInstalled=0; foreach($requiredTables as $t){ if (eneron_table($pdo,$t)) $tableInstalled++; }
$schemaReady = ($leadInstalled===count($requiredLeadFields) && $tableInstalled===count($requiredTables));
$schemaPercent = (int)round((($leadInstalled + $partnerInstalled + $tableInstalled) / max(1,(count($requiredLeadFields)+count($requiredPartnerFields)+count($requiredTables))))*100);

$target=20;
if ($owner && $canTarget) $target=max(1,(int)$getOne('SELECT target_lunar FROM users WHERE id=?',[$uid]));
$progress=min(100,$won*100/max(1,$target));
$today=(int)$getOne("SELECT COUNT(*) FROM leads l WHERE $scope AND DATE(l.created_at)=CURDATE()",$scopeParams);
$stages = [
  ['01','Surse lead','Lead-uri noi + canale de intrare',number_format($newLeads,0,',','.'),'Lead-uri noi în interval', $manager?'master_leads.php?new=1':'agent_workspace.php', $manager?'Adaugă lead':'Lead-urile mele'],
  ['02','Fișier Master','Bază centrală calificată',number_format($allTotal,0,',','.'),'Înregistrări totale în Master', 'master_leads.php', 'Deschide Master'],
  ['03','Reguli distribuție','Alocare după județ, putere, program',number_format($unassigned,0,',','.'),'Lead-uri nealocate', $manager?'distribution.php':'agent_workspace.php', $manager?'Vezi distribuția':'Vezi lead-urile mele'],
  ['04','Agenți & follow-up','Contactare, ofertare, următoarea acțiune',number_format($followupsDue,0,',','.'),'Follow-up-uri scadente', 'agent_workspace.php', 'Spațiu agenți'],
  ['05','Output ofertă','Contracte, instalări și rezultat',number_format($won,0,',','.'),'Contracte / instalări / închise', 'statistics.php', 'Rapoarte'],
];
include __DIR__ . '/meniu.php';
?>
<div class="v8-dashboard" aria-label="Dashboard Eneron">
 <div class="v8-dash-titlebar">
  <div>
   <div class="v8-overline"><span class="v8-overline-dot"></span> ENERON / SALES COMMAND CENTER</div>
   <h1>Salut, <?=eneron_h(explode(' ',trim((string)($_SESSION['nume']??'echipă')))[0] ?: 'echipă')?> <span class="v8-dot-accent" aria-hidden="true"></span></h1>
   <p>Tot fluxul comercial, de la primul lead până la ofertă și instalare.</p>
  </div>
  <div class="v8-title-actions">
   <div class="v8-filter-label">PERIOADĂ</div>
   <nav class="v8-periods" aria-label="Perioada statisticilor">
   <?php foreach([0=>'Tot timpul',7=>'7 zile',30=>'30 zile',90=>'90 zile'] as $d=>$label): ?>
    <a class="<?=$range===$d?'active':''?>" href="?range=<?=$d?>" <?=$range===$d?'aria-current="page"':''?>><?=eneron_h($label)?></a>
   <?php endforeach; ?>
   </nav>
  </div>
 </div>
 <section class="v8-hero-grid" aria-label="Prezentare generală">
  <div class="v8-hero-main">
    <div class="v8-hero-top"><span><b class="v8-spark"></b> LIVE WORKFLOW</span><span>01 / 05 · OVERVIEW</span></div>
    <h2>Ai control asupra<br><em>fiecărei oportunități.</em></h2>
    <p>Un singur loc pentru captare, calificare, distribuție și follow-up.</p>
    <div class="v8-hero-actions"><a href="master_leads.php" class="v8-btn v8-btn-white">Deschide Fișierul Master</a><?php if($manager):?><a href="distribution.php" class="v8-btn v8-btn-ghost">Distribuie lead-uri</a><?php else: ?><a href="agent_workspace.php" class="v8-btn v8-btn-ghost">Lead-urile mele</a><?php endif;?></div>
    <div class="v8-hero-art" aria-hidden="true"><div class="v8-art-orb"></div><div class="v8-art-ring one"></div><div class="v8-art-ring two"></div></div>
  </div>
  <div class="v8-hero-side">
    <div class="v8-side-head"><span class="v8-pill">DE URMĂRIT</span><span class="v8-lightning">PRIORITAR</span></div>
    <strong><?=number_format($manager?$unassigned:$followupsDue,0,',','.')?></strong>
    <h3><?=$manager?'Lead-uri fără agent':'Contactări restante'?></h3>
    <p><?=$manager?'Oportunități care așteaptă să fie repartizate.':'Contactări programate care au ajuns la scadență.'?></p>
    <a href="<?=$manager?'distribution.php':'agent_workspace.php'?>">Vezi lista și rezolvă</a>
  </div>
 </section>
 <div class="v8-section-heading"><div><span class="v8-section-line"></span><h2>Indicatori esențiali</h2></div><span><?=number_format($allTotal,0,',','.')?> lead-uri în baza centrală · <?= $range===0?'toată perioada':($range.' zile') ?></span></div>
 <section class="v8-metric-grid" aria-label="Indicatori de performanță">
   <article class="v8-metric v8-metric-blue"><div class="v8-metric-title">Lead-uri în interval <span>Portofoliu</span></div><div class="v8-metric-number"><?=number_format($tot,0,',','.')?></div><div class="v8-metric-bottom"><span><?=number_format($today,0,',','.')?> astăzi</span><i></i> Intrări în Master</div></article>
   <article class="v8-metric v8-metric-coral"><div class="v8-metric-title">În lucru / active <span>Pipeline</span></div><div class="v8-metric-number"><?=number_format($active,0,',','.')?></div><div class="v8-metric-bottom"><span><?=number_format($ofertat,0,',','.')?> ofertate</span><i></i> Pipeline activ</div></article>
   <article class="v8-metric v8-metric-green"><div class="v8-metric-title">Contracte și instalări <span>Rezultate</span></div><div class="v8-metric-number"><?=number_format($won,0,',','.')?></div><div class="v8-metric-bottom"><span><?=number_format($contract,0,',','.')?> contractate</span><i></i> Realizări</div></article>
   <article class="v8-metric v8-metric-purple"><div class="v8-metric-title">Conversie comercială <span>Indicator</span></div><div class="v8-metric-number"><?=number_format($conversion,1,',','.')?><small>%</small></div><div class="v8-metric-bottom"><span><?=number_format($tot,0,',','.')?> analizate</span><i></i> Din interval</div></article>
 </section>
 <div class="v8-section-heading"><div><span class="v8-section-line"></span><h2>Fluxul de vânzări</h2></div><a href="master_leads.php" class="v8-mini-link">Gestionare lead-uri</a></div>
 <section class="v8-flow-grid" aria-label="Etapele din schema Eneron">
   <?php foreach($stages as $idx=>$s): ?>
   <article class="v8-flow-card v8-flow-<?=$idx+1?>">
     <div class="v8-flow-top"><span class="v8-flow-step"><?=$s[0]?></span><span class="v8-flow-stage">ETAPĂ</span></div>
     <h3><?=eneron_h($s[1])?></h3><p><?=eneron_h($s[2])?></p>
     <div class="v8-flow-bottom"><strong><?=$s[3]?></strong><small><?=eneron_h($s[4])?></small></div>
     <a class="v8-flow-link" href="<?=eneron_h($s[5])?>"><?=eneron_h($s[6])?></a>
   </article>
   <?php endforeach; ?>
 </section>
 <div class="v8-dash-bottom-grid">
  <section class="v8-panel v8-panel-pipeline">
    <header class="v8-panel-head"><div><span class="v8-panel-eyebrow">PIPELINE · LEAD MASTER</span><h2>Unde se află lead-urile?</h2><p>Distribuția după stadiul de vânzare</p></div><a href="statistics.php">Raport complet</a></header>
    <div class="v8-panel-body v8-pipeline-list">
    <?php foreach($stats as $i=>$entry): $pct=$tot>0?(int)round(100*(int)$entry['qty']/$tot):0; ?>
     <div class="v8-pipeline-item"><div class="v8-pipeline-row"><span><i class="v8-pipeline-swatch v8-tone-<?=($i%5)+1?>"></i><?=eneron_h($entry['label'])?></span><strong><?=number_format((int)$entry['qty'],0,',','.')?> <small><?=$pct?>%</small></strong></div><div class="v8-pipeline-track"><span class="v8-tone-<?=($i%5)+1?>" style="width:<?=$pct?>%"></span></div></div>
    <?php endforeach;?>
    <?php if(!$stats):?><p class="v8-empty">Nu există înregistrări în acest interval. <a href="dashboard.php?range=0">Vezi întregul portofoliu</a>.</p><?php endif;?>
    </div>
  </section>
  <section class="v8-panel v8-panel-sources"><header class="v8-panel-head"><div><span class="v8-panel-eyebrow">ACHIZIȚIE · SURSE</span><h2>De unde vin oportunitățile</h2><p>Canale de intrare</p></div><a href="master_leads.php">Deschide Master</a></header>
   <div class="v8-panel-body v8-source-list">
   <?php foreach($sourceStats as $i=>$src): ?><div class="v8-source-item"><span class="v8-source-icon v8-tone-<?=($i%5)+1?>"><?=sprintf('%02d',$i+1)?></span><span class="v8-source-name"><?=eneron_h($src['label'])?></span><strong><?=number_format((int)$src['qty'],0,',','.')?></strong></div><?php endforeach;?>
   <?php if(!$sourceStats):?><p class="v8-empty">Nu există surse pentru perioada selectată.</p><?php endif; ?>
   </div><div class="v8-panel-foot">Surse identificate: <strong><?=$distinctSources?></strong> &nbsp;·&nbsp; Înregistrări parteneri: <strong><?=$partnerTotal?></strong></div>
  </section>
  <section class="v8-panel v8-panel-recent"><header class="v8-panel-head"><div><span class="v8-panel-eyebrow">ACTIVITATE RECENTĂ</span><h2>Ultimele lead-uri</h2><p>Intrări în Fișierul Master</p></div><a href="master_leads.php">Toate lead-urile</a></header>
    <div class="v8-table-scroller"><table class="v8-recent-table"><thead><tr><th>Client</th><th>Sursă</th><th>Status</th><th>Înregistrat</th></tr></thead><tbody>
    <?php foreach($recent as $lead): ?><tr><td><strong><?=eneron_h($lead['nume']?:'Client nou')?></strong><small><?=eneron_h($lead['judet']?:'Județ neprecizat')?></small></td><td><?=eneron_h($lead['sursa']?:'—')?></td><td><span class="v8-status-chip"><?=eneron_h($lead['status']?:'Fără status')?></span></td><td><?=eneron_h(date('d.m.Y',strtotime($lead['created_at'])))?></td></tr><?php endforeach;?>
    <?php if(!$recent):?><tr><td colspan="4">Niciun lead în aria ta.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
  <section class="v8-panel v8-panel-activity"><header class="v8-panel-head"><div><span class="v8-panel-eyebrow">ECHIPĂ & FOLLOW-UP</span><h2>Acțiuni și responsabilități</h2><p>Din agenda operațională Eneron</p></div><a href="agent_workspace.php">Agenda</a></header>
   <div class="v8-action-list">
    <a href="agent_workspace.php"><span class="v8-action-ico">A1</span><span><strong>Follow-up scadent</strong><small>Contactări care necesită atenție</small></span><b><?=$followupsDue?></b></a>
    <?php if($manager): ?><a href="distribution.php"><span class="v8-action-ico">A2</span><span><strong>Lead-uri de distribuit</strong><small>Fără agent, în portofoliu</small></span><b><?=$unassigned?></b></a><?php endif; ?>
    <a href="master_leads.php"><span class="v8-action-ico">A3</span><span><strong>Date incomplete</strong><small>Telefon / județ / sursă</small></span><b><?=$incomplete?></b></a>
    <a href="agents.php"><span class="v8-action-ico">A4</span><span><strong>Echipă activă</strong><small>Capacitate zilnică <?=$capacityUsed?> / <?=$capacityTotal?></small></span><b><?=$activeAgents?></b></a>
   </div>
  </section>
 </div>
</div>
<script>
const targetInput=document.getElementById('targetInput');
if(targetInput) targetInput.addEventListener('change',async()=>{
 const n=Number(targetInput.value);if(!Number.isInteger(n)||n<1){alert('Introdu un target valid.');return;}
 const p=new URLSearchParams({ajax_target:'1',target:String(n),csrf_token:<?=json_encode(eneron_csrf())?>});
 try{const response=await fetch('dashboard.php',{method:'POST',body:p,credentials:'same-origin'});if(!response.ok)throw new Error(await response.text());location.reload();}catch(e){alert('Nu s-a putut salva: '+e.message);}
});
</script>
<?php include __DIR__ . '/footer.php'; ?>
