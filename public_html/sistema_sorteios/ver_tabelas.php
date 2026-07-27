<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Se a sessão expirou, mas o PC tem o Cookie de 30 dias, libera o acesso silenciosamente
if (!isset($_SESSION['admin_logado']) && isset($_COOKIE['dking_lembrar']) && $_COOKIE['dking_lembrar'] === 'sim') {
    $_SESSION['admin_logado'] = true;
}
if (!isset($_SESSION['admin_logado']) || $_SESSION['admin_logado'] !== true) {
    header("Location: login.php");
    exit; 
}
include 'db.php'; // Certifique-se que o caminho do db.php está certo

echo "<h3>📂 Lista de Tabelas do Banco:</h3>";

$result = $conn->query("SHOW TABLES");

if ($result) {
    echo "<ul>";
    while ($row = $result->fetch_array()) {
        echo "<li>" . $row[0] . "</li>";
    }
    echo "</ul>";
} else {
    echo "Erro ao listar tabelas: " . $conn->error;
}
?>