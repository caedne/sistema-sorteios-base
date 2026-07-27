# ═══════════════════════════════════════════════════════════════
# COMPONENTES CORRIGIDOS - PASTA /componentes
# ═══════════════════════════════════════════════════════════════

## 📦 ARQUIVOS NESTA PASTA:

1. ✅ submenu_categorias.php (CORRIGIDO - PRINCIPAL)
2. ✅ sidebar.php (Corrigido encoding)
3. ✅ componentes_status.php (Melhorado - usa $total_numeros)
4. ✅ componente_botao.php (Melhorado - usa $total_numeros)
5. ✅ componente_botao_cancelar.php (Corrigido encoding)

═══════════════════════════════════════════════════════════════

## 🎯 PROBLEMA RESOLVIDO:

### ANTES (BUGADO):
- jogos_ativos.php usava ?cat= e $categoria_url
- historico.php usava ?tab= e $abaAtiva
- retirada.php usava ?tab= e $abaAtiva
- submenu_categorias.php só funcionava com $abaAtiva

**Resultado:** Bug de submenu nas diferentes páginas!

### AGORA (CORRIGIDO):
O submenu detecta AUTOMATICAMENTE qual parâmetro usar:
- Se a página usa ?tab= → usa tab
- Se a página usa ?cat= → usa cat
- Funciona com $abaAtiva, $categoria_url ou ambos

═══════════════════════════════════════════════════════════════

## 🔧 COMO O NOVO SUBMENU FUNCIONA:

```php
// DETECÇÃO AUTOMÁTICA:

1. Verifica se existe $abaAtiva
   ↓
2. Se não, verifica $_GET['tab']
   ↓
3. Se não, verifica $_GET['cat']
   ↓
4. Define automaticamente qual parâmetro URL usar (tab ou cat)
   ↓
5. JavaScript usa o parâmetro correto ao trocar de categoria
```

═══════════════════════════════════════════════════════════════

## 📋 INSTRUÇÕES DE INSTALAÇÃO:

### OPÇÃO 1: Substituir pasta inteira
```bash
1. Faça backup da pasta atual:
   cp -r componentes componentes_BACKUP

2. Substitua a pasta componentes/ pela nova
```

### OPÇÃO 2: Substituir arquivo por arquivo
```bash
Substitua na pasta dking_sorteios/componentes/:
- submenu_categorias.php (CRÍTICO)
- sidebar.php (Recomendado)
- componentes_status.php (Recomendado)
- componente_botao.php (Recomendado)
- componente_botao_cancelar.php (Recomendado)
```

═══════════════════════════════════════════════════════════════

## ✅ O QUE FOI CORRIGIDO:

### 1. submenu_categorias.php (PRINCIPAL):
- ✅ Detecção automática de ?tab= ou ?cat=
- ✅ Compatível com TODAS as páginas
- ✅ JavaScript unificado (sem duplicação)
- ✅ Sticky (fica fixo ao rolar)
- ✅ Transições suaves

### 2. componentes_status.php:
- ✅ Usa $total_numeros ao invés de 25 fixo
- ✅ Funciona com qualquer quantidade de números

### 3. componente_botao.php:
- ✅ Usa $total_numeros ao invés de 25 fixo
- ✅ Cursor "not-allowed" quando bloqueado
- ✅ Opacidade reduzida quando bloqueado

### 4. sidebar.php:
- ✅ Corrigido encoding UTF-8
- ✅ Emojis funcionando
- ✅ Link de configurações corrigido

### 5. componente_botao_cancelar.php:
- ✅ Corrigido encoding UTF-8

═══════════════════════════════════════════════════════════════

## 🧪 COMO TESTAR:

1. Acesse historico.php → Troque carnes/bebidas → Deve funcionar
2. Acesse retirada.php → Troque carnes/bebidas → Deve funcionar
3. Acesse jogos_ativos.php → Troque carnes/bebidas → Deve funcionar
4. Acesse selecionar_jogo.php → Troque carnes/bebidas → Deve funcionar

Se funcionar em TODOS os 4, está 100% corrigido! ✅

═══════════════════════════════════════════════════════════════

## ⚠️ IMPORTANTE:

Após instalar os novos componentes, REMOVA qualquer JavaScript duplicado
nos arquivos principais (historico.php, retirada.php, etc.) que tenha:

```javascript
// REMOVER ESTE CÓDIGO SE EXISTIR:
window.addEventListener('load', () => {
    const cat = '<?php echo $abaAtiva; ?>';
    // ... código de ativação dos botões
});

function abrirAba(cat) {
    // ... código de navegação
}
```

MOTIVO: O novo submenu já tem esse código embutido!

═══════════════════════════════════════════════════════════════
