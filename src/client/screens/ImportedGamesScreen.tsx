import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Dialog, Input, Label, PageContentBlock, PanelLink, Spinner, httpErrorToHuman, toast } from '@pterodactyl/sdk';
import { deleteImportedGame, enableImportedGame, getImportedGames, keys, saveImportedGame, type GameInput, type ImportedGame } from '../api';
import GameDialog from '../components/GameDialog';

export default function ImportedGamesScreen() {
    const client = useQueryClient();
    const games = useQuery({ queryKey: keys.importedGames, queryFn: getImportedGames });
    const [term, setTerm] = useState('');
    const [editing, setEditing] = useState<ImportedGame | null>(null);
    const [removing, setRemoving] = useState<ImportedGame | null>(null);
    const refresh = (result: ImportedGame[]) => {
        client.setQueryData(keys.importedGames, result);
        client.invalidateQueries({ queryKey: ['server-creator', 'audit'] });
        client.invalidateQueries({ queryKey: keys.overview });
        client.invalidateQueries({ queryKey: ['server-creator', 'server'] });
    };
    const onError = (error: unknown) => toast.error(httpErrorToHuman(error));
    const save = useMutation({ mutationFn: ({ id, input }: { id: number; input: GameInput }) => saveImportedGame(id, input), onSuccess: (result) => { refresh(result); setEditing(null); toast.success('Imported profile saved.'); }, onError });
    const remove = useMutation({ mutationFn: deleteImportedGame, onSuccess: (result) => { refresh(result); setRemoving(null); toast.success('Imported profile deleted.'); }, onError });
    const toggle = useMutation({ mutationFn: (game: ImportedGame) => enableImportedGame(game.id, !game.enabled), onSuccess: (result) => { refresh(result); toast.success('Imported profile updated.'); }, onError });
    const visible = (games.data ?? []).filter((game) => `${game.name} ${game.egg ?? ''} ${game.owner_name} ${game.owner_id}`.toLowerCase().includes(term.trim().toLowerCase()));

    return (
        <PageContentBlock title='Imported eggs'>
            <header className='srvc:mb-6'>
                <PanelLink destination={{ extension: 'server-creator', screen: 'admin' }} className='srvc:text-sm srvc:text-primary srvc:hover:underline'>Back to Server Creator</PanelLink>
                <h1 className='srvc:mt-2 srvc:font-header srvc:text-2xl srvc:font-semibold srvc:text-foreground'>User-imported eggs</h1>
                <p className='srvc:mt-1 srvc:text-sm srvc:text-muted-foreground'>These private profiles belong to individual users. Disabling a profile prevents new servers and game switches; existing servers keep running.</p>
            </header>
            <div className='srvc:mb-4'><Label htmlFor='srvc-imported-search'>Search profiles or users</Label><Input id='srvc-imported-search' value={term} onChange={(event) => setTerm(event.currentTarget.value)} /></div>
            {games.isLoading && <Spinner size={Spinner.Size.LARGE} />}
            {games.isError && <p role='alert' className='srvc:text-destructive'>{httpErrorToHuman(games.error)}</p>}
            {games.data && visible.length === 0 && <p className='srvc:text-sm srvc:text-muted-foreground'>No imported profiles found.</p>}
            <div className='srvc:grid srvc:gap-3'>
                {visible.map((game) => (
                    <div key={game.id} className='srvc:flex srvc:flex-wrap srvc:items-center srvc:justify-between srvc:gap-3 srvc:rounded-sm srvc:bg-card srvc:p-4'>
                        <div>
                            <p className='srvc:font-medium'>{game.name} {game.enabled ? '' : '(disabled)'}</p>
                            <p className='srvc:text-sm srvc:text-muted-foreground'>Egg: {game.egg} · Owner: {game.owner_name} (#{game.owner_id})</p>
                        </div>
                        <div className='srvc:flex srvc:flex-wrap srvc:gap-2'>
                            <Button.Text size='small' onClick={() => setEditing(game)}>Edit</Button.Text>
                            <Button.Text size='small' disabled={toggle.isPending} onClick={() => toggle.mutate(game)}>{game.enabled ? 'Disable' : 'Enable'}</Button.Text>
                            <Button.Danger size='small' onClick={() => setRemoving(game)}>Delete</Button.Danger>
                        </div>
                    </div>
                ))}
            </div>
            {editing && <GameDialog game={editing} eggs={[]} privateProfile pending={save.isPending} onClose={() => setEditing(null)} onSave={(input) => save.mutate({ id: editing.id, input })} />}
            <Dialog.Confirm open={removing !== null} onClose={() => setRemoving(null)} title={`Delete ${removing?.name}?`} confirm='Delete' pending={remove.isPending} onConfirmed={() => removing && remove.mutate(removing.id)}>
                Existing servers keep running but lose their link to this profile. The panel egg remains installed. A user with import permission can import it again.
            </Dialog.Confirm>
        </PageContentBlock>
    );
}
