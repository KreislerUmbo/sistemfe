// Acciones sobre la pantalla nueva de edición (T-110). Se va ajustando paso a paso, junto con edit.vue.
export default {
    esperar: async (p) => { await p.waitForSelector('.grid-venta'); await p.waitForSelector('.tabla-lineas tbody tr input[aria-label="Cantidad"]'); await p.waitForTimeout(800); },
    cantidad: async (p, n) => { const i = p.locator('.tabla-lineas tbody tr input[aria-label="Cantidad"]').first(); await i.fill(String(n)); await i.press('Tab'); await p.waitForTimeout(300); },
    abrirDatos: async (p) => { if (!(await p.locator('#dv-cond').count())) { await p.locator('button:has-text("Datos de la venta")').click(); await p.waitForSelector('#dv-cond'); } },
    condicion: async function (p, v) { await this.abrirDatos(p); await p.selectOption('#dv-cond', v); await p.waitForTimeout(300); },
    anticipo: async (p, v) => { await p.fill('input[placeholder="Ej: F001-00000123"]', v); },
    detraccion: async function (p) { await this.abrirDatos(p); await p.locator('#dv-detraccion').selectOption({ index: 1 }); await p.waitForTimeout(300); },
    cronograma: async (p) => { await p.click('text=Generar cronograma sugerido'); await p.waitForTimeout(1500); },
    guardar: async (p) => { await p.locator('button:has-text("Guardar cambios")').first().click(); },
};
