<?php

namespace App\Filament\Resources\EmpresaResource\Pages;

use App\Enums\Modulo;
use App\Exceptions\DependenciaModuloException;
use App\Filament\Resources\EmpresaResource;
use App\Models\Empresa;
use App\Models\EmpresaModulo;
use App\Services\ModulosEmpresaService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

class EditEmpresa extends EditRecord
{
    protected static string $resource = EmpresaResource::class;

    /** @return array<string, mixed> */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Empresa $record */
        $record = $this->record;

        $habilitados = $record->modulos()->get()->keyBy(fn (EmpresaModulo $m) => $m->modulo->value);

        foreach (Modulo::cases() as $modulo) {
            $data['modulos'][$modulo->value] = $habilitados->get($modulo->value)?->habilitado ?? true;
        }

        return $data;
    }

    /**
     * Valida las dependencias aquí (antes de guardar nada, ni siquiera los datos de identidad de
     * la empresa) para no dejar un guardado a medias; ModulosEmpresaService::actualizarModulos()
     * (afterSave) vuelve a validar como segunda capa, por si algo llega a llamarlo sin pasar por
     * este formulario.
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        try {
            app(ModulosEmpresaService::class)->validarDependencias($data['modulos'] ?? []);
        } catch (DependenciaModuloException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }

        // No son columnas de empresas: se guardan aparte (afterSave) en empresa_modulos.
        unset($data['modulos']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Empresa $record */
        $record = $this->record;

        app(ModulosEmpresaService::class)->actualizarModulos($record, $this->data['modulos'] ?? []);
    }
}
