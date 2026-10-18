<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\DocumentoViaje;
use App\Models\Pasajero;
use App\Models\Reserva;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRUEBAS CRITICAS: una agencia no puede leer los datos de otra.
 *
 * Estas pruebas van contra PostgreSQL de verdad, porque el aislamiento esta
 * implementado con Row Level Security. Si se corren contra SQLite, pasan sin
 * comprobar nada.
 *
 * Aqui, ademas de las filas de negocio, se prueba el caso mas sensible de esta
 * aplicacion: el DOCUMENTO de un pasajero. Si una agencia pudiera leer los
 * documentos de otra, no seria un problema de aislamiento, seria una filtracion
 * de datos personales de personas que nunca compraron en esa agencia.
 *
 * Lo que se comprueba aca:
 *
 *  1. Sin contexto de agencia, las tablas devuelven un ERROR, no cero filas.
 *  2. Cada agencia ve SOLO sus filas.
 *  3. Un `id` de otra agencia no existe para este agente (404, no 403).
 *  4. No se puede escribir una fila en nombre de otra agencia.
 *  5. El contexto no sobrevive a la transaccion.
 */
class AislamientoDeDatosTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_contexto_de_agencia_la_consulta_falla_en_vez_de_traer_datos(): void
    {
        $primera = $this->crearAgencia('primera.cl');
        Reserva::factory()->create(['tenant_id' => $primera['agencia']->id]);

        // Se limpia el contexto a proposito: es la situacion de una consulta
        // que se escapara del middleware.
        //
        // Lo que se espera NO es "cero filas", sino un error. `app_tenant_id()`
        // lanza excepcion cuando no hay agencia, y la politica RLS aborta la
        // consulta. Devolver cero filas seria mas comodo de probar, pero
        // dejaria pasar el caso peligroso: que alguien "arregle" la funcion
        // devolviendo NULL y termine leyendo filas de la agencia equivocada sin
        // que nada lo delate.
        $this->contextoLimpio();

        $this->expectException(\Illuminate\Database\QueryException::class);

        Reserva::query()->count();
    }

    public function test_cada_agencia_solo_ve_sus_propias_reservas(): void
    {
        $primera = $this->crearAgencia('primera.cl');
        Reserva::factory()->count(3)->create(['tenant_id' => $primera['agencia']->id]);

        $segunda = $this->crearSegundaAgencia('segunda.cl');
        Reserva::factory()->count(2)->create(['tenant_id' => $segunda['agencia']->id]);

        $this->fijarAgencia($primera['agencia']->id);
        $this->assertSame(3, Reserva::query()->count());
        $this->assertSame($primera['agencia']->id, Reserva::query()->value('tenant_id'));

        $this->fijarAgencia($segunda['agencia']->id);
        $this->assertSame(2, Reserva::query()->count());
        $this->assertSame($segunda['agencia']->id, Reserva::query()->value('tenant_id'));
    }

    public function test_un_documento_de_otra_agencia_no_se_puede_leer(): void
    {
        $primera = $this->crearAgencia('primera.cl');
        $segunda = $this->crearSegundaAgencia('segunda.cl');

        $pasajeroAjeno = Pasajero::factory()->create(['tenant_id' => $segunda['agencia']->id]);

        $documentoAjeno = DocumentoViaje::factory()->create([
            'tenant_id' => $segunda['agencia']->id,
            'pasajero_id' => $pasajeroAjeno->id,
        ]);

        // Volvemos al contexto de la primera agencia.
        $this->fijarAgencia($primera['agencia']->id);

        // Con el contexto de la primera agencia, el documento de la segunda no
        // existe. No es que salga con datos vacios: no sale.
        $this->assertNull(DocumentoViaje::find($documentoAjeno->id));
        $this->assertSame(0, DocumentoViaje::query()->count());
    }

    public function test_la_reserva_de_otra_agencia_no_se_puede_leer_ni_escribir(): void
    {
        $primera = $this->crearAgencia('primera.cl');
        Reserva::factory()->create(['tenant_id' => $primera['agencia']->id]);

        $segunda = $this->crearSegundaAgencia('segunda.cl');
        $reservaAjena = Reserva::factory()->create(['tenant_id' => $segunda['agencia']->id]);

        $this->fijarAgencia($primera['agencia']->id);

        $this->assertNull(Reserva::find($reservaAjena->id));

        // Y tampoco se puede tocar: la politica tiene WITH CHECK.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('reservas')
            ->where('id', $reservaAjena->id)
            ->update(['origen' => 'secuestrada']);
    }

    public function test_no_se_puede_insertar_una_fila_con_la_agencia_de_otra(): void
    {
        $primera = $this->crearAgencia('primera.cl');
        $segunda = $this->crearSegundaAgencia('segunda.cl');

        // Contexto de la primera agencia, pero se intenta guardar una reserva
        // de la segunda. La politica WITH CHECK lo tiene que impedir.
        $this->fijarAgencia($primera['agencia']->id);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Reserva::query()->create([
            'tenant_id' => $segunda['agencia']->id,
            'folio' => 'V-999999',
            'producto_id' => $primera['agencia']->id,
            'estado' => 'pendiente',
            'origen' => 'mostrador',
            'total_centimos' => 0,
            'creado_en' => now(),
        ]);
    }

    public function test_el_contexto_de_agencia_no_sobrevive_a_la_transaccion(): void
    {
        $agencia = $this->crearAgencia('primera.cl');
        Reserva::factory()->create(['tenant_id' => $agencia['agencia']->id]);

        $contexto = new ContextoTenant();

        $conteo = $contexto->ejecutarAislado(
            $agencia['agencia']->id,
            fn (): int => Reserva::query()->count()
        );

        $this->assertSame(1, $conteo);
        $this->assertNull(
            $contexto->tenantIdEnConexion(),
            'La variable de contexto debe desaparecer al terminar la transaccion. '
            . 'Si queda, el worker de colas podria leer datos de otra agencia.'
        );
    }

    public function test_la_funcion_de_contexto_falla_cerrada_sin_agencia(): void
    {
        $this->contextoLimpio();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::select('SELECT public.app_tenant_id()');
    }

    public function test_un_agente_de_otra_agencia_no_ve_los_pasajeros_por_la_api(): void
    {
        $primera = $this->crearAgencia('primera.cl');
        $segunda = $this->crearSegundaAgencia('segunda.cl');

        $pasajeroAjeno = Pasajero::factory()->create(['tenant_id' => $segunda['agencia']->id]);

        $this->comoAgente($primera['agente']);

        // 404 y no 403: un 403 confirmaria que el recurso existe. Y de hecho
        // para esta agencia no existe: la RLS no deja ni que se vea el id.
        $this->getJson('/api/v1/pasajeros/' . $pasajeroAjeno->id)
            ->assertNotFound();
    }

    public function test_lista_de_pasajeros_no_devuelve_documentos_de_otra_agencia(): void
    {
        $primera = $this->crearAgencia('primera.cl');
        $segunda = $this->crearSegundaAgencia('segunda.cl');

        Pasajero::factory()->create([
            'tenant_id' => $primera['agencia']->id,
            'nombre_declarado' => 'Pasajero De La Primera',
        ]);

        Pasajero::factory()->create([
            'tenant_id' => $segunda['agencia']->id,
            'nombre_declarado' => 'Pasajero De La Segunda',
        ]);

        $this->comoAgente($primera['agente']);

        $respuesta = $this->getJson('/api/v1/pasajeros')->assertOk();

        $nombres = array_column($respuesta->json('data'), 'nombre_declarado');

        $this->assertContains('Pasajero De La Primera', $nombres);
        $this->assertNotContains('Pasajero De La Segunda', $nombres);
    }
}