# 01 - Arquitectura

> PostgreSQL corre con dos roles: uno de arranque y otro de aplicación. Solo el
> de aplicación conecta con el sistema, y es el que las políticas de
> aislamiento pueden alcanzar. Ver [03](03-MULTITENANCY-Y-ROLES.md).

Este documento explica cómo está construida la aplicación y, sobre todo, por
qué se eligieron algunas cosas y otras no.

---

## 1. El problema real de una agencia

Una agencia de viajes vive de dos cosas: **vender el pasaje** y **no fallar
con el pasajero**. Lo segundo es lo que duele.

En venta de pasajes en línea, la causa número uno de problemas en el mostrador
del aeropuerto es que **el nombre del boleto no coincide con el documento del
pasajero**. Si el pasajero escribió "Rodrigo" y su pasaporte dice "Rodrigo
Andrés Pérez", el boleto sale con "Rodrigo" y en el mostrador no lo dejan
abordar: el pasajero no puede viajar, y el costo lo paga la agencia, que le
compró el pasaje.

El segundo problema, más caro: **el asiento**. Dos personas tomando el mismo
asiento en un vuelo lleno. Si el asiento no se bloquea cuando alguien compra,
el conflicto aparece.

Estos dos problemas son los que la aplicación existe para resolver. Todo lo
demás es la maquinaria que los sostiene.

---

## 2. Las dos piezas que resuelven los dos problemas

| Problema | Cómo se resuelve | Dónde está |
|---|---|---|
| El nombre del boleto no coincide con el documento | El boleto se arma **desde el documento**, y un agente revisa la correspondencia antes de emitir | `App\Services\Viajes\ConstruccionBoleto` |
| Dos compras toman el mismo asiento | Un **índice de exclusión** de PostgreSQL sobre `(vuelo_id, numero)` | migración `2026_02_10_000006` |

Ninguna de las dos se resuelve "en la interfaz". Las dos están en la capa donde
el error no se puede esconder.

---

## 3. Arquitectura general

```
   Navegador
       │  (misma origen: el servidor Nuxt)
       ▼
   ┌──────────────────────────────────────────┐
   │  Nuxt (SSR) — capa web                   │
   │  "backend for frontend"                  │
   │  Es el único que tiene la cookie         │
   └──────────────────┬───────────────────────┘
                      │  HTTP interno
                      ▼
   ┌──────────────────────────────────────────┐
   │  Laravel — API                           │
   │  ResolverTenant (contexto de agencia)    │
   │  AuditarAcceso (bitácora)                │
   │  Policies (segundo filtro)               │
   └──────┬────────────────────┬──────────────┘
          │                    │
          ▼                    ▼
   ┌────────────┐      ┌──────────────┐
   │ PostgreSQL │      │    Redis     │
   │ RLS        │      │ cache, colas │
   │ exclusión  │      │ candado de   │
   │            │      │ contención   │
   └────────────┘      └──────────────┘
```

### Arquitectura BFF

La interfaz es un *backend for frontend*: el navegador **nunca** habla directo
con la API de Laravel.

El motivo es concreto. La sesión vive en una cookie `HttpOnly` + `Secure` +
`SameSite=Lax`. Si el navegador llamara a la API (otro origen), la cookie no
viajaría y habría que resolverlo con CORS y credenciales, que duplica el riesgo
de CSRF. Al pasar todo por un mismo origen, la cookie se mantiene en un solo
lugar y el token CSRF viaja como cabecera `X-XSRF-TOKEN`.

El proxy es `web/server/api/[...ruta].ts`. Reenvía solo tres cabeceras del
navegador (`cookie`, `X-XSRF-TOKEN` y `X-Motivo-Consulta`) y descarta todo lo
demás.

---

## 4. El nombre del boleto

### Qué hace el sistema

Toma el nombre del **documento** y lo pone en el boleto. Punto.

### Qué NO hace, y por qué

No "normaliza" nombres. No quita el segundo nombre. No invierte el orden. No
sabe cuál de las dos grafías es la correcta, y **adivinar es exactamente lo que
produce el problema**.

Si el documento dice "Rodrigo Andrés Pérez", el boleto dice "Rodrigo Andrés
Pérez". El nombre que el pasajero escribió queda guardado aparte, como
`nombre_declarado`, y no va al boleto.

### La frontera: el sistema avisa, el agente decide

El sistema compara lo declarado con lo del documento, usando los criterios que
la agencia configuró, y deja el resultado **pendiente de revisión humana**.

**La comparación automática nunca verifica sola.** Que coincidan las cadenas no
significa que el pasajero viaje con el nombre correcto: puede estar escrito con
un guion, con un segundo nombre abreviado, o con el orden invertido. Solo una
persona, que puede mirar el documento y llamar a la aerínea, puede decidir.

Por eso:

- No existe ningún endpoint que escriba `nombre_boleto` a mano.
- El nombre del boleto es una **copia** del documento, no una referencia (ver
  sección 5).
- La verificación guarda quién, cuándo y qué observó
  (`verificado_por`, `verificado_en`, `observacion_verificacion`).
- Emitir una reserva exige que **todos** los pasajeros tengan el nombre
  verificado. Sin eso, la máquina de estados rechaza la emisión.

### El caso del pasajero con varios documentos

Un pasajero **puede tener varios viajes con distintos documentos**. No es una
excepción: es el caso normal de alguien que renueva su pasaporte.

Por eso el documento **no es una columna del pasajero**. Es una tabla aparte
(`documentos_viaje`) con historial, y cada reserva apunta al documento concreto
de ese viaje. Si fuera una columna, la renovación sobrescribiría el dato y el
boleto de junio quedaría apuntando al documento de diciembre.

---

## 5. Por qué el nombre del boleto es una copia

`reserva_pasajeros.nombre_boleto` es una **copia** del nombre del documento en
el momento de armar el boleto, no una referencia a `documentos_viaje`.

El boleto ya salió. Si mañana el pasajero actualiza su documento, el boleto que
se emitió ayer tiene que seguir diciendo lo que decía ayer. Con una referencia
sola, el boleto de ayer aparecería hoy con un nombre que **nunca se compró**.

Cuesta un poco de espacio duplicado y compra una garantía: el boleto es un
hecho, y un hecho no cambia porque cambien los datos de origen.

---

## 6. Bloqueo de asientos: el mecanismo y su justificación

Este es el módulo de concurrencia del producto.

### El problema

Dos compras simultáneas no pueden tomar el mismo asiento.

### La solución elegida: índice de exclusión de PostgreSQL

```sql
ALTER TABLE asientos ADD CONSTRAINT asientos_no_duplicados
    EXCLUDE USING gist (vuelo_id WITH =, numero WITH =)
    WHERE (estado IN ('bloqueado', 'asignado'));
```

Es una regla que PostgreSQL aplica **dentro de la transacción**, en el momento
del `INSERT` o del `UPDATE`.

### Por qué no un candado de Redis como garantía

La solución obvia es un candado en Redis (`SET NX`). Se evaluó y se descartó
**como garantía**, por cuatro razones concretas:

**1. Separación entre el candado y la venta.**
Con un candado en Redis, el candado y el `INSERT` del asiento viven en sistemas
distintos. Redis no conoce las transacciones de PostgreSQL. Si el proceso muere
entre "tomé el candado" y "inserté el asiento", el candado expira por TTL y el
asiento queda libre: pasa. Si muere **después** de insertar y antes de confirmar,
la transacción se revierte pero el candado sigue tomado: el asiento queda
apretado hasta que expire el TTL. Ninguno de los dos casos es un conflicto de
asientos, pero los dos son reclamos del cliente.

**2. Un TTL es una garantía que depende del reloj.**
Si Redis se reinicia con los datos en disco, o si la red se parte entre la app y
Redis, la respuesta a "¿este asiento está tomado?" puede ser "no" cuando es
"sí". Un vuelo lleno es exactamente el peor escenario.

**3. No se puede consultar el estado sin escribir.**
Para mostrar el mapa de asientos hay que responder si *cada* asiento está
libre. Con un candado de Redis habría que preguntar a Redis por cientos de
asientos, o mantener un segundo estado en la base que puede quedar
desincronizado. Con el índice de exclusión, el estado está en la fila y la
pantalla se arma con un `SELECT`.

**4. El índice de exclusión no tiene ventana.**
La exclusión se evalúa dentro de la transacción: no existe el intervalo entre
"comprobar que está libre" y "marcarlo como tomado", porque no hay dos pasos,
hay uno. Dos transacciones que intenten tomar el mismo asiento se serializan
solas; una entra y la otra recibe `23P01`.

### Entonces, ¿para qué sirve Redis?

Redis se usa, pero **como optimización, nunca como garantía**:

- Un **candado de serie por vuelo** (`agencia:asientos:serie:{vuelo_id}`) que
  serializa las compras del mismo vuelo y reduce la tasa de colisiones. Hace
  el sistema más rápido y más barato para la base, pero si Redis no está, la
  operación **sigue siendo correcta**.
- `SELECT ... FOR UPDATE` sobre la fila del asiento dentro de la transacción,
  para que la lectura y la escritura sean coherentes.

`ServicioAsientos` funciona con y sin Redis, y el resultado nunca depende del
candado: el candado dice "espera un momento", y **la base dice si el asiento se
pudo tomar**.

### El bloqueo provisional y su TTL

Un asiento en estado `bloqueado` tiene `bloqueado_hasta`. Al vencerse, el
bloqueo deja de ser un asiento tomado y pasa a ser uno que nadie está mirando.

**El TTL no es la garantía de que un asiento no se venda dos veces.** La
garantía es el índice de exclusión. El TTL existe para que un bloqueo olvidado
no apriete un asiento hasta el día del vuelo.

### Verificar que la garantía sigue puesta

`php artisan viajes:verificar-aislamiento` comprueba que la restricción
`asientos_no_duplicados` existe y que `btree_gist` está instalada. Si alguien
borra la restricción "para desbloquear una venta", la aplicación sigue
funcionando y dos personas terminan con el mismo asiento. El comando falla
explícitamente.

---

## 7. El saldo se calcula, no se guarda

No existe una columna `estado_pago` en `reservas`. El estado sale de comparar el
total con la suma de los pagos.

El error clásico no es que no se registre el pago: es que el sistema tenga un
campo "estado de pago" que alguien se olvide de actualizar. El sistema anuncia
"pagada" y en realidad le deben la mitad; o al revés, la agencia cree que debe
y el pasajero ya pagó.

Todos los montos son **enteros en centimos**. La coma flotante en dinero
produce diferencias de un peso entre el total, lo pagado y el saldo, y esa
diferencia es el tipo de descuadre que nadie encuentra después.

---

## 8. Una reserva no se borra: pasa por estados

```
   pendiente ──▶ confirmada ──▶ emitida ──▶ usada
        │             │
        └─────────────┴────────▶ cancelada
```

Borrar una reserva destruye el historial de la agencia: deja de constar que hubo
una venta, quién la hizo, cuánto se pagó y qué pasó con el pasaje.

Por eso:

- `Reserva` **prohíbe** `delete()` con un error que dice qué hacer en su lugar.
- No hay `deleted_at`.
- Todo cambio de estado pasa por `MaquinaDeEstadosReserva`, que escribe una fila
  en `reserva_eventos` **dentro de la misma transacción** que el cambio. Si el
  historial no se puede escribir, el cambio tampoco: un cambio de estado sin
  rastro es peor que un cambio que no ocurrió.
- `usada` y `cancelada` son terminales. No hay transiciones de salida.

---

## 9. Lo que NO se inventó

Estas dos cosas cambian por agencia, por proveedor y por temporada. Escribirlas
en el código sería inventar un acuerdo comercial que nadie acordó:

| Cosa | Qué hace el sistema |
|---|---|
| **Plazos de cancelación** | No hay ninguno en el código. Viven en `politicas_cancelacion` y los escribe la agencia. Si un producto no tiene política, la cancelación **se rechaza** con un mensaje que lo dice. No se asume costo cero. |
| **Reglas de nombres** | No hay ninguna en el código. Viven en `politicas_nombres`. Si no hay política, el boleto se arma con el texto del documento separado por un espacio y queda **pendiente** de revisión. No se aplica ninguna transformación. |

El comportamiento cuando falta configuración es siempre el prudente, y siempre
**falla cerrado** en vez de rellenar el hueco con un supuesto.

---

## 10. Decisiones que quedaron pendientes

Estas no están resueltas, están anotadas:

1. **El slug de la agencia es enumerable antes del login.** Como aparece en la
   URL de entrada, alguien puede descubrir que una agencia usa el sistema. No
   puede ver más que su nombre. Si se considera inaceptable, la alternativa es
   dejar el campo en blanco hasta el login y resolver siempre por dominio de
   correo. Ver [03](03-MULTITENANCY-Y-ROLES.md).

2. **El característica del paquete sin fecha de salida.** Para calcular la
   anticipación de una cancelación, el sistema necesita una fecha. Un paquete
   sin vuelo no la tiene, y el sistema lo rechaza en vez de suponer. La
   agencia puede agregar una fecha al producto.

3. **Hotel sin mapa de habitaciones.** El módulo de concurrencia está hecho
   para asientos. Un hotel necesita el mismo problema resuelto para
   habitaciones, y no está: no hay productos de hotel con inventario en esta
   versión.

---

## 11. Índice

| Documento | Qué cubre |
|---|---|
| [02](02-MODELO-DE-DATOS.md) | Las tablas, las decisiones de diseño, el dinero |
| [03](03-MULTITENANCY-Y-ROLES.md) | Aislamiento entre agencias, roles, Authentik |
| [04](04-INSTALACION.md) | Cómo levantarlo |
| [05](05-CONFIGURACION.md) | Qué hay que configurar antes de operar |
| [06](06-PRIMER-PRODUCTO.md) | La agencia de ejemplo, paso a paso |
| [07](07-SEGURIDAD-Y-LEY-19628.md) | Datos personales, CSRF, auditoría |
| [08](08-RESPALDOS.md) | Respaldo y restauración |
| [09](09-API.md) | Los endpoints |
| [10](10-PRUEBAS-Y-ESTADO.md) | Qué se probó y qué no |