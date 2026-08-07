import{m as qe,a3 as tt,U as I,G as nt,D as bt,aj as Ge,x as kt,K as b,h as k,B as Je,a5 as x,e as u,_ as De,d as R,F as se,V as de,a1 as U,g as M,k as H,y as xt,n as wt,a8 as Ft,a6 as _t,A as re,C as zt,I as Et,l as Ie,al as Ye,am as Ke,ag as At,af as St,b as ye}from"./app-BHXILLtH.js";import{_ as Tt}from"./PageDataContainer.vue_vue_type_script_setup_true_lang-Cfkw_MPa.js";const at=["queued","processing"],$t={width:1200,height:1600,aspect:"3:4"},Ct=[{key:"canvas",label:"1200 × 1600",width:1200,height:1600,note:"The design's own 3:4 canvas — print, WhatsApp, the noticeboard."},{key:"portrait",label:"1080 × 1350",width:1080,height:1350,note:"Instagram portrait (4:5). Scaled to fit, bleed filled from the palette — nothing is cropped."}],It=8,jt=.96,Xe=.72,Lt=6e4,Nt="Montserrat",Bt=qe({__name:"FlyerPreview",props:{manifest:{type:Object,required:!0},html:{type:String,required:!0},content:{type:Object,required:!0},cssVars:{type:Object,required:!0},placeholders:{type:Boolean,required:!1,default:!0}},emits:["overflow"],setup(r,{expose:e,emit:s}){const f=r,h=s,{manifest:p,html:z,content:w,cssVars:v,placeholders:j}=tt(f),F=I(null),$=I(null),L=I(.4),C=$t;let O=null,P=null,Y=null,G=null,V=null;nt(()=>{ee(),_(),G=new ResizeObserver(()=>K()),F.value&&G.observe(F.value),K()}),bt(()=>{G==null||G.disconnect(),G=null}),Ge([z,p],()=>{ee(),_()}),Ge([w,v,j],()=>_());function K(){var l;const a=((l=F.value)==null?void 0:l.clientWidth)??0;a>0&&(L.value=Math.min(1,a/C.width))}function ee(){const a=$.value;if(!a)return;a.innerHTML="",O=null,P=null,Y=null;const l=document.createElement("template");l.innerHTML=z.value;const y=Array.from(l.content.querySelectorAll("style")).map(g=>g.textContent??"").join(`
`);O=document.createElement("style"),O.textContent=y,a.appendChild(O),P=l.content.querySelector(".flyer")}function _(){const a=$.value;if(!a||!P)return;const l=P.cloneNode(!0);c(l),m(l,null,w.value),oe(l),Object.entries(v.value).forEach(([y,g])=>l.style.setProperty(y,g)),Y==null||Y.remove(),a.appendChild(l),Y=l,kt(()=>{B(l),h("overflow",l.scrollHeight>l.clientHeight+2)})}function c(a){a.querySelectorAll("[data-repeat]").forEach(l=>{const y=l.dataset.repeat,g=l.parentElement;if(!y||!g)return;const S=w.value[y];(Array.isArray(S)?S.filter(d):[]).forEach(N=>{const Z=l.cloneNode(!0);Z.removeAttribute("data-repeat"),m(Z,y,N),g.insertBefore(Z,l)}),l.remove()})}function d(a){return Object.values(a??{}).some(l=>!!String(l??"").trim())}function m(a,l,y){a.querySelectorAll("[data-slot]").forEach(g=>{const S=g.dataset.slot??"";let T=S;if(l){if(!S.startsWith(`${l}.`))return;T=S.slice(l.length+1)}else if(S.includes("."))return;q(g,T,y[T],l)})}function o(a){return p.value.slots.find(l=>l.name===a)}function q(a,l,y,g){const S=g?void 0:o(l),T=a.dataset.optional==="true",N=typeof y=="string"?y.trim():"",Z=J(a);if(Z){if(N){Z.setAttribute("src",N);return}if(T||!j.value){a.remove();return}a.replaceWith(ne(a,S));return}if(!N){if(T){a.remove();return}j.value&&S?(a.textContent=S.label,a.setAttribute("data-flyer-placeholder","text"),a.style.opacity="0.35"):a.textContent="";return}X(a,N)}function J(a){return a instanceof HTMLImageElement?a:a.querySelector("img")}function X(a,l){a.textContent="",l.split(/\r?\n/).forEach((y,g)=>{g>0&&a.appendChild(document.createElement("br")),a.appendChild(document.createTextNode(y))})}function ne(a,l){const y=document.createElement("div");return y.className=a.className,y.setAttribute("data-flyer-placeholder","image"),y.style.cssText=["width:100%","height:100%","display:flex","align-items:center","justify-content:center","border:6px dashed currentColor","border-radius:16px","opacity:0.3","font-size:36px","font-weight:500","text-align:center"].join(";"),y.textContent=l?l.label:"Image",y}function oe(a){const l=document.createTreeWalker(a,NodeFilter.SHOW_TEXT);for(;l.nextNode();){const y=l.currentNode,g=y.nodeValue??"";g.includes("{{")&&(y.nodeValue=g.replace(/\{\{\s*[\w.]+\s*\}\}/g,"").trim())}a.querySelectorAll('img[src*="{{"]').forEach(y=>y.remove())}function B(a){p.value.slots.filter(l=>!!l.size_var).forEach(l=>{const y=l.size_var;a.style.removeProperty(y);const g=a.querySelector(`[data-slot="${l.name}"]`),S=(g==null?void 0:g.closest(".zone"))??(g==null?void 0:g.parentElement)??null;if(!g||!S)return;const T=parseFloat(window.getComputedStyle(g).fontSize);if(!T||Number.isNaN(T))return;let N=T;for(let Z=0;Z<It&&!(!le(g,S)||(N=Math.max(N*jt,T*Xe),a.style.setProperty(y,`${N}px`),N<=T*Xe));Z+=1);})}function le(a,l){return a.scrollWidth>l.clientWidth+1?!0:a.scrollHeight>l.clientHeight*1.15}async function Q(a){const l=await ke(),y=pe(),g=Math.min(a.width/C.width,a.height/C.height),S=(a.width-C.width*g)/2,T=(a.height-C.height*g)/2,N=`<svg xmlns="http://www.w3.org/2000/svg" width="${a.width}" height="${a.height}" viewBox="0 0 ${a.width} ${a.height}"><g transform="translate(${S} ${T}) scale(${g})"><foreignObject x="0" y="0" width="${C.width}" height="${C.height}"><div xmlns="http://www.w3.org/1999/xhtml">${l}${y}</div></foreignObject></g></svg>`,Z=await Re(`data:image/svg+xml;charset=utf-8,${encodeURIComponent(N)}`),ue=document.createElement("canvas");ue.width=a.width,ue.height=a.height;const he=ue.getContext("2d");if(!he)throw new Error("This browser cannot export images.");return he.imageSmoothingQuality="high",te(he,a),he.drawImage(Z,0,0,a.width,a.height),await new Promise((Te,$e)=>{ue.toBlob(Fe=>Fe?Te(Fe):$e(new Error("The flyer could not be turned into a PNG.")),"image/png")})}function pe(){const a=$.value;if(!a)throw new Error("There is nothing to export yet.");const l=a.cloneNode(!0);l.querySelectorAll("[data-flyer-placeholder]").forEach(g=>g.remove());const y=new XMLSerializer;return Array.from(l.childNodes).map(g=>y.serializeToString(g)).join("")}async function ke(){if(V!==null)return V;const a=xe();if(!a.length)return V="",V;const l=[];for(const y of a)l.push(await me(y));return V=`<style>${Se(l.join(""))}</style>`,V}function xe(){const a=[];return Array.from(document.styleSheets).forEach(l=>{let y;try{y=l.cssRules}catch{return}Array.from(y).forEach(g=>{g instanceof CSSFontFaceRule&&g.style.getPropertyValue("font-family").includes(Nt)&&a.push(g)})}),a}async function me(a){let l=a.cssText;for(const y of Array.from(a.cssText.matchAll(/url\((["']?)([^"')]+)\1\)/g))){const g=y[2];g.startsWith("data:")||(l=l.replace(y[0],`url("${await Ae(g)}")`))}return l}async function Ae(a){const l=new Error("The flyer font could not be added to the export. Reload the page and try again."),y=await fetch(a).catch(()=>{throw l});if(!y.ok)throw l;const g=await y.blob();return await new Promise((S,T)=>{const N=new FileReader;N.onload=()=>S(String(N.result)),N.onerror=()=>T(l),N.readAsDataURL(g)})}function Se(a){return a.replace(/&/g,"&amp;").replace(/</g,"&lt;")}function te(a,l){var S;const y=v.value,g=(S=p.value.palette)==null?void 0:S.ground;if(g==="gradient"){const T=a.createLinearGradient(0,0,0,l.height);T.addColorStop(0,y["--flyer-grad-0"]),T.addColorStop(.5,y["--flyer-grad-1"]),T.addColorStop(.8,y["--flyer-grad-2"]),T.addColorStop(1,y["--flyer-grad-3"]),a.fillStyle=T}else g==="field"?a.fillStyle=y["--flyer-field"]:g==="solid"?a.fillStyle=y["--flyer-ground"]:a.fillStyle="#000000";a.fillRect(0,0,l.width,l.height)}function Re(a){return new Promise((l,y)=>{const g=new Image;g.onload=()=>l(g),g.onerror=()=>y(new Error("The flyer could not be rendered for export.")),g.src=a})}async function we(a,l){const y=await Q(a),g=URL.createObjectURL(y),S=document.createElement("a");S.href=g,S.download=l,document.body.appendChild(S),S.click(),S.remove(),setTimeout(()=>URL.revokeObjectURL(g),Lt)}return e({toBlob:Q,download:we}),(a,l)=>(b(),k("div",{class:"flyer-viewport",ref_key:"viewportRef",ref:F,style:Je({height:`${x(C).height*L.value}px`})},[u("div",{class:"flyer-stage",style:Je({transform:`scale(${L.value})`})},[u("div",{ref_key:"hostRef",ref:$},null,512)],4)],4))}}),qt=De(Bt,[["__scopeId","data-v-2e250e4e"]]),Dt={class:"flyer-slot-form"},Mt=["for"],Pt={class:"fw-semibold"},Rt={key:0,class:"text-danger"},Ot={key:1,class:"badge bg-warning-subtle text-warning-emphasis fw-normal"},Ut=["id","maxlength","value","onInput"],Vt=["id","maxlength","value","onInput"],Ht={key:2},Wt={key:0,class:"d-flex align-items-center gap-3"},Gt=["src"],Jt={class:"flex-grow-1"},Yt={class:"small text-truncate"},Kt={class:"d-flex gap-2 mt-2"},Xt={class:"btn btn-sm btn-outline-secondary mb-0"},Zt=["onChange"],Qt=["onClick"],en={key:1,class:"slot-drop w-100 text-center"},tn=["onChange"],nn={key:2,class:"mt-2"},an={key:0,class:"d-flex align-items-center gap-2 small text-muted"},rn={key:1,class:"form-check form-switch"},on=["id","checked","onChange"],ln=["for"],sn={key:2,class:"alert alert-warning py-2 px-3 mb-0 small"},dn=["disabled"],cn={key:3,class:"small text-muted"},un={key:3,class:"form-text mt-2"},hn={key:3},fn={class:"d-flex justify-content-between align-items-center mb-2"},pn={class:"small text-muted fw-semibold"},mn=["onClick"],gn={class:"row g-2"},yn={class:"form-label small mb-1"},vn={key:0,class:"text-danger"},bn=["maxlength","value","onInput"],kn=["disabled","onClick"],xn={key:0,class:"form-text"},wn={class:"d-flex justify-content-between gap-3 mt-1"},Fn={key:0,class:"form-text"},_n=qe({__name:"FlyerSlotForm",props:{slots:{type:Array,required:!0},content:{type:Object,required:!0},images:{type:Object,required:!0},cutoutSlot:{type:String,required:!1,default:null},cutoutStatus:{type:String,required:!1,default:"none"},cutoutError:{type:String,required:!1,default:null},cutoutAvailable:{type:Boolean,required:!1,default:!1},uploading:{type:Boolean,required:!1,default:!1}},emits:["update-slot","update-list","add-item","remove-item","image","clear-image","use-cutout","retry-photo"],setup(r,{emit:e}){const s=r,f=e,{slots:h,content:p,images:z,cutoutSlot:w,cutoutStatus:v,cutoutError:j,cutoutAvailable:F,uploading:$}=tt(s),L=R(()=>at.includes(v.value));function C(d){const m=p.value[d];return typeof m=="string"?m:""}function O(d){const m=p.value[d];return Array.isArray(m)?m:[]}function P(d){return z.value[d]}function Y(d){const m=P(d);return m?m.useCutout&&m.cutout?m.cutout:m.original:null}function G(d){return d.maxLength?C(d.name).length>=d.maxLength:!1}function V(d){return d.maxItems?O(d.name).length>=d.maxItems:!1}function K(d,m){const o=m.target;f("update-slot",d,o.value)}function ee(d,m,o,q){const J=q.target,X=O(d).map((ne,oe)=>oe===m?{...ne,[o]:J.value}:ne);f("update-list",d,X)}function _(d,m){var J;const o=m.target,q=(J=o.files)==null?void 0:J[0];q&&f("image",d,q),o.value=""}function c(d,m){const o=m.target;f("use-cutout",d,o.checked)}return(d,m)=>(b(),k("div",Dt,[(b(!0),k(se,null,de(x(h),o=>{var q,J,X,ne,oe;return b(),k("div",{key:o.name,class:"mb-4"},[u("label",{class:"form-label d-flex align-items-center gap-2 mb-1",for:`slot-${o.name}`},[u("span",Pt,U(o.label),1),o.required?(b(),k("span",Rt,"*")):M("",!0),o.ask_always?(b(),k("span",Ot,m[1]||(m[1]=[u("i",{class:"bi bi-question-circle me-1"},null,-1),H("Ask every time ")]))):M("",!0)],8,Mt),o.type==="text"?(b(),k("input",{key:0,id:`slot-${o.name}`,type:"text",class:"form-control",maxlength:o.maxLength??void 0,value:C(o.name),onInput:B=>K(o.name,B)},null,40,Ut)):o.type==="multiline"?(b(),k("textarea",{key:1,id:`slot-${o.name}`,class:"form-control",rows:"3",maxlength:o.maxLength??void 0,value:C(o.name),onInput:B=>K(o.name,B)},null,40,Vt)):o.type==="image"?(b(),k("div",Ht,[(q=P(o.name))!=null&&q.original?(b(),k("div",Wt,[u("img",{class:"slot-thumb",src:Y(o.name)??"",alt:""},null,8,Gt),u("div",Jt,[u("div",Yt,U((J=P(o.name))==null?void 0:J.fileName),1),u("div",Kt,[u("label",Xt,[m[2]||(m[2]=H(" Replace ")),u("input",{type:"file",class:"d-none",accept:"image/*",onChange:B=>_(o.name,B)},null,40,Zt)]),u("button",{type:"button",class:"btn btn-sm btn-outline-danger",onClick:B=>f("clear-image",o.name)}," Remove ",8,Qt)])])])):(b(),k("label",en,[m[3]||(m[3]=u("i",{class:"bi bi-image fs-3 d-block mb-1"},null,-1)),m[4]||(m[4]=u("span",{class:"small"},"Choose an image",-1)),u("input",{type:"file",class:"d-none",accept:"image/*",onChange:B=>_(o.name,B)},null,40,tn)])),o.name===x(w)&&((X=P(o.name))!=null&&X.original)?(b(),k("div",nn,[x($)||L.value?(b(),k("div",an,[m[5]||(m[5]=u("span",{class:"spinner-border spinner-border-sm",role:"status"},null,-1)),u("span",null,U(x($)?"Sending the photo…":"Removing the background…"),1)])):x(v)==="done"?(b(),k("div",rn,[u("input",{class:"form-check-input",type:"checkbox",id:`cutout-${o.name}`,checked:(ne=P(o.name))==null?void 0:ne.useCutout,onChange:B=>c(o.name,B)},null,40,on),u("label",{class:"form-check-label small",for:`cutout-${o.name}`},m[6]||(m[6]=[H(" Use the cut-out "),u("span",{class:"text-muted d-block"}," A transparent cut-out reads best against the gradient. Switch it off to use the photo as taken. ",-1)]),8,ln)])):x(v)==="failed"?(b(),k("div",sn,[m[8]||(m[8]=u("i",{class:"bi bi-exclamation-triangle me-1"},null,-1)),H(" "+U(x(j)||"The background could not be removed. The photo is being used as uploaded.")+" ",1),u("button",{type:"button",class:"btn btn-sm btn-outline-secondary mt-2 d-block",disabled:x($),onClick:m[0]||(m[0]=B=>f("retry-photo"))},m[7]||(m[7]=[u("i",{class:"bi bi-arrow-clockwise me-1"},null,-1),H("Try again ")]),8,dn)])):x(F)?M("",!0):(b(),k("div",cn," Background removal is not switched on for this server — the photo will be used as uploaded. "))])):(oe=P(o.name))!=null&&oe.original?(b(),k("div",un," Drawn on the flyer and included in the export, but not stored with the draft. ")):M("",!0)])):o.type==="list"?(b(),k("div",hn,[(b(!0),k(se,null,de(O(o.name),(B,le)=>(b(),k("div",{key:le,class:"border rounded p-3 mb-2 bg-light-subtle"},[u("div",fn,[u("span",pn,U(le+1),1),u("button",{type:"button",class:"btn btn-sm btn-link text-danger p-0",onClick:Q=>f("remove-item",o.name,le)}," Remove ",8,mn)]),u("div",gn,[(b(!0),k(se,null,de(o.item_slots??[],Q=>(b(),k("div",{key:Q.name,class:"col-12"},[u("label",yn,[H(U(Q.label)+" ",1),Q.required?(b(),k("span",vn,"*")):M("",!0)]),u("input",{type:"text",class:"form-control form-control-sm",maxlength:Q.maxLength??void 0,value:B[Q.name]??"",onInput:pe=>ee(o.name,le,Q.name,pe)},null,40,bn)]))),128))])]))),128)),u("button",{type:"button",class:"btn btn-sm btn-outline-secondary",disabled:V(o),onClick:B=>f("add-item",o.name)},m[9]||(m[9]=[u("i",{class:"bi bi-plus-lg me-1"},null,-1),H("Add ")]),8,kn),V(o)?(b(),k("div",xn,U(o.help),1)):M("",!0)])):M("",!0),u("div",wn,[o.help&&o.type!=="list"?(b(),k("div",Fn,U(o.help),1)):M("",!0),o.maxLength?(b(),k("div",{key:1,class:xt(["form-text text-nowrap ms-auto",{"text-danger":G(o)}])},U(C(o.name).length)+" / "+U(o.maxLength),3)):M("",!0)])])}),128))]))}}),zn=De(_n,[["__scopeId","data-v-6562af14"]]),En="event-banner",An="Event — Banner",Sn="event",Tn="banner",$n=1,Cn="event-banner.html",In="flyer-banner",jn="Big title over a flat colour field with optional cut-out artwork. The loudest archetype — use it to announce, not to invite.",Ln={width:1200,height:1600,aspect:"3:4"},Nn="flow",Bn={default:"cool-neutral",ground:"field",note:"Reads --flyer-field / --flyer-field-ink, not the gradient. palette.js clamps the field dark enough that white type clears AA."},qn=[{name:"eyebrow",label:"Intro line",type:"multiline",required:!1,maxLength:160,default:null,help:"One or two sentences of context above the title. Leave empty and the line is removed."},{name:"title",label:"Event title",type:"text",required:!0,maxLength:80,default:"Brothers Game Night",size_var:"--flyer-title-size",help:"Set in caps by the template — type it normally."},{name:"art",label:"Artwork",type:"image",required:!1,maxLength:null,default:null,help:"Transparent PNG (an icon or illustration). Absorbs the leftover vertical space, so it is what keeps a short flyer balanced."},{name:"venue",label:"Venue",type:"text",required:!0,maxLength:90,default:null,help:'e.g. "Youth Center, Burlington Masjid".'},{name:"details",label:"When / how to join",type:"multiline",required:!0,maxLength:150,default:null,size_var:"--flyer-details-size",help:"Set in spaced caps. Three short lines read better than one long one."},{name:"cta",label:"Call to action (phone number)",type:"text",required:!1,maxLength:60,default:null,ask_always:!0,help:"Ask which number to use — the events/RSVP line is not the food-order line. Never infer one from past flyers."}],Dn={key:En,name:An,kind:Sn,archetype:Tn,version:$n,html:Cn,root_class:In,summary:jn,canvas:Ln,layout:Nn,palette:Bn,slots:qn},Mn="event-bulletin",Pn="Event — Bulletin",Rn="event",On="bulletin",Un=1,Vn="event-bulletin.html",Hn="flyer-bulletin",Wn="Dense schedule layout for multi-session programmes: solid header band, then a scannable time / title / speaker table.",Gn={width:1200,height:1600,aspect:"3:4"},Jn="flow",Yn={default:"cool-neutral",ground:"gradient",contrast_sample:.6,note:"Header band uses --flyer-field / --flyer-field-ink; the schedule body sits on the gradient and uses --flyer-ink."},Kn=[{name:"kicker",label:"Kicker",type:"text",required:!1,maxLength:60,default:null,help:'Spaced caps above the title, e.g. "Weekend Seminar".'},{name:"title",label:"Programme title",type:"text",required:!0,maxLength:80,default:null,size_var:"--flyer-title-size"},{name:"subtitle",label:"Dates / location",type:"multiline",required:!1,maxLength:140,default:null,help:"Said once here so it does not have to repeat in every row."},{name:"sessions",label:"Sessions",type:"list",required:!0,maxLength:null,default:null,maxItems:9,help:"Nine rows is the comfortable ceiling at these sizes. Past that, split the programme across two flyers rather than shrinking the type.",item_slots:[{name:"time",label:"Time",type:"text",required:!0,maxLength:22,default:null},{name:"title",label:"Session",type:"text",required:!0,maxLength:54,default:null},{name:"speaker",label:"Speaker",type:"text",required:!1,maxLength:48,default:null}]},{name:"note",label:"Footnote",type:"multiline",required:!1,maxLength:130,default:null,help:"Childcare, meals, registration — the small print."},{name:"cta",label:"Call to action (phone number)",type:"text",required:!1,maxLength:60,default:null,ask_always:!0,help:"Ask which number to use — the events/RSVP line is not the food-order line."}],Xn={key:Mn,name:Pn,kind:Rn,archetype:On,version:Un,html:Vn,root_class:Hn,summary:Wn,canvas:Gn,layout:Jn,palette:Yn,slots:Kn},Zn="event-invitation",Qn="Event — Invitation",ea="event",ta="invitation",na=1,aa="event-invitation.html",ra="flyer-invitation",oa="Centred and airy, for dinners and Eid. The only template that does not end in the black CTA bar — a black slab would undo the airiness.",la={width:1200,height:1600,aspect:"3:4"},ia="flow",sa={default:"cool-neutral",ground:"gradient",contrast_sample:.5,note:"All type is unboxed here, so --flyer-ink carries the whole flyer rather than one line of it."},da=[{name:"logo",label:"Masjid logo",type:"image",required:!1,maxLength:null,default:null,help:"Small, at the top. Use a dark version — the ground here is pale. Omit rather than stretch a low-resolution file."},{name:"eyebrow",label:"Invitation line",type:"text",required:!1,maxLength:60,default:"You are cordially invited",help:"Set in spaced caps. Keep it short — long text at this letter-spacing wraps badly."},{name:"arabic",label:"Arabic line",type:"text",required:!1,maxLength:60,default:null,help:"Optional. Rendered right-to-left in a system Naskh face; check the export before sending."},{name:"title",label:"Event title",type:"text",required:!0,maxLength:80,default:"Eid Dinner",size_var:"--flyer-title-size"},{name:"subtitle",label:"Subtitle",type:"text",required:!1,maxLength:140,default:null,help:"Who is hosting, or what the evening is for."},{name:"date",label:"Date",type:"text",required:!0,maxLength:44,default:null,help:'e.g. "Saturday, April 12th".'},{name:"time",label:"Time",type:"text",required:!0,maxLength:44,default:null,help:'e.g. "After Maghrib Prayer".'},{name:"venue",label:"Venue",type:"text",required:!0,maxLength:90,default:null},{name:"rsvp",label:"RSVP line",type:"multiline",required:!1,maxLength:120,default:null,help:"Deadline and how to reply."},{name:"contact",label:"Contact (phone number)",type:"text",required:!1,maxLength:60,default:null,ask_always:!0,help:"Ask which number to use — the events/RSVP line is not the food-order line. Sits on a ruled line here, not in a black bar."}],ca={key:Zn,name:Qn,kind:ea,archetype:ta,version:na,html:aa,root_class:ra,summary:oa,canvas:la,layout:ia,palette:sa,slots:da},ua="event-photo",ha="Event — Photo",fa="event",pa="photo",ma=1,ga="event-photo.html",ya="flyer-photo",va="Full-bleed photo — the masjid building or a group shot — with the title over a dark scrim at the foot.",ba={width:1200,height:1600,aspect:"3:4"},ka="flow",xa={default:"cool-neutral",ground:"photo",note:"Type is white over a fixed dark scrim, not over the palette. The scrim is the contrast guarantee here because a user-supplied photo cannot be checked ahead of time."},wa=[{name:"photo",label:"Background photo",type:"image",required:!0,maxLength:null,default:null,help:"Cover-cropped to 3:4. Supply at least 1200x1600 — anything smaller shows. Keep faces and the building out of the bottom third; the scrim darkens it."},{name:"logo",label:"Masjid logo",type:"image",required:!1,maxLength:null,default:null,help:"Top-left. Use a light or white version — it sits over the photo."},{name:"kicker",label:"Kicker",type:"text",required:!1,maxLength:60,default:null,help:'Spaced caps in the accent colour, e.g. "Community Open House".'},{name:"title",label:"Event title",type:"text",required:!0,maxLength:80,default:null,size_var:"--flyer-title-size"},{name:"subtitle",label:"Subtitle",type:"text",required:!1,maxLength:140,default:null},{name:"details",label:"When and where",type:"multiline",required:!0,maxLength:120,default:null,help:"Two lines: date and time, then venue."},{name:"cta",label:"Call to action (phone number)",type:"text",required:!1,maxLength:60,default:null,ask_always:!0,help:"Ask which number to use — the events/RSVP line is not the food-order line."}],Fa={key:ua,name:ha,kind:fa,archetype:pa,version:ma,html:ga,root_class:ya,summary:va,canvas:ba,layout:ka,palette:xa,slots:wa},_a="food",za="Food Sale",Ea="food",Aa=1,Sa="food.html",Ta="flyer-food",$a="The locked food-sale layout: eight stacked zones, three treatments, black CTA bar last. Reproduced from ~124 exemplars.",Ca={width:1200,height:1600,aspect:"3:4"},Ia={default:"cool-neutral",ground:"gradient",contrast_sample:.84,note:"Burlington's purple is the named palette 'burlington-purple' and is the default for masjid 1 only. Every other tenant gets a cool/neutral gradient derived from theme_settings."},ja=[{name:"title",top:4.1,bottom:15.7,treatment:"sticker"},{name:"ingredients",top:18.1,bottom:29.2,treatment:"sticker"},{name:"when",top:31,bottom:35.8,treatment:"pill"},{name:"photo",top:40.2,bottom:70.2,treatment:"image",elastic:!0},{name:"price",top:74.1,bottom:79.4,treatment:"sticker"},{name:"disclaimer",top:81.9,bottom:86.1,treatment:"plain"},{name:"deadline",top:87.7,bottom:92,treatment:"pill"},{name:"cta",top:93.1,bottom:97.8,treatment:"bar"}],La=[{name:"title",label:"Dish name",type:"text",required:!0,maxLength:80,default:"Fresh Homemade Biryani",zone:"title",treatment:"sticker",size_var:"--flyer-title-size",help:"Two lines at 107px. Longer names shrink to fit rather than wrap to three.",maxLengthNote:'longest real: "Fresh Homemade Spinach, Zatar Akhdar, Meat, and Cheese (Iqras) Pies!" = 68'},{name:"ingredients",label:"Ingredients",type:"text",required:!0,maxLength:180,default:"Rice, Chicken, Onions, Garlic and Spices",zone:"ingredients",treatment:"sticker",size_var:"--flyer-ingredients-size",help:"Comma-separated, no 'Ingredients:' prefix — the originals never label it.",maxLengthNote:"longest real: the Falafel line = 134; 7 of 9 sampled exceeded the old cap of 72"},{name:"when",label:"When it is served",type:"text",required:!0,maxLength:60,default:"Friday, After Juma'a Prayer",zone:"when",treatment:"pill",size_var:"--flyer-when-size",help:`Pickup day and moment, e.g. "Friday (June 5th), After Juma'a Prayer".`,maxLengthNote:`longest real: "Friday (February 28th), After Juma'a Prayer" = 42`},{name:"photo",label:"Dish photo",type:"image",required:!0,maxLength:null,default:null,zone:"photo",treatment:"image",help:"Cut out (transparent PNG) reads best against the gradient; a square crop also works. Contained, never cropped by the template."},{name:"price",label:"Price",type:"text",required:!0,maxLength:40,default:"$10 Each",zone:"price",treatment:"sticker",size_var:"--flyer-price-size",help:'Includes the unit — "$8 Each", "$25 Per Tray".',maxLengthNote:'longest real: "$7 for Meal + $1 for Dessert" = 28'},{name:"disclaimer",label:"Where the money goes",type:"multiline",required:!0,maxLength:200,default:null,zone:"disclaimer",treatment:"plain",size_var:"--flyer-disclaimer-size",ask_always:!0,help:"Deliberately different on every flyer. Ask the masjid each time; never carry the last one forward.",maxLengthNote:"longest real = 81; headroom for a named cause, e.g. Sudan relief"},{name:"deadline",label:"Order deadline",type:"text",required:!0,maxLength:70,default:"All orders must be placed by 11 am Friday",zone:"deadline",treatment:"pill",size_var:"--flyer-deadline-size",help:"The cut-off, not the pickup time — those are different lines on purpose.",maxLengthNote:'longest real: "All Orders must be placed by 11 PM Nov 14th, Thursday" = 52'},{name:"cta",label:"Call to action (phone number)",type:"text",required:!0,maxLength:60,default:null,zone:"cta",treatment:"bar",size_var:"--flyer-cta-size",ask_always:!0,help:"Ask which number to use — the food-order line and the events/RSVP line are not interchangeable. Never infer one from past flyers.",maxLengthNote:'longest real: "Text 336-350-1642 to reserve yours today!" = 41'}],Na={key:_a,name:za,kind:Ea,version:Aa,html:Sa,root_class:Ta,summary:$a,canvas:Ca,palette:Ia,zones:ja,slots:La},Ba=1,qa={family:"Montserrat",weights:[400,500,800,900],self_hosted:!0,note:"Templates never @import or link a font; the app shell loads it once. resources/views/vue-app-index.blade.php serves public/fonts/Montserrat-variable.ttf as a single 100-900 variable face, so all four weights come from one first-party request — self-hosted because the PNG export must not depend on a font CDN. Without that face everything falls back to Helvetica/Arial, which changes the look."},Da={width:1200,height:1600,aspect:"3:4"},Ma={resolver:"palette.js",named:"palettes.json",default:"cool-neutral",vars:["--flyer-grad-0","--flyer-grad-1","--flyer-grad-2","--flyer-grad-3","--flyer-ink","--flyer-field","--flyer-field-ink","--flyer-ground","--flyer-ground-ink","--flyer-accent","--flyer-pill-bg","--flyer-pill-ink","--flyer-bar-bg","--flyer-bar-ink"]},Pa=[{key:"food",name:"Food Sale",kind:"food",manifest:"food.json",html:"food.html",measured:!0,summary:"The locked layout, reproduced from ~124 exemplars. Eight pinned zones, three treatments, black CTA bar last."},{key:"event-banner",name:"Event — Banner",kind:"event",archetype:"banner",manifest:"event-banner.json",html:"event-banner.html",measured:!1,summary:"Big title over a flat colour field with optional cut-out artwork."},{key:"event-invitation",name:"Event — Invitation",kind:"event",archetype:"invitation",manifest:"event-invitation.json",html:"event-invitation.html",measured:!1,summary:"Centred and airy, for dinners and Eid."},{key:"event-bulletin",name:"Event — Bulletin",kind:"event",archetype:"bulletin",manifest:"event-bulletin.json",html:"event-bulletin.html",measured:!1,summary:"Dense schedule for multi-session programmes."},{key:"event-photo",name:"Event — Photo",kind:"event",archetype:"photo",manifest:"event-photo.json",html:"event-photo.html",measured:!1,summary:"Full-bleed photo with the title over a dark scrim."},{key:"janazah",name:"Janazah Announcement",kind:"janazah",manifest:"janazah.json",html:"janazah.html",measured:!1,summary:"Plain dark ground, no photo, no gradient, no CTA bar. Derived from constants — the corpus had no example."}],Ra={version:Ba,font:qa,canvas_default:Da,palette:Ma,templates:Pa},Oa="janazah",Ua="Janazah Announcement",Va="janazah",Ha=1,Wa="janazah.html",Ga="flyer-janazah",Ja="Plain dark ground, no photo, no gradient, none of the food treatments. Name of the deceased, salah details, burial, and the tarji' line.",Ya={width:1200,height:1600,aspect:"3:4"},Ka="flow",Xa={default:"janazah-night",ground:"solid",note:"Reads --flyer-ground / --flyer-ground-ink only. A tenant's derived ground is its brand hue desaturated to near-black, so the notice stays sombre whatever the brand is."},Za=["No photograph of the deceased, ever.","No gradient, no illustration, no accent-coloured decoration.","None of the three food treatments — no sticker outline, no white pill, no black CTA bar.","No phone number in a bar. If a contact is genuinely needed it belongs in the note, quietly."],Qa=[{name:"arabic",label:"Tarji' (Arabic)",type:"text",required:!0,maxLength:60,default:"إِنَّا لِلَّٰهِ وَإِنَّا إِلَيْهِ رَاجِعُونَ",help:"Rendered right-to-left in a system Naskh face. Check the exported image before sending — shaping depends on the device's fonts."},{name:"translit",label:"Tarji' (transliteration)",type:"text",required:!0,maxLength:90,default:"Inna lillahi wa inna ilayhi raji'un",help:"Kept even when the Arabic renders correctly — it is what survives a font fallback."},{name:"intro",label:"Opening line",type:"multiline",required:!0,maxLength:120,default:"It is with great sadness that we announce the passing of",help:"Confirm the wording with the family before sending."},{name:"name",label:"Name of the deceased",type:"text",required:!0,maxLength:80,default:null,size_var:"--flyer-name-size",ask_always:!0,help:"Spell it exactly as the family gives it. Never carry a name forward from a previous flyer."},{name:"lifespan",label:"Years",type:"text",required:!1,maxLength:30,default:null,help:'Optional, e.g. "1948 — 2026". Omit unless the family asked for it.'},{name:"janazah_time",label:"Salatul Janazah — time",type:"text",required:!0,maxLength:60,default:null,help:'e.g. "Tuesday, after Dhuhr Prayer".'},{name:"janazah_masjid",label:"Salatul Janazah — masjid",type:"text",required:!0,maxLength:90,default:null},{name:"burial_location",label:"Burial — location",type:"text",required:!0,maxLength:90,default:null,help:"Cemetery name; add the city if it is not the masjid's own."},{name:"burial_time",label:"Burial — time",type:"text",required:!1,maxLength:60,default:null,help:"Only when it is known. An estimate that turns out wrong is worse than no line."},{name:"note",label:"Closing note",type:"multiline",required:!1,maxLength:140,default:"Please keep the family in your du'a.",help:"Also where a family contact goes, if they want one shared."},{name:"logo",label:"Masjid logo",type:"image",required:!1,maxLength:null,default:null,help:"Small, at the foot. Identification, not branding."}],er={key:Oa,name:Ua,kind:Va,version:Ha,html:Wa,root_class:Ga,summary:Ja,canvas:Ya,layout:Ka,palette:Xa,constraints:Za,slots:Qa},tr=1,nr="Named palettes a masjid can pick instead of the one derived from its theme_settings. Colours here are inputs to palette.js resolvePalette(), which re-checks every ink against WCAG AA before it reaches a template — a hand-added palette cannot ship an unreadable disclaimer.",ar={"burlington-purple":{key:"burlington-purple",name:"Burlington Purple",restricted_to_masjid:1,note:"Sampled down the centre of 19 exemplars, agreeing to within a couple of values. This is Burlington's brand, not the product's — it is the default for masjid 1 and is not offered to other tenants.",grad:["#A18CCF","#C5A7CB","#E0BCC8","#EDD2D1"],field:"#4B3B7A",ground:"#171326",accent:"#7A5FBF",ink:"#14181F",fieldInk:"#FFFFFF",groundInk:"#F2F4F7",pillBg:"#FFFFFF",pillInk:"#000000",barBg:"#000000",barInk:"#FFFFFF"},"cool-neutral":{key:"cool-neutral",name:"Cool Neutral",note:"The product default: a blue-grey ground that flatters a photographed dish without claiming a brand.",grad:["#C7D2DE","#D6DDE6","#E6E7EC","#F1F0F2"],field:"#2E4258",ground:"#12161C",accent:"#7A8CA3",ink:"#14181F",fieldInk:"#FFFFFF",groundInk:"#F2F4F7",pillBg:"#FFFFFF",pillInk:"#000000",barBg:"#000000",barInk:"#FFFFFF"},"sage-mist":{key:"sage-mist",name:"Sage Mist",note:"Cool green. The nearest neutral to a masjid whose brand is already green.",grad:["#B9CBBE","#CBD8CD","#DFE6DE","#EEF1EC"],field:"#294237",ground:"#111713",accent:"#6E8C79",ink:"#14181F",fieldInk:"#FFFFFF",groundInk:"#F2F4F7",pillBg:"#FFFFFF",pillInk:"#000000",barBg:"#000000",barInk:"#FFFFFF"},"slate-dawn":{key:"slate-dawn",name:"Slate Dawn",note:"Coldest of the set. Highest contrast under the disclaimer, so it is the safe pick when a flyer runs long.",grad:["#AFBAC9","#C6CEDA","#DDE2E9","#EEF0F4"],field:"#26313F",ground:"#0F1319",accent:"#5E7185",ink:"#12161C",fieldInk:"#FFFFFF",groundInk:"#F2F4F7",pillBg:"#FFFFFF",pillInk:"#000000",barBg:"#000000",barInk:"#FFFFFF"},"sand-warm":{key:"sand-warm",name:"Warm Sand",note:"The one warm option. Offered because food photography often reads better on it — pick deliberately, it is not the default.",grad:["#DAC7AE","#E4D5C0","#EEE4D6","#F5EFE6"],field:"#4A3826",ground:"#1A1410",accent:"#A9866C",ink:"#1A1510",fieldInk:"#FFFFFF",groundInk:"#F5F1EA",pillBg:"#FFFFFF",pillInk:"#000000",barBg:"#000000",barInk:"#FFFFFF"},"janazah-night":{key:"janazah-night",name:"Janazah Night",note:"Solid, no gradient. The janazah template reads --flyer-ground only; the grad stops are present so the same palette object shape works everywhere.",grad:["#1B2129","#1B2129","#1B2129","#1B2129"],field:"#1B2129",ground:"#131820",accent:"#4E5C6B",ink:"#EEF1F5",fieldInk:"#FFFFFF",groundInk:"#EEF1F5",pillBg:"#FFFFFF",pillInk:"#000000",barBg:"#000000",barInk:"#FFFFFF"}},rr={version:tr,default:"cool-neutral",notes:nr,palettes:ar},or=`<!--
  Event archetype: BANNER — a big title over a flat colour field, with optional
  cut-out artwork.

  Unlike food.html this is not a measured template: the ~107 event flyers in the
  corpus share no grid or type scale, so nothing here can claim to be sampled.
  What it does inherit are the enforced constants — Montserrat, the palette
  contract, and the black CTA bar carrying the phone number last. The layout is
  a flow column rather than pinned bands precisely because there is no measured
  truth to pin it to; content of varying length must not break it.
-->
<style>
.flyer-banner,
.flyer-banner *,
.flyer-banner *::before,
.flyer-banner *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

.flyer-banner {
    position: relative;
    display: flex;
    flex-direction: column;
    width: 1200px;
    height: 1600px;
    padding: 5.5% 6%;
    overflow: hidden;
    font-family: 'Montserrat', 'Helvetica Neue', Arial, sans-serif;
    font-kerning: normal;
    text-rendering: geometricPrecision;
    background: var(--flyer-field, #2E4258);
    color: var(--flyer-field-ink, #FFFFFF);
}

/* The corpus banners nearly all carry some low-contrast surface texture. A 3%
   diagonal wash gives the flat field the same depth without importing an image. */
.flyer-banner::before {
    content: '';
    position: absolute;
    inset: 0;
    background: repeating-linear-gradient(45deg,
            rgba(255, 255, 255, 0.03) 0 6px,
            rgba(255, 255, 255, 0) 6px 18px);
    pointer-events: none;
}

.flyer-banner > * {
    position: relative; /* above the texture */
}

.flyer-banner .rule {
    flex: 0 0 auto;
    height: 9px;
    border-radius: 2px;
    background: var(--flyer-accent, #7A8CA3);
}

.flyer-banner .eyebrow {
    margin-top: 5%;
    font-size: var(--flyer-eyebrow-size, 40px);
    line-height: 1.32;
    font-weight: 500;
    text-align: center;
}

.flyer-banner .title {
    margin-top: 5%;
    font-size: var(--flyer-title-size, 124px);
    line-height: 0.94;
    font-weight: 900;
    letter-spacing: -0.02em;
    text-transform: uppercase;
}

/* The artwork absorbs whatever vertical slack the text leaves, so a two-line
   and a four-line title both produce a balanced flyer. */
.flyer-banner .art {
    flex: 1 1 auto;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    min-height: 0;
    padding: 3% 0;
}

.flyer-banner .art img {
    display: block;
    max-width: 78%;
    max-height: 100%;
    object-fit: contain;
}

/* 800, not 700: index.json declares the face at 400/500/800/900 and the venue is
   the same secondary-emphasis line the other archetypes set in 800. */
.flyer-banner .venue {
    font-size: var(--flyer-venue-size, 48px);
    line-height: 1.24;
    font-weight: 800;
}

.flyer-banner .details {
    margin-top: 4%;
    font-size: var(--flyer-details-size, 38px);
    line-height: 1.7;
    font-weight: 500;
    letter-spacing: 0.20em;
    text-transform: uppercase;
}

.flyer-banner .foot {
    margin-top: auto;
    padding-top: 5%;
}

/* Same CTA treatment as the food template: white on solid black, weight 500,
   the phone number, last. It is the one element every flyer kind shares. */
.flyer-banner .cta {
    display: inline-block;
    max-width: 100%;
    margin-top: 4%;
    padding: 0.16em 0.34em;
    border-radius: 4px;
    font-size: var(--flyer-cta-size, 44px);
    line-height: 1.16;
    font-weight: 500;
    background: var(--flyer-bar-bg, #000000);
    color: var(--flyer-bar-ink, #FFFFFF);
}
</style>

<div class="flyer flyer-banner" data-template="event-banner" data-canvas="1200x1600">
    <div class="rule"></div>

    <p class="eyebrow" data-slot="eyebrow" data-optional="true">{{ eyebrow }}</p>

    <h1 class="title" data-slot="title">{{ title }}</h1>

    <div class="art" data-slot="art" data-optional="true">
        <img src="{{ art }}" alt="">
    </div>

    <p class="venue" data-slot="venue">{{ venue }}</p>

    <p class="details" data-slot="details">{{ details }}</p>

    <div class="foot">
        <div class="rule"></div>
        <p class="cta" data-slot="cta" data-optional="true">{{ cta }}</p>
    </div>
</div>
`,lr=`<!--
  Event archetype: BULLETIN — dense, for schedules and multi-session programmes
  (weekend seminars, Ramadan night-by-night, class timetables).

  Not measured. Its job is legibility at volume: a solid header band that states
  what and when once, then a scannable time / title / speaker table underneath.
  Type is smaller here than in any other template on purpose — a bulletin that
  shouts has no room left for the schedule.

  The session rows repeat. The element marked data-repeat="sessions" is a
  prototype: the renderer clones it once per item and substitutes the
  {{ sessions.* }} placeholders inside. See README.md.
-->
<style>
.flyer-bulletin,
.flyer-bulletin *,
.flyer-bulletin *::before,
.flyer-bulletin *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

.flyer-bulletin {
    position: relative;
    display: flex;
    flex-direction: column;
    width: 1200px;
    height: 1600px;
    overflow: hidden;
    font-family: 'Montserrat', 'Helvetica Neue', Arial, sans-serif;
    font-kerning: normal;
    text-rendering: geometricPrecision;
    color: var(--flyer-ink, #14181F);
    background: linear-gradient(180deg,
            var(--flyer-grad-0, #C7D2DE) 0%,
            var(--flyer-grad-1, #D6DDE6) 50%,
            var(--flyer-grad-2, #E6E7EC) 80%,
            var(--flyer-grad-3, #F1F0F2) 100%);
}

.flyer-bulletin .header {
    flex: 0 0 auto;
    padding: 6% 6% 5%;
    background: var(--flyer-field, #2E4258);
    color: var(--flyer-field-ink, #FFFFFF);
}

.flyer-bulletin .header .kicker {
    font-size: var(--flyer-kicker-size, 30px);
    line-height: 1.3;
    font-weight: 500;
    letter-spacing: 0.26em;
    text-transform: uppercase;
    opacity: 0.85;
}

.flyer-bulletin .header .title {
    margin-top: 2.5%;
    font-size: var(--flyer-title-size, 82px);
    line-height: 1.0;
    font-weight: 900;
    letter-spacing: -0.015em;
}

.flyer-bulletin .header .subtitle {
    margin-top: 2.5%;
    font-size: var(--flyer-subtitle-size, 36px);
    line-height: 1.3;
    font-weight: 500;
}

.flyer-bulletin .schedule {
    flex: 1 1 auto;
    min-height: 0;
    padding: 4% 6%;
}

.flyer-bulletin .session {
    display: grid;
    grid-template-columns: 300px 1fr;
    align-items: baseline;
    column-gap: 32px;
    padding: 22px 20px;
    border-radius: 8px;
}

/* Zebra tint rather than rules: at eight-plus rows, lines start to read as a
   grid the eye has to climb over. */
.flyer-bulletin .session:nth-child(odd) {
    background: rgba(0, 0, 0, 0.045);
}

.flyer-bulletin .session .time {
    font-size: var(--flyer-time-size, 34px);
    line-height: 1.2;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.flyer-bulletin .session .what {
    font-size: var(--flyer-session-size, 36px);
    line-height: 1.18;
    font-weight: 900;
}

.flyer-bulletin .session .who {
    margin-top: 6px;
    font-size: var(--flyer-speaker-size, 28px);
    line-height: 1.25;
    font-weight: 500;
    opacity: 0.75;
}

.flyer-bulletin .foot {
    flex: 0 0 auto;
    padding: 0 6% 6%;
}

.flyer-bulletin .note {
    font-size: var(--flyer-note-size, 28px);
    line-height: 1.3;
    font-weight: 400;
}

.flyer-bulletin .cta {
    display: inline-block;
    max-width: 100%;
    margin-top: 4%;
    padding: 0.16em 0.34em;
    border-radius: 4px;
    font-size: var(--flyer-cta-size, 40px);
    line-height: 1.16;
    font-weight: 500;
    background: var(--flyer-bar-bg, #000000);
    color: var(--flyer-bar-ink, #FFFFFF);
}
</style>

<div class="flyer flyer-bulletin" data-template="event-bulletin" data-canvas="1200x1600">
    <div class="header">
        <p class="kicker" data-slot="kicker" data-optional="true">{{ kicker }}</p>
        <h1 class="title" data-slot="title">{{ title }}</h1>
        <p class="subtitle" data-slot="subtitle" data-optional="true">{{ subtitle }}</p>
    </div>

    <div class="schedule">
        <div class="session" data-repeat="sessions">
            <p class="time" data-slot="sessions.time">{{ sessions.time }}</p>
            <div>
                <p class="what" data-slot="sessions.title">{{ sessions.title }}</p>
                <p class="who" data-slot="sessions.speaker" data-optional="true">{{ sessions.speaker }}</p>
            </div>
        </div>
    </div>

    <div class="foot">
        <p class="note" data-slot="note" data-optional="true">{{ note }}</p>
        <p class="cta" data-slot="cta" data-optional="true">{{ cta }}</p>
    </div>
</div>
`,ir=`<!--
  Event archetype: INVITATION — centred and airy, for dinners, Eid and anything
  where the flyer is asking rather than announcing.

  Not measured (no invitation template exists in the corpus). The rule it plays
  by is restraint: one column, everything centred, generous space, and hairlines
  instead of filled boxes. This is the one archetype that does NOT end in the
  black CTA bar — a solid black slab undoes the airiness the whole layout is
  for, so the contact sits on a ruled line instead.
-->
<style>
.flyer-invitation,
.flyer-invitation *,
.flyer-invitation *::before,
.flyer-invitation *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

.flyer-invitation {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 1200px;
    height: 1600px;
    padding: 9% 9%;
    overflow: hidden;
    text-align: center;
    font-family: 'Montserrat', 'Helvetica Neue', Arial, sans-serif;
    font-kerning: normal;
    text-rendering: geometricPrecision;
    color: var(--flyer-ink, #14181F);
    background: linear-gradient(180deg,
            var(--flyer-grad-0, #C7D2DE) 0%,
            var(--flyer-grad-1, #D6DDE6) 50%,
            var(--flyer-grad-2, #E6E7EC) 80%,
            var(--flyer-grad-3, #F1F0F2) 100%);
}

/* A hairline frame, inset. It reads as an invitation card without adding a
   single filled shape. */
.flyer-invitation::before {
    content: '';
    position: absolute;
    inset: 4.5%;
    border: 2px solid var(--flyer-accent, #7A8CA3);
    opacity: 0.55;
    pointer-events: none;
}

.flyer-invitation > * {
    position: relative;
    max-width: 100%;
}

.flyer-invitation .logo {
    height: 96px;
    margin-bottom: 6%;
    object-fit: contain;
}

.flyer-invitation .eyebrow {
    font-size: var(--flyer-eyebrow-size, 32px);
    line-height: 1.4;
    font-weight: 500;
    letter-spacing: 0.30em;
    text-transform: uppercase;
    text-indent: 0.30em; /* letter-spacing pads the right edge; re-centre it */
}

.flyer-invitation .arabic {
    margin-top: 5%;
    font-family: 'Geeza Pro', 'Noto Naskh Arabic', 'Segoe UI', 'Times New Roman', serif;
    font-size: var(--flyer-arabic-size, 54px);
    line-height: 1.6;
    direction: rtl;
    unicode-bidi: isolate;
}

.flyer-invitation .title {
    margin-top: 5%;
    font-size: var(--flyer-title-size, 96px);
    line-height: 1.02;
    font-weight: 900;
    letter-spacing: -0.015em;
}

.flyer-invitation .subtitle {
    margin-top: 4%;
    font-size: var(--flyer-subtitle-size, 40px);
    line-height: 1.34;
    font-weight: 500;
}

.flyer-invitation .hairline {
    width: 22%;
    height: 2px;
    margin: 7% 0;
    background: var(--flyer-accent, #7A8CA3);
    opacity: 0.7;
}

.flyer-invitation .meta {
    display: flex;
    flex-direction: column;
    gap: 26px;
    font-size: var(--flyer-meta-size, 46px);
    line-height: 1.18;
    font-weight: 800;
}

.flyer-invitation .rsvp {
    margin-top: 7%;
    font-size: var(--flyer-rsvp-size, 34px);
    line-height: 1.4;
    font-weight: 500;
}

/* Contact on a ruled line, not the black bar — see the file header. */
.flyer-invitation .contact {
    margin-top: 5%;
    padding-top: 3%;
    border-top: 2px solid var(--flyer-accent, #7A8CA3);
    font-size: var(--flyer-contact-size, 38px);
    line-height: 1.3;
    font-weight: 800;
}
</style>

<div class="flyer flyer-invitation" data-template="event-invitation" data-canvas="1200x1600">
    <img class="logo" data-slot="logo" data-optional="true" src="{{ logo }}" alt="">

    <p class="eyebrow" data-slot="eyebrow" data-optional="true">{{ eyebrow }}</p>

    <p class="arabic" data-slot="arabic" data-optional="true">{{ arabic }}</p>

    <h1 class="title" data-slot="title">{{ title }}</h1>

    <p class="subtitle" data-slot="subtitle" data-optional="true">{{ subtitle }}</p>

    <div class="hairline"></div>

    <div class="meta">
        <p data-slot="date">{{ date }}</p>
        <p data-slot="time">{{ time }}</p>
        <p data-slot="venue">{{ venue }}</p>
    </div>

    <p class="rsvp" data-slot="rsvp" data-optional="true">{{ rsvp }}</p>

    <p class="contact" data-slot="contact" data-optional="true">{{ contact }}</p>
</div>
`,sr=`<!--
  Event archetype: PHOTO — the masjid building or a group shot behind the title.

  Not measured. The load-bearing part is the scrim: the photo is user-supplied
  and can be any brightness, so white type over it can never be checked the way
  palette.js checks ink against a known ground. A fixed dark gradient under the
  text block is what makes the type legible on a bright sky as well as a dim
  interior — it is the contrast guarantee for this archetype, not decoration.
-->
<style>
.flyer-photo,
.flyer-photo *,
.flyer-photo *::before,
.flyer-photo *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

.flyer-photo {
    position: relative;
    width: 1200px;
    height: 1600px;
    overflow: hidden;
    font-family: 'Montserrat', 'Helvetica Neue', Arial, sans-serif;
    font-kerning: normal;
    text-rendering: geometricPrecision;
    background: var(--flyer-field, #2E4258);
    color: #FFFFFF;
}

.flyer-photo .backdrop {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.flyer-photo .scrim {
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg,
            rgba(0, 0, 0, 0.42) 0%,
            rgba(0, 0, 0, 0.10) 26%,
            rgba(0, 0, 0, 0.10) 40%,
            rgba(0, 0, 0, 0.72) 72%,
            rgba(0, 0, 0, 0.92) 100%);
}

.flyer-photo .content {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    padding: 6%;
}

.flyer-photo .logo {
    height: 84px;
    align-self: flex-start;
    object-fit: contain;
}

.flyer-photo .block {
    margin-top: auto;
}

.flyer-photo .kicker {
    font-size: var(--flyer-kicker-size, 32px);
    line-height: 1.3;
    font-weight: 500;
    letter-spacing: 0.26em;
    text-transform: uppercase;
    color: var(--flyer-accent, #7A8CA3);
    /* The accent is brand-derived and may be mid-tone; a hard shadow keeps it
       readable over the photo without forcing it to white. */
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.65);
}

.flyer-photo .title {
    margin-top: 2.5%;
    font-size: var(--flyer-title-size, 104px);
    line-height: 0.98;
    font-weight: 900;
    letter-spacing: -0.02em;
    text-shadow: 0 4px 24px rgba(0, 0, 0, 0.55);
}

.flyer-photo .subtitle {
    margin-top: 3%;
    font-size: var(--flyer-subtitle-size, 40px);
    line-height: 1.3;
    font-weight: 500;
    text-shadow: 0 2px 14px rgba(0, 0, 0, 0.55);
}

.flyer-photo .details {
    margin-top: 4%;
    font-size: var(--flyer-details-size, 42px);
    line-height: 1.45;
    font-weight: 800;
    text-shadow: 0 2px 14px rgba(0, 0, 0, 0.55);
}

.flyer-photo .cta {
    display: inline-block;
    max-width: 100%;
    margin-top: 5%;
    padding: 0.16em 0.34em;
    border-radius: 4px;
    font-size: var(--flyer-cta-size, 42px);
    line-height: 1.16;
    font-weight: 500;
    background: var(--flyer-bar-bg, #000000);
    color: var(--flyer-bar-ink, #FFFFFF);
    text-shadow: none;
}
</style>

<div class="flyer flyer-photo" data-template="event-photo" data-canvas="1200x1600">
    <img class="backdrop" data-slot="photo" src="{{ photo }}" alt="">
    <div class="scrim"></div>

    <div class="content">
        <img class="logo" data-slot="logo" data-optional="true" src="{{ logo }}" alt="">

        <div class="block">
            <p class="kicker" data-slot="kicker" data-optional="true">{{ kicker }}</p>
            <h1 class="title" data-slot="title">{{ title }}</h1>
            <p class="subtitle" data-slot="subtitle" data-optional="true">{{ subtitle }}</p>
            <p class="details" data-slot="details">{{ details }}</p>
            <p class="cta" data-slot="cta" data-optional="true">{{ cta }}</p>
        </div>
    </div>
</div>
`,dr=`<!--
  Food sale flyer — the one genuinely locked layout in the corpus (~124 of the
  231 Burlington flyers reuse it). Zone bands, type sizes and the three
  treatments below are MEASURED off those exemplars, not chosen; changing a
  number stops the output looking like the masjid's own flyers.

  Rendered client-side: no template engine, no @import, no remote asset. The
  host substitutes the slot placeholders below and supplies Montserrat
  400/500/800/900 itself.
-->
<style>
.flyer-food,
.flyer-food *,
.flyer-food *::before,
.flyer-food *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

.flyer-food {
    position: relative;
    width: 1200px;
    height: 1600px;
    overflow: hidden;
    font-family: 'Montserrat', 'Helvetica Neue', Arial, sans-serif;
    font-kerning: normal;
    text-rendering: geometricPrecision;
    color: var(--flyer-ink, #14181F);

    /* The palette arrives as custom properties from the host (see palette.js).
       They are consumed as var() fallbacks and never re-declared here, so a
       value set on ANY ancestor still wins. The built-in fallback is the
       neutral/cool default — Burlington's purple is a named palette, not a
       hard-coded ground, because tenant 1's brand is not every tenant's. */
    background: linear-gradient(180deg,
            var(--flyer-grad-0, #C7D2DE) 0%,
            var(--flyer-grad-1, #D6DDE6) 50%,
            var(--flyer-grad-2, #E6E7EC) 80%,
            var(--flyer-grad-3, #F1F0F2) 100%);
}

/* Each zone is an absolutely positioned band, top/height as a % of canvas
   height exactly as measured. The bands are ink extents, not hard boxes: text
   centres inside its band and is allowed to bleed into the gap above/below
   rather than reflow, which is how the originals behave. */
.flyer-food .zone {
    position: absolute;
    left: 4%;
    right: 4%;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.flyer-food .zone-title       { top: 4.1%;  height: 11.6%; }
.flyer-food .zone-ingredients { top: 18.1%; height: 11.1%; }
.flyer-food .zone-when        { top: 31.0%; height: 4.8%; }
.flyer-food .zone-price       { top: 74.1%; height: 5.3%; }
.flyer-food .zone-disclaimer  { top: 81.9%; height: 4.2%; }
.flyer-food .zone-deadline    { top: 87.7%; height: 4.3%; }
.flyer-food .zone-cta         { top: 93.1%; height: 4.7%; }

/* The photo is the one elastic band: pinned top and bottom, so when a short
   dish name leaves slack above, LOWERING --flyer-photo-top hands that slack to
   the photo. The measured 40.2% is the floor for a full-height two-line title. */
.flyer-food .zone-photo {
    top: var(--flyer-photo-top, 40.2%);
    bottom: 29.8%;
}

.flyer-food .photo {
    display: block;
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

/* ---- Treatment 1: sticker ------------------------------------------------
   Black fill with a white outline painted BEHIND the fill, plus a soft
   down-right shadow. Title, ingredients and price only — never a pill, never
   the bar. The stroke is in em so it stays proportional when a long line is
   shrunk to fit. */
.flyer-food .sticker {
    font-weight: 900;
    letter-spacing: -0.015em;
    color: var(--flyer-sticker-ink, #000000);
    -webkit-text-stroke: 0.15em var(--flyer-sticker-outline, #FFFFFF);
    paint-order: stroke fill; /* without this the stroke closes up the counters */
    text-shadow: 0.035em 0.05em 0.06em rgba(0, 0, 0, 0.28);
}

/* ---- Treatment 2: pill ---------------------------------------------------
   Bold black on a white rounded rect that hugs its text. Carries the two
   operational lines (when, deadline). Text in a pill is NEVER stroked — the
   reset below is deliberate, not defensive noise. */
.flyer-food .pill {
    display: inline-block;
    max-width: 100%;
    padding: 0.14em 0.30em;
    border-radius: 10px;
    font-weight: 800;
    background: var(--flyer-pill-bg, #FFFFFF);
    color: var(--flyer-pill-ink, #000000);
    -webkit-text-stroke: 0;
    text-shadow: none;
}

/* ---- Treatment 3: CTA bar ------------------------------------------------
   White on solid black. Always the phone number, always the last thing on the
   flyer. Lighter weight than the pills and near-square corners, which is what
   separates it from them at a glance. */
.flyer-food .cta {
    display: inline-block;
    max-width: 100%;
    padding: 0.16em 0.34em;
    border-radius: 4px;
    font-weight: 500;
    background: var(--flyer-bar-bg, #000000);
    color: var(--flyer-bar-ink, #FFFFFF);
    -webkit-text-stroke: 0;
    text-shadow: none;
}

/* Type scale at the 1200x1600 reference. Each size is a var so a shrink-to-fit
   pass can step a long line down without forking the stylesheet. */
.flyer-food .t-title       { font-size: var(--flyer-title-size, 107px); line-height: 0.92; }
.flyer-food .t-ingredients { font-size: var(--flyer-ingredients-size, 52px); line-height: 1.20; }
.flyer-food .t-when        { font-size: var(--flyer-when-size, 46px); line-height: 1.16; }
.flyer-food .t-price       { font-size: var(--flyer-price-size, 86px); line-height: 1.00; }
.flyer-food .t-deadline    { font-size: var(--flyer-deadline-size, 39px); line-height: 1.16; }
.flyer-food .t-cta         { font-size: var(--flyer-cta-size, 46px); line-height: 1.16; }

/* The disclaimer is the deliberate exception to all three treatments: no box,
   no outline, weight 400. It is therefore the ONLY line whose legibility
   depends on the ground, which is why palette.js gates --flyer-ink against the
   gradient sampled at this band. */
.flyer-food .t-disclaimer {
    font-size: var(--flyer-disclaimer-size, 33px);
    line-height: 1.26;
    font-weight: 400;
    color: var(--flyer-ink, #14181F);
}
</style>

<div class="flyer flyer-food" data-template="food" data-canvas="1200x1600">
    <div class="zone zone-title">
        <h1 class="sticker t-title" data-slot="title">{{ title }}</h1>
    </div>

    <div class="zone zone-ingredients">
        <p class="sticker t-ingredients" data-slot="ingredients">{{ ingredients }}</p>
    </div>

    <div class="zone zone-when">
        <p class="pill t-when" data-slot="when">{{ when }}</p>
    </div>

    <div class="zone zone-photo">
        <img class="photo" data-slot="photo" src="{{ photo }}" alt="">
    </div>

    <div class="zone zone-price">
        <p class="sticker t-price" data-slot="price">{{ price }}</p>
    </div>

    <div class="zone zone-disclaimer">
        <p class="t-disclaimer" data-slot="disclaimer">{{ disclaimer }}</p>
    </div>

    <div class="zone zone-deadline">
        <p class="pill t-deadline" data-slot="deadline">{{ deadline }}</p>
    </div>

    <div class="zone zone-cta">
        <p class="cta t-cta" data-slot="cta">{{ cta }}</p>
    </div>
</div>
`,cr=`<!--
  Janazah announcement.

  Nothing in the corpus to copy — there were zero examples, so this is derived
  from constants rather than extrapolated, and every choice is a subtraction.
  Plain dark ground: no gradient, no photograph, no illustration, no accent
  colour used as decoration. None of the three food treatments appear: no
  sticker outline, no white pill, no black CTA bar. A janazah notice is not
  advertising and must never look like the food flyer that shipped last Friday.

  Sizes are restrained on purpose. The name of the deceased is the largest thing
  on the page and nothing competes with it.
-->
<style>
.flyer-janazah,
.flyer-janazah *,
.flyer-janazah *::before,
.flyer-janazah *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

.flyer-janazah {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 1200px;
    height: 1600px;
    padding: 9% 8% 6%;
    overflow: hidden;
    text-align: center;
    font-family: 'Montserrat', 'Helvetica Neue', Arial, sans-serif;
    font-kerning: normal;
    text-rendering: geometricPrecision;
    background: var(--flyer-ground, #12161C);
    color: var(--flyer-ground-ink, #F2F4F7);
}

/* The notice centres in the space above the logo rather than stacking from the
   top, so a short announcement and a long one are both composed rather than
   trailing off into empty ground. */
.flyer-janazah .body {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: 0;
}

.flyer-janazah .body > * {
    max-width: 100%;
}

/* Arabic falls outside Montserrat's coverage, so it resolves to a system
   Naskh face. If the export path cannot shape Arabic, render the
   transliteration alone rather than shipping broken glyphs. */
.flyer-janazah .arabic {
    font-family: 'Geeza Pro', 'Noto Naskh Arabic', 'Segoe UI', 'Times New Roman', serif;
    font-size: var(--flyer-arabic-size, 56px);
    line-height: 1.7;
    direction: rtl;
    unicode-bidi: isolate;
    opacity: 0.92;
}

.flyer-janazah .translit {
    margin-top: 2.5%;
    font-size: var(--flyer-translit-size, 30px);
    line-height: 1.4;
    font-weight: 400;
    letter-spacing: 0.06em;
    opacity: 0.72;
}

.flyer-janazah .intro {
    margin-top: 9%;
    font-size: var(--flyer-intro-size, 34px);
    line-height: 1.45;
    font-weight: 400;
    opacity: 0.82;
}

.flyer-janazah .name {
    margin-top: 3.5%;
    font-size: var(--flyer-name-size, 84px);
    line-height: 1.06;
    font-weight: 900;
    letter-spacing: -0.01em;
}

.flyer-janazah .lifespan {
    margin-top: 2.5%;
    font-size: var(--flyer-lifespan-size, 32px);
    line-height: 1.3;
    font-weight: 400;
    opacity: 0.72;
}

.flyer-janazah .hairline {
    width: 18%;
    height: 1px;
    margin: 8% 0;
    background: currentColor;
    opacity: 0.35;
}

.flyer-janazah .details {
    display: flex;
    flex-direction: column;
    gap: 56px;
    width: 100%;
}

.flyer-janazah .label {
    font-size: var(--flyer-label-size, 26px);
    line-height: 1.3;
    font-weight: 500;
    letter-spacing: 0.30em;
    text-transform: uppercase;
    text-indent: 0.30em; /* letter-spacing pads the right edge; re-centre it */
    opacity: 0.62;
}

.flyer-janazah .value {
    margin-top: 14px;
    font-size: var(--flyer-value-size, 42px);
    line-height: 1.3;
    font-weight: 800;
}

.flyer-janazah .where {
    margin-top: 8px;
    font-size: var(--flyer-where-size, 34px);
    line-height: 1.32;
    font-weight: 400;
    opacity: 0.85;
}

.flyer-janazah .note {
    margin-top: 8%;
    font-size: var(--flyer-note-size, 30px);
    line-height: 1.45;
    font-weight: 400;
    opacity: 0.78;
}

/* Small, and last. The masjid identifies itself; it does not brand this. */
.flyer-janazah .logo {
    flex: 0 0 auto;
    margin-top: 6%;
    height: 130px;
    object-fit: contain;
    opacity: 0.85;
}
</style>

<div class="flyer flyer-janazah" data-template="janazah" data-canvas="1200x1600">
    <div class="body">
        <p class="arabic" data-slot="arabic">{{ arabic }}</p>
        <p class="translit" data-slot="translit">{{ translit }}</p>

        <p class="intro" data-slot="intro">{{ intro }}</p>
        <h1 class="name" data-slot="name">{{ name }}</h1>
        <p class="lifespan" data-slot="lifespan" data-optional="true">{{ lifespan }}</p>

        <div class="hairline"></div>

        <div class="details">
            <div>
                <p class="label">Salatul Janazah</p>
                <p class="value" data-slot="janazah_time">{{ janazah_time }}</p>
                <p class="where" data-slot="janazah_masjid">{{ janazah_masjid }}</p>
            </div>
            <div>
                <p class="label">Burial</p>
                <p class="value" data-slot="burial_location">{{ burial_location }}</p>
                <p class="where" data-slot="burial_time" data-optional="true">{{ burial_time }}</p>
            </div>
        </div>

        <p class="note" data-slot="note" data-optional="true">{{ note }}</p>
    </div>

    <img class="logo" data-slot="logo" data-optional="true" src="{{ logo }}" alt="">
</div>
`,Me=4.5,ur=.84,hr=1,fr="burlington-purple",Ne=[0,.5,.8,1],W=Object.freeze({key:"cool-neutral",name:"Cool Neutral",grad:["#C7D2DE","#D6DDE6","#E6E7EC","#F1F0F2"],field:"#2E4258",ground:"#12161C",accent:"#7A8CA3",ink:"#14181F",fieldInk:"#FFFFFF",groundInk:"#F2F4F7",pillBg:"#FFFFFF",pillInk:"#000000",barBg:"#000000",barInk:"#FFFFFF"}),pr=.55;function ce(r){const e=typeof r=="string"?r.trim():"";return/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(e)?e.length===4?`#${e[1]}${e[1]}${e[2]}${e[2]}${e[3]}${e[3]}`.toUpperCase():`#${e.slice(1,7)}`.toUpperCase():null}function D(r,e="colour"){const s=ce(r);if(!s)throw new Error(`Flyer palette: ${e} is not a hex colour (got ${JSON.stringify(r)}).`);return s}function ze(r,e="colour"){const s=D(r,e);return[parseInt(s.slice(1,3),16),parseInt(s.slice(3,5),16),parseInt(s.slice(5,7),16)]}function Be([r,e,s]){const f=h=>Math.max(0,Math.min(255,Math.round(h)));return`#${[r,e,s].map(h=>f(h).toString(16).padStart(2,"0")).join("")}`.toUpperCase()}function Ee(r){const[e,s,f]=ze(r).map(F=>F/255),h=Math.max(e,s,f),p=Math.min(e,s,f),z=(h+p)/2;if(h===p)return{h:0,s:0,l:z};const w=h-p,v=z>.5?w/(2-h-p):w/(h+p);let j;return h===e?j=((s-f)/w+(s<f?6:0))/6:h===s?j=((f-e)/w+2)/6:j=((e-s)/w+4)/6,{h:j*360,s:v,l:z}}function be({h:r,s:e,l:s}){const f=(r%360+360)%360/360;if(e===0){const w=s*255;return Be([w,w,w])}const h=s<.5?s*(1+e):s+e-s*e,p=2*s-h,z=w=>{let v=w;return v<0&&(v+=1),v>1&&(v-=1),v<1/6?p+(h-p)*6*v:v<1/2?h:v<2/3?p+(h-p)*(2/3-v)*6:p};return Be([z(f+1/3)*255,z(f)*255,z(f-1/3)*255])}function Ze(r){const e=p=>p<=.03928?p/12.92:((p+.055)/1.055)**2.4,[s,f,h]=ze(r).map(p=>e(p/255));return .2126*s+.7152*f+.0722*h}function fe(r,e){const s=Ze(r),f=Ze(e);return(Math.max(s,f)+.05)/(Math.min(s,f)+.05)}function rt(r,e,s){const f=ze(r),h=ze(e);return Be(f.map((p,z)=>p+(h[z]-p)*s))}function ot(r,e,s=Ne){if(!Array.isArray(r)||r.length!==s.length)throw new Error(`Flyer palette: expected ${s.length} gradient stops, got ${JSON.stringify(r)}.`);r.forEach((h,p)=>D(h,`gradient stop ${p}`));const f=Math.max(0,Math.min(1,e));for(let h=0;h<s.length-1;h+=1){const p=s[h],z=s[h+1];if(f<=z){const w=z-p||1;return rt(r[h],r[h+1],(f-p)/w)}}return D(r[r.length-1],"gradient stop")}function ve(r,e,s=Me){const f=D(r,"ink"),h=D(e,"ground");if(fe(f,h)>=s)return f;const p=fe("#FFFFFF",h),z=fe("#000000",h),w=Math.max(p,z);if(w<s)throw new Error(`Flyer palette: no ink clears ${s}:1 on ${h} — the best any colour can do is ${w.toFixed(2)}:1. Darken or lighten that ground.`);const v=p>z,j=Ee(f);for(let F=1;F<=50;F+=1){const $=v?Math.min(1,j.l+F*.02):Math.max(0,j.l-F*.02),L=be({...j,l:$});if(fe(L,h)>=s)return L}return v?"#FFFFFF":"#000000"}function mr(r){const e=ce(r);if(!e)return[...W.grad];const{h:s,s:f}=Ee(e),h=Math.max(.35,Math.min(1,f/.6)),p=[.26,.2,.14,.09].map(w=>w*h);return[.76,.83,.89,.94].map((w,v)=>rt(be({h:s,s:p[v],l:w}),W.grad[v],pr))}function gr(r){const e=ce(r);if(!e)return W.field;const{h:s,s:f}=Ee(e);let h=be({h:s,s:Math.min(f,.45),l:.3});for(let p=1;fe("#FFFFFF",h)<Me&&p<=15;p+=1)h=be({h:s,s:Math.min(f,.45),l:Math.max(.06,.3-p*.02)});return h}function yr(r){const e=ce(r);if(!e)return W.ground;const{h:s,s:f}=Ee(e);return be({h:s,s:Math.min(f,.12),l:.09})}function vr(r){const e=r||{},s=ce(e.primary_color??e.primary),f=ce(e.secondary_color??e.secondary),h=ce(e.accent_color??e.accent),p=mr(s),z=gr(s),w=yr(f||s);return{key:"derived",name:"Derived from theme",grad:p,field:z,ground:w,accent:h||W.accent,ink:W.ink,fieldInk:W.fieldInk,groundInk:W.groundInk,pillBg:W.pillBg,pillInk:W.pillInk,barBg:W.barBg,barInk:W.barInk}}function lt(r){if(typeof r!="number"&&typeof r!="string"||typeof r=="string"&&r.trim()==="")return null;const e=Number(r);return Number.isInteger(e)?e:null}function je(r,e){if(!r)return!1;const s=r.restricted_to_masjid;if(s==null)return!0;const f=lt(s);return f!==null&&f===e}function it(r){if(r==null)return ur;if(typeof r!="number"||!Number.isFinite(r)||r<0||r>1)throw new Error(`Flyer palette: contrast_sample must be a number 0..1 (got ${JSON.stringify(r)}).`);return r}function br(r={}){var C,O;const{palette:e,named:s,theme:f,masjidId:h,palettes:p,manifest:z,contrastSample:w}=r,v=lt(h),j=(C=p==null?void 0:p.palettes)==null?void 0:C[fr];let F;je(e,v)?F={...W,...e}:je(s,v)?F={...W,...s}:v===hr&&je(j,v)?F={...W,...j}:f?F=vr(f):F={...W};const $=it(w??((O=z==null?void 0:z.palette)==null?void 0:O.contrast_sample)),L=ot(F.grad,$);return{...F,contrastSample:$,ink:ve(F.ink,L),fieldInk:ve(F.fieldInk,F.field),groundInk:ve(F.groundInk,F.ground),pillInk:ve(F.pillInk,F.pillBg),barInk:ve(F.barInk,F.barBg)}}function kr(r){const e=r||{};if(!Array.isArray(e.grad)||e.grad.length!==Ne.length)throw new Error(`Flyer palette: expected ${Ne.length} gradient stops, got ${JSON.stringify(e.grad)}.`);return{"--flyer-grad-0":D(e.grad[0],"grad[0]"),"--flyer-grad-1":D(e.grad[1],"grad[1]"),"--flyer-grad-2":D(e.grad[2],"grad[2]"),"--flyer-grad-3":D(e.grad[3],"grad[3]"),"--flyer-ink":D(e.ink,"ink"),"--flyer-field":D(e.field,"field"),"--flyer-field-ink":D(e.fieldInk,"fieldInk"),"--flyer-ground":D(e.ground,"ground"),"--flyer-ground-ink":D(e.groundInk,"groundInk"),"--flyer-accent":D(e.accent,"accent"),"--flyer-pill-bg":D(e.pillBg,"pillBg"),"--flyer-pill-ink":D(e.pillInk,"pillInk"),"--flyer-bar-bg":D(e.barBg,"barBg"),"--flyer-bar-ink":D(e.barInk,"barInk")}}function xr(r,e){const s=it(r.contrastSample);return[["ink",r.ink,ot(r.grad,s)],["fieldInk",r.fieldInk,r.field],["groundInk",r.groundInk,r.ground],["pillInk",r.pillInk,r.pillBg],["barInk",r.barInk,r.barBg]].map(([h,p,z])=>({name:h,ink:p,ground:z,ratio:fe(p,z)})).filter(h=>h.ratio<Me)}const wr=Object.assign({"../../../flyer-templates/event-banner.json":Dn,"../../../flyer-templates/event-bulletin.json":Xn,"../../../flyer-templates/event-invitation.json":ca,"../../../flyer-templates/event-photo.json":Fa,"../../../flyer-templates/food.json":Na,"../../../flyer-templates/index.json":Ra,"../../../flyer-templates/janazah.json":er,"../../../flyer-templates/palettes.json":rr}),Fr=Object.assign({"../../../flyer-templates/event-banner.html":or,"../../../flyer-templates/event-bulletin.html":lr,"../../../flyer-templates/event-invitation.html":ir,"../../../flyer-templates/event-photo.html":sr,"../../../flyer-templates/food.html":dr,"../../../flyer-templates/janazah.html":cr});function st(r){const e={};return Object.entries(r).forEach(([s,f])=>{const h=s.split("/").pop();h&&(e[h]=f)}),e}const Pe=st(wr),_r=st(Fr),Le=Pe["index.json"],ie=Pe["palettes.json"],Qe=2e3,zr=5e3,Er=15,Ar=45,Sr=12*1024*1024,Tr=wt("flyersStore",()=>{const r=Ft(),e=_t(),s=I([]),f=I(!0),h=I(null),p=I(null),z=I(""),w=I({}),v=I({}),j=I(""),F=I(null),$=I("none"),L=I(null),C=I(!1),O=I(!1),P=I(!1),Y=I(!1),G=I(null),V=I(!1);let K=null,ee=0,_=0,c=null;const d=R(()=>((Le==null?void 0:Le.templates)??[]).map(n=>({key:n.key,manifest:Pe[n.manifest],html:_r[n.html],template:s.value.find(i=>i.key===n.key)??null})).filter(n=>!!n.manifest&&!!n.html)),m=R(()=>d.value.find(t=>t.key===p.value)??null),o=R(()=>{var t;return((t=m.value)==null?void 0:t.manifest)??null}),q=R(()=>{var t;return((t=o.value)==null?void 0:t.slots)??[]}),J=R(()=>q.value.filter(t=>t.type==="image")),X=R(()=>{var n,i,E;const t=J.value.find(A=>A.cutout===!0);return t?t.name:((i=(n=o.value)==null?void 0:n.palette)==null?void 0:i.ground)==="photo"?null:((E=J.value.find(A=>A.treatment==="image"))==null?void 0:E.name)??null});function ne(){const t=te(),n=typeof t=="string"?parseInt(t,10):t;return typeof n=="number"&&!Number.isNaN(n)?n:null}const oe=R(()=>{const t=Object.values((ie==null?void 0:ie.palettes)??{}),n=ne();return t.filter(i=>!i.restricted_to_masjid||i.restricted_to_masjid===n)}),B=R(()=>{var t;return br({named:j.value?(t=ie==null?void 0:ie.palettes)==null?void 0:t[j.value]:void 0,theme:h.value,masjidId:ne(),palettes:ie})}),le=R(()=>kr(B.value)),Q=R(()=>xr(B.value)),pe=R(()=>{const t={...w.value};return Object.entries(v.value).forEach(([n,i])=>{t[n]=i.useCutout&&i.cutout?i.cutout:i.original}),t}),ke=R(()=>q.value.filter(t=>{if(!t.required)return!1;if(t.type==="image"){const i=v.value[t.name];return!(i!=null&&i.original)}const n=w.value[t.name];return Array.isArray(n)?n.every(i=>!Se(i)):!String(n??"").trim()})),xe=R(()=>!!o.value&&ke.value.length===0),me=R(()=>at.includes($.value)),Ae=R(()=>{var t;return!!((t=m.value)!=null&&t.template)&&xe.value});function Se(t){return Object.values(t).some(n=>!!String(n??"").trim())}function te(){var t;return e.dashboardMasjidId??((t=r.masjid)==null?void 0:t.id)??null}function Re(t){return t}async function we(){const t=te();return t?(await re.get(`/api/admin/masjids/${t}/flyer-templates`).then(n=>{var i,E;((i=n.data)==null?void 0:i.status)==="success"&&Array.isArray((E=n.data)==null?void 0:E.data)&&(s.value=n.data.data,f.value=!0)}).catch(n=>{f.value=!1,console.error("Fetch flyer templates error: ",n)}),s.value):[]}async function a(){const t=te();return t?(await re.get(`/api/admin/masjids/${t}/theme`).then(n=>{var i,E;((i=n.data)==null?void 0:i.status)==="success"&&((E=n.data)!=null&&E.data)&&(h.value=n.data.data)}).catch(n=>{console.error("Fetch theme settings error: ",n)}),h.value):null}async function l(){O.value=!0;try{await Promise.all([we(),a()])}finally{O.value=!1}}function y(t){const n=d.value.find(i=>i.key===t);n&&(ge(),We(),p.value=t,F.value=null,V.value=!1,c=null,$.value="none",L.value=null,C.value=!1,G.value=null,v.value={},w.value=g(n.manifest),z.value=n.manifest.name)}function g(t){const n={};return t.slots.forEach(i=>{if(i.type==="list"){n[i.name]=[S(i)];return}if(i.type==="image"){n[i.name]=null;return}n[i.name]=i.ask_always?"":i.default??""}),n}function S(t){const n={};return(t.item_slots??[]).forEach(i=>{n[i.name]=i.default??""}),n}function T(t,n){w.value={...w.value,[t]:n}}function N(t,n){w.value={...w.value,[t]:n}}function Z(t){const n=q.value.find(E=>E.name===t);if(!n)return;const i=w.value[t]??[];n.maxItems&&i.length>=n.maxItems||N(t,[...i,S(n)])}function ue(t,n){const i=w.value[t]??[];N(t,i.filter((E,A)=>A!==n))}async function he(t,n){if(!n.type.startsWith("image/"))throw new Error("That file is not an image.");if(n.size>Sr)throw new Error("That image is larger than 12MB. Please use a smaller one.");const i=await Fe(n);v.value={...v.value,[t]:{original:i,cutout:null,useCutout:t===X.value,fileName:n.name}},t===X.value&&await Oe(n)}function Te(t){const n={...v.value};if(delete n[t],v.value=n,t!==X.value)return;ge(),c=null,$.value="none",L.value=null;const i=te();!i||!F.value||re.delete(`/api/admin/masjids/${i}/flyers/${F.value}/photo`).catch(E=>{console.error("Flyer photo delete error: ",E)})}function $e(t,n){const i=v.value[t];i&&(v.value={...v.value,[t]:{...i,useCutout:n}})}function Fe(t){return new Promise((n,i)=>{const E=new FileReader;E.onload=()=>n(String(E.result)),E.onerror=()=>i(new Error("That image could not be read.")),E.readAsDataURL(t)})}async function dt(t){const n=await re.VueApp.axios.get(t,{responseType:"blob"});return new Promise((i,E)=>{const A=new FileReader;A.onload=()=>i(String(A.result)),A.onerror=()=>E(new Error("The cut-out could not be read.")),A.readAsDataURL(n.data)})}async function Oe(t){var i,E;const n=te();if(!(!n||!X.value)){c=t,P.value=!0,L.value=null;try{const A=await gt(),ae=new FormData;ae.append("image",t),ae.append("remove_background","1");const Ce=await re.post(`/api/admin/masjids/${n}/flyers/${A}/photo`,ae);if(((i=Ce.data)==null?void 0:i.status)!=="success"||!((E=Ce.data)!=null&&E.data))throw new Error("The server did not accept the photo.");await Ue(Ce.data.data),me.value&&ht()}catch(A){$.value="failed",L.value=ct(A),console.error("Flyer photo upload error: ",A)}finally{P.value=!1}}}function ct(t){var i,E,A;return(i=m.value)!=null&&i.template?(((A=(E=t==null?void 0:t.response)==null?void 0:E.data)==null?void 0:A.message)||"The photo could not be sent for background removal.")+" It is still on the flyer and will be included in the export — try again to have the background removed.":"This design is not set up on this server yet, so the photo could not be sent for background removal. It is still on the flyer and will be included in the export."}async function ut(){!c||P.value||await Oe(c)}async function Ue(t){var E;t.flyer_id&&(F.value=t.flyer_id),$.value=t.cutout_status??"none",L.value=t.cutout_error??null,C.value=!!t.available;const n=X.value;if(!n)return;const i=v.value[n];if(!(!i||!((E=t.images)!=null&&E.cutout)||i.cutout))try{const A=await dt(t.images.cutout),ae=v.value[n];if(!ae||ae.original!==i.original)return;v.value={...v.value,[n]:{...ae,cutout:A}}}catch(A){$.value="failed",L.value="The cut-out was made but could not be downloaded. The original will be used.",console.error("Flyer cutout fetch error: ",A)}}function ht(){ge(),ee=0;const t=_;K=setTimeout(()=>Ve(t),Qe)}async function Ve(t){var i,E;if(t!==_)return;const n=te();if(!(!n||!F.value)){ee+=1;try{const A=await re.get(`/api/admin/masjids/${n}/flyers/${F.value}/cutout`);if(t!==_)return;((i=A.data)==null?void 0:i.status)==="success"&&((E=A.data)!=null&&E.data)&&await Ue(A.data.data)}catch(A){if(t!==_)return;console.error("Flyer cutout poll error: ",A)}if(t===_&&me.value){if(ee>=Ar){L.value="Background removal is taking longer than usual. The original photo is being used — reopen this draft later to pick up the cut-out.";return}K=setTimeout(()=>Ve(t),ee<Er?Qe:zr)}}}function ge(){K&&clearTimeout(K),K=null,_+=1}function ft(){const t={...w.value};return q.value.filter(n=>n.type==="image").forEach(n=>{t[n.name]=null}),t}function pt(){var i;const t=String(z.value??"").trim();return t||String(w.value.title??w.value.name??"").trim()||((i=o.value)==null?void 0:i.name)||"Untitled flyer"}function mt(){var n;const t=(n=m.value)==null?void 0:n.template;return t?{flyer_template_id:t.id,title:pt(),content:ft(),palette:B.value}:null}async function gt(){return F.value?F.value:await He()}async function yt(){const t=await He();return V.value=!0,G.value=new Date().toISOString(),t}async function He(){var i,E;const t=te();if(!t)throw new Error("Masjid not specified.");const n=mt();if(!n)throw new Error("This design has not been set up on the server yet, so it cannot be saved.");Y.value=!0;try{const A=F.value?await re.put(`/api/admin/masjids/${t}/flyers/${F.value}`,n):await re.post(`/api/admin/masjids/${t}/flyers`,n);if(((i=A.data)==null?void 0:i.status)==="success"&&((E=A.data)!=null&&E.data)){const ae=A.data.data;return F.value=ae.id,ae.id}throw new Error("Failed to save the flyer.")}finally{Y.value=!1}}function We(){const t=te(),n=F.value;!t||!n||V.value||re.delete(`/api/admin/masjids/${t}/flyers/${n}`).catch(i=>{console.error("Flyer draft discard error: ",i)})}function vt(){ge(),We(),p.value=null,F.value=null,V.value=!1,c=null,w.value={},v.value={},z.value="",$.value="none",L.value=null,C.value=!1,G.value=null}return{templates:s,templatesAvailable:f,theme:h,selectedKey:p,title:z,content:w,images:v,paletteKey:j,flyerId:F,cutoutStatus:$,cutoutError:L,cutoutAvailable:C,loading:O,uploading:P,saving:Y,savedAt:G,designs:d,design:m,manifest:o,slots:q,cutoutSlot:X,paletteOptions:oe,palette:B,cssVars:le,paletteAudit:Q,renderContent:pe,missingRequired:ke,isComplete:xe,cutoutPending:me,canSave:Ae,masjidId:te,fetchTemplates:we,fetchTheme:a,initialise:l,selectTemplate:y,setSlot:T,setListItems:N,addListItem:Z,removeListItem:ue,setImage:he,clearImage:Te,useCutout:$e,retryUpload:ut,saveDraft:yt,stopPolling:ge,reset:vt}}),$r=["disabled","title"],Cr={key:0,class:"spinner-border spinner-border-sm me-1",role:"status"},Ir={class:"container-fluid px-0"},jr={key:0,class:"text-center py-5"},Lr={key:1},Nr={class:"text-uppercase text-muted small fw-semibold mb-2"},Br={class:"row g-3"},qr=["onClick"],Dr={class:"d-flex justify-content-between align-items-start gap-2"},Mr={class:"fw-semibold"},Pr={key:0,class:"badge bg-primary-subtle text-primary-emphasis"},Rr={class:"small text-muted mt-1"},Or={key:0,class:"small text-warning-emphasis mt-2"},Ur={key:2,class:"row g-4"},Vr={class:"col-lg-5"},Hr={class:"mb-4"},Wr={class:"mb-4"},Gr=["value"],Jr={class:"col-lg-7"},Yr={class:"preview-column"},Kr={key:0,class:"alert alert-warning py-2 px-3 small"},Xr={key:1,class:"alert alert-danger py-2 px-3 small"},Zr={key:2,class:"alert alert-warning py-2 px-3 small"},Qr={key:3,class:"alert alert-secondary py-2 px-3 small"},eo={class:"mt-3"},to={key:0,class:"small text-muted mb-2"},no={class:"fw-semibold"},ao={class:"d-flex flex-wrap gap-2"},ro=["disabled","title","onClick"],oo={key:0,class:"spinner-border spinner-border-sm me-1",role:"status"},lo={key:1,class:"bi bi-download me-1"},_e="Montserrat",et="HALAL FOOD SALE 1234567890",io=qe({__name:"FlyerStudioView",setup(r){const e=Tr(),s=I(null),f=I(null),h=I(!1),p=I(!0),z=Ct,w=["food"],v=[400,500,800,900],j={food:"Food sale",event:"Events",janazah:"Janazah"},F=R(()=>["food","event","janazah"].map(c=>({kind:c,label:j[c],designs:e.designs.filter(d=>d.manifest.kind===c)})).filter(c=>c.designs.length>0)),$=R(()=>{var _;return(_=e.design)!=null&&_.template?e.missingRequired.length?"Fill in everything marked required first.":"Save this flyer as a draft.":"This design is not set up on this server yet."});zt(async()=>{await e.initialise()}),nt(async()=>{p.value=await C()}),Et(()=>{e.stopPolling()});function L(_){return w.includes(_)}async function C(){const _=document.fonts;if(!_)return!1;try{const c=await Promise.all(v.map(o=>_.load(`${o} 100px "${_e}"`)));return await _.ready,(!c.some(o=>o.length>0)||v.every(o=>_.check(`${o} 100px "${_e}"`)))&&O()}catch{return!1}}function O(){const _=document.createElement("canvas").getContext("2d");if(!_)return!1;_.font="900 100px monospace";const c=_.measureText(et).width;_.font=`900 100px "${_e}", monospace`;const d=_.measureText(et).width;return d>0&&Math.abs(d-c)>.5}function P(_){h.value=_}async function Y(){(await ye.fire({title:"Start a different design?",text:"What you have filled in will be cleared.",icon:"warning",showCancelButton:!0,confirmButtonColor:"#d33",cancelButtonColor:"#3085d6",confirmButtonText:"Yes, change design"})).isConfirmed&&e.reset()}async function G(_,c){try{await e.setImage(_,c)}catch(d){ye.fire({icon:"error",title:"Error!",text:(d==null?void 0:d.message)??"That image could not be used."})}}async function V(_){if(s.value){f.value=_.key;try{if(p.value=await C(),!p.value)throw new Error(`${_e} has not loaded, so this flyer would export in Helvetica instead of the lettering the masjid's flyers use. Reload the page and try again.`);await s.value.download(_,K(_))}catch(c){ye.fire({icon:"error",title:"Error!",text:(c==null?void 0:c.message)??"The flyer could not be exported."})}finally{f.value=null}}}function K(_){var d;return`${(e.title||((d=e.manifest)==null?void 0:d.name)||"flyer").toLowerCase().replace(/[^a-z0-9]+/g,"-").replace(/^-|-$/g,"")||"flyer"}-${_.width}x${_.height}.png`}async function ee(){var _,c,d,m;try{await e.saveDraft(),ye.fire({icon:"success",title:"Saved!",text:"The flyer was saved as a draft.",timer:2e3,showConfirmButton:!1})}catch(o){const q=(c=(_=o==null?void 0:o.response)==null?void 0:_.data)==null?void 0:c.data,J=q&&typeof q=="object"?Object.values(q).flat().join(" "):((m=(d=o==null?void 0:o.response)==null?void 0:d.data)==null?void 0:m.message)??(o==null?void 0:o.message)??"Failed to save the flyer.";ye.fire({icon:"error",title:"Error!",text:J})}}return(_,c)=>(b(),k("div",null,[Ie(Tt,{title:"Flyer Studio",hideButton:!0},{headerButtons:Ye(()=>[x(e).design?(b(),k("button",{key:0,type:"button",class:"btn btn-outline-secondary",onClick:Y},c[2]||(c[2]=[u("i",{class:"bi bi-arrow-left me-1"},null,-1),H("Change design ")]))):M("",!0),x(e).design?(b(),k("button",{key:1,type:"button",class:"btn btn-success",disabled:!x(e).canSave||x(e).saving,title:$.value,onClick:ee},[x(e).saving?(b(),k("span",Cr)):M("",!0),c[3]||(c[3]=H(" Save draft "))],8,$r)):M("",!0)]),default:Ye(()=>[u("div",Ir,[x(e).loading?(b(),k("div",jr,c[4]||(c[4]=[u("div",{class:"spinner-border text-primary",role:"status"},[u("span",{class:"visually-hidden"},"Loading...")],-1)]))):x(e).design?(b(),k("div",Ur,[u("div",Vr,[u("div",Hr,[c[7]||(c[7]=u("label",{class:"form-label fw-semibold mb-1",for:"flyer-title"},"Draft name",-1)),Ke(u("input",{id:"flyer-title",type:"text",class:"form-control","onUpdate:modelValue":c[0]||(c[0]=d=>x(e).title=d)},null,512),[[At,x(e).title,void 0,{trim:!0}]]),c[8]||(c[8]=u("div",{class:"form-text"},"What this flyer is called in the dashboard. It is not printed on it.",-1))]),u("div",Wr,[c[10]||(c[10]=u("label",{class:"form-label fw-semibold mb-1",for:"flyer-palette"},"Colours",-1)),Ke(u("select",{id:"flyer-palette",class:"form-select","onUpdate:modelValue":c[1]||(c[1]=d=>x(e).paletteKey=d)},[c[9]||(c[9]=u("option",{value:""},"From our brand colours",-1)),(b(!0),k(se,null,de(x(e).paletteOptions,d=>(b(),k("option",{key:d.key,value:d.key},U(d.name),9,Gr))),128))],512),[[St,x(e).paletteKey]]),c[11]||(c[11]=u("div",{class:"form-text"}," Every colour is checked for contrast before it reaches the flyer. ",-1))]),c[12]||(c[12]=u("hr",{class:"my-4"},null,-1)),Ie(zn,{slots:x(e).slots,content:x(e).content,images:x(e).images,cutoutSlot:x(e).cutoutSlot,cutoutStatus:x(e).cutoutStatus,cutoutError:x(e).cutoutError,cutoutAvailable:x(e).cutoutAvailable,uploading:x(e).uploading,onUpdateSlot:x(e).setSlot,onUpdateList:x(e).setListItems,onAddItem:x(e).addListItem,onRemoveItem:x(e).removeListItem,onImage:G,onClearImage:x(e).clearImage,onUseCutout:x(e).useCutout,onRetryPhoto:x(e).retryUpload},null,8,["slots","content","images","cutoutSlot","cutoutStatus","cutoutError","cutoutAvailable","uploading","onUpdateSlot","onUpdateList","onAddItem","onRemoveItem","onClearImage","onUseCutout","onRetryPhoto"])]),u("div",Jr,[u("div",Yr,[p.value?M("",!0):(b(),k("div",Kr,c[13]||(c[13]=[u("i",{class:"bi bi-type me-1"},null,-1),H(" Montserrat has not loaded, so the flyer is drawing in Helvetica. The layout is right; the lettering is not what the masjid's flyers use — so exporting is held back until the font is there rather than letting a PNG go out in the wrong face. ")]))),x(e).paletteAudit.length?(b(),k("div",Xr,[c[14]||(c[14]=u("i",{class:"bi bi-exclamation-octagon me-1"},null,-1)),H(" These colours do not clear the contrast minimum: "+U(x(e).paletteAudit.map(d=>d.name).join(", "))+". Pick another palette. ",1)])):M("",!0),h.value?(b(),k("div",Zr,c[15]||(c[15]=[u("i",{class:"bi bi-arrows-collapse me-1"},null,-1),H(" There is more here than the layout holds. Shorten a line — or, for a long programme, split it across two flyers rather than shrinking the type. ")]))):M("",!0),x(e).templatesAvailable?M("",!0):(b(),k("div",Qr,c[16]||(c[16]=[u("i",{class:"bi bi-cloud-slash me-1"},null,-1),H(" The flyer designs are not set up on this server yet. You can build and export this flyer; saving it as a draft needs the designs seeded first. ")]))),Ie(qt,{ref_key:"previewRef",ref:s,manifest:x(e).manifest,html:x(e).design.html,content:x(e).renderContent,cssVars:x(e).cssVars,onOverflow:P},null,8,["manifest","html","content","cssVars"]),u("div",eo,[x(e).missingRequired.length?(b(),k("div",to,[c[17]||(c[17]=H(" Still needed: ")),u("span",no,U(x(e).missingRequired.map(d=>d.label).join(", ")),1)])):M("",!0),u("div",ao,[(b(!0),k(se,null,de(x(z),d=>(b(),k("button",{key:d.key,type:"button",class:"btn btn-outline-primary",disabled:!x(e).isComplete||f.value===d.key,title:d.note,onClick:m=>V(d)},[f.value===d.key?(b(),k("span",oo)):(b(),k("i",lo)),H(" "+U(d.label),1)],8,ro))),128))]),c[18]||(c[18]=u("div",{class:"form-text mt-2"}," Exported at full canvas resolution, not at the size of this preview. ",-1))])])])])):(b(),k("div",Lr,[c[6]||(c[6]=u("p",{class:"text-muted"}," Pick a design. Everything is drawn here in the browser, and the export is a download — nothing is posted anywhere. ",-1)),(b(!0),k(se,null,de(F.value,d=>(b(),k("div",{key:d.kind,class:"mb-4"},[u("h6",Nr,U(d.label),1),u("div",Br,[(b(!0),k(se,null,de(d.designs,m=>(b(),k("div",{key:m.key,class:"col-md-6 col-xl-4"},[u("button",{type:"button",class:"design-card w-100 text-start",onClick:o=>x(e).selectTemplate(m.key)},[u("div",Dr,[u("span",Mr,U(m.manifest.name),1),L(m.key)?(b(),k("span",Pr," Measured ")):M("",!0)]),u("div",Rr,U(m.manifest.summary),1),m.template?M("",!0):(b(),k("div",Or,c[5]||(c[5]=[u("i",{class:"bi bi-exclamation-triangle me-1"},null,-1),H("Not set up on this server — build and export only. ")])))],8,qr)]))),128))])]))),128))]))])]),_:1})]))}}),uo=De(io,[["__scopeId","data-v-636e5460"]]);export{uo as default};
