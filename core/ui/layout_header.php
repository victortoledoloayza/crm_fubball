<?php
/**
 * core/ui/layout_header.php
 *
 * Head + apertura de <body> + sidebar + topbar, compartido por todas las
 * páginas protegidas. Requiere que la página ya haya llamado
 * Auth::requireLogin() (o requireRole()) antes de incluir este archivo, y
 * que defina $tituloPagina y opcionalmente $navActiva (una de las claves
 * de $itemsNav más abajo) antes del include.
 */

require_once __DIR__ . '/../pedidos/PedidoRepository.php';

$usuarioActual = Auth::currentUser();
$tituloPagina  = $tituloPagina ?? 'Fubball KDS';
$navActiva     = $navActiva ?? '';

// Conteo real por estado, para los badges del sidebar (Nuevos / Despacho /
// Facturación). Corre en cada carga de página — no hace falta que sea en
// tiempo real dentro de una misma pantalla, solo que esté correcto al
// navegar.
$conteosPorEstado = PedidoRepository::contarPorEstado();

$itemsNav = [
    'panel'       => ['etiqueta' => 'Panel',               'url' => baseUrl('index.php'),      'icono' => '🏠', 'disponible' => true],
    'tablero'     => ['etiqueta' => 'Tablero KDS',          'url' => baseUrl('pedidos.php'),    'icono' => '🍳', 'disponible' => true, 'badgeEstado' => 'nuevo'],
    'despacho'    => ['etiqueta' => 'Cola de Despacho',     'url' => baseUrl('despacho.php'),   'icono' => '🛵', 'disponible' => true, 'badgeEstado' => 'despacho'],
    'facturacion' => ['etiqueta' => 'Cola de Facturación',  'url' => baseUrl('facturacion.php'), 'icono' => '🧾', 'disponible' => true, 'badgeEstado' => 'facturacion_pendiente'],
    'tsi'         => ['etiqueta' => 'Asistente TSI',        'url' => baseUrl('tsi.php'),          'icono' => '🔗', 'disponible' => true],
    'usuarios'    => ['etiqueta' => 'Usuarios',             'url' => baseUrl('usuarios.php'),   'icono' => '👥', 'disponible' => true, 'rol' => 'admin'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($tituloPagina, ENT_QUOTES, 'UTF-8') ?> — Fubball KDS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-sidebar: #161c2b;
            --bg-app: #f4f5f7;
            --accent: #d6483d;
            --accent-hover: #b83a30;
            --texto-claro: #e8eaf0;
            --texto-tenue: #8b92a6;
            --borde: #e5e7eb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif;
            background: var(--bg-app);
            color: #1f2430;
            padding-left: 240px;
        }
        .app-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 240px;
            background: var(--bg-sidebar);
            color: var(--texto-claro);
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            z-index: 20;
        }
        .app-sidebar__logo {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 22px 20px;
            font-size: 17px;
            font-weight: 700;
            color: #ffffff;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .app-sidebar__logo span.acento { color: var(--accent); }
        .app-sidebar__nav {
            display: flex;
            flex-direction: column;
            padding: 14px 10px;
            gap: 4px;
        }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            color: var(--texto-claro);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        .nav-item:hover { background: rgba(255, 255, 255, 0.08); color: #ffffff; }
        .nav-item--activo { background: var(--accent); color: #ffffff; }
        .nav-item--activo:hover { background: var(--accent-hover); }
        .nav-item--proximamente {
            color: var(--texto-tenue);
            cursor: default;
            pointer-events: none;
        }
        .nav-item__badge {
            margin-left: auto;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 999px;
            padding: 2px 8px;
        }
        .nav-badge {
            margin-left: auto;
            background: var(--accent);
            color: #ffffff;
            font-size: 10px;
            font-weight: 700;
            border-radius: 999px;
            padding: 2px 8px;
            min-width: 16px;
            text-align: center;
        }
        .nav-badge.zero { display: none; }
        .app-topbar {
            position: fixed;
            top: 0;
            left: 240px;
            right: 0;
            height: 60px;
            background: #ffffff;
            border-bottom: 1px solid var(--borde);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 14px;
            padding: 0 24px;
            z-index: 10;
        }
        .topbar-rol {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #4b5563;
            background: #f0f1f3;
            border-radius: 999px;
            padding: 5px 12px;
        }
        .topbar-nombre { font-size: 14px; font-weight: 600; }
        .topbar-cerrar {
            padding: 8px 16px;
            background: var(--accent);
            color: #ffffff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
        }
        .topbar-cerrar:hover { background: var(--accent-hover); }
        main.contenido {
            padding: 96px 32px 32px;
            max-width: 1100px;
        }
    </style>
</head>
<body>
    <aside class="app-sidebar">
        <div class="app-sidebar__logo">
            <span>Fubball <span class="acento">KDS</span></span>
        </div>
        <nav class="app-sidebar__nav">
            <?php foreach ($itemsNav as $clave => $item): ?>
                <?php if (isset($item['rol']) && ($usuarioActual === null || $usuarioActual['rol'] !== $item['rol'])) continue; ?>
                <?php
                $clases = 'nav-item';
                $clases .= $navActiva === $clave ? ' nav-item--activo' : '';
                $clases .= !$item['disponible'] ? ' nav-item--proximamente' : '';
                ?>
                <?php $conteo = isset($item['badgeEstado']) ? ($conteosPorEstado[$item['badgeEstado']] ?? 0) : null; ?>
                <a class="<?= $clases ?>" href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>">
                    <span><?= $item['icono'] ?></span>
                    <span><?= htmlspecialchars($item['etiqueta'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($conteo !== null): ?>
                        <span class="nav-badge<?= $conteo === 0 ? ' zero' : '' ?>"><?= $conteo ?></span>
                    <?php elseif (!$item['disponible']): ?>
                        <span class="nav-item__badge">Próximamente</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>
    <header class="app-topbar">
        <?php if ($usuarioActual !== null): ?>
            <span class="topbar-rol"><?= htmlspecialchars(mb_strtoupper($usuarioActual['rol'], 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="topbar-nombre"><?= htmlspecialchars($usuarioActual['nombre'], ENT_QUOTES, 'UTF-8') ?></span>
            <a class="topbar-cerrar" href="<?= htmlspecialchars(baseUrl('logout.php'), ENT_QUOTES, 'UTF-8') ?>">Cerrar sesión</a>
        <?php endif; ?>
    </header>
    <main class="contenido">
