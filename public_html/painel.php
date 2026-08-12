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
$conn = new mysqli('localhost', 'root', '', 'dking_premios');

if (isset($_POST['zerar_rifa'])) {
    $nova_qtd = $_POST['nova_qtd'];
    $conn->query("TRUNCATE TABLE rifas");
    for ($i = 1; $i <= $nova_qtd; $i++) {
        $conn->query("INSERT INTO rifas (numero, status) VALUES ($i, 'disponivel')");
    }
    $conn->query("UPDATE configuracoes SET total_numeros = $nova_qtd");
    echo "<script>alert('Rifa Zerada!'); window.location.href='painel.php';</script>";
}

if (isset($_POST['salvar_config'])) {
    $nome_rifa = $_POST['nome_rifa'];
    $lista_premios = $_POST['lista_premios'];
    $valor = $_POST['valor'];
    $pix = $_POST['pix'];
    $qtd_premios = $_POST['qtd_premios'];
    $stmt = $conn->prepare("UPDATE configuracoes SET nome_rifa=?, lista_premios=?, valor_numero=?, chave_pix=?, qtd_premios=? WHERE id=1");
    $stmt->bind_param("ssdsi", $nome_rifa, $lista_premios, $valor, $pix, $qtd_premios);
    $stmt->execute();
    echo "<script>alert('Salvo!'); window.location.href='painel.php';</script>";
}

$config = $conn->query("SELECT * FROM configuracoes LIMIT 1")->fetch_assoc();
$total_numeros = $config['total_numeros'];
$vendidos = $conn->query("SELECT COUNT(*) as total FROM rifas WHERE status = 'pago'")->fetch_assoc()['total'];
$faturamento = $vendidos * $config['valor_numero'];
$chance_base = ($total_numeros > 0) ? ($config['qtd_premios'] / $total_numeros) * 100 : 0;
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Admin</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Ajustes extras só pro painel */
        .container { max-width: 1100px; margin: 0 auto; text-align: left; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); text-align: center; }
        .form-section { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        input, textarea { width: 95%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 10px; }
        .grid-mini { display: grid; grid-template-columns: repeat(auto-fill, minmax(30px, 1fr)); gap: 3px; }
        .n-mini { font-size: 10px; padding: 5px; color: white; text-align: center; border-radius: 3px; }
    </style>
</head>
<body>

<div class="container">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h1 style="border:none;">👑 Painel D-King</h1>
        <a href="index.php" target="_blank" class="btn-sortear" style="padding:10px 20px; font-size:14px; text-decoration:none;">Ver Site</a>
    </div>

    <div style="display:flex; gap:20px; margin-bottom:30px;">
        <div class="card" style="flex:1;">VENDIDOS<br><strong style="font-size:24px"><?php echo $vendidos; ?> / <?php echo $total_numeros; ?></strong></div>
        <div class="card" style="flex:1;">CAIXA<br><strong style="font-size:24px; color:#27ae60">R$ <?php echo number_format($faturamento, 2, ',', '.'); ?></strong></div>
        <div class="card" style="flex:1;">CHANCE (1 NÚMERO)<br><strong style="font-size:24px; color:#f39c12"><?php echo number_format($chance_base, 2); ?>%</strong></div>
    </div>

    <div style="display:flex; gap:30px;">
        <div style="flex:1;">
            <div class="form-section">
                <h2 style="margin-top:0;">⚙️ Configuração</h2>
                <form method="POST">
                    <label>Nome da Rifa:</label>
                    <input type="text" name="nome_rifa" value="<?php echo $config['nome_rifa']; ?>">
                    
                    <label>Prêmios:</label>
                    <textarea name="lista_premios" rows="4"><?php echo $config['lista_premios']; ?></textarea>

                    <label>Calculadora (Qtd Prêmios):</label>
                    <input type="number" name="qtd_premios" value="<?php echo $config['qtd_premios']; ?>">

                    <label>Valor (R$):</label>
                    <input type="number" step="0.01" name="valor" value="<?php echo $config['valor_numero']; ?>">

                    <label>Chave PIX:</label>
                    <input type="text" name="pix" value="<?php echo $config['chave_pix']; ?>">

                    <button type="submit" name="salvar_config" class="btn-sortear" style="width:100%; font-size:16px;">Salvar</button>
                </form>
            </div>
        </div>

        <div style="flex:1;">
            <div class="form-section" style="border: 2px solid #e74c3c;">
                <h2 style="color:#c0392b; margin-top:0;">🚨 Resetar Rifa</h2>
                <form method="POST" onsubmit="return confirm('Certeza absoluta?');">
                    <label>Nova Quantidade de Números:</label>
                    <input type="number" name="nova_qtd" value="100">
                    <button type="submit" name="zerar_rifa" class="btn-sortear" style="background:#c0392b; width:100%; font-size:16px;">ZERAR TUDO</button>
                </form>
            </div>

            <div class="form-section">
                <h3>Mapa</h3>
                <div class="grid-mini">
                    <?php 
                    $mapa = $conn->query("SELECT * FROM rifas");
                    while($n = $mapa->fetch_assoc()):
                        $classe = ($n['status'] == 'pago') ? 'pago' : (($n['status'] == 'reservado') ? 'reser' : 'livre');
                        // Ajuste para livre ficar cinza no painel
                        if($n['status'] == 'disponivel') $classe = 'livre'; 
                    ?>
                        <div class="n-mini <?php echo $classe; ?>" style="<?php if($classe=='livre') echo 'background:#ccc;'; ?>"><?php echo $n['numero']; ?></div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>