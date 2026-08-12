<?php
/**
 * SUBMENU UNIVERSAL - COMPONENTE (DINÂMICO)
 * Puxa as categorias diretamente do banco de dados do cliente.
 */

// 1. DETECÇÃO INTELIGENTE DA PÁGINA
$paginaAtual = basename($_SERVER['PHP_SELF']);
$parametroUrl = ($paginaAtual == 'historico.php' || $paginaAtual == 'retirada.php') ? 'tab' : 'cat';

// 2. BUSCA AS CATEGORIAS DIRETAMENTE NO BANCO DO CLIENTE
$categoriasDisponiveis = [];
$res_cat = $conn->query("SELECT categoria FROM contador_categorias ORDER BY id ASC");
if ($res_cat && $res_cat->num_rows > 0) {
    while ($row = $res_cat->fetch_assoc()) {
        $categoriasDisponiveis[] = $row['categoria'];
    }
}

// Se por acaso a tabela estiver vazia, define um padrão de segurança
if (empty($categoriasDisponiveis)) {
    $categoriasDisponiveis = ['carnes'];
}

// 3. DETECÇÃO DA CATEGORIA ATIVA
if (!isset($abaAtiva)) {
    if (isset($_GET[$parametroUrl]) && in_array($_GET[$parametroUrl], $categoriasDisponiveis)) {
        $abaAtiva = $_GET[$parametroUrl];
    } else {
        $abaAtiva = $categoriasDisponiveis[0]; // Padrão é a primeira categoria cadastrada
    }
}

// 4. REGRA DE OURO: SÓ EXIBE SE HOUVER MAIS DE 1 CATEGORIA
if (count($categoriasDisponiveis) > 1) {
    ?>

    <link rel="stylesheet" href="../assets/css/submenu.css?v=<?php echo time(); ?>">

    <div class="cabecalho-abas">
        <div class="botoes-categoria">

            <?php foreach ($categoriasDisponiveis as $cat): ?>
                <button class="btn-capsula <?php echo ($abaAtiva == $cat) ? 'ativo' : ''; ?>" data-cat="<?php echo $cat; ?>"
                    onclick="navegarPara('<?php echo $cat; ?>')">
                    <span>⚡</span> <?php echo strtoupper($cat); ?>
                </button>
            <?php endforeach; ?>

        </div>
    </div>

    <script>
        // Passa variáveis do PHP para o JS
        const PARAM_URL_SUBMENU = '<?php echo $parametroUrl; ?>';

        function navegarPara(categoria) {
            // 1. Efeito Visual Imediato
            document.querySelectorAll('.btn-capsula').forEach(btn => btn.classList.remove('ativo'));
            const btnClicado = document.querySelector(`.btn-capsula[data-cat="${categoria}"]`);
            if (btnClicado) btnClicado.classList.add('ativo');

            // 2. Verifica se existe a função AJAX
            if (typeof abrirAba === 'function') {
                abrirAba(categoria);
            } else {
                const url = new URL(window.location.href);
                url.searchParams.set(PARAM_URL_SUBMENU, categoria);

                if (url.searchParams.has('p')) {
                    url.searchParams.set('p', '1');
                }

                window.location.href = url.toString();
            }
        }
    </script>

<?php
} // Fim do if (count > 1) 
?>