export type GuideRealm = 'admin' | 'teacher' | 'lunch';
export interface GuideBook { book: string; title: string; version: string }
export interface GuideTask { id: string; title: string; section: string }
export interface GuidePage { version: string; title: string; html: string; css: string; tasks: GuideTask[] }
export interface GuideItem { kind: 'chapter' | 'task' | 'faq'; id: string; title: string; text: string; chapter?: string; faq?: string }

export function guidePath(realm: GuideRealm, book: string, task?: string, faq?: string): string {
    return `/${realm === 'admin' ? 'masjid' : realm}/help/${encodeURIComponent(book)}${task ? '/' + encodeURIComponent(task) : ''}${faq ? '?faq=' + encodeURIComponent(faq) : ''}`;
}
