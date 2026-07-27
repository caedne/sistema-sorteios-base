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
$id = intval($_GET['id']);
$carteira = $conn->query("SELECT * FROM carteiras WHERE id = $id")->fetch_assoc();
$transacoes = $conn->query("SELECT * FROM transacoes_carteira WHERE telefone = '{$carteira['telefone']}' ORDER BY data_transacao DESC LIMIT 20");
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Editar | <?= $carteira['nome_cliente'] ?></title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <style>
        .container {
            padding: 30px;
            max-width: 1200px;
            margin: 0 auto
        }

        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px
        }

        .card {
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .1)
        }

        .saldo-display {
            font-size: 48px;
            font-weight: 900;
            color: #22c55e;
            text-align: center;
            margin: 20px 0
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            font-weight: 900;
            cursor: pointer;
            margin-right: 10px
        }

        .btn-adicionar {
            background: #22c55e;
            color: #fff
        }

        .btn-bloquear {
            background: #ef4444;
            color: #fff
        }

        .btn-voltar {
            background: #64748b;
            color: #fff
        }

        .tabela-extrato {
            width: 100%;
            border-collapse: collapse
        }

        .tabela-extrato th {
            background: #1e293b;
            color: #facc15;
            padding: 12px;
            text-align: left
        }

        .tabela-extrato td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0
        }

        .tipo-recarga {
            color: #22c55e;
            font-weight: 700
        }

        .tipo-compra {
            color: #ef4444;
            font-weight: 700
        }

        .form-grupo {
            margin-bottom: 20px
        }

        .form-grupo label {
            display: block;
            font-weight: 700;
            margin-bottom: 8px
        }

        .form-grupo input {
            width: 100%;
            padding: 12px;
            border: 2px solid #cbd5e1;
            border-radius: 8px
        }
    </style>
</head>

<body>
    <div class="layout-sistema">
        <aside class="sidebar"><?php include '../componentes/sidebar.php'; ?></aside>
        <main class="conteudo-principal">
            <div class="container">
                <div class="header">
                    <h1>✏️ <?= htmlspecialchars($carteira['nome_cliente']) ?></h1><button class="btn btn-voltar"
                        onclick="history.back()">← Voltar</button>
                </div>
                <div class="card">
                    <h2>💰 Saldo Atual</h2>
                    <div class="saldo-display">R$ <?= number_format($carteira['saldo'], 2, ',', '.') ?></div>
                    <p style="text-align:center;color:#64748b">Tel: <?= $carteira['telefone'] ?> | Status:
                        <strong><?= strtoupper($carteira['status']) ?></strong></p>
                    <div style="text-align:center;margin-top:30px">
                        <button class="btn btn-adicionar"
                            onclick="document.getElementById('modalSaldo').style.display='flex'">💵 Adicionar
                            Saldo</button>
                        <?php if ($carteira['status'] === 'ativo'): ?><button class="btn btn-bloquear"
                                onclick="if(confirm('Bloquear?'))fetch('api_carteira.php?acao=bloquear',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'id=<?= $id ?>'}).then(r=>r.json()).then(d=>d.success&&location.reload())">🔒
                                Bloquear</button>
                        <?php else: ?><button class="btn btn-adicionar"
                                onclick="fetch('api_carteira.php?acao=desbloquear',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'id=<?= $id ?>'}).then(()=>location.reload())">🔓
                                Desbloquear</button><?php endif; ?>
                    </div>
                </div>
                <div class="card">
                    <h2>📊 Extrato</h2>
                    <?php if ($transacoes && $transacoes->num_rows > 0): ?>
                        <table class="tabela-extrato">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Tipo</th>
                                    <th>Valor</th>
                                    <th>Saldo Ant.</th>
                                    <th>Saldo Novo</th>
                                    <th>Descrição</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($t = $transacoes->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= date('d/m H:i', strtotime($t['data_transacao'])) ?></td>
                                        <td class="<?= strpos($t['tipo'], 'recarga') !== false ? 'tipo-recarga' : 'tipo-compra' ?>">
                                            <?= strtoupper(str_replace('_', ' ', $t['tipo'])) ?></td>
                                        <td>R$ <?= number_format($t['valor'], 2, ',', '.') ?></td>
                                        <td>R$ <?= number_format($t['saldo_anterior'], 2, ',', '.') ?></td>
                                        <td>R$ <?= number_format($t['saldo_novo'], 2, ',', '.') ?></td>
                                        <td><?= htmlspecialchars($t['descricao'] ?? '-') ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table><?php else: ?>
                        <p style="text-align:center;padding:40px;color:#94a3b8">Sem transações</p><?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    <div id="modalSaldo"
        style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.8);z-index:9999;align-items:center;justify-content:center">
        <div style="background:#fff;border-radius:20px;width:90%;max-width:500px;padding:30px">
            <h2>💵 Adicionar Saldo</h2>
            <form
                onsubmit="event.preventDefault();const f=new FormData(event.target);f.append('id',<?= $id ?>);fetch('api_carteira.php?acao=adicionar_saldo',{method:'POST',body:f}).then(r=>r.json()).then(d=>{if(d.success){alert('✅ OK');location.reload()}else alert('❌'+d.error)})">
                <div class="form-grupo"><label>Valor (R$)</label><input type="number" name="valor" step="0.01" required>
                </div>
                <div class="form-grupo"><label>Descrição</label><input type="text" name="descricao"
                        value="Recarga manual"></div>
                <button type="submit" class="btn btn-adicionar" style="width:100%">💾 Salvar</button>
                <button type="button" class="btn btn-voltar" style="width:100%;margin-top:10px"
                    onclick="this.closest('div').parentElement.style.display='none'">Cancelar</button>
            </form>
        </div>
    </div>
</body>

</html>