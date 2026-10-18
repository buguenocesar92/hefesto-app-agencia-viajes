# 10 - Pruebas y estado

Este documento separa, sin suavizar, **lo que se comprobó** de **lo que no**.

---

## 1. Estado, en una frase

**Código escrito, sin ejecutar.**

En el entorno donde se escribió esta aplicación **no había PHP, ni Composer, ni
PostgreSQL, ni Docker**. Todo lo que hay en `api/tests/` está escrito y no se ha
ejecutado nunca.

---

## 2. Lo que SÍ se ejecutó de verdad

| Comprobación | Herramienta | Resultado |
|---|---|---|
| Los scripts de shell son sintácticamente válidos | `bash -n` | **Correcto** en `deploy/api/docker/entrypoint.sh` |
| Los archivos de configuración son válidos | Node.js y XML | **Correcto** en `composer.json`, `package.json`, `tsconfig.json`, `phpunit.xml` |

Eso es todo. Dos comprobaciones, y ninguna toca el comportamiento de la
aplicación.

### Lo que eso NO cubre

- Que las migraciones corran.
- Que el índice de exclusión se pueda crear.
- Que la RLS filtre de verdad.
- Que los tests pasen.
- Que la interfaz cargue.

**Nada de eso está comprobado.**

---

## 3. Lo que NO se ejecutó

### 3.1 Las pruebas de aislamiento

`api/tests/Feature/Aislamiento/AislamientoDeDatosTest.php`

**Estado: escritas, no ejecutadas.**

Requieren PostgreSQL de verdad, porque el aislamiento está implementado con Row
Level Security, que es una función de PostgreSQL. Contra SQLite pasarían **sin
comprobar nada**.

Comprueban nueve cosas:

| Prueba | Qué garantiza |
|---|---|
| `test_sin_contexto_de_agencia_la_consulta_falla_en_vez_de_traer_datos` | La función de contexto **falla cerrada** |
| `test_cada_agencia_solo_ve_sus_propias_reservas` | Cada agencia ve solo sus filas |
| `test_un_documento_de_otra_agencia_no_se_puede_leer` | **Los documentos de otra agencia son invisibles** |
| `test_la_reserva_de_otra_agencia_no_se_puede_leer_ni_escribir` | Ni leer ni escribir |
| `test_no_se_puede_insertar_una_fila_con_la_agencia_de_otra` | La política tiene `WITH CHECK` |
| `test_el_contexto_de_agencia_no_sobrevive_a_la_transaccion` | El worker de colas no arrastra contexto |
| `test_la_funcion_de_contexto_falla_cerrada_sin_agencia` | Falla ruidosamente |
| `test_un_agente_de_otra_agencia_no_ve_los_pasajeros_por_la_api` | 404, no 403 |
| `test_lista_de_pasajeros_no_devuelve_documentos_de_otra_agencia` | El listado está filtrado |

### 3.2 Las pruebas de asientos

`api/tests/Feature/Asientos/BloqueoDeAsientosTest.php`

**Estado: escritas, no ejecutadas.**

| Prueba | Qué garantiza |
|---|---|
| `test_la_restriccion_de_exclusion_existe_y_tiene_la_forma_correcta` | La garantía está puesta |
| `test_la_extension_btree_gist_esta_instalada` | La extensión existe |
| `test_el_segundo_intento_de_tomar_el_mismo_asiento_falla` | El segundo falla |
| `test_las_peticiones_de_api_responden_409_a_un_asiento_ocupado` | La API responde 409 |
| `test_un_asiento_liberado_vuelve_a_estar_disponible` | Liberar devuelve el asiento |
| **`test_dos_conexiones_competiendo_dejan_un_solo_dueno_del_asiento`** | **La concurrencia real** |
| `test_un_bloqueo_vencido_no_impide_tomar_el_asiento` | El TTL no deja asientos muertos |
| `test_el_mapa_de_asientos_se_lee_de_la_fila_y_no_de_un_cache` | El mapa no se inventa |

**Sobre la prueba de concurrencia**, con honestidad: la prueba
`test_dos_conexiones_competiendo...` abre una **segunda conexión real** de
PostgreSQL, que es lo que hace que valga. Con la misma conexión, PHP serializa
las transacciones y la prueba pasaría aunque la base no garantizara nada.

Aun así, **esa prueba no se ejecutó**, y aun ejecutándose cubre el caso del
`UPDATE` sobre una fila existente, no el de dos `INSERT` simultáneos de una fila
nueva (que es donde la exclusión actúa de verdad). Para ese caso haría falta un
hilo con `pcntl_fork` o dos procesos, y **no está escrito**.

### 3.3 Las pruebas del nombre del boleto

`api/tests/Feature/Boleto/NombreBoletoDesdeDocumentoTest.php`

**Estado: escritas, no ejecutadas.**

| Prueba | Qué garantiza |
|---|---|
| `test_el_nombre_del_boleto_sale_del_documento_y_no_del_nombre_declarado` | **El producto**: "Rodrigo" → "Rodrigo Andrés Pérez Muñoz" |
| `test_el_sistema_no_transforma_el_nombre_del_documento` | No normaliza, no invierte |
| `test_un_pasajero_puede_tener_varios_documentos_y_cada_viaje_usa_el_suyo` | Cada viaje usa su documento |
| `test_la_comparacion_automatica_no_verifica_sola` | **La automática nunca verifica** |
| `test_sin_politica_de_nombres_el_nombre_queda_pendiente_y_no_se_inventa_una_regla` | Falla cerrado |
| `test_no_se_puede_emitir_una_reserva_con_nombres_sin_verificar` | **El control que evita el problema** |
| `test_emitir_es_posible_cuando_un_agente_verifico_el_nombre` | El camino feliz |
| `test_el_nombre_boleto_queda_copiado_y_no_cambia_si_se_actualiza_el_documento` | La copia es un hecho |

### 3.4 Las pruebas de pagos

`api/tests/Feature/Pagos/EstadoDeCuentaTest.php` y
`api/tests/Unit/CalculoDeEstadoPagoTest.php`

**Estado: escritas, no ejecutadas.**

Las de `Unit` son puras (no tocan la base) y se podrían correr sin PostgreSQL.
**Tampoco se ejecutaron**: no había PHP.

### 3.5 La máquina de estados

`api/tests/Unit/MaquinaDeEstadosReservaTest.php`

**Estado: escrita, no ejecutada.** Puras: no tocan la base.

### 3.6 El comando de verificación

`php artisan viajes:verificar-aislamiento`

**Estado: escrito, no ejecutado.** Es el que comprueba el rol, las 16 tablas, la
función de contexto, la lectura aislada, el índice de exclusión y la vista
pública. **No hay evidencia de que devuelva 0.**

---

## 4. Cómo ejecutarlas cuando haya un entorno

```bash
cd apps/agencia-viajes/api

composer install

# Crear la base de pruebas
createdb agencia_viajes_test

php artisan test
```

O por suite, en el orden de prioridad:

```bash
php artisan test --testsuite=Aislamiento
php artisan test --testsuite=Asientos
php artisan test --testsuite=Boleto
php artisan test --testsuite=Pagos
php artisan test --testsuite=Unit
```

**Requisitos que no son opcionales:**

| Requisito | Por qué |
|---|---|
| PostgreSQL de verdad | La RLS y la exclusión son funciones de PostgreSQL |
| La extensión `btree_gist` | Sin ella no hay índice de exclusión sobre `uuid` |
| El rol sin `BYPASSRLS` | Con él, las pruebas de aislamiento pasan sin comprobar nada |

### Lo primero que hay que hacer después de ejecutarlas

```bash
php artisan viajes:verificar-aislamiento
```

**Si no devuelve 0, no se abre al público.** Un despliegue con la RLS mal puesta
funciona exactamente igual que uno bien puesta, y por eso solo el comando lo
detecta.

---

## 5. Qué falta por escribir

No está escrito, y habría que escribirlo:

| Prueba | Por qué importa |
|---|---|
| **Cancelación con la política correcta** | El cálculo del costo de cancelar es código con aritmética y no tiene ninguna prueba |
| **Cancelación sin política → se rechaza** | Es el comportamiento prudente que sostiene [05](05-CONFIGURACION.md) |
| **Dos `INSERT` simultáneos en hilos separados** | El caso donde la exclusión actúa de verdad |
| **La interfaz de Nuxt** | No hay pruebas de componente. La pantalla de asientos no se probó |
| **El flujo de Authentik completo** | El `callback` no se probó |
| **El enmascarado en la API** | Se comprueba indirectamente; falta una prueba directa de que `numero` no aparece en el listado |
| **La expiración del bloqueo con el TTL vencido** | Hay una prueba del servicio; falta la del recorrido completo |

---

## 6. Los riesgos, en orden de gravedad

### 1. El índice de exclusión podría no poder crearse

Es el riesgo más grande porque **afecta al producto**. `EXCLUDE USING gist` sobre
columnas `uuid` requiere `btree_gist`, y el rol de la aplicación tiene que poder
crear la extensión.

**Si no puede**, la migración falla en voz alta, que es el comportamiento
correcto. Pero si alguien creó la base con el rol equivocado, o cambió los
permisos después, la aplicación no arranca.

**Mitigación:** el `entrypoint.sh` avisa si la extensión falta, y
`viajes:verificar-aislamiento` la comprueba.

### 2. La lógica de `ServicioAsientos::tomar()` nunca se ejecutó

Es el camino crítico del producto y es el código más complejo. Hay tres cosas
que **no se pueden afirmar** sin ejecutarlo:

- Que la cadena de `catch` capture correctamente la violación `23P01`.
- Que la búsqueda con `lockForUpdate` se comporte como se espera con el nivel de
  aislamiento por defecto.
- Que el candado de Redis no introduzca una condición de carrera propia.

### 3. Las rutas de la API no se probaron de punta a punta

Los controladores usan `authorize()` con habilidades que dependen de las
Policies, y las Policies están registradas a mano en `AuthServiceProvider`. Un
nombre de habilidad mal escrito haría que `authorize()` fallara **en tiempo de
ejecución**, no de compilación.

### 4. La semilla no se ejecutó

Si hay un error de sintaxis o una columna mal nombrada, la agente no puede
arrancar con datos de ejemplo.

### 5. El proxy de Nuxt no se probó

`web/server/api/[...ruta].ts` reenvía cabeceras y cuerpos. Si reenvía de más o
de menos, el CSRF o la auditoría fallan en silencio.

### 6. Nada se revisó por seguridad

No hay revisión externa, ni pruebas de penetración, ni análisis de
dependencias. Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

---

## 7. Qué haría antes de poner esto con datos reales de personas

En orden:

```
1.  Levantar el entorno y correr las pruebas.
    Si alguna falla, arreglar antes de seguir.

2.  php artisan viajes:verificar-aislamiento  →  código 0

3.  Cargar la semilla y recorrer a mano el flujo completo:
    crear reserva con un pasajero y un documento,
    ver el nombre del boleto,
    intentar emitir (debe fallar),
    verificar el nombre,
    emitir,
    registrar un pago parcial,
    ver el saldo.

4.  Probar la concurrencia de verdad: dos navegadores, dos agentes,
    el mismo vuelo, el mismo asiento, al mismo tiempo.
    UNO tiene que ganar y el otro tiene que recibir 409.

5.  Probar el aislamiento con dos agencias reales.

6.  Restaurar un respaldo en un ambiente aislado.

7.  Recién ahí, datos reales.
```

El paso 4 es el que más importa y el que menos se hace. La concurrencia se
prueba mal en un ambiente controlado, y el problema aparece cuando hay dos
agentes reales vendiendo al mismo tiempo, un martes a las 11 de la mañana.

---

## Los dos roles de PostgreSQL: escritos, no ejecutados

La separación entre el rol de arranque (`agencia_viajes_admin`) y el rol de la
aplicación (`agencia_app`) está escrita en el `compose`, en
`deploy/postgres/inicializar-rol.sh` y en
[03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md). **No se ha ejecutado
ninguna vez.**

En el equipo donde se escribió este cambio no había PostgreSQL ni Docker. Queda
pendiente:

1. Levantar el `compose` con el volumen de PostgreSQL vacío y confirmar que el
   rol `agencia_app` se crea sin error.
2. Confirmar con `SELECT rolname, rolsuper, rolbypassrls FROM pg_roles` que el
   rol de la conexión no es superusuario ni salta la RLS.
3. Correr `php artisan viajes:verificar-aislamiento` y confirmar que sale con
   código 0.
4. Probar el escenario que motiva todo el cambio: una agencia intentando leer
   viajes de otra.

Lo único verificado es estático: `bash -n` pasa sobre el script y el `compose`
es YAML válido, ambas cosas comprobadas por `tools/validar_apps.py`. El
razonamiento se apoya en la documentación oficial de PostgreSQL 18, no en una
ejecución.