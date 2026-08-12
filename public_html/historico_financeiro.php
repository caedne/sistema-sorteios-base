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

// Pega as datas do filtro (Se não tiver, o padrão é o dia de HOJE)
$data_inicio = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : date('Y-m-d');
$data_fim = isset($_GET['data_fim']) ? $_GET['data_fim'] : date('Y-m-d');
$categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : 'todas';

// 1. MÁGICA DA BUSCA: Filtra apenas sorteios que não estão ativos ou cancelados, e remove testes
$where = "s.status NOT IN ('ativo', 'cancelado', 'inativo') AND s.categoria != 'testes'";
$params = [];
$types = "";

if ($categoria_filtro !== 'todas') {
    $where .= " AND s.categoria = ?";
    $params[] = $categoria_filtro;
    $types .= "s";
}

$params[] = $data_inicio;
$params[] = $data_fim;
$types .= "ss";

// 2. MÁGICA DO AGRUPAMENTO (IGUAL AO RETIRADA.PHP)
// Agora o sistema cruza com a tabela 'ganhadores_premios' e puxa a data exata em que o prêmio foi sorteado (data_ganho).
$sql = "
    SELECT 
        s.id as sorteio_id,
        s.titulo,
        s.numero_visual,
        s.categoria,
        s.valor_numero,
        MAX(gp.data_ganho) as data_fechamento,
        (SELECT COUNT(*) FROM vendas v WHERE v.sorteio_id = s.id AND v.status_venda IN ('pago', 'ganhador')) as qtd_vendida,
        COUNT(DISTINCT gp.id) as qtd_premios
    FROM sorteios s
    JOIN ganhadores_premios gp ON s.id = gp.sorteio_id
    WHERE $where
    GROUP BY s.id, s.titulo, s.numero_visual, s.categoria, s.valor_numero
    HAVING DATE(data_fechamento) >= ? AND DATE(data_fechamento) <= ?
    ORDER BY data_fechamento DESC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$sorteios_vendidos = [];
$total_geral_arrecadado = 0;
$total_geral_numeros = 0;
$total_geral_premios = 0;

while ($row = $result->fetch_assoc()) {
    // Calcula o valor arrecadado (Qtd Vendida x Valor da Cota)
    $row['total_arrecadado'] = $row['qtd_vendida'] * $row['valor_numero'];

    $sorteios_vendidos[] = $row;
    $total_geral_arrecadado += $row['total_arrecadado'];
    $total_geral_numeros += $row['qtd_vendida'];
    $total_geral_premios += $row['qtd_premios'];
}

// Cores das Categorias
function getCorCategoria($cat)
{
    $cat = strtolower($cat);
    if ($cat == 'carnes')
        return '#ef4444';
    if ($cat == 'bebidas')
        return '#f59e0b';
    return '#64748b';
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Histórico Financeiro | Mercado Silveira</title>
    <link rel="stylesheet" href="assets/css/global.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/historico_financeiro.css?v=<?php echo time(); ?>">
</head>

<body>
    <div class="layout-sistema">
        <aside class="sidebar"><?php include 'componentes/sidebar.php'; ?></aside>
        <main class="conteudo-principal">
            <div class="container">
                <h1 style="color: #1e293b; margin-bottom: 20px; font-weight: 900;">📈 Histórico Financeiro</h1>

                <form method="GET" class="filtros-financeiro">
                    <div class="filtro-grupo">
                        <label>Data Inicial</label>
                        <input type="date" name="data_inicio" class="filtro-input" value="<?php echo $data_inicio; ?>">
                    </div>

                    <div class="filtro-grupo">
                        <label>Data Final</label>
                        <input type="date" name="data_fim" class="filtro-input" value="<?php echo $data_fim; ?>">
                    </div>

                    <div class="filtro-grupo">
                        <label>Categoria</label>
                        <select name="categoria" class="filtro-input">
                            <option value="todas" <?php echo $categoria_filtro == 'todas' ? 'selected' : ''; ?>>Todas as
                                Categorias</option>
                            <option value="carnes" <?php echo $categoria_filtro == 'carnes' ? 'selected' : ''; ?>>🥩
                                Carnes</option>
                            <option value="bebidas" <?php echo $categoria_filtro == 'bebidas' ? 'selected' : ''; ?>>🍺
                                Bebidas</option>
                        </select>
                    </div>

                    <div class="filtro-grupo">
                        <button type="submit" class="btn-filtrar">🔍 Buscar Vendas</button>
                    </div>
                </form>

                <div class="resumo-financeiro-grid">
                    <div class="resumo-card">
                        <div class="resumo-titulo">Total Arrecadado</div>
                        <div class="resumo-valor" style="color: #22c55e;">R$
                            <?php echo number_format($total_geral_arrecadado, 2, ',', '.'); ?></div>
                    </div>

                    <div class="resumo-card azul">
                        <div class="resumo-titulo">Números Vendidos</div>
                        <div class="resumo-valor"><?php echo $total_geral_numeros; ?></div>
                    </div>

                    <div class="resumo-card" style="border-left-color: #f59e0b;">
                        <div class="resumo-titulo">Prêmios Sorteados</div>
                        <div class="resumo-valor" style="color: #f59e0b;">🏆 <?php echo $total_geral_premios; ?></div>
                    </div>

                    <div class="resumo-card roxo">
                        <div class="resumo-titulo">Período Selecionado</div>
                        <div class="resumo-valor" style="font-size: 18px;">
                            <?php echo date('d/m/Y', strtotime($data_inicio)); ?> até
                            <?php echo date('d/m/Y', strtotime($data_fim)); ?>
                        </div>
                    </div>
                </div>

                <div class="tabela-financeira-container">
                    <table class="tabela-financeira">
                        <thead>
                            <tr>
                                <th>Fechamento</th>
                                <th>Categoria</th>
                                <th>Sorteio</th>
                                <th>Valor Cota</th>
                                <th>Qtd. Vendida</th>
                                <th>Prêmios</th>
                                <th>Arrecadado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($sorteios_vendidos) > 0): ?>
                                <?php foreach ($sorteios_vendidos as $venda):
                                    $numVisual = $venda['numero_visual'] ? $venda['numero_visual'] : $venda['sorteio_id'];
                                    $dataHora = date('d/m/Y \à\s H:i', strtotime($venda['data_fechamento']));
                                    ?>
                                    <tr>
                                        <td style="color: #64748b; font-size: 13px; font-weight: bold;">
                                            📅 <?php echo $dataHora; ?>
                                        </td>

                                        <td>
                                            <span class="tag-categoria"
                                                style="background: <?php echo getCorCategoria($venda['categoria']); ?>">
                                                <?php echo htmlspecialchars($venda['categoria']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong
                                                style="color: #1e293b;"><?php echo htmlspecialchars($venda['titulo']); ?></strong>
                                            <small
                                                style="color: #94a3b8; font-weight: bold;">#<?php echo str_pad($numVisual, 2, '0', STR_PAD_LEFT); ?></small>
                                        </td>
                                        <td>R$ <?php echo number_format($venda['valor_numero'], 2, ',', '.'); ?></td>
                                        <td>
                                            <strong
                                                style="background: #f1f5f9; padding: 4px 10px; border-radius: 6px;"><?php echo $venda['qtd_vendida']; ?></strong>
                                        </td>
                                        <td>
                                            <strong
                                                style="background: #fef3c7; color: #d97706; padding: 4px 10px; border-radius: 6px;">🏆
                                                <?php echo $venda['qtd_premios']; ?></strong>
                                        </td>
                                        <td style="color: #16a34a; font-weight: 900;">
                                            R$ <?php echo number_format($venda['total_arrecadado'], 2, ',', '.'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: #64748b; padding: 40px;">
                                        Nenhuma venda finalizada encontrada neste período.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </main>
    </div>
</body>

</html>