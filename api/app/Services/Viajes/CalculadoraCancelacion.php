<?php

declare(strict_types=1);

namespace App\Services\Viajes;

use App\Models\Producto;
use App\Models\PoliticaCancelacion;
use App\Models\Reserva;
use App\Models\Vuelo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Calcula el costo de cancelar una reserva.
 *
 * ============================================================
 * NO HAY PLAZOS DE CANCELACION EN ESTE CODIGO, Y ES A PROPOSITO
 * ============================================================
 * Un plazo de cancelacion no es un dato tecnico. Es un acuerdo entre la
 * agencia, su proveedor y el pasajero, y cambia por producto, por proveedor y
 * por temporada. Escribir "24 horas antes se devuelve el 100%" en el codigo
 * seria inventar un acuerdo comercial que nadie acordo.
 *
 * Por eso los plazos viven en `politicas_cancelacion` y los escribe la
 * agencia. Esta clase solo SABE APLICAR lo que esta escrito.
 *
 * Y CUANDO NO HAY POLITICA, ESTA CLASE NO ADIVINA
 * -----------------------------------------------
 * Si el producto no tiene politica configurada, la cancelacion se RECHAZA con
 * un mensaje que dice exactamente eso. La alternativa habria sido asumir
 * "costo cero", que es la forma elegante de regalar un pasaje.
 *
 * Ver docs/05-CONFIGURACION.md, seccion "Politicas de cancelacion".
 */
class CalculadoraCancelacion
{
    /**
     * Costo de cancelar una reserva, en centimos.
     *
     * @return array{
     *     costo_centimos: int,
     *     politica: PoliticaCancelacion|null,
     *     minutos_restantes: int|null,
     *     destino: string|null,
     *     explicacion: string
     * }
     *
     * @throws RuntimeException si el producto no tiene politica de cancelacion.
     */
    public function calcular(Reserva $reserva): array
    {
        $producto = $reserva->producto ?? Producto::query()->find($reserva->producto_id);

        if ($producto === null) {
            throw new RuntimeException('La reserva no tiene un producto asociado. Revise los datos.');
        }

        $salida = $this->fechaDeSalida($reserva);

        if ($salida === null) {
            throw new RuntimeException(
                'Esta reserva no tiene fecha de salida, asi que no se puede calcular el costo de '
                . 'cancelacion con las politicas configuradas. La agencia debe definir la politica '
                . 'de este producto a mano.'
            );
        }

        $minutosRestantes = (int) max(0, now()->diffInMinutes($salida, false));

        $politica = $this->politicaAplicable($producto, $minutosRestantes);

        if ($politica === null) {
            throw new RuntimeException(
                'El producto "' . $producto->nombre . '" no tiene una politica de cancelacion que '
                . 'aplique con ' . $this->textoAnticipacion($minutosRestantes) . ' de anticipacion. '
                . 'La agencia debe configurarla antes de cancelar; el sistema no asume un costo.'
            );
        }

        $costo = $this->costoDe($politica, $reserva->total_centimos);

        return [
            'costo_centimos' => $costo,
            'politica' => $politica,
            'minutos_restantes' => $minutosRestantes,
            'destino' => $politica->destino,
            'explicacion' => sprintf(
                'Escalon "%s": %d%% de %s mas %s fijo. El dinero va a %s.',
                $politica->nombre,
                $politica->porcentaje_costo,
                $this->formato($reserva->total_centimos),
                $this->formato($politica->costo_fijo_centimos),
                $politica->destino,
            ),
        ];
    }

    /**
     * El escalon que aplica: el de MAYOR anticipacion que no supere los
     * minutos restantes.
     *
     * Es la forma en que funcionan los escalones de una tarifa publicada. Si
     * hay un escalon "mas de 15 dias: sin costo" y otro "menos de 7 dias: 50%",
     * y el pasajero cancela con 10 dias de anticipacion, aplica el primero.
     */
    public function politicaAplicable(Producto $producto, int $minutosRestantes): ?PoliticaCancelacion
    {
        return PoliticaCancelacion::query()
            ->where('producto_id', $producto->id)
            ->where('activo', true)
            ->where('anticipacion_minima_minutos', '>=', $minutosRestantes)
            ->orderByDesc('anticipacion_minima_minutos')
            ->first();
    }

    /**
     * Costo en centimos de aplicar un escalon al total de una reserva.
     *
     * Se calcula con division entera y se redondea hacia arriba: el redondeo
     * en el costo de cancelacion va en contra de la agencia (se paga de mas),
     * pero se redondea SIEMPRE en la misma direccion para que el mismo calculo
     * produzca siempre el mismo numero. Un redondeo que depende del caso es un
     * descuadre esperando.
     */
    public function costoDe(PoliticaCancelacion $politica, int $totalCentimos): int
    {
        $porcentaje = (int) ceil($totalCentimos * $politica->porcentaje_costo / 100);

        return $porcentaje + $politica->costo_fijo_centimos;
    }

    /**
     * La fecha que manda para la anticipacion: la salida del vuelo, o la
     * entrada al hotel.
     */
    private function fechaDeSalida(Reserva $reserva): ?Carbon
    {
        if ($reserva->vuelo_id !== null) {
            $vuelo = $reserva->vuelo ?? Vuelo::query()->find($reserva->vuelo_id);

            return $vuelo?->salida_en;
        }

        // Paquete sin vuelo: la anticipacion se cuenta desde la creacion de la
        // reserva. Es una aproximacion, y por eso el mensaje de error de arriba
        // pide configurar la politica a mano cuando no hay fecha.
        return null;
    }

    private function textoAnticipacion(int $minutos): string
    {
        $dias = intdiv($minutos, 1440);

        if ($dias >= 1) {
            return $dias === 1 ? '1 dia' : $dias . ' dias';
        }

        $horas = intdiv($minutos, 60);

        if ($horas >= 1) {
            return $horas === 1 ? '1 hora' : $horas . ' horas';
        }

        return $minutos . ' minutos';
    }

    private function formato(int $centimos): string
    {
        return '$' . number_format(intdiv($centimos, 100), 0, ',', '.');
    }
}