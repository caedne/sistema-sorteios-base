<?php
// Exibir erros para debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); 
date_default_timezone_set('America/Sao_Paulo');
include 'db.php';

// Verifica conexão
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["sucesso" => false, "erro" => "Conexão falhou: " . $conn->connect_error]);
    exit;
}

// Recebe dados
$id_sorteio = isset($_POST['id_sorteio']) ? intval($_POST['id_sorteio']) : 0;
$ganhadores_json = $_POST['ganhadores'] ?? '';

if ($id_sorteio === 0 || empty($ganhadores_json)) {
    http_response_code(400);
    echo json_encode(["sucesso" => false, "erro" => "ID sorteio ou ganhadores vazios"]);
    exit;
}

$ganhadores = json_decode($ganhadores_json, true);

if (!$ganhadores || count($ganhadores) === 0) {
    http_response_code(400);
    echo json_encode(["sucesso" => false, "erro" => "JSON de ganhadores inválido"]);
    exit;
}

// 1. Limpa ganhadores anteriores (evita duplicidade)
$conn->query("DELETE FROM ganhadores_premios WHERE sorteio_id = $id_sorteio");

// 2. Insere os novos ganhadores na tabela CERTA (ganhadores_premios)
foreach ($ganhadores as $g) {
    $nome = $conn->real_escape_string($g['nome']);
    $numero = intval($g['numero']);
    $premio = $conn->real_escape_string($g['premio']);
    
    $sql = "INSERT INTO ganhadores_premios (sorteio_id, nome_cliente, numero_sorteado, premio, status_retirada, data_ganho) 
            VALUES ($id_sorteio, '$nome', $numero, '$premio', 'pendente', NOW())";
    
    if (!$conn->query($sql)) {
        http_response_code(500);
        echo json_encode(["sucesso" => false, "erro" => "Erro ao inserir ganhador: " . $conn->error]);
        exit;
    }
    
    // Marca na tabela vendas (Opcional, se der erro pode comentar essa linha)
    $conn->query("UPDATE vendas SET status_venda = 'ganhador' WHERE numero_escolhido = $numero AND sorteio_id = $id_sorteio");
}

// 3. ATUALIZAÇÃO SEGURA DO SORTEIO (Aqui estava o erro!)
// Só atualizamos o STATUS. Removemos as colunas 'ganhador', 'numero_ganhador' e 'data_fim' que não existem.
$sql_sorteio = "UPDATE sorteios SET status = 'finalizado' WHERE id = $id_sorteio";

if (!$conn->query($sql_sorteio)) {
    http_response_code(500);
    echo json_encode(["sucesso" => false, "erro" => "Erro ao finalizar sorteio: " . $conn->error]);
    exit;
}

// 4. Limpa as vendas não ganhas
//$conn->query("DELETE FROM vendas WHERE sorteio_id = $id_sorteio AND status_venda != 'ganhador'"); 

echo json_encode(["sucesso" => true, "mensagem" => "Sorteio finalizado com sucesso!"]);
?>