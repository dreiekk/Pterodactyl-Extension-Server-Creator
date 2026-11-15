import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Dialog, Input, TitledGreyBox, httpErrorToHuman, invalidateServerData, toast, useServerResources } from '@pterodactyl/sdk';
import { getAllocations, keys, saveAllocations, swapAllocations } from '../api';

export default function AllocationsBox({ identifier, uuid }: { identifier: string; uuid: string }) {
    const queryClient = useQueryClient();
    const query = useQuery({ queryKey: keys.allocations(identifier), queryFn: () => getAllocations(identifier) });
    const state = useServerResources(uuid, (resources) => resources.attributes.current_state).data;
    const stopped = state === 'offline' || state === 'stopped';
    const [selected, setSelected] = useState<number[]>([]);
    const [primary, setPrimary] = useState(0);
    const [search, setSearch] = useState('');
    const [other, setOther] = useState('');
    const [confirmSwap, setConfirmSwap] = useState(false);

    useEffect(() => {
        if (query.data) {
            setSelected(query.data.allocations.filter((allocation) => allocation.assigned).map((allocation) => allocation.id));
            setPrimary(query.data.primary);
        }
    }, [query.data]);

    const saving = useMutation({
        mutationFn: () => saveAllocations(identifier, primary, selected),
        onSuccess: (data) => {
            queryClient.setQueryData(keys.allocations(identifier), data);
            invalidateServerData(identifier, ['server', 'startup']);
            toast.success('Ports saved.');
        },
        onError: (error) => {
            toast.error(httpErrorToHuman(error));
            void queryClient.invalidateQueries({ queryKey: keys.allocations(identifier) });
        },
    });

    const data = query.data;
    const candidate = data?.swap_servers.find((item) => item.id === Number(other));
    const swapping = useMutation({
        mutationFn: () => swapAllocations(identifier, Number(other)),
        onSuccess: (fresh) => {
            queryClient.setQueryData(keys.allocations(identifier), fresh);
            invalidateServerData(identifier, ['server', 'startup']);
            if (candidate) {
                void queryClient.invalidateQueries({ queryKey: keys.allocations(candidate.identifier) });
                invalidateServerData(candidate.identifier, ['server', 'startup']);
            }
            setOther('');
            setConfirmSwap(false);
            toast.success('Both servers exchanged their ports.');
        },
        onError: (error) => {
            toast.error(httpErrorToHuman(error));
            setConfirmSwap(false);
            void queryClient.invalidateQueries({ queryKey: keys.allocations(identifier) });
        },
    });
    const changed = !!data && (primary !== data.primary || data.allocations.some((allocation) => allocation.assigned !== selected.includes(allocation.id)));
    const visible = data?.allocations.filter((allocation) => allocation.assigned || `${allocation.ip}:${allocation.port}`.toLowerCase().includes(search.toLowerCase())) ?? [];

    return (
        <TitledGreyBox title='Ports'>
            {query.isPending ? <p>Loading ports...</p> : query.isError ? <p>{httpErrorToHuman(query.error)}</p> : data && (
                <div className='srvc:grid srvc:gap-3'>
                    <p className='srvc:text-sm srvc:text-muted-foreground'>
                        Select up to {data.limit} ports on this node and choose a primary port.
                        {data.required > 1 && ` Required ports must be on the primary IP at offsets ${data.offsets.join(', ')} from the primary.`}
                        {data.range && ` Game port range: ${data.range}.`}
                    </p>
                    <Input aria-label='Search available ports' placeholder='Search IP or port' value={search} onChange={(event) => setSearch(event.currentTarget.value)} />
                    <div className='srvc:max-h-64 srvc:overflow-auto srvc:grid srvc:gap-2'>
                        {visible.map((allocation) => (
                            <div key={allocation.id} className='srvc:flex srvc:items-center srvc:gap-3 srvc:text-sm'>
                                <label>
                                    <input type='checkbox' checked={selected.includes(allocation.id)} disabled={saving.isPending || (!selected.includes(allocation.id) && selected.length >= data.limit)} onChange={(event) => {
                                        const checked = event.currentTarget.checked;
                                        setSelected((current) => checked ? [...current, allocation.id] : current.filter((id) => id !== allocation.id));
                                    }} /> {allocation.ip}:{allocation.port}
                                </label>
                                {selected.includes(allocation.id) && (
                                    <label><input type='radio' name='srvc-primary-port' checked={primary === allocation.id} onChange={() => setPrimary(allocation.id)} /> Primary</label>
                                )}
                            </div>
                        ))}
                    </div>
                    {!stopped && <p className='srvc:text-xs srvc:text-warning'>Stop the server to change its ports.</p>}
                    <Button size='small' disabled={!stopped || !changed || !selected.includes(primary) || swapping.isPending} isLoading={saving.isPending} onClick={() => saving.mutate()}>Save ports</Button>
                    {data.swap_servers.length > 0 && (
                        <div className='srvc:grid srvc:gap-2'>
                            <label htmlFor='srvc-swap-server' className='srvc:text-sm srvc:font-medium'>Swap ports with another server</label>
                            <select id='srvc-swap-server' value={other} disabled={saving.isPending || swapping.isPending} onChange={(event) => setOther(event.currentTarget.value)}>
                                <option value=''>Choose a server on this node</option>
                                {data.swap_servers.map((item) => <option key={item.id} value={item.id}>{item.name} — {item.ports.join(', ')}</option>)}
                            </select>
                            <p className='srvc:text-xs srvc:text-muted-foreground'>Both servers must be stopped. Their saved port sets and primary ports will be exchanged; unsaved selections are not used.</p>
                            <Button size='small' disabled={!stopped || !candidate || saving.isPending || swapping.isPending} onClick={() => setConfirmSwap(true)}>Swap ports</Button>
                        </div>
                    )}
                    {confirmSwap && candidate && (
                        <Dialog open onClose={() => setConfirmSwap(false)} title='Swap ports' preventExternalClose={swapping.isPending}>
                            <p className='srvc:text-sm'>Exchange all ports with {candidate.name}? Both servers must remain stopped.</p>
                            <p className='srvc:mt-2 srvc:text-sm'>Allocation notes move with their ports and may be visible to users of the other server.</p>
                            <p className='srvc:mt-2 srvc:text-sm'>This server receives: {candidate.ports.join(', ')}.</p>
                            <p className='srvc:mt-2 srvc:text-sm'>{candidate.name} receives: {data.allocations.filter((allocation) => allocation.assigned).map((allocation) => `${allocation.ip}:${allocation.port}`).join(', ')}.</p>
                            <Dialog.Footer>
                                <Button.Text disabled={swapping.isPending} onClick={() => setConfirmSwap(false)}>Cancel</Button.Text>
                                <Button isLoading={swapping.isPending} onClick={() => swapping.mutate()}>Confirm swap</Button>
                            </Dialog.Footer>
                        </Dialog>
                    )}
                </div>
            )}
        </TitledGreyBox>
    );
}
