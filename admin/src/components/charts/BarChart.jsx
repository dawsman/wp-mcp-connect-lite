import {
    ResponsiveContainer,
    BarChart as RechartsBarChart,
    Bar,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
} from 'recharts';
import CustomTooltip from './CustomTooltip';

const BarChart = ({
    data,
    dataKey = 'value',
    nameKey = 'name',
    color = '#6366f1',
    height = 250,
    layout = 'horizontal',
    formatValue,
}) => {
    if (!data || data.length === 0) {
        return null;
    }

    const isHorizontal = layout === 'horizontal';

    return (
        <div className="chart-container" style={{ height }}>
            <ResponsiveContainer width="100%" height="100%">
                <RechartsBarChart
                    data={data}
                    layout={isHorizontal ? 'vertical' : 'horizontal'}
                    margin={{ top: 5, right: 5, left: 0, bottom: 5 }}
                >
                    <CartesianGrid strokeDasharray="3 3" horizontal={isHorizontal} vertical={!isHorizontal} />
                    {isHorizontal ? (
                        <>
                            <XAxis type="number" tick={{ fontSize: 11 }} />
                            <YAxis
                                type="category"
                                dataKey={nameKey}
                                tick={{ fontSize: 11 }}
                                width={150}
                            />
                        </>
                    ) : (
                        <>
                            <XAxis dataKey={nameKey} tick={{ fontSize: 11 }} />
                            <YAxis tick={{ fontSize: 11 }} />
                        </>
                    )}
                    <Tooltip
                        content={<CustomTooltip formatValue={formatValue} />}
                    />
                    <Bar
                        dataKey={dataKey}
                        fill={color}
                        radius={[4, 4, 0, 0]}
                        maxBarSize={32}
                    />
                </RechartsBarChart>
            </ResponsiveContainer>
        </div>
    );
};

export default BarChart;
