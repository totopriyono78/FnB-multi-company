<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GroupResource\Pages;
use App\Filament\Support\ConsolidationAccess;
use App\Modules\Consolidation\Application\GroupService;
use App\Modules\Consolidation\Domain\Models\Group;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Grup holding (GRP-01).
 *
 * Layar ini mengurus **identitas** grup — kode, nama, badan hukum. Keanggotaannya tidak diatur di
 * sini, dan itu keputusan keamanan, bukan kemalasan: memilih entitas anggota dari sebuah daftar
 * berarti layar ini harus lebih dulu MEMBACA daftar entitas lain, dan daftar itu sendiri sudah
 * merupakan kebocoran — holding satu pelanggan tidak boleh tahu nama entitas pelanggan lain.
 *
 * Karena itu keanggotaan diatur dengan menyebut kode entitas lewat perintah `konsolidasi:grup`, oleh
 * orang yang sudah memegang akses platform. Daftar anggota yang sudah tersusun tetap ditampilkan di
 * sini, karena membacanya tidak menuntut akses apa pun ke entitas di luar grup.
 */
class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Holding & Konsolidasi';

    protected static ?string $navigationLabel = 'Grup Holding';

    protected static ?string $modelLabel = 'grup';

    protected static ?string $pluralModelLabel = 'grup';

    protected static ?string $slug = 'konsolidasi/grup';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        // Entitas holding selalu boleh (lihat/ubah grupnya). Entitas tanpa grup boleh, supaya
        // grupnya bisa dibuat. Entitas yang sudah menjadi ANGGOTA grup lain tidak: di sana layar
        // ini hanya akan memperlihatkan daftar kosong dan jalan buntu.
        if (ConsolidationAccess::canView()) {
            return true;
        }

        return ConsolidationAccess::canManageGroup() && ! ConsolidationAccess::isMemberOfOtherGroup();
    }

    public static function canCreate(): bool
    {
        return ConsolidationAccess::canManageGroup()
            && ! ConsolidationAccess::isHolding()
            && ! ConsolidationAccess::isMemberOfOtherGroup();
    }

    public static function canEdit(mixed $record): bool
    {
        return ConsolidationAccess::canManageGroup();
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('code')->label('Kode grup')->required()->maxLength(40)
                ->disabled(fn (?Group $record) => $record !== null)
                ->helperText('Tidak bisa diubah setelah dibuat.'),
            TextInput::make('name')->label('Nama grup')->required()->maxLength(120),
            TextInput::make('legal_name')->label('Nama badan hukum')->maxLength(150),
            Textarea::make('notes')->label('Catatan')->maxLength(300)->rows(2)->columnSpanFull(),
            Placeholder::make('anggota')
                ->label('Entitas anggota')
                ->content(fn (?Group $record) => $record === null
                    ? 'Entitas holding ini otomatis menjadi anggota pertama setelah grup dibuat.'
                    : self::memberList($record))
                ->columnSpanFull(),
            Placeholder::make('cara_atur')
                ->label('Menambah atau mengeluarkan anggota')
                ->content(fn (?Group $record) => $record === null
                    ? '—'
                    : 'php artisan konsolidasi:grup '.self::holdingCode()
                        .' --tambah={kode-entitas}'."\n"
                        .'Keanggotaan sengaja tidak diatur dari layar: memilih entitas dari daftar '
                        .'berarti layar ini harus membaca daftar entitas lain, dan daftar itu sendiri '
                        .'sudah merupakan kebocoran.')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Kode')->fontFamily('mono'),
                TextColumn::make('name')->label('Nama'),
                TextColumn::make('legal_name')->label('Badan hukum')->placeholder('-'),
                TextColumn::make('anggota')->label('Anggota')
                    ->state(fn (Group $record) => (string) count(app(GroupService::class)->members($record)))
                    ->alignRight(),
            ])
            ->actions([EditAction::make()->label('Ubah')])
            ->emptyStateHeading('Entitas ini belum memegang grup')
            ->emptyStateDescription('Buat grup di sini bila entitas ini memang entitas holding. '
                .'Sesudah itu, tarik entitas anggotanya lewat perintah konsolidasi:grup.');
    }

    /** Kode entitas holding yang sedang aktif, untuk dicontohkan di perintah konsol. */
    private static function holdingCode(): string
    {
        $tenant = Filament::getTenant();

        return is_object($tenant) && property_exists($tenant, 'code') ? (string) $tenant->code : '{kode-entitas-holding}';
    }

    private static function memberList(Group $group): string
    {
        $members = app(GroupService::class)->members($group);
        if ($members === []) {
            return 'Belum ada anggota.';
        }

        return implode("\n", array_map(
            fn (array $member): string => '· '.$member['code'].' — '.$member['name'],
            $members,
        ));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroups::route('/'),
            'create' => Pages\CreateGroup::route('/baru'),
            'edit' => Pages\EditGroup::route('/{record}/ubah'),
        ];
    }
}
