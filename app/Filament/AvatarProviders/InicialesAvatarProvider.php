<?php

declare(strict_types=1);

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Avatar por defecto (usuarios y empresas sin foto/logo): las iniciales en un SVG embebido.
 *
 * Reemplaza al proveedor por defecto de Filament, que pide la imagen a ui-avatars.com: sin internet
 * el avatar salía roto, y con internet se enviaban a un tercero los nombres de usuarios y empresas.
 */
class InicialesAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $iniciales = str(Filament::getNameForDefaultAvatar($record))
            ->trim()
            ->explode(' ')
            ->filter(fn (string $palabra): bool => filled($palabra))
            ->take(2)
            ->map(fn (string $palabra): string => mb_strtoupper(mb_substr($palabra, 0, 1)))
            ->join('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#1e293b"/>'
            .'<text x="32" y="33" fill="#ffffff" font-family="Inter, system-ui, sans-serif" font-size="26" font-weight="600" text-anchor="middle" dominant-baseline="central">'
            .e($iniciales !== '' ? $iniciales : '?')
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
