import { expect, test } from '@playwright/test';

const challenge = {
	second_factor_required: true,
	challenge: 'c'.repeat(43),
	challenge_expires_at: new Date(Date.now() + 4 * 60_000).toISOString(),
	enrollment_required: true,
	totp_secret: 'JBSWY3DPEHPK3PXP',
	provisioning_uri: 'otpauth://totp/SymSvel-App?secret=JBSWY3DPEHPK3PXP'
};

test('real assembled App creates a local QR PNG during 2FA enrollment', async ({ page }) => {
	await page.route('**/apiopen/auth/login?*', (route) =>
		route.fulfill({ status: 202, json: challenge })
	);
	await page.goto('/login');
	await page.locator('input[type=email]').fill('user@example.test');
	await page.locator('input[type=password]').fill('password');
	await page.locator('form button[type=submit]').click();
	await expect(page).toHaveURL(/\/two-factor$/);
	await expect(page.getByRole('heading', { name: 'Activer la double authentification' })).toBeVisible();
	const qr = page.getByRole('img', { name: 'QR code de configuration de la double authentification' });
	await expect(qr).toBeVisible();
	await expect(qr).toHaveAttribute('src', /^data:image\/png;base64,/);
	await expect.poll(() => qr.evaluate((img) => (img as HTMLImageElement).naturalWidth)).toBeGreaterThan(100);
	const manual = page.locator('details');
	await expect(manual).not.toHaveAttribute('open', '');
	await manual.locator('summary').click();
	await expect(manual.locator('.totp-secret')).toHaveText(challenge.totp_secret);
});
