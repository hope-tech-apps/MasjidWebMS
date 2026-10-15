import { test } from 'node:test';
import assert from 'node:assert/strict';
import { register } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const loader='export async function resolve(specifier,context,next){ if(specifier.startsWith("@/")) return next('+JSON.stringify(pathToFileURL(root).href)+'+"/"+specifier.slice(2)+".ts",context); return next(specifier,context); }';
register('data:text/javascript,'+encodeURIComponent(loader));
const values=new Map<string,string>();
(globalThis as any).sessionStorage={getItem:(k:string)=>values.get(k)??null,setItem:(k:string,v:string)=>values.set(k,v),removeItem:(k:string)=>values.delete(k)};
(globalThis as any).window={location:{origin:'https://school.invalid'}};

test('actual student axios client strips global and method staff credentials and scopes its own tokens and body encoding',async()=>{
    const { default: axios }=await import('axios');
    const { default: Client }=await import('@/core/services/StudentAccountApiService' as string);
    const previous=axios.defaults.headers.common.Authorization;
    const content=axios.defaults.headers.common['Content-Type'];
    const credentials=axios.defaults.withCredentials;
    const oldPost={...axios.defaults.headers.post},oldPut={...axios.defaults.headers.put};
    const sent:any[]=[];
    try{
        axios.defaults.headers.common.Authorization='Bearer staff-secret';
        axios.defaults.headers.post.Authorization='Bearer staff-post-secret';
        axios.defaults.headers.put.Authorization='Bearer staff-put-secret';
        axios.defaults.headers.common['Content-Type']='multipart/form-data';
        axios.defaults.withCredentials=true;
        Client.init('');
        assert.equal((Client as any).client.defaults.headers.common.Authorization,undefined);
        Client.adopt('3','student-three');
        (Client as any).client.defaults.adapter=async(config:any)=>{
            sent.push({url:config.url,auth:config.headers.get('Authorization'),type:config.headers.get('Content-Type'),cookies:config.withCredentials,body:config.data});
            return {data:{},status:200,statusText:'OK',headers:{},config};
        };
        await Client.get('/api/student/masjids/3/me');
        await Client.post('/api/student/masjids/4/auth/sign-in',{username:'practice'});
        await Client.put('/api/student/masjids/3/password',{password:'PracticePass42'});
        const form=new FormData();form.set('kind','photo');
        await Client.post('/api/student/masjids/3/classes/1/work/1/hand-ins',form);
        await Client.get('https://other.invalid/api/student/masjids/3/me');
        await Client.get('/api/family/masjids/3/me');
        assert.deepEqual(sent.map(r=>r.auth),['Bearer student-three',undefined,'Bearer student-three','Bearer student-three',undefined,undefined]);
        assert.ok(sent.every(r=>r.cookies===false));
        assert.equal(sent[1].type,'application/json');assert.equal(sent[2].type,'application/json');
        assert.equal(sent[3].body,form);assert.notEqual(sent[3].type,'application/json');
        Client.clear('3');await Client.get('/api/student/masjids/3/me');assert.equal(sent.at(-1).auth,undefined);
        assert.equal(axios.defaults.headers.common.Authorization,'Bearer staff-secret');
    }finally{
        axios.defaults.headers.common.Authorization=previous;
        axios.defaults.headers.common['Content-Type']=content;
        axios.defaults.withCredentials=credentials;
        axios.defaults.headers.post=oldPost;axios.defaults.headers.put=oldPut;
    }
});

test('a download finishing after its screen session changed never materializes private bytes',async()=>{
    const {default:Client}=await import('@/core/services/StudentAccountApiService' as string);
    Client.init('https://school.invalid');Client.adopt('3','student-A');
    let current=true,respond:(value:any)=>void=()=>{};
    const pending=new Promise(resolve=>{respond=resolve;});
    (Client as any).client.defaults.adapter=async(config:any)=>{await pending;return {data:new Blob(['private invented bytes']),status:200,statusText:'OK',headers:{},config};};
    const original=(globalThis as any).document;
    (globalThis as any).document={createElement:()=>{throw new Error('Old private file reached the new screen');}};
    try{
        const download=Client.download('/api/student/masjids/3/classes/1/work/1/hand-ins/1/download','practice.pdf',()=>current);
        await new Promise(resolve=>setTimeout(resolve,0));
        current=false;respond(null);await download;
    }finally{(globalThis as any).document=original;Client.clear('3');}
});
