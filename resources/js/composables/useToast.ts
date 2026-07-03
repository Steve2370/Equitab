import { ref } from 'vue';

type ToastType = 'success' | 'error' | 'warning';

interface ToastInstance {
    add: (message: string, type?: ToastType) => void;
}

const toastRef = ref<ToastInstance | null>(null);

export function useToast() {
    function success(message: string) {
        toastRef.value?.add(message, 'success');
    }
    function error(message: string) {
        toastRef.value?.add(message, 'error');
    }
    function warning(message: string) {
        toastRef.value?.add(message, 'warning');
    }
    function setRef(ref: ToastInstance) {
        toastRef.value = ref;
    }

    return { success, error, warning, setRef };
}
