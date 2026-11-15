import { useState } from 'react';
import { afterEach, expect, test } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { createExtensionTestHost } from '@pterodactyl/sdk/testing';
import QuotaFields from './QuotaFields';
import type { Limits } from '../api';

afterEach(cleanup);

test('each quota can independently be set unlimited and returned to a zero limit', async () => {
    const host = createExtensionTestHost({ extensionId: 'server-creator', prefix: 'srvc' });
    function Form() {
        const [value, setValue] = useState<Limits>({ servers: 0, memory: 1024, disk: 0, cpu: 0, databases: 0, backups: 0, allocations: 0 });
        return <><QuotaFields prefix='quota' value={value} onChange={setValue} /><output>{JSON.stringify(value)}</output></>;
    }
    render(<Form />, { wrapper: host.Wrapper });
    fireEvent.click(await screen.findByLabelText('Unlimited Servers'));
    fireEvent.click(screen.getByLabelText('Unlimited Memory'));
    expect(screen.getByText(/"servers":-1,"memory":-1/)).toBeTruthy();
    expect((screen.getByLabelText('Memory (MiB)') as HTMLInputElement).disabled).toBe(true);
    fireEvent.click(screen.getByLabelText('Unlimited Servers'));
    expect(screen.getByText(/"servers":0,"memory":-1/)).toBeTruthy();
    host.dispose();
});
