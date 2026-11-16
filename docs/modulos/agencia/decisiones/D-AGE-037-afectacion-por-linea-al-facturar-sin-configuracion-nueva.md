# D-AGE-037 — Sin configuración nueva: la afectación de cada línea (Gravado / Exonerado – Amazonía / Inafecto) se ve y se corrige al facturar

| | |
|---|---|
| **Fecha** | 2026-11-16 |
| **Estado** | aceptada. Deja en suspenso el punto 1 de D-AGE-035 ("quién opera"): mientras no haya sustento escrito, quien factura decide línea por línea; los puntos 2, 3 y 4 de D-AGE-035 siguen vigentes |
| **Reglas que afecta** | — (el tributario de Agencia todavía no tiene reglas en `reglas.md`) |
| **Decidió** | usuario (con Claude) |

## Contexto
Los contadores consultados no coinciden. Uno respondió "quién opera" (D-AGE-035, punto 1), pero la
norma y los informes de SUNAT apuntan a gravar lo que se ejecuta fuera de la Amazonía cuando la
agencia revende (§2.4b del requerimiento). El 16-nov otra opinión que transmitió el usuario: *"No
me sentiría cómodo afirmando, solo con la información actual, que un paquete ejecutado
íntegramente en Cusco pueda revenderse exonerado únicamente porque la agencia vendedora está en
Tarapoto."* El usuario no puede esperar a que se pongan de acuerdo para facturar.

## Opciones consideradas
1. **Dos valores por defecto en Configuración** (Amazonía / fuera de la zona) + columna al facturar.
   Descartada: suma configuración que ya existe en otra forma, confunde y alarga el camino.
2. **Configuración como está** (un valor por defecto de la agencia y la afectación de cada tarifa de
   proveedor) + columna «Afectación» editable al facturar.
3. Esperar a los contadores.

## Decisión
**Opción 2.**
1. **No se agrega configuración.** Lo que viene de una tarifa de proveedor trae su afectación (un
   proveedor de Cusco se marca Gravado una vez en su tarifa); lo que no tiene tarifa (mayorista,
   pasaje aéreo, manual) toma el valor por defecto de la agencia, como hoy.
2. **Tres afectaciones** en la columna: **Gravado (10)**, **Exonerado – Amazonía (20)** e
   **Inafecto (30)**.
3. **Columna «Afectación» al facturar**: prellenada, editable sin motivo, con la línea cambiada
   marcada. Es lo que reemplaza a la confirmación ítem por ítem (D-AGE-035, punto 3). Va en la misma
   tarea que quita la confirmación y el bloqueo de mezcla (T-091).
4. Exportación sigue bloqueada; exonerado que no sea de la Amazonía (Apéndice II) sigue fuera
   (en Agencia, 20 = Amazonía, D18).

## Consecuencias
- Un paquete de mayorista a Cusco nace con el valor por defecto de la agencia (hoy Exonerado) y
  **quien factura lo cambia a Gravado** en la columna si corresponde. No queda escondido: se ve antes
  de emitir, y el envío a SUNAT es manual.
- Criterio de uso recomendado mientras no haya sustento (no lo impone el sistema): lo que se ejecuta
  fuera de la Amazonía, facturarlo Gravado. Facturar gravado lo que era exonerado cuesta
  aproximadamente el 18 % del **margen** (el IGV del proveedor pasa a crédito fiscal, si factura con
  IGV); facturar exonerado lo que era gravado es IGV omitido, multa e intereses, y quizá la pérdida
  de la exoneración del año (C17).
- Sigue abierta la C17. Caminos que el usuario evalúa fuera del sistema: consulta institucional a
  SUNAT por un gremio (art. 93 del Código Tributario) e intermediación (el proveedor de fuera factura
  al cliente; la agencia, su comisión; C18).
- No se guarda quién cambió una línea (haría falta una migración); se guarda cuándo.
- Pregunta nueva para el contador (C23): qué conceptos van inafectos y si van como línea o como
  reembolso.
