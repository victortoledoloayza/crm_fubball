<?php
/**
 * core/vendor/autoload_pdfparser.php
 *
 * Autoloader manual para smalot/pdfparser (vendored a mano en
 * core/vendor/smalot/pdfparser/ — ver VERSION.txt ahí). No usamos Composer
 * porque no está garantizado ni en Local ni en el hosting compartido de
 * Producción (cPanel, sin SSH garantizado); esta librería es PHP puro (solo
 * necesita las extensiones zlib/iconv/mbstring, ya presentes), así que
 * vendorearla a mano evita esa dependencia por completo — el código ya
 * viaja en el repo, no hace falta "composer install" en ningún lado.
 *
 * Requerir este archivo una sola vez, solo donde se necesite (no en
 * bootstrap.php — no todas las páginas parsean PDFs).
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'Smalot\\PdfParser\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativo = substr($class, strlen($prefix));
    $ruta = __DIR__ . '/smalot/pdfparser/src/Smalot/PdfParser/' . str_replace('\\', '/', $relativo) . '.php';

    if (is_file($ruta)) {
        require $ruta;
    }
});
