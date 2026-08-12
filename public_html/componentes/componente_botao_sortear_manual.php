<?php
// Só mostra botão se TODOS estiverem pagos
$total_vendas = $total_vendas ?? count($vendas_map);
if ($sorteio && $total_vendas >= $qtd):
    $totalPagos = 0;
    foreach ($vendas_map as $dados) {
        if ($dados['status'] === 'pago') {
            $totalPagos++;
        }
    }
    
    if ($totalPagos >= $qtd):
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
        
        $dadosBase64 = base64_encode(json_encode([
            'sorteioId' => $sorteio['id'],
            'categoria' => 'geral',
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