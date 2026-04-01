import { useState, useEffect } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Card, Badge, SkeletonLoader, EmptyState } from '../ui';

const SerpOpportunities = () => {
    const [opportunities, setOpportunities] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        loadData();
    }, []);

    const loadData = async () => {
        setLoading(true);
        setError(null);
        try {
            const data = await apiFetch({ path: '/mcp/v1/gsc/serp-opportunities' });
            setOpportunities(data.opportunities || []);
        } catch (err) {
            setError(err.message || 'Failed to load SERP opportunities');
        } finally {
            setLoading(false);
        }
    };

    if (loading) {
        return <div className="mcp-loading"><SkeletonLoader type="table" rows={5} /></div>;
    }

    return (
        <div className="serp-opportunities fade-in">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            <Card title={__('SERP Feature Opportunities', 'wp-mcp-connect')}>
                <p className="muted">
                    {__('Pages ranking on page 1 where CTR is significantly below expected — likely due to SERP features stealing clicks. Add structured data to compete.', 'wp-mcp-connect')}
                </p>

                {opportunities.length > 0 ? (
                    <table className="mcp-table">
                        <thead>
                            <tr>
                                <th>{__('Page', 'wp-mcp-connect')}</th>
                                <th>{__('Position', 'wp-mcp-connect')}</th>
                                <th>{__('Actual CTR', 'wp-mcp-connect')}</th>
                                <th>{__('Expected CTR', 'wp-mcp-connect')}</th>
                                <th>{__('Lost Clicks', 'wp-mcp-connect')}</th>
                                <th>{__('Top Query', 'wp-mcp-connect')}</th>
                                <th>{__('Suggested Schema', 'wp-mcp-connect')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {opportunities.map((opp, i) => (
                                <tr key={i}>
                                    <td>
                                        {opp.edit_url ? (
                                            <a href={opp.edit_url} target="_blank" rel="noopener noreferrer">
                                                {opp.post_title || opp.url}
                                            </a>
                                        ) : (
                                            <span title={opp.url}>{opp.url}</span>
                                        )}
                                    </td>
                                    <td><Badge variant="primary">{opp.position}</Badge></td>
                                    <td>{(opp.actual_ctr * 100).toFixed(1)}%</td>
                                    <td>{(opp.expected_ctr * 100).toFixed(1)}%</td>
                                    <td>
                                        <Badge variant="danger">~{opp.estimated_lost_clicks}</Badge>
                                    </td>
                                    <td><code>{opp.top_query}</code></td>
                                    <td>
                                        <div className="mcp-badge-group">
                                            {opp.suggested_schema?.map((s) => (
                                                <Badge key={s} variant="info">{s}</Badge>
                                            ))}
                                            {(!opp.suggested_schema || opp.suggested_schema.length === 0) && (
                                                <span className="muted">—</span>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <EmptyState
                        message={__('No SERP feature opportunities found. This could mean your CTR is performing well, or there is not enough GSC data yet.', 'wp-mcp-connect')}
                    />
                )}
            </Card>
        </div>
    );
};

export default SerpOpportunities;
