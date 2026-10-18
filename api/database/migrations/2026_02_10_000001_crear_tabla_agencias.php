<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La agencia de viajes: el tenant.
 *
 * NO lleva Row Level Security, a proposito. Se consulta durante el login,
 * cuando todavia no hay sesion y por lo tanto no hay `app.tenant_id` que poner
 * en la conexion. La compensacion es la vista `vw_agencias_publicas`, que
 * expone solo slug, nombre y activo. Ver docs/03.
 *
 * `dominio_correo` es el vinculo con Authentik: es el dominio con el que la
 * persona entra y del que se deduce a que agencia pertenece.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencias', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->string('slug', 80)->unique();
            $tabla->string('nombre', 160);
            $tabla->string('razon_social', 200)->nullable();
            $tabla->string('rut', 20)->nullable();

            // Del dominio de correo se deduce la agencia al entrar. Se guarda
            // en claro porque es un dominio, no un dato personal, pero igual
            // no se devuelve en las respuestas de la API: esta en $hidden.
            $tabla->string('dominio_correo', 160)->nullable()->index();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agencias');
    }
};