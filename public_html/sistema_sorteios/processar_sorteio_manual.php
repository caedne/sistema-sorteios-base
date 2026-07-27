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
// processar_sorteio_manual.php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Método inválido');
}

$sorteio_id = intval($_POST['sorteio_id']);
$categoria = $_POST['categoria'];

if (!$sorteio_id) {
    die('Erro: Sorteio inválido');
}

try {
    // 1. Busca dados do sorteio
    $stmt = $conn->prepare("SELECT * FROM sorteios WHERE id = ?");
    $stmt->bind_param("i", $sorteio_id);
    $stmt->execute();
    $sorteio = $stmt->get_result()->fetch_assoc();
    
    if (!$sorteio) {
        die('Erro: Sorteio não encontrado');
    }
    
    $premios = explode('|||', $sorteio['premios']);
    
    // 2. Limpa ganhadores anteriores (se houver)
    $conn->query("DELETE FROM ganhadores_premios WHERE sorteio_id = $sorteio_id");
    
    // 3. Salva cada ganhador
    $ganhadores = [];
    for ($i = 0; $i < count($premios); $i++) {
        $numero = intval($_POST["numero_$i"]);
        $nome = $_POST["nome_$i"];
        $premio = $_POST["premio_$i"];
        
        // Busca telefone E id_whatsapp E cliente_id
        $stmtTel = $conn->prepare("SELECT v.telefone, v.id_whatsapp, v.cliente_id FROM vendas v WHERE v.sorteio_id = ? AND v.numero_escolhido = ?");
        $stmtTel->bind_param("ii", $sorteio_id, $numero);
        $stmtTel->execute();
        $resultTel = $stmtTel->get_result();
        
        $telefone = null;
        $id_whatsapp = null;
        $cliente_id = null;
        
        if ($resultTel->num_rows > 0) {
            $dadosVenda = $resultTel->fetch_assoc();
            $telefone = $dadosVenda['telefone'];
            $id_whatsapp = $dadosVenda['id_whatsapp'];
            $cliente_id = $dadosVenda['cliente_id'];
        }
        
        // Insere ganhador COM id_whatsapp e cliente_id
        $stmtInsert = $conn->prepare(
            "INSERT INTO ganhadores_premios 
            (cliente_id, id_whatsapp, sorteio_id, numero_sorteado, nome_cliente, telefone, premio, data_ganho, status_retirada) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'pendente')"
        );
        $stmtInsert->bind_param("isiisss", $cliente_id, $id_whatsapp, $sorteio_id, $numero, $nome, $telefone, $premio);
        $stmtInsert->execute();
        
        // ========== CORREÇÃO 1: INCREMENTA total_premios ==========
        // Cada prêmio ganho incrementa o contador na agenda
        if ($cliente_id) {
            $stmtInc = $conn->prepare("UPDATE agenda_clientes SET total_premios = total_premios + 1 WHERE id = ?");
            $stmtInc->bind_param("i", $cliente_id);
            $stmtInc->execute();
        }
        // =======================================================
        
        $ganhadores[] = [
            'numero' => $numero,
            'nome' => $nome,
            'telefone' => $telefone,
            'premio' => $premio
        ];
    }
    
    // 4. Atualiza status do sorteio
    $conn->query("UPDATE sorteios SET status = 'finalizado' WHERE id = $sorteio_id");
    
    // 5. Chama API do robô para enviar mensagens
    $urlApi = 'http://localhost:3000/api/enviar-resultado-manual';
    
    $dadosEnvio = [
        'sorteio_id' => $sorteio_id,
        'categoria' => $categoria,
        'ganhadores' => $ganhadores,
        'titulo' => $sorteio['titulo']
    ];
    
    $ch = curl_init($urlApi);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dadosEnvio));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("Erro ao enviar mensagens via API: HTTP $httpCode");
    }
    
    // ========== CORREÇÃO 2: NÃO DELETA MAIS AS VENDAS ==========
    // LINHA REMOVIDA: $conn->query("DELETE FROM vendas WHERE sorteio_id = $sorteio_id");
    // As vendas ficam no banco como histórico
    // ==========================================================
    
    // 6. Redireciona com sucesso
    header("Location: jogos_ativos.php?cat=$categoria&sorteio_realizado=1");
    exit;
    
} catch (Exception $e) {
    error_log("Erro ao processar sorteio manual: " . $e->getMessage());
    header("Location: jogos_ativos.php?cat=$categoria&erro=1");
    exit;
}
?>