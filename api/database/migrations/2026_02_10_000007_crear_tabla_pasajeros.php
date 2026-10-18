<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pasajeros.
 *
 * LO QUE NO ESTA EN ESTA TABLA, Y POR QUE
 * ---------------------------------------
 * No hay columna de documento. El documento con el que se viaja vive en
 * `documentos_viaje`, como una fila propia con historial.
 *
 * Un pasajero puede viajar en junio con el pasaporte A, renovarlo, y viajar en
 * diciembre con el pasaporte B. Si el documento fuera una columna, la segunda
 * vez se sobreescribiria la primera y el boleto de junio quedaria apuntando al
 * documento equivocado: exactamente el problema con el que nacio este
 * producto.
 *
 * `nombre_declarado` es como el pasajero se escribe a si mismo. Puede estar
 * abreviado ("Rodrigo") y NO es el nombre del boleto: el del boleto sale del
 * documento. Ver App\Services\Viajes\ConstruccionBoleto.
 *
 * `email`, `telefono` y `fecha_nacimiento` son datos personales y estan en
 * `$hidden` del modelo: no salen salvo que se pidan a proposito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pasajeros', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            $tabla->string('nombre_declarado', 200);

            $tabla->string('email', 200)->nullable();
            $tabla->string('telefono', 32)->nullable();
            $tabla->date('fecha_nacimiento')->nullable();
            $tabla->string('nacionalidad', 80)->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            // Indice para buscar a una persona por su nombre declarado. Es la
            // busqueda del mostrador: "la persona que se dijo Rodrigo Perez".
            $tabla->index(['tenant_id', 'nombre_declarado']);
            $tabla->foreign('tenant_id')->references('id')->on('agencias')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pasajeros');
    }
};