import { test, expect } from '@playwright/test';
import { klikNavigasi } from './support/spa.js';
import AxeBuilder from '@axe-core/playwright';

const OWNER = { email: 'rina@gtgroup.test', password: 'Rahasia123' };
const MANAGER = { email: 'dewi@gtgroup.test', password: 'Rahasia123' };
const SHOTS = process.env.E2E_SCREENSHOTS ?? 'test-results/screens';

async function login(page, { email, password }) {
    await page.goto('/admin/login');
    await page.getByLabel('Email atau nomor HP').fill(email);
    await page.getByLabel('Kata sandi').fill(password);
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await page.waitForURL(/\/admin\/(?!login)[a-z0-9-]+$/);
}

async function expectAccessible(page, name) {
    // Tunggu request Livewire & transisi selesai agar warna yang diperiksa adalah warna akhir.
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();
    const serious = results.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
    if (serious.length) {
        console.log('AXE', name, JSON.stringify(serious.map((v) => ({ id: v.id, nodes: v.nodes.slice(0, 3).map((n) => n.html.slice(0, 200)) }))));
    }
    expect(serious, `pelanggaran WCAG serius di ${name}`).toEqual([]);
}

test('login gagal menampilkan pesan yang jelas', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByLabel('Email atau nomor HP').fill(OWNER.email);
    await page.getByLabel('Kata sandi').fill('salah-sekali');
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await expect(page.getByText('Email/nomor HP atau password salah.')).toBeVisible();
    await expectAccessible(page, 'login');
    await page.screenshot({ path: `${SHOTS}/01-login.png` });
});

test('akun demo bisa dipilih dengan satu klik', async ({ page }) => {
    await page.goto('/admin/login');
    const demo = page.getByRole('region', { name: 'Akun demo' });
    await expect(demo).toBeVisible();
    await expect(demo.getByText('Rahasia123')).toBeVisible();
    await expectAccessible(page, 'login dengan akun demo');
    // Kartu akun tidak boleh melebar melewati form.
    const form = await page.locator('form#form').boundingBox();
    for (const box of await demo.getByRole('button').evaluateAll((els) => els.map((e) => e.getBoundingClientRect().right))) {
        expect(box).toBeLessThanOrEqual(form.x + form.width + 1);
    }
    await page.screenshot({ path: `${SHOTS}/00-login-demo.png`, fullPage: true });

    await demo.getByRole('button', { name: /Masuk sebagai Dewi Lestari/ }).click();
    await page.waitForURL(/\/admin\/(?!login)[a-z0-9-]+$/);
    await expect(page.getByRole('img', { name: 'Avatar Dewi Lestari' })).toBeVisible();
    // Ringkasan manajer Kaliurang hanya menghitung outletnya sendiri.
    await expect(page.locator('.fi-wi-stats-overview-stat').filter({ hasText: 'Outlet aktif' })).toContainText('1');
});

test('pemilik mengelola brand, outlet, perangkat, dan staf', async ({ page }) => {
    await login(page, OWNER);

    // Ringkasan
    await expect(page.getByText('Perangkat offline')).toBeVisible();
    await expectAccessible(page, 'ringkasan');
    await page.screenshot({ path: `${SHOTS}/02-ringkasan.png`, fullPage: true });

    // Brand baru
    await klikNavigasi(page, page.getByRole('link', { name: 'Brand' }).first());
    await expect(page.getByRole('cell', { name: 'Hamzah Coffee', exact: true })).toBeVisible();
    await klikNavigasi(page, page.getByRole('link', { name: 'Tambah Brand' }));
    const brandCode = `MBJ${Date.now() % 100000}`;
    await page.getByLabel('Kode').fill(brandCode.toLowerCase());
    await page.getByLabel('Nama brand').fill('Mie Bangka Jaya');
    await page.getByRole('button', { name: 'Simpan Brand' }).click();
    // Simpan lalu dialihkan lewat navigasi SPA; di mesin sibuk bisa lebih dari 5 dtk.
    await expect(page).toHaveURL(/\/brands\/.+\/edit$/, { timeout: 15_000 });
    await klikNavigasi(page, page.getByRole('link', { name: 'Brand' }).first());
    await expect(page.getByRole('cell', { name: brandCode, exact: true })).toBeVisible();
    await expectAccessible(page, 'daftar brand');
    await page.screenshot({ path: `${SHOTS}/03-brand.png`, fullPage: true });

    // Outlet
    await klikNavigasi(page, page.getByRole('link', { name: 'Outlet' }).first());
    await expect(page.getByRole('cell', { name: 'Hamzah Coffee Kaliurang' })).toBeVisible();
    await expectAccessible(page, 'daftar outlet');
    await page.screenshot({ path: `${SHOTS}/04-outlet.png`, fullPage: true });
    await page.getByRole('cell', { name: 'Hamzah Coffee Kaliurang' }).click();
    await page.getByRole('tab', { name: 'Pajak & Harga' }).click();
    await expect(page.getByRole('spinbutton', { name: /Tarif \(%\)/ })).toHaveValue('10.00');
    await expectAccessible(page, 'form outlet');
    await page.screenshot({ path: `${SHOTS}/05-outlet-pajak.png`, fullPage: true });

    // Perangkat + kode pairing
    await klikNavigasi(page, page.getByRole('link', { name: 'Perangkat' }).first());
    await expect(page.getByRole('columnheader', { name: 'Koneksi' })).toBeVisible();
    await expectAccessible(page, 'daftar perangkat');
    await page.screenshot({ path: `${SHOTS}/06-perangkat.png`, fullPage: true });
    await klikNavigasi(page, page.getByRole('link', { name: 'Daftarkan Perangkat' }));
    await page.getByLabel('Outlet').selectOption({ label: 'Hamzah Coffee Prawirotaman' });
    await page.getByLabel('Kode perangkat').fill(`t${Date.now() % 100000}`);
    await page.getByRole('textbox', { name: /Nama/ }).fill('Kasir teras');
    await page.getByRole('button', { name: 'Daftarkan & Buat Kode Pairing' }).click();
    await expect(page.getByText(/Kode pairing: [A-Z0-9]{4} [A-Z0-9]{4}/)).toBeVisible();
    await page.screenshot({ path: `${SHOTS}/07-kode-pairing.png` });

    // Staf
    await klikNavigasi(page, page.getByRole('link', { name: 'Staf' }).first());
    await expect(page.getByRole('cell', { name: 'Andi Saputra' })).toBeVisible();
    await expectAccessible(page, 'daftar staf');
    await page.screenshot({ path: `${SHOTS}/08-staf.png`, fullPage: true });

    // Audit log
    await klikNavigasi(page, page.getByRole('link', { name: 'Audit Log' }).first());
    await expect(page.getByRole('cell', { name: 'device.created' }).first()).toBeVisible();
    await expectAccessible(page, 'audit log');
    await page.screenshot({ path: `${SHOTS}/09-audit.png`, fullPage: true });
});

test('manajer outlet hanya melihat outlet & brand miliknya (lihat saja)', async ({ page }) => {
    await login(page, MANAGER);
    await expect(page.getByRole('img', { name: 'Avatar Dewi Lestari' })).toBeVisible();

    // SRS §12.1: Manajer Outlet melihat brand & outlet (👁️), tidak mengelola.
    await klikNavigasi(page, page.getByRole('link', { name: 'Brand', exact: true }));
    await expect(page.getByRole('cell', { name: 'Hamzah Coffee', exact: true })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Roti Bakar 88', exact: true })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Tambah Brand' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Daftar Menu' })).toBeVisible();
    await klikNavigasi(page, page.getByRole('link', { name: 'Outlet' }).first());
    await expect(page.getByRole('cell', { name: 'Hamzah Coffee Kaliurang' })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Hamzah Coffee Prawirotaman' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Tambah Outlet' })).toHaveCount(0);

    await klikNavigasi(page, page.getByRole('link', { name: 'Perangkat' }).first());
    await expect(page.getByRole('cell', { name: 'Kasir depan' })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Kasir utama' })).toHaveCount(0);

    await klikNavigasi(page, page.getByRole('link', { name: 'Staf' }).first());
    await expect(page.getByRole('cell', { name: 'Andi Saputra' })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Putri Maharani' })).toHaveCount(0);

    // Form tambah staf hanya menawarkan role & outlet yang boleh diberikan manajer.
    await klikNavigasi(page, page.getByRole('link', { name: 'Tambah Staf' }));
    await expect(page.getByRole('checkbox', { name: 'Kasir', exact: true })).toBeVisible();
    await expect(page.getByRole('checkbox', { name: 'Admin Company' })).toHaveCount(0);
    await expect(page.getByRole('checkbox', { name: 'Hamzah Coffee Prawirotaman' })).toHaveCount(0);
    await expectAccessible(page, 'form staf (manajer)');
    await page.screenshot({ path: `${SHOTS}/11-form-staf-manajer.png`, fullPage: true });
});

test('mode gelap tetap terbaca', async ({ browser }) => {
    const context = await browser.newContext({ colorScheme: 'dark', locale: 'id-ID' });
    const page = await context.newPage();
    await login(page, OWNER);
    await klikNavigasi(page, page.getByRole('link', { name: 'Perangkat' }).first());
    await expectAccessible(page, 'perangkat (gelap)');
    await page.screenshot({ path: `${SHOTS}/10-perangkat-gelap.png`, fullPage: true });
    await context.close();
});
