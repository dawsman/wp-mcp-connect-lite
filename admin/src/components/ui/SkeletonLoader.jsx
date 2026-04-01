import clsx from 'clsx';

const SkeletonLoader = ({ type = 'card', rows = 3, className }) => {
    if (type === 'table') {
        return (
            <div className={clsx('fade-in', className)}>
                {Array.from({ length: rows }, (_, i) => (
                    <div key={i} className="skeleton skeleton--row" />
                ))}
            </div>
        );
    }

    if (type === 'chart') {
        return (
            <div className={clsx('fade-in', className)}>
                <div className="skeleton skeleton--chart" />
            </div>
        );
    }

    if (type === 'metric') {
        return (
            <div className={clsx('metric-card fade-in', className)}>
                <div className="skeleton skeleton--text" style={{ width: '40%' }} />
                <div className="skeleton skeleton--value" />
            </div>
        );
    }

    // Default: card
    return (
        <div className={clsx('fade-in', className)}>
            {Array.from({ length: rows }, (_, i) => (
                <div key={i} className="skeleton skeleton--card" style={{ marginBottom: 12 }} />
            ))}
        </div>
    );
};

export default SkeletonLoader;
