import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Input, TitledGreyBox, httpErrorToHuman, invalidateServerData, toast, useServerResources } from '@pterodactyl/sdk';
import { getAllocations, keys, saveAllocations } from '../api';

export default function AllocationsBox({ identifier, uuid }: { identifier: string; uuid: string }) {
    const queryClient = useQueryClient();
    const query = useQuery({ queryKey: keys.allocations(identifier), queryFn: () => getAllocations(identifier) });
    const state = useServerResources(uuid, (resources) => resources.attributes.current_state).data;
    const stopped = state === 'offline' || state === 'stopped';
    const [selected, setSelected] = useState<number[]>([]);
    const [primary, setPrimary] = useState(0);
    const [search, setSearch] = useState('');

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
                    <Button size='small' disabled={!stopped || !changed || !selected.includes(primary)} isLoading={saving.isPending} onClick={() => saving.mutate()}>Save ports</Button>
                </div>
            )}
        </TitledGreyBox>
    );
}
