<?php
/**
 * login.php
 *
 * Formulario de usuario y contraseña. Si ya hay sesión activa, redirige
 * directo a index.php.
 */

require_once __DIR__ . '/core/bootstrap.php';

if (Auth::currentUser() !== null) {
    header('Location: ' . baseUrl('index.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequerir();

    $usuario  = trim($_POST['usuario'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($usuario === '' || $password === '') {
        $error = 'Usuario o contraseña incorrectos.';
    } elseif (Auth::login($usuario, $password)) {
        header('Location: ' . baseUrl('index.php'));
        exit;
    } else {
        // Mensaje genérico a propósito: nunca revela si el usuario existe,
        // está inactivo o bloqueado.
        $error = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión — Fubball KDS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-sidebar: #161c2b;
            --accent: #d6483d;
            --accent-hover: #b83a30;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif;
            background: var(--bg-sidebar);
            padding: 20px;
        }
        .login-card {
            background: #1e2536;
            width: 100%;
            max-width: 380px;
            padding: 40px 32px;
            border-radius: 14px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
        }
        .login-card h1 {
            margin: 0 0 4px;
            font-size: 22px;
            font-weight: 700;
            color: #ffffff;
            text-align: center;
        }
        .login-card h1 span { color: var(--accent); }
        .login-card p.subtitulo {
            margin: 0 0 28px;
            font-size: 13px;
            color: #8b92a6;
            text-align: center;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #cbd0dc;
            margin-bottom: 6px;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 11px 12px;
            margin-bottom: 18px;
            border: 1px solid #323a4e;
            border-radius: 8px;
            background: #161c2b;
            color: #e8eaf0;
            font-size: 14px;
            font-family: inherit;
        }
        input:focus { outline: none; border-color: var(--accent); }
        button {
            width: 100%;
            padding: 12px;
            background: var(--accent);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
        }
        button:hover { background: var(--accent-hover); }
        .mensaje-error {
            background: rgba(214, 72, 61, 0.15);
            border: 1px solid rgba(214, 72, 61, 0.4);
            color: #f4a49e;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 18px;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>Fubball <span>KDS</span></h1>
        <p class="subtitulo">Gestión de pedidos de despacho</p>
        <?php if ($error !== ''): ?>
            <div class="mensaje-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post" action="login.php">
            <?= csrfCampo() ?>
            <label for="usuario">Usuario</label>
            <input type="text" id="usuario" name="usuario" autocomplete="username" required autofocus>
            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <button type="submit">Iniciar sesión</button>
        </form>
    </div>
</body>
</html>
