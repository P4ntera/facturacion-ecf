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
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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
            // El certificado y su contraseña nunca vuelven al navegador: el campo de subida
            // arranca vacío siempre; solo se toca la fila si se sube un archivo nuevo (ver save()).
            'certificado_upload' => null,
            'certificado_password' => null,
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

                Section::make('Certificado digital (.p12)')
                    ->description('Firma las solicitudes ante el PAC. Se guarda en almacenamiento privado y no puede descargarse ni previsualizarse desde aquí; solo puede reemplazarse.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->components([
                        TextEntry::make('certificado_estado')
                            ->label('Estado actual')
                            ->state(fn () => $this->estadoCertificado())
                            ->columnSpanFull(),

                        FileUpload::make('certificado_upload')
                            ->label('Reemplazar certificado')
                            ->disk('local')
                            ->directory('certificados/'.$this->empresa()->id)
                            ->visibility('private')
                            // El MIME real de un .p12 varía mucho entre sistemas (a menudo llega
                            // como application/octet-stream): filtrar por extensión aquí es solo
                            // una ayuda visual del selector de archivos, no la validación real,
                            // que ocurre en save() abriendo el archivo con openssl_pkcs12_read().
                            ->extraInputAttributes(['accept' => '.p12,.pfx'])
                            ->maxSize(5120)
                            ->downloadable(false)
                            ->openable(false)
                            ->previewable(false)
                            ->helperText('Se valida junto con la contraseña antes de guardarse; si no coincide, se rechaza el archivo.'),

                        TextInput::make('certificado_password')
                            ->label('Contraseña del certificado')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->requiredWith('certificado_upload')
                            ->helperText('Requerida para validar el archivo subido. Se guarda cifrada.'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $certificado = null;

        if (filled($data['certificado_upload'] ?? null)) {
            $certificado = $this->validarCertificado($data['certificado_upload'], $data['certificado_password'] ?? '');

            if ($certificado === null) {
                return;
            }
        }

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

        $config = $empresa->config();

        $configData = [
            'dgii_api_key' => $data['dgii_api_key'],
            'dgii_ambiente' => $data['dgii_ambiente'],
            'dgii_base_url' => $data['dgii_base_url'],
        ];

        if ($certificado !== null) {
            $anterior = $config->certificado_path;

            $configData['certificado_path'] = $certificado['path'];
            $configData['certificado_password'] = $data['certificado_password'];
            $configData['certificado_vence'] = $certificado['vence'];

            if (filled($anterior) && $anterior !== $certificado['path']) {
                Storage::disk('local')->delete($anterior);
            }
        }

        $config->update($configData);

        Notification::make()->title('Datos guardados')->success()->send();

        $this->form->fill([...$data, 'certificado_upload' => null, 'certificado_password' => null]);
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

    private function estadoCertificado(): string
    {
        $config = $this->empresa()->config();

        if (! $config->tieneCertificado()) {
            return 'Sin certificado cargado.';
        }

        $vence = $config->certificado_vence?->format('d/m/Y') ?? 'fecha no disponible';

        return $config->certificadoPorVencer()
            ? "Certificado cargado. Vence el {$vence} (vence pronto o ya venció)."
            : "Certificado cargado. Vence el {$vence}.";
    }

    /**
     * Valida de verdad el .p12 recién subido contra la contraseña dada (no basta con la
     * extensión del archivo): si no abre, se rechaza y se borra el archivo huérfano del disco.
     * De paso extrae la fecha de vencimiento del certificado desde el propio X.509.
     *
     * @return array{path: string, vence: ?string}|null null si es inválido (ya se notificó).
     */
    private function validarCertificado(string $path, string $password): ?array
    {
        // El estado de un FileUpload es client-controllable (Livewire): antes de confiar en la
        // ruta hay que confirmar que cae dentro del directorio propio de ESTA empresa, o un
        // usuario de otra empresa podría apuntar al certificado ya subido por un tercero.
        if (! str_starts_with($path, "certificados/{$this->empresa()->id}/")) {
            Storage::disk('local')->delete($path);

            Notification::make()->title('Ruta de certificado inválida.')->danger()->send();

            return null;
        }

        $contenido = Storage::disk('local')->get($path);

        if ($contenido === null || ! openssl_pkcs12_read($contenido, $certificados, $password)) {
            Storage::disk('local')->delete($path);

            Notification::make()
                ->title('El certificado .p12 no es válido o la contraseña no coincide.')
                ->danger()
                ->send();

            return null;
        }

        $info = openssl_x509_parse($certificados['cert']);
        $vence = isset($info['validTo_time_t'])
            ? Carbon::createFromTimestamp($info['validTo_time_t'])->toDateString()
            : null;

        return ['path' => $path, 'vence' => $vence];
    }
}
