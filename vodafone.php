<?php
ob_start(); // Previne erorile la redirectarea directă
require_once 'includes/session_check.php';
require_once 'includes/db.php';
require_once 'includes/eneron_core.php';
eneron_sync_session($pdo);
$en_csrf=eneron_csrf();

// Încărcăm clasele PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =========================================================================
// AUTO-PATCH BAZĂ DE DATE
// =========================================================================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS partner_leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sursa VARCHAR(100), client_tip VARCHAR(50), nume VARCHAR(255), telefon VARCHAR(50), 
        email VARCHAR(255), judet VARCHAR(100), localitate VARCHAR(100), adresa VARCHAR(255),
        cost_electricitate INT DEFAULT 0, cost_gaz INT DEFAULT 0, cost_altele INT DEFAULT 0,
        consum VARCHAR(50), bransament VARCHAR(50), priza_pamant VARCHAR(50),
        tip_invelitoare VARCHAR(50), detalii_acoperis VARCHAR(255),
        dist_panouri_invertor VARCHAR(50), dist_invertor_tablou VARCHAR(50), dist_invertor_baterie VARCHAR(50),
        doreste_stocare VARCHAR(50), loc_baterie VARCHAR(255), montaj_perete VARCHAR(10),
        finantare VARCHAR(50), urgenta_proiect VARCHAR(50), nota_agent TEXT,
        status VARCHAR(50) DEFAULT 'NEW LEAD', agent_id INT, imported_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    $checklist_columns = [
        'adresa' => 'VARCHAR(255)', 'cost_electricitate' => 'INT DEFAULT 0', 'cost_gaz' => 'INT DEFAULT 0',
        'cost_altele' => 'INT DEFAULT 0', 'priza_pamant' => 'VARCHAR(50)', 'detalii_acoperis' => 'VARCHAR(255)',
        'dist_panouri_invertor' => 'VARCHAR(50)', 'dist_invertor_tablou' => 'VARCHAR(50)',
        'dist_invertor_baterie' => 'VARCHAR(50)', 'doreste_stocare' => 'VARCHAR(50)',
        'loc_baterie' => 'VARCHAR(255)', 'montaj_perete' => 'VARCHAR(10)'
    ];
    foreach ($checklist_columns as $col_name => $col_type) {
        try { $pdo->exec("ALTER TABLE partner_leads ADD COLUMN $col_name $col_type"); } catch(Throwable $e) {}
    }
} catch (Throwable $e) {} 
// =========================================================================

// Citim securizat din baza de date rolul și partenerul
$q_sec = $pdo->prepare("SELECT rol, partener_asociat FROM users WHERE id = ?");
$q_sec->execute([$_SESSION['user_id']]);
$u_sec = $q_sec->fetch();
$check_role = strtolower(trim($u_sec['rol'] ?? 'team'));
$check_partner = strtolower(trim($u_sec['partener_asociat'] ?? ''));

// Securizare acces formular Vodafone
if (!in_array($check_role, ['owner','admin'], true) && !($check_role === 'importer' && $check_partner === 'vodafone')) {
    header("Location: master_leads.php?tab=partners&msg=fara_acces");
    exit();
}

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_lead'])) {
    eneron_csrf_check();
    
    $client_tip = trim($_POST['client_tip'] ?? 'Persoană Fizică');
    $nume = trim($_POST['nume'] ?? '');
    $telefon = trim($_POST['telefon'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $judet = trim($_POST['judet'] ?? '');
    $localitate = trim($_POST['localitate'] ?? '');
    $adresa = trim($_POST['adresa'] ?? '');
    
    $cost_electricitate = !empty($_POST['cost_electricitate']) ? (int)$_POST['cost_electricitate'] : 0;
    $cost_gaz = !empty($_POST['cost_gaz']) ? (int)$_POST['cost_gaz'] : 0;
    $cost_altele = !empty($_POST['cost_altele']) ? (int)$_POST['cost_altele'] : 0;
    $consum_total = $cost_electricitate + $cost_gaz + $cost_altele;
    
    $bransament = trim($_POST['bransament'] ?? '');
    $priza_pamant = trim($_POST['priza_pamant'] ?? '');
    
    $invelitoare = trim($_POST['invelitoare'] ?? '');
    $detalii_acoperis = trim($_POST['detalii_acoperis'] ?? '');
    
    $dist_panouri_invertor = !empty($_POST['dist_panouri_invertor']) ? trim($_POST['dist_panouri_invertor']) : '0';
    $dist_invertor_tablou = !empty($_POST['dist_invertor_tablou']) ? trim($_POST['dist_invertor_tablou']) : '0';
    $dist_invertor_baterie = !empty($_POST['dist_invertor_baterie']) ? trim($_POST['dist_invertor_baterie']) : '0';
    
    $doreste_stocare = trim($_POST['doreste_stocare'] ?? '');
    $loc_baterie = trim($_POST['loc_baterie'] ?? '');
    $montaj_perete = trim($_POST['montaj_perete'] ?? '');
    
    $finantare = trim($_POST['finantare'] ?? '');
    $urgenta = trim($_POST['urgenta'] ?? '');
    $observatii = trim($_POST['observatii'] ?? '');
    
    $cleaned_phone = preg_replace('/[^0-9+]/', '', $telefon);

    // Găsim Owner-ul pentru alocare și email
    $owner_stmt = $pdo->query("SELECT id, nume, email FROM users WHERE LOWER(rol) = 'owner' LIMIT 1");
    $owner_data = $owner_stmt->fetch(PDO::FETCH_ASSOC);
    $owner_id = $owner_data ? $owner_data['id'] : null;

    // Verificăm duplicat
    $check = $pdo->prepare("SELECT id FROM partner_leads WHERE telefon = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 1");
    $check->execute([$cleaned_phone]);

    if ($check->rowCount() > 0) {
        $msg = " Eroare: Acest număr de telefon a fost înregistrat deja în ultimele 30 de zile.";
    } else {
        try {
            // Inserare lead
            $sql = "INSERT INTO partner_leads 
                    (sursa, client_tip, nume, telefon, email, judet, localitate, adresa, 
                     cost_electricitate, cost_gaz, cost_altele, consum, 
                     bransament, priza_pamant, tip_invelitoare, detalii_acoperis, 
                     dist_panouri_invertor, dist_invertor_tablou, dist_invertor_baterie, 
                     doreste_stocare, loc_baterie, montaj_perete, 
                     finantare, urgenta_proiect, nota_agent, status, agent_id, imported_by, created_at) 
                    VALUES ('Vodafone Partener', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'NEW LEAD', ?, ?, NOW())";
            
            $ins = $pdo->prepare($sql);
            $ins->execute([
                $client_tip, $nume, $cleaned_phone, $email, $judet, $localitate, $adresa,
                $cost_electricitate, $cost_gaz, $cost_altele, $consum_total,
                $bransament, $priza_pamant, $invelitoare, $detalii_acoperis,
                $dist_panouri_invertor, $dist_invertor_tablou, $dist_invertor_baterie,
                $doreste_stocare, $loc_baterie, $montaj_perete,
                $finantare, $urgenta, $observatii, 
                $owner_id, $_SESSION['user_id']
            ]);
            
            // =========================================================
            // TRIMITE EMAIL CĂTRE OWNER PRIN PHPMailer (SMTP)
            // =========================================================
            if ($owner_data && !empty($owner_data['email'])) {
                try {
                    $settings = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch();
                    
                    // Căutăm fișierele direct în folderul includes/, unde le-ai pus tu
                    if (file_exists('includes/PHPMailer.php')) {
                        require_once 'includes/Exception.php';
                        require_once 'includes/PHPMailer.php';
                        require_once 'includes/SMTP.php';
                        
                        $mail = new PHPMailer(true);
                        
                        $mail->isSMTP();
                        $mail->Host       = $settings['smtp_host'];
                        $mail->SMTPAuth   = true;
                        $mail->Username   = $settings['smtp_user'];
                        $mail->Password   = $settings['smtp_pass']; 
                        $mail->SMTPSecure = ($settings['smtp_secure'] == 'none') ? '' : $settings['smtp_secure'];
                        $mail->Port       = $settings['smtp_port'];

                        $sender = !empty($settings['smtp_user']) ? $settings['smtp_user'] : 'no-reply@pggweb.ro';
                        $mail->setFrom($sender, 'Eneron CRM');
                        $mail->addAddress($owner_data['email']);
                        
                        $subject = !empty($settings['partner_mail_subject']) ? $settings['partner_mail_subject'] : '🚨 Checklist Nou - Vodafone';
                        $body = !empty($settings['partner_mail_body']) ? $settings['partner_mail_body'] : "Ai un lead nou de la partenerul Vodafone.";
                        
                        // DICȚIONARUL COMPLET DE VARIABILE
                        $data_curenta = date('d.m.Y H:i');
                        
                        $search_vars = [
                            '{nume_agent}', '{partener}', '{data_lead}', '{nume_lead}', '{client_tip}', 
                            '{telefon_lead}', '{email_lead}', '{localitate_lead}', '{judet_lead}', '{adresa_lead}',
                            '{cost_curent}', '{cost_gaz}', '{cost_altele}', '{consum_lead}',
                            '{bransament_lead}', '{priza_pamant}', '{invelitoare}', '{detalii_acoperis}',
                            '{finantare_lead}', '{urgenta}', '{stocare}', '{loc_baterie}', '{montaj_perete}',
                            '{dist_panouri_inv}', '{dist_inv_tablou}', '{dist_inv_bat}', '{observatii}', '{proiect_lead}'
                        ];
                        
                        $replace_vals = [
                            $owner_data['nume'] ?? 'Owner', 
                            'Vodafone', 
                            $data_curenta, 
                            $nume, 
                            $client_tip,
                            $telefon, 
                            $email, 
                            $localitate, 
                            $judet, 
                            $adresa,
                            $cost_electricitate, 
                            $cost_gaz, 
                            $cost_altele, 
                            $consum_total,
                            $bransament, 
                            $priza_pamant, 
                            $invelitoare, 
                            $detalii_acoperis,
                            $finantare, 
                            $urgenta, 
                            $doreste_stocare, 
                            $loc_baterie, 
                            $montaj_perete,
                            $dist_panouri_invertor, 
                            $dist_invertor_tablou, 
                            $dist_invertor_baterie, 
                            $observatii,
                            'Vezi Checklist' // Pentru compatibilitate
                        ];
                        
                        // Înlocuim variabilele atât în Subiect cât și în Mesaj
                        $subject = str_replace($search_vars, $replace_vals, $subject);
                        $body = str_replace($search_vars, $replace_vals, $body);

                        $mail->isHTML(true); // <--- ACTIVAT PENTRU HTML
                        $mail->Subject = $subject;
                        $mail->Body    = $body;
                        $mail->CharSet = 'UTF-8';

                        $mail->send();
                    }
                } catch (Throwable $e) {
                    // Eroare de trimitere ignorată silențios pentru a face redirecționarea
                }
            }
            
            // Redirectare directă către dashboard fără mesaj popup
            header("Location: master_leads.php?tab=partners");
            exit();
            
        } catch (Throwable $e) {
            die("<div style='color:#d6e9aa; padding:20px; background:#152115; text-align:center;'><b>Eroare Bază de Date:</b><br>" . $e->getMessage() . "</div>");
        }
    }
}

include __DIR__ . '/meniu.php';
?>

<style>
    .form-box { max-width: 850px; margin: 0 auto; }
    
    .section-title {
        font-size: 1rem;
        color: var(--neon-green);
        border-bottom: 1px solid rgba(0, 210, 132, 0.3);
        padding-bottom: 8px;
        margin-bottom: 20px;
        margin-top: 35px;
        text-transform: uppercase;
        letter-spacing: 1px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .section-title:first-child { margin-top: 0; }
    .section-number { background: rgba(0, 210, 132, 0.2); padding: 4px 10px; border-radius: 6px; font-weight: bold; }

    .input-group { margin-bottom: 18px; }
    .input-group label { display: block; font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: bold; margin-bottom: 8px; letter-spacing: 0.5px; }
    
    .input-neon { width: 100%; background: rgba(0,0,0,0.5); border: 1px solid var(--glass-border); color: #fff; padding: 12px 15px; border-radius: 8px; outline: none; transition: 0.3s; font-size: 0.95rem; box-sizing: border-box; }
    .input-neon:focus { border-color: var(--neon-green); box-shadow: var(--neon-glow); }
    
    .input-addon-wrap { position: relative; display: flex; align-items: center; }
    .input-addon-wrap .addon { position: absolute; right: 15px; color: var(--neon-green); font-size: 0.8rem; font-weight: bold; }
    .input-addon-wrap input { padding-right: 40px; }

    .radio-group { display: flex; gap: 15px; flex-wrap: wrap; }
    .radio-label { display: flex; align-items: center; gap: 6px; font-size: 0.9rem; color: #fff; cursor: pointer; }
    .radio-label input[type="radio"] { accent-color: var(--neon-green); width: 16px; height: 16px; }
    
    @media (max-width: 768px) {
        .grid-2, .grid-3 { grid-template-columns: 1fr !important; }
    }
</style>

<div class="form-box">
    <div style="margin-bottom: 30px; text-align: center;">
        <h1 style="margin:0; font-size: 1.8rem; color: var(--text-main);">Portal partener Vodafone</h1>
        <p style="margin:5px 0 0 0; font-size: 0.9rem; color: var(--text-muted);">Checklist oficial Eneron pentru sisteme fotovoltaice.</p>
    </div>

    <?php if($msg): ?>
        <div class="glass-card" style="margin-bottom: 25px; text-align: center; font-weight: bold; font-size: 1.1rem; color: var(--eneron-green-light); border-color: var(--eneron-green-border);">
            <?= $msg ?>
        </div>
    <?php endif; ?>

    <div class="glass-card" style="padding: 35px;">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?=eneron_h($en_csrf)?>">
            
            <div class="section-title"><span class="section-number">1</span> CLIENT</div>
            
            <div class="grid-2" style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px;">
                <div class="input-group">
                    <label>Tip Client</label>
                    <select name="client_tip" class="input-neon" required>
                        <option value="Persoană Fizică">Persoană Fizică</option>
                        <option value="Persoană Juridică">Persoană Juridică</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>Nume Complet</label>
                    <input type="text" name="nume" class="input-neon" required placeholder="Ex: Popescu Ion">
                </div>
            </div>

            <div class="grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="input-group">
                    <label>Contact / WA (Telefon)</label>
                    <input type="text" name="telefon" class="input-neon" required placeholder="07xxxxxxxx">
                </div>
                <div class="input-group">
                    <label>Adresă Email</label>
                    <input type="email" name="email" class="input-neon" placeholder="client@email.ro">
                </div>
            </div>

            <div class="grid-3" style="display: grid; grid-template-columns: 1fr 1fr 2fr; gap: 20px;">
                <div class="input-group">
                    <label>Județ</label>
                    <input type="text" name="judet" class="input-neon" required placeholder="Ex: Arad">
                </div>
                <div class="input-group">
                    <label>Localitate</label>
                    <input type="text" name="localitate" class="input-neon" required placeholder="Ex: Ineu">
                </div>
                <div class="input-group">
                    <label>Adresă completă</label>
                    <input type="text" name="adresa" class="input-neon" placeholder="Strada, Număr...">
                </div>
            </div>

            <div class="section-title"><span class="section-number">2</span> COSTURI ENERGIE LUNARE</div>
            
            <div class="grid-3" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                <div class="input-group">
                    <label>Electricitate (Factura/lună)</label>
                    <div class="input-addon-wrap">
                        <input type="number" name="cost_electricitate" class="input-neon" placeholder="0">
                        <span class="addon">lei</span>
                    </div>
                </div>
                <div class="input-group">
                    <label>Gaz (Cost gaz/lună)</label>
                    <div class="input-addon-wrap">
                        <input type="number" name="cost_gaz" class="input-neon" placeholder="0">
                        <span class="addon">lei</span>
                    </div>
                </div>
                <div class="input-group">
                    <label>Căldură / Altele (Cost/lună)</label>
                    <div class="input-addon-wrap">
                        <input type="number" name="cost_altele" class="input-neon" placeholder="0">
                        <span class="addon">lei</span>
                    </div>
                </div>
            </div>

            <div class="section-title"><span class="section-number">3</span> BRANȘAMENT</div>

            <div class="grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px;">
                <div class="input-group" style="margin-bottom:0;">
                    <label>Faze:</label>
                    <div class="radio-group">
                        <label class="radio-label"><input type="radio" name="bransament" value="monofazat" checked> monofazat</label>
                        <label class="radio-label"><input type="radio" name="bransament" value="trifazat"> trifazat</label>
                    </div>
                </div>
                <div class="input-group" style="margin-bottom:0;">
                    <label>Priză de pământ:</label>
                    <div class="radio-group">
                        <label class="radio-label"><input type="radio" name="priza_pamant" value="da"> da</label>
                        <label class="radio-label"><input type="radio" name="priza_pamant" value="nu"> nu</label>
                        <label class="radio-label"><input type="radio" name="priza_pamant" value="necunoscut" checked> necunoscut</label>
                    </div>
                </div>
            </div>

            <div class="section-title"><span class="section-number">4</span> ACOPERIȘ</div>

            <div class="input-group">
                <label>Tip Învelitoare:</label>
                <div class="radio-group">
                    <label class="radio-label"><input type="radio" name="invelitoare" value="tabla" checked> tablă</label>
                    <label class="radio-label"><input type="radio" name="invelitoare" value="tigla"> țiglă</label>
                    <label class="radio-label"><input type="radio" name="invelitoare" value="terasa"> terasă</label>
                    <label class="radio-label"><input type="radio" name="invelitoare" value="alt"> altul</label>
                </div>
            </div>
            <div class="input-group">
                <label>Altele (orientare, umbre, copaci, coș, antenă...):</label>
                <input type="text" name="detalii_acoperis" class="input-neon" placeholder="Ex: Orientare sud, umbră de la copac dimineața...">
            </div>

            <div class="section-title"><span class="section-number">5</span> DISTANȚE (Estimative)</div>

            <div class="grid-3" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                <div class="input-group">
                    <label>Panouri - Invertor</label>
                    <div class="input-addon-wrap">
                        <input type="number" name="dist_panouri_invertor" class="input-neon" placeholder="Ex: 10">
                        <span class="addon">m</span>
                    </div>
                </div>
                <div class="input-group">
                    <label>Invertor - Tablou</label>
                    <div class="input-addon-wrap">
                        <input type="number" name="dist_invertor_tablou" class="input-neon" placeholder="Ex: 5">
                        <span class="addon">m</span>
                    </div>
                </div>
                <div class="input-group">
                    <label>Invertor - Baterie</label>
                    <div class="input-addon-wrap">
                        <input type="number" name="dist_invertor_baterie" class="input-neon" placeholder="Ex: 2">
                        <span class="addon">m</span>
                    </div>
                </div>
            </div>

            <div class="section-title"><span class="section-number">6</span> STOCARE & AMPLASARE BATERIE</div>

            <div class="grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px;">
                <div class="input-group" style="margin-bottom:0;">
                    <label>Dorește stocare:</label>
                    <div class="radio-group">
                        <label class="radio-label"><input type="radio" name="doreste_stocare" value="da"> da</label>
                        <label class="radio-label"><input type="radio" name="doreste_stocare" value="nu"> nu</label>
                        <label class="radio-label"><input type="radio" name="doreste_stocare" value="nedecis" checked> nedecis</label>
                    </div>
                </div>
                <div class="input-group" style="margin-bottom:0;">
                    <label>Montaj pe perete:</label>
                    <div class="radio-group">
                        <label class="radio-label"><input type="radio" name="montaj_perete" value="da"> da</label>
                        <label class="radio-label"><input type="radio" name="montaj_perete" value="nu"> nu</label>
                    </div>
                </div>
            </div>
            
            <div class="input-group" style="margin-top: 15px;">
                <label>Loc propus pentru baterie/invertor:</label>
                <input type="text" name="loc_baterie" class="input-neon" placeholder="Ex: Garaj, Camera tehnică...">
            </div>

            <div class="section-title"><span class="section-number">7</span> TERMEN IMPLEMENTARE & OBSERVAȚII</div>

            <div class="grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="input-group">
                    <label>Sursă Finanțare</label>
                    <select name="finantare" class="input-neon">
                        <option value="Fonduri Proprii">Fonduri Proprii</option>
                        <option value="Credit Bancar">Credit Bancar</option>
                        <option value="Casa Verde">Programul Casa Verde</option>
                    </select>
                </div>
                
                <div class="input-group">
                    <label>Termen de implementare:</label>
                    <select name="urgenta" class="input-neon">
                        <option value="urgent (< 1 luna)">urgent (< 1 lună)</option>
                        <option value="2-3 luni">2-3 luni</option>
                        <option value="flexibil">flexibil</option>
                        <option value="doar informare">doar informare</option>
                    </select>
                </div>
            </div>

            <div class="input-group" style="margin-top:20px;">
                <label>Observații / Next Step</label>
                <textarea name="observatii" class="input-neon" rows="3" placeholder="Notează aici concluziile discuției și următorul pas..."></textarea>
            </div>

            <button type="submit" name="submit_lead" class="neon-btn" style="width: 100%; padding: 16px; margin-top: 20px; font-size: 1rem; background: rgba(0, 210, 132, 0.15); box-shadow: var(--neon-glow);">Trimite Fișa Calificare la Eneron</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>