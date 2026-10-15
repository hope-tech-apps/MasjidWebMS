<template><p v-if="account?.has_account" class="small border rounded p-2">{{ labels.title }}: {{ account.enabled ? labels.on : labels.off }} · {{ labels.username }}: <strong>{{ account.username }}</strong><br>{{ labels.last }}: {{ account.last_sign_in_label ?? labels.never }}</p></template>
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import FamilyApi from '@/core/services/FamilyApiService';
import { useFamilyLang } from '@/views/family/familyI18n';
const props=defineProps<{schoolId:string|number;membershipId:number}>();
const { lang }=useFamilyLang();
const translations:Record<string,any>={
 en:{title:'Student account',on:'On',off:'Off',username:'Username',last:'Last sign-in',never:'Never'},
 ar:{title:'حساب الطالب',on:'مفعّل',off:'متوقف',username:'اسم المستخدم',last:'آخر تسجيل دخول',never:'لم يسجل الدخول بعد'},
 fr:{title:'Compte élève',on:'Activé',off:'Désactivé',username:'Identifiant',last:'Dernière connexion',never:'Jamais'},
 ur:{title:'طالب علم کا اکاؤنٹ',on:'فعال',off:'بند',username:'صارف نام',last:'آخری سائن ان',never:'ابھی تک نہیں'},
 ps:{title:'د زده کوونکي حساب',on:'فعال',off:'بند',username:'کارن نوم',last:'وروستی ننوتل',never:'تر اوسه نه'},
 'fa-AF':{title:'حساب دانش‌آموز',on:'فعال',off:'غیرفعال',username:'نام کاربری',last:'آخرین ورود',never:'هنوز وارد نشده'},
 es:{title:'Cuenta del estudiante',on:'Activada',off:'Desactivada',username:'Nombre de usuario',last:'Último acceso',never:'Nunca'},
};
const labels=computed(()=>translations[(lang as any)?.value ?? 'en'] ?? translations.en);
const account=ref<any>(null);
onMounted(async()=>{try{const r=await FamilyApi.get(`/api/family/masjids/${props.schoolId}/student-accounts`);account.value=(r.data.data ?? []).find((a:any)=>a.membership_id===props.membershipId);}catch{account.value=null;}});
</script>
