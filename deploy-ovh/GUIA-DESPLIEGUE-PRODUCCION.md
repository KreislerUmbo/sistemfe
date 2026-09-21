---
title: Guía de despliegue a producción — sistemafe (OVH)
fecha: 2026-09-21 (Fase 6 — dominio, DNS y HTTPS — completada; ver esa sección)
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
- `server_name umbosystem.com www.umbosystem.com *.umbosystem.com` — el
  wildcard deja el sitio listo para multi-tenant por subdominio (nginx no
  necesita saber qué tenant es cada uno — eso lo resuelve `stancl/tenancy`
  dentro de Laravel leyendo el `Host` header). Originalmente era la IP del
  servidor; el dominio y el HTTPS se configuraron en la Fase 6.

```bash
sudo cp config/nginx-sistemafe.conf /etc/nginx/sites-available/sistemfe
sudo nginx -t && sudo systemctl reload nginx
```

(El symlink `sites-enabled/sistemfe` ya existe apuntando ahí, no hace falta
recrearlo — solo reemplazas el archivo de `sites-available`.)

---

## Fase 6 — Dominio, DNS y HTTPS (HECHO el 2026-09-21)

Esta fase quedó completada. Lo que sigue documenta **cómo está montado hoy** y
**cómo se hizo**, para poder repetirlo o diagnosticarlo cuando se olvide.

### Quién es quién (tres proveedores distintos)

Un error fácil es pensar que todo vive en el mismo lugar. No es así:

```
   DonWeb                     Cloudflare                     OVH
   (REGISTRADOR)              (DNS + validación SSL)         (SERVIDOR)
   ────────────               ──────────────────────         ──────────
   Aquí compraste             Aquí viven los registros       VPS Ubuntu con nginx,
   umbosystem.com.            DNS (A, CNAME).                PHP-FPM y Laravel.
   Aquí se define QUÉ         Y desde aquí certbot           IP pública fija:
   servidores de nombres      demuestra a Let's Encrypt      149.56.128.92
   manda el dominio.          que el dominio es tuyo.
```

- **DonWeb** es el registrador: solo decide *quién responde el DNS* del
  dominio (los "nameservers"). Ya no aloja el DNS.
- **Cloudflare** es el DNS real del dominio (nameservers
  `miki.ns.cloudflare.com` y `nile.ns.cloudflare.com`). Se usa **solo para DNS**:
  los registros están en modo **"Solo DNS" (nube gris)**, es decir, el tráfico
  va **directo al servidor de OVH**, sin pasar por el proxy de Cloudflare.
- **OVH** aloja el servidor. El certificado SSL vive **en el propio servidor**
  (Let's Encrypt), no en Cloudflare.

### Por qué esta arquitectura

1. **Multi-tenant por subdominio**: cada negocio entra por
   `<tenant>.umbosystem.com`. Un registro `A *.umbosystem.com` los cubre a todos,
   presentes y futuros, sin tocar el DNS al crear un tenant.
2. **HTTPS obligatorio**: hay contraseñas, tokens de sesión y facturación
   electrónica SUNAT. Sin HTTPS el navegador marca "No seguro" y todo viaja
   sin cifrar.
3. **Certificado wildcard** (`umbosystem.com` + `*.umbosystem.com`): con uno solo
   se cubre cualquier tenant nuevo. Un wildcard **solo se puede validar por
   DNS-01** (no por HTTP), y para eso certbot necesita poder crear registros TXT
   por API. DonWeb no ofrece esa API; Cloudflare sí, y es gratis.
4. **Por qué "Solo DNS" y no el proxy de Cloudflare**: con el proxy encendido
   habría que restaurar la IP real del visitante en nginx (si no, el rate limit
   trata a todos como una sola IP), usar el modo SSL `Full (strict)` y aceptar
   que el tráfico dependa de Cloudflare. Con "Solo DNS" el sistema se comporta
   igual que antes, solo que con HTTPS. Si algún día se quiere el proxy, es una
   decisión aparte.

### Cómo se hizo, paso a paso

**1. Registros DNS en Cloudflare** (los tres, todos en *Solo DNS*):

```
A      umbosystem.com      149.56.128.92
A      *.umbosystem.com    149.56.128.92
CNAME  www                 umbosystem.com
```

**2. Apuntar el dominio a Cloudflare, en DonWeb.** En el panel de DonWeb, dentro
de la gestión del **dominio** (sección de *Servidores DNS / Nameservers*), reemplazar
`ns1.donweb.com` y `ns2.donweb.com` por los dos que Cloudflare asigna
(`miki.ns.cloudflare.com`, `nile.ns.cloudflare.com`). Cloudflare muestra los suyos
en el resumen del dominio; **deben coincidir exactamente**.

> Ojo: DonWeb tiene *además* un editor de "Zona DNS". Ese editor **no cambia la
> delegación**. Agregar ahí registros NS que apunten a Cloudflare no sirve de
> nada (así se perdió tiempo una vez). Lo que cuenta es el ajuste de nameservers
> del dominio, no la zona.

Después Cloudflare tarda de 1 a 24 horas en marcar el dominio como **Activo**
(mientras tanto dice "Servidores de nombres no válidos" o "Esperando a que su
registrador propague": es normal). No se interrumpe el sitio porque los
registros son los mismos en ambos lados.

**3. Token de API de Cloudflare** (solo permite editar DNS de esta zona):
*My Profile → API Tokens → Create Token → plantilla "Edit zone DNS" → Zone
Resources: solo `umbosystem.com`*.

> Al crearlo, Cloudflare muestra el token **y debajo un ejemplo de comando
> `curl`**. Hay que copiar **solo la línea `cfut_...`**. Pegar también el `curl`
> deja el archivo de credenciales con 298 caracteres en vez de ~80 y da el error
> `6003 Invalid request headers`. Si un token se expone (por ejemplo, se pega en
> un chat), usar **Renovar/Roll** en el panel y guardar el nuevo.

**4. Emitir el certificado**, con el script del repo:

```bash
cd /var/www/html/sistemfe/deploy-ovh/scripts
sudo ./50-ssl-cloudflare.sh tu-correo@gmail.com
```

Instala certbot + el plugin de Cloudflare, guarda el token en
`/root/.secrets/cloudflare.ini` (permiso 600, se pide sin eco), emite el
certificado wildcard, instala el hook que recarga nginx al renovar y corre una
renovación de prueba. El correo (puede ser Gmail) solo sirve para que Let's
Encrypt avise si una renovación falla.

**Antes de emitir el real, probar siempre en seco** (no gasta cupo de
Let's Encrypt, que limita a 5 validaciones fallidas por hora):

```bash
sudo certbot certonly --dry-run \
  --dns-cloudflare --dns-cloudflare-credentials /root/.secrets/cloudflare.ini \
  --dns-cloudflare-propagation-seconds 60 \
  -d umbosystem.com -d "*.umbosystem.com" \
  --email tu-correo@gmail.com --agree-tos --no-eff-email --non-interactive
```

Debe terminar con `The dry run was successful.` (El aviso largo sobre la versión
de `cloudflare` 2.20 es ruido, se puede ignorar).

**5. Pasar nginx a HTTPS.** Los archivos del repo ya lo traen:
`config/nginx-sistemafe.conf` y `config/nginx-admin-panel.conf` (puerto 80 solo
redirige a 443; el 443 usa el certificado wildcard). **Siempre con backup y `diff`
antes de copiar** (los archivos del servidor pueden haber sido editados a mano):

```bash
cd /var/www/html/sistemfe
sudo cp /etc/nginx/sites-available/sistemfe    /root/sistemfe.bak-$(date +%F)
sudo cp /etc/nginx/sites-available/admin-panel /root/admin-panel.bak-$(date +%F)
diff /etc/nginx/sites-available/sistemfe    deploy-ovh/config/nginx-sistemafe.conf
diff /etc/nginx/sites-available/admin-panel deploy-ovh/config/nginx-admin-panel.conf
# si los diffs muestran solo lo esperado:
sudo cp deploy-ovh/config/nginx-sistemafe.conf    /etc/nginx/sites-available/sistemfe
sudo cp deploy-ovh/config/nginx-admin-panel.conf  /etc/nginx/sites-available/admin-panel
sudo nginx -t && sudo systemctl reload nginx
```

(También debe estar abierto el puerto 443: `sudo ufw status | grep 443`.)
Para volver atrás, copiar los `.bak` de vuelta y recargar; **ese bloque es solo
para emergencias**, no se corre junto con el de aplicar.

**6. Variables `.env` de producción** (no están en git, se editan a mano; los
de Vite son de compilación, no surten efecto hasta recompilar con `deploy.sh`):

| Archivo | Variable | Valor |
|---|---|---|
| `api-sistema-fe/.env` | `APP_URL` | `https://umbosystem.com/` (con la barra final) |
| `central-panel/.env` | `VITE_API_BASE_URL` | `/api/` (relativa: sirve con cualquier protocolo) |
| `admin-start-kit/.env` | `VITE_STORAGE_URL` | vacía (se resuelve contra el dominio del tenant) |

**7. Verificar** (siempre en el servidor primero, sin depender del DNS, y luego
desde fuera):

```bash
# Certificado servido: CN=umbosystem.com, vence ~90 días adelante
echo | openssl s_client -connect 127.0.0.1:443 -servername market.umbosystem.com 2>/dev/null | openssl x509 -noout -subject -dates
curl -sI --resolve market.umbosystem.com:443:127.0.0.1 https://market.umbosystem.com | head -3   # HTTP/2 200
curl -sI -H "Host: market.umbosystem.com" http://127.0.0.1/ | head -4                            # 301 a https
```

Desde otra máquina, **sin `-k`** (si el certificado fuera inválido, aquí falla):
`curl -sI https://market.umbosystem.com`, y abrir el sitio en el navegador (candado).

### Cómo se mantiene

- **Renovación automática**: `certbot.timer` (systemd) intenta renovar dos veces
  al día y solo lo hace cuando faltan menos de 30 días. Al renovar, el hook
  `/etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh` recarga nginx.
  Verificar: `sudo certbot certificates` y `sudo certbot renew --dry-run`.
- **El token de Cloudflare debe seguir válido**: certbot lo reutiliza en cada
  renovación. Si se renueva (Roll) o se borra en Cloudflare, actualizar
  `/root/.secrets/cloudflare.ini`; si no, la renovación fallará (y Let's Encrypt
  avisará por correo).
- **Un tenant nuevo no requiere tocar nada** (ni DNS ni certificado): el registro
  `*` y el certificado wildcard ya lo cubren.
- **HSTS** quedó comentado a propósito en nginx. Una vez que el navegador lo
  recibe, se niega a abrir el sitio por HTTP durante todo el `max-age`; si el
  HTTPS estuviera roto no habría vuelta atrás rápida. Activarlo (empezando con
  `max-age=300`) tras ~1 semana de HTTPS estable.

### Problemas reales que aparecieron (y cómo se diagnosticaron)

1. **"Some challenges have failed" / `Incorrect TXT record ... found`.**
   Causa: el DNS real del dominio seguía en **DonWeb** (la delegación del `.com`
   apuntaba a `ns1/ns2.donweb.com`), aunque Cloudflare ya tenía la zona
   configurada. Certbot escribía los TXT correctos en Cloudflare, pero Let's
   Encrypt consultaba a DonWeb, donde quedaban TXT viejos de intentos manuales.
   **Cómo detectarlo**: no fiarse de un resolver normal; preguntar la delegación
   directamente al servidor del TLD:
   `nslookup -type=NS umbosystem.com a.gtld-servers.net`
   (debe mostrar los nameservers de Cloudflare, no los de DonWeb).
2. **El panel de Cloudflare decía "proxy activado" pero el DNS público devolvía la
   IP directa del servidor.** Mismo origen: Cloudflare todavía no era la autoridad,
   así que sus ajustes no se aplicaban. Con la zona activa se dejaron todos los
   registros en "Solo DNS".
3. **`www.<tenant>.umbosystem.com` daba 404 "Negocio no encontrado".**
   `stancl/tenancy` toma el **primer segmento** del host como tenant
   (`InitializeTenancyBySubdomain::$subdomainIndex = 0`); con `www.` delante, el
   tenant "sería" `www`. nginx redirige `www.<tenant>...` a la versión sin `www`
   (bloque del puerto 80). En 443 no se repite: el certificado wildcard **no**
   cubre hosts de dos niveles (`www.market...`), así que quien entre directo por
   `https://www.<tenant>...` vería un error de certificado; quien escribe la URL
   sin esquema entra por 80 y se corrige antes.
4. **Los wildcards de nginx y de DNS no se comportan igual.** En nginx,
   `*.umbosystem.com` cubre **cualquier** cantidad de niveles de subdominio; en
   DNS (y en el certificado) solo **uno**. Además, en nginx un match exacto o
   wildcard siempre gana sobre cualquier `server_name` con regex, sin importar el
   orden. Por eso el redirect de `www` va **dentro** del server block que ya
   recibe esos hosts, no en uno aparte. Un `nginx -t` correcto solo valida la
   sintaxis: probar siempre con `curl -H "Host: ..." http://127.0.0.1/`.
5. **Se ejecutó el bloque de "volver atrás" junto con el de aplicar** y nginx
   quedó con la configuración vieja (sin daño, pero HTTPS no se activó). Los dos
   bloques son alternativos, no consecutivos.
6. **Los archivos de nginx del servidor pueden diferir del repo** (ya pasó: un
   `cp` a ciegas borró un `server_name` editado a mano y todos los tenants
   mostraron el login de otro sitio). De ahí la regla de hacer `diff` antes de
   copiar.

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
APP_URL=https://umbosystem.com/   # con barra final (hay código que concatena 'storage/' a mano)

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

Una vez hecho el primer deploy, todo despliegue nuevo es (así se corre hoy en
producción):

```bash
cd /var/www/html/sistemfe/deploy-ovh/scripts
./deploy.sh main
```

`deploy.sh` (ver `scripts/deploy.sh`) automatiza estos 11 pasos: modo
mantenimiento → `git pull` → `composer install` → build de los dos frontends
(`admin-start-kit` y `central-panel`) → migraciones de la base central →
migraciones de los tenants (core + vertical) → **catálogos sembrados
(`MenuItemsSeeder`) + limpieza de caché del menú** → recache de
config/rutas/vistas → permisos → reload de PHP-FPM y restart de workers →
salir de mantenimiento. Es idempotente: correrlo de nuevo sin cambios no
rompe nada. Un `trap` garantiza `artisan up` aunque algún paso falle.

Detalles que conviene saber:

- **Los seeders NO corren solos con las migraciones.** Caso real: el menú
  lateral pasó a armarse desde la tabla central `menu_items`; el deploy la
  creó pero quedó vacía y todos veían solo el Dashboard. Por eso el paso 7 corre
  `MenuItemsSeeder` (idempotente, `updateOrCreate` por código) en cada deploy y
  limpia el caché del menú (queda cacheado hasta 24 h por usuario). Si en el
  futuro otro catálogo se siembra por seeder, hay que agregarlo a ese paso.
  Limitación: no borra ítems que se quiten del seeder.
- **Si `git pull` se queja por `yarn.lock`**: el build en Linux reescribe
  entradas de `esbuild` (`win32-x64` → `linux-x64`); es ruido, se resuelve con
  `git checkout -- admin-start-kit/yarn.lock` y se reintenta.
- **El aviso `The [public/storage] link already exists`** es inofensivo.
- **`deploy.sh` no toca nginx ni el certificado**: la configuración de nginx
  se aplica aparte (Fases 5 y 6), con `diff` primero.
- **Cambios en `.env`** (que no está en git) requieren un deploy o al menos
  `php artisan config:cache`; los de Vite (`VITE_*`) además requieren recompilar.
- **Antes de un deploy grande** (varias migraciones), sacar un backup manual:
  `sudo /var/backups/sistemafe/scripts/backup-postgres.sh`.
- **Rollback**: el de código es un `git checkout <commit>` + `composer install`
  + rebuild + recache + reload (no lo hace `deploy.sh`, que siempre trae lo
  último de la rama). Revertir migraciones es mucho más delicado (afecta a cada
  tenant y puede borrar datos ya cargados): casi siempre conviene un fix hacia
  adelante.

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
  UptimeRobot, Better Uptime) te avisa si el servidor cae, sin instalar nada
  en el servidor. **Ojo con la URL que se monitorea** (verificado en producción
  el 2026-09-21): `https://<tenant>.umbosystem.com/up` **no sirve** — nginx solo
  envía `/api` a Laravel, así que `/up` lo responde el frontend con `200
  text/html` aunque PHP o la base de datos estén caídos. Usar en cambio
  `https://market.umbosystem.com/api/branding` (o el subdominio de otro tenant
  real): pasa por nginx → PHP-FPM → Laravel → base de datos y devuelve JSON con
  `200`. Configurar la alerta para que compruebe el código `200` (y, si el
  servicio lo permite, que el cuerpo contenga `razon_social_comercial`).
- **Logs a revisar cuando algo falla:**
  - `/var/www/html/sistemfe/api-sistema-fe/storage/logs/laravel-*.log` (errores de la app)
  - `/var/log/nginx/sistemfe.error.log`
  - `journalctl -u php8.5-fpm -n 100`
  - `/var/www/html/sistemfe/api-sistema-fe/storage/logs/worker.log` (colas)

---

## Migraciones futuras — qué pasa si cambio de proveedor

Hoy la plataforma depende de **tres proveedores independientes**
(ver la Fase 6): el registrador del dominio (DonWeb), el DNS y la validación
del certificado (Cloudflare) y el servidor (OVH). Están desacoplados a
propósito: cambiar uno **no obliga** a cambiar los otros, siempre que se
respeten los puntos de cada caso. Esta sección es el checklist para cuando
llegue el momento; léela **antes** de empezar, no durante.

### Resumen: qué se cae y qué hay que tocar

| Si cambias… | ¿Se cae la plataforma? | Qué hay que tocar | Riesgo principal |
|---|---|---|---|
| **El registrador** (DonWeb → otro) | No, si los nameservers se conservan | Solo verificar los nameservers después | Que el nuevo registrador **restablezca los nameservers** a los suyos |
| **El DNS / Cloudflare** | No, si los registros se recrean **antes** de cambiar | 3 registros DNS + el método de validación de certbot | Certificado que no renueva (hay ~30 días de margen) |
| **El servidor** (OVH → Google Cloud u otro) | No, si se prepara en paralelo y se cambia solo el registro `A` | 2 registros `A`, bases de datos, `storage/`, `.env` | Perder datos que **no están en git** (bases, archivos, `.env`) |

Regla común a los tres: **nunca cortar lo viejo antes de verificar lo nuevo**, y
mantener lo viejo funcionando unos días como plan de vuelta.

---

### Caso 1 — Transferir el dominio a otro registrador

El registrador solo guarda *a qué nameservers apunta* el dominio. Como estos
son los de Cloudflare, la transferencia **no mueve** el DNS, ni el servidor, ni
el certificado. El único riesgo real: algunos registradores, al recibir un
dominio, **reemplazan los nameservers por los suyos por defecto**. Si nadie lo
nota, el dominio deja de resolver y la plataforma queda inaccesible (los datos
y el servidor siguen intactos, pero nadie puede entrar).

Checklist:

1. **Margen de tiempo**: iniciar la transferencia con **más de 15 días** antes
   del vencimiento. Un dominio vencido a mitad de camino sí tumba todo.
2. **Preparar en el registrador actual**: desbloquear el dominio y pedir el
   **código de autorización (EPP/AuthCode)**. Confirmar que se tiene acceso al
   **correo del titular**: ahí llegan las aprobaciones.
3. **Restricciones habituales**: un dominio no se puede transferir en los
   primeros 60 días desde su registro o desde su última transferencia.
4. **Mantener DNSSEC desactivado** (hoy lo está). Con DNSSEC activo, la
   transferencia obliga a coordinar el registro DS y es fácil romper la
   resolución.
5. **Durante la transferencia**, si el nuevo proveedor pregunta por nameservers,
   elegir "mantener los actuales" o poner desde el primer momento:
   `miki.ns.cloudflare.com` y `nile.ns.cloudflare.com`.
6. **Después, verificar siempre** (no confiar en que "se conservó"):
   ```bash
   nslookup -type=NS umbosystem.com a.gtld-servers.net
   ```
   Debe mostrar los dos de Cloudflare. Si muestra otros, corregirlos ya en el
   panel del nuevo registrador (el sitio estará caído hasta que propague).
7. Comprobar que los sitios cargan: `curl -sI https://market.umbosystem.com`.

**Alternativa recomendable: transferir a Cloudflare Registrar.** Como el DNS ya
está en Cloudflare, registrador y DNS quedarían en un solo lugar y desaparece
el riesgo de los nameservers. Revisar antes que el `.com` cumpla sus
condiciones de transferencia y el precio vigente.

---

### Caso 2 — Cambiar el DNS y la validación del certificado (salir de Cloudflare)

Cloudflare cumple **dos funciones**: responde el DNS y permite a certbot crear
los registros TXT de la validación DNS-01. Si algún día se deja Cloudflare, hay
que reemplazar **ambas**.

**A. Mover el DNS a otro proveedor**

1. **Antes de tocar nada**, crear en el proveedor nuevo los mismos registros:
   ```
   A      umbosystem.com      <IP del servidor>
   A      *.umbosystem.com    <IP del servidor>
   CNAME  www                 umbosystem.com
   ```
   (más cualquier otro registro que se haya agregado desde entonces; comparar
   con la lista actual en Cloudflare).
2. Comprobar que el proveedor nuevo responde bien **antes** de delegarle el
   dominio: `nslookup market.umbosystem.com <nameserver-del-nuevo-proveedor>`.
3. Recién entonces, cambiar los nameservers **en el registrador** (no en el
   editor de zona: ver Fase 6). Como los registros son idénticos en ambos lados,
   el sitio no se interrumpe durante la propagación.
4. Dejar la zona de Cloudflare activa unos días hasta confirmar que todo resuelve
   por el proveedor nuevo.

**B. Cambiar cómo se valida el certificado**

- Hay que usar el plugin de certbot del **nuevo proveedor DNS**
  (`python3-certbot-dns-<proveedor>`), con su propio token, y sustituir el
  archivo `/root/.secrets/cloudflare.ini`. El script `50-ssl-cloudflare.sh` es la
  plantilla: se copia y se adapta.
- Si el nuevo proveedor **no tiene API**, el wildcard no se puede renovar de forma
  automática (la validación DNS-01 exige crear un TXT por cada renovación). Opciones:
  delegar solo `_acme-challenge` a una zona que sí tenga API, o dejar de usar
  wildcard y emitir un certificado por cada subdominio con HTTP-01 (cada tenant
  nuevo necesitaría su certificado).
- **Probar siempre en seco antes**: `sudo certbot certonly --dry-run ...`.
- **Margen de seguridad**: el certificado dura 90 días y certbot empieza a
  renovarlo cuando faltan 30. Si una renovación falla, Let's Encrypt avisa por
  correo y hay un mes para corregirlo antes de que caduque. Comprobar el estado:
  `sudo certbot certificates` y `sudo certbot renew --dry-run`.
- Si se **rota o elimina** el token de Cloudflare sin haber migrado, la renovación
  falla: actualizar `/root/.secrets/cloudflare.ini` (ver Fase 6, "Cómo se
  mantiene").

---

### Caso 3 — Migrar el servidor (OVH → Google Cloud u otro)

OVH sigue siendo muy económico, así que conviene migrar **solo si hay una razón
concreta** (costo, región, servicios administrados). La migración es viable y
casi sin corte porque el certificado se valida por DNS (no depende de la IP del
servidor) y los registros `A` están en modo "Solo DNS" (cambiar la IP surte
efecto en minutos).

**Qué se recrea desde git** (lo que ya tiene esta guía): hardening, PHP, Redis,
Postgres (`10`-`40-*.sh`), nginx, supervisor, cron y logrotate (`config/`),
certificado (`50-ssl-cloudflare.sh`) y despliegue de la app (`deploy.sh`).

**Qué NO está en git y hay que llevarse a mano** (aquí es donde se pierde
información si se olvida algo):

| Qué | Dónde está | Cómo llevarlo |
|---|---|---|
| Base de datos **central** (tenants, dominios, menú, planes) | Postgres | `pg_dump` (ya lo hace `backup-postgres.sh`) |
| Una base **por tenant** (`tenant<id>`, con todas sus ventas, clientes, comprobantes) | Postgres | `pg_dump` de cada una (mismo script) |
| Roles/usuarios de Postgres (`sistemafe_app`) | Postgres | `pg_dumpall --globals-only` (mismo script) |
| **Archivos por tenant**: fotos, logos, XML/CDR, **certificados SUNAT** | `api-sistema-fe/storage/tenant<id>/` (más `storage/app/` con lo central) | `rsync` de toda la carpeta `storage/` |
| Archivos `.env` (3: backend y los dos frontends) | Solo en el servidor | Copiarlos a mano (contienen secretos: transferir por canal seguro) |
| Token de Cloudflare | `/root/.secrets/cloudflare.ini` | **No copiar**: crear uno nuevo en la máquina nueva |
| Configuración de sparse-checkout, llaves SSH, deploy key | Solo en el servidor | Rehacer en el nuevo |
| Backups viejos / copia offsite | `/var/backups/sistemafe/` | Copiar si se quieren conservar; reconfigurar `rclone` |

**Plan sugerido (sin corte largo):**

1. **Preparar el servidor nuevo en paralelo**, con el mismo Ubuntu y los scripts de
   esta guía. En una VM de nube (por ejemplo Compute Engine): reservar una **IP
   estática** (si no, cambia al reiniciar) y abrir 80/443 en el firewall del
   proveedor **además** de `ufw`. Usar la **misma versión mayor de PostgreSQL**
   (o superior); no se puede restaurar hacia una versión menor.
2. **Emitir el certificado en el servidor nuevo antes del cambio**:
   `sudo ./50-ssl-cloudflare.sh correo`. Funciona aunque el DNS todavía apunte al
   servidor viejo, porque se valida por DNS.
3. **Desplegar el código** (`70-primer-deploy.sh` / `deploy.sh`) y copiar los `.env`.
4. **Ensayo de la restauración con un backup real**, sin tocar producción:
   restaurar primero los roles y luego cada base, y comprobar que la app arranca
   con esos datos. Este ensayo es obligatorio: un backup que nunca se restauró
   no es un backup confiable.
   ```bash
   gunzip -c globals_FECHA.sql.gz | sudo -u postgres psql          # roles primero
   sudo -u postgres createdb -O sistemafe_app tenant<id>            # por cada base
   gunzip -c tenant<id>_FECHA.sql.gz | sudo -u postgres pg_restore -d tenant<id>
   ```
   (`backup-postgres.sh` guarda cada base como `pg_dump -Fc` comprimido con gzip;
   por eso se descomprime antes de `pg_restore`. Restaurar **sin** `--no-owner`
   para que los objetos queden a nombre de `sistemafe_app`, que es el usuario que
   usa la app.)
5. **Probar el servidor nuevo sin cambiar el DNS**, forzando la IP:
   `curl -sI --resolve market.umbosystem.com:443:<IP-NUEVA> https://market.umbosystem.com`
   y, desde un navegador, con una entrada temporal en el archivo `hosts` de
   una PC de prueba.
6. **Día del cambio (ventana corta):**
   a. En el servidor viejo: `php artisan down` (nadie escribe más datos).
   b. Hacer un **último** volcado de todas las bases + `rsync` de `storage/`
      (solo lo nuevo desde el ensayo) y restaurarlo en el nuevo.
   c. En Cloudflare, cambiar la IP de los registros `*` y `umbosystem.com`
      (el `www` la sigue solo). Con TTL "Automático" tarda unos 5 minutos.
   d. Verificar en el nuevo (login, una venta de prueba, un PDF, fotos, el
      menú). Si algo falla, **volver atrás es solo devolver la IP** en Cloudflare
      mientras el servidor viejo siga intacto.
7. **Dejar el servidor viejo encendido unos días** (en mantenimiento, sin
   tráfico) como red de seguridad antes de darlo de baja. Antes de apagarlo,
   confirmar que los backups automáticos del nuevo se están generando y que la
   renovación del certificado funciona (`certbot renew --dry-run`).

**Diferencias a tener presentes al cambiar de proveedor**

- **Bases administradas** (por ejemplo Cloud SQL) en vez de Postgres propio: la
  plataforma crea **una base por tenant automáticamente**, así que el usuario
  de la app necesita permiso `CREATEDB`. Comprobar que el servicio administrado
  lo permite y que el costo por muchas bases es razonable; es la parte que más
  cambia respecto a lo actual.
- **Rutas y nombres fijos** en `deploy.sh` (`/var/www/html/sistemfe`,
  `php8.5-fpm`, el programa `sistemafe-worker` de supervisor): se conservan si el
  servidor nuevo se arma con los mismos scripts; si no, hay que ajustarlos.
- **Ubicación y latencia**: revisar la región elegida respecto a los usuarios y a
  los servicios externos (SUNAT).
- **Costo**: comparar el total real (VM + disco + IP + tráfico + backups), no solo
  el precio de la máquina.

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
- [x] Dominio comprado (DonWeb), DNS en Cloudflare con wildcard apuntando al
      servidor (OVH), en modo "Solo DNS" (Fase 6, 2026-09-21)
- [x] Certificado SSL wildcard emitido y renovación automática verificada
      (Let's Encrypt vía Cloudflare, vence 2026-12-20, `certbot.timer` + hook)
- [ ] HSTS activado (esperar ~1 semana de HTTPS estable, empezar con `max-age=300`)
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
3. **Dominio y HTTPS**: hechos (Fase 6, 2026-09-21). Solo queda activar HSTS
   tras ~1 semana de HTTPS estable.

> **Nota sobre el estado de este checklist**: se dejó de mantener casilla por
> casilla mientras el servidor ya estaba en producción real. Los ítems
> marcados con `[ ]` de hardening, PHP, Postgres, Redis, workers, cron, backups
> y logrotate **no significan necesariamente que falten**: el servidor lleva
> tiempo funcionando y varios se hicieron antes de que existiera esta guía
> actualizada. Antes de asumir que uno falta, comprobarlo contra el servidor
> real (por ejemplo `sudo ufw status`, `systemctl status php8.5-fpm`,
> `sudo supervisorctl status`, `crontab -l`, `ls /etc/logrotate.d/`).
