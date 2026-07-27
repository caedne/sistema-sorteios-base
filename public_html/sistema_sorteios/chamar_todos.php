<?php
// Arquivo: sistema_sorteios/chamar_todos.php
header('Content-Type: application/json');
include 'db.php'; 

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;

if ($id === 0) {
    echo json_encode(["erro" => "ID inválido"]);
    exit;
}

// Descobre qual é a categoria desse sorteio (Carnes ou Bebidas)
$res = $conn->query("SELECT categoria FROM sorteios WHERE id = $id");
$row = $res->fetch_assoc();
$categoria = $row['categoria'];

// Manda o pedido para o Robô (Node.js)
$url = 'http://127.0.0.1:3000/api/chamar-todos';
$dados = json_encode(['categoria' => $categoria]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_POSTFIELDS, $dados);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($dados)
]);

$resultado = curl_exec($ch);
curl_close($ch);

echo $resultado;
?>