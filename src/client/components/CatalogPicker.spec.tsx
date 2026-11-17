import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost } from '@pterodactyl/sdk/testing';
import * as api from '../api';
import CatalogPicker from './CatalogPicker';

vi.mock('../api', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api')>()), getCatalog: vi.fn(), getCatalogPorts: vi.fn(), importCatalogEgg: vi.fn() }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });

test('searches and imports a catalog egg with an explicit port layout', async () => {
    vi.mocked(api.getCatalog).mockResolvedValue([{ id: 'games-barotrauma', name: 'Barotrauma', category: 'Games', description: 'Submarine game', source_url: 'https://eggs.pterodactyl.io/egg/games-barotrauma' }]);
    vi.mocked(api.getCatalogPorts).mockResolvedValue([27015, 27016]);
    const game = { id: 12, name: 'Barotrauma' } as api.Game;
    vi.mocked(api.importCatalogEgg).mockResolvedValue(game);
    const onImported = vi.fn();
    const host = createExtensionTestHost({ extensionId: 'server-creator', prefix: 'srvc' });
    render(<CatalogPicker onImported={onImported} />, { wrapper: host.Wrapper });

    expect(api.getCatalog).not.toHaveBeenCalled();
    fireEvent.click(await screen.findByRole('button', { name: 'Import an egg from the catalog' }));
    fireEvent.change(screen.getByLabelText('Search egg catalog'), { target: { value: 'baro' } });
    fireEvent.click(await screen.findByRole('button', { name: /Barotrauma Games/ }));
    await waitFor(() => expect((screen.getByLabelText('Default ports') as HTMLInputElement).value).toBe('27015, 27016'));
    expect((screen.getByLabelText('Required ports') as HTMLInputElement).value).toBe('2');
    fireEvent.change(screen.getByLabelText('Secondary port variables'), { target: { value: 'QUERY_PORT' } });
    fireEvent.click(screen.getByRole('button', { name: 'Import Barotrauma' }));
    await waitFor(() => expect(api.importCatalogEgg).toHaveBeenCalledWith({ catalog_id: 'games-barotrauma', ports: 2, default_ports: [27015, 27016], port_variables: ['QUERY_PORT'] }));
    await waitFor(() => expect(onImported).toHaveBeenCalledWith(game));
    host.dispose();
});

test('preserves manual input while loading and resets settings for a different egg', async () => {
    let resolvePorts: (ports: number[] | null) => void = () => {};
    vi.mocked(api.getCatalog).mockResolvedValue([
        { id: 'first', name: 'First', category: 'Games', description: '', source_url: 'https://eggs.pterodactyl.io/egg/first' },
        { id: 'second', name: 'Second', category: 'Games', description: '', source_url: 'https://eggs.pterodactyl.io/egg/second' },
    ]);
    vi.mocked(api.getCatalogPorts).mockImplementation((id) => id === 'first' ? new Promise((resolve) => { resolvePorts = resolve; }) : Promise.resolve([7777]));
    const host = createExtensionTestHost({ extensionId: 'server-creator', prefix: 'srvc' });
    render(<CatalogPicker onImported={vi.fn()} />, { wrapper: host.Wrapper });
    fireEvent.click(await screen.findByRole('button', { name: 'Import an egg from the catalog' }));
    fireEvent.click(await screen.findByRole('button', { name: /First Games/ }));
    fireEvent.change(screen.getByLabelText('Default ports'), { target: { value: '12345' } });
    resolvePorts([27015, 27016]);
    await screen.findByText(/Port suggestions come from the catalog/);
    expect((screen.getByLabelText('Default ports') as HTMLInputElement).value).toBe('12345');
    expect((screen.getByLabelText('Required ports') as HTMLInputElement).value).toBe('1');
    fireEvent.change(screen.getByLabelText('Secondary port variables'), { target: { value: 'QUERY_PORT' } });
    fireEvent.click(screen.getByRole('button', { name: /Second Games/ }));
    await waitFor(() => expect((screen.getByLabelText('Default ports') as HTMLInputElement).value).toBe('7777'));
    expect((screen.getByLabelText('Required ports') as HTMLInputElement).value).toBe('1');
    expect((screen.getByLabelText('Secondary port variables') as HTMLInputElement).value).toBe('');
    host.dispose();
});

test('allows manual entry when catalog ports are unavailable', async () => {
    vi.mocked(api.getCatalog).mockResolvedValue([{ id: 'unknown', name: 'Unknown', category: 'Games', description: '', source_url: 'https://eggs.pterodactyl.io/egg/unknown' }]);
    vi.mocked(api.getCatalogPorts).mockResolvedValue(null);
    const host = createExtensionTestHost({ extensionId: 'server-creator', prefix: 'srvc' });
    render(<CatalogPicker onImported={vi.fn()} />, { wrapper: host.Wrapper });
    fireEvent.click(await screen.findByRole('button', { name: 'Import an egg from the catalog' }));
    fireEvent.click(await screen.findByRole('button', { name: /Unknown Games/ }));
    expect(await screen.findByText(/does not list an unambiguous set of ports/)).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Default ports'), { target: { value: '1234' } });
    expect((screen.getByRole('button', { name: 'Import Unknown' }) as HTMLButtonElement).disabled).toBe(false);
    host.dispose();
});

test('reports port lookup failures without blocking manual entry', async () => {
    vi.mocked(api.getCatalog).mockResolvedValue([{ id: 'unknown', name: 'Unknown', category: 'Games', description: '', source_url: 'https://eggs.pterodactyl.io/egg/unknown' }]);
    vi.mocked(api.getCatalogPorts).mockRejectedValue(new Error('Network error'));
    const host = createExtensionTestHost({ extensionId: 'server-creator', prefix: 'srvc' });
    render(<CatalogPicker onImported={vi.fn()} />, { wrapper: host.Wrapper });
    fireEvent.click(await screen.findByRole('button', { name: 'Import an egg from the catalog' }));
    fireEvent.click(await screen.findByRole('button', { name: /Unknown Games/ }));
    expect((await screen.findByRole('alert')).textContent).toContain('Could not load catalog ports');
    fireEvent.change(screen.getByLabelText('Default ports'), { target: { value: '1234' } });
    expect((screen.getByRole('button', { name: 'Import Unknown' }) as HTMLButtonElement).disabled).toBe(false);
    host.dispose();
});
