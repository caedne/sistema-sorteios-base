<?php
// 1. OBRIGATÓRIO: Valida a licença no Painel Master (PostgreSQL)
// Isso blinda todas as páginas do sistema que usam o banco de dados.
require_once __DIR__ . '/validar_licenca.php';
// Se o script não parou no require acima, significa que a licença está ativa 
// e a variável $cliente (com os dados da loja) está disponível.

// 2. Credenciais do Banco MySQL (onde ficam as rifas e usuários)
$servername = 'localhost';
$username = 'root'; // Coloque o usuário do seu MySQL local (geralmente root)
$password = 'dkingadmin'; // Coloque a senha do Docker aqui
// O Pulo do Gato (Multitenant): O nome do banco é dinâmico!
// O sistema concatena "sistema_cliente_" com o ID da loja.
// Ex: O cliente com ID 1 vai conectar no banco "sistema_cliente_1"
$dbname = 'sistema_cliente_' . $cliente['id'];

// 3. Cria a conexão isolada usando mysqli (mantendo total compatibilidade com seu sistema)
$conn = new mysqli($servername, $username, $password, $dbname);

// 4. Verifica se conectou com sucesso
if ($conn->connect_error) {
    die('Erro de Conexão com o Banco da Loja: ' . $conn->connect_error);
}

// 5. Configura Charset e Fuso Horário
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '-03:00'");
date_default_timezone_set('America/Sao_Paulo');
?>