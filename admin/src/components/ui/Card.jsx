import clsx from 'clsx';

const Card = ({ title, subtitle, actions, flush = false, children, className }) => {
    return (
        <div className={clsx('mcp-card', flush && 'mcp-card--flush', className)}>
            {(title || actions) && (
                <div className="mcp-card__header">
                    <div>
                        {title && <h3 className="mcp-card__title">{title}</h3>}
                        {subtitle && <p className="mcp-card__subtitle">{subtitle}</p>}
                    </div>
                    {actions && <div>{actions}</div>}
                </div>
            )}
            {children}
        </div>
    );
};

export default Card;
