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

$id = $_GET['id'] ?? 0;

if ($id <= 0) {
    die("ID do cliente inválido. Volte e tente novamente.");
}

// 1. Busca Dados do Cliente
$sql = "SELECT * FROM agenda_clientes WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$cliente = $stmt->get_result()->fetch_assoc();

if (!$cliente) {
    echo "<script>alert('Cliente não encontrado!');window.location='agenda.php';</script>";
    exit;
}

$id_whatsapp = $cliente['id_whatsapp'] ?? '';
$mes_atual = date('m');
$ano_atual = date('Y');

// 2. Busca Carteira (Saldo e Crédito)
$saldo = 0;
$limite = 0;
$usado = 0;

$stmt_cart = $conn->prepare("SELECT saldo, credito_limite, credito_usado FROM carteiras WHERE cliente_id = ?");
if ($stmt_cart) {
    $stmt_cart->bind_param("i", $id);
    $stmt_cart->execute();
    $res_cart = $stmt_cart->get_result()->fetch_assoc();
    if ($res_cart) {
        $saldo = $res_cart['saldo'];
        $limite = $res_cart['credito_limite'];
        $usado = $res_cart['credito_usado'];
    }
}

$disponivel = $limite - $usado;

// =========================================================================
// 3. ESTATÍSTICAS MENSAIS E GERAIS
// =========================================================================

// Total de Jogadas (Mês e Geral)
$sql_jogadas = "SELECT 
    COUNT(*) as total_geral,
    SUM(CASE WHEN MONTH(data_reserva) = ? AND YEAR(data_reserva) = ? THEN 1 ELSE 0 END) as total_mes
    FROM vendas v 
    WHERE (v.cliente_id = ? OR (v.id_whatsapp = ? AND v.id_whatsapp != '')) 
    AND v.status_venda IN ('pago', 'ganhador', 'pendente')";

$stmt_jogadas = $conn->prepare($sql_jogadas);
$stmt_jogadas->bind_param("ssis", $mes_atual, $ano_atual, $id, $id_whatsapp);
$stmt_jogadas->execute();
$res_j = $stmt_jogadas->get_result()->fetch_assoc();

$total_jogadas_geral = $res_j['total_geral'] ?? 0;
$total_jogadas_mes = $res_j['total_mes'] ?? 0;

// Prêmios (Mês e Geral)
$sql_premios = "SELECT 
    COUNT(DISTINCT gp.id) as total_geral,
    COUNT(DISTINCT CASE WHEN MONTH(gp.data_ganho) = ? AND YEAR(gp.data_ganho) = ? THEN gp.id END) as total_mes
    FROM ganhadores_premios gp
    LEFT JOIN vendas v ON gp.sorteio_id = v.sorteio_id AND CAST(gp.numero_sorteado AS UNSIGNED) = CAST(v.numero_escolhido AS UNSIGNED)
    WHERE gp.cliente_id = ? OR (gp.id_whatsapp = ? AND gp.id_whatsapp != '') OR v.cliente_id = ? OR (v.id_whatsapp = ? AND v.id_whatsapp != '')";

$stmt_premios = $conn->prepare($sql_premios);
$stmt_premios->bind_param("ssisis", $mes_atual, $ano_atual, $id, $id_whatsapp, $id, $id_whatsapp);
$stmt_premios->execute();
$res_p = $stmt_premios->get_result()->fetch_assoc();

$total_premios_geral = $res_p['total_geral'] ?? 0;
$total_premios_mes = $res_p['total_mes'] ?? 0;


// =========================================================================
// 4. HISTÓRICO COMPLETO (JOGADAS + EXTRATO DA CARTEIRA)
// =========================================================================
$historico = [];

// A) Busca todas as Vendas/Jogadas
$sql_vendas = "
    SELECT v.sorteio_id, s.titulo, s.categoria, s.numero_visual, s.valor_numero,
           MAX(v.data_reserva) as data_acao, MAX(v.forma_pagamento) as forma_pagamento, MAX(v.status_venda) as status_venda,
           GROUP_CONCAT(v.numero_escolhido ORDER BY v.numero_escolhido ASC SEPARATOR ', ') as numeros,
           COUNT(v.id) as qtd_comprada
    FROM vendas v
    JOIN sorteios s ON v.sorteio_id = s.id
    WHERE (v.cliente_id = ? OR (v.id_whatsapp = ? AND v.id_whatsapp != ''))
      AND v.status_venda IN ('pago', 'ganhador', 'estornado')
    GROUP BY v.sorteio_id, s.titulo, s.categoria, s.numero_visual, s.valor_numero
";
$stmt_v = $conn->prepare($sql_vendas);
$stmt_v->bind_param("is", $id, $id_whatsapp);
$stmt_v->execute();
$res_v = $stmt_v->get_result();

while ($r = $res_v->fetch_assoc()) {
    $sid = $r['sorteio_id'];
    $historico['V_' . $sid] = [
        'is_transacao' => false,
        'sorteio_id' => $sid,
        'data' => $r['data_acao'],
        'titulo' => $r['titulo'],
        'categoria' => $r['categoria'],
        'numero_visual' => $r['numero_visual'],
        'numeros' => $r['numeros'],
        'valor_total' => $r['qtd_comprada'] * $r['valor_numero'],
        'forma_pagamento' => $r['forma_pagamento'],
        'status_venda' => $r['status_venda'],
        'premios' => [],
        'status_retirada' => 'pendente',
        'data_retirada' => null
    ];
}

// B) Varre a tabela de Prêmios para anexar nas Vendas
$stmt_p = $conn->prepare("
    SELECT DISTINCT gp.*, s.titulo, s.categoria, s.numero_visual, s.valor_numero 
    FROM ganhadores_premios gp
    JOIN sorteios s ON gp.sorteio_id = s.id
    LEFT JOIN vendas v ON gp.sorteio_id = v.sorteio_id AND CAST(gp.numero_sorteado AS UNSIGNED) = CAST(v.numero_escolhido AS UNSIGNED)
    WHERE gp.cliente_id = ? OR (gp.id_whatsapp = ? AND gp.id_whatsapp != '') OR v.cliente_id = ? OR (v.id_whatsapp = ? AND v.id_whatsapp != '')
");
$stmt_p->bind_param("isis", $id, $id_whatsapp, $id, $id_whatsapp);
$stmt_p->execute();
$res_p = $stmt_p->get_result();

while ($r = $res_p->fetch_assoc()) {
    $sid = $r['sorteio_id'];
    if (!isset($historico['V_' . $sid])) {
        $historico['V_' . $sid] = [
            'is_transacao' => false,
            'sorteio_id' => $sid,
            'data' => $r['data_ganho'],
            'titulo' => $r['titulo'],
            'categoria' => $r['categoria'],
            'numero_visual' => $r['numero_visual'],
            'numeros' => $r['numero_sorteado'],
            'valor_total' => $r['valor_numero'],
            'forma_pagamento' => null,
            'status_venda' => 'ganhador',
            'premios' => [],
            'status_retirada' => 'pendente',
            'data_retirada' => null
        ];
    }
    $historico['V_' . $sid]['premios'][] = $r['premio'] . ' (Nº ' . $r['numero_sorteado'] . ')';
    $status = strtolower($r['status_retirada'] ?? 'pendente');
    if ($status === 'entregue' || $status === 'retirado') {
        $historico['V_' . $sid]['status_retirada'] = 'entregue';
        if (!empty($r['data_retirada']))
            $historico['V_' . $sid]['data_retirada'] = $r['data_retirada'];
    }
}

// C) Busca as Transações de Carteira (Estornos, Recargas, Ajustes)
$sql_trans = "SELECT id, tipo, valor, descricao, data_transacao FROM transacoes_carteira WHERE cliente_id = ? AND tipo NOT IN ('compra_saldo', 'compra_credito')";
$stmt_t = $conn->prepare($sql_trans);
$stmt_t->bind_param("i", $id);
$stmt_t->execute();
$res_t = $stmt_t->get_result();

while ($r = $res_t->fetch_assoc()) {
    $historico['T_' . $r['id']] = [
        'is_transacao' => true,
        'data' => $r['data_transacao'],
        'titulo' => $r['descricao'],
        'categoria' => 'CARTEIRA',
        'numero_visual' => '',
        'numeros' => '-',
        'valor_total' => $r['valor'],
        'forma_pagamento' => $r['tipo'],
        'status_venda' => 'concluido',
        'premios' => [],
        'status_retirada' => '-',
        'data_retirada' => null
    ];
}


// ORDENAÇÃO E FILTRO DE RETIRADA
$ordem = $_GET['ordem'] ?? 'data';
$historico_lista = array_values($historico);

usort($historico_lista, function ($a, $b) use ($ordem) {
    if ($ordem === 'retirada') {
        $pesoA = count($a['premios']) > 0 ? ($a['status_retirada'] === 'pendente' ? 1 : 2) : 3;
        $pesoB = count($b['premios']) > 0 ? ($b['status_retirada'] === 'pendente' ? 1 : 2) : 3;
        if ($pesoA === $pesoB)
            return strtotime($b['data']) - strtotime($a['data']);
        return $pesoA - $pesoB;
    } else {
        return strtotime($b['data']) - strtotime($a['data']);
    }
});

// PAGINAÇÃO
$pagina = isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1;
if ($pagina < 1)
    $pagina = 1;

$limite_por_pagina = 25; // Aumentado para ver mais transações
$total_registros = count($historico_lista);
$total_paginas = ceil($total_registros / $limite_por_pagina);
$offset = ($pagina - 1) * $limite_por_pagina;

$historico_paginado = array_slice($historico_lista, $offset, $limite_por_pagina);

function formatarTelefone($telefone)
{
    $tel = preg_replace('/\D/', '', $telefone);
    if (strlen($tel) == 13)
        $tel = substr($tel, 2);
    if (strlen($tel) == 11)
        return '(' . substr($tel, 0, 2) . ') ' . substr($tel, 2, 5) . '-' . substr($tel, 7);
    return $telefone;
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Perfil | <?php echo htmlspecialchars($cliente['nome_fixo']); ?></title>
    <link rel="stylesheet" href="../assets/css/global.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../assets/css/sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../assets/css/detalhes_cliente.css?v=<?php echo time(); ?>">
</head>

<body>
    <div class="layout-sistema">
        <aside class="sidebar"><?php include 'componentes/sidebar.php'; ?></aside>

        <main class="conteudo-principal">
            <div class="container">

                <a href="agenda.php" class="btn-voltar">⬅ Voltar para Agenda</a>

                <div class="perfil-header">
                    <div class="perfil-dados">
                        <h1><?php echo htmlspecialchars($cliente['nome_fixo']); ?></h1>
                        <div class="meta">
                            📞 <?php echo formatarTelefone($cliente['telefone']); ?>
                            <?php if ($cliente['nome_whatsapp']): ?>
                                | 📱 WhatsApp: <?php echo htmlspecialchars($cliente['nome_whatsapp']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="financeiro-grid">

                    <!-- SALDO EM CONTA -->
                    <div class="fin-card destaque">
                        <h4>💰 Saldo em Conta</h4>
                        <div class="valor">R$ <?php echo number_format($saldo, 2, ',', '.'); ?></div>
                        <div class="legenda-mes">Dinheiro livre na carteira</div>
                        <a href="carteiras.php?cliente_id=<?php echo $id; ?>&acao=adicionar"
                            class="btn-adicionar-credito">
                            💲 Adicionar Crédito
                        </a>
                    </div>

                    <!-- CRÉDITO MENSAL PADRONIZADO -->
                    <div class="fin-card" style="border-left: 5px solid #3b82f6;">
                        <h4>💳 Crédito Mensal</h4>
                        <?php if ($limite > 0): ?>
                            <div class="valor" style="color: #3b82f6;">R$
                                <?php echo number_format($disponivel, 2, ',', '.'); ?>
                            </div>
                            <div class="legenda-mes">Dívida Atual: R$ <?php echo number_format($usado, 2, ',', '.'); ?>
                            </div>
                            <a href="credito_mensal.php" class="btn-adicionar-credito"
                                style="background:#cbd5e1; color:#1e293b;">
                                ⚙️ Gerenciar
                            </a>
                        <?php else: ?>
                            <div class="valor" style="color:#94a3b8;">R$ 0,00</div>
                            <div class="legenda-mes" style="color:#94a3b8;">Sem limite fiado ativo</div>
                            <a href="credito_mensal.php" class="btn-adicionar-credito"
                                style="background:#cbd5e1; color:#1e293b;">
                                ⚙️ Ativar Fiado
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- TOTAL JOGADO -->
                    <div class="fin-card" style="border-left: 5px solid #8b5cf6;">
                        <h4>🎮 Total Jogado (Mês)</h4>
                        <div class="valor" style="color:#8b5cf6"><?php echo $total_jogadas_mes; ?></div>
                        <div class="legenda-mes">Números no mês atual</div>
                        <div class="legenda-geral">Geral/Sempre: <b><?php echo $total_jogadas_geral; ?></b> compras
                        </div>
                    </div>

                    <!-- PRÊMIOS -->
                    <div class="fin-card" style="border-left: 5px solid #f59e0b;">
                        <h4>🏆 Prêmios (Mês)</h4>
                        <div class="valor" style="color:#f59e0b"><?php echo $total_premios_mes; ?></div>
                        <div class="legenda-mes">Prêmios ganhos no mês atual</div>
                        <div class="legenda-geral">Geral/Sempre: <b><?php echo $total_premios_geral; ?></b> prêmios
                        </div>
                    </div>

                </div>

                <div class="historico-container">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="margin: 0;">📜 Histórico Financeiro e Jogadas</h3>
                    </div>

                    <table class="tabela-historico">
                        <thead>
                            <tr>
                                <th>Data/Hora</th>
                                <th>Ação / Sorteio</th>
                                <th>Números Jogados</th>
                                <th>Valor Total</th>
                                <th>Origem / Destino</th>
                                <th>Prêmio</th>
                                <th>
                                    <a href="?id=<?php echo $id; ?>&ordem=<?php echo ($ordem == 'retirada' ? 'data' : 'retirada'); ?>"
                                        style="color: #facc15; text-decoration: none; display: flex; align-items: center; gap: 5px;"
                                        class="filtro-retirada" title="Clique para ordenar prêmios pendentes/retirados">
                                        Status Retirada <?php echo ($ordem == 'retirada') ? '⬇' : '↕'; ?>
                                    </a>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($historico_paginado) > 0): ?>
                                <?php foreach ($historico_paginado as $h):
                                    $data_formatada = date('d/m/y H:i', strtotime($h['data']));
                                    $is_transacao = isset($h['is_transacao']) && $h['is_transacao'];
                                    $tem_premio = count($h['premios']) > 0;

                                    // Cor do fundo para Prêmios ou Estornos
                                    $bg_tr = '';
                                    if ($tem_premio)
                                        $bg_tr = 'background-color: #fefce8;';
                                    if ($h['status_venda'] === 'estornado' || ($is_transacao && strpos(strtolower($h['forma_pagamento']), 'estorno') !== false))
                                        $bg_tr = 'background-color: #fef2f2;';
                                    ?>
                                    <tr style="<?php echo $bg_tr; ?>">

                                        <!-- DATA -->
                                        <td
                                            style="white-space:nowrap; font-size:12px; color:#64748b; font-weight: 600; padding: 10px;">
                                            <?php echo $data_formatada; ?>
                                        </td>

                                        <!-- NOME DA AÇÃO / SORTEIO -->
                                        <td style="padding: 10px;">
                                            <?php if ($is_transacao): ?>
                                                <strong style="color:#1e293b; font-size:12px;">💸
                                                    <?php echo htmlspecialchars($h['titulo']); ?></strong>
                                            <?php else:
                                                $cat = strtolower($h['categoria'] ?? '');
                                                $cor_cat = '#64748b';
                                                if ($cat == 'carnes')
                                                    $cor_cat = '#ef4444';
                                                if ($cat == 'bebidas')
                                                    $cor_cat = '#f59e0b';
                                                ?>
                                                <span
                                                    style="background:<?php echo $cor_cat; ?>; color:white; padding:2px 6px; border-radius:4px; font-size:9px; font-weight:900; text-transform:uppercase; margin-right:4px;">
                                                    <?php echo htmlspecialchars($cat); ?>
                                                </span>
                                                <strong
                                                    style="color:#1e293b; font-size:12px;"><?php echo htmlspecialchars($h['titulo']); ?></strong>
                                                <?php if (!empty($h['numero_visual'])): ?>
                                                    <small
                                                        style="color:#94a3b8; font-weight:bold;">#<?php echo str_pad($h['numero_visual'], 2, '0', STR_PAD_LEFT); ?></small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>

                                        <!-- NÚMEROS -->
                                        <td style="padding: 10px;">
                                            <?php if ($is_transacao): ?>
                                                <span style="color:#cbd5e1;">-</span>
                                            <?php else: ?>
                                                <div class="texto-numeros" title="<?php echo htmlspecialchars($h['numeros']); ?>">
                                                    <?php echo htmlspecialchars($h['numeros']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- VALOR -->
                                        <td
                                            style="font-weight:900; color:<?php echo $is_transacao ? '#16a34a' : '#1e293b'; ?>; white-space:nowrap; padding: 10px;">
                                            <?php echo $is_transacao ? '+ R$' : 'R$'; ?>
                                            <?php echo number_format($h['valor_total'], 2, ',', '.'); ?>
                                        </td>

                                        <!-- FORMA/ORIGEM -->
                                        <td style="padding: 10px;">
                                            <?php
                                            $forma = strtolower($h['forma_pagamento'] ?? '');

                                            if ($is_transacao) {
                                                // TAGS PARA MOVIMENTAÇÃO DE CARTEIRA (ENTRADA DE DINHEIRO)
                                                $desc_lower = strtolower($h['titulo'] ?? '');
                                                if (strpos($forma, 'estorno') !== false) {
                                                    echo '<span class="badge-estorno">+ ESTORNO PRA CARTEIRA</span>';
                                                } elseif (strpos($forma, 'recarga_pix') !== false || strpos($desc_lower, 'whatsapp') !== false) {
                                                    echo '<span class="badge-recarga">+ RECARGA PIX (BOT)</span>';
                                                } elseif ($forma === 'recarga_manual' || strpos($desc_lower, 'saldo inicial') !== false || strpos($desc_lower, 'adicionado') !== false || strpos($desc_lower, 'pelo administrador') !== false) {
                                                    echo '<span class="badge-admin">+ SALDO ADMIN</span>';
                                                } elseif (strpos($desc_lower, 'crédito') !== false || strpos($desc_lower, 'limite') !== false || strpos($desc_lower, 'fatura') !== false) {
                                                    echo '<span class="badge-mensal">CRÉDITO MENSAL</span>';
                                                } else {
                                                    echo '<span class="badge-admin">AJUSTE SISTEMA</span>';
                                                }
                                            } else {
                                                // TAGS PARA COMPRA DE SORTEIO (SAÍDA DE DINHEIRO)
                                                if ($h['status_venda'] === 'estornado') {
                                                    echo '<span class="badge-falha">ESTORNADO</span>';
                                                } elseif ($forma === 'carteira_credito') {
                                                    echo '<span class="badge-mensal">FIADO MENSAL</span>';
                                                } elseif ($forma === 'carteira_saldo' || $forma === 'carteira' || $forma === 'carteira_misto') {
                                                    echo '<span class="badge-saldo">SALDO CARTEIRA</span>';
                                                } elseif ($forma === '' || $forma === null) {
                                                    echo '<span class="badge-admin-pago">PAGO ADMIN</span>';
                                                } else {
                                                    echo '<span class="badge-pix">PIX DIRETO</span>';
                                                }
                                            }
                                            ?>
                                        </td>

                                        <!-- PRÊMIO -->
                                        <td
                                            style="font-weight:700; color:#d97706; font-size: 12px; line-height: 1.5; padding: 10px;">
                                            <?php if ($tem_premio): ?>
                                                🏆 <?php echo implode('<br>🏆 ', array_map('htmlspecialchars', $h['premios'])); ?>
                                            <?php else: ?>
                                                <span style="color:#cbd5e1; font-weight: normal;">-</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- STATUS DA RETIRADA -->
                                        <td style="padding: 10px;">
                                            <?php
                                            if ($tem_premio) {
                                                if ($h['status_retirada'] === 'entregue') {
                                                    $dt_str = !empty($h['data_retirada']) ? date('d/m/y H:i', strtotime($h['data_retirada'])) : 'Retirado';
                                                    echo '<span class="badge-entregue">✅ ' . $dt_str . '</span>';
                                                } else {
                                                    echo '<span class="badge-pendente">⏳ Pendente</span>';
                                                }
                                            } else {
                                                echo '<span style="color:#cbd5e1; font-size: 12px;">-</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="sem-dados">Nenhum histórico encontrado para este cliente.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ($total_paginas > 1): ?>
                        <div class="paginacao">
                            <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                                <a href="?id=<?php echo $id; ?>&ordem=<?php echo $ordem; ?>&pagina=<?php echo $i; ?>"
                                    class="btn-paginacao <?php echo ($i == $pagina) ? 'ativo' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </main>
    </div>
</body>

</html>