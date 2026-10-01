import { test, expect } from '@playwright/test';
import { klikNavigasi } from './support/spa.js';

/*
 * E2E siklus pengeluaran (DOC-02, 04, 05, 07, 09, 10).
 *
 * Yang dibuktikan di sini tidak bisa dibuktikan uji PHP: **satu pengajuan berjalan dari manajer
 * outlet sampai terjurnal, melewati tangan empat orang berbeda di browser sungguhan**. Uji Livewire
 * membuktikan tiap potongannya; ini membuktikan potongan-potongan itu tersambung, dan bahwa tombol
 * yang semestinya tidak ada memang tidak ada di layar orang yang salah.
 *
 * Prasyarat sama dengan spec lain: `php artisan migrate:fresh --seed` lalu server aktif.
 */

const MANAJER = { email: 'dewi@gtgroup.test', password: 'Rahasia123' };
const FINANCE = { email: 'lina@gtgroup.test', password: 'Rahasia123' };
const FINANCE2 = { email: 'farah@gtgroup.test', password: 'Rahasia123' };
const ADMIN = { email: 'bayu@gtgroup.test', password: 'Rahasia123' };
const KASIR = { email: 'andi@gtgroup.test', password: 'Rahasia123' };
const SHOTS = process.env.E2E_SCREENSHOTS ?? 'test-results/screens';

const KEPERLUAN = 'Pengadaan mesin es dan pemasangannya';
/*
 * Baris tabel dicari lewat nama penerima, bukan keperluannya: kolom keperluan tersembunyi secara
 * bawaan di daftar, dan baris yang dicari lewat teks yang tidak dirender tidak akan pernah ketemu.
 *
 * Namanya dibuat unik per jalannya uji. Dokumen tidak pernah dihapus — itu memang sifat dokumen
 * keuangan — jadi spec yang memakai nama tetap akan menabrak sisa jalannya sendiri yang sebelumnya,
 * dan kegagalannya terbaca sebagai "ada dua tombol Ajukan", bukan sebagai sisa data.
 */
const CAP = Date.now().toString().slice(-6);
const PENERIMA = `CV Dingin Abadi ${CAP}`;

async function masuk(page, akun) {
    // Sesi sebelumnya dibersihkan: berganti orang di tengah skenario adalah inti uji ini, dan
    // halaman login akan langsung dialihkan bila sesi lama masih hidup.
    await page.context().clearCookies();
    await page.goto('/admin/login');
    await page.getByLabel('Email atau nomor HP').fill(akun.email);
    await page.getByLabel('Kata sandi').fill(akun.password);
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await page.waitForURL(/\/admin\/(?!login)[a-z0-9-]+$/);

    return page.url();
}

/*
 * Pilih satu opsi pada dropdown pencarian Filament.
 *
 * Diklik lewat pembungkus Choices-nya, BUKAN lewat getByLabel: select aslinya disembunyikan Choices,
 * dan getByLabel justru menemukan select tersembunyi itu — klik yang menunggu selamanya pada elemen
 * yang memang tidak pernah terlihat.
 */
async function pilih(page, fieldId, teks) {
    await page.locator(`.choices:has([id="${fieldId}"])`).click();
    await page.waitForTimeout(300);
    await page.getByRole('option', { name: new RegExp(teks) }).first().click();
}

function baris(page, teks = PENERIMA) {
    return page.getByRole('row').filter({ hasText: teks });
}

test('satu pengajuan berjalan dari outlet sampai terjurnal', async ({ page }) => {
    // --- Manajer outlet mengajukan. Nilainya Rp20 juta: dua tanda tangan.
    const base = await masuk(page, MANAJER);
    await page.goto(`${base}/dokumen/pengajuan-pembayaran/baru`);
    await page.getByLabel('Nama penerima').fill(PENERIMA);
    const nilai = page.getByLabel('Nilai (Rp)');
    await nilai.fill('20000000');
    // Isian nilai dilepas fokusnya secara tegas: penunjuk kewenangan diperbarui saat blur, dan klik
    // ke dropdown Choices berikutnya tidak selalu melepas fokus isian angka.
    await nilai.blur();
    await page.waitForLoadState('networkidle');
    await pilih(page, 'data.expense_account_id', '^6108');
    await page.getByLabel('Keperluan').fill(KEPERLUAN);

    /*
     * Kewenangan yang dituntut harus terbaca SEBELUM diajukan. Tanpa itu pengaju baru tahu
     * pengajuannya butuh tanda tangan kedua setelah menunggu dua hari — dan itu biasanya berakhir
     * dengan pengajuan dipecah-pecah supaya masuk band yang lebih rendah.
     */
    await expect(page.getByText(/2 tanda tangan/)).toBeVisible({ timeout: 10_000 });
    await page.screenshot({ path: `${SHOTS}/60-sppk-form.png`, fullPage: true });

    await page.getByRole('button', { name: 'Buat', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Pengajuan Pembayaran (SPPK)' })).toBeVisible({ timeout: 15_000 });
    await expect(baris(page)).toContainText('Draft');

    await baris(page).getByRole('button', { name: 'Ajukan' }).click();
    await page.getByRole('button', { name: 'Ajukan SPPK' }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });
    await expect(baris(page)).toContainText('Menunggu persetujuan');

    // Pengajunya sendiri tidak diberi tombol Setujui — pemisahan tugas, bukan sekadar tata letak.
    await expect(baris(page).getByRole('button', { name: 'Setujui' })).toHaveCount(0);
    // Dan ia tidak boleh bisa mencairkan: mengajukan dan mengeluarkan uang adalah dua pekerjaan.
    await expect(baris(page).getByRole('button', { name: 'Terbitkan advis bayar' })).toHaveCount(0);

    // --- Admin entitas (tingkat 2) belum boleh menandatangani sebelum tingkat 1.
    await masuk(page, ADMIN);
    await page.goto(`${base}/dokumen/pengajuan-pembayaran`);
    await expect(baris(page).getByRole('button', { name: 'Setujui' })).toHaveCount(0);

    // --- Tingkat 1: finance. Antrian verifikasinya menyebut dokumen ini lebih dulu.
    await masuk(page, FINANCE);
    await page.goto(`${base}/dokumen/antrian-verifikasi`);
    await expect(page.getByRole('heading', { name: 'Menunggu tanda tangan Anda' })).toBeVisible({ timeout: 15_000 });
    // Bagian pertama antrian adalah "menunggu tanda tangan Anda"; bagian lain boleh berisi apa pun.
    await expect(page.locator('.fnb-queue').first()).toContainText(PENERIMA);
    await page.screenshot({ path: `${SHOTS}/61-antrian-verifikasi.png`, fullPage: true });

    await klikNavigasi(page, page.getByRole('link', { name: 'Pengajuan Pembayaran (SPPK)' }));
    await baris(page).getByRole('button', { name: 'Setujui' }).click();
    await page.getByRole('button', { name: 'Setujui pengajuan' }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });
    // Masih menunggu: satu tanda tangan dari dua.
    await expect(baris(page)).toContainText('Menunggu persetujuan');
    await expect(baris(page)).toContainText('1 / 2');
    // Orang yang sama tidak boleh mengisi tingkat kedua.
    await expect(baris(page).getByRole('button', { name: 'Setujui' })).toHaveCount(0);

    // --- Tingkat 2: admin entitas. Baru di sini disetujui lengkap.
    await masuk(page, ADMIN);
    await page.goto(`${base}/dokumen/pengajuan-pembayaran`);
    await baris(page).getByRole('button', { name: 'Setujui' }).click();
    await page.getByRole('button', { name: 'Setujui pengajuan' }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });
    await expect(baris(page)).toContainText('Disetujui');
    await expect(baris(page)).toContainText('2 / 2');

    // --- Pencairan oleh finance, lengkap dengan jurnalnya.
    await masuk(page, FINANCE);
    await page.goto(`${base}/dokumen/pengajuan-pembayaran`);
    await baris(page).getByRole('button', { name: 'Terbitkan advis bayar' }).click();
    // Sisa pengajuan sudah terisi sebagai nilai bawaan — pembayaran kedua lebih sering salah ketik.
    await expect(page.getByLabel('Nilai dibayar (Rp)')).toHaveValue('20000000.00');
    // Di dalam modal, dropdown-nya dicari lewat modalnya: id kolom pada form aksi tabel Filament
    // memuat indeks aksi yang sedang terbuka, dan itu bukan hal yang pantas dihafal sebuah uji.
    await page.locator('.fi-modal .choices').first().click();
    await page.waitForTimeout(300);
    await page.getByRole('option', { name: /^1110/ }).first().click();
    await page.getByLabel('No. referensi / bukti transfer').fill(`TRF-${CAP}`);
    await page.getByRole('button', { name: 'Simpan advis bayar' }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });
    await expect(baris(page)).toContainText('Dibayar');
    await page.screenshot({ path: `${SHOTS}/62-sppk-dibayar.png`, fullPage: true });

    // --- Jejaknya terbaca di layar detail: tanda tangan siapa, advis mana, jurnal mana.
    await baris(page).getByRole('link', { name: 'Detail' }).click();
    await expect(page.getByText('Jejak persetujuan')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('body')).toContainText('Tingkat 1');
    await expect(page.locator('body')).toContainText('Tingkat 2');
    await page.screenshot({ path: `${SHOTS}/63-sppk-detail.png`, fullPage: true });

    /*
     * --- Advis bayar: uangnya sudah keluar, tetapi jurnalnya masih draft. Itu keadaan yang
     * disengaja, dan layar advis harus mengatakannya — "sudah dibayar" dan "sudah terbukukan" dua
     * hal berbeda, dan yang kedua tetap melewati maker–checker seperti jurnal lain.
     */
    await page.goto(`${base}/dokumen/advis-bayar`);
    const advis = page.getByRole('row').filter({ hasText: `TRF-${CAP}` });
    await expect(advis).toContainText('Rp20.000.000');
    await expect(advis).toContainText('Draft');

    await page.goto(`${base}/dokumen/antrian-verifikasi`);
    await expect(page.getByRole('heading', { name: 'Advis bayar belum terbukukan' })).toBeVisible({ timeout: 15_000 });

    // --- Jurnal pembayarannya diajukan finance dan diposting orang lain.
    await page.goto(`${base}/pembukuan/jurnal`);
    const jurnal = page.getByRole('row').filter({ hasText: PENERIMA });
    await expect(jurnal).toContainText('Draft');
    await jurnal.getByRole('button', { name: 'Ajukan' }).click();
    await page.getByRole('button', { name: 'Ajukan jurnal' }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });

    await masuk(page, FINANCE2);
    await page.goto(`${base}/pembukuan/jurnal`);
    const jurnal2 = page.getByRole('row').filter({ hasText: PENERIMA });
    await jurnal2.getByRole('button', { name: 'Posting' }).click();
    await page.getByRole('button', { name: 'Posting jurnal' }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole('row').filter({ hasText: PENERIMA })).toContainText('Diposting');

    // Setelah terposting, ia keluar dari antrian "belum terbukukan" — itulah gunanya antrian itu ada.
    await page.goto(`${base}/dokumen/antrian-verifikasi`);
    await expect(page.locator('.fnb-queue li').filter({ hasText: PENERIMA })).toHaveCount(0);
});

test('penolakan membuang tanda tangan dan mengembalikan pengajuan ke pengaju', async ({ page }) => {
    const KEPERLUAN2 = 'Sewa genset cadangan untuk acara';
    const PENERIMA2 = `Genset Sewa Jaya ${CAP}`;
    const base = await masuk(page, MANAJER);

    await page.goto(`${base}/dokumen/pengajuan-pembayaran/baru`);
    await page.getByLabel('Nama penerima').fill(PENERIMA2);
    await page.getByLabel('Nilai (Rp)').fill('2500000');
    await page.getByLabel('Nilai (Rp)').blur();
    await pilih(page, 'data.expense_account_id', '^6108');
    await page.getByLabel('Keperluan').fill(KEPERLUAN2);
    await page.getByRole('button', { name: 'Buat', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Pengajuan Pembayaran (SPPK)' })).toBeVisible({ timeout: 15_000 });

    await baris(page, PENERIMA2).getByRole('button', { name: 'Ajukan' }).click();
    await page.getByRole('button', { name: 'Ajukan SPPK' }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });

    // Diajukan berarti terkunci: pengajunya tidak bisa mengubah angkanya di tengah pemeriksaan.
    await expect(baris(page, PENERIMA2).getByRole('link', { name: 'Ubah' })).toHaveCount(0);

    await masuk(page, FINANCE);
    await page.goto(`${base}/dokumen/pengajuan-pembayaran`);
    await baris(page, PENERIMA2).getByRole('button', { name: 'Tolak' }).click();
    await page.getByLabel('Alasan').fill('Lampirkan dua penawaran pembanding lebih dulu.');
    await page.getByRole('button', { name: 'Tolak pengajuan' }).click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });

    // Kembali ke draft, dan pengajunya bisa memperbaikinya lagi — bukan jalan buntu.
    await masuk(page, MANAJER);
    await page.goto(`${base}/dokumen/pengajuan-pembayaran`);
    await expect(baris(page, PENERIMA2)).toContainText('Draft');
    await expect(baris(page, PENERIMA2).getByRole('link', { name: 'Ubah' })).toHaveCount(1);
});

test('kasir tidak melihat menu dokumen pembayaran', async ({ page }) => {
    const base = await masuk(page, KASIR);

    await expect(page.getByRole('link', { name: 'Pengajuan Pembayaran (SPPK)' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Antrian Verifikasi' })).toHaveCount(0);

    // Bukan hanya menunya yang disembunyikan: alamatnya pun ditolak.
    const respons = await page.goto(`${base}/dokumen/pengajuan-pembayaran`);
    expect(respons.status()).toBe(403);
});
