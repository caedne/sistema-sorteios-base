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

// Função para formatar telefone
function formatarTelefone($telefone)
{
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

function telefoneValido($telefone)
{
    $tel = preg_replace('/\D/', '', $telefone);
    return (strlen($tel) == 13 && substr($tel, 0, 2) == '55') || (strlen($tel) == 11);
}

// 1. Configurações de Data
$mes_atual = date('m');
$ano_atual = date('Y');

// 2. Estatísticas do Topo
$stats_clientes = $conn->query("SELECT COUNT(*) as total FROM agenda_clientes")->fetch_assoc()['total'];

$q_sorteios = $conn->query("
    SELECT COUNT(DISTINCT gp.sorteio_id) as total 
    FROM ganhadores_premios gp
    JOIN sorteios s ON gp.sorteio_id = s.id
    WHERE MONTH(gp.data_ganho) = '$mes_atual' 
    AND YEAR(gp.data_ganho) = '$ano_atual'
");
$stats_sorteios = $q_sorteios ? $q_sorteios->fetch_assoc()['total'] : 0;

$q_premios = $conn->query("
    SELECT COUNT(*) as total 
    FROM ganhadores_premios gp
    JOIN sorteios s ON gp.sorteio_id = s.id
    WHERE MONTH(gp.data_ganho) = '$mes_atual' 
    AND YEAR(gp.data_ganho) = '$ano_atual'
");
$stats_premios = $q_premios ? $q_premios->fetch_assoc()['total'] : 0;

// 3. PAGINAÇÃO E BUSCA
$busca = $_GET['busca'] ?? '';
$pagina = isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1;
$limite_por_pagina = 20; // <--- SÓ APARECEM 20 POR VEZ AQUI
$offset = ($pagina - 1) * $limite_por_pagina;

$where_clause = "1=1";
if ($busca) {
    $busca_segura = $conn->real_escape_string($busca);
    $where_clause .= " AND (a.nome_fixo LIKE '%$busca_segura%' OR a.telefone LIKE '%$busca_segura%')";
}

// Conta o total de registros para criar os botões de página
$sql_count = "SELECT COUNT(*) as total FROM agenda_clientes a WHERE $where_clause";
$total_registros = $conn->query($sql_count)->fetch_assoc()['total'];
$total_paginas = ceil($total_registros / $limite_por_pagina);

// 4. Lista de Clientes (Consulta Principal)
$sql = "SELECT 
            a.id as id_real_cliente,
            a.id_whatsapp,
            a.nome_fixo,
            a.nome_whatsapp,
            a.telefone,
            (SELECT COUNT(*) 
             FROM vendas v 
             WHERE (v.cliente_id = a.id OR (v.id_whatsapp = a.id_whatsapp AND a.id_whatsapp != ''))
             AND v.status_venda IN ('pago', 'ganhador', 'pendente')
            ) as total_jogadas,
            c.saldo,
            c.credito_limite,
            c.credito_usado,
            (SELECT COUNT(DISTINCT gp.id) 
             FROM ganhadores_premios gp 
             LEFT JOIN vendas v ON gp.sorteio_id = v.sorteio_id AND gp.numero_sorteado = v.numero_escolhido
             WHERE gp.cliente_id = a.id 
                OR (gp.id_whatsapp = a.id_whatsapp AND a.id_whatsapp != '')
                OR v.cliente_id = a.id 
                OR (v.id_whatsapp = a.id_whatsapp AND a.id_whatsapp != '')
            ) as total_premios_real       
        FROM agenda_clientes a
        LEFT JOIN carteiras c ON a.id = c.cliente_id
        WHERE $where_clause
        ORDER BY a.ultima_atualizacao DESC 
        LIMIT $limite_por_pagina OFFSET $offset";

$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Agenda de Clientes </title>
    <link rel="stylesheet" href="assets/css/global.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    <link rel="stylesheet" href="assets/css/agenda.css">
    <style>
        .btn-novo-topo {
            background-color: #22c55e;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 900;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 6px -1px rgba(34, 197, 94, 0.4);
            transition: all 0.2s;
            font-size: 13px;
        }

        .btn-novo-topo:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 8px -1px rgba(34, 197, 94, 0.5);
        }

        .badge-jogadas {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-premios {
            background: #fef3c7;
            color: #92400e;
        }

        .cliente-nome {
            font-weight: 900;
            color: #1e293b;
            cursor: pointer;
            text-decoration: none;
        }

        .cliente-nome:hover {
            color: #0284c7;
        }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.8);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .modal-box {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 400px;
            padding: 25px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            font-weight: 700;
            margin-bottom: 5px;
            color: #475569;
            font-size: 12px;
        }

        .form-input {
            width: 100%;
            padding: 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
            box-sizing: border-box;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .btn-modal-salvar {
            flex: 1;
            padding: 10px;
            background: #22c55e;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
        }

        .btn-modal-cancelar {
            flex: 1;
            padding: 10px;
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
        }
    </style>
</head>

<body>
    <div class="layout-sistema">
        <aside class="sidebar"><?php include 'componentes/sidebar.php'; ?></aside>
        <main class="conteudo-principal">
            <div class="container">
                <div class="header">
                    <h1>📒 Agenda de Clientes</h1>
                    <button class="btn-novo-topo" onclick="abrirModalNovo()">
                        <span>➕</span> Novo Cliente
                    </button>
                </div>

                <div class="stats-cards">
                    <div class="stat-card card-roxo">
                        <h3>👥 Total de Clientes</h3>
                        <p class="valor"><?php echo $stats_clientes; ?></p>
                    </div>
                    <div class="stat-card card-rosa">
                        <h3>📅 Sorteios (Este Mês)</h3>
                        <p class="valor"><?php echo $stats_sorteios; ?></p>
                    </div>
                    <div class="stat-card card-azul">
                        <h3>🏆 Prêmios (Este Mês)</h3>
                        <p class="valor"><?php echo $stats_premios; ?></p>
                    </div>
                </div>

                <div class="barra-busca">
                    <input type="text" id="inputBusca" placeholder="🔍 Buscar por nome ou telefone..."
                        value="<?php echo htmlspecialchars($busca); ?>">
                    <button class="btn-buscar" onclick="buscar()">Buscar</button>
                    <button class="btn-limpar" onclick="location.href='agenda.php'">Limpar</button>
                </div>

                <div class="tabela-container">
                    <?php if ($result && $result->num_rows > 0): ?>
                        <table class="tabela">
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Telefone</th>
                                    <th>Jogadas</th>
                                    <th>Prêmios</th>
                                    <th>Carteira</th>
                                    <th>Mensal</th>
                                    <th>Saldo Total</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $result->fetch_assoc()):
                                    $tel_valido = telefoneValido($row['telefone']);
                                    $saldo_carteira = $row['saldo'] ?? 0;
                                    $credito_mensal = ($row['credito_limite'] ?? 0) - ($row['credito_usado'] ?? 0);
                                    $saldo_total = $saldo_carteira + $credito_mensal;
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="detalhes_cliente.php?id=<?php echo $row['id_real_cliente']; ?>"
                                                class="cliente-nome">
                                                <?php echo htmlspecialchars($row['nome_fixo']); ?>
                                            </a>
                                            <?php if ($row['nome_whatsapp'] && $row['nome_whatsapp'] != $row['nome_fixo']): ?>
                                                <small style="color:#64748b; font-weight:normal; display:block;">Zap:
                                                    <?php echo htmlspecialchars($row['nome_whatsapp']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo formatarTelefone($row['telefone']); ?>
                                            <?php if (!$tel_valido): ?>
                                                <br><small style="color:#f59e0b; font-size:10px;">⚠️ Inválido</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge badge-jogadas"><?php echo $row['total_jogadas']; ?>
                                                jogadas</span></td>
                                        <td><span class="badge badge-premios"><?php echo $row['total_premios_real']; ?>
                                                prêmios</span></td>

                                        <td class="<?php echo $saldo_carteira > 0 ? 'saldo-positivo' : 'saldo-zero'; ?>">
                                            R$ <?php echo number_format($saldo_carteira, 2, ',', '.'); ?>
                                        </td>

                                        <td>
                                            <?php if ($credito_mensal > 0): ?>
                                                <span style="color:#f59e0b; font-weight:bold;">R$
                                                    <?php echo number_format($credito_mensal, 2, ',', '.'); ?></span>
                                            <?php else: ?>
                                                <span style="color:#cbd5e1">-</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <span
                                                style="color:#22c55e; font-weight:bold; background:#dcfce7; padding:2px 6px; border-radius:4px;">
                                                R$ <?php echo number_format($saldo_total, 2, ',', '.'); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <button class="btn-acao btn-ver"
                                                onclick="location.href='detalhes_cliente.php?id=<?php echo $row['id_real_cliente']; ?>'"><i
                                                    class="fas fa-eye"></i> 👁️</button>
                                            <button class="btn-acao btn-editar"
                                                onclick="editarNome(<?php echo $row['id_real_cliente']; ?>, '<?php echo addslashes($row['nome_fixo']); ?>')"><i
                                                    class="fas fa-pencil-alt"></i> ✏️</button>
                                            <?php if (!$tel_valido): ?>
                                                <button class="btn-acao" style="background:#f59e0b;"
                                                    onclick="corrigirTelefone(<?php echo $row['id_real_cliente']; ?>, '<?php echo $row['telefone']; ?>')">📱</button>
                                            <?php endif; ?>
                                            <button class="btn-acao btn-excluir"
                                                onclick="excluirCliente(<?php echo $row['id_real_cliente']; ?>, '<?php echo addslashes($row['nome_fixo']); ?>')"><i
                                                    class="fas fa-trash"></i> 🗑️</button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>

                        <?php if ($total_paginas > 1): ?>
                            <div class="paginacao">
                                <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                                    <a href="?pagina=<?php echo $i; ?>&busca=<?php echo urlencode($busca); ?>"
                                        class="btn-paginacao <?php echo ($i == $pagina) ? 'ativo' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div style="text-align:center; padding: 40px; color: #94a3b8;">
                            <h3>📒 Nenhum cliente encontrado</h3>
                            <p><?php echo $busca ? 'Tente outra busca' : 'Ninguém registrado ainda'; ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <div id="modalNovo" class="modal-overlay">
        <div class="modal-box">
            <h2 style="margin:0 0 15px; font-size:18px; color:#1e293b;">➕ Novo Cliente</h2>
            <form onsubmit="salvarNovo(event)">
                <div class="form-group">
                    <label class="form-label">Nome Completo</label>
                    <input type="text" name="nome" class="form-input" required placeholder="Ex: João da Silva">
                </div>
                <div class="form-group">
                    <label class="form-label">Telefone (Whatsapp)</label>
                    <input type="text" name="telefone" class="form-input" required placeholder="Ex: 5547999998888">
                    <small style="color:#94a3b8; font-size:11px;">* Digite apenas números com DDD</small>
                </div>
                <div class="modal-buttons">
                    <button type="button" onclick="fecharModal()" class="btn-modal-cancelar">CANCELAR</button>
                    <button type="submit" class="btn-modal-salvar">SALVAR</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalNovo() {
            const m = document.getElementById('modalNovo');
            m.style.display = 'flex';
            setTimeout(() => m.querySelector('input').focus(), 100);
        }

        function fecharModal() { document.getElementById('modalNovo').style.display = 'none'; }
        document.getElementById('modalNovo').addEventListener('click', function (e) { if (e.target === this) fecharModal(); });

        function buscar() {
            const q = document.getElementById('inputBusca').value;
            location.href = 'agenda.php?busca=' + encodeURIComponent(q);
        }

        function salvarNovo(e) {
            e.preventDefault();
            const btn = e.target.querySelector('button[type="submit"]');
            const txtOriginal = btn.innerText;
            btn.innerText = "⏳ Salvando...";
            btn.disabled = true;

            const f = new FormData(e.target);
            fetch('api_agenda.php?acao=criar', { method: 'POST', body: f })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        btn.innerText = "✅ Sucesso!";
                        setTimeout(() => location.reload(), 500);
                    } else {
                        alert('❌ ' + d.error);
                        btn.innerText = txtOriginal;
                        btn.disabled = false;
                    }
                }).catch(err => {
                    alert('Erro de conexão');
                    btn.innerText = txtOriginal;
                    btn.disabled = false;
                });
        }

        function editarNome(id, nomeAtual) {
            const novo = prompt('Novo nome:', nomeAtual);
            if (!novo || novo === nomeAtual) return;
            fetch('api_agenda.php?acao=editar_nome', {
                method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + id + '&nome=' + encodeURIComponent(novo)
            }).then(r => r.json()).then(d => {
                if (d.success) location.reload(); else alert('❌ ' + d.error)
            });
        }

        function excluirCliente(id, nome) {
            if (!confirm(`⚠️ ATENÇÃO!\n\nExcluir "${nome}"?\nIsso apaga TUDO deste cliente.\n\nTem certeza?`)) return;
            fetch('api_agenda.php?acao=excluir', {
                method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + id
            }).then(r => r.json()).then(d => {
                if (d.success) { location.reload(); } else { alert('❌ Erro: ' + d.error); }
            });
        }

        function corrigirTelefone(id, telefoneAtual) {
            const novoTel = prompt('📱 Digite o telefone CORRETO (apenas números):\nEx: 5547999999999\n\nAtual: ' + telefoneAtual);
            if (!novoTel) return;
            let telLimpo = novoTel.replace(/\D/g, '');
            if (telLimpo.length === 11 && !telLimpo.startsWith('55')) telLimpo = '55' + telLimpo;
            if (telLimpo.length !== 13 || !telLimpo.startsWith('55')) {
                alert('❌ Telefone inválido!\nExemplos:\n- 47999999999\n- 5547999999999'); return;
            }
            fetch('api_agenda.php?acao=corrigir_telefone', {
                method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + id + '&telefone=' + telLimpo
            }).then(r => r.json()).then(d => {
                if (d.success) { location.reload(); } else { alert('❌ Erro: ' + d.error); }
            });
        }

        document.getElementById('inputBusca').addEventListener('keypress', function (e) { if (e.key === 'Enter') buscar(); });
    </script>
</body>

</html>