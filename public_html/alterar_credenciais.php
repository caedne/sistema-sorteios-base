<?php
session_start();
if (!isset($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

$credentials_file = 'credentials.json';
if (!file_exists($credentials_file)) {
    file_put_contents($credentials_file, json_encode([
        'username' => 'dksilveira',
        'password' => password_hash('silveiradk2026', PASSWORD_DEFAULT)
    ]));
}
$credentials = json_decode(file_get_contents($credentials_file), true);
$sucesso = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (password_verify($_POST['senha_atual'], $credentials['password'])) {
        if ($_POST['nova_senha'] === $_POST['confirmar_senha']) {
            $credentials['username'] = $_POST['novo_usuario'];
            $credentials['password'] = password_hash($_POST['nova_senha'], PASSWORD_DEFAULT);
            file_put_contents($credentials_file, json_encode($credentials));
            $sucesso = 'Credenciais alteradas com sucesso!';
        } else {
            $erro = 'As senhas não coincidem!';
        }
    } else {
        $erro = 'Senha atual incorreta!';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Alterar Credenciais</title>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial; background: #f5f5f5; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: white; padding: 40px; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.1);">
        <h2 style="color: #333; margin-bottom: 30px;">🔐 Alterar Usuário e Senha</h2>
        
        <?php if($sucesso) echo "<p style='color: #28a745; background: #d4edda; padding: 15px; border-radius: 8px; margin-bottom: 20px;'>✅ $sucesso</p>"; ?>
        <?php if($erro) echo "<p style='color: #dc3545; background: #f8d7da; padding: 15px; border-radius: 8px; margin-bottom: 20px;'>❌ $erro</p>"; ?>
        
        <form method="POST">
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; color: #555; font-weight: bold;">Usuário Atual:</label>
                <input type="text" value="<?= $credentials['username'] ?>" disabled style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px; background: #f9f9f9; box-sizing: border-box;">
            </div>
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; color: #555; font-weight: bold;">Novo Usuário:</label>
                <input type="text" name="novo_usuario" value="<?= $credentials['username'] ?>" required style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px; box-sizing: border-box;">
            </div>
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; color: #555; font-weight: bold;">Senha Atual:</label>
                <input type="password" name="senha_atual" required style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px; box-sizing: border-box;">
            </div>
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; color: #555; font-weight: bold;">Nova Senha:</label>
                <input type="password" name="nova_senha" required style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px; box-sizing: border-box;">
            </div>
            
            <div style="margin-bottom: 30px;">
                <label style="display: block; margin-bottom: 8px; color: #555; font-weight: bold;">Confirmar Nova Senha:</label>
                <input type="password" name="confirmar_senha" required style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px; box-sizing: border-box;">
            </div>
            
            <button type="submit" style="width: 100%; padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer;">Salvar Alterações</button>
        </form>
        
        <a href="index.php" style="display: block; text-align: center; margin-top: 20px; color: #667eea; text-decoration: none; font-weight: bold;">← Voltar ao Painel</a>
    </div>
</body>
</html>
