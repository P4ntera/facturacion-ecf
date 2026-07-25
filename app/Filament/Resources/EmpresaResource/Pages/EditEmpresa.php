<?php

namespace App\Filament\Resources\EmpresaResource\Pages;

use App\Enums\Modulo;
use App\Filament\Resources\EmpresaResource;
use App\Models\Empresa;
use App\Models\EmpresaModulo;
use Filament\Resources\Pages\EditRecord;

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

    /** @return array<string, mixed> */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // No son columnas de empresas: se guardan aparte (afterSave) en empresa_modulos.
        unset($data['modulos']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Empresa $record */
        $record = $this->record;

        $modulos = $this->data['modulos'] ?? [];

        foreach (Modulo::cases() as $modulo) {
            if ($modulo->esBloqueado()) {
                continue;
            }

            EmpresaModulo::updateOrCreate(
                ['empresa_id' => $record->id, 'modulo' => $modulo],
                ['habilitado' => (bool) ($modulos[$modulo->value] ?? true)],
            );
        }
    }
}
