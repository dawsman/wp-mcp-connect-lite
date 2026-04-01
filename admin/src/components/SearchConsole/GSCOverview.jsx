import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner, Notice, Card, CardBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { update } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

const GSCOverview = ({ siteUrl }) => {
    const [overview, setOverview] = useState(null);
    const [syncStatus, setSyncStatus] = useState(null);
    const [loading, setLoading] = useState(true);
    const [syncing, setSyncing] = useState(false);
    const [error, setError] = useState(null);
    const [syncMessage, setSyncMessage] = useState(null);

    useEffect(() => {
        loadData();
    }, []);

    const loadData = async () => {
        setLoading(true);
        setError(null);

        try {
            const [overviewData, statusData] = await Promise.all([
                apiFetch({ path: '/mcp/v1/gsc/overview' }),
                apiFetch({ path: '/mcp/v1/gsc/sync/status' }),
            ]);
            setOverview(overviewData);
            setSyncStatus(statusData);
        } catch (err) {
            setError(err.message || 'Failed to load data');
        } finally {
            setLoading(false);
        }
    };

    const handleSync = async () => {
        setSyncing(true);
        setSyncMessage(null);
        setError(null);

        try {
            const result = await apiFetch({
                path: '/mcp/v1/gsc/sync',
                method: 'POST',
                data: { type: 'full' },
            });

            if (result.success) {
                setSyncMessage(__(`Sync completed! ${result.pages_processed} pages processed.`, 'wp-mcp-connect'));
            } else {
                setSyncMessage(__('Sync completed with errors. Check the sync history for details.', 'wp-mcp-connect'));
            }

            loadData();
        } catch (err) {
            setError(err.message || 'Sync failed');
        } finally {
            setSyncing(false);
        }
    };

    const formatDate = (dateString) => {
        if (!dateString) return __('Never', 'wp-mcp-connect');
        const date = new Date(dateString);
        return date.toLocaleString();
    };

    const formatNumber = (num) => {
        return new Intl.NumberFormat().format(num || 0);
    };

    if (loading) {
        return (
            <div className="gsc-loading">
                <Spinner />
            </div>
        );
    }

    return (
        <div className="gsc-overview">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            {syncMessage && (
                <Notice status="success" isDismissible onDismiss={() => setSyncMessage(null)}>
                    {syncMessage}
                </Notice>
            )}

            <div className="sync-header">
                <div className="sync-info">
                    <span className="site-url">{siteUrl}</span>
                    <span className="last-sync">
                        {__('Last sync:', 'wp-mcp-connect')} {formatDate(overview?.last_sync)}
                    </span>
                </div>
                <Button
                    variant="secondary"
                    icon={update}
                    onClick={handleSync}
                    isBusy={syncing}
                    disabled={syncing}
                >
                    {syncing ? __('Syncing...', 'wp-mcp-connect') : __('Sync Now', 'wp-mcp-connect')}
                </Button>
            </div>

            <div className="wp-mcp-connect-stats">
                <div className="stat-card">
                    <div className="stat-value">{formatNumber(overview?.total_pages)}</div>
                    <div className="stat-label">{__('Total Pages', 'wp-mcp-connect')}</div>
                </div>
                <div className="stat-card stat-success">
                    <div className="stat-value">{formatNumber(overview?.indexed_pages)}</div>
                    <div className="stat-label">{__('Indexed', 'wp-mcp-connect')}</div>
                </div>
                <div className={`stat-card ${overview?.not_indexed > 0 ? 'stat-warning' : 'stat-success'}`}>
                    <div className="stat-value">{formatNumber(overview?.not_indexed)}</div>
                    <div className="stat-label">{__('Not Indexed', 'wp-mcp-connect')}</div>
                </div>
                <div className={`stat-card ${overview?.stale_crawls > 0 ? 'stat-warning' : 'stat-success'}`}>
                    <div className="stat-value">{formatNumber(overview?.stale_crawls)}</div>
                    <div className="stat-label">{__('Stale Crawls', 'wp-mcp-connect')}</div>
                </div>
            </div>

            <div className="wp-mcp-connect-stats">
                <div className="stat-card">
                    <div className="stat-value">{formatNumber(overview?.total_impressions)}</div>
                    <div className="stat-label">{__('Total Impressions', 'wp-mcp-connect')}</div>
                </div>
                <div className="stat-card">
                    <div className="stat-value">{formatNumber(overview?.total_clicks)}</div>
                    <div className="stat-label">{__('Total Clicks', 'wp-mcp-connect')}</div>
                </div>
                <div className="stat-card">
                    <div className="stat-value">{overview?.avg_ctr || 0}%</div>
                    <div className="stat-label">{__('Average CTR', 'wp-mcp-connect')}</div>
                </div>
                <div className="stat-card">
                    <div className="stat-value">{overview?.avg_position || 0}</div>
                    <div className="stat-label">{__('Average Position', 'wp-mcp-connect')}</div>
                </div>
            </div>

            {syncStatus && (
                <Card className="sync-details">
                    <CardBody>
                        <h3>{__('Sync Status', 'wp-mcp-connect')}</h3>
                        <div className="sync-detail-row">
                            <span>{__('URL Inspections Today:', 'wp-mcp-connect')}</span>
                            <strong>{syncStatus.inspections_today} / 2000</strong>
                        </div>
                        <div className="sync-detail-row">
                            <span>{__('Remaining:', 'wp-mcp-connect')}</span>
                            <strong>{syncStatus.inspections_remaining}</strong>
                        </div>
                        {syncStatus.last_completed && (
                            <div className="sync-detail-row">
                                <span>{__('Last Sync Result:', 'wp-mcp-connect')}</span>
                                <strong className={syncStatus.last_completed.status === 'completed' ? 'status-success' : 'status-warning'}>
                                    {syncStatus.last_completed.status === 'completed'
                                        ? __('Success', 'wp-mcp-connect')
                                        : __('Completed with errors', 'wp-mcp-connect')
                                    }
                                    {' '}({syncStatus.last_completed.pages_processed} {__('pages', 'wp-mcp-connect')})
                                </strong>
                            </div>
                        )}
                    </CardBody>
                </Card>
            )}

            <style>{`
                .gsc-overview {
                    padding: 0;
                }
                .sync-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 20px;
                    padding: 15px;
                    background: #f9f9f9;
                    border-radius: 4px;
                }
                .sync-info {
                    display: flex;
                    flex-direction: column;
                    gap: 5px;
                }
                .site-url {
                    font-family: monospace;
                    font-size: 14px;
                }
                .last-sync {
                    color: #666;
                    font-size: 13px;
                }
                .sync-details {
                    margin-top: 20px;
                }
                .sync-details h3 {
                    margin: 0 0 15px;
                    font-size: 14px;
                }
                .sync-detail-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 8px 0;
                    border-bottom: 1px solid #eee;
                }
                .sync-detail-row:last-child {
                    border-bottom: none;
                }
                .status-success {
                    color: #28a745;
                }
                .status-warning {
                    color: #ffc107;
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

export default GSCOverview;
