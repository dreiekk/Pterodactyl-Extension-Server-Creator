import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Input, Label, Spinner, httpErrorToHuman, toast } from '@pterodactyl/sdk';
import { getCatalog, getCatalogPorts, importCatalogEgg, keys, type CatalogEgg, type Game } from '../api';

export default function CatalogPicker({ onImported }: { onImported: (game: Game) => void }) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<CatalogEgg | null>(null);
    const [ports, setPorts] = useState(1);
    const [defaults, setDefaults] = useState('');
    const [variables, setVariables] = useState('');
    const portLayoutEdited = useRef(false);
    const queryClient = useQueryClient();
    const catalog = useQuery({ queryKey: keys.catalog, queryFn: getCatalog, enabled: open });
    const catalogPorts = useQuery({
        queryKey: [...keys.catalog, 'ports', selected?.id],
        queryFn: () => getCatalogPorts(selected!.id),
        enabled: open && selected !== null,
        retry: false,
    });
    useEffect(() => {
        if (catalogPorts.data && !portLayoutEdited.current) {
            setPorts(catalogPorts.data.length);
            setDefaults(catalogPorts.data.join(', '));
        }
    }, [selected?.id, catalogPorts.data]);
    const importEgg = useMutation({
        mutationFn: () => importCatalogEgg({ catalog_id: selected!.id, ports, default_ports: parsedPorts, port_variables: parsedVariables }),
        onSuccess: (game) => {
            queryClient.invalidateQueries({ queryKey: keys.overview });
            toast.success('Egg imported for your servers.');
            setOpen(false);
            onImported(game);
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });
    const parsedPorts = defaults.trim() ? defaults.split(',').map((part) => Number(part.trim())) : [];
    const parsedVariables = variables.trim() ? variables.split(',').map((part) => part.trim()) : [];
    const validDefaults = defaults.trim() === '' || (/^\s*\d+(?:\s*,\s*\d+)*\s*$/.test(defaults) && parsedPorts.length === ports && new Set(parsedPorts).size === parsedPorts.length && parsedPorts.every((port) => port >= 1 && port <= 65535));
    const validVariables = parsedVariables.length <= ports - 1 && new Set(parsedVariables).size === parsedVariables.length && parsedVariables.every((name) => /^[A-Za-z0-9_]{1,191}$/.test(name));
    const valid = selected !== null && Number.isInteger(ports) && ports >= 1 && ports <= 10 && validDefaults && validVariables;
    const term = search.trim().toLowerCase();
    const matches = (catalog.data ?? []).filter((egg) => `${egg.name} ${egg.description} ${egg.category}`.toLowerCase().includes(term));

    return (
        <div className='srvc:grid srvc:gap-3'>
            <div><Button.Text size='small' onClick={() => setOpen(!open)}>{open ? 'Close egg catalog' : 'Import an egg from the catalog'}</Button.Text></div>
            {open && (
                <div className='srvc:grid srvc:gap-3 srvc:rounded-sm srvc:bg-card srvc:p-4'>
                    <p className='srvc:text-sm srvc:text-muted-foreground'>Catalog eggs run their own installation scripts and container images. Only import eggs you trust. Your imported games are private to you.</p>
                    <div><Label htmlFor='srvc-catalog-search'>Search egg catalog</Label><Input id='srvc-catalog-search' value={search} onChange={(event) => setSearch(event.currentTarget.value)} /></div>
                    {catalog.isLoading && <Spinner size={Spinner.Size.SMALL} />}
                    {catalog.isError && <p role='alert' className='srvc:text-destructive'>{httpErrorToHuman(catalog.error)}</p>}
                    {catalog.data && matches.length === 0 && <p className='srvc:text-sm'>No matching eggs.</p>}
                    {matches.length > 30 && <p className='srvc:text-xs'>Showing the first 30 matches; search to narrow the results.</p>}
                    <div className='srvc:grid srvc:max-h-64 srvc:gap-1 srvc:overflow-auto'>
                        {matches.slice(0, 30).map((egg) => <button key={egg.id} type='button' aria-pressed={selected?.id === egg.id} onClick={() => {
                            if (selected?.id !== egg.id) {
                                portLayoutEdited.current = false;
                                setPorts(1);
                                setDefaults('');
                                setVariables('');
                                setSelected(egg);
                            }
                        }} className='srvc:rounded-sm srvc:p-2 srvc:text-left srvc:hover:bg-sunken'>
                            <span className='srvc:font-medium'>{egg.name}</span> <span className='srvc:text-xs srvc:text-muted-foreground'>{egg.category}</span>
                        </button>)}
                    </div>
                    {selected && <>
                        <div><p className='srvc:font-medium'>{selected.name}</p><p className='srvc:text-sm srvc:text-muted-foreground'>{selected.description}</p><a href={selected.source_url} target='_blank' rel='noreferrer' className='srvc:text-sm srvc:underline'>View catalog details</a></div>
                        <p className='srvc:text-xs srvc:text-muted-foreground'>Set the port layout before importing. If you have already imported this egg, its original settings are retained. Secondary ports must be supported by the egg or configured in the game files.</p>
                        {catalogPorts.isFetching && <p className='srvc:text-xs srvc:text-muted-foreground'>Loading default ports from the catalog…</p>}
                        {catalogPorts.isError && <p role='alert' className='srvc:text-xs srvc:text-destructive'>Could not load catalog ports. Please enter them manually.</p>}
                        {catalogPorts.isSuccess && catalogPorts.data === null && <p className='srvc:text-xs srvc:text-muted-foreground'>The catalog does not list an unambiguous set of ports. Check the catalog details and enter them manually.</p>}
                        {catalogPorts.data && <p className='srvc:text-xs srvc:text-muted-foreground'>Port suggestions come from the catalog. Check the catalog details and adjust them if needed.</p>}
                        <div><Label htmlFor='srvc-catalog-ports'>Required ports</Label><Input id='srvc-catalog-ports' type='number' min={1} max={10} value={ports} onChange={(event) => { portLayoutEdited.current = true; setPorts(Number(event.currentTarget.value)); }} /></div>
                        <div><Label htmlFor='srvc-catalog-defaults'>Default ports</Label><Input id='srvc-catalog-defaults' placeholder='27015, 27016' value={defaults} onChange={(event) => { portLayoutEdited.current = true; setDefaults(event.currentTarget.value); }} />{!validDefaults && <p role='alert' className='srvc:text-xs srvc:text-destructive'>Enter distinct ports from 1 to 65535, one per required port, or leave empty.</p>}</div>
                        <div><Label htmlFor='srvc-catalog-variables'>Secondary port variables</Label><Input id='srvc-catalog-variables' placeholder='QUERY_PORT, RCON_PORT' value={variables} onChange={(event) => setVariables(event.currentTarget.value)} /><p className='srvc:text-xs srvc:text-muted-foreground'>Only user-editable variables from the egg can be used.</p>{!validVariables && <p role='alert' className='srvc:text-xs srvc:text-destructive'>Enter distinct variable names, at most one per secondary port.</p>}</div>
                        <div><Button size='small' disabled={!valid || (catalogPorts.isFetching && !portLayoutEdited.current)} isLoading={importEgg.isPending} onClick={() => importEgg.mutate()}>Import {selected.name}</Button></div>
                    </>}
                </div>
            )}
        </div>
    );
}
