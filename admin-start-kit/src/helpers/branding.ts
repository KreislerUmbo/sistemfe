import httpClient from "@/helpers/http-client";

export interface Branding {
    razon_social_comercial: string | null;
    logo_vertical: string | null;
    logo_horizontal: string | null;
}

// GET /branding es público (no exige token — ver EnsureTokenBelongsToTenant)
// así que sirve tanto para el login (sin sesión) como para el sidebar
// (autenticado). Se cachea en memoria de módulo: el sidebar se monta una
// vez por sesión de SPA, no hace falta repetir la petición en cada render.
let cache: Branding | null = null;
let pending: Promise<Branding | null> | null = null;

export async function fetchBranding(): Promise<Branding | null> {
    if (cache) return cache;
    if (pending) return pending;

    pending = httpClient
        .get<Branding>("branding")
        .then((res) => {
            cache = res.data;
            return cache;
        })
        .catch(() => null)
        .finally(() => {
            pending = null;
        });

    return pending;
}
