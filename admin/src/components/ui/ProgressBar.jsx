import clsx from 'clsx';

const ProgressBar = ({ value, max = 100, variant = 'primary', className }) => {
    const percent = Math.min(100, Math.max(0, (value / max) * 100));

    const fillVariant =
        variant === 'auto'
            ? percent >= 80
                ? 'success'
                : percent >= 50
                ? 'warning'
                : 'danger'
            : variant === 'primary'
            ? ''
            : variant;

    return (
        <div className={clsx('progress-bar', className)}>
            <div
                className={clsx(
                    'progress-bar__fill',
                    fillVariant && `progress-bar__fill--${fillVariant}`
                )}
                style={{ width: `${percent}%` }}
            />
        </div>
    );
};

export default ProgressBar;
