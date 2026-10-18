<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Agencia;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Base de las pruebas de la agencia.
 *
 * Aporta utilidades para el aislamiento y para armar el escenario de viajes,
 * que es lo que mas se prueba.
 *
 *  - `comoAgente()`, que autentica a una persona Y fija su contexto de agencia
 *    en la base. IMPORTANTE: autenticar con `actingAs()` NO fija el contexto,
 *    porque el middleware ResolverTenant corre dentro del grupo `web`, que las
 *    pruebas no ejecutan. Si solo se autentica, la RLS no tendria contra que
 *    filtrar.
 *
 *  - `crearAgencia()`, que arma una agencia con su administrador y su agente.
 *
 * LAS PRUEBAS CORREN CONTRA POSTGRESQL DE VERDAD
 * ----------------------------------------------
 * Nunca contra SQLite. El aislamiento esta implementado con Row Level Security,
 * que es una funcion de PostgreSQL: contra SQLite, estas pruebas pasarian sin
 * comprobar nada. Ver phpunit.xml y docs/10.
 */
abstract class TestCase extends BaseTestCase
{
    /** Agencia fijada para la prueba en curso. */
    protected ?string $agenciaDePrueba = null;

    /**
     * Autentica a un agente y fija su contexto de agencia en la base.
     */
    protected function comoAgente(Usuario $usuario): static
    {
        $this->actingAs($usuario);

        return $this->fijarAgencia($usuario->tenant_id);
    }

    /**
     * Emula lo que hace App\Http\Middleware\ResolverTenant.
     *
     * Se usa `set_config(..., false)`, o sea con alcance de SESION y no de
     * transaccion. En la aplicacion real es al reves (alcance de transaccion,
     * para que el worker de colas no reutilice la agencia de otra). En las
     * pruebas cada caso abre su propia conexion, asi que el valor no sobrevive
     * entre pruebas: por eso el alcance de sesion es seguro aca y hace falta
     * para que las aserciones, que corren fuera de la transaccion del
     * middleware, sigan viendo el mismo contexto.
     */
    protected function fijarAgencia(?string $agenciaId): static
    {
        if ($agenciaId === null) {
            return $this;
        }

        DB::select('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, $agenciaId]);

        $this->agenciaDePrueba = $agenciaId;

        return $this;
    }

    /**
     * Crea una agencia con su administrador y su agente, y fija el contexto.
     *
     * @return array{agencia: Agencia, administrador: Usuario, agente: Usuario}
     */
    protected function crearAgencia(string $dominio = 'ejemplo.cl', string $nombre = 'Agencia de Prueba'): array
    {
        $agencia = Agencia::factory()->create([
            'slug' => \Illuminate\Support\Str::slug($nombre) . '-' . substr(md5($dominio), 0, 6),
            'dominio_correo' => $dominio,
        ]);

        $this->fijarAgencia($agencia->id);

        return [
            'agencia' => $agencia,
            'administrador' => Usuario::factory()->administrador()->de($agencia)->create(),
            'agente' => Usuario::factory()->de($agencia)->create(),
        ];
    }

    /**
     * Una SEGUNDA agencia, independiente de la primera.
     *
     * @return array{agencia: Agencia, administrador: Usuario, agente: Usuario}
     */
    protected function crearSegundaAgencia(string $dominio = 'otra.cl'): array
    {
        return $this->crearAgencia($dominio, 'Otra Agencia de Prueba');
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    protected function contextoLimpio(): void
    {
        DB::select('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, '']);
    }
}