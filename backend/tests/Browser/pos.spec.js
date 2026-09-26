import { test, expect } from '@playwright/test';
import { klikNavigasi } from './support/spa.js';

/*
 * E2E layar kasir (POS web).
 *
 * Alur yang diuji sama dengan yang dipakai kasir sungguhan: back-office menerbitkan
 * kode pairing, perangkat dipasangkan, kasir login PIN, memesan, mengirim ke dapur,
 * membayar tunai, lalu struk dicetak. Dialog cetak Windows tidak dapat dikendalikan
 * dari browser, jadi window.print() diganti pencatat agar isi kertas bisa diperiksa.
 *
 * Prasyarat sama dengan spec lain: `php artisan migrate:fresh --seed` lalu server aktif.
 */

const OWNER = { email: 'rina@kopinusantara.test', password: 'Rahasia123' };
const KASIR = { nama: 'Andi', pin: '7351' };

async function masukBackOffice(page) {
    await page.goto('/admin/login');
    // Bila sesi sebelumnya masih hidup, Filament langsung mengarahkan ke panel.
    if (!(await page.locator('#data\\.email').count())) return;
    await page.getByLabel('Email atau nomor HP').fill(OWNER.email);
    await page.getByLabel('Kata sandi').fill(OWNER.password);
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await page.waitForURL(/\/admin\/(?!login)[a-z0-9-]+$/);
}

async function kodePairing(page) {
    await masukBackOffice(page);

    await klikNavigasi(page, page.getByRole('link', { name: 'Perangkat', exact: true }));
    await expect(page.getByRole('heading', { name: 'Perangkat' })).toBeVisible();
    await page.locator('.fi-ta-search-field input').fill('POS01');
    // Tunggu pencarian diterapkan; tanpa ini baris KDS01 ikut terpilih.
    await expect(page.getByText('Pencarian: POS01')).toBeVisible({ timeout: 10_000 });
    // Beberapa outlet memakai kode perangkat POS01; ambil baris outlet Kemang. Jumlah barisnya
    // sengaja tidak diperiksa — data contoh boleh bertambah tanpa membuat skenario ini gagal.
    const baris = page.getByRole('row')
        .filter({ hasText: 'Kopi Tepi Jalan Kemang' })
        .filter({ hasText: 'POS01' })
        .first();
    await expect(baris).toBeVisible({ timeout: 15_000 });
    await baris.locator('.fi-dropdown-trigger').last().click();
    await page.getByRole('button', { name: 'Buat Kode Pairing' }).click();

    const judul = page.locator('.fi-no-notification').getByText(/Kode pairing:/);
    await expect(judul).toBeVisible({ timeout: 15_000 });
    const teks = await judul.textContent();
    const kode = teks.replace(/.*Kode pairing:\s*/, '').replace(/\s+/g, '').trim();
    expect(kode).toMatch(/^[A-Z0-9]{8}$/);
    return kode;
}

async function catatCetakan(page) {
    await page.addInitScript(() => {
        window.__cetak = [];
        window.print = () => { window.__cetak.push(document.getElementById('printSlip').textContent); };
    });
}

async function masukKasir(page, kode) {
    await page.goto('/pos');
    await page.locator('#pairCode').fill(kode);
    await page.locator('#pairBtn').click();
    await expect(page.locator('#staffList button').first()).toBeVisible({ timeout: 15_000 });
    await page.locator('#staffList button', { hasText: KASIR.nama }).first().click();
    for (const d of KASIR.pin) await page.locator(`#pinPad button[data-k="${d}"]`).click();
    await page.locator('#pinPad button[data-k="OK"]').click();
    await page.waitForTimeout(2000);
    if (await page.locator('#scr-shift.on').count()) {
        await page.locator('#openingCash').fill('500000');
        await page.locator('#scr-shift button', { hasText: /Buka shift/i }).first().click();
    }
    await expect(page.locator('#grid .card').first()).toBeVisible({ timeout: 15_000 });
}

async function isiKeranjang(page, jumlah = 2) {
    const kartu = page.locator('#grid .card:not(.off)');
    for (let i = 0; i < jumlah; i++) {
        await kartu.nth(i).click();
        await page.waitForTimeout(400);
        const modal = page.locator('.ov.on');
        if (await modal.count()) {
            await modal.locator('button', { hasText: /Tambah|Simpan/i }).first().click();
            await page.waitForTimeout(300);
        }
    }
}

async function pilihMeja(page, nomor) {
    if (await page.locator('#tableModal.on').count()) {
        await page.locator(`#tableGrid button[data-t="${nomor}"]`).click();
        await page.waitForTimeout(300);
        return true;
    }
    return false;
}

async function otorisasiSupervisor(page) {
    await expect(page.locator('#authModal.on')).toBeVisible({ timeout: 10_000 });
    await page.waitForFunction(() => !document.getElementById('authGo').disabled, null, { timeout: 10_000 });
    const opsi = await page.locator('#authWho option').allTextContents();
    await page.selectOption('#authWho', { index: 0 });
    const pin = (opsi[0].match(/PIN (\d+)/) || [])[1];
    expect(pin, 'PIN supervisor tersedia di mode demo').toBeTruthy();
    await page.locator('#authPin').fill(pin);
    await page.locator('#authGo').click();
}

test('kasir memasangkan perangkat, menjual, dan mencetak struk', async ({ page }) => {
    const peringatan = [];
    page.on('dialog', async (d) => { peringatan.push(d.message()); await d.dismiss(); });

    const kode = await kodePairing(page);
    await catatCetakan(page);

    await page.goto('/pos');
    await page.locator('#pairCode').fill(kode);
    await page.locator('#pairBtn').click();

    // login PIN
    await expect(page.locator('#staffList button').first()).toBeVisible({ timeout: 15_000 });
    await page.locator('#staffList button', { hasText: KASIR.nama }).first().click();
    for (const d of KASIR.pin) await page.locator(`#pinPad button[data-k="${d}"]`).click();
    await page.locator('#pinPad button[data-k="OK"]').click();

    // buka shift bila belum ada yang berjalan
    await page.waitForTimeout(2000);
    if (await page.locator('#scr-shift.on').count()) {
        await page.locator('#openCash').fill('500000');
        await page.locator('#scr-shift button', { hasText: /Buka shift/i }).first().click();
    }
    await expect(page.locator('#grid .card').first()).toBeVisible({ timeout: 15_000 });

    // dua menu ke keranjang
    const kartu = page.locator('#grid .card:not(.off)');
    for (let i = 0; i < 2; i++) {
        await kartu.nth(i).click();
        await page.waitForTimeout(400);
        const modal = page.locator('.ov.on');
        if (await modal.count()) {
            await modal.locator('button', { hasText: /Tambah|Simpan/i }).first().click();
            await page.waitForTimeout(300);
        }
    }

    // makan di tempat wajib bernomor meja: tombol Ke dapur membuka pemilih meja dulu
    await page.locator('#kitchenBtn').click();
    await expect(page.locator('#tableModal.on')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#tableGrid button')).toHaveCount(18); // jumlah meja outlet Kemang
    await page.locator('#tableGrid button[data-t="7"]').click();
    await expect(page.locator('#tableText')).toHaveText(/Meja\s*7/);

    // tiket dapur ikut tercetak
    await page.locator('#kitchenBtn').click();
    await expect.poll(() => page.evaluate(() => window.__cetak.length), { timeout: 10_000 }).toBeGreaterThan(0);
    const tiket = await page.evaluate(() => window.__cetak[window.__cetak.length - 1]);
    expect(tiket).toContain('TIKET DAPUR');
    expect(tiket).toContain('MEJA 7'); // dapur harus tahu mejanya
    expect(tiket).not.toMatch(/TOTAL/); // tiket dapur tidak memuat harga

    // bayar tunai
    await page.locator('#payBtn').click();
    await expect(page.locator('#payModal.on'), `peringatan: ${peringatan.join(' | ')}`)
        .toBeVisible({ timeout: 10_000 });
    await page.locator('#payDone').click();
    await expect(page.locator('#rcptModal.on')).toBeVisible({ timeout: 20_000 });

    const struk = await page.evaluate(() => window.__cetak[window.__cetak.length - 1]);
    expect(struk).toMatch(/KMG-POS01-\d{6}-\d{4}/);
    expect(struk).toContain('TOTAL');
    expect(struk).toContain('Tunai');
    expect(struk).toContain('Meja 7');
    // kertas 80 mm: 42 kolom
    expect(Math.max(...struk.split('\n').map((b) => b.length))).toBeLessThanOrEqual(42);

    // cetak ulang pada kertas 58 mm tidak boleh melebihi 32 kolom
    await page.locator('#rcptModal [data-close]').first().click();
    await page.locator('.rail button[data-scr="atur"]').click();
    await page.locator('#paperOpt button[data-w="58"]').click();
    await page.locator('.rail button[data-scr="pesanan"]').click();
    await page.locator('#orderRows button', { hasText: 'Cetak ulang' }).first().click();

    await page.waitForTimeout(1500);
    const ulang = await page.evaluate(() => window.__cetak[window.__cetak.length - 1]);
    expect(Math.max(...ulang.split('\n').map((b) => b.length))).toBeLessThanOrEqual(32);
    // nomor struk tidak boleh terpotong di kertas sempit
    expect(ulang).toMatch(/KMG-POS01-\d{6}-\d{4}/);
});


test('kasir memakai bayar gabungan, retur berotorisasi, kas shift, dan tandai habis', async ({ page }) => {
    const pesan = [];
    page.on('dialog', async (d) => { pesan.push(d.message()); await d.dismiss(); });

    const kode = await kodePairing(page);
    await catatCetakan(page);
    await masukKasir(page, kode);

    // tamu, nomor antrean, catatan pesanan
    await page.locator('#detailBar').click();
    await page.locator('#custName').fill('Ibu Sari');
    await page.locator('#queueNo').fill('27');
    await page.locator('#orderNote').fill('pedas sedang');
    await page.locator('#detailSave').click();
    await expect(page.locator('#detailText')).toHaveText(/Ibu Sari · antrean 27 · pedas sedang/);

    await isiKeranjang(page);

    // bayar gabungan: separuh tunai, sisanya kartu debit
    await page.locator('#payBtn').click();
    await page.waitForTimeout(500);
    if (await pilihMeja(page, 5)) await page.locator('#payBtn').click();
    await expect(page.locator('#payModal.on')).toBeVisible({ timeout: 10_000 });

    const total = await page.evaluate(() => totalTagihan());
    await page.locator('#cashInput').fill(String(Math.floor(total / 2)));
    await page.locator('#paySplit').click();
    await expect(page.locator('#splitBox')).toBeVisible();
    await page.locator('#ways button[data-code="debit"]').click();
    await page.locator('#payDone').click();
    await expect(page.locator('#rcptModal.on')).toBeVisible({ timeout: 20_000 });

    const struk = await page.evaluate(() => window.__cetak[window.__cetak.length - 1]);
    expect(struk).toContain('Tamu: Ibu Sari');
    expect(struk).toContain('Antrean 27');
    expect(struk).toContain('Tunai');
    expect(struk).toContain('Kartu Debit');
    await page.locator('#rcptModal [data-close]').first().click();

    // retur seluruh struk — kasir tidak punya izin, jadi wajib PIN supervisor
    await page.locator('.rail button[data-scr="pesanan"]').click();
    await page.locator('#orderRows button', { hasText: 'Retur' }).first().click();
    await expect(page.locator('#refundModal.on')).toBeVisible({ timeout: 10_000 });
    await page.locator('#refundAll').click();
    await expect(page.locator('#refundAmount')).not.toHaveText('Rp 0');
    await page.locator('#refundReason').fill('pesanan salah');
    await page.locator('#refundGo').click();
    await otorisasiSupervisor(page);
    await expect.poll(() => pesan.join(' '), { timeout: 15_000 }).toMatch(/Retur tercatat/);

    // kas keluar tercatat pada shift berjalan
    await page.locator('.rail button[data-scr="shift"]').click();
    await page.waitForTimeout(1000);
    await page.locator('#cashOutBtn').click();
    await page.locator('#cashAmount').fill('150000');
    await page.locator('#cashReason').fill('setoran ke brankas');
    await page.locator('#cashGo').click();
    await expect(page.locator('#cashModal.on')).toHaveCount(0, { timeout: 10_000 });

    // tandai menu habis dari layar kasir
    await page.locator('.rail button[data-scr="atur"]').click();
    // Ambil menu pertama yang masih tersedia; urutannya bisa berbeda antar-jalannya uji.
    const baris = page.locator('#soldRows tr').filter({ hasText: 'Tersedia' }).first();
    await expect(baris).toBeVisible({ timeout: 10_000 });
    const namaMenu = (await baris.locator('td').first().textContent()).trim();
    await baris.getByRole('button', { name: 'Tandai habis' }).click();
    await expect(page.locator('#soldRows tr').filter({ hasText: namaMenu }).first().locator('td').nth(1))
        .toHaveText('Habis', { timeout: 10_000 });
});

async function jadikanDijualPerBerat(page, namaMenu) {
    await masukBackOffice(page);

    await klikNavigasi(page, page.getByRole('link', { name: 'Daftar Menu', exact: true }));
    await page.locator('.fi-ta-search-field input').fill(namaMenu);
    await expect(page.getByText(`Pencarian: ${namaMenu}`)).toBeVisible({ timeout: 10_000 });
    await klikNavigasi(page, page.getByRole('row').filter({ hasText: namaMenu }).first().getByRole('link', { name: 'Ubah' }));

    const saklar = page.getByRole('switch', { name: 'Dijual per berat' });
    if ((await saklar.getAttribute('aria-checked')) !== 'true') await saklar.click();
    await page.locator('#data\\.unit').fill('kg');
    await page.locator('#data\\.base_price').fill('120000');
    await page.getByRole('button', { name: /Simpan/i }).first().click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });
}

test('kasir menimbang ikan, memarkir bill, melayani tamu lain, lalu menagih', async ({ page }) => {
    // Menu tanpa jadwal jual: "Nasi Goreng Kampung" hanya dijual 10.00-21.30, sehingga skenario
    // ini dulu gagal bila rangkaian uji dijalankan malam hari.
    // Sengaja menu polos: tanpa jadwal jual (dulu "Nasi Goreng Kampung" hanya 10.00-21.30,
    // sehingga skenario ini gagal bila dijalankan malam) dan tanpa modifier, agar klik kartu
    // langsung membuka isian berat.
    const IKAN = 'Cokelat Panas'; // data demo kedai kopi; di sini diperlakukan sebagai menu timbangan
    await jadikanDijualPerBerat(page, IKAN);

    const kode = await kodePairing(page);
    await catatCetakan(page);
    await masukKasir(page, kode);

    // menu timbangan meminta berat, bukan jumlah butir
    await page.locator('#q').fill(IKAN);
    await page.waitForTimeout(600);
    await page.locator('#grid .card:not(.off)').first().click();
    await expect(page.locator('#weightModal.on')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#weightPrice')).toHaveText('Rp 120.000');
    await page.locator('#weightInput').fill('1,35');
    await expect(page.locator('#weightTotal')).toHaveText('Rp 162.000'); // 120.000 x 1,35
    await page.locator('#weightSave').click();

    await expect(page.locator('#lines .stp .wgt')).toHaveText(/1,35 kg/);
    await expect(page.locator('#sTotal')).toHaveText('Rp 178.200'); // + PB1 10%

    // parkir bill: keranjang kosong lagi, kasir bebas melayani tamu berikutnya
    await page.locator('#parkBtn').click();
    await page.locator('#parkLabel').fill('Meja 4 — Pak Budi');
    await page.locator('#parkGo').click();
    await expect.poll(() => page.locator('#lines .ln').count(), { timeout: 10_000 }).toBe(0);
    await expect(page.locator('#billBadge')).toHaveText('1');

    // tamu kedua dilayani seperti biasa
    await page.locator('#q').fill('');
    await page.waitForTimeout(500);
    await isiKeranjang(page, 1);
    await expect(page.locator('#lines .ln')).toHaveCount(1);
    await page.locator('#parkBtn').click();
    await page.locator('#parkLabel').fill('Meja 9');
    await page.locator('#parkGo').click();
    await expect(page.locator('#billBadge')).toHaveText('2', { timeout: 10_000 });

    // tamu pertama minta bill: dibuka lagi, harga dihitung ulang server, lalu dibayar
    await page.locator('.rail button[data-scr="bill"]').click();
    await expect(page.locator('#billRows tr')).toHaveCount(2);
    await page.locator('#billRows tr').filter({ hasText: 'Pak Budi' }).getByRole('button', { name: 'Buka' }).click();
    await expect(page.locator('#sTotal')).toHaveText('Rp 178.200', { timeout: 15_000 });

    await page.locator('#payBtn').click();
    await page.waitForTimeout(500);
    if (await pilihMeja(page, 4)) await page.locator('#payBtn').click();
    await expect(page.locator('#payModal.on')).toBeVisible({ timeout: 10_000 });
    await page.locator('#payDone').click();
    await expect(page.locator('#rcptModal.on')).toBeVisible({ timeout: 20_000 });

    // Cetak otomatis berjalan sesaat setelah struk tampil; tunggu kertasnya keluar dulu.
    await expect.poll(() => page.evaluate(() => window.__cetak.length), { timeout: 10_000 }).toBeGreaterThan(0);
    const struk = await page.evaluate(() => window.__cetak[window.__cetak.length - 1]);
    expect(struk).toContain('1,35 kg x 120.000');
    expect(struk).toContain('178.200');

    // bill yang sudah dibayar hilang dari daftar, bill tamu lain tetap ada
    await page.locator('#rcptModal [data-close]').first().click();
    await page.locator('.rail button[data-scr="bill"]').click();
    await expect(page.locator('#billRows tr')).toHaveCount(1);
    await expect(page.locator('#billRows')).toContainText('Meja 9');
});

/** PNG 2x2 sebagai bahan unggahan; cukup untuk membuktikan alurnya tanpa berkas contoh di repo. */
const FOTO_PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAF0lEQVQIW2P8z8Dwn4GBgYkBBv7/ZwAAIAYDAWvW1BsAAAAASUVORK5CYII=',
    'base64',
);

test('pemilik mengunggah foto menu dan kasir melihatnya di kartu menu', async ({ page }) => {
    const MENU = 'Cokelat Panas';
    await masukBackOffice(page);

    await klikNavigasi(page, page.getByRole('link', { name: 'Daftar Menu', exact: true }));
    await page.locator('.fi-ta-search-field input').fill(MENU);
    await expect(page.getByText(`Pencarian: ${MENU}`)).toBeVisible({ timeout: 10_000 });
    await klikNavigasi(page, page.getByRole('row').filter({ hasText: MENU }).first().getByRole('link', { name: 'Ubah' }));
    await expect(page.getByText('Foto menu')).toBeVisible();

    // FilePond baru dipasang saat kolomnya terlihat di layar; tunggu siap sebelum memilih berkas.
    await page.locator('.fi-fo-file-upload').first().scrollIntoViewIfNeeded();
    await expect(page.locator('.fi-fo-file-upload .filepond--root').first()).toBeVisible({ timeout: 15_000 });
    await page.locator('.fi-fo-file-upload input[type="file"]').first().setInputFiles({
        name: 'cokelat.png', mimeType: 'image/png', buffer: FOTO_PNG,
    });
    // Menyimpan sebelum unggahannya rampung membuat kolom fotonya tersimpan kosong.
    await expect(page.locator('.filepond--item').first())
        .toHaveAttribute('data-filepond-item-state', 'processing-complete', { timeout: 30_000 });
    await page.getByRole('button', { name: /Simpan/i }).first().click();
    await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });

    // Jalurnya tersimpan sebagai berkas internal, bukan URL luar.
    await page.reload();
    const src = await page.locator('.fi-fo-file-upload img, .filepond--image-preview-wrapper img').first()
        .getAttribute('src').catch(() => null);
    if (src) expect(src).toContain('/media/menu/');

    // Kasir melihat fotonya, bukan kotak inisial.
    const kode = await kodePairing(page);
    await masukKasir(page, kode);
    await page.locator('#q').fill(MENU);
    await page.waitForTimeout(600);
    const kartu = page.locator('#grid .card').first();
    await expect(kartu).toBeVisible({ timeout: 10_000 });
    const gambar = kartu.locator('.ph img');
    await expect(gambar).toHaveCount(1);
    await expect(gambar).toHaveAttribute('src', /\/media\/menu\/[a-z0-9]+\.(jpg|png)$/);
    // Gambarnya benar-benar bisa diambil perangkat, bukan tautan mati.
    const status = await page.evaluate(async (url) => (await fetch(url)).status, await gambar.getAttribute('src'));
    expect(status).toBe(200);
});
