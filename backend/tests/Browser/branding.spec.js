import { test, expect } from '@playwright/test';

/**
 * Identitas produk di peramban sungguhan: judul tab, ikon, dan baris hak cipta.
 *
 * Sengaja hanya memakai halaman yang tidak menuntut login (/admin/login dan /pos) supaya uji ini
 * tidak ikut rusak setiap kali data demo berganti nama. Yang diuji di sini adalah hal-hal yang
 * TIDAK terlihat dari uji HTTP: ikonnya benar-benar bisa diunduh peramban, dan footernya benar-benar
 * terlihat di bawah isi halaman, bukan tertimpa atau terpotong.
 */
const HAK_CIPTA = '© 2026 PT. Gamatechno Indonesia';
const SHOTS = process.env.E2E_SCREENSHOTS ?? 'test-results/screens';

test('judul tab memakai nama brand di back-office dan di layar kasir', async ({ page }) => {
    await page.goto('/admin/login');
    await expect(page).toHaveTitle(/FnB Cloud - Gamatechno\s*$/);

    await page.goto('/pos');
    await expect(page).toHaveTitle('Kasir - FnB Cloud - Gamatechno');
});

test('favicon yang ditautkan benar-benar bisa diunduh', async ({ page, request }) => {
    await page.goto('/admin/login');

    const href = await page.locator('link[rel="icon"]').first().getAttribute('href');
    expect(href, 'halaman harus menautkan ikon').toBeTruthy();

    // Penyebab favicon "hilang" yang paling sering bukan <link> yang tidak ada, melainkan alamat
    // yang 404 atau berkas 0 byte. Keduanya hanya ketahuan bila berkasnya sungguh diambil.
    const svg = await request.get(href);
    expect(svg.status()).toBe(200);
    expect(svg.headers()['content-type']).toContain('svg');
    expect((await svg.body()).length).toBeGreaterThan(300);

    // Peramban meminta /favicon.ico sendiri walau tidak ada <link>; berkas 0 byte tampil rusak.
    const ico = await request.get('/favicon.ico');
    expect(ico.status()).toBe(200);
    expect((await ico.body()).length).toBeGreaterThan(1000);
});

test('baris hak cipta terlihat di bawah isi halaman masuk', async ({ page }) => {
    await page.goto('/admin/login');

    const footer = page.getByText(HAK_CIPTA);
    await expect(footer).toBeVisible();

    const kartu = await page.locator('.fi-simple-main').boundingBox();
    const baris = await footer.boundingBox();
    expect(baris.y, 'baris hak cipta harus di BAWAH kartu masuk, bukan menimpanya')
        .toBeGreaterThan(kartu.y + kartu.height - 1);

    // Dipusatkan: sisa kiri dan kanan halaman harus sama.
    const lebar = page.viewportSize().width;
    expect(Math.abs(baris.x - (lebar - baris.x - baris.width))).toBeLessThan(2);

    await page.screenshot({ path: `${SHOTS}/40-footer-login.png`, fullPage: true });
});

test('baris hak cipta tetap utuh di layar ponsel', async ({ page }) => {
    // 390 px: lebar iPhone. Teksnya 31 karakter — kalau footernya diberi padding kiri-kanan yang
    // salah, di sinilah ia patah dua baris atau membuat halaman menggulir ke samping.
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/admin/login');

    const footer = page.getByText(HAK_CIPTA);
    await expect(footer).toBeVisible();

    const ukur = await page.evaluate(() => ({
        gulirSamping: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        tinggiBaris: parseFloat(getComputedStyle(document.querySelector('.fnb-footer__text')).lineHeight),
        tinggiKotak: document.querySelector('.fnb-footer__text').getBoundingClientRect().height,
    }));

    expect(ukur.gulirSamping, 'halaman tidak boleh menggulir ke samping').toBe(false);
    expect(ukur.tinggiKotak, 'hak cipta harus muat satu baris').toBeLessThan(ukur.tinggiBaris * 1.5);
});
