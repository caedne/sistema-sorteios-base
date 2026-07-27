<div class="game-footer">
    <div class="stat-line">
        <span>VENDIDOS: <?php echo $vendas; ?>/<?php echo $total_numeros; ?></span>
        <span><?php echo round(($vendas/$total_numeros)*100); ?>%</span>
    </div>
    <div class="bar-bg">
        <div class="bar-fill" style="width: <?php echo ($vendas/$total_numeros)*100; ?>%;"></div>
    </div>
</div>
