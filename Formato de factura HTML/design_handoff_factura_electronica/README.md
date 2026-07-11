# Handoff: Formato de Factura Electrónica (SUNAT - Perú)

## Overview
Maqueta visual del formato de una **Factura Electrónica** peruana (estilo SUNAT), para imprimir/exportar como comprobante. Incluye cabecera con logo y datos de la empresa, datos del cliente, tabla de ítems, totales, importe en letras, guía de remisión y código QR.

## About the Design Files
Los archivos de este paquete (`Factura Electronica.dc.html` y `Factura Electronica.html`) son **referencias de diseño creadas en HTML** — muestran el look final y la estructura, pero **no son código de producción para copiar tal cual**. La tarea es **recrear este diseño usando Bootstrap** dentro del proyecto/entorno real del usuario (backend que inyectará los datos reales), respetando measurements, tipografía y estructura descritos abajo.

## Fidelity
**Alta fidelidad (hifi)**: la maqueta define el layout final, tipografía, bordes y espaciado tal como debe verse el documento impreso. Los datos (RUC, montos, cliente, logo, QR) son de ejemplo/placeholder — deben ser reemplazados por datos dinámicos desde el backend, pero el layout/estilos deben mantenerse igual.

## Screens / Views
Una sola vista: el documento de la factura, pensado para renderizarse en una hoja ~800px de ancho (proporción A4 aproximada), centrado sobre fondo gris claro.

### Estructura (de arriba hacia abajo)

**1. Contenedor del documento**
- Ancho: 800px (max-width 100%), fondo blanco `#ffffff`, borde `1px solid #999999`, padding 24px.
- Centrado en la página, sobre fondo `oklch(0.94 0.005 250)` (gris muy claro).
- Fuente: Arial/Helvetica, color de texto `#111111`.

**2. Header (cabecera)**
- Flex row, gap 20px, borde inferior `2px solid #111111`, padding-bottom 16px.
- Logo: caja de 170×70px a la izquierda (placeholder "LOGO" — reemplazar por `<img>` real).
- Datos empresa (centro, flex:1, font-size 12px, line-height 1.5):
  - Razón social (bold, 14px, uppercase)
  - Dirección
  - Distrito/Provincia/Departamento
  - Teléfono y Email
- Caja RUC/Tipo de documento (derecha, ancho fijo 220px, borde `1px solid #111111`, texto centrado):
  - "RUC 00000000000" (13px bold)
  - "FACTURA ELECTRÓNICA" (15px bold, uppercase)
  - Serie-Correlativo, ej "F001-00000001" (14px bold, monospace `Courier New`)

**3. Datos del cliente y fechas**
- Flex row, gap 12px.
- Caja izquierda (60%): borde `1px solid #999999`, padding 10px 12px, font-size 12px.
  - Título "DATOS DEL CLIENTE" (bold)
  - Grid 2 columnas (110px / 1fr): RUC, DENOMINACIÓN, DIRECCIÓN
- Caja derecha (40%): mismo borde/padding.
  - Grid 2 columnas (135px / 1fr): FECHA EMISIÓN, FECHA DE VENC., MONEDA

**4. Tabla de ítems**
- Grid columns: `50px 45px 55px 1fr 90px 90px 100px` (Cant. / UM / Cód. / Descripción / V.U / P.U / Importe).
- Header: fondo `#e6e6e6`, borde `1px solid #999999`, bold, font-size 11px, padding 8px 6px.
- Filas de datos: font-size 11px, padding 6px, bordes laterales `1px solid #999999`.
- Columnas numéricas (V.U, P.U, Importe) alineadas a la derecha.
- Filas vacías (relleno visual): altura 22px, borde superior claro `#eeeeee`, para simular el resto de la hoja — en producción se generan dinámicamente según cantidad de ítems.

**5. Totales**
- Alineado a la derecha, caja de 260px.
- Grid 3 columnas (1fr / 30px / 90px): GRAVADA, IGV 18.00%, TOTAL (bold, con borde superior `1px solid #111111`).

**6. Importe en letras**
- Caja centrada, borde `1px solid #999999`, border-radius 14px, padding 10px 16px, font-size 12px.
- Texto: "IMPORTE EN LETRAS: [monto en palabras]".

**7. Guía de remisión**
- Misma caja redondeada, más compacta (padding 8px 16px).

**8. Footer**
- Flex row, gap 16px.
- Izquierda (flex:1): caja con borde `1px solid #999999`, padding 10px 14px, font-size 11px, line-height 1.6.
  - Texto legal: "Representación impresa de la FACTURA ELECTRÓNICA, visita [URL]"
  - Resolución de Intendencia
  - Hash/resumen (monospace)
- Derecha: caja QR de 130×130px (placeholder — reemplazar por imagen QR real generada por el backend).

## Interactions & Behavior
Documento estático, sin interacciones. Pensado para imprimir / exportar a PDF. No tiene estados de hover/click — es un comprobante de solo lectura.

## State Management
No aplica estado de UI. Los únicos "datos dinámicos" son los que vienen del backend:
- Datos de empresa (razón social, dirección, RUC, teléfono, email, logo)
- Serie y correlativo del comprobante
- Datos del cliente (RUC, nombre, dirección)
- Fechas de emisión/vencimiento, moneda
- Lista de ítems (cantidad, unidad de medida, código, descripción, valor unitario, precio unitario, importe)
- Totales (gravada, IGV, total) y monto en letras
- Guía de remisión (si aplica)
- Texto legal/resolución y hash de resumen
- Imagen QR generada por el backend

## Design Tokens
- Colores: `#111111` (texto principal/bordes fuertes), `#999999` (bordes secundarios), `#e6e6e6` (fondo header tabla), `#eeeeee` (líneas divisorias suaves), fondo de página `oklch(0.94 0.005 250)` (gris claro), blanco `#ffffff` (documento).
- Tipografía: Arial/Helvetica (texto general), `Courier New` monospace (números de serie/correlativo y hash).
- Tamaños de fuente: 11px (tabla/footer), 12px (cuerpo general), 13–15px (títulos de cabecera).
- Bordes: `1px solid #999999` (cajas generales), `2px solid #111111` (separador de header), `1px solid #111111` (caja RUC y línea de totales).
- Border-radius: 14px (cajas de "importe en letras" y "guía de remisión"), 0 en el resto.
- Espaciado: padding de documento 24px; gaps de 12–20px entre bloques.

## Assets
- **Logo**: placeholder arrastrable (caja 170×70px) — reemplazar por `<img>` con el logo real de la empresa.
- **QR**: placeholder arrastrable (caja 130×130px) — reemplazar por imagen QR generada por el sistema de facturación.
- No se usan íconos ni imágenes adicionales.

## Files
- `Factura Electronica.dc.html` — versión editable/fuente (Design Component) usada para generar la maqueta.
- `Factura Electronica.html` — versión standalone (autocontenida) para previsualizar el diseño en cualquier navegador sin dependencias.

## Implementación sugerida con Bootstrap
Recrear la estructura con clases de Bootstrap (`container`, `row`/`col-*` o `d-flex`, `table`/`table-bordered`, utilidades de borde y espaciado `border`, `p-*`, `text-end`, etc.), manteniendo los valores exactos de colores, bordes y tamaños de fuente indicados arriba. El template debe recibir los datos reales (empresa, cliente, ítems, totales, QR) desde el backend, reemplazando únicamente el contenido — no la estructura/estilos.
