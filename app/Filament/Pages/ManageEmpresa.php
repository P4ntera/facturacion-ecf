<?php

namespace App\Filament\Pages;

use App\Enums\AmbienteEcf;
use App\Models\Empresa;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ManageEmpresa extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?string $navigationLabel = 'Datos de la Empresa';

    protected static ?string $title = 'Datos de la Empresa';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('empresa.administrar') ?? false;
    }

    public function mount(): void
    {
        $empresa = $this->empresa();
        $config = $empresa->config();

        $this->form->fill([
            'razon_social' => $empresa->razon_social,
            'nombre_comercial' => $empresa->nombre_comercial,
            'rnc' => $empresa->rnc,
            'telefono' => $empresa->telefono,
            'direccion' => $empresa->direccion,
            'email' => $empresa->email,
            'logo' => $empresa->logo,
            'dgii_api_key' => $config->dgii_api_key,
            'dgii_ambiente' => $config->dgii_ambiente->value,
            'dgii_base_url' => $config->dgii_base_url,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(2)
            ->components([
                TextInput::make('razon_social')
                    ->label('Razón social')
                    ->required()
                    ->maxLength(255),

                TextInput::make('nombre_comercial')
                    ->label('Nombre comercial')
                    ->required()
                    ->maxLength(255),

                TextInput::make('rnc')
                    ->label('RNC')
                    ->required()
                    ->regex('/^\d{9}(\d{2})?$/')
                    ->validationMessages(['regex' => 'El RNC debe tener 9 u 11 dígitos.'])
                    ->maxLength(11),

                TextInput::make('telefono')
                    ->label('Teléfono')
                    ->tel()
                    ->maxLength(50),

                TextInput::make('direccion')
                    ->label('Dirección')
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('email')
                    ->label('Correo electrónico')
                    ->email()
                    ->maxLength(255),

                FileUpload::make('logo')
                    ->label('Logo')
                    ->image()
                    ->disk('public')
                    ->directory('logos')
                    ->columnSpanFull(),

                Section::make('Integración e-CF (PAC)')
                    ->description('Credenciales del proveedor autorizado de servicios (PAC) para emitir e-CF ante la DGII.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->components([
                        TextInput::make('dgii_api_key')
                            ->label('API Key del PAC')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->helperText('Se guarda cifrada; nunca se muestra en reportes ni registros del sistema.')
                            ->columnSpanFull(),

                        Select::make('dgii_ambiente')
                            ->label('Ambiente')
                            ->options(collect(AmbienteEcf::cases())->mapWithKeys(
                                fn (AmbienteEcf $ambiente) => [$ambiente->value => $ambiente->etiqueta()]
                            ))
                            ->required(),

                        TextInput::make('dgii_base_url')
                            ->label('Base URL del PAC')
                            ->helperText('Avanzado: solo cámbiala si el PAC te asignó una URL distinta.')
                            ->url()
                            ->required()
                            ->maxLength(255),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $empresa = $this->empresa();

        $empresa->update([
            'razon_social' => $data['razon_social'],
            'nombre_comercial' => $data['nombre_comercial'],
            'rnc' => $data['rnc'],
            'telefono' => $data['telefono'],
            'direccion' => $data['direccion'],
            'email' => $data['email'],
            'logo' => $data['logo'],
        ]);

        $empresa->config()->update([
            'dgii_api_key' => $data['dgii_api_key'],
            'dgii_ambiente' => $data['dgii_ambiente'],
            'dgii_base_url' => $data['dgii_base_url'],
        ]);

        Notification::make()->title('Datos guardados')->success()->send();
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
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    private function empresa(): Empresa
    {
        /** @var Empresa */
        return Filament::getTenant();
    }
}
