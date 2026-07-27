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
// =======================================================

// Lógica de Cancelamento com Estorno Automático e Trava
if (isset($_POST['acao_cancelar']) && isset($_POST['id_cancelar'])) {
    $id = intval($_POST['id_cancelar']);

    $conn->query("UPDATE sorteios SET status = 'cancelado' WHERE id = $id AND status NOT IN ('finalizado', 'finalizando', 'gravar_video', 'video_pronto')");

    // Verifica se o banco ignorou o comando (porque o sorteio já tinha acabado)
    if ($conn->affected_rows === 0) {
        echo "<script>alert('⚠️ AÇÃO BLOQUEADA: Este sorteio já encerrou ou está rolando a roleta! A tela será atualizada com as informações corretas.'); window.location.href = 'index.php';</script>";
        exit;
    }

    header("Location: index.php");
    exit;
}

function getSorteio($conn, $cat)
{
    $res = $conn->query("SELECT * FROM sorteios WHERE categoria = '$cat' AND status IN ('ativo', 'aguardando_manual') ORDER BY id DESC LIMIT 1");
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

$s_carne = getSorteio($conn, 'carnes');
$s_bebida = getSorteio($conn, 'bebidas');

$v_carne = getVendasMap($conn, $s_carne['id'] ?? null);
$v_bebida = getVendasMap($conn, $s_bebida['id'] ?? null);

// CONTAGEM DE PAGOS PARA CADA CATEGORIA
$pagos_carne = 0;
foreach ($v_carne as $v) {
    if ($v['status'] == 'pago')
        $pagos_carne++;
}

$pagos_bebida = 0;
foreach ($v_bebida as $v) {
    if ($v['status'] == 'pago')
        $pagos_bebida++;
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>D'KING | Mercado Silveira</title>
    <link rel="stylesheet" href="../assets/css/global.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../assets/css/sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../assets/css/index.css?v=<?php echo time(); ?>">

    <script>
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
                    if (d.status === 'sucesso' || d.success === true) {
                        alert("✅ Pronto! O robô marcou " + (d.qtd || "todos") + " pessoas no grupo.");
                    } else {
                        alert("❌ Erro: " + (d.erro || d.message || "O robô não respondeu."));
                    }
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
    </script>
</head>

<body>

    <div class="layout-sistema">
        <aside class="sidebar">
            <?php include '../componentes/sidebar.php'; ?>
        </aside>

        <main class="conteudo-principal">

            <div class="welcome-area">
                <h1>Bem-Vindo, Silveira! 👑</h1>
            </div>

            <div class="dashboard-body">
                <?php foreach (['carnes' => $s_carne, 'bebidas' => $s_bebida] as $tipo => $sorteio):
                    $vendas_map = ($tipo == 'carnes') ? $v_carne : $v_bebida;
                    $total_vendidos = count($vendas_map);

                    $classeBtn = ($tipo == 'carnes') ? 'btn-topo-verde' : 'btn-topo-azul';
                    $qtd = $sorteio['qtd_numeros'] ?? 25;
                    $classeGrid = "grid-numeros-dashboard grid-" . $qtd;

                    $numVisual = "00";
                    if ($sorteio) {
                        $rawNum = $sorteio['numero_visual'] ?? $sorteio['id'];
                        $numVisual = str_pad($rawNum, 2, '0', STR_PAD_LEFT);
                    }
                    ?>
                    <div class="card-dashboard">

                        <a href="jogos_ativos.php?cat=<?php echo $tipo; ?>"
                            class="btn-titulo-card <?php echo $classeBtn; ?>">
                            <?php echo ($tipo == 'carnes') ? '🥩 GERIR CARNES' : '🍺 GERIR BEBIDAS'; ?>
                        </a>

                        <?php if ($sorteio): ?>

                            <div class="header-titulo-organizado">
                                <span class="badge-cat badge-<?php echo $tipo; ?>"><?php echo strtoupper($tipo); ?></span>
                                <span class="titulo-nome"><?php echo $sorteio['titulo']; ?></span>
                                <span class="badge-id">#<?php echo $numVisual; ?></span>
                            </div>

                            <div class="<?php echo $classeGrid; ?>">
                                <?php
                                for ($i = 1; $i <= $qtd; $i++):
                                    $d = $vendas_map[$i] ?? null;
                                    $status = $d ? $d['status'] : '';
                                    $classeNum = 'num-livre';

                                    if ($status == 'pago') {
                                        $classeNum = ($tipo == 'carnes') ? 'num-pago-verde' : 'num-pago-azul';
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
                            // 1. Identifica quantos pagos tem na categoria atual do loop
                            $vendas_da_rodada = ($tipo == 'carnes') ? $pagos_carne : $pagos_bebida;

                            // 2. Alimenta as variáveis para a barra de progresso
                            $vendas = $vendas_da_rodada;
                            $total_numeros = $qtd;
                            include '../componentes/componentes_status.php';
                            ?>

                            <div class="area-botoes-acao">
                                <?php
                                // 3. REFORÇO: Forçamos a variável exata que o componente_botao.php espera
                                $vendas = $vendas_da_rodada;

                                include '../componentes/componente_botao.php';
                                include '../componentes/componente_botao_sortear_manual.php';
                                include '../componentes/componente_botao_cancelar.php';
                                ?>
                            </div>

                        <?php else: ?>

                            <div class="card-vazio">
                                <div class="icone-vazio"><?php echo ($tipo == 'carnes') ? '🥩' : '🍺'; ?></div>
                                <div class="texto-vazio">Nenhum sorteio de <?php echo ucfirst($tipo); ?> ativo</div>
                                <a href="selecionar_jogo.php?cat=<?php echo $tipo; ?>"
                                    class="btn-novo-sorteio <?php echo ($tipo == 'carnes') ? 'btn-amarelo' : 'btn-azul'; ?>">
                                    ⚡ INICIAR NOVO SORTEIO
                                </a>
                            </div>

                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
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
        // ----------------------------------

        function abrirModalSorteio(sorteioId, categoria, premios, numeros) {
            const modal = document.getElementById('modalSorteio');
            if (!modal) {
                console.error('Modal não existe!');
                return;
            }

            modal.innerHTML = `
        <div style="background:white; border-radius:20px; width:90%; max-width:600px; max-height:80vh; overflow-y:auto;">
            <div style="background:#1e293b; color:#facc15; padding:20px; border-radius:20px 20px 0 0; display:flex; justify-content:space-between;">
                <h2 style="margin:0;">🎰 SORTEAR MANUALMENTE</h2>
                <button onclick="fecharModal()" style="background:none; border:none; color:white; font-size:24px; cursor:pointer;">✖</button>
            </div>
            <form id="formSorteio" method="POST" action="processar_sorteio_manual.php" style="padding:30px;">
                <input type="hidden" name="sorteio_id" value="${sorteioId}">
                <input type="hidden" name="categoria" value="${categoria}">
                <div id="campos-premios"></div>
                <div style="display:flex; gap:10px; justify-content:flex-end; padding-top:20px; border-top:2px solid #e2e8f0;">
                    <button type="button" onclick="fecharModal()" style="padding:12px 30px; border-radius:8px; font-weight:900; background:white; border:2px solid #ef4444; color:#ef4444; cursor:pointer;">CANCELAR</button>
                    <button type="submit" style="padding:12px 30px; border-radius:8px; font-weight:900; background:#22c55e; color:white; border:none; cursor:pointer;">✅ SALVAR</button>
                </div>
            </form>
        </div>
    `;

            const container = document.getElementById('campos-premios');
            const listaPremios = premios.split('|||');
            const icones = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'];

            listaPremios.forEach((premio, index) => {
                const div = document.createElement('div');
                div.style.cssText = 'margin-bottom:20px; padding:20px; background:#f8fafc; border-radius:10px; border-left:4px solid #22c55e;';
                div.innerHTML = `
            <h4 style="margin:0 0 15px 0;">${icones[index]} ${index + 1}º Lugar - ${premio}</h4>
            <select name="numero_${index}" required onchange="document.getElementById('nome_${index}').value=this.options[this.selectedIndex].getAttribute('data-nome')" style="width:100%; padding:12px; border:2px solid #cbd5e1; border-radius:8px; font-weight:600; margin-bottom:10px;">
                <option value="">Selecione</option>
                ${numeros.map(n => `<option value="${n.numero}" data-nome="${n.nome}">${n.numero} - ${n.nome}</option>`).join('')}
            </select>
            <input type="text" name="nome_${index}" id="nome_${index}" readonly style="width:100%; padding:12px; background:#e2e8f0; border:2px solid #cbd5e1; border-radius:8px;">
            <input type="hidden" name="premio_${index}" value="${premio}">
        `;
                container.appendChild(div);
            });

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
                if (confirm('✅ Confirma?')) this.submit();
            });

            modal.style.display = 'flex';
            modal.style.alignItems = 'center';
            modal.style.justifyContent = 'center';
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
    <script>
        // SISTEMA DE RECARREGAMENTO INTELIGENTE (ANTI-TELA VELHA) - 5 MINUTOS
        let ultimaVezNaTela = Date.now();

        document.addEventListener("visibilitychange", function () {
            if (document.visibilityState === 'visible') {
                let agora = Date.now();
                // Calcula quanto tempo a pessoa ficou fora dessa aba (em milissegundos)
                let tempoFora = agora - ultimaVezNaTela;

                // Se passou mais de 5 minutos (300.000 ms) em outra aba ou fora do PC, recarrega a página sozinho
                if (tempoFora > 300000) {
                    window.location.reload();
                }
            } else {
                // Grava a hora exata que o usuário minimizou o navegador ou mudou de aba
                ultimaVezNaTela = Date.now();
            }
        });
    </script>
</body>

</html>