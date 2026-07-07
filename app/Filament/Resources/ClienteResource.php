<?php

namespace App\Filament\Resources;

use App\Enums\TipoDocumentoCliente;
use App\Filament\Resources\ClienteResource\Pages;
use App\Models\Cliente;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ClienteResource extends Resource
{
    protected static ?string $model = Cliente::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Clientes';

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clientes';

    protected static string|\UnitEnum|null $navigationGroup = 'Maestros';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('tipo_documento')
                    ->label('Tipo de documento')
                    ->options([
                        TipoDocumentoCliente::RNC->value => 'RNC',
                        TipoDocumentoCliente::CEDULA->value => 'Cédula',
                        TipoDocumentoCliente::SIN_DOCUMENTO->value => 'Sin documento',
                    ])
                    ->required()
                    ->default(TipoDocumentoCliente::CEDULA->value),

                TextInput::make('documento')
                    ->label('Documento')
                    ->maxLength(20),

                TextInput::make('nombre')
                    ->label('Nombre completo / Razón social')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('telefono')
                    ->label('Teléfono')
                    ->tel()
                    ->maxLength(20),

                TextInput::make('email')
                    ->label('Correo electrónico')
                    ->email()
                    ->maxLength(255),

                Textarea::make('direccion')
                    ->label('Dirección')
                    ->rows(2)
                    ->columnSpanFull(),

                Toggle::make('activo')
                    ->label('Activo')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('tipo_documento')
                    ->label('Tipo doc.')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        TipoDocumentoCliente::RNC => 'RNC',
                        TipoDocumentoCliente::CEDULA => 'Cédula',
                        TipoDocumentoCliente::SIN_DOCUMENTO => '—',
                        default => $state,
                    }),

                TextColumn::make('documento')
                    ->label('Documento')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->placeholder('—'),

                TextColumn::make('email')
                    ->label('Correo')
                    ->placeholder('—')
                    ->toggleable(),

                ToggleColumn::make('activo')
                    ->label('Activo')
                    ->disabled(fn (): bool => ! auth()->user()?->can('gestionar_maestros'))
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('activo')->label('Activo')->default(true),
            ])
            ->defaultSort('nombre');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClientes::route('/'),
            'create' => Pages\CreateCliente::route('/create'),
            'edit' => Pages\EditCliente::route('/{record}/edit'),
        ];
    }
}
