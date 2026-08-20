<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Se a sessão expirou, mas o PC tem o Cookie de 30 dias, libera o acesso silenciosamente
if (!isset($_SESSION['admin_logado']) && isset($_COOKIE['dking_lembrar']) && $_COOKIE['dking_lembrar'] === 'sim') {
    $_SESSION['admin_logado'] = true;
}
if (!isset($_SESSION['admin_logado']) || $_SESSION['admin_logado'] !== true) {
    header("Location: login.php");
    exit;
}
include 'db.php';

$cliente_id = $_GET['cliente_id'] ?? null;

if (!$cliente_id) {
    header('Location: carteiras.php');
    exit;
}

// Buscar info do cliente
$stmt = $conn->prepare("SELECT nome_fixo, telefone FROM agenda_clientes WHERE id = ?");
$stmt->bind_param("i", $cliente_id);
$stmt->execute();
$cliente = $stmt->get_result()->fetch_assoc();

if (!$cliente) {
    header('Location: carteiras.php');
    exit;
}

// Buscar transações
$stmt = $conn->prepare("
    SELECT * FROM transacoes_carteira 
    WHERE cliente_id = ? 
      AND descricao NOT LIKE 'FECHAMENTO%'
    ORDER BY data_transacao DESC 
    LIMIT 100
");
$stmt->bind_param("i", $cliente_id);
$stmt->execute();
$transacoes = $stmt->get_result();

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
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Histórico - <?php echo htmlspecialchars($cliente['nome_fixo']); ?> | D'KING</title>
    <!-- CORRIGIDO: Removido o '../' dos caminhos -->
    <link rel="stylesheet" href="assets/css/global.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    <link rel="stylesheet" href="assets/css/carteiras.css">
</head>

<body>
    <div class="layout-sistema">
        <!-- CORRIGIDO: Removido o '../' do include -->
        <aside class="sidebar"><?php include 'componentes/sidebar.php'; ?></aside>

        <main class="conteudo-principal">
            <div class="container">

                <div class="header">
                    <div>
                        <h1>📜 Histórico Financeiro</h1>
                        <p style="color:#64748b; margin-top:10px;">
                            <strong><?php echo htmlspecialchars($cliente['nome_fixo']); ?></strong> -
                            <?php echo formatarTelefone($cliente['telefone']); ?>
                        </p>
                    </div>
                    <button class="btn-novo" onclick="location.href='carteiras.php'" style="background:#64748b;">
                        ← Voltar
                    </button>
                </div>

                <div class="tabela">
                    <?php if ($transacoes && $transacoes->num_rows > 0): ?>
                        <table
                            style="border-collapse: collapse; width: 100%; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                            <thead>
                                <tr>
                                    <th
                                        style="background: #1e293b; color: #facc15; padding: 12px 15px; text-align: left; font-size: 11px; font-weight: 800; text-transform: uppercase;">
                                        Data</th>
                                    <th
                                        style="background: #1e293b; color: #facc15; padding: 12px 15px; text-align: left; font-size: 11px; font-weight: 800; text-transform: uppercase;">
                                        Origem</th>
                                    <th
                                        style="background: #1e293b; color: #facc15; padding: 12px 15px; text-align: left; font-size: 11px; font-weight: 800; text-transform: uppercase;">
                                        Ação / Descrição</th>
                                    <th
                                        style="background: #1e293b; color: #facc15; padding: 12px 15px; text-align: left; font-size: 11px; font-weight: 800; text-transform: uppercase;">
                                        Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($t = $transacoes->fetch_assoc()):
                                    $cor = 'saldo-positivo';
                                    $sinal = '+ ';
                                    $cor_texto_valor = '#16a34a';
                                    $tipo_texto = '';

                                    if ($t['tipo'] === 'compra_saldo' || $t['tipo'] === 'compra_credito') {
                                        $sinal = '- ';
                                        $cor_texto_valor = '#ef4444';
                                    }

                                    switch ($t['tipo']) {
                                        case 'compra_saldo':
                                            $tipo_texto = '🛒 Compra c/ Saldo';
                                            break;
                                        case 'compra_credito':
                                            $tipo_texto = '🛒 Compra no Fiado';
                                            break;
                                        case 'recarga_manual':
                                            $tipo_texto = '💰 Recarga Manual';
                                            break;
                                        case 'recarga_pix':
                                            $tipo_texto = '📱 Pix (WhatsApp)';
                                            break;
                                        case 'ajuste_admin':
                                        case 'pagamento_credito':
                                            if (stripos($t['descricao'], 'Pagamento') !== false) {
                                                $tipo_texto = '💵 Fatura Paga';
                                            } elseif (stripos($t['descricao'], 'Limite') !== false || stripos($t['descricao'], 'Liberado') !== false) {
                                                $tipo_texto = '💳 Crédito Mensal';
                                            } else {
                                                $tipo_texto = '⚙️ Painel Admin';
                                            }
                                            break;
                                        case 'estorno':
                                            $tipo_texto = '↩️ Estorno';
                                            break;
                                        default:
                                            $tipo_texto = '📝 ' . ucfirst(str_replace('_', ' ', $t['tipo']));
                                    }
                                    ?>
                                    <tr>
                                        <td
                                            style="padding: 10px 15px; border-bottom: 1px solid #f1f5f9; color:#64748b; font-size:12px; font-weight:600; white-space:nowrap;">
                                            <?php echo date('d/m/Y H:i', strtotime($t['data_transacao'])); ?>
                                        </td>

                                        <td style="padding: 10px 15px; border-bottom: 1px solid #f1f5f9;">
                                            <span
                                                style="background:#f1f5f9; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:800; color:#475569; text-transform:uppercase;">
                                                <?php echo $tipo_texto; ?>
                                            </span>
                                        </td>

                                        <td
                                            style="padding: 10px 15px; border-bottom: 1px solid #f1f5f9; font-weight:600; color:#334155; font-size: 13px;">
                                            <?php echo htmlspecialchars($t['descricao']); ?>
                                        </td>

                                        <td style="padding: 10px 15px; border-bottom: 1px solid #f1f5f9;">
                                            <span
                                                style="font-weight:900; font-size:15px; color:<?php echo $cor_texto_valor; ?>;">
                                                <?php echo $sinal; ?>R$ <?php echo number_format($t['valor'], 2, ',', '.'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="vazio">
                            <h3>📜 Nenhuma transação financeira</h3>
                            <p>Este cliente não possui histórico de recargas ou limite mensal.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>
</body>

</html>