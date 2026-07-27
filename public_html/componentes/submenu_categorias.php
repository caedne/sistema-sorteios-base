<?php
/**
 * SUBMENU UNIVERSAL - COMPONENTE
 * Lógica unificada para Selecionar Jogo, Jogos Ativos e Histórico.
 */

// 1. DETECÇÃO INTELIGENTE DA PÁGINA
$paginaAtual = basename($_SERVER['PHP_SELF']);

// Define qual parâmetro a URL usa (tab para histórico/retirada, cat para os outros)
$parametroUrl = ($paginaAtual == 'historico.php' || $paginaAtual == 'retirada.php') ? 'tab' : 'cat';

// 2. DETECÇÃO DA CATEGORIA ATIVA
if (!isset($abaAtiva)) {
    if (isset($_GET[$parametroUrl])) {
        $abaAtiva = $_GET[$parametroUrl];
    } else {
        $abaAtiva = 'carnes'; // Padrão se não tiver nada
    }
}

// CORREÇÃO AQUI: Força PLURAL (testes) em vez de singular
if ($abaAtiva == 'teste') $abaAtiva = 'testes'; 

// Validação atualizada para aceitar 'testes'
if (!in_array($abaAtiva, ['carnes', 'bebidas', 'testes'])) {
    $abaAtiva = 'carnes';
}
?>

<link rel="stylesheet" href="../assets/css/submenu.css?v=<?php echo time(); ?>">

<div class="cabecalho-abas">
    <div class="botoes-categoria">
        
        <button class="btn-capsula <?php echo ($abaAtiva == 'carnes') ? 'ativo' : ''; ?>" 
                data-cat="carnes"
                onclick="navegarPara('carnes')">
            <span>🍖</span> CARNES
        </button>
        
        <button class="btn-capsula <?php echo ($abaAtiva == 'bebidas') ? 'ativo' : ''; ?>" 
                data-cat="bebidas"
                onclick="navegarPara('bebidas')">
            <span>🍻</span> BEBIDAS
        </button>
        
        <button class="btn-capsula <?php echo ($abaAtiva == 'testes') ? 'ativo' : ''; ?>" 
                data-cat="testes"
                onclick="navegarPara('testes')">
            <span>🚀</span> TESTES
        </button>

    </div>
</div>

<script>
// Passa variáveis do PHP para o JS
const PARAM_URL_SUBMENU = '<?php echo $parametroUrl; ?>';

function navegarPara(categoria) {
    // 1. Efeito Visual Imediato (Troca a classe .ativo)
    document.querySelectorAll('.btn-capsula').forEach(btn => btn.classList.remove('ativo'));
    const btnClicado = document.querySelector(`.btn-capsula[data-cat="${categoria}"]`);
    if(btnClicado) btnClicado.classList.add('ativo');

    // 2. Verifica se existe a função AJAX (exclusiva da página Selecionar Jogo)
    if (typeof abrirAba === 'function') {
        // Modo Rápido (Sem recarregar) - Vai enviar 'testes' agora
        abrirAba(categoria); 
    } else {
        // Modo Padrão (Recarrega a página - Jogos Ativos/Histórico)
        const url = new URL(window.location.href);
        url.searchParams.set(PARAM_URL_SUBMENU, categoria);
        
        // Se tiver paginação, volta para a página 1
        if (url.searchParams.has('p')) {
            url.searchParams.set('p', '1');
        }
        
        window.location.href = url.toString();
    }
}
</script>