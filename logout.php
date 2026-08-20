<?php
/**
 * logout.php
 *
 * Destruye la sesión activa y regresa al login.
 */

require_once __DIR__ . '/core/bootstrap.php';

Auth::logout();

header('Location: ' . baseUrl('login.php'));
exit;
