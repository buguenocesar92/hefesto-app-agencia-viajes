<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 |--------------------------------------------------------------------------
 | Rutas web
 |--------------------------------------------------------------------------
 |
 | El backend de Laravel no tiene interfaz: la pantalla es Nuxt. Aqui solo
 | viven las rutas de Authentik (el login es un OAuth contra el proveedor de
 | identidad) y el cierre de sesion.
 |
 | Estas rutas SI usan cookie de sesion, asi que el grupo `web` de Laravel
 | aplica CSRF y el token va en el formulario.
 */

Route::get('auth/redirect', [\App\Http\Controllers\AuthController::class, 'redirigir'])
    ->name('auth.redirect');

Route::get('auth/callback', [\App\Http\Controllers\AuthController::class, 'callback'])
    ->name('auth.callback');

Route::post('auth/salir', [\App\Http\Controllers\AuthController::class, 'salir'])
    ->name('auth.salir');