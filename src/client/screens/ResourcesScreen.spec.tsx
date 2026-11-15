import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { serverResourcesQueryOptions } from '@pterodactyl/sdk';
import { createExtensionTestHost, createTestServer, type ExtensionTestHost } from '@pterodactyl/sdk/testing';
import * as api from '../api';
import ResourcesScreen from './ResourcesScreen';

vi.mock('../api', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api')>()), getOwnedServer: vi.fn(), switchGame: vi.fn(), getAllocations: vi.fn(), saveAllocations: vi.fn(), swapAllocations: vi.fn() }));

let host: ExtensionTestHost | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.clearAllMocks();
});

const game = (id: number, name: string, changes: Partial<api.Game> = {}): api.Game => ({
    id,
    egg_id: id,
    egg: 'Egg',
    name,
    description: null,
    image: null,
    steam_app_id: null,
    artwork: null,
    docker_image: null,
    enabled: true,
    sort: 0,
    memory_min: 1024,
    memory_max: 4096,
    disk_min: 5120,
    disk_max: 20480,
    cpu_min: 100,
    cpu_max: 200,
    databases: 0,
    backups: 0,
    allocations: 0,
    ports: 1,
    port_variables: [],
    port_range: null,
    default_ports: [],
    ...changes,
});

const owned = (changes: Partial<api.OwnedServer> = {}): api.OwnedServer => ({
    owned: true,
    allow_resize: false,
    allow_switch: true,
    allow_delete: false,
    game: 'Paper',
    game_id: 1,
    artwork: null,
    current: { memory: 2048, disk: 10240, cpu: 100 },
    limits: { memory: { min: 1024, max: 4096 }, disk: { min: 5120, max: 20480 }, cpu: { min: 100, max: 200 } },
    quota: { memory: { limit: 8192, available: 6144 }, disk: { limit: 40960, available: 30720 }, cpu: { limit: 400, available: 300 } },
    remaining: { servers: 1, memory: 6144, disk: 30720, cpu: 300, databases: 0, backups: 2, allocations: 0 },
    games: [game(2, 'Valheim', { memory_min: 4096, memory_max: 8192, ports: 2, port_range: '2456-2500' }), game(3, 'Rust', { memory_min: 8192, memory_max: 16384 })],
    ...changes,
});

function renderScreen(data: api.OwnedServer, state = 'offline') {
    vi.mocked(api.getOwnedServer).mockResolvedValue(data);
    host = createExtensionTestHost({ extensionId: 'server-creator', prefix: 'srvc', server: createTestServer({ identifier: 'abcd1234', uuid: 'uuid-1' }) });
    host.queryClient.setQueryData(serverResourcesQueryOptions('uuid-1').queryKey, { attributes: { current_state: state } } as never);
    render(<ResourcesScreen />, { wrapper: host.Wrapper });
}

test('switches to a game with the sizes moved into its limits', async () => {
    renderScreen(owned());
    vi.mocked(api.switchGame).mockResolvedValue(owned({ game: 'Valheim', game_id: 2 }));

    fireEvent.click(await screen.findByRole('button', { name: 'Switch game' }));
    expect((screen.getByRole('button', { name: /Rust/ }) as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByText('Needs 8 GiB memory')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: /Valheim/ }));
    expect(screen.getByRole('slider', { name: 'Memory' }).getAttribute('aria-valuetext')).toBe('4 GiB');
    expect(screen.getByText(/moves to 2 free ports matching the game port pattern in 2456-2500/)).toBeTruthy();

    fireEvent.click(screen.getAllByRole('button', { name: 'Switch game' }).at(-1)!);
    await waitFor(() => expect(api.switchGame).toHaveBeenCalledWith('abcd1234', { game: 2, memory: 4096, disk: 10240, cpu: 100, keep_variables: true, reinstall: true }));
});

test('asks to stop a running server before switching', async () => {
    renderScreen(owned(), 'running');

    expect(((await screen.findByRole('button', { name: 'Switch game' })) as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByText('Stop the server to switch its game.')).toBeTruthy();
});

test('switches games with unlimited total quotas while respecting game maxima', async () => {
    const unlimited: api.Limits = { servers: -1, memory: -1, disk: -1, cpu: -1, databases: -1, backups: -1, allocations: -1 };
    const unlimitedQuota = { memory: { limit: -1, available: -1 }, disk: { limit: -1, available: -1 }, cpu: { limit: -1, available: -1 } };
    renderScreen(owned({ remaining: unlimited, quota: unlimitedQuota }));

    fireEvent.click(await screen.findByRole('button', { name: 'Switch game' }));
    expect((screen.getByRole('button', { name: /Rust/ }) as HTMLButtonElement).disabled).toBe(false);
    fireEvent.click(screen.getByRole('button', { name: /Rust/ }));
    expect(screen.getByRole('slider', { name: 'Memory' }).getAttribute('aria-valuetext')).toBe('8 GiB');
    expect(screen.getAllByText('Unlimited quota')).toHaveLength(3);
    expect((screen.getAllByRole('button', { name: 'Switch game' }).at(-1) as HTMLButtonElement).disabled).toBe(false);
});

test('says so for servers the user did not create here', async () => {
    renderScreen({ owned: false });

    expect(await screen.findByText('Only servers you created yourself can be changed here.')).toBeTruthy();
});

test('lets the owner replace ports and choose a primary allocation', async () => {
    const allocations: api.ServerAllocations = {
        primary: 1,
        required: 2,
        offsets: [0, 100],
        range: null,
        limit: 3,
        swap_servers: [],
        allocations: [
            { id: 1, ip: '10.0.0.1', port: 27015, assigned: true },
            { id: 2, ip: '10.0.0.1', port: 27115, assigned: true },
            { id: 3, ip: '10.0.0.1', port: 28015, assigned: false },
            { id: 4, ip: '10.0.0.1', port: 28115, assigned: false },
        ],
    };
    vi.mocked(api.getAllocations).mockResolvedValue(allocations);
    vi.mocked(api.saveAllocations).mockResolvedValue({ ...allocations, primary: 3 });
    renderScreen(owned({ can_manage_ports: true }));

    expect(await screen.findByText(/offsets 0, 100/)).toBeTruthy();
    fireEvent.click(screen.getByLabelText('10.0.0.1:27015'));
    fireEvent.click(screen.getByLabelText('10.0.0.1:27115'));
    fireEvent.click(screen.getByLabelText('10.0.0.1:28015'));
    fireEvent.click(screen.getByLabelText('10.0.0.1:28115'));
    fireEvent.click(screen.getAllByLabelText('Primary')[0]);
    fireEvent.click(screen.getByRole('button', { name: 'Save ports' }));
    await waitFor(() => expect(api.saveAllocations).toHaveBeenCalledWith('abcd1234', 3, [3, 4]));
});

test('shows port management for owners of servers created outside the extension', async () => {
    vi.mocked(api.getAllocations).mockResolvedValue({ primary: 1, required: 1, offsets: [0], range: null, limit: 1, swap_servers: [], allocations: [{ id: 1, ip: '127.0.0.1', port: 7777, assigned: true }] });
    renderScreen({ owned: false, can_manage_ports: true }, 'running');

    expect(await screen.findByLabelText('127.0.0.1:7777')).toBeTruthy();
    expect(screen.getByText('Stop the server to change its ports.')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Save ports' }) as HTMLButtonElement).disabled).toBe(true);
});

test('confirms and swaps complete port sets with another server', async () => {
    const allocations: api.ServerAllocations = {
        primary: 1, required: 2, offsets: [0, 1], range: null, limit: 2,
        allocations: [{ id: 1, ip: '10.0.0.1', port: 28015, assigned: true }, { id: 2, ip: '10.0.0.1', port: 28016, assigned: true }],
        swap_servers: [{ id: 8, identifier: 'other123', name: 'Barotrauma A', ports: ['10.0.0.1:27015', '10.0.0.1:27016'] }],
    };
    vi.mocked(api.getAllocations).mockResolvedValue(allocations);
    vi.mocked(api.swapAllocations).mockResolvedValue(allocations);
    renderScreen(owned({ can_manage_ports: true }));

    fireEvent.change(await screen.findByLabelText('Swap ports with another server'), { target: { value: '8' } });
    fireEvent.click(screen.getByRole('button', { name: 'Swap ports' }));
    expect(await screen.findByText(/This server receives: 10.0.0.1:27015, 10.0.0.1:27016/)).toBeTruthy();
    expect(screen.getByText(/Barotrauma A receives: 10.0.0.1:28015, 10.0.0.1:28016/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm swap' }));
    await waitFor(() => expect(api.swapAllocations).toHaveBeenCalledWith('abcd1234', 8));
});

test('does not allow a running server to start a port swap', async () => {
    vi.mocked(api.getAllocations).mockResolvedValue({
        primary: 1, required: 1, offsets: [0], range: null, limit: 1,
        allocations: [{ id: 1, ip: '10.0.0.1', port: 7777, assigned: true }],
        swap_servers: [{ id: 8, identifier: 'other123', name: 'Other server', ports: ['10.0.0.1:7778'] }],
    });
    renderScreen(owned({ can_manage_ports: true }), 'running');
    fireEvent.change(await screen.findByLabelText('Swap ports with another server'), { target: { value: '8' } });
    expect((screen.getByRole('button', { name: 'Swap ports' }) as HTMLButtonElement).disabled).toBe(true);
    expect(api.swapAllocations).not.toHaveBeenCalled();
});
