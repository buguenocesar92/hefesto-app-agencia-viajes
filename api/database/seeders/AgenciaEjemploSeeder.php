<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EstadoAsiento;
use App\Enums\EstadoReserva;
use App\Enums\EstadoVerificacionNombre;
use App\Enums\TipoDocumento;
use App\Enums\TipoProducto;
use App\Models\Agencia;
use App\Models\Asiento;
use App\Models\Configuracion;
use App\Models\DocumentoViaje;
use App\Models\Pago;
use App\Models\Pasajero;
use App\Models\PoliticaCancelacion;
use App\Models\PoliticaNombres;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Reserva;
use App\Models\ReservaEvento;
use App\Models\ReservaPasajero;
use App\Models\Tarifa;
use App\Models\Usuario;
use App\Models\Vuelo;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Database\Seeder;

/**
 * Una agencia de ejemplo, completa.
 *
 * QUE HAY Y POR QUE
 * -----------------
 *  - Una agencia con su configuracion.
 *  - Un agente y un administrador.
 *  - Un proveedor, tres productos (vuelo, hotel, paquete) y sus tarifas.
 *  - UN vuelo con una fila de asientos de verdad.
 *  - Un pasajero con DOS documentos, para que se vea que un pasajero puede
 *    viajar con distintos documentos.
 *  - Una reserva con pasajero, asiento tomado y PAGO PARCIAL.
 *  - Politicas de nombres y de cancelacion ESCRITAS A MANO por la agencia.
 *
 * LO QUE ESTA HECHO A PROPOSITO
 * -----------------------------
 * 1. EL PAGO PARCIAL. La reserva queda con la mitad pagada. Es el caso que el
 *    producto tiene que hacer visible, y si la semilla lo dejara pagada, la
 *    pantalla de "pagos incompletos" arrancaria vacia y nadie veria para que
 *    existe.
 *
 * 2. EL NOMBRE DECLARADO ABREVIADO. El pasajero se dijo "Rodrigo" y su
 *    pasaporte dice "Rodrigo Andrés Pérez Muñoz". El boleto sale con el nombre
 *    del DOCUMENTO, y la diferencia queda anotada. Es exactamente el problema
 *    que el producto existe para evitar.
 *
 * 3. LA POLITICA DE CANCELACION TIENE PLAZOS DE EJEMPLO.
 *    Los numeros concretos son de una agencia de muestra, no una
 *    recomendacion de Hefesto Studio. La agencia real tiene que cambiarlos por
 *    los suyos antes de operar. Esta nota esta en el codigo y en docs/06 para
 *    que nadie los tome por una politica nuestra.
 */
class AgenciaEjemploSeeder extends Seeder
{
    public function run(): void
    {
        $agencia = $this->crearAgencia();
        $contexto = new ContextoTenant();

        // Todo lo de mas se escribe DENTRO de una transaccion con el contexto
        // de agencia puesto. Sin el, la RLS no deja escribir nada.
        $contexto->ejecutarAislado($agencia->id, function () use ($agencia): void {
            $this->crearPersonas($agencia);
            $this->crearCatalogo($agencia);
            $this->crearPoliticas($agencia);
            $this->crearReservaDeEjemplo($agencia);
        });
    }

    private function crearAgencia(): Agencia
    {
        // `agencias` NO tiene RLS a proposito, asi que se puede escribir sin
        // contexto. Ver docs/03.
        $agencia = Agencia::query()->create([
            'slug' => 'andes-travel',
            'nombre' => 'Andes Travel',
            'razon_social' => 'Andes Travel Servicios de Turismo Ltda.',
            'rut' => '76.543.210-9',
            'dominio_correo' => 'andestravel.cl',
            'activo' => true,
        ]);

        Configuracion::query()->create([
            'tenant_id' => $agencia->id,
            // Los tres interruptores prudentes. Ver docs/07.
            'mostrar_documento_en_listados' => false,
            'exigir_verificacion_nombre' => true,
            'exigir_motivo_lectura_documento' => true,
            'dias_aviso_vuelo' => 7,
            'creado_en' => now(),
        ]);

        return $agencia;
    }

    private function crearPersonas(Agencia $agencia): void
    {
        Usuario::query()->create([
            'tenant_id' => $agencia->id,
            'subjekt_authentik' => 'seed-andes-admin',
            'nombre' => 'Camila Rojas',
            'email' => 'camila.rojas@andestravel.cl',
            'rol' => 'administrador',
            'activo' => true,
        ]);

        Usuario::query()->create([
            'tenant_id' => $agencia->id,
            'subjekt_authentik' => 'seed-andes-agente',
            'nombre' => 'Sebastián Muñoz',
            'email' => 'sebastian.munoz@andestravel.cl',
            'rol' => 'agente',
            'activo' => true,
        ]);
    }

    private function crearCatalogo(Agencia $agencia): void
    {
        $proveedor = Proveedor::query()->create([
            'tenant_id' => $agencia->id,
            'tipo' => 'aerolinea',
            'codigo' => 'SK',
            'nombre' => 'Aerolineas del Sur',
            'activo' => true,
        ]);

        $hotelProveedor = Proveedor::query()->create([
            'tenant_id' => $agencia->id,
            'tipo' => 'hotel',
            'codigo' => 'HT',
            'nombre' => 'Hotel Costa Norte',
            'activo' => true,
        ]);

        // --- Producto de vuelo: el trayecto. Las fechas van en `vuelos`. -----
        $productoVuelo = Producto::query()->create([
            'tenant_id' => $agencia->id,
            'proveedor_id' => $proveedor->id,
            'tipo' => TipoProducto::Vuelo,
            'codigo' => 'VUELO-SCL-SPA',
            'nombre' => 'Santiago - Sao Paulo',
            'descripcion' => 'Vuelo directo, ida y vuelta.',
            'activo' => true,
        ]);

        // --- Producto de hotel. NO usa asientos. ------------------------------
        $productoHotel = Producto::query()->create([
            'tenant_id' => $agencia->id,
            'proveedor_id' => $hotelProveedor->id,
            'tipo' => TipoProducto::Hotel,
            'codigo' => 'HOTEL-CN',
            'nombre' => 'Hotel Costa Norte, habitacion doble',
            'activo' => true,
        ]);

        // --- Producto de paquete. ---------------------------------------------
        Producto::query()->create([
            'tenant_id' => $agencia->id,
            'proveedor_id' => $proveedor->id,
            'tipo' => TipoProducto::Paquete,
            'codigo' => 'PAQ-CONA-SUR',
            'nombre' => 'Paquete Cona Sur, 5 noches',
            'activo' => true,
        ]);

        // --- DOS fechas del MISMO vuelo. Cada una con sus asientos. -------------
        $vueloProximo = Vuelo::query()->create([
            'tenant_id' => $agencia->id,
            'producto_id' => $productoVuelo->id,
            'codigo_vuelo' => 'SK404',
            'origen' => 'SCL',
            'destino' => 'SPA',
            'salida_en' => now()->addDays(14)->setTime(9, 30),
            'llegada_en' => now()->addDays(14)->setTime(17, 10),
            'aeronave' => 'A320neo',
            'activo' => true,
        ]);

        $vueloLejano = Vuelo::query()->create([
            'tenant_id' => $agencia->id,
            'producto_id' => $productoVuelo->id,
            'codigo_vuelo' => 'SK406',
            'origen' => 'SCL',
            'destino' => 'SPA',
            'salida_en' => now()->addDays(45)->setTime(9, 30),
            'llegada_en' => now()->addDays(45)->setTime(17, 10),
            'aeronave' => 'A320neo',
            'activo' => true,
        ]);

        Tarifa::query()->create([
            'tenant_id' => $agencia->id,
            'producto_id' => $productoVuelo->id,
            'vuelo_id' => $vueloProximo->id,
            'nombre_clase' => 'Economica',
            'precio_centimos' => 1800000,
            'moneda' => 'CLP',
            'cupos_disponibles' => 20,
            'vigente_desde' => now()->subDays(5)->toDateString(),
            'vigente_hasta' => $vueloProximo->salida_en->toDateString(),
        ]);

        Tarifa::query()->create([
            'tenant_id' => $agencia->id,
            'producto_id' => $productoVuelo->id,
            'vuelo_id' => $vueloLejano->id,
            'nombre_clase' => 'Economica',
            'precio_centimos' => 1650000,
            'moneda' => 'CLP',
            'cupos_disponibles' => 20,
        ]);

        Tarifa::query()->create([
            'tenant_id' => $agencia->id,
            'producto_id' => $productoHotel->id,
            'nombre_clase' => 'Doble',
            'precio_centimos' => 9500000,
            'moneda' => 'CLP',
            'cupos_disponibles' => null,
        ]);
    }

    private function crearPoliticas(Agencia $agencia): void
    {
        // -------------------------------------------------------------------
        // POLITICA DE NOMBRES.
        //
        // ESTOS VALORES SON UN EJEMPLO. La agencia real decide cual es el
        // separador que exige su aerolinea. Hefesto Studio no propone ninguno.
        // -------------------------------------------------------------------
        PoliticaNombres::query()->create([
            'tenant_id' => $agencia->id,
            'proveedor_id' => null,
            'nombre' => 'General Andes Travel',
            'separador' => ' ',
            'orden_apellidos_primero' => false,
            'exigir_tildes' => false,
            'exigir_mayusculas' => false,
            'ignorar_nombres_compuestos' => false,
            'activa' => true,
            'creado_en' => now(),
        ]);

        // -------------------------------------------------------------------
        // POLITICAS DE CANCELACION.
        //
        // ATENCION: los plazos de abajo son de la agencia de ejemplo, NO son
        // una politica de Hefesto Studio ni una recomendacion. Muestran como
        // se escriben. La agencia real los cambia por los suyos antes de
        // operar.
        // -------------------------------------------------------------------
        $productoVuelo = Producto::query()->where('codigo', 'VUELO-SCL-SPA')->firstOrFail();

        foreach ([
            ['Cancelacion con mas de 30 dias', 43200, 0, 0, 'reembolso'],
            ['Cancelacion entre 15 y 30 dias', 21600, 25, 0, 'reembolso'],
            ['Cancelacion entre 7 y 15 dias', 10080, 50, 0, 'credito'],
            ['Cancelacion con menos de 7 dias', 4320, 100, 0, 'credito'],
        ] as [$nombre, $minutos, $porcentaje, $fijo, $destino]) {
            PoliticaCancelacion::query()->create([
                'tenant_id' => $agencia->id,
                'producto_id' => $productoVuelo->id,
                'nombre' => $nombre,
                'anticipacion_minima_minutos' => $minutos,
                'porcentaje_costo' => $porcentaje,
                'costo_fijo_centimos' => $fijo,
                'destino' => $destino,
                'activo' => true,
                'creado_en' => now(),
            ]);
        }
    }

    private function crearReservaDeEjemplo(Agencia $agencia): void
    {
        $usuario = Usuario::query()->where('tenant_id', $agencia->id)->where('rol', 'administrador')->firstOrFail();
        $productoVuelo = Producto::query()->where('codigo', 'VUELO-SCL-SPA')->firstOrFail();
        $vuelo = Vuelo::query()->where('producto_id', $productoVuelo->id)->orderBy('salida_en')->firstOrFail();
        $tarifa = Tarifa::query()->where('vuelo_id', $vuelo->id)->firstOrFail();

        // --- El pasajero. -----------------------------------------------------
        //
        // Se dijo "Rodrigo": abreviado, como pasa en la venta en linea.
        $pasajero = Pasajero::query()->create([
            'tenant_id' => $agencia->id,
            'nombre_declarado' => 'Rodrigo',
            'email' => 'rodrigo.ejemplo@correo.cl',
            'telefono' => '+56 9 8765 4321',
            'fecha_nacimiento' => '1988-04-12',
            'nacionalidad' => 'Chilena',
            'activo' => true,
        ]);

        // --- Documento NUEVO: el del viaje. -----------------------------------
        $documentoVigente = DocumentoViaje::query()->create([
            'tenant_id' => $agencia->id,
            'pasajero_id' => $pasajero->id,
            'tipo' => TipoDocumento::Pasaporte,
            'numero' => 'P1234567',
            'nombres' => 'Rodrigo Andrés',
            'apellidos' => 'Pérez Muñoz',
            'fecha_emision' => '2019-03-10',
            'fecha_vencimiento' => '2029-03-10',
            'vencido_en' => '2029-03-10',
            'pais_emision' => 'CL',
            'es_principal' => true,
            'estado_verificacion_nombre' => EstadoVerificacionNombre::Pendiente,
            'creado_en' => now(),
            'updated_at' => now(),
        ]);

        // --- Documento VIEJO: el de un viaje anterior. ------------------------
        //
        // El mismo pasajero viajo antes con OTRO pasaporte. Por eso el
        // documento NO es una columna del pasajero: son dos filas con
        // historial, y cada reserva usa el suyo.
        DocumentoViaje::query()->create([
            'tenant_id' => $agencia->id,
            'pasajero_id' => $pasajero->id,
            'tipo' => TipoDocumento::Pasaporte,
            'numero' => 'P0890123',
            'nombres' => 'Rodrigo Andrés',
            'apellidos' => 'Pérez Muñoz',
            'fecha_emision' => '2011-08-02',
            'fecha_vencimiento' => '2021-08-02',
            'vencido_en' => '2021-08-02',
            'pais_emision' => 'CL',
            'es_principal' => false,
            'estado_verificacion_nombre' => EstadoVerificacionNombre::Verificado,
            'verificado_por' => $usuario->id,
            'verificado_en' => now()->subYear(),
            'creado_en' => now()->subYears(2),
            'updated_at' => now()->subYears(2),
        ]);

        // --- LA RESERVA. ------------------------------------------------------
        $reserva = Reserva::query()->create([
            'tenant_id' => $agencia->id,
            'folio' => 'V-000001',
            'producto_id' => $productoVuelo->id,
            'vuelo_id' => $vuelo->id,
            'tarifa_id' => $tarifa->id,
            'proveedor_id' => $productoVuelo->proveedor_id,
            'estado' => EstadoReserva::Pendiente,
            'origen' => 'mostrador',
            'total_centimos' => 1800000,
            'creada_por' => $usuario->id,
            'creado_en' => now()->subDays(3),
        ]);

        // --- EL PASAJERO EN LA RESERVA, CON EL NOMBRE DEL BOLETO. -------------
        //
        // ACA ESTA EL PRODUCTO: `nombre_boleto` sale del DOCUMENTO ("Rodrigo
        // Andrés Pérez Muñoz"), no de `nombre_declarado` ("Rodrigo").
        $pasajeroReserva = ReservaPasajero::query()->create([
            'tenant_id' => $agencia->id,
            'reserva_id' => $reserva->id,
            'pasajero_id' => $pasajero->id,
            'documento_viaje_id' => $documentoVigente->id,
            'nombre_boleto' => 'Rodrigo Andrés Pérez Muñoz',
            'estado_verificacion_nombre' => EstadoVerificacionNombre::Pendiente,
            'observacion_verificacion' => 'Documento: "Rodrigo Andrés Pérez Muñoz". '
                . 'Declarado por el pasajero: "Rodrigo". '
                . 'La correspondencia la revisa un agente: el sistema no la decide.',
            'es_contacto' => true,
        ]);

        ReservaEvento::query()->create([
            'tenant_id' => $agencia->id,
            'reserva_id' => $reserva->id,
            'estado_anterior' => null,
            'estado_nuevo' => EstadoReserva::Pendiente,
            'motivo' => 'Venta registrada en mostrador.',
            'costo_centimos' => 0,
            'usuario_id' => $usuario->id,
            'creado_en' => now()->subDays(3),
        ]);

        // --- LOS ASIENTOS DEL VUELO. -----------------------------------------
        //
        // Una fila y un asiento por pasajero. El de la reserva queda ASIGNADO;
        // el otro queda DISPONIBLE, para que se vea el contraste en el mapa.
        $this->crearAsientos($agencia, $vuelo, ['14A', '14B', '14C', '15A', '15B', '15C']);

        $asiento = Asiento::query()
            ->where('vuelo_id', $vuelo->id)
            ->where('numero', '14A')
            ->firstOrFail();

        $asiento->estado = EstadoAsiento::Asignado;
        $asiento->reserva_id = $reserva->id;
        $asiento->reserva_pasajero_id = $pasajeroReserva->id;
        $asiento->save();

        // --- EL PAGO PARCIAL. --------------------------------------------------
        //
        // El total es de $18.000 y se pagan $7.000. Quedan $11.000.
        //
        // Esto es a proposito: es el caso para el que existe la pantalla de
        // pagos incompletos. Si la semilla dejara la reserva pagada, esa
        // pantalla arrancaria vacia y nadie veria para que sirve.
        Pago::query()->create([
            'tenant_id' => $agencia->id,
            'reserva_id' => $reserva->id,
            'monto_centimos' => 700000,
            'metodo' => 'transferencia',
            'referencia' => 'TRANSF-000123',
            'anulado' => false,
            'registrado_por' => $usuario->id,
            'creado_en' => now()->subDays(2),
        ]);
    }

    /**
     * Una fila de asientos de verdad, para que el mapa tenga sentido.
     *
     * @param  list<string>  $numeros
     */
    private function crearAsientos(Agencia $agencia, Vuelo $vuelo, array $numeros): void
    {
        $columnas = ['A', 'B', 'C', 'D', 'E', 'F'];

        foreach ($numeros as $numero) {
            $fila = (int) substr($numero, 0, -1);
            $letra = substr($numero, -1);

            Asiento::query()->create([
                'tenant_id' => $agencia->id,
                'vuelo_id' => $vuelo->id,
                'numero' => $numero,
                'clase' => 'Economica',
                'estado' => EstadoAsiento::Disponible,
                'fila_orden' => $fila,
                'columna_numero' => array_search($letra, $columnas, true) + 1,
            ]);
        }
    }
}