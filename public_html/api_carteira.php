<?php
include 'db.php';
header('Content-Type: application/json');

$acao = $_GET['acao'] ?? '';

// Função para formatar telefone
function formatarTelefone($telefone)
{
    if (!$telefone)
        return 'Sem telefone';
    $tel = preg_replace('/\D/', '', $telefone);
    if (strlen($tel) == 13 && substr($tel, 0, 2) == '55') {
        $tel = substr($tel, 2);
        return '(' . substr($tel, 0, 2) . ') ' . substr($tel, 2, 5) . '-' . substr($tel, 7);
    }
    if (strlen($tel) == 11) {
        return '(' . substr($tel, 0, 2) . ') ' . substr($tel, 2, 5) . '-' . substr($tel, 7);
    }
    return $telefone;
}

try {
    switch ($acao) {
        case 'buscar_clientes':
            $busca = $_GET['busca'] ?? '';
            if (strlen($busca) < 2) {
                echo json_encode(['clientes' => []]);
                exit;
            }

            $stmt = $conn->prepare("
                SELECT id, nome_fixo, telefone 
                FROM agenda_clientes 
                WHERE nome_fixo COLLATE utf8mb4_general_ci LIKE ? 
                AND id NOT IN (SELECT cliente_id FROM carteiras WHERE status = 'ativo')
                ORDER BY nome_fixo 
                LIMIT 10
            ");
            $buscaLike = "%$busca%";
            $stmt->bind_param("s", $buscaLike);
            $stmt->execute();
            $result = $stmt->get_result();

            $clientes = [];
            while ($row = $result->fetch_assoc()) {
                $clientes[] = [
                    'id' => $row['id'],
                    'nome_fixo' => $row['nome_fixo'],
                    'telefone_formatado' => formatarTelefone($row['telefone'])
                ];
            }
            echo json_encode(['clientes' => $clientes]);
            break;

        case 'criar':
            $cliente_id = $_POST['cliente_id'] ?? null;
            $saldo = floatval($_POST['saldo'] ?? 0);

            if (!$cliente_id) {
                echo json_encode(['success' => false, 'error' => 'Cliente não selecionado']);
                exit;
            }

            $stmt = $conn->prepare("SELECT id FROM carteiras WHERE cliente_id = ? AND status = 'ativo'");
            $stmt->bind_param("i", $cliente_id);
            $stmt->execute();
            $existe = $stmt->get_result()->fetch_assoc();

            if ($existe) {
                echo json_encode(['success' => false, 'error' => 'Cliente já possui carteira ativa!']);
                exit;
            }

            $stmt = $conn->prepare("
                INSERT INTO carteiras (cliente_id, saldo, credito_limite, credito_usado, status, data_criacao) 
                VALUES (?, ?, 0, 0, 'ativo', NOW())
            ");
            $stmt->bind_param("id", $cliente_id, $saldo);
            $stmt->execute();

            if ($saldo > 0) {
                $stmt = $conn->prepare("
                    INSERT INTO transacoes_carteira (cliente_id, tipo, valor, descricao, data_transacao)
                    VALUES (?, 'recarga_manual', ?, 'Saldo inicial', NOW())
                ");
                $stmt->bind_param("id", $cliente_id, $saldo);
                $stmt->execute();
            }
            echo json_encode(['success' => true, 'message' => 'Carteira criada com sucesso!']);
            break;

        case 'adicionar_saldo':
            $cliente_id = $_POST['cliente_id'] ?? null;
            $valor = floatval($_POST['valor'] ?? 0);
            $descricao = $_POST['descricao'] ?? 'Recarga manual';

            if (!$cliente_id || $valor <= 0) {
                echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
                exit;
            }

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT id, saldo FROM carteiras WHERE cliente_id = ? AND status = 'ativo'");
                $stmt->bind_param("i", $cliente_id);
                $stmt->execute();
                $carteira = $stmt->get_result()->fetch_assoc();

                if (!$carteira)
                    throw new Exception('Carteira não encontrada');

                $saldo_anterior = $carteira['saldo'];
                $saldo_novo = $saldo_anterior + $valor;

                $stmt = $conn->prepare("UPDATE carteiras SET saldo = ? WHERE id = ?");
                $stmt->bind_param("di", $saldo_novo, $carteira['id']);
                $stmt->execute();

                $stmt = $conn->prepare("
                    INSERT INTO transacoes_carteira (cliente_id, tipo, valor, descricao, data_transacao)
                    VALUES (?, 'recarga_manual', ?, ?, NOW())
                ");
                $stmt->bind_param("ids", $cliente_id, $valor, $descricao);
                $stmt->execute();

                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Saldo adicionado!']);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'buscar_clientes_credito':
            $busca = $_GET['busca'] ?? '';
            if (strlen($busca) < 2) {
                echo json_encode(['clientes' => []]);
                exit;
            }

            $stmt = $conn->prepare("
                SELECT id, nome_fixo, telefone 
                FROM agenda_clientes 
                WHERE nome_fixo COLLATE utf8mb4_general_ci LIKE ? 
                ORDER BY nome_fixo 
                LIMIT 10
            ");
            $buscaLike = "%$busca%";
            $stmt->bind_param("s", $buscaLike);
            $stmt->execute();
            $result = $stmt->get_result();

            $clientes = [];
            while ($row = $result->fetch_assoc()) {
                $clientes[] = [
                    'id' => $row['id'],
                    'nome_fixo' => $row['nome_fixo'],
                    'telefone_formatado' => formatarTelefone($row['telefone'])
                ];
            }
            echo json_encode(['clientes' => $clientes]);
            break;

        case 'salvar_credito':
            $cliente_id = $_POST['id'] ?? null;
            $limite = floatval($_POST['limite'] ?? 0);
            $vencimento = intval($_POST['vencimento'] ?? 15);

            if (!$cliente_id) {
                echo json_encode(['success' => false, 'error' => 'Cliente inválido']);
                exit;
            }

            // 1. DESCOBRE O LIMITE ANTIGO ANTES DE ATUALIZAR
            $stmt = $conn->prepare("SELECT id, credito_limite FROM carteiras WHERE cliente_id = ?");
            $stmt->bind_param("i", $cliente_id);
            $stmt->execute();
            $existe = $stmt->get_result()->fetch_assoc();

            $limite_antigo = $existe ? floatval($existe['credito_limite']) : 0;
            $diferenca = $limite - $limite_antigo; // Calcula exatamente o que você adicionou ou tirou

            if ($existe) {
                $stmt = $conn->prepare("UPDATE carteiras SET credito_limite = ?, dia_vencimento = ? WHERE cliente_id = ?");
                $stmt->bind_param("dii", $limite, $vencimento, $cliente_id);
                $stmt->execute();
                $desc = "Limite Mensal Atualizado";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO carteiras (cliente_id, saldo, credito_limite, credito_usado, dia_vencimento, status, data_criacao) 
                    VALUES (?, 0, ?, 0, ?, 'ativo', NOW())
                ");
                $stmt->bind_param("idi", $cliente_id, $limite, $vencimento);
                $stmt->execute();
                $desc = "Crédito Mensal Liberado";
            }

            // 2. CORREÇÃO MÁGICA: Grava no histórico APENAS a diferença!
            if ($diferenca != 0) {
                $stmt_hist = $conn->prepare("INSERT INTO transacoes_carteira (cliente_id, tipo, valor, descricao, data_transacao) VALUES (?, 'ajuste_admin', ?, ?, NOW())");
                $stmt_hist->bind_param("ids", $cliente_id, $diferenca, $desc);
                $stmt_hist->execute();
            }

            echo json_encode(['success' => true]);
            break;

        case 'atualizar_credito_completo':
            $id = $_POST['id'];
            $limite = floatval($_POST['limite']);
            $vencimento = intval($_POST['vencimento']);

            // 1. DESCOBRE O LIMITE ANTIGO
            $stmt_old = $conn->prepare("SELECT credito_limite FROM carteiras WHERE cliente_id = ?");
            $stmt_old->bind_param("i", $id);
            $stmt_old->execute();
            $old_data = $stmt_old->get_result()->fetch_assoc();
            $limite_antigo = $old_data ? floatval($old_data['credito_limite']) : 0;

            $diferenca = $limite - $limite_antigo; // O pulo do gato

            $stmt = $conn->prepare("UPDATE carteiras SET credito_limite = ?, dia_vencimento = ? WHERE cliente_id = ?");
            $stmt->bind_param("dii", $limite, $vencimento, $id);

            if ($stmt->execute()) {
                // 2. CORREÇÃO MÁGICA: Só grava a diferença (seja positiva ou negativa)
                if ($diferenca != 0) {
                    $desc = ($diferenca > 0) ? "Aumento de Limite Mensal" : "Redução de Limite Mensal";
                    $stmt_hist = $conn->prepare("INSERT INTO transacoes_carteira (cliente_id, tipo, valor, descricao, data_transacao) VALUES (?, 'ajuste_admin', ?, ?, NOW())");
                    $stmt_hist->bind_param("ids", $id, $diferenca, $desc);
                    $stmt_hist->execute();
                }

                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
            break;

        case 'cobrar_credito':
            $cliente_id = $_POST['cliente_id'] ?? null;
            // CORREÇÃO: Pegando o id_whatsapp em vez do telefone para o robô não se perder
            $stmt = $conn->prepare("SELECT a.nome_fixo, a.id_whatsapp, c.credito_usado FROM agenda_clientes a JOIN carteiras c ON a.id = c.cliente_id WHERE a.id = ?");
            $stmt->bind_param("i", $cliente_id);
            $stmt->execute();
            $dados = $stmt->get_result()->fetch_assoc();

            if ($dados && $dados['credito_usado'] > 0) {
                $payload = json_encode([
                    'telefone' => $dados['id_whatsapp'], // Agora envia a rota garantida
                    'nome' => $dados['nome_fixo'],
                    'valor' => $dados['credito_usado'],
                    'cliente_id' => $cliente_id
                ]);

                $ch = curl_init('http://localhost:3000/api/enviar-cobranca-credito');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                curl_exec($ch);
                curl_close($ch);

                echo json_encode(['success' => true, 'message' => 'Cobrança enviada com sucesso pro WhatsApp do cliente!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Cliente não possui fatura pendente.']);
            }
            break;

        case 'receber_credito_manual':
            $cliente_id = $_POST['cliente_id'] ?? null;
            // Captura o valor que o admin digitou no prompt (se não vier, assume tudo)
            $valor_pago_input = isset($_POST['valor_pago']) ? floatval($_POST['valor_pago']) : null;

            $stmt = $conn->prepare("
                SELECT c.credito_usado, c.credito_limite, a.nome_fixo, a.id_whatsapp 
                FROM carteiras c 
                JOIN agenda_clientes a ON c.cliente_id = a.id 
                WHERE c.cliente_id = ?
            ");
            $stmt->bind_param("i", $cliente_id);
            $stmt->execute();
            $dados = $stmt->get_result()->fetch_assoc();

            $divida_atual = $dados['credito_usado'] ?? 0;

            // Se não informou valor, entende-se que pagou a dívida toda
            $valor_a_abater = ($valor_pago_input !== null) ? $valor_pago_input : $divida_atual;

            if ($divida_atual > 0 && $valor_a_abater > 0) {
                // O novo valor usado será o atual menos o que foi pago
                $novo_credito_usado = max(0, $divida_atual - $valor_a_abater);

                // 1. Atualiza a carteira com o novo saldo devedor
                $stmt_up = $conn->prepare("UPDATE carteiras SET credito_usado = ? WHERE cliente_id = ?");
                $stmt_up->bind_param("di", $novo_credito_usado, $cliente_id);
                $stmt_up->execute();

                // 2. Grava no histórico o valor que entrou no caixa
                $desc = ($valor_a_abater < $divida_atual) ? "Pagamento Parcial Fatura" : "Pagamento Total Fatura";
                $stmt_hist = $conn->prepare("INSERT INTO transacoes_carteira (cliente_id, tipo, valor, descricao, data_transacao) VALUES (?, 'ajuste_admin', ?, ?, NOW())");
                $stmt_hist->bind_param("ids", $cliente_id, $valor_a_abater, $desc);
                $stmt_hist->execute();

                // --- PREPARAÇÃO DA MENSAGEM ---
                $jid = $dados['id_whatsapp'];
                if (!str_contains($jid, '@')) {
                    $jid = (strlen($jid) >= 14) ? $jid . "@lid" : $jid . "@s.whatsapp.net";
                }

                $valor_f = number_format($valor_a_abater, 2, ',', '.');
                $restante_f = number_format($novo_credito_usado, 2, ',', '.');
                $disponivel_f = number_format($dados['credito_limite'] - $novo_credito_usado, 2, ',', '.');

                $msg = "✅ *PAGAMENTO RECEBIDO NA LOJA!*\n\n";
                $msg .= "Olá, *{$dados['nome_fixo']}*!\n";
                $msg .= "Registramos o pagamento manual de *R$ $valor_f*.\n\n";

                if ($novo_credito_usado > 0) {
                    $msg .= "📉 Saldo devedor restante: *R$ $restante_f*\n";
                } else {
                    $msg .= "🎊 Sua fatura foi *TOTALMENTE QUITADA*!\n";
                }

                $msg .= "💳 Limite disponível: *R$ $disponivel_f*\n\n";
                $msg .= "O crédito na *Mercado Silveira* foi atualizado! 🍀";

                $payload = json_encode(['telefone' => $jid, 'mensagem' => $msg]);
                $ch = curl_init('http://localhost:3000/api/enviar-mensagem-simples');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                curl_exec($ch);
                curl_close($ch);

                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Não há valor pendente para este cliente.']);
            }
            break;

        case 'remover_credito_completo':
            $cliente_id = $_POST['cliente_id'] ?? null;
            $zerar_divida = $_POST['zerar_divida'] ?? 'false';

            if ($zerar_divida === 'true') {
                // Zera o limite e zera a dívida fantasma
                $stmt = $conn->prepare("UPDATE carteiras SET credito_limite = 0, credito_usado = 0 WHERE cliente_id = ?");
            } else {
                // Remove apenas o limite
                $stmt = $conn->prepare("UPDATE carteiras SET credito_limite = 0 WHERE cliente_id = ?");
            }
            $stmt->bind_param("i", $cliente_id);
            echo json_encode(['success' => $stmt->execute()]);
            break;

        case 'bloquear_com_aviso':
            $id = $_POST['id'] ?? null;
            $telefone = $_POST['telefone'] ?? null;
            $nome = $_POST['nome'] ?? '';

            $stmt = $conn->prepare("UPDATE carteiras SET status = 'bloqueado' WHERE cliente_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();

            $msg = "🚫 *AVISO IMPORTANTE*\n\nOlá $nome,\nSua carteira e limite de crédito foram *bloqueados temporariamente*.\n\nVocê não poderá realizar novas reservas usando saldo ou fiado até a regularização. Entre em contato com a administração.";
            $payload = json_encode(['telefone' => $telefone, 'mensagem' => $msg]);
            $ch = curl_init('http://localhost:3000/api/enviar-mensagem-simples');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_exec($ch);
            curl_close($ch);

            echo json_encode(['success' => true]);
            break;

        case 'zerar_saldo_carteira':
            $cliente_id = $_POST['cliente_id'] ?? null;
            if (!$cliente_id) {
                echo json_encode(['success' => false, 'error' => 'ID do cliente não informado.']);
                exit;
            }

            // Atualiza a carteira APENAS zerando o SALDO. A dívida e limite mensal ficam intactos!
            $stmt = $conn->prepare("UPDATE carteiras SET saldo = 0 WHERE cliente_id = ?");
            $stmt->bind_param("i", $cliente_id);

            if ($stmt->execute()) {
                // Grava no histórico que o admin zerou o saldo
                $stmt_hist = $conn->prepare("INSERT INTO transacoes_carteira (cliente_id, tipo, valor, descricao, data_transacao) VALUES (?, 'ajuste_admin', 0, 'Saldo zerado pelo Administrador', NOW())");
                $stmt_hist->bind_param("i", $cliente_id);
                $stmt_hist->execute();

                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Erro ao zerar o saldo.']);
            }
            break;

        case 'desbloquear_com_aviso':
            $id = $_POST['id'] ?? null;
            $telefone = $_POST['telefone'] ?? null;
            $nome = $_POST['nome'] ?? '';

            $stmt = $conn->prepare("UPDATE carteiras SET status = 'ativo' WHERE cliente_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();

            $msg = "✅ *CONTA LIBERADA*\n\nOlá $nome,\nSeu acesso ao limite de crédito e carteira foi *restabelecido* com sucesso!\n\nVocê já pode voltar a realizar suas reservas. 🍀";
            $payload = json_encode(['telefone' => $telefone, 'mensagem' => $msg]);
            $ch = curl_init('http://localhost:3000/api/enviar-mensagem-simples');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_exec($ch);
            curl_close($ch);

            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}