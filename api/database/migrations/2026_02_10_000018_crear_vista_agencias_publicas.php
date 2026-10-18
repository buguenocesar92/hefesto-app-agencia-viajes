<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista publica de agencias: la compensacion por que `agencias` no lleva RLS.
 *
 * QUE EXPONE Y QUE NO
 * -------------------
 * Expone SOLO tres columnas: `slug`, `nombre` y `activo`. No expone razon
 * social, RUT, correo ni telefono. Antes del login, la aplicacion no lee
 * `agencias` crudo: lee esta vista.
 *
 * POR QUE HACE FALTA
 * ------------------
 * `agencias` se consulta durante el login, cuando todavia no hay sesion y por
 * lo tanto no hay `app.tenant_id` que poner en la conexion. Sin una politica
 * que lo compare contra algo, no se puede poner RLS en esa tabla.
 *
 * SEGUNDA COMPENSACION: el rol de la aplicacion no es superusuario ni tiene
 * BYPASSRLS. Quien tenga acceso SQL directo a la base puede leer `agencias`
 * entera; el comando `viajes:verificar-aislamiento` lo comprueba.
 *
 * RIESGO RESIDUAL, DICHO CON TODAS LAS LETRAS
 * --------------------------------------------
 * Un `slug` se puede enumerar antes de autenticarse, porque aparece en la URL
 * de entrada y en los enlaces que la agencia reparte. Un atacante puede
 * descubrir que la agencia "Andes Travel" usa el sistema. No puede ver mas que
 * su nombre. Si eso se considera inaceptable, la alternativa es dejar el campo
 * en blanco hasta el login y resolver siempre por dominio de correo. Queda
 * anotado como decision pendiente, no como algo resuelto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW public.vw_agencias_publicas AS
            SELECT
                slug,
                nombre,
                activo
            FROM public.agencias;
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON VIEW public.vw_agencias_publicas IS
            'Compensacion por que la tabla agencias no lleva RLS: expone solo slug, nombre y activo, y solo se usa antes del login.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS public.vw_agencias_publicas');
    }
};