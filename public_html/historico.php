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
include 'db.php';

// CONFIGURAÇÕES
$itensPorPagina = 20;
$p_input = isset($_GET['p']) ? $_GET['p'] : 1;
$paginaAtual = (int)$p_input; 
if ($paginaAtual < 1) $paginaAtual = 1;
$offset = ($paginaAtual - 1) * $itensPorPagina;

$abaAtiva = (isset($_GET['tab']) && in_array($_GET['tab'], ['carnes','bebidas','teste'])) ? $_GET['tab'] : 'carnes';

$filtroData = isset($_GET['data']) && $_GET['data'] != '' ? $_GET['data'] : date('Y-m-d');
$busca = isset($_GET['q']) ? $conn->real_escape_string($_GET['q']) : '';

$where = " AND DATE(gp.data_ganho) = '$filtroData' ";
if ($busca) {
    $where .= " AND (gp.nome_cliente LIKE '%$busca%' OR v.telefone LIKE '%$busca%')";
}

// CONTAGEM DE PAGINAÇÃO
$sqlCount = "
    SELECT COUNT(*) as total FROM (
        SELECT gp.sorteio_id 
        FROM ganhadores_premios gp 
        JOIN sorteios s ON gp.sorteio_id = s.id 
        LEFT JOIN vendas v ON (v.sorteio_id = gp.sorteio_id AND v.numero_escolhido = gp.numero_sorteado) 
        WHERE 1=1 $where 
        GROUP BY gp.sorteio_id
    ) as agrupado
";
$resCount = $conn->query($sqlCount);
$rowTotal = $resCount->fetch_assoc();
$totalRegistros = $rowTotal ? (int)$rowTotal['total'] : 0;
$totalPaginas = ceil($totalRegistros / $itensPorPagina);

// ==============================================================
// NOVO: CALCULAR O VALOR TOTAL DO DIA (Soma de todos os sorteios)
// ==============================================================
$sqlTotalDia = "
    SELECT SUM(IFNULL(agrupado.qtd, 25) * agrupado.valor) as total_dia 
    FROM (
        SELECT s.qtd_numeros as qtd, s.valor_numero as valor 
        FROM ganhadores_premios gp 
        JOIN sorteios s ON gp.sorteio_id = s.id 
        LEFT JOIN vendas v ON (v.sorteio_id = gp.sorteio_id AND v.numero_escolhido = gp.numero_sorteado) 
        WHERE 1=1 $where 
        GROUP BY gp.sorteio_id
    ) as agrupado
";
$resTotalDia = $conn->query($sqlTotalDia);
$rowTotalDia = $resTotalDia->fetch_assoc();
$valorTotalDia = $rowTotalDia ? (float)$rowTotalDia['total_dia'] : 0;
// ==============================================================

// DADOS DA TABELA
$sql = "
    SELECT 
        gp.sorteio_id, 
        MAX(gp.data_ganho) as data_venda, 
        s.titulo, 
        MAX(s.valor_numero) as valor_numero,
        MAX(s.qtd_numeros) as qtd_numeros
    FROM ganhadores_premios gp 
    JOIN sorteios s ON gp.sorteio_id = s.id 
    LEFT JOIN vendas v ON (v.sorteio_id = gp.sorteio_id AND v.numero_escolhido = gp.numero_sorteado) 
    WHERE 1=1 $where 
    GROUP BY gp.sorteio_id 
    ORDER BY data_venda DESC, gp.sorteio_id DESC 
    LIMIT $itensPorPagina OFFSET $offset
";

$res = $conn->query($sql);
$lista = [];

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $sId = $row['sorteio_id'];
        
        // Calculando o valor total do sorteio (Qtd * Valor da Cota)
        $qtd_nums = $row['qtd_numeros'] ? (int)$row['qtd_numeros'] : 25;
        $valor_cota = (float)$row['valor_numero'];
        $valor_total_sorteio = $qtd_nums * $valor_cota;

        $sqlGanhadores = "SELECT * FROM ganhadores_premios WHERE sorteio_id = $sId ORDER BY id ASC";
        $resG = $conn->query($sqlGanhadores);
        
        $detalhesArray = [];
        $rank = 1;
        while($g = $resG->fetch_assoc()) {
            $detalhesArray[] = [
                'ganhador' => $g['nome_cliente'],
                'premio' => $g['premio'],
                'cota' => $g['numero_sorteado'],
                'status' => $g['status_retirada'],
                'rank' => $rank++ . 'º Lugar'
            ];
        }

        $lista[] = [
            'data_venda' => $row['data_venda'], 
            'titulo' => $row['titulo'], 
            'valor_cota' => $valor_cota,
            'qtd_nums' => $qtd_nums,
            'valor_total' => $valor_total_sorteio, 
            'detalhes' => $detalhesArray, 
            'qtd_ganhadores' => count($detalhesArray)
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Histórico - D'King</title>
    <link rel="stylesheet" href="assets/css/global.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/historico.css?v=<?php echo time(); ?>">
</head>
<body>

<?php include 'componentes/sidebar.php'; ?>

<div id="tooltip-master"></div>

<div class="conteudo-principal">

    <div class="area-busca-historico">
        <form method="GET" class="form-busca-historico">
            <input type="hidden" name="tab" value="<?php echo $abaAtiva; ?>">
            <input type="date" name="data" value="<?php echo $filtroData; ?>" class="input-data" onchange="this.form.submit()">
            <span class="separador-busca">|</span>
            <input type="text" name="q" placeholder="Nome ou Telefone..." value="<?php echo htmlspecialchars($busca); ?>" class="input-busca-historico">
            <button type="submit">PESQUISAR</button>
        </form>
    </div>

    <div style="text-align:center; margin-bottom:15px; font-size:12px; color:#64748b;">
        Sorteios: <b><?php echo $totalRegistros; ?></b> | Página: <b><?php echo $paginaAtual; ?></b> de <b><?php echo $totalPaginas ?: 1; ?></b>
    </div>

    <div class="painel-tabela-full">
        <table class="tabela-historico-full">
            <thead>
                <tr>
                    <th style="width:40%">DATA / SORTEIO</th>
                    <th style="width:30%">GANHADORES E PRÊMIOS</th>
                    <th style="width:15%">ARRECADAÇÃO</th>
                    <th style="width:15%; text-align:right;">STATUS</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($lista)): ?>
                    <tr><td colspan="4" style="text-align:center; padding:40px; color:#94a3b8;">Nenhum histórico encontrado.</td></tr>
                <?php else: foreach ($lista as $v): ?>
                    <tr>
                        <td>
                            <div class="linha-combo">
                                <span class="data-hora"><?php echo date('d/m H:i', strtotime($v['data_venda'])); ?></span>
                                <span class="divisoria-vertical"></span>
                                <span class="titulo-sorteio" title="<?php echo $v['titulo']; ?>"><?php echo $v['titulo']; ?></span>
                            </div>
                        </td>
                        <td style="overflow:visible;"> 
                            <span class="link-ver-premios" onmouseover="mostrarTooltip(this)" onmouseout="esconderTooltip()" onmousemove="moverTooltip(event)">
                                🎁 Ver <?php echo $v['qtd_ganhadores']; ?> Ganhadores
                            </span>
                            
                            <div class="conteudo-tooltip-oculto">
                                <div class="tooltip-header">Sorteio: <?php echo $v['titulo']; ?></div>
                                <ul class="tooltip-lista">
                                    <?php foreach($v['detalhes'] as $item): ?>
                                        <li>
                                            <span class="tp-rank"><?php echo $item['rank']; ?></span>
                                            <span class="tp-nome-ganhador"><?php echo $item['ganhador']; ?></span>
                                            <span class="tp-premio"><?php echo $item['premio']; ?></span>
                                            <span class="tp-cota">#<?php echo str_pad($item['cota'], 2, '0', STR_PAD_LEFT); ?></span>
                                            <span class="tp-status"><?php echo ($item['status'] == 'entregue') ? '✅' : '⏳'; ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </td>
                        <td>
                            <strong style="color:#1e293b; font-size:14px;">R$ <?php echo number_format($v['valor_total'], 2, ',', '.'); ?></strong>
                            <br>
                            <small style="color:#64748b; font-size:11px;"><?php echo $v['qtd_nums']; ?> cotas a R$ <?php echo number_format($v['valor_cota'], 2, ',', '.'); ?></small>
                        </td>
                        <td style="text-align:right;"><span class="badge-entregue">✅ FINALIZADO</span></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <div style="background: #1e293b; color: white; padding: 15px 25px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; margin-top: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
            <div style="display: flex; flex-direction: column;">
                <span style="font-size: 14px; font-weight: 800; text-transform: uppercase;">Total Arrecadado</span>
                <span style="font-size: 11px; color: #94a3b8;">Soma de todos os sorteios desta data</span>
            </div>
            <span style="font-size: 22px; font-weight: 900; color: #22c55e;">R$ <?php echo number_format($valorTotalDia, 2, ',', '.'); ?></span>
        </div>

    </div>

    <?php if ($totalPaginas > 1): ?>
    <div class="paginacao-container">
        <?php $baseUrl = "?tab=$abaAtiva&data=$filtroData&q=$busca&p="; ?>
        <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
            <a href="<?php echo $baseUrl . $i; ?>" class="btn-pag <?php echo ($i == $paginaAtual) ? 'ativo' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<script>
// LÓGICA DO TOOLTIP FLUTUANTE (Nunca é cortado)
const tooltipMaster = document.getElementById('tooltip-master');

function mostrarTooltip(elemento) {
    // Pega o HTML escondido logo após o link
    const conteudo = elemento.nextElementSibling.innerHTML;
    tooltipMaster.innerHTML = conteudo;
    tooltipMaster.style.display = 'block';
}

function esconderTooltip() {
    tooltipMaster.style.display = 'none';
}

function moverTooltip(e) {
    // Faz o tooltip seguir o mouse, mas deslocado para não tampar
    // Ajuste X: Se estiver muito na direita, joga pra esquerda
    let x = e.clientX + 15;
    let y = e.clientY + 15;
    
    // Evita sair da tela (direita)
    if (x + 360 > window.innerWidth) {
        x = e.clientX - 365;
    }
    
    // Evita sair da tela (baixo)
    if (y + tooltipMaster.offsetHeight > window.innerHeight) {
        y = e.clientY - tooltipMaster.offsetHeight;
    }

    tooltipMaster.style.left = x + 'px';
    tooltipMaster.style.top = y + 'px';
}

// Garante aba ativa
window.addEventListener('load', () => {
    const cat = '<?php echo $abaAtiva; ?>';
    const btn = document.querySelector(`.btn-capsula[data-cat="${cat}"]`);
    if(btn && !btn.classList.contains('ativo')) {
        document.querySelectorAll('.btn-capsula').forEach(b => b.classList.remove('ativo'));
        btn.classList.add('ativo');
    }
});
</script>
</body>
</html>