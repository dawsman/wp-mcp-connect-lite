import clsx from 'clsx';

const Badge = ({ children, variant = 'neutral', className }) => {
    return (
        <span className={clsx('mcp-badge', `mcp-badge--${variant}`, className)}>
            {children}
        </span>
    );
};

export default Badge;
