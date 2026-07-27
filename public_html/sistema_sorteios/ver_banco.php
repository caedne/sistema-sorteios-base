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
include 'db.php';
echo "<h1>🕵️ Estrutura do Banco de Dados</h1>";

$tabelas = ['carteiras', 'sorteios', 'ganhadores_premios', 'transacoes_carteira'];

foreach ($tabelas as $tab) {
    echo "<h3>Tabela: $tab</h3>";
    $result = $conn->query("SHOW COLUMNS FROM $tab");
    if ($result) {
        echo "<ul>";
        while ($row = $result->fetch_assoc()) {
            echo "<li>" . $row['Field'] . " (" . $row['Type'] . ")</li>";
        }
        echo "</ul>";
    } else {
        echo "<p style='color:red'>Erro ou tabela não existe.</p>";
    }
    echo "<hr>";
}
?>