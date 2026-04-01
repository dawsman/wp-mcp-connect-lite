import { ResponsiveContainer, AreaChart, Area } from 'recharts';

const SparklineChart = ({ data, color = '#6366f1', width = 80, height = 32, dataKey = 'value' }) => {
    if (!data || data.length < 2) {
        return null;
    }

    return (
        <div style={{ width, height }}>
            <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={data} margin={{ top: 2, right: 0, left: 0, bottom: 2 }}>
                    <defs>
                        <linearGradient id={`sparkGrad-${color.replace('#', '')}`} x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor={color} stopOpacity={0.3} />
                            <stop offset="100%" stopColor={color} stopOpacity={0} />
                        </linearGradient>
                    </defs>
                    <Area
                        type="monotone"
                        dataKey={dataKey}
                        stroke={color}
                        strokeWidth={1.5}
                        fill={`url(#sparkGrad-${color.replace('#', '')})`}
                        dot={false}
                        isAnimationActive={false}
                    />
                </AreaChart>
            </ResponsiveContainer>
        </div>
    );
};

export default SparklineChart;
