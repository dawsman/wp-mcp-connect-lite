import { createContext, useContext, useState, useCallback, createPortal } from '@wordpress/element';
import Toast from './Toast';

const ToastContext = createContext(null);

let toastId = 0;

export const useToast = () => {
    const ctx = useContext(ToastContext);
    if (!ctx) throw new Error('useToast must be used within ToastProvider');
    return ctx;
};

const ToastProvider = ({ children }) => {
    const [toasts, setToasts] = useState([]);

    const dismiss = useCallback((id) => {
        setToasts((prev) => prev.filter((t) => t.id !== id));
    }, []);

    const addToast = useCallback((message, type = 'success', duration = 5000) => {
        const id = ++toastId;
        setToasts((prev) => [...prev, { id, message, type, duration }]);
        return id;
    }, []);

    const toast = {
        success: (msg) => addToast(msg, 'success'),
        error: (msg) => addToast(msg, 'error', 8000),
        info: (msg) => addToast(msg, 'info'),
        warning: (msg) => addToast(msg, 'warning'),
    };

    return (
        <ToastContext.Provider value={toast}>
            {children}
            {createPortal(
                <div className="mcp-toast-container" aria-live="polite">
                    {toasts.map((t) => (
                        <Toast key={t.id} {...t} onDismiss={dismiss} />
                    ))}
                </div>,
                document.body
            )}
        </ToastContext.Provider>
    );
};

export default ToastProvider;
