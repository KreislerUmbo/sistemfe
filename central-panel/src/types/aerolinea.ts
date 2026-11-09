// Catálogo central de aerolíneas (T-015), común a todas las agencias. Al unir A en B, A queda
// inactiva con `unida_a_id` = B. `creada_por_tenant` es el id del tenant que la agregó (o null
// si vino de la lista inicial).
export interface Aerolinea {
  id: number;
  nombre: string;
  codigo_iata: string | null;
  activa: boolean;
  creada_por_tenant: string | null;
  unida_a_id: number | null;
  created_at: string;
  updated_at: string;
}
