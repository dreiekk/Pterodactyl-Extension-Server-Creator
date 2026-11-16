import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost } from '@pterodactyl/sdk/testing';
import * as api from '../api';
import ImportedGamesScreen from './ImportedGamesScreen';
import manifest from '../../../extension.json';

vi.mock('../api', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../api')>()),
    getImportedGames: vi.fn(),
    saveImportedGame: vi.fn(),
    enableImportedGame: vi.fn(),
    deleteImportedGame: vi.fn(),
}));
vi.mock('@pterodactyl/sdk', async (importOriginal) => {
    const sdk = await importOriginal<typeof import('@pterodactyl/sdk')>();
    return { ...sdk, PanelLink: ({ children }: { children: React.ReactNode }) => <a href='/panel/server-creator'>{children}</a> };
});

const game: api.ImportedGame = {
    id: 8, owner_id: 3, owner_name: 'alice', egg_id: 5, egg: 'Barotrauma',
    name: 'Barotrauma', description: null, image: null, steam_app_id: null, artwork: null, docker_image: null,
    enabled: true, sort: 0, memory_min: 64, memory_max: 2147483647, disk_min: 128, disk_max: 2147483647,
    cpu_min: 10, cpu_max: 2147483647, databases: 0, backups: 0, allocations: 0, ports: 2,
    port_variables: ['QUERY_PORT'], port_range: null, default_ports: [27015, 27016],
};

afterEach(() => { cleanup(); vi.clearAllMocks(); });

test('lists and filters imported profiles and can disable one', async () => {
    expect(manifest.ui.screens).toContainEqual({ id: 'imported-games', area: 'admin', path: 'server-creator-imported-games' });
    vi.mocked(api.getImportedGames).mockResolvedValue([game]);
    vi.mocked(api.enableImportedGame).mockResolvedValue([{ ...game, enabled: false }]);
    const host = createExtensionTestHost({ extensionId: 'server-creator', prefix: 'srvc' });
    render(<ImportedGamesScreen />, { wrapper: host.Wrapper });
    expect(await screen.findByText(/Owner: alice/)).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Search profiles or users'), { target: { value: 'bob' } });
    expect(screen.getByText('No imported profiles found.')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Search profiles or users'), { target: { value: 'alice' } });
    fireEvent.click(screen.getByRole('button', { name: 'Disable' }));
    await waitFor(() => expect(api.enableImportedGame).toHaveBeenCalledWith(8, false));
    expect(await screen.findByRole('button', { name: 'Enable' })).toBeTruthy();
    host.dispose();
});

test('edits private settings without changing the egg and warns before deleting', async () => {
    vi.mocked(api.getImportedGames).mockResolvedValue([game]);
    vi.mocked(api.saveImportedGame).mockResolvedValue([{ ...game, name: 'Updated' }]);
    vi.mocked(api.deleteImportedGame).mockResolvedValue([]);
    const host = createExtensionTestHost({ extensionId: 'server-creator', prefix: 'srvc' });
    render(<ImportedGamesScreen />, { wrapper: host.Wrapper });
    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));
    expect(screen.queryByLabelText('Egg')).toBeNull();
    expect(screen.queryByLabelText('Backups')).toBeNull();
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Updated' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(api.saveImportedGame).toHaveBeenCalledWith(8, expect.objectContaining({ name: 'Updated', egg_id: 5, memory_max: 2147483647 })));
    fireEvent.click(await screen.findByRole('button', { name: 'Delete' }));
    expect(screen.getByText(/Existing servers keep running but lose their link/)).toBeTruthy();
    fireEvent.click(screen.getAllByRole('button', { name: 'Delete' }).at(-1)!);
    await waitFor(() => expect(api.deleteImportedGame).toHaveBeenCalledWith(8, expect.anything()));
    host.dispose();
});
