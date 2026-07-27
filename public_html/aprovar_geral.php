<?php
// Arquivo: public_html/aprovar_geral.php
// FUNÇÃO: Transforma apenas as reservas do sorteio selecionado em 'pago'.

date_default_timezone_set('America/Sao_Paulo');
$host = "localhost";
$user = "merc_mercadosilveira_user";
$pass = "De04081986##";
$db   = "merc_mercadosilveira_db";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) { die("Erro de Conexão"); }

// Captura o ID que vem do botão do painel
$sorteio_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

echo "<h1>Painel de Aprovação Manual - Mercado Silveira</h1>";

if ($sorteio_id > 0) {
    // O COMANDO CORRIGIDO: Agora filtra por sorteio_id para não misturar categorias
    $sql = "UPDATE vendas SET status_venda = 'pago' 
            WHERE status_venda = 'reservado' 
            AND sorteio_id = $sorteio_id";

    if ($conn->query($sql) === TRUE) {
        $afetados = $conn->affected_rows;
        if ($afetados > 0) {
            echo "<h2 style='color:green'>SUCESSO: $afetados reservas do sorteio #$sorteio_id foram pagas!</h2>";
        } else {
            echo "<h2 style='color:blue'>Nenhuma reserva pendente para o sorteio #$sorteio_id.</h2>";
        }
    } else {
        echo "Erro ao atualizar: " . $conn->error;
    }
} else {
    echo "<h2 style='color:red'>ERRO: Nenhum ID de sorteio foi fornecido.</h2>";
}

$conn->close();
?>