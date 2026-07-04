<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditoriaResource\Pages;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Spatie\Activitylog\Models\Activity;

class AuditoriaResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Auditoría';

    protected static ?string $modelLabel = 'Actividad';

    protected static ?string $pluralModelLabel = 'Auditoría';

    protected static ?string $slug = 'auditoria';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    // Auditoría de solo lectura: append-only, igual que el Kardex.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
            TextEntry::make('causer.name')->label('Usuario')->placeholder('Sistema'),
            TextEntry::make('log_name')->label('Módulo'),
            TextEntry::make('event')->label('Acción')->formatStateUsing(fn (Activity $record) => self::etiquetaAccion($record)),
            TextEntry::make('registro')->label('Registro')->getStateUsing(fn (Activity $record) => self::registroLegible($record)),
            TextEntry::make('cambios')
                ->label('Antes / Después')
                ->columnSpanFull()
                ->html()
                ->getStateUsing(fn (Activity $record) => self::cambiosLegibles($record)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('causer.name')
                    ->label('Usuario')
                    ->placeholder('Sistema')
                    ->sortable(),

                TextColumn::make('log_name')
                    ->label('Módulo')
                    ->badge()
                    ->sortable(),

                TextColumn::make('event')
                    ->label('Acción')
                    ->badge()
                    ->formatStateUsing(fn (Activity $record) => self::etiquetaAccion($record))
                    ->color(fn (Activity $record) => match ($record->event) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('registro')
                    ->label('Registro')
                    ->getStateUsing(fn (Activity $record) => self::registroLegible($record)),
            ])
            ->filters([
                SelectFilter::make('causer_id')
                    ->label('Usuario')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'] ?? null,
                            fn (Builder $q, $value) => $q->where('causer_id', $value)->where('causer_type', User::class),
                        );
                    }),

                SelectFilter::make('log_name')
                    ->label('Módulo')
                    ->options([
                        'Productos' => 'Productos',
                        'Clientes' => 'Clientes',
                        'Proveedores' => 'Proveedores',
                        'Categorias' => 'Categorías',
                        'Usuarios' => 'Usuarios',
                    ]),

                SelectFilter::make('event')
                    ->label('Acción')
                    ->options([
                        'created' => 'Creó',
                        'updated' => 'Actualizó',
                        'deleted' => 'Eliminó',
                    ]),

                Filter::make('fecha')
                    ->schema([
                        DatePicker::make('desde')->label('Desde'),
                        DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'] ?? null, fn (Builder $q, $desde) => $q->whereDate('created_at', '>=', $desde))
                            ->when($data['hasta'] ?? null, fn (Builder $q, $hasta) => $q->whereDate('created_at', '<=', $hasta));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditorias::route('/'),
            'view' => Pages\ViewAuditoria::route('/{record}'),
        ];
    }

    private static function etiquetaAccion(Activity $record): string
    {
        $antes = $record->properties?->get('old', []) ?? [];
        $despues = $record->properties?->get('attributes', []) ?? [];

        return match ($record->event) {
            'created' => 'Creó',
            'deleted' => 'Eliminó',
            'updated' => match (true) {
                ($despues['activo'] ?? null) === false => 'Desactivó',
                ($despues['activo'] ?? null) === true && ($antes['activo'] ?? null) === false => 'Activó',
                default => 'Actualizó',
            },
            default => ucfirst((string) $record->event),
        };
    }

    private static function registroLegible(Activity $record): string
    {
        if ($record->subject_type) {
            return class_basename($record->subject_type).' #'.$record->subject_id;
        }

        return $record->description ?: '—';
    }

    private static function cambiosLegibles(Activity $record): HtmlString
    {
        $antes = $record->properties?->get('old', []) ?? [];
        $despues = $record->properties?->get('attributes', []) ?? [];

        $claves = collect(array_keys($antes + $despues))->unique()->sort();

        if ($claves->isEmpty()) {
            return new HtmlString('<span class="text-gray-500">Sin cambios registrados.</span>');
        }

        $filas = $claves->map(function (string $campo) use ($antes, $despues) {
            $valorAntes = e(self::formatearValor($antes[$campo] ?? null));
            $valorDespues = e(self::formatearValor($despues[$campo] ?? null));

            return "<li><strong>{$campo}:</strong> {$valorAntes} → {$valorDespues}</li>";
        })->implode('');

        return new HtmlString("<ul class=\"list-disc pl-4 space-y-1\">{$filas}</ul>");
    }

    private static function formatearValor(mixed $valor): string
    {
        return match (true) {
            is_null($valor) => '—',
            is_bool($valor) => $valor ? 'Sí' : 'No',
            default => (string) $valor,
        };
    }
}
