<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tenancy\ContextoTenant;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Comprueba que el aislamiento entre agencias esta realmente puesto.
 *
 * POR QUE EXISTE ESTE COMANDO
 * --------------------------
 * El aislamiento no es solo el codigo que escribimos: es el ESTADO en que
 * quedo la base de datos. Un despliegue bien intencionado pero mal configurado
 * (un rol con BYPASSRLS, una politica borrada a mano, una tabla nueva sin
 * proteger) deja la aplicacion funcionando y, al mismo tiempo, completamente
 * abierta entre agencias. Nada en la interfaz lo delata.
 *
 * Por eso se ejecuta en el despliegue y antes de dar por buena una migracion.
 * Devuelve codigo de salida 1 si algo falla.
 *
 *   php artisan viajes:verificar-aislamiento
 */
class VerificarAislamientoCommand extends Command
{
    /**
     * Tablas que DEBEN tener RLS.
     *
     * La lista sale de config/tenancy.php, que es la misma que usa la
     * migracion: si alguien agrega una tabla nueva al catalogo y la pone en la
     * migracion pero no en la configuracion, la migracion falla; y si la pone
     * en la configuracion pero no en la migracion, este comando falla.
     *
     * @return list<string>
     */
    private function tablasProtegidas(): array
    {
        return (array) config('tenancy.tablas_protegidas');
    }

    protected $signature = 'viajes:verificar-aislamiento';

    protected $description = 'Comprueba que la RLS, el rol de base de datos y el contexto de agencia protegen los datos de cada agencia.';

    public function handle(): int
    {
        $fallos = [];

        $fallos = array_merge($fallos, $this->revisarRol());
        $fallos = array_merge($fallos, $this->revisarTablas());
        $fallos = array_merge($fallos, $this->revisarFuncionDeContexto());
        $fallos = array_merge($fallos, $this->revisarLecturaAislada());
        $fallos = array_merge($fallos, $this->revisarIndiceDeExclusion());
        $fallos = array_merge($fallos, $this->revisarVistaPublica());

        if ($fallos !== []) {
            $this->error('El aislamiento entre agencias NO esta bien puesto:');

            foreach ($fallos as $fallo) {
                $this->line('  - ' . $fallo);
            }

            $this->line('');
            $this->line('No sigas con el despliegue. Revisa la migracion');
            $this->line('database/migrations/2026_02_10_000017_activar_row_level_security.php');

            return self::FAILURE;
        }

        $this->info('Aislamiento entre agencias verificado correctamente.');

        return self::SUCCESS;
    }

    /**
     * El rol de la aplicacion no puede ser superusuario ni tener BYPASSRLS.
     *
     * Con cualquiera de las dos cosas, PostgreSQL ignora las politicas RLS por
     * completo: las consultas devolverian filas de todas las agencias y la
     * aplicacion no tendria como enterarse.
     *
     * @return list<string>
     */
    private function revisarRol(): array
    {
        $fila = DB::selectOne(
            'SELECT r.rolname AS nombre, r.rolsuper AS superusuario, r.rolbypassrls AS salta_rls
             FROM pg_roles r
             WHERE r.rolname = current_user'
        );

        if ($fila === null) {
            return ['No se pudo identificar el rol de la aplicacion en PostgreSQL.'];
        }

        $fallos = [];

        if ((bool) $fila->superusuario) {
            $fallos[] = sprintf(
                'El rol "%s" es SUPERUSUARIO: ignora todas las politicas RLS.',
                $fila->nombre
            );
        }

        if ((bool) $fila->salta_rls) {
            $fallos[] = sprintf(
                'El rol "%s" tiene BYPASSRLS: ignora todas las politicas RLS.',
                $fila->nombre
            );
        }

        if ($fallos === []) {
            $this->line('  [ok] Rol "' . $fila->nombre . '": sin superusuario y sin BYPASSRLS.');
        }

        return $fallos;
    }

    /**
     * Cada tabla de negocio tiene RLS activada, forzada y con politica.
     *
     * @return list<string>
     */
    private function revisarTablas(): array
    {
        $fallos = [];
        $tablas = $this->tablasProtegidas();

        foreach ($tablas as $tabla) {
            $estado = DB::selectOne(
                'SELECT c.relrowsecurity AS rls, c.relforcerowsecurity AS forzada
                 FROM pg_class c
                 WHERE c.oid = to_regclass(?)',
                ['public.' . $tabla]
            );

            if ($estado === null) {
                $fallos[] = 'La tabla public.' . $tabla . ' no existe. Falto correr las migraciones.';

                continue;
            }

            if (! $estado->rls) {
                $fallos[] = 'La tabla public.' . $tabla . ' NO tiene Row Level Security activada.';

                continue;
            }

            if (! $estado->forzada) {
                $fallos[] = 'La tabla public.' . $tabla . ' tiene RLS pero NO esta forzada '
                    . '(FORCE ROW LEVEL SECURITY): el dueno de la tabla saltaria la politica.';

                continue;
            }

            $politicas = DB::selectOne(
                'SELECT count(*) AS total FROM pg_policies WHERE schemaname = ? AND tablename = ?',
                ['public', $tabla]
            );

            if ((int) ($politicas->total ?? 0) === 0) {
                $fallos[] = 'La tabla public.' . $tabla . ' tiene RLS activada pero no tiene ninguna politica.';
            }
        }

        if ($fallos === []) {
            $this->line('  [ok] ' . count($tablas) . ' tablas con RLS activada, forzada y con politica.');
        }

        return $fallos;
    }

    /**
     * Sin contexto de agencia, la funcion de contexto tiene que FALLAR.
     *
     * Si devolviera NULL en vez de lanzar excepcion, una consulta sin contexto
     * devolveria cero filas por accidente en vez de por diseno, y en el futuro
     * alguien podria "arreglarlo" devolviendo NULL y abrir la puerta sin
     * darse cuenta.
     *
     * @return list<string>
     */
    private function revisarFuncionDeContexto(): array
    {
        try {
            DB::select('SELECT public.app_tenant_id()');
        } catch (QueryException) {
            $this->line('  [ok] public.app_tenant_id() falla sin contexto de agencia (aislamiento cerrado).');

            return [];
        }

        return [
            'public.app_tenant_id() devolvio un valor sin que haya contexto de agencia. '
            . 'Deberia lanzar excepcion para que el aislamiento falle cerrado.',
        ];
    }

    /**
     * Con una agencia que no existe, las tablas de negocio deben salir vacias.
     *
     * @return list<string>
     */
    private function revisarLecturaAislada(): array
    {
        $agenciaInexistente = (string) Str::uuid();
        $contexto = new ContextoTenant();

        try {
            $conteo = $contexto->ejecutarAislado(
                $agenciaInexistente,
                fn (): int => DB::table('reservas')->count()
            );
        } catch (QueryException $excepcion) {
            return ['No se pudo leer "reservas" con un contexto de agencia: ' . $excepcion->getMessage()];
        }

        $fallos = [];

        if ($conteo !== 0) {
            $fallos[] = 'La tabla "reservas" devolvio ' . $conteo . ' filas para una agencia inexistente.';
        }

        $contextoResiduo = $contexto->tenantIdEnConexion();

        if ($contextoResiduo !== null) {
            $fallos[] = 'La variable app.tenant_id quedo fijada (' . $contextoResiduo . ') despues de la transaccion. '
                . 'Si queda colgando, el worker de colas podria leer datos de otra agencia.';
        }

        if ($fallos === []) {
            $this->line('  [ok] Con una agencia inexistente no se lee ninguna fila y el contexto no queda colgando.');
        }

        return $fallos;
    }

    /**
     * El indice de exclusion de asientos tiene que existir.
     *
     * Esta revision es la que hace que la garantia de concurrencia sea una
     * garantia y no una intencion. Si alguien borra la restriccion para
     * "desbloquear una venta", la aplicacion sigue funcionando y dos personas
     * pueden terminar con el mismo asiento. Ver docs/01, seccion "Bloqueo de
     * asientos".
     *
     * @return list<string>
     */
    private function revisarIndiceDeExclusion(): array
    {
        $existe = DB::selectOne(
            "SELECT count(*) AS total
             FROM pg_constraint
             WHERE conrelid = to_regclass('public.asientos')
               AND contype = 'x'
               AND conname = 'asientos_no_duplicados'"
        );

        if ((int) ($existe->total ?? 0) === 0) {
            return [
                'La restriccion de exclusion "asientos_no_duplicados" no existe en public.asientos. '
                . 'Sin ella, dos compras simultaneas pueden tomar el mismo asiento.',
            ];
        }

        // La extension btree_gist es la que hace posible el indice de exclusion
        // sobre columnas uuid. Si alguien la borro, PostgreSQL no aviso y las
        // consultas empiezan a fallar.
        $extension = DB::selectOne("SELECT count(*) AS total FROM pg_extension WHERE extname = 'btree_gist'");

        if ((int) ($extension->total ?? 0) === 0) {
            return [
                'La extension btree_gist no esta instalada. Sin ella no se puede crear el indice de '
                . 'exclusion sobre uuid de asientos.',
            ];
        }

        $this->line('  [ok] La restriccion de exclusion de asientos existe y btree_gist esta instalada.');

        return [];
    }

    /**
     * La vista publica de agencias debe existir y exponer solo tres columnas.
     *
     * @return list<string>
     */
    private function revisarVistaPublica(): array
    {
        $columnas = DB::select(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'vw_agencias_publicas'
             ORDER BY column_name"
        );

        if ($columnas === []) {
            return ['No existe la vista public.vw_agencias_publicas.'];
        }

        $nombres = array_map(static fn ($fila): string => (string) $fila->column_name, $columnas);
        sort($nombres);

        $esperadas = ['activo', 'nombre', 'slug'];
        sort($esperadas);

        if ($nombres !== $esperadas) {
            return ['La vista public.vw_agencias_publicas expone columnas distintas de las tres esperadas ('
                . implode(', ', $nombres) . '). No debe exponer datos de contacto de las agencias.'];
        }

        $this->line('  [ok] La vista publica de agencias expone solo slug, nombre y activo.');

        return [];
    }
}