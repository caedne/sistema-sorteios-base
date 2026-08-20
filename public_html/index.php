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
// COMUNICAÇÃO COM O ROBÔ
// ==========================================
if (isset($_POST['acao_robo'])) {
    $acao = $_POST['acao_robo'];
    $id = intval($_POST['sorteio_id']);

    $url = "http://localhost:3000/api/reenviar-lista";
    if ($acao === 'alerta') {
        $url = "http://localhost:3000/api/enviar-alerta-restantes";
    } elseif ($acao === 'chamar_todos') {
        $url = "http://localhost:3000/api/chamar-todos"; // Conecta o chamar todos no robô
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    // Enviamos a categoria "geral" para o robô não se perder
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['sorteio_id' => $id, 'categoria' => 'geral']));
    $res = curl_exec($ch);
    curl_close($ch);

    if (!$res)
        $res = json_encode(['success' => false, 'error' => 'O Robô (Node.js) não está respondendo.']);

    ob_clean(); // <-- ISSO RESOLVE O "ERRO DE CONEXÃO" DOS BOTÕES!
    header('Content-Type: application/json');
    echo $res;
    exit;
}
// ==========================================
// MARCAR TUDO PAGO MANUALMENTE
// ==========================================
if (isset($_POST['acao_tudo_pago']) && isset($_POST['id_tudo_pago'])) {
    $id = intval($_POST['id_tudo_pago']);
    $conn->query("UPDATE vendas SET status_venda = 'pago', data_reserva = NOW() WHERE sorteio_id = $id AND status_venda != 'pago'");
    header("Location: index.php");
    exit;
}

// =======================================================
// LÓGICA PARA MUDAR A SENHA POR DENTRO DO SISTEMA
// =======================================================
if (isset($_POST['acao_mudar_senha_logado'])) {
    $usuario_logado = $_SESSION['admin_usuario'] ?? '';
    $senha_atual = $conn->real_escape_string($_POST['senha_atual']);
    $nova_senha = $conn->real_escape_string($_POST['nova_senha']);

    if ($usuario_logado) {
        $busca = $conn->query("SELECT * FROM admin_usuarios WHERE login = '$usuario_logado' AND senha = '$senha_atual'");
        if ($busca->num_rows > 0) {
            $conn->query("UPDATE admin_usuarios SET senha = '$nova_senha' WHERE login = '$usuario_logado'");
            echo "<script>alert('✅ Senha alterada com sucesso!');</script>";
        } else {
            echo "<script>alert('❌ A senha atual está incorreta!');</script>";
        }
    }
}

// Lógica de Cancelamento de Sorteio
if (isset($_POST['acao_cancelar']) && isset($_POST['id_cancelar'])) {
    $id = intval($_POST['id_cancelar']);

    $conn->query("UPDATE sorteios SET status = 'cancelado' WHERE id = $id AND status NOT IN ('finalizado', 'finalizando', 'gravar_video', 'video_pronto')");

    if ($conn->affected_rows === 0) {
        echo "<script>alert('⚠️ AÇÃO BLOQUEADA: Este sorteio já encerrou ou está rolando a roleta! A tela será atualizada com as informações corretas.'); window.location.href = 'index.php';</script>";
        exit;
    }

    header("Location: index.php");
    exit;
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

    $sql = "SELECT numero_escolhido, status_venda, nome_comprador, telefone 
            FROM vendas 
            WHERE sorteio_id = ? 
            ORDER BY numero_escolhido";

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
$pagos_total = 0;
foreach ($vendas_map as $numero => $dados_v) {
    if ($dados_v['status'] == 'pago') {
        $pagos_total++;
        $vendas_pagas_modal[] = ['numero' => $numero, 'nome' => $dados_v['nome']];
    }
}
$qtd = $sorteio['qtd_numeros'] ?? 25;
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>D'KING | Painel de Sorteios</title>
    <link rel="stylesheet" href="assets/css/global.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/index.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/jogos.css?v=<?php echo time(); ?>">
</head>

<body>

    <div class="layout-sistema">
        <aside class="sidebar">
            <?php include 'componentes/sidebar.php'; ?>
        </aside>

        <main class="conteudo-principal">

            <div class="welcome-area">
                <h1>Bem-Vindo, <?php echo htmlspecialchars($cliente['nome_fantasia'] ?? 'Administrador'); ?>! 👑</h1>
            </div>

            <div class="dashboard-body card-unico-central">
                <?php
                $classeGrid = "grid-numeros-dashboard grid-" . $qtd;

                $numVisual = "01";
                if ($sorteio) {
                    $rawNum = $sorteio['numero_visual'] ?? $sorteio['id'];
                    $numVisual = str_pad($rawNum, 2, '0', STR_PAD_LEFT);
                }
                ?>
                <div class="card-dashboard">

                    <a href="jogos_ativos.php" class="btn-titulo-card btn-topo-verde">
                        ⚡ GERIR SORTEIO
                    </a>

                    <?php if ($sorteio): ?>

                        <div class="header-titulo-organizado">
                            <span class="badge-cat badge-sorteio">SORTEIO</span>
                            <span class="titulo-nome"><?php echo htmlspecialchars($sorteio['titulo']); ?></span>
                            <span class="badge-id">#<?php echo $numVisual; ?></span>
                        </div>

                        <div class="<?php echo $classeGrid; ?>">
                            <?php
                            for ($i = 1; $i <= $qtd; $i++):
                                $d = $vendas_map[$i] ?? null;
                                $status = $d ? $d['status'] : '';
                                $classeNum = 'num-livre';

                                if ($status == 'pago') {
                                    $classeNum = 'num-pago-verde';
                                } elseif ($status == 'pendente' || $status == 'reservado') {
                                    $classeNum = 'num-pendente';
                                }
                                ?>
                                <div class="box-numero <?php echo $classeNum; ?>">
                                    <?php echo $i; ?>
                                </div>
                            <?php endfor; ?>
                        </div>

                        <?php
                        $vendas = $pagos_total;
                        $total_numeros = $qtd;
                        include 'componentes/componentes_status.php';
                        ?>

                        <div class="botoes-acao-row" style="margin-top: 20px;">
                            <form method="POST" style="flex: 1 1 auto; display: flex;">
                                <input type="hidden" name="acao_tudo_pago" value="1">
                                <input type="hidden" name="id_tudo_pago" value="<?php echo $sorteio['id']; ?>">
                                <button type="submit" class="btn-acao-custom btn-tudo-pago"
                                    style="cursor: pointer; width: 100%;"
                                    onclick="return confirm('⚠️ ATENÇÃO: Deseja marcar TODOS os números reservados desta rifa como PAGOS?');">
                                    TUDO PAGO
                                </button>
                            </form>

                            <button onclick="acionarRobo(<?php echo $sorteio['id']; ?>, 'chamar_todos')"
                                class="btn-acao-custom btn-chamar">
                                📢 CHAMAR TODOS NO GRUPO
                            </button>

                            <button onclick="acionarRobo(<?php echo $sorteio['id']; ?>, 'reenviar')"
                                class="btn-acao-custom btn-reenviar">
                                🔄 ENVIAR LISTA
                            </button>

                            <button onclick="acionarRobo(<?php echo $sorteio['id']; ?>, 'alerta')"
                                class="btn-acao-custom btn-alerta">
                                ⚠️ FALTAM X NÚMEROS
                            </button>

                            <!-- TRAVA ORIGINAL RESTAURADA: Só aparece se pagos_total >= qtd -->
                            <?php if ($pagos_total >= $qtd): ?>
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

                            <form method="POST" class="form-cancelar" style="flex: 1 1 auto; display: flex;">
                                <input type="hidden" name="acao_cancelar" value="1">
                                <input type="hidden" name="id_cancelar" value="<?php echo $sorteio['id']; ?>">
                                <button type="submit" class="btn-acao-custom btn-cancelar-outline"
                                    onclick="return confirm('⚠️ ATENÇÃO: Deseja CANCELAR este sorteio?\n\nO robô irá devolver automaticamente o dinheiro para a carteira de todos os clientes pagos.');">
                                    CANCELAR
                                </button>
                            </form>
                        </div>

                    <?php else: ?>

                        <div class="card-vazio">
                            <div class="icone-vazio">🎟️</div>
                            <div class="texto-vazio">Nenhum sorteio ativo no momento</div>
                            <a href="selecionar_jogo.php" class="btn-novo-sorteio btn-amarelo">
                                ⚡ INICIAR NOVO SORTEIO
                            </a>
                        </div>

                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>

    <div class="widget-usuario">
        <div class="widget-avatar">👤</div>
        <div class="widget-info" onclick="abrirModalSenhaLogado()" title="Clique para alterar sua senha">
            <span class="widget-nome"><?php echo htmlspecialchars($_SESSION['admin_usuario'] ?? 'Admin'); ?></span>
            <span class="widget-acao">🔒 Mudar Senha</span>
        </div>
        <a href="logout.php" class="btn-sair-widget">Sair 🚪</a>
    </div>

    <div id="modalSenhaLogado"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9999;">
        <div
            style="background:#1e293b; padding:30px; border-radius:15px; width:90%; max-width:350px; margin:auto; position:relative; top:50%; transform:translateY(-50%); text-align:center; border:1px solid #334155; box-shadow:0 10px 25px rgba(0,0,0,0.5);">
            <h3 style="color:white; margin-top:0;">Mudar Senha</h3>
            <p style="color:#94a3b8; font-size:13px; margin-bottom:20px;">Conta conectada:<br><b
                    style="color:#22c55e; font-size:15px;"><?php echo htmlspecialchars($_SESSION['admin_usuario'] ?? ''); ?></b>
            </p>

            <form method="POST" action="index.php">
                <input type="hidden" name="acao_mudar_senha_logado" value="1">
                <input type="password" name="senha_atual" placeholder="Sua Senha Atual" required
                    style="width:85%; padding:12px; margin-bottom:15px; border-radius:8px; border:1px solid #334155; background:#0f172a; color:white; text-align:center; outline:none;">
                <input type="password" name="nova_senha" placeholder="Digite a Nova Senha" required
                    style="width:85%; padding:12px; margin-bottom:20px; border-radius:8px; border:1px solid #334155; background:#0f172a; color:white; text-align:center; outline:none;">

                <div style="display:flex; gap:10px; justify-content:center;">
                    <button type="button" onclick="fecharModalSenhaLogado()"
                        style="padding:10px 20px; border-radius:8px; background:transparent; border:1px solid #ef4444; color:#ef4444; cursor:pointer; font-weight:bold;">Cancelar</button>
                    <button type="submit"
                        style="padding:10px 20px; border-radius:8px; background:#f59e0b; color:white; border:none; cursor:pointer; font-weight:bold;">Salvar</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modalSorteio"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9999;">
    </div>

    <script>
        // --- FUNÇÕES DO WIDGET DE SENHA ---
        function abrirModalSenhaLogado() {
            document.getElementById('modalSenhaLogado').style.display = 'block';
        }
        function fecharModalSenhaLogado() {
            document.getElementById('modalSenhaLogado').style.display = 'none';
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
                    if (!r.ok) throw new Error("Erro HTTP");
                    const text = await r.text();
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error("Resposta inválida do servidor.");
                    }
                })
                .then(d => {
                    if (d.success || d.status === 'sucesso' || d.message) alert("✅ Sucesso: Comando enviado!");
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

        function abrirModalSorteio(base64) {
            const modal = document.getElementById('modalSorteio');
            try {
                const dados = JSON.parse(decodeURIComponent(escape(window.atob(base64))));

                let htmlPremios = '';
                const icones = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣'];
                dados.premios.forEach((p, i) => {
                    let options = dados.numeros.map(n => `<option value="${n.numero}" data-nome="${n.nome}">${n.numero} - ${n.nome}</option>`).join('');
                    htmlPremios += `
                <div class="premio-item-box" style="margin-bottom:20px; padding:20px; background:#f8fafc; border-radius:10px; border-left:4px solid #22c55e;">
                    <h4 style="margin:0 0 15px 0;">${icones[i] || (i + 1) + 'º'} Lugar - ${p}</h4>
                    <select name="numero_${i}" required onchange="document.getElementById('nome_${i}').value=this.options[this.selectedIndex].getAttribute('data-nome')" style="width:100%; padding:12px; border:2px solid #cbd5e1; border-radius:8px; font-weight:600; margin-bottom:10px;">
                        <option value="">Selecione</option>
                        ${options}
                    </select>
                    <input type="text" name="nome_${i}" id="nome_${i}" readonly style="width:100%; padding:12px; background:#e2e8f0; border:2px solid #cbd5e1; border-radius:8px;">
                    <input type="hidden" name="premio_${i}" value="${p}">
                </div>`;
                });

                modal.innerHTML = `
            <div style="background:white; border-radius:20px; width:90%; max-width:600px; max-height:80vh; overflow-y:auto; margin: auto; position: relative; top: 50%; transform: translateY(-50%);">
                <div style="background:#1e293b; color:#facc15; padding:20px; border-radius:20px 20px 0 0; display:flex; justify-content:space-between;">
                    <h2 style="margin:0;">🎰 SORTEAR MANUALMENTE</h2>
                    <button onclick="fecharModal()" style="background:none; border:none; color:white; font-size:24px; cursor:pointer;">✖</button>
                </div>
                <form id="formSorteio" method="POST" action="processar_sorteio_manual.php" style="padding:30px;">
                    <input type="hidden" name="sorteio_id" value="${dados.id}">
                    <input type="hidden" name="categoria" value="${dados.cat}">
                    <div id="campos-premios">${htmlPremios}</div>
                    <div style="display:flex; gap:10px; justify-content:flex-end; padding-top:20px; border-top:2px solid #e2e8f0;">
                        <button type="button" onclick="fecharModal()" style="padding:12px 30px; border-radius:8px; font-weight:900; background:white; border:2px solid #ef4444; color:#ef4444; cursor:pointer;">CANCELAR</button>
                        <button type="submit" style="padding:12px 30px; border-radius:8px; font-weight:900; background:#22c55e; color:white; border:none; cursor:pointer;">✅ SALVAR</button>
                    </div>
                </form>
            </div>
            `;

                document.getElementById('formSorteio').addEventListener('submit', function (e) {
                    e.preventDefault();
                    const nums = [];
                    const selects = this.querySelectorAll('select[name^="numero_"]');
                    for (let s of selects) {
                        if (!s.value) {
                            alert('⚠️ Preencha todos!');
                            return;
                        }
                        if (nums.includes(s.value)) {
                            alert('⚠️ Duplicado!');
                            return;
                        }
                        nums.push(s.value);
                    }
                    if (confirm('✅ Confirma o Sorteio?')) this.submit();
                });

                modal.style.display = 'block';
            } catch (e) {
                console.error("Erro:", e);
                alert("Nenhum número pago disponível para sortear.");
            }
        }

        function fecharModal() {
            const modal = document.getElementById('modalSorteio');
            if (modal) modal.style.display = 'none';
        }

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('sorteio_realizado') === '1') {
            alert('✅ Sorteio realizado!\n📤 Mensagens enviadas!');
            window.history.replaceState({}, '', window.location.pathname);
        }
    </script>
</body>

</html>