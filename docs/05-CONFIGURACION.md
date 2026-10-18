# 05 - Configuración

Qué hay que configurar antes de que una agencia pueda operar, y por qué el
sistema **falla cerrado** cuando algo falta.

---

## 1. La regla de fondo

Hay dos cosas en esta aplicación que **no son entregables por el código**:

1. Los **plazos de cancelación**.
2. Las **reglas de nombres**.

Ambas cambian por agencia, por proveedor y por temporada. Escribirlas en el
código sería inventar un acuerdo comercial que nadie acordó.

Por eso, lo que sí hay en el código son los **interruptores que hacen que el
sistema falle cerrado** cuando esas reglas no están configuradas.

| Si falta... | Qué hace el sistema | Por qué |
|---|---|---|
| La política de cancelación del producto | La cancelación **se rechaza** con un mensaje que dice exactamente qué falta | Asumir costo cero es la forma elegante de regalar un pasaje |
| La política de nombres del proveedor | El boleto se arma con el texto del documento, separado por un espacio, y queda **pendiente** de revisión | El sistema no sabe qué grafía acepta la agencia |

En los dos casos, el comportamiento prudente no es inventar un valor.

---

## 2. Política de nombres

Vive en `politicas_nombres`, una fila por proveedor (o una general, con
`proveedor_id` nulo).

### Los campos

| Campo | Qué significa | Ejemplo |
|---|---|---|
| `separador` | Lo que va entre nombres y apellidos en el boleto | `' '` (un espacio) |
| `orden_apellidos_primero` | Si la agencia imprime "Pérez Muñoz, Rodrigo Andrés" | `false` |
| `exigir_tildes` | Criterio de **comparación**: las tildes importan | `false` |
| `exigir_mayusculas` | Criterio de **comparación**: las mayúsculas importan | `false` |
| `ignorar_nombres_compuestos` | Criterio de **comparación**: "Pérez-Rojas" equivale a "Pérez Rojas" | `false` |

### La distinción que hay que tener clara

**`exigir_tildes`, `exigir_mayusculas` e `ignorar_nombres_compuestos` son
criterios de COMPARACIÓN, no de impresión.**

Sirven para que "Pérez" y "PEREZ" no se listen como dos nombres distintos en la
pantalla de revisión. **El texto que se imprime en el boleto es siempre el del
documento**, sin aplicar ninguna de estas reglas.

Si la agencia quiere que el boleto salga en mayúsculas, eso lo decide la
aerolínea al emitir el boleto, no esta aplicación. Aquí no se "normaliza" nada.

### Sin política

Si un proveedor no tiene política, `PoliticaDeNombres` devuelve `null`. La
consecuencia es que:

- El nombre del boleto se arma con un espacio como separador (lo más cercano al
  documento).
- El nombre queda **pendiente de revisión**, y la diferencia se le explica al
  agente.

La agencia puede operar sin configurar esto. No puede **emitir** sin que un
agente verifique.

---

## 3. Políticas de cancelación

Viven en `politicas_cancelacion`, **un escalón por fila**, por producto.

### Los campos

| Campo | Qué significa |
|---|---|
| `anticipacion_minima_minutos` | Desde cuánta anticipación aplica este escalón |
| `porcentaje_costo` | Porcentaje del total a cobrar, de 0 a 100 |
| `costo_fijo_centimos` | Monto fijo adicional, en centimos |
| `destino` | Qué pasa con el dinero: `reembolso` o `credito` |

### Por qué en minutos y no en días

Porque hay tarifas con plazos de **horas** ("cancelar con menos de 6 horas
devuelve un crédito"). Guardarlo en días obligaría a inventar una regla para las
fracciones de día.

### Cómo se elige el escalón

El sistema toma el de **mayor anticipación** que **no supere** los minutos que
faltan para la salida. Es la forma en que funcionan los escalones de una tarifa
publicada.

Ejemplo de cómo lo ve la agencia, y que es **de ejemplo, no una política de
Hefesto Studio**:

| Escalón | Anticipación mínima | Costo | Destino |
|---|---|---|---|
| Cancelación con más de 30 días | 43.200 min (30 días) | 0 % | reembolso |
| Cancelación entre 15 y 30 días | 21.600 min (15 días) | 25 % | reembolso |
| Cancelación entre 7 y 15 días | 10.080 min (7 días) | 50 % | crédito |
| Cancelación con menos de 7 días | 4.320 min (3 días) | 100 % | crédito |

Con una cancelación hecha con 10 días de anticipación, aplica el primero: hay un
escalón que exige 15 días (no se cumple) y otro que exige 7 días (se cumple), y
el sistema toma el de **mayor** anticipación entre los que se cumplen.

**Estos números son de la agencia de ejemplo de la semilla.** La agencia real
tiene que cambiarlos por los suyos. Ver [06](06-PRIMER-PRODUCTO.md).

### Sin política

Si el producto no tiene ningún escalón aplicable, la cancelación se rechaza con
un mensaje que dice:

> El producto "Santiago - São Paulo" no tiene una política de cancelación que
> aplique con 10 días de anticipación. La agencia debe configurarla antes de
> cancelar; el sistema no asume un costo.

### Un límite que hay que conocer

La anticipación se cuenta desde la **fecha de salida del vuelo**. Para un producto
sin vuelo (un paquete sin fecha), la cancelación **se rechaza**: el sistema no
puede calcular la anticipación y no la inventa. La agencia tiene que resolverlo
a mano o agregar una fecha al producto.

---

## 4. Configuración de la agencia

Una fila en `configuraciones` por agencia. Tres interruptores.

| Interruptor | Por defecto | Qué hace |
|---|---|---|
| `mostrar_documento_en_listados` | `false` | Si fuera `true`, el documento aparecería completo en los listados. **No debería cambiar.** Existe la columna para que la decisión esté a la vista y se haya tomado a conciencia. |
| `exigir_verificacion_nombre` | `true` | Si es `true`, no se emite una reserva con nombres sin verificar. |
| `exigir_motivo_lectura_documento` | `true` | Si es `true`, leer el documento completo exige un motivo escrito. |

Más `dias_aviso_vuelo`, que es la política de la agencia sobre con cuánta
anticipación quiere que el sistema avise que viene un vuelo. No es una regla de
la industria.

### El comportamiento sin configuración

Si una agencia no tiene fila en `configuraciones`, `Configuracion::prudente()`
devuelve todos los interruptores en el valor restrictivo. Una agencia recién
creada no muestra documentos en listados: primero se configura, después se
abre.

---

## 5. Identidad: dar de alta una agencia

### Dos pasos

**1. La agencia**, en la tabla `agencias`:

```
slug              -> aparece en la URL de entrada
nombre            -> se muestra en la pantalla de entrada
dominio_correo    -> de aquí se deduce la agencia al entrar
```

El `dominio_correo` es lo que decide a qué agencia pertenece una persona. Un
dominio mal puesto significa que **nadie puede entrar**, o que entra a la agencia
equivocada.

**2. El agente**, en la tabla `usuarios`, con su `subjekt_authentik` copiado del
proveedor de identidad.

Si el `sub` no está en `usuarios`, la persona entra y **no puede operar**: el
mensaje se lo dice.

### Permisos que hay que abrir en Authentik

| Permiso | Para qué |
|---|---|
| `openid` | Obtener el `sub` y el `id_token` |
| `profile` | Obtener el nombre |
| `email` | Obtener el correo, **con el que se deduce la agencia** |

Sin `email`, la API no puede determinar la agencia y el ingreso se rechaza.

---

## 6. Lista de comprobación antes de operar

```
[ ]  La agencia tiene fila en `agencias` con `dominio_correo` correcto
[ ]  Los agentes están dados de alta en `usuarios` con su `subjekt_authentik`
[ ]  Hay al menos un producto activo con su proveedor
[ ]  Los vuelos tienen su fila de asientos
[ ]  Hay al menos una tarifa vigente por producto
[ ]  Hay politicas de cancelación escritas POR PRODUCTO
[ ]  Hay una política de nombres (o se acepta operar con ella pendiente)
[ ]  Hay una fila en `configuraciones` con los tres interruptores revisados
[ ]  `viajes:verificar-aislamiento` sale con código 0
[ ]  El respaldo automático está probado (ver docs/08)
```

Los tres últimos que **no** son opcionales:

- **Sin `configuraciones`**, la agencia no muestra documentos en listados, que
  es lo correcto, pero conviene que sea una decisión y no un descuido.
- **Sin políticas de cancelación**, **ninguna cancelación funciona**. Es
  intencional, pero hay que saberlo antes de que llegue un cliente a cancelar.
- **Sin `viajes:verificar-aislamiento` en verde**, no hay garantía de que el
  aislamiento ni la exclusión de asientos estén puestos.

---

## 7. Dónde vive cada cosa

| Qué | Dónde |
|---|---|
| Interruptores del sistema | `api/config/viajes.php`, `api/config/seguridad.php`, `api/config/tenancy.php` |
| Lista de tablas con RLS | `api/config/tenancy.php` |
| Tabla de políticas de nombres | `politicas_nombres` |
| Tabla de políticas de cancelación | `politicas_cancelacion` |
| Interruptores de la agencia | `configuraciones` |
| Comportamiento prudente sin configuración | `api/config/viajes.php`, `App\Models\Configuracion::prudente()` |