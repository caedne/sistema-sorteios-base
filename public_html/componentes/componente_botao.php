<?php
/**
 * Botões de Ação Administrativa (Com Trava de Segurança)
 */
$idSorteio = $sorteio['id'];
?>

<a href="https://mercadosilveira.dkingsorteios.com.br/aprovar_geral.php?id=<?php echo $idSorteio; ?>" 
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