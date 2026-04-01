import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner, SelectControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const ApiLog = () => {
    const [logs, setLogs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [statusFilter, setStatusFilter] = useState('all');
    const [page, setPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [loggingEnabled, setLoggingEnabled] = useState(false);

    useEffect(() => {
        checkLoggingStatus();
    }, []);

    useEffect(() => {
        if (loggingEnabled) {
            loadLogs();
        }
    }, [statusFilter, page, loggingEnabled]);

    const checkLoggingStatus = async () => {
        try {
            const response = await apiFetch({ path: '/mcp/v1/logs/status' });
            setLoggingEnabled(response.enabled || false);
            if (!response.enabled) {
                setLoading(false);
            }
        } catch {
            setLoggingEnabled(false);
            setLoading(false);
        }
    };

    const loadLogs = async () => {
        setLoading(true);
        setError(null);

        try {
            let url = `/mcp/v1/logs?page=${page}&per_page=25`;
            if (statusFilter !== 'all') {
                url += `&status=${statusFilter}`;
            }

            const data = await apiFetch({ path: url });
            setLogs(data.logs || []);
            setTotalPages(data.total_pages || 1);
        } catch (err) {
            setError(err.message || 'Failed to load API logs');
            setLogs([]);
        } finally {
            setLoading(false);
        }
    };

    const exportLogs = async () => {
        try {
            const response = await apiFetch({ path: `/mcp/v1/logs/export?status=${statusFilter}` });
            const blob = new Blob([response.csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = response.filename || 'api-logs.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        } catch (err) {
            setError(err.message || 'Failed to export logs');
        }
    };

    const getStatusClass = (statusCode) => {
        if (statusCode >= 200 && statusCode < 300) return 'status-success';
        if (statusCode >= 400 && statusCode < 500) return 'status-warning';
        if (statusCode >= 500) return 'status-error';
        return '';
    };

    const formatTimestamp = (timestamp) => {
        const date = new Date(timestamp);
        return date.toLocaleString();
    };

    if (!loggingEnabled) {
        return (
            <div className="api-log-content">
                <div className="wp-mcp-connect-card">
                    <h2>{__('API Activity Logging', 'wp-mcp-connect')}</h2>
                    <Notice status="info" isDismissible={false}>
                        {__('API logging is not yet enabled. This feature will be available after setting up the logging infrastructure.', 'wp-mcp-connect')}
                    </Notice>
                    <p>
                        {__('When enabled, you will be able to see:', 'wp-mcp-connect')}
                    </p>
                    <ul style={{ listStyle: 'disc', marginLeft: '20px' }}>
                        <li>{__('All API requests made to the MCP endpoints', 'wp-mcp-connect')}</li>
                        <li>{__('Request timestamps and response times', 'wp-mcp-connect')}</li>
                        <li>{__('Status codes and error details', 'wp-mcp-connect')}</li>
                        <li>{__('User information for authenticated requests', 'wp-mcp-connect')}</li>
                    </ul>
                </div>
            </div>
        );
    }

    return (
        <div className="api-log-content">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            <div className="wp-mcp-connect-card">
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '15px' }}>
                    <h2 style={{ margin: 0 }}>{__('API Request Log', 'wp-mcp-connect')}</h2>
                    <div style={{ display: 'flex', gap: '10px', alignItems: 'flex-end' }}>
                        <SelectControl
                            label={__('Status Filter', 'wp-mcp-connect')}
                            value={statusFilter}
                            onChange={(value) => { setStatusFilter(value); setPage(1); }}
                            options={[
                                { value: 'all', label: __('All Requests', 'wp-mcp-connect') },
                                { value: 'success', label: __('Success (2xx)', 'wp-mcp-connect') },
                                { value: 'error', label: __('Errors (4xx/5xx)', 'wp-mcp-connect') },
                            ]}
                            __nextHasNoMarginBottom
                        />
                        <Button variant="secondary" onClick={exportLogs}>
                            {__('Export CSV', 'wp-mcp-connect')}
                        </Button>
                    </div>
                </div>

                {loading ? (
                    <div className="wp-mcp-connect-loading">
                        <Spinner />
                    </div>
                ) : logs.length > 0 ? (
                    <>
                        <table className="wp-mcp-connect-table">
                            <thead>
                                <tr>
                                    <th>{__('Time', 'wp-mcp-connect')}</th>
                                    <th>{__('Method', 'wp-mcp-connect')}</th>
                                    <th>{__('Action', 'wp-mcp-connect')}</th>
                                    <th>{__('Endpoint', 'wp-mcp-connect')}</th>
                                    <th>{__('Status', 'wp-mcp-connect')}</th>
                                    <th>{__('Response Time', 'wp-mcp-connect')}</th>
                                    <th>{__('User', 'wp-mcp-connect')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {logs.map((log, index) => (
                                    <tr key={index}>
                                        <td>{formatTimestamp(log.timestamp)}</td>
                                        <td><code>{log.method}</code></td>
                                        <td>{log.description || '—'}</td>
                                        <td><code>{log.endpoint}</code></td>
                                        <td>
                                            <span className={`status-badge ${getStatusClass(log.status_code)}`}>
                                                {log.status_code}
                                            </span>
                                        </td>
                                        <td>{log.response_time_ms}ms</td>
                                        <td>{log.user_name || '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        {totalPages > 1 && (
                            <div className="wp-mcp-connect-pagination">
                                <Button
                                    variant="secondary"
                                    disabled={page <= 1}
                                    onClick={() => setPage(page - 1)}
                                >
                                    {__('Previous', 'wp-mcp-connect')}
                                </Button>
                                <span className="page-info">
                                    {__('Page', 'wp-mcp-connect')} {page} {__('of', 'wp-mcp-connect')} {totalPages}
                                </span>
                                <Button
                                    variant="secondary"
                                    disabled={page >= totalPages}
                                    onClick={() => setPage(page + 1)}
                                >
                                    {__('Next', 'wp-mcp-connect')}
                                </Button>
                            </div>
                        )}
                    </>
                ) : (
                    <p className="wp-mcp-connect-empty">
                        {__('No API activity logged yet.', 'wp-mcp-connect')}
                    </p>
                )}
            </div>

            <style>{`
                .status-badge {
                    border-radius: 3px;
                    display: inline-block;
                    font-size: 12px;
                    font-weight: 500;
                    padding: 2px 8px;
                }
                .status-success {
                    background: #d4edda;
                    color: #155724;
                }
                .status-warning {
                    background: #fff3cd;
                    color: #856404;
                }
                .status-error {
                    background: #f8d7da;
                    color: #721c24;
                }
            `}</style>
        </div>
    );
};

export default ApiLog;
