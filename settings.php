<?php
require_once 'includes/session_check.php';
require_once 'includes/db.php';

// SECURITATE: Doar utilizatorul cu rolul de 'owner' are acces la setările de sistem
if ($_SESSION['role'] !== 'owner') {
    header("Location: dashboard.php?msg=fara_acces");
    exit();
}

// =========================================================================
// AUTO-PATCH BAZĂ DE DATE
// =========================================================================
// 1. Auto-patch pentru auto_assign_status
try { $pdo->query("SELECT auto_assign_status FROM settings LIMIT 1"); } 
catch (Exception $e) { $pdo->exec("ALTER TABLE settings ADD COLUMN auto_assign_status TINYINT(1) DEFAULT 0"); }

// 2. Auto-patch pentru setările de email dedicate partenerilor
try { $pdo->query("SELECT partner_mail_subject FROM settings LIMIT 1"); } 
catch (Exception $e) { 
    $pdo->exec("ALTER TABLE settings ADD COLUMN partner_mail_subject VARCHAR(255) DEFAULT '🚨 Lead Nou - Partener Extern'");
    $pdo->exec("ALTER TABLE settings ADD COLUMN partner_mail_body TEXT"); 
}

$msg = '';
$test_results = '';

// --- LOGICĂ SALVARE SETĂRI ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {
    $auto_assign = isset($_POST['auto_assign_status']) ? 1 : 0;

    $stmt = $pdo->prepare("UPDATE settings SET 
        smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass = ?, 
        smtp_secure = ?, mail_subject = ?, mail_body = ?, 
        sheets_url = ?, sheets_name = ?, auto_assign_status = ?,
        partner_mail_subject = ?, partner_mail_body = ? WHERE id = 1");
    
    $stmt->execute([
        $_POST['smtp_host'], (int)$_POST['smtp_port'], $_POST['smtp_user'], 
        $_POST['smtp_pass'], $_POST['smtp_secure'], $_POST['mail_subject'], $_POST['mail_body'],
        $_POST['sheets_url'], $_POST['sheets_name'], $auto_assign,
        $_POST['partner_mail_subject'], $_POST['partner_mail_body']
    ]);
    $msg = "Succes: Toate modificările și statusul algoritmului au fost salvate cu succes!";
}

// --- LOGICĂ TESTARE CONEXIUNE SMTP ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['test_smtp'])) {
    $host = $_POST['smtp_host'];
    $port = (int)$_POST['smtp_port'];
    $destinatar = trim($_POST['test_email']);

    if (empty($destinatar)) {
        $test_results = "<span style='color:#ff4444;'>Eroare: Introdu o adresă de email pentru test!</span>";
    } else {
        $connection = @fsockopen($host, $port, $errno, $errstr, 10);
        if ($connection) {
            fclose($connection);
            $test_results = "<span style='color:var(--neon-green);'>Succes: Conexiune reușită la $host:$port!</span><br>
                             <small style='color:var(--text-muted);'>Serverul răspunde corect.</small>";
        } else {
            $test_results = "<span style='color:#ff4444;'>Eroare: Eroare Conexiune: $errstr ($errno)</span>";
        }
    }
}

// --- PRELUARE DATE ACTUALE ---
$conf = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch();

include __DIR__ . '/meniu.php'; 
?>

<style>
    .settings-container { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; align-items: start; }
    .glass-card { background: rgba(255, 255, 255, 0.03); border: 1px solid var(--glass-border); border-radius: 16px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
    
    .input-group { margin-bottom: 18px; }
    .input-group label { display: block; font-size: 0.7rem; color: var(--neon-green); text-transform: uppercase; font-weight: bold; margin-bottom: 6px; letter-spacing: 1px; }
    
    .input-neon { width: 100%; background: rgba(0,0,0,0.5); border: 1px solid var(--glass-border); color: #fff; padding: 12px; border-radius: 8px; outline: none; transition: 0.3s; font-size: 0.9rem; box-sizing: border-box; }
    .input-neon:focus { border-color: var(--neon-green); box-shadow: var(--neon-glow); }
    
    textarea.input-neon { min-height: 250px; resize: vertical; line-height: 1.6; font-family: monospace; font-size: 0.85rem; }
    
    .test-section { margin-top: 25px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.05); }
    
    /* Tabel Variabile cu Scroll */
    .table-scroll-wrap { max-height: 220px; overflow-y: auto; background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); }
    .table-scroll-wrap::-webkit-scrollbar { width: 6px; }
    .table-scroll-wrap::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
    
    .var-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
    .var-table th { text-align: left; padding: 8px; border-bottom: 1px solid rgba(255,255,255,0.1); color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; position: sticky; top: -15px; background: #1a1a1a; z-index: 1;}
    .var-table td { padding: 8px; border-bottom: 1px solid rgba(255,255,255,0.05); color: #ccc; }
    
    .variable-tag { background: rgba(0, 255, 136, 0.1); border: 1px solid var(--glass-border); color: var(--neon-green); padding: 3px 6px; border-radius: 4px; font-family: monospace; font-weight: bold; }
    .variable-tag-blue { background: rgba(0, 150, 255, 0.1); border: 1px solid rgba(0, 150, 255, 0.3); color: #0096ff; padding: 3px 6px; border-radius: 4px; font-family: monospace; font-weight: bold; }

    /* Stiluri Toggle Switch Algoritm */
    .switch-algo { position: relative; display: inline-block; width: 60px; height: 30px; }
    .switch-algo input { opacity: 0; width: 0; height: 0; }
    .slider-algo { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(255,255,255,0.1); transition: .4s; border-radius: 30px; border: 1px solid var(--glass-border); }
    .slider-algo:before { position: absolute; content: ""; height: 22px; width: 22px; left: 4px; bottom: 3px; background-color: var(--text-muted); transition: .4s; border-radius: 50%; }
    input:checked + .slider-algo { background-color: rgba(0, 255, 136, 0.2); border-color: var(--neon-green); box-shadow: inset 0 0 10px rgba(0,255,136,0.3); }
    input:checked + .slider-algo:before { transform: translateX(28px); background-color: var(--neon-green); box-shadow: var(--neon-glow); }

    @media (max-width: 1100px) { .settings-container { grid-template-columns: 1fr; } }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:30px;">
    <div>
        <h1 style="margin:0;">⚙️ Setări Sistem & Campanii</h1>
        <p style="margin:5px 0 0 0; color: var(--text-muted); font-size: 0.8rem;">Configurarea comunicațiilor SMTP, a integrărilor și a template-urilor email.</p>
    </div>
    <?php if($msg): ?>
        <div style="background: rgba(0, 255, 136, 0.1); border: 1px solid var(--neon-green); color: var(--neon-green); padding: 10px 20px; border-radius: 8px; font-weight: bold;">
            <?= $msg ?>
        </div>
    <?php endif; ?>
</div>

<form method="POST">
    <div class="settings-container">
        
        <div style="display: flex; flex-direction: column; gap: 25px;">
            
            <div class="glass-card" style="border-color: var(--neon-green); box-shadow: inset 0 0 20px rgba(0,255,136,0.05);">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <h2 style="font-size: 1.2rem; color: var(--neon-green); margin-top:0; margin-bottom:5px;">🤖 Algoritm Distribuție Automată</h2>
                        <p style="margin:0; font-size: 0.75rem; color: var(--text-muted);">Alocă automat lead-urile noi către agenți. <i>Necesită un Cron Job activ.</i></p>
                    </div>
                    <label class="switch-algo">
                        <input type="checkbox" name="auto_assign_status" value="1" <?= (isset($conf['auto_assign_status']) && $conf['auto_assign_status'] == 1) ? 'checked' : '' ?>>
                        <span class="slider-algo"></span>
                    </label>
                </div>
            </div>

            <div class="glass-card">
                <h2 style="font-size: 1.1rem; color: var(--neon-green); margin-top:0; margin-bottom:20px;">📧 Server Mail (SMTP)</h2>
                
                <div class="input-group">
                    <label>Host SMTP</label>
                    <input type="text" name="smtp_host" class="input-neon" value="<?= htmlspecialchars($conf['smtp_host']) ?>" placeholder="ex: mail.domeniul-tau.ro">
                </div>
                
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div class="input-group">
                        <label>Port</label>
                        <input type="number" name="smtp_port" class="input-neon" value="<?= $conf['smtp_port'] ?>" placeholder="465 sau 587">
                    </div>
                    <div class="input-group">
                        <label>Securitate</label>
                        <select name="smtp_secure" class="input-neon">
                            <option value="tls" <?= ($conf['smtp_secure']=='tls')?'selected':'' ?>>TLS</option>
                            <option value="ssl" <?= ($conf['smtp_secure']=='ssl')?'selected':'' ?>>SSL</option>
                            <option value="none" <?= ($conf['smtp_secure']=='none')?'selected':'' ?>>Fără</option>
                        </select>
                    </div>
                </div>
                
                <div class="input-group">
                    <label>User (Email Expeditor)</label>
                    <input type="text" name="smtp_user" class="input-neon" value="<?= htmlspecialchars($conf['smtp_user']) ?>" placeholder="notificari@pggweb.ro">
                </div>
                
                <div class="input-group">
                    <label>Parolă Email / App</label>
                    <input type="password" name="smtp_pass" class="input-neon" value="<?= htmlspecialchars($conf['smtp_pass']) ?>" placeholder="******">
                </div>

                <div class="test-section">
                    <label style="font-size: 0.65rem; color: var(--text-muted);">🧪 Testează Conexiunea</label>
                    <div style="display:flex; gap:10px; margin-top:5px;">
                        <input type="email" name="test_email" class="input-neon" style="flex:1; padding: 8px;" placeholder="Email pentru test...">
                        <button type="submit" name="test_smtp" class="neon-btn" style="padding: 0 15px; font-size: 0.75rem;">Verifică</button>
                    </div>
                    <?php if($test_results): ?>
                        <div style="margin-top:10px; padding: 10px; background: rgba(0,0,0,0.3); border-radius: 6px; font-size: 0.75rem; border-left: 3px solid var(--neon-green);">
                            <?= $test_results ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="glass-card">
                <h2 style="font-size: 1.1rem; color: var(--neon-green); margin-top:0; margin-bottom:20px;">📅 Integrare Google Sheets</h2>
                <div class="input-group">
                    <label>Public URL Google Sheets</label>
                    <input type="text" name="sheets_url" class="input-neon" value="<?= htmlspecialchars($conf['sheets_url']) ?>">
                </div>
                <div class="input-group">
                    <label>Nume Tab / Foaie</label>
                    <input type="text" name="sheets_name" class="input-neon" value="<?= htmlspecialchars($conf['sheets_name']) ?>">
                </div>
            </div>

        </div>

        <div style="display: flex; flex-direction: column; gap: 25px;">
            
            <div class="glass-card">
                <h2 style="font-size: 1.1rem; color: var(--neon-green); margin-top:0; margin-bottom:20px;">📝 Șablon: LEAD NOU (Standard)</h2>
                
                <div class="input-group">
                    <label>Subiect Email</label>
                    <input type="text" name="mail_subject" class="input-neon" value="<?= htmlspecialchars($conf['mail_subject'] ?? '🚨 Lead Nou Alocat: {nume_lead}') ?>">
                </div>
                
                <div class="input-group">
                    <label>Corp Mesaj (Email Body)</label>
                    <?php 
                    $default_normal_body = "Salut {nume_agent},\n\nUn lead nou a fost alocat către tine.\nData intrării: {data_lead}\nSursa: {sursa_lead}\n\n👤 DETALII CONTACT:\nClient: {nume_lead} ({client_tip})\nTelefon: {telefon_lead}\nEmail: {email_lead}\nLocație: {localitate_lead}, {judet_lead}\nAdresa: {adresa_lead}\n\n⚡ DETALII PROIECT:\nSistem dorit: {proiect_lead}\n\nDeschide CRM-ul pentru a prelua lead-ul:\n🔗 https://workflow.pggweb.ro";
                    ?>
                    <textarea name="mail_body" class="input-neon"><?= htmlspecialchars($conf['mail_body'] ?: $default_normal_body) ?></textarea>
                </div>
                
                <div class="table-scroll-wrap">
                    <p style="margin:0 0 10px 0; font-size:0.75rem; font-weight:bold; color:var(--neon-green);">Variabile disponibile (Lead Standard):</p>
                    <table class="var-table">
                        <tr><th>Tag</th><th>Reprezintă</th></tr>
                        <tr><td><span class="variable-tag">{data_lead}</span></td><td>Data și ora (ex: 12-06-2026 14:30)</td></tr>
                        <tr><td><span class="variable-tag">{nume_agent}</span></td><td>Numele agentului / ownerului</td></tr>
                        <tr><td><span class="variable-tag">{sursa_lead}</span></td><td>Sursa (Ex: Formular Site, FB)</td></tr>
                        <tr><td><span class="variable-tag">{client_tip}</span></td><td>Fizică / Juridică</td></tr>
                        <tr><td><span class="variable-tag">{nume_lead}</span></td><td>Numele clientului complet</td></tr>
                        <tr><td><span class="variable-tag">{telefon_lead}</span></td><td>Numărul de telefon</td></tr>
                        <tr><td><span class="variable-tag">{email_lead}</span></td><td>Adresa de e-mail</td></tr>
                        <tr><td><span class="variable-tag">{judet_lead}</span></td><td>Județul completat</td></tr>
                        <tr><td><span class="variable-tag">{localitate_lead}</span></td><td>Orașul / Comuna</td></tr>
                        <tr><td><span class="variable-tag">{adresa_lead}</span></td><td>Strada și numărul</td></tr>
                        <tr><td><span class="variable-tag">{proiect_lead}</span></td><td>Detalii / Puterea sistemului dorit</td></tr>
                    </table>
                </div>
            </div>

            <div class="glass-card" style="border-color: #0096ff;">
                <h2 style="font-size: 1.1rem; color: #0096ff; margin-top:0; margin-bottom:20px;">🤝 Șablon: LEAD COMPLEX (Parteneri/Vodafone)</h2>
                
                <div class="input-group">
                    <label style="color: #0096ff;">Subiect Email</label>
                    <input type="text" name="partner_mail_subject" class="input-neon" style="border-color: rgba(0,150,255,0.3);" value="<?= htmlspecialchars($conf['partner_mail_subject'] ?? '🚨 Fișă Calificare Nouă: {partener} - {judet_lead}') ?>">
                </div>
                
                <div class="input-group">
                    <label style="color: #0096ff;">Corp Mesaj (Email Body)</label>
                    <?php 
                    $default_partner_body = "Salut {nume_agent},\n\nAvem o fișă de calificare nouă de la {partener}.\nData completării: {data_lead}\n\n👤 DATE CLIENT:\nNume: {nume_lead} ({client_tip})\nTelefon: {telefon_lead} | Email: {email_lead}\nLocație: {localitate_lead}, {judet_lead}\nAdresa: {adresa_lead}\n\n📊 COSTURI FACTURI:\nCurent: {cost_curent} RON | Gaz: {cost_gaz} RON | Altele: {cost_altele} RON\n(Total mediu: {consum_lead} RON/lună)\n\n⚡ DETALII TEHNICE CASA:\nBranșament: {bransament_lead} | Priză Pământ: {priza_pamant}\nAcoperiș: {invelitoare} ({detalii_acoperis})\nFinanțare: {finantare_lead} | Urgență: {urgenta}\n\n🔋 STOCARE & DISTANȚE:\nDorește Stocare: {stocare}\nLoc propus: {loc_baterie} (Montaj perete: {montaj_perete})\nDist. Panouri-Invertor: {dist_panouri_inv}m\nDist. Invertor-Tablou: {dist_inv_tablou}m\nDist. Invertor-Baterie: {dist_inv_bat}m\n\n📝 NOTA PARTENERULUI:\n{observatii}\n\nDeschide CRM-ul pentru a vizualiza fișa completă:\n🔗 https://workflow.pggweb.ro";
                    ?>
                    <textarea name="partner_mail_body" class="input-neon" style="border-color: rgba(0,150,255,0.3);"><?= htmlspecialchars($conf['partner_mail_body'] ?: $default_partner_body) ?></textarea>
                </div>
                
                <div class="table-scroll-wrap" style="border-color: rgba(0,150,255,0.3);">
                    <p style="margin:0 0 10px 0; font-size:0.75rem; font-weight:bold; color:#0096ff;">Toate variabilele Checklist-ului tehnic:</p>
                    <table class="var-table">
                        <tr><th>Tag Complex</th><th>Reprezintă</th></tr>
                        <tr><td><span class="variable-tag-blue">{partener}</span></td><td>Sursa partenerului (ex: Vodafone)</td></tr>
                        <tr><td><span class="variable-tag-blue">{cost_curent}</span></td><td>Factura curent (RON)</td></tr>
                        <tr><td><span class="variable-tag-blue">{cost_gaz}</span></td><td>Factura gaz (RON)</td></tr>
                        <tr><td><span class="variable-tag-blue">{cost_altele}</span></td><td>Cost încălzire/altele (RON)</td></tr>
                        <tr><td><span class="variable-tag-blue">{consum_lead}</span></td><td>TOTAL facturi (RON)</td></tr>
                        <tr><td><span class="variable-tag-blue">{bransament_lead}</span></td><td>Monofazat / Trifazat</td></tr>
                        <tr><td><span class="variable-tag-blue">{priza_pamant}</span></td><td>Priză pământ (Da/Nu/Necunoscut)</td></tr>
                        <tr><td><span class="variable-tag-blue">{invelitoare}</span></td><td>Tip acoperiș (Tablă/Țiglă/Terasă)</td></tr>
                        <tr><td><span class="variable-tag-blue">{detalii_acoperis}</span></td><td>Umbre, coșuri, orientare</td></tr>
                        <tr><td><span class="variable-tag-blue">{finantare_lead}</span></td><td>Fonduri / Casa Verde / Credit</td></tr>
                        <tr><td><span class="variable-tag-blue">{urgenta}</span></td><td>Termen de implementare dorit</td></tr>
                        <tr><td><span class="variable-tag-blue">{stocare}</span></td><td>Dorește stocare (Da/Nu/Nedecis)</td></tr>
                        <tr><td><span class="variable-tag-blue">{loc_baterie}</span></td><td>Unde se montează bateria</td></tr>
                        <tr><td><span class="variable-tag-blue">{montaj_perete}</span></td><td>Montare invertor/baterie pe perete</td></tr>
                        <tr><td><span class="variable-tag-blue">{dist_panouri_inv}</span></td><td>Distanța Panouri -> Invertor (m)</td></tr>
                        <tr><td><span class="variable-tag-blue">{dist_inv_tablou}</span></td><td>Distanța Invertor -> Tablou (m)</td></tr>
                        <tr><td><span class="variable-tag-blue">{dist_inv_bat}</span></td><td>Distanța Invertor -> Baterie (m)</td></tr>
                        <tr><td><span class="variable-tag-blue">{observatii}</span></td><td>Notițele sau următorul pas lăsat de agent</td></tr>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <div style="margin-top:30px; text-align: right; background: rgba(0, 210, 132, 0.05); padding: 25px; border-radius: 12px; border: 1px solid var(--neon-green);">
        <button type="submit" name="save_settings" class="neon-btn" style="padding: 15px 40px; font-size: 1.1rem; box-shadow: var(--neon-glow);">
            💾 Salvează Toate Configurațiile
        </button>
    </div>
</form>

<?php include __DIR__ . '/footer.php'; ?>