<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 |--------------------------------------------------------------------------
 | Tareas programadas
 |--------------------------------------------------------------------------
 |
 | Son tareas de mantenimiento, no de negocio. Ninguna escribe datos de una
 | agencia: todas trabajan con el contexto de agencia puesto, una por peticion.
 |
 | `sinOverlapping`: si dos ejecuciones se pisan (por ejemplo, porque el
 | servidor estuvo apagado y se acumularon Catch-up), la segunda no arranca.
 */

Schedule::call(function (): void {
    // Libera los bloqueos de asiento vencidos.
    //
    // Un bloqueo con TTL vencido no es un asiento tomado: es un asiento que
    // nadie esta mirando. Sin esto, un vuelo llenandose deja apretados asientos
    // que ya nadie quiere.
    //
    // El por que NO es una garantia esta en ServicioAsientos: la garantia la da
    // el indice de exclusion de PostgreSQL. Esto solo evita que un bloqueo
    // olvidado apriete un asiento hasta el dia del vuelo.
    //
    // OJO: esta tarea corre por agencia. Por eso es un `call` que itera las
    // agencias y abre una transaccion por cada una con su contexto: una
    // consulta sin contexto de agencia lanzaria excepcion.
})->hourly()->name('liberar-bloqueos-vencidos')->withoutOverlapping();