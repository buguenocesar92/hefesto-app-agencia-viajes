<?php

declare(strict_types=1);

use App\Http\Controllers\AsientoController;
use App\Http\Controllers\PagoController;
use App\Http\Controllers\PasajeroController;
use App\Http\Controllers\ReservaController;
use App\Http\Controllers\VerificacionNombreController;
use App\Http\Controllers\VueloController;
use Illuminate\Support\Facades\Route;

/*
 |--------------------------------------------------------------------------
 | Rutas de la API
 |--------------------------------------------------------------------------
 |
 | Prefijo: /api/v1 (definido en bootstrap/app.php).
 |
 | El middleware de grupo `api` ya trae, en este orden:
 |   1. ResolverTenant  -> fija la agencia con alcance de transaccion
 |   2. AuditarAcceso   -> anota los accesos a datos personales
 | y despues `auth`, que exige sesion.
 |
 | Rutas que NO son de negocio y por eso viven fuera del grupo protegido:
 |   - `salud`: un chequeo de vida del servicio, sin datos que filtrar.
 |   - `agencias-publicas`: el listado minimo para la pantalla de entrada. Lee
 |     la vista `vw_agencias_publicas`, que expone solo slug, nombre y activo.
 |     Ver docs/03, seccion "La tabla que no lleva RLS".
 |
 | Nota sobre CSRF: el grupo `api` no aplica CSRF porque no usa cookies de
 | sesion. La sesion viaja por cookie HttpOnly y las peticiones pasan por el
 | backend-for-frontend de Nuxt, que reenvia el token como cabecera
 | `X-XSRF-TOKEN`. Ver docs/07.
 |
 */

// ---------------------------------------------------------------------------
// Sin proteccion: no tocan datos de negocio.
// ---------------------------------------------------------------------------

Route::get('salud', fn (): array => [
    'estado' => 'ok',
    'servicio' => 'agencia-viajes',
]);

Route::get('agencias-publicas', VueloController::class . '@agenciasPublicas');

// ---------------------------------------------------------------------------
// Todo lo demas exige agente autenticado Y contexto de agencia.
// ---------------------------------------------------------------------------

Route::middleware('auth')->group(function (): void {

    // -----------------------------------------------------------------------
    // Reservas
    // -----------------------------------------------------------------------
    Route::get('reservas', [ReservaController::class, 'index']);
    Route::post('reservas', [ReservaController::class, 'store']);

    Route::get('reservas/{reserva}', [ReservaController::class, 'show']);
    Route::get('reservas/{reserva}/eventos', [ReservaController::class, 'eventos']);
    Route::post('reservas/{reserva}/estado', [ReservaController::class, 'cambiarEstado']);

    // -----------------------------------------------------------------------
    // Verificacion del nombre del boleto.
    //
    // Rutas aparte y sin ningun endpoint que escriba el nombre del boleto a
    // mano: el nombre se arma desde el documento y aqui solo se ACEPTA O SE
    // RECHAZA. Es la frontera del producto. Ver
    // App\Http\Controllers\VerificacionNombreController.
    // -----------------------------------------------------------------------
    Route::get('reservas/{reserva}/nombres-pendientes', [VerificacionNombreController::class, 'pendientes']);
    Route::post('reserva-pasajeros/{pasajeroReserva}/verificar-nombre', [VerificacionNombreController::class, 'store']);

    // -----------------------------------------------------------------------
    // Asientos
    // -----------------------------------------------------------------------
    Route::get('vuelos/{vuelo}/asientos', [AsientoController::class, 'index']);

    // Tomar un asiento. Si esta ocupado, responde 409: dos compras
    // simultaneas no pueden salir las dos con el mismo asiento.
    Route::post('vuelos/{vuelo}/asientos/{numero}/tomar', [AsientoController::class, 'bloquear']);
    Route::delete('asientos/{asiento}', [AsientoController::class, 'liberar']);

    // -----------------------------------------------------------------------
    // Pasajeros y documentos
    //
    // `documento` es el UNICO endpoint que devuelve el numero completo. Pide
    // motivo y queda en la bitacora. Los demas devuelven el numero
    // enmascarado. Ver docs/07.
    // -----------------------------------------------------------------------
    Route::get('pasajeros', [PasajeroController::class, 'index']);
    Route::post('pasajeros', [PasajeroController::class, 'store']);
    Route::get('pasajeros/{pasajero}', [PasajeroController::class, 'show']);
    Route::post('pasajeros/{pasajero}/documentos', [PasajeroController::class, 'storeDocumento']);
    Route::get('pasajeros/{pasajero}/documentos/{documento}', [PasajeroController::class, 'documento']);

    // -----------------------------------------------------------------------
    // Pagos
    // -----------------------------------------------------------------------
    Route::get('reservas/{reserva}/pagos', [PagoController::class, 'index']);
    Route::post('reservas/{reserva}/pagos', [PagoController::class, 'store']);
    Route::post('reservas/{reserva}/pagos/{pago}/anular', [PagoController::class, 'anular']);
    Route::get('reservas/{reserva}/estado-de-cuenta', [PagoController::class, 'estadoDeCuenta']);

    // La pantalla de la plata: todo lo que esta pagado a medias.
    Route::get('pagos/incompletos', [PagoController::class, 'incompletos']);
});