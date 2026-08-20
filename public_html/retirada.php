<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_logado']) && isset($_COOKIE['dking_lembrar']) && $_COOKIE['dking_lembrar'] === 'sim') {
    $_SESSION['admin_logado'] = true;
}
if (!isset($_SESSION['admin_logado']) || $_SESSION['admin_logado'] !== true) {
    header("Location: login.php");
    exit;
}

include 'db.php';

// ==========================================
// 1. LÓGICA DA LISTA E BOTÕES DE AÇÃO
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = isset($_POST['pagina']) ? (int) $_POST['pagina'] : 1;

    if (isset($_POST['baixar_busca'])) {
        $b = $conn->real_escape_string($_POST['baixar_busca']);
        $aba = $conn->real_escape_string($_POST['tab']);
        if (!empty($b)) {
           $conn->query("UPDATE ganhadores_premios gp 
              JOIN sorteios s ON gp.sorteio_id = s.id 
              SET gp.status_retirada = 'entregue', gp.no_carrinho = 0, gp.data_retirada = NOW() 
              WHERE (gp.status_retirada = 'pendente' OR gp.status_retirada IS NULL OR gp.status_retirada = '') 
              AND (gp.nome_cliente LIKE '$b%' OR gp.nome_cliente LIKE '% $b%')");
            header("Location: retirada.php?tab=$aba&q=" . urlencode($b));
            exit;
        }
    }

    if (isset($_POST['toggle_carrinho'])) {
        $id = (int) $_POST['toggle_carrinho'];
        $acao = (int) $_POST['acao_status'];
        $conn->query("UPDATE ganhadores_premios SET no_carrinho = $acao WHERE id = $id");
        exit;
    }

    if (isset($_POST['remover_item_agrupado'])) {
        $nome_c = $conn->real_escape_string($_POST['cliente_nome']);
        $premio = $conn->real_escape_string($_POST['remover_item_agrupado']);
        $conn->query("UPDATE ganhadores_premios SET no_carrinho = 0 WHERE no_carrinho = 1 AND nome_cliente = '$nome_c' AND premio = '$premio'");
    }
    
    if (isset($_POST['cancelar_lista_toda'])) {
        $conn->query("UPDATE ganhadores_premios SET no_carrinho = 0 WHERE no_carrinho = 1");
    }
    
    if (isset($_POST['baixar_selecionados'])) {
        $conn->query("UPDATE ganhadores_premios SET status_retirada = 'entregue', no_carrinho = 0, data_retirada = NOW() WHERE no_carrinho = 1");
        header("Location: retirada.php?status=sucesso_selecionados");
        exit;
    }

    if (isset($_POST['finalizar_cliente'])) {
        $nome_c = $conn->real_escape_string($_POST['finalizar_cliente']);
        $conn->query("UPDATE ganhadores_premios SET status_retirada = 'entregue', no_carrinho = 0, data_retirada = NOW() WHERE no_carrinho = 1 AND nome_cliente = '$nome_c'");
        header("Location: retirada.php?status=sucesso_cliente");
        exit;
    }
    
    if (isset($_POST['entregar'])) {
        $v_id = (int) $_POST['entregar'];
        $conn->query("UPDATE ganhadores_premios SET status_retirada = 'entregue', no_carrinho = 0, data_retirada = NOW() WHERE id = $v_id");
        header("Location: retirada.php?tab=" . $_POST['tab'] . "&q=" . urlencode($_POST['q']) . "&data=" . $_POST['data'] . "&pagina=" . $p);
        exit;
    }
}

// ==========================================
// 2. BUSCA E FILTROS & PAGINAÇÃO (APENAS PENDENTES)
// ==========================================
$abaAtiva = (isset($_GET['tab']) && in_array($_GET['tab'], ['carnes', 'bebidas', 'teste'])) ? $_GET['tab'] : 'carnes';
$busca = isset($_GET['q']) ? $conn->real_escape_string(trim($_GET['q'])) : '';
$filtroData = isset($_GET['data']) ? $_GET['data'] : date('Y-m-d');
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'data';

// Filtro estrito para pegar somente pendentes
$statusFiltro = " AND (gp.status_retirada = 'pendente' OR gp.status_retirada IS NULL OR gp.status_retirada = '') ";

if (!empty($busca)) {
    $where = "$statusFiltro AND (gp.nome_cliente LIKE '$busca%' OR gp.nome_cliente LIKE '% $busca%')";
} else {
    $where = "$statusFiltro AND DATE(gp.data_ganho) = '$filtroData'";
}

if ($sort === 'nome') {
    $orderBy = "ORDER BY gp.nome_cliente ASC, gp.data_ganho DESC";
} else {
    $orderBy = "ORDER BY gp.data_ganho DESC";
}

$limite_por_pagina = 20;
$pagina = isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1;
if ($pagina < 1) $pagina = 1;
$offset = ($pagina - 1) * $limite_por_pagina;

$sql_count = "SELECT COUNT(*) as total FROM ganhadores_premios gp JOIN sorteios s ON gp.sorteio_id = s.id WHERE 1=1 $where";
$resCount = $conn->query($sql_count);
$total_registros = $resCount ? $resCount->fetch_assoc()['total'] : 0;
$total_paginas = ceil($total_registros / $limite_por_pagina);

$sql_lista = "SELECT gp.*, s.titulo FROM ganhadores_premios gp JOIN sorteios s ON gp.sorteio_id = s.id WHERE 1=1 $where $orderBy LIMIT $limite_por_pagina OFFSET $offset";
$res = $conn->query($sql_lista);

$itens_inicio = ($total_registros > 0) ? $offset + 1 : 0;
$itens_fim = min($offset + $limite_por_pagina, $total_registros);

// ==========================================
// 3. AGRUPAMENTO DO CARRINHO (SELECIONADOS)
// ==========================================
$resLista = $conn->query("SELECT nome_cliente, premio, COUNT(*) as quantidade FROM ganhadores_premios WHERE no_carrinho = 1 GROUP BY nome_cliente, premio ORDER BY nome_cliente ASC, premio ASC");

$itensFila = [];
$total_itens_carrinho = 0;
$clientes_no_carrinho = 0;

while ($row = $resLista->fetch_assoc()) {
    $cliente = $row['nome_cliente'];
    if (!isset($itensFila[$cliente])) {
        $itensFila[$cliente] = [];
        $clientes_no_carrinho++;
    }
    $itensFila[$cliente][] = [
        'premio' => $row['premio'],
        'quantidade' => $row['quantidade']
    ];
    $total_itens_carrinho += $row['quantidade'];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Retirada - Mercado Silveira</title>
    <link rel="stylesheet" href="assets/css/global.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/retirada.css?v=<?php echo time(); ?>">
    <style>
        .paginacao { display: flex; justify-content: center; gap: 8px; margin-top: 10px; padding-bottom: 20px; }
        .btn-paginacao { padding: 8px 14px; background: #f1f5f9; color: #1e293b; border-radius: 6px; text-decoration: none; font-weight: 800; font-size: 13px; border: 1px solid #cbd5e1; transition: 0.2s; }
        .btn-paginacao:hover { background: #e2e8f0; }
        .btn-paginacao.ativo { background: #10b981; color: white; border-color: #10b981; }
        .info-paginacao { text-align: center; color: #64748b; font-size: 13px; font-weight: bold; margin-top: 25px; margin-bottom: 5px; }
        .qtd-badge { background: #1e293b; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-right: 8px; font-weight: 900; }
        .checkbox-retirada { width: 18px; height: 18px; cursor: pointer; accent-color: #10b981; }

        @media print {
            body * { visibility: hidden; }
            .painel-tabela-full, .painel-tabela-full * { visibility: visible; }
            .painel-tabela-full { position: absolute; left: 0; top: 0; width: 100%; padding: 0; margin: 0; box-shadow: none; border: none; }
            .btn-lista-add, .btn-remover-lista, .btn-retirada-direta, .paginacao, .info-paginacao, .form-busca-retirada, .checkbox-retirada { display: none !important; }
            table th:last-child, table td:last-child { display: none !important; }
            table { width: 100% !important; border-collapse: collapse !important; }
            table th, table td { border: 1px solid #000 !important; padding: 5px !important; font-size: 14px !important; color: #000 !important; }
        }
    </style>
</head>

<body>
    <?php include 'componentes/sidebar.php'; ?>
    <div class="conteudo-principal">

        <div id="listaSeparacao" class="painel-lista-ativa <?php echo ($total_itens_carrinho == 0) ? 'vazia' : ''; ?>">
            <div class="header-lista-mobile" style="flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn-tela-cheia" onclick="toggleTelaCheia()" id="btnTelaCheia">⛶ TELA INTEIRA</button>
                    <button type="button" class="btn-imprimir" onclick="window.print()">🖨️ IMPRIMIR LISTA</button>
                </div>
                <h3>📝 Lista de Separação (<?php echo $total_itens_carrinho; ?> itens / <?php echo $clientes_no_carrinho; ?> clientes)</h3>
                
                <div style="display: flex; align-items: center; gap: 15px; margin-left: auto;">
                    <div style="background: #0f172a; color: #38bdf8; padding: 6px 14px; border-radius: 6px; font-weight: 800; font-size: 14px; border: 1px solid #334155;">
                        Selecionados: <?php echo $total_itens_carrinho; ?> de <?php echo $total_registros; ?>
                    </div>
                    <?php if ($total_itens_carrinho > 0): ?>
                    <form method="POST" onsubmit="return confirm('Deseja dar baixa em todos os prêmios selecionados?')">
                        <button type="submit" name="baixar_selecionados" style="background: #10b981; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 800; cursor: pointer;">✔️ BAIXAR SELECIONADOS</button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" onsubmit="return confirm('Deseja limpar/cancelar a seleção?')">
                        <button type="submit" name="cancelar_lista_toda" class="btn-limpar-lista">CANCELAR</button>
                    </form>
                </div>
            </div>

            <div class="corpo-lista-mobile">
                <?php if ($total_itens_carrinho > 0):
                    foreach ($itensFila as $cliente => $premios): ?>
                        <div class="grupo-cliente-lista">
                            <div class="header-cliente-lista">
                                <strong>👤 <?php echo $cliente; ?></strong>
                                <form method="POST" onsubmit="return confirm('Confirmar entrega para <?php echo $cliente; ?>?')">
                                    <button type="submit" name="finalizar_cliente" value="<?php echo htmlspecialchars($cliente); ?>" class="btn-baixar-cliente">BAIXAR ESTE CLIENTE</button>
                                </form>
                            </div>
                            <?php foreach ($premios as $p): ?>
                                <div class="item-agrupado">
                                    <span>
                                        <span class="qtd-badge"><?php echo $p['quantidade']; ?>x</span>
                                        <?php echo $p['premio']; ?>
                                    </span>
                                    <form method="POST">
                                        <input type="hidden" name="cliente_nome" value="<?php echo htmlspecialchars($cliente); ?>">
                                        <button type="submit" name="remover_item_agrupado" value="<?php echo htmlspecialchars($p['premio']); ?>" class="btn-tirar-item" title="Remover da lista">❌</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; else: ?>
                    <p style="text-align:center; color:#94a3b8; padding:30px;">Nenhum item selecionado para retirar.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="area-busca-retirada" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <form method="GET" class="form-busca-retirada" style="margin: 0; display: flex; align-items: center;">
                <input type="hidden" name="tab" value="<?php echo $abaAtiva; ?>">
                <input type="hidden" name="sort" value="<?php echo $sort; ?>">
                <input type="date" name="data" value="<?php echo $filtroData; ?>" class="input-data" onchange="this.form.submit()">
                <span style="color:#cbd5e1; margin:0 15px;">|</span>
                <input type="text" name="q" placeholder="Buscar ganhador..." value="<?php echo htmlspecialchars($busca); ?>" class="input-busca-retirada">
                <button type="submit">PESQUISAR</button>
            </form>

            <div style="display: flex; gap: 10px;">
                <?php if (!empty($busca)): ?>
                <form method="POST" onsubmit="return confirm('ATENÇÃO: Deseja confirmar a entrega de TODOS os prêmios filtrados para a pesquisa: <?php echo htmlspecialchars($busca); ?>?')">
                    <input type="hidden" name="tab" value="<?php echo $abaAtiva; ?>">
                    <input type="hidden" name="baixar_busca" value="<?php echo htmlspecialchars($busca); ?>">
                    <button type="submit" style="background: #10b981; font-weight: 800; padding: 10px 20px; border-radius: 6px; color: white; border: none; cursor: pointer; display: flex; align-items: center; gap: 5px; box-shadow: 0 4px 6px rgba(16, 185, 129, 0.2);">✔️ BAIXAR PESQUISA</button>
                </form>
                <?php endif; ?>
                <button type="button" onclick="window.print()" style="background: #3b82f6; font-weight: 800; padding: 10px 20px; border-radius: 6px; color: white; border: none; cursor: pointer; display: flex; align-items: center; gap: 5px; box-shadow: 0 4px 6px rgba(59, 130, 246, 0.2);">🖨️ IMPRIMIR</button>
            </div>
        </div>

        <div class="painel-tabela-full">
            <table class="tabela-retirada-full">
                <thead>
                    <tr>
                        <th style="width: 5%; text-align: center;">SELECIONAR</th>
                        <th style="width: 25%">DATA / SORTEIO</th>
                        <th style="width: 25%">
                            <a href="?tab=<?php echo $abaAtiva; ?>&data=<?php echo $filtroData; ?>&q=<?php echo urlencode($busca); ?>&sort=nome" 
                               style="color: inherit; text-decoration: none; cursor: pointer; display: flex; align-items: center; gap: 5px;" 
                               title="Clique para ordenar por nome">
                                GANHADOR ↕
                            </a>
                        </th>
                        <th style="width: 30%">PRÊMIO</th>
                        <th>AÇÕES</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($res && $res->num_rows > 0): ?>
                        <?php while ($v = $res->fetch_assoc()): ?>
                            <tr style="<?php echo ($v['no_carrinho'] == 1) ? 'background:#f0f9ff; border-left:4px solid #6366f1;' : ''; ?>">
                                <td style="text-align: center;">
                                    <input type="checkbox" class="checkbox-retirada" value="<?php echo $v['id']; ?>" 
                                        <?php echo ($v['no_carrinho'] == 1) ? 'checked' : ''; ?> 
                                        onchange="atualizarCarrinho(this, <?php echo $v['id']; ?>)">
                                </td>
                                <td>
                                    <small style="color: #64748b; font-weight: 700; display: block; margin-bottom: 2px;">
                                        <?php echo date('d/m H:i', strtotime($v['data_ganho'])); ?>
                                    </small>
                                    <b style="color: #0f172a; font-size: 13px;">
                                        <?php echo $v['titulo']; ?> #<?php echo $v['sorteio_id']; ?>
                                    </b>
                                </td>
                                <td><b><?php echo $v['nome_cliente']; ?></b></td>
                                <td><?php echo $v['premio']; ?></td>
                                <td style="display:flex; gap:8px;">
                                    <form method="POST" onsubmit="return confirm('Confirmar entrega imediata?')">
                                        <input type="hidden" name="entregar" value="<?php echo $v['id']; ?>">
                                        <input type="hidden" name="tab" value="<?php echo $abaAtiva; ?>">
                                        <input type="hidden" name="q" value="<?php echo $busca; ?>">
                                        <input type="hidden" name="data" value="<?php echo $filtroData; ?>">
                                        <input type="hidden" name="pagina" value="<?php echo $pagina; ?>">
                                        <button type="submit" class="btn-retirada-direta">RETIRADA</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding:30px; color:#94a3b8;">Nenhum prêmio pendente encontrado.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_registros > 0): ?>
                <div class="info-paginacao">Exibindo <?php echo $itens_inicio; ?> a <?php echo $itens_fim; ?> de <?php echo $total_registros; ?> prêmios pendentes</div>
            <?php endif; ?>

            <?php if ($total_paginas > 1): ?>
                <div class="paginacao">
                    <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                        <a href="?tab=<?php echo $abaAtiva; ?>&data=<?php echo $filtroData; ?>&q=<?php echo urlencode($busca); ?>&sort=<?php echo $sort; ?>&pagina=<?php echo $i; ?>" class="btn-paginacao <?php echo ($i == $pagina) ? 'ativo' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function toggleTelaCheia() {
            var painel = document.getElementById('listaSeparacao');
            var btn = document.getElementById('btnTelaCheia');
            painel.classList.toggle('tela-cheia');
            if (painel.classList.contains('tela-cheia')) {
                btn.innerHTML = '❌ FECHAR TELA INTEIRA';
                btn.style.background = '#ef4444';
            } else {
                btn.innerHTML = '⛶ TELA INTEIRA';
                btn.style.background = '#10b981';
            }
        }

        function atualizarCarrinho(checkbox, id) {
            let acao = checkbox.checked ? 1 : 0;
            let formData = new FormData();
            formData.append('toggle_carrinho', id);
            formData.append('acao_status', acao);

            fetch('retirada.php', {
                method: 'POST',
                body: formData
            }).then(response => {
                window.location.reload();
            });
        }
    </script>
</body>
</html>