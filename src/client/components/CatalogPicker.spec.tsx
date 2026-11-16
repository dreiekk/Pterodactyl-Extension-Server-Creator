import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost } from '@pterodactyl/sdk/testing';
import * as api from '../api';
import CatalogPicker from './CatalogPicker';

vi.mock('../api', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api')>()), getCatalog: vi.fn(), importCatalogEgg: vi.fn() }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });

test('searches and imports a catalog egg with an explicit port layout', async () => {
    vi.mocked(api.getCatalog).mockResolvedValue([{ id: 'games-barotrauma', name: 'Barotrauma', category: 'Games', description: 'Submarine game', source_url: 'https://eggs.pterodactyl.io/egg/games-barotrauma' }]);
    const game = { id: 12, name: 'Barotrauma' } as api.Game;
    vi.mocked(api.importCatalogEgg).mockResolvedValue(game);
    const onImported = vi.fn();
    const host = createExtensionTestHost({ extensionId: 'server-creator', prefix: 'srvc' });
    render(<CatalogPicker onImported={onImported} />, { wrapper: host.Wrapper });

    expect(api.getCatalog).not.toHaveBeenCalled();
    fireEvent.click(await screen.findByRole('button', { name: 'Import an egg from the catalog' }));
    fireEvent.change(screen.getByLabelText('Search egg catalog'), { target: { value: 'baro' } });
    fireEvent.click(await screen.findByRole('button', { name: /Barotrauma Games/ }));
    fireEvent.change(screen.getByLabelText('Default ports'), { target: { value: '27015,27016' } });
    expect((screen.getByRole('button', { name: 'Import Barotrauma' }) as HTMLButtonElement).disabled).toBe(true);
    fireEvent.change(screen.getByLabelText('Required ports'), { target: { value: '2' } });
    fireEvent.change(screen.getByLabelText('Secondary port variables'), { target: { value: 'QUERY_PORT' } });
    fireEvent.click(screen.getByRole('button', { name: 'Import Barotrauma' }));
    await waitFor(() => expect(api.importCatalogEgg).toHaveBeenCalledWith({ catalog_id: 'games-barotrauma', ports: 2, default_ports: [27015, 27016], port_variables: ['QUERY_PORT'] }));
    await waitFor(() => expect(onImported).toHaveBeenCalledWith(game));
    host.dispose();
});
