import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner, Notice, Card, CardBody, CardHeader, ToggleControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const GSCSettings = ({ onDisconnect, siteUrl }) => {
    const [settings, setSettings] = useState({
        sync_enabled: false,
        sync_frequency: 'daily',
        data_retention_days: 90,
    });
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState(null);
    const [syncHistory, setSyncHistory] = useState([]);
    const [disconnecting, setDisconnecting] = useState(false);

    useEffect(() => {
        loadSettings();
        loadSyncHistory();
    }, []);

    const loadSettings = async () => {
        setLoading(true);
        try {
            const response = await apiFetch({ path: '/mcp/v1/gsc/settings' });
            setSettings(response);
        } catch (err) {
            setError(err.message || 'Failed to load settings');
        } finally {
            setLoading(false);
        }
    };

    const loadSyncHistory = async () => {
        try {
            const response = await apiFetch({ path: '/mcp/v1/gsc/sync/history?limit=10' });
            setSyncHistory(response.history || []);
        } catch (err) {
            // Non-critical, don't show error
        }
    };

    const handleSave = async () => {
        setSaving(true);
        setError(null);
        setSuccess(null);

        try {
            await apiFetch({
                path: '/mcp/v1/gsc/settings',
                method: 'POST',
                data: settings,
            });
            setSuccess(__('Settings saved successfully.', 'wp-mcp-connect'));
        } catch (err) {
            setError(err.message || 'Failed to save settings');
        } finally {
            setSaving(false);
        }
    };

    const handleDisconnect = async () => {
        if (!window.confirm(__('Are you sure you want to disconnect from Google Search Console? This will remove all stored tokens.', 'wp-mcp-connect'))) {
            return;
        }

        setDisconnecting(true);
        try {
            await onDisconnect();
        } catch (err) {
            setError(err.message || 'Failed to disconnect');
            setDisconnecting(false);
        }
    };

    const formatDate = (dateString) => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString();
    };

    const getStatusBadge = (status) => {
        switch (status) {
            case 'completed':
                return <span className="badge badge-success">{__('Success', 'wp-mcp-connect')}</span>;
            case 'completed_with_errors':
                return <span className="badge badge-warning">{__('Partial', 'wp-mcp-connect')}</span>;
            case 'running':
                return <span className="badge badge-info">{__('Running', 'wp-mcp-connect')}</span>;
            default:
                return <span className="badge">{status}</span>;
        }
    };

    if (loading) {
        return (
            <div className="gsc-loading">
                <Spinner />
            </div>
        );
    }

    return (
        <div className="gsc-settings">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            {success && (
                <Notice status="success" isDismissible onDismiss={() => setSuccess(null)}>
                    {success}
                </Notice>
            )}

            <Card className="settings-card">
                <CardHeader>
                    <h3>{__('Sync Settings', 'wp-mcp-connect')}</h3>
                </CardHeader>
                <CardBody>
                    <ToggleControl
                        label={__('Enable Automatic Sync', 'wp-mcp-connect')}
                        help={__('Automatically sync data from Google Search Console on a schedule.', 'wp-mcp-connect')}
                        checked={settings.sync_enabled}
                        onChange={(value) => setSettings({ ...settings, sync_enabled: value })}
                    />

                    {settings.sync_enabled && (
                        <SelectControl
                            label={__('Sync Frequency', 'wp-mcp-connect')}
                            value={settings.sync_frequency}
                            onChange={(value) => setSettings({ ...settings, sync_frequency: value })}
                            options={[
                                { value: 'hourly', label: __('Hourly', 'wp-mcp-connect') },
                                { value: 'twicedaily', label: __('Twice Daily', 'wp-mcp-connect') },
                                { value: 'daily', label: __('Daily (Recommended)', 'wp-mcp-connect') },
                                { value: 'weekly', label: __('Weekly', 'wp-mcp-connect') },
                            ]}
                        />
                    )}

                    <SelectControl
                        label={__('Data Retention', 'wp-mcp-connect')}
                        help={__('How long to keep sync history logs.', 'wp-mcp-connect')}
                        value={settings.data_retention_days}
                        onChange={(value) => setSettings({ ...settings, data_retention_days: parseInt(value, 10) })}
                        options={[
                            { value: 30, label: __('30 days', 'wp-mcp-connect') },
                            { value: 60, label: __('60 days', 'wp-mcp-connect') },
                            { value: 90, label: __('90 days', 'wp-mcp-connect') },
                            { value: 180, label: __('180 days', 'wp-mcp-connect') },
                            { value: 365, label: __('1 year', 'wp-mcp-connect') },
                        ]}
                    />

                    <Button
                        variant="primary"
                        onClick={handleSave}
                        isBusy={saving}
                        disabled={saving}
                    >
                        {__('Save Settings', 'wp-mcp-connect')}
                    </Button>
                </CardBody>
            </Card>

            {syncHistory.length > 0 && (
                <Card className="settings-card">
                    <CardHeader>
                        <h3>{__('Sync History', 'wp-mcp-connect')}</h3>
                    </CardHeader>
                    <CardBody>
                        <table className="wp-mcp-connect-table history-table">
                            <thead>
                                <tr>
                                    <th>{__('Started', 'wp-mcp-connect')}</th>
                                    <th>{__('Type', 'wp-mcp-connect')}</th>
                                    <th>{__('Status', 'wp-mcp-connect')}</th>
                                    <th>{__('Pages', 'wp-mcp-connect')}</th>
                                    <th>{__('Errors', 'wp-mcp-connect')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {syncHistory.map((sync) => (
                                    <tr key={sync.id}>
                                        <td>{formatDate(sync.started_at)}</td>
                                        <td>{sync.sync_type}</td>
                                        <td>{getStatusBadge(sync.status)}</td>
                                        <td>{sync.pages_processed}</td>
                                        <td>
                                            {sync.errors_count > 0 ? (
                                                <span className="error-count" title={sync.error_message}>
                                                    {sync.errors_count}
                                                </span>
                                            ) : '-'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </CardBody>
                </Card>
            )}

            <Card className="settings-card danger-zone">
                <CardHeader>
                    <h3>{__('Connection', 'wp-mcp-connect')}</h3>
                </CardHeader>
                <CardBody>
                    <div className="connection-info">
                        <p>
                            <strong>{__('Connected Site:', 'wp-mcp-connect')}</strong>
                            <br />
                            <code>{siteUrl}</code>
                        </p>
                    </div>

                    <Button
                        variant="secondary"
                        isDestructive
                        onClick={handleDisconnect}
                        isBusy={disconnecting}
                        disabled={disconnecting}
                    >
                        {__('Disconnect from Google', 'wp-mcp-connect')}
                    </Button>
                    <p className="description">
                        {__('This will remove OAuth tokens but keep your synced data.', 'wp-mcp-connect')}
                    </p>
                </CardBody>
            </Card>

            <style>{`
                .gsc-settings {
                    max-width: 800px;
                }
                .settings-card {
                    margin-bottom: 20px;
                }
                .settings-card h3 {
                    margin: 0;
                    font-size: 14px;
                }
                .settings-card .components-toggle-control {
                    margin-bottom: 20px;
                }
                .settings-card .components-select-control {
                    margin-bottom: 20px;
                    max-width: 300px;
                }
                .history-table {
                    font-size: 13px;
                }
                .badge {
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 3px;
                    font-size: 11px;
                    background: #e0e0e0;
                }
                .badge-success {
                    background: #d4edda;
                    color: #155724;
                }
                .badge-warning {
                    background: #fff3cd;
                    color: #856404;
                }
                .badge-info {
                    background: #cce5ff;
                    color: #004085;
                }
                .error-count {
                    color: #dc3545;
                    cursor: help;
                }
                .danger-zone {
                    border-color: #dc3545;
                }
                .connection-info {
                    margin-bottom: 15px;
                }
                .connection-info code {
                    display: inline-block;
                    margin-top: 5px;
                    padding: 5px 10px;
                    background: #f5f5f5;
                }
                .description {
                    color: #666;
                    font-size: 13px;
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

export default GSCSettings;
