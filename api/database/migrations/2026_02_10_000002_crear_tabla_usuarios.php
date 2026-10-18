<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agentes de la agencia.
 *
 * `subjekt_authentik` es el identificador del sujeto en Authentik (el campo
 * `sub` del token). Es la unica pieza que conecta la identidad externa con la
 * base: a partir de ahi, el rol y los permisos son de esta tabla, no de
 * Authentik.
 *
 * El indice unico sobre (tenant_id, email) impide que haya dos agentes con el
 * mismo correo en la misma agencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('subjekt_authentik', 255)->unique();
            $tabla->string('nombre', 160);
            $tabla->string('email', 200);

            // Rol dentro de la agencia: "agente" o "administrador".
            $tabla->string('rol', 32)->default('agente');
            $tabla->boolean('activo')->default(true);
            $tabla->timestampTz('ultimo_acceso')->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'email']);
            $tabla->index(['tenant_id', 'activo']);

            // La FK se declara aunque `agencias` no tenga RLS: el aislamiento
            // no lo da la FK, lo da la politica. La FK evita, ademas, dejar
            // huerfanos que nadie pueda limpiar.
            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};