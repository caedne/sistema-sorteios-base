<?php
// Conexão direta ao banco PostgreSQL central (mesmo banco do Admin Master)
$host = 'localhost';
$port = '5432';
$dbname = 'evolution';
$user = 'postgres';
$password = 'dkingadmin';

try {
    $pdoMaster = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    $pdoMaster->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro crítico de conexão com o sistema central.");
}

// Pega o domínio/host que o usuário acessou no navegador (ex: sistema.localhost)
$dominioAtual = $_SERVER['HTTP_HOST'];

// Remove a porta se houver (ex: localhost:8000 vira localhost)
$dominioAtual = explode(':', $dominioAtual)[0];

// Busca o cliente e o status da licença no banco Master
$stmt = $pdoMaster->prepare("
    SELECT c.id, c.nome_fantasia, c.status, l.vencimento_fatura 
    FROM clientes_saas c 
    JOIN licencas_clientes l ON c.id = l.cliente_id 
    WHERE c.dominio = ?
");
$stmt->execute([$dominioAtual]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

// Regras de Bloqueio
if (!$cliente) {
    // Domínio não cadastrado na plataforma
    header("HTTP/1.1 403 Forbidden");
    echo "<h2 style='font-family:sans-serif; text-align:center; margin-top:50px; color:#ef4444;'>Acesso Negado: Loja não encontrada ou não cadastrada no sistema.</h2>";
    exit;
}

if ($cliente['status'] !== 'ativo' || strtotime($cliente['vencimento_fatura']) < time()) {
    // Cliente bloqueado ou licença vencida
    header("HTTP/1.1 403 Forbidden");
    echo "<h2 style='font-family:sans-serif; text-align:center; margin-top:50px; color:#f59e0b;'>Aviso: A licença desta loja está vencida ou suspensa. Entre em contato com o suporte.</h2>";
    exit;
}

// Se passou por tudo, temos a variável $cliente disponível para uso no sistema!