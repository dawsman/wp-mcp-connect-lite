import { useState, useEffect } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { MetricCard, Card, Badge, SkeletonLoader } from './ui';
import { TrendLineChart } from './charts';
import useGSCTrends from '../hooks/useGSCTrends';

const timeAgo = (dateStr) => {
    if (!dateStr) return null;
    try {
        const diff = Date.now() - new Date(dateStr).getTime();
        const mins = Math.floor(diff / 60000);
        if (mins < 1) return 'just now';
        if (mins < 60) return `${mins}m ago`;
        const hrs = Math.floor(mins / 60);
        if (hrs < 24) return `${hrs}h ago`;
        const days = Math.floor(hrs / 24);
        return `${days}d ago`;
    } catch {
        return null;
    }
};

const AUDIT_TAB_MAP = {
    missing_title: 'audits',
    missing_description: 'audits',
    missing_og_title: 'audits',
    missing_schema: 'audits',
    missing_alt: 'audits',
    orphan_pages: 'topology',
    broken_links: 'audits',
};

const Dashboard = () => {
    const [stats, setStats] = useState(null);
    const [systemInfo, setSystemInfo] = useState(null);
    const [recentOps, setRecentOps] = useState([]);
    const [gscOverview, setGscOverview] = useState(null);
    const [priorityActions, setPriorityActions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [connectionStatus, setConnectionStatus] = useState('unknown');
    const [healthData, setHealthData] = useState(null);
    const [topPages, setTopPages] = useState([]);
    const { data: trendsData } = useGSCTrends('28d');

    useEffect(() => {
        loadDashboardData();
    }, []);

    const loadDashboardData = async () => {
        setLoading(true);
        setError(null);

        try {
            const [system, redirects, missingAlt, seoAudit, auditSummary] = await Promise.all([
                apiFetch({ path: '/mcp/v1/system' }),
                apiFetch({ path: '/wp/v2/redirects?per_page=1' }).catch(() => []),
                apiFetch({ path: '/mcp/v1/media/missing-alt?per_page=1' }).catch(() => ({ total: 0 })),
                apiFetch({ path: '/mcp/v1/seo/audit?per_page=1' }).catch(() => ({ summary: {} })),
                apiFetch({ path: '/mcp/v1/audit/summary' }).catch(() => ({ summary: {} })),
            ]);

            setSystemInfo(system);
            setConnectionStatus('connected');

            const summary = seoAudit.summary || {};
            setStats({
                redirects: system.redirects_count || 0,
                missingAlt: missingAlt.total || 0,
                missingSeo: (summary.missing_title || 0) + (summary.missing_description || 0),
                missingTitle: summary.missing_title || 0,
                missingDesc: summary.missing_description || 0,
                missingOg: summary.missing_og_title || 0,
                missingSchema: summary.missing_schema || 0,
            });

            // Build priority actions from audit data
            const actions = [];
            if (summary.missing_title > 0) {
                actions.push({ key: 'missing_title', label: `${summary.missing_title} posts missing SEO title`, severity: 'danger', tab: 'audits' });
            }
            if (summary.missing_description > 0) {
                actions.push({ key: 'missing_desc', label: `${summary.missing_description} posts missing meta description`, severity: 'danger', tab: 'audits' });
            }
            if ((missingAlt.total || 0) > 0) {
                actions.push({ key: 'missing_alt', label: `${missingAlt.total} images missing alt text`, severity: 'warning', tab: 'audits' });
            }
            if (summary.missing_og_title > 0) {
                actions.push({ key: 'missing_og', label: `${summary.missing_og_title} posts missing Open Graph data`, severity: 'warning', tab: 'audits' });
            }
            if (summary.missing_schema > 0) {
                actions.push({ key: 'missing_schema', label: `${summary.missing_schema} posts missing schema markup`, severity: 'info', tab: 'audits' });
            }
            setPriorityActions(actions.slice(0, 5));

            const s = auditSummary?.summary || {};
            setHealthData({
                open404s:         s['404_count'] || 0,
                orphanPages:      s.orphan_pages || 0,
                decliningContent: (s.decay?.early_decline || 0) + (s.decay?.accelerating_decline || 0),
                deadEnds:         s.dead_ends || 0,
            });

            // Load GSC data and ops in parallel (non-blocking)
            Promise.allSettled([
                apiFetch({ path: '/mcp/v1/gsc/overview' }),
                apiFetch({ path: '/mcp/v1/ops?per_page=5' }).catch(() =>
                    apiFetch({ path: '/mcp/v1/logs/recent?limit=5' }).catch(() => [])
                ),
                apiFetch({ path: '/mcp/v1/gsc/pages?per_page=3&orderby=clicks&order=desc' }),
            ]).then(([gscRes, opsRes, topPagesRes]) => {
                if (gscRes.status === 'fulfilled') setGscOverview(gscRes.value);
                if (opsRes.status === 'fulfilled') {
                    const ops = opsRes.value;
                    setRecentOps(Array.isArray(ops) ? ops : (ops?.entries || []));
                }
                if (topPagesRes.status === 'fulfilled') {
                    setTopPages(topPagesRes.value?.pages || []);
                }
            });
        } catch (err) {
            setError(err.message || 'Failed to load dashboard data');
            setConnectionStatus('disconnected');
        } finally {
            setLoading(false);
        }
    };

    if (loading) {
        return (
            <div className="grid grid-cols-4 grid-auto">
                <SkeletonLoader type="metric" />
                <SkeletonLoader type="metric" />
                <SkeletonLoader type="metric" />
                <SkeletonLoader type="metric" />
            </div>
        );
    }

    const sparklines = trendsData?.series ? {
        impressions: trendsData.series.map((d) => d.impressions),
        clicks: trendsData.series.map((d) => d.clicks),
        ctr: trendsData.series.map((d) => d.ctr),
        position: trendsData.series.map((d) => d.position),
    } : {};

    const siteName = systemInfo?.site_title || systemInfo?.name || 'WordPress';
    const lastSync = systemInfo?.last_gsc_sync || gscOverview?.last_sync || null;

    const navigateToTab = (tabName) => {
        const tabBtn = document.querySelector(`#tab-${tabName}`);
        if (tabBtn) tabBtn.click();
    };

    return (
        <div className="dashboard-content fade-in">
            {error && (
                <Notice status="error" isDismissible={false}>
                    {error}
                </Notice>
            )}

            {/* Zone 1 — Status Bar */}
            <div className="dashboard-status-bar">
                <div className="dashboard-status-bar__left">
                    <span className={`status-dot status-dot--${connectionStatus}`} />
                    <span className="dashboard-status-bar__site">{siteName}</span>
                    {lastSync && (
                        <span className="dashboard-status-bar__sync">
                            {__('Last GSC sync:', 'wp-mcp-connect')} {timeAgo(lastSync)}
                        </span>
                    )}
                </div>
                <span className="dashboard-status-bar__version">
                    v{systemInfo?.plugin_version || systemInfo?.version || '—'}
                </span>
            </div>

            {/* Zone 2 — KPI Strip */}
            <div className="grid grid-cols-4 grid-auto" style={{ marginBottom: 20 }}>
                {gscOverview && (
                    <>
                        <MetricCard
                            label={__('Impressions', 'wp-mcp-connect')}
                            value={(gscOverview.total_impressions || 0).toLocaleString()}
                            sparklineData={sparklines.impressions}
                            sparklineColor="#6366f1"
                        />
                        <MetricCard
                            label={__('Clicks', 'wp-mcp-connect')}
                            value={(gscOverview.total_clicks || 0).toLocaleString()}
                            sparklineData={sparklines.clicks}
                            sparklineColor="#10b981"
                        />
                        <MetricCard
                            label={__('Avg CTR', 'wp-mcp-connect')}
                            value={`${gscOverview.avg_ctr || 0}%`}
                            sparklineData={sparklines.ctr}
                            sparklineColor="#06b6d4"
                        />
                        <MetricCard
                            label={__('Avg Position', 'wp-mcp-connect')}
                            value={gscOverview.avg_position || '—'}
                            sparklineData={sparklines.position}
                            sparklineColor="#f59e0b"
                            invertTrend
                        />
                    </>
                )}
                {!gscOverview && (
                    <>
                        <MetricCard
                            label={__('Active Redirects', 'wp-mcp-connect')}
                            value={stats?.redirects || 0}
                        />
                        <MetricCard
                            label={__('Missing Alt Text', 'wp-mcp-connect')}
                            value={stats?.missingAlt || 0}
                        />
                        <MetricCard
                            label={__('Missing SEO Data', 'wp-mcp-connect')}
                            value={stats?.missingSeo || 0}
                        />
                    </>
                )}
            </div>

            {/* Zone 2a — Site Health Strip */}
            {healthData && (
                <div className="grid grid-cols-4 grid-auto" style={{ marginBottom: 20 }}>
                    <MetricCard
                        label={__('Open 404s', 'wp-mcp-connect')}
                        value={healthData.open404s}
                        className={healthData.open404s > 0 ? 'metric-card--warning' : ''}
                    />
                    <MetricCard
                        label={__('Orphan Pages', 'wp-mcp-connect')}
                        value={healthData.orphanPages}
                        className={healthData.orphanPages > 0 ? 'metric-card--warning' : ''}
                    />
                    <MetricCard
                        label={__('Declining Content', 'wp-mcp-connect')}
                        value={healthData.decliningContent}
                        className={healthData.decliningContent > 0 ? 'metric-card--warning' : ''}
                    />
                    <MetricCard
                        label={__('Dead End Pages', 'wp-mcp-connect')}
                        value={healthData.deadEnds}
                        className={healthData.deadEnds > 0 ? 'metric-card--warning' : ''}
                    />
                </div>
            )}

            {/* Zone 2b — Trend Chart (if GSC data available) */}
            {trendsData?.series && trendsData.series.length > 0 && (
                <Card title={__('Search Performance Trend', 'wp-mcp-connect')}>
                    <TrendLineChart
                        data={trendsData.series}
                        series={['impressions', 'clicks']}
                        height={220}
                    />
                </Card>
            )}

            {/* Zone 3 — Action Feed */}
            <div className="dashboard-grid">
                <Card title={__('Priority Actions', 'wp-mcp-connect')}>
                    {priorityActions.length > 0 ? (
                        <div className="priority-actions-list">
                            {priorityActions.map((action, i) => (
                                <div key={action.key} className="priority-action-item">
                                    <Badge variant={action.severity}>{action.severity}</Badge>
                                    <span className="priority-action-item__label">{action.label}</span>
                                    <Button
                                        variant="link"
                                        className="priority-action-item__link"
                                        onClick={() => navigateToTab(action.tab)}
                                    >
                                        {__('Fix', 'wp-mcp-connect')}
                                    </Button>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-muted">{__('No issues found. Your site looks healthy!', 'wp-mcp-connect')}</p>
                    )}
                </Card>

                <Card title={__('Recent Changes', 'wp-mcp-connect')}>
                    {recentOps.length > 0 ? (
                        <div className="recent-changes-list">
                            {recentOps.slice(0, 5).map((op, i) => (
                                <div key={i} className="recent-change-item">
                                    <div className="recent-change-item__info">
                                        <span className="recent-change-item__action">
                                            {op.description || op.action || op.endpoint || 'API call'}
                                        </span>
                                        <span className="recent-change-item__time">
                                            {timeAgo(op.timestamp || op.created_at || op.date) || op.timestamp || '—'}
                                        </span>
                                    </div>
                                    {(op.status_code || op.status) && (
                                        <Badge variant={(op.status_code || op.status) < 400 ? 'success' : 'danger'}>
                                            {op.status_code || op.status}
                                        </Badge>
                                    )}
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-muted">{__('No recent changes recorded.', 'wp-mcp-connect')}</p>
                    )}
                </Card>
            </div>

            {/* Zone 4 — Top Pages (GSC-dependent) */}
            {topPages.length > 0 && (
                <Card title={__('Top Pages by Clicks', 'wp-mcp-connect')}>
                    <table className="mcp-table">
                        <thead>
                            <tr>
                                <th>{__('Page', 'wp-mcp-connect')}</th>
                                <th>{__('Impressions', 'wp-mcp-connect')}</th>
                                <th>{__('Clicks', 'wp-mcp-connect')}</th>
                                <th>{__('Position', 'wp-mcp-connect')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {topPages.map((page, i) => (
                                <tr key={i}>
                                    <td>
                                        <a href={page.url} target="_blank" rel="noreferrer">
                                            {page.post_title || page.url}
                                        </a>
                                    </td>
                                    <td>{(page.impressions || 0).toLocaleString()}</td>
                                    <td>{(page.clicks || 0).toLocaleString()}</td>
                                    <td>{page.avg_position ? Number(page.avg_position).toFixed(1) : '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            )}
        </div>
    );
};

export default Dashboard;
