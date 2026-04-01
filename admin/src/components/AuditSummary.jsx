import { useState, useEffect } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Card, MetricCard, Badge, SkeletonLoader } from './ui';

const AuditSummary = () => {
    const [summary, setSummary] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        loadSummary();
    }, []);

    const loadSummary = async () => {
        setLoading(true);
        try {
            const data = await apiFetch({ path: '/mcp/v1/audit/summary' });
            setSummary(data);
        } catch (err) {
            setError(err.message || 'Failed to load audit summary');
        } finally {
            setLoading(false);
        }
    };

    if (loading) {
        return <div className="mcp-loading"><SkeletonLoader type="card" rows={3} /></div>;
    }

    return (
        <div className="audit-summary fade-in">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            {summary && (
                <>
                    <div className="grid grid-cols-4 grid-auto">
                        <MetricCard
                            label={__('Total Issues', 'wp-mcp-connect')}
                            value={summary.total_issues || 0}
                            className={summary.total_issues > 0 ? 'stat-warning' : 'stat-success'}
                        />
                        <MetricCard
                            label={__('Missing SEO Meta', 'wp-mcp-connect')}
                            value={summary.seo?.total_missing || 0}
                            className={summary.seo?.total_missing > 0 ? 'stat-warning' : 'stat-success'}
                        />
                        <MetricCard
                            label={__('Missing Alt Text', 'wp-mcp-connect')}
                            value={summary.media?.missing_alt || 0}
                            className={summary.media?.missing_alt > 0 ? 'stat-warning' : 'stat-success'}
                        />
                        <MetricCard
                            label={__('Open 404s', 'wp-mcp-connect')}
                            value={summary['404_count'] || 0}
                            className={summary['404_count'] > 0 ? 'stat-warning' : 'stat-success'}
                        />
                    </div>

                    <div className="grid grid-cols-4 grid-auto">
                        <MetricCard
                            label={__('Orphan Pages', 'wp-mcp-connect')}
                            value={summary.orphan_pages || 0}
                            className={summary.orphan_pages > 0 ? 'stat-warning' : 'stat-success'}
                        />
                        <MetricCard
                            label={__('Dead End Pages', 'wp-mcp-connect')}
                            value={summary.dead_ends || 0}
                            className={summary.dead_ends > 0 ? 'stat-warning' : 'stat-success'}
                        />
                        <MetricCard
                            label={__('Declining Content', 'wp-mcp-connect')}
                            value={(summary.decay?.early_decline || 0) + (summary.decay?.accelerating_decline || 0)}
                            className={(summary.decay?.early_decline || 0) + (summary.decay?.accelerating_decline || 0) > 0 ? 'stat-warning' : 'stat-success'}
                        />
                        <MetricCard
                            label={__('Total Internal Links', 'wp-mcp-connect')}
                            value={summary.links?.total_links || 0}
                        />
                    </div>

                    {summary.health && Object.keys(summary.health).length > 0 && (
                        <Card title={__('Content Health Distribution', 'wp-mcp-connect')}>
                            <div className="mcp-badge-group" style={{ display: 'flex', gap: '12px', flexWrap: 'wrap' }}>
                                {summary.health.healthy > 0 && <Badge variant="success">{summary.health.healthy} Healthy</Badge>}
                                {summary.health.good > 0 && <Badge variant="info">{summary.health.good} Good</Badge>}
                                {summary.health.needs_attention > 0 && <Badge variant="warning">{summary.health.needs_attention} Needs Attention</Badge>}
                                {summary.health.critical > 0 && <Badge variant="danger">{summary.health.critical} Critical</Badge>}
                            </div>
                        </Card>
                    )}
                </>
            )}
        </div>
    );
};

export default AuditSummary;
