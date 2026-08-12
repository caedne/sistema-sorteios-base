<div class="game-footer">
    <div class="stat-line">
        <span>VENDIDOS: <?php echo $vendas; ?>/<?php echo $total_numeros; ?></span>
        <span><?php echo ($total_numeros > 0) ? round(($vendas/$total_numeros)*100) : 0; ?>%</span>
    </div>
    <div class="bar-bg">
        <div class="bar-fill" style="width: <?php echo ($total_numeros > 0) ? ($vendas/$total_numeros)*100 : 0; ?>%;"></div>
    </div>
</div>