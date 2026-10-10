<?php
require_once 'includes/session_check.php';
require_once 'includes/db.php';
require_once 'includes/eneron_core.php';
eneron_sync_session($pdo);

// Modificările de schemă sunt gestionate separat, nu la fiecare vizită pe pagină.
$en_csrf=eneron_csrf();

// Citim rolul LIVE din DB pentru cel logat (pt siguranta max)
$q_live = $pdo->prepare("SELECT rol FROM users WHERE id = ?");
$q_live->execute([$_SESSION['user_id']]);
$current_role = strtolower(trim($q_live->fetchColumn() ?: 'team'));

$is_team = ($current_role === 'team');
$is_importer = ($current_role === 'importer');
$is_manager = ($current_role === 'manager');
$is_owner = in_array($current_role, ['owner','admin'], true); // Owner este singurul rol suprem acum
$current_user_id = (int)$_SESSION['user_id'];

// --- LOGICA STERGERI ---
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_user'])) {
    eneron_csrf_check();
    $id_to_delete = (int)$_POST['delete_user'];
    
    if ($id_to_delete === $current_user_id) {
        die("Nu te poți șterge pe tine.");
    }
    
    if ($is_team || $is_importer) {
        die("Acces Refuzat");
    }
    
    if ($is_manager) {
        $check = $pdo->prepare("SELECT manager_id FROM users WHERE id = ?");
        $check->execute([$id_to_delete]);
        if ($check->fetchColumn() != $current_user_id) {
            die("Acces Refuzat: Acest utilizator nu face parte din echipa ta.");
        }
    }
    
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id_to_delete]);
    header("Location: agents.php?msg=Sters"); 
    exit();
}

// --- LOGICA SALVARE (ADD/EDIT) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_user'])) {
    eneron_csrf_check();
    $nume = trim($_POST['nume']);
    $telefon = trim($_POST['telefon']);
    $email = trim($_POST['email']);
    $cap = max(0, min(1000, (int)($_POST['capacitate'] ?? 5)));
    $pass = !empty($_POST['parola']) ? password_hash($_POST['parola'], PASSWORD_DEFAULT) : null;
    
    try {
        if (!empty($_POST['user_id'])) { 
            // ================= EDITARE =================
            $u_id = (int)$_POST['user_id'];
            
            if (($is_team || $is_importer) && $u_id != $current_user_id) {
                die("Acces Refuzat");
            }
            if ($is_manager && $u_id != $current_user_id) {
                $check = $pdo->prepare("SELECT manager_id FROM users WHERE id = ?");
                $check->execute([$u_id]);
                if ($check->fetchColumn() != $current_user_id) {
                    die("Acces Refuzat");
                }
            }

            $updates = ["nume = ?", "capacitate_maxima = ?", "telefon = ?", "email = ?"];
            $params = [$nume, $cap, $telefon, $email];

            if ($pass) {
                $updates[] = "parola = ?";
                $params[] = $pass;
            }

            if ($is_manager || $is_owner) {
                $updates[] = "judete_alocate = ?";
                $params[] = $_POST['judete'] ?? '';
            }

            if ($is_owner) {
                $rol_ales = strtolower(trim($_POST['rol'] ?? 'team'));
                if (!in_array($rol_ales,['owner','manager','team','importer'],true)) die('Rol invalid');
                if ($u_id === $current_user_id && !in_array($rol_ales, ['owner','admin'], true)) die('Nu îți poți elimina propriul rol Owner.');
                $m_id = !empty($_POST['manager_id']) ? $_POST['manager_id'] : null;
                $partener_ales = ($rol_ales === 'importer') ? trim($_POST['partener_asociat'] ?? null) : null;
                
                $updates[] = "rol = ?";
                $updates[] = "manager_id = ?";
                $updates[] = "partener_asociat = ?";
                
                $params[] = $rol_ales;
                $params[] = $m_id;
                $params[] = $partener_ales;
            }

            $params[] = $u_id; 
            
            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

        } else { 
            // ================= ADAUGARE NOU UTILIZATOR =================
            if ($is_team || $is_importer) die("Acces Refuzat"); 
            
            $uname = trim($_POST['username']);
            $judete = $_POST['judete'] ?? '';
            
            $rol_ales = $is_manager ? 'team' : strtolower(trim($_POST['rol'] ?? 'team'));
            if (!in_array($rol_ales,['owner','manager','team','importer'],true)) die('Rol invalid');
            if ($pass === null) die('Parola este obligatorie pentru un cont nou.');
            $m_id = $is_manager ? $current_user_id : (!empty($_POST['manager_id']) ? $_POST['manager_id'] : null);
            $partener_ales = ($rol_ales === 'importer') ? trim($_POST['partener_asociat'] ?? null) : null;
            
            $stmt = $pdo->prepare("INSERT INTO users (nume, username, parola, rol, manager_id, judete_alocate, capacitate_maxima, telefon, email, partener_asociat) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nume, $uname, $pass, $rol_ales, $m_id, $judete, $cap, $telefon, $email, $partener_ales]);
        }
        header("Location: agents.php?msg=Succes"); 
        exit();
        
    } catch (PDOException $e) {
        error_log('Eneron users save error: '.$e->getMessage()); http_response_code(500); die('Nu s-a putut salva utilizatorul. Verifică datele și încearcă din nou.');
    }
}

// AJAX Status Toggle
if (isset($_POST['ajax_status']) && !$is_team && !$is_importer) {
    eneron_csrf_check();
    $id_to_toggle = (int)$_POST['id'];
    
    if ($id_to_toggle === $current_user_id) {http_response_code(403);exit('Nu te poți suspenda pe tine.');}
    if ($is_manager) {
        $check = $pdo->prepare("SELECT manager_id FROM users WHERE id = ?");
        $check->execute([$id_to_toggle]);
        if ($check->fetchColumn() != $current_user_id) {
            exit("Acces Refuzat");
        }
    }

    $stmt = $pdo->prepare("UPDATE users SET status_activ = ? WHERE id = ?");
    $stmt->execute([($_POST['status'] ?? '0')==='1'?1:0, $id_to_toggle]); 
    exit("ok");
}

// ================= SELECTII BAZA DE DATE =================
if ($is_team || $is_importer) {
    $users = $pdo->query("SELECT u.*, m.nume as manager_nume FROM users u LEFT JOIN users m ON u.manager_id = m.id WHERE u.id = $current_user_id")->fetchAll();
} elseif ($is_manager) {
    $users = $pdo->query("SELECT u.*, m.nume as manager_nume FROM users u LEFT JOIN users m ON u.manager_id = m.id WHERE u.manager_id = $current_user_id OR u.id = $current_user_id ORDER BY u.rol ASC")->fetchAll();
} else {
    // Owner vede toți utilizatorii, ordonați logic (Owner primul, apoi Manager, Team, Importer)
    $users = $pdo->query("SELECT u.*, m.nume as manager_nume FROM users u LEFT JOIN users m ON u.manager_id = m.id ORDER BY CASE WHEN LOWER(u.rol)='owner' THEN 1 WHEN LOWER(u.rol)='manager' THEN 2 WHEN LOWER(u.rol)='team' THEN 3 WHEN LOWER(u.rol)='importer' THEN 4 ELSE 5 END")->fetchAll();
}

// Lista de manageri pentru dropdown (folosită exclusiv de Owner)
$managers_list = $is_owner ? $pdo->query("SELECT id, nume FROM users WHERE LOWER(rol) IN ('manager', 'owner')")->fetchAll() : [];

include __DIR__ . '/meniu.php'; 
?>

<style>
    .agent-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }
    .agent-card { border: 1px solid var(--glass-border); transition: 0.3s; position: relative; }
    .agent-card:hover { border-color: var(--neon-green); box-shadow: var(--neon-glow); }
    .switch { position: relative; display: inline-block; width: 40px; height: 20px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.1); border-radius: 20px; border: 1px solid var(--glass-border); transition: .4s; }
    input:checked + .slider { background: var(--neon-green); box-shadow: 0 0 10px var(--neon-green); }
    
    .modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index:1000; overflow-y:auto; padding: 20px 0; }
    .modal-content-glass { position:relative; margin: 0 auto; width:450px; max-width: 90%; border: 1px solid var(--neon-green); }
    
    .input-neon { width:100%; background:rgba(0,0,0,0.6); border:1px solid var(--glass-border); color:#fff; padding:10px; border-radius:8px; margin-bottom:12px; box-sizing: border-box; outline:none; }
    .input-neon:focus { border-color: var(--neon-green); box-shadow: 0 0 5px var(--neon-green); }
    .btn-action { font-size: 0.7rem; padding: 5px 10px; margin-top: 10px; cursor: pointer; }
    .partner-badge { background: rgba(0, 150, 255, 0.2); color: #0096ff; padding: 2px 6px; border-radius: 4px; font-size: 0.6rem; margin-left: 5px; border: 1px solid rgba(0, 150, 255, 0.3); }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:30px;">
    <h1><?= ($is_team || $is_importer) ? '👤 Profilul Meu' : '👥 Management Echipă & Parteneri' ?></h1>
    <?php if(!$is_team && !$is_importer): ?>
        <button class="neon-btn" onclick="openUserModal()">+ Adaugă Cont Nou</button>
    <?php endif; ?>
</div>

<div class="agent-grid">
    <?php foreach($users as $u): ?>
    <div class="glass-card agent-card">
        <div style="display:flex; justify-content:space-between; align-items:start;">
            <div>
                <h3 style="margin:0;"><?= htmlspecialchars($u['nume']) ?></h3>
                <div style="display: flex; align-items: center; margin-top: 4px;">
                    <span style="color:var(--neon-green); font-size: 0.65rem; font-weight:bold; text-transform: uppercase;">
                        <?= htmlspecialchars($u['rol'] ?: 'TEAM') ?>
                    </span>
                    <?php if($u['rol'] === 'importer' && !empty($u['partener_asociat'])): ?>
                        <span class="partner-badge"><?= strtoupper(htmlspecialchars($u['partener_asociat'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if(!$is_team && !$is_importer && $u['id'] != $current_user_id): ?>
                <label class="switch">
                    <input type="checkbox" <?= $u['status_activ']?'checked':'' ?> onchange="togStatus(<?= $u['id'] ?>, this.checked)">
                    <span class="slider"></span>
                </label>
            <?php else: ?>
                <span style="font-size:0.7rem; font-weight:bold; color:<?= $u['status_activ'] ? 'var(--neon-green)' : '#ff4444' ?>;">
                    <?= $u['status_activ'] ? 'ACTIV' : 'OFFLINE' ?>
                </span>
            <?php endif; ?>
        </div>
        
        <div style="margin-top:15px; font-size:0.8rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top:10px;">
            <p style="margin:5px 0; display:flex; justify-content:space-between;"><span>Telefon:</span> <span style="color:var(--text-muted);"><?= htmlspecialchars($u['telefon'] ?: 'Nesetat') ?></span></p>
            <p style="margin:5px 0; display:flex; justify-content:space-between;"><span>Email:</span> <span style="color:var(--text-muted);"><?= htmlspecialchars($u['email'] ?: 'Nesetat') ?></span></p>
            <div style="border-top: 1px solid rgba(255,255,255,0.05); margin: 8px 0;"></div>
            
            <?php if($u['rol'] !== 'importer'): ?>
                <p style="margin:5px 0; display:flex; justify-content:space-between;"><span>Manager:</span> <b style="color:#fff;"><?= htmlspecialchars($u['manager_nume'] ?: 'Owner/Fără') ?></b></p>
                <p style="margin:5px 0; display:flex; justify-content:space-between;"><span>Județe:</span> <span style="color:var(--text-muted); text-align:right; max-width:60%;"><?= htmlspecialchars($u['judete_alocate'] ?: 'Toate') ?></span></p>
                <p style="margin:5px 0; display:flex; justify-content:space-between;"><span>Limită Setată:</span> <b style="color:var(--neon-green);"><?= htmlspecialchars($u['capacitate_maxima']) ?> lead-uri/zi</b></p>
            <?php else: ?>
                <p style="margin:5px 0; display:flex; justify-content:space-between;"><span>Tip Cont:</span> <span style="color:var(--text-muted);">Platformă Externă</span></p>
            <?php endif; ?>
        </div>

        <div style="display:flex; gap:10px; margin-top:15px;">
            <button class="neon-btn btn-action" onclick='editUser(<?= htmlspecialchars(json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG), ENT_QUOTES, 'UTF-8') ?>)'>Editează</button>
            <?php if(!$is_team && !$is_importer && $u['id'] != $current_user_id): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Sigur ștergi acest utilizator? Acțiunea este definitivă.')"><input type="hidden" name="csrf_token" value="<?=eneron_h($en_csrf)?>"><button name="delete_user" value="<?=(int)$u['id']?>" type="submit" class="neon-btn btn-action" style="border-color:#ff4444;color:#ff4444">Șterge</button></form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div id="userModal" class="modal-overlay">
    <div class="glass-card modal-content-glass">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?=eneron_h($en_csrf)?>">
            <h2 id="modalTitle" style="margin-top:0; color: var(--neon-green); border-bottom: 1px solid var(--glass-border); padding-bottom: 10px;">Utilizator</h2>
            <input type="hidden" name="user_id" id="form_user_id">
            
            <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Nume Complet / Denumire Partener</label>
            <input type="text" name="nume" id="form_nume" class="input-neon" required>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div>
                    <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Telefon</label>
                    <input type="text" name="telefon" id="form_telefon" class="input-neon" placeholder="07xx xxx xxx">
                </div>
                <div>
                    <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Email</label>
                    <input type="email" name="email" id="form_email" class="input-neon" placeholder="nume@eneron.ro">
                </div>
            </div>

            <?php if(!$is_team && !$is_importer): ?>
            <div id="username_wrap">
                <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Username (Login)</label>
                <input type="text" name="username" id="form_username" class="input-neon">
            </div>
            <?php endif; ?>

            <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Parolă (lasă gol dacă nu o schimbi)</label>
            <input type="password" name="parola" id="form_parola" class="input-neon">

            <?php if($is_owner): ?>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div>
                    <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Rol Acces</label>
                    <select name="rol" id="form_rol" class="input-neon" onchange="toggleImporterOptions()">
                        <option value="team">Sales Agent</option>
                        <option value="manager">Manager Sales</option>
                        <option value="owner">Owner</option>
                        <option value="importer">Partener Extern (Importer)</option>
                    </select>
                </div>
                
                <div id="manager_wrap">
                    <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Manager Alocat</label>
                    <select name="manager_id" id="form_manager_id" class="input-neon">
                        <option value="">Fără Manager</option>
                        <?php foreach($managers_list as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['nume']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="importer_wrap" style="display:none;">
                    <label style="font-size:0.7rem; color:#0096ff; text-transform:uppercase;">Asociere Canal</label>
                    <select name="partener_asociat" id="form_partener" class="input-neon" style="border-color: #0096ff;">
                        <option value="123credit">Portal 123Credit</option>
                        <option value="vodafone">Portal Vodafone</option>
                    </select>
                </div>
            </div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;" id="limits_wrap">
                <div>
                    <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Lead-uri / Zi (Target)</label>
                    <input type="number" name="capacitate" id="form_capacitate" class="input-neon" value="5" required>
                </div>
                <?php if(!$is_team && !$is_importer): ?>
                <div>
                    <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Județe (Sales)</label>
                    <input type="text" name="judete" id="form_judete" class="input-neon" placeholder="Ex: Ilfov, Arad">
                </div>
                <?php endif; ?>
            </div>

            <button type="submit" name="save_user" class="neon-btn" style="width:100%; margin-top:15px; padding: 12px;">Salvează Modificări</button>
            <button type="button" class="neon-btn" style="width:100%; margin-top:8px; border-color:#ff4444; color:#ff4444; padding: 12px;" onclick="closeUserModal()">Anulează</button>
        </form>
    </div>
</div>

<script>
function toggleImporterOptions() {
    const rolElement = document.getElementById('form_rol');
    if(!rolElement) return;
    
    const rol = rolElement.value;
    const importerWrap = document.getElementById('importer_wrap');
    const managerWrap = document.getElementById('manager_wrap');
    const limitsWrap = document.getElementById('limits_wrap');
    
    if (rol === 'importer') {
        if(importerWrap) importerWrap.style.display = 'block';
        if(managerWrap) managerWrap.style.display = 'none';
        if(limitsWrap) limitsWrap.style.display = 'none'; 
    } else {
        if(importerWrap) importerWrap.style.display = 'none';
        if(managerWrap) managerWrap.style.display = 'block';
        if(limitsWrap) limitsWrap.style.display = 'grid';
    }
}

function openUserModal() {
    document.getElementById('form_user_id').value = '';
    document.getElementById('form_nume').value = '';
    document.getElementById('form_telefon').value = '';
    document.getElementById('form_email').value = '';
    document.getElementById('form_capacitate').value = '5';
    
    if(document.getElementById('form_username')) {
        document.getElementById('form_username').value = '';
        document.getElementById('form_username').setAttribute('required', 'required'); 
    }
    document.getElementById('form_parola').value = '';
    document.getElementById('form_parola').setAttribute('required', 'required'); 
    
    if(document.getElementById('username_wrap')) document.getElementById('username_wrap').style.display = 'block';
    
    if(document.getElementById('form_rol')) {
        document.getElementById('form_rol').value = 'team';
        toggleImporterOptions(); 
    }
    
    if(document.getElementById('form_manager_id')) document.getElementById('form_manager_id').value = '';
    if(document.getElementById('form_judete')) document.getElementById('form_judete').value = '';
    if(document.getElementById('form_partener')) document.getElementById('form_partener').value = '123credit';
    
    document.getElementById('modalTitle').innerText = 'Creare Cont Nou';
    document.getElementById('userModal').style.display = 'block';
}

function closeUserModal() {
    document.getElementById('userModal').style.display = 'none';
}

function editUser(u) {
    document.getElementById('form_user_id').value = u.id;
    document.getElementById('form_nume').value = u.nume;
    document.getElementById('form_telefon').value = u.telefon || '';
    document.getElementById('form_email').value = u.email || '';
    document.getElementById('form_capacitate').value = u.capacitate_maxima;
    
    document.getElementById('form_parola').value = ''; 
    document.getElementById('form_parola').removeAttribute('required'); 
    
    if(document.getElementById('form_username')) {
        document.getElementById('form_username').removeAttribute('required');
    }
    if(document.getElementById('username_wrap')) document.getElementById('username_wrap').style.display = 'none';
    
    if(document.getElementById('form_rol')) {
        document.getElementById('form_rol').value = u.rol;
        toggleImporterOptions(); 
    }
    
    if(document.getElementById('form_manager_id')) document.getElementById('form_manager_id').value = u.manager_id || '';
    if(document.getElementById('form_judete')) document.getElementById('form_judete').value = u.judete_alocate || '';
    if(document.getElementById('form_partener')) document.getElementById('form_partener').value = u.partener_asociat || '123credit';
    
    document.getElementById('modalTitle').innerText = 'Editează: ' + u.nume;
    document.getElementById('userModal').style.display = 'block';
}

function togStatus(id, isChecked) {
    const p = new URLSearchParams(); 
    p.append('ajax_status', '1'); 
    p.append('id', id); 
    p.append('status', isChecked ? '1' : '0'); p.append('csrf_token', <?=json_encode($en_csrf)?>);
    
    fetch('agents.php', { method: 'POST', body: p })
    .then(response => {
        if(!response.ok) { alert('Eroare la actualizarea statusului.'); location.reload(); }
    });
}
</script>

<?php include __DIR__ . '/footer.php'; ?>