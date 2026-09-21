<?php

namespace App\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Avatar inisial yang dibuat lokal (tanpa layanan pihak ketiga), sehingga
 * back-office tetap rapi saat internet outlet lambat dan tidak mengirim nama ke luar.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = (string) Filament::getNameForDefaultAvatar($record);
        $initials = Str::of($name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="%s"/>'
            .'<text x="50%%" y="50%%" dy=".35em" text-anchor="middle" font-family="sans-serif" font-size="26" font-weight="600" fill="%s">%s</text></svg>',
            DesignTokens::PRIMARY_SOFT,
            DesignTokens::PRIMARY_STRONG,
            e($initials !== '' ? $initials : '?'),
        );

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
