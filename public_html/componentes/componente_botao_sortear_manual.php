<?php
// Só mostra botão se TODOS estiverem pagos
if ($sorteio && $total_vendas >= $qtd):
    $totalPagos = 0;
    foreach ($vendas_map as $dados) {
        if ($dados['status'] === 'pago') {
            $totalPagos++;
        }
    }
    
    // Só mostra se TODOS pagos
    if ($totalPagos >= $qtd):
        // Busca todos os números pagos
        $numerosPagos = [];
        foreach ($vendas_map as $num => $dados) {
            if ($dados['status'] === 'pago') {
                $numerosPagos[] = [
                    'numero' => $num,
                    'nome' => $dados['nome'],
                    'tel' => $dados['tel']
                ];
            }
        }
        
        // Codifica em Base64 para evitar problemas com caracteres especiais
        $dadosBase64 = base64_encode(json_encode([
            'sorteioId' => $sorteio['id'],
            'categoria' => $tipo,
            'premios' => $sorteio['premios'],
            'numeros' => $numerosPagos
        ]));
        
        $btnId = 'btn_sortear_' . $sorteio['id'];
?>
    <button 
        type="button" 
        id="<?php echo $btnId; ?>"
        class="btn-acao btn-verde" 
        data-sortear="<?php echo $dadosBase64; ?>"
        style="background: #8b5cf6; box-shadow: 0 4px 0 #7c3aed;"
    >
        🎰 SORTEAR MANUAL
    </button>
    
    <script>
    document.getElementById('<?php echo $btnId; ?>').addEventListener('click', function() {
        const dadosBase64 = this.getAttribute('data-sortear');
        const dados = JSON.parse(atob(dadosBase64));
        
        abrirModalSorteio(
            dados.sorteioId,
            dados.categoria,
            dados.premios,
            dados.numeros
        );
    });
    </script>
<?php 
    endif;
endif; 
?>