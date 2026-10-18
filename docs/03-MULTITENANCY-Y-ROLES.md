# 03 - Multi-tenancy y roles

Este es el documento más importante del proyecto, después del de arquitectura.

El riesgo número uno de esta aplicación **no** es que falle una pantalla: es que
la agencia "Andes Travel" vea los datos de la agencia "Viajes Sur". Todo lo demás
— la interfaz, la rapidez, el diseño — es menos importante que eso.

---

## 1. El patrón que se eligió

**Columna `tenant_id` + Row Level Security de PostgreSQL.**

Este patrón ya está decidido y documentado en
`apps/portal-autoservicio/docs/03-MULTITENANCY.md`. Se **reutiliza**, no se
inventa un cuarto enfoque. En esta aplicación la agencia es el tenant: en viajes,
"tenant" no significa nada para quien opera una agencia, así que la tabla de
entrada se llama `agencias`.

Todas las tablas de negocio llevan `tenant_id`, y PostgreSQL aplica una política
que exige que coincida con el de la petición.

### Por qué no un esquema por agencia

La alternativa clásica es darle a cada agencia su propio esquema
(`agencia_1.reservas`). Ya se evaluó en `portal-autoservicio` y se descartó. Los
motivos concretos:

1. **Las migraciones se multiplican.** Con esquema por cliente, cada migración
   tiene que aplicarse en todos los esquemas. Con 30 agencias son 30 ejecuciones;
   con 300, 300. Cada ejecución es una oportunidad de que unas queden
   desalineadas.
2. **El pool de conexiones se vuelve el cuello de botella.** Hay que dimensionarlo
   por número de combinaciones (esquema, rol) realmente usadas.
3. **Laravel obliga a duplicar la lógica de esquema.** La conexión pasa a
   depender del usuario conectado, y cada consulta, cada prueba y cada cola
   necesita acordarse del esquema correcto.
4. **Las herramientas de administración se complican.** Respaldar una agencia
   pasa a ser `pg_dump -n agencia_1`, que obliga a scripts a medida por cliente.
5. **El aislamiento queda en manos de la aplicación.** El `search_path` es un
   parámetro de sesión: si queda mal puesto (una conexión reutilizada, un error
   en el middleware), la consulta va al esquema equivocado y **no falla**.

El costo de columna + RLS es que todas las consultas deben tener contexto de
tenant. Ese costo está resuelto y forzado por el diseño (sección 4).

---

## 2. El mecanismo, en detalle

Definido en
`api/database/migrations/2026_02_10_000017_activar_row_level_security.php`.

### La función de contexto

```sql
CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
...
    valor := current_setting('app.tenant_id', true);

    IF valor IS NULL OR valor = '' THEN
        RAISE EXCEPTION USING ERRCODE = 'insufficient_privilege', ...
```

**Lanza excepción si no hay agencia.** Podría haber devuelto `NULL` y filtrado
todo en silencio, pero se eligió que falle de forma ruidosa:

- Si devuelve `NULL`, la consulta devuelve cero filas **sin aviso**. Alguien lo
  "arregla" devolviendo `NULL` cuando hace falta (un reporte, un comando de
  consola) y la puerta queda abierta sin que nada lo delate.
- Si lanza excepción, el error aparece en el log **en el momento**, y se sabe
  exactamente qué operación se escapó del middleware.

Es el mismo criterio que un candado: importa que falle cerrado.

### La política

```sql
ALTER TABLE public.reservas ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.reservas FORCE ROW LEVEL SECURITY;

CREATE POLICY politica_aislamiento_reservas ON public.reservas
    FOR ALL
    TO PUBLIC
    USING (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

Tres detalles:

- `USING` filtra lo que se **lee**.
- `WITH CHECK` impide **escribir** una fila con un `tenant_id` distinto al del
  contexto. Sin esto, un `INSERT` con el tenant equivocado dejaría una fila
  invisible que nadie podría ver ni borrar.
- `FORCE ROW LEVEL SECURITY` hace que la política aplique **también al dueño de
  la tabla**. Sin `FORCE`, el dueño la saltaría. En desarrollo el rol de la
  aplicación suele ser el mismo que creó las tablas: sin `FORCE`, el aislamiento
  sería decorativo justo en el entorno donde se prueba.

### Cómo viaja la agencia

```php
DB::select('SELECT set_config(?, ?, true)', [ContextoTenant::VARIABLE, $tenantId]);
```

El `true` final es lo más importante del archivo: significa **alcance de
transacción**. Al confirmar o revertir, PostgreSQL descarta el valor solo.

Con un `SET` normal (alcance de sesión), el valor sobreviviría a la petición. Y
el **worker de colas reutiliza la misma conexión** para trabajos de agencias
distintas: un trabajo de "Andes Travel" podría leer los datos de "Viajes Sur".
Ese es exactamente el fallo que este diseño evita, y por eso
`ContextoTenant::establecer()` **lanza excepción si no está dentro de una
transacción**.

### Quién fija el contexto

Solo una clase: `App\Services\Tenancy\ContextoTenant`, y solo a través del
middleware `App\Http\Middleware\ResolverTenant`, que envuelve la petición
autenticada en una transacción con la agencia ya fijada.

El middleware va **primero**, antes que `auth` (ver `bootstrap/app.php`): si
fuera después, cualquier middleware que ya consultó la base leería sin contexto.

---

## 3. La tabla que no lleva RLS

`agencias` **no lleva RLS, a propósito**. Se consulta durante el login, cuando
todavía no hay sesión y por lo tanto no hay `app.tenant_id` que poner en la
conexión.

Es una excepción consciente, con dos compensaciones:

**Compensación 1: la vista `vw_agencias_publicas`.** Antes del login, la
aplicación no lee `agencias` crudo: lee esta vista, que expone **tres** columnas
(`slug`, `nombre`, `activo`) y solo de las agencias activas. No expone razón
social, RUT, correos ni teléfonos. El comando `viajes:verificar-aislamiento`
comprueba que la vista siga exponiendo exactamente esas tres columnas.

**Compensación 2: el rol de la aplicación no es superusuario.** Quien tenga
acceso SQL directo a la base puede leer `agencias` entera. El comando comprueba
que el rol no sea superusuario ni tenga `BYPASSRLS`.

### Riesgo residual, dicho con todas las letras

Un `slug` **se puede enumerar antes de autenticarse**, porque aparece en la URL de
entrada y en los enlaces que la agencia reparte. Un atacante puede descubrir que
"Andes Travel" usa el sistema. **No puede ver más que su nombre.**

Si incluso eso se considera inaceptable, la alternativa es dejar el campo en
blanco hasta el login y resolver siempre por dominio de correo. Queda anotado
como decisión pendiente, **no como algo resuelto**.

---

## 4. Doble filtro dentro de la agencia

La RLS ya garantiza que nada sale de la agencia. Dentro de ella hay un segundo
filtro, en las Policies.

| Recurso | Agente | Administrador |
|---|---|---|
| Ver reservas, pasajeros, asientos | todas de su agencia | todas de su agencia |
| Crear una reserva | sí | sí |
| Cambiar el estado de una reserva | sí | sí |
| Cancelar una reserva | sí | sí |
| Registrar pagos | sí | sí |
| Verificar el nombre del boleto | sí | sí |
| Ver el documento completo (con motivo y bitácora) | sí | sí |
| Proveedores, productos, tarifas | **no** | sí |
| Políticas de nombres y de cancelación | **no** | sí |

Las políticas de nombres y de cancelación son lo más restringido de la
administración, y a propósito: son las reglas que decide el sistema en la
emisión de un boleto y en una cancelación. Un agente que las cambia puede, sin
querer, regalar un pasaje o emitir un boleto que la aerolínea va a rechazar.

### Por qué 404 y no 403

Cuando una Policy rechaza, la respuesta es **404, no 403**: un 403 confirmaría que
el recurso existe. El filtro por agencia impide que el recurso exista a ojos del
otro, así que 404 es además la respuesta correcta en el caso habitual.

---

## 5. Roles: de dónde sale cada permiso

**El rol no viene de Authentik.** Authentik responde *quién entró*. La tabla
`usuarios` responde *qué puede hacer dentro de la agencia*.

| De | Qué trae |
|---|---|
| **Authentik** | La identidad: el campo `sub` del token. |
| **`usuarios`** | El rol (`agente` o `administrador`), el nombre, el correo, si está activo. |

Si el rol viniera del proveedor, cambiar los permisos de un agente sería
cambiarlo en un sistema de identidad compartido con otras aplicaciones, y
auditarlo sería imposible.

`AUTHENTIK_GRUPO_ADMIN` existe en la configuración como **pista** para el
administrador al dar de alta un agente, pero la autoridad final es la tabla.

### Qué pasa si la cuenta existe en Authentik pero no en `usuarios`

El ingreso se rechaza con un mensaje que lo dice: *"su cuenta está en el
proveedor de identidad pero no está dada de alta como agente de ninguna
agencia"*. Esa persona entra y **no puede operar**.

### Cómo se deduce la agencia al entrar

Del **dominio del correo**. `agencias.dominio_correo` es la llave.

Si el dominio no está registrado, el ingreso se rechaza. No se busca "la primera
agencia" ni se adivina: una persona que entra por un dominio desconocido no
tiene agencia, y sin agencia no puede operar.

---

## 6. El riesgo número uno y qué se hizo

**Riesgo: fuga de datos entre agencias**, que aquí incluye documentos de
identidad de personas que nunca compraron en esa agencia.

Mitigaciones, en orden de fuerza:

1. **La base de datos lo impide**, no la aplicación. Un error en PHP no basta para
   filtrar datos.
2. **La política tiene `WITH CHECK`.** No se pueden crear filas en nombre de otra
   agencia.
3. **El contexto tiene alcance de transacción.** El worker de colas no puede
   arrastrar la agencia de otra petición.
4. **`FORCE ROW LEVEL SECURITY`.** El dueño de la tabla tampoco salta la política.
5. **La función de contexto falla cerrada.** Una consulta sin agencia se rompe de
   forma ruidosa en vez de devolver nada en silencio.
6. **Pruebas dedicated de aislamiento** en `api/tests/Feature/Aislamiento/`, que
   corren contra PostgreSQL de verdad y verifican el cruce con identificadores
   reales de otra agencia.
7. **`viajes:verificar-aislamiento`**, que se corre en el despliegue y falla si el
   rol tiene `BYPASSRLS`, si alguna tabla quedó sin política, si la función de
   contexto dejó de fallar cerrada, **o si la restricción de exclusión de
   asientos fue borrada**.

### Lo que NO se hizo y sigue siendo un riesgo

- **No hay revisión de seguridad externa.**
- **No hay pruebas de penetración.**
- **Las pruebas de aislamiento están escritas pero no se ejecutaron**: no había
  PostgreSQL disponible al escribirlas. Ver [10](10-PRUEBAS-Y-ESTADO.md).
- La política protege el dato, pero **no el archivo**: los documentos que se
  suban a disco se sirven después de autorizar.
- Un operador de la base con acceso SQL directo ve las filas de todas las
  agencias. La RLS protege a la aplicación, no a quien ya está adentro.

---

## 7. Resumen en una tabla

| | Esquema por agencia (descartado) | Columna + RLS (elegido) |
|---|---|---|
| Migraciones | Una por esquema × N agencias | Una sola |
| Pool de conexiones | Se dimensiona por agencia | Uno solo |
| Aislamiento | En la aplicación (`search_path`) | En la base de datos |
| Error de contexto | Falla en silencio | Falla ruidosamente |
| Riesgo de fuga | Alto (depende de cada consulta) | Bajo (la base lo impide) |
| Complejidad en Laravel | Alta | Baja |

---

## 8. Los dos roles de PostgreSQL

La base de esta aplicación corre con **dos** roles, no con uno. La separación es
deliberada: es lo que hace que el aislamiento entre agencias funcione en vez de
quedar como decoración.

### Qué es cada rol

| Rol | Nombre | De dónde sale | Para qué sirve | ¿Lo usa la aplicación? |
|---|---|---|---|---|
| **Arranque** | `agencia_viajes_admin` | `POSTGRES_USER` | Crear la base `agencia_viajes` y las tareas administrativas del despliegue | **Nunca** |
| **Aplicación** | `agencia_app` | `DB_USERNAME`, creado por `deploy/postgres/inicializar-rol.sh` | Todo el tráfico de la API | **Sí, este y solo este** |

Ojo con el nombre del rol de aplicación: es `agencia_app`, no
`agencia_viajes_app`, aunque la base sí se llame `agencia_viajes`. El rol de
arranque, en cambio, sí lleva el nombre completo.

El rol de arranque no es una convención del estudio: la imagen oficial de
PostgreSQL ejecuta `initdb --username="$POSTGRES_USER"`, así que ese rol nace
**superusuario**. Por eso su contraseña va en `DB_ADMIN_PASSWORD`, separada de
`DB_PASSWORD`.

### Por qué con un solo rol no servía

La documentación de PostgreSQL 18 lo dice de forma textual:

> *«Superusers and roles with the `BYPASSRLS` attribute always bypass the row
> security system when accessing a table. Table owners normally bypass row
> security as well, though a table owner can choose to be subject to row
> security with `ALTER TABLE ... FORCE ROW LEVEL SECURITY`.*

La segunda frase es la clave. `FORCE ROW LEVEL SECURITY` sujeta al **dueño** de
la tabla, pero **no al superusuario**. Mientras la aplicación conectara con el
rol de `POSTGRES_USER`, ninguna política de este documento se iba a aplicar: dos
agentes habrían visto los viajes una de la otra, sin error y sin aviso. El
aislamiento entre agencias era decorativo.

### Los atributos van todos escritos

`deploy/postgres/inicializar-rol.sh`, montado en
`/docker-entrypoint-initdb.d/`, crea el rol con `NOSUPERUSER`, `NOCREATEDB`,
`NOCREATEROLE`, `NOINHERIT` y `NOBYPASSRLS` **explícitos todos**, aunque
`NOBYPASSRLS` ya sea el valor por omisión.

Se escriben igual a propósito: un rol verificado no debería depender de un valor
por omisión que una versión nueva puede cambiar. El script también falla
ruidosamente si falta `POSTGRES_DB`, `APP_DB_ROLE` o `APP_DB_PASSWORD`.

### Por qué el rol de aplicación es dueño de la base

Justo después de crearlo, el script corre:

```sql
ALTER DATABASE agencia_viajes OWNER TO agencia_app;
```

Las migraciones de Laravel se ejecutan con el rol de la aplicación y necesitan
**crear tablas**. La única forma de darles ese permiso sin convertirlos en
superusuario es que ese rol sea el dueño de la base. Desde PostgreSQL 15 el
esquema `public` pertenece al dueño de la base, así que con esto el rol ya
puede crear tablas ahí.

Y este es el punto exacto del diseño: al ser el dueño, **las tablas quedan a su
nombre**, y por eso el `FORCE ROW LEVEL SECURITY` que usan las tablas sí lo
sujeto. Ser dueño no es saltarse la política; es lo que hace que la política lo
alcance. Un superusuario la saltearía siempre.

### Cómo se comprueba

```
php artisan viajes:verificar-aislamiento
```

El nombre del comando no lleva el de la aplicación: el prefijo es `viajes:`.
El comando consulta `pg_roles` sobre el rol de la conexión y falla con código 1
si ese rol es superusuario o tiene `BYPASSRLS`, y después revisa que la RLS,
las políticas y el contexto de agencia protejan los datos de cada agencia.

**Este comando no se ha ejecutado.** En el equipo donde se escribió este cambio
no había PostgreSQL ni Docker. El diseño es correcto y está sostenido en la
documentación oficial, pero nadie lo ha corrido. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).