import { test, expect } from '@playwright/test';
import { klikNavigasi } from './support/spa.js';

/*
 * E2E modul akuntansi (ACC-01, 05, 08).
 *
 * Yang diuji di sini adalah satu putaran penuh yang tidak bisa dibuktikan uji PHP: pasang bagan
 * akun, tulis jurnal lewat form sungguhan, posting, lalu angkanya muncul seimbang di neraca saldo.
 * Uji Livewire membuktikan tiap potongannya; ini membuktikan potongan-potongan itu tersambung.
 *
 * Prasyarat sama dengan spec lain: `php artisan migrate:fresh --seed` lalu server aktif.
 */

const FINANCE = { email: 'lina@gtgroup.test', password: 'Rahasia123' };
const SHOTS = process.env.E2E_SCREENSHOTS ?? 'test-results/screens';

async function masuk(page) {
    await page.goto('/admin/login');
    await page.getByLabel('Email atau nomor HP').fill(FINANCE.email);
    await page.getByLabel('Kata sandi').fill(FINANCE.password);
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await page.waitForURL(/\/admin\/(?!login)[a-z0-9-]+$/);

    return page.url();
}

/** Pilih akun pada baris repeater ke-i lewat dropdown pencarian Filament. */
async function pilihAkun(page, baris, kode) {
    await page.locator('.fi-fo-repeater-item').nth(baris).locator('[role="combobox"], select').first().click();
    await page.waitForTimeout(300);
    await page.getByRole('option', { name: new RegExp('^' + kode) }).first().click();
}

test('finance memasang bagan akun, menjurnal, memposting, dan membaca neraca saldo', async ({ page }) => {
    const base = await masuk(page);

    // --- Bagan akun: dipasang dari template, dan aman dijalankan ulang.
    await page.goto(`${base}/pembukuan/bagan-akun`);
    await page.getByRole('button', { name: 'Pasang template standar' }).click();
    await page.getByRole('button', { name: 'Pasang', exact: true }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });
    await page.locator('.fi-ta-search-field input').fill('1110');
    // Baris dicari lewat KODE akun: nama akun dirender bersama nama induknya sebagai deskripsi,
    // sehingga 'nama yang dapat diakses' selnya bukan sekadar 'Bank'.
    await expect(page.getByRole('row').filter({ hasText: '1110' })).toBeVisible({ timeout: 10_000 });
    await page.screenshot({ path: `${SHOTS}/50-bagan-akun.png`, fullPage: true });

    // --- Jurnal: ditulis lewat form sungguhan, bukan disuntik ke basis data.
    await page.goto(`${base}/pembukuan/jurnal/baru`);
    await page.getByLabel('Keterangan').fill('Setoran modal awal pemilik');
    await pilihAkun(page, 0, '1110');
    await pilihAkun(page, 1, '3101');
    await page.getByLabel('Debit').nth(0).fill('5000000');
    await page.getByLabel('Kredit').nth(1).fill('5000000');
    await page.locator('body').click();

    /*
     * Penunjuk keseimbangan harus hidup sambil mengetik. Tanpa itu kasir pembukuan baru tahu
     * jurnalnya timpang setelah menekan Simpan, dan harus mencari sendiri baris mana yang salah.
     */
    await expect(page.getByText(/Seimbang — debit dan kredit sama-sama Rp5\.000\.000/)).toBeVisible({ timeout: 10_000 });
    await page.screenshot({ path: `${SHOTS}/51-jurnal-form.png`, fullPage: true });

    await page.getByRole('button', { name: 'Buat', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Jurnal Umum' })).toBeVisible({ timeout: 15_000 });
    const baris = page.getByRole('row').filter({ hasText: 'Setoran modal awal pemilik' });
    await expect(baris).toContainText('Draft');

    // --- Posting: setelah ini jurnalnya final.
    await baris.getByRole('button', { name: 'Posting' }).click();
    // Tombol di dalam modal, bukan tombol "Posting" milik baris tabel yang namanya mirip.
    await page.getByRole('button', { name: 'Posting jurnal' }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole('row').filter({ hasText: 'Setoran modal awal pemilik' })).toContainText('Diposting');
    // Jurnal yang sudah diposting tidak menawarkan Posting lagi — hanya jurnal balik.
    await expect(page.getByRole('row').filter({ hasText: 'Setoran modal awal pemilik' })
        .getByRole('button', { name: 'Posting' })).toHaveCount(0);

    // --- Neraca saldo: angkanya sampai ke laporan, dan seimbang.
    await klikNavigasi(page, page.getByRole('link', { name: 'Neraca Saldo' }));
    await expect(page.getByRole('heading', { name: 'Neraca Saldo' }).first()).toBeVisible();
    const tabel = page.locator('table.fnb-report-table');
    await expect(tabel).toContainText('1110');
    await expect(tabel).toContainText('3101');

    const total = tabel.locator('tfoot tr');
    await expect(total).toContainText('Rp5.000.000');
    // Janji terpenting sebuah neraca saldo: sisi debit dan kredit sama besar.
    const angka = (await total.textContent()).match(/Rp5\.000\.000/g) ?? [];
    expect(angka.length, 'debit dan kredit harus sama-sama muncul di baris total').toBeGreaterThanOrEqual(4);

    await expect(page.getByRole('button', { name: 'Ekspor' })).toBeVisible();
    await page.screenshot({ path: `${SHOTS}/52-neraca-saldo.png`, fullPage: true });
});

test('kasir tidak melihat menu akuntansi', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByLabel('Email atau nomor HP').fill('andi@gtgroup.test');
    await page.getByLabel('Kata sandi').fill('Rahasia123');
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await page.waitForURL(/\/admin\/(?!login)[a-z0-9-]+$/);
    const base = page.url();

    await expect(page.getByRole('link', { name: 'Bagan Akun' })).toHaveCount(0);
    expect((await page.goto(`${base}/pembukuan/jurnal`)).status()).toBe(403);
});

test('finance melengkapi pemetaan akun jurnal otomatis', async ({ page }) => {
    const base = await masuk(page);

    await page.goto(`${base}/pembukuan/bagan-akun`);
    await page.getByRole('button', { name: 'Pasang template standar' }).click();
    await page.getByRole('button', { name: 'Pasang', exact: true }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });

    await page.goto(`${base}/pembukuan/pemetaan-akun`);
    /*
     * Sebelum dilengkapi, layar harus MENGATAKAN bahwa jurnal otomatis belum jalan. Pemetaan yang
     * diam-diam kosong adalah cara paling mudah kehilangan sebulan pembukuan tanpa ada yang sadar.
     */
    const peringatan = page.locator('.fnb-callout--danger');
    await expect(peringatan).toContainText('belum dapat disusun');

    await page.getByRole('button', { name: 'Isi dengan akun bawaan' }).click();
    await page.getByRole('button', { name: 'Konfirmasi' }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });

    await page.reload();
    await expect(page.locator('.fnb-callout--danger')).toHaveCount(0);
    // Akun pajak terisi 2201 — satu-satunya slot yang boleh menunjuk ke sana.
    await expect(page.getByLabel('Pajak keluaran (PB1)')).not.toHaveValue('');
    await expect(page.locator('.choices__list--single').filter({ hasText: '2201 — PB1' })).toHaveCount(1);

    /*
     * Slot pembayaran diperiksa terpisah karena ia berada di bawah lipatan layar: isian Filament
     * baru dimuat saat terlihat, sehingga "kosong" pada tangkapan layar penuh bisa berarti belum
     * dimuat — bukan belum terpetakan. Yang dipastikan di sini adalah nilainya benar-benar sampai.
     */
    const tunai = page.getByLabel('Pembayaran tunai');
    await tunai.scrollIntoViewIfNeeded();
    await expect(tunai).not.toHaveValue('');
    await expect(page.locator('.choices__list--single').filter({ hasText: '1101 — Kas di Laci Kasir' })).toHaveCount(1);
    await page.screenshot({ path: `${SHOTS}/53-pemetaan-akun.png`, fullPage: true });
});
