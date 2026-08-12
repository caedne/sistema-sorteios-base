<?php
session_start();
session_destroy(); // Mata a sessão

// MÁGICA: Apaga o cookie do navegador definindo a validade para o passado
setcookie("dking_lembrar", "", time() - 3600, "/");

header("Location: login.php");
exit;
?>