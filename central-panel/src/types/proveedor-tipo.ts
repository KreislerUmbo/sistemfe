// CRUD del catálogo central `proveedor_tipos` (vertical Agencia de Viajes) — antes fijo,
// sembrado solo por ProveedorTipoSeeder. slug nunca viaja en el payload de alta/edición:
// el backend lo deriva de `nombre` una sola vez, al crear, y queda inmutable (ver
// ProveedorTipoController).
export interface ProveedorTipo {
  id: number;
  nombre: string;
  slug: string;
  giro: string;
  activo: boolean;
  created_at: string;
  updated_at: string;
}
