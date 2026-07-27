<?php
$servername = 'localhost';
$username = 'merc_mercadosilveira_user';
$password = 'De04081986##';
$dbname = 'merc_mercadosilveira_db';

// 1. PRIMEIRO: Cria a conexão com o Banco
$conn = new mysqli($servername, $username, $password, $dbname);

// 2. SEGUNDO: Verifica se conectou com sucesso
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

// 3. TERCEIRO: Agora sim configura o Charset (Para Emojis e Acentos funcionarem)
$conn->set_charset("utf8mb4");

// 4. QUARTO: Configura o Fuso Horário para o Brasil
$conn->query("SET time_zone = '-03:00'");
date_default_timezone_set('America/Sao_Paulo');
?>