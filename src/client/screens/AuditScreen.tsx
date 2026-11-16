import { PageContentBlock, PanelLink } from '@pterodactyl/sdk';
import AuditLog from '../components/AuditLog';

export default function AuditScreen() {
    return (
        <PageContentBlock title='Server Creator audit log'>
            <header className='srvc:mb-6'>
                <PanelLink destination={{ extension: 'server-creator', screen: 'admin' }} className='srvc:text-sm srvc:text-primary srvc:hover:underline'>Back to Server Creator</PanelLink>
                <h1 className='srvc:mt-2 srvc:font-header srvc:text-2xl srvc:font-semibold srvc:text-foreground'>Server Creator audit log</h1>
            </header>
            <AuditLog />
        </PageContentBlock>
    );
}
