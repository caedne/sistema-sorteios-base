<?php
date_default_timezone_set('America/Sao_Paulo');
include 'db.php';

$sorteio_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($sorteio_id === 0)
    die("Erro: ID não fornecido.");

// 1. Busca dados do sorteio
$sqlSorteio = "SELECT titulo, premios, categoria, numero_visual, id FROM sorteios WHERE id = $sorteio_id";
$resSorteio = $conn->query($sqlSorteio);
$dadosSorteio = $resSorteio->fetch_assoc();
if (!$dadosSorteio)
    die("Erro: Sorteio não encontrado.");

$categoriaNome = strtoupper($dadosSorteio['categoria']);
$numVisual = isset($dadosSorteio['numero_visual']) ? $dadosSorteio['numero_visual'] : $dadosSorteio['id'];
$tituloCompleto = "[" . $categoriaNome . "] " . $dadosSorteio['titulo'] . " #" . str_pad($numVisual, 2, '0', STR_PAD_LEFT);
$listaPremiosBD = explode("|||", $dadosSorteio['premios']);
$listaPremiosInvertida = $listaPremiosBD;

$replay = isset($_GET['replay']) && $_GET['replay'] == '1';

// 2. BUSCA TODAS AS VENDAS (Para encher o globo com todas as bolas)
$sqlVendas = "SELECT numero_escolhido, nome_comprador FROM vendas WHERE sorteio_id = $sorteio_id AND status_venda = 'pago' ORDER BY numero_escolhido ASC";
$resVendas = $conn->query($sqlVendas);

$participantesBD = [];
while ($row = $resVendas->fetch_assoc()) {
    $participantesBD[] = ["id" => $row['numero_escolhido'], "nome" => $row['nome_comprador']];
}

$ganhadoresReplay = [];
if ($replay) {
    $sqlGanhadores = "SELECT numero_sorteado, nome_cliente, premio 
                      FROM ganhadores_premios 
                      WHERE sorteio_id = $sorteio_id 
                      ORDER BY id ASC";
    $resGanhadores = $conn->query($sqlGanhadores);
    while ($rowG = $resGanhadores->fetch_assoc()) {
        $ganhadoresReplay[] = $rowG;
    }
}

if (empty($participantesBD) && !$replay) {
    echo "<h2 style='color:white; text-align:center; margin-top:20%;'>Aguardando participantes pagos...</h2>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Sorteio - Mercado Silveira</title>
    <link rel="stylesheet" href="../assets/css/sorteador.css?v=<?php echo time(); ?>">
    <style>
        /* Trava o tamanho para o gravador HD e centraliza o conteúdo */
        body {
            background: #0f172a;
            overflow: hidden;
            margin: 0;
            padding: 0;
            width: 1280px;
            height: 720px;
            display: flex;
        }

        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>

<body>

    <div class="sidebar">
        <div class="relogio-container"><span id="dataLive"></span> <span id="horaLive"></span></div>
        <div class="logo-container">SISTEMA DE SORTEIOS<span
                class="cliente-nome"><?php echo getenv('NOME_CLIENTE') ? htmlspecialchars(getenv('NOME_CLIENTE')) : ''; ?></span>
        </div>
        <div class="painel-vencedores">
            <h3
                style="text-align:center; color:#f1c40f; border-bottom:1px solid #475569; padding-bottom:8px; margin:0; font-size:14px;">
                GANHADORES</h3>
            <div id="listaGanhadores"></div>
        </div>
    </div>

    <div class="main-content">
        <div id="topoInfo">
            <div class="categoria-tag"><?php echo $categoriaNome; ?></div>
            <h2 id="infoPremio" style="font-size:28px;"><?php echo $tituloCompleto; ?></h2>
            <div class="sub-titulo" id="premioAtual" style="color:#facc15; font-weight:bold;">AGUARDANDO...</div>
        </div>

        <div class="maquina-box">
            <div
                style="position:absolute; width:550px; height:10px; background:linear-gradient(to bottom, #94a3b8, #475569); top:50%; border-radius:5px;">
            </div>
            <canvas id="globoCanvas" width="600" height="600"></canvas>
            <div id="bolinhaSorteada">
                <span id="resNum" style="font-size:110px; font-weight:900; line-height:1;">00</span>
                <span id="resNome"
                    style="font-size:20px; font-weight:bold; padding:0 20px; text-transform:uppercase;">CLIENTE</span>
            </div>
        </div>

        <button class="btn-sortear" id="btnPlay" onclick="rodarSorteio()">▶ INICIAR</button>
    </div>

    <script>
        const replay = <?php echo $replay ? 'true' : 'false'; ?>;

        function atualizarRelogio() {
            const agora = new Date();
            const opcoes = {
                timeZone: 'America/Sao_Paulo'
            };
            document.getElementById('dataLive').innerText = agora.toLocaleDateString('pt-BR', opcoes);
            document.getElementById('horaLive').innerText = agora.toLocaleTimeString('pt-BR', opcoes);
        }
        setInterval(atualizarRelogio, 1000);
        atualizarRelogio();

        const canvas = document.getElementById('globoCanvas');
        const ctx = canvas.getContext('2d');
        const listaPremios = <?php echo json_encode($listaPremiosInvertida); ?>;
        const ganhadoresReplay = <?php echo json_encode($ganhadoresReplay); ?>;
        let bolinhasOriginais = <?php echo json_encode($participantesBD); ?>;
        let bolinhas = JSON.parse(JSON.stringify(bolinhasOriginais));

        if (bolinhas.length === 0 && ganhadoresReplay.length > 0) {
            bolinhas = ganhadoresReplay.map(g => ({
                id: g.numero_sorteado,
                nome: g.nome_cliente
            }));
        }

        bolinhas = bolinhas.map(b => ({
            id: b.id,
            nome: b.nome,
            x: 300 + (Math.random() - 0.5) * 140,
            y: 300 + (Math.random() - 0.5) * 140,
            vx: (Math.random() - 0.5) * 20,
            vy: (Math.random() - 0.5) * 20,
            r: 13
        }));

        let girando = false;
        let angle = 0;

        function desenhar() {
            ctx.clearRect(0, 0, 600, 600);
            const centro = 300;
            const raio = 240;
            if (girando) angle += 0.25;
            ctx.strokeStyle = '#334155';
            ctx.lineWidth = 1;
            for (let i = 0; i < Math.PI; i += 0.35) {
                ctx.beginPath();
                ctx.ellipse(centro, centro, raio, Math.abs(raio * Math.cos(i + angle)), angle, 0, Math.PI * 2);
                ctx.stroke();
            }
            bolinhas.forEach(b => {
                let speed = girando ? 2.8 : 0.3;
                b.x += b.vx * speed;
                b.y += b.vy * speed;
                const dx = b.x - centro,
                    dy = b.y - centro,
                    d = Math.sqrt(dx * dx + dy * dy);
                if (d > raio - b.r) {
                    const theta = Math.atan2(dy, dx);
                    b.x = centro + Math.cos(theta) * (raio - b.r);
                    b.y = centro + Math.sin(theta) * (raio - b.r);
                    const nx = dx / d,
                        ny = dy / d,
                        dot = b.vx * nx + b.vy * ny;
                    b.vx -= 2 * dot * nx;
                    b.vy -= 2 * dot * ny;
                    b.vx *= 0.95;
                    b.vy *= 0.95;
                }
                ctx.beginPath();
                ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2);
                ctx.fillStyle = b.id % 2 === 0 ? '#f1c40f' : '#cbd5e1';
                ctx.fill();
                if (!girando) {
                    ctx.fillStyle = '#000';
                    ctx.font = 'bold 10px Arial';
                    ctx.textAlign = 'center';
                    ctx.fillText(b.id, b.x, b.y + 4);
                }
            });
            ctx.strokeStyle = '#94a3b8';
            ctx.lineWidth = 2;
            for (let i = Math.PI; i < Math.PI * 2; i += 0.35) {
                ctx.beginPath();
                ctx.ellipse(centro, centro, raio, Math.abs(raio * Math.cos(i + angle)), angle, 0, Math.PI * 2);
                ctx.stroke();
            }
            // 33ms de intervalo = 30 FPS (Fluido e economiza CPU)
            setTimeout(() => requestAnimationFrame(desenhar), 33);
        }
        desenhar();

        let sorteioJaIniciou = false;

        async function rodarSorteio() {
            if (sorteioJaIniciou) return;
            sorteioJaIniciou = true;

            document.getElementById('btnPlay').style.display = 'none';
            await new Promise(r => setTimeout(r, 1000));

            const res = document.getElementById('bolinhaSorteada');
            const pAtual = document.getElementById('premioAtual');
            let vencedores = [];
            const totalGiros = replay && ganhadoresReplay.length > 0 ? ganhadoresReplay.length : listaPremios.length;

            for (let i = 0; i < totalGiros; i++) {
                if (bolinhas.length === 0) break;
                let nomePremio = (replay && ganhadoresReplay[i]) ? ganhadoresReplay[i].premio : listaPremios[i];

                pAtual.innerText = `VALENDO: ${i + 1}º LUGAR - ${nomePremio}`;
                res.style.transform = "translate(-50%,-50%) scale(0)";

                girando = true;
                await new Promise(r => setTimeout(r, 2500));
                girando = false;

                let sort;
                if (replay && ganhadoresReplay[i]) {
                    const numVencedor = parseInt(ganhadoresReplay[i].numero_sorteado);
                    const idx = bolinhas.findIndex(b => parseInt(b.id) === numVencedor);
                    sort = (idx !== -1) ? bolinhas.splice(idx, 1)[0] : bolinhas.splice(0, 1)[0];
                } else {
                    const idx = Math.floor(Math.random() * bolinhas.length);
                    sort = bolinhas.splice(idx, 1)[0];
                }

                if (!sort) break;
                vencedores.push({
                    nome: sort.nome,
                    numero: sort.id,
                    premio: nomePremio
                });
                document.getElementById('resNum').innerText = sort.id;
                document.getElementById('resNome').innerText = sort.nome;
                res.style.transform = "translate(-50%,-50%) scale(1)";

                const item = document.createElement('div');
                item.className = 'item-card';
                item.innerHTML = `<b>${i + 1}º Lugar</b><br>${sort.id} - ${sort.nome}`;
                document.getElementById('listaGanhadores').prepend(item);
                await new Promise(r => setTimeout(r, 3000));
            }

            // ESCONDE A ÚLTIMA BOLA ANTES DO PRINT
            res.style.transform = "translate(-50%,-50%) scale(0)";
            await new Promise(r => setTimeout(r, 800)); // Dá 0.8s para ela sumir da tela

            // 1. AVISA O ROBÔ QUE ACABOU (Cria o sinal dinâmico que o robô espera)
            pAtual.textContent = 'SORTEIO FINALIZADO';

            const sinal = document.createElement('div');
            sinal.id = 'sorteioFinalizado';
            sinal.innerText = 'FIM';
            document.body.appendChild(sinal);

            // 2. DEPOIS SALVA NO BANCO
            if (!replay) {
                finalizarSorteioNoBanco(vencedores);
            }

        }
        async function finalizarSorteioNoBanco(dados) {
            const fd = new FormData();
            fd.append('id_sorteio', <?php echo $sorteio_id; ?>);
            fd.append('ganhadores', JSON.stringify(dados));
            try {
                await fetch('finalizar_backend.php', {
                    method: 'POST',
                    body: fd
                });
            } catch (e) {
                console.log("Erro ao salvar:", e);
            }
        }
    </script>
</body>

</html>