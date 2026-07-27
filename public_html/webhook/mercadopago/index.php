<?php
// Arquivo: webhook/mercadopago/index.php

// 1. Conecta no Banco de Dados
include '/home/mercadosilveira.dkingsorteios.com.br/public_html/sistema_sorteios/db.php';

// 2. SUA SENHA (TOKEN)
$access_token = 'APP_USR-6651320471200024-012118-324d066a31dd71d2d0a9f1afdb131004-3144592579';

// 3. Recebe os dados
$json = file_get_contents("php://input");
$dados = json_decode($json, true);

// 4. Cria log de teste
file_put_contents('log_mp.txt', date('d/m/Y H:i:s') . " - Recebido\n", FILE_APPEND);

// 5. Verifica pagamento
if (isset($dados['action']) && $dados['action'] == 'payment.updated') {
    $id_pagamento = $dados['data']['id'];
    
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.mercadopago.com/v1/payments/$id_pagamento",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array("Authorization: Bearer " . $access_token),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    $pagamento = json_decode($response, true);

    if (isset($pagamento['status']) && $pagamento['status'] == 'approved') {
        $referencia = $pagamento['external_reference'];
        
        // Atualiza Banco
        $stmt = $conn->prepare("UPDATE vendas SET status_venda = 'pago' WHERE id = ? OR id_pix = ?");
        $stmt->bind_param("ss", $referencia, $id_pagamento);
        $stmt->execute();

        // Avisa Robô
        $busca = $conn->query("SELECT sorteio_id, categoria, telefone FROM vendas WHERE id = '$referencia' OR id_pix = '$id_pagamento' LIMIT 1");
        $venda = $busca->fetch_assoc();

        if ($venda) {
            $dados_robo = [
                'categoria' => $venda['categoria'],
                'status' => 'pago',
                'sorteio_id' => $venda['sorteio_id'],
                'telefone' => $venda['telefone']
            ];
            
            $ch = curl_init('http://localhost:3000/webhook-venda'); 
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados_robo));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            curl_setopt($ch, CURLOPT_TIMEOUT, 2); 
            curl_exec($ch);
            curl_close($ch);
        }
    }
}

http_response_code(200);
echo "OK";
?>