<?php

namespace App\Repositories\Contracts;

/**
 * Contrato para el repositorio de Ventas.
 *
 * El patrón Repository aísla Eloquent (o cualquier ORM) del resto del sistema:
 * los Services y Controllers nunca llaman al ORM directamente, sino a esta
 * interfaz. Así se puede cambiar la capa de persistencia sin tocar la lógica
 * de negocio, y se pueden crear implementaciones alternativas (p. ej. para tests).
 */
interface VentaRepositoryInterface
{
    public function all(): iterable;

    public function find(int|string $id): mixed;

    public function create(array $data): mixed;

    public function update(int|string $id, array $data): mixed;

    public function delete(int|string $id): bool;
}
