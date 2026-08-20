<?php
/**
 * scripts/crear_api_token.php
 *
 * Genera un token de API (criptográficamente aleatorio) para que un
 * sistema externo (webhook de Shopify, extensión de Chrome) pueda
 * autenticarse contra api/webhooks/*.php sin usuario/contraseña. Solo se
 * guarda el hash del token en `api_tokens` — el valor en texto plano se
 * muestra UNA sola vez acá y no se puede recuperar después.
 *
 * Uso:
 *   php scripts/crear_api_token.php --nombre "Shopify Webhook"
 *   php scripts/crear_api_token.php --nombre "Extension Chrome"
 */

if (PHP_SAPI !== 'cli') {
    die("Este script solo puede ejecutarse por línea de comandos.\n");
}

require_once __DIR__ . '/../core/util/env.php';

loadEnv(__DIR__ . '/../.env');

require_once __DIR__ . '/../core/db/Database.php';

$nombre = null;
foreach ($argv as $indice => $valor) {
    if ($valor === '--nombre' && isset($argv[$indice + 1])) {
        $nombre = trim($argv[$indice + 1]);
    }
}

if (!$nombre) {
    die("Uso: php scripts/crear_api_token.php --nombre \"Nombre descriptivo (ej. 'Shopify Webhook')\"\n");
}

$tokenPlano = bin2hex(random_bytes(32));
$hash = hash('sha256', $tokenPlano);

$pdo = Database::getConnection();

$stmt = $pdo->prepare('INSERT INTO api_tokens (nombre, token_hash) VALUES (?, ?)');
$stmt->execute([$nombre, $hash]);

$id = $pdo->lastInsertId();

echo "Token creado para '{$nombre}' (id {$id}).\n\n";
echo "⚠️  GUARDA ESTO AHORA — no se puede recuperar después, solo queda su hash en la BD:\n\n";
echo "  {$tokenPlano}\n\n";
echo "Úsalo en cada request con el header:\n";
echo "  Authorization: Bearer {$tokenPlano}\n";
