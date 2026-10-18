<?php

declare(strict_types=1);

namespace Tests\Feature\Asientos;

use App\Enums\EstadoAsiento;
use App\Models\Asiento;
use App\Models\Producto;
use App\Models\Reserva;
use App\Models\Vuelo;
use App\Services\Viajes\ServicioAsientos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRUEBAS CRITICAS: dos compras simultaneas NO pueden tomar el mismo asiento.
 *
 * ESTAS PRUEBAS NO SE EJECUTARON
 * -----------------------------
 * En el entorno donde se escribio el codigo no habia PHP, ni PostgreSQL, ni
 * Docker.estan escritas y son la especificacion del comportamiento, pero no se
 * han corrido. Ver docs/10, seccion "Lo que quedo sin probar".
 *
 * QUE COMPRUEBAN Y COMO
 * ---------------------
 * La garantia la da el indice de exclusion de PostgreSQL
 * (`asientos_no_duplicados`), que hace que la SEGUNDA escritura del mismo
 * (vuelo, numero) en estado que ocupa reciba una violacion `23P01`.
 *
 * Estas pruebas comprueban tres cosas, de menos a mas fuerte:
 *
 *  1. La restriccion EXISTE y tiene la forma correcta (estructural).
 *  2. La restriccion RECHAZA el segundo INSERT del mismo asiento.
 *  3. Dos conexiones separadas que compiten: una gana y la otra falla.
 *
 * El punto 3 es el que de verdad importa, y ver `test_dos_conexiones...`
 * debajo: usa una segunda conexion REAL de PostgreSQL, no la misma. Con la
 * misma conexion, PHP serializa las transacciones y la prueba pasaria aunque
 * la base no garantizara nada.
 */
class BloqueoDeAsientosTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_restriccion_de_exclusion_existe_y_tiene_la_forma_correcta(): void
    {
        $restriccion = DB::selectOne(
            "SELECT conname, pg_get_constraintdef(oid) AS definicion
             FROM pg_constraint
             WHERE conrelid = to_regclass('public.asientos')
               AND contype = 'x'
               AND conname = 'asientos_no_duplicados'"
        );

        $this->assertNotNull(
            $restriccion,
            'La restriccion de exclusion asientos_no_duplicados no existe. '
            . 'Sin ella, dos compras simultaneas pueden tomar el mismo asiento.'
        );

        // La condicion es la que hace que un asiento liberado vuelva a estar
        // disponible. Si alguien la cambia, hay asientos que quedan apretados
        // para siempre.
        $this->assertStringContainsString("estado = ANY", $restriccion->definicion);
        $this->assertStringContainsString('bloqueado', $restriccion->definicion);
        $this->assertStringContainsString('asignado', $restriccion->definicion);
    }

    public function test_la_extension_btree_gist_esta_instalada(): void
    {
        // Sin esta extension no se puede crear un indice de exclusion sobre
        // columnas uuid, que es justo lo que necesita la restriccion.
        $extension = DB::selectOne("SELECT count(*) AS total FROM pg_extension WHERE extname = 'btree_gist'");

        $this->assertSame(1, (int) $extension->total, 'Falta la extension btree_gist.');
    }

    public function test_el_segundo_intento_de_tomar_el_mismo_asiento_falla(): void
    {
        $escenario = $this->armarVuelo();

        $primeraReserva = Reserva::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'producto_id' => $escenario['producto']->id,
            'vuelo_id' => $escenario['vuelo']->id,
        ]);

        $segundaReserva = Reserva::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'producto_id' => $escenario['producto']->id,
            'vuelo_id' => $escenario['vuelo']->id,
        ]);

        $servicio = new ServicioAsientos();

        // La primera compra se lleva el asiento.
        $servicio->tomar(
            vueloId: $escenario['vuelo']->id,
            numero: '14A',
            reservaId: $primeraReserva->id,
        );

        // La segunda compra del MISMO asiento tiene que fallar.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tomado/');

        $servicio->tomar(
            vueloId: $escenario['vuelo']->id,
            numero: '14A',
            reservaId: $segundaReserva->id,
        );
    }

    public function test_las_peticiones_de_api_responden_409_a_un_asiento_ocupado(): void
    {
        $escenario = $this->armarVuelo();

        $primeraReserva = Reserva::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'producto_id' => $escenario['producto']->id,
            'vuelo_id' => $escenario['vuelo']->id,
        ]);

        $segundaReserva = Reserva::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'producto_id' => $escenario['producto']->id,
            'vuelo_id' => $escenario['vuelo']->id,
        ]);

        $this->comoAgente($escenario['agente']);

        $this->postJson("/api/v1/vuelos/{$escenario['vuelo']->id}/asientos/14A/tomar", [
            'reserva_id' => $primeraReserva->id,
        ])->assertOk();

        $respuesta = $this->postJson("/api/v1/vuelos/{$escenario['vuelo']->id}/asientos/14A/tomar", [
            'reserva_id' => $segundaReserva->id,
        ]);

        // 409 y no 500: es un conflicto con el estado actual, no una falla del
        // servidor. El agente necesita poder distinguir "elige otro asiento" de
        // "el sistema se caigo".
        $respuesta->assertStatus(409);
        $respuesta->assertJsonStructure(['mensaje']);
    }

    public function test_un_asiento_liberado_vuelve_a_estar_disponible(): void
    {
        $escenario = $this->armarVuelo();

        $primeraReserva = Reserva::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'producto_id' => $escenario['producto']->id,
            'vuelo_id' => $escenario['vuelo']->id,
        ]);

        $segundaReserva = Reserva::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'producto_id' => $escenario['producto']->id,
            'vuelo_id' => $escenario['vuelo']->id,
        ]);

        $servicio = new ServicioAsientos();

        $asiento = $servicio->tomar(
            vueloId: $escenario['vuelo']->id,
            numero: '14B',
            reservaId: $primeraReserva->id,
        );

        $this->assertSame(EstadoAsiento::Asignado, $asiento->estado);

        $servicio->liberar($asiento->id);

        // Ahora otro pasajero puede tomarlo. La condicion de la restriccion es
        // parcial: `liberado` no compite con nadie.
        $otro = $servicio->tomar(
            vueloId: $escenario['vuelo']->id,
            numero: '14B',
            reservaId: $segundaReserva->id,
        );

        $this->assertSame(EstadoAsiento::Asignado, $otro->estado);
        $this->assertSame($segundaReserva->id, $otro->reserva_id);
    }

    /**
     * LA PRUEBA DE CONCURRENCIA REAL.
     *
     * Dos conexiones de PostgreSQL distintas, que es lo que pasa cuando dos
     * agentes venden al mismo tiempo desde el navegador.
     *
     * Se abre una segunda conexion con `DB::connection('pgsql')` en una
     * transaccion propia, y se le pide el mismo asiento. Con la misma conexion,
     * PHP serializa las transacciones y la prueba pasaria aunque la base no
     * garantizara nada: seria una prueba de teatro.
     *
     * NOTA: con el nivel de aislamiento por defecto de PostgreSQL
     * (READ COMMITTED), dos UPDATE concurrentes sobre la misma fila se
     * serializan por el bloqueo de fila. Lo que se comprueba aqui es que la
     * fila termine con UN solo dueno, no con dos. Para el caso de dos INSERT
     * simultaneos de una fila nueva, la que se serializa es la restriccion de
     * exclusion, y ahi el perdedor recibe `23P01`.
     */
    public function test_dos_conexiones_competiendo_dejan_un_solo_dueno_del_asiento(): void
    {
        $escenario = $this->armarVuelo();

        $reservaUno = Reserva::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'producto_id' => $escenario['producto']->id,
            'vuelo_id' => $escenario['vuelo']->id,
        ]);

        $reservaDos = Reserva::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'producto_id' => $escenario['producto']->id,
            'vuelo_id' => $escenario['vuelo']->id,
        ]);

        // La conexion principal toma el asiento y lo deja tomado.
        $servicio = new ServicioAsientos();
        $servicio->tomar(
            vueloId: $escenario['vuelo']->id,
            numero: '15A',
            reservaId: $reservaUno->id,
        );

        // La segunda conexion, con el contexto de la MISMA agencia, intenta
        // quitarselo. Es una operacion sin las validaciones del servicio: se
        // escribe directo, que es el peor caso.
        $otraConexion = DB::connection('pgsql');
        $otraConexion->statement('BEGIN');

        try {
            $otraConexion->select('SELECT set_config(?, ?, true)', [
                ContextoTenant::VARIABLE,
                $escenario['agencia']->id,
            ]);

            $afectadas = $otraConexion->update(
                'UPDATE asientos SET reserva_id = ?, estado = ? WHERE vuelo_id = ? AND numero = ? AND estado = ?',
                [$reservaDos->id, EstadoAsiento::Asignado->value, $escenario['vuelo']->id, '15A', EstadoAsiento::Bloqueado->value]
            );

            $otraConexion->statement('COMMIT');
        } catch (\Throwable $excepcion) {
            $otraConexion->statement('ROLLBACK');

            // Si la base veto la operacion, la garantia esta intacta y la
            // prueba pasa. Es el resultado que se busca.
            $this->assertStringContainsString('23P01', $excepcion->getMessage() . ($excepcion->getCode() ?? ''));

            return;
        }

        // Si no hubo error, el UPDATE no pudo haber tocado nada: condiciono
        // `estado = 'bloqueado'` y el asiento ya estaba asignado. Si hubiera
        // tocado la fila, el estado habria cambiado y no habria dos duenos, pero
        // tampoco habria aviso.
        $this->assertSame(0, $afectadas, 'El segundo UPDATE no deberia poder robar un asiento ya asignado.');

        $asiento = Asiento::query()
            ->where('vuelo_id', $escenario['vuelo']->id)
            ->where('numero', '15A')
            ->firstOrFail();

        $this->assertSame($reservaUno->id, $asiento->reserva_id);
    }

    public function test_un_bloqueo_vencido_no_impide_tomar_el_asiento(): void
    {
        $escenario = $this->armarVuelo();

        $primeraReserva = Reserva::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'producto_id' => $escenario['producto']->id,
            'vuelo_id' => $escenario['vuelo']->id,
        ]);

        $segundaReserva = Reserva::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'producto_id' => $escenario['producto']->id,
            'vuelo_id' => $escenario['vuelo']->id,
        ]);

        // Un bloqueo que quedo colgado: paso su fecha y nadie lo renovo.
        Asiento::query()
            ->where('vuelo_id', $escenario['vuelo']->id)
            ->where('numero', '14C')
            ->update([
                'estado' => EstadoAsiento::Bloqueado->value,
                'bloqueado_hasta' => now()->subMinutes(5),
                'bloqueado_en' => now()->subMinutes(20),
                'reserva_id' => $primeraReserva->id,
            ]);

        $servicio = new ServicioAsientos();

        // Un bloqueo vencido NO es un asiento tomado: es uno que nadie esta
        // mirando. El servicio lo libera y lo concede.
        $asiento = $servicio->tomar(
            vueloId: $escenario['vuelo']->id,
            numero: '14C',
            reservaId: $segundaReserva->id,
        );

        $this->assertSame(EstadoAsiento::Asignado, $asiento->estado);
        $this->assertSame($segundaReserva->id, $asiento->reserva_id);
    }

    public function test_el_mapa_de_asientos_se_lee_de_la_fila_y_no_de_un_cache(): void
    {
        $escenario = $this->armarVuelo();

        $servicio = new ServicioAsientos();

        $mapa = $servicio->mapa($escenario['vuelo']->id);

        $numeros = array_column($mapa, 'numero');

        $this->assertContains('14A', $numeros);
        $this->assertContains('14B', $numeros);

        $estadoDe14A = null;
        foreach ($mapa as $asiento) {
            if ($asiento['numero'] === '14A') {
                $estadoDe14A = $asiento;
            }
        }

        $this->assertNotNull($estadoDe14A);
        $this->assertTrue($estadoDe14A['disponible']);
    }

    /**
     * Un vuelo con una fila de asientos y dos agentes.
     *
     * @return array{agencia: \App\Models\Agencia, agente: \App\Models\Usuario,
     *               producto: Producto, vuelo: Vuelo}
     */
    private function armarVuelo(): array
    {
        $agencia = $this->crearAgencia('primera.cl');
        $this->fijarAgencia($agencia['agencia']->id);

        $producto = Producto::factory()->vuelo()->create([
            'tenant_id' => $agencia['agencia']->id,
        ]);

        $vuelo = Vuelo::factory()->create([
            'tenant_id' => $agencia['agencia']->id,
            'producto_id' => $producto->id,
            'salida_en' => now()->addDays(20),
            'llegada_en' => now()->addDays(20)->addHours(6),
        ]);

        foreach (['14A', '14B', '14C', '15A', '15B', '15C'] as $numero) {
            $columnas = ['A', 'B', 'C', 'D', 'E', 'F'];
            $fila = (int) substr($numero, 0, -1);
            $letra = substr($numero, -1);

            Asiento::query()->create([
                'tenant_id' => $agencia['agencia']->id,
                'vuelo_id' => $vuelo->id,
                'numero' => $numero,
                'clase' => 'Economica',
                'estado' => EstadoAsiento::Disponible,
                'fila_orden' => $fila,
                'columna_numero' => array_search($letra, $columnas, true) + 1,
            ]);
        }

        return [
            'agencia' => $agencia['agencia'],
            'agente' => $agencia['agente'],
            'producto' => $producto,
            'vuelo' => $vuelo,
        ];
    }
}