import clsx from 'clsx';

const TrendIndicator = ({ value, direction, inverted = false }) => {
    if (value === undefined || value === null || value === 0) {
        return <span className="trend trend--neutral">—</span>;
    }

    const dir = direction || (value > 0 ? 'up' : 'down');

    return (
        <span
            className={clsx(
                'trend',
                `trend--${dir}`,
                inverted && 'trend--inverted'
            )}
        >
            {dir === 'up' ? '↑' : '↓'}
            {' '}
            {typeof value === 'number' ? Math.abs(value).toLocaleString() : value}
        </span>
    );
};

export default TrendIndicator;
