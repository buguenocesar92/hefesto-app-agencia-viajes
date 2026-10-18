<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuracion de la agencia sobre el nombre que se imprime en el boleto.
 *
 * LO QUE ESTA TABLA HACE Y LO QUE NO
 * ----------------------------------
 * HACE: guarda las decisiones que la agencia ya tomo. Separador de nombres,
 * orden en que se escriben, si exige tilde o mayuscula, cuanto espera para
 * considerar que el nombre esta bien.
 *
 * NO HACE: no define ninguna regla. Si esta tabla esta vacia, el sistema NO
 * inventa un separador ni un orden: arma el nombre con los datos del
 * documento, separado por un espacio, y lo deja PENDIENTE de revision. La
 * correspondencia la revisa un agente.
 *
 * Esta separacion es deliberada. Un sistema que "normaliza" nombres por su
 * cuenta produce dos cosas: unpredictibilidad (el nombre del boleto depende de
 * una regla invisible) y falsos positivos (marca como error un nombre que
 * estaba bien). Las dos salidas producen el mismo resultado en el mostrador:
 * un pasajero que no puede abordar.
 *
 * Ver docs/05-CONFIGURACION.md.
 */
class PoliticaNombres extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'politicas_nombres';

    protected $fillable = [
        'tenant_id',
        'proveedor_id',
        'nombre',
        'separador',
        'orden_apellidos_primero',
        'exigir_tildes',
        'exigir_mayusculas',
        'ignorar_nombres_compuestos',
        'activa',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'orden_apellidos_primero' => 'boolean',
            'exigir_tildes' => 'boolean',
            'exigir_mayusculas' => 'boolean',
            'ignorar_nombres_compuestos' => 'boolean',
            'activa' => 'boolean',
            'creado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Proveedor, $this> */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * Normalizar PARA COMPARAR no es transformar el dato.
     *
     * Estos son los ajustes de comparacion, no de impresion. Sirven para que
     * "Pérez" y "PEREZ" no se listen como dos nombres distintos en la pantalla
     * de revision. El texto que se imprime es siempre el del documento.
     */
    public function alComparar(string $valor): string
    {
        $normalizado = $valor;

        if ($this->exigir_mayusculas) {
            $normalizado = mb_strtoupper($normalizado, 'UTF-8');
        }

        if ($this->exigir_tildes) {
            $normalizado = $this->quitarTildes($normalizado);
        }

        return trim(preg_replace('/\s+/u', ' ', $normalizado) ?? $normalizado);
    }

    private function quitarTildes(string $valor): string
    {
        $original = ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ'];
        $reemplazo = ['a', 'e', 'i', 'o', 'u', 'u', 'n', 'A', 'E', 'I', 'O', 'U', 'U', 'N'];

        return str_replace($original, $reemplazo, $valor);
    }
}