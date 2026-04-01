import { useState, useEffect } from '@wordpress/element';
import { Button, TextControl, ToggleControl, Notice, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Card, SkeletonLoader } from './ui';
import AutomationRules from './AutomationRules';

const DEFAULT_SETTINGS = {
    rate_limit: 60,
    rate_limit_window: 60,
    enable_logging: false,
    log_retention_days: 30,
    ip_whitelist: '',
    ip_blacklist: '',
    trusted_proxies: '',
    reports_enabled: false,
    reports_recipients: '',
    reports_frequency: 'weekly',
    task_refresh_enabled: false,
    task_refresh_frequency: 'daily',
};

const Settings = () => {
    const [settings, setSettings] = useState(DEFAULT_SETTINGS);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState(null);
    const [connectionTest, setConnectionTest] = useState(null);

    useEffect(() => {
        loadSettings();
    }, []);

    const loadSettings = async () => {
        setLoading(true);
        try {
            const data = await apiFetch({ path: '/mcp/v1/settings' });
            setSettings({
                ...DEFAULT_SETTINGS,
                ...data,
            });
        } catch (err) {
            setSettings({ ...DEFAULT_SETTINGS });
        } finally {
            setLoading(false);
        }
    };

    const saveSettings = async () => {
        setSaving(true);
        setError(null);
        setSuccess(null);

        try {
            await apiFetch({
                path: '/mcp/v1/settings',
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

    const testConnection = async () => {
        setConnectionTest({ status: 'testing' });
        try {
            const start = Date.now();
            const data = await apiFetch({ path: '/mcp/v1/system' });
            const duration = Date.now() - start;
            setConnectionTest({
                status: 'success',
                message: __('Connection successful!', 'wp-mcp-connect'),
                details: `Response time: ${duration}ms | WP ${data.wp_version} | PHP ${data.php_version}`,
            });
        } catch (err) {
            setConnectionTest({
                status: 'error',
                message: __('Connection failed', 'wp-mcp-connect'),
                details: err.message,
            });
        }
    };

    if (loading) {
        return (
            <div className="mcp-loading">
                <SkeletonLoader type="card" rows={4} />
            </div>
        );
    }

    return (
        <div className="settings-content fade-in">
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

            <Card title={__('Connection Test', 'wp-mcp-connect')}>
                <p>{__('Test the connection to the WordPress REST API.', 'wp-mcp-connect')}</p>
                <Button
                    variant="secondary"
                    onClick={testConnection}
                    disabled={connectionTest?.status === 'testing'}
                >
                    {connectionTest?.status === 'testing' ? __('Testing...', 'wp-mcp-connect') : __('Test Connection', 'wp-mcp-connect')}
                </Button>
                {connectionTest && connectionTest.status !== 'testing' && (
                    <Notice
                        status={connectionTest.status === 'success' ? 'success' : 'error'}
                        isDismissible={false}
                        className="mcp-notice-inline"
                    >
                        <strong>{connectionTest.message}</strong>
                        {connectionTest.details && <p className="mcp-notice-details">{connectionTest.details}</p>}
                    </Notice>
                )}
            </Card>

            <Card title={__('Rate Limiting', 'wp-mcp-connect')}>
                <p>{__('Configure API rate limiting to prevent abuse.', 'wp-mcp-connect')}</p>
                <div className="mcp-form">
                    <div className="form-row">
                        <TextControl
                            label={__('Requests per window', 'wp-mcp-connect')}
                            type="number"
                            value={settings.rate_limit}
                            onChange={(value) => setSettings({ ...settings, rate_limit: parseInt(value, 10) || 60 })}
                            help={__('Maximum number of requests allowed per time window.', 'wp-mcp-connect')}
                        />
                    </div>
                    <div className="form-row">
                        <TextControl
                            label={__('Window duration (seconds)', 'wp-mcp-connect')}
                            type="number"
                            value={settings.rate_limit_window}
                            onChange={(value) => setSettings({ ...settings, rate_limit_window: parseInt(value, 10) || 60 })}
                            help={__('Time window in seconds for rate limiting.', 'wp-mcp-connect')}
                        />
                    </div>
                </div>
            </Card>

            <Card title={__('API Logging', 'wp-mcp-connect')}>
                <p>{__('Enable logging of API requests for debugging and monitoring.', 'wp-mcp-connect')}</p>
                <div className="mcp-form">
                    <div className="form-row">
                        <ToggleControl
                            label={__('Enable API logging', 'wp-mcp-connect')}
                            checked={settings.enable_logging}
                            onChange={(value) => setSettings({ ...settings, enable_logging: value })}
                        />
                    </div>
                    {settings.enable_logging && (
                        <div className="form-row">
                            <TextControl
                                label={__('Log retention (days)', 'wp-mcp-connect')}
                                type="number"
                                value={settings.log_retention_days}
                                onChange={(value) => setSettings({ ...settings, log_retention_days: parseInt(value, 10) || 30 })}
                                help={__('Number of days to keep log entries. Older entries will be automatically deleted.', 'wp-mcp-connect')}
                            />
                        </div>
                    )}
                </div>
            </Card>

            <Card title={__('IP Access Control', 'wp-mcp-connect')}>
                <p>{__('Configure IP-based access control for the API.', 'wp-mcp-connect')}</p>
                <div className="mcp-form">
                    <div className="form-row">
                        <TextControl
                            label={__('IP Whitelist', 'wp-mcp-connect')}
                            value={settings.ip_whitelist}
                            onChange={(value) => setSettings({ ...settings, ip_whitelist: value })}
                            help={__('Comma-separated list of IPs or CIDR ranges to always allow. Leave empty to allow all.', 'wp-mcp-connect')}
                            placeholder="192.168.1.1, 10.0.0.0/8"
                        />
                    </div>
                    <div className="form-row">
                        <TextControl
                            label={__('IP Blacklist', 'wp-mcp-connect')}
                            value={settings.ip_blacklist}
                            onChange={(value) => setSettings({ ...settings, ip_blacklist: value })}
                            help={__('Comma-separated list of IPs or CIDR ranges to block.', 'wp-mcp-connect')}
                            placeholder="192.168.1.100, 172.16.0.0/12"
                        />
                    </div>
                    <div className="form-row">
                        <TextControl
                            label={__('Trusted Proxies', 'wp-mcp-connect')}
                            value={settings.trusted_proxies}
                            onChange={(value) => setSettings({ ...settings, trusted_proxies: value })}
                            help={__('Comma-separated list of proxy IPs to trust for forwarded headers.', 'wp-mcp-connect')}
                            placeholder="10.0.0.1, 10.0.0.2"
                        />
                    </div>
                </div>
            </Card>

            <Card title={__('Reports', 'wp-mcp-connect')}>
                <p>{__('Configure weekly SEO health reports.', 'wp-mcp-connect')}</p>
                <div className="mcp-form">
                    <div className="form-row">
                        <ToggleControl
                            label={__('Enable Weekly Reports', 'wp-mcp-connect')}
                            checked={settings.reports_enabled}
                            onChange={(value) => setSettings({ ...settings, reports_enabled: value })}
                        />
                    </div>
                    <div className="form-row">
                        <TextControl
                            label={__('Recipients', 'wp-mcp-connect')}
                            value={settings.reports_recipients}
                            onChange={(value) => setSettings({ ...settings, reports_recipients: value })}
                            help={__('Comma-separated email addresses. Leave blank to use site admin email.', 'wp-mcp-connect')}
                        />
                    </div>
                    <div className="form-row">
                        <SelectControl
                            label={__('Frequency', 'wp-mcp-connect')}
                            value={settings.reports_frequency}
                            onChange={(value) => setSettings({ ...settings, reports_frequency: value })}
                            options={[
                                { value: 'weekly', label: __('Weekly', 'wp-mcp-connect') },
                            ]}
                        />
                    </div>
                </div>
            </Card>

            <Card title={__('Task Refresh', 'wp-mcp-connect')}>
                <p>{__('Automatically refresh the task queue from audits.', 'wp-mcp-connect')}</p>
                <div className="mcp-form">
                    <div className="form-row">
                        <ToggleControl
                            label={__('Enable Task Refresh', 'wp-mcp-connect')}
                            checked={settings.task_refresh_enabled}
                            onChange={(value) => setSettings({ ...settings, task_refresh_enabled: value })}
                        />
                    </div>
                    {settings.task_refresh_enabled && (
                        <div className="form-row">
                            <SelectControl
                                label={__('Frequency', 'wp-mcp-connect')}
                                value={settings.task_refresh_frequency}
                                onChange={(value) => setSettings({ ...settings, task_refresh_frequency: value })}
                                options={[
                                    { value: 'hourly', label: __('Hourly', 'wp-mcp-connect') },
                                    { value: 'twicedaily', label: __('Twice Daily', 'wp-mcp-connect') },
                                    { value: 'daily', label: __('Daily (Recommended)', 'wp-mcp-connect') },
                                ]}
                            />
                        </div>
                    )}
                </div>
            </Card>

            <AutomationRules />

            <div className="mcp-save-bar">
                <Button
                    variant="primary"
                    onClick={saveSettings}
                    disabled={saving}
                >
                    {saving ? __('Saving...', 'wp-mcp-connect') : __('Save Settings', 'wp-mcp-connect')}
                </Button>
            </div>
        </div>
    );
};

export default Settings;
