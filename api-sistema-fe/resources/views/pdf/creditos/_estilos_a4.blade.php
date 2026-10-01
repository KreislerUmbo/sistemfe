{{-- Módulo Créditos (Fase 4b): estilos A4 compartidos, mismo lenguaje visual que recibo_pago_a4. --}}
<style>
    @page { margin: 15mm 12mm 18mm; }
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #111111; margin: 0; }
    table { border-collapse: collapse; }
    .derecha { text-align: right; }
    .centro { text-align: center; }
    .negrita { font-weight: bold; }
    .apagado { color: #666666; }
    .rojo { color: #b02a2a; }
    .cabecera { width: 100%; border-bottom: 1px solid #111111; padding-bottom: 12px; margin-bottom: 14px; }
    .empresa-nombre { font-weight: bold; font-size: 13px; text-transform: uppercase; }
    .empresa-datos { font-size: 11px; line-height: 1.5; }
    .doc-box { width: 100%; border: 1px solid #111111; }
    .doc-box td { text-align: center; padding: 8px; }
    .doc-box .tipo { font-size: 13px; font-weight: bold; text-transform: uppercase; }
    .doc-box .numero { font-size: 13px; font-weight: bold; font-family: 'Courier New', monospace; margin-top: 4px; }
    .info { width: 100%; border: 1px solid #999999; margin-bottom: 12px; }
    .info td { padding: 3px 10px; font-size: 11px; vertical-align: top; }
    .info .titulo { font-weight: bold; padding-top: 8px; }
    .tabla { width: 100%; margin-top: 8px; }
    .tabla th { background: #e6e6e6; border: 1px solid #999999; font-size: 10px; padding: 6px 5px; text-align: left; }
    .tabla td { border: 1px solid #dddddd; font-size: 10px; padding: 5px; }
    .tabla .fila-vencida td { background: #fbeaea; }
    .totales { width: 300px; margin-left: auto; margin-top: 12px; }
    .totales td { padding: 2px 0; font-size: 11px; }
    .totales .final td { font-weight: bold; border-top: 1px solid #111111; padding-top: 5px; }
    .seccion { font-size: 12px; font-weight: bold; margin: 16px 0 4px; }
    .caja-anulado { margin-bottom: 12px; border: 2px solid #b02a2a; border-radius: 8px; padding: 8px 14px; font-weight: bold; text-align: center; color: #b02a2a; }
    .marca-copia { position: fixed; top: 40%; left: 0; right: 0; text-align: center; font-size: 90px; color: #e8e8e8; font-weight: bold; transform: rotate(-25deg); z-index: -1; }
    .firmas { width: 100%; margin-top: 60px; }
    .firmas td { width: 50%; text-align: center; font-size: 11px; padding: 0 20px; }
    .firmas .linea { border-top: 1px solid #111111; padding-top: 4px; }
    .pie { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 9px; color: #777777; text-align: center; }
</style>
