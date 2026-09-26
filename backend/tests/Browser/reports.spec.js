import { test, expect } from '@playwright/test';
import { klikNavigasi } from './support/spa.js';
import AxeBuilder from '@axe-core/playwright';

const OWNER = { email: 'rina@gtgroup.test', password: 'Rahasia123' };
const FINANCE = { email: 'lina@gtgroup.test', password: 'Rahasia123' };
const MANAGER = { email: 'dewi@gtgroup.test', password: 'Rahasia123' };
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

test('pemilik memantau dashboard, membaca laporan, dan mengekspor', async ({ page }) => {
    await login(page, OWNER);

    // Dashboard real-time (FR-RPT-01).
    await expect(page.getByRole('heading', { name: 'Penjualan hari ini' })).toBeVisible();
    await expect(page.getByText('Penjualan bersih').first()).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Penjualan per jam' })).toBeVisible();
    await expect(page.getByRole('table', { name: 'Peringkat outlet hari ini' })).toContainText('Hamzah Coffee Prawirotaman');
    await expectAccessible(page, 'ringkasan');
    await page.screenshot({ path: `${SHOTS}/40-dashboard.png`, fullPage: true });

    // Laporan penjualan per outlet (FR-RPT-02/10).
    await klikNavigasi(page, page.getByRole('link', { name: 'Penjualan', exact: true }).first());
    await expect(page.getByRole('heading', { name: 'Laporan Penjualan', exact: true })).toBeVisible();
    await page.getByRole('combobox', { name: 'Kelompokkan' }).selectOption({ label: 'Per outlet' });
    const table = page.getByRole('table', { name: 'Laporan Penjualan — Per outlet' });
    await expect(table).toContainText('Hamzah Coffee Kaliurang');
    await expect(table).toContainText('Hamzah Coffee Prawirotaman');
    await expect(table.locator('tfoot')).toContainText('100%');
    await expectAccessible(page, 'laporan penjualan');
    await page.screenshot({ path: `${SHOTS}/41-laporan-outlet.png`, fullPage: true });

    // Ekspor Excel.
    await page.getByRole('button', { name: 'Ekspor' }).click();
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('button', { name: 'Excel (.xlsx)' }).click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/^laporan-penjualan-per-outlet-\d{8}-\d{8}\.xlsx$/);

    // Per jam untuk satu outlet.
    await page.getByRole('combobox', { name: 'Kelompokkan' }).selectOption({ label: 'Per jam' });
    await page.getByRole('combobox', { name: 'Outlet' }).selectOption({ label: 'Hamzah Coffee Prawirotaman' });
    await expect(page.getByText('Outlet: Hamzah Coffee Prawirotaman')).toBeVisible();
    await expect(page.getByRole('table', { name: 'Laporan Penjualan — Per jam' })).toContainText('08.00–08.59');

    // Anti-fraud & rincian kejadian (FR-RPT-04).
    await klikNavigasi(page, page.getByRole('link', { name: 'Anti-Fraud' }));
    await expect(page.getByRole('table', { name: 'Laporan Anti-Fraud per Pengguna' })).toContainText('Dewi Lestari');
    await expectAccessible(page, 'anti-fraud');
    await page.screenshot({ path: `${SHOTS}/42-anti-fraud.png`, fullPage: true });
    await page.getByRole('combobox', { name: 'Tampilan' }).selectOption({ label: 'Rincian kejadian' });
    await expect(page.getByRole('table', { name: 'Rincian Void, Refund, Diskon Manual & Selisih Kas' })).toContainText('Croissant gosong');

    // Menu engineering (FR-RPT-03).
    await klikNavigasi(page, page.getByRole('link', { name: 'Menu Terlaris' }));
    await expect(page.getByRole('table', { name: 'Menu Terlaris & Menu Engineering' })).toContainText('Kopi Susu Hamzah');
    await expect(page.getByRole('table', { name: 'Saran per kelompok menu' })).toBeVisible();
    await expectAccessible(page, 'menu engineering');
    await page.screenshot({ path: `${SHOTS}/43-menu-engineering.png`, fullPage: true });

    // Laba kotor (FR-RPT-07).
    await klikNavigasi(page, page.getByRole('link', { name: 'Laba Kotor' }));
    await expect(page.getByRole('table', { name: 'Laporan Laba Kotor per Outlet' })).toContainText('Hamzah Coffee Kaliurang');
    await expectAccessible(page, 'laba kotor');
    await page.screenshot({ path: `${SHOTS}/44-laba-kotor.png`, fullPage: true });
});

test('finance membaca laporan pajak dan membuat jadwal email', async ({ page }) => {
    await login(page, FINANCE);

    await klikNavigasi(page, page.getByRole('link', { name: 'Pajak & Service' }));
    const tax = page.getByRole('table', { name: 'Laporan Pajak & Service Charge' });
    await expect(tax).toContainText('PB1 10%');
    await expect(tax).toContainText('Hamzah Coffee Prawirotaman');
    await expectAccessible(page, 'pajak');
    await page.screenshot({ path: `${SHOTS}/45-pajak.png`, fullPage: true });

    await page.getByRole('button', { name: 'Ekspor' }).click();
    const [pdf] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('button', { name: 'PDF' }).click(),
    ]);
    expect(pdf.suggestedFilename()).toMatch(/^laporan-pajak-service-charge-\d{8}-\d{8}\.pdf$/);

    // Jadwal email (FR-RPT-08).
    await klikNavigasi(page, page.getByRole('link', { name: 'Jadwal Email' }));
    await klikNavigasi(page, page.getByRole('link', { name: 'Buat Jadwal' }));
    await page.getByLabel('Nama jadwal').fill('Laba kotor mingguan');
    await page.locator('.fi-fo-field-wrp', { has: page.locator('label', { hasText: /^\s*Laporan\s*\*?\s*$/ }) }).locator('.choices').first().click();
    await page.locator('.choices.is-open input[type="search"]').fill('Laba kotor');
    await page.locator('.choices.is-open .choices__item--choice', { hasText: 'Laba kotor per outlet' }).first().click();
    await page.getByRole('combobox', { name: 'Frekuensi' }).selectOption({ label: 'Mingguan (Senin–Minggu lalu)' });
    await expect(page.getByText('Dikirim setiap Senin untuk Senin–Minggu sebelumnya.')).toBeVisible();
    const recipients = page.getByRole('combobox', { name: /Email penerima/ });
    await recipients.fill('owner@gtgroup.test');
    await recipients.press('Enter');
    await expectAccessible(page, 'jadwal baru');
    await page.screenshot({ path: `${SHOTS}/46-jadwal-baru.png`, fullPage: true });
    await page.getByRole('button', { name: 'Simpan Jadwal' }).click();
    await expect(page.getByRole('heading', { name: 'Jadwal: Laba kotor mingguan' })).toBeVisible();
    await expect(page.getByText('Belum ada pengiriman.')).toBeVisible();
    await expectAccessible(page, 'rincian jadwal');
});

test('manajer outlet hanya melihat outletnya; kasir tidak melihat laporan', async ({ page }) => {
    const base = await login(page, MANAGER);
    await page.goto(`${base}/laporan/penjualan?tampilan=outlet`);
    const table = page.getByRole('table', { name: 'Laporan Penjualan — Per outlet' });
    await expect(table).toContainText('Hamzah Coffee Kaliurang');
    await expect(table).not.toContainText('Prawirotaman');
    await page.goto(`${base}/laporan/jadwal-email`);
    await expect(page.getByText('Anti-fraud mingguan Kaliurang')).toBeVisible();
    await expect(page.getByText('Pajak bulanan untuk konsultan')).toHaveCount(0);

    await page.goto('/admin/logout');
    await page.context().clearCookies();
    const cashierBase = await login(page, CASHIER);
    await expect(page.getByRole('link', { name: 'Anti-Fraud' })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Penjualan hari ini' })).toHaveCount(0);
    const res = await page.goto(`${cashierBase}/laporan/pajak`);
    expect(res.status()).toBe(403);
});
