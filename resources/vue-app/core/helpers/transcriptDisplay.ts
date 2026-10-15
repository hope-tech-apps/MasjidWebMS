/** Calendar dates stay calendar dates; instants use the school's clock. */
export function transcriptDate(value: string | null | undefined): string {
    if (!value) return 'Not recorded';
    return new Intl.DateTimeFormat('en-US', { month:'short', day:'numeric', year:'numeric', timeZone:'UTC' }).format(new Date(`${value.slice(0,10)}T12:00:00Z`));
}
export function transcriptGrade(value: string | null | undefined): string {
    if (!value) return 'Not recorded';
    return value.replace(/^(?:Grade\s*)?(\d+)(?:st|nd|rd|th)?(?:\s*Grade)?$/i, 'Grade $1');
}

/** Plain office labels for each transcript request state. */
export function transcriptStatus(value: string): string {
    return ({awaiting_family:'Waiting for the family',pending:'Waiting for the other school',accepted:'Accepted',declined:'Declined',withdrawn:'Withdrawn'} as Record<string,string>)[value] ?? 'Status unavailable';
}
