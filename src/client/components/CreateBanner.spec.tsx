import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { createExtensionTestHost } from '@pterodactyl/sdk/testing';
import * as api from '../api';
import CreateBanner from './CreateBanner';

vi.mock('../api', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api')>()), getOverview: vi.fn() }));
afterEach(cleanup);

test('shows the create action with an unlimited server quota', async () => {
    const unlimited: api.Limits = { servers: -1, memory: -1, disk: -1, cpu: -1, databases: -1, backups: -1, allocations: -1 };
    vi.mocked(api.getOverview).mockResolvedValue({ quota: { limits: unlimited, used: { ...unlimited, servers: 2 }, remaining: unlimited }, games: [{} as api.Game], servers: [] });
    const host = createExtensionTestHost({ extensionId: 'server-creator', prefix: 'srvc' });
    render(<CreateBanner />, { wrapper: host.Wrapper });
    expect(await screen.findByRole('button', { name: 'Create server' })).toBeTruthy();
    expect(screen.getByText(/unlimited servers/)).toBeTruthy();
    host.dispose();
});
