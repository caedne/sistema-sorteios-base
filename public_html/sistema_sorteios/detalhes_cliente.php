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

// Cálculos de Crédito
$disponivel = $limite - $usado;
$porcentagem_uso = ($limite > 0) ? ($usado / $limite) * 100 : 0;
if ($porcentagem_uso > 100)
    $porcentagem_uso = 100;

$id_whatsapp = $cliente['id_whatsapp'] ?? '';

// =========================================================================
// 3. ESTATÍSTICAS CORRIGIDAS (NÃO ZERA MAIS)
// =========================================================================

// Total de Prêmios REAIS ganhos por este cliente (Cruzando com as vendas para garantir antigos e novos)
$sql_premios = "SELECT COUNT(DISTINCT gp.id) as total 
                FROM ganhadores_premios gp
                LEFT JOIN vendas v ON gp.sorteio_id = v.sorteio_id AND CAST(gp.numero_sorteado AS UNSIGNED) = CAST(v.numero_escolhido AS UNSIGNED)
                WHERE gp.cliente_id = ? 
                   OR (gp.id_whatsapp = ? AND gp.id_whatsapp != '')
                   OR v.cliente_id = ? 
                   OR (v.id_whatsapp = ? AND v.id_whatsapp != '')";
$stmt_premios = $conn->prepare($sql_premios);
$stmt_premios->bind_param("isis", $id, $id_whatsapp, $id, $id_whatsapp);
$stmt_premios->execute();
$total_premios = $stmt_premios->get_result()->fetch_assoc()['total'] ?? 0;

// Total de Jogadas (Conta tudo que ele comprou na vida)
$sql_jogadas = "SELECT COUNT(*) as total
                FROM vendas v
                WHERE (v.cliente_id = ? OR (v.id_whatsapp = ? AND v.id_whatsapp != ''))
                AND v.status_venda IN ('pago', 'ganhador', 'pendente', 'estornado')"; // <-- Adicionado 'estornado'
$stmt_jogadas = $conn->prepare($sql_jogadas);
$stmt_jogadas->bind_param("is", $id, $id_whatsapp);
$stmt_jogadas->execute();
$total_jogadas = $stmt_jogadas->get_result()->fetch_assoc()['total'] ?? 0;


// =========================================================================
// 4. O SUPER HISTÓRICO DE JOGADAS (NOVO FORMATO)
// =========================================================================
$historico = [];

// A) Busca todas as participações em Sorteios (Agrupadas por Sorteio)
$sql_vendas = "
    SELECT v.sorteio_id, s.titulo, s.categoria, s.numero_visual, s.valor_numero,
           MAX(v.data_reserva) as data_acao,
           MAX(v.forma_pagamento) as forma_pagamento,
           MAX(v.status_venda) as status_venda,
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
    $historico[$sid] = [
        'sorteio_id' => $sid,
        'data' => $r['data_acao'],
        'titulo' => $r['titulo'],
        'categoria' => $r['categoria'],
        'numero_visual' => $r['numero_visual'],
        'numeros' => $r['numeros'],
        'valor_total' => $r['qtd_comprada'] * $r['valor_numero'],
        'forma_pagamento' => $r['forma_pagamento'], // <-- ADICIONADO AQUI
        'status_venda' => $r['status_venda'],
        'premios' => [],
        'status_retirada' => 'pendente',
        'data_retirada' => null
    ];
}
// B) Varre a tabela de ganhadores para anexar os prêmios nas respectivas jogadas
$sql_premios_g = "
    SELECT DISTINCT gp.*, 
           s.titulo, s.categoria, s.numero_visual, s.valor_numero 
    FROM ganhadores_premios gp
    JOIN sorteios s ON gp.sorteio_id = s.id
    LEFT JOIN vendas v ON gp.sorteio_id = v.sorteio_id AND CAST(gp.numero_sorteado AS UNSIGNED) = CAST(v.numero_escolhido AS UNSIGNED)
    WHERE gp.cliente_id = ? 
       OR (gp.id_whatsapp = ? AND gp.id_whatsapp != '')
       OR v.cliente_id = ? 
       OR (v.id_whatsapp = ? AND v.id_whatsapp != '')
";
$stmt_p = $conn->prepare($sql_premios_g);
$stmt_p->bind_param("isis", $id, $id_whatsapp, $id, $id_whatsapp);
$stmt_p->execute();
$res_p = $stmt_p->get_result();

while ($r = $res_p->fetch_assoc()) {
    $sid = $r['sorteio_id'];

    // Se por acaso a venda foi deletada, mas o prêmio ficou, a gente recria o card visual
    if (!isset($historico[$sid])) {
        $historico[$sid] = [
            'sorteio_id' => $sid,
            'data' => $r['data_ganho'],
            'titulo' => $r['titulo'],
            'categoria' => $r['categoria'],
            'numero_visual' => $r['numero_visual'],
            'numeros' => $r['numero_sorteado'],
            'valor_total' => $r['valor_numero'],
            'forma_pagamento' => null,
            'premios' => [],
            'status_retirada' => 'pendente',
            'data_retirada' => null
        ];
    }

    // Anexa o Prêmio e o número que ganhou
    $historico[$sid]['premios'][] = $r['premio'] . ' (Nº ' . $r['numero_sorteado'] . ')';

    // Atualiza status de retirada
    $status = strtolower($r['status_retirada'] ?? 'pendente');
    if ($status === 'entregue' || $status === 'retirado') {
        $historico[$sid]['status_retirada'] = 'entregue';

        // Puxa a data de onde ela realmente existir
        if (!empty($r['data_retirada'])) {
            $historico[$sid]['data_retirada'] = $r['data_retirada'];
        }
    }
}

// ORDENAÇÃO E FILTRO DE RETIRADA
$ordem = $_GET['ordem'] ?? 'data';
$historico_lista = array_values($historico);

usort($historico_lista, function ($a, $b) use ($ordem) {
    if ($ordem === 'retirada') {
        // Lógica de Prioridade: 1º Prêmios Pendentes | 2º Prêmios Entregues | 3º Sorteios Sem Prêmios
        $pesoA = count($a['premios']) > 0 ? ($a['status_retirada'] === 'pendente' ? 1 : 2) : 3;
        $pesoB = count($b['premios']) > 0 ? ($b['status_retirada'] === 'pendente' ? 1 : 2) : 3;

        if ($pesoA === $pesoB) {
            return strtotime($b['data']) - strtotime($a['data']);
        }
        return $pesoA - $pesoB;
    } else {
        // Ordenação Padrão por Data Mais Recente
        return strtotime($b['data']) - strtotime($a['data']);
    }
});

// PAGINAÇÃO
$pagina = isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1;
if ($pagina < 1)
    $pagina = 1;

$limite_por_pagina = 15;
$total_registros = count($historico_lista);
$total_paginas = ceil($total_registros / $limite_por_pagina);
$offset = ($pagina - 1) * $limite_por_pagina;

$historico_paginado = array_slice($historico_lista, $offset, $limite_por_pagina);

// Função para formatar telefone
function formatarTelefone($telefone)
{
    $tel = preg_replace('/\D/', '', $telefone);
    if (strlen($tel) == 13) {
        $tel = substr($tel, 2);
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
    <title>Perfil | <?php echo htmlspecialchars($cliente['nome_fixo']); ?></title>
    <link rel="stylesheet" href="../assets/css/global.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../assets/css/sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../assets/css/detalhes_cliente.css?v=<?php echo time(); ?>">
    <style>
        .badge-pendente {
            background: #fef3c7;
            color: #b45309;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 800;
            font-size: 11px;
            white-space: nowrap;
        }

        .badge-entregue {
            background: #dcfce7;
            color: #16a34a;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 800;
            font-size: 11px;
            white-space: nowrap;
        }

        .texto-numeros {
            max-width: 250px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 12px;
            color: #475569;
            line-height: 1.4;
        }

        .texto-numeros:hover {
            -webkit-line-clamp: unset;
            background: #f8fafc;
            cursor: pointer;
        }

        .filtro-retirada:hover {
            opacity: 0.8;
        }
    </style>
</head>

<body>
    <div class="layout-sistema">
        <aside class="sidebar"><?php include '../componentes/sidebar.php'; ?></aside>

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
                    <div class="fin-card destaque">
                        <h4>💰 Saldo em Conta</h4>
                        <div class="valor">R$ <?php echo number_format($saldo, 2, ',', '.'); ?></div>
                        <a href="carteiras.php?cliente_id=<?php echo $id; ?>&acao=adicionar"
                            class="btn-adicionar-credito">
                            💲 Adicionar Crédito
                        </a>
                    </div>

                    <div class="fin-card">
                        <h4>💳 Crédito Mensal</h4>
                        <?php if ($limite > 0): ?>
                            <div class="valor">R$ <?php echo number_format($disponivel, 2, ',', '.'); ?></div>
                            <div class="credito-bar-bg">
                                <div class="credito-bar-fill"
                                    style="width: <?php echo $porcentagem_uso; ?>%; background: <?php echo $porcentagem_uso > 90 ? '#ef4444' : '#22c55e'; ?>">
                                </div>
                            </div>
                            <div class="credito-text">
                                <span>Usado: R$ <?php echo number_format($usado, 2, ',', '.'); ?></span>
                                <span>Total: R$ <?php echo number_format($limite, 2, ',', '.'); ?></span>
                            </div>
                            <a href="credito_mensal.php" class="btn-gerenciar">⚙️ Gerenciar</a>
                        <?php else: ?>
                            <div class="valor" style="color:#cbd5e1; font-size: 20px;">Bloqueado 🔒</div>
                            <div style="font-size:11px; color:#94a3b8; margin-top:5px;">Pagamento apenas à vista.</div>
                        <?php endif; ?>
                    </div>

                    <div class="fin-card">
                        <h4>🎮 Total Jogado</h4>
                        <div class="valor" style="color:#6366f1"><?php echo $total_jogadas; ?></div>
                        <div style="font-size:11px; color:#64748b;">Números comprados</div>
                    </div>

                    <div class="fin-card">
                        <h4>🏆 Prêmios</h4>
                        <div class="valor" style="color:#f59e0b"><?php echo $total_premios; ?></div>
                        <div style="font-size:11px; color:#64748b;">Total arrecadado</div>
                    </div>
                </div>

                <div class="historico-container">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="margin: 0;">📜 Histórico de Jogadas</h3>
                    </div>

                    <table class="tabela-historico">
                        <thead>
                            <tr>
                                <th>Data/Hora</th>
                                <th>Sorteio</th>
                                <th>Números Jogados</th>
                                <th>Valor Total</th>
                                <th>Pagamento</th>
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
                                    $data_formatada = date('d/m/Y H:i', strtotime($h['data']));

                                    // Cor da categoria
                                    $cat = strtolower($h['categoria'] ?? '');
                                    $cor_cat = '#64748b';
                                    if ($cat == 'carnes')
                                        $cor_cat = '#ef4444';
                                    if ($cat == 'bebidas')
                                        $cor_cat = '#f59e0b';
                                    if ($cat == 'testes')
                                        $cor_cat = '#8b5cf6';

                                    $tem_premio = count($h['premios']) > 0;
                                    ?>
                                    <tr style="<?php echo $tem_premio ? 'background-color: #fefce8;' : ''; ?>">
                                        <td
                                            style="white-space:nowrap; font-size:12px; color:#64748b; font-weight: 600; padding: 10px;">
                                            <?php echo $data_formatada; ?>
                                        </td>

                                        <td style="padding: 10px;">
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
                                        </td>

                                        <td style="padding: 10px;">
                                            <div class="texto-numeros" title="<?php echo htmlspecialchars($h['numeros']); ?>">
                                                <?php echo htmlspecialchars($h['numeros']); ?>
                                            </div>
                                        </td>

                                        <td style="font-weight:900; color:#16a34a; white-space:nowrap; padding: 10px;">
                                            R$ <?php echo number_format($h['valor_total'], 2, ',', '.'); ?>
                                        </td>

                                        <td style="padding: 10px;">
                                            <?php
                                            $forma = strtolower($h['forma_pagamento'] ?? '');
                                            $is_estornado = ($h['status_venda'] === 'estornado');

                                            if ($is_estornado) {
                                                echo '<span style="background:#fef2f2; padding:4px 8px; border-radius:4px; font-size:10px; font-weight:800; color:#ef4444; text-transform:uppercase;">ESTORNADO</span>';
                                            } elseif ($forma === 'carteira_credito') {
                                                echo '<span style="background:#f1f5f9; padding:4px 8px; border-radius:4px; font-size:10px; font-weight:800; color:#475569; text-transform:uppercase;">MENSAL</span>';
                                            } elseif ($forma === 'carteira_saldo' || $forma === 'carteira') {
                                                echo '<span style="background:#f1f5f9; padding:4px 8px; border-radius:4px; font-size:10px; font-weight:800; color:#475569; text-transform:uppercase;">CARTEIRA</span>';
                                            } elseif (!empty($forma)) {
                                                echo '<span style="background:#f1f5f9; padding:4px 8px; border-radius:4px; font-size:10px; font-weight:800; color:#475569; text-transform:uppercase;">PIX DIRETO</span>';
                                            } else {
                                                echo '<span style="color:#cbd5e1">-</span>';
                                            }
                                            ?>
                                        </td>

                                        <td
                                            style="font-weight:700; color:#d97706; font-size: 12px; line-height: 1.5; padding: 10px;">
                                            <?php if ($tem_premio): ?>
                                                🏆 <?php echo implode('<br>🏆 ', array_map('htmlspecialchars', $h['premios'])); ?>
                                            <?php else: ?>
                                                <span style="color:#cbd5e1; font-weight: normal;">-</span>
                                            <?php endif; ?>
                                        </td>

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
                                    <td colspan="7" class="sem-dados">Nenhuma jogada encontrada para este cliente.</td>
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