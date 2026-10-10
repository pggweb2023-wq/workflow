<?php
require_once 'includes/session_check.php';
require_once 'includes/db.php';
require_once 'includes/eneron_core.php';
eneron_sync_session($pdo);

// Securizare: Owner/Admin au acces total, Importerul are acces doar dacă este setat pe acest partener
if (!in_array((string)($_SESSION['role']??''), ['owner','admin'], true) && !((string)($_SESSION['role']??'') === 'importer' && (string)($_SESSION['partener_asociat']??'') === '123credit')) {
    header("Location: master_leads.php?tab=partners&msg=fara_acces");
    exit();
}

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_lead'])) {
    eneron_csrf_check();
    $nume = trim($_POST['nume']);
    $telefon = trim($_POST['telefon']);
    $email = trim($_POST['email']);
    $judet = trim($_POST['judet']);
    $proiect = trim($_POST['proiect']);
    $bransament = trim($_POST['bransament']);
    $consum = trim($_POST['consum']);
    
    // Curățare număr telefon
    $cleaned_phone = preg_replace('/[^0-9+]/', '', $telefon);

    // 1. Găsim dinamic ID-ul utilizatorului care deține rolul de Owner pentru alocarea automată directă
    $owner_stmt = $pdo->query("SELECT id FROM users WHERE rol = 'owner' LIMIT 1");
    $owner_id = $owner_stmt->fetchColumn() ?: null;

    // 2. Verificăm duplicat în ultimele 30 de zile
    $check = $pdo->prepare("SELECT id FROM leads WHERE telefon = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 1");
    $check->execute([$cleaned_phone]);

    if ($check->rowCount() > 0) {
        $msg = " Eroare: Acest număr de telefon a fost deja introdus recent în sistem!";
    } else {
        // Inserare lead legat de Owner și marcat cu ID-ul importatorului
        $ins = $pdo->prepare("INSERT INTO leads (sursa, nume, telefon, email, judet, bransament, consum, proiect, prioritate, status, agent_id, imported_by, created_at) VALUES ('123Credit Partener', ?, ?, ?, ?, ?, ?, ?, '5', 'NEW LEAD', ?, ?, NOW())");
        $ins->execute([$nume, $cleaned_phone, $email, $judet, $bransament, $consum, $proiect, $owner_id, $_SESSION['user_id']]);
        $msg = " Clientul $nume a fost trimis cu succes și alocat automat către management!";
    }
}

include __DIR__ . '/meniu.php';
?>

<style>
    .form-box { max-width: 600px; margin: 0 auto; }
    .input-group { margin-bottom: 18px; }
    .input-group label { display: block; font-size: 0.7rem; color: var(--neon-green); text-transform: uppercase; font-weight: bold; margin-bottom: 6px; letter-spacing: 1px; }
    .input-neon { width: 100%; background: rgba(0,0,0,0.5); border: 1px solid var(--glass-border); color: #fff; padding: 12px; border-radius: 8px; outline: none; transition: 0.3s; font-size: 0.9rem; box-sizing: border-box; }
    .input-neon:focus { border-color: var(--neon-green); box-shadow: var(--neon-glow); }
</style>

<div class="form-box">
    <div style="margin-bottom: 30px; text-align: center;">
        <h1 style="margin:0;">Portal partener 123Credit</h1>
        <p style="margin:5px 0 0 0; font-size: 0.85rem; color: var(--text-muted);">Trimitere securizată a clienților direct în fluxul de vânzare Eneron.</p>
    </div>

    <?php if($msg): ?>
        <div class="glass-card" style="margin-bottom: 25px; text-align: center; font-weight: bold; color: var(--neon-green); border-color: var(--neon-green);">
            <?= htmlspecialchars((string)$msg, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
        </div>
    <?php endif; ?>

    <div class="glass-card">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?=eneron_h(eneron_csrf())?>">
            <div class="input-group">
                <label>Nume și Prenume Client</label>
                <input type="text" name="nume" class="input-neon" required placeholder="Ex: Ion Popescu">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="input-group">
                    <label>Telefon</label>
                    <input type="text" name="telefon" class="input-neon" required placeholder="07xxxxxxxx">
                </div>
                <div class="input-group">
                    <label>Email</label>
                    <input type="email" name="email" class="input-neon" placeholder="client@email.com">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="input-group">
                    <label>Județ Reședință</label>
                    <input type="text" name="judet" class="input-neon" required placeholder="Ex: Timis">
                </div>
                <div class="input-group">
                    <label>Putere Proiect Solicitat (kWp)</label>
                    <input type="text" name="proiect" class="input-neon" placeholder="Ex: 5.5">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="input-group">
                    <label>Tip Branșament</label>
                    <select name="bransament" class="input-neon">
                        <option value="Monofazat">Monofazat</option>
                        <option value="Trifazat">Trifazat</option>
                        <option value="Nesitat">Nu se știe</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>Consum Lunar Mediu (RON)</label>
                    <input type="text" name="consum" class="input-neon" placeholder="Ex: 350">
                </div>
            </div>

            <button type="submit" name="submit_lead" class="neon-btn" style="width: 100%; padding: 14px; margin-top: 15px; font-size: 0.9rem;">Trimite Client la Eneron Sales</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>