import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { createExtensionTestHost, type ExtensionTestHost } from '@pterodactyl/sdk/testing';
import GameDialog from './GameDialog';

let host: ExtensionTestHost | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
});

test('accepts multiple non-consecutive default ports in primary-first order', async () => {
    host = createExtensionTestHost({ extensionId: 'server-creator', prefix: 'srvc' });
    const save = vi.fn();
    render(<GameDialog game={null} eggs={[{ id: 1, name: 'Egg', images: [] }]} pending={false} onClose={vi.fn()} onSave={save} />, { wrapper: host.Wrapper });

    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'Game' } });
    fireEvent.change(screen.getByLabelText('Required ports'), { target: { value: '2' } });
    fireEvent.change(screen.getByLabelText('Default ports'), { target: { value: '27015, 27115' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    expect(save).toHaveBeenCalledWith(expect.objectContaining({ ports: 2, default_ports: [27015, 27115] }));
});

test('explains a default-port count mismatch and allows matching the required count', async () => {
    host = createExtensionTestHost({ extensionId: 'server-creator', prefix: 'srvc' });
    const save = vi.fn();
    render(<GameDialog game={null} eggs={[{ id: 1, name: 'Egg', images: [] }]} pending={false} onClose={vi.fn()} onSave={save} />, { wrapper: host.Wrapper });

    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'Barotrauma' } });
    fireEvent.change(screen.getByLabelText('Default ports'), { target: { value: '27015,27016' } });
    expect(screen.getByRole('alert').textContent).toContain('You entered 2 default ports, but Required ports is 1');
    expect((screen.getByRole('button', { name: 'Save' }) as HTMLButtonElement).disabled).toBe(true);
    fireEvent.click(screen.getByRole('button', { name: 'Set required ports to 2' }));
    expect((screen.getByLabelText('Required ports') as HTMLInputElement).value).toBe('2');
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect(save).toHaveBeenCalledWith(expect.objectContaining({ ports: 2, default_ports: [27015, 27016] }));
});

test('rejects invalid and duplicate ports without offering a count change', async () => {
    host = createExtensionTestHost({ extensionId: 'server-creator', prefix: 'srvc' });
    render(<GameDialog game={null} eggs={[{ id: 1, name: 'Egg', images: [] }]} pending={false} onClose={vi.fn()} onSave={vi.fn()} />, { wrapper: host.Wrapper });

    const input = await screen.findByLabelText('Default ports');
    for (const value of ['27015,27015', '27015,65536', '27015,', '27015,abc']) {
        fireEvent.change(input, { target: { value } });
        expect(screen.getByRole('alert').textContent).toContain('distinct ports');
        expect(screen.queryByRole('button', { name: /Set required ports/ })).toBeNull();
    }
});
