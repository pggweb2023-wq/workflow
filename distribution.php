<?php
require_once __DIR__ . '/includes/session_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/eneron_core.php';
require_once __DIR__ . '/includes/eneron_email.php';
eneron_sync_session($pdo);
if (!in_array(eneron_role(), ['owner','admin'], true)) { http_response_code(403); exit('Distribuția globală este rezervată Owner-ului.'); }
$owner = in_array(eneron_role(), ['owner','admin'], true);
$installed = eneron_table($pdo, 'agent_distribution_rules');
$hasPower = eneron_column($pdo, 'leads', 'putere_kw');
$hasProgram = eneron_column($pdo, 'leads', 'program');
$msg = ''; $error = ''; $results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    eneron_csrf_check();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_rule') {
            if (!$owner || !$installed) throw new RuntimeException('Configurarea criteriilor este rezervată Owner-ului și necesită instalarea tabelului SQL.');
            $agentId = (int)($_POST['agent_id'] ?? 0);
            $s = $pdo->prepare("SELECT id FROM users WHERE id = ? AND rol IN ('team', 'manager')");
            $s->execute([$agentId]);
            if (!$s->fetchColumn()) throw new RuntimeException('Agent invalid.');
            $minRaw = trim((string)($_POST['min_kw'] ?? ''));
            $maxRaw = trim((string)($_POST['max_kw'] ?? ''));
            $min = $minRaw === '' ? null : filter_var($minRaw, FILTER_VALIDATE_FLOAT);
            $max = $maxRaw === '' ? null : filter_var($maxRaw, FILTER_VALIDATE_FLOAT);
            if (($minRaw !== '' && $min === false) || ($maxRaw !== '' && $max === false) || ($min !== null && $min < 0) || ($max !== null && $max < 0) || ($min !== null && $max !== null && $min > $max)) throw new RuntimeException('Interval de putere invalid.');
            $bransamente = eneron_limit(trim((string)($_POST['bransamente'] ?? '')),0,500);
            $programe = eneron_limit(trim((string)($_POST['programe'] ?? '')),0,500);
            $prioritati = eneron_limit(trim((string)($_POST['prioritati'] ?? '')),0,255);
            $sql = 'INSERT INTO agent_distribution_rules (user_id,putere_min_kw,putere_max_kw,bransamente,programe,prioritati) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE putere_min_kw=VALUES(putere_min_kw),putere_max_kw=VALUES(putere_max_kw),bransamente=VALUES(bransamente),programe=VALUES(programe),prioritati=VALUES(prioritati)';
            $pdo->prepare($sql)->execute([$agentId,$min,$max,$bransamente,$programe,$prioritati]);
            $msg = 'Regulile au fost salvate.';
        } elseif (in_array($action, ['preview', 'apply'], true)) {
            $ids = eneron_clean_ids($_POST['lead_ids'] ?? '', 200);
            if (!$ids) throw new RuntimeException('Selectează cel puțin un lead.');
            $results = eneron_distribute($pdo, $ids, $action === 'preview');
            if ($action === 'apply') {
                $assigned=[];
                foreach ($results as $r) {
                    if (!empty($r['agent_id']) && in_array($r['reason'],['Potrivire criterii + capacitate','Fallback către Owner'],true)) {
                        $assigned[(int)$r['agent_id']][]=(int)$r['lead_id'];
                    }
                }
                foreach ($assigned as $agentId=>$leadIds) sendStandardLeadEmail($pdo,$leadIds,$agentId);
            }
            $msg = $action === 'preview' ? 'Simulare efectuată: nicio alocare nu a fost salvată.' : 'Distribuția a fost înregistrată în Fișier Master.';
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$users = $pdo->query("SELECT u.id,u.nume,u.rol,u.judete_alocate,u.capacitate_maxima,u.status_activ,COUNT(l.id) AS assigned_today FROM users u LEFT JOIN leads l ON l.agent_id=u.id AND l.created_at>=CURDATE() AND l.created_at<CURDATE()+INTERVAL 1 DAY WHERE LOWER(u.rol) IN ('team','manager','owner','admin') GROUP BY u.id,u.nume,u.rol,u.judete_alocate,u.capacitate_maxima,u.status_activ ORDER BY CASE WHEN u.rol='team' THEN 0 WHEN u.rol='manager' THEN 1 ELSE 2 END, u.nume")->fetchAll(PDO::FETCH_ASSOC);
$rules = [];
if ($installed) foreach ($pdo->query('SELECT * FROM agent_distribution_rules')->fetchAll(PDO::FETCH_ASSOC) as $r) $rules[(int)$r['user_id']]=$r;
$unassigned = $pdo->query("SELECT id,nume,judet,bransament,prioritate,created_at FROM leads WHERE agent_id IS NULL AND (status IS NULL OR status NOT IN ('CONTRACT','DE INSTALAT','CLOSED')) ORDER BY CASE WHEN prioritate=0 THEN 0 WHEN prioritate=1 THEN 1 ELSE 2 END, id ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
include __DIR__ . '/meniu.php';
?>
<section class="en-page-heading">
 <div><div class="en-eyebrow">Operațiuni / Distribuire</div><h1>Distribuție inteligentă</h1><p>Simulează și distribuie lead-urile către agenții eligibili. Owner-ul preia automat cazurile fără potrivire.</p></div>
 <a class="en-action en-action-outline" href="master_leads.php">Deschide Fișier Master</a>
</section>
<?php if ($msg): ?><div class="en-alert success"><?=eneron_h($msg)?></div><?php endif; ?>
<?php if ($error): ?><div class="en-alert danger"><?=eneron_h($error)?></div><?php endif; ?>
<div class="en-metrics en-metrics-3">
 <article class="en-kpi"><span>În așteptare (primele 100)</span><strong><?=count($unassigned)?></strong><small>Lead-uri încă nealocate</small></article>
 <article class="en-kpi"><span>Agenți și manageri activi</span><strong><?=count(array_filter($users,static fn($u)=>$u['status_activ'] && in_array($u['rol'],['team','manager'],true)))?></strong><small>Cu reguli de capacitate și județ</small></article>
 <article class="en-kpi"><span>Reguli avansate</span><strong><?=$installed?'Active':'Neinstalate'?></strong><small>Putere, branșament, program, prioritate</small></article>
</div>
<div class="en-grid-split">
 <section class="glass-card"><div class="en-section-header"><h2>Coada de distribuție</h2><span class="en-muted">Maxim 100 lead-uri afișate</span></div>
 <form method="post" id="routeForm"><input type="hidden" name="csrf_token" value="<?=eneron_h(eneron_csrf())?>"><input type="hidden" name="lead_ids" id="routeIds">
  <div class="en-inline-actions"><label><input type="checkbox" id="routeSelectAll"> Selectează toate</label><span id="routeCounter" class="en-muted">0 selectate</span></div>
  <div class="en-table-wrap"><table class="en-table"><thead><tr><th></th><th>Lead</th><th>Județ</th><th>Branșament</th><th>Prioritate</th></tr></thead><tbody>
 <?php foreach ($unassigned as $l): ?><tr><td><input type="checkbox" class="route-check" value="<?=(int)$l['id']?>"></td><td><strong><?=eneron_h($l['nume'])?></strong><div class="en-muted">#<?=(int)$l['id']?></div></td><td><?=eneron_h($l['judet'])?></td><td><?=eneron_h($l['bransament'])?></td><td>P<?=eneron_h($l['prioritate'])?></td></tr><?php endforeach; ?>
 <?php if (!$unassigned): ?><tr><td colspan="5" class="en-empty">Nu există lead-uri în așteptare.</td></tr><?php endif; ?>
 </tbody></table></div>
 <div class="en-inline-actions en-actions-bottom"><button class="en-action en-action-outline" name="action" value="preview" type="submit">Simulează alocarea</button><button class="en-action" name="action" value="apply" type="submit" onclick="return confirm('Confirmi distribuirea lead-urilor selectate?')">Distribuie lead-urile</button></div>
 </form></section>
 <section class="glass-card"><div class="en-section-header"><h2>Capacitate agenți</h2></div><div class="en-agents-list">
 <?php foreach ($users as $u): ?><div class="en-agent-line"><div><strong><?=eneron_h($u['nume'])?></strong><small><?=eneron_h(ucfirst($u['rol']))?> · <?=eneron_h($u['judete_alocate'] ?: 'Toate județele')?></small></div><div class="en-agent-numbers"><b><?=(int)$u['assigned_today']?> / <?=in_array($u['rol'],['owner','admin'],true)?'∞':(int)$u['capacitate_maxima']?></b><span class="en-muted"><?=$u['status_activ']?'Activ':'Inactiv'?></span></div></div><?php endforeach; ?>
 </div><p class="en-muted">Sunt eligibili doar utilizatorii activi. Limita este calculată din lead-urile create azi și alocate fiecărui agent.</p></section>
</div>
<?php if ($results): ?><section class="glass-card en-top-gap"><div class="en-section-header"><h2>Rezultatul <?=($_POST['action']??'')==='preview'?'simulării':'distribuirii'?></h2></div><div class="en-table-wrap"><table class="en-table"><thead><tr><th>Lead</th><th>Destinație</th><th>Motiv</th></tr></thead><tbody><?php foreach ($results as $r): ?><tr><td>#<?=(int)$r['lead_id']?></td><td><?=eneron_h($r['agent'])?></td><td><?=eneron_h($r['reason'])?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<section class="glass-card en-top-gap"><div class="en-section-header"><div><h2>Reguli avansate pe agent</h2><p class="en-muted">Câmpurile lăsate goale acceptă orice valoare. Listele se separă prin virgulă.</p></div></div>
<?php if (!$installed): ?><div class="en-alert danger">Rulează mai întâi <code>install_distribution.sql</code> în phpMyAdmin pentru a salva reguli pe fiecare agent. Distribuirea de bază după județ și capacitate rămâne disponibilă.</div><?php endif; ?>
<?php if (!$hasPower || !$hasProgram): ?><div class="en-alert">În tabelul <code>leads</code> lipsesc momentan: <?=eneron_h(implode(', ',array_filter([!$hasPower?'putere_kw':null,!$hasProgram?'program':null])))?>. Reguli restrictive pe aceste câmpuri vor trimite lead-urile fără date suficiente către Owner. Nu convertim automat consumul în kW.</div><?php endif; ?>
<div class="en-rule-grid">
<?php foreach ($users as $u): if (in_array($u['rol'],['owner','admin'],true)) continue; $r=$rules[(int)$u['id']]??[]; ?>
<form method="post" class="en-rule-card"><input type="hidden" name="csrf_token" value="<?=eneron_h(eneron_csrf())?>"><input type="hidden" name="action" value="save_rule"><input type="hidden" name="agent_id" value="<?=(int)$u['id']?>"><h3><?=eneron_h($u['nume'])?></h3><small class="en-muted">Județe: <?=eneron_h($u['judete_alocate']?:'Toate')?></small>
<div class="en-form-grid"><label>Minim kW<input type="number" step="0.01" min="0" name="min_kw" value="<?=eneron_h($r['putere_min_kw']??'')?>"></label><label>Maxim kW<input type="number" step="0.01" min="0" name="max_kw" value="<?=eneron_h($r['putere_max_kw']??'')?>"></label></div>
<label>Branșamente<input name="bransamente" value="<?=eneron_h($r['bransamente']??'')?>" placeholder="Monofazat, Trifazat"></label>
<label>Programe<input name="programe" value="<?=eneron_h($r['programe']??'')?>" placeholder="Program A, Program B"></label>
<label>Priorități<input name="prioritati" value="<?=eneron_h($r['prioritati']??'')?>" placeholder="0, 1, 2"></label>
<?php if ($owner && $installed): ?><button type="submit" class="en-action en-action-outline">Salvează regulile</button><?php endif; ?></form>
<?php endforeach; ?></div>
</section>
<script>
const checkboxes=Array.from(document.querySelectorAll('.route-check'));
const syncSelection=()=>{const checked=checkboxes.filter(x=>x.checked);document.getElementById('routeIds').value=checked.map(x=>x.value).join(',');document.getElementById('routeCounter').textContent=checked.length+' selectate';};
checkboxes.forEach(x=>x.addEventListener('change',syncSelection));
document.getElementById('routeSelectAll').addEventListener('change',e=>{checkboxes.forEach(x=>x.checked=e.target.checked);syncSelection();});
document.getElementById('routeForm').addEventListener('submit',e=>{syncSelection();if(!document.getElementById('routeIds').value){e.preventDefault();alert('Selectează cel puțin un lead.');}});
</script>
<?php include __DIR__ . '/footer.php'; ?>
