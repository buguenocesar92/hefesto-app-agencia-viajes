# 06 - Primer producto

Cómo se carga la agencia de ejemplo y qué hay que cambiar antes de operar de
verdad.

---

## 1. Cargar la semilla

```bash
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan migrate --seed
```

La semilla vive en
`api/database/seeders/AgenciaEjemploSeeder.php`.

**No hay datos de faker sueltos.** Una base llena de datos inventados no sirve
para probar nada, porque nadie sabe qué debería haber ahí.

---

## 2. Qué crea

### La agencia

| Campo | Valor |
|---|---|
| `slug` | `andes-travel` |
| `nombre` | Andes Travel |
| `dominio_correo` | `andestravel.cl` |
| `activo` | sí |

Las **personas** (los agentes, no los pasajeros):

| Nombre | Correo | Rol |
|---|---|---|
| Camila Rojas | camila.rojas@andestravel.cl | administrador |
| Sebastián Muñoz | sebastian.munoz@andestravel.cl | agente |

### Configuración de la agencia

Los tres interruptores en su valor prudente:

- `mostrar_documento_en_listados` → `false`
- `exigir_verificacion_nombre` → `true`
- `exigir_motivo_lectura_documento` → `true`

### El catálogo

**Dos proveedores:** Aerolíneas del Sur y Hotel Costa Norte.

**Tres productos:**

| Código | Tipo | Nombre |
|---|---|---|
| `VUELO-SCL-SPA` | vuelo | Santiago - São Paulo |
| `HOTEL-CN` | hotel | Hotel Costa Norte, habitación doble |
| `PAQ-CONA-SUR` | paquete | Paquete Cona Sur, 5 noches |

### **Dos fechas del mismo vuelo**

Este es el punto que hay que mirar, porque muestra de qué sirve separar
producto de fecha:

```
Producto "Santiago - São Paulo" (uno solo)
   │
   ├── Vuelo SK404, sale en 14 días   →  asientos 14A, 14B, 14C, 15A, 15B, 15C
   └── Vuelo SK406, sale en 45 días   →  (sin asientos cargados)
```

**Cada fecha tiene su propia tabla de asientos.** Por eso la reserva apunta a
`vuelo_id`: dos personas que viajan el mismo trayecto en fechas distintas
**no comparten asientos**.

En la semilla solo el primer vuelo tiene asientos cargados, que es lo que hace
que el mapa se pueda probar.

### Las tarifas

| Producto | Fecha | Clase | Precio | Cupos |
|---|---|---|---|---|
| VUELO-SCL-SPA | SK404 | Económica | $18.000 | 20 |
| VUELO-SCL-SPA | SK406 | Económica | $16.500 | 20 |
| HOTEL-CN | (sin fecha) | Doble | $95.000 | sin control |

---

## 3. El pasajero y sus dos documentos

Este es el caso que hace que el documento **no sea una columna**.

### El pasajero

```
nombre_declarado: "Rodrigo"
```

Se dijo "Rodrigo". Abreviado, como pasa en la venta en línea.

### Sus dos documentos

| Número | Tipo | Vencimiento | Verificación | Estado |
|---|---|---|---|---|
| `P0890123` | pasaporte | hace 1 año | verificado | **Vigente anterior, ya vencido** |
| `P1234567` | pasaporte | en 5 años | pendiente | **El del viaje que viene** |

La persona **viajó antes con un pasaporte y ahora viaja con otro**. Por eso hay
dos filas y no una: si el documento fuera una columna, la renovación habría
sobrescrito el dato y el boleto del viaje anterior quedaría apuntando al
documento equivocado.

---

## 4. La reserva

| Campo | Valor |
|---|---|
| `folio` | `V-000001` |
| Producto | Santiago - São Paulo |
| Vuelo | SK404 |
| Total | **$18.000** |
| Estado | `pendiente` |
| Origen | mostrador |
| Pasajero | Rodrigo |

### El nombre del boleto

```
nombre_declarado:   "Rodrigo"
documento:          "Rodrigo Andrés" + "Pérez Muñoz"
nombre_boleto:      "Rodrigo Andrés Pérez Muñoz"   ← del DOCUMENTO
```

**Esto es el producto.** El boleto sale con el nombre del documento, no con el
nombre abreviado que escribió el pasajero.

La diferencia queda anotada:

> Documento: "Rodrigo Andrés Pérez Muñoz". Declarado por el pasajero: "Rodrigo".
> La correspondencia la revisa un agente: el sistema no la decide.

### El asiento

**14A**, en estado `asignado`, ligado a la reserva y al pasajero. Los otros cinco
quedan disponibles, para que el mapa muestre el contraste.

### El pago parcial

**Esto también es a propósito.**

| | |
|---|---|
| Total | $18.000 |
| Pagado | $7.000 |
| **Saldo** | **$11.000** |

Si la semilla dejara la reserva pagada, la pantalla de pagos incompletos
arrancaría vacía y nadie vería para qué existe.

Al entrar a `/pagos`, la reserva `V-000001` aparece con $11.000 pendientes.

---

## 5. Las políticas

### Política de nombres

Una política general de la agencia, sin proveedor asociado:

- Separador: un espacio
- Apellidos primero: no
- Exigir tildes: no
- Exigir mayúsculas: no

### Políticas de cancelación

Cuatro escalones para el producto de vuelo.

> **ATENCIÓN: los plazos de abajo son de una agencia de muestra.**
> No son una política de Hefesto Studio ni una recomendación. Muestran cómo se
> escriben. La agencia real tiene que cambiarlos por los suyos antes de operar.

| Escalón | Anticipación mínima | Costo | Destino |
|---|---|---|---|
| Cancelación con más de 30 días | 30 días | 0 % | reembolso |
| Cancelación entre 15 y 30 días | 15 días | 25 % | reembolso |
| Cancelación entre 7 y 15 días | 7 días | 50 % | crédito |
| Cancelación con menos de 7 días | 3 días | 100 % | crédito |

El producto de **hotel** y el de **paquete no tienen políticas**. Si se intenta
cancelar una reserva de hotel, el sistema la rechaza diciendo que falta
configurar la política. Es el comportamiento correcto: el sistema no inventa un
plazo.

---

## 6. Qué probar con la semilla

### 6.1 El nombre del boleto sale del documento

Entrar como agente, abrir la reserva `V-000001`:

- El nombre del boleto dice "Rodrigo Andrés Pérez Muñoz".
- El pasajero se llamaba "Rodrigo".
- El estado del nombre dice "Pendiente de revisión".

### 6.2 No se puede emitir sin verificar

Intentar cambiar el estado a `emitida`:

> No se puede emitir la reserva: hay pasajeros con el nombre del boleto sin
> verificar. La correspondencia entre el nombre del boleto y el documento la
> revisa un agente.

**Esto es exactamente el control que evita el problema del mostrador.**

### 6.3 Verificar y emitir

1. En `/reservas`, "Revisar nombres" de la reserva.
2. Confirmar que "Rodrigo Andrés Pérez Muñoz" coincide con el pasaporte.
3. Cambiar el estado a `confirmada`.
4. Ahora sí: "Emitir boleto".

Queda registrado quién verificó (`Camila Rojas`), cuándo, y con qué
observación.

### 6.4 El pago parcial

En `/pagos`:

- La reserva `V-000001` aparece con $11.000 pendientes.
- Es la única, y está ordenada por antigüedad.

### 6.5 El mapa de asientos

En `/vuelos`, elegir el SK404:

- **14A** aparece como ocupado (es el de la reserva).
- Los otros cinco aparecen disponibles.

Tomar 14A para otra reserva devuelve **409** con un mensaje que dice que fue
tomado por otra compra.

---

## 7. Antes de operar de verdad

La semilla es un punto de partida, no una configuración de producción.

| Qué cambiar | Por qué |
|---|---|
| **Los plazos de cancelación** | Los de la semilla son de ejemplo. La agencia escribe los suyos. |
| **La política de nombres** | Si su aerolínea exige otro separador u otro orden, hay que configurarlo. |
| **Los agentes** | Que se den de alta los agentes reales, con sus `sub` de Authentik. |
| **El dominio de correo** | El de la agencia real. |
| **Los datos de ejemplo** | El pasajero "Rodrigo" y sus pasaportes son ficticios. Se pueden dejar: sirven para probar. |

Y verificar, siempre:

```bash
php artisan viajes:verificar-aislamiento
```

Debe salir con código 0. Ver [04](04-INSTALACION.md).

---

## 8. Para borrar todo y empezar de nuevo

En **desarrollo**:

```bash
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan migrate:fresh --seed
```

`migrate:fresh` **borra todas las tablas** y las vuelve a crear. **Nunca en
producción**: ver [08](08-RESPALDOS.md).