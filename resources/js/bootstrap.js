import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Sesión cerrada por inactividad (CerrarSesionPorInactividad): una llamada
// axios que se topa con la sesión vencida manda al login, donde sale el aviso.
window.axios.interceptors.response.use(
    (r) => r,
    (error) => {
        if (error.response?.status === 401 && error.response.data?.inactividad) {
            window.location.href = '/login';
        }
        return Promise.reject(error);
    },
);
