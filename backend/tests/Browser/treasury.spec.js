import { test, expect } from '@playwright/test';

/*
 * E2E kas, hutang & piutang (Kelompok 5).
 *
 * Yang dibuktikan di sini tidak bisa dibuktikan uji PHP: **satu tagihan supplier berjalan dari
 * faktur sampai lunas di browser sungguhan, melewati dua modul** — faktur pembelian (Kelompok 5)
 * dan persetujuan SPPK (Kelompok 4) — tanpa jalur pembayaran tersendiri.
 *
 * Prasyarat sama dengan spec lain: `php artisan migrate:fresh --seed` lalu server aktif.
 */

const FINANCE = { email: 'lina@gtgroup.test', password: 'Rahasia123' };
const FINANCE2 = { email: 'farah@gtgroup.test', password: 'Rahasia123' };
const KASIR = { email: 'andi@gtgroup.test', password: 'Rahasia123' };
const SHOTS = process.env.E2E_SCREENSHOTS ?? 'test-results/screens';

/*
 * Nama dibuat unik per jalannya uji. Dokumen keuangan tidak pernah dihapus, jadi spec yang memakai
 * nama tetap akan menabrak sisa jalannya sendiri — dan kegagalannya terbaca sebagai "ada dua tombol
 * Terbitkan", bukan sebagai sisa data.
 */
const CAP = Date.now().toString().slice(-6);
const NOTA = `INV/E2E/${CAP}`;

async function masuk(page, akun) {
    await page.context().clearCookies();
    await page.goto('/admin/login');
    await page.getByLabel('Email atau nomor HP').fill(akun.email);
    await page.getByLabel('Kata sandi').fill(akun.password);
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await page.waitForURL(/\/admin\/(?!login)[a-z0-9-]+$/);

    return page.url();
}

/** Dropdown Filament diklik lewat pembungkus Choices-nya: select aslinya disembunyikan. */
async function pilih(page, fieldId, teks) {
    await pilihDi(page, page.locator(`.choices:has([id="${fieldId}"])`), teks);
}

/*
 * Varian berbasis locator, untuk dropdown di dalam repeater: id kolom repeater Filament memuat UUID
 * baris yang dibuat saat itu juga, jadi ia tidak bisa ditebak — yang stabil adalah posisinya di
 * dalam barisnya.
 */
async function pilihDi(page, pembungkus, teks) {
    await pembungkus.click();
    await page.waitForTimeout(300);
    await page.getByRole('option', { name: new RegExp(teks) }).first().click();
}

test('faktur supplier berjalan dari tagihan sampai lunas lewat SPPK', async ({ page }) => {
    const base = await masuk(page, FINANCE);

    // --- Faktur pembelian: tagihan masuk dari supplier.
    await page.goto(`${base}/hutang/faktur-pembelian/baru`);
    await pilih(page, 'data.supplier_id', '.');
    await page.getByLabel('No. faktur supplier').fill(NOTA);
    await page.getByLabel('Keterangan').first().fill('Belanja bahan baku untuk uji menyeluruh');

    const baris = page.locator('.fi-fo-repeater-item').first();
    await baris.getByLabel('Keterangan').fill('Biji kopi 20 kg');
    await pilihDi(page, baris.locator('.choices').first(), '^1301');
    /*
     * Nilai barisnya TIDAK diketik: ia dihitung layar dari jumlah × harga satuan. Mengetiknya sendiri
     * berarti berebut dengan perhitungan itu — isian yang satu menimpa yang lain tergantung mana yang
     * selesai lebih dulu, dan ujinya menjadi tidak dapat diandalkan tanpa ada yang rusak.
     */
    await baris.getByLabel('Jumlah').fill('20');
    await baris.getByLabel('Harga satuan').fill('150000');
    await baris.getByLabel('Harga satuan').blur();
    await page.waitForLoadState('networkidle');
    await expect(baris.getByLabel('Nilai')).toHaveValue(/3000000/);

    // Jumlah tagihan dihitung di layar sebelum disimpan — tanpa itu salah ketik baru ketahuan
    // setelah jurnalnya terbentuk.
    await expect(page.getByText(/= Rp3\.000\.000/)).toBeVisible({ timeout: 10_000 });
    await page.screenshot({ path: `${SHOTS}/70-faktur-pembelian.png`, fullPage: true });

    await page.getByRole('button', { name: 'Buat', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Faktur Pembelian' })).toBeVisible({ timeout: 15_000 });

    const barisFaktur = page.getByRole('row').filter({ hasText: NOTA });
    await expect(barisFaktur).toContainText('Draft');

    // --- Diterbitkan: hutang muncul, beban diakui. Kas belum bergerak sama sekali.
    await barisFaktur.getByRole('button', { name: 'Terbitkan' }).click();
    await page.getByRole('button', { name: 'Terbitkan faktur' }).click();
    await expect(page.getByRole('row').filter({ hasText: NOTA }))
        .toContainText('Belum lunas', { timeout: 15_000 });

    // Faktur yang sudah terbit tidak bisa diubah lagi.
    await expect(page.getByRole('row').filter({ hasText: NOTA })
        .getByRole('button', { name: 'Terbitkan' })).toHaveCount(0);

    /*
     * Nomor fakturnya dibaca dari layar, bukan ditebak: data demo sengaja memuat faktur lain milik
     * supplier yang sama, dan memilih "yang pertama muncul" akan melunasi tagihan yang salah —
     * persis kesalahan yang hendak dicegah seluruh kelompok ini.
     */
    const teksBaris = await page.getByRole('row').filter({ hasText: NOTA }).innerText();
    const nomorFaktur = (teksBaris.match(/FB-\d{4}-\d{4}/) ?? [])[0];
    expect(nomorFaktur, 'nomor faktur harus terbaca dari daftar').toBeTruthy();

    // --- Umur hutang memuatnya.
    await page.goto(`${base}/hutang/umur-hutang`);
    await expect(page.locator('table.fnb-report-table')).toContainText('Rp');
    await page.screenshot({ path: `${SHOTS}/71-umur-hutang.png`, fullPage: true });

    /*
     * --- Pelunasannya lewat SPPK, bukan lewat jalur tersendiri. Memilih fakturnya mengisi nilai dan
     * memindahkan akunnya ke Utang Usaha — karena bebannya sudah diakui saat faktur terbit.
     */
    await page.goto(`${base}/dokumen/pengajuan-pembayaran/baru`);
    await pilih(page, 'data.supplier_id', '.');
    await page.waitForLoadState('networkidle');
    await page.locator('.choices:has([id="data.invoice_ids"])').click();
    await page.waitForTimeout(400);
    await page.getByRole('option', { name: new RegExp(nomorFaktur) }).first().click();
    await page.locator('body').click();
    await page.waitForLoadState('networkidle');

    // Nilai dan akunnya terisi sendiri dari faktur yang dipilih.
    await expect(page.getByLabel('Nilai (Rp)')).toHaveValue(/3000000/);
    await page.getByLabel('Keperluan').fill(`Pelunasan faktur ${NOTA}`);
    await page.screenshot({ path: `${SHOTS}/72-sppk-pelunasan.png`, fullPage: true });
    await page.getByRole('button', { name: 'Buat', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Pengajuan Pembayaran (SPPK)' })).toBeVisible({ timeout: 15_000 });

    /*
     * Nomor SPPK-nya dibaca dari layar, lalu dipakai untuk SEMUA langkah berikutnya. Menyaring baris
     * lewat nilainya saja tidak cukup: data demo memuat pengajuan lain yang kebetulan menampilkan
     * angka sama di kolom "dibayar", dan menekan tombol di baris yang salah akan menyetujui dokumen
     * orang lain — persis kesalahan yang hendak dicegah seluruh kelompok ini.
     */
    const barisSppk = page.getByRole('row').filter({ hasText: 'Rp3.000.000' }).filter({ hasText: 'Draft' }).first();
    const nomorSppk = ((await barisSppk.innerText()).match(/SPPK-\d{4}-\d{4}/) ?? [])[0];
    expect(nomorSppk, 'nomor SPPK harus terbaca dari daftar').toBeTruthy();

    const sppk = page.getByRole('row').filter({ hasText: nomorSppk });
    await sppk.getByRole('button', { name: 'Ajukan' }).click();
    await page.getByRole('button', { name: 'Ajukan SPPK' }).click();
    /*
     * Ditunggu lewat STATUS BARISNYA, bukan lewat munculnya notifikasi. Notifikasi dari langkah
     * sebelumnya sering masih tertempel di layar, sehingga menunggunya terpenuhi seketika — lalu uji
     * berpindah halaman di tengah permintaan yang belum selesai, dan dokumennya tidak pernah
     * benar-benar diajukan. Kegagalannya muncul jauh kemudian, pada langkah yang tidak bersalah.
     */
    await expect(page.getByRole('row').filter({ hasText: nomorSppk }))
        .toContainText('Menunggu persetujuan', { timeout: 15_000 });

    // --- Disetujui orang kedua (nilai di bawah 5 juta: satu tanda tangan dari finance).
    await masuk(page, FINANCE2);
    await page.goto(`${base}/dokumen/pengajuan-pembayaran`);
    const menunggu = page.getByRole('row').filter({ hasText: nomorSppk });
    await menunggu.getByRole('button', { name: 'Setujui' }).click();
    await page.getByRole('button', { name: 'Setujui pengajuan' }).click();
    await expect(page.getByRole('row').filter({ hasText: nomorSppk }))
        .toContainText('Disetujui', { timeout: 15_000 });

    // --- Advis bayar: uang keluar, dan hutangnya hilang.
    const disetujui = page.getByRole('row').filter({ hasText: nomorSppk });
    await disetujui.getByRole('button', { name: 'Terbitkan advis bayar' }).click();
    await page.locator('.fi-modal .choices').first().click();
    await page.waitForTimeout(300);
    await page.getByRole('option', { name: /^1110/ }).first().click();
    await page.getByRole('button', { name: 'Simpan advis bayar' }).click();
    await expect(page.getByRole('row').filter({ hasText: nomorSppk }))
        .toContainText('Dibayar', { timeout: 15_000 });

    // --- Dan fakturnya menjadi lunas tanpa ada yang menyentuhnya langsung.
    await page.goto(`${base}/hutang/faktur-pembelian`);
    await expect(page.getByRole('row').filter({ hasText: NOTA })).toContainText('Lunas');
    await page.screenshot({ path: `${SHOTS}/73-faktur-lunas.png`, fullPage: true });
});

test('posisi kas dan rekonsiliasi terbuka untuk finance', async ({ page }) => {
    const base = await masuk(page, FINANCE);

    await page.goto(`${base}/kas/posisi`);
    await expect(page.getByRole('heading', { name: 'Posisi Kas & Bank' }).first()).toBeVisible();
    // Saldo buku dikatakan apa adanya sebagai saldo BUKU, bukan saldo rekening.
    await expect(page.locator('.fnb-report-notes')).toContainText('rekonsiliasi');
    await page.screenshot({ path: `${SHOTS}/74-posisi-kas.png`, fullPage: true });

    await page.goto(`${base}/kas/rekonsiliasi`);
    await expect(page.getByRole('heading', { name: 'Rekonsiliasi Bank' }).first()).toBeVisible();

    await page.goto(`${base}/kas/kelengkapan`);
    await expect(page.getByRole('heading', { name: 'Papan Kelengkapan Entry' }).first()).toBeVisible();
    await expect(page.locator('table.fnb-report-table')).toContainText('Jurnal diposting');
    await page.screenshot({ path: `${SHOTS}/75-papan-kelengkapan.png`, fullPage: true });
});

test('laporan arus kas dan perubahan ekuitas terbuka dan menutup sendiri', async ({ page }) => {
    const base = await masuk(page, FINANCE);

    await page.goto(`${base}/pembukuan/arus-kas`);
    await expect(page.getByRole('heading', { name: 'Laporan Arus Kas' }).first()).toBeVisible();
    const arus = page.locator('table.fnb-report-table');
    await expect(arus).toContainText('ARUS KAS DARI AKTIVITAS OPERASI');
    await expect(arus).toContainText('KAS & SETARA KAS AKHIR PERIODE');
    // Laporan yang diam-diam tidak menutup adalah laporan yang menyesatkan; kalau timpang ia bilang.
    await expect(page.locator('.fnb-report-notes')).not.toContainText('PERINGATAN');
    await page.screenshot({ path: `${SHOTS}/76-arus-kas.png`, fullPage: true });

    await page.goto(`${base}/pembukuan/perubahan-ekuitas`);
    await expect(page.getByRole('heading', { name: 'Laporan Perubahan Ekuitas' }).first()).toBeVisible();
    await expect(page.locator('table.fnb-report-table')).toContainText('EKUITAS AKHIR PERIODE');
    await expect(page.locator('.fnb-report-notes')).not.toContainText('PERINGATAN');
});

test('kasir tidak melihat menu kas & hutang', async ({ page }) => {
    const base = await masuk(page, KASIR);

    await expect(page.getByRole('link', { name: 'Faktur Pembelian' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Rekening Kas & Bank' })).toHaveCount(0);

    const respons = await page.goto(`${base}/hutang/faktur-pembelian`);
    expect(respons.status()).toBe(403);
});
