<?php

declare(strict_types=1);

namespace App\Services\Viajes;

use App\Enums\EstadoVerificacionNombre;
use App\Models\DocumentoViaje;
use App\Models\PoliticaNombres;

/**
 * Arma el nombre que se imprime en el boleto, a partir del documento.
 *
 * ============================================================
 * ESTE ES EL PRODUCTO. AQUI ESTA EL PROBLEMA QUE RESUELVE.
 * ============================================================
 * En venta de pasajes en linea, la causa numero uno de problemas en el
 * mostrador es que el nombre del boleto NO coincide con el documento del
 * pasajero. Si el pasajero escribio "Rodrigo" y su pasaporte dice "Rodrigo
 * AndrÃ©s PÃ©rez", el boleto sale con "Rodrigo" y la aerolinea lo rechaza en el
 * mostrador del aeropuerto. El costo lo paga la agencia.
 *
 * QUE HACE ESTA CLASE
 * -------------------
 * Toma el nombre del DOCUMENTO y lo pone en el boleto. Punto.
 *
 * QUE NO HACE, Y POR QUE
 * ----------------------
 * No "normaliza" nombres. No quita el segundo nombre. No invierte el orden.
 * No sabe cual de las dos grafias es la correcta, y adivinar es justamente lo
 * que produce el problema. Si el documento dice "Rodrigo AndrÃ©s PÃ©rez", el
 * boleto dice "Rodrigo AndrÃ©s PÃ©rez".
 *
 * LO QUE HACE ADEMAS
 * ------------------
 * Compara el nombre que el pasajero DECLARO con el del documento, usando los
 * criterios que la agencia configuro, y deja el resultado PENDIENTE de que un
 * agente lo revise. El sistema avisa cuando hay una diferencia; no decide cual
 * lado es el correcto.
 *
 * Esa es la frontera: el sistema detecta y avisa, el agente decide. Ver
 * docs/01, seccion "El nombre del boleto".
 */
class ConstruccionBoleto
{
    public function __construct(private readonly PoliticaDeNombres $politica) {}

    /**
     * Nombre del boleto para un documento, con la politica indicada.
     *
     * La politica se recibe como parametro y no se busca aqui: el nombre del
     * boleto depende del PROVEEDOR, que es un dato de la reserva, no del
     * documento. Quien arma la reserva (el controlador) resuelve la politica
     * con PoliticaDeNombres y la pasa.
     *
     * @return array{nombre: string, separador: string}
     */
    public function nombreDelBoleto(DocumentoViaje $documento, ?PoliticaNombres $politica = null): array
    {
        $separador = $this->politica->separadorCon($politica);

        return [
            'nombre' => $documento->nombreCompleto($separador),
            'separador' => $separador,
        ];
    }

    /**
     * Revisa el nombre declarado por el pasajero contra el del documento.
     *
     * Devuelve el estado en que queda el boleto y las diferencias que
     * encontro, para que un agente las mire.
     *
     * IMPORTANTE, Y ES LO MAS IMPORTANTE DE ESTA CLASE: el resultado
     * automatica NUNCA es `Verificado`. Esta comparacion produce datos para
     * que una persona decida; no decide. La unica forma de pasar a
     * `Verificado` es que un agente lo haga, y queda con su nombre y su hora.
     *
     * @return array{estado: EstadoVerificacionNombre, diferencias: list<string>}
     */
    public function revisar(
        DocumentoViaje $documento,
        ?string $nombreDeclarado,
        ?PoliticaNombres $politica = null,
    ): array {
        if ($nombreDeclarado === null || trim($nombreDeclarado) === '') {
            return [
                'estado' => EstadoVerificacionNombre::Pendiente,
                'diferencias' => ['El pasajero no informo un nombre para comparar.'],
            ];
        }

        if (trim($documento->nombres) === '' || trim($documento->apellidos) === '') {
            return [
                'estado' => EstadoVerificacionNombre::Pendiente,
                'diferencias' => ['El documento no tiene nombres y apellidos completos.'],
            ];
        }

        if ($politica === null) {
            // Sin politica configurada, el sistema NO decide. Deja el nombre
            // pendiente de revision, que es lo prudente: nadie dijo que la
            // agencia acepta esta grafia.
            return [
                'estado' => EstadoVerificacionNombre::Pendiente,
                'diferencias' => [
                    'La agencia no tiene una politica de nombres configurada para este proveedor.',
                ],
            ];
        }

        $separador = $this->politica->separadorCon($politica);
        $delDocumento = $politica->alComparar($documento->nombreCompleto($separador));
        $declarado = $politica->alComparar($nombreDeclarado);

        if ($delDocumento === $declarado) {
            return [
                'estado' => EstadoVerificacionNombre::Pendiente,
                'diferencias' => [],
            ];
        }

        return [
            'estado' => EstadoVerificacionNombre::Pendiente,
            'diferencias' => $this->describirDiferencias($delDocumento, $declarado, $politica),
        ];
    }

    /**
     * En que se diferencia un nombre del otro.
     *
     * El resultado se muestra al agente para que decida. Por eso no devuelve
     * un "correcto" y un "incorrecto": devuelve dos textos y la diferencia.
     * Decidir cual es el bueno es trabajo de una persona que puede llamar a la
     * aerolinea, no de una comparacion de cadenas.
     *
     * @return list<string>
     */
    private function_describirDiferencias(string $delDocumento, string $declarado, PoliticaNombres $politica): array
    {
        $diferencias = [];

        $diferencias[] = sprintf(
            'Documento: "%s". Declarado por el pasajero: "%s".',
            $delDocumento,
            $declarado,
        );

        if ($politica->orden_apellidos_primero) {
            $diferencias[] = 'La agencia imprime los apellidos primero; el pasajero puede haber escrito el nombre primero.';
        }

        if ($politica->ignorar_nombres_compuestos) {
            $diferencias[] = 'La agencia considera equivalentes los nombres compuestos, por ejemplo los que llevan guion.';
        }

        if ($politica->exigir_mayusculas || $politica->exigir_tildes) {
            $diferencias[] = 'La agencia exige mayusculas o tildes en el nombre del boleto, segun su configuracion.';
        }

        $diferencias[] = 'La correspondencia la revisa un agente: el sistema no la decide.';

        return $diferencias;
    }
}