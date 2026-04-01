import { __ } from '@wordpress/i18n';
import clsx from 'clsx';
import TrendIndicator from './TrendIndicator';
import SparklineChart from '../charts/SparklineChart';

const MetricCard = ({
    label,
    value,
    trend,
    trendValue,
    invertTrend = false,
    sparklineData,
    sparklineColor,
    className,
}) => {
    return (
        <div className={clsx('metric-card', className)}>
            <span className="metric-card__label">{label}</span>
            <div className="metric-card__value-row">
                <span className="metric-card__value">{value}</span>
                {trend !== undefined && (
                    <TrendIndicator
                        value={trendValue}
                        direction={trend}
                        inverted={invertTrend}
                    />
                )}
                {sparklineData && sparklineData.length > 1 && (
                    <div className="metric-card__sparkline">
                        <SparklineChart
                            data={sparklineData}
                            color={sparklineColor}
                            width={80}
                            height={32}
                        />
                    </div>
                )}
            </div>
        </div>
    );
};

export default MetricCard;
