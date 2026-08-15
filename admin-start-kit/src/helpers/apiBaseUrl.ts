export function resolveApiBaseUrl(): string {
  if (import.meta.env.PROD) {
    return '/api';
  }
  const { protocol, hostname } = window.location;
  const apiPort = import.meta.env.VITE_API_DEV_PORT ?? '8000';
  return `${protocol}//${hostname}:${apiPort}/api`;
}
