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
// sistema_sorteios/cancelar.php
include 'db.php'; // Mudei de '../db.php' para 'db.php' porque estão na mesma pasta

if (isset($_GET['id'])) {
    
    $id = intval($_GET['id']);
    
    // Deleta as vendas para zerar os números do sorteio
    $query = "DELETE FROM vendas WHERE sorteio_id = $id";
    
    if ($conn->query($query)) {
        // Redireciona de volta para a página que você estava
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit();
    } else {
        echo "Erro ao cancelar: " . $conn->error;
    }
    
}
?>