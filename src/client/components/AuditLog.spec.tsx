import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost } from '@pterodactyl/sdk/testing';
import * as api from '../api';
import AuditLog from './AuditLog';

vi.mock('../api', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api')>()), getAudit: vi.fn() }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });

test('shows actor and details and pages through older audit entries', async () => {
    vi.mocked(api.getAudit).mockImplementation(async (before) => before === undefined
        ? { entries: [{ id: 51, actor_id: 7, actor_name: 'alice', action: 'server.created', target: 'My server', details: { server_id: 5 }, created_at: '2026-11-16T12:00:00Z' }], next_before: 51 }
        : { entries: [{ id: 50, actor_id: 9, actor_name: 'bob', action: 'catalog.selected', target: 'Barotrauma', details: { egg_imported: false }, created_at: '2026-11-15T12:00:00Z' }], next_before: null });
    const host = createExtensionTestHost({ extensionId: 'server-creator', prefix: 'srvc' });
    render(<AuditLog />, { wrapper: host.Wrapper });
    expect(await screen.findByText('Server created: My server')).toBeTruthy();
    expect(screen.getByText('By alice (user #7)')).toBeTruthy();
    expect(screen.getByText(/"server_id": 5/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Older' }));
    expect(await screen.findByText('Catalog egg selected: Barotrauma')).toBeTruthy();
    await waitFor(() => expect(api.getAudit).toHaveBeenCalledWith(51));
    fireEvent.click(screen.getByRole('button', { name: 'Newer' }));
    expect(await screen.findByText('Server created: My server')).toBeTruthy();
    host.dispose();
});
