<?php 
ob_start();
require_once 'includes/session_check.php'; 
require_once 'includes/db.php';
require_once 'includes/eneron_core.php';
require_once 'includes/eneron_email.php';
eneron_sync_session($pdo);
$en_owner = in_array(eneron_role(), ['owner','admin'], true);
$en_manage = eneron_is_management();
$en_csrf = eneron_csrf();

// Partenerii nu au acces la operațiunile din Masterul general.
// Pentru Owner, tabul afișează registrul partenerilor.
$en_is_importer = eneron_role() === 'importer';
$en_partner_tab = $en_is_importer || (($_GET['tab'] ?? '') === 'partners');
if ($en_partner_tab) {
    if (!$en_is_importer && !$en_owner) { http_response_code(403); exit('Acces interzis.'); }
    $csrf = eneron_csrf();

$userId = (int)($_SESSION['user_id'] ?? 0);
$q = $pdo->prepare('SELECT rol, partener_asociat FROM users WHERE id = ? LIMIT 1');
$q->execute([$userId]);
$account = $q->fetch(PDO::FETCH_ASSOC) ?: [];
$role = strtolower(trim((string)($account['rol'] ?? '')));
$partnerName = strtolower(trim((string)($account['partener_asociat'] ?? '')));
$owner = in_array($role, ['owner', 'admin'], true);
if (!$owner && $role !== 'importer') {
    http_response_code(403);
    exit('Acces interzis.');
}

// Doar Owner poate modifica lead-urile din partner_leads.
// Lead-urile 123Credit sunt deja în Fișier Master și se editează numai acolo.
$partnerStatuses = ['NEW LEAD', 'INTERESAT', 'LEAD', 'IN LUCRU', 'OFERTAT', 'FOLLOW UP', 'NEGOCIERE', 'CONTRACT', 'DE INSTALAT', 'CLOSED', 'SE GANDESTE', 'NU RASPUNDE', 'REFUZ OFERTA', 'PIPELINE', 'URGENT', 'ALT INSTALATOR', 'VREA SA FACA'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    eneron_csrf_check();
    if (!$owner) { http_response_code(403); exit('Acces refuzat.'); }
    $id = filter_var($_POST['lead_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) { http_response_code(422); exit('Lead invalid.'); }
    $action = (string)$_POST['ajax_action'];
    if ($action === 'update_status') {
        $new = (string)($_POST['new_status'] ?? '');
        if (!in_array($new, $partnerStatuses, true)) { http_response_code(422); exit('Status invalid.'); }
        $q = $pdo->prepare('UPDATE partner_leads SET status = ? WHERE id = ?');
        $q->execute([$new, $id]);
        exit('ok');
    }
    if ($action === 'delete_lead') {
        $q = $pdo->prepare('DELETE FROM partner_leads WHERE id = ?');
        $q->execute([$id]);
        exit('ok');
    }
    http_response_code(422); exit('Acțiune invalidă.');
}

$filter = trim((string)($_GET['sursa'] ?? 'Toate'));
if (!in_array($filter, ['Toate', 'Vodafone Partener', '123Credit Partener'], true)) $filter = 'Toate';
$showVodafone = $owner || $partnerName === 'vodafone';
$showCredit = $owner || $partnerName === '123credit';

// UNION ALL păstrează ID-ul și tabelul fiecărui lead. În acest fel nu
// dublează datele, nu schimbă statusuri și nu modifică structura MySQL.
$branches = [];
$params = [];
if ($showVodafone) {
    $branches[] = "SELECT 'partner' AS origine, p.id, p.sursa, p.nume, p.telefon, p.judet, p.consum, p.status, p.created_at
                   FROM partner_leads p WHERE p.sursa = 'Vodafone Partener'" . ($owner ? '' : ' AND p.imported_by = ?');
    if (!$owner) $params[] = $userId;
}
// Dacă există și alte surse în partner_leads, Owner-ul le poate vedea.
if ($owner) {
    $branches[] = "SELECT 'partner' AS origine, p.id, p.sursa, p.nume, p.telefon, p.judet, p.consum, p.status, p.created_at
                   FROM partner_leads p WHERE p.sursa <> 'Vodafone Partener'";
}
if ($showCredit) {
    $branches[] = "SELECT 'master' AS origine, l.id, l.sursa, l.nume, l.telefon, l.judet, l.consum, l.status, l.created_at
                   FROM leads l WHERE l.sursa = '123Credit Partener'" . ($owner ? '' : ' AND l.imported_by = ?');
    if (!$owner) $params[] = $userId;
}
if (!$branches) { // Importator fără partener asociat: niciun lead vizibil.
    $branches[] = "SELECT 'partner' AS origine, p.id, p.sursa, p.nume, p.telefon, p.judet, p.consum, p.status, p.created_at FROM partner_leads p WHERE 1=0";
}
$union = implode(' UNION ALL ', $branches);
$filterWhere = ($owner && $filter !== 'Toate') ? ' WHERE x.sursa = ?' : '';
$filterParams = $params;
if ($filterWhere !== '') $filterParams[] = $filter;
$sqlStats = "SELECT COUNT(*) AS total,
 SUM(CASE WHEN x.status = 'NEW LEAD' THEN 1 ELSE 0 END) AS noi,
 SUM(CASE WHEN x.status IN ('LEAD','IN LUCRU','OFERTAT','FOLLOW UP') THEN 1 ELSE 0 END) AS lucru,
 SUM(CASE WHEN x.status IN ('CONTRACT','DE INSTALAT','CLOSED') THEN 1 ELSE 0 END) AS finalizate
 FROM ($union) x $filterWhere";
$q = $pdo->prepare($sqlStats);
$q->execute($filterParams);
$stats = $q->fetch(PDO::FETCH_ASSOC) ?: [];
$q = $pdo->prepare("SELECT x.* FROM ($union) x $filterWhere ORDER BY x.created_at DESC, x.id DESC LIMIT 200");
$q->execute($filterParams);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);
$h = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

include __DIR__ . '/meniu.php';
?>
<style>
  .eneron-master-tabs{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 22px;padding:8px;border:1px solid var(--glass-border,#344053);background:#121b24;border-radius:16px}
  .eneron-master-tabs a{color:#b7c8bd;padding:10px 16px;border-radius:10px;font-weight:750;font-size:13px}
  .eneron-master-tabs a.is-active{color:#e7f6e6;border:1px solid #426b4d;background:#203629}
  .partner-table-container{max-width:100%}
  .partner-table{background:#101b17!important}
  .partner-table td,.partner-table th{background:transparent!important;color:#dce8dc!important}
  .partner-kpi,.partner-hero,.glass-card{color:#e1ecdf}

  .partner-hero{display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap;margin-bottom:22px}
  .partner-hero h1{margin:0 0 7px;font-size:clamp(25px,3vw,38px);line-height:1.12;color:var(--text-main,#e8f0f7)}
  .partner-hero p{color:var(--text-muted,#9caec1);max-width:740px}
  .partner-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .partner-actions a,.partner-actions select{min-height:43px;border-radius:12px;padding:9px 15px;background:var(--panel,#141e2a);color:var(--text-main,#e8f0f7);border:1px solid var(--glass-border,#344053);text-decoration:none;font-weight:650}
  .partner-actions a:hover{border-color:var(--neon-green,#a1b79d)}
  .partner-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:0 0 24px}
  .partner-kpi{background:var(--panel,#141e2a);border:1px solid var(--glass-border,#344053);border-radius:16px;padding:20px;box-shadow:0 14px 35px rgba(0,0,0,.17)}
  .partner-kpi span{color:var(--text-muted,#9caec1);display:block;font-size:12px;margin-bottom:8px}
  .partner-kpi strong{color:var(--text-main,#e8f0f7);font-size:28px}
  .partner-table-container{overflow:auto;border:1px solid var(--glass-border,#344053);border-radius:16px}
  .partner-table{border-collapse:collapse;width:100%;min-width:760px;background:var(--panel,#141e2a)}
  .partner-table th,.partner-table td{text-align:left;border-bottom:1px solid var(--glass-border,#344053);padding:14px 16px;color:var(--text-main,#e8f0f7);vertical-align:middle}
  .partner-table th{font-size:11px;color:var(--text-muted,#9caec1);text-transform:uppercase;letter-spacing:1px}
  .partner-table tbody tr:hover{background:rgba(255,255,255,.035)}
  .partner-table select{background:var(--panel,#141e2a);color:var(--text-main,#e8f0f7);border:1px solid var(--glass-border,#344053);padding:8px;border-radius:8px;max-width:170px}
  .partner-table button,.partner-table a{color:var(--text-main,#e8f0f7);font:inherit;background:transparent;border:1px solid var(--glass-border,#344053);border-radius:9px;padding:8px 12px;cursor:pointer;display:inline-block;text-decoration:none;white-space:nowrap}
  .partner-table button:hover,.partner-table a:hover{border-color:var(--neon-green,#a1b79d)}
  .partner-note{color:var(--text-muted,#9caec1);font-size:12px;margin:14px 0}
  @media(max-width:1100px){.partner-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media(max-width:580px){.partner-kpis{grid-template-columns:1fr 1fr}.partner-kpi{padding:14px}.partner-hero{align-items:stretch}.partner-actions{width:100%}.partner-actions a{flex:1;text-align:center}.partner-actions select{width:100%}.partner-table{min-width:720px}}
</style>
<nav class="eneron-master-tabs" aria-label="Secțiunile fișierului Master">
  <?php if ($owner): ?><a href="master_leads.php">Fișier Master</a><?php endif; ?>
  <a href="master_leads.php?tab=partners" class="is-active" aria-current="page">Lead-uri parteneri</a>
</nav>
<section class="partner-hero">
    <div>
        <div class="en-eyebrow">SURSE EXTERNE / PARTENERI</div>
        <h1>Lead-uri parteneri</h1>
        <p>Vodafone și 123Credit, împreună într-un singur punct de lucru. Istoricul și statusurile sunt citite din tabelele existente.</p>
    </div>
    <div class="partner-actions">
        <?php if ($showVodafone): ?><a href="vodafone.php">Lead nou Vodafone</a><?php endif; ?>
        <?php if ($showCredit): ?><a href="123credit.php">Lead nou 123Credit</a><?php endif; ?>
        <?php if ($owner): ?>
          <form method="get"><input type="hidden" name="tab" value="partners"><label for="partner-source" class="sr-only">Partener</label>
          <select id="partner-source" name="sursa" onchange="this.form.submit()">
           <option value="Toate" <?= $filter==='Toate'?'selected':'' ?>>Toți partenerii</option>
           <option value="Vodafone Partener" <?= $filter==='Vodafone Partener'?'selected':'' ?>>Vodafone</option>
           <option value="123Credit Partener" <?= $filter==='123Credit Partener'?'selected':'' ?>>123Credit</option>
          </select></form>
        <?php endif; ?>
    </div>
</section>
<div class="partner-kpis">
  <article class="partner-kpi"><span>Total solicitări</span><strong><?= (int)($stats['total'] ?? 0) ?></strong></article>
  <article class="partner-kpi"><span>Lead-uri noi</span><strong><?= (int)($stats['noi'] ?? 0) ?></strong></article>
  <article class="partner-kpi"><span>În lucru</span><strong><?= (int)($stats['lucru'] ?? 0) ?></strong></article>
  <article class="partner-kpi"><span>Finalizate</span><strong><?= (int)($stats['finalizate'] ?? 0) ?></strong></article>
</div>
<section class="glass-card">
  <div class="en-section-header"><h2>Istoric lead-uri parteneri</h2><span class="en-muted">Primele 200 înregistrări</span></div>
  <div class="partner-table-container">
   <table class="partner-table"><thead><tr><th>Înregistrare</th><th>Client</th><th>Telefon</th><th>Județ</th><th>Partener</th><th>Status</th><?php if ($owner): ?><th>Acțiune</th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
      <?php $id = (int)$row['id']; $source = (string)$row['origine']; ?>
      <tr id="partner-row-<?= $h($source) ?>-<?= $id ?>">
       <td><?= $h(date('d.m.Y H:i', strtotime((string)$row['created_at']))) ?></td>
       <td><strong><?= $h($row['nume']) ?></strong></td>
       <td><?= $h($row['telefon']) ?></td>
       <td><?= $h($row['judet']) ?></td>
       <td><?= $h(str_replace(' Partener','',(string)$row['sursa'])) ?></td>
       <td>
        <?php if ($owner && $source === 'partner'): ?>
           <select aria-label="Status lead <?= $id ?>" onfocus="this.dataset.previous=this.value" onchange="updatePartnerStatus(<?= $id ?>, this)">
             <?php foreach ($partnerStatuses as $s): ?><option value="<?= $h($s) ?>" <?= $row['status']===$s?'selected':'' ?>><?= $h($s) ?></option><?php endforeach; ?>
           </select>
        <?php else: ?><span><?= $h($row['status'] ?: 'NEW LEAD') ?></span><?php endif; ?>
       </td>
       <?php if ($owner): ?><td>
         <?php if ($source === 'partner'): ?><button type="button" onclick="deletePartnerLead(<?= $id ?>)">Șterge</button>
         <?php else: ?><a href="master_leads.php?<?= $h(http_build_query(['sursa' => '123Credit Partener', 'q' => (string)$row['telefon']])) ?>">Deschide în Master</a><?php endif; ?>
       </td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= $owner?7:6 ?>">Nu există lead-uri pentru selecția curentă.</td></tr><?php endif; ?>
   </tbody></table>
  </div>
  <p class="partner-note">Lead-urile Vodafone sunt gestionate în registrul partenerilor, iar cele 123Credit sunt urmărite în Fișier Master. Nu s-au copiat și nu s-au mutat înregistrări.</p>
</section>
<?php if ($owner): ?>
<script>
const partnerCsrf = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
async function partnerRequest(action, id, status='') {
  const body = new URLSearchParams({ajax_action:action,lead_id:String(id),csrf_token:partnerCsrf});
  if(action==='update_status') body.set('new_status', status);
  const response = await fetch('master_leads.php?tab=partners', {method:'POST',credentials:'same-origin',body});
  const result = await response.text();
  if(!response.ok || result.trim()!=='ok') throw Error(result);
}
async function updatePartnerStatus(id, el) {
  const oldValue = el.dataset.previous ?? el.value;
  try{await partnerRequest('update_status',id,el.value);el.dataset.previous=el.value;}
  catch(e){alert('Statusul nu s-a salvat.');el.value=oldValue;}
}
async function deletePartnerLead(id) {
  if(!confirm('Ștergi definitiv acest lead din registrul partenerilor?')) return;
  try{await partnerRequest('delete_lead',id);document.getElementById('partner-row-partner-'+id)?.remove();}
  catch(e){alert('Lead-ul nu a putut fi șters.');}
}
</script>
<?php endif; ?>
<?php include __DIR__ . '/footer.php'; ?>
<?php
exit;
}

[$en_scope, $en_scope_params] = eneron_scoped_where();


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =========================================================================
// FUNCȚIE PENTRU TRIMITERE EMAIL LEAD STANDARD (HTML)
// =========================================================================
// Captare manuală direct în Master, fără endpoint separat.
$newLeadError = '';
$hasPower=eneron_column($pdo,'leads','putere_kw');
$hasProgram=eneron_column($pdo,'leads','program');
$hasLocality=eneron_column($pdo,'leads','oras_localitate');
$optionalFields=['tip_client','adresa','urgenta_proiect','sursa_campanie','data_contact_urmator'];
$availableFields=[];foreach($optionalFields as $f)$availableFields[$f]=eneron_column($pdo,'leads',$f);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_lead'])) {
    if (!$en_manage) { http_response_code(403); exit('Acces interzis.'); }
    $error = '';

    eneron_csrf_check();
    $nume=trim((string)($_POST['nume']??''));
    $telefon=preg_replace('/[^0-9+]/','',trim((string)($_POST['telefon']??'')));
    $email=trim((string)($_POST['email']??''));
    $judet=trim((string)($_POST['judet']??''));
    $source=trim((string)($_POST['sursa']??''));
    $digits=preg_replace('/\D/','',$telefon);
    if(eneron_length($nume)<2 || strlen($digits)<9 || strlen($digits)>15 || !$judet || !$source) $error='Completează numele, un telefon valid, sursa și județul.';
    elseif($email && !filter_var($email,FILTER_VALIDATE_EMAIL)) $error='Adresa de email este invalidă.';
    else {
        $q=$pdo->prepare('SELECT id FROM leads WHERE telefon=? AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) LIMIT 1');
        $q->execute([$telefon]);
        if($q->fetchColumn()) $error='Există deja un lead cu același număr în ultimele 30 de zile.';
        else {
            $data=['sursa'=>$source,'nume'=>$nume,'telefon'=>$telefon,'email'=>$email,'judet'=>$judet,
              'bransament'=>trim((string)($_POST['bransament']??'')),
              'consum'=>trim((string)($_POST['consum']??'')),
              'proiect'=>trim((string)($_POST['proiect']??'')),
              'prioritate'=>in_array((string)($_POST['prioritate']??''),['0','1','2','3','5','7'],true)?(int)$_POST['prioritate']:0,
              'status'=>'NEW LEAD'];
            if($hasPower) {
                $raw=trim((string)($_POST['putere_kw']??''));
                if($raw!=='' && (!is_numeric($raw)||floatval($raw)<0)) $error='Puterea trebuie să fie un număr pozitiv.';
                else $data['putere_kw']=$raw===''?null:(float)$raw;
            }
            if($hasProgram)$data['program']=trim((string)($_POST['program']??''));
            if($hasLocality)$data['oras_localitate']=trim((string)($_POST['localitate']??''));
            foreach (['tip_client'=>60,'adresa'=>255,'urgenta_proiect'=>120,'sursa_campanie'=>180] as $column=>$limit) {
                if(!empty($availableFields[$column])) $data[$column]=eneron_limit(trim((string)($_POST[$column]??'')),0,$limit);
            }
            if(!empty($availableFields['data_contact_urmator'])) {
                $next=trim((string)($_POST['data_contact_urmator']??''));
                if ($next!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',$next)) $error='Dată de contact invalidă.';
                else $data['data_contact_urmator']=$next===''?null:str_replace('T',' ',$next).':00';
            }
            if(!$error) {
                $cols=array_keys($data);$holders=implode(',',array_fill(0,count($cols),'?'));
                $sql='INSERT INTO leads (`'.implode('`,`',$cols).'`,created_at) VALUES ('.$holders.',NOW())';
                try{$pdo->prepare($sql)->execute(array_values($data));
                    header('Location: master_leads.php?msg='.urlencode('Lead adăugat în Fișier Master. Poate fi distribuit din Distribuție inteligentă.'));exit;
                } catch (Throwable $e) {error_log('Eneron manual lead: '.$e->getMessage());$error='Nu s-a putut salva lead-ul. Verifică structura bazei de date.';}
            }
        }
    }
    $newLeadError = $error;
}

// Export CSV only for managers: all Master fields available on this DB schema.
if (isset($_GET['export_csv'])) {
    if (!$en_manage) {http_response_code(403);exit('Export nepermis');}
    $columns=['created_at','sursa','sursa_campanie','lead_external_id','nume','telefon','email','judet',
        'oras_localitate','adresa','tip_client','bransament','consum','proiect','putere_kw',
        'program','urgenta_proiect','prioritate','status','agent_id','nota_agent','nota_manager',
        'data_contact_urmator','ultima_contactare','updated_at'];
    $columns=array_values(array_filter($columns,static fn($col)=>eneron_column($pdo,'leads',$col)));
    $sql="SELECT ".implode(',',array_map(static fn($col)=>'l.`'.$col.'`',$columns))." FROM leads l WHERE $en_scope ORDER BY l.id DESC";
    $q=$pdo->prepare($sql);$q->execute($en_scope_params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="eneron_master_'.date('Y-m-d').'.csv"');
    header('X-Content-Type-Options: nosniff');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,$columns);
    while($row=$q->fetch(PDO::FETCH_NUM)){
       foreach($row as &$cell)if(is_string($cell)&&preg_match('/^[\s\x00-\x1f]*[=+@-]/u',$cell))$cell="'".$cell;
       unset($cell);fputcsv($out,$row);
    }
    fclose($out);exit;
}

// Şablon CSV v6. Compatible with Excel (UTF-8 BOM), no personal data.
if (isset($_GET['csv_template'])) {
    if (!$en_manage) {http_response_code(403);exit('Acces interzis');}
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="eneron_model_import_v6.csv"');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['sursa','sursa_campanie','nume','telefon','email','judet','oras_localitate','bransament','consum','proiect','putere_kw','program','tip_client','urgenta_proiect','prioritate','nota_agent']);
    fclose($out);exit;
}
// Import CSV with explicit column headers; fallback for the original 10-column Eneron template.
if (isset($_FILES['csv_file'])) {
    if (!$en_manage) {http_response_code(403);exit('Import nepermis');}
    eneron_csrf_check();
    if ((int)($_FILES['csv_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK ||
        (int)($_FILES['csv_file']['size']??0)>5*1024*1024 || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        http_response_code(400);exit('CSV invalid. Maxim 5 MB.');
    }
    $file=fopen($_FILES['csv_file']['tmp_name'],'rb');
    $first=fgets($file,8192);if($first===false){fclose($file);http_response_code(422);exit('CSV gol');}
    $delimiter=substr_count($first,';')>substr_count($first,',')?';':',';
    rewind($file);$header=fgetcsv($file,8192,$delimiter);
    if (!$header) {fclose($file);http_response_code(422);exit('Lipsește antetul CSV');}
    $normalized=[];
    foreach($header as $col){$col=preg_replace('/^\xEF\xBB\xBF/','',(string)$col);$col=eneron_norm($col);$col=str_replace([' ','-'],['_','_'],$col);$normalized[]=$col;}
    $aliases=['nume_prenume'=>'nume','name'=>'nume','phone'=>'telefon','phone_number'=>'telefon','telephone'=>'telefon','county'=>'judet',
      'localitate'=>'oras_localitate','oras'=>'oras_localitate','source'=>'sursa','campaign'=>'sursa_campanie','putere'=>'putere_kw',
      'putere_kwp'=>'putere_kw','cand_doreste_sa_inceapa'=>'urgenta_proiect','urgenta'=>'urgenta_proiect','note'=>'nota_agent'];
    $mapped=[];foreach($normalized as $i=>$col){$col=$aliases[$col]??$col;$mapped[$col]=$i;}
    $headerMode=isset($mapped['telefon'])&&isset($mapped['nume']);
    // Original Eneron CSV indexed: sursa[0], nume[2], telefon[3], email[4], judet[5]...
    $legacyMap=['sursa'=>0,'nume'=>2,'telefon'=>3,'email'=>4,'judet'=>5,'bransament'=>6,'consum'=>7,'proiect'=>8,'prioritate'=>9];
    if(!$headerMode && count($header)<10){fclose($file);http_response_code(422);exit('CSV neacceptat: sunt necesare antetele nume și telefon sau formatul Eneron vechi (10 coloane).');}
    if(!$headerMode)$mapped=$legacyMap;
    $allowed=['sursa','sursa_campanie','nume','telefon','email','judet','oras_localitate','bransament','consum','proiect','putere_kw','program','tip_client','urgenta_proiect','prioritate','nota_agent'];
    $maxLength=['sursa'=>50,'sursa_campanie'=>180,'nume'=>150,'telefon'=>20,'email'=>100,'judet'=>50,'oras_localitate'=>100,'bransament'=>100,'consum'=>100,'proiect'=>100,'program'=>120,'tip_client'=>60,'urgenta_proiect'=>120,'nota_agent'=>5000];
    $cols=[];foreach($allowed as $col){if(isset($mapped[$col])&&eneron_column($pdo,'leads',$col))$cols[]=$col;}
    foreach(['telefon','nume'] as $required)if(!in_array($required,$cols,true)){fclose($file);http_response_code(422);exit('Coloană obligatorie absentă: '.$required);}
    $pdoInsertCols=array_merge($cols,['status']);$placeholders=implode(',',array_fill(0,count($pdoInsertCols),'?'));
    $sql='INSERT INTO leads (`'.implode('`,`',$pdoInsertCols).'`) VALUES ('.$placeholders.')';
    $insert=$pdo->prepare($sql);
    $check=$pdo->prepare('SELECT id FROM leads WHERE telefon=? AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) LIMIT 1');
    $imported_count=0;$skipped=0;$new_lead_ids=[];$line=0;
    while(($cells=fgetcsv($file,16384,$delimiter))!==false && ++$line<=10000){
        if(!array_filter($cells,static fn($x)=>trim((string)$x)!==''))continue;
        $phoneIndex=$mapped['telefon'];$phone=preg_replace('/[^0-9+]/','',trim((string)($cells[$phoneIndex]??'')));
        if(strlen(preg_replace('/\D/','',$phone))<9){$skipped++;continue;}
        $check->execute([$phone]);if($check->fetchColumn()){$skipped++;continue;}
        $params=[];foreach($cols as $column){$val=trim((string)($cells[$mapped[$column]]??''));
            if($column==='telefon')$val=$phone;
            elseif($column==='putere_kw'){$val=str_replace(',','.',$val);$val=$val!==''&&is_numeric($val)&&floatval($val)>=0?(string)$val:null;}
            elseif($column==='prioritate'){$val=in_array($val,['0','1','2','3','5','7'],true)?(int)$val:3;}
            elseif(isset($maxLength[$column]))$val=eneron_limit($val,0,$maxLength[$column]);
            $params[]=$val===''?null:$val;
        }
        $params[]='NEW LEAD';$insert->execute($params);$new_lead_ids[]=(int)$pdo->lastInsertId();$imported_count++;
    }
    fclose($file);
    $conf=$pdo->query('SELECT auto_assign_status FROM settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if($conf&&(int)$conf['auto_assign_status']===1&&$new_lead_ids){
        foreach(array_chunk($new_lead_ids,100) as $chunk){$decisions=eneron_distribute($pdo,$chunk,false);$emails=[];
            foreach($decisions as $decision)if(!empty($decision['agent_id']))$emails[(int)$decision['agent_id']][]=(int)$decision['lead_id'];
            foreach($emails as $agentId=>$assigned)sendStandardLeadEmail($pdo,$assigned,$agentId);
        }
    }
    header('Location: master_leads.php?msg='.rawurlencode('Importate '.$imported_count.' lead-uri. Omise '.$skipped.' (duplicate / date incomplete).'));exit;
}

// ENERON v6: update strict, scope-aware and optional contact history.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajax_update_lead']) || isset($_POST['bulk_assign']) || isset($_POST['bulk_delete']))) {
    eneron_csrf_check();
    $fail = static function(int $code,string $message): void { http_response_code($code); exit($message); };
    if (isset($_POST['ajax_update_lead'])) {
        $id=(int)($_POST['id']??0); $field=(string)($_POST['field']??''); $value=trim((string)($_POST['value']??''));
        $teamFields=['status','prioritate','nota_agent','data_contact_urmator'];
        $manageFields=['agent_id','nume','telefon','email','judet','oras_localitate','sursa','bransament','consum','proiect','putere_kw','program','tip_client','adresa','urgenta_proiect','sursa_campanie','nota_manager'];
        if (!in_array($field,$teamFields,true) && !($en_manage && in_array($field,$manageFields,true))) $fail(403,'Nu ai dreptul să modifici acest câmp.');
        if (!eneron_column($pdo,'leads',$field)) $fail(422,'Coloană inexistentă. Rulează migrarea SQL v6.');
        if ($field==='status' && !in_array($value,eneron_statuses(),true)) $fail(422,'Status invalid');
        if ($field==='prioritate' && !in_array($value,['0','1','2','3','5','7'],true)) $fail(422,'Prioritate invalidă');
        if ($field==='email' && $value!=='' && !filter_var($value,FILTER_VALIDATE_EMAIL)) $fail(422,'Email invalid');
        if ($field==='telefon') { $value=preg_replace('/[^0-9+]/','',$value); if (strlen(preg_replace('/\D/','',$value))<9) $fail(422,'Telefon prea scurt'); }
        if ($field==='putere_kw' && $value!=='' && (!is_numeric($value) || (float)$value<0 || (float)$value>999999)) $fail(422,'Putere kW invalidă');
        if ($field==='data_contact_urmator' && $value!=='' && !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/',$value)) $fail(422,'Dată invalidă');
        if ($field==='data_contact_urmator' && $value!=='') $value=str_replace('T',' ',$value).':00';
        $limits=['nume'=>150,'telefon'=>20,'email'=>100,'judet'=>50,'oras_localitate'=>100,'sursa'=>50,'bransament'=>100,'consum'=>100,'proiect'=>100,'program'=>120,'tip_client'=>60,'adresa'=>255,'urgenta_proiect'=>120,'sursa_campanie'=>180,'nota_agent'=>5000,'nota_manager'=>5000];
        if (isset($limits[$field])) $value=eneron_limit($value,0,$limits[$field]);
        $exists=$pdo->prepare("SELECT l.id FROM leads l WHERE l.id=? AND $en_scope");
        $exists->execute(array_merge([$id],$en_scope_params));
        if (!$exists->fetchColumn()) $fail(403,'Lead inaccesibil');
        if ($field==='agent_id' && $value!=='') {
            if (!ctype_digit($value)) $fail(422,'Agent invalid');
            $sql='SELECT id FROM users WHERE id=? AND status_activ=1'; $args=[(int)$value];
            if (eneron_role()==='manager') {$sql.=' AND (id=? OR manager_id=?)'; $args[]=(int)$_SESSION['user_id'];$args[]=(int)$_SESSION['user_id'];}
            $q=$pdo->prepare($sql);$q->execute($args);if (!$q->fetchColumn()) $fail(403,'Agent inaccesibil');
        }
        $pdo->prepare("UPDATE leads SET `$field`=? WHERE id=?")->execute([$value===''?null:$value,$id]);
        if ($field==='agent_id' && $value!=='') {
            if (eneron_table($pdo,'lead_distribution_log')) $pdo->prepare('INSERT INTO lead_distribution_log(lead_id,agent_id,reason,created_by) VALUES(?,?,?,?)')->execute([$id,(int)$value,'Alocare manuală din Master',(int)$_SESSION['user_id']]);
            sendStandardLeadEmail($pdo,[$id],(int)$value);
        }
        if (in_array($field,['status','nota_agent'],true) && eneron_table($pdo,'lead_activities')) {
            $desc=$field==='status'?'Status actualizat: '.$value:'Notă actualizată din Fișier Master';
            $pdo->prepare('INSERT INTO lead_activities (lead_id,user_id,tip,descriere) VALUES (?,?,?,?)')->execute([$id,(int)$_SESSION['user_id'],$field==='status'?'status':'nota',$desc]);
        }
        header('Content-Type: application/json; charset=utf-8');exit(json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE));
    }
    $ids=eneron_clean_ids($_POST['ids']??'',500);if (!$ids) $fail(422,'Nu ai selectat lead-uri');
    $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $verify=$pdo->prepare("SELECT COUNT(*) FROM leads l WHERE l.id IN ($placeholders) AND $en_scope");
    $verify->execute(array_merge($ids,$en_scope_params));
    if ((int)$verify->fetchColumn()!==count($ids)) $fail(403,'Selecție inaccesibilă');
    if (isset($_POST['bulk_assign'])) {
        if (!$en_manage) $fail(403,'Acțiune pentru manageri');
        $agent=(int)($_POST['agent_id']??0);$sql='SELECT id FROM users WHERE id=? AND status_activ=1';$args=[$agent];
        if (eneron_role()==='manager'){$sql.=' AND (id=? OR manager_id=?)';$args[]=(int)$_SESSION['user_id'];$args[]=(int)$_SESSION['user_id'];}
        $q=$pdo->prepare($sql);$q->execute($args);if(!$q->fetchColumn()) $fail(403,'Agent invalid');
        $pdo->prepare("UPDATE leads SET agent_id=? WHERE id IN ($placeholders)")->execute(array_merge([$agent],$ids));
        if(eneron_table($pdo,'lead_distribution_log')){
            $log=$pdo->prepare('INSERT INTO lead_distribution_log (lead_id,agent_id,reason,created_by) VALUES (?,?,?,?)');
            foreach($ids as $leadId)$log->execute([$leadId,$agent,'Alocare multiplă din Master',(int)$_SESSION['user_id']]);
        }
        sendStandardLeadEmail($pdo,$ids,$agent);exit('ok');
    }
    if (!$en_owner) $fail(403,'Ștergerea este rezervată Owner-ului');
    $pdo->prepare("DELETE FROM leads WHERE id IN ($placeholders)")->execute($ids);exit('ok');
}

$columnV6=eneron_column($pdo,'leads','putere_kw');
$agentsSql='SELECT id,nume FROM users WHERE status_activ=1'; $agentsArgs=[];
if (eneron_role()==='manager') {$agentsSql.=' AND (id=? OR manager_id=?)';$agentsArgs=[(int)$_SESSION['user_id'],(int)$_SESSION['user_id']];}
elseif (!$en_manage) {$agentsSql.=' AND id=?';$agentsArgs=[(int)$_SESSION['user_id']];}
$agentsSql.=' ORDER BY nume';
$as=$pdo->prepare($agentsSql);$as->execute($agentsArgs);$assignable_agents=$as->fetchAll(PDO::FETCH_ASSOC);
$allAgents=[];foreach($assignable_agents as $a)$allAgents[(int)$a['id']]=$a['nume'];
$sourceStmt=$pdo->prepare("SELECT DISTINCT l.sursa FROM leads l WHERE $en_scope AND l.sursa IS NOT NULL AND l.sursa<>'' ORDER BY l.sursa");
$sourceStmt->execute($en_scope_params);$sources=$sourceStmt->fetchAll(PDO::FETCH_COLUMN);
$st_list=eneron_statuses();
$priorityList=[0=>'P0 · Imediat',1=>'P1 · 1–3 luni',2=>'P2 · 3–6 luni',3=>'P3 · 6+ luni',5=>'P5 · Informații',7=>'P7 · Vechi'];
$filters=[
 'q'=>trim((string)($_GET['q']??'')), 'status'=>trim((string)($_GET['status']??'')),
 'sursa'=>trim((string)($_GET['sursa']??'')), 'judet'=>trim((string)($_GET['judet']??'')),
 'prio'=>trim((string)($_GET['prio']??'')), 'agent'=>trim((string)($_GET['agent']??''))];
$whereParts=[$en_scope];$params=$en_scope_params;
if ($filters['q']!=='') {$whereParts[]='(l.nume LIKE ? OR l.telefon LIKE ? OR l.email LIKE ? OR l.judet LIKE ?)';$like='%'.eneron_limit($filters['q'],0,90).'%';array_push($params,$like,$like,$like,$like);}
foreach (['status','sursa','judet'] as $k) if ($filters[$k]!=='') {$whereParts[]='l.`'.$k.'`=?';$params[]=$filters[$k];}
if ($filters['prio']!=='' && ctype_digit($filters['prio'])){$whereParts[]='l.prioritate=?';$params[]=(int)$filters['prio'];}
if ($filters['agent']==='unassigned')$whereParts[]='l.agent_id IS NULL';
elseif ($filters['agent']!=='' && ctype_digit($filters['agent'])){$whereParts[]='l.agent_id=?';$params[]=(int)$filters['agent'];}
$where=implode(' AND ',$whereParts);
$pageSize=(int)($_GET['size']??30);if(!in_array($pageSize,[30,60,100],true))$pageSize=30;
$countQuery=$pdo->prepare("SELECT COUNT(*) FROM leads l WHERE $where");$countQuery->execute($params);$total=(int)$countQuery->fetchColumn();
$pages=max(1,(int)ceil($total/$pageSize));$page=max(1,min($pages,(int)($_GET['page']??1)));$offset=($page-1)*$pageSize;
$s=$pdo->prepare("SELECT l.*,u.nume AS agent_nume FROM leads l LEFT JOIN users u ON u.id=l.agent_id WHERE $where ORDER BY l.id DESC LIMIT $pageSize OFFSET $offset");
$s->execute($params);$leads=$s->fetchAll(PDO::FETCH_ASSOC);
$getCount=static function(string $extra='') use($pdo,$en_scope,$en_scope_params):int{
 $q=$pdo->prepare("SELECT COUNT(*) FROM leads l WHERE $en_scope ".$extra);$q->execute($en_scope_params);return (int)$q->fetchColumn();
};
$summaryTotal=$getCount();$summaryUnassigned=$getCount('AND l.agent_id IS NULL');
$summaryNew=$getCount("AND (l.status='NEW LEAD' OR l.status IS NULL)");
$summaryPriority=$getCount('AND l.prioritate IN (0,1)');
$queryPage=static function(int $dest) use($filters,$pageSize):string {
 $qs=array_filter($filters,static fn($v)=>$v!=='');$qs['size']=$pageSize;$qs['page']=$dest;return 'master_leads.php?'.http_build_query($qs);
};
include __DIR__ . '/meniu.php';
?>
<nav class="eneron-master-tabs" aria-label="Secțiunile fișierului Master">
 <a class="is-active" href="master_leads.php" aria-current="page">Fișier Master</a>
 <?php if($en_owner): ?><a href="master_leads.php?tab=partners">Lead-uri parteneri</a><?php endif; ?>
</nav>
<div class="v8-master-hero"><div class="crm-page-title">
  <div><span class="crm-eyebrow">ETAPA 02 <span class="crm-dot"></span> MASTER DATABASE · CENTRAL</span><h1>Fișier Master <span class="crm-title-count"><?=number_format($summaryTotal,0,',','.')?></span></h1><p>Toate oportunitățile într-un singur loc: calificare, agent responsabil, ofertare și follow-up.</p><div class="crm-title-flow"><span>Surse lead</span><i></i><span class="active">Master</span><i></i><span>Distribuție</span><i></i><span>Agenți & ofertare</span></div></div>
  <div class="crm-page-actions"><?php if($en_manage):?><?php if($en_owner):?><a class="crm-btn crm-btn-soft" href="sheet_sync.php">Sincronizare Meta / Sheets</a><?php endif;?><a class="crm-btn crm-btn-soft" href="?export_csv=1">Export CSV</a><a class="crm-btn crm-btn-primary" href="master_leads.php?new=1">Lead nou</a><?php endif;?></div>
</div></div>
<?php if(isset($_GET['msg'])):?><div class="crm-toast"><?=eneron_h($_GET['msg'])?></div><?php endif;?>
<?php if(!$columnV6):?><div class="crm-warning">Schema SQL pentru distribuție nu este încă instalată: puterea, programul și câmpurile complete nu pot fi editate. Rulează <strong>Migrarea MySQL v7 (din pachetul anterior)</strong> din phpMyAdmin.</div><?php endif;?>
<div class="crm-mini-metrics">
 <div class="crm-mini-stat"><span>Total portofoliu</span><strong><?=number_format($summaryTotal,0,',','.')?></strong><small>Lead-uri în aria ta</small></div>
 <div class="crm-mini-stat"><span>De calificat</span><strong><?=number_format($summaryNew,0,',','.')?></strong><small>Status nou</small></div>
 <div class="crm-mini-stat"><span>Fără agent</span><strong><?=number_format($summaryUnassigned,0,',','.')?></strong><small>De distribuit</small></div>
 <div class="crm-mini-stat"><span>Prioritate P0–P1</span><strong><?=number_format($summaryPriority,0,',','.')?></strong><small>Necesită atenție</small></div>
</div>
<section class="crm-master-shell">
 <div class="crm-panel-head"><div><h2>Toate oportunitățile</h2><p>Arată <?=number_format($total,0,',','.')?> rezultate · pagina <?=$page?> din <?=$pages?></p></div><div class="crm-view-controls"><span class="crm-live-dot"></span> Date din MySQL</div></div>
 <form method="get" class="crm-filters" id="crmFilters">
  <label class="crm-search-field"><span>Căutare rapidă</span><input type="search" name="q" value="<?=eneron_h($filters['q'])?>" placeholder="Nume, telefon, email, județ..."></label>
  <label><span>Status</span><select name="status"><option value="">Toate statusurile</option><?php foreach($st_list as $v):?><option value="<?=eneron_h($v)?>" <?=$filters['status']===$v?'selected':''?>><?=eneron_h($v)?></option><?php endforeach;?></select></label>
  <label><span>Sursă</span><select name="sursa"><option value="">Toate sursele</option><?php foreach($sources as $v):?><option value="<?=eneron_h($v)?>" <?=$filters['sursa']===$v?'selected':''?>><?=eneron_h($v)?></option><?php endforeach;?></select></label>
  <label><span>Prioritate</span><select name="prio"><option value="">Orice prioritate</option><?php foreach($priorityList as $k=>$v):?><option value="<?=$k?>" <?=$filters['prio']===(string)$k?'selected':''?>><?=eneron_h($v)?></option><?php endforeach;?></select></label>
  <label><span>Agent</span><select name="agent"><option value="">Toți agenții</option><option value="unassigned" <?=$filters['agent']==='unassigned'?'selected':''?>>Nealocate</option><?php foreach($assignable_agents as $a):?><option value="<?=(int)$a['id']?>" <?=$filters['agent']===(string)$a['id']?'selected':''?>><?=eneron_h($a['nume'])?></option><?php endforeach;?></select></label>
  <label><span>Județ</span><input name="judet" value="<?=eneron_h($filters['judet'])?>" placeholder="Toate județele"></label>
  <button class="crm-btn crm-btn-primary" type="submit">Aplică filtre</button><a href="master_leads.php" class="crm-btn crm-btn-soft">Resetează</a>
 </form>
 <div class="crm-master-toolbar">
  <div class="crm-selection"><label><input type="checkbox" id="select_all"> <strong id="selected_count">0</strong> selectate</label></div>
  <?php if($en_manage):?><div class="crm-bulk"><select id="bulk_agent_id" aria-label="Agent pentru atribuire"><option value="">Alege agent...</option><?php foreach($assignable_agents as $a):?><option value="<?=(int)$a['id']?>"><?=eneron_h($a['nume'])?></option><?php endforeach;?></select><button class="crm-btn crm-btn-soft" type="button" onclick="bulkAssign()">Alocă selectate</button></div><?php endif;?>
  <?php if($en_owner):?><div><a href="distribution.php" class="crm-link">Distribuție inteligentă</a><button type="button" class="crm-delete-link" onclick="bulkDelete()">Șterge</button></div><?php endif;?>
 </div>
 <div class="crm-master-table-wrap"><table class="crm-master-table" id="leadsTable"><thead><tr>
 <th class="crm-col-check">Selectare</th><th>Client / contact</th><th>Sursă</th><th>Localizare</th><th>Proiect & putere</th><th>Prioritate</th><th>Status</th><th>Agent</th><th>Actualizat</th><th>Detalii</th>
 </tr></thead><tbody>
 <?php foreach($leads as $l):
  $leadId=(int)$l['id'];$phone=preg_replace('/[^0-9+]/','',(string)($l['telefon']??''));$wa=preg_replace('/\D/','',$phone);if(strlen($wa)===10 && $wa[0]==='0')$wa='40'.substr($wa,1);
  $displayPriority=(int)($l['prioritate']??3);
  $payload=[];foreach(['id','nume','telefon','email','judet','oras_localitate','sursa','bransament','consum','proiect','putere_kw','program','tip_client','adresa','urgenta_proiect','sursa_campanie','nota_agent','nota_manager','lead_external_id','observatii','ultima_contactare','created_at','data_contact_urmator','status','prioritate','agent_id'] as $f) $payload[$f]=$l[$f]??null;
  $payloadAttr=eneron_h(json_encode($payload,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP));
 ?>
 <tr data-lead="<?=$payloadAttr?>" id="row_<?=$leadId?>">
  <td data-label="Selectează" class="crm-col-check"><input type="checkbox" class="lead_checkbox" value="<?=$leadId?>" aria-label="Selectează lead #<?=$leadId?>"></td>
  <td data-label="Client"><div class="crm-client-cell"><span class="crm-avatar"><?=eneron_h(function_exists('mb_substr')?mb_strtoupper(mb_substr((string)($l['nume']??'C'),0,1,'UTF-8')):strtoupper(substr((string)($l['nume']??'C'),0,1)))?></span><div><strong><?=eneron_h($l['nume']?:'Client fără nume')?></strong><div class="crm-meta-row"><a href="tel:<?=eneron_h($phone)?>"><?=eneron_h($phone)?></a><span>· #<?=$leadId?></span><?php if($wa):?><a class="crm-whatsapp" target="_blank" rel="noopener noreferrer" href="https://wa.me/<?=eneron_h($wa)?>">WhatsApp</a><?php endif;?></div></div></div></td>
  <td data-label="Sursă"><span class="crm-source-badge"><?=eneron_h($l['sursa']?:'Necunoscută')?></span></td>
  <td data-label="Locație"><strong><?=eneron_h($l['judet']?:'—')?></strong><div class="crm-muted"><?=eneron_h($l['oras_localitate']??'')?></div></td>
  <td data-label="Proiect"><strong><?=eneron_h(($l['putere_kw']??null)!==null && ($l['putere_kw']??'')!=='' ? $l['putere_kw'].' kW' : ($l['proiect']?:'—'))?></strong><div class="crm-muted"><?=eneron_h($l['bransament']?:'Nespecificat')?></div></td>
  <td data-label="Prioritate"><select aria-label="Prioritate lead #<?=$leadId?>" onchange="crmUpdate(<?=$leadId?>,'prioritate',this.value)"><?php foreach($priorityList as $k=>$v):?><option value="<?=$k?>" <?=$displayPriority===$k?'selected':''?>><?=eneron_h($v)?></option><?php endforeach;?></select></td>
  <td data-label="Status"><select class="crm-status-select <?=in_array((string)$l['status'],['CONTRACT','DE INSTALAT','CLOSED'],true)?'crm-status-won':''?>" aria-label="Status lead #<?=$leadId?>" onchange="crmUpdate(<?=$leadId?>,'status',this.value)"><?php foreach($st_list as $v):?><option value="<?=eneron_h($v)?>" <?=$l['status']===$v?'selected':''?>><?=eneron_h($v)?></option><?php endforeach;?></select></td>
  <td data-label="Agent"><select <?=!$en_manage?'disabled':''?> aria-label="Agent lead #<?=$leadId?>" onchange="crmUpdate(<?=$leadId?>,'agent_id',this.value)"><option value="">Nealocat</option><?php foreach($assignable_agents as $a):?><option value="<?=(int)$a['id']?>" <?=$l['agent_id']==$a['id']?'selected':''?>><?=eneron_h($a['nume'])?></option><?php endforeach;?></select></td>
  <td data-label="Actualizat"><time><?=eneron_h(!empty($l['updated_at'])?date('d.m.Y',strtotime($l['updated_at'])):'—')?></time><div class="crm-muted"><?=eneron_h(!empty($l['updated_at'])?date('H:i',strtotime($l['updated_at'])):'')?></div></td>
  <td data-label="Detalii"><button class="crm-details-button" type="button" onclick="openLead(<?=$leadId?>)">Vezi fișa</button></td>
 </tr><?php endforeach;?>
 <?php if(!$leads):?><tr><td colspan="10" class="crm-no-results">Niciun lead nu corespunde filtrelor. <a href="master_leads.php">Șterge filtrele</a>.</td></tr><?php endif;?>
 </tbody></table></div>
 <div class="crm-pagination"><div><label>Pe pagină <select id="crmPageSize" aria-label="Rânduri pe pagină"><?php foreach([30,60,100] as $n):?><option value="<?=$n?>" <?=$pageSize===$n?'selected':''?>><?=$n?></option><?php endforeach;?></select></label><span><?=min($total,$offset+1)?>–<?=min($total,$offset+$pageSize)?> din <?=$total?></span></div><nav aria-label="Paginare"><a class="crm-pager <?=$page<=1?'disabled':''?>" href="<?=eneron_h($queryPage(max(1,$page-1)))?>">← Anterior</a><span>Pagina <?=$page?> / <?=$pages?></span><a class="crm-pager <?=$page>=$pages?'disabled':''?>" href="<?=eneron_h($queryPage(min($pages,$page+1)))?>">Următor</a></nav></div>
 <?php if($en_manage):?><div class="crm-import-strip"><div><strong>Import lead-uri CSV</strong><p>CSV cu antete (toate câmpurile din Master), separator virgulă sau punct și virgulă. <a href="?csv_template=1">Descarcă șablon</a> · Importul vechi cu 10 coloane este acceptat.</p></div><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=eneron_h($en_csrf)?>"><input type="file" name="csv_file" accept=".csv,text/csv" required><button class="crm-btn crm-btn-soft" type="submit">Importă CSV</button></form></div><?php endif;?>
</section>
<dialog id="crmLeadDialog" class="crm-dialog" aria-labelledby="crmDialogTitle"><div class="crm-dialog-top"><div><span class="crm-eyebrow">FIȘĂ COMPLETĂ · MASTER</span><h2 id="crmDialogTitle">Detalii lead</h2><p id="crmDialogSub">Editare și calificare</p></div><button type="button" class="crm-close" id="crmDialogClose" aria-label="Închide">×</button></div><div class="crm-dialog-inner">
<div class="crm-detail-grid" id="crmDetailFields">
<?php
$fields=[['nume','Nume și prenume'],['telefon','Telefon'],['email','Email'],['judet','Județ'],['oras_localitate','Oraș / localitate'],['sursa','Sursa lead'],['sursa_campanie','Campanie'],['tip_client','Tip client'],['adresa','Adresă'],['bransament','Tip branșament'],['consum','Consum estimat'],['proiect','Proiect / putere estimată'],['putere_kw','Putere solicitată (kW)'],['program','Program / finanțare'],['urgenta_proiect','Când dorește să înceapă'],['data_contact_urmator','Următoarea contactare'],['nota_agent','Nota agentului'],['nota_manager','Nota managerului'],['observatii','Observații din formular'],['lead_external_id','ID extern lead'],['ultima_contactare','Ultima contactare'],['created_at','Înregistrat la']];
foreach($fields as [$key,$label]): if(!eneron_column($pdo,'leads',$key))continue;$editable=($en_manage || in_array($key,['nota_agent','data_contact_urmator'],true)) && !in_array($key,['observatii','lead_external_id','ultima_contactare','created_at'],true);?>
<label class="crm-detail-field <?=in_array($key,['nota_agent','nota_manager','observatii'],true)?'crm-field-wide':''?>"><span><?=eneron_h($label)?></span><?php if(in_array($key,['nota_agent','nota_manager','observatii'],true)):?><textarea rows="3" data-crm-field="<?=eneron_h($key)?>" <?=$editable?'':'disabled'?>></textarea><?php else:?><input type="<?=($key==='data_contact_urmator'?'datetime-local':($key==='putere_kw'?'number':'text'))?>" <?=($key==='putere_kw'?'step="0.01" min="0"':'')?> data-crm-field="<?=eneron_h($key)?>" <?=$editable?'':'disabled'?> /><?php endif;?></label>
<?php endforeach;?>
</div></div><div class="crm-dialog-footer"><span id="crmDialogMessage" role="status">Modificările sunt salvate în MySQL.</span><button class="crm-btn crm-btn-soft" id="crmDialogCancel" type="button">Închide</button><button class="crm-btn crm-btn-primary" id="crmDialogSave" type="button">Salvează modificările</button></div></dialog>
<script>
const crmCsrf=<?=json_encode($en_csrf)?>, crmDialog=document.getElementById('crmLeadDialog');
let crmActiveId=null,crmActiveValues=null;
async function crmPost(data){const p=new URLSearchParams({...data,csrf_token:crmCsrf});const r=await fetch('master_leads.php',{method:'POST',credentials:'same-origin',body:p});if(!r.ok)throw new Error(await r.text());return r;}
async function crmUpdate(id,field,value){try{await crmPost({ajax_update_lead:1,id,field,value});document.querySelector('#row_'+id)?.classList.add('crm-updated');setTimeout(()=>document.querySelector('#row_'+id)?.classList.remove('crm-updated'),1000);}catch(err){alert('Nu s-a salvat: '+err.message);location.reload();}}
function openLead(id){const tr=document.getElementById('row_'+id);if(!tr)return;crmActiveId=id;crmActiveValues=JSON.parse(tr.dataset.lead);document.getElementById('crmDialogTitle').textContent=crmActiveValues.nume||'Lead #'+id;document.getElementById('crmDialogSub').textContent='Fișa #'+id+' · '+(crmActiveValues.sursa||'Sursă necunoscută');document.querySelectorAll('[data-crm-field]').forEach(field=>{let value=crmActiveValues[field.dataset.crmField]??'';if(field.type==='datetime-local' && value)value=String(value).replace(' ','T').slice(0,16);field.value=value;});document.getElementById('crmDialogMessage').textContent='Editează câmpurile disponibile și salvează.';crmDialog.showModal();}
document.getElementById('crmDialogClose').onclick=()=>crmDialog.close();document.getElementById('crmDialogCancel').onclick=()=>crmDialog.close();
document.getElementById('crmDialogSave').onclick=async function(){if(!crmActiveValues)return;const button=this,message=document.getElementById('crmDialogMessage');button.disabled=true;message.textContent='Se salvează...';try{for(const el of document.querySelectorAll('[data-crm-field]:not(:disabled)')){const field=el.dataset.crmField,value=el.value;if(String(crmActiveValues[field]??'').replace(' ','T').slice(0,16)===String(value).slice(0,16))continue;await crmPost({ajax_update_lead:1,id:crmActiveId,field,value});}message.textContent='Salvat cu succes.';setTimeout(()=>location.reload(),350);}catch(err){message.textContent='Eroare: '+err.message;}finally{button.disabled=false;}};
const selectAll=document.getElementById('select_all');function updateCounter(){let count=0;document.querySelectorAll('.lead_checkbox').forEach(el=>{if(el.checked)count++;el.closest('tr')?.classList.toggle('crm-selected',el.checked);});document.getElementById('selected_count').textContent=count;}
selectAll?.addEventListener('change',()=>{document.querySelectorAll('.lead_checkbox').forEach(el=>el.checked=selectAll.checked);updateCounter();});document.querySelectorAll('.lead_checkbox').forEach(el=>el.addEventListener('change',updateCounter));
function crmSelected(){return [...document.querySelectorAll('.lead_checkbox:checked')].map(el=>el.value).join(',');}
async function bulkAssign(){const ids=crmSelected(),agent=document.getElementById('bulk_agent_id')?.value;if(!ids)return alert('Selectează cel puțin un lead');if(!agent)return alert('Alege un agent');try{await crmPost({bulk_assign:1,ids,agent_id:agent});location.reload();}catch(err){alert(err.message);}}
async function bulkDelete(){const ids=crmSelected();if(!ids)return alert('Selectează lead-urile');if(!confirm('Ștergi definitiv '+ids.split(',').length+' lead-uri?'))return;try{await crmPost({bulk_delete:1,ids});location.reload();}catch(err){alert(err.message);}}
document.getElementById('crmPageSize')?.addEventListener('change',function(){const u=new URL(location.href);u.searchParams.set('size',this.value);u.searchParams.set('page',1);location.href=u.toString();});
</script>
<?php if($en_manage): ?>
<dialog id="enNewLeadDialog" class="crm-dialog" aria-label="Adaugă lead manual">
  <div class="en-new-lead-panel">
<section class="en-page-heading"><div><div class="en-eyebrow">Captare / Formular intern</div><h1>Adaugă lead manual</h1><p>Introdu datele unui client venit prin telefon, recomandare sau dintr-o altă sursă. Lead-ul va apărea în Fișier Master ca nealocat.</p></div><button type="button" class="en-action en-action-outline" id="enCloseNewLead">Închide</button></section>
<?php if($newLeadError):?><div class="en-alert danger"><?=eneron_h($newLeadError)?></div><?php endif; ?>
<form method="post" class="glass-card" style="max-width:100%;"><input type="hidden" name="csrf_token" value="<?=eneron_h(eneron_csrf())?>">
<div class="en-rule-grid" style="grid-template-columns:repeat(auto-fit,minmax(250px,1fr))">
<?php $fields=[['nume','Nume client *','text'],['telefon','Telefon *','tel'],['email','Email','email'],['judet','Județ *','text'],['localitate','Localitate','text'],['sursa','Sursă lead *','text'],['bransament','Branșament','text'],['consum','Consum estimat','text'],['proiect','Proiect / lucrare','text']]; foreach($fields as [$name,$label,$type]):if($name==='localitate'&&!$hasLocality)continue;?><label class="en-rule-card" style="padding:12px;gap:6px"><span><?=eneron_h($label)?></span><input style="padding:10px" type="<?=eneron_h($type)?>" name="<?=eneron_h($name)?>" value="<?=eneron_h($_POST[$name]??($name==='sursa'?'Telefon / Recomandare':''))?>" <?=in_array($name,['nume','telefon','judet','sursa'],true)?'required':''?>></label><?php endforeach; ?>
<?php if($hasPower):?><label class="en-rule-card" style="padding:12px;gap:6px"><span>Putere (kW)</span><input type="number" step="0.01" min="0" name="putere_kw" value="<?=eneron_h($_POST['putere_kw']??'')?>"></label><?php endif; ?>
<?php if($hasProgram):?><label class="en-rule-card" style="padding:12px;gap:6px"><span>Program</span><input name="program" value="<?=eneron_h($_POST['program']??'')?>"></label><?php endif; ?>
<?php if(!empty($availableFields['tip_client'])):?><label class="en-rule-card" style="padding:12px;gap:6px"><span>Tip client</span><select name="tip_client"><option value="Persoană fizică">Persoană fizică</option><option value="Persoană juridică">Persoană juridică</option></select></label><?php endif;?>
<?php foreach(['adresa'=>'Adresă','urgenta_proiect'=>'Când dorește să înceapă proiectul','sursa_campanie'=>'Campanie / formular'] as $col=>$label):if(empty($availableFields[$col]))continue;?><label class="en-rule-card" style="padding:12px;gap:6px"><span><?=eneron_h($label)?></span><input name="<?=eneron_h($col)?>" value="<?=eneron_h($_POST[$col]??'')?>" placeholder="<?=eneron_h($label)?>"></label><?php endforeach;?>
<?php if(!empty($availableFields['data_contact_urmator'])):?><label class="en-rule-card" style="padding:12px;gap:6px"><span>Următoarea contactare</span><input type="datetime-local" name="data_contact_urmator" value="<?=eneron_h($_POST['data_contact_urmator']??'')?>"></label><?php endif;?>
<label class="en-rule-card" style="padding:12px;gap:6px"><span>Prioritate</span><select name="prioritate" style="padding:10px"><option value="0">P0 · Cât mai curând</option><option value="1">P1 · 1–3 luni</option><option value="2">P2 · 3–6 luni</option><option value="3">P3 · 6+ luni</option><option value="5">P5 · Doar informații</option><option value="7">P7 · Lead vechi</option></select></label>
</div>
<div class="en-inline-actions en-actions-bottom"><button type="submit" name="create_lead" class="en-action">Salvează în Fișier Master</button></div>
</form>

  </div>
</dialog>
<style>
.eneron-master-tabs{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:18px;padding:7px;border:1px solid var(--glass-border,#344053);border-radius:16px;background:#121b19}
.eneron-master-tabs a{display:inline-block;padding:10px 16px;border-radius:10px;color:#b9ccb8;text-decoration:none;font-weight:800;font-size:13px}
.eneron-master-tabs a.is-active{background:#1e3727;border:1px solid #45664b;color:#ecf6eb}
#enNewLeadDialog{max-width:min(960px,calc(100vw - 24px));width:100%;max-height:min(92vh,980px);overflow:auto;background:#121c19;color:#e4eee4;border:1px solid #38503c;border-radius:20px;padding:18px;box-shadow:0 28px 72px rgba(0,0,0,.65)}
#enNewLeadDialog::backdrop{background:rgba(2,9,6,.75);backdrop-filter:blur(5px)}
#enNewLeadDialog .en-page-heading{padding:14px 4px 18px!important;margin-bottom:12px!important;box-shadow:none!important;border:0!important;background:transparent!important}
#enNewLeadDialog .en-rule-card{background:#18231d!important;border:1px solid #2e4935!important}
#enNewLeadDialog input,#enNewLeadDialog select{background:#101b15!important;color:#e8f3e7!important;border:1px solid #36503d!important;min-width:0}
#enNewLeadDialog .glass-card{background:#121c19!important;box-shadow:none!important;border:0!important}
@media(max-width:700px){#enNewLeadDialog{width:calc(100vw - 12px);max-width:calc(100vw - 12px);padding:12px;border-radius:16px}#enNewLeadDialog .en-page-heading{flex-direction:column!important;align-items:stretch!important}#enNewLeadDialog .en-rule-grid{grid-template-columns:1fr!important}#enNewLeadDialog .en-rule-card{min-width:0}}
</style>
<script>
(function(){
 const dlg=document.getElementById('enNewLeadDialog');
 const close=document.getElementById('enCloseNewLead');
 function show(){if(dlg&&!dlg.open){try{dlg.showModal();}catch(e){dlg.setAttribute('open','');}}}
 window.eneronOpenNewLead=show;
 close?.addEventListener('click',()=>dlg?.close());
 dlg?.addEventListener('click',e=>{if(e.target===dlg)dlg.close()});
 if(new URLSearchParams(location.search).has('new') || <?= $newLeadError!==''?'true':'false' ?>) show();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
