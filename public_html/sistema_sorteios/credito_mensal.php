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
session_start();

$sql = "SELECT * FROM carteiras WHERE credito_limite > 0 
        ORDER BY 
        CASE 
            WHEN credito_usado > 0 AND DAY(CURRENT_DATE) = dia_vencimento THEN 1 /* 🟢 Verde: Vence Hoje */
            WHEN credito_usado > 0 AND DAY(CURRENT_DATE) > dia_vencimento THEN 2 /* 🔴 Vermelho: Atrasado */
            WHEN credito_usado > 0 AND DAY(CURRENT_DATE) < dia_vencimento THEN 3 /* 🟠 Laranja: Adiantado */
            ELSE 4 /* 🔘 Cinza: Nada a cobrar */
        END ASC, 
        dia_vencimento ASC";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Crédito Mensal | D'KING</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/credito_mensal.css?v=<?php echo time(); ?>">
</head>

<body>
    <div class="layout-sistema">
        <aside class="sidebar"><?php include '../componentes/sidebar.php'; ?></aside>

        <main class="conteudo-principal">
            <div class="container">

                <div class="header">
                    <h1>💳 Crédito Mensal</h1>
                    <button class="btn-novo" onclick="abrirModalAdicionar()">+ Adicionar Cliente</button>
                </div>

                <div class="tabela">
                    <?php if ($result && $result->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Limite</th>
                                    <th>Usado</th>
                                    <th>Disponível</th>
                                    <th>Vencimento</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $result->fetch_assoc()):
                                    $cliente_sql = "SELECT nome_fixo, telefone FROM agenda_clientes WHERE id = {$row['cliente_id']}";
                                    $cliente = $conn->query($cliente_sql)->fetch_assoc();
                                    $disponivel = $row['credito_limite'] - $row['credito_usado'];
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($cliente['nome_fixo']); ?></strong>
                                            <?php if ($row['status'] == 'bloqueado'): ?>
                                                <span
                                                    style="color:white; background:#ef4444; font-size:10px; padding:2px 6px; border-radius:4px; margin-left:5px;">BLOQUEADO</span>
                                            <?php endif; ?><br>
                                            <small style="color:#64748b;"><?php echo $cliente['telefone']; ?></small>
                                        </td>

                                        <td class="valor-limite">R$
                                            <?php echo number_format($row['credito_limite'], 2, ',', '.'); ?></td>
                                        <td class="valor-usado">R$
                                            <?php echo number_format($row['credito_usado'], 2, ',', '.'); ?></td>
                                        <td class="valor-disponivel">R$ <?php echo number_format($disponivel, 2, ',', '.'); ?>
                                        </td>
                                        <td><strong>Dia <?php echo $row['dia_vencimento'] ?? 15; ?></strong></td>

                                        <td>
                                            <?php
                                            // Lógica do Semáforo (Botão Cobrar)
                                            if ($row['credito_usado'] > 0):
                                                $hoje = (int) date('d');
                                                $vencimento = $row['dia_vencimento'] ?? 15;
                                                $classePiscar = ''; // Inicia sem piscar
                                    
                                                if ($hoje < $vencimento) {
                                                    $corBtn = '#f59e0b';
                                                    $titulo = "Cobrar Adiantado";
                                                } elseif ($hoje == $vencimento) {
                                                    $corBtn = '#22c55e';
                                                    $titulo = "Vence Hoje! Cobrar";
                                                    $classePiscar = 'piscar-urgente'; // Aplica a classe do CSS
                                                } else {
                                                    $corBtn = '#ef4444';
                                                    $titulo = "Atrasado! Cobrar";
                                                    $classePiscar = 'piscar-urgente'; // Aplica a classe do CSS
                                                }
                                                ?>
                                                <button class="btn-acao <?php echo $classePiscar; ?>"
                                                    style="background:<?php echo $corBtn; ?>; color:white;"
                                                    onclick="cobrar(<?php echo $row['cliente_id']; ?>, <?php echo $row['credito_usado']; ?>, <?php echo $vencimento; ?>)"
                                                    title="<?php echo $titulo; ?>">
                                                    💰 Cobrar
                                                </button>

                                                <button class="btn-acao" style="background:#10b981; color:white;"
                                                    onclick="receberManual(<?php echo $row['cliente_id']; ?>, '<?php echo addslashes($cliente['nome_fixo']); ?>', <?php echo $row['credito_usado']; ?>)"
                                                    title="Dar Baixa - Recebido em Dinheiro">
                                                    💵 Pagar
                                                </button>
                                            <?php else: ?>
                                                <button class="btn-acao"
                                                    style="background:#94a3b8; color:white; cursor:not-allowed;"
                                                    onclick="alert('Este cliente está com as contas em dia! Não há o que cobrar.')"
                                                    title="Nada a cobrar">
                                                    💰 Cobrar
                                                </button>
                                            <?php endif; ?>

                                            <button class="btn-acao btn-editar"
                                                onclick="adicionarCredito(<?php echo $row['cliente_id']; ?>, <?php echo $row['credito_limite']; ?>, <?php echo $row['dia_vencimento'] ?? 15; ?>)"
                                                title="Somar Limite">
                                                ➕ Crédito
                                            </button>

                                            <button class="btn-acao" style="background:#6366f1; color:white;"
                                                onclick="alterarVencimento(<?php echo $row['cliente_id']; ?>, <?php echo $row['credito_limite']; ?>, <?php echo $row['dia_vencimento'] ?? 15; ?>)"
                                                title="Alterar Data de Vencimento">
                                                📅 Data
                                            </button>

                                            <?php if ($row['status'] == 'ativo'): ?>
                                                <button class="btn-acao" style="background:#0f172a; color:white;"
                                                    onclick="bloquearCliente(<?php echo $row['cliente_id']; ?>, '<?php echo addslashes($cliente['nome_fixo']); ?>', '<?php echo $cliente['telefone']; ?>')"
                                                    title="Bloquear Conta">
                                                    🚫 Bloquear
                                                </button>
                                            <?php else: ?>
                                                <button class="btn-acao" style="background:#16a34a; color:white;"
                                                    onclick="desbloquearCliente(<?php echo $row['cliente_id']; ?>, '<?php echo addslashes($cliente['nome_fixo']); ?>', '<?php echo $cliente['telefone']; ?>')"
                                                    title="Desbloquear Conta">
                                                    ✅ Liberar
                                                </button>
                                            <?php endif; ?>

                                            <button class="btn-acao btn-excluir"
                                                onclick="removerCredito(<?php echo $row['cliente_id']; ?>, '<?php echo addslashes($cliente['nome_fixo']); ?>')">
                                                🗑️
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="vazio">
                            <h3>💳 Nenhum cliente com crédito mensal</h3>
                            <p>Adicione um limite mensal a um cliente para começar.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>

    <div id="modalAdicionar" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <h2>➕ Liberar Crédito Mensal</h2>
                <button class="btn-fechar" onclick="fecharModal()">✖</button>
            </div>
            <div class="form-group">
                <label class="form-label">Pesquisar Cliente na Agenda</label>
                <input type="text" id="buscaClienteCredito" class="form-input" placeholder="Digite o nome..."
                    onkeyup="buscarClientesCredito()">
            </div>
            <div id="resultadosBuscaCredito" class="resultados-busca" style="display:none;"></div>
            <div id="clienteSelecionadoCredito" style="display:none">
                <div class="card-selecionado">
                    <strong id="nomeSelecionado"></strong><br>
                    <small id="telSelecionado" style="color:#64748b;"></small>
                    <input type="hidden" id="idSelecionado">
                </div>

                <div class="form-group" style="margin-top: 15px;">
                    <label class="form-label">Limite de Crédito Inicial (R$)</label>
                    <input type="number" id="novoLimite" class="form-input" step="0.01" placeholder="Ex: 150.00">
                </div>

                <div class="form-group" style="margin-top: 15px;">
                    <label class="form-label">Dia de Vencimento</label>
                    <input type="number" id="novoVencimento" class="form-input" min="1" max="31" placeholder="Ex: 15"
                        value="15">
                </div>

                <button class="btn-confirmar" onclick="confirmarCredito()" style="margin-top: 20px;">LIBERAR
                    CRÉDITO</button>
            </div>
        </div>
    </div>

    <script>
        function cobrar(id, valor, diaVencimento) {
            const diaAtual = new Date().getDate();
            let mensagem = `💰 Gerar PIX de R$ ${valor.toFixed(2)} e enviar no WhatsApp?\n\n📅 Vencimento do cliente: Dia ${diaVencimento}`;

            if (diaAtual < diaVencimento) {
                mensagem += `\n\n⚠️ ATENÇÃO: Hoje é dia ${diaAtual}. Você está gerando a cobrança ANTES da data combinada. Deseja continuar mesmo assim?`;
            } else {
                mensagem += `\n✅ O vencimento é hoje ou já passou. Pode enviar!`;
            }

            if (!confirm(mensagem)) return;

            fetch('api_carteira.php?acao=cobrar_credito', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'cliente_id=' + id
            }).then(r => r.json()).then(d => {
                if (d.success) alert('✅ ' + d.message);
                else alert('❌ Erro: ' + d.error);
            });
        }
        //botão receber manual
        function receberManual(id, nome, valorTotalDevido) {
            // Pergunta o valor com o total como sugestão
            const valorPago = prompt(`💵 BAIXA DE PAGAMENTO - ${nome}\n\nO cliente deve R$ ${valorTotalDevido.toFixed(2)}.\n\nQuanto ele está pagando agora?`, valorTotalDevido.toFixed(2));

            // Se cancelar ou não digitar nada, para aqui
            if (valorPago === null || valorPago === "") return;

            // Converte vírgula para ponto e garante que é número
            const valorNum = parseFloat(valorPago.replace(',', '.'));

            if (isNaN(valorNum) || valorNum <= 0) {
                alert("⚠️ Por favor, digite um valor válido maior que zero!");
                return;
            }

            if (valorNum > valorTotalDevido) {
                alert(`❌ Erro: O valor pago (R$ ${valorNum.toFixed(2)}) não pode ser maior que a dívida total (R$ ${valorTotalDevido.toFixed(2)})!`);
                return;
            }

            const sobra = valorTotalDevido - valorNum;
            let msgConfirm = `Confirma o recebimento de R$ ${valorNum.toFixed(2)} do cliente ${nome}?`;

            if (sobra > 0) {
                msgConfirm += `\n\nO saldo restante será de R$ ${sobra.toFixed(2)}.`;
            }

            if (!confirm(msgConfirm)) return;

            // Enviamos agora o 'valor_pago' para a API tratar
            fetch('api_carteira.php?acao=receber_credito_manual', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `cliente_id=${id}&valor_pago=${valorNum}`
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    alert('✅ Pagamento registrado com sucesso!');
                    location.reload();
                } else alert('❌ Erro: ' + d.error);
            });
        }

        function adicionarCredito(id, limiteAtual, vencimentoAtual) {
            const adicional = prompt(`Limite atual: R$ ${limiteAtual}\nQuanto deseja ADICIONAR ao limite atual?`);
            if (adicional === null || adicional === "" || isNaN(adicional)) return;

            const novoLimiteTotal = parseFloat(limiteAtual) + parseFloat(adicional);

            fetch('api_carteira.php?acao=atualizar_credito_completo', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `id=${id}&limite=${novoLimiteTotal}&vencimento=${vencimentoAtual}`
            }).then(() => {
                alert('✅ Crédito somado com sucesso!');
                location.reload();
            });
        }

        function alterarVencimento(id, limiteAtual, vencimentoAtual) {
            const novoVenc = prompt(`Dia de vencimento atual: ${vencimentoAtual}\nQual o novo dia de cobrança (1-31)?`, vencimentoAtual);
            if (novoVenc === null || novoVenc === "" || isNaN(novoVenc)) return;

            fetch('api_carteira.php?acao=atualizar_credito_completo', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `id=${id}&limite=${limiteAtual}&vencimento=${novoVenc}`
            }).then(() => {
                alert('✅ Data de vencimento alterada!');
                location.reload();
            });
        }

        function abrirModalAdicionar() {
            document.getElementById('modalAdicionar').style.display = 'flex';
        }

        function fecharModal() {
            document.getElementById('modalAdicionar').style.display = 'none';
        }

        function removerCredito(clienteId, nome) {
            if (!confirm(`⚠️ REMOVER CRÉDITO MENSAL de "${nome}"?\n\nO saldo será mantido.`)) return;
            fetch('api_carteira.php?acao=remover_credito_completo', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'cliente_id=' + clienteId
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    alert('✅ Removido!');
                    location.reload();
                } else alert('❌ Erro: ' + d.error);
            });
        }

        async function buscarClientesCredito() {
            const busca = document.getElementById('buscaClienteCredito').value;
            if (busca.length < 2) return;
            const response = await fetch('api_carteira.php?acao=buscar_clientes_credito&busca=' + encodeURIComponent(busca));
            const data = await response.json();
            const div = document.getElementById('resultadosBuscaCredito');
            div.innerHTML = '';
            if (data.clientes && data.clientes.length > 0) {
                data.clientes.forEach(c => {
                    const item = document.createElement('div');
                    item.className = 'resultado-item';
                    item.innerHTML = `<strong>${c.nome_fixo}</strong> - <small>${c.telefone_formatado}</small>`;
                    item.onclick = () => {
                        document.getElementById('idSelecionado').value = c.id;
                        document.getElementById('nomeSelecionado').innerText = c.nome_fixo;
                        document.getElementById('telSelecionado').innerText = c.telefone_formatado;
                        document.getElementById('clienteSelecionadoCredito').style.display = 'block';
                        div.style.display = 'none';
                    };
                    div.appendChild(item);
                });
                div.style.display = 'block';
            }
        }

        async function confirmarCredito() {
            const id = document.getElementById('idSelecionado').value;
            const limite = document.getElementById('novoLimite').value;
            const vencimento = document.getElementById('novoVencimento').value;

            if (!limite || limite <= 0) {
                alert("Digite um limite válido!");
                return;
            }
            if (!vencimento || vencimento < 1 || vencimento > 31) {
                alert("Digite um dia de vencimento válido entre 1 e 31!");
                return;
            }

            const response = await fetch('api_carteira.php?acao=salvar_credito', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `id=${id}&limite=${limite}&vencimento=${vencimento}`
            });
            const d = await response.json();
            if (d.success) {
                alert('✅ Crédito liberado!');
                location.reload();
            } else {
                alert('❌ Erro ao liberar: ' + (d.error || 'Erro desconhecido'));
            }
        }

        function bloquearCliente(id, nome, telefone) {
            if (!confirm(`🚫 Deseja BLOQUEAR o cliente ${nome}?\n\nEle receberá um aviso no WhatsApp.`)) return;
            fetch('api_carteira.php?acao=bloquear_com_aviso', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `id=${id}&telefone=${telefone}&nome=${encodeURIComponent(nome)}`
            }).then(() => {
                alert('✅ Cliente bloqueado e avisado!');
                location.reload();
            });
        }

        function desbloquearCliente(id, nome, telefone) {
            if (!confirm(`✅ Deseja DESBLOQUEAR o cliente ${nome}?\n\nEle receberá um aviso no WhatsApp.`)) return;
            fetch('api_carteira.php?acao=desbloquear_com_aviso', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `id=${id}&telefone=${telefone}&nome=${encodeURIComponent(nome)}`
            }).then(() => {
                alert('✅ Cliente desbloqueado e avisado!');
                location.reload();
            });
        }
    </script>
</body>

</html>