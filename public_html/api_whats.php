<?php
// Envia avisos de reserva e pagamento
function avisarRobo($dados) {
    $url = 'http://localhost:3000/webhook-venda';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    curl_exec($ch);
    curl_close($ch);
}

// NOVA FUNÇÃO: Envia o card do sorteio assim que é ativado
function avisarRoboNovoSorteio($dados) {
    $url = 'http://localhost:3000/novo-sorteio';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    curl_exec($ch);
    curl_close($ch);
}
?>