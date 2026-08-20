---
title: Guía de despliegue a producción — sistemafe (OVH)
fecha: 2026-08-14 (actualizado con diagnóstico real del servidor)
stack: Ubuntu Server 26.04 · Nginx · PHP-FPM 8.5 · Laravel 12 · PostgreSQL 18 · Redis
---

# Guía de despliegue a producción — sistemafe en OVH

Esta guía complementa `arquitectura-multitenant-backend.md` y
`plan-general-vertical-agencia-viajes_1.md`: ahí está el diseño de la
aplicación (multi-tenant, core + verticales); acá está cómo poner ese
diseño a correr en un servidor real, de forma segura, estable y rápida.

Todos los scripts referenciados están en `scripts/` y `config/`, en el
mismo paquete que esta guía. Están escritos para ejecutarse en orden, pero
cada uno es legible e independiente — revísalos antes de correrlos, no son
una caja negra.

## Hallazgos confirmados (ya reflejados en los scripts)

- **Versiones reales:** PHP 8.5.4 y PostgreSQL 18 (no 8.3/16 como se pensó
  al inicio). `30-postgres.sh` detecta la versión de Postgres solo.
- **PHP 8.5 vs. `phpoffice/phpspreadsheet`:** `phpoffice/phpspreadsheet`
  1.30.x (traído por `maatwebsite/excel` en su rama 3.1.x) exige PHP
  <8.5.0, así que `composer install` falla tal cual está el `composer.lock`
  hoy. Se evaluó instalar PHP 8.3 en el servidor como salida rápida, pero
  el PPA `ondrej/php` todavía NO tiene paquetes para Ubuntu 26.04
  "resolute" (release de abril 2026, hay issues abiertas sin resolver a la
  fecha) — instalarlo así falla con 404. El fix correcto es actualizar
  `maatwebsite/excel` a una versión que dependa de `phpoffice/phpspreadsheet`
  ^5.8+ (sin el límite <8.5.0), probándolo primero en local antes de tocar
  producción. Ver sección "PHP 8.5 y phpspreadsheet" más abajo.
- **RAM:** desinstalaste Postgres/evolution-api a propósito para partir
  limpio — con eso, ahora hay **3.2GB disponibles de 3.7GB totales**, de
  sobra para todo el stack. El tuning de PHP-FPM/Postgres/Redis sigue
  basado en RAM disponible (no total) por si en el futuro vuelve a haber
  otra cosa corriendo en el servidor.
- **Usuario operativo:** todo bajo tu cuenta `umbo` — no se crea usuario
  `deploy` separado.
- **`~/sistemfe` y `sv_facturacion.sql`** (en tu home): confirmado que son
  del sistema viejo de un solo tenant, ya sin uso — no son parte de este
  despliegue, se pueden ignorar.
- **El site de nginx `sistemfe`** (en `/etc/nginx/sites-enabled/`) sí es
  relevante: apunta a `/var/www/html/sistemfe`, que **es un monorepo real**
  con `api-sistema-fe/` (backend Laravel) y `admin-start-kit/` (frontend).
  Confirmaste que este site no tiene tráfico real ahora mismo, así que es
  seguro reemplazarlo (Fase 5). Falta confirmar si el código dentro de
  `api-sistema-fe/` ya tiene el trabajo del vertical agencia de viajes o
  si hace falta actualizarlo (ver Fase 7).
- **Postgres/puerto 60126:** ya no aplica — ambos servicios que los usaban
  están abajo, así que el firewall (Fase 1) cierra todo excepto 22/80/443
  sin necesidad de excepciones.

---

## Fase 0 — Diagnóstico (ya corrido con la versión actualizada)

`00-diagnostico.sh` reporta (sin instalar ni cambiar nada): los 15 procesos
que más RAM consumen, contenedores Docker, el contenido real de cada site
de nginx habilitado, y el estado real de los repos git encontrados. Si en
algún momento necesitas volver a correrlo (ej. después de cambios grandes):

```bash
# Desde TU COMPUTADORA (no desde la sesión SSH ya abierta al servidor):
scp deploy-ovh-sistemafe.zip umbo@149.56.128.92:~/
# Ya en el servidor:
ssh umbo@149.56.128.92
cd ~ && rm -rf deploy-ovh && unzip deploy-ovh-sistemafe.zip
cd ~/deploy-ovh/scripts
chmod +x *.sh
./00-diagnostico.sh | tee ~/diagnostico.txt
```

Copia y pega esa salida de vuelta. Con eso confirmamos: qué exactamente
consume la RAM (para decidir si subir el plan), qué es el repo de
`/var/www/html/sistemfe`, y si el site de nginx ya existente sirve como
base o hay que reemplazarlo.

---

## Fase 1 — Hardening del servidor

Script: `scripts/10-hardening.sh`

Qué hace:
- Actualiza el sistema
- Confirma que sigues operando con tu usuario actual `umbo` (no crea
  ningún usuario nuevo — ya decidimos mantenerlo simple)
- SSH: deshabilita login de root y autenticación por password, solo llave
- Firewall (`ufw`): cierra todo excepto SSH, 80 y 443 — **esto incluye el
  puerto 60126 que vimos abierto**; ya confirmaste que evolution-api no
  depende críticamente de tener eso expuesto
- `fail2ban`: banea IPs con intentos de fuerza bruta contra SSH y nginx
- Actualizaciones de seguridad automáticas (`unattended-upgrades`)
- Timezone `America/Lima` + `chrony` para hora sincronizada (importante:
  timestamps de facturación/SUNAT y de auditoría deben ser confiables)
- Swap de 2GB (tu RAM total es ≤4GB, así que esto se activa sí o sí — es
  importante dado lo justa que anda la memoria disponible)

**Antes de correrlo:** confirma que tienes una llave SSH funcionando —ya
la tienes, es como estás conectado ahora—, porque el script desactiva el
acceso por password. Si te quedas sin acceso, OVH tiene consola de rescate
(KVM/VNC) desde el panel de control para recuperar el servidor.

```bash
chmod +x 10-hardening.sh
sudo ./10-hardening.sh
```

Verifica en OTRA terminal (sin cerrar la sesión actual) que sigues
pudiendo entrar como `umbo` antes de continuar.

---

## Fase 2 — PHP-FPM 8.5

Script: `scripts/20-php.sh`

Tu servidor ya trae PHP 8.5.4 como CLI (Laravel 12 solo requiere ≥8.2), así
que instalamos `php8.5-fpm` para que CLI y FPM corran la misma versión —
mezclar 8.3 con un CLI en 8.5 puede dar comportamientos distintos entre
`artisan` (cron/colas) y las requests web. El script instala PHP-FPM y
todas las extensiones que Laravel 12 y `stancl/tenancy` necesitan (te
faltaban: bcmath, mbstring, gd, intl, redis, opcache), y ajusta:

- **Opcache**: memoria, `validate_timestamps=0` (máximo rendimiento, pero
  significa que **cada deploy debe recargar PHP-FPM** para que el código
  nuevo se vea — el `deploy.sh` ya lo hace).
- **Pool `www.conf`**: `pm.max_children` calculado según tu RAM
  **disponible** (no total) — con evolution-api ya consumiendo memoria,
  usar el total habría sobreestimado cuánto le queda realmente a PHP-FPM.
  El script avisa si el resultado queda muy bajo.
- `expose_php = Off`, `display_errors = Off` (no filtrar detalles internos
  en respuestas de error).
- Composer, si no está instalado (ya lo tienes: 2.9.5, así que este paso
  no hace nada).

```bash
sudo ./20-php.sh
```

---

## Fase 3 — PostgreSQL 18: rol de aplicación y tuning

Script: `scripts/30-postgres.sh`

Punto crítico de tu arquitectura (ya lo señala
`arquitectura-multitenant-backend.md`): **`stancl/tenancy` necesita que el
usuario de Postgres que usa Laravel tenga privilegio `CREATEDB`**, porque
cada tenant nuevo dispara la creación automática de su propia base de
datos.

El script crea un rol dedicado (ej. `sistemafe_app`) con:
- `CREATEDB` — sí, pero
- **NO superusuario** — no puede tocar roles de sistema, no puede leer
  archivos del servidor, etc. Es el balance correcto entre "Laravel puede
  aprovisionar tenants" y "si la app se compromete, no compromete todo
  Postgres".

**Antes de crear nada**, el script muestra los roles y bases de datos que
ya existen en el cluster (recuerda: `evolution-api` probablemente ya usa
este mismo Postgres) y te pide confirmar que los nombres nuevos no chocan.

También:
- Ofrece restringir `pg_hba.conf` a solo `localhost` — tu diagnóstico
  mostró Postgres escuchando en `0.0.0.0:5432`, accesible desde la red.
  Como confirmaste que evolution-api no depende críticamente de eso, el
  script lo restringe, pero primero **te muestra el `pg_hba.conf` actual y
  pide confirmación explícita** antes de reemplazarlo — por si hay algo
  más ahí que no vimos.
- Tuning de memoria (`shared_buffers`, `effective_cache_size`, `work_mem`)
  calculado según la RAM real del servidor.
- `password_encryption = scram-sha-256` (el método moderno, no MD5).

**Nota de RAM:** igual que en PHP-FPM, el tuning usa memoria *disponible*
en el momento de correr el script, no el total de 3.7GB — con solo ~830MB
libres ahora mismo, `shared_buffers` va a salir bajo (~200MB) y el script
te avisa si queda muy ajustado. Esto es intencionalmente conservador: es
mejor un Postgres con menos cache que uno que se queda sin memoria. Si más
adelante subes el plan de RAM, vuelve a correr este script para que el
tuning se recalcule con la memoria nueva.

```bash
sudo ./30-postgres.sh
```

**Nota sobre `max_connections`:** el script deja 100. Con muchos tenants
activos simultáneos y varios workers PHP-FPM, esto se puede quedar corto.
Antes de simplemente subir el número, considera **PgBouncer** (connection
pooling) — es más eficiente que abrir cientos de conexiones directas.
Cuando tengas tráfico real, mide primero (`SELECT count(*) FROM
pg_stat_activity;`) antes de ajustar a ciegas.

---

## Fase 4 — Redis (cache, sesiones, colas)

Script: `scripts/40-redis.sh`

No está instalado todavía y lo necesitas: sin Redis, si configuras
`QUEUE_CONNECTION=database` los jobs (aprovisionar tenant, generar PDF de
cotización, enviar notificación) compiten por las mismas tablas que tu
tráfico normal y son más lentos. Con Redis:

- `CACHE_STORE=redis` — cache de la app
- `SESSION_DRIVER=redis` — sesiones no dependen del disco local (importa
  si algún día escalas a más de un servidor)
- `QUEUE_CONNECTION=redis` — colas rápidas, y habilita usar Laravel
  Horizon más adelante si quieres un dashboard de monitoreo de colas

El script deja Redis escuchando **solo en localhost**, con `requirepass`,
y `maxmemory` + política `allkeys-lru` (si se llena, descarta lo menos
usado en vez de tirar errores). Con la RAM ajustada que tienes, `maxmemory`
va a salir bajo (RAM total / 8) — es correcto dejarlo así por ahora; no lo
subas a mano sin confirmar antes cuánta memoria sobra de verdad.

```bash
sudo ./40-redis.sh
```

---

## Fase 5 — Nginx: reemplazar el site existente

Archivo: `config/nginx-sistemafe.conf`

Tu diagnóstico reveló que el site `sistemfe` **ya existe y ya funciona**
con una arquitectura específica — no es un Laravel genérico con todo
sirviéndose desde `public/`, sino frontend SPA + API por separado:

```
/var/www/html/sistemfe/admin-start-kit/dist   <- frontend (root del site)
/var/www/html/sistemfe/api-sistema-fe/public  <- Laravel, montado en /api
```

En vez de reemplazar esto por una plantilla genérica, `nginx-sistemafe.conf`
**mantiene exactamente ese mismo mapeo de rutas** (confirmaste que este
site no tiene tráfico real, así que es seguro reemplazarlo) y le suma:

- **Rate limiting** en `/api/auth*` y `/api/*` — mitiga fuerza bruta y
  scraping agresivo antes de que llegue a PHP.
- **Cache agresivo** para los assets del build del frontend (JS/CSS/imágenes
  con hash en el nombre — inmutables, se pueden cachear un año sin riesgo).
- **Bloqueo explícito** de `.env`, `.git`, `composer.json/lock`, `artisan`
  — defensa en profundidad, no deberían estar servibles nunca.
- Cabeceras de seguridad básicas (`X-Frame-Options`,
  `X-Content-Type-Options`, etc.), gzip.
- `server_name` sigue siendo tu IP por ahora — **cuando compres el
  dominio** (Fase 6), cambias esa línea a
  `tudominio.com www.tudominio.com *.tudominio.com` y ahí sí queda listo
  para multi-tenant por subdominio (nginx no necesita saber qué tenant es
  cada uno — eso lo resuelve `stancl/tenancy` dentro de Laravel leyendo el
  `Host` header).

```bash
sudo cp config/nginx-sistemafe.conf /etc/nginx/sites-available/sistemfe
sudo nginx -t && sudo systemctl reload nginx
```

(El symlink `sites-enabled/sistemfe` ya existe apuntando ahí, no hace falta
recrearlo — solo reemplazas el archivo de `sites-available`.)

---

## Fase 6 — Dominio y SSL (bloqueante: todavía no tienes dominio)

Dijiste que aún no tienes dominio — esto **sí es bloqueante** para una
producción real, por tres razones concretas:

1. **El multi-tenant por subdominio lo requiere.** `tenant1.tudominio.com`,
   `tenant2.tudominio.com`, etc. — sin dominio propio no hay dónde crear
   esos subdominios.
2. **Let's Encrypt necesita un dominio** para emitir certificados. Sin
   HTTPS, cualquier dato de clientes (incluida facturación electrónica)
   viaja sin cifrar — no es aceptable para producción real, mucho menos
   con datos SUNAT de por medio.
3. Un certificado **wildcard** (`*.tudominio.com`, el que necesitas para
   no emitir uno por cada tenant nuevo) solo se puede validar por **DNS-01**,
   no por HTTP-01 — es decir, necesitas que tu proveedor DNS tenga API.

### Qué hacer

**1. Comprar el dominio.** OVH vende dominios directamente y se integra
bien con el resto (un solo panel, DNS y certbot-dns-ovh listo para usar).
No es la única opción — Namecheap, Cloudflare Registrar, etc. funcionan
igual — pero si ya estás en OVH, comprarlo ahí simplifica un paso.

**2. Configurar DNS**, apuntando al IP público de tu servidor:

```
A     tudominio.com          -> TU_IP
A     *.tudominio.com        -> TU_IP
```

(Si compraste el dominio en OVH, esto se hace desde el mismo panel, Zona
DNS.)

**3. Certificado wildcard con DNS-01.** Si el dominio está en OVH:

```bash
sudo apt install certbot python3-certbot-dns-ovh
```

Necesitas credenciales de API de OVH (se generan en
`https://api.ovh.com/createToken/` con permisos sobre `/domain/zone/*`).
Guárdalas en `/root/.secrets/ovh.ini`:

```ini
dns_ovh_endpoint = ovh-eu
dns_ovh_application_key = xxxx
dns_ovh_application_secret = xxxx
dns_ovh_consumer_key = xxxx
```

```bash
sudo chmod 600 /root/.secrets/ovh.ini
sudo certbot certonly \
  --dns-ovh --dns-ovh-credentials /root/.secrets/ovh.ini \
  -d tudominio.com -d "*.tudominio.com"
```

Certbot instala un timer systemd de renovación automática por defecto —
verifícalo con `systemctl list-timers | grep certbot`.

**4. Activar el bloque HTTPS** en `nginx-sistemafe.conf` (está comentado
al final del archivo, listo para descomentar una vez tengas el
certificado) y recargar nginx.

### Mientras tanto (sin dominio todavía)

Puedes avanzar con **todo lo demás** de esta guía usando la IP del
servidor directamente, en un solo "tenant" de prueba, sin HTTPS. Es válido
para terminar de armar el servidor y probar el deploy — **pero no la
consideres producción real hasta tener dominio + HTTPS**, especialmente
antes de meter datos de clientes reales o facturación electrónica.

---

## Fase 7 — Despliegue de Laravel

### Estructura real del repo (ya confirmada)

Es un **monorepo** en `/var/www/html/sistemfe/`, con dos subcarpetas:

```
/var/www/html/sistemfe/
├── api-sistema-fe/     ← backend Laravel (composer.json, artisan, .env)
└── admin-start-kit/    ← frontend (dashboard), build en admin-start-kit/dist
```

El repo ya está clonado ahí — el diagnóstico anterior decía "no" a
`composer.json`/`artisan` porque buscaba en la raíz en vez de dentro de
`api-sistema-fe/`. `70-primer-deploy.sh` y `deploy.sh` ya están ajustados
a esta ruta real.

**Antes de correr nada:** confirma con `cd /var/www/html/sistemfe && git
remote -v && git log --oneline -10` si este checkout ya tiene el trabajo
del vertical agencia de viajes (busca commits relacionados, o revisa si
existe `api-sistema-fe/database/migrations/verticals/agencia-viajes`) o si
es una versión previa a esa migración — dijiste que este `sistemfe` "era
tu sistema cuando no era tenant", así que puede que necesite un `git pull`
o cambiar de rama para tener lo último.

### PHP 8.5 y phpspreadsheet: qué arreglar antes del `composer install`

Con el `composer.lock` actual, `composer install` falla en este servidor
porque `phpoffice/phpspreadsheet` (traído por `maatwebsite/excel`) exige
PHP <8.5.0, y el servidor tiene 8.5.4. Instalar PHP 8.3 aparte para
esquivarlo no es viable hoy: el PPA `ondrej/php` todavía no soporta Ubuntu
26.04 "resolute" (ver Hallazgos, arriba). El fix va en `composer.json`, no
en el servidor:

1. **En tu XAMPP local**, revisa qué versión de `maatwebsite/excel` tienes
   y qué tan a fondo se usa en el código:
   ```bash
   composer show maatwebsite/excel
   grep -rl "Maatwebsite\\\\Excel\|use Maatwebsite" app/ --include="*.php"
   ```
   Si el proyecto usa `maatwebsite/excel` en su rama 3.1.x, está pegado a
   `phpoffice/phpspreadsheet` ^1.30.5 (la que bloquea 8.5) — no se puede
   aflojar solo esa dependencia porque `maatwebsite/excel` la fija.
2. **Actualiza `maatwebsite/excel` a una versión 4.x**, que ya depende de
   `phpoffice/phpspreadsheet` ^5.8 (sin el límite <8.5.0). Es un salto de
   versión mayor, así que:
   - Revisa el `CHANGELOG.md` del paquete (en su repo de GitHub, no la
     página de releases) para ver qué cambió en las clases de
     import/export que uses.
   - Pruébalo primero en tu XAMPP local — para eso tu XAMPP necesita al
     menos PHP 8.3 instalado (`maatwebsite/excel` 4.x pide PHP ^8.3). Si tu
     XAMPP anda en 8.1/8.2, súbelo ahí primero.
   - Corre tus exports/imports reales (no solo que cargue la página) antes
     de dar por bueno el cambio.
3. Con eso probado y funcionando local, commitea el `composer.json` /
   `composer.lock` actualizados, haz `git pull` en el servidor, y
   `composer install --no-dev --optimize-autoloader --no-interaction` ya
   debería pasar contra el PHP 8.5 nativo del servidor — sin tocar nada de
   infraestructura.

### Primer despliegue

Script: `scripts/70-primer-deploy.sh`

```bash
# Como tu usuario habitual (umbo), no root
cd ~/deploy-ovh/scripts
./70-primer-deploy.sh
```

Como el repo ya existe en el servidor, el script detecta eso y hace
`git pull` en vez de clonar desde cero (te muestra el remote y los últimos
commits, y pide confirmación antes de actualizar — por si resulta que
prefieres partir de cero). Si el repo NO existiera, pide la URL como
argumento y clona.

Hace además: instala dependencias (composer en `api-sistema-fe/`, npm
build en `admin-start-kit/`), crea `.env` desde `.env.example`, genera
`APP_KEY`, aplica permisos correctos (no `777` — nunca — sino
`umbo:www-data` con `775` solo en `storage/` y `bootstrap/cache/`), corre
migraciones (`core` + vertical agencia-viajes) y cachea configuración.

**Si en algún momento sí necesitas clonar con una deploy key** (por
ejemplo, para un segundo servidor): genera una llave SSH dedicada y
agrégala como "Deploy Key" de solo lectura en tu proveedor de git — así si
el servidor se compromete, el atacante no obtiene acceso de escritura a tu
repo ni a tus otros proyectos.

```bash
ssh-keygen -t ed25519 -C "sistemafe-ovh" -f ~/.ssh/id_ed25519_deploy
cat ~/.ssh/id_ed25519_deploy.pub   # pega esto como Deploy Key
```

### Checklist de `.env` de producción

```
APP_ENV=production
APP_DEBUG=false                    # CRÍTICO: en true, expone stack traces con rutas y queries
APP_URL=https://tudominio.com

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_DATABASE=db_tenant_central
DB_USERNAME=sistemafe_app
DB_PASSWORD=...                    # el de la Fase 3

CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true         # solo enviar cookie de sesión por HTTPS
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=...                 # el de la Fase 4

LOG_CHANNEL=daily
LOG_LEVEL=warning                  # info/debug generan demasiado ruido en prod
```

### Colas y scheduler

- Script: `config/supervisor-sistemafe-worker.conf` — mantiene vivos los
  `queue:work` (aprovisionamiento de tenant, PDFs, notificaciones). Sin
  esto, con `QUEUE_CONNECTION=redis` los jobs se quedan encolados sin
  procesar nunca.

  ```bash
  sudo apt install supervisor
  sudo cp config/supervisor-sistemafe-worker.conf /etc/supervisor/conf.d/
  sudo supervisorctl reread && sudo supervisorctl update
  sudo supervisorctl start sistemafe-worker:*
  ```

- `config/crontab-deploy.txt` — instala el scheduler de Laravel
  (`php artisan schedule:run` cada minuto) como crontab de `umbo`, más el
  cron de backup nocturno.

  ```bash
  crontab -e   # como umbo, pega el contenido de crontab-deploy.txt
  ```

- `config/sudoers-deploy` — ya tienes sudo completo como `umbo`, así que
  esto no es indispensable, pero sin él cada `./deploy.sh` te va a pedir
  tu password de sudo a mitad del script (para el reload de PHP-FPM y el
  restart de supervisor). Da permiso NOPASSWD solo para esos comandos
  puntuales, no sudo completo sin password.

  ```bash
  sudo visudo -f /etc/sudoers.d/umbo-deploy   # pega el contenido de config/sudoers-deploy
  ```

### Deploys siguientes

Una vez hecho el primer deploy, todo despliegue nuevo es:

```bash
cd /var/www/api-sistema-fe
./deploy.sh main
```

`deploy.sh` (ver `scripts/deploy.sh`) automatiza: modo mantenimiento →
`git pull` → `composer install` → build de frontend si aplica →
migraciones (core + vertical + por-tenant) → recache de config/rutas/vistas
→ reload de PHP-FPM → restart de workers → salir de mantenimiento. Es
idempotente: correrlo de nuevo sin cambios no rompe nada.

---

## Fase 8 — Backups

Script: `scripts/backup-postgres.sh` + `config/crontab-deploy.txt`

- Backup diario (3 AM hora Lima) de **todas** las bases del cluster —
  incluye la central y cada base de tenant, porque cada una es una BD
  Postgres separada (así es como funciona `stancl/tenancy`).
- Comprimido (`pg_dump -Fc` + gzip), retención de 14 días en disco.
- **Recomendado fuertemente:** copia offsite. Un backup que vive en el
  mismo disco que la base de datos no te protege si el servidor completo
  falla o se borra por error. OVH tiene Object Storage (compatible S3);
  con `rclone` es una línea de cron adicional (comentada al final del
  script, lista para activar cuando configures las credenciales).
- Prueba de restore: un backup que nunca probaste restaurar no es un
  backup confiable. Cuando tengas el primer backup real, practica
  restaurarlo en un servidor de prueba antes de necesitarlo de verdad.

---

## Fase 9 — Monitoreo y logs

- `config/logrotate-sistemafe` — rota logs de Laravel y de nginx
  (14 días, comprimidos) para que no llenen el disco.

  ```bash
  sudo cp config/logrotate-sistemafe /etc/logrotate.d/sistemafe
  ```

- **Monitoreo externo simple (gratis):** un ping de uptime externo (ej.
  UptimeRobot, Better Uptime) contra `https://tudominio.com/up` (Laravel
  12 trae esa ruta de health-check por defecto) te avisa si el servidor
  cae, sin instalar nada en el servidor.
- **Logs a revisar cuando algo falla:**
  - `/var/www/html/sistemfe/api-sistema-fe/storage/logs/laravel-*.log` (errores de la app)
  - `/var/log/nginx/sistemfe.error.log`
  - `journalctl -u php8.5-fpm -n 100`
  - `/var/www/html/sistemfe/api-sistema-fe/storage/logs/worker.log` (colas)

---

## Checklist final antes de ir a producción real

- [x] Diagnóstico corrido y revisado (Fase 0)
- [x] Confirmado qué pasó con Postgres/evolution-api (RAM ahora libre)
- [x] Confirmada estructura real del repo (monorepo, `api-sistema-fe/` +
      `admin-start-kit/`) y que el site `sistemfe` no tiene tráfico real
- [ ] SSH solo por llave, root deshabilitado, `ufw` activo, `fail2ban`
      corriendo (`10-hardening.sh`)
- [ ] PHP-FPM 8.5 con todas las extensiones, opcache activo (`20-php.sh`)
- [ ] Rol de Postgres con `CREATEDB` pero sin superusuario; `pg_hba.conf`
      restringido a localhost (`30-postgres.sh`)
- [ ] Redis con password y bind a localhost (`40-redis.sh`)
- [ ] Nginx reemplazado con la versión endurecida (`nginx-sistemafe.conf`)
- [ ] Dominio comprado, DNS wildcard apuntando al servidor
- [ ] Certificado SSL wildcard emitido y renovación automática verificada
- [ ] Confirmado si `api-sistema-fe/` ya tiene el trabajo del vertical
      agencia de viajes o si hace falta `git pull`/cambiar de rama
- [ ] `.env` de producción con `APP_DEBUG=false`, `APP_ENV=production`
- [ ] Permisos correctos (nunca `777`)
- [ ] Migraciones `core` + `verticals/agencia-viajes` corridas
- [ ] Supervisor corriendo los workers de cola
- [ ] Cron del scheduler de Laravel instalado
- [ ] Backup automático corriendo + copia offsite configurada
- [ ] Logrotate instalado
- [ ] Monitoreo externo de uptime configurado
- [ ] Al menos un ciclo de `deploy.sh` probado de punta a punta

---

## Lo que falta decidir juntos

1. **Correr las Fases 1-4** (hardening, PHP, Postgres, Redis) — ya no hay
   nada bloqueando esto, son los siguientes comandos a correr.
2. **Confirmar si `api-sistema-fe/` tiene el código multi-tenant al día**
   (ver Fase 7) — con `git log`/`git remote -v` dentro de esa carpeta.
3. **Dominio**: en cuanto lo compres, seguimos con la Fase 6 completa.
