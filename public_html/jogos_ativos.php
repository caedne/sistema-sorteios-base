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

// ==========================================
// AÇÕES INDIVIDUAIS DO QUADRADO
// ==========================================
if (isset($_POST['acao_individual'])) {
    $acao = $_POST['acao_individual'];
    $sorteio_id = intval($_POST['sorteio_id']);
    $numero = intval($_POST['numero']);
    $telefone = $_POST['telefone'];

    header('Content-Type: application/json');

    function enviarMsgBot($tel, $msg)
    {
        $ch = curl_init('http://localhost:3000/api/enviar-mensagem-simples');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['telefone' => $tel, 'mensagem' => $msg]));
        curl_exec($ch);
        curl_close($ch);
    }

    if ($acao === 'marcar_pago') {
        $conn->query("UPDATE vendas SET status_venda = 'pago', data_reserva = NOW() WHERE sorteio_id = $sorteio_id AND numero_escolhido = $numero");

        $s_q = $conn->query("SELECT titulo, numero_visual FROM sorteios WHERE id = $sorteio_id");
        $s_dados = $s_q->fetch_assoc();

        $msg = "✅ *PAGAMENTO CONFIRMADO MANUALMENTE*\n\n🎁 Rifa: {$s_dados['titulo']} #{$s_dados['numero_visual']}\n🎫 Número: $numero\n🍀 Boa sorte!";
        enviarMsgBot($telefone, $msg);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($acao === 'cancelar_numero') {
        $v_q = $conn->query("SELECT status_venda, cliente_id, id_whatsapp FROM vendas WHERE sorteio_id = $sorteio_id AND numero_escolhido = $numero");
        if ($v_q->num_rows > 0) {
            $venda = $v_q->fetch_assoc();

            $s_q = $conn->query("SELECT titulo, numero_visual, valor_numero FROM sorteios WHERE id = $sorteio_id");
            $s_dados = $s_q->fetch_assoc();
            $valor = floatval($s_dados['valor_numero']);

            if ($venda['status_venda'] === 'pago') {
                $cId = $venda['cliente_id'];

                if (!$cId && $venda['id_whatsapp']) {
                    $c_q = $conn->query("SELECT id FROM agenda_clientes WHERE id_whatsapp = '{$venda['id_whatsapp']}'");
                    if ($c_q->num_rows > 0)
                        $cId = $c_q->fetch_assoc()['id'];
                }

                if ($cId) {
                    $cart_q = $conn->query("SELECT saldo FROM carteiras WHERE cliente_id = $cId");
                    if ($cart_q->num_rows > 0) {
                        $conn->query("UPDATE carteiras SET saldo = saldo + $valor WHERE cliente_id = $cId");
                    } else {
                        $conn->query("INSERT INTO carteiras (cliente_id, saldo, credito_limite, credito_usado, status, data_criacao) VALUES ($cId, $valor, 0, 0, 'ativo', NOW())");
                    }

                    $desc = "Estorno (Número Individual): {$s_dados['titulo']} #{$s_dados['numero_visual']} - Nº " . str_pad($numero, 2, '0', STR_PAD_LEFT);
                    $conn->query("INSERT INTO transacoes_carteira (cliente_id, tipo, valor, descricao, data_transacao) VALUES ($cId, 'estorno', $valor, '$desc', NOW())");
                }

                $msg = "⚠️ *RESERVA CANCELADA COM ESTORNO*\n\nO número *$numero* da rifa *{$s_dados['titulo']}* foi cancelado pelo administrador.\n\n✅ Como você já havia pago, o valor de *R$ " . number_format($valor, 2, ',', '.') . "* foi devolvido para o seu *Saldo na Carteira*!";
            } else {
                $msg = "⚠️ *RESERVA CANCELADA*\n\nO número *$numero* da rifa *{$s_dados['titulo']}* foi cancelado.";
            }

            $conn->query("DELETE FROM vendas WHERE sorteio_id = $sorteio_id AND numero_escolhido = $numero");
            enviarMsgBot($telefone, $msg);
        }
        echo json_encode(['success' => true]);
        exit;
    }
}

// ==========================================
// COMUNICAÇÃO COM O ROBÔ
// ==========================================
if (isset($_POST['acao_robo'])) {
    $acao = $_POST['acao_robo'];
    $id = intval($_POST['sorteio_id']);

    $url = "http://localhost:3000/api/reenviar-lista";
    if ($acao === 'alerta')
        $url = "http://localhost:3000/api/enviar-alerta-restantes";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['sorteio_id' => $id]));
    $res = curl_exec($ch);
    curl_close($ch);

    header('Content-Type: application/json');
    echo $res;
    exit;
}

// Lógica de Cancelamento
if (isset($_POST['acao_cancelar']) && isset($_POST['id_cancelar'])) {
    $id = intval($_POST['id_cancelar']);

    $conn->query("UPDATE sorteios SET status = 'cancelado' WHERE id = $id AND status NOT IN ('finalizado', 'finalizando', 'gravar_video', 'video_pronto')");

    if ($conn->affected_rows === 0) {
        echo "<script>alert('⚠️ AÇÃO BLOQUEADA: Este sorteio já encerrou ou está rolando a roleta!'); window.location.href = 'jogos_ativos.php';</script>";
        exit;
    }

    header("Location: jogos_ativos.php");
    exit;
}

// Marcar Tudo Pago Manualmente
if (isset($_POST['acao_tudo_pago']) && isset($_POST['id_tudo_pago'])) {
    $id = intval($_POST['id_tudo_pago']);
    $conn->query("UPDATE vendas SET status_venda = 'pago', data_reserva = NOW() WHERE sorteio_id = $id AND status_venda != 'pago'");
    header("Location: jogos_ativos.php");
    exit;
}

// Funções Auxiliares
function renderizarListaPremios($stringPremios)
{
    if (!$stringPremios)
        return "<li>Sem prêmios definidos</li>";
    $lista = explode("|||", $stringPremios);
    $html = "";
    $icones = ["1️⃣", "2️⃣", "3️⃣", "4️⃣", "5️⃣", "6️⃣", "7️⃣", "8️⃣", "9️⃣", "🔟"];
    foreach ($lista as $index => $premio) {
        $ico = $icones[$index] ?? "🎁";
        $html .= "<li><span class='icone-premio'>$ico</span> <span class='texto-premio'>$premio</span></li>";
    }
    return $html;
}

function getSorteioAtivo($conn)
{
    $res = $conn->query("SELECT * FROM sorteios WHERE status IN ('ativo', 'aguardando_manual', 'gravar_video', 'gravando') ORDER BY id DESC LIMIT 1");
    return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
}

function getVendasMap($conn, $id)
{
    $map = [];
    if (!$id)
        return $map;

    $sql = "SELECT numero_escolhido, status_venda, nome_comprador, telefone FROM vendas WHERE sorteio_id = ? ORDER BY numero_escolhido";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $status = trim($row['status_venda']);
        $map[(int) $row['numero_escolhido']] = [
            'status' => empty($status) ? 'pendente' : strtolower($status),
            'nome' => $row['nome_comprador'] ?? 'Cliente',
            'tel' => $row['telefone'] ?? ''
        ];
    }
    $stmt->close();
    return $map;
}

$sorteio = getSorteioAtivo($conn);
$vendas_map = getVendasMap($conn, $sorteio['id'] ?? null);

$vendas_pagas_modal = [];
foreach ($vendas_map as $numero => $dados_v) {
    if ($dados_v['status'] == 'pago') {
        $vendas_pagas_modal[] = ['numero' => $numero, 'nome' => $dados_v['nome']];
    }
}
$total_pagos = count($vendas_pagas_modal);
$qtd = $sorteio['qtd_numeros'] ?? 25;
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Jogo Ativo | D'King Sorteios</title>
    <link rel="stylesheet" href="assets/css/global.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/jogos.css?v=<?php echo time(); ?>">
</head>

<body>

    <div class="layout-sistema">
        <aside class="sidebar">
            <?php include 'componentes/sidebar.php'; ?>
        </aside>

        <main class="conteudo-principal">

            <div class="jogos-body" style="margin-top: 10px;">
                <div class="conteudo-aba-flex" style="display: flex;">

                    <?php if ($sorteio): ?>
                        <div class="coluna-numeros">
                            <div class="grid-unificado">
                                <?php
                                for ($i = 1; $i <= $qtd; $i++):
                                    $d = $vendas_map[$i] ?? null;
                                    if ($d !== null) {
                                        $st = $d['status'];
                                        $classe = ($st === 'pago') ? 'pago' : 'pendente';
                                        $nomeJs = addslashes($d['nome']);
                                        $onclick = "abrirOpcoesIndividuais({$sorteio['id']}, $i, '$st', '$nomeJs', '{$d['tel']}');";
                                        echo "<div class='quadrado-numero $classe' onclick=\"$onclick\" style='cursor:pointer;' title='Gerenciar este número'>";
                                        echo "<span class='numero-grande'>$i</span>";
                                        echo "<span class='nome-pequeno'>" . htmlspecialchars($d['nome']) . "</span>";
                                        echo "</div>";
                                    } else {
                                        echo "<div class='quadrado-numero livre'>";
                                        echo "<span class='numero-grande'>$i</span>";
                                        echo "<span class='nome-pequeno'></span>";
                                        echo "</div>";
                                    }
                                endfor;
                                ?>
                            </div>

                            <div class="status-barra-container">
                                <div class="stat-line">
                                    <span>VENDIDOS: <?php echo $total_pagos; ?>/<?php echo $qtd; ?></span>
                                    <span><?php echo ($qtd > 0) ? round(($total_pagos / $qtd) * 100) : 0; ?>%</span>
                                </div>
                                <div class="bar-bg">
                                    <div class="bar-fill"
                                        style="width: <?php echo ($qtd > 0) ? ($total_pagos / $qtd) * 100 : 0; ?>%;"></div>
                                </div>
                            </div>

                            <div class="botoes-acao-row">
                                <form method="POST" style="flex: 1 1 auto; display: flex;">
                                    <input type="hidden" name="acao_tudo_pago" value="1">
                                    <input type="hidden" name="id_tudo_pago" value="<?php echo $sorteio['id']; ?>">
                                    <button type="submit" class="btn-acao-custom btn-tudo-pago"
                                        style="cursor: pointer; width: 100%;"
                                        onclick="return confirm('⚠️ ATENÇÃO: Deseja marcar TODOS os números reservados desta rifa como PAGOS?');">
                                        TUDO PAGO
                                    </button>
                                </form>

                                <button onclick="chamarTodos(<?php echo $sorteio['id']; ?>)"
                                    class="btn-acao-custom btn-chamar">
                                    📢 CHAMAR
                                </button>

                                <button onclick="acionarRobo(<?php echo $sorteio['id']; ?>, 'reenviar')"
                                    class="btn-acao-custom btn-reenviar">
                                    🔄 LISTA
                                </button>

                                <button onclick="acionarRobo(<?php echo $sorteio['id']; ?>, 'alerta')"
                                    class="btn-acao-custom btn-alerta">
                                    ⚠️ FALTAM
                                </button>

                                <?php if ($total_pagos >= $qtd): ?>
                                    <?php if ($sorteio['status'] === 'gravar_video' || $sorteio['status'] === 'gravando'): ?>
                                        <button disabled class="btn-acao-custom"
                                            style="background: #64748b; cursor: not-allowed; opacity: 0.7;">
                                            ⏳ GRAVANDO...
                                        </button>
                                    <?php else: ?>
                                        <?php
                                        $payload = base64_encode(json_encode([
                                            'id' => $sorteio['id'],
                                            'cat' => 'geral',
                                            'premios' => explode('|||', $sorteio['premios']),
                                            'numeros' => $vendas_pagas_modal
                                        ]));
                                        ?>
                                        <button onclick="abrirModalSorteio('<?php echo $payload; ?>')"
                                            class="btn-acao-custom btn-sortear-manual">
                                            🎰 SORTEAR
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <form method="POST" class="form-cancelar" style="flex: 1 1 auto; display: flex;">
                                    <input type="hidden" name="acao_cancelar" value="1">
                                    <input type="hidden" name="id_cancelar" value="<?php echo $sorteio['id']; ?>">
                                    <button type="submit" class="btn-acao-custom btn-cancelar-outline"
                                        onclick="return confirm('⚠️ ATENÇÃO: Deseja CANCELAR este sorteio?\n\nO robô irá devolver automaticamente o dinheiro para a carteira de todos os clientes pagos.');">
                                        CANCELAR
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="coluna-premios">
                            <div class="info-sorteio-lateral">
                                <h2>
                                    <?php echo htmlspecialchars($sorteio['titulo']); ?>
                                    <span
                                        class="badge-num">#<?php echo str_pad($sorteio['numero_visual'], 2, '0', STR_PAD_LEFT); ?></span>
                                </h2>
                            </div>

                            <h3 class="titulo-premios">🏆 PRÊMIOS</h3>
                            <ul class="lista-premios">
                                <?php echo renderizarListaPremios($sorteio['premios'] ?? ''); ?>
                            </ul>

                            <div class="info-extra">
                                <small>VALOR DA COTA</small><br>
                                <strong style="font-size:24px;">R$
                                    <?php echo number_format($sorteio['valor_numero'] ?? 10, 2, ',', '.'); ?></strong>
                            </div>
                        </div>

                    <?php else: ?>
                        <div class="card-vazio" style="width: 100%;">
                            <div class="icone-vazio">🎟️</div>
                            <div class="texto-vazio">Nenhum sorteio ativo no momento</div>
                            <div class="subtexto-vazio">Inicie um novo sorteio para gerenciar as cotas e os participantes.
                            </div>
                            <a href="selecionar_jogo.php" class="btn-novo-sorteio btn-amarelo">
                                ⚡ INICIAR NOVO SORTEIO
                            </a>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </main>
    </div>

    <div id="modalSorteio" class="modal-sorteio-overlay" style="display:none;"></div>

    <div id="modalInd" class="modal-sorteio-overlay" style="display:none; z-index:10001;">
        <div class="modal-sorteio-box">
            <h3 style="text-align:center; margin-top:0; font-weight:900;">Opções do Número <span id="ind_num_display"
                    style="color:#22c55e;"></span></h3>
            <p style="text-align:center; color:#64748b; margin-bottom:20px;">
                👤 <b id="ind_nome_display" style="color:#1e293b;"></b><br>
                📱 <span id="ind_tel_display"></span>
            </p>

            <input type="hidden" id="ind_sorteio_id">
            <input type="hidden" id="ind_numero">
            <input type="hidden" id="ind_tel">

            <div style="display:flex; flex-direction:column; gap:10px;">
                <button id="btnIndPago" onclick="acaoIndividual('marcar_pago')"
                    style="background:#22c55e; color:white; padding:12px; border:none; border-radius:8px; font-weight:900; cursor:pointer;">✅
                    MARCAR COMO PAGO</button>

                <button onclick="acaoIndividual('cancelar_numero')"
                    style="background:#ef4444; color:white; padding:12px; border:none; border-radius:8px; font-weight:900; cursor:pointer;">🗑️
                    CANCELAR NÚMERO / ESTORNAR</button>

                <button onclick="document.getElementById('boxMsgInd').style.display='block'"
                    style="background:#0284c7; color:white; padding:12px; border:none; border-radius:8px; font-weight:900; cursor:pointer;">💬
                    ENVIAR MENSAGEM WHATSAPP</button>
            </div>

            <div id="boxMsgInd" style="display:none; margin-top:15px; border-top:1px solid #e2e8f0; padding-top:15px;">
                <textarea id="ind_texto_msg" rows="3" placeholder="Escreva a mensagem aqui..."
                    style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1; margin-bottom:10px; resize:none; font-family:inherit;"></textarea>
                <button onclick="acaoIndividual('enviar_msg')"
                    style="width:100%; background:#8b5cf6; color:white; padding:10px; border:none; border-radius:8px; font-weight:900; cursor:pointer;">🚀
                    ENVIAR</button>
            </div>

            <button onclick="document.getElementById('modalInd').style.display='none'"
                style="width:100%; background:#e2e8f0; color:#334155; padding:12px; border:none; border-radius:8px; font-weight:900; cursor:pointer; margin-top:15px;">FECHAR</button>
        </div>
    </div>


    <script>
        function abrirOpcoesIndividuais(sorteio_id, numero, status, nome, telefone) {
            document.getElementById('ind_sorteio_id').value = sorteio_id;
            document.getElementById('ind_numero').value = numero;
            document.getElementById('ind_tel').value = telefone;

            document.getElementById('ind_num_display').innerText = numero;
            document.getElementById('ind_nome_display').innerText = nome;
            document.getElementById('ind_tel_display').innerText = telefone;

            const btnPago = document.getElementById('btnIndPago');
            if (status === 'pago') {
                btnPago.style.display = 'none';
            } else {
                btnPago.style.display = 'block';
            }

            document.getElementById('boxMsgInd').style.display = 'none';
            document.getElementById('ind_texto_msg').value = '';

            document.getElementById('modalInd').style.display = 'flex';
        }

        function acaoIndividual(acao) {
            const sorteio_id = document.getElementById('ind_sorteio_id').value;
            const numero = document.getElementById('ind_numero').value;
            const telefone = document.getElementById('ind_tel').value;
            const texto_msg = document.getElementById('ind_texto_msg').value;

            if (acao === 'cancelar_numero') {
                if (!confirm("⚠️ Tem certeza que deseja apagar a reserva deste número?\n\nSe ele já estiver PAGO, o sistema devolverá o valor para a carteira do cliente automaticamente.")) return;
            }

            if (acao === 'enviar_msg' && texto_msg.trim() === '') {
                alert("Escreva uma mensagem primeiro!");
                return;
            }

            const containerModal = document.querySelector('#modalInd .modal-sorteio-box');
            containerModal.style.opacity = '0.5';

            let fd = new FormData();
            fd.append('acao_individual', acao);
            fd.append('sorteio_id', sorteio_id);
            fd.append('numero', numero);
            fd.append('telefone', telefone);
            if (acao === 'enviar_msg') fd.append('texto_msg', texto_msg);

            fetch(window.location.href, {
                method: 'POST',
                body: fd
            })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        if (acao === 'enviar_msg') {
                            alert("✅ Mensagem enviada!");
                            document.getElementById('modalInd').style.display = 'none';
                            containerModal.style.opacity = '1';
                        } else {
                            window.location.reload();
                        }
                    } else {
                        alert("❌ Erro ao processar ação.");
                        containerModal.style.opacity = '1';
                    }
                })
                .catch(e => {
                    console.error(e);
                    alert("❌ Erro de conexão com o servidor.");
                    containerModal.style.opacity = '1';
                });
        }

        function chamarTodos(idSorteio) {
            if (!confirm('⚠️ ATENÇÃO: Marcar todos os membros no WhatsApp?')) return;
            let btn = event.target;
            if (btn.tagName !== 'BUTTON') btn = btn.closest('button');
            let txtOriginal = btn.innerText;
            btn.innerText = "⏳ Enviando...";
            btn.disabled = true;
            let fd = new FormData();
            fd.append('id', idSorteio);
            fetch('../sistema_sorteios/chamar_todos.php', {
                method: 'POST',
                body: fd
            })
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'sucesso' || d.success === true) alert("✅ Feito!");
                    else alert("❌ Erro: " + (d.erro || "Sem resposta."));
                })
                .catch(e => {
                    console.error(e);
                    alert("Erro de conexão.");
                })
                .finally(() => {
                    btn.innerText = txtOriginal;
                    btn.disabled = false;
                });
        }

        function abrirModalSorteio(base64) {
            const modal = document.getElementById('modalSorteio');
            try {
                const dados = JSON.parse(decodeURIComponent(escape(window.atob(base64))));

                let htmlPremios = '';
                const icones = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];
                dados.premios.forEach((p, i) => {
                    let options = dados.numeros.map(n => `<option value="${n.numero}" data-nome="${n.nome}">${n.numero} - ${n.nome}</option>`).join('');
                    htmlPremios += `
                <div class="premio-item-box" style="background:#f8fafc; padding:15px; border-radius:12px; margin-bottom:15px; border:1px solid #e2e8f0;">
                    <h4 style="margin:0 0 10px 0;">${icones[i] || (i + 1) + 'º'} Lugar - ${p}</h4>
                    <select name="numero_${i}" required style="width:100%; padding:10px; border-radius:8px; border:2px solid #cbd5e1; font-weight:bold;" 
                            onchange="this.nextElementSibling.value=this.options[this.selectedIndex].getAttribute('data-nome')">
                        <option value="">-- Selecione o Ganhador --</option>
                        ${options}
                    </select>
                    <input type="text" name="nome_${i}" placeholder="Nome do Ganhador" readonly style="width:100%; padding:10px; margin-top:5px; border:none; background:transparent; font-weight:800; color:#1e293b;">
                    <input type="hidden" name="premio_${i}" value="${p}">
                </div>`;
                });

                modal.innerHTML = `
            <div class="modal-sorteio-box">
                <h2 style="text-align:center; margin-bottom:20px; font-weight:900;">🎰 REALIZAR SORTEIO</h2>
                
                <form id="formSorteioAJAX" onsubmit="enviarSorteioImediato(event, this)">
                    <input type="hidden" name="sorteio_id" value="${dados.id}">
                    <input type="hidden" name="categoria" value="geral">
                    ${htmlPremios}
                    <div style="display:flex; gap:10px; margin-top:20px;">
                        <button type="button" onclick="fecharModalSorteio()" style="flex:1; padding:15px; border-radius:10px; border:none; background:#e2e8f0; font-weight:900; cursor:pointer;">CANCELAR</button>
                        <button type="submit" id="btnConfirmar" style="flex:2; padding:15px; border-radius:10px; border:none; background:#8b5cf6; color:white; font-weight:900; cursor:pointer;">✅ CONFIRMAR SORTEIO</button>
                    </div>
                </form>
            </div>`;
                modal.style.display = 'flex';
            } catch (e) {
                console.error("Erro:", e);
            }
        }

        function enviarSorteioImediato(event, form) {
            event.preventDefault();
            const btn = document.getElementById('btnConfirmar');
            const formData = new FormData(form);

            btn.disabled = true;
            btn.innerText = "⏳ PROCESSANDO...";
            fecharModalSorteio();

            alert("🚀 Sorteio enviado! O robô está a processar as mensagens. A página irá atualizar em breve.");

            fetch('processar_sorteio_manual.php', {
                method: 'POST',
                body: formData
            })
                .then(response => {
                    window.location.reload();
                })
                .catch(error => {
                    console.error('Erro no processamento:', error);
                    alert("❌ Ocorreu um erro ao salvar o sorteio.");
                });
        }

        function fecharModalSorteio() {
            document.getElementById('modalSorteio').style.display = 'none';
        }

        function acionarRobo(idSorteio, acao) {
            let btn = event.target.closest('button');
            let txtOriginal = btn.innerHTML;
            btn.innerHTML = "⏳...";
            btn.disabled = true;

            let fd = new FormData();
            fd.append('acao_robo', acao);
            fd.append('sorteio_id', idSorteio);

            fetch(window.location.href, {
                method: 'POST',
                body: fd
            })
                .then(async r => {
                    if (!r.ok) throw new Error("Erro HTTP: " + r.status);
                    const text = await r.text();
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error("Resposta inválida do servidor: " + text.substring(0, 50));
                    }
                })
                .then(d => {
                    if (d.success) alert("✅ Sucesso: O Robô enviou a mensagem!");
                    else alert("❌ Erro: " + (d.error || "Erro no robô"));
                })
                .catch(e => {
                    console.error(e);
                    alert("❌ Ocorreu um erro ao comunicar com o robô.");
                })
                .finally(() => {
                    btn.innerHTML = txtOriginal;
                    btn.disabled = false;
                });
        }
    </script>
</body>

</html>