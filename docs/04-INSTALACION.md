# 04 - Instalación

Qué se necesita, cómo se levanta y qué se comprueba después.

---

## 1. Requisitos

| Componente | Versión fijada | Dónde se fija |
|---|---|---|
| PHP | 8.3 | `deploy/api/Dockerfile` |
| PostgreSQL | 18.6 | `deploy/docker-compose.*.yml` |
| Redis | 8.8.3 | `deploy/docker-compose.*.yml` |
| Node | 22.14.0 (Alpine 3.21) | `deploy/web/Dockerfile` |
| Nuxt | 4.6.2 | `web/package.json` |
| Laravel | 13.37 | `api/composer.json` |
| Composer | 2.8.9 | `deploy/api/Dockerfile` |
| Caddy | 2.10.0 | `deploy/docker-compose.prod.yml` |
| Authentik | 2025.10 | `deploy/docker-compose.*.yml` |

**Las versiones están fijadas a propósito.** Ni una imagen ni una dependencia
usa `latest`. Una actualización no solicitada en una reconstrucción de imagen es
un despliegue que cambia sin que nadie lo decida.

---

## 2. Desarrollo

### 2.1 Preparar el `.env`

```bash
cd apps/agencia-viajes
cp .env.example .env
```

Generar los secretos:

```bash
openssl rand -base64 48                  # APP_KEY
openssl rand -base64 60 | tr -d '\n'     # AUTHENTIK_SECRET_KEY
openssl rand -hex 32                     # AUTHENTIK_BOOTSTRAP__TOKEN
openssl rand -base64 32                  # DB_PASSWORD
```

**Ojo:** PostgreSQL no acepta contraseñas de más de 99 caracteres
(documentado por Authentik).

Ningún valor de `.env.example` es real. El archivo está en `.gitignore`.

### 2.2 Levantar

```bash
docker compose -f deploy/docker-compose.dev.yml --env-file .env up -d
```

Qué levanta:

| Servicio | Puerto (host) | Para qué |
|---|---|---|
| `web` | 3000 | La interfaz. Es lo que se abre en el navegador. |
| `api` | 8000 | La API. Para probar desde la terminal. |
| `authentik-server` | 9000 | El proveedor de identidad. |
| `postgres` | 127.0.0.1:5432 | La base. |
| `redis` | 127.0.0.1:6379 | Cache y colas. |

### 2.3 Estado inicial

El `entrypoint.sh` de la API aplica las migraciones al arrancar, cuando
`AGENCIA_CORRER_MIGRACIONES=true`.

Para cargar la agencia de ejemplo:

```bash
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan migrate --seed
```

Ver [06](06-PRIMER-PRODUCTO.md) para qué hay en esa semilla.

---

## 3. Producción

```bash
cp .env.example .env.prod
# completar todos los valores

docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  build -t hefesto/agencia-api:1.0.0 ../api -f deploy/api/Dockerfile
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  build -t hefesto/agencia-web:1.0.0 ../web -f deploy/web/Dockerfile

docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod up -d
```

`API_IMAGE` y `WEB_IMAGE` en `.env.prod` apuntan a las imágenes construidas. El
compose de producción usa imágenes, **no** `build`: el despliegue tiene que ser
reproducible y no depender de que el código fuente esté en el servidor.

### Qué cambia respecto de desarrollo

| | Desarrollo | Producción |
|---|---|---|
| Puertos publicados | API, web, Authentik, PostgreSQL, Redis | **Solo Caddy** (80 y 443) |
| HTTPS | No | Sí, con HSTS y `preload` |
| Código | Volumen montado desde el host | Dentro de la imagen |
| Redis | Sin contraseña | Con contraseña |
| `APP_DEBUG` | `true` | `false` |
| `SESSION_SECURE_COOKIE` | `false` | `true` |

PostgreSQL y Redis **no** tienen puertos publicados en producción: quedan dentro
de la red interna, sin salida a Internet.

---

## 4. Comprobaciones obligatorias después de levantar

### 4.1 El aislamiento entre agencias

```bash
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  run --rm api php artisan viajes:verificar-aislamiento
```

**Este comando tiene que salir con código 0.** Devuelve 1 si algo falla, y el
mensaje dice qué.

Comprueba:

1. Que el rol de la base **no** sea superusuario ni tenga `BYPASSRLS`.
2. Que las 16 tablas de negocio tengan RLS activada, **forzada** y con política.
3. Que `public.app_tenant_id()` **falle** sin contexto (aislamiento cerrado).
4. Que con una agencia inexistente no se lea ninguna fila, y que el contexto no
   quede colgando después de la transacción.
5. Que la restricción de exclusión `asientos_no_duplicados` **exista** y que
   `btree_gist` esté instalada.
6. Que la vista `vw_agencias_publicas` exponga solo tres columnas.

El punto 5 es el que protege la garantía de asientos. Si alguien borra la
restricción "para desbloquear una venta", la aplicación sigue funcionando y dos
personas terminan con el mismo asiento. El comando falla explícitamente.

### 4.2 La extensión de la exclusión

La migración de asientos hace `CREATE EXTENSION IF NOT EXISTS btree_gist`. El rol
de la aplicación **necesita permiso para crearla** la primera vez.

Si no lo tiene:

```sql
-- como superusuario, una sola vez
CREATE EXTENSION IF NOT EXISTS btree_gist;
GRANT ALL ON SCHEMA public TO agencia_app;
```

Sin esta extensión, la migración **falla en voz alta**. No deja la tabla sin
protección en silencio.

### 4.3 La salud del servicio

```bash
curl http://localhost:8000/api/v1/salud
# {"estado":"ok","servicio":"agencia-viajes"}
```

Esta ruta no toca datos de negocio y no necesita sesión.

---

## 5. Las pruebas

```bash
cd apps/agencia-viajes/api
composer install
php artisan test
```

O por suite:

```bash
php artisan test --testsuite=Aislamiento
php artisan test --testsuite=Asientos
php artisan test --testsuite=Boleto
php artisan test --testsuite=Pagos
php artisan test --testsuite=Unit
```

### Las pruebas exigen PostgreSQL de verdad

**No corran contra SQLite.** El aislamiento está implementado con Row Level
Security, que es una función de PostgreSQL: contra SQLite, las pruebas de
aislamiento pasarían **sin comprobar nada**.

Además, la restricción de exclusión necesita la extensión `btree_gist`, que
SQLite no tiene.

### Base de datos de pruebas

```sql
CREATE DATABASE agencia_viajes_test;
```

Y en `.env`:

```
DB_DATABASE=agencia_viajes_test
```

**Estado actual:** las pruebas están escritas pero **no se ejecutaron**. En el
entorno donde se escribió el código no había PHP, ni PostgreSQL, ni Docker. Ver
[10](10-PRUEBAS-Y-ESTADO.md).

---

## 6. Problemas frecuentes

| Síntoma | Causa probable |
|---|---|
| `app_tenant_id()` lanza excepción en una petición | Falta `ResolverTenant`, o la operación salió de la transacción. Revisar `bootstrap/app.php`. |
| "La conexión de base de datos no tiene RLS" | El rol tiene `BYPASSRLS`. Corregir los permisos del rol. |
| La migración de asientos falla | El rol no puede crear `btree_gist`. Ver 4.2. |
| El error de RLS sale con un 500 feo | Falta el manejador de `bootstrap/app.php`, o el `APP_DEBUG` está en `true` en producción. |
| El candado de Redis no responde | **No es un error de aislamiento.** Redis es una optimización. La garantía es el índice de exclusión. Revisar los logs. |
| 409 al tomar un asiento | Alguien más lo tomó. Es el comportamiento correcto. |

---

## 7. Qué hay que leer antes de tocar el despliegue

| Documento | Por qué |
|---|---|
| [05](05-CONFIGURACION.md) | Qué hay que configurar **antes de operar**. Sin políticas, cancelar sale rechazado. |
| [07](07-SEGURIDAD-Y-LEY-19628.md) | Cómo se tratan los documentos de identidad. |
| [08](08-RESPALDOS.md) | Cómo se respalda y cómo se restaura. |
| [10](10-PRUEBAS-Y-ESTADO.md) | Qué está probado y qué no. |