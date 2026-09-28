import { test, expect } from '@playwright/test';

/*
 * Geometri struk cetak.
 *
 * Struk disusun dengan lebar kolom tetap (42 kolom di kertas 80 mm, 32 di 58 mm) dan
 * kerapiannya bergantung pada seluruh kolom itu muat dalam satu baris. Pernah tidak:
 * ukuran huruf 11pt membuat baris 42 karakter memerlukan ±103 mm di kertas 72 mm, sehingga
 * kolom harga terpotong di printer termal — tidak terlihat saat mencetak ke A4 karena
 * halamannya lebar. Uji ini mengukurnya, bukan menilai dari tampilan.
 */

for (const [lebarKertas, cssLebar] of [[80, '72mm'], [58, '50mm']]) {
    test(`ukur slip ${lebarKertas}mm`, async ({ page }) => {
        await page.goto('/pos');
        const hasil = await page.evaluate((w) => {
            savePrefs({ w });
            applyPaper();
            const P = slip();
            // Baris terpanjang yang mungkin: garis pemisah selebar kolom penuh.
            const teks = [P.rule.trimEnd(), P.row('TOTAL', '1.200').trimEnd(), P.mid('UJI CETAK').trimEnd()].join('\n');
            const el = document.getElementById('printSlip');
            el.innerHTML = '';
            const s = document.createElement('span');
            s.textContent = teks;
            el.appendChild(s);
            return { kolom: P.W, panjangBaris: P.rule.trimEnd().length };
        }, lebarKertas);

        await page.emulateMedia({ media: 'print' });
        const ukur = await page.evaluate(() => {
            const el = document.getElementById('printSlip');
            const r = el.getBoundingClientRect();
            const sr = el.querySelector('span').getBoundingClientRect();
            const mm = (px) => +(px / 96 * 25.4).toFixed(1);
            const gaya = getComputedStyle(el);
            const padKiri = parseFloat(gaya.paddingLeft), padKanan = parseFloat(gaya.paddingRight);
            return {
                slipKiri: mm(r.left), slipLebar: mm(r.width),
                areaTeks: mm(el.clientWidth - padKiri - padKanan), teksLebar: mm(sr.width),
                huruf: gaya.fontSize,
            };
        });
        console.log(`UKUR ${lebarKertas}mm`, JSON.stringify({ ...hasil, ...ukur }));

        // Teks harus muat di dalam area aman, bukan cuma di dalam kertas.
        expect(ukur.teksLebar, 'baris terpanjang harus muat di area cetak').toBeLessThanOrEqual(ukur.areaTeks + 0.2);
        expect(ukur.slipKiri, 'slip tidak boleh menempel tepi kiri kertas lebar').toBeGreaterThan(1);
    });
}

test('pratinjau struk di layar berada di tengah, barisnya tetap rata kiri', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/pos');
    await page.evaluate(() => {
        savePrefs({ w: 80 });
        applyPaper();
        const P = slip();
        document.getElementById('rcpt').textContent =
            [P.rule, P.row('TOTAL', '28.200'), P.row('Tunai', '50.000')].join('');
        document.getElementById('rcptModal').classList.add('on');
    });

    const ukur = await page.evaluate(() => {
        const el = document.getElementById('rcpt');
        const wadah = el.parentElement;
        const r = el.getBoundingClientRect();
        const w = wadah.getBoundingClientRect();
        return {
            kiri: r.left - w.left,
            kanan: w.right - r.right,
            menyusutKeIsi: r.width < w.width - 20,
            perataanTeks: getComputedStyle(el).textAlign,
        };
    });

    // Kotaknya menyusut seukuran isi lalu dipusatkan — jarak kiri dan kanan sama.
    expect(ukur.menyusutKeIsi, 'kotak struk harus seukuran isinya, bukan selebar modal').toBe(true);
    expect(Math.abs(ukur.kiri - ukur.kanan), 'kotak struk harus di tengah').toBeLessThan(2);
    // Yang dipusatkan kotaknya, bukan teksnya: baris struk wajib tetap rata kiri
    // supaya kolom harga yang rata kanan tidak berantakan.
    expect(ukur.perataanTeks).not.toBe('center');
});

/*
 * Geometri kode QR di slip cetak (jalan mundur untuk outlet tanpa layar pelanggan).
 *
 * Yang menentukan QR cetak bisa dipindai bukan tampilannya di layar, melainkan berapa TITIK
 * printer yang dipakai satu modul. Kepala cetak termal hanya menghitamkan titik utuh, jadi modul
 * selebar 4,8 titik keluar sebagai campuran 4 dan 5 titik — kode yang rapi di layar tetapi gagal
 * dipindai di kertas. Uji ini mengukur angkanya, bukan menilai dari gambar.
 *
 * viewBox di bawah disintesis (73 dan 97 modul) supaya kedua cabang keputusan teruji tanpa
 * bergantung pada panjang payload gateway: 73 modul = payload QRIS ±280 karakter seperti contoh
 * AINO, 97 modul = payload jauh lebih panjang.
 */
const qrSvg = (modul) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${modul} ${modul}">`
    + `<rect width="${modul}" height="${modul}" fill="#fff"/></svg>`;

test('lebar cetak QR dipatok ke jumlah titik printer yang bulat', async ({ page }) => {
    await page.goto('/pos');

    const hasil = await page.evaluate(([kecil, besar]) => {
        const ukur = (w, markup) => { savePrefs({ w }); return ukuranQrMm(svgDari(markup)); };

        return {
            mm80: ukur(80, kecil),
            mm58: ukur(58, kecil),
            besar80: ukur(80, besar),
            besar58: ukur(58, besar),
        };
    }, [qrSvg(73), qrSvg(97)]);

    // Kertas 80 mm: isi 66 mm = 528 titik; 528/73 = 7 titik per modul -> 511 titik = 63,875 mm.
    expect(hasil.mm80).toBeCloseTo(63.875, 3);
    // Kertas 58 mm: isi 44 mm = 352 titik; 352/73 = 4 titik per modul -> 292 titik = 36,5 mm.
    // Masih di atas batas minimum, dan tetap di atas ukuran cetak QRIS yang lazim (2,5 cm).
    expect(hasil.mm58).toBeCloseTo(36.5, 3);
    // Payload panjang di 80 mm: 528/97 = 5 titik per modul -> 485 titik = 60,625 mm.
    expect(hasil.besar80).toBeCloseTo(60.625, 3);
    // Payload panjang di 58 mm: hanya 3 titik per modul. Ditolak, bukan dicetak samar-samar —
    // kertas yang keluar dengan QR tak terbaca lebih buruk daripada penolakan yang jelas.
    expect(hasil.besar58).toBeNull();
});

test('slip QRIS memuat gambar QR, nominal, batas waktu, dan peringatan bukan bukti bayar', async ({ page }) => {
    await page.goto('/pos');

    const hasil = await page.evaluate((svg) => {
        window.print = () => {
            const area = document.getElementById('printSlip');
            const box = area.querySelector('.slipQr');
            window.__slipQr = {
                teks: area.textContent,
                adaSvg: !!(box && box.querySelector('svg')),
                lebar: box ? box.style.width : '',
            };
        };

        savePrefs({ w: 80 });
        S.device = { outlet: { name: 'Hamzah Coffee Kaliurang' } };
        S.intent = {
            id: 'intent-uji', status: 'pending', amount: '78500',
            qr_svg: svg, qr_payable: true, provider_reference: 'REF-123',
            expires_at: new Date(Date.now() + 5 * 60 * 1000).toISOString(),
        };
        qrisTampilkan(S.intent);
        document.getElementById('qrisPrint').onclick();

        return window.__slipQr;
    }, qrSvg(73));

    expect(hasil.adaSvg, 'QR harus ikut ke kertas sebagai gambar').toBe(true);
    expect(hasil.lebar).toBe('63.88mm');
    expect(hasil.teks).toContain('PEMBAYARAN QRIS');
    expect(hasil.teks).toContain('78.500');
    expect(hasil.teks).toContain('Berlaku sampai');
    // Slip QR gampang disalahpahami sebagai struk. Peringatan ini wajib ada di kertas.
    expect(hasil.teks).toContain('BUKAN bukti pembayaran');
    expect(hasil.teks).toContain('Ref: REF-123');
});

test('tidak mencetak QR simulasi dan menolak QR yang terlalu rapat', async ({ page }) => {
    await page.goto('/pos');

    const hasil = await page.evaluate(([kecil, besar]) => {
        let cetakan = 0;
        window.print = () => { cetakan++; };
        savePrefs({ w: 58 });
        S.device = { outlet: { name: 'Uji' } };

        // 1. QR simulasi: tombolnya tidak boleh tersedia sama sekali — kertas tidak dibuang
        //    untuk kode yang pasti ditolak aplikasi bank.
        S.intent = { id: 'a', status: 'pending', amount: '1000', qr_svg: kecil, qr_payable: false };
        qrisTampilkan(S.intent);
        const simulasi = {
            tersembunyi: document.getElementById('qrisPrint').hidden,
            nonaktif: document.getElementById('qrisPrint').disabled,
        };

        // 2. Payload terlalu panjang untuk kertas 58 mm: ditolak dengan penjelasan.
        S.intent = { id: 'b', status: 'pending', amount: '1000', qr_svg: besar, qr_payable: true };
        qrisTampilkan(S.intent);
        document.getElementById('qrisPrint').onclick();

        return { simulasi, cetakan, pesan: document.getElementById('payErr').textContent };
    }, [qrSvg(73), qrSvg(97)]);

    expect(hasil.simulasi.tersembunyi).toBe(true);
    expect(hasil.simulasi.nonaktif).toBe(true);
    expect(hasil.cetakan, 'tidak ada kertas yang keluar').toBe(0);
    expect(hasil.pesan).toMatch(/terlalu rapat|80 mm/);
});
