<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Asiento;
use App\Models\Usuario;
use App\Models\Vuelo;

/**
 * Permisos sobre asientos.
 *
 * El mapa de asientos lo puede ver cualquiera de la agencia: es una pantalla
 * de trabajo, no un dato personal. TOMAR un asiento es otra cosa, y cambia el
 * estado de una venta.
 */
class AsientoPolicy
{
    public function ver(Usuario $usuario, Vuelo $vuelo): bool
    {
        return $usuario->tenant_id !== null && $usuario->tenant_id === $vuelo->tenant_id;
    }

    /** Tomar y liberar asientos: cualquier agente activo de la agencia. */
    public function actualizar(Usuario $usuario, Vuelo $vuelo): bool
    {
        return $usuario->activo && $usuario->tenant_id === $vuelo->tenant_id;
    }

    public function verAsiento(Usuario $usuario, Asiento $asiento): bool
    {
        return $usuario->tenant_id !== null && $usuario->tenant_id === $asiento->tenant_id;
    }
}