import { test, expect } from '@playwright/test';
import { klikNavigasi } from './support/spa.js';
import AxeBuilder from '@axe-core/playwright';

const OWNER = { email: 'rina@gtgroup.test', password: 'Rahasia123' };
const CASHIER = { email: 'andi@gtgroup.test', password: 'Rahasia123' };
const SHOTS = process.env.E2E_SCREENSHOTS ?? 'test-results/screens';

async function login(page, { email, password }) {
    await page.goto('/admin/login');
    await page.getByLabel('Email atau nomor HP').fill(email);
    await page.getByLabel('Kata sandi').fill(password);
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await page.waitForURL(/\/admin\/(?!login)[a-z0-9-]+$/);
    return page.url();
}

async function expectAccessible(page, name) {
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

test('pemilik meninjau transaksi, shift, dan tutup hari', async ({ page }) => {
    const base = await login(page, OWNER);

    // Dasbor menampilkan angka transaksi yang perlu ditinjau.
    await expect(page.getByText('Transaksi perlu ditinjau')).toBeVisible();

    await klikNavigasi(page, page.getByRole('link', { name: 'Transaksi', exact: true }));
    await expect(page.getByRole('heading', { name: 'Transaksi' })).toBeVisible();
    // Data demo berisi riwayat dua minggu: cari struk kasir depan Kaliurang.
    await page.locator('.fi-ta-search-field input').fill('KLU-POS01');
    await expect(page.getByRole('cell', { name: /KLU-POS01-\d{6}-0007/ })).toBeVisible();
    await expect(page.getByRole('row', { name: /KLU-POS01-\d{6}-0007/ }).getByText('Refund sebagian')).toBeVisible();
    await expectAccessible(page, 'daftar transaksi');
    await page.screenshot({ path: `${SHOTS}/20-transaksi.png`, fullPage: true });

    // Rincian transaksi yang sebagian direfund
    await klikNavigasi(page, page.getByRole('row', { name: /KLU-POS01-\d{6}-0007/ }).getByRole('link', { name: 'Detail' }));
    await expect(page.getByRole('heading', { name: /Transaksi KLU-POS01-\d{6}-0007/ })).toBeVisible();
    await expect(page.getByRole('table', { name: 'Rincian pesanan' })).toContainText('Croissant Butter');
    await expect(page.getByRole('table', { name: 'Refund' })).toContainText('Croissant gosong');
    await expect(page.getByRole('table', { name: 'Refund' })).toContainText('Dibuang (waste)');
    await expectAccessible(page, 'rincian transaksi');
    await page.screenshot({ path: `${SHOTS}/21-transaksi-detail.png`, fullPage: true });

    // Shift kemarin (ditutup, selisih) dan hari ini (terbuka)
    await klikNavigasi(page, page.getByRole('link', { name: 'Shift Kasir' }));
    await expect(page.getByText('Masih terbuka').first()).toBeVisible();
    await expect(page.getByRole('cell', { name: '-Rp2.000' })).toBeVisible();
    await expectAccessible(page, 'daftar shift');
    await klikNavigasi(page, page.getByRole('row', { name: /-Rp2\.000/ }).getByRole('link', { name: 'Detail' }));
    await expect(page.getByText('Selisih uang receh Rp2.000')).toBeVisible();
    await expect(page.getByRole('table', { name: 'Pergerakan kas' })).toContainText('Beli es batu & galon');
    await expect(page.getByRole('table', { name: 'Pembayaran per metode' })).toContainText('QRIS');
    await expectAccessible(page, 'detail shift');
    await page.screenshot({ path: `${SHOTS}/22-shift-detail.png`, fullPage: true });

    // Tutup hari: hari ini masih ada shift terbuka → tombol nonaktif
    await klikNavigasi(page, page.getByRole('link', { name: 'Tutup Hari' }));
    await expect(page.getByRole('heading', { name: 'Tutup Hari' })).toBeVisible();
    await page.getByRole('combobox', { name: 'Outlet' }).selectOption({ label: 'Hamzah Coffee Kaliurang (KMG)' });
    await expect(page.getByText(/Masih ada 1 shift terbuka/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Tutup hari' })).toBeDisabled();
    await expectAccessible(page, 'tutup hari');
    await page.screenshot({ path: `${SHOTS}/23-tutup-hari.png`, fullPage: true });

    // Metode pembayaran outlet
    await page.goto(`${base}/outlets`);
    await klikNavigasi(page, page.getByRole('row', { name: /Hamzah Coffee Kaliurang/ }).getByRole('link', { name: /Ubah/ }));
    await expect(page.getByRole('heading', { name: 'Metode Pembayaran' })).toBeVisible();
    await expect(page.getByRole('switch', { name: 'Aktifkan E-Wallet' })).toHaveAttribute('aria-checked', 'false');
    await expectAccessible(page, 'metode pembayaran outlet');
    await page.screenshot({ path: `${SHOTS}/24-metode-bayar.png`, fullPage: true });
});

test('kasir tidak melihat menu penjualan back-office', async ({ page }) => {
    const base = await login(page, CASHIER);
    await expect(page.getByRole('link', { name: 'Transaksi', exact: true })).toHaveCount(0);
    const response = await page.goto(`${base}/transaksi`);
    expect(response.status()).toBe(403);
});
