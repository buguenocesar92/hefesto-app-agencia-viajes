# 08 - Respaldos

Un respaldo que no se ha restaurado es una suposición, no un respaldo.

---

## 1. Qué hay que respaldar

| Qué | Por qué |
|---|---|
| **La base de datos** | Reservas, pasajeros, documentos, pagos, asientos. Todo lo que no se puede recuperar. |
| El `.env` de producción | Sin él, sin `APP_KEY` no se pueden descifrar las cookies y sin claves de Authentik no hay ingreso. |
| Los volúmenes de Authentik | El proveedor de identidad: sin ellos, nadie entra. |
| La clave de Caddy (`caddy-datos`) | Los certificados TLS. Perderla es molesta, no grave. |

**Los volúmenes de la aplicación son de Docker, no de la aplicación.** En esta
versión la API no guarda archivos de pasajeros en disco: los documentos se
guardan como datos, en la base. Si más adelante se suben archivos, hay que
sumar ese volumen.

---

## 2. Respaldar la base de datos

### Desde el contenedor

```bash
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  exec -T postgres \
  pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" --format=custom --file=/tmp/respaldo.dump
```

Para sacarlo al host:

```bash
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  exec -T postgres cat /tmp/respaldo.dump >agency-respaldo-$(date +%F).dump
```

### El `--format=custom` importa

El formato propio de PostgreSQL (`-Fc`) comprime y permite restaurar tabla por
tabla. `pg_dumpall` en formato SQL plano genera un archivo mucho más grande y no
permite restauración parcial, que es justo lo que se necesita cuando se rompió
una tabla.

---

## 3. Los respaldos contienen datos personales

Esto es lo más importante de este documento.

Un respaldo de esta base **contiene el número de documento de identificación de
todas las personas que viajan con la agencia**. También sus nombres,
correos, teléfonos y fechas de nacimiento.

**Ley 19.628**, y sentido común:

| Medida | Por qué |
|---|---|
| **Cifrar el respaldo** | Si alguien encuentra el archivo, tiene los documentos de todos los pasajeros |
| **Control de acceso estricto** | El respaldo se guarda en un lugar con permisos, no en un escritorio |
| **No enviarlo por correo** | Un correo es un respaldo que viaja sin control |
| **Saber cuántos respaldos hay y dónde** | Un respaldo olvidado en un servidor antiguo es una fuga esperando |
| **Borrar los respaldos vencidos** | Guardarlos para siempre no agrega seguridad, agrega riesgo |
| **Probarla** | Encriptado que nunca se restauró es una pérdida de datos |

### Cifrado

```bash
# Crear (guarda la clave FUERA de este servidor)
openssl enc -aes-256-cbc -pbkdf2 -salt \
  -in agencia-respaldo-2026-03-15.dump \
  -out agencia-respaldo-2026-03-15.dump.enc

# Restaurar (desencriptar primero)
openssl enc -d -aes-256-cbc -pbkdf2 \
  -in agencia-respaldo-2026-03-15.dump.enc \
  -out agencia-respaldo-2026-03-15.dump
```

La clave **no va en el mismo lugar que el respaldo**. Si van juntos, el cifrado no
protege de nada.

**Limitación honesta:** el script de respaldo de este repositorio
(`deploy/api/docker/entrypoint.sh` y los compose) **no incluye el paso de
cifrado**. Hay que hacerlo en el sistema que corre el respaldo, o agregar el paso
explícitamente. Está anotado en [07](07-SEGURIDAD-Y-LEY-19628.md) como riesgo
abierto.

---

## 4. Restaurar

### En un servidor nuevo

```bash
# 1. Crear la base
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  exec -T postgres psql -U "$DB_USERNAME" -d postgres \
  -c "CREATE DATABASE agencia_viajes;"

# 2. Desencriptar (si aplica)

# 3. Restaurar
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  exec -T postgres pg_restore -U "$DB_USERNAME" -d agencia_viajes \
  --clean --if-exists < agencia-respaldo.dump

# 4. Verificar el aislamiento ANTES de abrir al público
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  run --rm api php artisan viajes:verificar-aislamiento
```

### El paso 4 no es opcional

Un respaldo restaurado puede venir con la RLS **desactivada**, o con una tabla
nueva que quedó sin política. La aplicación funciona igual y, al mismo tiempo,
está completamente abierta entre agencias.

`viajes:verificar-aislamiento` es la comprobación que lo detecta. Si devuelve
código 1, **no se abre al público**.

### Restaurar en la misma instalación

**Nunca con `migrate:fresh`.** Ese comando borra todas las tablas, y en
producción eso incluye las reservas de todos los clientes.

El orden correcto es: apagar la API, restaurar la base, verificar el
aislamiento, levantar la API.

---

## 5. Cuándo respaldar

| Frecuencia | Qué |
|---|---|
| **Cada noche** | La base completa |
| **Antes de migrar** | Una copia extra, en el servidor, por si la migración sale mal |
| **Antes de cambiar la configuración** | `.env.prod` y `configuraciones` |
| **Antes de restaurar** | Una copia de lo que hay, por si el respaldo estaba malo |

---

## 6. Probar el respaldo

Un respaldo que no se ha restaurado no es un respaldo: es una esperanza.

**Cada trimestre**, en un ambiente aislado:

1. Crear una base vacía.
2. Restaurar el último respaldo.
3. Correr `viajes:verificar-aislamiento`.
4. Contar reservas y passengers. ¿Cuadra con lo que había?
5. Abrir la interfaz con un agente real y revisar una reserva.

Si algo falla, ese es el momento de descubrirlo, no el día que se necesite.

---

## 7. Las medidas de la base de datos

Complementan PostgreSQL, no sustituyen al respaldo:

| Medida | Qué hace |
|---|---|
| **WAL archiving** | Replica cada cambio a otro servidor. Permite recuperar **hasta el minuto** en que algo se rompió. |
| **Instantánea diaria** | Copia completa cada noche. |
| **`pg_dump` diario** | Portable, y es lo que se prueba en la restauración. |

El WAL archiving es lo que de verdad salva una noche: si alguien borró una tabla a
las 14:00, el `pg_dump` de la madrugada anterior no alcanza, pero el WAL sí.

---

## 8. Resumen

```
Cada noche:
  pg_dump --format=custom  →  cifrar  →  guardar en lugar controlado

Antes de migrar:
  copia extra del .dump y del .env.prod

Cada trimestre:
  restaurar en un ambiente aislado y verificar el aislamiento

Cada vez:
  php artisan viajes:verificar-aislamiento
```

La última línea es la que importa más. Un respaldo restaurado sin verificar el
aislamiento es un respaldo que devuelve los datos de todos los clientes a todos
los clientes.