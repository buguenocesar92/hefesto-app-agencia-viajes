# Agencia de Viajes

Aplicación propia de Hefesto Studio para **agencias de viajes**: venta de
pasajes, reservas, documentación de viaje, bloqueo de asientos y estado de
cuenta.

Software original. No es un kit de despliegue de una herramienta de terceros.

---

## El problema que resuelve

Una agencia vive de dos cosas: vender el pasaje y **no fallar con el
pasajero**. Lo segundo es lo que duele.

**El nombre del boleto tiene que coincidir con el documento del pasajero,
validado antes de emitir.** En venta de pasajes en línea, que no coincidan es la
causa número uno de problemas en el mostrador del aeropuerto: el pasajero no
puede viajar y el costo lo paga la agencia.

El segundo problema, más caro: **el asiento**. Dos personas tomando el mismo
asiento en un vuelo lleno, porque el asiento no se bloqueó cuando alguien
compró.

Esta aplicación existe para que esas dos cosas no pasen.

---

## Qué hace, en concreto

### El nombre del boleto sale del documento

Si el pasajero escribió "Rodrigo" y su pasaporte dice "Rodrigo Andrés Pérez
Muñoz", el boleto sale con **"Rodrigo Andrés Pérez Muñoz"**.

El sistema **no inventa reglas de transformación de nombres**. Las reglas las
configura la agencia, y la correspondencia entre lo declarado y lo del documento
**la revisa un agente**. Emitir una reserva con nombres sin verificar **no se
puede**.

### El asiento no se puede vender dos veces

La garantía es un **índice de exclusión de PostgreSQL** sobre
`(vuelo_id, numero)`, en la misma transacción que la venta. Redis se usa para
reducir colisiones, pero **no es la garantía**: si Redis no está, la operación
sigue siendo correcta.

La justificación completa está en [docs/01](docs/01-ARQUITECTURA.md).

### El pasaje pagado a medias se ve

El saldo **se calcula**, no se guarda. No hay una columna "estado de pago" que
alguien pueda olvidar de actualizar. Hay una pantalla que lista todo lo que está
pendiente.

### Una reserva no se borra

Pasa por estados: pendiente, confirmada, emitida, usada, cancelada. Todo cambio
deja rastro en `reserva_eventos`. Borrar destruiría el historial de la agencia.

### El pasajero puede tener varios documentos

Un pasajero que renueva su pasaporte tiene **dos documentos**, no uno. Cada viaje
usa el documento de ese viaje. Por eso el documento no es una columna del
pasajero.

---

## Lo que NO se inventó

| Cosa | Qué hace el sistema |
|---|---|
| **Plazos de cancelación** | No hay ninguno en el código. Los escribe la agencia. Si un producto no tiene política, la cancelación **se rechaza**: el sistema no asume costo cero. |
| **Reglas de nombres** | No hay ninguna en el código. Las configura la agencia. Si no hay política, el boleto sale con el texto del documento y queda **pendiente** de revisión. |

En los dos casos, el sistema **falla cerrado** en vez de rellenar el hueco con un
supuesto.

---

## Los datos de los pasajeros

Un pasajero es una persona natural y su documento de identidad es el dato más
sensible que maneja esta aplicación (**Ley 19.628**).

| Medida | Qué hace |
|---|---|
| **El documento no sale en los listados** | Enmascarado (`P12*****`), en el Resource, no solo en el modelo |
| **Un solo endpoint lo muestra completo** | Exige motivo escrito y queda en la bitácora |
| **Auditoría de acceso** | Se registra quién vio qué dato y por qué, no solo quién lo cambió |
| **Aislamiento por agencia** | Row Level Security: la base de datos lo impide, no la aplicación |
| **Permisos mínimos** | Ver un listado y leer un documento son habilidades separadas |

Ver [docs/07](docs/07-SEGURIDAD-Y-LEY-19628.md).

---

## Estado: código escrito, sin ejecutar

En el entorno donde se escribió esto **no había PHP, ni Composer, ni
PostgreSQL, ni Docker**.

Lo que **sí** se ejecutó: `bash -n` sobre el script de entrada, y la validación
de los archivos de configuración.

Lo que **no** se ejecutó: las migraciones, las pruebas, el comando de
verificación del aislamiento y la aplicación.

[docs/10](docs/10-PRUEBAS-Y-ESTADO.md) separa, sin suavizar, lo comprobado de lo
que no.

---

## Cómo se levanta

```bash
cd apps/agencia-viajes
cp .env.example .env
# generar los secretos con openssl rand (ver docs/04)

docker compose -f deploy/docker-compose.dev.yml --env-file .env up -d
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan migrate --seed
```

Antes de dejar entrar a alguien:

```bash
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan viajes:verificar-aislamiento
```

**Ese comando tiene que devolver 0.** Comprueba el rol de la base, las 16 tablas
con RLS, la función de contexto, la lectura aislada, el índice de exclusión de
asientos y la vista pública. Si devuelve 1, no se abre al público.

---

## Estructura

```
apps/agencia-viajes/
├── README.md
├── .env.example
├── docs/          01 arquitectura   06 primer producto
│                  02 modelo de datos 07 seguridad y Ley 19.628
│                  03 multitenancy    08 respaldos
│                  04 instalación     09 API
│                  05 configuración   10 pruebas y estado
├── api/           Laravel + PostgreSQL con RLS
│   ├── app/Services/Viajes/     el nombre del boleto, los asientos, los estados
│   ├── app/Services/Tenancy/    el contexto de agencia y la bitácora
│   ├── app/Services/Pagos/      el estado de cuenta
│   ├── database/migrations/     18 migraciones reales
│   └── tests/                   4 suites priorizadas + unitarias
├── web/           Nuxt con renderizado en el servidor
├── deploy/        Docker Compose con versiones fijadas, Caddy, Authentik
└── .env.example
```

---

## Aislamiento entre agencias

Columna `tenant_id` + **Row Level Security** de PostgreSQL, con `WITH CHECK`,
`FORCE ROW LEVEL SECURITY` y el contexto viajando con
`set_config(..., true)` (alcance de transacción).

Es el patrón ya decidido en
`apps/portal-autoservicio/docs/03-MULTITENANCY.md`, reutilizado y no inventado de
nuevo.

La aplicación es el **backend for frontend**: el navegador nunca habla directo
con la API.

---

## La documentación

| Documento | Qué cubre |
|---|---|
| [01 Arquitectura](docs/01-ARQUITECTURA.md) | Las decisiones y **la justificación del bloqueo de asientos** |
| [02 Modelo de datos](docs/02-MODELO-DE-DATOS.md) | Las tablas y qué decisión esconde cada una |
| [03 Multitenancy y roles](docs/03-MULTITENANCY-Y-ROLES.md) | El aislamiento y quién puede hacer qué |
| [04 Instalación](docs/04-INSTALACION.md) | Cómo levantarlo y qué comprobar |
| [05 Configuración](docs/05-CONFIGURACION.md) | **Qué hay que configurar antes de operar** |
| [06 Primer producto](docs/06-PRIMER-PRODUCTO.md) | La agencia de ejemplo, paso a paso |
| [07 Seguridad y Ley 19.628](docs/07-SEGURIDAD-Y-LEY-19628.md) | Los datos personales |
| [08 Respaldos](docs/08-RESPALDOS.md) | Respaldo y restauración |
| [09 API](docs/09-API.md) | Los endpoints |
| [10 Pruebas y estado](docs/10-PRUEBAS-Y-ESTADO.md) | **Qué se probó y qué no** |

---

## Stack

Laravel 13 y PostgreSQL 18 con RLS · Nuxt 4 con SSR · Redis para caché y colas ·
Authentik para identidad · Docker Compose con versiones fijadas.