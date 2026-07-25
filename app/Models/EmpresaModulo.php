<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Modulo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmpresaModulo extends Model
{
    protected $table = 'empresa_modulos';

    protected $fillable = ['empresa_id', 'modulo', 'habilitado'];

    protected $casts = [
        'modulo' => Modulo::class,
        'habilitado' => 'boolean',
    ];

    protected $attributes = [
        'habilitado' => true,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
