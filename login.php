<?php
/**
 * Eneron CRM — autentificare + deconectare, un singur endpoint.
 * Înlocuiește login.php și permite eliminarea logout.php.
 * Deconectarea se efectuează numai prin POST cu token de sesiune.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['auth_action'] ?? '') === 'logout') {
    $expected = (string)($_SESSION['logout_csrf'] ?? '');
    $received = (string)($_POST['logout_csrf'] ?? '');
    if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Cerere de deconectare invalidă. Reîncarcă platforma și încearcă din nou.');
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]);
    }
    session_destroy();
    header('Location: login.php?deconectat=1', true, 303);
    exit;
}

if (empty($_SESSION['login_csrf'])) $_SESSION['login_csrf']=bin2hex(random_bytes(32)); 
require_once 'includes/db.php';

// Dacă utilizatorul este deja logat, îl trimitem automat mai departe
if (isset($_SESSION['user_id'])) {
    // Redirecționare inteligentă bazată pe rol direct din sesiune
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'importer') {
        header("Location: master_leads.php?tab=partners");
    } else {
        header("Location: dashboard.php");
    }
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!hash_equals($_SESSION['login_csrf'], (string)($_POST['csrf_token']??''))) {http_response_code(403);exit('Sesiunea a expirat. Reîncarcă pagina.');}
    $u = trim((string)($_POST['username']??''));
    $p = (string)($_POST['parola']??'');
    $attempts=(int)($_SESSION['login_attempts']??0);
    if ($attempts>=12) {http_response_code(429);exit('Prea multe încercări în această sesiune. Încearcă mai târziu.');}
    $_SESSION['login_attempts']=$attempts+1;
    
    $s = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $s->execute([$u]); 
    $user = $s->fetch();
    
    if ($user && password_verify($p, $user['parola'])) {
        // Verificăm dacă contul este activ
        if (isset($user['status_activ']) && $user['status_activ'] == 0) {
            $error = "Acest cont este suspendat. Contactați administratorul.";
        } else {
            session_regenerate_id(true);
            unset($_SESSION['login_attempts']);
            // Construim Sesiunea COMPLETĂ
            $_SESSION['user_id'] = $user['id']; 
            $_SESSION['nume'] = $user['nume']; 
            $_SESSION['role'] = $user['rol'];
            
            // NOU: Salvăm asocierile partenerului direct în sesiune
            $_SESSION['partener_asociat'] = $user['partener_asociat'] ?? null;
            
            // Rutare inteligentă la login
            if ($user['rol'] === 'importer') {
                header("Location: master_leads.php?tab=partners"); 
            } else {
                header("Location: dashboard.php"); 
            }
            exit();
        }
    } else { 
        $error = "Utilizator sau parolă incorecte!"; 
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#080a08">
<meta name="color-scheme" content="dark">
<meta name="robots" content="noindex,nofollow">
<link rel="icon" href="media/LOGO-ENERON.png" type="image/png">
<title>Autentificare · Eneron CRM</title>
<style>
:root{color-scheme:dark;--bg:#080a08;--card:#131713;--line:#34402f;--txt:#f0f3ec;--muted:#a5b39f;--green:#83b72b}
*{box-sizing:border-box}body{margin:0;min-height:100dvh;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at 50% 0%,#1d291a 0%,#0a0d0a 44%,#070907 100%);color:var(--txt);font:14px/1.5 system-ui,-apple-system,'Segoe UI',sans-serif}
.login-shell{width:min(440px,100%)}.login-logo{position:relative;margin:0 auto 20px;width:min(245px,80vw);height:92px;overflow:hidden;background:#c4e3a6;border-radius:14px}.login-logo img{position:absolute;top:-108px;left:50%;transform:translateX(-50%);display:block;width:286px;height:286px;max-width:none;object-fit:contain}
.login-card{background:var(--card);padding:32px;border:1px solid var(--line);border-radius:21px;box-shadow:0 24px 55px rgba(0,0,0,.3)}.login-eyebrow{display:block;font-size:11px;letter-spacing:1.5px;color:#b0d082;text-transform:uppercase;font-weight:800}.login-card h1{margin:8px 0 6px;font-size:27px;letter-spacing:-1px}.login-card p{margin:0 0 24px;color:var(--muted)}.input-group{margin-bottom:16px}.input-group label{display:block;font-size:12px;font-weight:700;margin-bottom:7px;color:#c5d0bf}.login-input{display:block;width:100%;padding:13px 14px;background:#0b100b;border:1px solid #3c4938;border-radius:11px;color:#eef5e8;font:inherit;outline:none}.login-input:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(131,183,43,.12)}.password-wrap{position:relative}.password-wrap .login-input{padding-right:60px}.toggle-password{position:absolute;right:7px;top:6px;background:#202c1c;color:#d1e7be;border:1px solid #415b36;padding:6px 9px;border-radius:8px;font:inherit;cursor:pointer}.login-submit{width:100%;margin-top:7px;padding:13px 16px;background:var(--green);color:#10160b;border:1px solid var(--green);font:800 14px system-ui;border-radius:11px;cursor:pointer}.login-submit:hover{background:#a2d34f}.error-msg{background:#30201c;color:#f0b5a9;border:1px solid #6f473c;padding:12px;border-radius:10px;margin-bottom:16px}.login-footer{text-align:center;color:#788b71;font-size:12px;margin-top:20px}@media(max-width:520px){.login-card{padding:24px 20px}.login-card h1{font-size:25px}}
</style>
</head>
<body>
<main class="login-shell">
  <div class="login-logo"><img src="media/LOGO-ENERON.png" alt="Eneron"></div>
  <section class="login-card">
    <span class="login-eyebrow">Eneron Sales Workspace</span>
    <h1>Autentificare</h1>
    <p>Conectează-te pentru a gestiona lead-urile și activitatea comercială.</p>
    <?php if (!empty($error)): ?><div class="error-msg" role="alert"><?=htmlspecialchars((string)$error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></div><?php endif; ?>
    <?php if (isset($_GET['deconectat'])): ?><div class="login-eyebrow" role="status">Deconectare efectuată.</div><?php endif; ?>
    <form action="login.php" method="post">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars((string)$_SESSION['login_csrf'],ENT_QUOTES,'UTF-8')?>">
      <div class="input-group"><label for="username">Utilizator</label><input class="login-input" id="username" name="username" autocomplete="username" required autofocus></div>
      <div class="input-group"><label for="parola_input">Parolă</label><div class="password-wrap"><input class="login-input" id="parola_input" name="parola" type="password" autocomplete="current-password" required><button class="toggle-password" type="button" id="show-password" aria-pressed="false" aria-label="Afișează parola">Arată</button></div></div>
      <button class="login-submit" type="submit">Intră în platformă</button>
    </form>
  </section>
  <div class="login-footer">Eneron CRM · Acces securizat</div>
</main>
<script>
(()=>{const btn=document.getElementById('show-password'),inp=document.getElementById('parola_input');btn.addEventListener('click',()=>{const show=inp.type==='password';inp.type=show?'text':'password';btn.textContent=show?'Ascunde':'Arată';btn.setAttribute('aria-pressed',String(show));});})();
</script>
</body></html>
