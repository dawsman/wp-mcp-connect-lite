import { useEffect } from '@wordpress/element';
import clsx from 'clsx';

const Toast = ({ id, type = 'info', message, onDismiss, duration = 5000 }) => {
    useEffect(() => {
        if (duration > 0) {
            const timer = setTimeout(() => onDismiss(id), duration);
            return () => clearTimeout(timer);
        }
    }, [id, duration, onDismiss]);

    return (
        <div className={clsx('mcp-toast', `mcp-toast--${type}`)} role="alert">
            <span className="mcp-toast__message">{message}</span>
            <button
                className="mcp-toast__close"
                onClick={() => onDismiss(id)}
                aria-label="Dismiss"
            >
                &times;
            </button>
        </div>
    );
};

export default Toast;
