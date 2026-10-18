<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AISLAMIENTO ENTRE AGENCIAS: Row Level Security (RLS) de PostgreSQL.
 *
 * Esta migracion es el corazon del aislamiento. Ver docs/03.
 *
 * MECANISMO
 * ---------
 * 1. `public.app_tenant_id()` lee el valor de la variable de sesion
 *    `app.tenant_id`. Si no esta definida, LANZA EXCEPCION. No devuelve NULL
 *    ni cadena vacia: falla de forma ruidosa.
 * 2. Cada tabla sensible tiene una politica que exige
 *    `tenant_id = app_tenant_id()`, tanto al leer (USING) como al escribir
 *    (WITH CHECK).
 * 3. `FORCE ROW LEVEL SECURITY` hace que la politica se aplique tambien al
 *    dueno de la tabla. Sin FORCE, el dueno la saltaria.
 * 4. La variable se fija con `set_config('app.tenant_id', ..., true)`, o sea
 *    con alcance de TRANSACCION. Al confirmar o revertir, PostgreSQL la
 *    descarta sola. Por eso no puede quedar colgando en una conexion que
 *    reutiliza el worker de colas, que es donde un `SET` normal filtraria datos
 *    de una agencia a otra.
 *
 * LA TABLA `agencias` NO TIENE RLS, A PROPOSITO
 * --------------------------------------------
 * Se consulta durante el login, cuando todavia no hay sesion y por lo tanto no
 * hay contexto que poner. La compensacion es la vista `vw_agencias_publicas`
 * (migracion siguiente), que expone solo slug, nombre y activo, y que el
 * comando `viajes:verificar-aislamiento` comprueba.
 *
 * REQUISITO DE DESPLIEGUE
 * -----------------------
 * El rol que usa la aplicacion NO puede ser superusuario ni tener BYPASSRLS.
 * Con cualquiera de las dos cosas, PostgreSQL ignora las politicas por
 * completo. El comando `viajes:verificar-aislamiento` lo comprueba.
 *
 * Mismo patron que apps/portal-autoservicio/docs/03-MULTITENANCY.md.
 */
return new class extends Migration
{
    /**
     * Tablas con datos de negocio, y por lo tanto con RLS.
     *
     * @var list<string>
     */
    private array $tablasProtegidas = [
        'usuarios',
        'proveedores',
        'productos',
        'tarifas',
        'vuelos',
        'asientos',
        'reservas',
        'reserva_eventos',
        'reserva_pasajeros',
        'pasajeros',
        'documentos_viaje',
        'pagos',
        'politicas_nombres',
        'politicas_cancelacion',
        'configuraciones',
        'bitacora_accesos',
    ];

    public function up(): void
    {
        $this->crearFuncionDeContexto();

        foreach ($this->tablasProtegidas as $tabla) {
            $this->proteger($tabla);
        }

        // Si alguna tabla de la lista no tiene `tenant_id`, la migracion falla
        // en vez de dejar datos de negocio sin proteccion.
        $this->verificarColumnaTenant();
    }

    public function down(): void
    {
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne('SELECT to_regclass(?) AS tabla', ['public.' . $tabla]);

            if ($existe === null || $existe->tabla === null) {
                continue;
            }

            DB::unprepared('DROP POLICY IF EXISTS politica_aislamiento_' . $tabla . ' ON public.' . $tabla);
            DB::unprepared('ALTER TABLE public.' . $tabla . ' NO FORCE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE public.' . $tabla . ' DISABLE ROW LEVEL SECURITY');
        }

        DB::unprepared('DROP FUNCTION IF EXISTS public.app_tenant_id()');
    }

    /**
     * La funcion de contexto.
     *
     * LANZA EXCEPCION si no hay tenant. Podria haber devuelto NULL y filtrado
     * todo en silencio, pero se eligio que falle de forma ruidosa: si devuelve
     * NULL, la consulta devuelve cero filas sin aviso, y en el futuro alguien
     * podria "arreglarlo" devolviendo NULL cuando hace falta y abrir la puerta
     * sin que nada lo delate.
     */
    private function crearFuncionDeContexto(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
            LANGUAGE plpgsql
            STABLE
            AS $$
            DECLARE
                valor text;
            BEGIN
                valor := current_setting('app.tenant_id', true);

                IF valor IS NULL OR valor = '' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'insufficient_privilege',
                        MESSAGE = 'No hay contexto de agencia en la conexion (app.tenant_id no esta definida). Se aborta la operacion para no exponer datos de otra agencia.',
                        HINT = 'La peticion no paso por App\Http\Middleware\ResolverTenant, o la operacion se ejecuto fuera de una transaccion.';
                END IF;

                RETURN valor::uuid;
            END
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON FUNCTION public.app_tenant_id() IS
            'Devuelve la agencia de la peticion. Lanza excepcion si no hay contexto: el aislamiento falla cerrado.';
        SQL);
    }

    private function proteger(string $tabla): void
    {
        $existe = DB::selectOne('SELECT to_regclass(?) AS tabla', ['public.' . $tabla]);

        // to_regclass devuelve NULL si la tabla todavia no existe.
        if ($existe === null || $existe->tabla === null) {
            return;
        }

        DB::unprepared('ALTER TABLE public.' . $tabla . ' ENABLE ROW LEVEL SECURITY');

        // FORCE hace que la politica aplique tambien al dueno.
        // Es indispensable: en desarrollo el dueno suele ser el mismo rol que
        // corre la aplicacion.
        DB::unprepared('ALTER TABLE public.' . $tabla . ' FORCE ROW LEVEL SECURITY');

        $nombre = 'politica_aislamiento_' . $tabla;

        DB::unprepared(<<<SQL
            DROP POLICY IF EXISTS {$nombre} ON public.{$tabla};

            CREATE POLICY {$nombre} ON public.{$tabla}
                FOR ALL
                TO PUBLIC
                USING (tenant_id = public.app_tenant_id())
                WITH CHECK (tenant_id = public.app_tenant_id());
        SQL);
    }

    /**
     * Falla ruidosamente si alguna tabla de la lista no tiene `tenant_id`.
     *
     * Sin esto, un error de tipeo dejaria una tabla de datos de negocio sin
     * politica y la migracion pasaria igual.
     */
    private function verificarColumnaTenant(): void
    {
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne(
                'SELECT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = \'public\' AND table_name = ? AND column_name = \'tenant_id\'
                 ) AS tiene_columna',
                [$tabla]
            );

            if ($existe === null || ! $existe->tiene_columna) {
                throw new RuntimeException(
                    'La tabla public.' . $tabla . ' no tiene columna tenant_id y no puede protegerse con RLS. '
                    . 'Revise la migracion antes de continuar.'
                );
            }
        }
    }
};