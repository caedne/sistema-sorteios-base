<?php
// Arquivo: ver_banco.php
// Objetivo: Mostrar as colunas e CRIAR a id_pix se faltar

$host = "localhost";
$user = "merc_mercadosilveira_user";
$pass = "De04081986##";
$db   = "merc_mercadosilveira_db";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Falha na conexão: " . $conn->connect_error);
}

echo "<h1>Diagnóstico do Banco de Dados</h1>";

// --- PARTE 1: Tenta Criar a Coluna id_pix ---
// O comando SQL abaixo cria a coluna, mas ignoramos erro se ela já existir
$sql_cria = "ALTER TABLE vendas ADD id_pix VARCHAR(255) NULL";
if ($conn->query($sql_cria) === TRUE) {
    echo "<p style='color:green; font-weight:bold;'>✅ SUCESSO: Coluna 'id_pix' foi criada agora!</p>";
} else {
    // Se der erro, provavelmente é porque já existe, então tudo bem.
    echo "<p style='color:blue;'>ℹ️ Aviso: " . $conn->error . " (Provavelmente já existe)</p>";
}

echo "<hr>";

// --- PARTE 2: Lista as Colunas Atuais ---
echo "<h3>Colunas da Tabela 'vendas':</h3>";
$result = $conn->query("SHOW COLUMNS FROM vendas");

if ($result) {
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>Nome da Coluna (Field)</th><th>Tipo</th></tr>";
    while($row = $result->fetch_assoc()) {
        $destaque = ($row['Field'] == 'id_pix') ? "style='background-color: yellow;'" : "";
        echo "<tr $destaque>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "Erro ao ler tabela: " . $conn->error;
}
?>