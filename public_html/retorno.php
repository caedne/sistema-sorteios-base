<?php
// Arquivo: public_html/retorno.php - VERSÃO FINAL (COM ATUALIZAÇÃO DE ID_PIX)
date_default_timezone_set('America/Sao_Paulo');
ini_set('display_errors', 0); 
mysqli_report(MYSQLI_REPORT_OFF);

// --- 1. CONEXÃO ---
$host = "localhost";
$user = "merc_mercadosilveira_user";
$pass = "De04081986##";
$db   = "merc_mercadosilveira_db";

$conn = new mysqli($host, $user, $pass, $db);

// LOG INICIAL
$log_msg = "\n--------------------------------\n";
$log_msg .= date('d/m/Y H:i:s') . " - CHEGOU NOTIFICAÇÃO\n";

if ($conn->connect_error) {
    file_put_contents('log_retorno.txt', $log_msg . "ERRO CONEXÃO: " . $conn->connect_error . "\n", FILE_APPEND);
    die();
}

// --- 2. DADOS DO MP ---
$access_token = 'APP_USR-6651320471200024-012118-324d066a31dd71d2d0a9f1afdb131004-3144592579';
$json = file_get_contents("php://input");
$dados = json_decode($json, true);

if (isset($dados['data']['id'])) {
    $id_pagamento = $dados['data']['id'];

    // Consulta API
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.mercadopago.com/v1/payments/$id_pagamento",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array("Authorization: Bearer " . $access_token),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    $pagamento = json_decode($response, true);

    $status_real = $pagamento['status'] ?? 'erro';
    $ref_banco = $pagamento['external_reference'] ?? '';
    
    $log_msg .= "Status MP: $status_real | ID Pix: $id_pagamento | Ref: $ref_banco\n";

    if ($status_real == 'approved') {
        
        // 1. Limpa a referência para pegar o telefone (Ex: "12345@c.us|99" -> "12345@c.us")
        $partes = explode('|', $ref_banco);
        $telefone_cliente = $partes[0]; 

        $log_msg .= "Localizando cliente: $telefone_cliente\n";

        // 2. ATUALIZAÇÃO INTELIGENTE
        // Marca como 'pago' E TAMBÉM salva o id_pix na coluna nova para ficar registrado
        $sql = "UPDATE vendas SET status_venda = 'pago', id_pix = ? 
                WHERE (id_pix = ? OR telefone = ? OR payment_id = ?) 
                AND status_venda != 'pago'";
                
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            // "ssss" significa que vamos passar 4 strings (id_pix novo, id_pix busca, telefone, payment_id busca)
            $stmt->bind_param("ssss", $id_pagamento, $id_pagamento, $telefone_cliente, $id_pagamento);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $log_msg .= "SUCESSO: Venda paga e ID Pix salvo no banco! Chamando Robô...\n";
                
                // 3. DISPARA O ROBÔ
                $dados_robo = [
                    'status' => 'pago',
                    'telefone' => $telefone_cliente,
                    'pagamento_id' => $id_pagamento
                ];
                
                $ch = curl_init('http://localhost:3000/webhook-venda'); 
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados_robo));
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
                curl_setopt($ch, CURLOPT_TIMEOUT, 3); 
                curl_exec($ch);
                curl_close($ch);
                
            } else {
                $log_msg .= "AVISO: Nenhuma venda atualizada. (Já estava paga ou telefone não bateu)\n";
            }
        } else {
            $log_msg .= "ERRO SQL: " . $conn->error . "\n";
        }
    }
}

file_put_contents('log_retorno.txt', $log_msg, FILE_APPEND);
echo "OK";
?>