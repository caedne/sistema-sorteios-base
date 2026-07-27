<?php
// salvar_ganhador.php - ATUALIZADO PARA AS NOVAS TABELAS
include 'db.php';

if (isset($_POST['numero']) && isset($_POST['id_sorteio'])) {
    $numero = intval($_POST['numero']);
    $id_sorteio = intval($_POST['id_sorteio']);
    
    // Agora ele marca corretamente na tabela 'vendas' que o número ganhou
    $stmt = $conn->prepare("UPDATE vendas SET status_venda = 'ganhador' WHERE numero_escolhido = ? AND sorteio_id = ?");
    $stmt->bind_param("ii", $numero, $id_sorteio);
    
    if ($stmt->execute()) {
        echo "Sucesso: Venda atualizada para ganhador!";
    } else {
        echo "Erro ao atualizar banco: " . $conn->error;
    }
}
?>