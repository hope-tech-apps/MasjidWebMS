// import axios, { AxiosStatic } from 'axios';

// // Declare window.axios as an any type
// declare global {
//     interface Window {
//         axios: AxiosStatic;
//     }
// }

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
