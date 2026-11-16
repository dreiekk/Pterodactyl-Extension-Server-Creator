import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button, Spinner, TitledGreyBox, httpErrorToHuman } from '@pterodactyl/sdk';
import { getAudit, keys } from '../api';

const ACTIONS: Record<string, string> = {
    'catalog.selected': 'Catalog egg selected',
    'catalog.permission_changed': 'Catalog permission changed',
    'server.created': 'Server created',
    'server.deleted': 'Server deleted',
    'server.resized': 'Server resized',
    'server.game_switched': 'Server game switched',
    'server.ports_changed': 'Server ports changed',
    'server.ports_swapped': 'Server ports swapped',
    'server.duplicated': 'Server duplicated',
    'game.created': 'Game created',
    'game.updated': 'Game updated',
    'game.deleted': 'Game deleted',
    'imported_game.updated': 'Imported game updated',
    'imported_game.deleted': 'Imported game deleted',
    'quota.default_updated': 'Default quota updated',
    'quota.user_updated': 'User quota updated',
    'quota.user_reset': 'User quota reset',
    'locations.updated': 'Placement locations updated',
};

export default function AuditLog() {
    const [pages, setPages] = useState<(number | undefined)[]>([undefined]);
    const before = pages[pages.length - 1];
    const audit = useQuery({ queryKey: keys.audit(before), queryFn: () => getAudit(before) });

    return (
        <TitledGreyBox title='Audit log'>
            <p className='srvc:mb-4 srvc:text-sm srvc:text-muted-foreground'>Successful Server Creator actions, newest first. Startup variable values are not recorded.</p>
            {audit.isLoading && <Spinner size={Spinner.Size.SMALL} />}
            {audit.isError && <p role='alert' className='srvc:text-sm srvc:text-destructive'>{httpErrorToHuman(audit.error)}</p>}
            {audit.data && audit.data.entries.length === 0 && <p className='srvc:text-sm srvc:text-muted-foreground'>No audit entries yet.</p>}
            <ul className='srvc:grid srvc:gap-3'>
                {audit.data?.entries.map((entry) => (
                    <li key={entry.id} className='srvc:rounded-sm srvc:border srvc:border-border srvc:p-3 srvc:text-sm'>
                        <div className='srvc:flex srvc:flex-wrap srvc:justify-between srvc:gap-2'>
                            <span className='srvc:font-medium'>{ACTIONS[entry.action] ?? entry.action}: {entry.target}</span>
                            <time dateTime={entry.created_at} className='srvc:text-muted-foreground'>{new Date(entry.created_at).toLocaleString()}</time>
                        </div>
                        <p className='srvc:text-muted-foreground'>By {entry.actor_name} (user #{entry.actor_id})</p>
                        <details className='srvc:mt-1'><summary>Details</summary><pre className='srvc:overflow-auto srvc:whitespace-pre-wrap srvc:break-all srvc:text-xs'>{JSON.stringify(entry.details, null, 2)}</pre></details>
                    </li>
                ))}
            </ul>
            <div className='srvc:mt-4 srvc:flex srvc:gap-2'>
                <Button.Text size='small' disabled={pages.length === 1} onClick={() => setPages((current) => current.slice(0, -1))}>Newer</Button.Text>
                <Button.Text size='small' disabled={!audit.data?.next_before} onClick={() => {
                    if (audit.data?.next_before) setPages((current) => [...current, audit.data!.next_before!]);
                }}>Older</Button.Text>
                <Button.Text size='small' onClick={() => audit.refetch()}>Refresh</Button.Text>
            </div>
        </TitledGreyBox>
    );
}
