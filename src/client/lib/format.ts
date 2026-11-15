import type { Limits, Resource } from '../api';
import { UNLIMITED } from './quota';
import { formatCpu, formatMiB, formatSize, isSized } from './resources';

export const LABELS: Record<Resource, string> = {
    servers: 'Servers',
    memory: 'Memory',
    disk: 'Disk',
    cpu: 'CPU',
    databases: 'Databases',
    backups: 'Backups',
    allocations: 'Extra ports',
};

export function formatResource(resource: Resource, value: number): string {
    return value === UNLIMITED ? 'Unlimited' : isSized(resource) ? formatSize(resource, value) : String(value);
}

/** "2 servers, 4 GiB memory and 20 GiB disk left" style summary of what is left. */
export function remainingText(remaining: Limits): string {
    if (remaining.servers !== UNLIMITED && remaining.servers < 1) {
        return 'You have used all of your servers.';
    }

    const servers = remaining.servers === UNLIMITED ? 'unlimited servers' : `${remaining.servers} more ${remaining.servers === 1 ? 'server' : 'servers'}`;
    const memory = remaining.memory === UNLIMITED ? 'unlimited' : formatMiB(remaining.memory);
    const disk = remaining.disk === UNLIMITED ? 'unlimited' : formatMiB(remaining.disk);
    const cpu = remaining.cpu === UNLIMITED ? 'unlimited CPU' : formatCpu(remaining.cpu);
    return `You can create ${servers} with up to ${memory} memory, ${disk} disk and ${cpu}.`;
}
