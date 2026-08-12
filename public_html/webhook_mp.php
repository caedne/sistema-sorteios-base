<?php
// Arquivo: webhook_mp.php
// Função: Recebe do Mercado Pago e chuta para o Robô (index.js)

// Recebe os dados do Mercado Pago
$json = file_get_contents("php://input");
$dados = json_decode($json, true);

// Se for notificação de pagamento
if (isset($dados['action']) && $dados['action'] == 'payment.updated') {
    
    // Prepara o pacote para mandar pro Robô
    // Manda exatamente o que chegou do Mercado Pago
    
    $ch = curl_init('http://localhost:3000/webhook-mercadopago'); // O endereço interno do robô
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json); // Repassa o JSON original
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    curl_setopt($ch, CURLOPT_TIMEOUT, 2); // Não espera muito, só entrega
    curl_exec($ch);
    curl_close($ch);
}

// Responde pro Mercado Pago que recebeu (pra ele parar de mandar erro)
http_response_code(200);
echo "Recebido e encaminhado para o robo.";
?>
