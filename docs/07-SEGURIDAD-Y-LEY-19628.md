# 07 - Seguridad y Ley 19.628

Este documento cubre el tratamiento de datos personales, que es el punto más
delicado de toda la aplicación.

---

## 1. Qué es un dato personal aquí

Un pasajero es una **persona natural**, y su **documento de identidad** es un
dato personal bajo la Ley 19.628. También lo son el nombre, el correo, el
teléfono, la fecha de nacimiento y la nacionalidad.

En esta aplicación el documento de identidad es **el dato más sensible**: es lo
que permite viajar y es el que se renueva. Por eso recibe un tratamiento distinto
al del resto.

### La ley no prohíbe tratarlo

La Ley 19.628 no prohíbe que una agencia de viajes guarde el documento de sus
pasajeros: es indispensable para vender el pasaje. Lo que exige es:

1. **Saber quién accede a él.**
2. **Poder demostrar después quién lo accedió.**
3. **No guardarlo más allá del plazo declarado.**
4. **Tomar las medidas de seguridad razonables** para que no se filtre.

Las tres primeras cosas son las que este diseño resuelve. La cuarta tiene su
propio apartado (sección 6).

---

## 2. El principio que ordena todo: el listado no muestra el documento

**El número de documento completo nunca sale en un listado.**

El listado es el lugar por donde se escapan los datos, porque lo ve cualquiera
que tenga acceso a la pantalla, y en una agencia eso incluye a gente que no
necesita el documento para nada.

Hay **dos barreras**, y las dos están a propósito:

| Barrera | Dónde | Qué hace |
|---|---|---|
| El modelo oculta el campo | `DocumentoViaje::$hidden` | `numero` no aparece en el `toArray()` del modelo |
| El Resource lo enmascara | `DocumentoViajeResource` | Devuelve `numero_enmascarado`: `P12*****` |

La primera es la red de seguridad. La segunda es la que se lee al revisar la
pantalla, y por eso está en el Resource: el Resource es lo que **decide qué se
muestra**.

### Qué sale y qué no en un listado

| Sale | No sale |
|---|---|
| Tipo de documento (pasaporte, cédula) | Número completo |
| Número **enmascarado** | Fecha de nacimiento |
| Nombres y apellidos del documento | Correo del pasajero |
| Fecha de vencimiento | Teléfono del pasajero |
| Estado de verificación | |

Los nombres y apellidos **sí** salen, y es a propósito: son el dato con el que se
viaja y es exactamente lo que el agente necesita para imprimir el boleto. No son
el dato sensible.

---

## 3. El único endpoint que muestra el documento completo

```
GET /api/v1/pasajeros/{pasajero}/documentos/{documento}
```

Es **el único** lugar donde el número completo se devuelve. Y tiene cuatro
condiciones:

1. **Permiso propio.** La habilidad `leerDocumentoCompleto` de `PasajeroPolicy`,
   separada de `ver`. Ver un listado de pasajeros es una necesidad diaria; leer
   el número entero de un documento no lo es.
2. **Motivo obligatorio.** La cabecera `X-Motivo-Consulta` es obligatoria si la
   agencia lo exige (que es el valor por defecto). Sin motivo, responde 422 y no
   entrega nada.
3. **Bitácora.** Queda registrado quién, cuándo, desde qué IP, con qué motivo y
   qué pasajero vio.
4. **La respuesta no se guarda en el frontend.** El Resource del endpoint
   completo no alimenta ningún estado de la interfaz: es una lectura de una vez.

Además, hay una comprobación que parece obvia y no lo es: el documento **tiene
que ser de ese pasajero**. El route-model binding resuelve el `documento_id`
pero no sabe de quién es:

```php
if ($documento->pasajero_id !== $pasajero->id) {
    abort(404);
}
```

Sin eso, un `documento_id` de otro pasajero devolvería el documento.

---

## 4. Auditoría: la bitácora de accesos

### Qué se registra

**El ACCESO, no solo el cambio.**

Ver el documento es el acceso sensible: el dato no se modifica, pero se lee. Si
solo se registrara el cambio, el rastro no serviría para nada, porque nadie
cambia un documento.

| Campo | Qué guarda |
|---|---|
| `accion` | `documento.leido`, `reserva.cancelada`, `pago.registrado`... |
| `recurso`, `recurso_id` | Qué se accedió |
| `usuario_id` | Quién |
| `motivo` | Por qué. **Obligatorio** al leer el documento completo |
| `ip`, `agente` | De dónde |
| `detalle` | JSON con identificadores relacionados |

### Qué NO se registra

**El valor del dato.** Nunca el número del documento, nunca el nombre completo
en un campo de detalle.

Meter el dato sensible en el registro de auditoría sería crear **un segundo lugar
donde vive el dato personal**, sin los controles del primero. La bitácora está
protegida por RLS y por la política de autorización, pero el número del
documento tiene además el enmascarado. Ponerlo en la bitácora saltaría esa
barrera.

### Para qué sirve en la práctica

Cuando un pasajero pregunta *"¿quién vio mis datos?"*, existe una respuesta, y
se puede dar.

---

## 5. Retención

`BITACORA_RETENCION_DIAS` declara el plazo. Por defecto, 1.825 días (cinco años).

La retención es una **obligación**, no una cortesía: la ley pide que los datos no
se guarden más allá del plazo que declara la propia organización. Por eso el
plazo está en `config/tenancy.php` y no enterrado en el código.

Cero significa que se conserva para siempre. **Una agencia con el valor en cero
debe evaluarlo**: guardar para siempre el registro de quién vio documentos de
pasajeros es una decisión, y probablemente no sea la que se quiere.

La purga corre por agencia, dentro de una transacción con su contexto. No hace
falta un comando global con privilegios especiales.

---

## 6. El resto de las medidas

### Aislamiento

Ver [03](03-MULTITENANCY-Y-ROLES.md). La RLS impide que una agencia vea los
documentos de otra. Aquí eso es más grave que en otros módulos: son documentos
de personas que **nunca compraron** en esa agencia.

### Permisos mínimos

| Habilidad | Quién | Qué controla |
|---|---|---|
| `ver` | Todos | Ver el pasajero con el documento enmascarado |
| `leerDocumentoCompleto` | Todos, con motivo y bitácora | Ver el número entero |
| `verificarNombre` | Todos | Confirmar que el nombre del boleto coincide con el documento |
| `cambiar-politicas` | Solo administrador | Cambiar reglas de nombres y cancelación |

### CSRF

| Grupo | ¿CSRF? | Por qué |
|---|---|---|
| `web` | **Sí** | Usa cookie de sesión. El token va en el formulario. |
| `api` | No | No usa cookies de sesión directamente; la sesión viaja por el backend-for-frontend y el token va como cabecera `X-XSRF-TOKEN`. |

El cierre de sesión es un **POST**, no un GET: un GET se podría disparar desde
otro sitio con una imagen y cerrarle la sesión a alguien.

### Autorización

Cuando una Policy rechaza, la respuesta es **404, no 403**: un 403 confirmaría que
el recurso existe.

### Cabeceras

| Cabecera | Dónde | Para qué |
|---|---|---|
| `Strict-Transport-Security` | Caddy | Obliga a HTTPS desde la primera visita |
| `X-Content-Type-Options: nosniff` | Nginx y Caddy | Evita que el navegador interprete un archivo como otro tipo |
| `X-Frame-Options: DENY` | Nginx y Caddy | Evita el *clickjacking* |
| `Content-Security-Policy` | Caddy | La interfaz no carga recursos de terceros |
| `Referrer-Policy: same-origin` | Nginx y Caddy | No filtra la URL al saltar a sitios externos |

### `.env.example` sin valores reales

Ningún valor del archivo es real. Los secretos se generan con `openssl rand`.
El archivo está en `.gitignore`.

### Logs sin el dato

Ni el registro de acceso de Nginx ni el de Caddy guardan el **cuerpo** de la
petición, que puede contener datos personales de un pasajero. Un log de acceso
con el cuerpo es otro lugar donde vive el dato.

---

## 7. Lo que NO se hizo, y sigue siendo un riesgo

Esto hay que decirlo con todas las letras:

1. **No hay revisión de seguridad externa.** El diseño se hizo aplicando criterios
   conocidos; nadie de fuera lo miró.
2. **No hay pruebas de penetración.**
3. **Las pruebas están escritas pero no se ejecutaron.** No había PHP ni
   PostgreSQL en el entorno donde se escribió el código. Ver
   [10](10-PRUEBAS-Y-ESTADO.md).
4. **La aplicación no cifra los documentos.** Están en claro en la base, protegidos
   por RLS y por la política de autorización. Si alguien obtiene un respaldo, tiene
   los documentos en claro. Cifrar la columna de `numero` es una decisión que
   **no se tomó**: dificulta las búsquedas y el enmascarado.
5. **Un operador con acceso SQL directo ve todo.** La RLS protege a la aplicación,
   no a quien ya está adentro de la base.
6. **No hay cifrado de los respaldos en el script.** Ver [08](08-RESPALDOS.md).
7. **No hay doble factor de autenticación.** Authentik lo soporta, pero no se
   activó.
8. **No hay política de retención para los datos de pasajeros.** Solo para la
   bitácora. Los registros de pasajeros y documentos se conservan mientras la
   agencia exista. Eso puede ser lo correcto (la agencia tiene que poder demostrar
   una venta años después) y probablemente lo sea, pero es una decisión que la
   agencia tiene que tomar **conscientemente**, no por omisión.
9. **La eliminación de datos no está implementada.** Si un pasajero pide que se
   borren sus datos, hay que hacerlo a mano, y el sistema no avisa de los casos
   que podrían quedar.

---

## 8. Lista de comprobación de datos personales

Antes de poner esto en producción con datos reales de personas:

```
[ ]  `BITACORA_RETENCION_DIAS` tiene un valor pensado, no el de ejemplo
[ ]  Se sabe quién puede leer el documento completo, y es quien debe
[ ]  Se probó el flujo de leer un documento y se verificó la bitácora
[ ]  Se revisó `mostrar_documento_en_listados` y sigue en false
[ ]  Los respaldos están cifrados y el lugar donde se guardan tiene
     control de acceso
[ ]  Hay un procedimiento escrito para responder a un pasajero que
     pregunta quién vio sus datos
[ ]  Hay un procedimiento escrito para atender una solicitud de
     eliminación
[ ]  El periodo de retención de los datos de pasajeros está decidido
     por la agencia y anotado
```