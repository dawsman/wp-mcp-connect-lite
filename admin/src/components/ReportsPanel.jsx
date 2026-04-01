import { useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const ReportsPanel = () => {
    const [sending, setSending] = useState(false);
    const [message, setMessage] = useState(null);
    const [error, setError] = useState(null);

    const sendReport = async () => {
        setSending(true);
        setError(null);
        setMessage(null);
        try {
            const response = await apiFetch({ path: '/mcp/v1/reports/weekly', method: 'POST', data: { send: true } });
            setMessage(response.sent ? __('Report sent.', 'wp-mcp-connect') : __('Report generated.', 'wp-mcp-connect'));
        } catch (err) {
            setError(err.message || 'Failed to send report');
        } finally {
            setSending(false);
        }
    };

    return (
        <div className="reports-panel">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}
            {message && (
                <Notice status="success" isDismissible onDismiss={() => setMessage(null)}>
                    {message}
                </Notice>
            )}

            <div className="wp-mcp-connect-card">
                <h2>{__('Weekly Report', 'wp-mcp-connect')}</h2>
                <p>{__('Send the latest SEO health report to configured recipients.', 'wp-mcp-connect')}</p>
                <Button variant="primary" onClick={sendReport} isBusy={sending} disabled={sending}>
                    {sending ? __('Sending...', 'wp-mcp-connect') : __('Send Weekly Report', 'wp-mcp-connect')}
                </Button>
            </div>
        </div>
    );
};

export default ReportsPanel;
