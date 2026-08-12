<?php
/**
 * Botões de Ação Administrativa (Com Trava de Segurança)
 */
$idSorteio = $sorteio['id'];
$baseUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
?>

<a href="<?php echo $baseUrl; ?>/aprovar_geral.php?id=<?php echo $idSorteio; ?>" 
   target="_blank" 
   onclick="return confirm('⚠️ ATENÇÃO!\n\nDeseja realmente marcar TODOS os números deste sorteio como PAGOS?');"
   class="btn-acao btn-verde">
    ✅ TUDO PAGO
</a>

<button type="button" 
        onclick="chamarTodos(<?php echo $idSorteio; ?>)" 
        class="btn-acao btn-azul">
    📢 CHAMAR TODOS DO GRUPO!
</button>