<!-- frontend/src/components/Portal/SeccionServicios.vue -->
<template>
<!-- ===== BANNERS PROMO ===== -->
<section class="banners-row">
  <div class="banner-card banner-1">
    <span class="banner-icon">🛒</span>
    <span class="banner-tag">Productos digitales</span>
    <span class="banner-title">ANTIVIRUS & SOFTWARE</span>
    <span class="banner-sub">Licencias originales al mejor precio. Activación inmediata.</span>
    <span class="banner-link">Ver productos →</span>
  </div>
  <div class="banner-card banner-2">
    <span class="banner-icon">🏢</span>
    <span class="banner-tag">Para empresas</span>
    <span class="banner-title">SISTEMAS A MEDIDA</span>
    <span class="banner-sub">Minimarket, boticas, restaurantes, hoteles y más.</span>
    <span class="banner-link">Ver sistemas →</span>
  </div>
  <div class="banner-card banner-3">
    <span class="banner-icon">🔧</span>
    <span class="banner-tag">Soporte técnico</span>
    <span class="banner-title">REPARACIÓN EXPRESS</span>
    <span class="banner-sub">Diagnóstico gratuito. Garantía en todos los servicios.</span>
    <span class="banner-link">Solicitar servicio →</span>
  </div>
</section>
</template>

<style scoped>
/* Banner promos inferior */
  .banners-row {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 32px 40px;
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 16px;
  }

  .banner-card {
    border-radius: 14px;
    padding: 28px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    cursor: pointer;
    transition: transform 0.3s, box-shadow 0.3s;
    position: relative;
    overflow: hidden;
    min-height: 140px;
  }
  .banner-card:hover { transform: translateY(-3px); box-shadow: 0 16px 48px rgba(0,0,0,0.5); }

  .banner-1 { background: linear-gradient(135deg, #1a0005 0%, #3d0010 100%); border: 1px solid rgba(232,0,29,0.2); }
  .banner-2 { background: linear-gradient(135deg, #050510 0%, #0d1535 100%); border: 1px solid rgba(50,100,255,0.2); }
  .banner-3 { background: linear-gradient(135deg, #0a0a00 0%, #1f1800 100%); border: 1px solid rgba(255,160,0,0.2); }

   .banner-card .banner-icon { font-size: 32px; }
  .banner-card .banner-tag { font-size: 10px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; }
  .banner-1 .banner-tag { color: var(--rojo); }
  .banner-2 .banner-tag { color: #6b8fff; }
  .banner-3 .banner-tag { color: #ffb300; }
  .banner-card .banner-title { font-family: var(--font-display); font-size: 28px; letter-spacing: 1px; color: white; line-height: 1; }
  .banner-card .banner-sub { font-size: 13px; color: rgba(255,255,255,0.55); line-height: 1.5; }
  .banner-card .banner-link { font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 4px; margin-top: auto; }
  .banner-1 .banner-link { color: var(--rojo); }
  .banner-2 .banner-link { color: #6b8fff; }
  .banner-3 .banner-link { color: #ffb300; }
</style>