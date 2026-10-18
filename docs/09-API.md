# 09 - API

Prefijo: `/api/v1`. Formato: JSON.

---

## 1. Cómo se llama

El navegador **nunca** habla directo con esta API. Todo pasa por el
*backend for frontend* de Nuxt, en `web/server/api/[...ruta].ts`, que es el
único que tiene la cookie de sesión.

El motivo: la cookie de sesión es `HttpOnly` + `Secure` + `SameSite=Lax`. Si el
navegador llamara a la API (otro origen), la cookie no viajaría y habría que
resolverlo con CORS y credenciales, que duplica el riesgo de CSRF.

Para probar desde la terminal, hay que llamar a la API directamente, con la
cookie de sesión a mano.

---

## 2. Autenticación

OAuth contra Authentik. La sesión vive en una cookie.

```
GET  /auth/redirect      arranca el login
GET  /auth/callback      vuelve del proveedor
POST /auth/salir         cierra sesión
```

### La agencia se deduce del dominio del correo

En el `callback`, el dominio del correo se busca en `agencias.dominio_correo`. Si
no está, el ingreso se rechaza: una persona que entra por un dominio
desconocido **no tiene agencia**, y sin agencia no puede operar.

El rol **no** viene de Authentik: sale de la tabla `usuarios`. Ver
[03](03-MULTITENANCY-Y-ROLES.md).

### Si la cuenta existe en Authentik pero no en `usuarios`

403 con:

> Su cuenta está en el proveedor de identidad pero no está dada de alta como
> agente de ninguna agencia. Pida al administrador que la agregue.

---

## 3. Sin protección

Estas rutas no tocan datos de negocio y no necesitan sesión:

| Ruta | Para qué |
|---|---|
| `GET /api/v1/salud` | Chequeo de vida. |
| `GET /api/v1/agencias-publicas` | La pantalla de entrada. Lee la vista `vw_agencias_publicas`, que expone solo `slug`, `nombre` y `activo`. |

---

## 4. Convenciones de respuesta

### Errores

| Código | Cuándo |
|---|---|
| 400 | Petición malformada |
| 401 | Sin sesión |
| 403 | Sin permiso, o cuenta no dada de alta |
| **404** | No existe **o** no le corresponde. Un 403 confirmaría que el recurso existe |
| 409 | Conflicto con el estado actual. Por ejemplo: el asiento ya está tomado |
| 422 | Los datos no pasan la validación, o una regla de negocio lo rechaza |
| 500 | Fallo interno |

Los mensajes de 422 y 409 están escritos para el operador, no para un
desarrollador: dicen **qué pasó y qué hacer**.

### Montos

**Todos los montos son enteros en centimos.** `"saldo": 1100000` son $11.000.

Convertir a pesos es responsabilidad de la interfaz, y se hace con
`Math.floor(valor / 100)`, nunca con división de coma flotante.

---

## 5. Reservas

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/reservas` | Listado. Filtros: `estado`, `paginas_incompletas`, `buscar` |
| `POST` | `/reservas` | Crea una reserva |
| `GET` | `/reservas/{id}` | Detalle |
| `GET` | `/reservas/{id}/eventos` | Historial de estados |
| `POST` | `/reservas/{id}/estado` | Cambia el estado |

### Crear una reserva

```json
{
  "producto_id": "uuid",
  "vuelo_id": "uuid",
  "tarifa_id": "uuid",
  "origen": "mostrador",
  "pasajeros": [
    {
      "pasajero_id": "uuid",
      "documento_viaje_id": "uuid",
      "es_contacto": true
    }
  ]
}
```

Lo que hace, y que no es evidente:

1. Arma el **nombre del boleto desde el documento** de cada pasajero, con la
   política de nombres del proveedor del producto.
2. Compara el nombre declarado con el del documento y **deja el nombre
   pendiente**. La revisión automática nunca verifica sola.
3. Nace en estado `pendiente`, con folio correlativo **por agencia**.

### Cambiar el estado

```json
{ "estado": "emitida", "motivo": null }
```

| Estado destino | Qué exige |
|---|---|
| `confirmada` | Nada más. |
| `emitida` | **Todos** los pasajeros con el nombre `verificado`. |
| `usada` | Que estuviera `emitida`. |
| `cancelada` | Una **política de cancelación aplicable**. Si no hay, se rechaza con 422. |

Si la transición no es válida, el 422 dice cuáles sí son:

> No se puede pasar de "Usada" a "Cancelada". Las transiciones permitidas son:
> ninguna: es un estado terminal.

---

## 6. Verificación del nombre

**La frontera del producto.** No existe ningún endpoint que escriba
`nombre_boleto`: el nombre se arma desde el documento y aquí solo se acepta o
se rechaza.

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/reservas/{id}/nombres-pendientes` | La cola de trabajo del agente |
| `POST` | `/reserva-pasajeros/{id}/verificar-nombre` | El agente marca verificado o rechazado |

```json
{ "estado": "verificado", "observacion": "Coincide con el pasaporte vigente." }
```

- `estado` solo acepta `verificado` o `rechazado`. **Nunca `pendiente`**: eso
  lo pone el sistema.
- Rechazar **exige** `observacion`: rechazar sin decir qué se encontró deja al
  pasajero y al siguiente agente sin información.

La respuesta de `nombres-pendientes` trae el documento **enmascarado**, los dos
nombres para comparar, y un recordatorio:

> La correspondencia entre el nombre del boleto y el documento la revisa un
> agente. El sistema solo avisa.

---

## 7. Asientos

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/vuelos/{id}/asientos` | El mapa, leído de las filas |
| `POST` | `/vuelos/{id}/asientos/{numero}/tomar` | Toma un asiento |
| `DELETE` | `/asientos/{id}` | Lo libera |

### Tomar un asiento

```json
{ "reserva_id": "uuid", "reserva_pasajero_id": "uuid", "bloquear": false }
```

`reserva_id` es **obligatorio**: un asiento no se toma "al vuelo", se toma para
una reserva concreta. Sin ella, un asiento tomado no es de nadie y queda apretado
hasta que expire el bloqueo.

### El 409 es el comportamiento correcto

Si el asiento ya está tomado:

```
409 Conflict
{ "mensaje": "El asiento 14A fue tomado por otra compra al mismo tiempo. Se debe elegir otro asiento." }
```

**409 y no 500**: es un conflicto con el estado actual, no una falla del
servidor. El agente tiene que poder distinguir "elija otro asiento" de "el
sistema se cayó".

El mensaje es específico a propósito. Un "error al guardar el asiento" haría que
el operador reintentara, y reintentar un asiento tomado es como se llena el
vuelo de gente con el mismo asiento.

Detrás del mensaje hay una de dos cosas, y en los dos casos el resultado es el
mismo: **la garantía la da el índice de exclusión de PostgreSQL**, no el código de
la aplicación. Ver [01 §6](01-ARQUITECTURA.md).

---

## 8. Pasajeros y documentos

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/pasajeros` | Listado. Filtro: `buscar` |
| `POST` | `/pasajeros` | Alta |
| `GET` | `/pasajeros/{id}` | Detalle |
| `POST` | `/pasajeros/{id}/documentos` | **Alta de un documento nuevo** |
| `GET` | `/pasajeros/{id}/documentos/{documento}` | **El único que muestra el número completo** |

### Alta de documento

```json
{
  "tipo": "pasaporte",
  "numero": "P1234567",
  "nombres": "Rodrigo Andrés",
  "apellidos": "Pérez Muñoz",
  "fecha_emision": "2019-03-10",
  "fecha_vencimiento": "2029-03-10"
}
```

`nombres` y `apellidos` son **el texto del documento, tal como está**. El sistema
no los corrige, no los abrevia, no les cambia el orden.

La API **no acepta** `estado_verificacion_nombre`. Si lo aceptara, la revisión
humana sería opcional y el producto perdería su sentido.

`POST` crea un documento **nuevo**, aunque el pasajero ya tenga uno vigente. La
renovación de pasaporte no es "actualizar el documento": es un documento más, y
el viejo queda como historial.

### El endpoint del documento completo

```
GET /api/v1/pasajeros/{id}/documentos/{documento}
X-Motivo-Consulta: Verificación de identidad para emisión de boleto
```

Sin la cabecera de motivo, responde **422** y no entrega nada.

La respuesta incluye el número completo, **solo en esta llamada**. No se guarda
en ningún estado del frontend.

Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

---

## 9. Pagos

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/reservas/{id}/pagos` | Los pagos de la reserva |
| `POST` | `/reservas/{id}/pagos` | Registra un pago, **puede ser parcial** |
| `POST` | `/reservas/{id}/pagos/{pago}/anular` | Lo anula. **No lo borra** |
| `GET` | `/reservas/{id}/estado-de-cuenta` | El estado de cuenta |
| `GET` | `/pagos/incompletos` | **Todo lo que está pagado a medias** |

### Registrar un pago

```json
{ "monto_centimos": 700000, "metodo": "transferencia", "referencia": "TRANSF-123" }
```

La respuesta trae el pago **y el estado de cuenta completo**:

```json
{
  "pago": { "...": "..." },
  "estado_de_cuenta": {
    "total": 1800000,
    "pagado": 700000,
    "saldo": 1100000,
    "estado": "parcial",
    "estado_etiqueta": "Pago parcial"
  }
}
```

Un pago parcial es legítimo y corriente. Lo que no es legítimo es que el sistema
no lo muestre, y por eso la respuesta trae el saldo siempre.

No se puede pagar más de lo que se debe, ni con monto cero, ni una reserva
cancelada.

### Anular

Exige motivo de 5 caracteres o más. El pago queda con `anulado`, su motivo y su
fecha, y **deja de sumar**. La historia se conserva: es lo que hace falta cuando
alguien reclama.

---

## 10. Catálogo

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/vuelos` | Vuelos con plazas disponibles |
| `GET` | `/api/v1/vuelos/{id}` | Detalle de un vuelo |
| `GET` | `/api/v1/productos` | Productos del catálogo |

---

## 11. Códigos de error que vale la pena conocer

| Situación | Código | Qué hacer |
|---|---|---|
| Asiento tomado por otra compra | 409 | Elegir otro asiento. **No reintentar** |
| Emitir con nombres sin verificar | 422 | Verificar los nombres primero |
| Cancelar sin política aplicable | 422 | El administrador tiene que configurar la política |
| Falta el motivo de consulta | 422 | Mandar la cabecera `X-Motivo-Consulta` |
| Transición de estado inválida | 422 | El mensaje dice cuáles son válidas |
| Recurso de otra agencia | 404 | **No existe para ti.** No reintentar con otro id |

El 404 de "otra agencia" es el comportamiento correcto, no un error. Es el
aislamiento funcionando: la otra agencia no puede ni confirmar que ese recurso
existe. Ver [03](03-MULTITENANCY-Y-ROLES.md).