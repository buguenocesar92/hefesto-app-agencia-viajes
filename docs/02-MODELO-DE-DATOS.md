# 02 - Modelo de datos

Dieciocho tablas. Este documento explica qué es cada una y, sobre todo, **por
qué está así**: qué decisión de diseño esconde cada una.

---

## 1. El mapa en una mirada

```
   agencias  (tenant, SIN RLS)
      │
      ├── usuarios                 agentes de la agencia
      ├── proveedores              aerolíneas, hoteles, mayoristas
      ├── politicas_nombres        cómo se imprime el nombre en el boleto
      ├── configuraciones         interruptores de la agencia
      ├── bitacora_accesos         quién miró qué dato personal
      │
      ├── productos ──────────┐    el trayecto, el hotel, el paquete
      │                       │
      ├── tarifas ───────────┤    precio por producto y fecha
      │                       │
      ├── vuelos              │    UNA FECHA CONCRETA de salida
      │   └── asientos        │    con índice de exclusión
      │                       │
      ├── politicas_cancelacion ── plazos y costos, escritos por la agencia
      │                       │
      ├── pasajeros ◄────────┘
      │   └── documentos_viaje    UN documento por fila
      │
      └── reservas ──┬── reserva_pasajeros   el nombre del boleto, copiado
                     ├── reserva_eventos     historial de estados
                     ├── pagos               pagos y anulaciones
                     └── asientos
```

Las tablas con RLS están marcadas en la migración
`2026_02_10_000017_activar_row_level_security.php`. `agencias` es la única que
no lo lleva, a propósito. Ver [03](03-MULTITENANCY-Y-ROLES.md).

---

## 2. `agencias`: el tenant

La agencia es el tenant. No lleva RLS porque se consulta durante el login, cuando
todavía no hay sesión y por lo tanto no hay `app.tenant_id` que poner en la
conexión. Se compensa con la vista `vw_agencias_publicas` (solo `slug`, `nombre`,
`activo`) y con que el rol de la base no sea superusuario.

`dominio_correo` es el vínculo con Authentik: del dominio del correo se deduce a
qué agencia pertenece la persona que entra. Está en `$hidden` del modelo: es un
dato de contacto de la agencia y no lo necesita el frontend.

---

## 3. `productos` y `vuelos`: el catálogo y las fechas

**Un producto es el trayecto. Una fecha concreta es un vuelo.**

El producto "Santiago - São Paulo, Aerolíneas del Sur" es **uno solo**. De él
salen muchas filas en `vuelos`: el vuelo del lunes, el del martes, el de marzo.

Cada vuelo tiene **su propia tabla de asientos**. Por eso la reserva apunta a
`vuelo_id` y no a `producto_id`: si apuntara al producto, dos personas que viajan
el mismo trayecto en fechas distintas compartirían asientos.

```
   productos (id = "Santiago - São Paulo")
      │
      ├── vuelos (SK404, sale el 14 de marzo)  → asientos 14A, 14B, ...
      ├── vuelos (SK406, sale el 15 de marzo)  → asientos 22C, 22D, ...
      └── vuelos (SK404, sale el 20 de abril)  → asientos 08F, 08G, ...
```

El tipo del producto (`vuelo`, `hotel`, `paquete`) decide una sola cosa
concreta: si la reserva necesita asiento. Solo `vuelo` los usa.

---

## 4. `asientos`: el módulo de concurrencia

| Columna | Para qué |
|---|---|
| `vuelo_id` + `numero` | Identifican el asiento. `numero` es case-sensitive: "14A" no es "14a", porque la aerolínea distingue mayúsculas. |
| `estado` | `disponible`, `bloqueado`, `asignado`, `liberado`, `no_disponible`. |
| `fila_orden` + `columna_numero` | Para ordenar el mapa **sin parsear "14A" en PHP**. Parsear en PHP es donde aparecen los errores: como texto, "14A" va después de "2C". |
| `bloqueado_hasta` | El bloqueo provisional vence solo. |

### La restricción que sostiene todo

```sql
ALTER TABLE asientos ADD CONSTRAINT asientos_no_duplicados
    EXCLUDE USING gist (vuelo_id WITH =, numero WITH =)
    WHERE (estado IN ('bloqueado', 'asignado'));
```

Solo los estados que **ocupan** compiten entre sí. Un asiento `liberado` deja de
apretar al siguiente: se puede reutilizar y queda el rastro del uso.

`btree_gist` es la extensión que hace posible un índice de exclusión sobre
columnas `uuid`. La migración la instala; si el rol no puede crearla, la
migración **falla en voz alta** en vez de dejar la tabla sin protección.

La justificación completa de por qué esto y no un candado de Redis está en
[01, sección 6](01-ARQUITECTURA.md).

---

## 5. `pasajeros` y `documentos_viaje`: la separación que evita el problema

**El documento NO es una columna del pasajero.**

Un pasajero puede viajar en junio con el pasaporte A, renovarlo, y viajar en
diciembre con el pasaporte B. Si el documento fuera una columna, la segunda vez
se sobrescribiría la primera y el boleto de junio quedaría apuntando al
documento equivocado.

Entonces:

- `pasajeros.nombre_declarado` es **cómo el pasajero se escribe a sí mismo**.
  Puede venir abreviado ("Rodrigo"). **No es el nombre del boleto.**
- `documentos_viaje` tiene una fila por documento, con historial, y guarda el
  texto **tal como está**, sin transformarlo.
- `es_principal` marca cuál se propone por defecto. Que sea el más reciente no
  significa que sea el correcto para el viaje.

### `numero` es el campo más sensible de la base

Dato personal bajo la Ley 19.628. Está en `$hidden` del modelo y
`DocumentoViajeResource` lo devuelve **enmascarado**. El índice es único por
`(tenant_id, tipo, numero)`: el mismo número puede existir en dos agencias sin
que se mezclen.

Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

---

## 6. `reservas`, `reserva_pasajeros`, `reserva_eventos`

### `reservas`: la venta

`folio` es único **por agencia**, no global. El índice es compuesto a propósito:
dos agencias pueden tener "V-000001" cada una, y el número del folio no revela
nada de la otra.

**No existe `deleted_at`** y el modelo prohíbe `delete()`. Para deshacer una
venta se cancela, y la cancelación queda registrada con su fecha, su motivo y
su costo.

**No existe `estado_pago`.** El estado de cuenta se calcula. Ver
[01, sección 7](01-ARQUITECTURA.md).

### `reserva_pasajeros`: la bisagra entre la persona y el viaje

Es la tabla que resuelve "la misma persona viaja muchas veces con distintos
documentos".

| Columna | Significado |
|---|---|
| `pasajero_id` | Quién es la persona. |
| `documento_viaje_id` | Con qué documento viaja **en esta reserva**. |
| `nombre_boleto` | **Copia** del nombre del documento en el momento de emitir. |
| `estado_verificacion_nombre` | Qué hizo un agente con ese nombre. |
| `verificado_por`, `verificado_en` | Quién y cuándo lo revisó. |
| `observacion_verificacion` | Qué encontró o qué dejó asentado. |

`nombre_boleto` es una copia y no una referencia **a propósito**: el boleto ya
salió. Si mañana el pasajero actualiza su documento, el boleto de ayer tiene que
seguir diciendo lo que decía ayer.

### `reserva_eventos`: el historial

Se escribe **dentro de la misma transacción** que el cambio de estado. Si el
historial no se puede escribir, el cambio tampoco: un cambio de estado sin
rastro es peor que un cambio que no ocurrió.

---

## 7. `pagos`: el estado de cuenta

**Los pagos no se editan ni se borran.** Se anulan (`anulado = true`, con motivo
y fecha) y se vuelve a registrar. Así el estado de cuenta siempre se puede
reconstruir mirando la historia, que es lo que hace falta cuando alguien
reclama.

`monto_centimos` es un entero. Un pago parcial de 45.000 pesos son 4.500.000 de
centimos. **Nunca coma flotante**: la suma de flotantes produce diferencias de
un peso, y esa diferencia es el descuadre que nadie encuentra después.

---

## 8. `politicas_nombres` y `politicas_cancelacion`: lo que la agencia escribe

Estas dos tablas están **vacías a propósito** en una instalación nueva.

| Si falta... | Qué hace el sistema |
|---|---|
| La política de cancelación del producto | La cancelación **se rechaza** con un mensaje que lo dice. No se asume costo cero. |
| La política de nombres del proveedor | El boleto se arma con el texto del documento, separado por un espacio, y queda **pendiente** de revisión. No se aplica ninguna transformación. |

`politicas_nombres` se busca **por proveedor**, que es como las agencias
realmente organizan estas cosas: una aerolínea exige una cosa y otra, otra.

Los tres criterios `exigir_tildes`, `exigir_mayusculas` e
`ignorar_nombres_compuestos` son de **comparación**, no de impresión. El texto
que sale en el boleto es siempre el del documento.

`politicas_cancelacion` guarda un **escalón por fila**, y el sistema elige el de
mayor anticipación que no supere los minutos que faltan para salir. Se guardan en
**minutos** y no en días porque hay tarifas con plazos de horas.

Ver [05](05-CONFIGURACION.md).

---

## 9. `configuraciones` y `bitacora_accesos`

`configuraciones` tiene tres interruptores, todos en el valor prudente:

| Interruptor | Por defecto | Por qué existe la columna |
|---|---|---|
| `mostrar_documento_en_listados` | `false` | **No debería cambiar nunca.** Existe para que la decisión esté a la vista y se haya tomado a conciencia, no por descuido. |
| `exigir_verificacion_nombre` | `true` | Sin verificar, no se emite. |
| `exigir_motivo_lectura_documento` | `true` | Leer el documento es el acceso sensible. |

`bitacora_accesos` registra **quién accederá a qué dato personal y por qué**.
Guarda `accion`, `recurso`, `recurso_id`, `motivo`, `ip`, `agente`, y `detalle`
en `jsonb`.

**No guarda el valor del dato.** Meter el documento en el registro de auditoría
sería crear un segundo lugar donde vive el dato personal, sin los controles del
primero.

---

## 10. `bitácora` de decisiones de diseño

| Decisión | Alternativa descartada | Por qué |
|---|---|---|
| `tenant_id` + RLS | Esquema por agencia | Reutilizado de `portal-autoservicio`; una migración, un pool. Ver [03](03-MULTITENANCY-Y-ROLES.md). |
| Índice de exclusión | Candado de Redis | Sin ventana entre comprobar y tomar. Ver [01 §6](01-ARQUITECTURA.md). |
| Saldo calculado | Columna `estado_pago` | Un campo guardado se queda viejo. Ver [01 §7](01-ARQUITECTURA.md). |
| Estados en vez de borrado | `deleted_at` | Borrar destruye el historial. Ver [01 §8](01-ARQUITECTURA.md). |
| Documento en tabla aparte | Columna en `pasajeros` | Un pasajero viaja con distintos documentos. |
| `nombre_boleto` copiado | Referencia al documento | El boleto ya salió; es un hecho. |
| Dinero en centimos | Decimales | La coma flotante produce descuadres de un peso. |
| `conteo` de filas sin caché | Cache de Redis | El mapa debe mostrar lo que la base garantiza. |