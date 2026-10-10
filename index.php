<?php
// Redirecționăm vizitatorul automat către dashboard.
// Dacă nu este logat, fișierul session_check.php din dashboard îl va trimite automat la login.php.
header("Location: dashboard.php");
exit();
?>