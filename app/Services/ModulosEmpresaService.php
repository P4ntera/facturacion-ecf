<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Modulo;
use App\Exceptions\DependenciaModuloException;
use App\Models\Empresa;
use App\Models\EmpresaModulo;
use Illuminate\Support\Facades\DB;

/** Siembra y actualiza los módulos habilitados de cada empresa, respetando sus dependencias. */
class ModulosEmpresaService
{
    public function sembrarModulos(Empresa $empresa): void
    {
        foreach (Modulo::cases() as $modulo) {
            EmpresaModulo::firstOrCreate([
                'empresa_id' => $empresa->id,
                'modulo' => $modulo,
            ], [
                'habilitado' => true,
            ]);
        }
    }

    /**
     * Punto único de escritura de empresa_modulos: valida dependencias SIEMPRE (segunda capa,
     * más allá de lo que ya haya validado la UI) y persiste todo en una transacción.
     *
     * @param  array<string, bool>  $modulos  [Modulo->value => habilitado]
     *
     * @throws DependenciaModuloException
     */
    public function actualizarModulos(Empresa $empresa, array $modulos): void
    {
        $this->validarDependencias($modulos);

        DB::transaction(function () use ($empresa, $modulos) {
            foreach (Modulo::cases() as $modulo) {
                if ($modulo->esBloqueado()) {
                    continue;
                }

                EmpresaModulo::updateOrCreate(
                    ['empresa_id' => $empresa->id, 'modulo' => $modulo],
                    ['habilitado' => (bool) ($modulos[$modulo->value] ?? true)],
                );
            }
        });
    }

    /**
     * Un módulo activo no puede depender de uno que se está dejando apagado en el mismo envío
     * (los módulos sin entrada explícita en $modulos se asumen habilitados, igual que
     * Empresa::tieneModulo()).
     *
     * @param  array<string, bool>  $modulos
     *
     * @throws DependenciaModuloException
     */
    public function validarDependencias(array $modulos): void
    {
        foreach (Modulo::cases() as $modulo) {
            if (! ($modulos[$modulo->value] ?? true)) {
                continue;
            }

            foreach ($modulo->dependeDe() as $requerido) {
                if (! ($modulos[$requerido->value] ?? true)) {
                    throw new DependenciaModuloException(
                        "No se puede desactivar «{$requerido->etiqueta()}»: «{$modulo->etiqueta()}» lo necesita para funcionar."
                    );
                }
            }
        }
    }
}
