<?php
$apiUrl = "http://localhost:8080";
$instanceName = "dkingbot";
$apiKey = "chave_mestra_da_evolution";

// Vai direto buscar o QR Code da instância existente
$ch = curl_init("$apiUrl/instance/connect/$instanceName");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $apiKey"
]);

$resposta = curl_exec($ch);
curl_close($ch);

$retorno = json_decode($resposta, true);

echo "<div style='font-family: Arial; text-align: center; margin-top: 50px;'>";
echo "<h2>Conexão do Robô D'King</h2>";

// Procura o base64 do qrcode na resposta da Evolution
$qrcode = $retorno['qrcode']['base64'] ?? $retorno['base64'] ?? null;

if ($qrcode) {
    echo "<p>Abra o WhatsApp do celular do robô e leia o QR Code abaixo:</p>";
    echo "<img src='$qrcode' style='border: 2px solid #22c55e; padding: 10px; border-radius: 10px; width: 300px;' />";
} else {
    echo "<p style='color: #22c55e; font-weight: bold;'>✅ Status da Instância:</p>";
    echo "<pre style='text-align: left; display: inline-block; background: #f1f5f9; padding: 15px; border-radius: 8px;'>" . htmlspecialchars(json_encode($retorno, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "</pre>";
    echo "<p>Se apareceu que já está conectado, o robô está pronto!</p>";
}
echo "</div>";
?>