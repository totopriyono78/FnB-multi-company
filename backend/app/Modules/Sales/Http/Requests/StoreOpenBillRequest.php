<?php

namespace App\Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Menyimpan tagihan terbuka (FR-POS-12).
 *
 * Baris disimpan apa adanya seperti isi keranjang kasir; harga TIDAK diambil dari sini.
 * Saat tagihan dibuka kembali, layar kasir menghitung ulang lewat `POST /pos/quotes`,
 * sehingga harga yang dipakai selalu harga resmi dari server, bukan angka lama yang disimpan.
 */
class StoreOpenBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'channel_code' => ['required', 'string', 'max:30'],
            'label' => ['nullable', 'string', 'max:40'],
            'table_label' => ['nullable', 'string', 'max:30'],
            'customer_name' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:300'],
            'queue_no' => ['nullable', 'integer', 'between:1,99999'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.variant_id' => ['nullable', 'uuid'],
            'lines.*.name' => ['nullable', 'string', 'max:100'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:9999', 'decimal:0,3'],
            'lines.*.note' => ['nullable', 'string', 'max:200'],
            'lines.*.modifiers' => ['nullable', 'array', 'max:30'],
            'lines.*.modifiers.*.id' => ['required', 'uuid'],
            'lines.*.modifiers.*.qty' => ['nullable', 'integer', 'between:1,99'],
            'lines.*.bundle' => ['nullable', 'array', 'max:20'],
            'lines.*.bundle.*.group_id' => ['required', 'uuid'],
            'lines.*.bundle.*.options' => ['required', 'array', 'max:20'],
            'lines.*.bundle.*.options.*.option_id' => ['required', 'uuid'],
            'totals' => ['nullable', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['label' => 'nama/meja tagihan', 'lines' => 'isi pesanan'];
    }
}
