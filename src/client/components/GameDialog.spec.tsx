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
