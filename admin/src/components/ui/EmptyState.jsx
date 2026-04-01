import { Button } from '@wordpress/components';
import { Icon } from '@wordpress/icons';

const EmptyState = ({ icon, title, message, action, actionLabel, onAction }) => {
    return (
        <div className="empty-state">
            {icon && (
                <div className="empty-state__icon">
                    <Icon icon={icon} size={48} />
                </div>
            )}
            {title && <h3 className="empty-state__title">{title}</h3>}
            {message && <p className="empty-state__message">{message}</p>}
            {(action || onAction) && (
                <Button variant="primary" onClick={onAction}>
                    {actionLabel || action}
                </Button>
            )}
        </div>
    );
};

export default EmptyState;
