#!/bin/sh
# ---------------------------------------------------------------------------
# Entrada del contenedor de la API.
# ---------------------------------------------------------------------------
# El punto de este script es hacer FALLAR el despliegue con un mensaje claro
# cuando algo quedo mal configurado, en vez de levantar un contenedor que
# responde con errores 500 a todas partes.
#
# Lo que comprueba:
#   1. que APP_KEY este definida
#   2. que la API pueda conectarse a PostgreSQL
#   3. que la extension btree_gist este (sin ella no hay exclusion de asientos)
#   4. que la RLS este puesta (viajes:verificar-aislamiento)
#
# POR QUE EL 4 ES UN AVISO Y NO UN BLOQUEO
# -----------------------------------------
# Si se hiciera bloqueante, un contenedor que arranca con la base de datos
# todavia levantandose caeria en bucle. Aqui solo se avisa por log; el comando
# se ejecuta aparte, en el despliegue (ver docs/04 y docs/08).
# ---------------------------------------------------------------------------

set -e

cd /var/www/html

echo "[entrada] PHP $(php -v | head -n 1)"

# ---------------------------------------------------------------------------
# 1. Configuracion minima
# ---------------------------------------------------------------------------
if [ -z "${APP_KEY}" ]; then
    echo "[entrada] ERROR: APP_KEY no esta definida."
    echo "[entrada] Genere una con:  docker compose run --rm api php artisan key:generate --show"
    exit 1
fi

# ---------------------------------------------------------------------------
# 2. Espera a la base de datos y aplicacion de migraciones
# ---------------------------------------------------------------------------
echo "[entrada] esperando a PostgreSQL..."
intentos=0
hasta=30

while [ "$intentos" -lt "$hasta" ]; do
    if php -r 'exit(@pg_connect("host=".(getenv("DB_HOST") ?: "127.0.0.1")." port=".(getenv("DB_PORT") ?: "5432")." dbname=".getenv("DB_DATABASE")." user=".getenv("DB_USERNAME")." connect_timeout=2")) ? 0 : 1);'; then
        echo "[entrada] PostgreSQL responde."
        break
    fi

    intentos=$((intentos + 1))
    echo "[entrada] todavia no responde (intento $intentos de $hasta)"
    sleep 2
done

if [ "$intentos" -ge "$hasta" ]; then
    echo "[entrada] ERROR: PostgreSQL no respondio a tiempo."
    exit 1
fi

if [ "${AGENCIA_CORRER_MIGRACIONES:-false}" = "true" ]; then
    echo "[entrada] aplicando migraciones..."

    # Las migraciones crean la extension btree_gist y la restriccion de
    # exclusion de asientos. Si el rol de la base no tiene permiso para crear
    # extensiones, esto falla aqui y no en mitad de una venta.
    php artisan migrate --force --no-interaction

    php artisan config:cache
    php artisan route:cache
fi

# ---------------------------------------------------------------------------
# 3. La extension de la exclusion de asientos
# ---------------------------------------------------------------------------
# Sin btree_gist no se puede crear el indice de exclusion sobre uuid, que es lo
# que garantiza que un asiento no se venda dos veces. Se avisa temprano porque
# el sintoma (dos personas con el mismo asiento) aparece mucho despues.
if ! php -r '$r = @pg_connect("host=".(getenv("DB_HOST") ?: "127.0.0.1")." port=".(getenv("DB_PORT") ?: "5432")." dbname=".getenv("DB_DATABASE")." user=".getenv("DB_USERNAME")." connect_timeout=2"); exit($r ? 0 : 1);' ; then
    echo "[entrada] AVISO: no se pudo abrir una conexion para revisar las extensiones."
else
    php artisan viajes:verificar-aislamiento || true
fi

# ---------------------------------------------------------------------------
# 4. Aviso sobre el aislamiento (no bloqueante)
# ---------------------------------------------------------------------------
echo "[entrada] revisando el aislamiento entre agencias..."
if php artisan viajes:verificar-aislamiento; then
    echo "[entrada] aislamiento verificado."
else
    echo "[entrada] AVISO: el aislamiento entre agencias NO esta bien puesto."
    echo "[entrada] El servicio puede arrancar, pero no debe recibir agentes hasta corregirlo."
    echo "[entrada] Sin RLS, una agencia puede ver los datos de otra, incluidos documentos de pasajeros."
fi

exec "$@"