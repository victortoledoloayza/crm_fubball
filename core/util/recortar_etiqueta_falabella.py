#!/usr/bin/env python3
"""
core/util/recortar_etiqueta_falabella.py

Recorta la etiqueta PDF de Falabella al rectángulo fijo donde vive el
contenido real (header, dirección, QR, barcode, items), descartando el
espacio en blanco/gris que Falabella deja alrededor en la página A4.

Coordenadas medidas empíricamente rasterizando 3 etiquetas reales de
pedidos distintos (ver sesión de investigación) — en los 3 casos el
contenido arrancó exactamente en la esquina superior izquierda de la
página y midió 262.5 x 412.5pt, sin variación de un pixel entre pedidos.
Falabella usa una plantilla fija, así que se recorta con coordenadas fijas
en vez de detectar el bounding box por PDF (no hay contenido variable que
detectar). +2pt de margen de seguridad en el borde derecho/inferior para
no cortar el trazo del borde.

Uso: python3 recortar_etiqueta_falabella.py <ruta_pdf>
Recorta el PDF en el sitio (in-place). Exit codes:
  0 = recortado OK
  2 = tamaño de página no coincide con lo esperado (A4) — se dejó el PDF
      intacto, probablemente Falabella cambió su plantilla y estas
      coordenadas fijas ya no aplican; hay que re-medir.
  1 = cualquier otro error (PDF corrupto, etc.) — se dejó el PDF intacto.
"""
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), 'vendor'))

import pypdf  # noqa: E402

CONTENIDO_ANCHO_PT = 262.5
CONTENIDO_ALTO_PT = 412.5
PADDING_PT = 2.0

PAGINA_ANCHO_ESPERADO_PT = 595.92
PAGINA_ALTO_ESPERADO_PT = 841.92
TOLERANCIA_PT = 5.0


def main() -> int:
    if len(sys.argv) != 2:
        print('uso: recortar_etiqueta_falabella.py <ruta_pdf>', file=sys.stderr)
        return 1

    ruta = sys.argv[1]

    try:
        lector = pypdf.PdfReader(ruta)
        pagina = lector.pages[0]
        ancho = float(pagina.mediabox.width)
        alto = float(pagina.mediabox.height)

        if (abs(ancho - PAGINA_ANCHO_ESPERADO_PT) > TOLERANCIA_PT
                or abs(alto - PAGINA_ALTO_ESPERADO_PT) > TOLERANCIA_PT):
            print(
                f'tamaño de página inesperado ({ancho:.1f}x{alto:.1f}pt, '
                f'se esperaba ~{PAGINA_ANCHO_ESPERADO_PT}x{PAGINA_ALTO_ESPERADO_PT}pt) '
                '— se deja el PDF sin recortar.',
                file=sys.stderr,
            )
            return 2

        x0 = 0.0
        x1 = CONTENIDO_ANCHO_PT + PADDING_PT
        y1 = alto
        y0 = alto - (CONTENIDO_ALTO_PT + PADDING_PT)

        pagina.mediabox.lower_left = (x0, y0)
        pagina.mediabox.upper_right = (x1, y1)
        pagina.cropbox.lower_left = (x0, y0)
        pagina.cropbox.upper_right = (x1, y1)

        escritor = pypdf.PdfWriter()
        escritor.add_page(pagina)

        ruta_tmp = ruta + '.recortando'
        with open(ruta_tmp, 'wb') as f:
            escritor.write(f)
        os.replace(ruta_tmp, ruta)

        return 0
    except Exception as e:  # noqa: BLE001 — cualquier fallo debe dejar el PDF original intacto
        print(f'error al recortar: {e}', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
