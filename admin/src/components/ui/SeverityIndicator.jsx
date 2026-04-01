import clsx from 'clsx';

const priorityToSeverity = {
    high: 'critical',
    medium: 'medium',
    low: 'low',
    info: 'info',
};

const SeverityIndicator = ({ priority, variant = 'dot', label }) => {
    const severity = priorityToSeverity[priority] || priority;

    return (
        <span className="severity">
            <span
                className={clsx(
                    variant === 'bar' ? 'severity__bar' : 'severity__dot',
                    variant === 'bar'
                        ? `severity__bar--${severity}`
                        : `severity__dot--${severity}`
                )}
            />
            {label && <span>{label}</span>}
        </span>
    );
};

export default SeverityIndicator;
