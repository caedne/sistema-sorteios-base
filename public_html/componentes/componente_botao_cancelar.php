<?php if(isset($sorteio['id'])): ?>
    <form method="POST" style="flex: 1; display: flex;" onsubmit="return confirm('ATENÇÃO: Deseja realmente CANCELAR este sorteio?\n\nIsso vai apagar todas as vendas e remover o jogo da tela.');">
        <input type="hidden" name="acao_cancelar" value="true">
        <input type="hidden" name="id_cancelar" value="<?php echo $sorteio['id']; ?>">
        
        <button type="submit" class="btn-cancelar-unificado" style="width: 100%;">
            CANCELAR
        </button>
    </form>
<?php endif; ?>
