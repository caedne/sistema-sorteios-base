<?php
$apiUrl = "http://localhost:8080";
$instanceName = "dkingbot"; 
$apiKey = "chave_mestra_da_evolution";

$ch = curl_init("$apiUrl/group/fetchAllGroups/$instanceName?getParticipants=false");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["apikey: $apiKey"]);
$resposta = curl_exec($ch);
curl_close($ch);

echo "<pre>" . json_encode(json_decode($resposta, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
?>