import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { createExtensionTestHost } from '@pterodactyl/sdk/testing';
import * as api from '../api';
import AuditScreen from './AuditScreen';
import manifest from '../../../extension.json';

vi.mock('../api', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api')>()), getAudit: vi.fn() }));
vi.mock('@pterodactyl/sdk', async (importOriginal) => {
    const sdk = await importOriginal<typeof import('@pterodactyl/sdk')>();
    return { ...sdk, PanelLink: ({ children }: { children: React.ReactNode }) => <a href='/panel/server-creator'>{children}</a> };
});
afterEach(() => { cleanup(); vi.clearAllMocks(); });

test('declares a separate admin screen and renders the audit page', async () => {
    expect(manifest.ui.screens).toContainEqual({ id: 'audit', area: 'admin', path: 'server-creator-audit' });
    vi.mocked(api.getAudit).mockResolvedValue({ entries: [], next_before: null });
    const host = createExtensionTestHost({ extensionId: 'server-creator', prefix: 'srvc' });
    render(<AuditScreen />, { wrapper: host.Wrapper });
    expect(await screen.findByRole('heading', { name: 'Server Creator audit log' })).toBeTruthy();
    expect(await screen.findByText('No audit entries yet.')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Back to Server Creator' })).toBeTruthy();
    host.dispose();
});
