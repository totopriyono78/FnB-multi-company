@php
    use App\Filament\Support\MenuFields;
    use App\Filament\Support\TreasuryAccess;
    use App\Modules\Reporting\Application\ReportTable;

    $statement = $this->statement();
    $lines = $this->lines();
    $bookLines = $this->bookLines();
    $summary = $this->summary();
    $bisaUbah = $statement !== null && ! $statement->isLocked() && TreasuryAccess::canReconcile();
@endphp

<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @if ($statement === null)
        <x-filament::section>
            <p>Belum ada rekening koran yang diimpor.</p>
            <p class="fnb-muted">
                Impor mutasi rekening untuk membandingkan catatan kita dengan catatan bank. Yang dicari bukan
                kecocokan, melainkan yang <em>tidak</em> cocok: biaya yang belum dibukukan, transfer yang belum
                kliring, dan pengeluaran yang tidak pernah diajukan siapa pun.
            </p>
        </x-filament::section>
    @else
        {{-- Ringkasan lebih dulu: angka selisih inilah yang dicari orang saat membuka layar ini. --}}
        <x-filament::section :heading="$summary->title" :description="$summary->subtitle">
            <table class="fnb-receipt fnb-report-table" aria-label="{{ $summary->title }}">
                <tbody>
                    @foreach ($summary->rows as $row)
                        @php $style = $row['_style'] ?? null; @endphp
                        <tr @class(['fnb-report-row--'.$style => $style !== null])>
                            <th scope="row" class="fnb-report-table__label">{{ $row['name'] }}</th>
                            <td @class(['fnb-num' => true, 'fnb-negative' => str_starts_with((string) $row['amount'], '-')])>
                                {{ ReportTable::format($row['amount'], ReportTable::MONEY) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($bisaUbah)
                <div class="fnb-recon__balance">
                    <label for="saldo-koran">Saldo akhir menurut rekening koran</label>
                    <input id="saldo-koran" type="number" step="0.01" wire:model="closingBalance" class="fi-input">
                    <x-filament::button wire:click="saveClosingBalance" size="sm" color="gray">Simpan</x-filament::button>
                </div>
            @endif

            <ul class="fnb-report-notes">
                @foreach ($summary->notes as $note)
                    <li>{{ $note }}</li>
                @endforeach
                @if ($statement->isLocked())
                    <li><strong>Terkunci</strong> {{ $statement->locked_at->timezone(config('app.display_timezone'))->translatedFormat('d M Y H.i') }}
                        oleh {{ $statement->locker?->name ?? '-' }}. Pencocokannya tidak dapat diubah lagi.</li>
                @endif
            </ul>
        </x-filament::section>

        <x-filament::section heading="Mutasi rekening koran"
            :description="$lines->whereNull('matched_journal_line_id')->where('is_ignored', false)->count().' baris belum dicocokkan'">
            <div class="fnb-table-scroll" tabindex="0" role="region" aria-label="Mutasi rekening koran (dapat digulir)">
                <table class="fnb-receipt fnb-report-table" aria-label="Mutasi rekening koran">
                    <thead>
                        <tr>
                            <th scope="col">Tanggal</th>
                            <th scope="col">Keterangan</th>
                            <th scope="col" class="fnb-num">Keluar</th>
                            <th scope="col" class="fnb-num">Masuk</th>
                            <th scope="col">Pasangan di buku</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $line)
                            <tr @class(['fnb-recon__row--open' => ! $line->isMatched() && ! $line->is_ignored])>
                                <th scope="row">{{ $line->value_date->translatedFormat('d M Y') }}</th>
                                <td>
                                    {{ $line->description }}
                                    @if ($line->reference)
                                        <span class="fnb-muted">· {{ $line->reference }}</span>
                                    @endif
                                </td>
                                <td class="fnb-num">{{ (float) $line->debit > 0 ? MenuFields::rupiah((string) $line->debit) : '' }}</td>
                                <td class="fnb-num">{{ (float) $line->credit > 0 ? MenuFields::rupiah((string) $line->credit) : '' }}</td>
                                <td>
                                    @if ($line->isMatched())
                                        <span>{{ $line->journalLine?->journal?->number }}</span>
                                        <span class="fnb-muted">({{ $line->match_mode === 'auto' ? 'otomatis' : 'manual' }})</span>
                                        @if ($bisaUbah)
                                            <x-filament::button wire:click="unmatchLine('{{ $line->id }}')"
                                                size="xs" color="gray">Batalkan</x-filament::button>
                                        @endif
                                    @elseif ($line->is_ignored)
                                        <span class="fnb-muted">Tidak dicocokkan — {{ $line->ignore_reason }}</span>
                                    @elseif ($bisaUbah)
                                        @php $kandidat = $this->candidateOptions($line->id); @endphp
                                        @if ($kandidat === [])
                                            {{-- Tidak ada pasangan sama sekali: inilah temuan yang paling berharga
                                                 dari rekonsiliasi, jadi ia dikatakan terang-terangan. --}}
                                            <span class="fnb-muted">Tidak ada di buku</span>
                                        @else
                                            <select class="fi-input fnb-recon__pick"
                                                wire:change="matchLine('{{ $line->id }}', $event.target.value)">
                                                <option value="">Pilih pasangan ({{ count($kandidat) }})…</option>
                                                @foreach ($kandidat as $id => $label)
                                                    <option value="{{ $id }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                        <x-filament::button size="xs" color="warning"
                                            wire:click="ignoreLine('{{ $line->id }}', prompt('Alasan baris ini tidak perlu dicocokkan:') ?? '')">
                                            Abaikan
                                        </x-filament::button>
                                    @else
                                        <span class="fnb-muted">Belum dicocokkan</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- Arah sebaliknya, dan sama pentingnya: yang ada di buku tetapi tidak ada di bank. --}}
        <x-filament::section heading="Ada di buku, belum ada di rekening koran" collapsible
            :collapsed="$bookLines->isEmpty()"
            description="Cek yang belum dicairkan, transfer yang belum kliring — atau pembayaran yang tercatat dua kali.">
            @if ($bookLines->isEmpty())
                <p class="fnb-muted">Seluruh mutasi buku pada rekening ini sudah ada pasangannya di rekening koran.</p>
            @else
                <ul class="fnb-attachments">
                    @foreach ($bookLines as $jl)
                        <li>
                            <strong>{{ $jl->journal->number }}</strong>
                            — {{ MenuFields::rupiah((float) $jl->debit > 0 ? (string) $jl->debit : (string) $jl->credit) }}
                            <span class="fnb-muted">
                                {{ (float) $jl->debit > 0 ? 'masuk' : 'keluar' }}
                                · {{ $jl->journal->journal_date->translatedFormat('d M Y') }}
                                · {{ $jl->journal->description }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
