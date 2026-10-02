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
// Pemeriksa: orang kedua yang memposting apa yang diajukan finance (ACC-05).
const PEMERIKSA = { email: 'farah@gtgroup.test', password: 'Rahasia123' };
const SHOTS = process.env.E2E_SCREENSHOTS ?? 'test-results/screens';

async function masuk(page, akun = FINANCE) {
    // Sesi sebelumnya dibersihkan: berganti orang di tengah skenario adalah inti uji maker–checker,
    // dan halaman login akan langsung dialihkan bila sesi lama masih hidup.
    await page.context().clearCookies();
    await page.goto('/admin/login');
    await page.getByLabel('Email atau nomor HP').fill(akun.email);
    await page.getByLabel('Kata sandi').fill(akun.password);
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

    // --- Diajukan oleh finance.
    await baris.getByRole('button', { name: 'Ajukan' }).click();
    await page.getByRole('button', { name: 'Ajukan jurnal' }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole('row').filter({ hasText: 'Setoran modal awal pemilik' })).toContainText('Diajukan');

    /*
     * Pemisahan tugas (ACC-05): pengajunya sendiri tidak diberi tombol Posting. Ini yang paling
     * mudah lolos dari uji — layanannya memang menolak, tetapi kalau tombolnya tetap tampil orang
     * akan menekannya berkali-kali dan mengira sistemnya rusak.
     */
    await expect(page.getByRole('row').filter({ hasText: 'Setoran modal awal pemilik' })
        .getByRole('button', { name: 'Posting' })).toHaveCount(0);

    // --- Orang kedua yang memposting. Setelah ini jurnalnya final.
    await masuk(page, PEMERIKSA);
    await page.goto(`${base}/pembukuan/jurnal`);
    const barisPemeriksa = page.getByRole('row').filter({ hasText: 'Setoran modal awal pemilik' });
    await barisPemeriksa.getByRole('button', { name: 'Posting' }).click();
    // Tombol di dalam modal, bukan tombol "Posting" milik baris tabel yang namanya mirip.
    await page.getByRole('button', { name: 'Posting jurnal' }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole('row').filter({ hasText: 'Setoran modal awal pemilik' })).toContainText('Diposting');
    await expect(page.getByRole('row').filter({ hasText: 'Setoran modal awal pemilik' })
        .getByRole('button', { name: 'Posting' })).toHaveCount(0);

    // --- Neraca saldo: angkanya sampai ke laporan, dan seimbang.
    await klikNavigasi(page, page.getByRole('link', { name: 'Neraca Saldo' }));
    await expect(page.getByRole('heading', { name: 'Neraca Saldo' }).first()).toBeVisible();
    const tabel = page.locator('table.fnb-report-table');
    await expect(tabel).toContainText('1110');
    await expect(tabel).toContainText('3101');

    /*
     * Janji terpenting sebuah neraca saldo: sisi debit dan kredit sama besar. Dibandingkan sebagai
     * NILAI, bukan dengan mencari satu angka tertentu — data demo memuat jurnal lain di periode yang
     * sama, dan uji yang mematok angka hanya akan gagal setiap kali data demo bertambah, pada hal
     * yang sama sekali bukan kesalahan.
     */
    const total = tabel.locator('tfoot tr');
    const selTotal = (await total.locator('td').allTextContents()).filter((t) => t.trim() !== '');
    expect(selTotal.length, 'baris total harus memuat pasangan debit & kredit').toBeGreaterThanOrEqual(2);
    expect(selTotal.length % 2, 'kolom angka selalu berpasangan debit–kredit').toBe(0);
    for (let i = 0; i < selTotal.length; i += 2) {
        expect(selTotal[i], `pasangan kolom ke-${i / 2 + 1} harus sama besar`).toBe(selTotal[i + 1]);
    }
    // Dan jurnal yang baru saja dibuat memang ikut terhitung.
    await expect(tabel).toContainText('Rp5.000.000');

    await expect(page.getByRole('button', { name: 'Ekspor' })).toBeVisible();
    await page.screenshot({ path: `${SHOTS}/52-neraca-saldo.png`, fullPage: true });

    // --- Laba Rugi: belum ada penjualan, jadi angkanya nol — dan itu pun harus terbaca jelas.
    await klikNavigasi(page, page.getByRole('link', { name: 'Laba Rugi' }));
    await expect(page.getByRole('heading', { name: 'Laporan Laba Rugi' }).first()).toBeVisible();
    await expect(page.getByText('Marjin Bersih')).toBeVisible();
    await expect(page.locator('table.fnb-report-table')).toContainText('LABA (RUGI) BERSIH');

    /*
     * --- Neraca. Janji yang diperiksa di sini bukan "halamannya terbuka", melainkan bahwa angka
     * yang sampai ke layar benar-benar seimbang: jumlah aset sama persis dengan jumlah liabilitas
     * dan ekuitas, dan tidak ada peringatan ketimpangan di catatan kakinya.
     */
    await klikNavigasi(page, page.getByRole('link', { name: 'Neraca', exact: true }));
    await expect(page.getByRole('heading', { name: 'Neraca', exact: true }).first()).toBeVisible();
    const neraca = page.locator('table.fnb-report-table');
    await expect(neraca).toContainText('1110 — Bank');

    const nilai = async (label) => (await neraca.locator('tr', { hasText: label }).first().locator('td').first().textContent()).trim();
    expect(await nilai('JUMLAH ASET')).toBe(await nilai('JUMLAH LIABILITAS & EKUITAS'));
    await expect(page.locator('.fnb-report-notes')).not.toContainText('PERINGATAN');
    /*
     * Modal disetor 5 juta masuk sebagai ekuitas, bukan tersangkut di aset saja. Diperiksa pada
     * BARIS AKUNNYA, bukan pada subtotal ekuitas: subtotal ikut memuat laba berjalan dari data demo,
     * jadi mematoknya ke satu angka hanya akan gagal setiap kali data demo bertambah.
     */
    await expect(neraca.locator('tr', { hasText: '3101' })).toContainText('Rp5.000.000');
    await page.screenshot({ path: `${SHOTS}/54-neraca.png`, fullPage: true });
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

test('finance mencatat pencairan settlement dan piutangnya berkurang', async ({ page }) => {
    const base = await masuk(page);

    await page.goto(`${base}/pembukuan/bagan-akun`);
    await page.getByRole('button', { name: 'Pasang template standar' }).click();
    await page.getByRole('button', { name: 'Pasang', exact: true }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });

    await page.goto(`${base}/pembukuan/pemetaan-akun`);
    await page.getByRole('button', { name: 'Isi dengan akun bawaan' }).click();
    await page.getByRole('button', { name: 'Konfirmasi' }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });

    /*
     * Layar ini menjawab pertanyaan yang sebelumnya tidak bisa dijawab sistem sama sekali:
     * berapa uang non-tunai kita yang masih ditahan penyedia pembayaran.
     */
    await page.goto(`${base}/pembukuan/settlement`);
    await expect(page.getByRole('heading', { name: 'Piutang Settlement' }).first()).toBeVisible();
    const tabel = page.locator('table.fnb-report-table');
    await expect(tabel).toContainText('Sisa piutang');
    await page.screenshot({ path: `${SHOTS}/55-piutang-settlement.png`, fullPage: true });

    await page.getByRole('button', { name: 'Catat pencairan' }).click();
    const modal = page.locator('.fi-modal-window');
    await modal.getByLabel('Metode').selectOption({ label: 'QRIS' });
    await modal.getByLabel('Piutang yang dicairkan').fill('250000');
    await modal.getByLabel('Potongan saat pencairan').fill('2500');
    await modal.getByLabel('Nomor settlement penyedia').fill('E2E-STL-1');
    await modal.getByRole('button', { name: 'Simpan pencairan' }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });

    // Tercatat, tetapi piutangnya belum berkurang sampai jurnalnya diposting — dan layar mengatakannya.
    await expect(page.locator('.fnb-report-notes')).toContainText('belum diposting');
    await expect(page.getByRole('row').filter({ hasText: 'E2E-STL-1' })).toContainText('Draft');
});
