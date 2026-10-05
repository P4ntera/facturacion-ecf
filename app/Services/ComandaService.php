<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoComanda;
use App\Enums\EstadoPreparacion;
use App\Models\Comanda;
use App\Models\ComandaDetalle;
use App\Models\Empresa;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ComandaService
{
    public function __construct(
        private readonly VentaService $ventaService,
    ) {}

    public function abrir(Empresa $empresa, Mesa $mesa, User $mesero, int $comensales = 1, ?string $notas = null): Comanda
    {
        if ($mesa->empresa_id !== $empresa->id) {
            throw new RuntimeException('La mesa no pertenece a esta empresa.');
        }

        return DB::transaction(function () use ($empresa, $mesa, $mesero, $comensales, $notas) {
            $mesa->lockForUpdate();
            $mesa->refresh();

            if (! $mesa->estaDisponible()) {
                throw new RuntimeException('La mesa no está disponible.');
            }

            $mesa->ocupar();

            return Comanda::create([
                'empresa_id' => $empresa->id,
                'mesa_id' => $mesa->id,
                'mesero_id' => $mesero->id,
                'numero' => Comanda::generarNumero($empresa->id),
                'comensales' => $comensales,
                'notas' => $notas,
            ]);
        });
    }

    public function agregarProducto(
        Comanda $comanda,
        Producto $producto,
        float $cantidad = 1,
        ?string $notas = null,
    ): ComandaDetalle {
        if ($producto->empresa_id !== $comanda->empresa_id) {
            throw new RuntimeException('El producto no pertenece a esta empresa.');
        }

        if (! in_array($comanda->estado, [EstadoComanda::ABIERTA, EstadoComanda::EN_PREPARACION])) {
            throw new RuntimeException('No se pueden agregar productos a esta comanda.');
        }

        return $comanda->detalles()->create([
            'producto_id' => $producto->id,
            'cantidad' => $cantidad,
            'precio_unitario' => $producto->precio,
            'notas' => $notas,
        ]);
    }

    public function enviarACocina(Comanda $comanda): int
    {
        $pendientes = $comanda->detalles()
            ->where('estado_preparacion', EstadoPreparacion::PENDIENTE)
            ->get();

        if ($pendientes->isEmpty()) {
            return 0;
        }

        $ahora = now();
        $pendientes->each(fn (ComandaDetalle $d) => $d->update([
            'estado_preparacion' => EstadoPreparacion::EN_PREPARACION,
            'enviado_cocina_en' => $ahora,
        ]));

        if ($comanda->estado === EstadoComanda::ABIERTA) {
            $comanda->update(['estado' => EstadoComanda::EN_PREPARACION]);
        }

        return $pendientes->count();
    }

    public function marcarPreparado(ComandaDetalle $detalle): void
    {
        $detalle->update([
            'estado_preparacion' => EstadoPreparacion::LISTO,
            'preparado_en' => now(),
        ]);

        $comanda = $detalle->comanda;
        $todosListos = $comanda->detalles()
            ->whereNotIn('estado_preparacion', [
                EstadoPreparacion::LISTO,
                EstadoPreparacion::ENTREGADO,
                EstadoPreparacion::CANCELADO,
            ])
            ->doesntExist();

        if ($todosListos) {
            $comanda->update(['estado' => EstadoComanda::LISTA]);
        }
    }

    public function cerrar(Comanda $comanda, User $cajero, array $datosVenta = []): Venta
    {
        if (in_array($comanda->estado, [EstadoComanda::CERRADA, EstadoComanda::CANCELADA])) {
            throw new RuntimeException('Esta comanda no se puede cerrar.');
        }

        return DB::transaction(function () use ($comanda, $cajero, $datosVenta) {
            $detallesActivos = $comanda->detalles()
                ->where('estado_preparacion', '!=', EstadoPreparacion::CANCELADO)
                ->with('producto')
                ->get();

            if ($detallesActivos->isEmpty()) {
                throw new RuntimeException('La comanda no tiene productos activos.');
            }

            $lineas = $detallesActivos->map(fn (ComandaDetalle $d) => [
                'producto_id' => $d->producto_id,
                'cantidad' => $d->cantidad,
                'precio_unitario' => $d->precio_unitario,
            ])->toArray();

            $venta = $this->ventaService->registrar(
                array_merge($datosVenta, ['lineas' => $lineas]),
                $comanda->empresa,
            );

            $comanda->detalles()
                ->where('estado_preparacion', '!=', EstadoPreparacion::CANCELADO)
                ->update(['estado_preparacion' => EstadoPreparacion::ENTREGADO]);

            $comanda->update([
                'estado' => EstadoComanda::CERRADA,
                'venta_id' => $venta->id,
                'cerrada_en' => now(),
            ]);

            $comanda->mesa->lockForUpdate();
            $comanda->mesa->liberar();

            return $venta;
        });
    }

    public function cancelar(Comanda $comanda): void
    {
        if ($comanda->estado === EstadoComanda::CERRADA) {
            throw new RuntimeException('No se puede cancelar una comanda cerrada.');
        }

        DB::transaction(function () use ($comanda) {
            $comanda->detalles()
                ->whereNot('estado_preparacion', EstadoPreparacion::CANCELADO)
                ->update(['estado_preparacion' => EstadoPreparacion::CANCELADO]);

            $comanda->update(['estado' => EstadoComanda::CANCELADA]);

            $comanda->mesa->lockForUpdate();
            $comanda->mesa->liberar();
        });
    }

    public function transferir(Comanda $comanda, Mesa $nuevaMesa): void
    {
        if ($nuevaMesa->empresa_id !== $comanda->empresa_id) {
            throw new RuntimeException('La mesa destino no pertenece a esta empresa.');
        }

        DB::transaction(function () use ($comanda, $nuevaMesa) {
            $nuevaMesa->lockForUpdate();
            $nuevaMesa->refresh();

            if (! $nuevaMesa->estaDisponible()) {
                throw new RuntimeException('La mesa destino no está disponible.');
            }

            $mesaAnterior = $comanda->mesa;
            $comanda->update(['mesa_id' => $nuevaMesa->id]);
            $nuevaMesa->ocupar();

            $mesaAnterior->lockForUpdate();
            $mesaAnterior->liberar();
        });
    }
}
