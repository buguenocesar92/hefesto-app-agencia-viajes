<?php

declare(strict_types=1);

namespace Tests\Feature\Boleto;

use App\Enums\EstadoReserva;
use App\Enums\EstadoVerificacionNombre;
use App\Enums\TipoDocumento;
use App\Models\DocumentoViaje;
use App\Models\Pasajero;
use App\Models\PoliticaNombres;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Reserva;
use App\Models\ReservaPasajero;
use App\Models\Usuario;
use App\Models\Vuelo;
use App\Services\Viajes\ConstruccionBoleto;
use App\Services\Viajes\PoliticaDeNombres;
use App\Services\Viajes\MaquinaDeEstadosReserva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * PRUEBAS CRITICAS: EL NOMBRE DEL BOLETO SALE DEL DOCUMENTO.
 *
 * ESTE ES EL PRODUCTO. Si el nombre del boleto no coincide con el documento del
 * pasajero, la aerolinea lo rechaza en el mostrador del aeropuerto y el costo
 * lo paga la agencia. En venta de pasajes en linea es la causa numero uno de
 * problemas en el mostrador.
 *
 * Lo que se comprueba aca:
 *
 *  1. El nombre del boleto sale del DOCUMENTO, no del nombre declarado.
 *  2. El sistema NO "normaliza" nombres: no invierte, no abrevia, no cambia
 *     mayusculas.
 *  3. Un pasajero puede tener VARIOS documentos, y cada viaje usa el suyo.
 *  4. La comparacion automatica NUNCA verifica sola: deja el nombre pendiente
 *     para que un agente lo mire.
 *  5. No se puede emitir una reserva con nombres sin verificar.
 *  6. Sin politica de nombres configurada, el nombre queda pendiente en vez de
 *     que el sistema invente una regla.
 *
 * NO SE EJECUTARON. Ver docs/10.
 */
class NombreBoletoDesdeDocumentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_nombre_del_boleto_sale_del_documento_y_no_del_nombre_declarado(): void
    {
        $escenario = $this->armarEscenario();

        // El caso del producto: el pasajero se dijo "Rodrigo" y su pasaporte
        // dice "Rodrigo Andrés Pérez Muñoz".
        $pasajero = Pasajero::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'nombre_declarado' => 'Rodrigo',
        ]);

        $documento = DocumentoViaje::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'pasajero_id' => $pasajero->id,
            'nombres' => 'Rodrigo Andrés',
            'apellidos' => 'Pérez Muñoz',
        ]);

        $armado = (new ConstruccionBoleto())->nombreDelBoleto($documento, $escenario['politica']);

        $this->assertSame('Rodrigo Andrés Pérez Muñoz', $armado['nombre']);
        $this->assertNotSame(
            'Rodrigo',
            $armado['nombre'],
            'El nombre del boleto NO puede ser el nombre abreviado que escribio el pasajero.'
        );
    }

    public function test_el_sistema_no_transforma_el_nombre_del_documento(): void
    {
        $escenario = $this->armarEscenario();

        $documento = DocumentoViaje::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'pasajero_id' => Pasajero::factory()->create(['tenant_id' => $escenario['agencia']->id])->id,
            // Con dobles espacios y minusculas, tal como puede venir.
            'nombres' => 'MARÍA JOSÉ',
            'apellidos' => 'DE LA FUENTE',
        ]);

        $armado = (new ConstruccionBoleto())->nombreDelBoleto($documento, $escenario['politica']);

        // El boleto lleva el texto del documento. El sistema no "corrige" nada:
        // no sabe cual de las dos grafias es la buena, y adivinar es
        // precisamente lo que produce el problema.
        $this->assertStringContainsString('MARÍA JOSÉ', $armado['nombre']);
        $this->assertStringContainsString('DE LA FUENTE', $armado['nombre']);
        $this->assertStringNotContainsString('maría', mb_strtolower($armado['nombre']));
    }

    public function test_un_pasajero_puede_tener_varios_documentos_y_cada_viaje_usa_el_suyo(): void
    {
        $escenario = $this->armarEscenario();

        $pasajero = Pasajero::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'nombre_declarado' => 'Rodrigo',
        ]);

        // Pasaporte viejo, ya vencido: el de un viaje anterior.
        $viejo = DocumentoViaje::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'pasajero_id' => $pasajero->id,
            'numero' => 'P0890123',
            'nombres' => 'Rodrigo Andrés',
            'apellidos' => 'Pérez Muñoz',
            'vencido_en' => now()->subYear()->toDateString(),
            'es_principal' => false,
        ]);

        // Pasaporte nuevo: el del viaje que viene.
        $nuevo = DocumentoViaje::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'pasajero_id' => $pasajero->id,
            'numero' => 'P1234567',
            'nombres' => 'Rodrigo Andrés',
            'apellidos' => 'Pérez Muñoz',
            'vencido_en' => now()->addYears(5)->toDateString(),
            'es_principal' => true,
        ]);

        $this->assertCount(2, $pasajero->documentos()->get());

        // Cada reserva apunta al documento CONCRETO de ese viaje, no a "el
        // documento del pasajero".
        $reservaPasada = $this->crearReserva($escenario, $pasajero, $viejo);
        $reservaFutura = $this->crearReserva($escenario, $pasajero, $nuevo);

        $this->assertSame($viejo->id, $reservaPasada->documento_viaje_id);
        $this->assertSame($nuevo->id, $reservaFutura->documento_viaje_id);
        $this->assertNotSame($reservaPasada->id, $reservaFutura->reserva_id);
    }

    public function test_la_comparacion_automatica_no_verifica_sola(): void
    {
        $escenario = $this->armarEscenario();

        $pasajero = Pasajero::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'nombre_declarado' => 'Rodrigo',
        ]);

        $documento = DocumentoViaje::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'pasajero_id' => $pasajero->id,
            'nombres' => 'Rodrigo Andrés',
            'apellidos' => 'Pérez Muñoz',
        ]);

        $revision = (new ConstruccionBoleto())->revisar(
            $documento,
            $pasajero->nombre_declarado,
            $escenario['politica'],
        );

        // ESTA ES LA FRONTERA DEL PRODUCTO: la maquina avisa, la persona decide.
        $this->assertSame(
            EstadoVerificacionNombre::Pendiente,
            $revision['estado'],
            'La revision automatica NUNCA debe dejar un nombre verificado. Eso lo decide un agente.'
        );

        $this->assertNotEmpty($revision['diferencias']);
        $this->assertStringContainsString('agente', implode(' ', $revision['diferencias']));
    }

    public function test_sin_politica_de_nombres_el_nombre_queda_pendiente_y_no_se_inventa_una_regla(): void
    {
        // Agencia SIN politica de nombres: es el estado en que queda una
        // instalacion nueva.
        $escenario = $this->armarEscenario(conPolitica: false);

        $documento = DocumentoViaje::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'pasajero_id' => Pasajero::factory()->create(['tenant_id' => $escenario['agencia']->id])->id,
            'nombres' => 'Rodrigo Andrés',
            'apellidos' => 'Pérez Muñoz',
        ]);

        $revision = (new ConstruccionBoleto())->revisar($documento, 'Rodrigo', null);

        $this->assertSame(EstadoVerificacionNombre::Pendiente, $revision['estado']);
        $this->assertStringContainsString(
            'politica de nombres',
            implode(' ', $revision['diferencias']),
            'Sin politica, el sistema debe decirlo en vez de decidir por su cuenta.'
        );

        // Y aun asi, el nombre del boleto sale del documento, sin transformar.
        $armado = (new ConstruccionBoleto())->nombreDelBoleto($documento, null);
        $this->assertSame('Rodrigo Andrés Pérez Muñoz', $armado['nombre']);
    }

    public function test_no_se_puede_emitir_una_reserva_con_nombres_sin_verificar(): void
    {
        $escenario = $this->armarEscenario();

        $reserva = $this->crearReservaCompleta($escenario, verificado: false);
        $reserva->estado = EstadoReserva::Confirmada;
        $reserva->save();

        $maquina = new MaquinaDeEstadosReserva(
            new \App\Services\Viajes\CalculadoraCancelacion(),
            new \App\Services\Tenancy\AuditorDeAcceso(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sin verificar/');

        $maquina->transicionar($reserva, EstadoReserva::Emitida, $escenario['agente']);
    }

    public function test_emitir_es_posible_cuando_un_agente_verifico_el_nombre(): void
    {
        $escenario = $this->armarEscenario();

        $reserva = $this->crearReservaCompleta($escenario, verificado: false);
        $reserva->estado = EstadoReserva::Confirmada;
        $reserva->save();

        $maquina = new MaquinaDeEstadosReserva(
            new \App\Services\Viajes\CalculadoraCancelacion(),
            new \App\Services\Tenancy\AuditorDeAcceso(),
        );

        // El agente verifica. Aca si se toca el estado a mano, y queda con su
        // autor y su hora.
        $pasajeroReserva = $reserva->pasajeros()->firstOrFail();
        $maquina->verificarNombre(
            $pasajeroReserva,
            EstadoVerificacionNombre::Verificado,
            $escenario['agente'],
            'Coincide con el pasaporte vigente.',
        );

        $emitida = $maquina->transicionar($reserva->refresh(), EstadoReserva::Emitida, $escenario['agente']);

        $this->assertSame(EstadoReserva::Emitida, $emitida->estado);
        $this->assertNotNull($emitida->emitida_en);

        $verificado = $emitida->pasajeros()->firstOrFail();
        $this->assertSame(EstadoVerificacionNombre::Verificado, $verificado->estado_verificacion_nombre);
        $this->assertSame($escenario['agente']->id, $verificado->verificado_por);
        $this->assertNotNull($verificado->verificado_en);
    }

    public function test_el_nombre_boleto_queda_copiado_y_no_cambia_si_se_actualiza_el_documento(): void
    {
        $escenario = $this->armarEscenario();

        $reserva = $this->crearReservaCompleta($escenario, verificado: true);

        $nombreAlEmitir = $reserva->pasajeros()->firstOrFail()->nombre_boleto;

        $this->assertSame('Rodrigo Andrés Pérez Muñoz', $nombreAlEmitir);

        // El pasajero actualiza el nombre en su documento.
        $documento = DocumentoViaje::query()->firstOrFail();
        $documento->apellidos = 'Pérez Muñoz Green';
        $documento->save();

        $reserva->refresh();

        // El boleto ya habia salido con el nombre de antes. Tiene que seguir
        // diciendo eso: con una referencia sola, apareceria hoy con un nombre
        // que nunca se compro.
        $this->assertSame(
            $nombreAlEmitir,
            $reserva->pasajeros()->firstOrFail()->nombre_boleto,
            'El nombre del boleto es una COPIA del documento en el momento de emitirlo.'
        );
    }

    /**
     * @return array{agencia: \App\Models\Agencia, agente: Usuario,
     *               proveedor: Proveedor, producto: Producto, vuelo: Vuelo,
     *               politica: PoliticaNombres|null}
     */
    private function armarEscenario(bool $conPolitica = true): array
    {
        $agencia = $this->crearAgencia('primera.cl');
        $this->fijarAgencia($agencia['agencia']->id);

        $proveedor = Proveedor::factory()->create(['tenant_id' => $agencia['agencia']->id]);

        $producto = Producto::factory()->vuelo()->create([
            'tenant_id' => $agencia['agencia']->id,
            'proveedor_id' => $proveedor->id,
        ]);

        $vuelo = Vuelo::factory()->de($producto)->create([
            'salida_en' => now()->addDays(20),
        ]);

        $politica = null;

        if ($conPolitica) {
            $politica = PoliticaNombres::query()->create([
                'tenant_id' => $agencia['agencia']->id,
                'proveedor_id' => $proveedor->id,
                'nombre' => 'General de prueba',
                'separador' => ' ',
                'orden_apellidos_primero' => false,
                'exigir_tildes' => false,
                'exigir_mayusculas' => false,
                'ignorar_nombres_compuestos' => false,
                'activa' => true,
                'creado_en' => now(),
            ]);
        }

        return [
            'agencia' => $agencia['agencia'],
            'agente' => $agencia['agente'],
            'proveedor' => $proveedor,
            'producto' => $producto,
            'vuelo' => $vuelo,
            'politica' => $politica,
        ];
    }

    /**
     * @param  array{producto: Producto, vuelo: Vuelo}  $escenario
     */
    private function crearReserva(array $escenario, Pasajero $pasajero, DocumentoViaje $documento): ReservaPasajero
    {
        $reserva = Reserva::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'producto_id' => $escenario['producto']->id,
            'vuelo_id' => $escenario['vuelo']->id,
        ]);

        return ReservaPasajero::query()->create([
            'tenant_id' => $escenario['agencia']->id,
            'reserva_id' => $reserva->id,
            'pasajero_id' => $pasajero->id,
            'documento_viaje_id' => $documento->id,
            'nombre_boleto' => $documento->nombreCompleto(' '),
            'estado_verificacion_nombre' => EstadoVerificacionNombre::Pendiente,
        ]);
    }

    /**
     * @param  array<string, mixed>  $escenario
     */
    private function crearReservaCompleta(array $escenario, bool $verificado): Reserva
    {
        $pasajero = Pasajero::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'nombre_declarado' => 'Rodrigo',
        ]);

        $documento = DocumentoViaje::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'pasajero_id' => $pasajero->id,
            'tipo' => TipoDocumento::Pasaporte,
            'nombres' => 'Rodrigo Andrés',
            'apellidos' => 'Pérez Muñoz',
            'estado_verificacion_nombre' => $verificado
                ? EstadoVerificacionNombre::Verificado
                : EstadoVerificacionNombre::Pendiente,
            'verificado_por' => $verificado ? $escenario['agente']->id : null,
            'verificado_en' => $verificado ? now() : null,
        ]);

        $reserva = Reserva::factory()->create([
            'tenant_id' => $escenario['agencia']->id,
            'producto_id' => $escenario['producto']->id,
            'vuelo_id' => $escenario['vuelo']->id,
        ]);

        ReservaPasajero::query()->create([
            'tenant_id' => $escenario['agencia']->id,
            'reserva_id' => $reserva->id,
            'pasajero_id' => $pasajero->id,
            'documento_viaje_id' => $documento->id,
            'nombre_boleto' => 'Rodrigo Andrés Pérez Muñoz',
            'estado_verificacion_nombre' => $verificado
                ? EstadoVerificacionNombre::Verificado
                : EstadoVerificacionNombre::Pendiente,
            'verificado_por' => $verificado ? $escenario['agente']->id : null,
            'verificado_en' => $verificado ? now() : null,
        ]);

        return $reserva->refresh();
    }
}