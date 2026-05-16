<?php

namespace App\Http\Controllers\Api\Concerns;

trait ScopesBySucursal
{
    protected function sucursalId(): ?int
    {
        return app()->bound('sucursal_id') ? (int) app('sucursal_id') : null;
    }

    protected function applySucursalScope($query): mixed
    {
        $id = $this->sucursalId();
        return $id ? $query->where('sucursal_id', $id) : $query;
    }
}
