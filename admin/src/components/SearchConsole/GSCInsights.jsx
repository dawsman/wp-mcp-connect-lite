import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner, Notice, SelectControl, Card, CardBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { external, warning, chartBar, search, backup, starFilled, trendingDown } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

const GSCInsights = () => {
    const [insights, setInsights] = useState([]);
    const [summary, setSummary] = useState({});
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [type, setType] = useState('all');

    useEffect(() => {
        loadInsights();
    }, [type]);

    const loadInsights = async () => {
        setLoading(true);
        setError(null);

        try {
            const response = await apiFetch({
                path: `/mcp/v1/gsc/insights?type=${type}&limit=30`,
            });
            setInsights(response.insights || []);
            setSummary(response.summary || {});
        } catch (err) {
            setError(err.message || 'Failed to load insights');
        } finally {
            setLoading(false);
        }
    };

    const getTypeIcon = (insightType) => {
        switch (insightType) {
            case 'ctr_opportunity':
                return chartBar;
            case 'keyword_mismatch':
                return search;
            case 'not_indexed':
                return warning;
            case 'stale_crawl':
                return backup;
            case 'position_decline':
                return trendingDown;
            case 'top_performer':
                return starFilled;
            default:
                return warning;
        }
    };

    const getTypeLabel = (insightType) => {
        switch (insightType) {
            case 'ctr_opportunity':
                return __('CTR Opportunity', 'wp-mcp-connect');
            case 'keyword_mismatch':
                return __('Keyword Mismatch', 'wp-mcp-connect');
            case 'not_indexed':
                return __('Not Indexed', 'wp-mcp-connect');
            case 'stale_crawl':
                return __('Stale Crawl', 'wp-mcp-connect');
            case 'position_decline':
                return __('Position Decline', 'wp-mcp-connect');
            case 'top_performer':
                return __('Top Performer', 'wp-mcp-connect');
            default:
                return insightType;
        }
    };

    const getPriorityClass = (priority) => {
        switch (priority) {
            case 'high':
                return 'priority-high';
            case 'medium':
                return 'priority-medium';
            case 'low':
                return 'priority-low';
            case 'info':
                return 'priority-info';
            default:
                return '';
        }
    };

    const getTypeClass = (insightType) => {
        switch (insightType) {
            case 'ctr_opportunity':
                return 'type-ctr';
            case 'keyword_mismatch':
                return 'type-keyword';
            case 'not_indexed':
                return 'type-indexed';
            case 'stale_crawl':
                return 'type-stale';
            case 'position_decline':
                return 'type-decline';
            case 'top_performer':
                return 'type-top';
            default:
                return '';
        }
    };

    const truncateUrl = (url, maxLength = 60) => {
        if (!url) return '';
        if (url.length <= maxLength) return url;
        return url.substring(0, maxLength) + '...';
    };

    return (
        <div className="gsc-insights">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            <div className="insights-header">
                <div className="summary-badges">
                    {summary.ctr_opportunity > 0 && (
                        <span className="summary-badge type-ctr">
                            {summary.ctr_opportunity} {__('CTR Opportunities', 'wp-mcp-connect')}
                        </span>
                    )}
                    {summary.not_indexed > 0 && (
                        <span className="summary-badge type-indexed">
                            {summary.not_indexed} {__('Not Indexed', 'wp-mcp-connect')}
                        </span>
                    )}
                    {summary.position_decline > 0 && (
                        <span className="summary-badge type-decline">
                            {summary.position_decline} {__('Declining', 'wp-mcp-connect')}
                        </span>
                    )}
                    {summary.keyword_mismatch > 0 && (
                        <span className="summary-badge type-keyword">
                            {summary.keyword_mismatch} {__('Keyword Issues', 'wp-mcp-connect')}
                        </span>
                    )}
                </div>

                <SelectControl
                    value={type}
                    onChange={setType}
                    options={[
                        { value: 'all', label: __('All Insights', 'wp-mcp-connect') },
                        { value: 'ctr_opportunity', label: __('CTR Opportunities', 'wp-mcp-connect') },
                        { value: 'not_indexed', label: __('Not Indexed', 'wp-mcp-connect') },
                        { value: 'position_decline', label: __('Position Declines', 'wp-mcp-connect') },
                        { value: 'keyword_mismatch', label: __('Keyword Mismatches', 'wp-mcp-connect') },
                        { value: 'stale_crawl', label: __('Stale Crawls', 'wp-mcp-connect') },
                        { value: 'top_performer', label: __('Top Performers', 'wp-mcp-connect') },
                    ]}
                />
            </div>

            {loading ? (
                <div className="gsc-loading">
                    <Spinner />
                </div>
            ) : insights.length > 0 ? (
                <div className="insights-list">
                    {insights.map((insight, index) => (
                        <Card key={index} className={`insight-card ${getTypeClass(insight.type)}`}>
                            <CardBody>
                                <div className="insight-header">
                                    <span className={`insight-type ${getTypeClass(insight.type)}`}>
                                        {getTypeLabel(insight.type)}
                                    </span>
                                    <span className={`insight-priority ${getPriorityClass(insight.priority)}`}>
                                        {insight.priority}
                                    </span>
                                </div>

                                <div className="insight-url">
                                    <a href={insight.url} target="_blank" rel="noopener noreferrer" title={insight.url}>
                                        {truncateUrl(insight.url)}
                                    </a>
                                    {insight.post && (
                                        <span className="post-title">{insight.post.title}</span>
                                    )}
                                </div>

                                <p className="insight-message">{insight.message}</p>

                                {insight.metrics && (
                                    <div className="insight-metrics">
                                        {insight.metrics.impressions !== undefined && (
                                            <span className="metric">
                                                <strong>{insight.metrics.impressions.toLocaleString()}</strong> impressions
                                            </span>
                                        )}
                                        {insight.metrics.clicks !== undefined && (
                                            <span className="metric">
                                                <strong>{insight.metrics.clicks.toLocaleString()}</strong> clicks
                                            </span>
                                        )}
                                        {insight.metrics.ctr !== undefined && (
                                            <span className="metric">
                                                <strong>{insight.metrics.ctr}%</strong> CTR
                                            </span>
                                        )}
                                        {insight.metrics.position !== undefined && (
                                            <span className="metric">
                                                Position <strong>{insight.metrics.position}</strong>
                                            </span>
                                        )}
                                    </div>
                                )}

                                {insight.action_url && (
                                    <div className="insight-actions">
                                        <Button
                                            variant="secondary"
                                            href={insight.action_url}
                                            target="_blank"
                                            icon={external}
                                        >
                                            {insight.action_label || __('Take Action', 'wp-mcp-connect')}
                                        </Button>
                                    </div>
                                )}
                            </CardBody>
                        </Card>
                    ))}
                </div>
            ) : (
                <p className="wp-mcp-connect-empty">
                    {__('No insights found. Try syncing more data from Google Search Console.', 'wp-mcp-connect')}
                </p>
            )}

            <style>{`
                .insights-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 20px;
                    flex-wrap: wrap;
                    gap: 15px;
                }
                .summary-badges {
                    display: flex;
                    gap: 10px;
                    flex-wrap: wrap;
                }
                .summary-badge {
                    padding: 5px 12px;
                    border-radius: 15px;
                    font-size: 12px;
                    font-weight: 500;
                }
                .insights-list {
                    display: flex;
                    flex-direction: column;
                    gap: 15px;
                }
                .insight-card {
                    border-left: 4px solid #ddd;
                }
                .insight-card.type-ctr {
                    border-left-color: #ffc107;
                }
                .insight-card.type-keyword {
                    border-left-color: #fd7e14;
                }
                .insight-card.type-indexed {
                    border-left-color: #dc3545;
                }
                .insight-card.type-stale {
                    border-left-color: #6c757d;
                }
                .insight-card.type-decline {
                    border-left-color: #dc3545;
                }
                .insight-card.type-top {
                    border-left-color: #28a745;
                }
                .insight-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 10px;
                }
                .insight-type {
                    font-size: 11px;
                    font-weight: 600;
                    text-transform: uppercase;
                    padding: 3px 8px;
                    border-radius: 3px;
                    background: #f0f0f0;
                }
                .insight-type.type-ctr {
                    background: #fff3cd;
                    color: #856404;
                }
                .insight-type.type-keyword {
                    background: #ffe8d4;
                    color: #9a5c28;
                }
                .insight-type.type-indexed {
                    background: #f8d7da;
                    color: #721c24;
                }
                .insight-type.type-stale {
                    background: #e2e3e5;
                    color: #383d41;
                }
                .insight-type.type-decline {
                    background: #f8d7da;
                    color: #721c24;
                }
                .insight-type.type-top {
                    background: #d4edda;
                    color: #155724;
                }
                .summary-badge.type-ctr {
                    background: #fff3cd;
                    color: #856404;
                }
                .summary-badge.type-keyword {
                    background: #ffe8d4;
                    color: #9a5c28;
                }
                .summary-badge.type-indexed {
                    background: #f8d7da;
                    color: #721c24;
                }
                .summary-badge.type-decline {
                    background: #f8d7da;
                    color: #721c24;
                }
                .insight-priority {
                    font-size: 10px;
                    text-transform: uppercase;
                    padding: 2px 6px;
                    border-radius: 3px;
                }
                .priority-high {
                    background: #dc3545;
                    color: white;
                }
                .priority-medium {
                    background: #ffc107;
                    color: #333;
                }
                .priority-low {
                    background: #6c757d;
                    color: white;
                }
                .priority-info {
                    background: #17a2b8;
                    color: white;
                }
                .insight-url {
                    margin-bottom: 10px;
                }
                .insight-url a {
                    font-family: monospace;
                    font-size: 13px;
                    text-decoration: none;
                }
                .insight-url .post-title {
                    display: block;
                    font-size: 12px;
                    color: #666;
                    margin-top: 3px;
                }
                .insight-message {
                    color: #333;
                    margin-bottom: 15px;
                    line-height: 1.5;
                }
                .insight-metrics {
                    display: flex;
                    gap: 15px;
                    flex-wrap: wrap;
                    margin-bottom: 15px;
                    padding: 10px;
                    background: #f9f9f9;
                    border-radius: 4px;
                }
                .metric {
                    font-size: 12px;
                    color: #666;
                }
                .insight-actions {
                    margin-top: 10px;
                }
                .gsc-loading {
                    display: flex;
                    justify-content: center;
                    padding: 40px;
                }
            `}</style>
        </div>
    );
};

export default GSCInsights;
