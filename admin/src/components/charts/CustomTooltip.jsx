const CustomTooltip = ({ active, payload, label, formatLabel, formatValue }) => {
    if (!active || !payload || payload.length === 0) {
        return null;
    }

    return (
        <div className="chart-tooltip">
            <div className="chart-tooltip__label">
                {formatLabel ? formatLabel(label) : label}
            </div>
            {payload.map((entry, i) => (
                <div key={i} className="chart-tooltip__row">
                    <span className="chart-tooltip__name">
                        <span
                            className="chart-tooltip__dot"
                            style={{ backgroundColor: entry.color }}
                        />
                        {entry.name}
                    </span>
                    <span className="chart-tooltip__value">
                        {formatValue ? formatValue(entry.value, entry.name) : entry.value?.toLocaleString()}
                    </span>
                </div>
            ))}
        </div>
    );
};

export default CustomTooltip;
