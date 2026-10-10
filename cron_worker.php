<?php
/** Eneron scheduled Google Sheets synchronization. Run via cPanel Cron Jobs (CLI only). */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Acces interzis.'); }
chdir(__DIR__);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/eneron_core.php';
require_once __DIR__ . '/includes/eneron_sheet_sync.php';
try {
    $results=en_sheet_due($pdo);
    foreach ($results as $source=>$r) {
        echo sprintf("%s | Sursa %d | citite %d, noi %d, duplicate %d, istoric %d, erori %d\n",date('c'),$source,$r['read'],$r['new'],$r['duplicates'],$r['baseline'],$r['errors']);
    }
} catch (Throwable $e) {
    error_log('Eneron Cron Sheet Sync: '.$e->getMessage());
    fwrite(STDERR,"Eroare import Eneron. Verifică jurnalul serverului.\n");
    exit(1);
}
