<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Usuario;

/**
 * Administracion: proveedores, productos, tarifas y politicas.
 *
 * SOLO EL ADMINISTRADOR DE LA AGENCIA.
 *
 * Estas son las decisiones que cambian las condiciones comerciales: los precios,
 * los plazos de cancelacion, las reglas de nombres. Un agente que las cambia
 * puede, sin querer, regalar un pasaje o emitir un boleto con un nombre que la
 * aerolinea va a rechazar.
 *
 * El rol sale de la tabla `usuarios`, no de Authentik. Authentik dice quien
 * entro; esta tabla dice que puede hacer DENTRO de la agencia. Ver docs/03.
 */
class AdministracionPolicy
{
    public function ver(Usuario $usuario, string $recurso): bool
    {
        return $usuario->activo && $usuario->tenant_id !== null;
    }

    public function crear(Usuario $usuario, string $recurso): bool
    {
        return $usuario->activo && $usuario->esAdministrador();
    }

    public function actualizar(Usuario $usuario, string $recurso): bool
    {
        return $usuario->activo && $usuario->esAdministrador();
    }

    /**
     * Cambiar las POLITICAS de nombres y de cancelacion.
     *
     * Lo mas restringido de la administracion, y a proposito: son las reglas
     * que decide el sistema en la emision de un boleto y en una cancelacion.
     */
    public function cambiarPoliticas(Usuario $usuario, string $recurso): bool
    {
        return $usuario->activo && $usuario->esAdministrador();
    }
}