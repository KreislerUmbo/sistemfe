export function resolveApiBaseUrl(): string {
  if (import.meta.env.PROD) {
    return import.meta.env.VITE_API_BASE_URL;
  }
  const { protocol, hostname } = window.location;
  const apiPort = import.meta.env.VITE_API_DEV_PORT ?? '8000';
  return `${protocol}//${hostname}:${apiPort}/api`;
}
