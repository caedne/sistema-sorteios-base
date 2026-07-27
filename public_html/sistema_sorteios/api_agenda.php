<?php
include 'db.php';
header('Content-Type: application/json');

$acao = $_GET['acao'] ?? '';

try {
    switch ($acao) {
        case 'criar':
            $stmt = $conn->prepare("INSERT INTO agenda_clientes (telefone, nome_fixo, nome_whatsapp) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $_POST['telefone'], $_POST['nome'], $_POST['nome']);
            echo json_encode(['success' => $stmt->execute()]);
            break;
            
        case 'editar_nome':
            $novo_nome = $_POST['nome'];
            $cliente_id = $_POST['id'];
            
            $conn->begin_transaction();
            try {
                // 1. Atualiza na agenda_clientes
                $stmt = $conn->prepare("UPDATE agenda_clientes SET nome_fixo = ? WHERE id = ?");
                $stmt->bind_param("si", $novo_nome, $cliente_id);
                $stmt->execute();
                
                // 2. Busca o id_whatsapp para atualizar as outras tabelas
                $stmt = $conn->prepare("SELECT id_whatsapp FROM agenda_clientes WHERE id = ?");
                $stmt->bind_param("i", $cliente_id);
                $stmt->execute();
                $cliente = $stmt->get_result()->fetch_assoc();
                
                if ($cliente && $cliente['id_whatsapp']) {
                    $id_whatsapp = $cliente['id_whatsapp'];
                    
                    // 3. Atualiza na tabela de vendas (Para a lista do robô sair com o nome novo)
                    $stmt = $conn->prepare("UPDATE vendas SET nome_comprador = ? WHERE id_whatsapp = ?");
                    $stmt->bind_param("ss", $novo_nome, $id_whatsapp);
                    $stmt->execute();
                    
                    // 4. Atualiza na tabela de ganhadores (Para o histórico ficar bonito)
                    $stmt = $conn->prepare("UPDATE ganhadores_premios SET nome_cliente = ? WHERE id_whatsapp = ?");
                    $stmt->bind_param("ss", $novo_nome, $id_whatsapp);
                    $stmt->execute();
                }
                
                $conn->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'excluir':
            $stmt = $conn->prepare("DELETE FROM agenda_clientes WHERE id = ?");
            $stmt->bind_param("i", $_POST['id']);
            echo json_encode(['success' => $stmt->execute()]);
            break;
            
        case 'corrigir_telefone':
            $cliente_id = $_POST['id'] ?? null;
            $telefone_novo = $_POST['telefone'] ?? null;
            
            if (!$cliente_id || !$telefone_novo) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }
            
            // Aceitar com ou sem 55
            $tel_limpo = preg_replace('/\D/', '', $telefone_novo);
            if (strlen($tel_limpo) == 11) {
                $tel_limpo = '55' . $tel_limpo;
            }
            
            if (strlen($tel_limpo) !== 13 || substr($tel_limpo, 0, 2) !== '55') {
                echo json_encode(['success' => false, 'error' => 'Telefone inválido']);
                exit;
            }
            
            // Buscar id_whatsapp
            $stmt = $conn->prepare("SELECT id_whatsapp FROM agenda_clientes WHERE id = ?");
            $stmt->bind_param("i", $cliente_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $cliente = $result->fetch_assoc();
            
            if (!$cliente) {
                echo json_encode(['success' => false, 'error' => 'Cliente não encontrado']);
                exit;
            }
            
            $id_whatsapp = $cliente['id_whatsapp'];
            
            // Iniciar transação
            $conn->begin_transaction();
            
            try {
                $linhas_total = 0;
                
                // 1. Atualizar agenda_clientes
                $stmt = $conn->prepare("UPDATE agenda_clientes SET telefone = ? WHERE id = ?");
                $stmt->bind_param("si", $tel_limpo, $cliente_id);
                $stmt->execute();
                $linhas_total += $stmt->affected_rows;
                
                // 2. Atualizar vendas
                if ($id_whatsapp) {
                    $stmt = $conn->prepare("UPDATE vendas SET telefone = ? WHERE id_whatsapp = ?");
                    $stmt->bind_param("ss", $tel_limpo, $id_whatsapp);
                    $stmt->execute();
                    $linhas_total += $stmt->affected_rows;
                }
                
                // 3. Atualizar ganhadores_premios
                if ($id_whatsapp) {
                    $stmt = $conn->prepare("UPDATE ganhadores_premios SET telefone = ? WHERE id_whatsapp = ?");
                    $stmt->bind_param("ss", $tel_limpo, $id_whatsapp);
                    $stmt->execute();
                    $linhas_total += $stmt->affected_rows;
                }
                
                // 4. Carteiras NÃO tem coluna telefone, só tem id_whatsapp
                // Não precisa atualizar nada aqui!
                
                // Commit
                $conn->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => "✅ Telefone corrigido!\n$linhas_total registro(s) atualizados",
                    'telefone_novo' => $tel_limpo
                ]);
                
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
} catch (Exception $e) {
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>