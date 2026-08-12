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

$busca = $_GET['busca'] ?? '';
$mes_atual = date('m');
$ano_atual = date('Y');

// PAGINAÇÃO
$pagina = isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1;
$limite_por_pagina = 20; // 20 por página para ficar compacto
$offset = ($pagina - 1) * $limite_por_pagina;

$where_clause = "1=1";
if ($busca) {
    $busca_segura = $conn->real_escape_string($busca);
    $where_clause .= " AND (a.nome_fixo LIKE '%$busca_segura%' OR a.telefone LIKE '%$busca_segura%')";
}

// Conta total para os botões de página
$sql_count = "SELECT COUNT(*) as total FROM carteiras c JOIN agenda_clientes a ON c.cliente_id = a.id WHERE $where_clause";
$total_registros = $conn->query($sql_count)->fetch_assoc()['total'];
$total_paginas = ceil($total_registros / $limite_por_pagina);

// Query Principal com LIMIT e OFFSET
$sql = "SELECT 
            c.id as carteira_id, c.cliente_id, c.saldo, c.credito_limite, c.credito_usado, c.status,
            a.nome_fixo, a.telefone,
            (SELECT COALESCE(SUM(valor), 0) FROM transacoes_carteira WHERE cliente_id = a.id AND tipo IN ('recarga_manual', 'recarga_pix', 'ajuste_admin') AND descricao != 'Saldo inicial' AND MONTH(data_transacao) = '$mes_atual' AND YEAR(data_transacao) = '$ano_atual') as adicionado_mes,
            (SELECT COALESCE(SUM(valor), 0) FROM transacoes_carteira WHERE cliente_id = a.id AND tipo IN ('compra_saldo', 'compra_credito') AND MONTH(data_transacao) = '$mes_atual' AND YEAR(data_transacao) = '$ano_atual') as usado_mes
        FROM carteiras c 
        JOIN agenda_clientes a ON c.cliente_id = a.id
        WHERE $where_clause
        ORDER BY a.nome_fixo ASC
        LIMIT $limite_por_pagina OFFSET $offset";

$result = $conn->query($sql);

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
    <title>Carteiras | D'KING</title>
    <link rel="stylesheet" href="assets/css/global.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/carteiras.css?v=<?php echo time(); ?>">
</head>

<body>
    <div class="layout-sistema">
        <aside class="sidebar"><?php include 'componentes/sidebar.php'; ?></aside>
        <main class="conteudo-principal">
            <div class="container-carteiras">
                <div class="header">
                    <h1>💳 Carteiras </h1>
                    <button class="btn-novo" onclick="abrirModalNovo()">➕ Nova Carteira</button>
                </div>

                <div class="barra-busca">
                    <input type="text" id="inputBusca" placeholder="🔍 Buscar por nome ou telefone..."
                        value="<?php echo htmlspecialchars($busca); ?>">
                    <button class="btn-buscar" onclick="buscar()">Buscar</button>
                    <button class="btn-limpar" onclick="location.href='carteiras.php'">Limpar</button>
                </div>

                <div class="tabela">
                    <?php if ($result && $result->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Carteira</th>
                                    <th>Mensal</th>
                                    <th>Saldo Total</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $result->fetch_assoc()):
                                    $saldo = $row['saldo'] ?? 0;
                                    $limite = $row['credito_limite'] ?? 0;
                                    $usado = $row['credito_usado'] ?? 0;
                                    $fiado_livre = $limite - $usado;
                                    $poder_total = $saldo + $fiado_livre;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($row['nome_fixo']); ?></strong><br>
                                            <small
                                                class="text-muted-sm"><?php echo formatarTelefone($row['telefone']); ?></small>
                                        </td>
                                        <td>
                                            <div class="bloco-dinheiro">Saldo: R$
                                                <?php echo number_format($saldo, 2, ',', '.'); ?>
                                            </div>
                                            <div class="texto-verde">+ Mês: R$
                                                <?php echo number_format($row['adicionado_mes'], 2, ',', '.'); ?>
                                            </div>
                                            <div class="texto-vermelho">- Mês: R$
                                                <?php echo number_format($row['usado_mes'], 2, ',', '.'); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($limite > 0): ?>
                                                <div class="bloco-fiado">Livre: R$
                                                    <?php echo number_format($fiado_livre, 2, ',', '.'); ?>
                                                </div>
                                                <div class="texto-divida">Dívida: R$
                                                    <?php echo number_format($usado, 2, ',', '.'); ?>
                                                </div>
                                                <div class="texto-limite">Limite: R$
                                                    <?php echo number_format($limite, 2, ',', '.'); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="texto-vazio">Não possui fiado</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge-poder-total">R$
                                                <?php echo number_format($poder_total, 2, ',', '.'); ?></span>
                                        </td>
                                        <td>
                                            <button class="btn-acao btn-editar"
                                                onclick="verModalSaldo(<?php echo $row['cliente_id']; ?>, '<?php echo addslashes($row['nome_fixo']); ?>', <?php echo $saldo; ?>); return false;"
                                                title="Adicionar Saldo em Dinheiro">💰 + Saldo</button>
                                            <button class="btn-acao btn-historico"
                                                onclick="location.href='historico_carteira.php?cliente_id=<?php echo $row['cliente_id']; ?>'">📜
                                                Histórico</button>
                                            <button class="btn-acao btn-excluir"
                                                onclick="zerarSaldoCarteira(<?php echo $row['cliente_id']; ?>, '<?php echo addslashes($row['nome_fixo']); ?>')"
                                                title="Zerar Apenas o Saldo da Carteira">🗑️ Zerar Saldo</button>
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
                        <div class="vazio">
                            <h3>💳 Nenhuma carteira encontrada</h3>
                            <p><?php echo $busca ? 'Tente outra busca' : 'Crie a primeira carteira'; ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <div id="modalNovo" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-title">➕ Nova Carteira</div>
            <div class="form-group">
                <label class="form-label">Pesquisar Cliente na Agenda</label>
                <input type="text" id="buscaCliente" class="form-input" placeholder="Digite o nome do cliente..."
                    onkeyup="buscarClientes()">
                <small class="text-helper">* Cliente precisa estar na agenda</small>
            </div>
            <div id="resultadosBusca" class="resultados-busca" style="display:none;"></div>

            <div id="clienteSelecionado" style="display:none; margin-top:15px;">
                <div class="info-box">
                    <strong class="info-box-title">Selecionado:</strong><br>
                    <span id="nomeClienteSelecionado" class="info-box-highlight"></span><br>
                    <span id="telefoneClienteSelecionado" class="text-muted-sm"></span>
                    <input type="hidden" id="clienteIdSelecionado">
                </div>
                <div class="form-group">
                    <label class="form-label">Limite de Crédito Fiado Inicial (R$)</label>
                    <input type="number" id="limiteCredito" class="form-input" placeholder="100.00" step="0.01" min="0">
                </div>
                <div class="modal-buttons">
                    <button onclick="fecharModal()" class="btn-modal-cancelar">CANCELAR</button>
                    <button onclick="criarCarteira()" class="btn-modal-salvar">CRIAR CARTEIRA</button>
                </div>
            </div>
        </div>
    </div>

    <div id="modalSaldo" class="modal-overlay" style="display:none;">
        <div class="modal-box">
            <div class="modal-title">💰 Adicionar Saldo</div>
            <input type="hidden" id="saldoClienteId">

            <div class="info-box">
                <strong id="saldoClienteNome" class="info-box-title"></strong><br>
                <span class="text-muted-sm">Saldo atual: R$ <span id="saldoAtual">0,00</span></span>
            </div>

            <div class="form-group">
                <label class="form-label">Valor a Adicionar (R$)</label>
                <input type="number" id="valorAdicionar" class="form-input" step="1" placeholder="Ex: 50" value="">
                <small class="text-helper">Digite apenas números inteiros</small>
            </div>

            <div class="form-group">
                <label class="form-label">Descrição</label>
                <input type="text" id="descricaoSaldo" class="form-input" value="Crédito adicionado manualmente">
            </div>

            <div class="modal-buttons">
                <button type="button" onclick="fecharModalSaldo(); return false;"
                    class="btn-modal-cancelar">CANCELAR</button>
                <button type="button" onclick="adicionarSaldo(); return false;"
                    class="btn-modal-salvar">ADICIONAR</button>
            </div>
        </div>
    </div>

    <script>
        function abrirModalNovo() {
            document.getElementById('modalNovo').style.display = 'flex';
            setTimeout(() => document.getElementById('buscaCliente').focus(), 100);
        }

        function fecharModal() {
            document.getElementById('modalNovo').style.display = 'none';
            document.getElementById('resultadosBusca').style.display = 'none';
            document.getElementById('clienteSelecionado').style.display = 'none';
            document.getElementById('buscaCliente').value = '';
            document.getElementById('limiteCredito').value = '';
        }

        function buscar() {
            const q = document.getElementById('inputBusca').value;
            location.href = 'carteiras.php?busca=' + encodeURIComponent(q);
        }

        async function buscarClientes() {
            const busca = document.getElementById('buscaCliente').value;
            if (busca.length < 2) {
                document.getElementById('resultadosBusca').style.display = 'none';
                return;
            }
            const response = await fetch('api_carteira.php?acao=buscar_clientes&busca=' + encodeURIComponent(busca));
            const data = await response.json();
            const div = document.getElementById('resultadosBusca');

            if (data.clientes && data.clientes.length > 0) {
                div.innerHTML = '';
                data.clientes.forEach(c => {
                    const item = document.createElement('div');
                    item.className = 'resultado-item';
                    item.innerHTML = `
                        <div class="resultado-nome">${c.nome_fixo}</div>
                        <div class="resultado-telefone">${c.telefone_formatado}</div>
                        <button class="btn-selecionar" onclick="selecionarCliente(${c.id}, '${c.nome_fixo}', '${c.telefone_formatado}')">SELECIONAR</button>
                    `;
                    div.appendChild(item);
                });
                div.style.display = 'block';
            } else {
                div.innerHTML = '<div style="padding:15px; text-align:center;" class="text-muted-sm">Nenhum cliente encontrado.</div>';
                div.style.display = 'block';
            }
        }

        function selecionarCliente(id, nome, telefone) {
            document.getElementById('clienteIdSelecionado').value = id;
            document.getElementById('nomeClienteSelecionado').textContent = nome;
            document.getElementById('telefoneClienteSelecionado').textContent = telefone;
            document.getElementById('clienteSelecionado').style.display = 'block';
            document.getElementById('resultadosBusca').style.display = 'none';
            document.getElementById('limiteCredito').focus();
        }

        async function criarCarteira() {
            const clienteId = document.getElementById('clienteIdSelecionado').value;
            const saldo = document.getElementById('limiteCredito').value || 0;
            if (!clienteId) {
                alert('❌ Selecione um cliente!');
                return;
            }
            try {
                const response = await fetch('api_carteira.php?acao=criar', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `cliente_id=${clienteId}&saldo=${saldo}`
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert('❌ Erro: ' + data.error);
                }
            } catch (error) {
                alert('Erro ao criar carteira: ' + error);
            }
        }

        function verModalSaldo(clienteId, nome, saldoAtual) {
            document.getElementById('saldoClienteId').value = clienteId;
            document.getElementById('saldoClienteNome').textContent = nome;
            document.getElementById('saldoAtual').textContent = parseFloat(saldoAtual).toFixed(2).replace('.', ',');

            const modal = document.getElementById('modalSaldo');
            modal.classList.add('modal-saldo-fix');
            setTimeout(() => document.getElementById('valorAdicionar').focus(), 100);
        }

        function fecharModalSaldo() {
            const modal = document.getElementById('modalSaldo');
            modal.classList.remove('modal-saldo-fix');
            modal.style.display = 'none';
            document.getElementById('valorAdicionar').value = '';
            document.getElementById('descricaoSaldo').value = 'Crédito adicionado manualmente';
        }

        async function adicionarSaldo() {
            const clienteId = document.getElementById('saldoClienteId').value;
            const valor = document.getElementById('valorAdicionar').value;
            const descricao = document.getElementById('descricaoSaldo').value || 'Recarga manual';

            if (!valor || valor <= 0) {
                alert('❌ Digite um valor válido!');
                return;
            }
            try {
                const response = await fetch('api_carteira.php?acao=adicionar_saldo', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `cliente_id=${clienteId}&valor=${valor}&descricao=${encodeURIComponent(descricao)}`
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert('❌ Erro: ' + data.error);
                }
            } catch (error) {
                alert('Erro: ' + error);
            }
        }

        document.getElementById('inputBusca').addEventListener('keypress', e => {
            if (e.key === 'Enter') buscar();
        });
        document.getElementById('modalNovo').addEventListener('click', function (e) {
            if (e.target === this) fecharModal();
        });
        document.getElementById('modalSaldo').addEventListener('click', function (e) {
            if (e.target === this) fecharModalSaldo();
        });

        document.addEventListener('DOMContentLoaded', function () {
            let modal = document.getElementById('modalSaldo');
            if (modal) document.body.appendChild(modal);
        });
        async function zerarSaldoCarteira(clienteId, nome) {
            // O aviso agora deixa claro que só o SALDO será apagado
            if (confirm(`⚠️ ATENÇÃO!\n\nTem certeza que deseja ZERAR O SALDO da carteira de ${nome}?\n\nIsso vai apagar apenas o valor em dinheiro do saldo atual. O limite mensal e as dívidas (fiado) NÃO serão alterados.\n\nDeseja continuar?`)) {
                try {
                    const response = await fetch('api_carteira.php?acao=zerar_saldo_carteira', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `cliente_id=${clienteId}`
                    });
                    const data = await response.json();
                    if (data.success) {
                        alert('✅ Saldo zerado com sucesso!');
                        location.reload();
                    } else {
                        alert('❌ Erro: ' + data.error);
                    }
                } catch (error) {
                    alert('Erro ao zerar saldo: ' + error);
                }
            }
        }
    </script>
</body>

</html>