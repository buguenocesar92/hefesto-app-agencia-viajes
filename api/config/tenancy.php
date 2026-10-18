<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Aislamiento entre agencias (multi-tenancy)
    |--------------------------------------------------------------------------
    |
    | Patron YA decidido y documentado en
    | apps/portal-autoservicio/docs/03-MULTITENANCY.md: columna `tenant_id` mas
    | Row Level Security de PostgreSQL, con `WITH CHECK`, `FORCE ROW LEVEL
    | SECURITY` y el contexto viajando con `set_config(..., true)`, es decir
    | con alcance de transaccion.
    |
    | En esta aplicacion la agencia es el tenant. La tabla de entrada al
    | sistema se llama `agencias`, no `tenants`, porque en viajes "tenant" no
    | significa nada para quien opera la agencia. El mecanismo es el mismo.
    |
    | Ver docs/03-MULTITENANCY-Y-ROLES.md.
    */

    'variable_contexto' => 'app.tenant_id',

    /*
    | Funcion de contexto en PostgreSQL. Debe LANZAR EXCEPCION si no hay
    | tenant: si devolviera NULL, una consulta sin contexto devolveria cero
    | filas sin avisar, y en el futuro alguien podria "arreglarla" devolviendo
    | NULL y abrir la puerta sin enterarse.
    */
    'funcion_contexto' => 'public.app_tenant_id()',

    /*
    | Tablas que DEBEN tener RLS. El comando `viajes:verificar-aislamiento`
    | falla si alguna quedo sin politica, y la migracion falla si alguna de
    | estas no tiene columna `tenant_id`.
    |
    | `agencias` NO esta en la lista a proposito: se consulta durante el
    | login, cuando todavia no hay sesion y por lo tanto no hay contexto que
    | poner. Se compensa con la vista publica `vw_agencias_publicas`. Ver
    | docs/03, seccion "La tabla que no lleva RLS".
    */
    'tablas_protegidas' => [
        'usuarios',
        'proveedores',
        'productos',
        'tarifas',
        'vuelos',
        'asientos',
        'reservas',
        'reserva_eventos',
        'reserva_pasajeros',
        'pasajeros',
        'documentos_viaje',
        'pagos',
        'politicas_nombres',
        'politicas_cancelacion',
        'configuraciones',
        'bitacora_accesos',
    ],

    /*
    | Dias que se conserva la bitacora de acceso a datos personales.
    | Ley 19.628. Ver docs/07.
    */
    'bitacora_retencion_dias' => (int) env('BITACORA_RETENCION_DIAS', 1825),

];