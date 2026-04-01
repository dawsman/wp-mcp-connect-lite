import {
    ResponsiveContainer,
    ComposedChart,
    Area,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
} from 'recharts';
import { format, parseISO } from 'date-fns';
import CustomTooltip from './CustomTooltip';

const CHART_COLORS = {
    impressions: '#6366f1',
    clicks: '#8b5cf6',
    ctr: '#06b6d4',
    position: '#10b981',
};

const TrendLineChart = ({
    data,
    series = ['impressions', 'clicks'],
    height = 300,
    showGrid = true,
    dateFormat = 'MMM d',
}) => {
    if (!data || data.length === 0) {
        return null;
    }

    const formatDate = (tick) => {
        try {
            return format(parseISO(tick), dateFormat);
        } catch {
            return tick;
        }
    };

    const formatValue = (value, name) => {
        if (name === 'CTR') {
            return `${(value * 100).toFixed(2)}%`;
        }
        if (name === 'Position') {
            return value.toFixed(1);
        }
        return value.toLocaleString();
    };

    const seriesConfig = {
        impressions: { name: 'Impressions', color: CHART_COLORS.impressions, type: 'area', yAxisId: 'left' },
        clicks: { name: 'Clicks', color: CHART_COLORS.clicks, type: 'area', yAxisId: 'left' },
        ctr: { name: 'CTR', color: CHART_COLORS.ctr, type: 'line', yAxisId: 'right' },
        position: { name: 'Position', color: CHART_COLORS.position, type: 'line', yAxisId: 'right' },
    };

    const hasRightAxis = series.some((s) => s === 'ctr' || s === 'position');

    return (
        <div className="chart-container" style={{ height }}>
            <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={data} margin={{ top: 5, right: 5, left: 0, bottom: 5 }}>
                    {showGrid && <CartesianGrid strokeDasharray="3 3" vertical={false} />}
                    <XAxis
                        dataKey="date"
                        tickFormatter={formatDate}
                        tick={{ fontSize: 11 }}
                        tickMargin={8}
                    />
                    <YAxis
                        yAxisId="left"
                        tick={{ fontSize: 11 }}
                        tickFormatter={(v) => v >= 1000 ? `${(v / 1000).toFixed(0)}k` : v}
                        width={50}
                    />
                    {hasRightAxis && (
                        <YAxis
                            yAxisId="right"
                            orientation="right"
                            tick={{ fontSize: 11 }}
                            tickFormatter={(v) =>
                                series.includes('ctr')
                                    ? `${(v * 100).toFixed(0)}%`
                                    : v.toFixed(0)
                            }
                            width={50}
                        />
                    )}
                    <Tooltip
                        content={
                            <CustomTooltip
                                formatLabel={formatDate}
                                formatValue={formatValue}
                            />
                        }
                    />
                    <Legend />

                    {series.map((key) => {
                        const cfg = seriesConfig[key];
                        if (!cfg) return null;

                        if (cfg.type === 'area') {
                            return (
                                <Area
                                    key={key}
                                    type="monotone"
                                    dataKey={key}
                                    name={cfg.name}
                                    stroke={cfg.color}
                                    fill={cfg.color}
                                    fillOpacity={0.1}
                                    strokeWidth={2}
                                    yAxisId={cfg.yAxisId}
                                    dot={false}
                                />
                            );
                        }
                        return (
                            <Line
                                key={key}
                                type="monotone"
                                dataKey={key}
                                name={cfg.name}
                                stroke={cfg.color}
                                strokeWidth={2}
                                yAxisId={cfg.yAxisId}
                                dot={false}
                            />
                        );
                    })}
                </ComposedChart>
            </ResponsiveContainer>
        </div>
    );
};

export default TrendLineChart;
