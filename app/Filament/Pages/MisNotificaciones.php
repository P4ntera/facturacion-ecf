<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\TipoNotificacion;
use App\Models\PreferenciaNotificacion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Preferencias personales de notificación: el rol decide QUÉ alertas puede recibir cada usuario
 * (permisos notificaciones.*); aquí cada uno silencia las que no quiera en su campana. Solo
 * lista las que su rol le permite y que se envían como notificación in-app (las de solo
 * dashboard, como cuentas vencidas, no se silencian aquí). Se abre desde el menú de usuario.
 */
class MisNotificaciones extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Mis notificaciones';

    protected static ?string $slug = 'mis-notificaciones';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return self::tiposDisponibles() !== [];
    }

    /** @return array<int, TipoNotificacion> */
    public static function tiposDisponibles(): array
    {
        $usuario = auth()->user();

        if ($usuario === null) {
            return [];
        }

        return array_values(array_filter(
            TipoNotificacion::cases(),
            fn (TipoNotificacion $tipo) => $tipo->seEnviaEnApp() && $usuario->can($tipo->permiso()),
        ));
    }

    public function mount(): void
    {
        $this->form->fill(collect(self::tiposDisponibles())->mapWithKeys(
            fn (TipoNotificacion $tipo) => [$tipo->value => auth()->user()->recibeNotificacion($tipo)]
        )->all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components(collect(self::tiposDisponibles())->map(
                fn (TipoNotificacion $tipo) => Toggle::make($tipo->value)->label($tipo->etiqueta())
            )->all());
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // Solo se guardan los tipos que el rol permite: un valor manipulado desde el navegador
        // para otro tipo se ignora.
        foreach (self::tiposDisponibles() as $tipo) {
            PreferenciaNotificacion::updateOrCreate(
                ['user_id' => auth()->id(), 'tipo' => $tipo->value],
                ['activa' => (bool) ($data[$tipo->value] ?? true)],
            );
        }

        Notification::make()->title('Preferencias guardadas')->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Guardar')
                            ->submit('save'),
                    ]),
                ]),
        ]);
    }
}
