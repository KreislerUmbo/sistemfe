// src/types/capacitacion.ts
export interface RecursoCapacitacion {
  id: number
  sistema_id: number
  sistema?: { id: number; title: string }
  categoria: string
  titulo: string
  descripcion: string
  tipo: 'video' | 'documento' | 'imagen' | 'link'
  url: string | null
  archivo: string | null
  miniatura: string | null
  orden: number
  destacado: boolean
  estado: boolean
  created_at: string
  updated_at: string
}

export interface Sistema {
  id: number
  title: string
  // otros campos si los hay
}