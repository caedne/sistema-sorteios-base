<?php
// LÓGICA INTELIGENTE: Descobre o nome do arquivo atual
$paginaAtual = basename($_SERVER['PHP_SELF']);
?>

<nav class="sidebar">
    <<div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 10px 0;">
            <div style="color: #94a3b8; font-size: 10px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase;">
                SISTEMA DE SORTEIOS
            </div>
            <div style="color: #ffffff; font-size: 18px; font-weight: 900; letter-spacing: 1px; text-transform: uppercase; text-shadow: 0px 2px 4px rgba(0,0,0,0.5);">
                MERCADO SILVEIRA
            </div>
        </div>
    
    <div class="nav-group">
        
        <div style="padding: 10px 15px 5px; font-size: 10px; color: #64748b; font-weight: bold; letter-spacing: 1px;">PRINCIPAL</div>

        <a href="../sistema_sorteios/index.php" class="nav-item <?php echo ($paginaAtual == 'index.php' || $paginaAtual == 'painel.php') ? 'active' : ''; ?>">
            <span>🏠</span> <span>INÍCIO</span>
        </a>
        
        <a href="../sistema_sorteios/jogos_ativos.php" class="nav-item <?php echo ($paginaAtual == 'jogos_ativos.php' || $paginaAtual == 'jogosativosmanual.php') ? 'active' : ''; ?>">
            <span>🎮</span> <span>JOGOS ATIVOS</span>
        </a>
        
        <a href="../sistema_sorteios/selecionar_jogo.php" class="nav-item <?php echo (strpos($paginaAtual, 'selecionar') !== false) ? 'active' : ''; ?>">
            <span>🎯</span> <span>CRIAR/EDITAR</span>
        </a>

        <div style="padding: 15px 15px 5px; font-size: 10px; color: #64748b; font-weight: bold; letter-spacing: 1px; border-top: 1px solid rgba(255,255,255,0.05); margin-top: 5px;">FINANCEIRO</div>

        <a href="../sistema_sorteios/agenda.php" class="nav-item <?php echo ($paginaAtual == 'agenda.php') ? 'active' : ''; ?>">
            <span>📒</span> <span>AGENDA CLIENTES</span>
        </a>

        <a href="../sistema_sorteios/carteiras.php" class="nav-item <?php echo ($paginaAtual == 'carteiras.php') ? 'active' : ''; ?>">
            <span>💼</span> <span>CARTEIRAS</span>
        </a>

        <a href="../sistema_sorteios/credito_mensal.php" class="nav-item <?php echo ($paginaAtual == 'credito_mensal.php') ? 'active' : ''; ?>">
            <span>💳</span> <span>CRÉDITO MENSAL</span>
        </a>

        <a href="../sistema_sorteios/retirada.php" class="nav-item <?php echo ($paginaAtual == 'retirada.php') ? 'active' : ''; ?>">
            <span>📦</span> <span>PRÊMIOS A RETIRAR</span>
        </a>
        <a href="../sistema_sorteios/historico_financeiro.php" class="nav-item <?php echo ($paginaAtual == 'historico_financeiro.php') ? 'active' : ''; ?>">
            <span>📈</span> <span>HISTÓRICO FINANCEIRO</span>
        </a>

        <div style="padding: 15px 15px 5px; font-size: 10px; color: #64748b; font-weight: bold; letter-spacing: 1px; border-top: 1px solid rgba(255,255,255,0.05); margin-top: 5px;">RELATÓRIOS</div>

        <a href="../sistema_sorteios/historico.php" class="nav-item <?php echo ($paginaAtual == 'historico.php') ? 'active' : ''; ?>">
            <span>📊</span> <span>HISTÓRICO</span>
        </a>

    </div>
</nav>