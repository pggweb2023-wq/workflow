<?php
require_once 'includes/session_check.php';
require_once 'includes/db.php';
require_once 'includes/eneron_core.php';
eneron_sync_session($pdo);

// Securitate: Redirecționăm agenții simpli, ei nu au voie să vadă statistici globale
if (!in_array($_SESSION['role']??'', ['owner','admin','manager'], true)) {
    header("Location: dashboard.php");
    exit();
}

$role = $_SESSION['role'];
$user_id = (int)$_SESSION['user_id'];

// --- 1. PRELUARE PARAMETRI FILTRARE (Valori implicite logice) ---
// Setăm implicit pe luna curentă pentru relevanță
$start_date   = $_GET['start_date'] ?? date('Y-m-01');
$end_date     = $_GET['end_date'] ?? date('Y-m-t');
$f_agent      = $_GET['agent'] ?? '';
$f_manager    = $_GET['manager'] ?? ''; // NOU: Filtru per manager
$f_judet      = $_GET['judet'] ?? '';
$f_status     = $_GET['status'] ?? '';
$f_prioritate = $_GET['prioritate'] ?? '';
$f_sursa      = $_GET['sursa'] ?? '';

// --- 2. LOGICĂ DE VIZIBILITATE IERARHICĂ ---
$scope_sql = "1=1"; // Default pentru Admin/Owner (văd tot)

if ($role === 'manager') {
    // Managerul vede doar ce este al lui sau al echipei sale
    $scope_sql = "(l.agent_id = $user_id OR l.agent_id IN (SELECT id FROM users WHERE manager_id = $user_id))";
}

// --- 3. CONSTRUIRE QUERY DINAMIC (Filtre cumulative) ---
$params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
$filter_sql = "";

if ($f_agent !== '') {
    $filter_sql .= " AND l.agent_id = ?";
    $params[] = $f_agent;
}

// Dacă s-a selectat un filtru de manager (disponibil doar pt Owner/Admin)
if ($f_manager !== '' && in_array($role, ['owner', 'admin'])) {
    $filter_sql .= " AND (l.agent_id = ? OR l.agent_id IN (SELECT id FROM users WHERE manager_id = ?))";
    $params[] = $f_manager;
    $params[] = $f_manager;
}

if ($f_judet !== '') {
    $filter_sql .= " AND l.judet = ?";
    $params[] = $f_judet;
}
if ($f_status !== '') {
    $filter_sql .= " AND l.status = ?";
    $params[] = $f_status;
}
if ($f_prioritate !== '') {
    $filter_sql .= " AND l.prioritate = ?";
    $params[] = $f_prioritate;
}
if ($f_sursa !== '') {
    $filter_sql .= " AND l.sursa = ?";
    $params[] = $f_sursa;
}

$base_where = "WHERE l.created_at >= ? AND l.created_at <= ? AND $scope_sql $filter_sql";

// --- 4. EXECUTARE INTEROGĂRI STATISTICE ---
// A. Total Leads (în intervalul și filtrele alese)
$q_total = $pdo->prepare("SELECT COUNT(*) FROM leads l $base_where");
$q_total->execute($params);
$total_leads = $q_total->fetchColumn();

// B. Contracte Închise (Succes)
$q_closed = $pdo->prepare("SELECT COUNT(*) FROM leads l $base_where AND l.status IN ('CONTRACT', 'DE INSTALAT', 'CLOSED')");
$q_closed->execute($params);
$closed_deals = $q_closed->fetchColumn();

// Rata de închidere medie
$win_rate = $total_leads > 0 ? round(($closed_deals / $total_leads) * 100, 1) : 0;

// C. Distribuție Status
$q_status = $pdo->prepare("SELECT l.status, COUNT(*) as cnt FROM leads l $base_where GROUP BY l.status ORDER BY cnt DESC");
$q_status->execute($params);
$status_stats = $q_status->fetchAll();

// D. Performanță Agenți (Clasament detaliat)
$q_agents = $pdo->prepare("
    SELECT u.nume, 
           COUNT(l.id) as total_leads, 
           SUM(CASE WHEN l.status IN ('CONTRACT', 'DE INSTALAT', 'CLOSED') THEN 1 ELSE 0 END) as closed_deals
    FROM leads l 
    LEFT JOIN users u ON l.agent_id = u.id 
    $base_where AND l.agent_id IS NOT NULL
    GROUP BY l.agent_id 
    ORDER BY closed_deals DESC, total_leads DESC
");
$q_agents->execute($params);
$agent_stats = $q_agents->fetchAll();

// E. NOU: Analiză pe Județe (Câte lead-uri / Câte închise per județ)
$q_county = $pdo->prepare("
    SELECT l.judet, 
           COUNT(l.id) as total_leads, 
           SUM(CASE WHEN l.status IN ('CONTRACT', 'DE INSTALAT', 'CLOSED') THEN 1 ELSE 0 END) as closed_deals
    FROM leads l 
    $base_where AND l.judet IS NOT NULL AND l.judet != ''
    GROUP BY l.judet 
    ORDER BY total_leads DESC
    LIMIT 10
");
$q_county->execute($params);
$county_stats = $q_county->fetchAll();

// --- 5. PREGĂTIRE LISTE PENTRU DROPDOWN-URI FILTRE ---
// 5.1 Lista agenți permisă pentru utilizatorul curent
$ag_query = "SELECT id, nume FROM users WHERE rol IN ('team', 'manager')";
if ($role === 'manager') {
    $ag_query .= " AND (id = $user_id OR manager_id = $user_id)";
}
$agents_list = $pdo->query($ag_query)->fetchAll();

// 5.2 NOU: Lista Manageri (Doar pentru Owner/Admin)
$managers_list = [];
if (in_array($role, ['owner', 'admin'])) {
    $managers_list = $pdo->query("SELECT id, nume FROM users WHERE rol = 'manager'")->fetchAll();
}

// 5.3 Liste dinamice din baza de date
$judete_list = $pdo->query("SELECT DISTINCT judet FROM leads l WHERE $scope_sql AND judet IS NOT NULL AND judet != '' ORDER BY judet")->fetchAll(PDO::FETCH_COLUMN);
$surse_list  = $pdo->query("SELECT DISTINCT sursa FROM leads l WHERE $scope_sql AND sursa IS NOT NULL AND sursa != '' ORDER BY sursa")->fetchAll(PDO::FETCH_COLUMN);

// 5.4 Liste statice predefinite
$st_list = eneron_statuses();
$pr_list = ['0'=>'P0-Curând', '1'=>'P1-1-3 luni', '2'=>'P2-3-6 luni', '3'=>'P3-6+ luni', '5'=>'P5-Info', '7'=>'P7-Vechi'];

include __DIR__ . '/meniu.php'; 
?>
<style>
    .filter-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; align-items: flex-end; background: rgba(0,255,136,0.03); padding: 20px; border-radius: 12px; border: 1px solid var(--glass-border); margin-bottom: 30px; box-shadow: inset 0 0 20px rgba(0,0,0,0.5); }
    .filter-group { display: flex; flex-direction: column; gap: 5px; }
    .filter-group label { font-size: 0.65rem; color: var(--neon-green); text-transform: uppercase; font-weight: bold; letter-spacing: 1px; }
    .filter-input { background: #000; color: #fff; border: 1px solid #333; padding: 10px; border-radius: 6px; outline: none; font-size: 0.8rem; width: 100%; box-sizing: border-box; }
    .filter-input:focus { border-color: var(--neon-green); box-shadow: var(--neon-glow); }
    
    .stat-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.05); align-items: center; }
    .stat-row:last-child { border-bottom: none; }
    
    table.perf-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    .perf-table th { text-align: left; padding: 12px 10px; font-size: 0.65rem; color: var(--neon-green); border-bottom: 1px solid var(--glass-border); text-transform: uppercase; letter-spacing: 1px;}
    .perf-table td { padding: 12px 10px; border-bottom: 1px solid rgba(255,255,255,0.02); font-size: 0.85rem; }
    .progress-bg { background: rgba(0,0,0,0.5); height: 6px; border-radius: 3px; width: 100%; overflow: hidden; margin-top: 5px; border: 1px solid #222; }
    .progress-bar { height: 100%; background: var(--neon-green); box-shadow: var(--neon-glow); }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <div>
        <h1 style="margin:0;">Statistici & filtre avansate</h1>
        <p style="margin:5px 0 0 0; font-size: 0.8rem; color: var(--text-muted);">
            Rapoarte detaliate bazate pe data de intrare a lead-urilor.
        </p>
    </div>
</div>

<form method="GET" class="filter-bar">
    <div class="filter-group">
        <label>De la Data</label>
        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="filter-input">
    </div>
    <div class="filter-group">
        <label>Până la Data</label>
        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="filter-input">
    </div>
    
    <?php if (in_array($role, ['owner', 'admin'])): ?>
    <div class="filter-group">
        <label>Echipă Manager</label>
        <select name="manager" class="filter-input">
            <option value="">Toate Echipele</option>
            <?php foreach($managers_list as $m): ?>
                <option value="<?= $m['id'] ?>" <?= ($f_manager == $m['id']) ? 'selected' : '' ?>><?= htmlspecialchars($m['nume']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <div class="filter-group">
        <label>Agent Alocat</label>
        <select name="agent" class="filter-input">
            <option value="">Toți Agenții</option>
            <?php foreach($agents_list as $a): ?>
                <option value="<?= $a['id'] ?>" <?= ($f_agent == $a['id']) ? 'selected' : '' ?>><?= htmlspecialchars($a['nume']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Status Lead</label>
        <select name="status" class="filter-input">
            <option value="">Toate Statusurile</option>
            <?php foreach($st_list as $s): ?>
                <option value="<?= $s ?>" <?= ($f_status === $s) ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Prioritate</label>
        <select name="prioritate" class="filter-input">
            <option value="">Toate</option>
            <?php foreach($pr_list as $k => $v): ?>
                <option value="<?= $k ?>" <?= ($f_prioritate === (string)$k) ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Județ</label>
        <select name="judet" class="filter-input">
            <option value="">Toate Județele</option>
            <?php foreach($judete_list as $j): ?>
                <option value="<?= htmlspecialchars($j) ?>" <?= ($f_judet == $j) ? 'selected' : '' ?>><?= htmlspecialchars($j) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Sursă Lead</label>
        <select name="sursa" class="filter-input">
            <option value="">Toate Sursele</option>
            <?php foreach($surse_list as $sr): ?>
                <option value="<?= htmlspecialchars($sr) ?>" <?= ($f_sursa == $sr) ? 'selected' : '' ?>><?= htmlspecialchars($sr) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group" style="display: flex; flex-direction: row; gap: 10px; align-items: flex-end;">
        <button type="submit" class="neon-btn" style="flex: 1; padding: 10px;">Aplică Filtre</button>
        <a href="statistics.php" class="neon-btn" style="flex: 1; text-align:center; text-decoration:none; border-color: #ff4444; color: #ff4444; padding: 10px;">Reset</a>
    </div>
</form>

<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <div class="glass-card" style="text-align:center; padding: 30px 20px;">
        <h2 style="font-size: 2.5rem; margin:0; color: #fff;"><?= $total_leads ?></h2>
        <p style="font-size:0.75rem; color:var(--text-muted); font-weight:bold; letter-spacing:1px; margin-top:10px;">LEAD-URI GĂSITE</p>
    </div>
    <div class="glass-card" style="text-align:center; padding: 30px 20px; border-color: var(--neon-green); box-shadow: inset 0 0 20px rgba(0,255,136,0.05);">
        <h2 style="font-size: 2.5rem; margin:0; color: var(--neon-green); text-shadow: var(--neon-glow);"><?= $win_rate ?>%</h2>
        <p style="font-size:0.75rem; color:var(--text-muted); font-weight:bold; letter-spacing:1px; margin-top:10px;">RATĂ MEDIE DE ÎNCHIDERE</p>
    </div>
    <div class="glass-card" style="text-align:center; padding: 30px 20px;">
        <h2 style="font-size: 2.5rem; margin:0; color: #fff;"><?= $closed_deals ?></h2>
        <p style="font-size:0.75rem; color:var(--text-muted); font-weight:bold; letter-spacing:1px; margin-top:10px;">CONTRACTE FINALIZATE</p>
    </div>
</div>

<div style="display:grid; grid-template-columns: 1.5fr 1fr; gap: 30px;">
    
    <div style="display: flex; flex-direction: column; gap: 30px;">
        <div class="glass-card">
            <h2 style="font-size: 1.1rem; color: var(--neon-green); margin-top:0;">Performanța agenților</h2>
            <table class="perf-table">
                <thead>
                    <tr>
                        <th>Nume Agent</th>
                        <th style="text-align:center;">Total Lead-uri</th>
                        <th style="text-align:center;">Contracte Închise</th>
                        <th style="text-align:right;">Rată Conversie</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($agent_stats)): ?>
                        <tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding: 20px;">Nu există date pentru filtrele selectate.</td></tr>
                    <?php endif; ?>
                    
                    <?php foreach($agent_stats as $astat): 
                        $agent_rate = $astat['total_leads'] > 0 ? round(($astat['closed_deals'] / $astat['total_leads']) * 100, 1) : 0;
                    ?>
                    <tr>
                        <td><strong style="color:#fff;"><?= htmlspecialchars($astat['nume'] ?? 'Agent Șters/Nealocat') ?></strong></td>
                        <td style="text-align:center;"><?= $astat['total_leads'] ?></td>
                        <td style="text-align:center; color: var(--neon-green); font-weight: bold; font-size:1rem;"><?= $astat['closed_deals'] ?></td>
                        <td style="text-align:right;">
                            <span style="font-weight:bold;"><?= $agent_rate ?>%</span>
                            <div class="progress-bg">
                                <div class="progress-bar" style="width: <?= $agent_rate ?>%;"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="glass-card">
            <h2 style="font-size: 1.1rem; color: var(--neon-green); margin-top:0;">Top 10 județe · volum și conversie</h2>
            <table class="perf-table">
                <thead>
                    <tr>
                        <th>Județ</th>
                        <th style="text-align:center;">Lead-uri</th>
                        <th style="text-align:center;">Contracte Închise</th>
                        <th style="text-align:right;">Conversie Județ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($county_stats)): ?>
                        <tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding: 20px;">Nu există date pentru județe.</td></tr>
                    <?php endif; ?>

                    <?php foreach($county_stats as $cstat): 
                        $county_rate = $cstat['total_leads'] > 0 ? round(($cstat['closed_deals'] / $cstat['total_leads']) * 100, 1) : 0;
                    ?>
                    <tr>
                        <td><strong style="color:#fff;"><?= htmlspecialchars($cstat['judet']) ?></strong></td>
                        <td style="text-align:center;"><?= $cstat['total_leads'] ?></td>
                        <td style="text-align:center; color: var(--neon-green);"><?= $cstat['closed_deals'] ?></td>
                        <td style="text-align:right;">
                            <span style="font-weight:bold; color:var(--text-muted);"><?= $county_rate ?>%</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="glass-card">
        <h2 style="font-size: 1.1rem; color: var(--neon-green); margin-top:0;">Distribuția statusurilor</h2>
        <div style="margin-top:20px;">
            <?php if(empty($status_stats)): ?>
                <p style="text-align:center; color:var(--text-muted);">Fără lead-uri în acest interval.</p>
            <?php endif; ?>
            
            <?php foreach($status_stats as $s): 
                $status_pct = $total_leads > 0 ? round(($s['cnt'] / $total_leads) * 100, 1) : 0;
            ?>
                <div class="stat-row">
                    <div style="flex:1;">
                        <span style="font-size: 0.85rem; font-weight:bold;"><?= htmlspecialchars($s['status']) ?></span>
                        <div class="progress-bg" style="height:4px; margin-top:4px;">
                            <div class="progress-bar" style="width: <?= $status_pct ?>%; background: #94a3b8; box-shadow: none;"></div>
                        </div>
                    </div>
                    <div style="text-align:right; margin-left:15px;">
                        <strong style="color: var(--neon-green); font-size:1.1rem;"><?= $s['cnt'] ?></strong><br>
                        <span style="font-size:0.65rem; color:var(--text-muted);"><?= $status_pct ?>%</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<?php include __DIR__ . '/footer.php'; ?>