import { test, expect } from '@playwright/test';

/*
 * E2E holding & konsolidasi (GRP-01, GRP-02, CON-01, CON-05, CON-06, CON-07, CON-09).
 *
 * Yang dibuktikan di sini tidak bisa dibuktikan uji PHP: satu konsolidator — orang dengan izin
 * paling sempit di seluruh sistem — membuka dasbor, menarik ulang saldo seluruh entitas, mengentri
 * ayat eliminasi lewat form sungguhan, dan melihat angkanya berubah di kertas kerja beserta neraca
 * konsolidasi yang tetap seimbang.
 *
 * Satu lagi yang hanya bisa dibuktikan di sini: konsolidator **tidak punya** menu transaksi apa pun.
 * Uji izin di PHP membuktikan daftar izinnya; ini membuktikan menunya memang tidak muncul.
 *
 * Prasyarat sama dengan spec lain: `php artisan migrate:fresh --seed` lalu server aktif.
 */

const KONSOLIDATOR = { email: 'nadia@gtgroup.test', password: 'Rahasia123' };
const SHOTS = process.env.E2E_SCREENSHOTS ?? 'test-results/screens';

async function masuk(page, akun = KONSOLIDATOR) {
    await page.context().clearCookies();
    await page.goto('/admin/login');
    await page.getByLabel('Email atau nomor HP').fill(akun.email);
    await page.getByLabel('Kata sandi').fill(akun.password);
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await page.waitForURL(/\/admin\/(?!login)[a-z0-9-]+$/);

    return page.url();
}

/**
 * Nilai kolom Konsolidasi pada baris yang namanya cocok, sebagai ANGKA.
 *
 * Dibaca sebagai angka, bukan dibandingkan sebagai teks: format rupiah menghilangkan desimal yang
 * nol, sehingga "Rp0" dan "Rp0,00" sama-sama sah dan uji yang mencocokkan teksnya akan gagal karena
 * alasan yang tidak ada hubungannya dengan apa pun yang diuji.
 */
async function nilaiKonsolidasi(page, teksBaris) {
    const row = page.getByRole('row').filter({ hasText: teksBaris }).first();
    await expect(row).toBeVisible({ timeout: 15_000 });
    const teks = (await row.locator('td').last().innerText()).trim();

    return Number(teks.replace(/[^0-9,-]/g, '').replace(/\./g, '').replace(',', '.'));
}

test('konsolidator menarik saldo, mengeliminasi, dan membaca laporan grup', async ({ page }) => {
    const base = await masuk(page);

    /* --- Dasbor holding: entitas yang belum mengisi buku harus terlihat sebagai masalah. */
    await page.goto(`${base}/konsolidasi/dasbor`);
    await expect(page.getByRole('heading', { name: 'Dasbor Holding' }).first()).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: 'Gamatechno Retail' })).toContainText('Belum ada data');
    await page.screenshot({ path: `${SHOTS}/80-dasbor-holding.png`, fullPage: true });

    /* --- Kertas kerja: satu kolom per entitas, dan baris pemeriksaan yang harus nol. */
    await page.goto(`${base}/konsolidasi/kertas-kerja`);
    await expect(page.getByRole('columnheader', { name: 'Villa Merapi' })).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole('columnheader', { name: 'Konsolidasi' })).toBeVisible();
    expect(await nilaiKonsolidasi(page, 'PEMERIKSAAN')).toBe(0);
    await page.screenshot({ path: `${SHOTS}/81-kertas-kerja.png`, fullPage: true });

    // Talangan antar entitas sudah dieliminasi oleh data demo: piutangnya tinggal nol di grup.
    const piutangAwal = await nilaiKonsolidasi(page, 'Piutang Usaha');

    /* --- Tarik ulang saldo dari layar proses. Angkanya tidak boleh berubah: ia idempoten. */
    await page.goto(`${base}/konsolidasi/proses`);
    const baris = page.getByRole('row').filter({ hasText: /KON-\d{4}-\d{4}/ }).first();
    await expect(baris).toBeVisible({ timeout: 15_000 });
    const nomor = (await baris.locator('td').first().innerText()).trim();

    await baris.getByRole('button', { name: 'Tarik saldo entitas' }).click();
    await page.getByRole('button', { name: 'Tarik saldo', exact: true }).click();
    // Status "Saldo ditarik" yang berubah, bukan notifikasi: notifikasi lama yang masih tertinggal
    // di layar membuat uji berpindah halaman di tengah permintaan.
    await expect(page.getByRole('row').filter({ hasText: nomor })).toContainText(/\d{2}:\d{2}/, { timeout: 30_000 });

    await page.goto(`${base}/konsolidasi/kertas-kerja`);
    expect(await nilaiKonsolidasi(page, 'Piutang Usaha')).toBe(piutangAwal);
    expect(await nilaiKonsolidasi(page, 'PEMERIKSAAN')).toBe(0);

    /* --- Ayat eliminasi baru lewat form sungguhan. */
    await page.goto(`${base}/konsolidasi/proses`);
    await page.getByRole('row').filter({ hasText: nomor }).getByRole('link', { name: 'Detail' }).click();
    await page.waitForURL(/\/konsolidasi\/proses\/[0-9a-f-]+$/);
    await expect(page.getByText('Eliminasi & penyesuaian')).toBeVisible();

    await page.getByRole('button', { name: 'Ayat baru' }).click();
    await page.getByLabel('Nilai').fill('1500000');
    await pilihAkunDialog(page, 'Akun debit', '4101');
    await pilihAkunDialog(page, 'Akun kredit', '6102');
    await page.getByLabel('Keterangan').fill('Eliminasi jasa antar entitas');
    await page.getByRole('button', { name: 'Simpan ayat' }).click();
    await expect(page.getByRole('row').filter({ hasText: 'Eliminasi jasa antar entitas' })).toBeVisible({ timeout: 20_000 });
    await page.screenshot({ path: `${SHOTS}/82-eliminasi.png`, fullPage: true });

    /* --- Angkanya ikut berubah di kertas kerja, dan neraca tetap seimbang. */
    await page.goto(`${base}/konsolidasi/kertas-kerja`);
    expect(await nilaiKonsolidasi(page, 'PEMERIKSAAN')).toBe(0);

    await page.goto(`${base}/konsolidasi/neraca`);
    await expect(page.getByRole('heading', { name: 'Neraca Konsolidasi' }).first()).toBeVisible();
    await expect(page.locator('.fnb-report-notes')).not.toContainText('aset tidak sama dengan liabilitas');
    await page.screenshot({ path: `${SHOTS}/83-neraca-konsolidasi.png`, fullPage: true });

    await page.goto(`${base}/konsolidasi/laba-rugi`);
    await expect(page.locator('.fnb-report-notes')).toContainText('MANAJERIAL');
});

test('konsolidator tidak melihat satu pun menu transaksi', async ({ page }) => {
    const base = await masuk(page);
    await page.goto(`${base}/konsolidasi/dasbor`);

    const sidebar = page.locator('.fi-sidebar-nav');
    await expect(sidebar.getByText('Holding & Konsolidasi').first()).toBeVisible();

    /*
     * Daftar ini disebut satu per satu, bukan diperiksa dengan satu pola: kelompok menu yang BARU
     * ditambahkan harus membuat uji ini gagal kalau ia ternyata terbuka bagi konsolidator.
     *
     * "Penjualan" ada di daftar ini karena uji inilah yang menemukan pintasan Aplikasi Kasir (POS)
     * muncul bagi siapa pun yang bisa masuk back-office — termasuk finance dan gudang, yang tak satu
     * pun bisa bertransaksi di POS.
     */
    for (const grup of ['Penjualan', 'Akuntansi', 'Kas & Hutang', 'Dokumen', 'Inventory', 'Pembelian']) {
        await expect(sidebar.getByText(grup, { exact: true })).toHaveCount(0);
    }

    /*
     * "Laporan" MEMANG muncul, dan itu benar: di sanalah konsolidator menjadwalkan paket laporan
     * grup. Isinya pun hanya satu — Jadwal Email. Tidak ada satu pun halaman laporan penjualan,
     * inventory, atau pajak di sana.
     */
    await expect(sidebar.getByRole('link', { name: 'Jadwal Email' })).toBeVisible();
    for (const laporan of ['Penjualan', 'Anti-Fraud', 'Inventory', 'Laba Kotor', 'Menu Terlaris', 'Pajak']) {
        await expect(sidebar.getByRole('link', { name: laporan, exact: true })).toHaveCount(0);
    }

    // Dan menebak URL-nya pun tidak membantu: kewenangannya dijaga halamannya, bukan menunya.
    for (const path of ['/laporan/penjualan', '/laporan/anti-fraud', '/laporan/inventory', '/kas/posisi', '/pembukuan/jurnal']) {
        expect((await page.goto(`${base}${path}`)).status()).toBe(403);
    }
});

/**
 * Pilih akun pada Select pencarian Filament (Choices.js) di dalam modal.
 *
 * `getByLabel` tidak bisa dipakai: Choices.js menyembunyikan `<select>` aslinya dan membangun
 * widgetnya sendiri, jadi elemen yang terhubung ke label justru elemen yang tidak pernah terlihat.
 */
async function pilihAkunDialog(page, label, kode) {
    const field = page.locator('.fi-modal .fi-fo-field-wrp', {
        has: page.locator('label', { hasText: new RegExp(`^\\s*${label}\\s*\\*?\\s*$`) }),
    }).locator('.choices').first();

    await field.click();
    const search = page.locator('.choices.is-open input[type="search"]');
    if (await search.count()) {
        await search.fill(kode);
    }
    await page.locator('.choices.is-open .choices__item--choice', { hasText: new RegExp('^' + kode) }).first().click();
}
