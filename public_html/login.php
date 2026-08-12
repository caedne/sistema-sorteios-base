<?php
session_start();
include 'db.php';

// Se já estiver logado (Sessão ou Cookie), pula direto pro painel
if (isset($_SESSION['admin_logado']) && $_SESSION['admin_logado'] === true) {
    header("Location: index.php"); // Mudou aqui
    exit;
}
if (!isset($_SESSION['admin_logado']) && isset($_COOKIE['dking_lembrar']) && $_COOKIE['dking_lembrar'] === 'sim') {
    $_SESSION['admin_logado'] = true;
    header("Location: index.php"); // Mudou aqui
    exit;
}

// Cria a tabela de usuários se não existir
$conn->query("CREATE TABLE IF NOT EXISTS admin_usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(100) UNIQUE,
    senha VARCHAR(100)
)");

// Popula os usuários padrões se a tabela estiver vazia
$check = $conn->query("SELECT id FROM admin_usuarios");
if ($check->num_rows == 0) {
$conn->query("INSERT INTO admin_usuarios (login, senha) VALUES ('admin@dking', 'admin123')");
$conn->query("INSERT INTO admin_usuarios (login, senha) VALUES ('sistema@sistema', 'admin123')");
}

$erro = "";
$sucesso = "";
$exibirMudarSenha = false; // Controle de qual tela mostrar

// Processa a Troca de Senha
if (isset($_POST['acao']) && $_POST['acao'] == 'mudar_senha') {
    $login_mudar = $conn->real_escape_string($_POST['login_mudar']);
    $senha_atual = $conn->real_escape_string($_POST['senha_atual']);
    $nova_senha = $conn->real_escape_string($_POST['nova_senha']);
    $confirmar_senha = $conn->real_escape_string($_POST['confirmar_senha']);

    if ($nova_senha !== $confirmar_senha) {
        $erro = "As novas senhas não coincidem!";
        $exibirMudarSenha = true; // Mantém na tela de mudar senha para ele tentar de novo
    } else {
        $busca = $conn->query("SELECT * FROM admin_usuarios WHERE login = '$login_mudar' AND senha = '$senha_atual'");
        if ($busca->num_rows > 0) {
            $conn->query("UPDATE admin_usuarios SET senha = '$nova_senha' WHERE login = '$login_mudar'");
            $sucesso = "Senha alterada com sucesso! Faça o login com sua nova senha.";
        } else {
            $erro = "Erro: Usuário ou Senha atual incorretos!";
            $exibirMudarSenha = true;
        }
    }
}

// Processa o Login e cria o Cookie
if (isset($_POST['acao']) && $_POST['acao'] == 'login') {
    $login = $conn->real_escape_string($_POST['login']);
    $senha = $conn->real_escape_string($_POST['senha']);

    $busca = $conn->query("SELECT * FROM admin_usuarios WHERE login = '$login' AND senha = '$senha'");
    if ($busca->num_rows > 0) {
        $usuario = $busca->fetch_assoc();
        $_SESSION['admin_logado'] = true;
        $_SESSION['admin_usuario'] = $usuario['login'];

        setcookie("dking_lembrar", "sim", time() + (86400 * 30), "/");

        header("Location: index.php"); // Mudou aqui
        exit;
    } else {
        $erro = "Usuário ou senha incorretos!";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Acesso Restrito -
        <?php echo htmlspecialchars($cliente['nome_fantasia']); ?>
    </title>
    <style>
        body {
            background-color: #0f172a;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            font-family: Arial, sans-serif;
        }

        .login-box {
            background: #1e293b;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            text-align: center;
            width: 100%;
            max-width: 350px;
            position: relative;
            overflow: hidden;
            margin: 20px;
        }

        .login-box h2 {
            color: #f8fafc;
            margin-bottom: 5px;
            font-weight: 900;
        }

        .login-box h2 span {
            color: #22c55e;
        }

        .input-campo {
            width: 90%;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #334155;
            background: #0f172a;
            color: white;
            font-size: 15px;
            margin-bottom: 15px;
            outline: none;
            text-align: center;
        }

        .input-campo:focus {
            border-color: #22c55e;
        }

        .btn-entrar {
            background: #22c55e;
            color: white;
            border: none;
            padding: 15px;
            width: 100%;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.2s;
            margin-top: 10px;
        }

        .btn-entrar:hover {
            background: #16a34a;
        }

        .btn-secundario {
            background: transparent;
            color: #94a3b8;
            border: 1px solid #475569;
            padding: 10px;
            width: 100%;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
            margin-top: 15px;
            transition: 0.2s;
        }

        .btn-secundario:hover {
            background: #334155;
            color: white;
        }

        .alerta-erro {
            color: #ef4444;
            margin-bottom: 15px;
            font-weight: bold;
            font-size: 14px;
        }

        .alerta-sucesso {
            color: #22c55e;
            margin-bottom: 15px;
            font-weight: bold;
            font-size: 14px;
        }

        /* Controle de Exibição Inicial via PHP */
        #formLogin {
            display:
                <?php echo $exibirMudarSenha ? 'none' : 'block'; ?>
            ;
        }

        #formMudarSenha {
            display:
                <?php echo $exibirMudarSenha ? 'block' : 'none'; ?>
            ;
        }
    </style>
</head>

<body>
    <div class="login-box">
        <h2><?php echo htmlspecialchars($cliente['nome_fantasia']); ?></h2>
        <p style="color: #94a3b8; margin-bottom: 25px; font-size: 13px;">Painel Administrativo</p>

        <?php if ($erro): ?>
            <div class="alerta-erro">⚠️ <?php echo $erro; ?></div><?php endif; ?>
        <?php if ($sucesso): ?>
            <div class="alerta-sucesso">✅ <?php echo $sucesso; ?></div><?php endif; ?>

        <form method="POST" id="formLogin">
            <input type="hidden" name="acao" value="login">
            <input type="text" name="login" class="input-campo" placeholder="Usuário" required autofocus>
            <input type="password" name="senha" class="input-campo" placeholder="Senha" required>
            <button type="submit" class="btn-entrar">ENTRAR NO SISTEMA</button>
            <button type="button" class="btn-secundario" onclick="mostrarForm('mudar')">🔒 Deseja mudar sua
                senha?</button>
        </form>

        <form method="POST" id="formMudarSenha">
            <input type="hidden" name="acao" value="mudar_senha">
            <input type="text" name="login_mudar" class="input-campo" placeholder="Usuário" required>
            <input type="password" name="senha_atual" class="input-campo" placeholder="Senha Atual" required>
            <input type="password" name="nova_senha" class="input-campo" placeholder="Nova Senha" required>
            <input type="password" name="confirmar_senha" class="input-campo" placeholder="Repita a Nova Senha"
                required>
            <button type="submit" class="btn-entrar" style="background:#f59e0b;">SALVAR NOVA SENHA</button>
            <button type="button" class="btn-secundario" onclick="mostrarForm('login')">⬅️ Voltar para o Login</button>
        </form>
    </div>

    <script>
        function mostrarForm(qual) {
            var formMudar = document.getElementById("formMudarSenha");
            var formLogin = document.getElementById("formLogin");

            // Limpa os alertas ao trocar de tela para não confundir o usuário
            var alertas = document.querySelectorAll('.alerta-erro, .alerta-sucesso');
            alertas.forEach(a => a.style.display = 'none');

            if (qual === 'mudar') {
                formMudar.style.display = "block";
                formLogin.style.display = "none";
            } else {
                formMudar.style.display = "none";
                formLogin.style.display = "block";
            }
        }
    </script>
</body>

</html>