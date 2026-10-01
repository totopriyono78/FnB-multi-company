<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentAdviceResource\Pages;
use App\Filament\Support\MenuFields;
use App\Filament\Support\PaymentAccess;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Documents\Domain\Models\PaymentAdvice;
use App\Modules\Shared\Domain\Models\DocumentAttachment;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Advis bayar (DOC-05) — daftar pembayaran yang sudah diinstruksikan.
 *
 * Sengaja hanya bisa dilihat, tidak bisa diketik di sini. Advis bayar selalu lahir dari SPPK yang
 * sudah disetujui; membolehkannya dibuat sendiri berarti membuka jalan memindahkan uang tanpa
 * pengajuan dan tanpa tanda tangan — tepat lubang yang hendak ditutup oleh seluruh Kelompok ini.
 * Koreksinya pun bukan dengan mengubah advis, melainkan dengan jurnal balik atas jurnalnya.
 */
class PaymentAdviceResource extends Resource
{
    protected static ?string $model = PaymentAdvice::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Dokumen';

    protected static ?string $modelLabel = 'advis bayar';

    protected static ?string $pluralModelLabel = 'Advis Bayar';

    protected static ?string $slug = 'dokumen/advis-bayar';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return PaymentAccess::canView();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['request:id,number,payee_name', 'bankAccount:id,code,name', 'journal:id,number,status', 'creator:id,name']))
            ->columns([
                TextColumn::make('number')->label('No. advis')->fontFamily('mono')->searchable(),
                TextColumn::make('paid_on')->label('Tanggal bayar')->date('d M Y')->sortable(),
                TextColumn::make('request.number')->label('Atas SPPK')->fontFamily('mono')->searchable()
                    ->description(fn (PaymentAdvice $record) => $record->request?->payee_name),
                TextColumn::make('amount')->label('Nilai')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('bankAccount.code')->label('Dibayar dari')
                    ->formatStateUsing(fn ($state, PaymentAdvice $record) => $record->bankAccount?->label() ?? $state),
                TextColumn::make('reference')->label('Referensi')->placeholder('-')->searchable(),
                /*
                 * Status jurnalnya ikut di daftar. "Uangnya sudah keluar" dan "sudah terbukukan"
                 * adalah dua hal berbeda, dan jurnal pembayaran yang tertinggal sebagai draft adalah
                 * selisih kas yang baru ditemukan saat rekonsiliasi bank — jauh terlambat.
                 */
                TextColumn::make('journal.status')->label('Jurnal')->badge()->placeholder('-')
                    ->formatStateUsing(fn (?string $state) => Journal::STATUS_LABEL[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        Journal::POSTED => 'success',
                        Journal::SUBMITTED => 'info',
                        Journal::DRAFT => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (PaymentAdvice $record) => $record->journal?->number),
                TextColumn::make('creator.name')->label('Diterbitkan oleh')->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('paid_on', 'desc')
            ->filters([
                Filter::make('periode')
                    ->form([
                        DatePicker::make('from')->label('Dari tanggal'),
                        DatePicker::make('until')->label('Sampai tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->where('paid_on', '>=', $v))
                        ->when($data['until'] ?? null, fn ($q, $v) => $q->where('paid_on', '<=', $v))),
                Filter::make('jurnal_draft')->label('Jurnalnya belum diposting')
                    ->query(fn (Builder $query) => $query->whereHas('journal',
                        fn (Builder $q) => $q->where('status', '!=', Journal::POSTED))),
            ])
            ->actions([ViewAction::make()->label('Detail')])
            ->emptyStateHeading('Belum ada advis bayar')
            ->emptyStateDescription('Advis bayar diterbitkan dari pengajuan pembayaran yang sudah disetujui.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('No. advis')->fontFamily('mono'),
                TextEntry::make('paid_on')->label('Tanggal bayar')->date('d M Y'),
                TextEntry::make('amount')->label('Nilai')
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('reference')->label('Referensi')->placeholder('-'),
                TextEntry::make('request.number')->label('Atas SPPK')->fontFamily('mono')
                    ->url(fn (PaymentAdvice $record) => $record->payment_request_id === null ? null
                        : PaymentRequestResource::getUrl('view', ['record' => $record->payment_request_id])),
                TextEntry::make('request.payee_name')->label('Penerima'),
                TextEntry::make('bankAccount.code')->label('Dibayar dari')
                    ->formatStateUsing(fn ($state, PaymentAdvice $record) => $record->bankAccount?->label() ?? $state),
                TextEntry::make('journal.number')->label('Jurnal')->placeholder('-')
                    ->url(fn (PaymentAdvice $record) => $record->journal_id === null ? null
                        : JournalResource::getUrl('view', ['record' => $record->journal_id])),
                TextEntry::make('creator.name')->label('Diterbitkan oleh')->placeholder('-'),
                TextEntry::make('note')->label('Catatan')->placeholder('-')->columnSpan(3),
            ]),
            ViewEntry::make('attachments')->view('filament.documents.attachments')
                ->viewData(['ownerType' => DocumentAttachment::PAYMENT_ADVICE])->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentAdvices::route('/'),
            'view' => Pages\ViewPaymentAdvice::route('/{record}'),
        ];
    }
}
