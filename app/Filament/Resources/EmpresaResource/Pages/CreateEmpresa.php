<?php

namespace App\Filament\Resources\EmpresaResource\Pages;

use App\Enums\PerfilEmpresa;
use App\Filament\Resources\EmpresaResource;
use App\Models\Empresa;
use App\Services\ModulosEmpresaService;
use App\Services\RolesEmpresaService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\QueryException;

class CreateEmpresa extends CreateRecord
{
    protected static string $resource = EmpresaResource::class;

    /**
     * Intercepta la creación para convertir errores SQL en mensajes legibles.
     *
     * Las dos situaciones más comunes:
     * 1) NOT NULL violation (ej. rnc antes de correr la migración que lo hace nullable)
     * 2) UNIQUE violation (ej. RNC duplicado, slug duplicado)
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            return static::getModel()::create($data);
        } catch (QueryException $e) {
            $mensaje = $this->traducirErrorSql($e);

            Notification::make()
                ->title('No se pudo crear la empresa')
                ->body($mensaje)
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    /** Toda empresa nueva entra ya con sus roles base y todos sus módulos habilitados. */
    protected function afterCreate(): void
    {
        /** @var Empresa $record */
        $record = $this->record;

        try {
            app(RolesEmpresaService::class)->sembrarRolesBase($record);
            app(ModulosEmpresaService::class)->sembrarModulos($record);

            // Lavandería siempre vende sin comprobante por defecto (el cliente lo pide si lo necesita).
            if ($record->perfil === PerfilEmpresa::LAVANDERIA) {
                $record->config()->update(['permite_ventas_sin_comprobante' => true]);
            }
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title('Empresa creada, pero ocurrió un error al configurarla')
                ->body('Revisa los roles y módulos de esta empresa manualmente. Detalle: '.$e->getMessage())
                ->warning()
                ->persistent()
                ->send();
        }
    }

    /**
     * Convierte excepciones de PostgreSQL en mensajes amigables para el usuario.
     */
    private function traducirErrorSql(QueryException $e): string
    {
        $sqlState = $e->errorInfo[0] ?? '';
        $mensaje = $e->errorInfo[2] ?? $e->getMessage();

        // NOT NULL violation (23502)
        if ($sqlState === '23502') {
            if (str_contains($mensaje, '"rnc"')) {
                return 'El campo RNC es obligatorio para este tipo de empresa. Si estás creando una lavandería u otro negocio sin RNC, ejecuta las migraciones pendientes (php artisan migrate).';
            }

            // Extraer nombre de columna del mensaje de PostgreSQL
            if (preg_match('/column "(\w+)"/', $mensaje, $matches)) {
                return "El campo \"{$matches[1]}\" es obligatorio y no puede quedar vacío.";
            }

            return 'Uno de los campos obligatorios está vacío. Revisa que todos los datos requeridos estén completos.';
        }

        // UNIQUE violation (23505)
        if ($sqlState === '23505') {
            if (str_contains($mensaje, 'rnc')) {
                return 'Ya existe una empresa registrada con ese RNC. Verifica el número o busca la empresa existente.';
            }

            if (str_contains($mensaje, 'slug')) {
                return 'Ya existe una empresa con esa URL (slug). Deja el campo vacío para generar una automáticamente.';
            }

            return 'Ya existe un registro con esos datos. Verifica que no estés duplicando una empresa.';
        }

        // Otros errores de integridad (genéricos)
        if (str_starts_with($sqlState, '23')) {
            return 'Error de datos: '.$this->limpiarMensajeSql($mensaje);
        }

        // Error desconocido — log completo, mensaje genérico
        report($e);

        return 'Ocurrió un error inesperado al guardar. Contacta al administrador del sistema.';
    }

    /**
     * Limpia un mensaje de error SQL para que sea legible sin exponer detalles internos.
     */
    private function limpiarMensajeSql(string $mensaje): string
    {
        // Eliminar prefijos técnicos de PostgreSQL
        $mensaje = preg_replace('/^ERROR:\s*/i', '', $mensaje);

        // Cortar en el primer DETAIL/CONTEXT/HINT
        $mensaje = preg_replace('/\s*(DETAIL|CONTEXT|HINT):.*/s', '', $mensaje);

        return trim($mensaje);
    }
}
