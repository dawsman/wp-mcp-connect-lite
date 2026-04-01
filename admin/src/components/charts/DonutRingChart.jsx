import { ResponsiveContainer, PieChart, Pie, Cell } from 'recharts';

const COLORS = ['#10b981', '#f59e0b', '#ef4444', '#6366f1', '#8b5cf6', '#06b6d4'];

const DonutRingChart = ({
    data,
    centerValue,
    centerLabel,
    colors = COLORS,
    size = 180,
    innerRadius = 55,
    outerRadius = 75,
}) => {
    if (!data || data.length === 0) {
        return null;
    }

    return (
        <div style={{ width: size, height: size, margin: '0 auto' }}>
            <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                    <Pie
                        data={data}
                        innerRadius={innerRadius}
                        outerRadius={outerRadius}
                        paddingAngle={2}
                        dataKey="value"
                        stroke="none"
                        isAnimationActive={false}
                    >
                        {data.map((_, i) => (
                            <Cell key={i} fill={colors[i % colors.length]} />
                        ))}
                    </Pie>
                    {(centerValue !== undefined || centerLabel) && (
                        <text x="50%" y="50%" textAnchor="middle">
                            {centerValue !== undefined && (
                                <tspan
                                    className="donut-center__value"
                                    x="50%"
                                    dy="-6"
                                >
                                    {centerValue}
                                </tspan>
                            )}
                            {centerLabel && (
                                <tspan
                                    className="donut-center__label"
                                    x="50%"
                                    dy="20"
                                >
                                    {centerLabel}
                                </tspan>
                            )}
                        </text>
                    )}
                </PieChart>
            </ResponsiveContainer>
        </div>
    );
};

export default DonutRingChart;
