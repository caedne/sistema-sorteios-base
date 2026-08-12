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

// --- ATUALIZAÇÃO DO BANCO DE DADOS ---
$checkCol = $conn->query("SHOW COLUMNS FROM sorteios LIKE 'ordem_fila'");
if ($checkCol->num_rows == 0) {
    $conn->query("ALTER TABLE sorteios ADD COLUMN ordem_fila INT DEFAULT 0");
}
$checkCol2 = $conn->query("SHOW COLUMNS FROM sorteios LIKE 'imagem2'");
if ($checkCol2->num_rows == 0) {
    $conn->query("ALTER TABLE sorteios ADD COLUMN imagem2 VARCHAR(255) DEFAULT NULL");
}

function uploadArquivo($file)
{
    if (isset($file) && $file['error'] == 0) {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $novoNome = uniqid() . "." . $ext;
        $destino = "assets/uploads/" . $novoNome;
        if (move_uploaded_file($file['tmp_name'], $destino))
            return $novoNome;
    }
    return null;
}

function obterProximoNumeroUnico($conn)
{
    $conn->query("CREATE TABLE IF NOT EXISTS contador_categorias (
        id INT PRIMARY KEY AUTO_INCREMENT, categoria VARCHAR(50) NOT NULL UNIQUE, proximo_numero INT NOT NULL DEFAULT 1
    )");
    $sql = "SELECT proximo_numero FROM contador_categorias WHERE categoria = 'geral'";
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $numero = $row['proximo_numero'];
        $conn->query("UPDATE contador_categorias SET proximo_numero = proximo_numero + 1 WHERE categoria = 'geral'");
    } else {
        $conn->query("INSERT INTO contador_categorias (categoria, proximo_numero) VALUES ('geral', 2)");
        $numero = 1;
    }
    return $numero;
}

// ==========================================
// AJAX: REORDENAR FILA (Arrastar e Soltar)
// ==========================================
if (isset($_POST['acao']) && $_POST['acao'] == 'reordenar') {
    $ids = json_decode($_POST['ids'], true);

    if (is_array($ids) && count($ids) > 0) {
        $idsStr = implode(',', array_map('intval', $ids));
        $resNums = $conn->query("SELECT numero_visual FROM sorteios WHERE id IN ($idsStr) ORDER BY numero_visual ASC");

        $numerosDisponiveis = [];
        while ($row = $resNums->fetch_assoc()) {
            $numerosDisponiveis[] = $row['numero_visual'];
        }

        foreach ($ids as $index => $id) {
            $ordem = $index + 1;
            $id = intval($id);
            $numeroVisualCorreto = $numerosDisponiveis[$index];

            $conn->query("UPDATE sorteios SET ordem_fila = $ordem, numero_visual = $numeroVisualCorreto WHERE id = $id AND status = 'fila'");
        }
    }
    exit;
}

// 1. SALVAR NOVO
if (isset($_POST['acao']) && $_POST['acao'] == 'novo') {
    $titulo_digitado = $conn->real_escape_string($_POST['titulo_parcial']);
    $proximoNumeroVisual = obterProximoNumeroUnico($conn);
    $valor = str_replace(',', '.', $_POST['valor']);
    $qtd_numeros = intval($_POST['qtd_numeros']);

    $imagem = uploadArquivo($_FILES['imagem']);
    $imagem2 = uploadArquivo($_FILES['imagem2']);
    $video = uploadArquivo($_FILES['video']);

    $premios = [];
    if (isset($_POST['premios'])) {
        foreach ($_POST['premios'] as $p) {
            if (trim($p) !== '')
                $premios[] = $conn->real_escape_string($p);
        }
    }
    $premiosString = implode("|||", $premios);

    $sql = "INSERT INTO sorteios (titulo, categoria, valor_numero, qtd_numeros, numero_visual, premios, imagem, imagem2, video, status) 
            VALUES ('$titulo_digitado', 'geral', '$valor', $qtd_numeros, $proximoNumeroVisual, '$premiosString', '$imagem', '$imagem2', '$video', 'inativo')";

    $conn->query($sql);
    header("Location: selecionar_jogo.php");
    exit;
}

// 2. EDITAR
if (isset($_POST['acao']) && $_POST['acao'] == 'editar') {
    $id = intval($_POST['id_editar']);

    $check = $conn->query("SELECT status, imagem, imagem2, video FROM sorteios WHERE id = $id")->fetch_assoc();
    if ($check['status'] == 'ativo') {
        echo "<script>alert('BLOQUEADO: Sorteio ativo não pode ser editado!'); window.location.href='selecionar_jogo.php';</script>";
        exit;
    }

    $titulo = $conn->real_escape_string($_POST['titulo_parcial']);
    $valor = str_replace(',', '.', $_POST['valor']);
    $qtd_numeros = intval($_POST['qtd_numeros']);
    $updateMidia = "";

    // IMAGEM 1
    $novaImg = uploadArquivo($_FILES['imagem']);
    if ($novaImg) {
        if (!empty($check['imagem']) && file_exists("assets/uploads/" . $check['imagem'])) {
            unlink("assets/uploads/" . $check['imagem']);
        }
        $updateMidia .= ", imagem='$novaImg'";
    } elseif (isset($_POST['apagar_imagem']) && $_POST['apagar_imagem'] == '1') {
        if (!empty($check['imagem']) && file_exists("assets/uploads/" . $check['imagem'])) {
            unlink("assets/uploads/" . $check['imagem']);
        }
        $updateMidia .= ", imagem=NULL";
    }

    // IMAGEM 2
    $novaImg2 = uploadArquivo($_FILES['imagem2']);
    if ($novaImg2) {
        if (!empty($check['imagem2']) && file_exists("assets/uploads/" . $check['imagem2'])) {
            unlink("assets/uploads/" . $check['imagem2']);
        }
        $updateMidia .= ", imagem2='$novaImg2'";
    } elseif (isset($_POST['apagar_imagem2']) && $_POST['apagar_imagem2'] == '1') {
        if (!empty($check['imagem2']) && file_exists("assets/uploads/" . $check['imagem2'])) {
            unlink("assets/uploads/" . $check['imagem2']);
        }
        $updateMidia .= ", imagem2=NULL";
    }

    // VÍDEO
    $novoVid = uploadArquivo($_FILES['video']);
    if ($novoVid) {
        if (!empty($check['video']) && file_exists("assets/uploads/" . $check['video'])) {
            unlink("assets/uploads/" . $check['video']);
        }
        $updateMidia .= ", video='$novoVid'";
    } elseif (isset($_POST['apagar_video']) && $_POST['apagar_video'] == '1') {
        if (!empty($check['video']) && file_exists("assets/uploads/" . $check['video'])) {
            unlink("assets/uploads/" . $check['video']);
        }
        $updateMidia .= ", video=NULL";
    }

    $premios = implode("|||", array_filter($_POST['premios']));

    $sql = "UPDATE sorteios SET titulo='$titulo', valor_numero='$valor', qtd_numeros=$qtd_numeros, premios='$premios' $updateMidia WHERE id=$id";
    $conn->query($sql);
    header("Location: selecionar_jogo.php");
    exit;
}

// 3. ATIVAR
if (isset($_POST['acao']) && $_POST['acao'] == 'ativar') {
    $id_molde = intval($_POST['id']);

    $temAtivo = $conn->query("SELECT id FROM sorteios WHERE status = 'ativo'")->num_rows > 0;
    $statusNovo = $temAtivo ? 'fila' : 'ativo';

    $resOrdem = $conn->query("SELECT MAX(ordem_fila) as max_ordem FROM sorteios WHERE status = 'fila'");
    $ordemNova = ($temAtivo) ? (intval($resOrdem->fetch_assoc()['max_ordem']) + 1) : 0;

    $resNum = $conn->query("SELECT MAX(numero_visual) as max_id FROM sorteios");
    $rowNum = $resNum->fetch_assoc();
    $novoNum = ($rowNum['max_id']) ? $rowNum['max_id'] + 1 : 1;

    $sqlClone = "INSERT INTO sorteios (titulo, categoria, valor_numero, qtd_numeros, premios, status, imagem, imagem2, video, numero_visual, data_sorteio, ordem_fila)
        SELECT titulo, 'geral', valor_numero, qtd_numeros, premios, '$statusNovo', imagem, imagem2, video, $novoNum, NULL, $ordemNova
        FROM sorteios WHERE id = $id_molde";

    if ($conn->query($sqlClone)) {
        header("Location: selecionar_jogo.php");
        exit;
    } else {
        die("Erro ao ativar/enfileirar: " . $conn->error);
    }
}

// 4. EXCLUIR
if (isset($_POST['acao']) && $_POST['acao'] == 'excluir') {
    $id = intval($_POST['id']);

    $busca = $conn->query("SELECT status, imagem, imagem2, video FROM sorteios WHERE id = $id");
    $dados = $busca->fetch_assoc();

    if ($dados['status'] == 'ativo') {
        echo "<script>alert('❌ PERIGO: Sorteio ATIVO! Finalize antes de excluir.'); window.location.href='selecionar_jogo.php';</script>";
        exit;
    }

    if (!empty($dados['imagem']) && file_exists("assets/uploads/" . $dados['imagem'])) {
        unlink("assets/uploads/" . $dados['imagem']);
    }
    if (!empty($dados['imagem2']) && file_exists("assets/uploads/" . $dados['imagem2'])) {
        unlink("assets/uploads/" . $dados['imagem2']);
    }
    if (!empty($dados['video']) && file_exists("assets/uploads/" . $dados['video'])) {
        unlink("assets/uploads/" . $dados['video']);
    }

    $conn->query("UPDATE sorteios SET status = 'arquivado', categoria = 'lixeira', imagem = NULL, imagem2 = NULL, video = NULL WHERE id = $id");
    header("Location: selecionar_jogo.php");
    exit;
}

// --- DADOS DA PÁGINA ---
$proximoNumeroGeral = ($conn->query("SELECT proximo_numero FROM contador_categorias WHERE categoria = 'geral'")->fetch_assoc()['proximo_numero'] ?? 1);
$idAtivoGeral = ($conn->query("SELECT id FROM sorteios WHERE status = 'ativo' LIMIT 1")->fetch_assoc()['id'] ?? 0);

$resTitulos = $conn->query("SELECT titulo, COUNT(*) as freq FROM sorteios GROUP BY titulo ORDER BY freq DESC LIMIT 50");
$historicoTitulos = [];
if ($resTitulos) {
    while ($row = $resTitulos->fetch_assoc())
        $historicoTitulos[] = $row['titulo'];
}

$resPremios = $conn->query("SELECT premios FROM sorteios WHERE premios IS NOT NULL AND premios != ''");
$todosPremios = [];
if ($resPremios) {
    while ($row = $resPremios->fetch_assoc()) {
        $listaPremios = explode('|||', $row['premios']);
        foreach ($listaPremios as $p) {
            $pt = trim($p);
            if ($pt !== '')
                $todosPremios[] = $pt;
        }
    }
}
$freqPremios = array_count_values($todosPremios);
arsort($freqPremios);
$historicoPremios = array_slice(array_keys($freqPremios), 0, 50);
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Criar / Editar Sorteios | D'King Sorteios</title>
    <link rel="stylesheet" href="assets/css/global.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/selecao.css?v=<?php echo time(); ?>">
    <style>
        .btn-fila {
            background-color: #f59e0b !important;
            color: white !important;
            cursor: grab;
            border: 2px dashed #b45309;
        }

        .btn-fila:active {
            cursor: grabbing;
        }

        .titulo-secao {
            color: #94a3b8;
            margin: 25px 0 15px 0;
            font-size: 1.1em;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding-bottom: 5px;
            border-bottom: 1px solid #334155;
        }

        .card-sorteio.drag-item {
            cursor: pointer;
            transition: transform 0.2s;
        }

        .card-sorteio.drag-item:active {
            transform: scale(1.02);
            z-index: 10;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
        }
    </style>
</head>

<body>
    <?php include 'componentes/sidebar.php'; ?>
    <div class="conteudo-principal">
        <div class="painel-grid">

            <div class="conteudo-aba visivel" style="display: block;">
                <h3 class="titulo-secao">🟢 EXECUÇÃO E FILA DE ESPERA</h3>
                <div class="grid-cards sortable-list" data-cat="geral">
                    <?php
                    $fila = $conn->query("SELECT * FROM sorteios WHERE status IN ('ativo', 'fila') ORDER BY CASE WHEN status='ativo' THEN 1 ELSE 2 END, ordem_fila ASC");
                    while ($row = $fila->fetch_assoc()) {
                        renderizarCard($row, $idAtivoGeral, $proximoNumeroGeral);
                    }
                    if ($fila->num_rows == 0)
                        echo "<p style='color:#64748b;'>Nenhum sorteio rodando ou na fila.</p>";
                    ?>
                </div>

                <h3 class="titulo-secao">📁 MODELOS DISPONÍVEIS</h3>
                <div class="grid-cards">
                    <?php
                    $titulosVistos = [];
                    $modelos = $conn->query("SELECT * FROM sorteios WHERE status = 'inativo' ORDER BY id DESC");
                    while ($row = $modelos->fetch_assoc()) {
                        if (in_array($row['titulo'], $titulosVistos))
                            continue;
                        $titulosVistos[] = $row['titulo'];
                        renderizarCard($row, $idAtivoGeral, $proximoNumeroGeral);
                    }
                    ?>
                </div>
            </div>

            <div class="btn-novo-container" style="margin-top: 30px;">
                <button class="btn-novo" onclick="abrirModalNovo()">+ NOVO MODELO</button>
            </div>
        </div>
    </div>

    <div id="modalNovo" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="fecharModal('modalNovo')">&times;</span>
            <h3>Criar Novo Sorteio</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="acao" value="novo">
                <div class="form-group">
                    <label>IDENTIFICAÇÃO:</label>
                    <div class="input-group-joined">
                        <div id="label_cat_novo" class="addon-prefix">SORTEIO</div>
                        <input type="text" name="titulo_parcial" class="input-middle autocomplete-input"
                            placeholder="Nome do sorteio..." autocomplete="off" required>
                        <div class="addon-suffix" id="badge_id_novo">#--</div>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 15px;">
                    <div class="form-group"><label>VALOR:</label><input type="text" name="valor" id="valor_novo"
                            value="12,00" required onkeyup="calcularTotal('novo')"></div>
                    <div class="form-group"><label>NÚMEROS:</label><input type="number" name="qtd_numeros" id="qtd_novo"
                            value="25" required onkeyup="calcularTotal('novo')" onchange="calcularTotal('novo')"></div>
                    <div class="form-group"><label>PRÊMIOS:</label><input type="number" value="1"
                            onchange="gerarInputsPremios('container-premios-novo', this.value, 'novo')"></div>
                    <div class="form-group"><label>TOTAL (R$):</label><input type="text" id="total_novo" readonly
                            style="background-color: #f1f5f9; color: #16a34a; font-weight: bold; font-style: italic; border: 1px solid #cbd5e1; cursor: not-allowed;">
                    </div>
                </div>
                <div id="container-premios-novo" class="lista-premios-container"></div>
                <div class="area-uploads">
                    <div class="form-group">
                        <label>FOTO 1:</label><input type="file" name="imagem" accept="image/*"
                            class="custom-file-input" onchange="previewUpload(this, 'preview-img-novo')">
                        <div id="preview-img-novo" class="preview-box"></div>
                    </div>
                    <div class="form-group">
                        <label>FOTO 2 (OPCIONAL):</label><input type="file" name="imagem2" accept="image/*"
                            class="custom-file-input" onchange="previewUpload(this, 'preview-img2-novo')">
                        <div id="preview-img2-novo" class="preview-box"></div>
                    </div>
                    <div class="form-group">
                        <label>VÍDEO:</label><input type="file" name="video" accept="video/*" class="custom-file-input"
                            onchange="previewUpload(this, 'preview-vid-novo')">
                        <div id="preview-vid-novo" class="preview-box"></div>
                    </div>
                </div>
                <button type="submit" class="btn-salvar">SALVAR MODELO</button>
            </form>
        </div>
    </div>

    <div id="modalEditar" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="fecharModal('modalEditar')">&times;</span>
            <h3>Editar Sorteio</h3>
            <form method="POST" enctype="multipart/form-data" id="formEditar">
                <input type="hidden" name="acao" value="editar">
                <input type="hidden" name="id_editar" id="edit_id">
                <input type="hidden" name="apagar_imagem" id="apagar_imagem" value="0">
                <input type="hidden" name="apagar_imagem2" id="apagar_imagem2" value="0">
                <input type="hidden" name="apagar_video" id="apagar_video" value="0">

                <div class="form-group">
                    <label>IDENTIFICAÇÃO:</label>
                    <div class="input-group-joined">
                        <div id="badge_categoria_editar" class="addon-prefix">SORTEIO</div>
                        <input type="text" name="titulo_parcial" id="edit_titulo"
                            class="input-middle autocomplete-input" autocomplete="off" required>
                        <div class="addon-suffix" id="badge_id_editar">#--</div>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 15px;">
                    <div class="form-group"><label>VALOR:</label><input type="text" name="valor" id="edit_valor"
                            required onkeyup="calcularTotal('editar')"></div>
                    <div class="form-group"><label>NÚMEROS:</label><input type="number" name="qtd_numeros" id="edit_qtd"
                            required onkeyup="calcularTotal('editar')" onchange="calcularTotal('editar')"></div>
                    <div class="form-group"><label>PRÊMIOS:</label><input type="number" id="edit_qtd_premios"
                            onchange="gerarInputsPremios('edit_premios_container', this.value, 'editar')"></div>
                    <div class="form-group"><label>TOTAL (R$):</label><input type="text" id="total_edit" readonly
                            style="background-color: #f1f5f9; color: #16a34a; font-weight: bold; font-style: italic; border: 1px solid #cbd5e1; cursor: not-allowed;">
                    </div>
                </div>
                <div id="edit_premios_container" class="lista-premios-container"></div>
                <div class="area-uploads">
                    <div class="form-group">
                        <label>FOTO 1:</label><input type="file" name="imagem" id="input_img_edit" accept="image/*"
                            class="custom-file-input" onchange="previewUpload(this, 'preview-img')">
                        <div id="preview-img" class="preview-box"></div>
                    </div>
                    <div class="form-group">
                        <label>FOTO 2 (OPCIONAL):</label><input type="file" name="imagem2" id="input_img2_edit"
                            accept="image/*" class="custom-file-input"
                            onchange="previewUpload(this, 'preview-img2-edit')">
                        <div id="preview-img2-edit" class="preview-box"></div>
                    </div>
                    <div class="form-group">
                        <label>VÍDEO:</label><input type="file" name="video" id="input_vid_edit" accept="video/*"
                            class="custom-file-input" onchange="previewUpload(this, 'preview-vid')">
                        <div id="preview-vid" class="preview-box"></div>
                    </div>
                </div>
                <button type="submit" id="btnSalvarEdit" class="btn-salvar">SALVAR ALTERAÇÕES</button>
            </form>
        </div>
    </div>

    <div id="lightbox" class="lightbox">
        <div class="lightbox-content" onclick="event.stopPropagation()">
            <div class="close-lightbox" onclick="document.getElementById('lightbox').classList.remove('ativo')">&times;
            </div>
            <div id="lightbox-media"></div>
        </div>
        <div style="position:absolute; width:100%; height:100%; z-index:-1;"
            onclick="document.getElementById('lightbox').classList.remove('ativo')"></div>
    </div>

    <form id="formAtivar" method="POST" style="display:none;">
        <input type="hidden" name="acao" value="ativar">
        <input type="hidden" name="id" id="ativar_id">
    </form>
    <form id="formExcluir" method="POST" style="display:none;">
        <input type="hidden" name="acao" value="excluir">
        <input type="hidden" name="id" id="excluir_id">
    </form>

    <?php
    function renderizarCard($row, $idAtivoDaCategoria, $proximoNumero)
    {
        $idReal = $row['id'];
        $status = $row['status'];
        $isAtivo = ($status == 'ativo');
        $isFila = ($status == 'fila');

        $imgJS = !empty($row['imagem']) ? $row['imagem'] : '';
        $img2JS = !empty($row['imagem2']) ? $row['imagem2'] : '';
        $vidJS = !empty($row['video']) ? $row['video'] : '';
        $tituloJS = htmlspecialchars($row['titulo'], ENT_QUOTES);
        $valorJS = number_format($row['valor_numero'], 2, ',', '.');
        $premiosJS = htmlspecialchars(json_encode(explode("|||", $row['premios'])), ENT_QUOTES, 'UTF-8');

        if ($isAtivo) {
            $badgeCard = "#" . str_pad($row['numero_visual'], 2, '0', STR_PAD_LEFT);
            $btnClasse = "btn-iniciar-card";
            $txtBtn = "SORTEIO ATIVO";
            $clickBtn = "event.stopPropagation();";
            $cardClass = "ativo";
            $onclickCard = "";
            $botaoExcluir = "";
        } elseif ($isFila) {
            $badgeCard = "#" . str_pad($row['numero_visual'], 2, '0', STR_PAD_LEFT);
            $btnClasse = "btn-iniciar-card btn-fila";
            $txtBtn = "NA FILA (" . $row['ordem_fila'] . "º) ☰";
            $clickBtn = "event.stopPropagation();";
            $cardClass = "drag-item";
            $onclickCard = "abrirModalEditar($idReal, \"$tituloJS\", \"$valorJS\", {$row['qtd_numeros']}, $premiosJS, \"{$row['numero_visual']}\", \"$imgJS\", \"$vidJS\", \"$status\", \"$img2JS\")";
            $botaoExcluir = "<div class='btn-excluir-card' onclick=\"event.stopPropagation(); excluirSorteio($idReal)\" title='Remover'>&times;</div>";
        } else {
            $badgeCard = "MODELO";
            $btnClasse = "btn-iniciar-card";
            $txtBtn = ($idAtivoDaCategoria > 0) ? "COLOCAR NA FILA" : "ATIVAR AGORA";
            $clickBtn = "event.stopPropagation(); ativarSorteio($idReal)";
            $cardClass = "";
            $onclickCard = "abrirModalEditar($idReal, \"$tituloJS\", \"$valorJS\", {$row['qtd_numeros']}, $premiosJS, \"{$row['numero_visual']}\", \"$imgJS\", \"$vidJS\", \"$status\", \"$img2JS\")";
            $botaoExcluir = "<div class='btn-excluir-card' onclick=\"event.stopPropagation(); excluirSorteio($idReal)\">&times;</div>";
        }

        echo "<div class='card-sorteio $cardClass' data-id='$idReal' onclick='$onclickCard' style='position: relative;'>
        <div class='card-header'>
            <span style='flex: 1; padding-right: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;'>" . htmlspecialchars($row['titulo']) . "</span>
            <div style='display: flex; align-items: center; gap: 6px;'>
                <span class='badge-id'>$badgeCard</span> $botaoExcluir
            </div>
        </div>
        <div class='card-body'><ul>";
        $premios = explode("|||", $row['premios']);
        foreach ($premios as $i => $p) {
            echo "<li><b>" . ($i + 1) . "º</b> " . htmlspecialchars($p) . "</li>";
        }
        $valorTotalRifa = $row['valor_numero'] * $row['qtd_numeros'];
        echo "</ul></div>
        <div class='card-price-tag' style='display:flex; flex-direction:column; gap:4px; padding: 10px;'>
            <span style='font-size: 1.1em;'>R$ " . number_format($row['valor_numero'], 2, ',', '.') . " <small style='font-weight:normal;'>($row[qtd_numeros] nºs)</small></span>
            <span style='font-size: 0.85em; border-top: 1px solid rgba(255,255,255,0.3); padding-top: 5px; color: #22c55e; font-weight: bold; font-style: italic;'>💰 Valor Total: R$ " . number_format($valorTotalRifa, 2, ',', '.') . "</span>
        </div>
        <button class='$btnClasse' onclick=\"$clickBtn\">$txtBtn</button>
    </div>";
    }
    ?>

    <script>
        let cacheNovo = {}; let cacheEdit = {};
        const proximoNumeroGeral = <?php echo $proximoNumeroGeral; ?>;

        function abrirModalNovo() {
            let proximo = proximoNumeroGeral;
            let qtdPadrao = 25; let qtdPremiosPadrao = 5;

            document.getElementById('badge_id_novo').innerText = '#' + String(proximo).padStart(2, '0');
            document.getElementById('qtd_novo').value = qtdPadrao;
            let inputPremiosNovo = document.querySelector('input[onchange="gerarInputsPremios(\'container-premios-novo\', this.value, \'novo\')"]');
            if (inputPremiosNovo) inputPremiosNovo.value = qtdPremiosPadrao;

            document.getElementById('container-premios-novo').innerHTML = '';
            cacheNovo = {};
            document.getElementById('preview-img-novo').innerHTML = ''; document.getElementById('preview-img2-novo').innerHTML = ''; document.getElementById('preview-vid-novo').innerHTML = '';
            document.getElementById('modalNovo').classList.add('visivel');
            gerarInputsPremios('container-premios-novo', qtdPremiosPadrao, 'novo'); calcularTotal('novo');
        }

        function abrirModalEditar(id, titulo, valor, qtd, premios, num, img, vid, status, img2) {
            if (status === 'ativo') return;
            document.getElementById('edit_id').value = id;
            document.getElementById('apagar_imagem').value = "0"; document.getElementById('apagar_imagem2').value = "0"; document.getElementById('apagar_video').value = "0";

            document.getElementById('badge_id_editar').innerText = 'Próximo: #' + String(proximoNumeroGeral).padStart(2, '0');
            document.getElementById('edit_titulo').value = titulo; document.getElementById('edit_valor').value = valor; document.getElementById('edit_qtd').value = qtd;

            const pImg = document.getElementById('preview-img'); const pImg2 = document.getElementById('preview-img2-edit'); const pVid = document.getElementById('preview-vid');
            pImg.innerHTML = img ? `<div id="box-img-atual" style="text-align:center;"><img src="assets/uploads/${img}" class="thumb" onclick="verMidia('assets/uploads/${img}', 'img')"><br><span onclick="removerMidia('imagem')" class="btn-remove-media">🗑️ APAGAR FOTO 1</span></div>` : '';
            pImg2.innerHTML = img2 ? `<div id="box-img2-atual" style="text-align:center;"><img src="assets/uploads/${img2}" class="thumb" onclick="verMidia('assets/uploads/${img2}', 'img')"><br><span onclick="removerMidia('imagem2')" class="btn-remove-media">🗑️ APAGAR FOTO 2</span></div>` : '';
            pVid.innerHTML = vid ? `<div id="box-vid-atual" style="text-align:center;"><div class="thumb vid-thumb" onclick="verMidia('assets/uploads/${vid}', 'vid')">🎥 VÍDEO ATUAL</div><br><span onclick="removerMidia('video')" class="btn-remove-media">🗑️ APAGAR VÍDEO</span></div>` : '';

            document.getElementById('edit_premios_container').innerHTML = '';
            document.getElementById('input_img_edit').value = '';
            document.getElementById('input_img2_edit').value = '';
            document.getElementById('input_vid_edit').value = '';
            cacheEdit = {}; premios.forEach((v, i) => cacheEdit[i] = v);
            document.getElementById('edit_qtd_premios').value = premios.length;
            gerarInputsPremios('edit_premios_container', premios.length, 'editar');
            document.getElementById('modalEditar').classList.add('visivel'); calcularTotal('editar');
        }

        function removerMidia(tipo) {
            if (confirm('Remover esta mídia?')) {
                if (tipo === 'imagem') { document.getElementById('apagar_imagem').value = "1"; document.getElementById('box-img-atual').style.display = 'none'; }
                if (tipo === 'imagem2') { document.getElementById('apagar_imagem2').value = "1"; document.getElementById('box-img2-atual').style.display = 'none'; }
                if (tipo === 'video') { document.getElementById('apagar_video').value = "1"; document.getElementById('box-vid-atual').style.display = 'none'; }
            }
        }

        function verMidia(src, tipo) { document.getElementById('lightbox-media').innerHTML = (tipo === 'img') ? `<img src="${src}">` : `<video src="${src}" controls autoplay></video>`; document.getElementById('lightbox').classList.add('ativo'); }

        function previewUpload(input, previewId) {
            const box = document.getElementById(previewId);
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => box.innerHTML = input.files[0].type.startsWith('image/') ? `<img src="${e.target.result}" class="thumb">` : `<div class="thumb vid-thumb">🎥 NOVO VÍDEO</div>`;
                reader.readAsDataURL(input.files[0]);
            } else box.innerHTML = '';
        }

        function gerarInputsPremios(contId, qtd, modo) {
            const cont = document.getElementById(contId); let cache = (modo === 'novo' ? cacheNovo : cacheEdit); if (!cache) cache = {};
            if (cont.innerHTML !== '') { cont.querySelectorAll('input').forEach((inp, i) => cache[i] = inp.value); }
            cont.innerHTML = '';
            for (let i = 0; i < parseInt(qtd); i++) {
                cont.innerHTML += `<div class="input-premio-linha" style="position: relative;"><div class="rank-badge">${i + 1}º</div><input type="text" name="premios[]" class="autocomplete-premio" value="${cache[i] || ''}" placeholder="Prêmio" autocomplete="off" required style="flex:1"></div>`;
            }
        }

        function fecharModal(id) { document.getElementById(id).classList.remove('visivel'); }

        function ativarSorteio(id) {
            if (confirm(document.querySelector('.card-sorteio.ativo') ? 'Colocar este sorteio na FILA DE ESPERA?' : 'Deseja iniciar este sorteio AGORA?')) {
                document.getElementById('ativar_id').value = id; document.getElementById('formAtivar').submit();
            }
        }

        function excluirSorteio(id) {
            if (confirm('🗑️ Deseja excluir/tirar da fila este cartão?')) {
                document.getElementById('excluir_id').value = id; document.getElementById('formExcluir').submit();
            }
        }

        function calcularTotal(modo) {
            let vStr = (modo === 'novo' ? document.getElementById('valor_novo') : document.getElementById('edit_valor')).value.replace(/\./g, '').replace(',', '.');
            let total = (parseFloat(vStr) || 0) * (parseInt((modo === 'novo' ? document.getElementById('qtd_novo') : document.getElementById('edit_qtd')).value) || 0);
            (modo === 'novo' ? document.getElementById('total_novo') : document.getElementById('total_edit')).value = 'R$ ' + total.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // --- AUTOCOMPLETE ---
        const historicoTitulos = <?php echo json_encode($historicoTitulos); ?>; const historicoPremios = <?php echo json_encode($historicoPremios); ?>;
        function closeAllLists() { document.querySelectorAll('.autocomplete-items').forEach(el => el.remove()); }
        function showAutocomplete(input, dataArray) {
            closeAllLists(); const val = input.value.toLowerCase();
            let matches = dataArray.filter(t => t.toLowerCase().includes(val));
            matches = (val === '') ? dataArray.slice(0, 5) : matches.slice(0, 5);
            if (matches.length === 0) return;

            const list = document.createElement('div'); list.setAttribute('class', 'autocomplete-items');
            list.style.position = 'absolute'; list.style.top = '100%'; list.style.left = input.classList.contains('autocomplete-input') ? '0' : '33px'; list.style.right = '0';
            list.style.background = 'white'; list.style.border = '2px solid #e2e8f0'; list.style.borderTop = 'none'; list.style.borderRadius = '0 0 8px 8px'; list.style.zIndex = '10000'; list.style.boxShadow = '0 10px 15px rgba(0,0,0,0.1)'; list.style.marginTop = '-2px'; list.style.overflow = 'hidden';

            matches.forEach(match => {
                const item = document.createElement('div'); item.innerHTML = "🏷️ " + match;
                item.style.padding = '10px 15px'; item.style.cursor = 'pointer'; item.style.borderBottom = '1px solid #f1f5f9'; item.style.fontSize = '13px'; item.style.fontWeight = '800'; item.style.color = '#475569';
                item.addEventListener('mouseenter', () => { item.style.background = '#f8fafc'; item.style.color = '#1e293b'; });
                item.addEventListener('mouseleave', () => { item.style.background = 'white'; item.style.color = '#475569'; });
                item.addEventListener('click', function (e) {
                    input.value = match; closeAllLists();
                    if (input.classList.contains('autocomplete-premio')) {
                        const inputs = input.closest('.lista-premios-container').querySelectorAll('input');
                        (input.closest('#modalNovo') ? cacheNovo : cacheEdit)[Array.from(inputs).indexOf(input)] = match;
                    }
                    input.focus();
                });
                list.appendChild(item);
            });
            input.closest(input.classList.contains('autocomplete-input') ? '.form-group' : '.input-premio-linha').appendChild(list);
        }
        document.addEventListener('click', e => { if (!e.target.classList.contains('autocomplete-input') && !e.target.classList.contains('autocomplete-premio')) closeAllLists(); });
        document.addEventListener('input', e => { if (e.target.classList.contains('autocomplete-input')) showAutocomplete(e.target, historicoTitulos); else if (e.target.classList.contains('autocomplete-premio')) showAutocomplete(e.target, historicoPremios); });
        document.addEventListener('focusin', e => { if (e.target.classList.contains('autocomplete-input')) showAutocomplete(e.target, historicoTitulos); else if (e.target.classList.contains('autocomplete-premio')) showAutocomplete(e.target, historicoPremios); });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            document.querySelectorAll('.sortable-list').forEach(function (list) {
                new Sortable(list, {
                    animation: 150, filter: '.ativo', draggable: '.drag-item',
                    onEnd: function (evt) {
                        let order = [];
                        list.querySelectorAll('.card-sorteio.drag-item').forEach(card => order.push(card.getAttribute('data-id')));
                        if (order.length > 0) {
                            let formData = new FormData(); formData.append('acao', 'reordenar'); formData.append('ids', JSON.stringify(order));
                            fetch('selecionar_jogo.php', { method: 'POST', body: formData }).then(() => window.location.reload());
                        }
                    }
                });
            });
        });
    </script>
</body>

</html>