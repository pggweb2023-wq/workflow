<?php
/** Eneron CRM · navigare și layout în rădăcină */
// Token dedicat deconectării; generat când afișăm meniul după autentificare.
if (empty($_SESSION['logout_csrf'])) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}
$current_page = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
$menu_role = strtolower(trim((string)($_SESSION['role'] ?? 'team')));
$menu_partner = strtolower(trim((string)($_SESSION['partener_asociat'] ?? '')));
if (isset($pdo) && !empty($_SESSION['user_id'])) {
    try {
        $ms = $pdo->prepare('SELECT rol, partener_asociat, nume FROM users WHERE id = ? LIMIT 1');
        $ms->execute([(int)$_SESSION['user_id']]);
        if ($mr = $ms->fetch(PDO::FETCH_ASSOC)) {
            $menu_role = strtolower(trim((string)($mr['rol'] ?? $menu_role)));
            $menu_partner = strtolower(trim((string)($mr['partener_asociat'] ?? $menu_partner)));
            $menu_name = trim((string)($mr['nume'] ?? ''));
        }
    } catch (Throwable $e) {}
}
$menu_name = $menu_name ?? (string)($_SESSION['nume'] ?? 'Utilizator');
$menu_owner = in_array($menu_role, ['owner','admin'], true);
$menu_manager = in_array($menu_role, ['owner','admin','manager'], true);
$menu_role_labels = ['owner'=>'Owner','admin'=>'Administrator','manager'=>'Manager','team'=>'Agent vânzări','importer'=>'Partener'];
$menu_counts = ['new'=>null,'unassigned'=>null,'followups'=>null];
if (isset($pdo) && function_exists('eneron_scoped_where') && $menu_role !== 'importer') {
    try {
        [$menuScope,$menuScopeParams] = eneron_scoped_where();
        $q = $pdo->prepare("SELECT COUNT(*) FROM leads l WHERE $menuScope AND (l.status='NEW LEAD' OR l.status IS NULL OR l.status='')");
        $q->execute($menuScopeParams);
        $menu_counts['new'] = (int)$q->fetchColumn();
        if ($menu_owner || $menu_manager) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM leads l WHERE $menuScope AND l.agent_id IS NULL AND COALESCE(l.status,'') NOT IN ('CONTRACT','DE INSTALAT','CLOSED','ALT INSTALATOR','LASAT')");
            $q->execute($menuScopeParams);
            $menu_counts['unassigned'] = (int)$q->fetchColumn();
        }
        if (function_exists('eneron_column') && eneron_column($pdo,'leads','data_contact_urmator')) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM leads l WHERE $menuScope AND l.data_contact_urmator IS NOT NULL AND l.data_contact_urmator <= NOW() AND COALESCE(l.status,'') NOT IN ('CONTRACT','DE INSTALAT','CLOSED','ALT INSTALATOR','LASAT')");
            $q->execute($menuScopeParams);
            $menu_counts['followups'] = (int)$q->fetchColumn();
        }
    } catch (Throwable $e) {}
}
$menu_items = [];
if ($menu_role === 'importer') {
    // Pentru parteneri există un singur punct de intrare: istoric + formularul lor.
    $menu_items[] = ['Partener Eneron', [
        ['master_leads.php?tab=partners', 'Lead-uri parteneri', 'layers', null],
    ]];
} else {
    // Meniul urmează strict schema comercială, nu organizarea fișierelor PHP.
    $menu_items[] = ['Workflow Eneron', [
        ['dashboard.php', 'Dashboard', 'grid', null],
        ['master_leads.php', 'Fișier Master', 'users', $menu_counts['new']],
        ...($menu_owner ? [['distribution.php', 'Distribuție', 'arrows', $menu_counts['unassigned']]] : []),
        ['agent_workspace.php', 'Agenți & follow-up', 'check', $menu_counts['followups']],
        ...($menu_owner ? [['master_leads.php?tab=partners', 'Lead-uri parteneri', 'layers', null]] : []),
    ]];
}
$menu_page_label = 'Workflow Eneron';
foreach ($menu_items as $group) {
    foreach ($group[1] as $nav) {
        if (($current_page === basename((string)parse_url($nav[0], PHP_URL_PATH)) && (($menu_role==='importer') || $nav[0]==='master_leads.php' || (($_GET['tab'] ?? '')==='partners') || $current_page!=='master_leads.php'))) $menu_page_label = $nav[1];
    }
}
// Pagini operaționale deschise din module; nu creează intrări noi în sidebar.
$menu_page_label = match ($current_page) {
    'master_leads.php' => (($_GET['tab'] ?? '')==='partners' || $menu_role==='importer') ? 'Lead-uri parteneri' : 'Fișier Master',
    'agents.php' => 'Agenți & follow-up / Echipă',
    'statistics.php' => 'Dashboard / Rezultate',
    'vodafone.php' => 'Lead-uri parteneri / Vodafone',
    '123credit.php' => 'Lead-uri parteneri / 123Credit',
    'settings.php' => 'Configurare internă',
    default => $menu_page_label,
};
if (!function_exists('eneron_nav_icon')) {
    function eneron_nav_icon(string $name): string {
        $paths = [
            'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'users'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'check'=>'<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18m-12 5 2 2 4-4"/>',
            'chart'=>'<path d="M3 3v18h18M7 16l4-5 4 3 5-7"/>',
            'arrows'=>'<path d="M4 6h16m-5-4 5 4-5 4M20 18H4m5-4-5 4 5 4"/>',
            'plus'=>'<path d="M12 5v14M5 12h14"/>',
            'layers'=>'<path d="m12 2 9 5-9 5-9-5 9-5ZM3 12l9 5 9-5M3 17l9 5 9-5"/>',
            'bolt'=>'<path d="m13 2-3 9H4l8 11 3-9h5L13 2Z"/>',
            'file'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Zm0 0v6h6M8 13h8M8 17h8"/>',
            'people'=>'<circle cx="12" cy="8" r="4"/><path d="M4 22v-2a8 8 0 0 1 16 0v2"/>',
            'settings'=>'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06-2 2-.06-.06A1.65 1.65 0 0 0 16 18.5l-.13.05L15 21H9l-.87-2.45L8 18.5a1.65 1.65 0 0 0-1.77.32l-.06.06-2-2 .06-.06A1.65 1.65 0 0 0 4.6 15L2 14v-4l2.6-1A1.65 1.65 0 0 0 4.27 7.2l-.06-.06 2-2 .06.06A1.65 1.65 0 0 0 8 5.5l.13-.05L9 3h6l.87 2.45L16 5.5a1.65 1.65 0 0 0 1.77-.32l.06-.06 2 2-.06.06A1.65 1.65 0 0 0 19.4 9l2.6 1v4z"/>',
            'shield'=>'<path d="m12 22 8-5V6l-8-4-8 4v11l8 5Zm-3-10 2 2 4-4"/>',
            'help'=>'<circle cx="12" cy="12" r="10"/><path d="M9.5 9a2.5 2.5 0 0 1 5 0c0 2-2.5 2.5-2.5 4M12 17h.01"/>',
            'menu'=>'<path d="M4 7h16M4 12h16M4 17h16"/>',
            'close'=>'<path d="M5 5 19 19M19 5 5 19"/>',
            'logout'=>'<path d="M10 17l5-5-5-5m5 5H3M12 3h6a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3h-6"/>',
            'chevron'=>'<path d="m9 18 6-6-6-6"/>',
            'bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
            'search'=>'<circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/>',
        ];
        return '<svg class="en-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.($paths[$name]??$paths['grid']).'</svg>';
    }
}
if (!function_exists('eneron_menu_escape')) { function eneron_menu_escape($str): string { return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
$initials = implode('',array_slice(preg_split('/\s+/u',trim($menu_name)) ?: [], 0, 2));
$initials = function_exists('mb_substr') ? mb_substr($initials,0,2,'UTF-8') : substr($initials,0,2);
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#080a08">
<meta name="color-scheme" content="dark">
<meta name="robots" content="noindex,nofollow">
<title>Eneron CRM · Platformă de vânzări</title>
<link rel="icon" type="image/png" href="media/LOGO-ENERON.png">
<style>
/* Eneron CRM — tema unificată, fără fișiere CSS externe. */
:root{color-scheme:dark;--bg:#080a08;--sidebar:#0b0e0b;--card:#131713;--card2:#171c17;--field:#0c100c;--edge:#2c352c;--edge2:#3a4939;--txt:#f0f3eb;--sub:#a8b3a4;--dim:#7c8878;--brand:#83b72b;--brand-hover:#a5d350;--danger:#e39b91;--warning:#dbb76b;--good:#a7d47b;--glass-bg:var(--card);--glass-border:var(--edge);--bg-main:var(--bg);--text-main:var(--txt);--text-muted:var(--sub);--neon-green:var(--brand);--neon-glow:0 0 0 3px rgba(131,183,43,.17);--panel:var(--card);--radius:17px}
*,*::before,*::after{box-sizing:border-box}
html{font-size:15px;scroll-behavior:smooth;background:var(--bg)}
body{margin:0!important;background:var(--bg)!important;color:var(--txt)!important;font:400 14px/1.55 system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif!important;display:flex!important;min-height:100vh!important;height:100dvh;overflow:hidden!important}
a{text-decoration:none;color:#b9da8c}a:hover{color:#d5f1b5}
h1,h2,h3,h4,h5,h6{color:var(--txt);line-height:1.2;letter-spacing:-.025em}p{color:var(--sub)}small,.en-muted,.crm-muted{color:var(--sub)}
button,input,select,textarea{font:inherit}
button{cursor:pointer}
input[type=checkbox],input[type=radio]{width:16px!important;height:16px!important;accent-color:var(--brand)!important;padding:0!important;min-height:0!important}
input:not([type=checkbox]):not([type=radio]):not([type=hidden]),select,textarea,.filter-input,.input-neon{width:100%;max-width:100%;min-height:41px;background:var(--field)!important;color:var(--txt)!important;border:1px solid var(--edge2)!important;border-radius:10px!important;padding:10px 12px!important;box-shadow:none!important}
input[type=date],input[type=datetime-local]{color-scheme:dark}input::placeholder,textarea::placeholder{color:#879285!important}
select option{background:#0f130f;color:var(--txt)}textarea{resize:vertical;min-height:88px}
:focus-visible{outline:2px solid var(--brand)!important;outline-offset:2px}
input:focus,select:focus,textarea:focus{border-color:var(--brand)!important;box-shadow:0 0 0 3px rgba(131,183,43,.12)!important;outline:0!important}
button:disabled,.disabled{opacity:.5;cursor:not-allowed}
[hidden]{display:none!important}
*{scrollbar-width:thin;scrollbar-color:#40513c #0b0e0b}
::-webkit-scrollbar{width:9px;height:9px}::-webkit-scrollbar-thumb{background:#3a4a36;border-radius:99px}::-webkit-scrollbar-track{background:#0b0e0b}
.en-icon{width:19px;height:19px;display:block;flex:none}
/* Structura unică: meniu.php și footer.php în rădăcină */
.sidebar.en-v8-sidebar{width:258px!important;min-width:258px!important;max-width:258px;background:var(--sidebar)!important;border-right:1px solid #263126!important;box-shadow:none!important;display:flex;flex-direction:column;position:relative;z-index:35;overflow:hidden;flex-shrink:0}
.en-v8-sidebar-head{padding:18px 14px 8px;position:relative}
.en-brand.en-brand-v8{padding:9px 10px!important;display:flex!important;align-items:center!important;justify-content:center;background:#161c14!important;border:1px solid var(--edge)!important;border-radius:15px!important;min-height:78px!important}
.en-v8-brand-mark{display:block;position:relative;overflow:hidden;padding:0;background:#c4e3a6!important;border-radius:10px;width:100%;height:55px;min-height:55px}
.en-v8-brand-mark img{position:absolute;left:50%;top:-79px;display:block;width:210px!important;min-width:210px!important;max-width:none!important;height:210px!important;max-height:none!important;object-fit:contain;transform:translateX(-50%);filter:none!important}
.en-v8-sidebar-label{display:flex;align-items:center;gap:8px;margin-top:13px;padding:9px 10px;border:1px solid var(--edge);border-radius:12px;background:#121712;color:#aab6a5;font-size:10px;letter-spacing:.85px;text-transform:uppercase;font-weight:750;white-space:nowrap}
.en-v8-live,.en-footer-dot{width:7px;height:7px;display:inline-block;flex:none;background:var(--brand);border-radius:50%}
.en-v8-version{margin-left:auto;background:#243020;border:1px solid #42563a;color:#bfdf9a;padding:3px 7px;font-size:10px;font-weight:750;border-radius:7px}
.sidebar-menu.en-v8-nav{flex:1;overflow-y:auto;padding:12px 10px 14px;min-height:0}
.menu-section{margin:0}.menu-group{padding:11px 10px 14px;color:#879782!important;text-transform:uppercase;font-size:10px!important;letter-spacing:1.5px;font-weight:800}
.menu-items{display:grid!important;gap:5px!important;max-height:none!important;overflow:visible!important}
.sidebar-menu .en-nav-link{display:flex!important;align-items:center!important;min-width:0;gap:12px!important;padding:9px 10px!important;min-height:48px;margin:0!important;border-radius:11px!important;color:#bfcbbd!important;background:transparent!important;border:1px solid transparent!important;font-size:13px!important;font-weight:650;white-space:nowrap}
.sidebar-menu .en-nav-link:hover{background:#171e17!important;color:#eef3e8!important}
.sidebar-menu .en-nav-link.active{background:#1b2719!important;border-color:#4e743b!important;color:#f6f9f0!important;box-shadow:inset 3px 0 0 var(--brand)!important}
.en-v8-nav-ico{width:31px;height:31px;min-width:31px;display:grid;place-items:center;border-radius:9px;background:#182018;border:1px solid #354335;color:#92b36c}
.en-v8-nav-ico .en-icon{width:17px;height:17px}
.en-nav-link.active .en-v8-nav-ico{background:#354b28;color:#c6eca0;border-color:#587845}
.en-v8-nav-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.en-nav-badge{margin-left:auto;font-size:11px!important;color:#c5ddaa!important;font-style:normal!important;line-height:1;min-width:24px;text-align:center;background:#24311f!important;border:1px solid #405336!important;border-radius:999px;padding:5px 6px!important}
.en-nav-indicator{display:none}
.en-sidebar-bottom-v8{margin:0 10px 12px;display:flex!important;align-items:center;gap:10px;padding:11px!important;background:#151c14!important;border:1px solid var(--edge)!important;border-radius:12px}
.en-profile-avatar{display:grid;place-items:center;background:#7fad35!important;color:#10170c!important;width:36px;height:36px;min-width:36px;border-radius:10px;font-weight:800}
.en-profile-details{flex:1;min-width:0}.en-profile-details strong{display:block;color:#eef3e9;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.en-profile-details small{display:block;color:#95ac85;font-size:11px}
.en-logout-form{margin-left:auto!important}.en-logout-icon{color:#abc19f!important}.en-logout-icon:hover{color:#fff!important}
.en-sidebar-close{display:none!important}
.en-sidebar-backdrop{position:fixed;inset:0;z-index:30;background:rgba(0,0,0,.72);backdrop-filter:blur(2px)}
.main-content.en-v8-main{display:flex!important;flex-direction:column!important;flex:1!important;min-width:0;overflow:hidden!important;background:var(--bg)!important}
.en-global-topbar.en-v8-topbar{background:#0d110d!important;flex:none;min-height:64px;height:64px!important;border-bottom:1px solid #253025!important;padding:0 24px!important;display:flex!important;align-items:center!important;justify-content:space-between!important;gap:15px;position:relative;z-index:20}
.en-topbar-left,.en-topbar-right{display:flex;align-items:center;gap:14px;min-width:0}
.en-breadcrumb{display:flex;align-items:center;gap:8px;min-width:0;font-size:12px;color:#9eaf98;white-space:nowrap}
.en-breadcrumb strong{display:block;color:#e7eee3;white-space:nowrap;text-overflow:ellipsis;overflow:hidden;max-width:280px}
.en-breadcrumb .en-icon{width:13px;height:13px;color:#82987a}
.en-v8-header-online{display:inline-flex;gap:7px;align-items:center;color:#a9c697;background:#1a2617;border:1px solid #3a5531;border-radius:999px;padding:6px 9px;font-size:11px;font-weight:650;white-space:nowrap}.en-v8-header-online i{width:6px;height:6px;border-radius:50%;background:var(--brand)}
.en-topbar-date{white-space:nowrap;color:#899986;font-size:12px}
.en-topbar-create{background:var(--brand)!important;color:#10150c!important;display:inline-flex;gap:7px;align-items:center;padding:9px 13px;border-radius:10px;font-weight:800;white-space:nowrap;font-size:12px}.en-topbar-create:hover{background:var(--brand-hover)!important;color:#10150c!important}.en-topbar-create .en-icon{width:16px;height:16px}
.en-topbar-user{width:36px;height:36px;display:grid;place-items:center;border-radius:10px;background:#192218;border:1px solid #32442d;color:#e5f3d4;font-weight:800;font-size:12px}
.en-menu-trigger{display:none!important}
.content-scrollable.en-v8-content{flex:1!important;min-height:0!important;overflow:auto!important;background:#090b09!important;padding:25px clamp(16px,2.5vw,34px) 28px!important}
.app-footer{display:flex!important;justify-content:space-between!important;align-items:center;flex-wrap:wrap;gap:12px;min-height:44px;padding:10px 18px!important;background:#0d100d!important;color:#82927e!important;border-top:1px solid #253025!important;font-size:11px!important}.app-footer .en-footer-status{display:flex;align-items:center;gap:7px}.en-footer-muted{color:#83917f}
/* Carduri și tipografie — grafit predominant */
.glass-card,.v8-panel,.v8-metric,.v8-flow-card,.v8-hero-main,.v8-hero-side,.crm-mini-stat,.crm-master-shell,.crm-page-title-wrap,.en-kpi,.en-rule-card,.en-lead-card,.en-agent-card,.agent-card,.modal-content-glass,.partner-kpi,.partner-table-container,.crm-dialog,.en-alert,.crm-toast,.crm-warning{background:var(--card)!important;background-image:none!important;color:var(--txt)!important;border:1px solid var(--edge)!important;border-radius:var(--radius)!important;box-shadow:0 10px 30px rgba(0,0,0,.20)!important}
.glass-card,.v8-panel,.en-rule-card,.crm-master-shell{padding:20px}
.glass-card h1,.glass-card h2,.glass-card h3,.en-lead-card h3,.en-lead-card strong,.partner-kpi strong,.crm-panel-head h2,.v8-panel h2,.v8-hero-main h2,.v8-hero-side h3{color:var(--txt)!important}
.glass-card p,.en-lead-card p,.en-lead-card small,.partner-hero p,.crm-page-title p{color:var(--sub)!important}
.en-page-heading,.v8-master-hero,.partner-hero,.crm-page-title-wrap,.en-agent-head{background:#101510!important;background-image:none!important;border:1px solid var(--edge)!important;border-radius:18px!important;padding:21px 23px!important;display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:15px;margin-bottom:20px;color:var(--txt)}
.en-page-heading h1,.crm-page-title h1,.v8-master-hero h1,.partner-hero h1{color:var(--txt)!important;font-size:clamp(25px,2.8vw,36px)!important;margin:5px 0!important;letter-spacing:-.04em}
.en-page-heading p,.crm-page-title p{font-size:13px!important;margin:7px 0!important}
.en-eyebrow,.crm-eyebrow,.v8-overline,.v8-panel-eyebrow{color:#9ab58d!important;letter-spacing:1.4px!important;font-size:10px!important;font-weight:800;text-transform:uppercase}
.en-action,.crm-btn,.crm-link,.crm-details-button,.crm-pager,.neon-btn,.btn-action,.partner-actions a,.v8-btn,.v8-periods a,.en-inline-filters a{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:38px;padding:9px 13px!important;color:#dfe8d9!important;background:#202920!important;border:1px solid #394b35!important;border-radius:10px!important;font-weight:700;font-size:12px!important;text-decoration:none!important;box-shadow:none!important;text-transform:none!important;letter-spacing:normal!important}
.en-action:hover,.crm-btn:hover,.crm-link:hover,.crm-details-button:hover,.v8-btn:hover,.partner-actions a:hover{background:#293728!important;border-color:#5c7a4c!important;color:#fff!important}
.en-action:not(.en-action-outline),.crm-btn-primary,.v8-btn-white,.neon-btn:not(.btn-danger),.en-topbar-create,.v8-periods a.active,.en-inline-filters a.active{background:var(--brand)!important;color:#0d1409!important;border-color:var(--brand)!important;font-weight:800!important}
.en-action:not(.en-action-outline):hover,.crm-btn-primary:hover,.neon-btn:hover{background:var(--brand-hover)!important;color:#0d1409!important}
.crm-delete-link{background:#37221e!important;border:1px solid #613e37!important;color:#f3b1a7!important;padding:9px 13px;border-radius:9px}
.en-alert,.crm-toast,.crm-warning{padding:14px!important;margin:12px 0!important;background:#1a2218!important}.en-alert.danger,.crm-warning{background:#291d1a!important;border-color:#633d36!important;color:#f0beb6!important}.en-alert.success{border-color:#526a43!important;color:#cde7ad!important}
/* Dashboard */
.v8-dashboard{display:grid;gap:20px}.v8-dash-titlebar{display:flex;align-items:flex-end;justify-content:space-between;gap:15px;flex-wrap:wrap}.v8-dash-titlebar h1{color:#f2f5ef;font-size:clamp(26px,3vw,39px);margin:10px 0 4px}.v8-dash-titlebar p{margin:0;color:var(--sub)}
.v8-overline-dot,.v8-spark{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--brand);margin-right:6px}.v8-dot-accent{display:inline-block;background:var(--brand)!important;width:10px;height:10px;border-radius:50%;margin-left:8px}
.v8-filter-label{color:#b6c3b0;font-size:10px;font-weight:800;letter-spacing:1px;margin-bottom:8px}.v8-periods{display:flex;gap:6px;flex-wrap:wrap}
.v8-hero-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(260px,.8fr);gap:16px}.v8-hero-main,.v8-hero-side{padding:25px!important;position:relative;overflow:hidden;min-height:230px}.v8-hero-main{background:#171d17!important}.v8-hero-main::before,.v8-hero-art{display:none!important}.v8-hero-main h2{font-size:clamp(25px,3vw,40px);letter-spacing:-.045em;max-width:650px;margin:12px 0}.v8-hero-main h2 em{font-style:normal;color:#b8da93!important}.v8-hero-main p{font-size:13px}.v8-hero-top{display:flex;justify-content:space-between;gap:12px;color:#b0c19f;font-size:10px;font-weight:800;letter-spacing:1.1px}
.v8-hero-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:22px}.v8-hero-side{min-height:230px!important;display:flex;flex-direction:column;justify-content:center;background:#151b15!important}.v8-hero-side strong{color:var(--txt);font-size:45px;line-height:1.1}.v8-hero-side h3{color:var(--txt);font-size:21px;margin:7px 0}.v8-side-head{display:flex;justify-content:space-between;gap:10px}.v8-pill{padding:5px 9px;background:#263323;border:1px solid #40573a;color:#c8e7aa;font-size:10px;border-radius:8px}.v8-hero-side a{color:#b6d68b;font-weight:800}
.v8-section-heading{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}.v8-section-heading>div{display:flex;align-items:center;gap:10px}.v8-section-line{width:26px;height:3px;border-radius:99px;background:var(--brand)}.v8-section-heading h2{font-size:18px;margin:0}.v8-section-heading>span,.v8-mini-link{color:var(--sub);font-size:12px}
.v8-metric-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.v8-metric{padding:19px!important;border-top:2px solid #4b673e!important}.v8-metric-title{display:flex;justify-content:space-between;gap:12px;color:#9eac98;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.7px}.v8-metric-number{color:#f0f5e8;font-size:clamp(26px,3vw,38px);font-weight:850;margin-top:12px}.v8-metric-number small{font-size:17px}.v8-metric-bottom{display:flex;align-items:center;gap:10px;color:#a6b6a1;font-size:11px;margin-top:13px}.v8-metric-bottom i{height:1px;background:#303c2f;flex:1}
.v8-flow-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}.v8-flow-card{padding:17px!important;min-height:200px;display:flex;flex-direction:column;gap:9px}.v8-flow-top{display:flex;justify-content:space-between;align-items:center}.v8-flow-step{display:grid;place-items:center;width:35px;height:35px;background:#263123;border:1px solid #41583a;border-radius:10px;color:#c2e2a0;font-weight:800}.v8-flow-stage{font-size:10px;color:#96a78e}.v8-flow-card h3{font-size:16px;margin:0}.v8-flow-card p{font-size:12px;line-height:1.5;margin:0;flex:1}.v8-flow-bottom strong{font-size:26px;display:block;color:#eaf2e0}.v8-flow-bottom small{display:block;color:var(--sub)}.v8-flow-link{display:block;border-top:1px solid var(--edge);margin-top:8px;padding-top:12px;color:#b6d78d;font-weight:750;font-size:12px}
.v8-dash-bottom-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.v8-panel-head{display:flex;justify-content:space-between;gap:15px;flex-wrap:wrap;border-bottom:1px solid var(--edge);padding-bottom:13px;margin-bottom:15px}.v8-panel-head h2{font-size:18px;margin:5px 0}.v8-panel-head p{font-size:12px;margin:0}.v8-panel-head a{font-size:12px;font-weight:700}.v8-panel-body{min-height:50px}.v8-panel .v8-panel-eyebrow{display:block}
.v8-pipeline-list,.v8-source-list,.v8-action-list{display:grid;gap:8px}.v8-pipeline-item,.v8-source-item,.v8-action-list a{padding:10px 12px;background:#101510;border:1px solid #2a3329;border-radius:10px}.v8-pipeline-row{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:7px;color:var(--txt)}.v8-pipeline-row small{color:var(--sub)}.v8-pipeline-swatch,.v8-source-icon,.v8-action-ico{display:grid;place-items:center;background:#32452b!important;color:#deefcd!important;border-radius:8px;width:30px;height:30px;flex:none;font-size:12px}.v8-pipeline-track{height:6px;background:#293327;border-radius:99px;overflow:hidden}.v8-pipeline-track span{height:100%;display:block;background:var(--brand)!important;border-radius:99px}.v8-source-item{display:flex;align-items:center;gap:10px}.v8-source-name{flex:1}.v8-panel-foot{font-size:12px;color:var(--sub);padding-top:10px}.v8-table-scroller{overflow:auto}.v8-recent-table{width:100%;border-collapse:collapse}.v8-recent-table th{font-size:11px;color:#adbfa5;text-align:left;border-bottom:1px solid var(--edge);padding:10px;white-space:nowrap}.v8-recent-table td{border-bottom:1px solid #283126;padding:12px 10px;color:var(--txt);background:transparent!important}.v8-recent-table small{color:var(--sub)}.v8-status-chip{display:inline-block;padding:5px 7px;background:#263323;border:1px solid #3b5134;border-radius:7px;font-size:10px;color:#cce7b4}.v8-action-list a{display:flex;align-items:center;gap:12px}.v8-action-list a>span:nth-child(2){flex:1}.v8-action-list a strong{display:block;color:var(--txt)}.v8-action-list a small{display:block;color:var(--sub)}.v8-empty{color:var(--sub);padding:15px}
/* Master: tabele, taburi, dialoguri, adăugare lead */
.eneron-master-tabs{display:flex;align-items:center;flex-wrap:wrap;gap:6px;padding:6px;background:#111611!important;border:1px solid var(--edge)!important;border-radius:12px;margin-bottom:14px}.eneron-master-tabs a{display:inline-flex;padding:9px 12px;border-radius:8px;color:#b9c7b5!important;font-size:12px;font-weight:750}.eneron-master-tabs a.is-active{background:#253621!important;color:#ecf6e5!important;border:1px solid #4b6a3c}
.crm-page-title{display:flex;flex-wrap:wrap;justify-content:space-between;gap:14px}.crm-page-title h1{font-size:30px}.crm-page-actions{display:flex;gap:10px;flex-wrap:wrap}.crm-title-count{font-size:16px;color:#add47b;margin-left:8px}.crm-title-flow{display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:11px;color:#8c9b88;margin-top:14px}.crm-title-flow i{width:16px;height:1px;background:#465443}.crm-title-flow .active{color:#d0e6b7}
.crm-mini-metrics,.partner-kpis,.en-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 15px}.en-metrics-3{grid-template-columns:repeat(3,minmax(0,1fr))}.crm-mini-stat,.partner-kpi,.en-kpi{padding:18px!important;min-width:0}.crm-mini-stat span,.partner-kpi span,.en-kpi span{display:block;color:#aab9a5;font-size:11px!important;letter-spacing:.7px;text-transform:uppercase;font-weight:700}.crm-mini-stat strong,.partner-kpi strong,.en-kpi strong{display:block;margin:11px 0;color:#f2f5eb!important;font-size:32px;line-height:1.05}.crm-mini-stat small,.en-kpi small{display:block;font-size:11px;color:var(--sub)}
.crm-master-shell{padding:18px!important}.crm-panel-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:14px}.crm-panel-head h2{font-size:20px;color:#f0f3ee;margin:0 0 4px}.crm-panel-head p{font-size:12px;margin:0}.crm-view-controls{display:flex;align-items:center;gap:8px;color:#a7b5a1;font-size:12px}.crm-live-dot{background:var(--brand);width:7px;height:7px;border-radius:50%}
.crm-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:14px!important;margin:0 0 12px!important;background:#101410!important;border:1px solid var(--edge)!important;border-radius:12px!important;align-items:end}.crm-filters label,.en-agent-filter label,.en-rule-card label{display:grid;gap:6px;font-size:11px;color:#b6c8ab;font-weight:700}.crm-filters label span{color:#b6c8ab}.crm-filters .crm-search-field{grid-column:auto}
.crm-master-toolbar,.crm-bulk,.crm-pagination,.en-inline-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.crm-master-toolbar{padding:10px 0 14px}.crm-bulk{justify-content:flex-start}.crm-bulk select{min-width:155px}.crm-master-toolbar .crm-selection{font-size:12px;color:var(--sub)}.crm-master-toolbar .crm-selection label{display:flex;gap:7px;align-items:center}
.crm-master-table-wrap,.en-table-wrap,.partner-table-container,.table-scroll-wrap{width:100%;max-width:100%;overflow:auto;border:1px solid var(--edge)!important;border-radius:12px;background:#101410!important}.crm-master-table,.en-table,.partner-table,.perf-table,.var-table{width:100%;border-collapse:collapse;color:var(--txt)!important}.crm-master-table{min-width:1000px}.crm-master-table th,.en-table th,.partner-table th,.perf-table th,.var-table th{background:#1c251b!important;color:#baccb0!important;font-size:11px!important;font-weight:800;padding:11px 12px!important;text-align:left!important;border-bottom:1px solid #40513b!important;white-space:nowrap}.crm-master-table td,.en-table td,.partner-table td,.perf-table td,.var-table td{padding:12px!important;background:#111711!important;color:#e3ecdc!important;border-bottom:1px solid #2a3628!important;vertical-align:middle}.crm-master-table tbody tr:hover td,.partner-table tbody tr:hover td{background:#192019!important}.crm-master-table td strong,.partner-table td strong{color:#edf3e7!important}.crm-master-table select{min-width:125px!important;padding:8px 24px 8px 8px!important}
.crm-client-cell{display:flex;align-items:center;gap:10px}.crm-avatar{display:grid;place-items:center;width:35px;height:35px;flex:none;border-radius:9px;background:#36492d!important;color:#ecf5dd!important;font-weight:800}.crm-meta-row{display:flex;gap:7px;align-items:center;flex-wrap:wrap;color:var(--sub);font-size:11px}.crm-whatsapp,.crm-source-badge{display:inline-flex;padding:4px 7px;border:1px solid #49613e;border-radius:7px;background:#233021;color:#cfedaf!important;font-size:11px}.crm-status-won{border-color:var(--brand)!important}
.crm-pagination{margin-top:14px;color:var(--sub);font-size:12px}.crm-pagination nav,.crm-pagination>div{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.crm-import-strip{padding:15px;background:#101610;border:1px solid var(--edge);border-radius:12px;margin-top:16px;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.crm-import-strip p{font-size:12px}.crm-import-strip form{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.crm-import-strip input[type=file]{max-width:260px}
.crm-dialog{border:1px solid #475a41!important;background:#111611!important;width:min(1000px,calc(100vw - 24px));max-height:90dvh;overflow:hidden;padding:0!important;color:var(--txt)!important}.crm-dialog::backdrop{background:rgba(0,0,0,.78)}.crm-dialog-top,.crm-dialog-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 18px;border-bottom:1px solid var(--edge)}.crm-dialog-footer{border-top:1px solid var(--edge);border-bottom:0}.crm-dialog-inner{padding:15px 18px;overflow:auto;max-height:65dvh}.crm-detail-grid,.en-rule-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.crm-detail-field{display:grid;gap:5px;color:var(--sub);font-size:12px}.crm-field-wide{grid-column:1/-1}.crm-dialog h2{color:var(--txt)}.crm-close{display:grid;place-items:center;width:32px;height:32px;border:1px solid var(--edge);border-radius:9px;background:#212c20;color:var(--txt)}.crm-detail-actions{display:flex;gap:10px;flex-wrap:wrap}.crm-warning{color:#d9b68f}.crm-no-results,.en-empty{text-align:center;color:var(--sub);padding:20px}
.partner-hero{margin-bottom:15px}.partner-hero h1{font-size:31px}.partner-actions{display:flex;align-items:center;flex-wrap:wrap;gap:9px}.partner-actions select{max-width:200px!important}.partner-kpis{margin-bottom:14px}.partner-kpi{padding:16px!important}
/* Distribuție, agenți, statistici, setări, formulare parteneri */
.en-grid-split{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(300px,1fr);gap:14px}.en-grid-even{grid-template-columns:repeat(2,minmax(0,1fr))}.en-top-gap{margin-top:15px}.en-section-header{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px}.en-section-header h2{font-size:19px;margin:0}.en-section-header span{color:var(--sub);font-size:12px}.en-actions-bottom{margin-top:14px}.en-rule-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.en-rule-card{padding:17px!important}.en-rule-card h3{font-size:16px;margin:0 0 8px}.en-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.en-agents-list{display:grid;gap:1px}.en-agent-line{display:flex;justify-content:space-between;gap:12px;padding:13px 0;border-bottom:1px solid var(--edge)}.en-agent-line strong{display:block;color:var(--txt)}.en-agent-line small{display:block;color:var(--sub)}.en-agent-numbers{text-align:right;white-space:nowrap;color:var(--txt)}
.en-agent-filter{display:grid;grid-template-columns:minmax(0,1fr) minmax(160px,.35fr) auto auto;align-items:end;gap:10px;margin-bottom:15px}.en-agent-result-count{font-size:12px;color:var(--sub)}.en-lead-cards.en-agent-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.en-lead-card.en-agent-card{padding:18px!important;background:#131713!important;color:var(--txt)!important}.en-agent-card-header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}.en-agent-card h3{margin:7px 0;color:var(--txt)!important}.en-agent-id{font-size:11px;color:var(--sub);white-space:nowrap}.en-agent-status{display:inline-flex;background:#273325;border:1px solid #3d5633;border-radius:6px;color:#b5d891;padding:3px 7px;font-size:10px;font-weight:750}.en-agent-facts{display:flex;gap:7px;flex-wrap:wrap;color:var(--sub);font-size:11px}.en-agent-facts span+span:before{content:'·';margin-right:7px}.en-agent-actions{display:flex;gap:7px;flex-wrap:wrap;margin:13px 0}.en-agent-form{display:grid;gap:11px;padding:14px;border:1px solid var(--edge);background:#0e120e;border-radius:12px}.en-agent-form label{display:grid;gap:5px;color:var(--sub);font-size:11px}.en-agent-form textarea{min-height:82px}.en-agent-card input,.en-agent-card select,.en-agent-card textarea{background:#0a0e0a!important;color:var(--txt)!important}
.settings-container{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.filter-bar{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;align-items:end;padding:16px;background:#121712!important;border:1px solid var(--edge)!important;border-radius:14px;margin-bottom:16px}.filter-group{display:flex;flex-direction:column;gap:6px}.filter-group label,.input-group label{color:#aabca2!important;font-size:11px;font-weight:700}.input-group{margin-bottom:12px}.form-box{max-width:650px;width:100%;margin:0 auto}.form-box>.glass-card{padding:22px}.stat-row{display:flex;justify-content:space-between;gap:10px;padding:11px 0;border-bottom:1px solid var(--edge);color:var(--txt)}.stat-row span{color:var(--sub)}.agent-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.agent-card{padding:18px}.agent-card h3{color:var(--txt)}.modal-content-glass{background:#131713!important}.modal{background:rgba(0,0,0,.8)!important}.variable-tag,.variable-tag-blue{color:#b5d994!important;background:#243023!important;border:1px solid #426237!important}
/* corecții pentru stiluri legacy inline ale paginilor */
body .glass-card,body .partner-kpi,body .en-lead-card,body .en-agent-card,body .crm-master-shell,body .v8-panel,body .en-kpi{background-color:var(--card)!important}
body .partner-table-container,body .partner-table,body .crm-master-table-wrap,body .en-table-wrap{background-color:#101410!important}
body .filter-bar,body .crm-filters{background-color:#121712!important}
body .en-lead-card *,body .agent-card *,body .crm-master-shell *,body .glass-card *{text-shadow:none!important}
/* responsive desktop → tablet → telefon */
@media(max-width:1240px){.v8-flow-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.v8-metric-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.agent-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:1150px){.v8-hero-grid,.en-grid-split{grid-template-columns:1fr}.en-lead-cards.en-agent-grid{grid-template-columns:1fr}.crm-mini-metrics,.partner-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.settings-container{grid-template-columns:1fr}.filter-bar{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:1060px){.sidebar.en-v8-sidebar{position:fixed!important;top:0;bottom:0;left:0;max-width:min(280px,calc(100vw - 48px))!important;width:min(280px,calc(100vw - 48px))!important;min-width:0!important;transform:translateX(-102%);transition:transform .24s ease;z-index:40!important}.sidebar.en-v8-sidebar.active{transform:translateX(0)}.en-sidebar-close{display:grid!important;place-items:center;position:absolute;top:10px;right:9px;width:32px;height:32px;border-radius:9px;background:#293529!important;border:1px solid #496045;color:#e8efdf;z-index:2}.en-v8-sidebar-head{padding-top:17px}.en-brand.en-brand-v8{margin-right:21px!important}.en-brand .en-v8-brand-mark img{max-width:none!important}.en-menu-trigger{display:grid!important;place-items:center;width:36px;height:36px;border:1px solid var(--edge);border-radius:9px;background:#1a2418;color:#d2e1c9}.en-menu-open{overflow:hidden!important}.en-global-topbar.en-v8-topbar{padding:0 15px!important}}
@media(max-width:800px){.content-scrollable.en-v8-content{padding:17px 13px 24px!important}.en-v8-header-online,.en-topbar-date{display:none}.en-global-topbar.en-v8-topbar{height:59px!important;min-height:59px!important}.en-breadcrumb strong{max-width:170px}.v8-hero-main,.v8-hero-side{min-height:auto!important;padding:19px!important}.v8-flow-grid,.en-rule-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.crm-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-bar{grid-template-columns:repeat(2,minmax(0,1fr))}.en-metrics-3{grid-template-columns:1fr}.en-agent-filter{grid-template-columns:1fr 1fr}.en-agent-filter .en-action{grid-column:1/-1}.crm-detail-grid{grid-template-columns:1fr}}
@media(max-width:560px){.content-scrollable.en-v8-content{padding:12px 10px 20px!important}.en-topbar-left,.en-topbar-right{gap:8px}.en-topbar-user{display:none}.en-topbar-create{padding:8px!important;font-size:11px!important;white-space:nowrap}.en-topbar-create .en-icon{display:none}.en-breadcrumb{gap:5px}.en-breadcrumb strong{max-width:130px;font-size:12px}.v8-dash-titlebar h1{font-size:29px}.v8-hero-main h2{font-size:27px}.v8-flow-grid,.v8-metric-grid,.crm-mini-metrics,.partner-kpis,.en-lead-cards.en-agent-grid,.agent-grid,.filter-bar,.crm-filters,.en-rule-grid,.en-form-grid,.en-agent-filter,.crm-output-grid{grid-template-columns:1fr!important}.crm-page-title-wrap,.en-page-heading,.v8-master-hero,.partner-hero{padding:16px!important}.crm-master-shell,.v8-panel,.glass-card{padding:14px!important}.en-table-wrap,.partner-table-container,.crm-master-table-wrap{overflow-x:auto}.crm-master-table{min-width:800px}.en-table{min-width:550px}.crm-panel-head{align-items:flex-start}.crm-pagination,.crm-master-toolbar,.crm-bulk,.crm-page-actions,.en-inline-actions{align-items:stretch;flex-direction:column}.crm-import-strip{display:grid}.crm-import-strip form{display:grid}.app-footer{font-size:10px!important;padding:9px 12px!important}.app-footer time{display:none}.en-topbar-create span{display:inline}.en-sidebar-bottom-v8{margin-bottom:9px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;transition-duration:.01ms!important;animation-duration:.01ms!important}}

</style>
</head>
<body>

<div class="en-sidebar-backdrop" id="sidebar-overlay" hidden></div>
<aside class="sidebar en-v8-sidebar" id="eneronSidebar" aria-label="Navigare principală">
  <div class="en-v8-sidebar-head">
    <button type="button" id="eneronSidebarClose" class="en-sidebar-close" aria-label="Închide meniul" title="Închide meniul"><?=eneron_nav_icon('close')?></button>
    <a class="en-brand en-brand-v8" href="<?= $menu_role==='importer'?'master_leads.php?tab=partners':'dashboard.php' ?>" aria-label="Eneron CRM – Dashboard">
      <span class="en-v8-brand-mark"><img src="media/LOGO-ENERON.png" alt="Eneron" loading="eager"></span>
      
    </a>
    <div class="en-v8-sidebar-label"><span class="en-v8-live" aria-hidden="true"></span><span>PLATFORMĂ DE VÂNZĂRI</span><span class="en-v8-version">CRM</span></div>
  </div>
  <nav class="sidebar-menu en-v8-nav" aria-label="Meniu Eneron">
   <?php foreach($menu_items as $section): ?>
    <section class="menu-section open" aria-label="<?=eneron_menu_escape($section[0])?>">
     <div class="menu-group"><?=eneron_menu_escape($section[0])?></div>
     <div class="menu-items">
      <?php foreach($section[1] as $item): $active=($current_page===basename((string)parse_url($item[0],PHP_URL_PATH)) &&
          ($current_page!=='master_leads.php' || (((($_GET['tab']??'')==='partners') || $menu_role==='importer') === str_contains($item[0],'tab=partners')))); $badge=$item[3] ?? null; ?>
      <a href="<?=eneron_menu_escape($item[0])?>" class="en-nav-link <?=$active?'active':''?>" <?=$active?'aria-current="page"':''?>>
        <span class="en-v8-nav-ico"><?=eneron_nav_icon($item[2])?></span><span class="en-v8-nav-name"><?=eneron_menu_escape($item[1])?></span>
        <?php if($badge !== null && $badge>0): ?><em class="en-nav-badge"><?= (int)$badge ?></em><?php endif; ?>
        <?php if($active):?><span class="en-nav-indicator" aria-hidden="true"></span><?php endif;?>
      </a>
      <?php endforeach; ?>
     </div>
    </section>
   <?php endforeach; ?>
  </nav>
  <div class="en-sidebar-bottom en-sidebar-bottom-v8">
    <div class="en-profile-avatar" aria-hidden="true"><?=eneron_menu_escape(strtoupper($initials))?></div>
    <div class="en-profile-details"><strong><?=eneron_menu_escape($menu_name)?></strong><small><?=eneron_menu_escape($menu_role_labels[$menu_role]??ucfirst($menu_role))?></small></div>
    <form action="login.php" method="post" class="en-logout-form" style="display:flex;align-items:center;margin-left:auto;">
      <input type="hidden" name="auth_action" value="logout">
      <input type="hidden" name="logout_csrf" value="<?=eneron_menu_escape($_SESSION['logout_csrf'])?>">
      <button type="submit" class="en-logout-icon" aria-label="Deconectare" title="Deconectare" style="background:transparent;border:0;cursor:pointer;display:grid;place-items:center;padding:0;min-width:36px;min-height:36px;">
        <?=eneron_nav_icon('logout')?>
      </button>
    </form>
  </div>
</aside>
<div class="main-content en-v8-main">
 <header class="en-global-topbar en-v8-topbar">
   <div class="en-topbar-left"><button type="button" class="en-menu-trigger" id="eneronMenuTrigger" aria-label="Deschide meniul" aria-controls="eneronSidebar" aria-expanded="false"><?=eneron_nav_icon('menu')?></button><div class="en-breadcrumb"><span>Eneron CRM</span><?=eneron_nav_icon('chevron')?><strong><?=eneron_menu_escape($menu_page_label)?></strong></div></div>
   <div class="en-topbar-right"><span class="en-v8-header-online"><i></i>Sistem activ</span><span class="en-topbar-date"><?=date('d.m.Y')?></span><?php if($menu_manager):?><a href="master_leads.php?new=1" class="en-topbar-create"><?=eneron_nav_icon('plus')?><span>Adaugă lead</span></a><?php endif;?><a href="agents.php" class="en-topbar-user" aria-label="Contul meu"><?=eneron_menu_escape(strtoupper($initials))?></a></div>
 </header>
 <div class="content-scrollable en-v8-content" id="eneron-content">
<script>
(function(){
 const trigger=document.getElementById('eneronMenuTrigger'),sidebar=document.getElementById('eneronSidebar'),overlay=document.getElementById('sidebar-overlay'),close=document.getElementById('eneronSidebarClose');
 if(!trigger||!sidebar||!overlay)return;
 let priorFocus=null;
 function setMenu(open){
   const mobile=window.matchMedia('(max-width: 1060px)').matches;
   const showing=!!open&&mobile;
   sidebar.classList.toggle('active',showing);
   overlay.hidden=!showing;
   document.body.classList.toggle('en-menu-open',showing);
   trigger.setAttribute('aria-expanded',String(showing));
   trigger.setAttribute('aria-label',showing?'Închide meniul':'Deschide meniul');
   if(showing){priorFocus=document.activeElement;sidebar.querySelector('a')?.focus();}
   else if(priorFocus){priorFocus.focus();priorFocus=null;}
 }
 trigger.addEventListener('click',()=>setMenu(!sidebar.classList.contains('active')));
 overlay.addEventListener('click',()=>setMenu(false));
 close?.addEventListener('click',()=>setMenu(false));
 sidebar.querySelectorAll('.en-nav-link').forEach(a=>a.addEventListener('click',()=>setMenu(false)));
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&sidebar.classList.contains('active'))setMenu(false);if(e.key==='Tab'&&sidebar.classList.contains('active')){const a=[...sidebar.querySelectorAll('a,button')].filter(x=>x.offsetParent!==null);if(!a.length)return;const first=a[0],last=a[a.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}});
 window.addEventListener('resize',()=>{if(window.innerWidth>1060)setMenu(false);});
})();
</script>
